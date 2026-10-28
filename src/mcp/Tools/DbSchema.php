<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class DbSchema extends Tool {
    public function name(): string {
        return 'db_schema';
    }

    public function describe(): array {
        return [
            'title'       => 'Database schema',
            'description' => 'guide=true: how this store\'s database fits together (relations, traps, ready SQL) - read it before your own SQL. No arguments: every table with rows and its AI access level (none, read, write, full). table: its columns with levels. With or without the prefix.',
            'inputSchema' => self::schema([
                'table'  => self::prop('string', 'Table name, e.g. "product" or "oc_product".'),
                'filter' => self::prop('string', 'Only list tables whose name contains this substring.'),
                'guide'  => self::prop('boolean', 'Return the database guide of this store (relations, traps, ready queries).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        if ($this->bool($a, 'guide', false)) {
            return ToolResult::data(\OcMcp\SchemaGuide::build($c), 'Database guide of this store. Run the ready queries with sql_query; adjust periods and ids.');
        }

        $db = $c->db;
        $prefix = $db->prefix();
        $table = $this->str($a, 'table');

        if ($table !== null && $table !== '') {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);

            if ($prefix !== '' && strpos($table, $prefix) !== 0) {
                $table = $prefix . $table;
            }

            if (!$db->tableExists(substr($table, strlen($prefix)))) {
                return ToolResult::error("Table '$table' does not exist.");
            }

            $policy = $c->policy();

            if (!$policy->canRead($table)) {
                return ToolResult::error("Table '$table' is closed for the AI assistant (access level: none); it is neither described nor readable.");
            }

            $columns = $db->rows("SHOW FULL COLUMNS FROM `" . $table . "`");
            $out = [];

            foreach ($columns as $col) {
                $out[] = ['name' => $col['Field'], 'type' => $col['Type'], 'null' => $col['Null'] === 'YES', 'key' => $col['Key'], 'default' => $col['Default'], 'extra' => $col['Extra'], 'level' => $policy->columnLevel($table, (string)$col['Field'])];
            }

            $count = (int)$db->value("SELECT COUNT(*) AS c FROM `" . $table . "`", 'c');

            return ToolResult::data(['table' => $table, 'level' => $policy->level($table), 'writable_columns' => $policy->level($table) === \OcMcp\TablePolicy::WRITE ? ($policy->writableColumns($table) ?: 'all') : ($policy->level($table) === \OcMcp\TablePolicy::FULL ? 'all' : []), 'rows' => $count, 'columns' => $out]);
        }

        $filter = strtolower((string)$this->str($a, 'filter', ''));
        $rows = $db->rows("SELECT `TABLE_NAME` AS name, `TABLE_ROWS` AS approx_rows, `ENGINE` AS engine FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() ORDER BY `TABLE_NAME`");
        $tables = [];

        foreach ($rows as $r) {
            $name = (string)$r['name'];

            if ($prefix !== '' && strpos($name, $prefix) !== 0) {
                continue;
            }

            if ($filter !== '' && strpos(strtolower($name), $filter) === false) {
                continue;
            }

            $level = $c->policy()->level($name);
            $tables[] = $level === \OcMcp\TablePolicy::NONE || \OcMcp\TablePolicy::rawSqlClosed($c->policy()->normalize($name))
                ? ['table' => $name, 'level' => $level === \OcMcp\TablePolicy::NONE ? $level : 'tools only'] // closed: its size is nobody's business either
                : ['table' => $name, 'level' => $level, 'approx_rows' => (int)$r['approx_rows'], 'engine' => (string)$r['engine']];
        }

        return ToolResult::data(['prefix' => $prefix, 'count' => count($tables), 'levels' => $c->policy()->summary()['rules'], 'tables' => $tables]);
    }
}
