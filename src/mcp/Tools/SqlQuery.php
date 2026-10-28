<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class SqlQuery extends Tool {
    const MAX_ROWS = 200;
    const TIMEOUT_MS = 10000;
    const MAX_BYTES = 100000; // about 25 000 tokens of rows per answer

    public function name(): string {
        return 'sql_query';
    }

    public function describe(): array {
        return [
            'title'       => 'Read-only SQL query',
            'description' => 'One read-only statement (SELECT / SHOW / DESCRIBE / EXPLAIN), table prefix from store_info. At most 200 rows, cells over 2000 characters cut. Writes, DDL and several statements are refused; closed tables and credential columns cannot be read (db_schema shows the levels).',
            'inputSchema' => self::schema([
                'sql'   => self::prop('string', 'The SQL statement.'),
                'limit' => self::prop('integer', 'Row cap 1-' . self::MAX_ROWS . ' (default ' . self::MAX_ROWS . ').'),
            ], ['sql']),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $this->need($a, 'sql');
        $max = $this->limit($a, self::MAX_ROWS, self::MAX_ROWS);
        $sql = SqlGuard::normalize((string)$a['sql']);
        $skeleton = SqlGuard::skeleton($sql);

        SqlGuard::assertSingleStatement($skeleton);
        SqlGuard::assertNoForbidden($skeleton);
        SqlGuard::assertSchemas($sql);
        SqlGuard::assertNoPositionalRename($skeleton);

        $first = SqlGuard::firstWord($skeleton);

        if (!in_array($first, SqlGuard::READ_PREFIXES, true)) {
            $how = $c->can(Tool::LEVEL_WRITE) ? 'Changes go through the dedicated tools (bulk_update_products, update_product, save_content, update_order_status, manage_records ...): they preview, follow the Access tab and can be rolled back. Raw SQL writes are not offered.'
                : 'Changes are not allowed in the store settings: MCP Server > Access, set "Edit" for the needed section.';

            return ToolResult::error("sql_query only reads (SELECT, SHOW, DESCRIBE, EXPLAIN). Got: $first. " . $how);
        }

        // REPLACE(str, from, to) and INSERT(str, pos, len, new) are string functions of a SELECT, not writes
        if (preg_match('/\b(UPDATE|DELETE|INTO)\b|\b(INSERT|REPLACE)\b(?!\s*\()/i', $skeleton)) {
            return ToolResult::error('Statement contains write keywords; only read statements are allowed.');
        }

        $tables = [];
        $denied = $c->policy()->checkRead($sql, $tables);

        if ($denied !== null) {
            return \OcMcp\AccessDenied::result($c->policy()->lastDenial());
        }

        $sql = SqlGuard::ensureLimit($sql, $skeleton, $max);
        $start = microtime(true);
        try {
            $raw = $c->db->withTimeout(self::TIMEOUT_MS, function () use ($c, $sql) {
                return $c->db->rows($sql);
            });
        } catch (\Throwable $e) {
            if (preg_match('/max_statement_time|max_execution_time|interrupted/i', $e->getMessage())) {
                return ToolResult::error('The query took longer than ' . (self::TIMEOUT_MS / 1000) . ' s and was stopped. Narrow it down (WHERE, LIMIT, fewer JOINs).');
            }

            throw $e;
        }

        if (preg_match('/\binformation_schema\b/i', $sql)) { // metadata of closed tables and hidden columns is closed too
            $raw = $this->filterSchemaRows($raw, $c);

            if ($raw === null) {
                return ToolResult::error('Queries on information_schema must return TABLE_NAME (and COLUMN_NAME for columns) so the answer can be limited to the tables open for the AI; or use db_schema.');
            }
        }

        $rows = $c->policy()->maskRows($c->policy()->stripHiddenColumns($raw, $tables), $tables);
        $ms = (int)round((microtime(true) - $start) * 1000);
        $truncated = count($rows) > $max;
        $rows = array_slice($rows, 0, $max);

        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if (is_string($v) && strlen($v) > 2000) {
                    $row[$k] = substr($v, 0, 2000) . '...[truncated]';
                }
            }
        }
        unset($row);

        // 200 rows of long texts could fill the AI's whole context: the answer stops at MAX_BYTES and says so
        $bytes = 0;

        foreach ($rows as $n => $row) {
            $bytes += strlen((string)json_encode($row, JSON_UNESCAPED_UNICODE));

            if ($bytes > self::MAX_BYTES && $n > 0) {
                $rows = array_slice($rows, 0, $n);
                $truncated = true;
                break;
            }
        }

        $columns = $rows ? array_keys($rows[0]) : [];

        return ToolResult::data([
            'sql'         => $sql,
            'tables'      => $tables,
            'columns'     => $columns,
            'row_count'   => count($rows),
            'truncated'   => $truncated,
            'duration_ms' => $ms,
            'rows'        => $rows,
        ], count($rows) . " row(s) in {$ms} ms." . ($truncated ? ' More rows match: narrow the query (WHERE, fewer or shorter columns, LIMIT ... OFFSET) to see the rest.' : ''));
    }

    /** Rows of an information_schema query that belong to readable tables / visible columns; null when the rows cannot be attributed. */
    private function filterSchemaRows(array $rows, Context $c): ?array {
        $policy = $c->policy();
        $out = [];

        foreach ($rows as $row) {
            $keys = array_change_key_case($row, CASE_LOWER);

            if (!array_key_exists('table_name', $keys)) {
                return null;
            }

            $short = $policy->normalize((string)$keys['table_name']);

            if (!$policy->canRead($short) || \OcMcp\TablePolicy::rawSqlClosed($short)) {
                continue;
            }

            if (array_key_exists('column_name', $keys) && in_array(strtolower((string)$keys['column_name']), $policy->hiddenColumns($short), true)) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }
}
