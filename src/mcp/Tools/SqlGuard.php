<?php
namespace OcMcp\Tools;

use OcMcp\ToolException;

/**
 * Very conservative SQL validation for sql_query.
 */
final class SqlGuard {
    const READ_PREFIXES = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];
    const FORBIDDEN = ['DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'LOAD', 'OUTFILE', 'DUMPFILE', 'INFILE', 'SHUTDOWN', 'KILL', 'CALL', 'PREPARE', 'EXECUTE', 'HANDLER', 'FLUSH', 'RESET', 'PURGE', 'INSTALL', 'UNINSTALL', 'BENCHMARK', 'SLEEP'];

    public static function normalize(string $sql): string {
        $sql = trim($sql);

        // MySQL/MariaDB run the body of /*! ... */ and /*M! ... */ comments as code, so they would hide SQL from every check.
        if (preg_match('/\/\*\s*M?!/i', $sql)) {
            throw new ToolException('MySQL executable comments (/*! ... */) are not allowed.');
        }

        $sql = preg_replace('/^(\s*(--\s[^\n]*\n|#[^\n]*\n|\/\*.*?\*\/))+/s', '', $sql);
        $sql = rtrim(trim($sql), ';');

        if ($sql === '') {
            throw new ToolException('Empty SQL statement.');
        }

        return $sql;
    }

    /** Statement with string literals and comments blanked out, for keyword checks. */
    public static function skeleton(string $sql): string {
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql);
        $s = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $s);
        $s = preg_replace('/`[^`]*`/', '``', $s);
        $s = preg_replace('/\/\*.*?\*\//s', ' ', $s);
        $s = preg_replace('/(--(?=\s|$)|#)[^\n]*/', ' ', $s); // MySQL: "--" starts a comment only when followed by whitespace

        return strtoupper($s);
    }

    /** skeleton() that keeps the names inside backticks: `information_schema`.`PROCESSLIST` must not hide from the checks. */
    public static function identSkeleton(string $sql): string {
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql);
        $s = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $s);
        $s = preg_replace('/`([^`]*)`/', ' $1 ', $s);
        $s = preg_replace('/\/\*.*?\*\//s', ' ', $s);
        $s = preg_replace('/(--(?=\s|$)|#)[^\n]*/', ' ', $s);

        return strtoupper($s);
    }

    /**
     * Hidden columns (passwords, tokens, owner-hidden fields) are found by their names; a query that renames the columns of
     * a table by position would carry them under other names (audit 2026-10-12: SELECT 1,2,... UNION SELECT * FROM customer).
     */
    public static function assertNoPositionalRename(string $skeleton): void {
        if (preg_match('/\b(UNION|INTERSECT|EXCEPT)\b/', $skeleton)) {
            throw new ToolException('UNION / INTERSECT / EXCEPT are not allowed: run the SELECTs one by one.');
        }

        // WITH name (a, b) AS (...) and (SELECT ...) AS t (a, b): column lists by position
        if (preg_match('/\bWITH\s+(RECURSIVE\s+)?(``|\w+)\s*\(/', $skeleton)
            || preg_match('/\)\s*(AS\s+)?(``|\w+)\s*\(\s*(``|\w+)\s*[,)]/', $skeleton, $m) && !in_array($m[2], self::KEYWORDS_BEFORE_PAREN, true)) {
            throw new ToolException('Column lists for a WITH query or a subquery are not allowed: name the columns inside the SELECT (AS alias).');
        }
    }

    /** Words that may stand between ")" and "(" in an ordinary query. */
    const KEYWORDS_BEFORE_PAREN = ['AND', 'OR', 'NOT', 'IN', 'ON', 'WHEN', 'THEN', 'ELSE', 'IS', 'LIKE', 'BETWEEN', 'EXISTS', 'OVER', 'XOR', 'DIV', 'MOD', 'IF', 'IFNULL', 'COALESCE', 'CONCAT', 'USING', 'WHERE', 'HAVING', 'SELECT', 'FROM', 'JOIN', 'BY', 'AS', 'END', 'CASE', 'ANY', 'ALL', 'SOME', 'VALUES', 'ROW', 'INTERVAL', 'REGEXP', 'RLIKE', 'SOUNDS'];

    /** Same length as $sql, with string literal contents and comments replaced by spaces (positions stay valid). */
    public static function maskedSameLength(string $sql): string {
        $blank = function ($m) { return str_repeat(' ', strlen($m[0])); };
        $s = preg_replace_callback("/'(?:[^'\\\\]|\\\\.)*'/s", function ($m) { return "'" . str_repeat(' ', strlen($m[0]) - 2) . "'"; }, $sql);
        $s = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s', function ($m) { return '"' . str_repeat(' ', strlen($m[0]) - 2) . '"'; }, $s);
        $s = preg_replace_callback('/\/\*.*?\*\//s', $blank, $s);

        return preg_replace_callback('/(--\s|#)[^\n]*/', $blank, $s);
    }

    /** The WHERE condition text of a single-table UPDATE / DELETE (from the original SQL), or null. */
    public static function whereClause(string $sql): ?string {
        $m = self::maskedSameLength($sql);

        if (!preg_match('/\bWHERE\b/i', $m, $w, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $w[0][1] + 5;
        $end = strlen($sql);

        if (preg_match('/\b(ORDER\s+BY|LIMIT)\b/i', $m, $x, PREG_OFFSET_CAPTURE, $start)) {
            $end = $x[0][1];
        }

        $where = trim(substr($sql, $start, $end - $start));

        return $where !== '' ? $where : null;
    }

    /** Statement with string literals and comments blanked out but identifiers kept (for table/column detection). */
    public static function identifiersOnly(string $sql): string {
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql);
        $s = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $s);
        $s = preg_replace('/\/\*.*?\*\//s', ' ', $s);
        $s = preg_replace('/(--(?=\s|$)|#)[^\n]*/', ' ', $s); // MySQL: "--" starts a comment only when followed by whitespace

        return $s;
    }

    public static function assertSingleStatement(string $skeleton): void {
        if (strpos($skeleton, ';') !== false) {
            throw new ToolException('Only a single SQL statement is allowed.');
        }
    }

    /** Functions that read server files or reveal server/session state. */
    const FORBIDDEN_FUNCTIONS = ['LOAD_FILE', 'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'USER', 'CURRENT_USER', 'SESSION_USER', 'SYSTEM_USER', 'CONNECTION_ID', 'DATABASE', 'SCHEMA', 'VERSION', 'SYS_EXEC', 'SYS_EVAL'];

    /** SHOW statements that only describe tables are allowed; everything else (variables, status, databases, grants, processlist...) is not. */
    const ALLOWED_SHOW = '/^\s*SHOW\s+(FULL\s+)?(TABLES|COLUMNS|FIELDS|INDEX|INDEXES|KEYS|CREATE\s+TABLE|TABLE\s+STATUS)\b/';

    public static function assertNoForbidden(string $skeleton): void {
        foreach (self::FORBIDDEN as $word) {
            if (preg_match('/\b' . $word . '\b/', $skeleton)) {
                throw new ToolException("Forbidden keyword in SQL: $word.");
            }
        }

        foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
            if (preg_match('/\b' . $fn . '\s*\(/', $skeleton)) {
                throw new ToolException("Function $fn() is not allowed.");
            }
        }

        if (preg_match('/@/', $skeleton)) {
            throw new ToolException('Server and user variables (@@name, @name) are not allowed.');
        }

        if (preg_match('/^\s*SHOW\b/', $skeleton) && !preg_match(self::ALLOWED_SHOW, $skeleton)) {
            throw new ToolException('Only SHOW TABLES / COLUMNS / INDEX / CREATE TABLE / TABLE STATUS are allowed.');
        }

    }

    /**
     * System schemas, checked on the names as written (backticks included): only information_schema.TABLES / COLUMNS /
     * STATISTICS / KEY_COLUMN_USAGE - PROCESSLIST would show the SQL of other connections (logins, tokens).
     */
    public static function assertSchemas(string $sql): void {
        $idents = self::identSkeleton($sql);

        if (preg_match('/\b(MYSQL|PERFORMANCE_SCHEMA|SYS)\s*\./', $idents)) {
            throw new ToolException('Access to system schemas is not allowed.');
        }

        if (preg_match_all('/\bINFORMATION_SCHEMA\b(\s*\.\s*(\w+))?/', $idents, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if (!in_array($m[2] ?? '', ['TABLES', 'COLUMNS', 'STATISTICS', 'KEY_COLUMN_USAGE'], true)) {
                    throw new ToolException('Only INFORMATION_SCHEMA.TABLES / COLUMNS / STATISTICS / KEY_COLUMN_USAGE are allowed (or use db_schema).');
                }
            }
        }
    }

    public static function firstWord(string $skeleton): string {
        if (preg_match('/^\s*\(?\s*([A-Z]+)/', $skeleton, $m)) {
            return $m[1];
        }

        return '';
    }

    public static function ensureLimit(string $sql, string $skeleton, int $max): string {
        $first = self::firstWord($skeleton);

        if (!in_array($first, ['SELECT', 'WITH'], true)) {
            return $sql;
        }

        // the statement's own LIMIT at its end: "LIMIT n", "LIMIT off, n", "LIMIT n OFFSET off" - capped to $max rows
        if (preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i', $sql, $m)) {
            return (int)$m[2] <= $max ? $sql : preg_replace('/\bLIMIT\s+\d+\s*,\s*\d+\s*$/i', 'LIMIT ' . (int)$m[1] . ', ' . $max, $sql);
        }

        if (preg_match('/\bLIMIT\s+(\d+)(\s+OFFSET\s+(\d+))?\s*$/i', $sql, $m)) {
            return (int)$m[1] <= $max ? $sql : preg_replace('/\bLIMIT\s+\d+((\s+OFFSET\s+\d+)?)\s*$/i', 'LIMIT ' . $max . '$1', $sql);
        }

        // no LIMIT of its own at the end (one inside a subquery does not count): the database would hand over every row
        // and PHP would hold them all before keeping $max - one big table could exhaust the memory of a shared host
        return $sql . ' LIMIT ' . $max;
    }
}
