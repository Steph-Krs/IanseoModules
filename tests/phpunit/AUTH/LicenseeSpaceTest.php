<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Licensee space: a participant keyed in by the organiser in ianseo shows among the archer's
 * registrations like an online registration, read live from Entries (issue 3, AUTH 1.5.0).
 */
final class LicenseeSpaceTest extends AuthTestCase
{
    private const LIC = '9100001K';

    /** @return list<object> */
    private function mine(): array
    {
        return bk_my_registrations(self::LIC);
    }

    public function testOrganiserEntryIsListedAsImport(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        $en = Fixtures::archer($tour, 2, 3, 'B', array('licence' => self::LIC));

        $mine = $this->mine();
        $this->assertCount(1, $mine);
        $this->assertSame('IMPORT', $mine[0]->BrByRole, 'shown as taken over from ianseo');
        $this->assertNull($mine[0]->BrId);
        $this->assertSame($en, (int) $mine[0]->BrEnId);
        $this->assertSame(2, (int) $mine[0]->QuSession);
    }

    public function testDepartureChangedInIanseoIsFollowed(): void
    {
        $tour = Fixtures::competition(3);
        Fixtures::booking($tour, 2);
        $en = Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));
        TestDb::exec("UPDATE Qualifications SET QuSession = 3 WHERE QuId = $en");

        $this->assertSame(3, (int) $this->mine()[0]->QuSession);
    }

    public function testEntryDeletedInIanseoDisappears(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        $en = Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));
        Fixtures::ianseoDeleteArcher($en);

        $this->assertSame(array(), $this->mine());
    }

    public function testHiddenCompetitionIsNotListed(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 1);   // hidden, payments not followed
        Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));

        $this->assertSame(array(), $this->mine());
    }

    public function testHiddenCompetitionWithPaymentsIsListed(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 1, 1);   // hidden, but payments followed
        Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));

        $this->assertCount(1, $this->mine());
    }

    public function testCompetitionOutsideLicenseeSpaceIsNotListed(): void
    {
        $tour = Fixtures::competition();   // no BookingCompetitions row
        Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));

        $this->assertSame(array(), $this->mine());
    }

    public function testNonAthleteIsNotListed(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC, 'athlete' => 0));   // judge, official

        $this->assertSame(array(), $this->mine());
    }

    public function testOnlineRegistrationIsNotListedTwice(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 3);
        $en = Fixtures::archer($tour, 1, 2, 'A', array('licence' => self::LIC));
        TestDb::exec("INSERT INTO BookingRegistrations SET BrTournament = $tour, BrEnId = $en, BrLicence = '" . self::LIC . "',
            BrByRole = 'SELF', BrCreated = NOW(), BrValidated = 1");

        $mine = $this->mine();
        $this->assertCount(1, $mine);
        $this->assertSame('SELF', $mine[0]->BrByRole);
    }

    public function testLicenceIsMatchedWithoutSpacesAndCase(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));

        $this->assertCount(1, bk_my_registrations(' 9100001k '));
    }
}
