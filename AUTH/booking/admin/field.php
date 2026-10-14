<?php
/**
 * admin/field.php — field assignment constraints: capabilities of each target.
 * ("Field plan" is the visual target plan of the DragDropTarget module.)
 *
 * Graphical editing: palette of distances and faces to drag onto the targets, multiple
 * selection to apply at once. Saved through AJAX (ajax-field.php) — no page submission while
 * editing. Markup produced in PHP.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/adm-lib.php';   // frame of the organiser pages

CheckTourSession(true);
checkFullACL(AclParticipants, 'pTarget', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/caps.php';
require_once dirname(__DIR__) . '/lib/field-events.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();

$TOUR = intval($_SESSION['TourId']);

$rs   = safe_r_sql("SELECT ToType FROM Tournament WHERE ToId = $TOUR");
$tRow = safe_fetch($rs);
$type = $tRow ? $tRow->ToType : '';

$sessions = bk_comp_sessions($TOUR);
$ses      = intval($_GET['s'] ?? 0);
if (!$ses && $sessions) $ses = intval($sessions[0]->SesOrder);

$sesRow = null;
foreach ($sessions as $s) if (intval($s->SesOrder) === $ses) $sesRow = $s;

$dists = bk_caps_distances($TOUR, $type);
$faces = bk_caps_faces($TOUR);
$caps  = $ses ? bk_caps_get($TOUR, $ses) : array();

// Courses: the "faces" are coloured PEGS (field/3D/nature).
$hasPegs = false;
foreach ($faces as $f) if (!empty($f['peg'])) { $hasPegs = true; break; }

$targets = array();
if ($sesRow) {
    $first = intval($sesRow->SesFirstTarget) ?: 1;
    for ($i = 0; $i < intval($sesRow->SesTar4Session); $i++) $targets[] = $first + $i;
}

// Palette: the distances and faces of the categories open to online registration only
// (Restrictions); everything when none gives any. The faces all stay known to the script: a
// target already set with another one keeps showing it.
$pal = bk_field_palette($TOUR);
$palD = $pal['d'] ? array_values(array_intersect(array_keys($dists), $pal['d'])) : array_keys($dists);
sort($palD);
$palF = $pal['f'] ? array_flip($pal['f']) : $faces;

// Axis scale: those distances, evenly spaced — plus any a target of the departure already uses,
// so that a setting made before still shows. A setting to the metre makes no sense: a target
// sits at one of the rules' distances, not between two.
$used = array();
foreach ($caps as $c) foreach (array('def', 'min', 'max') as $k) if (!empty($c[$k])) $used[intval($c[$k])] = true;
$steps = array_values(array_unique(array_merge($palD, array_keys($used))));
sort($steps);

$boot = array(
    'steps'    => $steps,
    'tour'     => $TOUR,
    'session'  => $ses,
    'targets'  => $targets,
    'caps'     => (object) $caps,
    'dists'    => array_values($dists),
    'faces'    => array_values($faces),
    'token'    => bk_csrf_token(),
    'ajax'     => $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/ajax-field.php',
    'img'      => $CFG->ROOT_DIR . 'Common/Images/Targets/',
    'sessions' => array_map(function ($s) { return intval($s->SesOrder); }, $sessions),
    // Events the departure can take, and those it cannot (lib/field-events.php), redrawn after
    // every change.
    'events'   => $ses ? bk_field_events($TOUR, $ses) : null,
);

$boot['t'] = bk_ts(array('FjClickRemove', 'FjMin', 'FjDef', 'FjMax', 'FjSel0', 'FjSel1', 'FjSelN', 'FjSaving',
    'FjSaveFail', 'FjServerDown', 'FjSaved1', 'FjSavedN', 'FjSelectFirst', 'FjPickValue', 'FjCleared',
    'FjClearAllConfirm', 'FjDepCleared', 'FjPickDest', 'FjCopyConfirm', 'FjCopied', 'FjPickSrc',
    'FjCopyFromConfirm', 'FjCopiedFrom', 'FjEvPlaces', 'FjEvNoneOk', 'FjEvNoneKo', 'FjEvWhyNone', 'FjEvWhyDist',
    'FjEvWhyFace', 'FjEvEmpty'));

$base = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/';
$PAGE_TITLE = bk_t('MnuField');
$JS_SCRIPT = aut_adm_assets(array('<link rel="stylesheet" href="' . $base . 'assets/field.css?v=' . bk_e(bk_version()) . '">'));
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$out = aut_adm_open('booking/admin/field.php', bk_t('MnuField'), '', '', bk_e(bk_t('FldLegend'))) . '<div id="bkfield">';

if (!$sessions) {
    $out .= '<p class="bkf-warn">' . bk_t('FldNoSession') . '</p>';
} elseif (!$steps && !$faces) {
    $out .= '<p class="bkf-warn">' . bk_t('FldNothing') . '</p>';
} else {
    if (!$steps) $out .= '<p class="bkf-warn">' . bk_e(bk_t('FldNoMetres')) . '</p>';

    $out .= '<div class="bkf-tabs">';
    foreach ($sessions as $s) {
        $o = intval($s->SesOrder);
        $out .= '<a class="bkf-tab' . ($o === $ses ? ' bkf-tab-on' : '') . '" href="?s=' . $o . '">'
            . bk_e(bk_t('DepCap', $o) . ($s->SesName ? ' — ' . $s->SesName : '')) . '</a>';
    }
    $out .= '</div>';

    // Distances column.
    $out .= '<div class="bkf-wrap"><aside class="bkf-palette"><div class="bkf-pcol"><h2>' . bk_e(bk_t('FldDistances')) . '</h2>';
    if (!$dists) {
        $out .= '<p class="bkf-none">' . bk_e(bk_t('FldNoDist')) . '</p>';
    } else {
        $sel = function ($id, $key) use ($palD) {
            $o = '<option value="">—</option>';
            foreach ($palD as $m) $o .= '<option value="' . $m . '">' . $m . ' m</option>';
            return '<label>' . bk_e(bk_t($key)) . ' <select id="' . $id . '">' . $o . '</select></label>';
        };
        $out .= '<div class="bkf-dform">' . $sel('bkf-min', 'FldMin') . $sel('bkf-def', 'FldDef') . $sel('bkf-max', 'FldMax')
            . '<button type="button" class="bkf-btn bkf-btn-go" data-act="applyd">' . bk_e(bk_t('FldApply')) . '</button></div>'
            . '<div class="bkf-quicks"><span class="bkf-quick">' . bk_e(bk_t('FldSetTo')) . '</span>';
        foreach ($palD as $m) $out .= ' <button type="button" class="bkf-chip bkf-chip-d" data-quick="' . $m . '">' . $m . ' m</button>';
        $out .= '</div>';
    }
    $out .= '</div>';

    // Faces column. One colour per face (bk_caps_faces: text, background, border), the same as
    // its tags on the targets, given as CSS variables (field.css). Here there is room: picture,
    // size and the name of the face.
    $out .= '<div class="bkf-pcol"><h2>' . bk_e(bk_t($hasPegs ? 'FldPegs' : 'FldFaces')) . ' <span class="bkf-h2sub">'
        . bk_e(bk_t('FldDragHint')) . '</span></h2>'
        . (!$faces ? '<p class="bkf-none">' . bk_e(bk_t($hasPegs ? 'FldNoPeg' : 'FldNoFace')) . '</p>' : '')
        . '<div class="bkf-chips">';
    foreach ($faces as $f) {
        if (!isset($palF[$f['id']])) continue;   // no category open to online registration shoots on it
        $out .= '<div class="bkf-chip bkf-chip-f" draggable="true" data-kind="f" data-val="' . intval($f['id']) . '"'
            . ' style="--fc:' . bk_e($f['fg']) . ';--fb:' . bk_e($f['bg']) . ';--fd:' . bk_e($f['bd']) . '">';
        if (!empty($f['peg'])) {
            $out .= bk_piquet_svg($f['color'], 22)
                . '<span class="bkf-chip-txt"><span class="bkf-chip-main">' . bk_e($f['name']) . '</span></span>';
        } else {
            $out .= '<img class="bkf-face-ic" src="' . bk_e($CFG->ROOT_DIR . 'Common/Images/Targets/' . $f['svg']) . '"'
                . ' width="22" height="22" alt="" draggable="false"><span class="bkf-chip-txt">'
                . '<span class="bkf-chip-main">' . bk_e($f['cm'] ? $f['cm'] . ' cm' : bk_t('FldFace')) . '</span>'
                . ($f['name'] !== '' ? '<span class="bkf-chip-sub">' . bk_e($f['name']) . '</span>' : '') . '</span>';
        }
        $out .= '</div>';
    }
    $out .= '</div></div>'
        . '<div class="bkf-pcol bkf-help"><p>' . bk_t('FldHelp1') . '</p><p>' . bk_t('FldHelp2') . '</p><p>' . bk_t('FldHelp3') . '</p></div>'
        . '</aside>';

    // Field: toolbar, zoom, chart.
    $out .= '<section class="bkf-field"><div class="bkf-toolbar">'
        . '<span id="bkf-count" class="bkf-count">' . bk_e(bk_t('FjSel0')) . '</span> '
        . '<button type="button" class="bkf-btn" data-act="all">' . bk_e(bk_t('FldSelAll')) . '</button> '
        . '<button type="button" class="bkf-btn" data-act="none">' . bk_e(bk_t('FldSelNone')) . '</button> '
        . '<button type="button" class="bkf-btn" data-act="clearsel">' . bk_e(bk_t('FldClearSel')) . '</button>'
        . '<span class="bkf-sep"></span>';
    if (count($sessions) > 1) {
        $depOpts = '<option value="">' . bk_e(bk_t('FldDepPh')) . '</option>';
        foreach ($sessions as $s) {
            $o = intval($s->SesOrder);
            if ($o !== $ses) $depOpts .= '<option value="' . $o . '">' . bk_e(bk_t('DepCap', $o)) . '</option>';
        }
        $out .= '<label class="bkf-copy">' . bk_e(bk_t('FldCopyFrom')) . ' <select id="bkf-copyfrom">' . $depOpts . '</select></label> '
            . '<button type="button" class="bkf-btn" data-act="copyfrom" title="' . bk_e(bk_t('FldCopyFromTip')) . '">' . bk_e(bk_t('FldCopyFromBtn')) . '</button>'
            . '<span class="bkf-sep"></span>'
            . '<label class="bkf-copy">' . bk_e(bk_t('FldCopyTo')) . ' <select id="bkf-copyto">' . $depOpts . '</select></label> '
            . '<button type="button" class="bkf-btn" data-act="copy" title="' . bk_e(bk_t('FldCopyToTip')) . '">' . bk_e(bk_t('FldCopyToBtn')) . '</button>';
    }
    list($qSize, $hSize) = aut_adm_help(bk_e(bk_t('FldSizeHint')));
    $out .= '<span class="bkf-sep"></span>'
        . '<button type="button" class="bkf-btn bkf-btn-danger" data-act="clearall">' . bk_e(bk_t('FldClearAll')) . '</button>'
        . '<span id="bkf-state" class="bkf-state"></span></div>'
        . '<div class="bkf-zoom"><label>' . bk_e(bk_t('FldSize'))
        . ' <input type="range" id="bkf-size" min="34" max="110" value="56" step="2"></label>' . $qSize . $hSize . '</div>'
        . '<div class="bkf-plot"><div id="bkf-axis" class="bkf-axis"></div><div id="bkf-grid" class="bkf-grid"></div></div>'
        . '</section></div>';

    // Events of the departure, live: those it can take and those it cannot, individual and team
    // events in two tabs (individual first: user's choice).
    list($qEv, $hEv) = aut_adm_help(bk_e(bk_t('FldEvHint')));
    $col = function ($key, $list) {
        return '<div class="bkf-ev-col bkf-ev-' . $list . '"><h3>' . bk_e(bk_t($key)) . ' <span class="bkf-ev-n" data-n="' . $list . '"></span></h3>'
            . '<div class="bkf-ev-list" data-list="' . $list . '"></div></div>';
    };
    $out .= '<section class="bkf-events" id="bkf-events"><div class="bkf-ev-head"><h2>' . bk_e(bk_t('FldEvTitle')) . $qEv . '</h2>'
        . '<div class="bkf-ev-tabs" role="tablist">'
        . '<button type="button" class="bkf-ev-tab bkf-ev-tab-on" data-kind="ind" role="tab">' . bk_e(bk_t('FldEvInd')) . '</button>'
        . '<button type="button" class="bkf-ev-tab" data-kind="team" role="tab">' . bk_e(bk_t('FldEvTeam')) . '</button></div></div>'
        . $hEv . '<div class="bkf-ev-cols">' . $col('FldEvOk', 'ok') . $col('FldEvKo', 'ko') . '</div></section>';
}
$out .= '</div>' . aut_adm_close()
    . '<script>window.BKF = ' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>'
    . '<script src="' . $base . 'assets/field.js?v=' . bk_e(bk_version()) . '"></script>';
echo $out;
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
