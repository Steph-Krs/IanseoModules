<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Automatic placement of online registrations (booking/lib/targets.php, bk_assign_session):
 * never moves a placed archer, keeps to the number of archers of a club per target when it can,
 * and writes a consistent target (QuTarget, QuLetter, QuTargetNo).
 */
final class TargetAssignTest extends AuthTestCase
{
    private function cfg(int $maxPerClub = 2): object
    {
        return (object) array('BcMaxPerClubPerTarget' => $maxPerClub, 'BcWishLetter' => 0, 'BcWishWith' => 0, 'BcWishFree' => 0);
    }

    /** @return array<int,array{t:int,l:string,no:string,club:string}> By EnId. */
    private function places(int $tour, int $session): array
    {
        $out = array();
        foreach (TestDb::rows("SELECT EnId, QuTarget, QuLetter, QuTargetNo, CoCode FROM Entries
                INNER JOIN Qualifications ON QuId = EnId LEFT JOIN Countries ON CoId = EnCountry
                WHERE EnTournament = $tour AND QuSession = $session") as $r) {
            $out[(int) $r['EnId']] = array('t' => (int) $r['QuTarget'], 'l' => (string) $r['QuLetter'],
                'no' => (string) $r['QuTargetNo'], 'club' => (string) $r['CoCode']);
        }
        return $out;
    }

    public function testEveryoneIsPlacedWithAConsistentTarget(): void
    {
        $tour = Fixtures::competition(1, '+3 days', 3);
        for ($i = 0; $i < 6; $i++) Fixtures::archer($tour, 1);

        $res = bk_assign_session($tour, 1, $this->cfg());
        $this->assertSame(6, $res['places']);
        $this->assertSame(0, $res['restants']);

        $seen = array();
        foreach ($this->places($tour, 1) as $p) {
            $this->assertGreaterThan(0, $p['t']);
            $this->assertSame(bk_target_no(1, $p['t'], $p['l']), $p['no'], 'QuTargetNo follows QuTarget and QuLetter');
            $this->assertArrayNotHasKey($p['t'] . $p['l'], $seen, 'two archers on the same place');
            $seen[$p['t'] . $p['l']] = true;
        }
    }

    public function testAnArcherAlreadyPlacedIsNeverMoved(): void
    {
        $tour = Fixtures::competition(1, '+3 days', 3);
        $manual = Fixtures::archer($tour, 1);
        Fixtures::ianseoSetTarget($manual, 2, 'C');   // placed by hand in ianseo
        for ($i = 0; $i < 4; $i++) Fixtures::archer($tour, 1);

        bk_assign_session($tour, 1, $this->cfg());

        $p = $this->places($tour, 1);
        $this->assertSame(array('t' => 2, 'l' => 'C'), array('t' => $p[$manual]['t'], 'l' => $p[$manual]['l']));
        foreach ($p as $id => $q) {
            if ($id !== $manual) $this->assertNotSame('2C', $q['t'] . $q['l'], 'the place taken by hand stays his');
        }
    }

    public function testClubsAreSpreadOverTheTargets(): void
    {
        $tour = Fixtures::competition(1, '+3 days', 2);   // 2 targets × 4 places
        $a = Fixtures::club($tour, '9966001', 'Club A');
        $b = Fixtures::club($tour, '9966002', 'Club B');
        for ($i = 0; $i < 4; $i++) {
            Fixtures::archer($tour, 1, 0, '', array('club' => $a));
            Fixtures::archer($tour, 1, 0, '', array('club' => $b));
        }

        $res = bk_assign_session($tour, 1, $this->cfg(2));
        $this->assertSame(8, $res['places']);
        $this->assertSame(0, $res['compromis']);

        $perTarget = array();
        foreach ($this->places($tour, 1) as $p) $perTarget[$p['t']][$p['club']] = ($perTarget[$p['t']][$p['club']] ?? 0) + 1;
        foreach ($perTarget as $t => $clubs) {
            foreach ($clubs as $club => $n) $this->assertLessThanOrEqual(2, $n, "target $t: $n archers of club $club");
        }
    }

    public function testFullDepartureLeavesTheRestUnplaced(): void
    {
        $tour = Fixtures::competition(1, '+3 days', 1);   // 1 target × 4 places
        for ($i = 0; $i < 6; $i++) Fixtures::archer($tour, 1);

        $res = bk_assign_session($tour, 1, $this->cfg(4));
        $this->assertSame(4, $res['places']);
        $this->assertSame(2, $res['restants']);
    }

    public function testRegistrationAwaitingValidationIsNotPlaced(): void
    {
        $tour = Fixtures::competition(1, '+3 days', 2);
        $waiting = Fixtures::archer($tour, 1);
        TestDb::exec("INSERT INTO BookingRegistrations SET BrTournament = $tour, BrEnId = $waiting, BrLicence = 'X',
            BrByRole = 'SELF', BrCreated = NOW(), BrValidated = 0");
        $other = Fixtures::archer($tour, 1);

        bk_assign_session($tour, 1, $this->cfg());

        $p = $this->places($tour, 1);
        $this->assertSame(0, $p[$waiting]['t']);
        $this->assertGreaterThan(0, $p[$other]['t']);
    }

    public function testUnknownFaceTakesTheWholeTarget(): void
    {
        $tour = Fixtures::competition(1, '+3 days', 2);
        for ($i = 0; $i < 3; $i++) Fixtures::archer($tour, 1, 0, '', array('face' => 0));   // unknown face

        $res = bk_assign_session($tour, 1, $this->cfg(4));
        $this->assertSame(2, $res['places'], 'when in doubt, one archer per target');
        $this->assertSame(1, $res['restants']);
    }

    public function testOtherDepartureIsUntouched(): void
    {
        $tour = Fixtures::competition(2, '+3 days', 2);
        Fixtures::archer($tour, 1);
        $second = Fixtures::archer($tour, 2);

        bk_assign_session($tour, 1, $this->cfg());

        $this->assertSame(0, $this->places($tour, 2)[$second]['t']);
    }
}
