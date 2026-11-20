<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Till of the check-in desk: what goes into the till, closing per departure, undoing the last
 * closing (issue 5, AUTH 1.5.0).
 */
final class DeskCashTest extends AuthTestCase
{
    /** @var int */
    private $tour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tour = Fixtures::competition(3);
        Fixtures::shop($this->tour);
    }

    /**
     * @param string|int $view
     * @return list<int>
     */
    private function ids($view): array
    {
        return array_column(shp_cash_lines($this->tour, $view), 'id');
    }

    public function testOnlyDeskPaymentsAreInTheTill(): void
    {
        $desk = Fixtures::deskPayment($this->tour, '9200001A', 20, 'cash');
        Fixtures::deskPayment($this->tour, '9200002A', 15, 'cheque', 0);   // Payments page: not at the desk
        TestDb::exec("UPDATE BookingLedger SET BlgStand = 1 WHERE BlgId = "
            . Fixtures::deskPayment($this->tour, '9200003A', 4, 'cash'));   // a stand of the food & shop
        TestDb::exec("UPDATE BookingLedger SET BlgProvider = 'helloasso' WHERE BlgId = "
            . Fixtures::deskPayment($this->tour, '9200004A', 12, 'card'));   // online payment
        TestDb::exec("UPDATE BookingLedger SET BlgStatus = 'pending' WHERE BlgId = "
            . Fixtures::deskPayment($this->tour, '9200005A', 9, 'cash'));   // not paid yet

        $this->assertSame(array($desk), $this->ids('open'));
    }

    public function testOtherCompetitionIsNotInTheTill(): void
    {
        $other = Fixtures::competition();
        Fixtures::deskPayment($other, '9200001A', 20);
        $this->assertSame(array(), $this->ids('open'));
    }

    public function testCloseTiesOpenLinesToTheDeparture(): void
    {
        $a = Fixtures::deskPayment($this->tour, '9200001A', 20, 'cash');
        $b = Fixtures::deskPayment($this->tour, '9200002A', 11.5, 'cheque');

        $close = shp_cash_close($this->tour, 1, 'Test organiser');
        $this->assertGreaterThan(0, $close);
        $this->assertSame(array(), $this->ids('open'));
        $this->assertSame(array($a, $b), $this->ids(1));

        $closes = shp_cash_closes($this->tour);
        $this->assertSame(1, $closes[$close]['session']);
        $this->assertSame(31.5, $closes[$close]['total']);
        $this->assertSame(2, $closes[$close]['n']);
    }

    public function testCloseWithNothingOpenIsRefused(): void
    {
        $this->assertSame(0, shp_cash_close($this->tour, 1, 'Test organiser'));
        $this->assertSame(0, (int) TestDb::value("SELECT COUNT(*) FROM ShopDeskCloses WHERE DcTournament = {$this->tour}"));
    }

    public function testCloseRefusesAnInvalidDeparture(): void
    {
        Fixtures::deskPayment($this->tour, '9200001A', 20);
        $this->assertSame(0, shp_cash_close($this->tour, 0, 'x'));
        $this->assertSame(0, shp_cash_close($this->tour, 256, 'x'));
        $this->assertCount(1, $this->ids('open'));
    }

    public function testDepartureClosedTwiceSumsItsClosings(): void
    {
        Fixtures::deskPayment($this->tour, '9200001A', 20);
        shp_cash_close($this->tour, 1, 'x');
        Fixtures::deskPayment($this->tour, '9200002A', 7);   // late payment
        shp_cash_close($this->tour, 1, 'x');

        $this->assertSame(27.0, shp_cash_totals(shp_cash_lines($this->tour, 1))['total']);
        $this->assertCount(2, shp_cash_closes($this->tour));
    }

    public function testOnlyTheLastClosingCanBeUndone(): void
    {
        $a = Fixtures::deskPayment($this->tour, '9200001A', 20);
        $first = shp_cash_close($this->tour, 1, 'x');
        $b = Fixtures::deskPayment($this->tour, '9200002A', 7);
        $last = shp_cash_close($this->tour, 2, 'x');

        $this->assertFalse(shp_cash_reopen($this->tour, $first), 'not an older closing');
        $this->assertTrue(shp_cash_reopen($this->tour, $last));
        $this->assertSame(array($b), $this->ids('open'), 'its lines come back into the open till');
        $this->assertSame(array($a), $this->ids(1));
        $this->assertTrue(shp_cash_reopen($this->tour, $first), 'now the last one');
    }

    public function testReopenOfAnotherCompetitionIsRefused(): void
    {
        $other = Fixtures::competition();
        Fixtures::deskPayment($other, '9200001A', 20);
        $close = shp_cash_close($other, 1, 'x');

        $this->assertFalse(shp_cash_reopen($this->tour, $close));
        $this->assertSame(1, (int) TestDb::value("SELECT COUNT(*) FROM ShopDeskCloses WHERE DcId = $close"));
    }

    public function testCancellationCountsNegativeWhereverItIsMade(): void
    {
        $pay = Fixtures::deskPayment($this->tour, '9200001A', 20, 'cash');
        shp_cash_close($this->tour, 1, 'x');
        // Cancelled later from the Payments page (by the organiser, not a volunteer).
        $res = bk_ledger_cancel($this->tour, $pay, 'Test organiser');
        $this->assertNotEmpty($res);

        $open = shp_cash_lines($this->tour, 'open');
        $this->assertCount(1, $open, 'the counter-line goes into the open till');
        $this->assertSame(-20.0, $open[0]['amount']);
        $this->assertSame(0, shp_cash_totals($open)['n'], 'a counter-line does not count as a payment taken');
    }

    public function testTotalsByMethod(): void
    {
        Fixtures::deskPayment($this->tour, '9200001A', 20, 'cash');
        Fixtures::deskPayment($this->tour, '9200002A', 10.1, 'cash');
        Fixtures::deskPayment($this->tour, '9200003A', 15, 'cheque');

        $t = shp_cash_totals(shp_cash_lines($this->tour, 'open'));
        $this->assertSame(45.1, $t['total']);
        $this->assertSame(3, $t['n']);
        $this->assertSame(array('amount' => 30.1, 'n' => 2), $t['methods']['cash']);
        $this->assertSame(array('amount' => 15.0, 'n' => 1), $t['methods']['cheque']);
    }
}
