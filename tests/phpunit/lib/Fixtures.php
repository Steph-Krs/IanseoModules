<?php
/**
 * Test data, all made up: no real name, licence or club number. Writes go through TestDb (a
 * connection of its own) and fill only the columns a test needs.
 *
 * The writes "as ianseo does" copy word for word the queries of the core screens, so that the
 * modules are tested against what the core really writes.
 */
final class Fixtures
{
    /** @var int */
    private static $seq = 0;

    /**
     * A competition, its departures, one target face, two divisions and one class.
     * @return int ToId
     */
    public static function competition(int $sessions = 2, string $from = '+3 days', int $targets = 10): int
    {
        $n = ++self::$seq;
        $code = 'TST' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $d1 = date('Y-m-d', strtotime($from));
        $d2 = date('Y-m-d', strtotime($from . ' +1 day'));
        TestDb::exec("INSERT INTO Tournament SET ToCode = '$code', ToName = 'Test competition $n', ToNameShort = 'Test $n',
            ToWhere = 'Testville', ToVenue = 'Test hall', ToCountry = 'FRA', ToIocCode = 'FRA',
            ToWhenFrom = '$d1', ToWhenTo = '$d2', ToNumSession = $sessions, ToType = 6, ToNumDist = 2,
            ToTypeName = 'Indoor', ToTypeSubRule = '', ToGolds = '10', ToXNine = '9', ToCollation = '',
            ToOptions = '', ToRecCode = '', ToComDescr = '', ToCommitee = '', ToPrintLang = 'fr',
            ToTimeZone = 'Europe/Paris', ToDbVersion = NOW()");
        $tour = (int) TestDb::value('SELECT LAST_INSERT_ID()');
        for ($s = 1; $s <= $sessions; $s++) {
            TestDb::exec("INSERT INTO Session SET SesTournament = $tour, SesOrder = $s, SesType = 'Q',
                SesName = 'Departure $s', SesTar4Session = $targets, SesAth4Target = 4, SesFirstTarget = 1");
        }
        // Face 1: 40 cm triple spot, 4 archers per target indoors (booking/lib/cohabitation.php).
        TestDb::exec("INSERT INTO TargetFaces SET TfTournament = $tour, TfId = 1, TfName = 'Trispot 40 cm', TfClasses = '%',
            TfRegExp = '', TfDefault = '1'");
        TestDb::exec("INSERT INTO Divisions SET DivTournament = $tour, DivId = 'CL', DivDescription = 'Recurve', DivAthlete = '1', DivViewOrder = 1");
        TestDb::exec("INSERT INTO Divisions SET DivTournament = $tour, DivId = 'CO', DivDescription = 'Compound', DivAthlete = '1', DivViewOrder = 2");
        TestDb::exec("INSERT INTO Classes SET ClTournament = $tour, ClId = 'S1H', ClDescription = 'Senior 1 Men', ClAthlete = '1',
            ClAgeFrom = 21, ClAgeTo = 39, ClSex = 0, ClViewOrder = 1, ClDivisionsAllowed = '', ClValidClass = 'S1H'");
        return $tour;
    }

    /** A club of the competition. @return int CoId */
    public static function club(int $tour, string $code = '9966000', string $name = 'Test club'): int
    {
        $id = TestDb::value("SELECT CoId FROM Countries WHERE CoTournament = $tour AND CoCode = " . TestDb::quote($code));
        if ($id) return (int) $id;
        TestDb::exec("INSERT INTO Countries SET CoTournament = $tour, CoCode = " . TestDb::quote($code) . ", CoName = "
            . TestDb::quote($name) . ", CoNameComplete = " . TestDb::quote($name) . ", CoIocCode = 'FRA'");
        return (int) TestDb::value('SELECT LAST_INSERT_ID()');
    }

    /**
     * A participant, placed or not. Placed as the placement of online registrations does it
     * (booking/lib/targets.php): QuTarget, QuLetter AND QuTargetNo.
     * @param array{licence?:string,family?:string,given?:string,division?:string,club?:int,status?:int,athlete?:int,face?:int} $o
     * @return int EnId
     */
    public static function archer(int $tour, int $session = 1, int $target = 0, string $letter = '', array $o = array()): int
    {
        $n = ++self::$seq;
        $club = $o['club'] ?? self::club($tour);
        $lic = $o['licence'] ?? sprintf('9%06dT', $n);
        TestDb::exec("INSERT INTO Entries SET EnTournament = $tour, EnCode = " . TestDb::quote($lic) . ",
            EnFirstName = " . TestDb::quote($o['family'] ?? "Archer$n") . ", EnName = " . TestDb::quote($o['given'] ?? "Test$n") . ",
            EnCountry = $club, EnIocCode = 'FRA', EnDivision = " . TestDb::quote($o['division'] ?? 'CL') . ", EnClass = 'S1H',
            EnAgeClass = 'S1H', EnSubClass = '', EnCtrlCode = '', EnDob = '1990-01-01', EnAthlete = " . (int) ($o['athlete'] ?? 1) . ",
            EnStatus = " . (int) ($o['status'] ?? 0) . ", EnIndClEvent = 1, EnTargetFace = " . (int) ($o['face'] ?? 1) . ", EnClassified = 0,
            EnLueTimeStamp = NOW(), EnLueFieldChanged = 0, EnMainInfoUpdate = NOW(), EnNameOrder = 0,
            EnOdfShortname = '', EnTvGivenName = '', EnTvFamilyName = '', EnTvInitials = ''");
        $id = (int) TestDb::value('SELECT LAST_INSERT_ID()');
        $no = $target > 0 ? $session . str_pad((string) $target, 3, '0', STR_PAD_LEFT) . strtoupper($letter) : '';
        TestDb::exec("INSERT INTO Qualifications SET QuId = $id, QuSession = $session, QuTarget = $target,
            QuLetter = " . TestDb::quote(strtoupper($letter)) . ", QuTargetNo = " . TestDb::quote($no));
        return $id;
    }

    /**
     * Target changed in ianseo, Participants > Target > manual assignment: query of
     * Partecipants/UpdateTargetNo.php (QuTargetNo is not rewritten).
     */
    public static function ianseoSetTarget(int $enId, int $target, string $letter): void
    {
        TestDb::exec("UPDATE Qualifications SET QuTarget=" . $target . ", QuLetter='" . strtoupper($letter)
            . "', QuTimestamp=QuTimestamp WHERE QuId=" . $enId);
    }

    /** Target removed in ianseo: query of Partecipants/UpdateTargetNo.php (empty target). */
    public static function ianseoClearTarget(int $enId): void
    {
        TestDb::exec("UPDATE Qualifications SET QuTarget=0, QuLetter='', QuBacknoPrinted=0, QuTimestamp=QuTimestamp WHERE QuId=" . $enId);
    }

    /** Participant deleted in ianseo (Entries and Qualifications). */
    public static function ianseoDeleteArcher(int $enId): void
    {
        TestDb::exec("DELETE FROM Entries WHERE EnId = $enId");
        TestDb::exec("DELETE FROM Qualifications WHERE QuId = $enId");
    }

    /**
     * The competition in the licensee space. $publish: 1 hidden, 2 published, 3 registrations open.
     */
    public static function booking(int $tour, int $publish = 2, int $payments = 0): void
    {
        TestDb::exec("INSERT INTO BookingCompetitions SET BcTournament = $tour, BcPublishLevel = $publish, BcPayments = $payments,
            BcShowAssignment = 1, BcOpen = " . ($publish >= 3 ? 1 : 0) . "
            ON DUPLICATE KEY UPDATE BcPublishLevel = VALUES(BcPublishLevel), BcPayments = VALUES(BcPayments)");
    }

    /** Food & shop and check-in desk turned on for the competition (ShopSettings). */
    public static function shop(int $tour): void
    {
        $key = substr(bin2hex(random_bytes(5)), 0, 10);
        TestDb::exec("INSERT IGNORE INTO ShopSettings SET SgTournament = $tour, SgEnabled = 1, SgDesk = 1, SgJudges = 1,
            SgPublicKey = '$key', SgCreated = NOW()");
    }

    /**
     * A line of the payment ledger taken at the check-in desk (shp_pay_account without a stand):
     * by a volunteer ($staff > 0) or keyed in on the Payments page ($staff = 0).
     * @return int BlgId
     */
    public static function deskPayment(int $tour, string $account, float $amount, string $method = 'cash', int $staff = 1,
        string $kind = 'payment', string $when = 'now'): int
    {
        TestDb::exec("INSERT INTO BookingLedger SET BlgTournament = $tour, BlgAccount = " . TestDb::quote($account) . ",
            BlgKind = " . TestDb::quote($kind) . ", BlgAmount = " . sprintf('%.2F', $amount) . ", BlgMethod = " . TestDb::quote($method) . ",
            BlgLabel = 'Paid at the desk', BlgStaff = $staff, BlgStand = 0, BlgOrder = 0, BlgProvider = 'manual',
            BlgStatus = 'done', BlgIdem = UUID(), BlgWhen = " . TestDb::quote(date('Y-m-d H:i:s', strtotime($when))) . ",
            BlgCreated = NOW(), BlgBy = " . TestDb::quote($staff > 0 ? '' : 'Test organiser'));
        return (int) TestDb::value('SELECT LAST_INSERT_ID()');
    }
}
