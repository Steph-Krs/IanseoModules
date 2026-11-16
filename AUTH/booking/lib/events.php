<?php
/**
 * lib/events.php — taking part in the events of a competition (duels, team events), chosen by the
 * archer when registering and changed until the registration closes.
 *
 * ianseo has five switches per entry (Entries): EnIndClEvent "Tir Ind.", EnIndFEvent "Épreuve
 * Ind.", EnTeamClEvent "Tir par Équipe", EnTeamFEvent "Épreuve par Équipe", EnTeamMixEvent
 * "Épreuve Équipe Mixte". Rules given by the user (2026-11-16):
 *  - EnIndClEvent is always 1, on every shoot: five switches at 0 break ianseo;
 *  - individual events: taken part in by default; when the category is in an individual event
 *    with duels, the archer may say no (EnIndFEvent = 0) — they keep their place for the
 *    qualification round, which is what counts for the places left;
 *  - team events: not taken part in by default; one box per kind the category belongs to (team,
 *    mixed team), either one ticked turns EnTeamClEvent on;
 *  - a departmental or regional championship may keep the individual events, and separately the
 *    team events, to the archers of the organiser's department or region (approval number of the
 *    club, LLDDCCC): the others shoot the qualification round without them, and get no box.
 * With several shoots of one bow, only the first shoot of the competition carries the event
 * choices (bk_events_normalise), the others keep EnIndClEvent alone.
 *
 * The switches are changed in place, then the core's own recalculations run, as the core's
 * "event access" screen does (Partecipants/UpdateField-action.php).
 */

if (defined('BK_EVENTS_LOADED')) return;
define('BK_EVENTS_LOADED', true);

require_once __DIR__ . '/pricing.php';   // bk_org_agrement
require_once __DIR__ . '/ui.php';        // bk_e, bk_t

// Choices of the archer, stored as a bit set (BookingRegistrations.BrEvOpts, BookingWaitlist.BwEvOpts).
define('BK_EV_NOFINALS', 1);   // no to the individual events (duels)
define('BK_EV_TEAM', 2);       // team event
define('BK_EV_MIX', 4);        // mixed team event

/** The switches that follow the first shoot of a bow (EnIndClEvent stays on everywhere). */
function bk_ev_carry_cols()
{
    return array('EnIndFEvent', 'EnTeamClEvent', 'EnTeamFEvent', 'EnTeamMixEvent');
}

/** Areas a championship can keep its events to: '' everyone, 'D' department, 'R' region. */
function bk_ev_zones()
{
    return array('' => bk_t('EvZoneNone'), 'D' => bk_t('EvZoneDept'), 'R' => bk_t('EvZoneRegion'));
}

/** A zone value as stored: '', 'D' or 'R'. */
function bk_ev_zone_clean($z)
{
    $z = (string) $z;
    return ($z === 'D' || $z === 'R') ? $z : '';
}

/**
 * Is a club inside the area $zone of the organiser of $tourId? Compared on the approval numbers
 * (LLDDCCC): the league (2 first characters) for a region, league and department (4 first) for a
 * department — the department alone is not unique without its league. A club with no approval
 * number (archer of another federation) is outside any area.
 */
function bk_ev_zone_ok($tourId, $zone, $clubCode)
{
    $zone = bk_ev_zone_clean($zone);
    if ($zone === '') return true;
    $n = $zone === 'R' ? 2 : 4;
    // bytes: approval numbers are ASCII letters and digits
    $org  = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', bk_org_agrement($tourId)));
    $club = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $clubCode));
    // An organiser without an approval number has no area: nobody is kept out (the organiser's
    // page says so) rather than everybody.
    // bytes: approval numbers are ASCII letters and digits
    if (strlen($org) < $n || !ctype_digit(substr($org, 0, 2))) return true;
    // bytes: approval numbers are ASCII letters and digits
    if (strlen($club) < $n || !ctype_digit(substr($club, 0, 2))) return false;
    // bytes: approval numbers are ASCII letters and digits
    return substr($org, 0, $n) === substr($club, 0, $n);
}

/**
 * What the events of the competition offer to a category: individual events (and whether one has
 * duels), team and mixed team events (their names), and whether the archer's club is inside the
 * championship's area for each. Read once per request and category.
 */
function bk_ev_offer($tourId, $division, $class, $clubCode, $cfg = null)
{
    static $cache = array();
    $tourId = intval($tourId);
    if ($cfg === null && function_exists('bk_comp_config')) $cfg = bk_comp_config($tourId);
    $key = $tourId . '|' . $division . '|' . $class . '|' . $clubCode . '|' . ($cfg->BcFinalsZone ?? '') . '|' . ($cfg->BcTeamsZone ?? '');
    if (isset($cache[$key])) return $cache[$key];

    $o = array('ind' => false, 'duels' => false, 'indNames' => array(), 'team' => array(), 'mix' => array(),
               'indZone' => bk_ev_zone_clean($cfg->BcFinalsZone ?? ''), 'teamZone' => bk_ev_zone_clean($cfg->BcTeamsZone ?? ''));
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvFinalFirstPhase FROM EventClass
        INNER JOIN Events ON EvTournament = EcTournament AND EvCode = EcCode AND EvTeamEvent = 0
        WHERE EcTournament = $tourId AND EcTeamEvent = 0
          AND EcDivision = " . StrSafe_DB($division) . " AND EcClass = " . StrSafe_DB($class) . "
        ORDER BY EvProgr, EvCode");
    while ($r = safe_fetch($rs)) {
        $o['ind'] = true;
        if (intval($r->EvFinalFirstPhase) > 0) {
            $o['duels'] = true;
            $o['indNames'][] = (string) ($r->EvEventName ?: $r->EvCode);
        }
    }
    // Team events the category is a component of (sub-events of a bracket left out).
    $rs = safe_r_sql("SELECT DISTINCT EvCode, EvEventName, EvMixedTeam, EvProgr FROM EventClass
        INNER JOIN Events ON EvTournament = EcTournament AND EvCode = EcCode AND EvTeamEvent = 1
        WHERE EcTournament = $tourId AND EcTeamEvent > 0 AND EvCodeParent = ''
          AND EcDivision = " . StrSafe_DB($division) . " AND EcClass = " . StrSafe_DB($class) . "
        ORDER BY EvProgr, EvCode");
    while ($r = safe_fetch($rs)) {
        $o[intval($r->EvMixedTeam) ? 'mix' : 'team'][] = (string) ($r->EvEventName ?: $r->EvCode);
    }
    $o['indOk']  = bk_ev_zone_ok($tourId, $o['indZone'], $clubCode);
    $o['teamOk'] = bk_ev_zone_ok($tourId, $o['teamZone'], $clubCode);
    return $cache[$key] = $o;
}

/** Keeps only the choices the offer really shows (a forged POST does not turn on a hidden box). */
function bk_ev_opts_clean($offer, $opts)
{
    $opts = intval($opts);
    $out = 0;
    if ($offer['duels'] && $offer['indOk'] && ($opts & BK_EV_NOFINALS)) $out |= BK_EV_NOFINALS;
    if ($offer['team'] && $offer['teamOk'] && ($opts & BK_EV_TEAM)) $out |= BK_EV_TEAM;
    if ($offer['mix'] && $offer['teamOk'] && ($opts & BK_EV_MIX)) $out |= BK_EV_MIX;
    return $out;
}

/** The choices posted by the registration form. */
function bk_ev_opts_post($post)
{
    return (!empty($post['ev_nofinals']) ? BK_EV_NOFINALS : 0)
         | (!empty($post['ev_team']) ? BK_EV_TEAM : 0)
         | (!empty($post['ev_mix']) ? BK_EV_MIX : 0);
}

/** The choices read back from the switches of an entry (to fill the form in when changing it). */
function bk_ev_opts_from_entry($row)
{
    return (intval($row->EnIndFEvent ?? 1) === 0 ? BK_EV_NOFINALS : 0)
         | (intval($row->EnTeamFEvent ?? 0) === 1 ? BK_EV_TEAM : 0)
         | (intval($row->EnTeamMixEvent ?? 0) === 1 ? BK_EV_MIX : 0);
}

/**
 * Switches carried by the first shoot for these choices. Outside the championship's area: no
 * individual event, no team event, whatever was asked. A category in no individual event keeps
 * EnIndFEvent = 1, as ianseo sets it (it then means nothing).
 */
function bk_ev_flags($offer, $opts)
{
    $opts = bk_ev_opts_clean($offer, $opts);
    $indF = ($offer['ind'] && !$offer['indOk']) ? 0 : (($opts & BK_EV_NOFINALS) ? 0 : 1);
    $tf = ($opts & BK_EV_TEAM) ? 1 : 0;
    $tm = ($opts & BK_EV_MIX) ? 1 : 0;
    return array('EnIndFEvent' => $indF, 'EnTeamClEvent' => ($tf || $tm) ? 1 : 0, 'EnTeamFEvent' => $tf, 'EnTeamMixEvent' => $tm);
}

/** The championship's area applied to switches kept from earlier shoots. */
function bk_ev_constrain($offer, $carry)
{
    if ($offer['ind'] && !$offer['indOk']) $carry['EnIndFEvent'] = 0;
    if (!$offer['teamOk']) $carry['EnTeamClEvent'] = $carry['EnTeamFEvent'] = $carry['EnTeamMixEvent'] = 0;
    return $carry;
}

/**
 * Block "Events" of the registration form: the boxes the category is offered, or why it is not.
 * '' when the category is in no event the archer could choose. $mate: registering a clubmate.
 */
function bk_ev_form_html($offer, $opts, $mate = false)
{
    $zoneTxt = function ($z) { return bk_t($z === 'R' ? 'EvInRegion' : 'EvInDept'); };
    $chk = function ($name, $on, $label) {
        return '<label class="bk-chk bk-ev-chk"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
    };
    $h = '';
    if ($offer['duels'] && $offer['indOk']) {
        $h .= $chk('ev_nofinals', ($opts & BK_EV_NOFINALS) !== 0, bk_e(bk_t($mate ? 'EvNoFinalsMate' : 'EvNoFinals')))
            . '<p class="bk-hint">' . bk_e(bk_t('EvNoFinalsHint', implode(', ', $offer['indNames']))) . '</p>';
    } elseif ($offer['ind'] && !$offer['indOk']) {
        $h .= '<p class="bk-note">' . bk_e(bk_t('EvIndOutZone', $zoneTxt($offer['indZone']))) . '</p>';
    }
    if (($offer['team'] || $offer['mix']) && !$offer['teamOk']) {
        $h .= '<p class="bk-note">' . bk_e(bk_t('EvTeamOutZone', $zoneTxt($offer['teamZone']))) . '</p>';
    } elseif ($offer['team'] || $offer['mix']) {
        if ($offer['team']) $h .= $chk('ev_team', ($opts & BK_EV_TEAM) !== 0, bk_e(bk_t('EvTeam')) . ' <span class="bk-hint">(' . bk_e(implode(', ', $offer['team'])) . ')</span>');
        if ($offer['mix'])  $h .= $chk('ev_mix', ($opts & BK_EV_MIX) !== 0, bk_e(bk_t('EvMix')) . ' <span class="bk-hint">(' . bk_e(implode(', ', $offer['mix'])) . ')</span>');
        $h .= '<p class="bk-hint">' . bk_e(bk_t('EvTeamHint')) . '</p>';
    }
    if ($h === '') return '';
    return '<fieldset class="bk-wishes bk-ev"><legend>' . bk_e(bk_t('EvLegend')) . '</legend>' . $h . '</fieldset>';
}

/** Short tags of the events of a registration ("My registrations"). */
function bk_ev_tags($row)
{
    $out = array();
    if (isset($row->EnIndFEvent) && intval($row->EnIndFEvent) === 0 && intval($row->EnIndClEvent ?? 1) === 1) $out[] = bk_t('EvTagNoFinals');
    if (intval($row->EnTeamFEvent ?? 0) === 1) $out[] = bk_t('EvTagTeam');
    if (intval($row->EnTeamMixEvent ?? 0) === 1) $out[] = bk_t('EvTagMix');
    return $out;
}

/**
 * Puts the switches of an archer's shoots with one bow in order: EnIndClEvent on everywhere, the
 * other four on the first shoot of the competition only (bk_shoots_in_order), set to $carry —
 * or, when $carry is null, to what the shoots carried between them (a shoot that took part
 * hands it over). Returns [EnId => switches changed] for the recalculations (bk_events_recalc).
 */
function bk_events_normalise($tourId, $code, $division, $carry = null)
{
    $shoots = bk_shoots_in_order($tourId, $code, $division);
    if (!$shoots) return array();
    $cols = bk_ev_carry_cols();
    if ($carry === null) {
        $carry = array_fill_keys($cols, 0);
        foreach ($shoots as $s) foreach ($cols as $c) if (intval($s->$c) === 1) $carry[$c] = 1;
    }
    $changed = array();
    foreach ($shoots as $i => $s) {
        $want = array('EnIndClEvent' => 1);
        foreach ($cols as $c) $want[$c] = ($i === 0 && !empty($carry[$c])) ? 1 : 0;
        $diff = array();
        foreach ($want as $c => $v) if (intval($s->$c) !== $v) $diff[] = $c;
        if (!$diff) continue;
        bk_event_flags_set($tourId, $s->EnId, $want);
        $changed[intval($s->EnId)] = $diff;
    }
    return $changed;
}

/** Merges two results of bk_events_normalise. */
function bk_events_merge($a, $b)
{
    foreach ($b as $id => $cols) $a[$id] = array_values(array_unique(array_merge($a[$id] ?? array(), $cols)));
    return $a;
}

/**
 * The core's recalculations for the switches changed (as Partecipants/UpdateField-action.php).
 * To call with the competition's session open (bk_with_tournament).
 */
function bk_events_recalc($tourId, $changed)
{
    $tourId = intval($tourId);
    $w = array('iq' => array(), 'if' => array(), 'tq' => array(), 'tf' => array());
    foreach ($changed as $id => $cols) {
        foreach ($cols as $c) {
            if ($c === 'EnIndClEvent') $w['iq'][] = intval($id);
            elseif ($c === 'EnIndFEvent') $w['if'][] = intval($id);
            elseif ($c === 'EnTeamClEvent') $w['tq'][] = intval($id);
            else $w['tf'][] = intval($id);
        }
    }
    if ($w['iq']) recalculateIndividualClassDiv(array_values(array_unique($w['iq'])), -1, $tourId);
    if ($w['if']) recalculateIndividualFinals(array_values(array_unique($w['if'])), -1, $tourId);
    // One archer per call: these two read a single division and class from their list.
    foreach (array_unique($w['tq']) as $id) recalculateTeamClassDiv(array($id), $tourId);
    foreach (array_unique($w['tf']) as $id) recalculateTeamFinals(array($id), $tourId);
}

/**
 * Keeps the choices of an archer with one bow on every online registration of theirs with it: the
 * choices belong to the bow, not to one shoot, and are read back from any of them.
 */
function bk_events_store_opts($tourId, $code, $division, $opts)
{
    safe_w_sql("UPDATE BookingRegistrations INNER JOIN Entries ON EnId = BrEnId AND EnTournament = BrTournament
        SET BrEvOpts = " . ($opts === null ? 'NULL' : intval($opts)) . "
        WHERE BrTournament = " . intval($tourId) . " AND EnCode = " . StrSafe_DB($code) . " AND EnDivision = " . StrSafe_DB($division));
}

/**
 * The championship's areas applied again to the online registrations of a competition (all, or
 * one archer's: $code) — after the organiser changed them, or after an archer changed club. An
 * archer back inside an area gets back what they had chosen (BrEvOpts); the organiser's own
 * entries are left alone. Returns the number of archer-bow pairs whose switches changed.
 */
function bk_events_apply_zones($tourId, $code = null)
{
    $tourId = intval($tourId);
    if (function_exists('bk_comp_finished') && bk_comp_finished($tourId)) return 0;
    $rs = safe_r_sql("SELECT EnCode, EnDivision, EnClass, CoCode, MAX(BrEvOpts) AS Opts FROM Entries
        INNER JOIN BookingRegistrations ON BrEnId = EnId AND BrTournament = EnTournament
        LEFT JOIN Countries ON CoId = EnCountry
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND EnCode <> '' AND BrByRole <> 'IMPORT' AND BrLicHold IS NULL"
        . ($code !== null ? " AND EnCode = " . StrSafe_DB($code) : '') . "
        GROUP BY EnCode, EnDivision, EnClass, CoCode");
    $todo = array();
    while ($r = safe_fetch($rs)) $todo[] = $r;
    if (!$todo) return 0;
    $run = function () use ($tourId, $todo) {
        if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return 0;
        $cfg = bk_comp_config($tourId);
        $changed = array();
        $n = 0;
        foreach ($todo as $r) {
            $offer = bk_ev_offer($tourId, $r->EnDivision, $r->EnClass, (string) $r->CoCode, $cfg);
            if ($r->Opts !== null) {
                $carry = bk_ev_flags($offer, intval($r->Opts));
            } else {
                $carry = array_fill_keys(bk_ev_carry_cols(), 0);
                foreach (bk_shoots_in_order($tourId, $r->EnCode, $r->EnDivision) as $s) {
                    foreach (bk_ev_carry_cols() as $c) if (intval($s->$c) === 1) $carry[$c] = 1;
                }
                $carry = bk_ev_constrain($offer, $carry);
            }
            $one = bk_events_normalise($tourId, $r->EnCode, $r->EnDivision, $carry);
            if ($one) { $n++; $changed = bk_events_merge($changed, $one); }
        }
        if ($changed) bk_events_recalc($tourId, $changed);
        return $n;
    };
    return intval($_SESSION['TourId'] ?? 0) === $tourId ? $run() : bk_with_tournament($tourId, $run);
}

/**
 * Online registrations of a competition left with "Tir Ind." off (written before the rule: an
 * extra shoot had its five switches at 0) are put right — run when the organiser opens the
 * online registration page, with the competition open. Organiser's own entries are left alone.
 * Returns the number of archers put right.
 */
function bk_events_repair($tourId)
{
    $tourId = intval($tourId);
    if (function_exists('bk_comp_finished') && bk_comp_finished($tourId)) return 0;
    $rs = safe_r_sql("SELECT DISTINCT EnCode, EnDivision FROM Entries
        INNER JOIN BookingRegistrations ON BrEnId = EnId AND BrTournament = EnTournament
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND EnIndClEvent = 0 AND EnCode <> ''
          AND BrByRole <> 'IMPORT' AND BrLicHold IS NULL");
    $todo = array();
    while ($r = safe_fetch($rs)) $todo[] = $r;
    if (!$todo) return 0;
    $run = function () use ($tourId, $todo) {
        if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return 0;
        $changed = array();
        foreach ($todo as $r) $changed = bk_events_merge($changed, bk_events_normalise($tourId, $r->EnCode, $r->EnDivision));
        if ($changed) bk_events_recalc($tourId, $changed);
        return count($todo);
    };
    return intval($_SESSION['TourId'] ?? 0) === $tourId ? $run() : bk_with_tournament($tourId, $run);
}
