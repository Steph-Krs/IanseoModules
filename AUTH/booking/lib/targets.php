<?php
/**
 * lib/targets.php — target assignment and check of the federation rules.
 *
 * The possible slots are NOT computed here: they come from createAvailableTargetSQL()
 * (Common/Globals.inc.php), the virtual view the ianseo core builds from Session
 * (SesFirstTarget/SesTar4Session/SesAth4Target). The AvailableTarget table exists but is dead
 * (its INSERT is commented out in Fun_ManSessions.inc.php): never rely on it.
 *
 * ⚠️ Qualifications has no competition column: every read and every write goes through a join
 * on Entries (EnTournament).
 */

if (defined('BK_TARGETS_LOADED')) return;
define('BK_TARGETS_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/caps.php';
require_once __DIR__ . '/cohabitation.php';   // faces sharing a target (M7)

/** ianseo format of QuTargetNo: departure + target on 3 digits + letter → 1004A. */
function bk_target_no($session, $target, $letter)
{
    return intval($session) . str_pad(intval($target), 3, '0', STR_PAD_LEFT) . strtoupper($letter);
}

/** Free slots of a departure, ordered by target then letter. */
function bk_free_slots($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $sql = bk_with_tournament($tourId, function () use ($sessionOrder, $tourId) {
        return createAvailableTargetSQL(intval($sessionOrder), $tourId);
    });

    $rs = safe_r_sql("SELECT f.FullTgtTarget AS t, f.FullTgtLetter AS l
        FROM ($sql) f
        LEFT JOIN (
            SELECT q.QuTarget, q.QuLetter, q.QuSession
              FROM Qualifications q
              INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        ) o ON o.QuSession = f.FullTgtSession
           AND o.QuTarget  = f.FullTgtTarget
           AND o.QuLetter  = f.FullTgtLetter
        WHERE o.QuTarget IS NULL
        ORDER BY f.FullTgtTarget, f.FullTgtLetter");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = array('t' => intval($r->t), 'l' => $r->l);
    return $out;
}

/** Archers of a departure, with their club and their place if already assigned. */
function bk_session_archers($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
                e.EnCountry, e.EnTargetFace, c.CoCode, c.CoName,
                q.QuTarget, q.QuLetter, r.BrRequest, r.BrEnId AS BrRow, r.BrValidated
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        LEFT  JOIN Countries c ON c.CoId = e.EnCountry
        LEFT  JOIN BK_Registrations r ON r.BrEnId = e.EnId
        WHERE e.EnTournament = $tourId AND e.EnAthlete = 1
          AND q.QuSession = " . intval($sessionOrder) . "
        ORDER BY c.CoCode, e.EnFirstName, e.EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Same, with the structured wishes and the origin of the registration. */
function bk_session_archers_full($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
                e.EnCountry, e.EnTargetFace, c.CoCode, c.CoName,
                q.QuTarget, q.QuLetter,
                r.BrEnId, r.BrRequest, r.BrWantLetter, r.BrWantWith, r.BrValidated
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        LEFT  JOIN Countries c ON c.CoId = e.EnCountry
        LEFT  JOIN BK_Registrations r ON r.BrEnId = e.EnId
        WHERE e.EnTournament = $tourId AND e.EnAthlete = 1
          AND q.QuSession = " . intval($sessionOrder) . "
        ORDER BY c.CoCode, e.EnFirstName, e.EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Assigns a target to the archers of a departure who have none yet.
 *
 * NEVER MOVES an archer already placed: the organiser may have adjusted by hand (or another tool
 * placed them), and a late registration must not reshuffle everyone.
 *
 * Constraints, by priority:
 *  1. **Field capabilities** (BK_TargetCaps) — a target that does not accept the archer's
 *     distance or face is ruled out. HARD constraint: better leave an archer unplaced than on an
 *     impossible target.
 *  2. **One distance per target** — two archers of one target shoot together: a physical
 *     constraint, not a rule on faces sharing a target.
 *  3. At most `BcMaxPerClubPerTarget` archers of one club per target. Soft: exceeded as a last
 *     resort, and reported by the check.
 *
 * Archers are served club by club in turn, to spread the clubs.
 *
 * Returns ['places'=>N, 'restants'=>M, 'compromis'=>K, 'incompatibles'=>I] — `incompatibles`
 * counts the archers no target of the departure can take.
 */
function bk_assign_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    $max    = max(1, intval($cfg->BcMaxPerClubPerTarget));

    $archers = bk_session_archers_full($tourId, $sessionOrder);
    $slots   = bk_free_slots($tourId, $sessionOrder);

    $rs = safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule FROM Tournament WHERE ToId = $tourId");
    $tr = safe_fetch($rs);
    $type = $tr ? $tr->ToType : '';
    $caps = bk_caps_get($tourId, $sessionOrder);

    // Faces sharing a target (M7): discipline + rhythm (archers per target) of the departure.
    // Outdoors/18 m, a target can only carry a set of faces whose "costs" fit in the physical
    // budget (see lib/cohabitation.php).
    $dd   = bk_comp_discipline($type, $tr ? $tr->ToTypeSubRule : '', $tr ? $tr->ToTypeName : '');
    $disc = $dd['key'];
    $sr   = safe_fetch(safe_r_sql("SELECT SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = " . intval($sessionOrder) . " AND SesType = 'Q'"));
    $rhythm = $sr ? max(1, intval($sr->SesAth4Target)) : 1;

    // Needs of each archer (distances + face), cached by category.
    $needCache = array();
    $needs = function ($a) use (&$needCache, $tourId, $type) {
        $k = $a->EnDivision . '|' . $a->EnClass . '|' . intval($a->EnTargetFace);
        if (!isset($needCache[$k])) {
            $needCache[$k] = bk_caps_needs($tourId, $type, $a->EnDivision, $a->EnClass, $a->EnTargetFace);
        }
        return $needCache[$k];
    };

    // Occupation of the targets already in place: quota per club AND distance already imposed
    // on the target by its occupants.
    $parCible = array();
    $distCible = array();
    $facesCible = array();     // target → list of the face classes already placed (sharing)
    $aPlacer  = array();
    foreach ($archers as $a) {
        $club = (string) ($a->CoCode ?: '?');
        if (intval($a->QuTarget) > 0) {
            $t = intval($a->QuTarget);
            $parCible[$t][$club] = ($parCible[$t][$club] ?? 0) + 1;
            $distCible[$t] = bk_caps_dist_key($needs($a));
            $facesCible[$t][] = bk_face_class_by_id($tourId, $a->EnTargetFace);
        } else {
            // Online registration not validated yet (manual mode) → not placed.
            if (!empty($a->BrEnId) && intval($a->BrValidated) === 0) continue;
            $aPlacer[$club][] = $a;
        }
    }
    if (!$aPlacer) return array('places' => 0, 'restants' => 0, 'compromis' => 0,
                               'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);

    // Queue: one archer of each club in turn, the biggest clubs first — this is what spreads
    // the clubs over all the targets.
    uasort($aPlacer, function ($x, $y) { return count($y) - count($x); });
    $file = array();
    while ($aPlacer) {
        foreach ($aPlacer as $club => $liste) {
            $file[] = array_shift($aPlacer[$club]);
            if (!$aPlacer[$club]) unset($aPlacer[$club]);
        }
    }

    /* ---- "With someone" wishes: grouped before placing --------------------
       Such a wish cannot be met by placing archers one by one in order of arrival. Clusters are
       therefore built (simplified union-find), and each cluster moves to the front of the
       queue: its members are served one after the other, so on the same target while there is
       room. Clusters bigger than a target overflow — unavoidable. */
    $indexLic = array();
    foreach ($file as $i => $a) $indexLic[bk_clean_licence($a->EnCode)] = $i;

    $groupe = array();                       // EnId → cluster id
    $racine = function ($x) use (&$groupe, &$racine) {
        while (isset($groupe[$x]) && $groupe[$x] !== $x) $x = $groupe[$x];
        return $x;
    };
    foreach ($file as $a) {
        $me = intval($a->EnId);
        if (!isset($groupe[$me])) $groupe[$me] = $me;
        $want = bk_clean_licence($a->BrWantWith ?? '');
        if ($want === '' || !isset($indexLic[$want])) continue;
        $lui = intval($file[$indexLic[$want]]->EnId);
        if (!isset($groupe[$lui])) $groupe[$lui] = $lui;
        $ra = $racine($me); $rb = $racine($lui);
        if ($ra !== $rb) $groupe[$ra] = $rb;
    }
    $grappes = array();
    foreach ($file as $i => $a) $grappes[$racine(intval($a->EnId))][] = $i;

    // Within a cluster, the ANCHOR first: the one the others asked for. When the cluster does
    // not fit on one target (club quota), they are the one who must stay — otherwise the
    // askers end up together without the person they asked for, which pleases nobody.
    $cite = array();
    foreach ($file as $a) {
        $w = bk_clean_licence($a->BrWantWith ?? '');
        if ($w !== '') $cite[$w] = ($cite[$w] ?? 0) + 1;
    }
    foreach ($grappes as &$g) {
        usort($g, function ($x, $y) use ($file, $cite) {
            $cx = $cite[bk_clean_licence($file[$x]->EnCode)] ?? 0;
            $cy = $cite[bk_clean_licence($file[$y]->EnCode)] ?? 0;
            return $cy - $cx;
        });
    }
    unset($g);

    // Real clusters (≥ 2) first, the biggest at the front; then the single ones in the club
    // shuffling order already computed.
    uasort($grappes, function ($x, $y) { return count($y) - count($x); });
    $ordre = array();
    $enGrappe = array();          // index (in the NEW queue) → member of a cluster
    foreach ($grappes as $g) { if (count($g) > 1) foreach ($g as $i) { $ordre[] = $i; } }
    foreach ($grappes as $g) { if (count($g) === 1) $ordre[] = $g[0]; }
    $nouvelle = array();
    foreach ($ordre as $rang => $i) {
        $nouvelle[] = $file[$i];
        $enGrappe[intval($file[$i]->EnId)] = (count($grappes[$racine(intval($file[$i]->EnId))]) > 1);
    }
    $file = $nouvelle;

    // Count of the wishes, to report to the organiser.
    $voeux = 0;
    foreach ($file as $a) {
        if (trim((string) ($a->BrWantLetter ?? '')) !== '' || trim((string) ($a->BrWantWith ?? '')) !== '') $voeux++;
    }
    $voeuxOk = 0;

    $places = 0; $compromis = 0; $pose = array();
    foreach ($slots as $slot) {
        if (!$file) break;
        $t = $slot['t'];

        // Candidates THIS target can physically take: field capabilities, then the distance
        // already imposed by the target's occupants.
        $eligibles = array();
        foreach ($file as $i => $a) {
            $n = $needs($a);
            if (!bk_caps_target_ok($caps, $t, $n)) continue;
            if (isset($distCible[$t]) && $distCible[$t] !== bk_caps_dist_key($n)) continue;
            // Sharing: the archer's face must fit in the target's remaining budget given the
            // faces already placed (outdoors/18 m; no effect otherwise).
            if (bk_cohabit_max_add($facesCible[$t] ?? array(),
                    bk_face_class_by_id($tourId, $a->EnTargetFace), $disc, $rhythm) < 1) continue;
            $eligibles[] = $i;
        }
        if (!$eligibles) continue;   // no impossible target: the slot stays empty

        // Among them, the first whose club still fits on this target. On a tie, the one who
        // asked for THIS letter goes first: a wish never beats a rule, only the arrival order.
        $possibles = array();
        foreach ($eligibles as $i) {
            $club = (string) ($file[$i]->CoCode ?: '?');
            if (($parCible[$t][$club] ?? 0) < $max) $possibles[] = $i;
        }
        $idx = null;
        if ($possibles) {
            // Letter preference: only among the archers WITHOUT a cluster. Letting a letter
            // wish overtake a cluster member would break the adjacency that meets a "with
            // someone" wish — two broken requests instead of one.
            foreach ($possibles as $i) {
                if (!empty($enGrappe[intval($file[$i]->EnId)])) continue;
                if (strtoupper(trim((string) ($file[$i]->BrWantLetter ?? ''))) === strtoupper($slot['l'])) {
                    $idx = $i; break;
                }
            }
            if ($idx === null) $idx = $possibles[0];
        } else {
            $idx = $eligibles[0]; $compromis++;   // quota exceeded, reported
        }

        $a    = $file[$idx];
        $club = (string) ($a->CoCode ?: '?');
        $distCible[$t] = bk_caps_dist_key($needs($a));
        unset($file[$idx]);
        $file = array_values($file);

        $no = bk_target_no($sessionOrder, $slot['t'], $slot['l']);
        // Redundant safeguard: the UPDATE aims at one EnId AND checks the competition again —
        // Qualifications alone would spill over the whole database.
        safe_w_sql("UPDATE Qualifications q
            INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
            SET q.QuTarget = " . intval($slot['t']) . ",
                q.QuLetter = " . StrSafe_DB($slot['l']) . ",
                q.QuTargetNo = " . StrSafe_DB($no) . ",
                q.QuTimestamp = q.QuTimestamp
            WHERE q.QuId = " . intval($a->EnId));

        $parCible[$t][$club] = ($parCible[$t][$club] ?? 0) + 1;
        $facesCible[$t][] = bk_face_class_by_id($tourId, $a->EnTargetFace);
        $pose[bk_clean_licence($a->EnCode)] = array('t' => $t, 'l' => strtoupper($slot['l']), 'a' => $a);
        $places++;
    }

    // Actual satisfaction, measured AFTERWARDS rather than guessed during placement: a "with
    // someone" wish can only be judged once both are placed.
    foreach ($archers as $a) {
        if (intval($a->QuTarget) > 0) {
            $pose[bk_clean_licence($a->EnCode)] = array(
                't' => intval($a->QuTarget), 'l' => strtoupper($a->QuLetter), 'a' => $a);
        }
    }
    foreach ($pose as $lic => $p) {
        $wl = strtoupper(trim((string) ($p['a']->BrWantLetter ?? '')));
        $ww = bk_clean_licence($p['a']->BrWantWith ?? '');
        if ($wl === '' && $ww === '') continue;
        $ok = true;
        if ($wl !== '' && $p['l'] !== $wl) $ok = false;
        if ($ww !== '' && (!isset($pose[$ww]) || $pose[$ww]['t'] !== $p['t'])) $ok = false;
        if ($ok) $voeuxOk++;
    }

    // Those NO target of the departure can take: told apart from a mere lack of room, since
    // the cause is the field's setting.
    $incompatibles = 0;
    foreach ($file as $a) {
        $n = $needs($a);
        $ok = false;
        foreach ($slots as $s) {
            if (bk_caps_target_ok($caps, $s['t'], $n)) { $ok = true; break; }
        }
        if (!$ok) $incompatibles++;
    }

    return array('places' => $places, 'restants' => count($file),
                 'compromis' => $compromis, 'incompatibles' => $incompatibles,
                 'voeux' => $voeux, 'voeuxOk' => $voeuxOk);
}

/**
 * Places STILL available on a departure for a given PROFILE (bow, category, face) — the
 * "specific gauge" of the registration, and the admission check: 0 ⇒ no target can take this
 * archer any more, the registration must be refused.
 *
 * Reproduces exactly the eligibility of bk_assign_session (field capabilities, one distance per
 * target, faces sharing a target) and adds up, over the targets with free letters, what each
 * can still take of this profile. Returns null when the constraint is unknown (no departure).
 * Based on the current placements: exact with AUTOMATIC validation (each registration is placed
 * at once); with MANUAL validation, the pending registrations are not placed yet.
 */
function bk_profile_remaining($tourId, $sessionOrder, $division, $class, $faceId)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);

    $tr = safe_fetch(safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule FROM Tournament WHERE ToId = $tourId"));
    if (!$tr) return null;
    $type = $tr->ToType;
    $dd   = bk_comp_discipline($type, $tr->ToTypeSubRule, $tr->ToTypeName);
    $disc = $dd['key'];

    $ses = safe_fetch(safe_r_sql("SELECT SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = $sessionOrder AND SesType = 'Q'"));
    if (!$ses) return null;
    $rhythm = max(1, intval($ses->SesAth4Target));

    $caps      = bk_caps_get($tourId, $sessionOrder);
    $needCache = array();
    $needOf = function ($div, $cls, $fid) use (&$needCache, $tourId, $type) {
        $k = $div . '|' . $cls . '|' . intval($fid);
        if (!isset($needCache[$k])) $needCache[$k] = bk_caps_needs($tourId, $type, $div, $cls, $fid);
        return $needCache[$k];
    };
    $needs     = $needOf($division, $class, $faceId);
    $distKey   = bk_caps_dist_key($needs);
    $faceClass = bk_face_class_by_id($tourId, intval($faceId));

    $slots = bk_free_slots($tourId, $sessionOrder);
    $targets = array();
    foreach ($slots as $s) $targets[intval($s['t'])] = true;
    if (!$targets) return 0;

    // Current occupation: faces and distance imposed by the archers already placed.
    $facesCible = array(); $distCible = array();
    foreach (bk_session_archers_full($tourId, $sessionOrder) as $a) {
        if (intval($a->QuTarget) <= 0) continue;
        $t = intval($a->QuTarget);
        $facesCible[$t][] = bk_face_class_by_id($tourId, $a->EnTargetFace);
        $distCible[$t] = bk_caps_dist_key($needOf($a->EnDivision, $a->EnClass, $a->EnTargetFace));
    }

    $remaining = 0;
    foreach (array_keys($targets) as $t) {
        if (!bk_caps_target_ok($caps, $t, $needs)) continue;
        if (isset($distCible[$t]) && $distCible[$t] !== $distKey) continue;
        // ⚠️ bk_cohabit_max_add() answers "how many more NOW", not "how many in all". On an
        // empty outdoor target, a full face (122) costs the whole budget → it returned 1,
        // although this face is THEN SHARED by up to SesAth4Target archers. The gauge showed
        // the number of free TARGETS and not the places (confusion reported at registration).
        // Archers are therefore placed one by one, as the placement really does.
        $cur = $facesCible[$t] ?? array();
        for ($n = 0; $n < $rhythm; $n++) {
            if (bk_cohabit_max_add($cur, $faceClass, $disc, $rhythm) < 1) break;
            $cur[] = $faceClass;
            $remaining++;
        }
    }
    return $remaining;
}

/**
 * Plans a departure ENTIRELY again to best meet the archers' wishes, then places everyone.
 *
 * Called after each registration: a "with someone" wish cannot be met by placing people one by
 * one in order of arrival — the cards must be reshuffled.
 *
 * ⚠️ Frees ONLY the registrations made by this module (in BK_Registrations). A participant
 * entered by the organiser, or placed by another tool, keeps their target: it becomes a fixed
 * obstacle. Without this guard, an online registration would move the organiser's manual work.
 */
function bk_replan_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        INNER JOIN BK_Registrations r ON r.BrEnId = e.EnId
        SET q.QuTarget = 0, q.QuLetter = '', q.QuTargetNo = '', q.QuTimestamp = q.QuTimestamp
        WHERE q.QuSession = " . intval($sessionOrder));
    return bk_assign_session($tourId, $sessionOrder, $cfg);
}

/**
 * Plans again every departure where the module has registrations. Used after a registration or
 * a cancellation, when the organiser leaves the automatic placement on.
 */
function bk_replan_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT DISTINCT q.QuSession FROM Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        INNER JOIN BK_Registrations r ON r.BrEnId = e.EnId
        WHERE q.QuSession > 0");
    $tot = array('places' => 0, 'restants' => 0, 'compromis' => 0, 'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);
    while ($r = safe_fetch($rs)) {
        $x = bk_replan_session($tourId, intval($r->QuSession), $cfg);
        foreach ($tot as $k => $v) $tot[$k] = $v + ($x[$k] ?? 0);
    }
    return $tot;
}

/* ---- Manual validation of the registrations ---------------------------- */

/** Online registrations waiting for validation (BrValidated=0). */
function bk_pending_registrations($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT r.BrEnId, r.BrCreated, e.EnFirstName, e.EnName, e.EnCode,
                e.EnDivision, e.EnClass, d.DivDescription, cl.ClDescription,
                c.CoName, c.CoCode, q.QuSession
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        /* 1:1 avec Entries → INNER JOIN, jamais LEFT + IS NULL. */
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        LEFT  JOIN Divisions d      ON d.DivTournament = e.EnTournament AND d.DivId = e.EnDivision
        LEFT  JOIN Classes cl       ON cl.ClTournament = e.EnTournament AND cl.ClId = e.EnClass
        LEFT  JOIN Countries c      ON c.CoId = e.EnCountry
        WHERE r.BrTournament = $tourId AND r.BrValidated = 0
        ORDER BY r.BrCreated, r.BrId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Number of registrations waiting for validation. */
function bk_pending_count($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) n FROM BK_Registrations
        WHERE BrTournament = " . $tourId . " AND BrValidated = 0"));
    return $r ? intval($r->n) : 0;
}

/** Validates a registration, then places its departure. Returns bool. */
function bk_validate_registration($tourId, $enId, $cfg)
{
    $tourId = intval($tourId); $enId = intval($enId);
    $r = safe_fetch(safe_r_sql("SELECT q.QuSession FROM BK_Registrations br
        INNER JOIN Qualifications q ON q.QuId = br.BrEnId
        WHERE br.BrEnId = $enId AND br.BrTournament = $tourId AND br.BrValidated = 0"));
    if (!$r) return false;
    safe_w_sql("UPDATE BK_Registrations SET BrValidated = 1 WHERE BrEnId = $enId AND BrTournament = $tourId");
    bk_replan_session($tourId, intval($r->QuSession), $cfg);
    return true;
}

/** Validates every pending registration + places the departures. Returns the number validated. */
function bk_validate_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $sessions = array(); $n = 0;
    $rs = safe_r_sql("SELECT q.QuSession FROM BK_Registrations r
        INNER JOIN Qualifications q ON q.QuId = r.BrEnId
        WHERE r.BrTournament = $tourId AND r.BrValidated = 0");
    while ($r = safe_fetch($rs)) { $sessions[intval($r->QuSession)] = true; $n++; }
    if (!$n) return 0;
    safe_w_sql("UPDATE BK_Registrations SET BrValidated = 1 WHERE BrTournament = $tourId AND BrValidated = 0");
    foreach (array_keys($sessions) as $so) bk_replan_session($tourId, $so, $cfg);
    return $n;
}

/** Frees the targets of a departure (archers back to waiting for a placement). */
function bk_clear_session($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        SET q.QuTarget = 0, q.QuLetter = '', q.QuTargetNo = '', q.QuTimestamp = q.QuTimestamp
        WHERE q.QuSession = " . intval($sessionOrder));
    return intval(safe_w_affected_rows());
}

/**
 * Check of the rules, departure by departure. To run when registrations close (that is when the
 * check makes sense: before, the field still moves).
 *
 * Returns an array per departure: club/target quota breaches, number of clubs present, archers
 * not placed.
 */
function bk_rules_check($tourId, $cfg)
{
    $tourId   = intval($tourId);
    $max      = max(1, intval($cfg->BcMaxPerClubPerTarget));
    $minClubs = max(1, intval($cfg->BcMinClubsPerSession));

    $out = array();
    foreach (bk_comp_sessions($tourId) as $s) {
        $order   = intval($s->SesOrder);
        $archers = bk_session_archers($tourId, $order);
        if (!$archers) continue;

        $parCible = array(); $clubs = array(); $nonPlaces = 0; $doublons = array();
        foreach ($archers as $a) {
            $club = (string) ($a->CoCode ?: '?');
            $clubs[$club] = true;
            if (intval($a->QuTarget) > 0) {
                $parCible[intval($a->QuTarget)][$club][] = $a;
            } else {
                $nonPlaces++;
            }
        }

        $exces = array();
        foreach ($parCible as $cible => $parClub) {
            foreach ($parClub as $club => $liste) {
                if (count($liste) > $max) {
                    $exces[] = array('cible' => $cible, 'club' => $club, 'n' => count($liste));
                }
            }
        }

        // The same archer twice on one departure (should never happen through booking, but the
        // organiser also enters by hand).
        $vus = array();
        foreach ($archers as $a) {
            $k = bk_clean_licence($a->EnCode);
            if ($k === '') continue;
            if (isset($vus[$k])) $doublons[$k] = ($doublons[$k] ?? 1) + 1;
            $vus[$k] = true;
        }

        $out[] = array(
            'depart'     => $order,
            'nom'        => $s->SesName,
            'archers'    => count($archers),
            'clubs'      => count($clubs),
            'minClubs'   => $minClubs,
            'clubsOk'    => count($clubs) >= $minClubs,
            'max'        => $max,
            'exces'      => $exces,
            'nonPlaces'  => $nonPlaces,
            'doublons'   => $doublons,
            'ok'         => !$exces && count($clubs) >= $minClubs && $nonPlaces === 0 && !$doublons,
        );
    }
    return $out;
}

/**
 * Plan of a departure for display: target → letter → archer.
 * Used by the organiser and, when BcShowAssignment, by the archers.
 */
function bk_session_plan($tourId, $sessionOrder)
{
    $plan = array();
    foreach (bk_session_archers($tourId, $sessionOrder) as $a) {
        if (intval($a->QuTarget) > 0) {
            $plan[intval($a->QuTarget)][strtoupper($a->QuLetter)] = $a;
        }
    }
    ksort($plan);
    foreach ($plan as &$c) ksort($c);
    return $plan;
}
