<?php
/**
 * admin/ajax-field.php — saves the field capabilities.
 *
 * Same guards as the page: open competition, right on the targets, anti-CSRF token. An AJAX
 * endpoint is no less exposed than a page.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pTarget', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/caps.php';
require_once dirname(__DIR__) . '/lib/archer.php';

bk_schema();

if (!bk_csrf_check()) JsonOut(array('ok' => false, 'err' => bk_t('AdmBadToken')));

$TOUR = intval($_SESSION['TourId']);
$act  = (string) ($_POST['action'] ?? '');
$ses  = intval($_POST['session'] ?? 0);

// The departure must exist on THIS competition (never an arbitrary number).
$valid = false;
$capacity = array();
foreach (bk_comp_sessions($TOUR) as $s) {
    $o = intval($s->SesOrder);
    if ($o === $ses) $valid = true;
    $first = intval($s->SesFirstTarget) ?: 1;
    $capacity[$o] = array($first, $first + intval($s->SesTar4Session) - 1);
}
if (!$valid) JsonOut(array('ok' => false, 'err' => bk_t('AdmUnknownDep')));

if ($act === 'set') {
    $targets = array_map('intval', (array) ($_POST['targets'] ?? array()));
    $f = array_map('intval', (array) ($_POST['f'] ?? array()));

    // The faces must exist on the competition — never what the browser sends. Distances are
    // bounded integers (a range is not limited to the declared distances: a target may be set
    // beyond).
    $okF = array_keys(bk_caps_faces($TOUR));
    $f = array_values(array_intersect($f, $okF));

    $borne = function ($v) { return max(0, min(500, intval($v))); };
    $def = $borne($_POST['def'] ?? 0);
    $dmin = $borne($_POST['min'] ?? 0);
    $dmax = $borne($_POST['max'] ?? 0);

    list($tmin, $tmax) = $capacity[$ses];
    $n = 0;
    foreach ($targets as $t) {
        if ($t < $tmin || $t > $tmax) continue;
        bk_caps_set($TOUR, $ses, $t, $def, $dmin, $dmax, $f);
        $n++;
    }
    JsonOut(array('ok' => true, 'n' => $n, 'caps' => (object) bk_caps_get($TOUR, $ses)));
}

if ($act === 'clear') {
    bk_caps_clear($TOUR, $ses);
    JsonOut(array('ok' => true, 'caps' => (object) array()));
}

if ($act === 'copy') {
    $to = intval($_POST['to'] ?? 0);
    if (!isset($capacity[$to]) || $to === $ses) {
        JsonOut(array('ok' => false, 'err' => bk_t('AdmBadDestDep')));
    }
    bk_caps_copy($TOUR, $ses, $to);
    JsonOut(array('ok' => true, 'msg' => bk_t('AdmCapsCopiedTo', $to)));
}

if ($act === 'copyfrom') {
    // Takes the settings of a SOURCE departure onto the CURRENT one ($ses).
    $from = intval($_POST['from'] ?? 0);
    if (!isset($capacity[$from]) || $from === $ses) {
        JsonOut(array('ok' => false, 'err' => bk_t('AdmBadSrcDep')));
    }
    bk_caps_copy($TOUR, $from, $ses);
    // capabilities of the current departure sent back → the grid refreshes at once
    JsonOut(array('ok' => true, 'msg' => bk_t('AdmCapsCopiedFrom', $from),
        'caps' => (object) bk_caps_get($TOUR, $ses)));
}

JsonOut(array('ok' => false, 'err' => bk_t('AdmUnknownAction')));
