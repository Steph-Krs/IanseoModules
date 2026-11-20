<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AuthTestCase.php';

/**
 * Pure functions of AUTH (no database access): targets, accounts, licences, faces, till.
 */
final class AuthPureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        IanseoTestCase::loadModule('AUTH', array_merge(AuthTestCase::LIBS, array('booking/lib/cohabitation.php')));
    }

    public function deskTargets(): array
    {
        return array(
            'placed'           => array(4, 'B', '4B'),
            'lowercase letter' => array(12, 'c', '12C'),
            'letter in spaces' => array(3, ' a ', '3A'),
            'no target'        => array(0, 'A', ''),
            'text from the db' => array('7', 'D', '7D'),
            'null'             => array(null, null, ''),
        );
    }

    /**
     * @dataProvider deskTargets
     * @param mixed $target
     * @param mixed $letter
     */
    public function testDeskTarget($target, $letter, string $expected): void
    {
        $this->assertSame($expected, shp_desk_target($target, $letter));
    }

    public function testTargetNoFollowsIanseoFormat(): void
    {
        $this->assertSame('1004A', bk_target_no(1, 4, 'a'));
        $this->assertSame('2012C', bk_target_no('2', '12', 'C'));
        $this->assertSame('3100B', bk_target_no(3, 100, 'B'));
    }

    public function testAccountKeys(): void
    {
        $this->assertSame('9000123H', bk_account_key(' 9000123H ', 12));
        $this->assertSame('#12', bk_account_key('', 12));
        $this->assertSame(12, bk_account_enid('#12'));
        $this->assertSame(0, bk_account_enid('9000123H'));
        $this->assertTrue(shp_desk_account_ok('9000123H'));
        $this->assertTrue(shp_desk_account_ok('#12'));
        $this->assertTrue(shp_desk_account_ok('IT-12345'), 'licence of another federation');
        $this->assertFalse(shp_desk_account_ok("9000123H' OR 1=1"));
        $this->assertFalse(shp_desk_account_ok(''));
        $this->assertFalse(shp_desk_account_ok('#'));
    }

    public function testAccountSqlIsSafe(): void
    {
        $this->assertSame("EnId = 12 AND EnCode = ''", shp_desk_account_sql('#12'));
        $this->assertSame("EnCode = 'x\\' OR \\'1\\'=\\'1'", shp_desk_account_sql("x' OR '1'='1"), 'escaped by StrSafe_DB');
    }

    public function testCleanLicence(): void
    {
        $this->assertSame('9000123H', bk_clean_licence(" 9000 123h\t"));
        $this->assertSame('', bk_clean_licence(null));
    }

    public function faces(): array
    {
        return array(
            'triple spot 40' => array('Trispot 40 cm', 'salle', 1),
            'face 60'        => array('Blason 60 cm', 'salle', 2),
            'face 80'        => array('Blason 80 cm', 'salle', 4),
            'unknown indoor' => array('', 'salle', 4),
            'reduced 80'     => array('Blason réduit 80 cm', 'ext', 1),
            'full 122'       => array('Blason complet 122 cm', 'ext', 3),
        );
    }

    /** @dataProvider faces */
    public function testFaceCost(string $name, string $disc, int $cost): void
    {
        $this->assertSame($cost, bk_face_cost(bk_face_class($name), $disc));
    }

    public function testDisciplineOfIanseoTypes(): void
    {
        $this->assertSame('salle', bk_comp_discipline(6)['key']);
        $this->assertSame('salle', bk_comp_discipline(7)['key'], 'Indoor 25, not a course');
        $this->assertSame('campagne', bk_comp_discipline(9)['key'], 'Hunter-Field, not an outdoor shoot');
        $this->assertSame('ext', bk_comp_discipline(1)['key']);
    }

    public function testCashTotals(): void
    {
        $line = function (float $amount, string $method, string $kind = 'payment', string $by = 'Volunteer A') {
            return array('amount' => $amount, 'method' => $method, 'kind' => $kind, 'staff' => 0, 'by' => $by);
        };
        $t = shp_cash_totals(array(
            $line(20, 'cash'),
            $line(0.1, 'cash'),
            $line(0.2, 'cash', 'payment', 'Volunteer B'),
            $line(15, 'cheque'),
            $line(-20, 'cash', 'cancel'),
        ));
        $this->assertSame(15.3, $t['total'], 'rounded to the cent despite floats');
        $this->assertSame(4, $t['n'], 'the counter-line is not counted');
        $this->assertSame(array('amount' => 0.3, 'n' => 3), $t['methods']['cash']);
        $this->assertSame(array('' => 15.1, 'cash' => 0.1, 'cheque' => 15.0), $t['who']['Volunteer A']);
        $this->assertSame(array('' => 0.2, 'cash' => 0.2), $t['who']['Volunteer B']);
    }

    public function testDates(): void
    {
        $this->assertSame('14/11/2026', bk_date_dmy('2026-11-14 08:30:00'));
        $this->assertSame('', bk_date_iso('0000-00-00'));
        $this->assertSame('2026-11-14', bk_date_iso('2026-11-14 08:30:00'));
    }
}
