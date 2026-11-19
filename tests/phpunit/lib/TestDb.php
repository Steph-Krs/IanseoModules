<?php
/**
 * Direct access to the test database, beside ianseo's own connections (safe_r_sql / safe_w_sql).
 *
 * Reset between two tests: ianseo reads and writes through two separate connections, so a
 * transaction rolled back at the end of a test does not work (the read side would not see
 * the writes). Each table is compared with the reference copy <database>_ref made by
 * bin/phpunit.php instead: a table empty in the reference is emptied when needed; a filled one
 * (parameters, base data of ianseo) is reloaded when its checksum changed.
 * DELETE rather than TRUNCATE: ids do not start again from 1, so the static caches of the
 * modules (keyed by competition) never mix two tests.
 */
final class TestDb
{
    /** @var mysqli|null */
    private static $link = null;
    /** @var array<string,bool> table => filled in the reference */
    private static $refFilled = array();
    /** @var array<string,string> table => checksum in the reference */
    private static $refChecksum = array();

    public static function name(): string
    {
        return (string) getenv('BENCH_DB');
    }

    public static function link(): mysqli
    {
        if (self::$link === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$link = new mysqli(getenv('BENCH_DB_HOST') ?: 'db', getenv('BENCH_DB_USER') ?: 'root',
                getenv('BENCH_DB_PASS') ?: 'bench', self::name());
            self::$link->set_charset('utf8mb4');
            // The session mode of ianseo's own connections (Common/Fun_DB.inc.php): the made-up
            // rows fill only the columns a test needs, the others take their implicit value.
            self::$link->query("SET SESSION sql_mode = 'NO_UNSIGNED_SUBTRACTION'");
            mysqli_report(MYSQLI_REPORT_OFF);   // leaves the behaviour of ianseo's connections alone
        }
        return self::$link;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(string $sql): array
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $r = self::link()->query($sql);
            return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : array();
        } finally {
            mysqli_report(MYSQLI_REPORT_OFF);
        }
    }

    /** @return array<string,mixed>|null */
    public static function row(string $sql): ?array
    {
        $r = self::rows($sql);
        return $r[0] ?? null;
    }

    /** @return mixed First column of the first row, null when there is none. */
    public static function value(string $sql)
    {
        $r = self::row($sql);
        return $r === null ? null : reset($r);
    }

    public static function exec(string $sql): void
    {
        self::rows($sql);
    }

    public static function quote(string $v): string
    {
        return "'" . self::link()->real_escape_string($v) . "'";
    }

    /** Puts the database back in its reference state (see the header). */
    public static function reset(): void
    {
        $db = self::name();
        $ref = $db . '_ref';
        if (!self::$refFilled) {
            foreach (self::tables($ref) as $t) self::$refFilled[$t] = false;
            foreach (self::nonEmpty($ref, array_keys(self::$refFilled)) as $t) self::$refFilled[$t] = true;
            $filled = array_keys(array_filter(self::$refFilled));
            if ($filled) {
                foreach (self::rows('CHECKSUM TABLE ' . self::list($ref, $filled)) as $r) {
                    self::$refChecksum[substr($r['Table'], strlen($ref) + 1)] = (string) $r['Checksum'];
                }
            }
        }

        $current = self::tables($db);
        self::exec('SET FOREIGN_KEY_CHECKS = 0');
        // A table created during a test (lazy creation by a module) is kept, emptied: the module
        // believes it created (flag in $_SESSION) for the following tests.
        $known = array_values(array_intersect($current, array_keys(self::$refFilled)));
        $empty = array_values(array_merge(array_diff($current, $known), array_filter($known, function ($t) {
            return !self::$refFilled[$t];
        })));
        foreach (self::nonEmpty($db, $empty) as $t) self::exec("DELETE FROM `$db`.`$t`");
        $filled = array_values(array_filter($known, function ($t) {
            return self::$refFilled[$t];
        }));
        if ($filled) {
            foreach (self::rows('CHECKSUM TABLE ' . self::list($db, $filled)) as $r) {
                $t = substr($r['Table'], strlen($db) + 1);
                if ((string) $r['Checksum'] === self::$refChecksum[$t]) continue;
                self::exec("DELETE FROM `$db`.`$t`");
                self::exec("INSERT INTO `$db`.`$t` SELECT * FROM `$ref`.`$t`");
            }
        }
        self::exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    private static function tables(string $schema): array
    {
        return array_column(self::rows("SELECT TABLE_NAME AS t FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = " . self::quote($schema) . " AND TABLE_TYPE = 'BASE TABLE'"), 't');
    }

    /**
     * @param list<string> $tables
     * @return list<string> Those holding at least one row (a single query).
     */
    private static function nonEmpty(string $schema, array $tables): array
    {
        if (!$tables) return array();
        $parts = array();
        foreach ($tables as $t) $parts[] = "SELECT " . self::quote($t) . " AS t FROM DUAL WHERE EXISTS (SELECT 1 FROM `$schema`.`$t`)";
        return array_column(self::rows(implode(' UNION ALL ', $parts)), 't');
    }

    /** @param list<string> $tables */
    private static function list(string $schema, array $tables): string
    {
        return implode(', ', array_map(function ($t) use ($schema) {
            return "`$schema`.`$t`";
        }, $tables));
    }
}
