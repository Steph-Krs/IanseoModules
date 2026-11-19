<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Check-in desk: the target shown is ianseo's (QuTarget + QuLetter), even when changed or
 * removed in ianseo after the placement of online registrations. Regression of QuTargetNo,
 * which the core screens no longer write (issue 7, AUTH 1.5.0).
 */
final class DeskTargetTest extends AuthTestCase
{
    /** @var int */
    private $tour;
    /** @var int */
    private $moved;
    /** @var int */
    private $stays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tour = Fixtures::competition();
        $this->stays = Fixtures::archer($this->tour, 1, 1, 'A', array('family' => 'Alphatest', 'licence' => '9000001A'));
        $this->moved = Fixtures::archer($this->tour, 1, 1, 'C', array('family' => 'Foxtrotest', 'licence' => '9000002B'));
    }

    /** @return array<int,array<string,mixed>> Rows of the desk list, by EnId. */
    private function rows(): array
    {
        return array_column(shp_desk_rows($this->tour), null, 'entry');
    }

    public function testListShowsPlacementOfOnlineRegistration(): void
    {
        $rows = $this->rows();
        $this->assertSame('1A', $rows[$this->stays]['target']);
        $this->assertSame('1C', $rows[$this->moved]['target']);
    }

    public function testListFollowsTargetChangedInIanseo(): void
    {
        Fixtures::ianseoSetTarget($this->moved, 4, 'B');
        $this->assertSame('1001C', TestDb::value("SELECT QuTargetNo FROM Qualifications WHERE QuId = {$this->moved}"),
            'the core does not rewrite QuTargetNo: the case of the bug');

        $rows = $this->rows();
        $this->assertSame('4B', $rows[$this->moved]['target']);
        $this->assertSame('1A', $rows[$this->stays]['target']);
    }

    public function testListSortsOnIanseoTarget(): void
    {
        Fixtures::ianseoSetTarget($this->moved, 4, 'B');
        $later = Fixtures::archer($this->tour, 1, 2, 'A', array('family' => 'Bravotest'));

        $order = array_column(shp_desk_rows($this->tour), 'entry');
        $this->assertSame(array($this->stays, $later, $this->moved), $order, '1A, 2A then 4B');
    }

    public function testTargetRemovedInIanseoIsNoLongerShown(): void
    {
        Fixtures::ianseoClearTarget($this->moved);
        $this->assertSame('', $this->rows()[$this->moved]['target']);
    }

    public function testArcherFileShowsIanseoTarget(): void
    {
        Fixtures::ianseoSetTarget($this->moved, 4, 'B');
        $entries = shp_desk_entries($this->tour, '9000002B');
        $this->assertCount(1, $entries);
        $this->assertSame(4, (int) $entries[$this->moved]->QuTarget);
        $this->assertSame('4B', shp_desk_target($entries[$this->moved]->QuTarget, $entries[$this->moved]->QuLetter));
    }

    public function testSearchShowsIanseoTarget(): void
    {
        Fixtures::ianseoSetTarget($this->moved, 4, 'B');
        $res = shp_desk_search($this->tour, 'Foxtrotest');
        $this->assertCount(1, $res['rows']);
        $this->assertSame(array(array('session' => 1, 'target' => '4B')), $res['rows'][0]['places']);
    }
}
