<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * "Offer the rest": a 'waiver' line of the payment journal settles an account without money.
 * It is never a refund to make, never in the till of the check-in desk, never money collected.
 */
final class LedgerWaiverTest extends AuthTestCase
{
    private const LIC = '9200101A';

    /** @var int */
    private $tour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tour = Fixtures::competition();
        Fixtures::booking($this->tour, 2, 1);
        $this->fee(12);
        Fixtures::archer($this->tour, 1, 0, '', array('licence' => self::LIC));
    }

    private function fee(float $fee): void
    {
        TestDb::exec("UPDATE BookingCompetitions SET BcFee = " . sprintf('%.2F', $fee) . " WHERE BcTournament = {$this->tour}");
    }

    /** @return array<string,mixed> */
    private function account(): array
    {
        return bk_account($this->tour, self::LIC);
    }

    private function organiserStaff(string $status = 'active'): int
    {
        TestDb::exec("INSERT INTO ShopStaff SET SfTournament = {$this->tour}, SfKind = 'ORGANISER', SfStatus = '$status',
            SfFamilyName = 'Organiser', SfGivenName = 'Test', SfCreated = NOW()");
        return (int) TestDb::value('SELECT LAST_INSERT_ID()');
    }

    // ---- The journal line ------------------------------------------------------------------------

    public function testOfferSettlesTheAccount(): void
    {
        $this->assertGreaterThan(0, bk_ledger_waive($this->tour, self::LIC, 12, 'Volunteer', 'Test organiser'));
        $a = $this->account();
        $this->assertSame(0.0, $a['remaining']);
        $this->assertSame(12.0, $a['paid']);
        $this->assertSame(12.0, $a['waived']);
        $this->assertSame('settled', bk_account_state($a));
    }

    public function testOfferNeedsAReasonAndAnAmount(): void
    {
        $this->assertSame(0, bk_ledger_waive($this->tour, self::LIC, 12, '  ', 'Test'));
        $this->assertSame(0, bk_ledger_waive($this->tour, self::LIC, 0, 'Volunteer', 'Test'));
        $this->assertSame(0, (int) TestDb::value("SELECT COUNT(*) FROM BookingLedger WHERE BlgTournament = {$this->tour}"));
    }

    public function testOfferHasNoMeansOfPayment(): void
    {
        $id = bk_ledger_add($this->tour, self::LIC, 'waiver', 12, 'cheque', '', 'Offered', 'Test', 0, array('ref' => '1234567'));
        $row = TestDb::row("SELECT BlgKind, BlgMethod, BlgRef, BlgAmount FROM BookingLedger WHERE BlgId = $id");
        $this->assertSame(array('BlgKind' => 'waiver', 'BlgMethod' => '', 'BlgRef' => '', 'BlgAmount' => '12.00'), $row);
    }

    public function testPaymentAndOfferTogether(): void
    {
        bk_ledger_add($this->tour, self::LIC, 'payment', 5, 'cash', '', 'Paid', 'Test');
        bk_ledger_waive($this->tour, self::LIC, 7, 'Judge', 'Test');
        $a = $this->account();
        $this->assertSame(12.0, $a['paid']);
        $this->assertSame(7.0, $a['waived']);
        $this->assertSame(0.0, $a['remaining']);
    }

    public function testCancelledOfferIsOwedAgain(): void
    {
        $id = bk_ledger_waive($this->tour, self::LIC, 12, 'Mistake', 'Test');
        $this->assertGreaterThan(0, bk_ledger_cancel($this->tour, $id, 'Test'));
        $a = $this->account();
        $this->assertSame(12.0, $a['remaining']);
        $this->assertSame(0.0, $a['waived']);
        $this->assertSame('due', bk_account_state($a));
    }

    public function testOfferIsNeverARefundToMake(): void
    {
        bk_ledger_waive($this->tour, self::LIC, 12, 'Volunteer', 'Test');
        $this->fee(0);   // the due drops below the offer
        $a = $this->account();
        $this->assertSame(0.0, $a['remaining']);
        $this->assertSame(0.0, $a['waived']);
        $this->assertSame('none', bk_account_state($a));
    }

    public function testMoneyAboveTheDueIsStillARefundToMake(): void
    {
        bk_ledger_add($this->tour, self::LIC, 'payment', 12, 'cash', '', 'Paid', 'Test');
        bk_ledger_waive($this->tour, self::LIC, 3, 'Goodwill', 'Test');   // a mistake: nothing was left
        $this->fee(10);
        $a = $this->account();
        $this->assertSame(0.0, $a['waived']);
        $this->assertSame(-2.0, $a['remaining']);
        $this->assertSame('over', bk_account_state($a));
    }

    public function testListAgreesWithTheAccount(): void
    {
        bk_ledger_add($this->tour, self::LIC, 'payment', 4, 'cash', '', 'Paid', 'Test');
        $cancelled = bk_ledger_waive($this->tour, self::LIC, 8, 'Mistake', 'Test');
        bk_ledger_cancel($this->tour, $cancelled, 'Test');
        bk_ledger_waive($this->tour, self::LIC, 8, 'Volunteer', 'Test');

        $one = $this->account();
        $row = bk_accounts($this->tour)[self::LIC];
        foreach (array('paid', 'waived', 'remaining') as $k) $this->assertSame($one[$k], $row[$k], $k);
        $this->assertSame(array(12.0, 8.0, 0.0), array($row['paid'], $row['waived'], $row['remaining']));
    }

    // ---- Check-in desk ---------------------------------------------------------------------------

    public function testOfferAtTheDeskIsNotInTheTill(): void
    {
        Fixtures::shop($this->tour);
        $pay = Fixtures::deskPayment($this->tour, '9200102A', 10, 'cash');
        $offer = Fixtures::deskPayment($this->tour, '9200103A', 12, '', 1, 'waiver');
        Fixtures::deskPayment($this->tour, '9200104A', 6, '', 1, 'waiver');
        bk_ledger_cancel($this->tour, $offer, 'Test', 1);

        $this->assertSame(array($pay), array_column(shp_cash_lines($this->tour, 'open'), 'id'));
    }

    public function testDeskOffersTheRest(): void
    {
        Fixtures::shop($this->tour);
        $me = shp_staff_get($this->organiserStaff());
        bk_ledger_add($this->tour, self::LIC, 'payment', 2, 'cash', '', 'Paid', 'Test');

        $r = shp_desk_waive($me, self::LIC, 'Volunteer', '');
        $this->assertSame(0, $r['error']);
        $line = TestDb::row("SELECT BlgKind, BlgAmount, BlgStaff FROM BookingLedger WHERE BlgId = " . intval($r['line']));
        $this->assertSame(array('BlgKind' => 'waiver', 'BlgAmount' => '10.00', 'BlgStaff' => (string) $me->SfId), $line);
        $this->assertSame(0.0, $this->account()['remaining']);
    }

    public function testDeskOfferSentTwiceWritesOnce(): void
    {
        Fixtures::shop($this->tour);
        $me = shp_staff_get($this->organiserStaff());
        $idem = '0f8c2a3e-1b2c-4d5e-8f90-123456789abc';
        $this->assertSame(0, shp_desk_waive($me, self::LIC, 'Volunteer', $idem)['error']);
        $this->assertSame(0, shp_desk_waive($me, self::LIC, 'Volunteer', $idem)['error']);
        $this->assertSame(1, (int) TestDb::value("SELECT COUNT(*) FROM BookingLedger WHERE BlgTournament = {$this->tour}"));
    }

    public function testDeskOfferRefusals(): void
    {
        Fixtures::shop($this->tour);
        $me = shp_staff_get($this->organiserStaff());
        $this->assertSame('reason', shp_desk_waive($me, self::LIC, '', '')['code']);
        $this->assertSame('account', shp_desk_waive($me, '9299999Z', 'Volunteer', '')['code']);
        $this->assertSame('forbidden', shp_desk_waive(shp_staff_get($this->organiserStaff('pending')), self::LIC, 'Volunteer', '')['code']);

        bk_ledger_waive($this->tour, self::LIC, 12, 'Volunteer', 'Test');
        $this->assertSame('nothing_due', shp_desk_waive($me, self::LIC, 'Again', '')['code']);
    }
}
