<?php
/**
 * lib/modify.php — an archer changes their online registration until the registration closes:
 * bow, category, face, wheelchair, departure, events (duels, team events) and wishes.
 *
 * Changed IN PLACE (same Entries row, same BookingRegistrations row) rather than cancelled and
 * made again: the registration keeps its date (rank of the multi-registration discount, seniority
 * of the wishes), its payments and the club it was paid by. Every rule of a new registration is
 * checked again, the registration itself not counting against its own departure or profile.
 * The amount due follows by itself: it is computed from the registration (bk_account_registrations).
 */

if (defined('BK_MODIFY_LOADED')) return;
define('BK_MODIFY_LOADED', true);

require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/targets.php';   // bk_profile_remaining

/**
 * The registration $enId, if the signed-in archer may change it: their own online registration,
 * one they made for a clubmate (BrArcher), or a participation the organiser entered in ianseo for
 * their licence (bk_reg_org_row) — same rules for all. Returns [row, ''] or [null, refusal
 * message]. Until when:
 * bk_change_window() (lib/sessionrules.php) — by default while the registration of its departure is
 * open and the competition has not started; with BcModUntilStart, until the departure starts.
 */
function bk_reg_modifiable($enId, $archer)
{
    $enId = intval($enId);
    $r = safe_fetch(safe_r_sql("SELECT BrEnId, BrTournament, BrLicence, BrArcher, BrByRole, BrLicHold, BrValidated,
            BrRequest, BrWantLetter, BrWantWith, BrEvOpts,
            EnCode, EnDivision, EnClass, EnTargetFace, EnWChair, " . implode(', ', bk_event_cols()) . ",
            QuSession, QuTarget, QuLetter
        FROM BookingRegistrations
        INNER JOIN Entries ON EnId = BrEnId AND EnTournament = BrTournament
        INNER JOIN Qualifications ON QuId = EnId
        WHERE BrEnId = $enId"));
    if (!$r) $r = bk_reg_org_row($enId, $archer->BaLicence);
    if (!$r) return array(null, bk_t('RgModMissing'));
    $isOwn = bk_clean_licence($r->BrLicence) === bk_clean_licence($archer->BaLicence);
    // An entry of the organiser has no author: only the archer themself.
    $isAuthor = (string) $r->BrByRole !== 'IMPORT' && intval($archer->BaId) > 0 && intval($r->BrArcher) === intval($archer->BaId);
    if (!$isOwn && !$isAuthor) return array(null, bk_t('NotYourReg'));
    if (!empty($r->BrLicHold)) return array(null, bk_t('RgModHeld'));
    $tourId = intval($r->BrTournament);
    $w = bk_change_window($tourId, bk_comp_config($tourId), intval($r->QuSession));
    if (!$w['modify']['ok']) return array(null, bk_t($w['now'] >= $w['cancel']['until'] && bk_comp_started($tourId) ? 'RgStarted' : 'RgModClosed'));
    return array($r, '');
}

/**
 * Changes the registration $enId. $new: division, class, session, face, wheelchair (bool),
 * events (BK_EV_*), letter, with, request. Returns ['ok' => true, 'from' => old departure,
 * 'to' => new departure, 'validated' => 0/1] or ['ok' => false, 'msg' => …]. The caller places
 * the two departures again and serves the waiting list (bk_reg_modify_after).
 */
function bk_reg_modify($enId, $archer, $new)
{
    list($r, $why) = bk_reg_modifiable($enId, $archer);
    if (!$r) return array('ok' => false, 'msg' => $why);
    $enId = intval($enId);
    $tourId = intval($r->BrTournament);
    $cfg = bk_comp_config($tourId);
    $lue = bk_lookup_licence($r->BrLicence);
    if (!$lue) return array('ok' => false, 'msg' => bk_t('RgModNoLicence'));

    $division = (string) ($new['division'] ?? '');
    $class    = (string) ($new['class'] ?? '');
    $session  = intval($new['session'] ?? 0);
    $wheel    = !empty($new['wheelchair']) ? 1 : 0;

    if (!bk_reg_lock($tourId)) return array('ok' => false, 'msg' => bk_t('RegBusy'));
    try {
        $err = bk_reg_blocked($tourId, $cfg, $r->BrLicence, $lue->LueCountry, $division, $class, $session, $lue, false, $enId);
        if ($err !== '') return array('ok' => false, 'msg' => $err);

        return bk_with_tournament($tourId, function () use ($tourId, $enId, $r, $cfg, $lue, $division, $class, $session, $wheel, $new) {
            if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return array('ok' => false, 'msg' => bk_t('RgLocked'));

            $rs = safe_r_sql("SELECT (DivAthlete AND ClAthlete) AS Athlete
                FROM Divisions INNER JOIN Classes ON DivTournament = ClTournament
                WHERE DivTournament = $tourId AND DivId = " . StrSafe_DB($division) . " AND ClId = " . StrSafe_DB($class));
            $a = safe_fetch($rs);
            if (!$a || !$a->Athlete) return array('ok' => false, 'msg' => bk_t('RgBadCategory'));

            // Face: as a new registration (bk_register_write) — the one chosen when the settings
            // offer it for the category, otherwise the first offered.
            $face = 0;
            $all = getTargets(true);
            if (!empty($all[$division][$class])) {
                $ids = array_map('intval', array_keys($all[$division][$class]));
                $want = intval($new['face'] ?? 0);
                $face = ($want && in_array($want, $ids, true)) ? $want : $ids[0];
            }

            // Profile or departure changed: the field must still take it (admission check), its
            // own place counting as free.
            $oldSes = intval($r->QuSession);
            $moved = $session !== $oldSes || $division !== (string) $r->EnDivision || $class !== (string) $r->EnClass
                || $face !== intval($r->EnTargetFace) || $wheel !== (intval($r->EnWChair) > 0 ? 1 : 0);
            if ($moved && function_exists('bk_profile_remaining')) {
                $rem = bk_profile_remaining($tourId, $session, $division, $class, $face, $wheel, $enId);
                if ($rem !== null && $rem < 1) return array('ok' => false, 'msg' => bk_t('RgProfileFull'));
            }

            $before = Params4Recalc($enId);
            $oldRow = (object) array('EnCode' => $r->EnCode, 'EnDivision' => $r->EnDivision);
            foreach (bk_event_cols() as $c) $oldRow->$c = $r->$c;

            safe_w_sql("UPDATE Entries SET
                EnDivision = " . StrSafe_DB($division) . ",
                EnClass = " . StrSafe_DB($class) . ",
                EnAgeClass = " . StrSafe_DB($class) . ",
                EnAthlete = 1,
                EnTargetFace = $face,
                EnWChair = $wheel,
                EnTimestamp = '" . date('Y-m-d H:i:s') . "'
                WHERE EnId = $enId AND EnTournament = $tourId");
            if ($moved) {
                // Off its target: the placement puts it back (on its new departure).
                safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
                    SET QuSession = $session, QuTarget = 0, QuLetter = '', QuTargetNo = ''
                    WHERE QuId = $enId");
            }

            // Events: a bow left behind hands its events to the archer's next shoot with it; the
            // choices made here go to the first shoot of the new bow.
            $changed = array();
            if ($division !== (string) $r->EnDivision) $changed = bk_events_after_removal($tourId, $oldRow);
            $offer = bk_ev_offer($tourId, $division, $class, $lue->LueCountry, $cfg);
            $evOpts = bk_ev_opts_clean($offer, intval($new['events'] ?? 0));
            $changed = bk_events_merge($changed, bk_events_normalise($tourId, $r->EnCode, $division, bk_ev_flags($offer, $evOpts)));

            // Core recalculations: for the category left, for the new one, for the switches changed.
            $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
            $after = Params4Recalc($enId);
            foreach (array($before, $after) as $p) {
                if ($p === false) continue;
                list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
                RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
                if ($t) for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            }
            if ($changed) bk_events_recalc($tourId, $changed);
            MakeIndAbs();
            checkAgainstLUE($enId);

            // Wishes: only those the organiser offers (as bk_register_write).
            // bytes: target letters and numbers are ASCII
            $wLetter = empty($cfg->BcWishLetter) ? '' : strtoupper(substr(trim((string) ($new['letter'] ?? '')), 0, 2));
            $wWith   = empty($cfg->BcWishWith) ? '' : bk_clean_licence($new['with'] ?? '');
            $wReq    = empty($cfg->BcWishFree) ? '' : mb_substr(trim((string) ($new['request'] ?? '')), 0, 2000);
            if ($wWith === bk_clean_licence($r->BrLicence)) $wWith = '';
            safe_w_sql("UPDATE BookingRegistrations SET
                BrRequest = " . StrSafe_DB($wReq) . ",
                BrWantLetter = " . StrSafe_DB($wLetter) . ",
                BrWantWith = " . StrSafe_DB($wWith) . ",
                BrDivision = " . StrSafe_DB($division) . ",
                BrClass = " . StrSafe_DB($class) . ",
                BrSession = $session,
                BrFace = $face
                WHERE BrEnId = $enId");
            bk_events_store_opts($tourId, $r->EnCode, $division, $evOpts);
            bk_wheelchair_set($r->BrLicence, $wheel);

            return array('ok' => true, 'from' => $oldSes, 'to' => $session, 'validated' => intval($r->BrValidated));
        });
    } finally {
        bk_reg_unlock($tourId);
    }
}

/** After a change: the departures concerned placed again, the place freed offered to the waiting list. */
function bk_reg_modify_after($tourId, $res)
{
    require_once __DIR__ . '/waitlist.php';
    $tourId = intval($tourId);
    $cfg = bk_comp_config($tourId);
    if (!empty($res['validated'])) {
        foreach (array_unique(array_filter(array(intval($res['from']), intval($res['to'])))) as $o) bk_replan_session($tourId, $o, $cfg);
    }
    if (intval($res['from']) !== intval($res['to'])) bk_waitlist_process($tourId);
}
