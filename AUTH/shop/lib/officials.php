<?php
/**
 * lib/officials.php — the competition's officials as ianseo records them (menu "Field crew",
 * Tournament/ManStaffField.php: table TournamentInvolved, one row per person and function; the
 * functions are InvolvedType) and the access of the volunteers (user's choice, 2026-10-13):
 *   - an official with a licence and no access yet is offered on the Volunteers page, to be given
 *     an access in one click (access proposed from the function);
 *   - the function of a licensed volunteer is set from the Volunteers page too: it is written in
 *     ianseo's field crew of the open competition, as ManStaffField.php writes it. Only the
 *     function shown on the volunteer's card is changed or removed — a person may hold several;
 *   - a withdrawn access leaves the function in ianseo: the page says so.
 * Matched by the licence (TiCode). In the core the function only shows on documents: no right
 * comes with it.
 */

if (defined('SHP_OFFICIALS_LOADED')) return;
define('SHP_OFFICIALS_LOADED', true);

require_once __DIR__ . '/staff.php';

/** ianseo's functions, in the order and groups of its own list: [['id', 'label', 'group']]. */
function shp_fn_list()
{
    static $out = null;
    if ($out !== null) return $out;
    $out = array();
    $rs = safe_r_sql("SELECT ItId, ItDescription, IF(ItJudge > 0, 1, 0) + IF(ItDoS > 0, 2, 0) + IF(ItJury > 0, 3, 0) + IF(ItOC > 0, 4, 0) AS ItGroup
        FROM InvolvedType ORDER BY ItJudge = 0, ItJudge, ItDoS = 0, ItDoS, ItJury = 0, ItJury, ItOC");
    while ($r = safe_fetch($rs)) {
        $out[] = array('id' => intval($r->ItId), 'label' => get_text($r->ItDescription, 'Tournament'), 'group' => intval($r->ItGroup));
    }
    return $out;
}

/** Label of a function, '' when unknown. */
function shp_fn_label($type)
{
    foreach (shp_fn_list() as $f) if ($f['id'] === intval($type)) return $f['label'];
    return '';
}

/**
 * The officials of the competition by licence: licence => rows [TiId, TiType, names, country],
 * the main function first (the order of ianseo's list: judges, director of shooting, jury, others).
 */
function shp_fn_by_licence($tourId)
{
    $order = array();
    foreach (shp_fn_list() as $i => $f) $order[$f['id']] = $i;
    $out = array();
    $rs = safe_r_sql("SELECT TiId, TiType, TiCode, TiName, TiGivenName, TiCountry FROM TournamentInvolved
        WHERE TiTournament = " . intval($tourId) . " AND TiCode <> '' ORDER BY TiId");
    while ($r = safe_fetch($rs)) {
        $lic = bk_clean_licence($r->TiCode);
        if ($lic !== '') $out[$lic][] = $r;
    }
    foreach ($out as $lic => $rows) {
        usort($rows, function ($a, $b) use ($order) {
            return ($order[intval($a->TiType)] ?? 999) <=> ($order[intval($b->TiType)] ?? 999) ?: intval($a->TiId) <=> intval($b->TiId);
        });
        $out[$lic] = $rows;
    }
    return $out;
}

/** Adds the main function of each licensed volunteer of $rows (team, waiting requests, search): fn, fn_id, fn_label. */
function shp_fn_decorate(array $rows, $tourId)
{
    $by = shp_fn_by_licence($tourId);
    foreach ($rows as $i => $r) {
        $main = $by[bk_clean_licence($r['licence'] ?? '')][0] ?? null;
        $rows[$i]['fn'] = $main ? intval($main->TiType) : 0;
        $rows[$i]['fn_id'] = $main ? intval($main->TiId) : 0;
        $rows[$i]['fn_label'] = $main ? shp_fn_label($main->TiType) : '';
    }
    return $rows;
}

/**
 * The licensed officials of ianseo's field crew without an access yet (no volunteer row still
 * working for their licence): licence, names, club, main function, licensee-space account, what
 * the team says of them (withdrawn, waiting). Sorted as ianseo's list, then by name.
 */
function shp_fn_officials($tourId)
{
    $tourId = intval($tourId);
    $team = array();
    $rs = safe_r_sql("SELECT SfLicence, SfStatus, SfApprovedAt FROM ShopStaff WHERE SfTournament = $tourId AND SfKind = 'LICENSEE' ORDER BY SfId");
    while ($r = safe_fetch($rs)) {
        $st = (string) $r->SfStatus;
        if ($st === 'revoked' && $r->SfApprovedAt === null) $st = '';
        $team[bk_clean_licence($r->SfLicence)] = $st;
    }
    $clubs = array();
    $rs = safe_r_sql("SELECT CoId, CoCode, CoName FROM Countries WHERE CoTournament = $tourId");
    while ($r = safe_fetch($rs)) $clubs[intval($r->CoId)] = $r;
    $order = array();
    foreach (shp_fn_list() as $i => $f) $order[$f['id']] = $i;
    $out = array();
    foreach (shp_fn_by_licence($tourId) as $lic => $rows) {
        $st = $team[$lic] ?? '';
        if ($st === 'active' || $st === 'locked') continue;
        $m = $rows[0];
        $c = $clubs[intval($m->TiCountry)] ?? null;
        $out[] = array('licence' => $lic, 'family' => mb_strtoupper((string) $m->TiName), 'given' => (string) $m->TiGivenName,
            'club' => $c ? (string) $c->CoCode : '', 'club_name' => $c ? (string) $c->CoName : '',
            'fn' => intval($m->TiType), 'fn_id' => intval($m->TiId), 'fn_label' => shp_fn_label($m->TiType),
            'account' => false, 'team' => $st, 'rank' => $order[intval($m->TiType)] ?? 999);
    }
    if ($out) {
        $in = implode(',', array_map(function ($r) { return StrSafe_DB($r['licence']); }, $out));
        $acc = array();
        $rs = safe_r_sql("SELECT BaLicence FROM BookingArchers WHERE BaActive = 1 AND BaLicence IN ($in)", false, true);
        while ($rs && ($r = safe_fetch($rs))) $acc[bk_clean_licence($r->BaLicence)] = true;
        foreach ($out as $i => $r) $out[$i]['account'] = isset($acc[$r['licence']]);
    }
    usort($out, function ($a, $b) { return $a['rank'] <=> $b['rank'] ?: strcasecmp($a['family'], $b['family']) ?: strcasecmp($a['given'], $b['given']); });
    return $out;
}

/** Club of the competition (Countries) for a club code, created when missing, as ManStaffField_Edit.php does. 0 without a code. */
function shp_fn_country($tourId, $code, $name)
{
    $tourId = intval($tourId);
    $code = trim((string) $code);
    if ($code === '') return 0;
    $r = safe_fetch(safe_r_sql("SELECT CoId FROM Countries WHERE CoTournament = $tourId AND CoCode = " . StrSafe_DB($code)));
    if ($r) return intval($r->CoId);
    $name = trim((string) $name) !== '' ? (string) $name : $code;
    safe_w_sql("INSERT INTO Countries SET CoTournament = $tourId, CoCode = " . StrSafe_DB($code) . ", CoName = " . StrSafe_DB($name)
        . ", CoNameComplete = " . StrSafe_DB($name));
    return intval(safe_w_last_id());
}

/**
 * Writes the function of a licensed volunteer in ianseo's field crew of the competition.
 * $wasId: the official row shown on the volunteer's card (0: none) — only this one is changed
 * ($type another function) or removed ($type 0); the person's other functions stay. Without a row
 * shown, $type adds the function (unless they already hold it). Returns ['changed' => bool, 'from', 'to'].
 */
function shp_fn_save($tourId, $staff, $wasId, $type)
{
    $tourId = intval($tourId);
    $type = intval($type);
    $wasId = intval($wasId);
    $none = array('changed' => false, 'from' => 0, 'to' => 0);
    $s = shp_staff_row($staff);
    if (!$s || $s->SfKind !== 'LICENSEE' || intval($s->SfTournament) !== $tourId) return $none;
    $lic = bk_clean_licence($s->SfLicence);
    if ($lic === '') return $none;
    if ($type > 0 && shp_fn_label($type) === '') return $none;   // not a function of ianseo
    // As ManStaffField_Edit.php: the time stamp to the microsecond.
    $t = microtime(true);
    $stamp = StrSafe_DB(date('Y-m-d H:i:s', intval($t)) . '.' . sprintf('%06d', ($t - floor($t)) * 1000000));

    $was = null;
    if ($wasId > 0) {
        $was = safe_fetch(safe_r_sql("SELECT TiId, TiType, TiCode FROM TournamentInvolved WHERE TiId = $wasId AND TiTournament = $tourId"));
        if ($was && bk_clean_licence($was->TiCode) !== $lic) $was = null;   // not this volunteer's row
    }
    if ($was) {
        $from = intval($was->TiType);
        if ($type === $from) return $none;
        if ($type === 0) {
            safe_w_sql("DELETE FROM TournamentInvolved WHERE TiTournament = $tourId AND TiId = $wasId");
        } else {
            safe_w_sql("UPDATE TournamentInvolved SET TiType = $type, TiTimeStamp = $stamp WHERE TiTournament = $tourId AND TiId = $wasId");
        }
        return array('changed' => true, 'from' => $from, 'to' => $type);
    }
    if ($type === 0) return $none;
    if (safe_fetch(safe_r_sql("SELECT TiId FROM TournamentInvolved WHERE TiTournament = $tourId AND TiType = $type
            AND TiCode = " . StrSafe_DB(mb_substr($lic, 0, 9))))) {
        return $none;
    }
    // Identity as ianseo's own form takes it: the licence file, else the licensee-space account,
    // else the volunteer's row.
    $family = (string) $s->SfFamilyName; $given = (string) $s->SfGivenName; $sex = 0; $club = ''; $clubName = '';
    $l = safe_fetch(safe_r_sql("SELECT LueFamilyName, LueName, LueSex, LueCountry, LueCoShort, LueCoDescr FROM LookUpEntries
        INNER JOIN Tournament ON ToId = $tourId AND ToIocCode = LueIocCode
        WHERE LueCode = " . StrSafe_DB($lic) . " ORDER BY LueDefault DESC LIMIT 1"));
    if ($l) {
        $family = (string) $l->LueFamilyName; $given = (string) $l->LueName; $sex = intval($l->LueSex);
        $club = (string) $l->LueCountry; $clubName = (string) ($l->LueCoShort !== '' ? $l->LueCoShort : $l->LueCoDescr);
    } else {
        $a = safe_r_sql("SELECT BaFamilyName, BaName, BaSex, BaClubCode, BaClubName FROM BookingArchers WHERE BaLicence = " . StrSafe_DB($lic)
            . " AND BaActive = 1 ORDER BY BaId DESC LIMIT 1", false, true);
        $a = $a ? safe_fetch($a) : null;
        if ($a) {
            $family = (string) $a->BaFamilyName; $given = (string) $a->BaName; $sex = intval($a->BaSex);
            $club = (string) $a->BaClubCode; $clubName = (string) $a->BaClubName;
        }
    }
    safe_w_sql("INSERT INTO TournamentInvolved SET TiTournament = $tourId, TiType = $type, TiCode = " . StrSafe_DB(mb_substr($lic, 0, 9))
        . ", TiCodeLocal = '', TiName = " . StrSafe_DB(mb_substr(mb_strtoupper($family), 0, 64))
        . ", TiGivenName = " . StrSafe_DB(mb_substr($given, 0, 64)) . ", TiCountry = " . shp_fn_country($tourId, $club, $clubName)
        . ", TiGender = " . ($sex === 1 ? 1 : 0) . ", TiTimeStamp = $stamp");
    return array('changed' => true, 'from' => 0, 'to' => $type);
}
