<?php
/**
 * AUTH module — anonymising a licensee across every competition of the server
 * (administrator page admin/anonymise.php).
 *
 * The person is found by their licence. Competitions to come and those already shot are
 * treated differently:
 *  - TO COME (not over, and no score of this person yet): the registrations are DELETED,
 *    through the module's own removal path (core recalculation hooks), shop orders and
 *    declared payment choice too, official roles as well; the freed places go to the waiting
 *    list. If something had been paid (payments journal), a refund (club + amount, no name) is
 *    recorded for the organiser (BK_Refunds), shown on the "Paiements" page of that
 *    competition; the journal lines stay, under the anonymous code, and marking the refund as
 *    done writes the matching refund line. A competition
 *    whose participants are locked by its organiser cannot be changed: it is anonymised
 *    as below instead, and the page says so.
 *  - SHOT (over, or the person has a score — never destroy a result): ANONYMISED.
 *
 * Anonymised, sport data stays (scores, ranks, matches, club, category); what identifies
 * the person goes:
 *  - participants (Entries): licence replaced by AUT_ANON_CODE, family name, first name and
 *    TV display names emptied, date of birth removed — in both columns that carry it:
 *    EnDob, and EnCtrlCode where ianseo's participant form keeps the date as typed. The
 *    category stays: it is stored (EnClass, EnAgeClass) and the core never recomputes it
 *    by itself; for a missing date (EnDob = 0) it offers every age class. Photo deleted
 *    (Photos + TV/Photos/<code>-En-<id>.jpg), caption and e-mail of ExtraData removed;
 *  - officials (TournamentInvolved): licence replaced, local code, names emptied;
 *  - online account of the archer (BK_Archers, its sessions and club-manager rights):
 *    deleted — it is copied from the federal file at each login, emptying it would not
 *    last. Their waiting-list rows go too. Every other BK_ row carrying the licence gets
 *    the anonymous code (registrations, payments, shop, journal, re-import conflicts),
 *    or loses the licence (survey answer, voters' roll, wishes of others).
 *
 * The anonymous code is the SAME for everybody: no new identifier is made up. Checked: no
 * unique key on EnCode or TiCode in the core. The BK_ tables with a unique key on
 * (competition, licence) are updated with IGNORE, and what would collide (two anonymised
 * people on the same competition) is merged — payments and shop orders of the second are
 * dropped.
 *
 * Empty names were checked against the core: it tests for a MISSING archer (NULL), never
 * for an empty string; lists and PDFs show a blank, brackets show "(CLUB)". The anonymous
 * code matches nothing in the federal file, so ianseo no longer flags the entry as
 * different from it, and an organiser cannot fill it again from there.
 *
 * Not touched, by design: LookUpEntries (the federal file, reloaded every night), the
 * tables of other modules, results already published on ianseo.net or sent to the
 * federation, backups (they expire on their own).
 */

if (!defined('AUT_ANON_CODE')) define('AUT_ANON_CODE', 'ANON');

if (!function_exists('aut_csrf_check') && is_file(__DIR__ . '/lib.php')) require_once __DIR__ . '/lib.php';
// Online registration is part of this module: removal path, dues, refunds, waiting list.
require_once __DIR__ . '/booking/lib/waitlist.php';
require_once __DIR__ . '/booking/lib/adopt.php';   // bk_reimport_remove_entry

/** Does a table exist? (module tables may not, on a server without online registration) */
function aut_anon_table($name)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($name), false, true));
    return (bool) $r;
}

/** LIKE pattern for user input: wildcards of the input are taken literally. */
function aut_anon_like($word)
{
    return StrSafe_DB('%' . addcslashes($word, '%_\\') . '%');
}

/**
 * Licences matching a search: a licence (exact), or words that must all appear in the
 * family or first name. Looks at participants, officials, online accounts and the federal
 * file. Returns [licence => ['name', 'year', 'club', 'entries', 'officials', 'account']].
 */
function aut_anon_search($q)
{
    $q = trim((string) $q);
    $out = array();
    if (mb_strlen($q) < 3) return $out;
    $isLic = (bool) preg_match('/^[0-9]{5,8}[A-Za-z]?$/', $q);
    $words = array_slice(preg_split('/\s+/u', $q), 0, 4);
    $cond = function ($code, $a, $b) use ($q, $isLic, $words) {
        if ($isLic) return "$code = " . StrSafe_DB($q);
        $w = array();
        foreach ($words as $x) $w[] = "($a LIKE " . aut_anon_like($x) . " OR $b LIKE " . aut_anon_like($x) . ")";
        return implode(' AND ', $w);
    };
    $add = function ($lic, $name, $year, $club) use (&$out) {
        $lic = trim((string) $lic);
        if ($lic === '' || strcasecmp($lic, AUT_ANON_CODE) === 0) return;   // already anonymised
        if (!isset($out[$lic])) $out[$lic] = array('name' => '', 'year' => '', 'club' => '', 'entries' => 0, 'officials' => 0, 'account' => false);
        if ($out[$lic]['name'] === '' && trim($name) !== '') $out[$lic]['name'] = trim($name);
        if ($out[$lic]['year'] === '' && intval($year) > 0) $out[$lic]['year'] = (string) intval($year);
        if ($out[$lic]['club'] === '' && trim((string) $club) !== '') $out[$lic]['club'] = trim($club);
    };

    $rs = safe_r_sql("SELECT EnCode, MAX(CONCAT(EnFirstName, ' ', EnName)) AS Nm, MAX(YEAR(EnDob)) AS Y,
            MAX(CoName) AS Club, COUNT(*) AS N
        FROM Entries LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnCode <> '' AND " . $cond('EnCode', 'EnFirstName', 'EnName') . "
        GROUP BY EnCode LIMIT 100");
    while ($r = safe_fetch($rs)) { $add($r->EnCode, $r->Nm, $r->Y, $r->Club); $out[trim($r->EnCode)]['entries'] = intval($r->N); }

    $rs = safe_r_sql("SELECT TiCode, MAX(CONCAT(TiName, ' ', TiGivenName)) AS Nm, COUNT(*) AS N
        FROM TournamentInvolved
        WHERE TiCode <> '' AND " . $cond('TiCode', 'TiName', 'TiGivenName') . "
        GROUP BY TiCode LIMIT 100");
    while ($r = safe_fetch($rs)) { $add($r->TiCode, $r->Nm, 0, ''); $out[trim($r->TiCode)]['officials'] = intval($r->N); }

    $rs = safe_r_sql("SELECT LueCode, CONCAT(LueFamilyName, ' ', LueName) AS Nm, YEAR(LueCtrlCode) AS Y, LueCoDescr
        FROM LookUpEntries WHERE " . $cond('LueCode', 'LueFamilyName', 'LueName') . " LIMIT 100");
    while ($r = safe_fetch($rs)) $add($r->LueCode, $r->Nm, $r->Y, $r->LueCoDescr);

    if (aut_anon_table('BK_Archers')) {
        $rs = safe_r_sql("SELECT BaLicence, CONCAT(BaFamilyName, ' ', BaName) AS Nm FROM BK_Archers
            WHERE " . $cond('BaLicence', 'BaFamilyName', 'BaName') . " LIMIT 100");
        while ($r = safe_fetch($rs)) { $add($r->BaLicence, $r->Nm, 0, ''); $out[trim($r->BaLicence)]['account'] = true; }
    }

    // Counts for the licences found through another table only.
    foreach ($out as $lic => &$p) {
        if (!$p['entries']) {
            $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM Entries WHERE EnCode = " . StrSafe_DB($lic)));
            $p['entries'] = $r ? intval($r->n) : 0;
        }
        if (!$p['officials']) {
            $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM TournamentInvolved WHERE TiCode = " . StrSafe_DB($lic)));
            $p['officials'] = $r ? intval($r->n) : 0;
        }
        if (!$p['account'] && aut_anon_table('BK_Archers')) {
            $p['account'] = (bool) safe_fetch(safe_r_sql("SELECT BaId FROM BK_Archers WHERE BaLicence = " . StrSafe_DB($lic)));
        }
    }
    unset($p);
    uasort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return array_slice($out, 0, 50, true);
}

/**
 * Everything the server holds about a licence, for the preview: participations and
 * official roles (with their competition), photos, extra data, account, waiting rows.
 */
function aut_anon_person($lic)
{
    $l = StrSafe_DB(trim((string) $lic));
    $p = array('entries' => array(), 'officials' => array(), 'photos' => 0, 'extra' => 0,
               'account' => null, 'waits' => 0, 'conflicts' => 0, 'lue' => null);
    $rs = safe_r_sql("SELECT EnId, EnTournament, EnFirstName, EnName, EnDob, EnCtrlCode, EnDivision, EnClass,
            ToCode, ToName, ToWhenFrom, ToWhenTo, ToOnlineId
        FROM Entries INNER JOIN Tournament ON ToId = EnTournament
        WHERE EnCode = $l ORDER BY ToWhenFrom DESC, EnId");
    while ($r = safe_fetch($rs)) $p['entries'][] = $r;
    $rs = safe_r_sql("SELECT TiId, TiTournament, TiName, TiGivenName, ItDescription, ToCode, ToName, ToWhenFrom, ToWhenTo, ToOnlineId
        FROM TournamentInvolved INNER JOIN Tournament ON ToId = TiTournament
        LEFT JOIN InvolvedType ON ItId = TiType
        WHERE TiCode = $l ORDER BY ToWhenFrom DESC, TiId");
    while ($r = safe_fetch($rs)) $p['officials'][] = $r;
    $ids = aut_anon_entry_ids($lic);
    if ($ids) {
        $in = implode(',', $ids);
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM Photos WHERE PhEnId IN ($in)"));
        $p['photos'] = $r ? intval($r->n) : 0;
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM ExtraData WHERE EdId IN ($in) AND (EdType IN ('C', 'E') OR EdEmail <> '')"));
        $p['extra'] = $r ? intval($r->n) : 0;
    }
    if (aut_anon_table('BK_Archers')) {
        $p['account'] = safe_fetch(safe_r_sql("SELECT BaId, BaFamilyName, BaName, BaEmail FROM BK_Archers WHERE BaLicence = $l")) ?: null;
    }
    if (aut_anon_table('BK_Waitlist')) {
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BK_Waitlist WHERE BwLicence = $l"));
        $p['waits'] = $r ? intval($r->n) : 0;
    }
    if (aut_anon_table('BK_ReimportConflicts')) {
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BK_ReimportConflicts WHERE RcLicence = $l"));
        $p['conflicts'] = $r ? intval($r->n) : 0;
    }
    $p['lue'] = safe_fetch(safe_r_sql("SELECT LueFamilyName, LueName, LueCtrlCode, LueCoDescr FROM LookUpEntries
        WHERE LueCode = $l ORDER BY LueDefault DESC LIMIT 1")) ?: null;
    return $p;
}

/**
 * Competitions TO COME for this licence: not over (local date of each competition), where
 * the person has something (registration, payment, shop order, official role) and no score
 * yet. For each: [ToId => ['code', 'name', 'entries' => [EnId], 'officials' => n,
 * 'refund' => null | ['amount', 'method', 'club_code', 'club_name']]]. The refund is what the
 * payments journal holds for this licence (bk_account, before anything is removed), with the
 * method of the last payment. Reads only — apart from bk_ledger_migrate(), which takes over
 * the former "paid" ticks into the journal once. The preview and aut_anon_apply() share it.
 */
function aut_anon_plan($lic)
{
    bk_schema();
    $l = StrSafe_DB(trim((string) $lic));
    $out = array();
    $rs = safe_r_sql("SELECT ToId, ToCode, ToName FROM Tournament
        WHERE ToWhenTo >= " . bk_local_today_sql('ToTimeZone') . "
          AND ToId IN (SELECT EnTournament FROM Entries WHERE EnCode = $l
                 UNION SELECT TiTournament FROM TournamentInvolved WHERE TiCode = $l
                 UNION SELECT PyTournament FROM BK_Payments WHERE PyLicence = $l
                 UNION SELECT SoTournament FROM BK_ShopOrders WHERE SoLicence = $l
                 UNION SELECT BlgTournament FROM BK_Ledger WHERE BlgAccount = $l)
          AND ToId NOT IN (SELECT EnTournament FROM Entries INNER JOIN Qualifications ON QuId = EnId
                 WHERE EnCode = $l AND (QuScore <> 0 OR QuHits <> 0))
        ORDER BY ToWhenFrom, ToId");
    while ($r = safe_fetch($rs)) {
        $t = intval($r->ToId);
        $out[$t] = array('code' => $r->ToCode, 'name' => $r->ToName, 'entries' => array(), 'officials' => 0, 'refund' => null);
        $q = safe_r_sql("SELECT EnId FROM Entries WHERE EnTournament = $t AND EnCode = $l");
        while ($e = safe_fetch($q)) $out[$t]['entries'][] = intval($e->EnId);
        $o = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM TournamentInvolved WHERE TiTournament = $t AND TiCode = $l"));
        $out[$t]['officials'] = $o ? intval($o->n) : 0;
        $acc = bk_account($t, $lic);
        if ($acc['paid'] > 0.005) {
            $method = '';
            foreach ($acc['moves'] as $m) if ($m->BlgKind === 'payment' && !intval($m->BlgCancelled)) $method = (string) $m->BlgMethod;
            $club = safe_fetch(safe_r_sql("SELECT CoCode AS c, CoName AS n FROM Entries
                INNER JOIN Countries ON CoId = EnCountry WHERE EnTournament = $t AND EnCode = $l LIMIT 1"))
                ?: safe_fetch(safe_r_sql("SELECT LueCountry AS c, LueCoDescr AS n FROM LookUpEntries
                WHERE LueCode = $l ORDER BY LueDefault DESC LIMIT 1"));
            $out[$t]['refund'] = array('amount' => $acc['paid'], 'method' => $method,
                'club_code' => $club ? (string) $club->c : '', 'club_name' => $club ? (string) $club->n : '');
        }
    }
    return $out;
}

/**
 * Removes the person from a competition to come (see aut_anon_plan): registrations through
 * the module's removal path, then official roles, shop orders, declared payment choice; records
 * the refund (the journal lines are kept, aut_anon_bk_tables() gives them the anonymous code);
 * gives the freed places to the waiting list. false when the organiser has locked the
 * participants (nothing removed then: the caller anonymises instead).
 */
function aut_anon_remove_from($tourId, $lic, $plan)
{
    $tourId = intval($tourId);
    $l = StrSafe_DB($lic);
    foreach ($plan['entries'] as $enId) {
        $r = bk_reimport_remove_entry($tourId, $enId);
        if (empty($r['ok'])) return false;   // locked: only the first one can fail, nothing removed yet
    }
    safe_w_sql("DELETE FROM TournamentInvolved WHERE TiTournament = $tourId AND TiCode = $l");
    safe_w_sql("DELETE FROM BK_ShopOrders WHERE SoTournament = $tourId AND SoLicence = $l");
    safe_w_sql("DELETE FROM BK_Payments WHERE PyTournament = $tourId AND PyLicence = $l");
    if ($plan['refund']) {
        $f = $plan['refund'];
        bk_refund_add($tourId, $f['club_code'], $f['club_name'], $f['amount'], $f['method']);
    }
    if ($plan['entries']) bk_waitlist_process($tourId);
    return true;
}

/** Entries.EnId of a licence, every competition. */
function aut_anon_entry_ids($lic)
{
    $ids = array();
    $rs = safe_r_sql("SELECT EnId FROM Entries WHERE EnCode = " . StrSafe_DB(trim((string) $lic)));
    while ($r = safe_fetch($rs)) $ids[] = intval($r->EnId);
    return $ids;
}

/**
 * The licence in the online-registration tables (BK_), beside the account and waiting
 * list handled by aut_anon_apply(). Tables with a unique key on (competition, licence)
 * are updated with IGNORE, then what could not move (a second anonymised person on the
 * same competition) is deleted: the licence must not survive anywhere.
 */
function aut_anon_bk_tables($lic)
{
    $l = StrSafe_DB($lic);
    $a = StrSafe_DB(AUT_ANON_CODE);
    if (aut_anon_table('BK_Registrations')) {
        safe_w_sql("UPDATE BK_Registrations SET BrLicence = $a WHERE BrLicence = $l");
        safe_w_sql("UPDATE BK_Registrations SET BrBy = $a WHERE BrBy = $l");   // registered others
        safe_w_sql("UPDATE BK_Registrations SET BrWantWith = '' WHERE BrWantWith = $l");
    }
    // Payments journal: the money did come in (and, for a competition to come, is to be given
    // back): the lines stay, under the anonymous code. No unique key there.
    if (aut_anon_table('BK_Ledger')) safe_w_sql("UPDATE BK_Ledger SET BlgAccount = $a WHERE BlgAccount = $l");
    foreach (array('BK_Payments' => 'PyLicence', 'BK_ShopOrders' => 'SoLicence') as $t => $c) {
        if (!aut_anon_table($t)) continue;
        safe_w_sql("UPDATE IGNORE $t SET $c = $a WHERE $c = $l");
        safe_w_sql("DELETE FROM $t WHERE $c = $l");
    }
    // Survey: the answer keeps its anonymous form "#<id>" (bk_survey_anonymise); the roll of
    // who answered is only about a licence, it goes.
    if (aut_anon_table('BK_Surveys')) safe_w_sql("UPDATE BK_Surveys SET BqLicence = CONCAT('#', BqId) WHERE BqLicence = $l");
    if (aut_anon_table('BK_SurveyVoters')) safe_w_sql("DELETE FROM BK_SurveyVoters WHERE BvLicence = $l");
    if (aut_anon_table('BK_Log')) safe_w_sql("UPDATE BK_Log SET BlUser = $a WHERE BlUser = $l");
    if (aut_anon_table('BK_ReimportConflicts')) {
        safe_w_sql("UPDATE BK_ReimportConflicts SET RcLicence = $a, RcName = '' WHERE RcLicence = $l");
    }
}

/**
 * Anonymises a licence everywhere (see the file header). Competitions to come first, each
 * through the core's own removal path; then one transaction for the rest; photo files are
 * deleted at the end. Returns the counts, the competitions the person was removed from
 * ('removed', with their refunds) or that were locked ('locked'), and the competitions
 * published on ianseo.net, which keep the names until published again.
 */
function aut_anon_apply($lic)
{
    global $CFG;
    $lic = trim((string) $lic);
    $l = StrSafe_DB($lic);
    $a = StrSafe_DB(AUT_ANON_CODE);
    $res = array('entries' => 0, 'officials' => 0, 'photos' => 0, 'extra' => 0, 'account' => 0,
                 'waits' => 0, 'online' => array(), 'removed' => array(), 'locked' => array());
    if ($lic === '' || strcasecmp($lic, AUT_ANON_CODE) === 0) return $res;

    // Photo files and published competitions: every participation, before anything changes.
    $ids = aut_anon_entry_ids($lic);
    $files = array();
    if ($ids) {
        $rs = safe_r_sql("SELECT EnId, ToCode, ToName, ToOnlineId FROM Entries INNER JOIN Tournament ON ToId = EnTournament
            WHERE EnId IN (" . implode(',', $ids) . ")");
        while ($r = safe_fetch($rs)) {
            // Same name as ianseo's (Common/CheckPictures.php).
            $files[] = $CFG->DOCUMENT_PATH . 'TV/Photos/' . preg_replace('/[^a-z0-9_.-]/sim', '', $r->ToCode) . '-En-' . intval($r->EnId) . '.jpg';
            if (intval($r->ToOnlineId) > 0) $res['online'][$r->ToCode] = $r->ToName;
        }
    }
    $rs = safe_r_sql("SELECT ToCode, ToName FROM TournamentInvolved INNER JOIN Tournament ON ToId = TiTournament
        WHERE TiCode = $l AND ToOnlineId > 0");
    while ($r = safe_fetch($rs)) $res['online'][$r->ToCode] = $r->ToName;

    // Waiting rows first: a place freed below must not go back to this very person.
    bk_schema();
    safe_w_sql("DELETE FROM BK_Waitlist WHERE BwLicence = $l");
    $res['waits'] = safe_w_affected_rows();

    // Competitions to come: the person is removed, not anonymised.
    foreach (aut_anon_plan($lic) as $t => $p) {
        if (aut_anon_remove_from($t, $lic, $p)) $res['removed'][$t] = $p;
        else $res['locked'][$t] = $p;
    }

    // What is left: competitions already shot (and locked ones), anonymised.
    $ids = aut_anon_entry_ids($lic);
    safe_w_sql("START TRANSACTION");
    $res['entries'] = count($ids);
    if ($ids) {
        $in = implode(',', $ids);
        // By id, not by licence: the licence is what this statement changes. EnLue*: the
        // anonymous code matches nothing in the federal file (as checkAgainstLUE would set).
        safe_w_sql("UPDATE Entries SET EnCode = $a, EnFirstName = '', EnName = '',
                EnTvGivenName = '', EnTvFamilyName = '', EnTvInitials = '',
                EnDob = '0000-00-00', EnCtrlCode = '', EnLueFieldChanged = 0, EnLueTimeStamp = 0,
                EnTimestamp = NOW()
            WHERE EnId IN ($in)");
        safe_w_sql("DELETE FROM Photos WHERE PhEnId IN ($in)");
        $res['photos'] = safe_w_affected_rows();
        safe_w_sql("DELETE FROM ExtraData WHERE EdId IN ($in) AND EdType IN ('C', 'E')");
        $res['extra'] = safe_w_affected_rows();
        safe_w_sql("UPDATE ExtraData SET EdEmail = '' WHERE EdId IN ($in) AND EdEmail <> ''");
        $res['extra'] += safe_w_affected_rows();
    }
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM TournamentInvolved WHERE TiCode = $l"));
    $res['officials'] = $r ? intval($r->n) : 0;
    safe_w_sql("UPDATE TournamentInvolved SET TiCode = $a, TiCodeLocal = '', TiName = '', TiGivenName = '' WHERE TiCode = $l");

    if (aut_anon_table('BK_Archers')) {
        $rs = safe_r_sql("SELECT BaId FROM BK_Archers WHERE BaLicence = $l");
        $acc = array();
        while ($r = safe_fetch($rs)) $acc[] = intval($r->BaId);
        if ($acc) {
            $in = implode(',', $acc);
            if (aut_anon_table('BK_Sessions')) safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher IN ($in)");
            if (aut_anon_table('BK_ClubManagers')) safe_w_sql("DELETE FROM BK_ClubManagers WHERE BmArcher IN ($in)");
            safe_w_sql("DELETE FROM BK_Archers WHERE BaId IN ($in)");
            $res['account'] = count($acc);
        }
    }
    // Waiting rows of others: this person as their author, or as the one they want to shoot with.
    safe_w_sql("UPDATE BK_Waitlist SET BwBy = $a WHERE BwBy = $l");
    safe_w_sql("UPDATE BK_Waitlist SET BwWantWith = '' WHERE BwWantWith = $l");
    aut_anon_bk_tables($lic);
    safe_w_sql("COMMIT");

    foreach ($files as $f) if (is_file($f)) @unlink($f);
    return $res;
}
