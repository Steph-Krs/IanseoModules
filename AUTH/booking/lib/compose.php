<?php
/**
 * lib/compose.php — final composition of a departure, from the archers registered now
 * (user's request, 2026-11-18). One departure at a time, previewed before anything is written,
 * undone by one click (the last composition of the departure).
 *
 * Two modes:
 * - 'free'  (field set up for the competition): the field settings of the departure are written
 *   again from the archers — from a chosen first target, no gap, each target given exactly the
 *   distances and faces of the archers it carries; the targets left over get no setting.
 * - 'fixed' (permanent field): the field settings stay; every archer is placed again within them.
 *
 * Every archer moves, online registrations and entries made in ianseo alike (user's rule for the
 * reassign buttons). A registration still waiting for validation keeps its target if it has one.
 */

if (defined('BK_COMPOSE_LOADED')) return;
define('BK_COMPOSE_LOADED', true);

require_once __DIR__ . '/targets.php';

/**
 * The departure as the composition reads it, or null: bk_plan_read in 'all' mode plus the field
 * settings, the targets of the departure and where every archer stands now.
 */
function bk_compose_read($tourId, $sessionOrder, $cfg)
{
    $data = bk_plan_read($tourId, $sessionOrder, $cfg, 'all');
    if ($data === null) return null;
    $data['tour'] = intval($tourId);
    $data['session'] = intval($sessionOrder);
    $data['caps'] = bk_caps_get($tourId, $sessionOrder);
    $data['targets'] = $data['in']['targets'];
    $data['before'] = array();
    foreach ($data['rows'] as $id => $r) {
        // bytes: target letters are ASCII
        if (intval($r->QuTarget) > 0) $data['before'][$id] = array('t' => intval($r->QuTarget), 'l' => strtoupper((string) $r->QuLetter));
    }
    return $data;
}

/** Targets carrying someone: [target => true], the archers placed and those who keep their target. */
function bk_compose_used($in, $places)
{
    $used = array();
    foreach ($places as $p) $used[intval($p['t'])] = true;
    foreach ($in['fixed'] as $f) $used[intval($f['t'])] = true;
    return $used;
}

/**
 * The composition, computed (nothing written):
 * ['res' => planner result, 'caps' => field settings after [target => ['def','min','max','f']],
 *  'places' => [EnId => ['t','l']] after (archers who keep their target included),
 *  'unplaced' => [EnId…], 'first' => first target used by the free mode].
 */
function bk_compose_plan($read, $mode, $first)
{
    $in = $read['in'];
    if ($mode !== 'free') {
        $res = bk_plan_session($in);
        $caps = $read['caps'];
        $first = 0;
    } else {
        $all = $read['targets'];
        $first = min(max(intval($first), $all[0]), end($all));
        $range = array_values(array_filter($all, function ($t) use ($first) { return $t >= $first; }));
        $in['caps'] = array();
        $in['targets'] = $range;
        $res = bk_plan_session($in);
        // Then the same archers on just as many targets from the first one: the planner spreads
        // a departure (minimum of targets, a target more to keep a 40 off a triple face), never
        // over a gap, but a departure placed from its last target would start at the far end.
        $need = count(bk_compose_used($in, $res['places']));
        for ($k = $need; $k < count($range); $k++) {
            $in['targets'] = array_slice($range, 0, $k);
            $try = bk_plan_session($in);
            if (count($try['unplaced']) <= count($res['unplaced'])) { $res = $try; break; }
        }
        $caps = bk_compose_caps($in, $res['places']);
    }
    $places = $res['places'];
    foreach ($in['fixed'] as $f) $places[intval($f['id'])] = array('t' => intval($f['t']), 'l' => (string) $f['l']);
    return array('res' => $res, 'caps' => $caps, 'places' => $places, 'unplaced' => $res['unplaced'], 'first' => $first);
}

/**
 * Field settings written from the archers on each target: the range of their distances (the
 * first distance as default) and their faces. A target nobody uses gets no setting.
 */
function bk_compose_caps($in, $places)
{
    $who = array();
    foreach ($in['archers'] as $a) if (isset($places[$a['id']])) $who[intval($places[$a['id']]['t'])][] = $a;
    foreach ($in['fixed'] as $f) $who[intval($f['t'])][] = $f;
    $caps = array();
    foreach ($who as $t => $list) {
        $d = array(); $f = array(); $def = 0;
        foreach ($list as $a) {
            foreach ((array) $a['dist'] as $m) if (intval($m) > 0) $d[] = intval($m);
            if (!$def && !empty($a['dist'])) $def = intval(reset($a['dist']));
            if (intval($a['face']) > 0) $f[intval($a['face'])] = true;
        }
        $f = array_keys($f);
        sort($f);
        $caps[$t] = array('def' => $def, 'min' => $d ? min($d) : 0, 'max' => $d ? max($d) : 0, 'f' => $f);
    }
    ksort($caps);
    return $caps;
}

/** Fingerprint of a preview: an apply on a departure changed since shows the preview again. */
function bk_compose_hash($read, $mode, $first)
{
    return md5(json_encode(array($read['in'], $read['caps'], $read['before'], $mode, intval($first))));
}

/**
 * Span of a placement: ['first', 'last', 'used' (targets carrying someone), 'holes' (empty
 * targets between the first and the last)]; zeros when nobody is placed.
 */
function bk_compose_span($places)
{
    $used = array();
    foreach ($places as $p) $used[intval($p['t'])] = true;
    if (!$used) return array('first' => 0, 'last' => 0, 'used' => 0, 'holes' => 0);
    $first = min(array_keys($used));
    $last = max(array_keys($used));
    return array('first' => $first, 'last' => $last, 'used' => count($used), 'holes' => $last - $first + 1 - count($used));
}

/** The last composition of a departure that can still be undone: ['mode', 'when'] or null. */
function bk_compose_undo_info($tourId, $sessionOrder)
{
    $r = safe_fetch(safe_r_sql("SELECT BuMode, BuWhen FROM BookingFieldUndo
        WHERE BuTournament = " . intval($tourId) . " AND BuSession = " . intval($sessionOrder), false, true));
    return $r ? array('mode' => (string) $r->BuMode, 'when' => (string) $r->BuWhen) : null;
}

/** Writes the places of a departure: frees $free (EnIds), then writes $places [EnId => ['t','l']]. */
function bk_compose_write_places($tourId, $sessionOrder, $free, $places)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $free = array_map('intval', $free);
    if ($free) {
        // Qualifications has no competition column: the join on Entries bounds the UPDATE.
        safe_w_sql("UPDATE Qualifications
            INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
            SET QuTarget = 0, QuLetter = '', QuTargetNo = '', QuTimestamp = QuTimestamp
            WHERE QuSession = $sessionOrder AND QuId IN (" . implode(',', $free) . ")");
    }
    foreach ($places as $id => $p) {
        safe_w_sql("UPDATE Qualifications
            INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
            SET QuTarget = " . intval($p['t']) . ",
                QuLetter = " . StrSafe_DB($p['l']) . ",
                QuTargetNo = " . StrSafe_DB(bk_target_no($sessionOrder, $p['t'], $p['l'])) . ",
                QuTimestamp = QuTimestamp
            WHERE QuSession = $sessionOrder AND QuId = " . intval($id));
    }
}

/** Replaces the field settings of a departure by $caps [target => ['def','min','max','f']]. */
function bk_compose_write_caps($tourId, $sessionOrder, $caps)
{
    bk_caps_clear($tourId, $sessionOrder);
    foreach ($caps as $t => $c) bk_caps_set($tourId, $sessionOrder, $t, $c['def'], $c['min'], $c['max'], $c['f']);
}

/**
 * Applies a composition previewed with fingerprint $hash. Returns ['changed' => true] when the
 * departure changed since the preview (nothing written), else ['places' => N, 'unplaced' => M].
 * What it replaces is kept first (BookingFieldUndo), in the same transaction.
 */
function bk_compose_apply($tourId, $sessionOrder, $cfg, $mode, $first, $hash)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $mode = $mode === 'free' ? 'free' : 'fixed';
    $read = bk_compose_read($tourId, $sessionOrder, $cfg);
    if ($read === null || !hash_equals(bk_compose_hash($read, $mode, $first), (string) $hash)) return array('changed' => true);
    $plan = bk_compose_plan($read, $mode, $first);

    $snap = array('caps' => $read['caps'], 'places' => $read['before']);
    $ids = array();
    foreach ($read['in']['archers'] as $a) $ids[] = intval($a['id']);
    $moved = array_intersect_key($plan['res']['places'], array_flip($ids));

    safe_w_sql("START TRANSACTION");
    safe_w_sql("REPLACE INTO BookingFieldUndo (BuTournament, BuSession, BuMode, BuWhen, BuData)
        SELECT $tourId, $sessionOrder, " . StrSafe_DB($mode) . ", " . bk_local_now_sql() . ", "
        . StrSafe_DB(json_encode($snap)) . " FROM Tournament WHERE ToId = $tourId");
    if ($mode === 'free') bk_compose_write_caps($tourId, $sessionOrder, $plan['caps']);
    bk_compose_write_places($tourId, $sessionOrder, $ids, $moved);
    safe_w_sql("COMMIT");
    return array('places' => count($moved), 'unplaced' => count($plan['unplaced']));
}

/**
 * Undoes the last composition of a departure: its field settings and the places of the archers
 * still on it come back; an archer registered since is placed again around them. Returns null
 * when there is nothing to undo, else ['restored' => N, 'placed' => M].
 */
function bk_compose_undo($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $r = safe_fetch(safe_r_sql("SELECT BuMode, BuData FROM BookingFieldUndo
        WHERE BuTournament = $tourId AND BuSession = $sessionOrder", false, true));
    $snap = $r ? json_decode((string) $r->BuData, true) : null;
    if (!is_array($snap)) return null;

    $caps = array();
    foreach ((array) ($snap['caps'] ?? array()) as $t => $c) $caps[intval($t)] = $c;
    $places = array();
    foreach ((array) ($snap['places'] ?? array()) as $id => $p) $places[intval($id)] = $p;
    $all = array();
    foreach (bk_session_archers($tourId, $sessionOrder) as $a) $all[] = intval($a->EnId);
    $back = array_intersect_key($places, array_flip($all));

    safe_w_sql("START TRANSACTION");
    // A fixed field kept its settings: changed since by hand, they stay.
    if ($r->BuMode === 'free') bk_compose_write_caps($tourId, $sessionOrder, $caps);
    bk_compose_write_places($tourId, $sessionOrder, $all, $back);
    safe_w_sql("DELETE FROM BookingFieldUndo WHERE BuTournament = $tourId AND BuSession = $sessionOrder");
    safe_w_sql("COMMIT");
    // Archers registered after the composition: placed around the places restored.
    $more = bk_assign_session($tourId, $sessionOrder, $cfg);
    return array('restored' => count($back), 'placed' => intval($more['places']));
}

/**
 * Rows of the preview, target by target over both placements:
 * [target => ['before' => [caps, [label => n]], 'after' => [caps, [label => n]], 'changed' => bool]].
 * A label = division and face of the archers ("CL 40 cm").
 */
function bk_compose_rows($read, $plan, $faces)
{
    $tally = function ($places) use ($read, $faces) {
        $out = array();
        foreach ($places as $id => $p) {
            $r = $read['rows'][$id] ?? null;
            if (!$r) continue;
            $f = intval($r->EnTargetFace);
            $lab = trim($r->EnDivision . ' ' . ($faces[$f]['label'] ?? ''));
            $t = intval($p['t']);
            $out[$t][$lab] = ($out[$t][$lab] ?? 0) + 1;
        }
        return $out;
    };
    $b = $tally($read['before']);
    $a = $tally($plan['places']);
    $ts = array_unique(array_merge(array_keys($b), array_keys($a), array_keys($read['caps']), array_keys($plan['caps'])));
    sort($ts);
    $rows = array();
    foreach ($ts as $t) {
        $bb = $b[$t] ?? array(); $aa = $a[$t] ?? array();
        ksort($bb); ksort($aa);
        $cb = $read['caps'][$t] ?? null; $ca = $plan['caps'][$t] ?? null;
        $rows[$t] = array('before' => array($cb, $bb), 'after' => array($ca, $aa),
                          'changed' => $bb !== $aa || json_encode($cb) !== json_encode($ca));
    }
    return $rows;
}
