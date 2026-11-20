<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Online registration (booking/lib/registration.php, bk_register_write): EnIocCode is always
 * filled in, as the core does (Partecipants/AddRow.php). Left empty, the FFTA export
 * (Modules/Sets/FR/exports) wrote 999999 instead of the licence number (issue 11).
 */
final class RegistrationIocTest extends AuthTestCase
{
    private function lue(string $licence, string $ioc): object
    {
        return (object) array(
            'LueCode' => $licence, 'LueIocCode' => $ioc,
            'LueFamilyName' => 'ARCHER', 'LueName' => 'Test', 'LueSex' => 0,
            'LueCtrlCode' => '1990-01-01', 'LueSubClass' => '', 'LueStatus' => 1,
            'LueCountry' => '9966000', 'LueCoDescr' => 'Test club',
            'LueCountry2' => '', 'LueCoDescr2' => '',
        );
    }

    private function register(int $tour, object $lue): int
    {
        $res = bk_register_write($tour, $lue, 'CL', 'S1H', 1, '', 'TEST', array('skip_capacity' => 1));
        $this->assertTrue($res['ok'], $res['msg'] ?? '');
        return (int) TestDb::value("SELECT EnId FROM Entries WHERE EnTournament = $tour AND EnCode = "
            . TestDb::quote($lue->LueCode));
    }

    public function testEntryAlwaysGetsACountry(): void
    {
        $tour = Fixtures::competition();
        // Back number already there: otherwise CreateTourSession (through bk_with_tournament)
        // creates it and writes its picture into TV/Photos.
        TestDb::exec("INSERT INTO BackNumber SET BnTournament = $tour, BnFinal = 0");
        $cases = array(
            '9200001K' => array('FRA', 'FRA'),   // FFTA licensee
            '9200002K' => array('', 'FRA'),      // no country: the competition's
            'ESP-1234' => array('ESP', 'ESP'),   // another federation: keeps its own
        );
        foreach ($cases as $licence => list($lueIoc, $expected)) {
            $en = $this->register($tour, $this->lue($licence, $lueIoc));
            $this->assertSame($expected, TestDb::value("SELECT EnIocCode FROM Entries WHERE EnId = $en"), $licence);
        }
    }
}
