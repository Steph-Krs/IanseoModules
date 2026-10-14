<?php
/**
 * lib/field-events.php — the events a departure can take, given its field constraints (user's
 * choice, 2026-10-14): for the organiser setting them up (admin/field.php), shown live.
 *
 * For each event of the competition (individual or team), only its categories OPEN to online
 * registration on this departure count (bk_cat_allowed); an event without any is left out. An event can take part
 * when at least one target of the departure accepts the distances and a target face of one of
 * these categories, as the placement decides (bk_caps_target_ok). Its places: the maximum the
 * empty departure offers to this event alone — on each target, the archers its best face lets
 * share it (rhythm of the departure, rules of faces sharing a target: lib/cohabitation.php),
 * whoever is registered. The places really left will be shown elsewhere, later.
 */

if (defined('BK_FIELD_EVENTS_LOADED')) return;
define('BK_FIELD_EVENTS_LOADED', true);

require_once __DIR__ . '/caps.php';
require_once __DIR__ . '/cohabitation.php';
require_once __DIR__ . '/competition.php';

/**
 * Distances (metres) and target faces (TfId) of the categories open to online registration on
 * this departure: the palette of the field page offers only those (user's choice, 2026-10-15).
 * Empty lists when no category gives any (the page then offers everything, as before).
 */
function bk_field_palette($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $tr = safe_fetch(safe_r_sql("SELECT ToType FROM Tournament WHERE ToId = $tourId"));
    if (!$tr) return array('d' => array(), 'f' => array());
    $faces = bk_caps_faces($tourId);
    $d = $f = array();
    foreach (bk_cat_grid($tourId, bk_cat_forbidden($tourId, intval($sessionOrder)))['cells'] as $div => $row) {
        foreach ($row as $cls => $open) {
            if (!$open) continue;
            foreach (bk_caps_needs($tourId, $tr->ToType, $div, $cls, 0)['d'] as $m) $d[$m] = true;
            foreach (array_keys(bk_caps_faces_for($tourId, $div, $cls, $faces)) as $id) $f[$id] = true;
        }
    }
    $d = array_keys($d);
    sort($d);
    return array('d' => $d, 'f' => array_keys($f));
}

/**
 * Events of a departure, split: ['ind' => ['ok' => [...], 'ko' => [...]], 'team' => [...]].
 * Each: code, name, dist (metres, "70 m"), faces (labels), places (ok) or why (ko).
 */
function bk_field_events($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $out = array('ind' => array('ok' => array(), 'ko' => array()), 'team' => array('ok' => array(), 'ko' => array()));

    $tr = safe_fetch(safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule FROM Tournament WHERE ToId = $tourId"));
    $ses = safe_fetch(safe_r_sql("SELECT SesAth4Target, SesFirstTarget, SesTar4Session FROM Session
        WHERE SesTournament = $tourId AND SesOrder = $sessionOrder AND SesType = 'Q'"));
    if (!$tr || !$ses) return $out;
    $type = $tr->ToType;
    $disc = bk_comp_discipline($type, $tr->ToTypeSubRule, $tr->ToTypeName)['key'];
    $rhythm = max(1, intval($ses->SesAth4Target));
    $first = intval($ses->SesFirstTarget) ?: 1;
    $targets = array();
    for ($i = 0; $i < intval($ses->SesTar4Session); $i++) $targets[] = $first + $i;
    $caps = bk_caps_get($tourId, $sessionOrder);
    $faces = bk_caps_faces($tourId);

    // Archers one target takes of a face alone (same for every target: rhythm and face).
    $perTarget = array();
    $capOf = function ($faceId) use (&$perTarget, $tourId, $disc, $rhythm) {
        $cls = bk_face_class_by_id($tourId, $faceId);
        $k = bk_face_key($cls);
        if (!isset($perTarget[$k])) {
            $cur = array();
            for ($n = 0; $n < $rhythm; $n++) {
                if (bk_cohabit_max_add($cur, $cls, $disc, $rhythm) < 1) break;
                $cur[] = $cls;
            }
            $perTarget[$k] = count($cur);
        }
        return $perTarget[$k];
    };

    // Categories of each event, open to online registration on this departure only.
    $cats = array();
    $rs = safe_r_sql("SELECT EcCode, EcTeamEvent, EcDivision, EcClass FROM EventClass WHERE EcTournament = $tourId");
    while ($r = safe_fetch($rs)) {
        if (!bk_cat_allowed($tourId, $r->EcDivision, $r->EcClass, $sessionOrder)) continue;
        $cats[(intval($r->EcTeamEvent) > 0 ? 'team' : 'ind') . '|' . $r->EcCode][$r->EcDivision . '|' . $r->EcClass] = array((string) $r->EcDivision, (string) $r->EcClass);
    }
    $grid = bk_cat_grid($tourId, array());

    $rs = safe_r_sql("SELECT EvCode, EvTeamEvent, EvEventName FROM Events WHERE EvTournament = $tourId ORDER BY EvTeamEvent, EvProgr, EvCode");
    while ($ev = safe_fetch($rs)) {
        $kind = intval($ev->EvTeamEvent) ? 'team' : 'ind';
        $list = array();
        foreach ($cats[$kind . '|' . $ev->EvCode] ?? array() as $c) {
            if (isset($grid['cells'][$c[0]][$c[1]])) $list[] = $c;   // a category of athletes of the competition
        }
        if (!$list) continue;

        // Profiles: distances and target face of each category (each face it may shoot on).
        $profiles = array();
        foreach ($list as $c) {
            $fs = array_keys(bk_caps_faces_for($tourId, $c[0], $c[1], $faces)) ?: array(0);
            foreach ($fs as $fid) {
                $needs = bk_caps_needs($tourId, $type, $c[0], $c[1], $fid);
                $profiles[bk_caps_dist_key($needs) . '#' . $fid] = $needs;
            }
        }
        $dists = $faceIds = array();
        foreach ($profiles as $p) {
            foreach ($p['d'] as $m) $dists[$m] = true;
            if ($p['f']) $faceIds[$p['f']] = true;
        }
        ksort($dists);
        $item = array('code' => (string) $ev->EvCode, 'name' => (string) $ev->EvEventName,
            'dist' => implode(' / ', array_map(function ($m) { return $m . ' m'; }, array_keys($dists))),
            // The face by its name ("Trispot Classique 6-10 40cm"), which says its size too; its
            // label otherwise.
            'faces' => implode(' / ', array_map(function ($id) use ($faces) {
                return ($faces[$id]['name'] ?? '') !== '' ? $faces[$id]['name'] : ($faces[$id]['label'] ?? '');
            }, array_keys($faceIds))));

        // On each target, the best of the event's profiles it accepts.
        $places = 0;
        foreach ($targets as $t) {
            $best = 0;
            foreach ($profiles as $p) if (bk_caps_target_ok($caps, $t, $p)) $best = max($best, $capOf($p['f']));
            $places += $best;
        }
        if ($places > 0) {
            $item['places'] = $places;
            $out[$kind]['ok'][] = $item;
            continue;
        }
        // Why not: no target on the departure, none at the distance, or the face missing there.
        if (!$targets) {
            $item['why'] = 'none';
        } else {
            $distOk = false;
            foreach ($targets as $t) {
                foreach ($profiles as $p) if (bk_caps_target_ok($caps, $t, array('d' => $p['d'], 'f' => 0))) { $distOk = true; break 2; }
            }
            $item['why'] = $distOk ? 'face' : 'dist';
        }
        $out[$kind]['ko'][] = $item;
    }
    return $out;
}
