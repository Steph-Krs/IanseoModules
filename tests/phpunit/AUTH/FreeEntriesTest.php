<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Free registrations in the tariff: the archers of the organising club, or of a club of the free
 * list, owe nothing, whatever the category, departure, origin and rank.
 */
final class FreeEntriesTest extends AuthTestCase
{
    private const ORG = '9966001';
    private const PARTNER = '9966002';
    private const OTHER = '9966003';

    /** @var int */
    private $tour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tour = Fixtures::competition();
        TestDb::exec("UPDATE Tournament SET ToCommitee = '" . self::ORG . "' WHERE ToId = {$this->tour}");
        Fixtures::booking($this->tour, 2, 1);
    }

    /** @param array<string,mixed> $pricing */
    private function tariff(float $fee, array $pricing): void
    {
        TestDb::exec("UPDATE BookingCompetitions SET BcFee = " . sprintf('%.2F', $fee) . ", BcPricing = "
            . TestDb::quote(json_encode($pricing)) . " WHERE BcTournament = {$this->tour}");
    }

    private function archerOf(string $club, string $licence, int $session = 1): int
    {
        return Fixtures::archer($this->tour, $session, 0, '', array('licence' => $licence,
            'club' => Fixtures::club($this->tour, $club, 'Club ' . $club)));
    }

    // ---- Pure: normalisation and reason ---------------------------------------------------------

    public function testNormKeepsCleanUniqueClubCodes(): void
    {
        $p = bk_pricing_norm(array('free' => array('org' => 1, 'clubs' => ' 9966002, 9966002;ab-12 ,, ')));
        $this->assertTrue($p['free']['org']);
        $this->assertSame(array('9966002', 'AB12'), $p['free']['clubs']);
    }

    public function testNormWithoutFreeIsOff(): void
    {
        $p = bk_pricing_norm('');
        $this->assertSame(array('org' => false, 'clubs' => array()), $p['free']);
        $this->assertFalse(bk_pricing_has_free($p));
        $this->assertFalse(bk_pricing_is_advanced($p));
    }

    public function testFreeAloneMakesTheTariffDetailed(): void
    {
        // Otherwise the setting would not be saved (competition.php keeps BcPricing only when detailed).
        $this->assertTrue(bk_pricing_is_advanced(bk_pricing_norm(array('free' => array('org' => true)))));
        $this->assertTrue(bk_pricing_is_advanced(bk_pricing_norm(array('free' => array('clubs' => array('9966002'))))));
    }

    public function testReason(): void
    {
        $p = bk_pricing_norm(array('free' => array('org' => true, 'clubs' => array(self::PARTNER, self::ORG))));
        $this->assertSame('org', bk_free_reason($p, self::ORG, self::ORG));   // the organising club wins
        $this->assertSame('club', bk_free_reason($p, self::PARTNER, self::ORG));
        $this->assertSame('', bk_free_reason($p, self::OTHER, self::ORG));
        $this->assertSame('', bk_free_reason($p, '', ''), 'no club, no organiser: paying');
    }

    public function testOrganiserNotFreeWhenNotTicked(): void
    {
        $p = bk_pricing_norm(array('free' => array('org' => false, 'clubs' => array(self::PARTNER))));
        $this->assertSame('', bk_free_reason($p, self::ORG, self::ORG));
    }

    public function testOrganiserUnknownFreesNobody(): void
    {
        $p = bk_pricing_norm(array('free' => array('org' => true)));
        $this->assertSame('', bk_free_reason($p, self::ORG, ''));
    }

    public function testCalcBringsToZeroAndKeepsTheDetail(): void
    {
        $p = bk_pricing_norm(array('departures' => array('2' => 3), 'free' => array('org' => true)));
        $r = bk_price_calc(12, $p, 'CL', 'S1H', 2, '', 1, 'org');
        $this->assertSame(0.0, $r['total']);
        $this->assertCount(3, $r['lines']);
        $this->assertSame(bk_t('PriceFreeOrg'), $r['lines'][2]['label']);
        $this->assertSame(-15.0, $r['lines'][2]['amount']);
    }

    public function testCalcAlreadyZeroAddsNoLine(): void
    {
        $p = bk_pricing_norm(array('categories' => array(array('div' => array('CL'), 'price' => 0)), 'free' => array('org' => true)));
        $r = bk_price_calc(12, $p, 'CL', 'S1H', 1, '', 1, 'org');
        $this->assertSame(0.0, $r['total']);
        $this->assertCount(1, $r['lines']);
    }

    public function testCalcWithoutReasonIsUnchanged(): void
    {
        $p = bk_pricing_norm(array('free' => array('org' => true)));
        $this->assertSame(12.0, bk_price_of(12, $p, 'CL', 'S1H', 1, '', 1));
        $this->assertSame(12.0, bk_price_of(12, $p, 'CL', 'S1H', 1, '', 1, 'nonsense'));
    }

    public function testMinimumPriceIgnoresFreeClubs(): void
    {
        $this->assertSame(12.0, bk_price_min(12, bk_pricing_norm(array('free' => array('org' => true)))));
    }

    // ---- On the accounts of the Payments page ---------------------------------------------------

    public function testOrganisingClubOwesNothing(): void
    {
        $this->tariff(12, array('free' => array('org' => true)));
        $this->archerOf(self::ORG, '9100001A');
        $this->archerOf(self::OTHER, '9100002A');

        $this->assertSame(0.0, bk_account($this->tour, '9100001A')['due']);
        $this->assertSame(12.0, bk_account($this->tour, '9100002A')['due']);
    }

    public function testFreeListClubOwesNothing(): void
    {
        $this->tariff(12, array('free' => array('clubs' => array(self::PARTNER))));
        $this->archerOf(self::PARTNER, '9100003A');
        $this->archerOf(self::ORG, '9100004A');

        $a = bk_account($this->tour, '9100003A');
        $this->assertSame(0.0, $a['due']);
        $this->assertSame('none', bk_account_state($a));
        $this->assertSame(12.0, bk_account($this->tour, '9100004A')['due'], 'organiser not ticked: paying');
    }

    public function testTickingFreesRegistrationsAlreadyMade(): void
    {
        $this->tariff(12, array());
        $this->archerOf(self::ORG, '9100005A');
        $this->assertSame(12.0, bk_account($this->tour, '9100005A')['due']);

        $this->tariff(12, array('free' => array('org' => true)));
        $this->assertSame(0.0, bk_account($this->tour, '9100005A')['due']);
    }

    public function testPaymentAlreadyRecordedBecomesARefundToMake(): void
    {
        $this->tariff(12, array());
        $this->archerOf(self::ORG, '9100006A');
        $this->assertGreaterThan(0, bk_ledger_add($this->tour, '9100006A', 'payment', 12, 'cash', '', 'Paid', 'Test'));

        $this->tariff(12, array('free' => array('org' => true)));
        $a = bk_account($this->tour, '9100006A');
        $this->assertSame(-12.0, $a['remaining']);
        $this->assertSame('over', bk_account_state($a));
    }

    public function testEveryRegistrationOfAFreeArcherIsFree(): void
    {
        $this->tariff(12, array('rank' => array('2' => -4), 'free' => array('org' => true)));
        $club = Fixtures::club($this->tour, self::ORG, 'Club org');
        Fixtures::archer($this->tour, 1, 0, '', array('licence' => '9100007A', 'club' => $club));
        Fixtures::archer($this->tour, 2, 0, '', array('licence' => '9100007A', 'club' => $club));

        $a = bk_account($this->tour, '9100007A');
        $this->assertCount(2, $a['registrations']);
        $this->assertSame(0.0, $a['due']);
    }

    public function testClubTotalsOnTheList(): void
    {
        $this->tariff(10, array('free' => array('org' => true)));
        $this->archerOf(self::ORG, '9100008A');
        $this->archerOf(self::ORG, '9100009A');
        $this->archerOf(self::OTHER, '9100010A');

        $acc = bk_accounts($this->tour);
        $this->assertSame(0.0, $acc['9100008A']['due']);
        $this->assertSame(0.0, $acc['9100009A']['due']);
        $this->assertSame(10.0, $acc['9100010A']['due']);
    }
}
