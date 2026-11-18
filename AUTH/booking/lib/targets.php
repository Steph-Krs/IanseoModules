<?php
/**
 * lib/targets.php — target assignment and check of the federation rules.
 *
 * The planner itself is pure (lib/placement.php, rules in REGLES_PLACEMENT.md); this file reads
 * a departure from ianseo for it and writes its result. The targets of a departure come from its
 * Session row (SesFirstTarget, SesTar4Session, SesAth4Target), never from
 * createAvailableTargetSQL(): a filter on that derived table turns quadratic under MySQL 8
 * (derived_condition_pushdown). The AvailableTarget table is dead (its INSERT is commented out
 * in Fun_ManSessions.inc.php): never rely on it.
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
require_once __DIR__ . '/placement.php';

/** ianseo format of QuTargetNo: departure + target on 3 digits + letter → 1004A. */
function bk_target_no($session, $target, $letter)
{
    // bytes: target letters and numbers are ASCII
    return intval($session) . str_pad(intval($target), 3, '0', STR_PAD_LEFT) . strtoupper($letter);
}

/** Archers of a departure, with their club and their place if already assigned. */
function bk_session_archers($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass,
                EnCountry, EnTargetFace, EnWChair, CoCode, CoName,
                QuTarget, QuLetter, BrRequest, BrEnId AS BrRow, BrValidated
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Countries ON CoId = EnCountry
        LEFT  JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1
          AND QuSession = " . intval($sessionOrder) . "
        ORDER BY CoCode, EnFirstName, EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Same, with the structured wishes and the origin of the registration. */
function bk_session_archers_full($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass,
                EnCountry, EnTargetFace, EnWChair, CoCode, CoName,
                QuTarget, QuLetter,
                BrEnId, BrRequest, BrWantLetter, BrWantWith, BrValidated, UNIX_TIMESTAMP(BrCreated) AS BrSince
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Countries ON CoId = EnCountry
        LEFT  JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1
          AND QuSession = " . intval($sessionOrder) . "
        ORDER BY CoCode, EnFirstName, EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * What the planner (lib/placement.php) needs of a departure, read from ianseo: discipline,
 * rhythm, targets, field settings, order of the placement, archers already placed (they never
 * move) and archers to place, with their distances series by series, their face (family at each
 * distance, size, TfId), their category (bow, age, sex), their club and their wishes.
 *
 * $replan: the departure as the automatic re-planning sees it (bk_replan_session) — the online
 * registrations are archers to place even when they hold a target, only the others stay fixed.
 * $replan === 'all': every archer is to place (final composition, lib/compose.php), except a
 * registration still waiting for validation that holds a target.
 *
 * Returns null when the departure does not exist, else
 * ['in' => planner input, 'rows' => [EnId => row], 'type' => ToType, 'disc' => discipline,
 *  'nDist' => int, 'classes' => [ClId => ['age', 'sex']]].
 */
function bk_plan_read($tourId, $sessionOrder, $cfg = null, $replan = false)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $tr = safe_fetch(safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule, ToNumDist FROM Tournament WHERE ToId = $tourId"));
    $ses = safe_fetch(safe_r_sql("SELECT SesFirstTarget, SesTar4Session, SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = $sessionOrder AND SesType = 'Q'"));
    if (!$tr || !$ses) return null;
    if ($cfg === null) $cfg = bk_comp_config($tourId);
    $type = $tr->ToType;
    $disc = bk_comp_discipline($type, $tr->ToTypeSubRule, $tr->ToTypeName)['key'];
    $nDist = max(1, intval($tr->ToNumDist));
    $order = bk_place_order($tourId, $cfg);

    $caps = array();
    foreach (bk_caps_get($tourId, $sessionOrder) as $t => $c) $caps[$t] = array('min' => $c['min'], 'max' => $c['max'], 'faces' => $c['f']);

    // Age and sex of each class: a class without an age range (discovery: 1 to 127) has no age.
    $classes = array();
    $rs = safe_r_sql("SELECT ClId, ClSex, ClAgeFrom, ClAgeTo FROM Classes WHERE ClTournament = $tourId");
    while ($r = safe_fetch($rs)) {
        $classes[(string) $r->ClId] = array(
            'age' => (intval($r->ClAgeFrom) <= 1 && intval($r->ClAgeTo) >= 99) ? null : intval($r->ClAgeFrom),
            'sex' => intval($r->ClSex));
    }

    $targets = array();
    for ($t = intval($ses->SesFirstTarget); $t < intval($ses->SesFirstTarget) + intval($ses->SesTar4Session); $t++) $targets[] = $t;

    $in = array('disc' => $disc, 'rhythm' => max(1, intval($ses->SesAth4Target)), 'targets' => $targets,
                'reverse' => in_array($sessionOrder, $order['reverse'], true),
                'maxPerClub' => max(1, intval($cfg->BcMaxPerClubPerTarget ?? 2)),
                'minTargets' => max(0, intval($cfg->BcMinTargetsPerSession ?? 6)),
                'caps' => $caps, 'order' => $order['crit'], 'bows' => array_flip(array_keys($order['bows'])),
                'fixed' => array(), 'archers' => array());
    $rows = array();
    $byLicence = array();
    $archers = bk_session_archers_full($tourId, $sessionOrder);
    foreach ($archers as $a) $byLicence[bk_clean_licence($a->EnCode)] = intval($a->EnId);
    foreach ($archers as $a) {
        $id = intval($a->EnId);
        $rows[$id] = $a;
        $dist = bk_plan_series($tourId, $type, $a->EnDivision, $a->EnClass, $nDist);
        $face = bk_caps_face_profile($tourId, $disc, $a->EnTargetFace, max(1, count($dist)));
        $cl = $classes[(string) $a->EnClass] ?? array('age' => null, 'sex' => null);
        $one = array('id' => $id, 'dist' => $dist, 'fam' => $face['fams'], 'fsize' => $face['fsize'],
                     'face' => intval($a->EnTargetFace), 'club' => (string) ($a->CoCode ?: '?'),
                     // Wheelchair or bow stand (ianseo's own field): two places on the target (B.1.2).
                     'wheelchair' => intval($a->EnWChair ?? 0) > 0,
                     'div' => (string) $a->EnDivision, 'cls' => (string) $a->EnClass, 'age' => $cl['age'], 'sex' => $cl['sex']);
        $pending = !empty($a->BrEnId) && intval($a->BrValidated) === 0;
        $held = intval($a->QuTarget) > 0
            && ($replan === 'all' ? $pending : !($replan && !empty($a->BrEnId)));
        if ($held) {
            // bytes: target letters are ASCII
            $in['fixed'][] = $one + array('t' => intval($a->QuTarget), 'l' => strtoupper((string) $a->QuLetter));
            continue;
        }
        // Online registration not validated yet (manual mode) → not placed.
        if ($pending) continue;
        $want = bk_clean_licence($a->BrWantWith ?? '');
        $one['with'] = ($want !== '' && isset($byLicence[$want]) && $byLicence[$want] !== $id) ? $byLicence[$want] : null;
        // bytes: target letters are ASCII
        $one['letter'] = strtoupper(trim((string) ($a->BrWantLetter ?? '')));
        // Seniority of the wishes: the oldest registration is served first.
        $one['since'] = intval($a->BrSince ?? 0) ?: PHP_INT_MAX;
        $in['archers'][] = $one;
    }
    return array('in' => $in, 'rows' => $rows, 'type' => $type, 'disc' => $disc, 'nDist' => $nDist, 'classes' => $classes);
}

/**
 * Distances of a category, series by series, in metres (TournamentDistances: Td<n> / TdDist<n>,
 * the most specific TdClasses). A series without a distance ("-") is left out.
 */
function bk_plan_series($tourId, $type, $division, $class, $nDist)
{
    static $cache = array();
    $k = intval($tourId) . '|' . $type . '|' . $division . '|' . $class;
    if (isset($cache[$k])) return $cache[$k];
    $rs = safe_r_sql("SELECT * FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . "
          AND TdType = " . StrSafe_DB($type) . "
          AND " . StrSafe_DB(trim($division) . trim($class)) . " LIKE TdClasses
        ORDER BY CHAR_LENGTH(TdClasses) DESC LIMIT 1");
    $d = array();
    if ($r = safe_fetch($rs)) {
        for ($i = 1; $i <= min(8, max(1, intval($nDist))); $i++) {
            $lab = trim((string) ($r->{'Td' . $i} ?? ''));
            if ($lab === '' || $lab === '-') continue;
            $m = bk_td_metres($lab, $r->{'TdDist' . $i} ?? 0);
            if ($m > 0) $d[] = $m;
        }
    }
    return $cache[$k] = $d;
}

/**
 * Assigns a target to the archers of a departure who have none yet (lib/placement.php, rules in
 * REGLES_PLACEMENT.md).
 *
 * NEVER MOVES an archer already placed: the organiser may have adjusted by hand (or another tool
 * placed them), and a late registration must not reshuffle everyone. Writes QuTarget, QuLetter
 * and QuTargetNo, as the target screens of the core do.
 *
 * Returns ['places'=>N, 'restants'=>M, 'compromis'=>K, 'melanges'=>X, 'fauteuil'=>F,
 * 'incompatibles'=>I, 'voeux'=>W, 'voeuxOk'=>V]: `compromis` counts the archers placed beyond the
 * club quota, `melanges` the targets carrying both 40 and triple faces, `fauteuil` the targets where
 * the place beside a wheelchair archer is taken, `incompatibles` the archers no target of the departure can
 * take (field settings).
 */
function bk_assign_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    $zero = array('places' => 0, 'restants' => 0, 'compromis' => 0, 'melanges' => 0, 'fauteuil' => 0,
                  'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);
    $data = bk_plan_read($tourId, $sessionOrder, $cfg);
    if ($data === null || !$data['in']['archers']) return $zero;

    $res = bk_plan_session($data['in']);

    foreach ($res['places'] as $id => $p) {
        $no = bk_target_no($sessionOrder, $p['t'], $p['l']);
        // Redundant safeguard: the UPDATE aims at one EnId AND checks the competition again —
        // Qualifications alone would spill over the whole database.
        safe_w_sql("UPDATE Qualifications
            INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
            SET QuTarget = " . intval($p['t']) . ",
                QuLetter = " . StrSafe_DB($p['l']) . ",
                QuTargetNo = " . StrSafe_DB($no) . ",
                QuTimestamp = QuTimestamp
            WHERE QuId = " . intval($id));
    }

    // Those NO target of the departure can take (field settings), told apart from a lack of room.
    $ctx = bk_plan_ctx($data['in']);
    $incompatibles = 0;
    foreach ($res['unplaced'] as $id) {
        $ok = false;
        foreach ($ctx['targets'] as $t) if (bk_plan_caps_ok($ctx, $t, $ctx['archers'][$id])) { $ok = true; break; }
        if (!$ok) $incompatibles++;
    }

    return array('places' => count($res['places']), 'restants' => count($res['unplaced']),
                 'compromis' => $res['overQuota'], 'melanges' => $res['mixed40tri'], 'fauteuil' => $res['wheelchairFull'],
                 'incompatibles' => $incompatibles,
                 'voeux' => $res['wishes']['asked'], 'voeuxOk' => $res['wishes']['kept']);
}

/**
 * Places STILL available on a departure for a given PROFILE (bow, category, face) — the
 * "specific gauge" of the registration, and the admission check: 0 ⇒ no target can take this
 * archer any more, the registration must be refused.
 *
 * "How many more archers of this profile would the NEXT automatic re-planning still place?"
 * (bk_replan_session moves every online registration) — never the room left around the archers
 * where they stand now. Measured around the current places, an archer of another category on a
 * target shared by two categories looked like a wall, while the next re-planning moves them
 * onto their own targets (bug of AUTH ≤ 1.10.0 seen on a real competition: recurve archers on
 * the barebow/recurve targets made the barebow look full). Returns null when the constraint is
 * unknown (no departure). With MANUAL validation, the pending registrations are not placed yet,
 * hence not counted.
 */
function bk_profile_remaining($tourId, $sessionOrder, $division, $class, $faceId, $wheel = false, $except = 0)
{
    $ctx = bk_profile_ctx($tourId, $sessionOrder, $except);
    return $ctx === null ? null : bk_profile_left($ctx, $division, $class, $faceId, $wheel);
}

/** Above this many archers on a departure (registered + measured), no simulation: the room around the planned places. */
const BK_GAUGE_SIM_MAX = 1500;

/**
 * What bk_profile_remaining needs of a departure, read once: several profiles can then be
 * measured against it (the table of the places left per category). null: no such departure.
 * $except: an EnId left out (a registration being changed: its own place is free for it).
 *
 * The departure twice, as the automatic re-planning reads it (bk_replan_pick): 'in' with every
 * online registration to place (bk_plan_read in replan mode, only the organiser's entries fixed)
 * and 'inKeep' as it stands (the archers placed stay, only those without a target are placed).
 * The re-planning itself is computed on demand (bk_profile_base): a gauge already in the cache
 * costs nothing more than reading the departure.
 */
function bk_profile_ctx($tourId, $sessionOrder, $except = 0)
{
    $data = bk_plan_read($tourId, $sessionOrder, null, true);
    if ($data === null) return null;
    $data['inKeep'] = bk_plan_read($tourId, $sessionOrder)['in'];
    if ($except) {
        $keep = function ($f) use ($except) { return intval($f['id']) !== intval($except); };
        foreach (array('in', 'inKeep') as $k) {
            $data[$k]['fixed'] = array_values(array_filter($data[$k]['fixed'], $keep));
            $data[$k]['archers'] = array_values(array_filter($data[$k]['archers'], $keep));
        }
    }
    $data['tour'] = intval($tourId);
    $data['session'] = intval($sessionOrder);
    $data['left'] = array();
    $data['hash'] = md5(json_encode(array($data['in'], $data['inKeep'])) . '|' . bk_gauge_code_version());
    return $data;
}

/**
 * The plan the automatic re-planning keeps (bk_replan_session): everyone placed again ($inAll)
 * or the archers kept where they stand and only those without a target placed ($inKeep),
 * whichever leaves fewer archers out, then uses fewer targets, then breaks fewer soft rules,
 * then keeps more wishes (bk_plan_quality); a full tie keeps the places held — an archer does
 * not move for nothing. Re-planning from scratch around fixed archers spread over the field (the
 * organiser's entries after a "Reassign" or a final composition) can fail, or spread the others
 * onto new targets, where the places held were a solution: a target partly held by archers who
 * do not move is not cut again. Returns [planner result, 'all'|'keep'].
 */
function bk_replan_pick($inAll, $inKeep)
{
    $all = bk_plan_session($inAll);
    $keep = bk_plan_session($inKeep);
    // Both measured on the same archers: everyone online is an archer of $inAll.
    $ctx = bk_plan_ctx($inAll);
    $held = $keep['places'];
    foreach ($inKeep['fixed'] as $f) {
        // bytes: target letters are ASCII
        if (isset($ctx['archers'][intval($f['id'])])) $held[intval($f['id'])] = array('t' => intval($f['t']), 'l' => strtoupper((string) $f['l']));
    }
    $sk = array_merge(array(count($keep['unplaced'])), bk_plan_quality($ctx, $held));
    $sa = array_merge(array(count($all['unplaced'])), bk_plan_quality($ctx, $all['places']));
    return $sk <= $sa ? array($keep, 'keep') : array($all, 'all');
}

/**
 * How good a placement of a departure is, to compare two of them (smaller is better):
 * [targets carrying someone, soft rules broken (archer alone, two of a club alone, place beside
 * a wheelchair taken, club over its quota, 40 with a triple face), − wishes kept].
 */
function bk_plan_quality($ctx, $places)
{
    $occ = bk_plan_occupants($ctx, $places);
    $issues = 0;
    foreach ($occ as $here) {
        $issues += array_sum(bk_plan_target_issues($ctx, $here));
        $n = array();
        foreach ($here as $o) $n[$o['club']] = ($n[$o['club']] ?? 0) + 1;
        foreach ($n as $c) $issues += max(0, $c - $ctx['max']);
        if (bk_plan_mixed40tri($here)) $issues++;
    }
    return array(count($occ), $issues, -bk_plan_wishes($ctx, $places)['kept']);
}

/**
 * The re-planning of the departure, once per context: 'base' (planner result of
 * bk_replan_pick), 'plan' + 'occ' (the archers where it leaves them, as obstacles), 'placed' (ids
 * holding a target then) and 'hard' + 'hardOcc' (only the archers no re-planning moves). A caller
 * of bk_profile_left_static may narrow $ctx['plan']['targets'] after this call: the room is
 * counted target by target.
 */
function bk_profile_base(&$ctx)
{
    if (isset($ctx['base'])) return;
    list($ctx['base'], $mode) = bk_replan_pick($ctx['in'], $ctx['inKeep']);
    $src = $mode === 'keep' ? $ctx['inKeep'] : $ctx['in'];
    $fixed = $src['fixed'];
    foreach ($src['archers'] as $a) {
        if (isset($ctx['base']['places'][$a['id']])) $fixed[] = $a + $ctx['base']['places'][$a['id']];
    }
    $ctx['placed'] = array();
    foreach ($fixed as $f) $ctx['placed'][intval($f['id'])] = true;
    $static = $src;
    $static['fixed'] = $fixed;
    $static['archers'] = array();
    $ctx['plan'] = bk_plan_ctx($static);
    $ctx['occ'] = bk_plan_occupants($ctx['plan'], array());
    $hard = $ctx['in'];
    $hard['archers'] = array();
    $ctx['hard'] = bk_plan_ctx($hard);
    $ctx['hardOcc'] = bk_plan_occupants($ctx['hard'], array());
}

/** An archer of the profile, as the planner reads one (no wish, a club of their own). */
function bk_profile_probe(&$ctx, $division, $class, $faceId, $wheel)
{
    $dist = bk_plan_series($ctx['tour'], $ctx['type'], $division, $class, $ctx['nDist']);
    $face = bk_caps_face_profile($ctx['tour'], $ctx['disc'], $faceId, max(1, count($dist)));
    $cl = $ctx['classes'][(string) $class] ?? array('age' => null, 'sex' => null);
    // A wheelchair archer needs a place with the one beside it free (bk_wheel_pairs).
    return array('id' => -1, 'dist' => $dist, 'fam' => $face['fams'], 'fsize' => $face['fsize'],
                 'face' => intval($faceId), 'club' => '~', 'wheelchair' => (bool) $wheel,
                 'div' => (string) $division, 'cls' => (string) $class, 'age' => $cl['age'], 'sex' => $cl['sex']);
}

/**
 * Room for archer $a on the targets of $pctx around $occ, archer by archer (bk_plan_room).
 * ⚠️ PLACES, not targets: an outdoor full face (122) is SHARED by up to SesAth4Target archers,
 * so each target is filled archer by archer. Empty targets with the same settings take the same
 * number: measured once (a departure of 9 999 targets stays cheap).
 */
function bk_profile_room_sum($pctx, $occ, $a)
{
    $n = 0;
    $empty = array();
    foreach ($pctx['targets'] as $t) {
        if (!empty($occ[$t])) { $n += bk_plan_room($pctx, $occ, $t, $a); continue; }
        $sig = json_encode($pctx['caps'][$t] ?? null);
        if (!isset($empty[$sig])) $empty[$sig] = bk_plan_room($pctx, $occ, $t, $a);
        $n += $empty[$sig];
    }
    return $n;
}

/**
 * Room around the planned places only, no re-planning simulated, on $ctx['plan']['targets'] —
 * which the caller may narrow (club quota of an archer coming back, lib/clubmoves.php).
 */
function bk_profile_left_static(&$ctx, $division, $class, $faceId, $wheel = false)
{
    bk_profile_base($ctx);
    return bk_profile_room_sum($ctx['plan'], $ctx['occ'], bk_profile_probe($ctx, $division, $class, $faceId, $wheel));
}

/**
 * Places of a departure (bk_profile_ctx) still open to one profile — see bk_profile_remaining.
 *
 * The largest k such that the re-planning with k more archers of the profile places all of them
 * without leaving out anyone it placed before. Bounds: from below, the room around the planned
 * places (the plan plus those archers is one solution); from above, the room around the
 * organiser's entries alone, less the online archers of the same distances and face (they take
 * seats this profile could use). Then a binary search, each step one re-planning (8 ms for 90
 * archers, 55 ms for 430). Profiles of one bow at the same distances and face share a key: the
 * class only orders the archers. Kept per request ($ctx['left']) and, between requests, in a file
 * of the system's temporary folder keyed by the departure's state (bk_gauge_cache): recomputed
 * only when a registration, a placement, the field plan or the code changes.
 */
function bk_profile_left(&$ctx, $division, $class, $faceId, $wheel = false)
{
    $a = bk_profile_probe($ctx, $division, $class, $faceId, $wheel);
    $key = md5($division . '#' . implode('-', $a['dist']) . '#' . intval($faceId) . '#' . ($wheel ? 'w' : ''));
    if (isset($ctx['left'][$key])) return $ctx['left'][$key];
    $cached = bk_gauge_cache($ctx, $key);
    if ($cached !== null) return $ctx['left'][$key] = $cached;

    bk_profile_base($ctx);
    $low = bk_profile_room_sum($ctx['plan'], $ctx['occ'], $a);
    $high = bk_profile_room_sum($ctx['hard'], $ctx['hardOcc'], $a);
    $an = bk_plan_norm($a);
    foreach ($ctx['in']['archers'] as $o) {
        $o = bk_plan_norm($o);
        if ($o['dk'] === $an['dk'] && $o['face'] === $an['face'] && isset($ctx['placed'][$o['id']])) $high--;
    }
    $high = max($high, $low);
    $n = $low;
    if (count($ctx['in']['archers']) + $high <= BK_GAUGE_SIM_MAX) {
        $baseOut = count($ctx['base']['unplaced']);
        $fits = function ($k) use ($ctx, $a, $baseOut) {
            if ($k <= 0) return true;
            $in = $ctx['in'];
            $keep = $ctx['inKeep'];
            for ($i = 1; $i <= $k; $i++) {
                $in['archers'][] = $keep['archers'][] = array('id' => -$i, 'club' => '~' . $i) + $a;
            }
            list($res) = bk_replan_pick($in, $keep);
            foreach ($res['unplaced'] as $id) if ($id < 0) return false;
            return count($res['unplaced']) <= $baseOut;
        };
        // Every number given is one the re-planning reaches: the planner starts from scratch and,
        // near a full departure, does not always find the solution the planned places prove
        // (the lower bound is then checked too, like any other).
        if ($fits($high)) {
            $n = $high;
        } else {
            if ($low < $high && $fits($low)) { $lo = $low; $hi = $high - 1; }
            else { $lo = 0; $hi = $low - 1; }
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                if ($fits($mid)) $lo = $mid; else $hi = $mid - 1;
            }
            $n = $lo;
        }
        // The admission check: one more archer of the profile must be placed by the re-planning.
        if ($n > 1 && !$fits(1)) $n = 0;
    }
    bk_gauge_cache($ctx, $key, $n);
    return $ctx['left'][$key] = $n;
}

/**
 * Places of a departure (bk_profile_ctx) still open to SEVERAL profiles of one bow taken together
 * — the classes of an event. Two measures, the larger kept: target by target the best of them
 * around the planned places, summed (classes on different faces each count their own targets),
 * and the best gauge of a single profile (bk_profile_left: archers moved by the re-planning).
 * They share the targets, so the numbers of each profile do not add up. $profiles: [[class, face TfId]…].
 */
function bk_profile_left_multi(&$ctx, $division, $profiles)
{
    $as = array();
    foreach ($profiles as $p) {
        $a = bk_profile_probe($ctx, $division, $p[0], $p[1], false);
        $as[implode('-', $a['dist']) . '#' . intval($p[1])] = array($a, $p);
    }
    $key = md5('multi#' . $division . '#' . implode(',', array_keys($as)));
    if (isset($ctx['left'][$key])) return $ctx['left'][$key];
    bk_profile_base($ctx);
    $n = 0;
    $empty = array();
    foreach ($ctx['plan']['targets'] as $t) {
        $busy = !empty($ctx['occ'][$t]);
        $sig = $busy ? null : json_encode($ctx['plan']['caps'][$t] ?? null);
        if (!$busy && isset($empty[$sig])) { $n += $empty[$sig]; continue; }
        $best = 0;
        foreach ($as as $x) $best = max($best, bk_plan_room($ctx['plan'], $ctx['occ'], $t, $x[0]));
        if (!$busy) $empty[$sig] = $best;
        $n += $best;
    }
    foreach ($as as $x) $n = max($n, bk_profile_left($ctx, $division, $x[1][0], $x[1][1]));
    return $ctx['left'][$key] = $n;
}

/** What changes the gauges besides the departure itself: the planner and this file. */
function bk_gauge_code_version()
{
    static $v = null;
    if ($v === null) {
        $v = @filemtime(__DIR__ . '/placement.php') . '.' . @filemtime(__DIR__ . '/cohabitation.php') . '.' . @filemtime(__FILE__);
    }
    return $v;
}

/**
 * Gauges of a departure kept between requests: one JSON file per departure in the system's
 * temporary folder (never in the module: Apache would serve it; never in the database: public
 * pages read the gauge), valid while the departure's state ($ctx['hash']) stays the same. Read
 * with $set null (null when absent), written otherwise. Unreadable or not writable: everything
 * still works, only slower.
 */
function bk_gauge_cache(&$ctx, $key, $set = null)
{
    global $CFG;
    // bytes: a hexadecimal digest is ASCII
    $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ianseo-gauge-'
        . substr(md5(__DIR__ . '|' . ($CFG->DB_NAME ?? '')), 0, 12) . '-' . $ctx['tour'] . '-' . $ctx['session'] . '.json';
    $raw = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || ($data['h'] ?? '') !== $ctx['hash']) $data = array('h' => $ctx['hash'], 'l' => array());
    if ($set === null) return isset($data['l'][$key]) ? intval($data['l'][$key]) : null;
    $data['l'][$key] = intval($set);
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $set;
}

/**
 * Plans a departure ENTIRELY again to best meet the archers' wishes, then places everyone.
 *
 * Called after each registration: a "with someone" wish cannot be met by placing people one by
 * one in order of arrival — the cards must be reshuffled.
 *
 * ⚠️ After a registration ($all false), frees ONLY the registrations made by this module (in
 * BookingRegistrations). A participant entered by the organiser, or placed by another tool, keeps
 * their target: it becomes a fixed obstacle. Without this guard, an online registration would move
 * the organiser's manual work. The organiser's "Reassign" buttons ($all true) free EVERY archer of
 * the departure, whatever the origin of their registration (user's rule, 2026-11-18).
 */
function bk_replan_session($tourId, $sessionOrder, $cfg, $all = false)
{
    $tourId = intval($tourId);
    if (!$all) {
        // Never worse than the places held (bk_replan_pick): then only the archers without a
        // target are placed. bk_assign_session plans the same input again, hence the same plan.
        $keep = bk_plan_read($tourId, $sessionOrder, $cfg);
        $full = bk_plan_read($tourId, $sessionOrder, $cfg, true);
        if ($keep !== null && $full !== null && bk_replan_pick($full['in'], $keep['in'])[1] === 'keep') {
            return bk_assign_session($tourId, $sessionOrder, $cfg);
        }
    }
    $join = $all ? '' : ' INNER JOIN BookingRegistrations ON BrEnId = EnId';
    safe_w_sql("UPDATE Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId$join
        SET QuTarget = 0, QuLetter = '', QuTargetNo = '', QuTimestamp = QuTimestamp
        WHERE QuSession = " . intval($sessionOrder));
    return bk_assign_session($tourId, $sessionOrder, $cfg);
}

/**
 * Plans again every departure where the module has registrations. $all: every departure with
 * archers, all of them freed (the organiser's "Reassign on every session").
 */
function bk_replan_all($tourId, $cfg, $all = false)
{
    $tourId = intval($tourId);
    $join = $all ? '' : ' INNER JOIN BookingRegistrations ON BrEnId = EnId';
    $rs = safe_r_sql("SELECT DISTINCT QuSession FROM Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId$join
        WHERE QuSession > 0");
    $tot = array('places' => 0, 'restants' => 0, 'compromis' => 0, 'melanges' => 0, 'fauteuil' => 0,
                 'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);
    while ($r = safe_fetch($rs)) {
        $x = bk_replan_session($tourId, intval($r->QuSession), $cfg, $all);
        foreach ($tot as $k => $v) $tot[$k] = $v + ($x[$k] ?? 0);
    }
    return $tot;
}

/* ---- Manual validation of the registrations ---------------------------- */

/** Online registrations waiting for validation (BrValidated=0). */
function bk_pending_registrations($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT BrEnId, BrCreated, EnFirstName, EnName, EnCode,
                EnDivision, EnClass, DivDescription, ClDescription,
                CoName, CoCode, QuSession
        FROM BookingRegistrations
        INNER JOIN Entries        ON EnId = BrEnId
        /* 1:1 with Entries: INNER JOIN, never LEFT + IS NULL. */
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Divisions      ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT  JOIN Classes       ON ClTournament = EnTournament AND ClId = EnClass
        LEFT  JOIN Countries      ON CoId = EnCountry
        WHERE BrTournament = $tourId AND BrValidated = 0
        ORDER BY BrCreated, BrId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Number of registrations waiting for validation. */
function bk_pending_count($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) n FROM BookingRegistrations
        WHERE BrTournament = " . $tourId . " AND BrValidated = 0"));
    return $r ? intval($r->n) : 0;
}

/** Validates a registration, then places its departure. Returns bool. */
function bk_validate_registration($tourId, $enId, $cfg)
{
    $tourId = intval($tourId); $enId = intval($enId);
    $r = safe_fetch(safe_r_sql("SELECT QuSession FROM BookingRegistrations
        INNER JOIN Qualifications ON QuId = BrEnId
        WHERE BrEnId = $enId AND BrTournament = $tourId AND BrValidated = 0"));
    if (!$r) return false;
    safe_w_sql("UPDATE BookingRegistrations SET BrValidated = 1 WHERE BrEnId = $enId AND BrTournament = $tourId");
    bk_replan_session($tourId, intval($r->QuSession), $cfg);
    return true;
}

/** Validates every pending registration + places the departures. Returns the number validated. */
function bk_validate_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $sessions = array(); $n = 0;
    $rs = safe_r_sql("SELECT QuSession FROM BookingRegistrations
        INNER JOIN Qualifications ON QuId = BrEnId
        WHERE BrTournament = $tourId AND BrValidated = 0");
    while ($r = safe_fetch($rs)) { $sessions[intval($r->QuSession)] = true; $n++; }
    if (!$n) return 0;
    safe_w_sql("UPDATE BookingRegistrations SET BrValidated = 1 WHERE BrTournament = $tourId AND BrValidated = 0");
    foreach (array_keys($sessions) as $so) bk_replan_session($tourId, $so, $cfg);
    return $n;
}

/** Frees the targets of a departure (archers back to waiting for a placement). */
function bk_clear_session($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        SET QuTarget = 0, QuLetter = '', QuTargetNo = '', QuTimestamp = QuTimestamp
        WHERE QuSession = " . intval($sessionOrder));
    return intval(safe_w_affected_rows());
}

/**
 * Check of the rules, departure by departure. To run when registrations close (that is when the
 * check makes sense: before, the field still moves). It looks at the targets as they are, the
 * organiser's own moves in ianseo included.
 *
 * Returns an array per departure: club/target quota breaches, number of clubs present, targets
 * with 2 archers or more against the minimum, archers alone, two archers of one club alone,
 * the place beside a wheelchair archer taken, faces that cannot share their target (one
 * family per half of the buttress indoors, sharing rules outdoors, one distance), archers not
 * placed, the same licence twice.
 */
function bk_rules_check($tourId, $cfg)
{
    $tourId   = intval($tourId);
    $max      = max(1, intval($cfg->BcMaxPerClubPerTarget));
    $minClubs = max(1, intval($cfg->BcMinClubsPerSession));
    $minTargets = max(0, intval($cfg->BcMinTargetsPerSession ?? 0));

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

        // Targets with 2 archers or more (rule of the regulations, with the clubs); no archer
        // alone, nor 2 archers of one club alone (they would mark each other's scores) —
        // regulations B.1.2, after withdrawals too.
        $remplies = 0; $seuls = array(); $paires = array();
        foreach ($parCible as $cible => $parClub) {
            $n = 0;
            foreach ($parClub as $liste) $n += count($liste);
            if ($n >= 2) $remplies++;
            if ($n === 1) $seuls[] = $cible;
            if ($n === 2 && count($parClub) === 1) $paires[] = array('cible' => $cible, 'club' => (string) key($parClub));   // a numeric club code is an int key
        }

        // Wheelchair and faces, from the placement rules (lib/placement.php).
        $fauteuil = array(); $blasons = array();
        $data = bk_plan_read($tourId, $order, $cfg);
        if ($data !== null) {
            $ctx = bk_plan_ctx($data['in']);
            foreach (bk_plan_occupants($ctx, array()) as $cible => $here) {
                if (bk_plan_target_issues($ctx, $here)[2]) $fauteuil[] = $cible;
                $ok = count(array_unique(array_map(function ($o) { return $o['dk']; }, $here))) === 1;
                $nd = count(reset($here)['fams']);
                for ($d = 0; $ok && $d < $nd; $d++) {
                    $by = array();
                    foreach ($here as $l => $o) $by[$l] = $o['fams'][$d] ?? $o['fams'][0];
                    $ok = bk_target_layout($ctx['disc'], $ctx['rhythm'], $by)['ok'];
                }
                if (!$ok) $blasons[] = $cible;
            }
            sort($fauteuil);
            sort($blasons);
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
            'remplies'   => $remplies,
            'minTargets' => $minTargets,
            'targetsOk'  => $remplies >= $minTargets,
            'max'        => $max,
            'exces'      => $exces,
            'nonPlaces'  => $nonPlaces,
            'doublons'   => $doublons,
            'seuls'      => $seuls,
            'paires'     => $paires,
            'fauteuil'   => $fauteuil,
            'blasons'    => $blasons,
            'ok'         => !$exces && count($clubs) >= $minClubs && $remplies >= $minTargets && $nonPlaces === 0 && !$doublons
                            && !$seuls && !$paires && !$fauteuil && !$blasons,
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
            // bytes: target letters and numbers are ASCII
            $plan[intval($a->QuTarget)][strtoupper($a->QuLetter)] = $a;
        }
    }
    ksort($plan);
    foreach ($plan as &$c) ksort($c);
    return $plan;
}
