<?php
/**
 * lib/places.php — places left per category on each departure (user's choice, 2026-10-15): the
 * grid of the categories allowed (divisions × classes, as in the Restrictions), each cell the
 * number of archers of THIS category the departure can still take — field constraints, archers
 * already placed and faces sharing a target (REGLES_COHABITATION_FR.md), exactly as the gauge of
 * the registration form (bk_profile_left). Categories share the targets: the numbers do not add
 * up. Shown in the registration state of the organiser; meant for the invitation later.
 */

if (defined('BK_PLACES_LOADED')) return;
define('BK_PLACES_LOADED', true);

require_once __DIR__ . '/caps.php';
require_once __DIR__ . '/cohabitation.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/targets.php';

/**
 * The grid of the places left, per departure.
 * ['at' => local date-time of the competition when computed,
 *  'divs' => [DivId => label], 'classes' => [ClId => label],
 *  'sessions' => [SesOrder => ['name' => SesName, 'cells' => [DivId => [ClId => places]]]]]
 * Only the categories open to online registration on the departure; a division or a class
 * without any is absent from 'cells'. Per category: the best of the faces it can choose,
 * never more than the places of the departure (registered archers not placed yet included).
 */
function bk_places_grid($tourId)
{
    $tourId = intval($tourId);
    $at = safe_fetch(safe_r_sql("SELECT " . bk_local_now_sql() . " AS n FROM Tournament WHERE ToId = $tourId"));
    $out = array('at' => $at ? (string) $at->n : '', 'divs' => array(), 'classes' => array(), 'sessions' => array());
    $grid = bk_cat_grid($tourId, array());
    foreach ($grid['divs'] as $d) $out['divs'][$d[0]] = $d[1];
    foreach ($grid['classes'] as $c) $out['classes'][$c[0]] = $c[1];

    $run = function () use ($tourId, $grid, $out) {
        $faces = bk_caps_faces($tourId);
        $choices = array();
        foreach (bk_comp_sessions($tourId) as $s) {
            $o = intval($s->SesOrder);
            $left = max(0, intval($s->Places) - intval($s->Pris));
            $ctx = bk_profile_ctx($tourId, $o);
            $cells = array();
            foreach ($grid['cells'] as $div => $row) {
                foreach (array_keys($row) as $cls) {
                    if (!bk_cat_allowed($tourId, $div, $cls, $o)) continue;
                    $k = $div . '|' . $cls;
                    if (!isset($choices[$k])) $choices[$k] = array_keys(bk_caps_face_choices($tourId, $div, $cls, $faces));
                    // No face known for the category: the departure's own count, as on the form.
                    $n = $left;
                    if ($ctx !== null && $choices[$k]) {
                        $best = 0;
                        foreach ($choices[$k] as $f) $best = max($best, bk_profile_left($ctx, $div, $cls, $f));
                        $n = min($left, $best);
                    }
                    $cells[$div][$cls] = $n;
                }
            }
            $out['sessions'][$o] = array('name' => (string) $s->SesName, 'cells' => $cells);
        }
        return $out;
    };
    // getTargets() reads the open competition: opened for the computation when it is another one.
    return intval($_SESSION['TourId'] ?? 0) === $tourId ? $run() : bk_with_tournament($tourId, $run);
}
