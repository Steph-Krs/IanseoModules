<?php
/**
 * booking/public/stats-import.php — the archer adds their federation record ("Mon palmarès" of
 * the extranet) to their statistics.
 *
 * The page walks through four steps, one picture each (assets/palmares/, simplified screens
 * with a made-up archer), opens the extranet in a new tab — the archer's own session, in their
 * own browser: this server never connects there — and reads the exported file IN THE BROWSER
 * (assets/palmares.js). Only the rows come back here.
 *
 * POST, CSRF on each: preview = what would be kept, nothing written; import = stored
 * (lib/palmares.php); both answer JSON. forget = everything imported removed (a plain form,
 * back to this page).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/stats.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $archer = bk_current_archer();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'forget') {
        if ($archer && bk_csrf_check()) {
            bk_pal_forget($archer->BaLicence);
            bk_log('pal_forget', $archer->BaLicence);
        }
        bk_redirect('stats-import.php?gone=1');
    }
    if (!$archer || !bk_csrf_check()) JsonOut(array('error' => 1, 'msg' => bk_t('PalErrSession')));
    $lic = $archer->BaLicence;
    // Each try is logged: a burst of them from one account or one address is cut short.
    if (bk_too_many(array('pal_preview', 'pal_import'), 40, $lic)) JsonOut(array('error' => 1, 'msg' => bk_t('PalErrTooMany')));
    $chk = bk_pal_check((string) ($_POST['data'] ?? ''), $lic);
    if (isset($chk['error'])) JsonOut(array('error' => 1, 'msg' => bk_t($chk['error'], $chk['lic'] ?? null)));
    $rounds = bk_pal_dedupe($chk['rows']);
    if (!$rounds) JsonOut(array('error' => 1, 'msg' => bk_t('PalErrEmpty')));
    if ($action === 'preview') {
        bk_log('pal_preview', $lic);
        JsonOut(array('error' => 0, 'msg' => '', 'skipped' => $chk['skipped'],
            'preview' => bk_pal_preview($rounds, bk_stats_local_rounds($lic), bk_pal_birth_year($lic))));
    }
    if ($action === 'import') {
        bk_log('pal_import', $lic);
        $n = bk_pal_store($lic, $rounds);
        JsonOut(array('error' => 0, 'msg' => '', 'next' => bk_public_url('stats.php?pal=' . $n)));
    }
    JsonOut(array('error' => 1, 'msg' => bk_t('PalErrFormat')));
}

$archer = bk_require_archer();
$pal = bk_pal_status($archer->BaLicence);
$v = '?v=' . rawurlencode(bk_version());
$pic = function ($n) use ($v) {
    return '<img class="bk-pal-img" src="' . bk_e(bk_public_url('assets/palmares/step-' . $n . '.svg') . $v) . '" alt="'
        . bk_e(bk_t('PalImg' . $n)) . '" width="480" height="270" loading="lazy">';
};
// $wide: under the text and the picture, the whole width (the file and the preview of step 4).
$step = function ($n, $body, $wide = '') use ($pic) {
    return '<li class="bk-pal-step"><div class="bk-pal-txt"><h2><span class="n" aria-hidden="true">' . $n . '</span>'
        . bk_e(bk_t('PalStep' . $n)) . '</h2>' . $body . '</div><div class="bk-pal-pic">' . $pic($n) . '</div>'
        . ($wide !== '' ? '<div class="bk-pal-wide">' . $wide . '</div>' : '') . '</li>';
};
$p = function ($key, $cls = '') { return '<p' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . bk_e(bk_t($key)) . '</p>'; };

bk_head(bk_t('PalTitle'));
$out = '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/stats.css') . $v) . '">'
    . '<a class="bk-sx-back" href="' . bk_e(bk_public_url('stats.php')) . '">← ' . bk_e(bk_t('NavStatsTitle')) . '</a>'
    . '<h1 class="bk-pal-h1">' . bk_e(bk_t('PalTitle')) . '</h1>'
    . $p('PalIntro', 'bk-pal-intro')
    . (isset($_GET['gone']) ? bk_msg('ok', bk_t('PalGone')) : '');

if ($pal) {
    $out .= '<div class="bk-pal-have"><span>' . bk_e(bk_t('PalStatus', array('n' => $pal['n'], 'first' => $pal['first'],
            'last' => $pal['last'], 'when' => bk_date_fr(substr($pal['when'], 0, 10))))) . '</span>'   // bytes: ASCII date
        . '<form method="post" onsubmit="return confirm(' . bk_e(json_encode(bk_t('PalForgetConfirm'), JSON_UNESCAPED_UNICODE)) . ')">' . bk_csrf_field()
        . '<input type="hidden" name="action" value="forget"><button type="submit" class="bk-btn bk-btn-sm bk-btn-danger">'
        . bk_e(bk_t('PalForget')) . '</button></form></div>';
}

// The extranet opens in another tab (this one stays), without telling it where the archer came from.
$open = '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_pal_extranet_url()) . '" target="_blank" rel="noopener noreferrer">'
    . bk_e(bk_t('PalOpenExtranet')) . ' ↗</a></p>';
$cfg = array('url' => bk_public_url('stats-import.php'), 'csrf' => bk_csrf_token(), 't' => bk_ts(array(
    'PalReading', 'PalSaving', 'PalSummary', 'PalThSeason', 'PalThHeading', 'PalThRounds', 'PalThPlace', 'PalKnown',
    'PalConfSure', 'PalConfMaybe', 'PalConfNone', 'PalConfNote', 'PalDoImport', 'PalSkipped', 'PalErrXls', 'PalErrNotXlsx',
    'PalErrBrowser', 'PalErrEmpty', 'PalErrNetwork')));
$file = '<div id="bk-pal" data-cfg="' . bk_e(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
    . '<label class="bk-pal-drop"><span>' . bk_e(bk_t('PalChooseFile')) . '</span>'
    . '<input type="file" id="bk-pal-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>'
    . '<p id="bk-pal-msg" class="bk-pal-msg" role="status" hidden></p>'
    . '<div id="bk-pal-preview" class="bk-pal-preview" hidden></div></div>';

$out .= '<ol class="bk-pal-steps">'
    . $step(1, $p('PalStep1Text') . $open . $p('PalStep1Hint', 'bk-hint'))
    . $step(2, $p('PalStep2Text') . $p('PalStep2Hint', 'bk-hint'))
    . $step(3, $p('PalStep3Text'))
    . $step(4, $p('PalStep4Text'), $file)
    . '</ol>'
    . '<div class="bk-pal-about"><h2>' . bk_e(bk_t('PalHowTitle')) . '</h2>' . $p('PalHow')
    . '<h2>' . bk_e(bk_t('PalPrivacyTitle')) . '</h2><ul>';
foreach (array(1, 2, 3, 4) as $i) $out .= '<li>' . bk_e(bk_t('PalPrivacy' . $i)) . '</li>';
$out .= '</ul></div>'
    . '<script src="' . bk_e(bk_public_url('assets/palmares.js') . $v) . '"></script>';
echo $out;
bk_foot();
