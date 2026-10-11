<?php
/**
 * admin/index.php — settings of the points of sale (refreshment bar, food, shop) for the
 * competition currently open in ianseo: switch on, customer options, the points of sale
 * themselves, the public address with its QR code posters and table cards, and "copy from…"
 * another competition.
 *
 * ORGANISER page (computer): ianseo look and ACL of the core, frame shared by AUTH's organiser
 * pages (adm-lib.php). Settings and points of sale are ONE form with ONE save button, fixed at
 * the bottom right; a save goes back to the page (redirect after post) with its message.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/copy.php';
require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';
require_once dirname(__DIR__) . '/lib/staff-session.php';   // the organiser's till on this device
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_qr_svg
if (is_file(dirname(__DIR__, 2) . '/trust-lib.php')) require_once dirname(__DIR__, 2) . '/trust-lib.php';   // aut_trust_mode

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
    } elseif ($act === 'save' && !isset($_POST['form_end'])) {
        $errors[] = shp_t('ShSetErrTruncated');   // fields beyond max_input_vars are dropped silently
    } elseif ($act === 'save' && intval($_POST['delete_stand'] ?? 0) > 0) {
        // A deletion applies to that point of sale only; nothing else of the form is saved.
        $r = shp_stand_delete($TOUR, intval($_POST['delete_stand']));
        shp_adm_flash_set($r['error'] ? 'err' : 'ok', array($r['msg']));
        header('Location: ' . $SELF . '#stands');
        exit;
    } elseif ($act === 'save') {
        // Settings checked first (nothing written), then the points of sale (checked as a whole
        // before any write): one refusal and nothing is saved.
        $d = array('guests' => !empty($_POST['guests']), 'tab' => !empty($_POST['tab']), 'trust_gate' => !empty($_POST['trust_gate']));
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
        $standRows = (array) ($_POST['stand'] ?? array());
        if (!$errors) {
            $r = shp_stands_save($TOUR, $standRows);
            $errors = $r['errors'];
        }
        if ($errors) {
            $settingsIn = $d;
        } else {
            shp_settings_save($TOUR, $d);
            shp_adm_flash_set('ok', array(shp_t('ShSetSaved')));
            header('Location: ' . $SELF);
            exit;
        }
    } elseif ($act === 'switch') {
        // The openings, one step at a time (user's choice, 2026-10-12). A way for the customers to
        // order (pre-orders, phone orders on the day) opens the volunteers too — they serve those
        // orders; closing the volunteers closes those ways as well. "On this device": the
        // organiser's till, whatever is open.
        $what = (string) ($_POST['what'] ?? '');
        $s0 = shp_settings($TOUR);
        $vol0 = $s0 && intval($s0->SgEnabled) === 1;
        $pre0 = $vol0 && intval($s0->SgPreorders ?? 0) === 1;
        $cli0 = $vol0 && intval($s0->SgClients ?? 0) === 1;
        $w0 = shp_window($TOUR);
        $lines = array();
        if ($what === 'till') {
            $me = ($w0 && !$w0['purge_due']) ? shp_staff_organiser($TOUR, shp_staff_by()) : null;
            if ($me) {
                shp_staff_session_open(intval($me->SfId));
                header('Location: ' . shp_url('staff/index.php'));
                exit;
            }
            shp_adm_flash_set('err', array(shp_t($w0 && !$w0['purge_due'] ? 'ShErrInternal' : 'ShStfErrOver')));
        } elseif (in_array($what, array('vol_on', 'pre_on', 'cli_on'), true) && !shp_stands($TOUR, false)) {
            shp_adm_flash_set('err', array(shp_t('ShSetNoStandYet')));
        } else {
            $open = array(
                'vol_on'  => array(array('enabled' => 1), 'ShSetVolOpened'),
                'vol_off' => array(array('enabled' => 0, 'preorders' => 0, 'clients' => 0), 'ShSetVolClosed'),
                'pre_on'  => array(array('preorders' => 1, 'enabled' => 1), 'ShSetPreOpened'),
                'pre_off' => array(array('preorders' => 0), 'ShSetPreClosed'),
                'cli_on'  => array(array('clients' => 1, 'enabled' => 1), 'ShSetCliOpened'),
                'cli_off' => array(array('clients' => 0), 'ShSetCliClosed'),
            );
            if (isset($open[$what])) {
                shp_settings_save($TOUR, $open[$what][0]);
                $lines[] = shp_t($open[$what][1]);
                if (($what === 'pre_on' || $what === 'cli_on') && !$vol0) $lines[] = shp_t('ShSetVolAlso');
                if ($what === 'vol_off' && ($pre0 || $cli0)) $lines[] = shp_t('ShSetVolClosedAll');
                shp_adm_flash_set('ok', $lines);
            }
        }
        header('Location: ' . $SELF . '#settings');
        exit;
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

/* ---------------- Opening, step by step ---------------- */

// What is open (user's choice, 2026-10-12): the pre-orders (archers' space, before the
// competition), the customers' phone orders on the day, and the volunteers (their till: the
// sales at the stand, and they serve the two others) — each way for the customers needs the
// volunteers. The organiser's till on their own device is always there.
$volOn = intval($set->SgEnabled) === 1;
$preOn = $volOn && intval($set->SgPreorders ?? 0) === 1;
$cliOn = $volOn && intval($set->SgClients ?? 0) === 1;
$over = !$win || $win['purge_due'];
$guests = $settingsIn !== null ? !empty($settingsIn['guests']) : intval($set->SgGuests) === 1;
$tab = $settingsIn !== null ? !empty($settingsIn['tab']) : intval($set->SgTab) === 1;
$trust = $settingsIn !== null ? !empty($settingsIn['trust_gate']) : intval($set->SgTrustGate) === 1;
$until = $settingsIn !== null ? (string) $settingsIn['preorder_until'] : shp_adm_datetime($set->SgPreorderUntil);
$gmax = $settingsIn !== null ? intval($settingsIn['guest_max_open']) : intval($set->SgGuestMaxOpen);
$notice = $settingsIn !== null ? (string) $settingsIn['notice'] : (string) $set->SgNotice;

$trustLive = function_exists('aut_trust_mode') && in_array(aut_trust_mode(), array('alert', 'block'), true);
$period = $win && $win['from'] > '0000-00-00'
    ? shp_t('ShSetPeriod', array('from' => shp_adm_date($win['from']), 'to' => shp_adm_date($win['to'])))
    : '';

// One opening: its state and its explanation on demand (same look as the desk); its buttons go in
// a bar of their own.
$switch = function ($on, $title, $help, $tagOn, $tagOff, $area = '') {
    list($q, $h) = aut_adm_help($help);
    return '<div class="sa-switch' . ($on ? ' sa-on' : '') . '"' . ($area !== '' ? ' style="grid-area:' . $area . '"' : '') . '><div><b>'
        . shp_e($title) . '</b>' . $q . $h . '</div><span class="sa-tag">' . shp_e($on ? $tagOn : $tagOff) . '</span></div>';
};
// The buttons send their own small form (#shop-switch), never the settings typed on the page.
$sw = function ($what, $label, $primary, $confirm = '') {
    return '<button type="submit" form="shop-switch" name="what" value="' . shp_e($what) . '" class="sa-btn' . ($primary ? ' sa-primary' : '') . '"'
        . ($confirm !== '' ? ' data-confirm="' . shp_e($confirm) . '"' : '') . '>' . shp_e(shp_t($label)) . '</button>';
};

$tillUrl = shp_till_url($TOUR);
list($qUntil, $hUntil) = aut_adm_help(shp_e(shp_t('ShSetPreorderHint') . ($period !== '' ? ' ' . $period : '')));
$settingsForm = '<div class="sa-card" id="settings">' . aut_adm_title('h2', shp_t('ShSetSectSwitch'))
    . ($stands ? '' : '<p class="sa-msg sa-info">' . shp_e(shp_t('ShSetNoStandYet')) . '</p>')
    // The two ways for the customers to order, side by side (user's choice: height saved) —
    // pre-orders on the left with their deadline, phone orders on the day on the right; one
    // under the other on a narrow screen (admin.css, .sa-pair).
    . '<div class="sa-pair">'
    . $switch($preOn, shp_t('ShSetPreTitle'), shp_e(shp_t('ShSetPreHelp')), shp_t('ShSetPreTagOn'), shp_t('ShSetPreTagOff'), 'ls')
    . $switch($cliOn, shp_t('ShSetEnable'), shp_e(shp_t('ShSetEnableWhat')), shp_t('ShSetCliTagOn'), shp_t('ShSetCliTagOff'), 'rs')
    // The deadline of the pre-orders right of their button (user's choice: height saved); its
    // explanation unfolds under both, across the bar.
    . '<div class="sa-bar" style="grid-area:lb">' . $sw($preOn ? 'pre_off' : 'pre_on', $preOn ? 'ShSetPreCloseBtn' : 'ShSetPreOpenBtn', false)
    . '<label class="sa-inline-f"><span>' . shp_e(shp_t('ShSetPreorder')) . $qUntil . '</span>'
    . '<input type="datetime-local" name="preorder_until" value="' . shp_e($until) . '"></label>' . $hUntil . '</div>'
    . '<div class="sa-bar" style="grid-area:rb">' . $sw($cliOn ? 'cli_off' : 'cli_on', $cliOn ? 'ShSetCliCloseBtn' : 'ShSetCliOpenBtn', false) . '</div>'
    . ($preOn && $win && !$win['preorder_open'] ? '<p class="sa-hint" style="grid-area:lx">' . shp_e(shp_t('ShSetPreOver')) . '</p>' : '')
    . '</div>'
    // The volunteers under them, with every button of the stands in one bar (as at the desk).
    . $switch($volOn, shp_t('ShSetVolTitle'), shp_e(shp_t('ShSetVolHelp')), shp_t('DkOn'), shp_t('DkOff'))
    . '<div class="sa-bar">'
    . $sw($volOn ? 'vol_off' : 'vol_on', $volOn ? 'ShSetVolCloseBtn' : 'ShSetVolOpenBtn', false,
        $volOn && ($preOn || $cliOn) ? shp_t('ShSetVolCloseConfirm') : '')
    . ($over ? '' : $sw('till', 'ShSetUseHere', true))
    . '<a class="sa-btn" href="' . shp_e(shp_adm_url('staff.php')) . '">' . shp_e(shp_t('DkVolunteers')) . '</a></div>'
    . ($volOn && $tillUrl !== ''
        ? '<details class="sa-access"><summary>' . shp_e(shp_t('DkAddressTitle')) . '</summary><div class="sa-address">'
          . '<div class="sa-qr">' . aut_qr_svg($tillUrl, 180) . '</div><div class="sa-address-text"><p>' . shp_e(shp_t('ShSetVolAccessHint')) . '</p>'
          . '<input type="text" class="sa-url" readonly value="' . shp_e($tillUrl) . '" data-select aria-label="' . shp_e(shp_t('DkAddressTitle')) . '"></div></div></details>'
        : '')
    . '</div>';

/* ---------------- Customers ---------------- */

// QR code posters (A4) and table cards (A6) leading to the shop: all the stands, or one.
$pdfLink = function ($kind, $standId, $label) {
    global $CFG;
    return '<a class="sa-pdf" target="_blank" href="' . shp_e(shp_adm_url('posters.php') . '?pdf=' . $kind . ($standId > 0 ? '&s=' . intval($standId) : '')) . '">'
        . '<img src="' . shp_e($CFG->ROOT_DIR . 'Common/Images/pdf_small.gif') . '" alt="" width="16" height="16"> ' . shp_e($label) . '</a>';
};
$posterRows = '<tr><td><b>' . shp_e(shp_t('ShSetPostersAll')) . '</b></td><td>' . $pdfLink('poster', 0, shp_t('ShSetPosterA4'))
    . '</td><td>' . $pdfLink('cards', 0, shp_t('ShSetCardsA6')) . '</td></tr>';
$onlineStands = 0;
foreach ($stands as $sid => $st) {
    if (intval($st->SdOnline) !== 1) continue;
    $onlineStands++;
    $posterRows .= '<tr><td>' . shp_e($st->SdName) . '</td><td>' . $pdfLink('poster', $sid, shp_t('ShSetPosterA4'))
        . '</td><td>' . $pdfLink('cards', $sid, shp_t('ShSetCardsA6')) . '</td></tr>';
}
$publicUrl = shp_abs_url('public/index.php?k=' . $set->SgPublicKey);
$customersForm = '<div class="sa-card" id="clients">' . aut_adm_title('h2', shp_t('ShSetSectCustomers'))
    . '<div class="sa-checks">'
    . shp_adm_check('guests', $guests, shp_t('ShSetGuests'), shp_t('ShSetGuestsHint'))
    . shp_adm_check('tab', $tab, shp_t('ShSetTab'), shp_t('ShSetTabHint'))
    // The payer trust index only where the server administrator switched it on (alert or block):
    // off or only observed, the box would promise nothing — its value is kept as it is.
    . ($trustLive ? shp_adm_check('trust_gate', $trust, shp_t('ShSetTrust'), shp_t('ShSetTrustHint'))
        : ($trust ? '<input type="hidden" name="trust_gate" value="1">' : ''))
    . '</div>'
    . '<div class="sa-row">'
    . shp_adm_field(shp_t('ShSetGuestMax'), '<input type="number" name="guest_max_open" min="0" max="20" value="' . $gmax . '" class="sa-num">', shp_t('ShSetGuestMaxHint'))
    . '</div>'
    . shp_adm_field(shp_t('ShSetNotice'), '<input type="text" name="notice" maxlength="255" value="' . shp_e($notice) . '">', shp_t('ShSetNoticeHint'), 'sa-wide')
    // The customers' address, its QR code and the printed posters (the new key: its own form).
    . '<details class="sa-access" id="address"><summary>' . shp_e(shp_t('ShSetSectAddress')) . '</summary>'
    . (!$preOn && !$cliOn ? '<p class="sa-hint">' . shp_e(shp_t('ShSetAddressOff')) . '</p>' : '')
    . '<div class="sa-address"><div class="sa-qr">' . aut_qr_svg($publicUrl, 180) . '</div><div class="sa-address-text">'
    . '<p>' . shp_e(shp_t('ShSetAddressHint')) . '</p>'
    . '<input type="text" readonly value="' . shp_e($publicUrl) . '" class="sa-url" data-select aria-label="' . shp_e(shp_t('ShSetSectAddress')) . '">'
    . '<div class="sa-bar"><a class="sa-btn" href="' . shp_e($publicUrl) . '" target="_blank" rel="noopener">' . shp_e(shp_t('ShSetAddressOpen')) . '</a>'
    . '<button type="submit" form="shop-newkey" class="sa-btn sa-danger" data-confirm="' . shp_e(shp_t('ShSetNewKeyConfirm')) . '">'
    . shp_e(shp_t('ShSetNewKey')) . '</button></div></div></div>'
    . aut_adm_title('h3', shp_t('ShSetPostersTitle'), shp_e(shp_t('ShSetPostersLead')) . '<br>' . shp_e(shp_t('ShSetPostersHint')))
    . '<table class="sa-table sa-posters"><thead><tr><th>' . shp_e(shp_t('ShSetPostersFor')) . '</th><th>' . shp_e(shp_t('ShSetPosterA4'))
    . '</th><th>' . shp_e(shp_t('ShSetCardsA6')) . '</th></tr></thead><tbody>' . $posterRows . '</tbody></table>'
    . ($onlineStands ? '' : '<p class="sa-hint">' . shp_e(shp_t('ShSetPostersNoStand')) . '</p>')
    . '</details></div>';

$standsForm = '<div class="sa-card" id="stands">'
    . aut_adm_title('h2', shp_t('ShSetSectStands'), shp_e(shp_t('ShSetStandsHint')))
    . '<div class="sa-scroll"><table class="sa-table sa-stands"><thead><tr>' . $standHead . '<th></th></tr></thead>'
    . '<tbody id="stand-rows" data-next="' . $nextIndex . '">' . $rowsHtml . '</tbody></table></div>'
    . '<template id="stand-tpl"><table><tbody>' . shp_adm_stand_row('__i__', array(), false) . '</tbody></table></template>'
    . '<div class="sa-bar"><button type="button" class="sa-btn" data-add="stand-tpl" data-into="stand-rows">' . shp_e(shp_t('ShSetAddStand')) . '</button></div></div>';

// Settings, customers and points of sale: one form, one save button (fixed at the bottom right).
// The openings and the new key send forms of their own, outside it.
$mainForm = '<form method="post" id="shopset">' . aut_adm_default_submit() . bk_csrf_field() . '<input type="hidden" name="act" value="save">'
    . $settingsForm . $customersForm . $standsForm . '<input type="hidden" name="form_end" value="1"></form>'
    . '<form method="post" id="shop-switch">' . bk_csrf_field() . '<input type="hidden" name="act" value="switch"></form>'
    . '<form method="post" id="shop-newkey">' . bk_csrf_field() . '<input type="hidden" name="act" value="new_key"></form>';

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

echo aut_adm_open('shop/admin/index.php', shp_t('ShSetTitle'), $copyBox, '', shp_e(shp_t('ShSetLead')))
    . ($flash ? shp_adm_msg($flash['type'], $flash['lines']) : '')
    . ($errors ? shp_adm_msg('err', array_merge(array(shp_t('ShSetNothingSaved')), $errors)) : '')
    . $mainForm . aut_adm_save('shopset') . aut_adm_close();

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
