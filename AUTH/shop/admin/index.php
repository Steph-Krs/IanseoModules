<?php
/**
 * admin/index.php — settings of the points of sale (refreshment bar, food, shop) for the
 * competition currently open in ianseo: switch on, customer options, the points of sale
 * themselves, the public address, and "copy from…" another competition.
 *
 * ORGANISER page (desktop first): ianseo look and ACL of the core. Every save goes back to the
 * page (redirect after post) with its message kept in the session.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/copy.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_qr_svg

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$SELF = shp_adm_url('index.php');
$errors = array();          // texts of a refused form
$standRows = null;          // lines of the points of sale form as typed, when it was refused
$settingsIn = null;         // settings as typed, when refused

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!bk_csrf_check()) {
        $errors[] = shp_t('ShSetErrSession');
    } elseif ($act === 'save_settings') {
        $d = array('enabled' => !empty($_POST['enabled']), 'guests' => !empty($_POST['guests']),
            'tab' => !empty($_POST['tab']), 'trust_gate' => !empty($_POST['trust_gate']));
        $until = trim((string) ($_POST['preorder_until'] ?? ''));
        if ($until !== '') {
            $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $until, $m)
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60;
            if (!$ok) $errors[] = shp_t('ShSetErrPreorder');
        }
        $max = shp_int_parse($_POST['guest_max_open'] ?? '', 0, 20);
        if ($max === false || $max === null) $errors[] = shp_t('ShSetErrGuestMax');
        $notice = shp_text_clean($_POST['notice'] ?? '', 255);
        $d['preorder_until'] = $until;
        $d['guest_max_open'] = intval($max);
        $d['notice'] = $notice;
        if ($errors) {
            $settingsIn = $d;
        } else {
            shp_settings_save($TOUR, $d);
            shp_adm_flash_set('ok', array(shp_t('ShSetSaved')));
            header('Location: ' . $SELF);
            exit;
        }
    } elseif ($act === 'save_stands' && !isset($_POST['form_end'])) {
        $errors[] = shp_t('ShSetErrTruncated');   // fields beyond max_input_vars are dropped silently
    } elseif ($act === 'save_stands') {
        $standRows = (array) ($_POST['stand'] ?? array());
        $delId = intval($_POST['delete_stand'] ?? 0);
        if ($delId > 0) {
            // A deletion applies to that point of sale only; the other lines are not saved.
            $r = shp_stand_delete($TOUR, $delId);
            shp_adm_flash_set($r['error'] ? 'err' : 'ok', array($r['msg']));
            header('Location: ' . $SELF . '#stands');
            exit;
        }
        $r = shp_stands_save($TOUR, $standRows);
        if ($r['errors']) {
            $errors = $r['errors'];
        } else {
            shp_adm_flash_set('ok', array(shp_t('ShSetStandsSaved')));
            header('Location: ' . $SELF . '#stands');
            exit;
        }
    } elseif ($act === 'new_key') {
        shp_settings_new_key($TOUR);
        shp_adm_flash_set('ok', array(shp_t('ShSetNewKeyDone')));
        header('Location: ' . $SELF . '#address');
        exit;
    } elseif ($act === 'copy') {
        $srcId = bk_copy_is_admin()
            ? shp_copy_resolve($_POST['copy_src_text'] ?? '', $TOUR)
            : intval($_POST['copy_src'] ?? 0);
        if ($srcId > 0 && !shp_copy_allowed($srcId, $TOUR)) $srcId = 0;
        if ($srcId <= 0) {
            $errors[] = shp_t('ShSetCopyNoSrc');
        } else {
            $r = shp_copy_from($TOUR, $srcId);
            if ($r['error']) {
                $errors[] = $r['msg'];
            } else {
                shp_adm_flash_set('ok', array(shp_t('ShSetCopied', array('stands' => $r['stands'], 'products' => $r['products']))));
                header('Location: ' . $SELF);
                exit;
            }
        }
    }
}

$set = shp_settings_ensure($TOUR);
$win = shp_window($TOUR, true);
$stands = shp_stands($TOUR, false);
$flash = shp_adm_flash_take();
$cur = bk_currency($TOUR);

/* ---------------- Points of sale ---------------- */

/** One line of the points of sale (server rendering AND script template, index __i__). */
function shp_adm_stand_row($i, array $r, $hasOrders)
{
    $r = shp_stand_row_defaults($r);
    $n = 'stand[' . $i . ']';
    $in = function ($name, $value, $attrs = '') use ($n) {
        return '<input name="' . $n . '[' . $name . ']" value="' . shp_e($value) . '" ' . $attrs . '>';
    };
    $chk = function ($name, $on, $label) use ($n) {
        return '<input type="checkbox" name="' . $n . '[' . $name . ']" value="1"' . ($on ? ' checked' : '') . ' aria-label="' . shp_e($label) . '">';
    };
    $sel = function ($name, $opts, $cur, $label) use ($n) {
        return '<select name="' . $n . '[' . $name . ']" aria-label="' . shp_e($label) . '">' . shp_adm_options($opts, $cur) . '</select>';
    };
    $del = '';
    if (intval($r['id']) > 0) {
        $del = $hasOrders
            ? '<small class="sa-muted">' . shp_e(shp_t('ShSetStandHasOrders')) . '</small>'
            : '<button type="submit" name="delete_stand" value="' . intval($r['id']) . '" class="sa-btn sa-danger" formnovalidate'
                . ' data-confirm="' . shp_e(shp_t('ShSetStandDeleteConfirm', (string) $r['name'])) . '">' . shp_e(shp_t('ShSetDelete')) . '</button>';
    } else {
        $del = '<button type="button" class="sa-btn sa-danger" data-remove="tr">' . shp_e(shp_t('ShSetDelete')) . '</button>';
    }
    return '<tr data-row><td data-label="' . shp_e(shp_t('ShSetColKind')) . '"><input type="hidden" name="' . $n . '[id]" value="' . intval($r['id']) . '">'
        . $sel('kind', shp_stand_kinds(), $r['kind'], shp_t('ShSetColKind')) . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColName')) . '">' . $in('name', $r['name'], 'type="text" maxlength="60" class="sa-name" aria-label="' . shp_e(shp_t('ShSetColName')) . '"') . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColMode')) . '">' . $sel('mode', shp_stand_modes(), $r['mode'], shp_t('ShSetColMode')) . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColPay')) . '">' . $sel('paywhen', shp_stand_paywhens(), $r['paywhen'], shp_t('ShSetColPay')) . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColPhone')) . '" class="sa-c">' . $chk('online', $r['online'], shp_t('ShSetColPhone')) . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColPrep')) . '">' . $in('prepmin', $r['prepmin'], 'type="number" min="0" max="240" class="sa-num" aria-label="' . shp_e(shp_t('ShSetColPrep')) . '"') . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColParallel')) . '">' . $in('parallel', $r['parallel'], 'type="number" min="1" max="20" class="sa-num" aria-label="' . shp_e(shp_t('ShSetColParallel')) . '"') . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColLetter')) . '">' . $in('prefix', $r['prefix'], 'type="text" maxlength="1" class="sa-letter" data-letter aria-label="' . shp_e(shp_t('ShSetColLetter')) . '"') . '</td>'
        . '<td data-label="' . shp_e(shp_t('ShSetColActive')) . '" class="sa-c">' . $chk('active', $r['active'], shp_t('ShSetColActive')) . '</td>'
        . '<td class="sa-acts"><button type="button" class="sa-btn" data-move="-1" aria-label="' . shp_e(shp_t('ShSetMoveUp')) . '">' . shp_e(shp_t('ShSetUp')) . '</button> '
        . '<button type="button" class="sa-btn" data-move="1" aria-label="' . shp_e(shp_t('ShSetMoveDown')) . '">' . shp_e(shp_t('ShSetDown')) . '</button> ' . $del . '</td></tr>';
}

$rowsHtml = '';
$i = 0;
if ($standRows !== null) {
    foreach ($standRows as $r) {
        if (!is_array($r)) continue;
        $id = intval($r['id'] ?? 0);
        $rowsHtml .= shp_adm_stand_row($i++, $r, $id > 0 && shp_stand_has_orders($TOUR, $id));
    }
} else {
    foreach ($stands as $s) {
        $rowsHtml .= shp_adm_stand_row($i++, array('id' => $s->SdId, 'kind' => $s->SdKind, 'name' => $s->SdName,
            'mode' => $s->SdMode, 'paywhen' => $s->SdPayWhen, 'online' => $s->SdOnline, 'prepmin' => $s->SdPrepMin,
            'parallel' => $s->SdParallel, 'prefix' => $s->SdPrefix, 'active' => $s->SdActive), shp_stand_has_orders($TOUR, $s->SdId));
    }
}
$nextIndex = $i;
$standHead = '';
foreach (array('ShSetColKind', 'ShSetColName', 'ShSetColMode', 'ShSetColPay', 'ShSetColPhone', 'ShSetColPrep', 'ShSetColParallel', 'ShSetColLetter', 'ShSetColActive') as $k) {
    $standHead .= '<th title="' . shp_e(shp_t($k . 'Tip')) . '">' . shp_e(shp_t($k)) . '</th>';
}

/* ---------------- Settings block ---------------- */

$on = $settingsIn !== null ? !empty($settingsIn['enabled']) : intval($set->SgEnabled) === 1;
$guests = $settingsIn !== null ? !empty($settingsIn['guests']) : intval($set->SgGuests) === 1;
$tab = $settingsIn !== null ? !empty($settingsIn['tab']) : intval($set->SgTab) === 1;
$trust = $settingsIn !== null ? !empty($settingsIn['trust_gate']) : intval($set->SgTrustGate) === 1;
$until = $settingsIn !== null ? (string) $settingsIn['preorder_until'] : shp_adm_datetime($set->SgPreorderUntil);
$gmax = $settingsIn !== null ? intval($settingsIn['guest_max_open']) : intval($set->SgGuestMaxOpen);
$notice = $settingsIn !== null ? (string) $settingsIn['notice'] : (string) $set->SgNotice;

$period = $win && $win['from'] > '0000-00-00'
    ? shp_t('ShSetPeriod', array('from' => shp_adm_date($win['from']), 'to' => shp_adm_date($win['to'])))
    : '';

$settingsForm = '<form method="post" class="sa-card" id="settings">' . bk_csrf_field() . '<input type="hidden" name="act" value="save_settings">'
    . '<h2>' . shp_e(shp_t('ShSetSectSwitch')) . '</h2>'
    . '<div class="sa-switch ' . ($on ? 'sa-on' : 'sa-off') . '">'
    . shp_adm_check('enabled', $on, shp_t('ShSetEnable'), shp_t('ShSetEnableWhat'))
    . '<span class="sa-tag">' . shp_e(shp_t($on ? 'ShSetOn' : 'ShSetOff')) . '</span></div>'
    . ($stands ? '' : '<p class="sa-hint">' . shp_e(shp_t('ShSetNoStandYet')) . '</p>')
    . '<h2>' . shp_e(shp_t('ShSetSectCustomers')) . '</h2>'
    . '<div class="sa-checks">'
    . shp_adm_check('guests', $guests, shp_t('ShSetGuests'), shp_t('ShSetGuestsHint'))
    . shp_adm_check('tab', $tab, shp_t('ShSetTab'), shp_t('ShSetTabHint'))
    . shp_adm_check('trust_gate', $trust, shp_t('ShSetTrust'), shp_t('ShSetTrustHint'))
    . '</div>'
    . '<div class="sa-row">'
    . shp_adm_field(shp_t('ShSetPreorder'), '<input type="datetime-local" name="preorder_until" value="' . shp_e($until) . '">',
        shp_t('ShSetPreorderHint') . ($period !== '' ? ' ' . $period : ''))
    . shp_adm_field(shp_t('ShSetGuestMax'), '<input type="number" name="guest_max_open" min="0" max="20" value="' . $gmax . '" class="sa-num">', shp_t('ShSetGuestMaxHint'))
    . '</div>'
    . shp_adm_field(shp_t('ShSetNotice'), '<input type="text" name="notice" maxlength="255" value="' . shp_e($notice) . '">', shp_t('ShSetNoticeHint'), 'sa-wide')
    . '<div class="sa-bar"><button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t('ShSetSaveSettings')) . '</button></div></form>';

$standsForm = '<form method="post" class="sa-card" id="stands">' . bk_csrf_field() . '<input type="hidden" name="act" value="save_stands">'
    . '<h2>' . shp_e(shp_t('ShSetSectStands')) . '</h2><p class="sa-hint">' . shp_e(shp_t('ShSetStandsHint')) . '</p>'
    . '<div class="sa-scroll"><table class="sa-table sa-stands"><thead><tr>' . $standHead . '<th></th></tr></thead>'
    . '<tbody id="stand-rows" data-next="' . $nextIndex . '">' . $rowsHtml . '</tbody></table></div>'
    . '<template id="stand-tpl"><table><tbody>' . shp_adm_stand_row('__i__', array(), false) . '</tbody></table></template>'
    . '<input type="hidden" name="form_end" value="1">'
    . '<div class="sa-bar"><button type="button" class="sa-btn" data-add="stand-tpl" data-into="stand-rows">' . shp_e(shp_t('ShSetAddStand')) . '</button> '
    . '<button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t('ShSetSaveStands')) . '</button></div></form>';

/* ---------------- Public address ---------------- */

$publicUrl = shp_abs_url('public/index.php?k=' . $set->SgPublicKey);
$qr = aut_qr_svg($publicUrl, 180);
$addressBox = '<div class="sa-card" id="address"><h2>' . shp_e(shp_t('ShSetSectAddress')) . '</h2>'
    . '<p class="sa-hint">' . shp_e(shp_t($on ? 'ShSetAddressHint' : 'ShSetAddressOff')) . '</p>'
    . '<div class="sa-address"><div class="sa-qr">' . $qr . '</div><div class="sa-address-text">'
    . '<input type="text" readonly value="' . shp_e($publicUrl) . '" class="sa-url" data-select aria-label="' . shp_e(shp_t('ShSetSectAddress')) . '">'
    . '<p><a class="sa-btn" href="' . shp_e($publicUrl) . '" target="_blank" rel="noopener">' . shp_e(shp_t('ShSetAddressOpen')) . '</a></p>'
    . '<form method="post">' . bk_csrf_field() . '<input type="hidden" name="act" value="new_key">'
    . '<button type="submit" class="sa-btn sa-danger" data-confirm="' . shp_e(shp_t('ShSetNewKeyConfirm')) . '">' . shp_e(shp_t('ShSetNewKey')) . '</button></form>'
    . '</div></div></div>';

/* ---------------- Copy from… ---------------- */

if (bk_copy_is_admin()) {
    $copyBody = shp_adm_field(shp_t('ShSetCopyCode'), '<input type="text" name="copy_src_text" placeholder="' . shp_e(shp_t('ShSetCopyCodePh')) . '" autocomplete="off" required>');
} else {
    $sources = shp_copy_sources($TOUR);
    if (!$sources) {
        $copyBody = '<p class="sa-hint">' . shp_e(shp_t('ShSetCopyNone')) . '</p>';
    } else {
        $o = array('' => shp_t('ShSetChoose'));
        foreach ($sources as $s) $o[intval($s->ToId)] = $s->ToName . ' (' . $s->ToCode . ') — ' . shp_adm_date($s->ToWhenFrom);
        $copyBody = shp_adm_field(shp_t('ShSetCopySrc'), '<select name="copy_src" required>' . shp_adm_options($o, '') . '</select>');
    }
}
$copyBtn = (bk_copy_is_admin() || !empty($sources))
    ? '<button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t('ShSetCopyBtn')) . '</button>' : '';
$copyBox = '<details class="sa-copy"><summary>' . shp_e(shp_t('ShSetCopySummary')) . '</summary><div class="sa-copy-body">'
    . '<p class="sa-hint">' . shp_e(shp_t('ShSetCopyHint')) . '</p>'
    . '<form method="post" data-confirm="' . shp_e(shp_t('ShSetCopyConfirm')) . '">' . bk_csrf_field() . '<input type="hidden" name="act" value="copy">'
    . $copyBody . $copyBtn . '</form></div></details>';

/* ---------------- Page ---------------- */

$PAGE_TITLE = shp_t('MnuSettings') . ' — ' . shp_t('MnuTitle');
$JS_SCRIPT = shp_adm_assets();
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

echo '<div id="shpadm"><div class="sa-title"><h1>' . shp_e(shp_t('ShSetTitle')) . '</h1>' . $copyBox . '</div>'
    . shp_adm_nav('index.php')
    . '<p class="sa-lead">' . shp_e(shp_t('ShSetLead')) . '</p>'
    . ($flash ? shp_adm_msg($flash['type'], $flash['lines']) : '')
    . ($errors ? shp_adm_msg('err', array_merge(array(shp_t('ShSetNothingSaved')), $errors)) : '')
    . $settingsForm . $standsForm . $addressBox . '</div>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
