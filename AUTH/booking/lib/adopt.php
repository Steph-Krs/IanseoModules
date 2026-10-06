<?php
/**
 * lib/adopt.php — registration data kept across a RE-IMPORT.
 *
 * Problem (Common/Fun_TourDelete.php:tour_import): re-importing a newer version of a competition
 * already there (SAME ToCode) DELETES the old tournament and creates a new one with a DIFFERENT
 * ToId. Every BK_ table is tied to the ToId → they are orphaned, and the online registrations
 * (Entries) disappear with the old tournament.
 *
 * Solution (same principle as PRONO): BookingCompetitions is anchored on the (stable) ToCode. When
 * the organiser opens the new version, bk_adopt_check() finds the orphan (same code, different
 * ToId) and bk_adopt():
 *   1. moves every table tied to the ToId from the old one to the new one;
 *   2. reconciles the registrations with the new import:
 *      - on both sides             → linked again (placement/departure of the NEW IMPORT);
 *        different category        → 'category' conflict (import kept, organiser decides);
 *      - only in booking           → INJECTED AGAIN into the new version;
 *      - only in the import        → captured (visible in the archer's space), WITHOUT
 *        payment information (BrByRole='IMPORT').
 *
 * ⚠️ Reuses bk_register() for the injection (tested write path, core recomputation hooks)
 * rather than creating an Entry by hand.
 */

if (defined('BK_ADOPT_LOADED')) return;
define('BK_ADOPT_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/registration.php';   // bk_register, bk_lookup_licence, bk_comp_finished
require_once __DIR__ . '/payment.php';        // bk_shp (points of sale follow a re-import)

/** ToCode (stable) of a LIVE competition. '' when the tournament does not exist (any more). */
function bk_tour_code($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = " . intval($tourId)));
    return $r ? trim((string) $r->ToCode) : '';
}

/**
 * Old orphan tournament matching this code (booking data of a previous version), or 0. The most
 * recent previous version is taken.
 */
function bk_adopt_orphan($newId, $code)
{
    $newId = intval($newId);
    $code  = trim((string) $code);
    if ($code === '') return 0;
    $r = safe_fetch(safe_r_sql("SELECT BcTournament FROM BookingCompetitions
        WHERE BcCode = " . StrSafe_DB($code) . " AND BcTournament <> $newId
        ORDER BY BcTournament DESC LIMIT 1"));
    return $r ? intval($r->BcTournament) : 0;
}

/** Does the competition (new ToId) already have booking data of its own? */
function bk_has_booking_data($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT
        (SELECT COUNT(*) FROM BookingCompetitions  WHERE BcTournament = $tourId)
      + (SELECT COUNT(*) FROM BookingRegistrations WHERE BrTournament = $tourId)
      + (SELECT COUNT(*) FROM BookingPayments      WHERE PyTournament = $tourId)
      + (SELECT COUNT(*) FROM BookingLedger        WHERE BlgTournament = $tourId) AS n"));
    return $r && intval($r->n) > 0;
}

/**
 * Cheap trigger, called when a competition is opened on the organiser side. Does nothing (one
 * indexed SELECT) while there is nothing to adopt. Starts the adoption ONLY when the new
 * competition has no booking data of its own yet and an orphan with the same code exists.
 * Keeps the report in the session for the admin page to show. Returns the report or null.
 */
function bk_adopt_check($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    if ($tourId <= 0) return null;

    // Already has its own data? → either already adopted, or a competition not concerned.
    if (bk_has_booking_data($tourId)) return null;

    $code = bk_tour_code($tourId);
    if ($code === '') return null;
    if (!bk_adopt_orphan($tourId, $code)) return null;

    $report = bk_adopt($tourId);
    if ($report && !empty($report['ok'])) {
        $_SESSION['BK_ADOPT_REPORT'] = $report;
        bk_log('REIMPORT_ADOPT', 'tour=' . $tourId . ' from=' . $report['old']);
    }
    return $report;
}

/** Report of the last adoption (shown once by the admin page). */
function bk_adopt_report_pull()
{
    if (empty($_SESSION['BK_ADOPT_REPORT'])) return null;
    $r = $_SESSION['BK_ADOPT_REPORT'];
    unset($_SESSION['BK_ADOPT_REPORT']);
    return $r;
}

/** Records an inconsistency for the organiser to settle. */
function bk_reimport_conflict($tourId, $code, $licence, $name, $kind, $enId, $booking, $import)
{
    safe_w_sql("INSERT INTO BookingReimportConflicts SET
        RcTournament = " . intval($tourId) . ",
        RcCode = "     . StrSafe_DB((string) $code) . ",
        RcLicence = "  . StrSafe_DB((string) $licence) . ",
        RcName = "     . StrSafe_DB(mb_substr((string) $name, 0, 120)) . ",
        RcKind = "     . StrSafe_DB((string) $kind) . ",
        RcEnId = "     . intval($enId) . ",
        RcBooking = "  . StrSafe_DB(json_encode($booking, JSON_UNESCAPED_UNICODE)) . ",
        RcImport = "   . StrSafe_DB(json_encode($import, JSON_UNESCAPED_UNICODE)));
}

/** Unsettled inconsistencies of a competition (for the admin page). */
function bk_reimport_conflicts($tourId, $onlyOpen = true)
{
    bk_schema();
    $tourId = intval($tourId);
    $w = "RcTournament = $tourId" . ($onlyOpen ? " AND RcResolved = 0" : '');
    $out = array();
    $q = safe_r_sql("SELECT * FROM BookingReimportConflicts WHERE $w ORDER BY RcKind, RcName");
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/** Registrations captured from the import (entered outside the module, without payment). */
function bk_reimport_imported($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $out = array();
    $q = safe_r_sql("SELECT BookingRegistrations.*, EnFirstName, EnName, EnDivision, EnClass
        FROM BookingRegistrations
        INNER JOIN Entries ON EnId = BrEnId
        WHERE BrTournament = $tourId AND BrByRole = 'IMPORT'
        ORDER BY EnFirstName, EnName");
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/**
 * Injects a booking registration (missing from the new import) into the new competition again,
 * through the standard write path. Keeps the original author, date and validation status.
 * Returns ['ok'=>bool, 'msg'=>...].
 */
function bk_adopt_reinject($newId, $reg)
{
    $lue = bk_lookup_licence($reg->BrLicence);
    if (!$lue) {
        return array('ok' => false, 'msg' => bk_t('AdLicUnknown'));
    }
    $by = array(
        'archer' => intval($reg->BrArcher),
        'role'   => (string) $reg->BrByRole,
        'who'    => (string) $reg->BrBy,
    );
    $opts = array(
        'face'   => intval($reg->BrFace),
        'letter' => (string) $reg->BrWantLetter,
        'with'   => (string) $reg->BrWantWith,
        'skip_capacity' => true,   // injection of a past registration: never refused by the admission check
    );
    $res = bk_register($newId, $lue, $reg->BrDivision, $reg->BrClass,
        intval($reg->BrSession), (string) $reg->BrRequest, $by, $opts);
    if (empty($res['ok'])) {
        return array('ok' => false, 'msg' => $res['msg'] ?? bk_t('AdFailed'));
    }
    $newEn = intval($res['enid']);
    // bk_register computed BrValidated again (validation mode) and set BrCreated=now: the
    // original values are restored, then the old orphan row is deleted.
    safe_w_sql("UPDATE BookingRegistrations SET
        BrValidated = " . intval($reg->BrValidated) . ",
        BrCreated = "   . StrSafe_DB((string) $reg->BrCreated) . "
        WHERE BrEnId = $newEn");
    safe_w_sql("DELETE FROM BookingRegistrations WHERE BrId = " . intval($reg->BrId));
    return array('ok' => true, 'enid' => $newEn);
}

/* ------------------------------------------------------------------ */
/* Settling the inconsistencies (admin/reimport.php page)             */
/* ------------------------------------------------------------------ */

/** Marks an inconsistency as settled. */
function bk_reimport_resolve($rcId)
{
    safe_w_sql("UPDATE BookingReimportConflicts SET RcResolved = 1 WHERE RcId = " . intval($rcId));
}

/**
 * Applies the category of the booking registration to the import's Entry ('category'
 * conflict). The import's PLACEMENT is kept (QuTarget unchanged) and only the category changes —
 * edited in place, like PopEdit.php, followed by the recomputation hooks.
 */
function bk_reimport_apply_booking($tourId, $rc)
{
    $tourId = intval($tourId);
    $enId   = intval($rc->RcEnId);
    $book   = json_decode((string) $rc->RcBooking, true);
    if (!$enId || !is_array($book) || empty($book['division']) || empty($book['class'])) {
        return array('ok' => false, 'msg' => bk_t('AdIncomplete'));
    }
    $division = (string) $book['division'];
    $class    = (string) $book['class'];

    return bk_with_tournament($tourId, function () use ($tourId, $enId, $division, $class) {
        $now = date('Y-m-d H:i:s');

        $r = safe_fetch(safe_r_sql("SELECT (DivAthlete AND ClAthlete) AS Athlete
            FROM Divisions INNER JOIN Classes ON DivTournament = ClTournament
            WHERE DivTournament = $tourId
              AND DivId = " . StrSafe_DB($division) . "
              AND ClId  = " . StrSafe_DB($class)));
        if (!$r || !$r->Athlete) return array('ok' => false, 'msg' => bk_t('AdBadCategory'));

        $face = 0;
        $all = getTargets(true);
        if (!empty($all[$division][$class])) {
            $ids  = array_map('intval', array_keys($all[$division][$class]));
            $face = $ids[0];
        }

        safe_w_sql("UPDATE Entries SET
            EnDivision = " . StrSafe_DB($division) . ",
            EnClass = "    . StrSafe_DB($class) . ",
            EnAgeClass = " . StrSafe_DB($class) . ",
            EnAthlete = 1,
            EnTargetFace = $face,
            EnMainInfoUpdate = '$now', EnTimestamp = '$now'
            WHERE EnId = $enId");

        $p = Params4Recalc($enId);
        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
            if ($t) for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            MakeIndAbs();
        }
        checkAgainstLUE($enId);

        safe_w_sql("UPDATE BookingRegistrations SET
            BrDivision = " . StrSafe_DB($division) . ",
            BrClass = "    . StrSafe_DB($class) . ",
            BrFace = $face WHERE BrEnId = $enId");

        return array('ok' => true);
    });
}

/** Booking orphan row not injected yet (Entry gone) for a licence. */
function bk_reimport_orphan_row($tourId, $licence)
{
    $tourId = intval($tourId);
    return safe_fetch(safe_r_sql("SELECT BookingRegistrations.* FROM BookingRegistrations
        LEFT JOIN Entries ON EnId = BrEnId
        WHERE BrTournament = $tourId
          AND BrLicence = " . StrSafe_DB((string) $licence) . "
          AND BrByRole <> 'IMPORT'
          AND EnId IS NULL
        ORDER BY BrId LIMIT 1")) ?: null;
}

/** New try at injecting a 'reinject' conflict. */
function bk_reimport_retry($tourId, $rc)
{
    $reg = bk_reimport_orphan_row($tourId, $rc->RcLicence);
    if (!$reg) return array('ok' => false, 'msg' => bk_t('AdTraceGone'));
    return bk_adopt_reinject($tourId, $reg);
}

/** Gives up an injection: deletes the orphan trace. */
function bk_reimport_drop($tourId, $rc)
{
    $reg = bk_reimport_orphan_row($tourId, $rc->RcLicence);
    if ($reg) safe_w_sql("DELETE FROM BookingRegistrations WHERE BrId = " . intval($reg->BrId));
    return array('ok' => true);
}

/**
 * REMOVES a registration from the competition (Entry deleted from the ianseo core +
 * BookingRegistrations row). ADMINISTRATOR action (no archer authentication): only for the
 * re-import reconciliation. Follows bk_unregister exactly (deleteArcher + event promotion +
 * recomputation hooks), so rankings/teams are not left outdated.
 */
function bk_reimport_remove_entry($tourId, $enId)
{
    $tourId = intval($tourId);
    $enId   = intval($enId);
    if (!$enId) return array('ok' => false, 'msg' => bk_t('AdRegMissing'));

    return bk_with_tournament($tourId, function () use ($tourId, $enId) {
        if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return array('ok' => false, 'msg' => bk_t('AdLocked'));

        $old = safe_fetch(safe_r_sql("SELECT EnCode, EnDivision, " . implode(', ', bk_event_cols()) . "
            FROM Entries WHERE EnId = $enId AND EnTournament = $tourId"));
        if (!$old) { safe_w_sql("DELETE FROM BookingRegistrations WHERE BrEnId = $enId"); return array('ok' => true); }

        $p = Params4Recalc($enId);
        deleteArcher($enId);

        // The shoot that took part in the events is removed while another shoot with the same
        // weapon remains: the first remaining one of the competition takes them over, otherwise
        // the archer would leave the ranking while still registered (lib/registration.php).
        bk_events_after_removal($tourId, $old);

        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
            if ($t) for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            MakeIndAbs();
        }

        safe_w_sql("DELETE FROM BookingRegistrations WHERE BrEnId = $enId");
        return array('ok' => true);
    });
}

/**
 * Applies a decision to a conflict depending on the SIDE chosen ('import' | 'booking').
 * Meaning per type:
 *   category    import → keep the import's category; booking → apply the licensee's.
 *   onlybooking import → REMOVE the registration (the import does not have it);
 *                booking → keep it (already injected again).
 *   onlyimport  import → keep the participant (already visible);
 *                booking → REMOVE them from the competition (Entry deleted).
 *   reinject    import → give up the trace; booking → try the injection again.
 * Returns ['ok'=>bool, 'msg'=>...]. Marks the conflict settled on success.
 */
function bk_reimport_apply($tourId, $rc, $side)
{
    $side = ($side === 'booking') ? 'booking' : 'import';
    $kind = (string) $rc->RcKind;
    $r = array('ok' => true);

    if ($kind === 'category') {
        if ($side === 'booking') $r = bk_reimport_apply_booking($tourId, $rc);
        // import → nothing to do (the import is already in place).
    } elseif ($kind === 'onlybooking') {
        if ($side === 'import') $r = bk_reimport_remove_entry($tourId, intval($rc->RcEnId));
        // booking → keep (already injected again).
    } elseif ($kind === 'onlyimport') {
        if ($side === 'booking') $r = bk_reimport_remove_entry($tourId, intval($rc->RcEnId));
        // import → keep (already captured).
    } elseif ($kind === 'reinject') {
        if ($side === 'booking') { $r = bk_reimport_retry($tourId, $rc); }
        else { $r = bk_reimport_drop($tourId, $rc); }
    }

    if (!empty($r['ok'])) bk_reimport_resolve(intval($rc->RcId));
    return $r;
}

/**
 * Global button: settles ALL the unsettled conflicts on the same side. Returns a report (handled,
 * removed, failures). May take a while with many deletions.
 */
function bk_reimport_bulk($tourId, $side)
{
    $out = array('done' => 0, 'removed' => 0, 'fail' => 0);
    foreach (bk_reimport_conflicts($tourId) as $rc) {
        $before = $rc->RcKind;
        $r = bk_reimport_apply($tourId, $rc, $side);
        if (!empty($r['ok'])) {
            $out['done']++;
            if (($side === 'import' && $before === 'onlybooking') ||
                ($side === 'booking' && $before === 'onlyimport')) $out['removed']++;
        } else {
            $out['fail']++;
        }
    }
    return $out;
}

/**
 * Adopts the booking data of a previous version into the new competition. Call only through
 * bk_adopt_check() (which ensures the preconditions). Returns a report.
 */
function bk_adopt($newId)
{
    bk_schema();
    $newId = intval($newId);
    $code  = bk_tour_code($newId);
    if ($code === '') return array('ok' => false, 'reason' => 'no_code');

    $old = bk_adopt_orphan($newId, $code);
    if (!$old) return array('ok' => false, 'reason' => 'no_orphan');

    // Guard: never overwrite booking data already on the new competition (ambiguous case: the
    // organiser would have set booking up again on the new import).
    if (bk_has_booking_data($newId)) {
        return array('ok' => false, 'reason' => 'target_has_data', 'old' => $old, 'new' => $newId);
    }

    $rep = array('ok' => true, 'old' => $old, 'new' => $newId, 'code' => $code,
        'relinked' => 0, 'reinjected' => 0, 'reinject_fail' => 0, 'imported' => 0,
        'category' => 0, 'payments' => 0);

    // How many payments moved (for the report).
    $p = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BookingPayments WHERE PyTournament = $old"));
    $rep['payments'] = $p ? intval($p->n) : 0;

    // Points of sale (AUTH/shop) follow too, unless the new version already has stands or orders
    // of its own. Schema first: it may run DDL, which would end the transaction below.
    $shopMove = bk_shp() && !safe_fetch(safe_r_sql("SELECT 1 AS x FROM ShopStands WHERE SdTournament = $newId
        UNION SELECT 1 FROM ShopOrders WHERE ShTournament = $newId LIMIT 1"));
    $shopOld = $shopMove && safe_fetch(safe_r_sql("SELECT SgTournament FROM ShopSettings WHERE SgTournament = $old"));

    // ---- Phase A: move the tables tied to the ToId (old → new) ----
    safe_w_sql("START TRANSACTION");
    safe_w_sql("UPDATE BookingCompetitions   SET BcTournament = $newId, BcCode = " . StrSafe_DB($code) . " WHERE BcTournament = $old");
    safe_w_sql("UPDATE BookingTargetCaps     SET BtTournament = $newId WHERE BtTournament = $old");
    safe_w_sql("UPDATE BookingShopItems      SET SiTournament = $newId WHERE SiTournament = $old");
    safe_w_sql("UPDATE BookingShopOrders     SET SoTournament = $newId WHERE SoTournament = $old");
    safe_w_sql("UPDATE BookingPayments       SET PyTournament = $newId WHERE PyTournament = $old");
    safe_w_sql("UPDATE BookingRegistrations  SET BrTournament = $newId WHERE BrTournament = $old");
    safe_w_sql("UPDATE BookingSurveys        SET BqTournament = $newId WHERE BqTournament = $old");
    safe_w_sql("UPDATE BookingSurveyVoters   SET BvTournament = $newId WHERE BvTournament = $old");
    // Waiting list: the archers keep their place in the queue across a re-import. A
    // promoted row points at an Entry of the old version (BwEnId): the history stays.
    safe_w_sql("UPDATE BookingWaitlist       SET BwTournament = $newId WHERE BwTournament = $old");
    safe_w_sql("UPDATE BookingRefunds        SET BfTournament = $newId WHERE BfTournament = $old");
    // Payments journal: accounts are licences, they follow as they are. An account "#<EnId>"
    // (participant without a licence) keeps the old EnId and shows apart, with its payments.
    safe_w_sql("UPDATE BookingLedger         SET BlgTournament = $newId WHERE BlgTournament = $old");
    if ($shopMove) {
        // The settings of the old version win (its public key is the one printed on the posters):
        // an empty row made by a visit to the new version goes.
        if ($shopOld) safe_w_sql("DELETE FROM ShopSettings WHERE SgTournament = $newId");
        foreach (array('ShopSettings' => 'SgTournament', 'ShopStands' => 'SdTournament', 'ShopProducts' => 'SpTournament',
                'ShopOrders' => 'ShTournament', 'ShopStockMoves' => 'SmTournament', 'ShopStaff' => 'SfTournament',
                'ShopInvites' => 'SqTournament', 'ShopGuests' => 'SuTournament', 'ShopStaffLog' => 'SjTournament') as $tb => $col) {
            safe_w_sql("UPDATE $tb SET $col = $newId WHERE $col = $old");
        }
    }
    safe_w_sql("COMMIT");
    // A former shop that was waiting on the old version (no longer in Tournament) moves now.
    if (bk_shp()) {
        require_once dirname(__DIR__, 2) . '/shop/lib/legacy.php';
        shp_legacy_migrate();
    }

    // ---- Phase B: reconcile the registrations with the new import ----

    // Booking registrations (now on the new ToId, but BrEnId still points to the deleted
    // Entries). Captured in an array: the loop changes the table.
    $regs = array();
    $q = safe_r_sql("SELECT * FROM BookingRegistrations WHERE BrTournament = $newId ORDER BY BrId");
    while ($r = safe_fetch($q)) $regs[] = $r;

    // Entries of the new import, indexed by licence (each one "to be claimed").
    $importByLic = array();
    $q = safe_r_sql("SELECT EnId, EnCode, EnDivision, EnClass, EnTargetFace,
                EnFirstName, EnName, QuSession, QuTarget
        /* ⚠ One of the few places of the module where Qualifications is LEFT joined, and it
           must stay so. Elsewhere the relation is 1:1 (INNER JOIN) because the core repairs
           it at the top of Partecipants/index.php — but here the competition HAS JUST been
           imported and that screen was never opened. An INNER JOIN would leave an archer
           without a placement row out of the per-licence index: the reconciliation would
           believe them absent in the import and inject a duplicate. */
        FROM Entries LEFT JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $newId");
    while ($e = safe_fetch($q)) {
        $lic = trim((string) $e->EnCode);
        $importByLic[$lic][] = array('e' => $e, 'claimed' => false);
    }

    // Matching, one booking registration at a time. Indexed straight into $importByLic (no
    // reference: `=& $importByLic[$lic]` would create a null entry for a missing licence, then
    // walked through as a new participant).
    foreach ($regs as $reg) {
        $lic = trim((string) $reg->BrLicence);
        $pickIdx = -1;

        if (!empty($importByLic[$lic])) {
            // Priority: same departure AND same bow; then same departure; then same bow; then
            // any unclaimed Entry of this licence.
            $prefs = array(
                function ($e) use ($reg) { return intval($e->QuSession) === intval($reg->BrSession) && (string) $e->EnDivision === (string) $reg->BrDivision; },
                function ($e) use ($reg) { return intval($e->QuSession) === intval($reg->BrSession); },
                function ($e) use ($reg) { return (string) $e->EnDivision === (string) $reg->BrDivision; },
                function ($e) { return true; },
            );
            foreach ($prefs as $ok) {
                foreach ($importByLic[$lic] as $i => $cand) {
                    if ($cand['claimed']) continue;
                    if ($ok($cand['e'])) { $pickIdx = $i; break; }
                }
                if ($pickIdx >= 0) break;
            }
        }

        if ($pickIdx >= 0) {
            $e = $importByLic[$lic][$pickIdx]['e'];
            $importByLic[$lic][$pickIdx]['claimed'] = true;
            $enId = intval($e->EnId);

            // Placement / departure: the NEW IMPORT decides (the snapshot is synchronised).
            $catDiff = ((string) $e->EnDivision !== (string) $reg->BrDivision)
                    || ((string) $e->EnClass    !== (string) $reg->BrClass);
            safe_w_sql("UPDATE BookingRegistrations SET
                BrEnId = $enId,
                BrDivision = " . StrSafe_DB((string) $e->EnDivision) . ",
                BrClass = "    . StrSafe_DB((string) $e->EnClass) . ",
                BrSession = "  . intval($e->QuSession) . ",
                BrFace = "     . intval($e->EnTargetFace) . "
                WHERE BrId = " . intval($reg->BrId));
            $rep['relinked']++;

            if ($catDiff) {
                // Same person + same departure, different category: the import is kept, the
                // organiser confirms on the dedicated page.
                $rep['category']++;
                bk_reimport_conflict($newId, $code, $lic,
                    trim($e->EnFirstName . ' ' . $e->EnName), 'category', $enId,
                    array('division' => $reg->BrDivision, 'class' => $reg->BrClass),
                    array('division' => $e->EnDivision, 'class' => $e->EnClass));
            }
        } else {
            // Missing from the import → injected into the new version (safe default: never lose
            // an online registration). Recorded as an 'onlybooking' conflict so the organiser
            // can KEEP it (default) or REMOVE it (the import does not have it).
            $res = bk_adopt_reinject($newId, $reg);
            if (!empty($res['ok'])) {
                $rep['reinjected']++;
                $nm = '';
                $ne = safe_fetch(safe_r_sql("SELECT EnFirstName, EnName FROM Entries WHERE EnId = " . intval($res['enid'])));
                if ($ne) $nm = trim($ne->EnFirstName . ' ' . $ne->EnName);
                bk_reimport_conflict($newId, $code, $lic, $nm, 'onlybooking', intval($res['enid']),
                    array('division' => $reg->BrDivision, 'class' => $reg->BrClass, 'session' => $reg->BrSession),
                    null);
            } else {
                $rep['reinject_fail']++;
                bk_reimport_conflict($newId, $code, $lic,
                    '', 'reinject', 0,
                    array('division' => $reg->BrDivision, 'class' => $reg->BrClass,
                          'session' => $reg->BrSession, 'msg' => $res['msg'] ?? ''),
                    null);
                // The old orphan row is kept for tracing / manual handling.
            }
        }
    }

    // Participants ONLY in the import (entered outside the module) → captured in the archer's
    // space (BrByRole='IMPORT'), without payment information (safe default: a participant is
    // never deleted without a decision). Recorded as 'onlyimport' conflicts so the organiser
    // can KEEP them (default) or REMOVE them from the competition ("booking side" choice).
    $now = date('Y-m-d H:i:s');
    foreach ($importByLic as $lic => $cands) {
        foreach ($cands as $cand) {
            if ($cand['claimed']) continue;
            $e = $cand['e'];
            safe_w_sql("INSERT INTO BookingRegistrations SET
                BrEnId = "     . intval($e->EnId) . ",
                BrTournament = $newId,
                BrArcher = 0,
                BrLicence = "  . StrSafe_DB((string) $e->EnCode) . ",
                BrByRole = 'IMPORT',
                BrBy = 'import',
                BrRequest = NULL,
                BrWantLetter = '', BrWantWith = '',
                BrValidated = 1,
                BrDivision = " . StrSafe_DB((string) $e->EnDivision) . ",
                BrClass = "    . StrSafe_DB((string) $e->EnClass) . ",
                BrSession = "  . intval($e->QuSession) . ",
                BrFace = "     . intval($e->EnTargetFace) . ",
                BrCreated = "  . StrSafe_DB($now));
            $rep['imported']++;
            bk_reimport_conflict($newId, $code, (string) $e->EnCode,
                trim($e->EnFirstName . ' ' . $e->EnName), 'onlyimport', intval($e->EnId),
                null,
                array('division' => $e->EnDivision, 'class' => $e->EnClass, 'session' => $e->QuSession));
        }
    }

    return $rep;
}
