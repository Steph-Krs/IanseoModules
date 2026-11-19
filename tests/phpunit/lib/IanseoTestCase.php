<?php
use PHPUnit\Framework\TestCase;

/**
 * Base of the integration tests: the database is put back in its reference state before each
 * test. A module's own base class loads its libraries (AUTH/AuthTestCase.php).
 */
abstract class IanseoTestCase extends TestCase
{
    protected function setUp(): void
    {
        TestDb::reset();
    }

    /**
     * On an SQL error, ianseo's safe_error() first calls header(), which PHPUnit reports as
     * "headers already sent": the SQL error itself is added to the report.
     */
    protected function onNotSuccessfulTest(Throwable $t): void
    {
        foreach (array('write' => 'WRIT_CON', 'read' => 'READ_CON') as $side => $global) {
            $con = $GLOBALS[$global] ?? null;
            if ($con instanceof mysqli && $con->errno) {
                fwrite(STDERR, "\n  last SQL error of ianseo ($side connection): {$con->errno} {$con->error}\n");
            }
        }
        throw $t;
    }

    /**
     * Load files of a module, in the order its pages load them.
     * @param list<string> $files Paths inside Modules/Custom/<module>/.
     */
    public static function loadModule(string $module, array $files): void
    {
        foreach ($files as $f) require_once HTDOCS . '/Modules/Custom/' . $module . '/' . $f;
    }
}
