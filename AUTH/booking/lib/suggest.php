<?php
/**
 * lib/suggest.php — the competitions most likely to interest an archer, for the "Competitions"
 * block of the home page of the licensee space.
 *
 * Only competitions the archer can register for now: not over, registrations open, not
 * registered yet (those are in the "Registrations" block), not out of their area (geographic
 * restriction) and with a class open to their age and sex on one bow at least. Ranked by:
 *  - discipline: share of the archer's own entries on this server in that discipline (weight 3);
 *  - proximity: organiser of the same department 1, of the same region 0.5 (weight 2), from the
 *    approval numbers LLDDCCC of the club and of the organiser (ToCommitee);
 *  - date: 1 today, down to 0 at 120 days (weight 2);
 *  - room: a competition full on every departure open to registration comes after all others.
 */
if (defined('BK_SUGGEST_LOADED')) return;
define('BK_SUGGEST_LOADED', true);

require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/sessionrules.php';

/**
 * Up to $limit competitions for this archer, soonest first, the full ones last. Each item:
 * ['c' => calendar row, 'disc' => bk_comp_discipline(), 'format' => type and rule in the core's
 * words, 'duels' => bool, 'sessions' => int, 'places' => int, 'taken' => int, 'full' => bool].
 * $lue = their row of the licence file (bk_lookup_licence): date of birth, sex, club.
 */
function bk_comp_suggest($archer, $lue, $limit = 3)
{
    if (!$lue) return array();
    $licence = bk_clean_licence($archer->BaLicence);
    // bytes: an approval number is ASCII
    $club = strtoupper(trim((string) $lue->LueCountry));
    $today = new DateTime(bk_today());

    // What the archer has shot on this server, per discipline.
    $disc = array(); $total = 0;
    $rs = safe_r_sql("SELECT ToType, ToTypeSubRule, ToTypeName, COUNT(*) AS n
        FROM Entries INNER JOIN Tournament ON ToId = EnTournament
        WHERE EnCode = " . StrSafe_DB($licence) . "
        GROUP BY ToType, ToTypeSubRule, ToTypeName");
    while ($r = safe_fetch($rs)) {
        $k = bk_comp_discipline($r->ToType, $r->ToTypeSubRule, $r->ToTypeName)['key'];
        $disc[$k] = ($disc[$k] ?? 0) + intval($r->n);
        $total += intval($r->n);
    }
    $mine = array();
    foreach (bk_my_registrations($licence) as $r) $mine[intval($r->BrTournament)] = true;

    // Cheap score on every candidate first; the checks that cost queries come after, in order.
    $cands = array();
    foreach (bk_comp_calendar() as $c) {
        if (empty($c->BcIsOpen) || bk_is_finished($c->ToWhenTo) || isset($mine[intval($c->ToId)])) continue;
        $dd = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
        // bytes, both lines: approval numbers are ASCII
        $org = strtoupper(trim((string) $c->ToCommitee));
        $prox = ($club === '' || $org === '') ? 0.0
            : (substr($org, 0, 4) === substr($club, 0, 4) ? 1.0 : (substr($org, 0, 2) === substr($club, 0, 2) ? 0.5 : 0.0));
        $start = new DateTime(substr((string) $c->ToWhenFrom, 0, 10));   // bytes: an ASCII date
        $days = $start > $today ? $today->diff($start)->days : 0;   // under way: today
        $score = 3 * ($total ? ($disc[$dd['key']] ?? 0) / $total : 0) + 2 * $prox + 2 * max(0, 1 - $days / 120);
        $cands[] = array('c' => $c, 'disc' => $dd, 'score' => $score);
    }
    usort($cands, function ($a, $b) { return ($b['score'] <=> $a['score']) ?: strcmp($a['c']->ToWhenFrom, $b['c']->ToWhenFrom); });

    $ok = array(); $full = array(); $seen = 0;
    foreach ($cands as $x) {
        if (count($ok) >= $limit || ++$seen > 40) break;   // 40: bounds the cost on a big calendar
        $c = $x['c']; $t = intval($c->ToId);
        if (bk_comp_archer_blocked($c, $club) !== '' || !bk_suggest_class_open($t, $lue)) continue;
        $sessions = bk_comp_sessions($t);
        $states = bk_session_states($t, $c, $sessions);
        $places = 0; $taken = 0; $room = false;
        foreach ($sessions as $s) {
            $pl = intval($s->Places); $pr = intval($s->Pris);
            $places += $pl; $taken += min($pr, $pl);
            if (($states[intval($s->SesOrder)]['open'] ?? true) && $pr < $pl) $room = true;
        }
        $item = array('c' => $c, 'disc' => $x['disc'], 'sessions' => count($sessions),
            'places' => $places, 'taken' => $taken, 'full' => !$room);
        if ($room) $ok[] = $item; else $full[] = $item;
    }
    $out = array_slice(array_merge($ok, $full), 0, $limit);
    foreach ($out as &$it) {
        $c = $it['c']; $t = intval($c->ToId);
        $it['format'] = implode(' — ', array_filter(array(
            $c->ToTypeName ? get_text($c->ToTypeName, 'Tournament') : '',
            $c->ToTypeSubRule ? get_text($c->ToTypeSubRule, 'Install') : '',
        )));
        $it['duels'] = (bool) safe_fetch(safe_r_sql("SELECT 1 FROM Events
            WHERE EvTournament = $t AND EvCodeParent = '' AND EvFinalFirstPhase > 0 LIMIT 1"));
    }
    unset($it);
    usort($out, function ($a, $b) { return ($a['full'] <=> $b['full']) ?: strcmp($a['c']->ToWhenFrom, $b['c']->ToWhenFrom); });
    return $out;
}

/**
 * Has the competition a class open to the archer's age and sex, on one bow at least? (Same
 * rule as the registration form: bk_reg_classes, classes closed to online registration left
 * out.) Without a date of birth the age cannot be checked: the competition is kept.
 */
function bk_suggest_class_open($tourId, $lue)
{
    $dob = (string) $lue->LueCtrlCode;
    if (intval(substr($dob, 0, 4)) < 1900) return true;   // bytes: an ASCII date
    foreach (array_keys(bk_reg_divisions($tourId)) as $div) {
        if (bk_reg_classes($tourId, $dob, $lue->LueSex, $div)) return true;
    }
    return false;
}
