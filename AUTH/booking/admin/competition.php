<?php
/**
 * admin/competition.php — opening of the online registration for the competition currently
 * open in ianseo.
 *
 * ORGANISER page: unlike public/, it sits in the ianseo look and follows the core's ACL. Markup
 * produced in PHP; the two scripts at the end get their texts from the page.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/pricing.php';  // advanced tariff
require_once dirname(__DIR__) . '/lib/payment.php';  // means of payment
require_once dirname(__DIR__) . '/lib/mandate.php';  // bk_mandate_visible
require_once dirname(__DIR__) . '/lib/targets.php';  // bk_rules_check
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/adopt.php';    // bk_adopt_check (kept across a re-import)
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e
require_once dirname(__DIR__, 2) . '/shop/lib/lang.php';   // shp_t: name of the points of sale
require_once dirname(__DIR__) . '/lib/waitlist.php'; // waiting list
require_once dirname(__DIR__) . '/lib/sessionrules.php'; // opening of each departure
require_once dirname(__DIR__) . '/lib/ffta-event.php';    // what the FFTA extranet announces
require_once dirname(__DIR__) . '/lib/licences.php';      // licences missing from the federation file

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$msg  = '';
$err  = '';
bk_money_tour($TOUR);   // amounts of this page in the competition's currency
$SELF = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';
$ADMIN = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/';
$SHOP_ADMIN = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/admin/index.php';

// Publication on ianseo.net: Tournament.ToOnlineId is only set once the publication codes are
// obtained AND validated for THIS competition (ianseo core, Common/Lib/CommonLib.php →
// CheckCredentials). That is exactly what removes the "ask for the codes" block of
// Tournament/SetCredentials.php. Without codes there is no ianseo.net page: no use offering
// to paste its link.
$rOnline  = safe_fetch(safe_r_sql("SELECT ToOnlineId FROM Tournament WHERE ToId = $TOUR"));
$onlineId = $rOnline ? intval($rOnline->ToOnlineId) : 0;

// Kept across a re-import: when this competition is a newer version of one already followed
// by booking (same ToCode, different ToId), settings, payments, shop and registrations are
// brought back automatically. Does nothing (one indexed SELECT) otherwise. See lib/adopt.php.
$adoptReport = bk_adopt_check($TOUR);

/**
 * Detailed tariff rebuilt from the posted form, normalised: JSON for BcPricing, or '' when
 * only the base fee is used. Same form at level 3 and on a closed competition.
 */
function bk_adm_pricing_from_post($post)
{
    $num = function ($x) { return floatval(str_replace(',', '.', trim((string) $x))); };
    $pin = array(
        'categories' => array(), 'departures' => array(), 'rank' => array(),
        'prov' => array(
            'deptCode'   => $post['prov_deptcode'] ?? '',
            'regionCode' => $post['prov_regioncode'] ?? '',
            'dept'       => $num($post['prov_dept'] ?? 0),
            'region'     => $num($post['prov_region'] ?? 0),
        ),
    );
    foreach ((array) ($post['cat'] ?? array()) as $row) {
        if (!is_array($row)) continue;
        $price = trim((string) ($row['price'] ?? ''));
        $div   = array_values((array) ($row['div'] ?? array()));
        $cls   = array_values((array) ($row['cls'] ?? array()));
        if ($price === '' && !$div && !$cls) continue;     // empty rule ignored
        $pin['categories'][] = array('label' => $row['label'] ?? '', 'div' => $div, 'cls' => $cls, 'price' => $num($price));
    }
    foreach ((array) ($post['dep'] ?? array()) as $ord => $val) {
        $v = $num($val); if ($v != 0.0) $pin['departures'][(string) intval($ord)] = $v;
    }
    foreach ((array) ($post['rank'] ?? array()) as $th => $val) {
        $v = $num($val); if ($v != 0.0 && intval($th) >= 2) $pin['rank'][(string) intval($th)] = $v;
    }
    $norm = bk_pricing_norm($pin);
    return bk_pricing_is_advanced($norm) ? json_encode($norm) : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (isset($_POST['copy_from'])) {
        // "Copy from…": take the settings of another competition the user can reach.
        $srcId = bk_copy_is_admin()
            ? bk_copy_resolve($_POST['copy_src_text'] ?? '', $TOUR)
            : intval($_POST['copy_src'] ?? 0);
        // Not an admin: the source is checked again (the list shown is not proof).
        if (!bk_copy_is_admin() && $srcId > 0) {
            $chk = safe_fetch(safe_r_sql("SELECT ToId FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament
                WHERE ToId = $srcId AND ToId <> $TOUR AND " . bk_copy_access_where()));
            if (!$chk) $srcId = 0;
        }
        if ($srcId <= 0) {
            $err = bk_t('AcCopyNoSrc');
        } elseif (bk_comp_copy_from($TOUR, $srcId)) {
            header('Location: ' . $SELF . '?copied=1');
            exit;
        } else {
            $err = bk_t('AcCopyFailed');
        }
    } elseif (isset($_POST['set_level'])) {
        // 3-level bar: applies the transition (snapshot / automatic / restore), then reloads the
        // page (PRG) to show the chosen level.
        bk_comp_set_level($TOUR, intval($_POST['set_level']));
        header('Location: ' . $SELF);
        exit;
    } elseif (isset($_POST['wait_action'])) {
        // Waiting list, by hand: register now (even on a full departure) or remove.
        $wid = intval($_POST['w'] ?? 0);
        if ($_POST['wait_action'] === 'register') {
            $r = bk_waitlist_register_now($TOUR, $wid, intval($_POST['wait_session'] ?? 0));
            if (!empty($r['ok'])) $msg = bk_t('AcWaitRegistered');
            else $err = $r['msg'];
        } elseif ($_POST['wait_action'] === 'remove') {
            bk_waitlist_remove($TOUR, $wid);
            $msg = bk_t('AcWaitRemoved');
        }
    } elseif (isset($_POST['save_fee'])) {
        // Level 2 "simple publication": base fee only (without the advanced modulation).
        $fee = number_format((float) str_replace(',', '.', (string) ($_POST['fee'] ?? 0)), 2, '.', '');
        safe_w_sql("UPDATE BookingCompetitions SET BcFee = " . StrSafe_DB($fee) . " WHERE BcTournament = $TOUR");
        $msg = bk_t('AcFeeSaved');
    } elseif (isset($_POST['set_payments'])) {
        // Closed competition: use the payments and the shop (or stop showing them).
        safe_w_sql("INSERT INTO BookingCompetitions (BcTournament, BcPayments) VALUES ($TOUR, " . (empty($_POST['payments']) ? 0 : 1) . ")
            ON DUPLICATE KEY UPDATE BcPayments = VALUES(BcPayments)");
        header('Location: ' . $SELF);
        exit;
    } elseif (isset($_POST['save_payments'])) {
        // Closed competition using the payments: tariffs and payment methods only. Nothing
        // here opens the competition to the archers.
        $cur = bk_comp_config($TOUR);
        if (intval($cur->BcPublishLevel ?? 1) !== 1 || empty($cur->BcPayments)) {
            $err = bk_t('AcPayOffReload');
        } else {
            $pricingJson = bk_adm_pricing_from_post($_POST);
            $payJson = bk_payinfo_from_post($_POST['pay'] ?? array());
            bk_comp_set_effective($TOUR, array(
                'BcFee' => number_format((float) str_replace(',', '.', (string) ($_POST['fee'] ?? 0)), 2, '.', ''),
                'BcPricing' => $pricingJson === '' ? null : $pricingJson,
                'BcPayInfo' => $payJson === '' ? null : $payJson,
            ));
            $msg = bk_t('AcTariffsSaved');
        }
    } else {
        $kind = (string) ($_POST['kind'] ?? '');
        if (!array_key_exists($kind, bk_restrict_kinds())) $kind = '';
        $err = bk_scope_error($kind, $_POST['code'] ?? '');
        if ($err === '') {
            $pricingJson = bk_adm_pricing_from_post($_POST);

            // Placement rules: federation values, editable only overseas.
            $isDromPost = bk_is_dromtom(bk_org_agrement($TOUR));
            $save = array(
                'open'        => 1,   // level 3 = published (the bar drives the publication)
                'from'        => $_POST['from'] ?? '',
                'to'          => $_POST['to'] ?? '',
                'kind'        => $kind,
                'code'        => $_POST['code'] ?? '',
                'restrict_to' => $kind === '' ? '' : ($_POST['restrict_to'] ?? ''),
                'max_club'    => $isDromPost ? ($_POST['max_club'] ?? 2) : 2,
                'min_clubs'   => $isDromPost ? ($_POST['min_clubs'] ?? 3) : 3,
                'show_assign' => !empty($_POST['show_assign']),
                'show_gauges' => !empty($_POST['show_gauges']),
                'scoresheet'  => !empty($_POST['scoresheet']),
                'wish_letter' => !empty($_POST['wish_letter']),
                'wish_with'   => !empty($_POST['wish_with']),
                'wish_free'   => !empty($_POST['wish_free']),
                'manual_validation' => !empty($_POST['manual_validation']),
                'fee'         => $_POST['fee'] ?? 0,
                'pricing'     => $pricingJson,
                'payinfo'     => bk_payinfo_from_post($_POST['pay'] ?? array()),
                'docs_present'      => 1,
                'show_program'      => !empty($_POST['show_program']),
                'show_participants' => !empty($_POST['show_participants']),
                'show_results'      => !empty($_POST['show_results']),
                'show_dossard'      => !empty($_POST['show_dossard']),
            );
            // Mandate visibility: written only when the box was there (it only is when a mandate
            // exists) — keeps the three states.
            if (!empty($_POST['show_mandate_present'])) {
                $save['show_mandate'] = !empty($_POST['show_mandate']);
            }
            // Same for the ianseo.net link: the box is only shown when the competition has its
            // publication codes. Without this guard, every save made without the box would erase
            // the link. The ADDRESS itself is never posted: bk_comp_save() rebuilds it from
            // ToOnlineId.
            if (!empty($_POST['ianseo_present'])) {
                $save['ianseo_present'] = 1;
                $save['show_ianseo'] = !empty($_POST['show_ianseo']);
            }
            // Satisfaction survey: the checkbox only exists at level 3.
            if (!empty($_POST['survey_present'])) {
                $save['survey'] = !empty($_POST['survey']);
            }
            // Waiting list: same, level 3 only.
            if (!empty($_POST['waitlist_present'])) {
                $save['waitlist'] = !empty($_POST['waitlist']);
            }
            if (!empty($_POST['single_reg_present'])) {
                $save['single_reg'] = !empty($_POST['single_reg']);
            }
            bk_comp_save($TOUR, $save);
            // Opening of each departure (lib/sessionrules.php).
            if (!empty($_POST['ses_present']) && is_array($_POST['ses'] ?? null)) {
                bk_session_rules_save($TOUR, $_POST['ses']);
            }
            safe_w_sql("UPDATE BookingCompetitions SET BcPublishLevel = 3 WHERE BcTournament = $TOUR");
            $msg = bk_t('AcSaved');
        }
    }
}

/* Autosave: same POST, same validation, but the state is returned instead of the page.
   JsonOut() answers with "Access-Control-Allow-Origin: *", which exposes nothing here: a
   browser never lets another site read a response sent with the session cookie under "*". */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ajax'])) {
    JsonOut(array(
        'error' => ($err === '' ? 0 : 1),
        'msg'   => ($err !== '' ? $err : ($msg !== '' ? $msg : bk_t('AcSavedShort'))),
    ));
}

if (isset($_GET['copied'])) $msg = bk_t('AcCopied');

// Places freed in ianseo's own screens (a participant deleted, targets added) go to the
// waiting list as soon as the organiser comes back here; the cron catches the rest.
bk_waitlist_process($TOUR);
$waitList = bk_waitlist_of_tournament($TOUR);

$cfg      = bk_comp_config($TOUR);
$sessions = bk_comp_sessions($TOUR);
$level    = intval($cfg->BcPublishLevel ?? 1);          // 3-level bar
$copyAdmin  = bk_copy_is_admin();
$copySources = $copyAdmin ? array() : bk_copy_sources($TOUR);
$isDrom   = bk_is_dromtom(bk_org_agrement($TOUR));       // placement rules editable
$rules    = ($level >= 2) ? bk_rules_check($TOUR, $cfg) : array();
// Address given to the archers: the page of this competition in their space. Not signed in,
// they sign in first and come back to it (bk_require_archer / bk_next_after_login).
$publicUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://'
    . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/competition.php?t=' . $TOUR;

// Tariff: current settings + lists of categories for the editor.
$pricing = bk_pricing_get($cfg);
$payinfo = bk_payinfo_get($cfg);
$payByM = array();
foreach ($payinfo as $pi) $payByM[$pi['m']] = $pi;
$divs = array();
$rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions WHERE DivTournament = $TOUR ORDER BY DivId");
while ($r = safe_fetch($rs)) $divs[(string) $r->DivId] = $r->DivDescription ?: $r->DivId;
$classes = array();
$rs = safe_r_sql("SELECT ClId, ClDescription FROM Classes WHERE ClTournament = $TOUR ORDER BY ClId");
while ($r = safe_fetch($rs)) $classes[(string) $r->ClId] = $r->ClDescription ?: $r->ClId;
// Local codes suggested from the organiser's approval number while they are not set.
$orgc = preg_replace('/[^0-9A-Za-z]/', '', bk_org_agrement($TOUR));   // ASCII only: bytes are characters below
$provDeptDef   = $pricing['prov']['deptCode']   !== '' ? $pricing['prov']['deptCode']   : (strlen($orgc) >= 4 ? substr($orgc, 2, 2) : '');
$provRegionDef = $pricing['prov']['regionCode'] !== '' ? $pricing['prov']['regionCode'] : (strlen($orgc) >= 2 ? substr($orgc, 0, 2) : '');
$CUR = bk_currency($TOUR);

/** Value of a datetime-local field from a DATETIME column. */
function bk_dtval($v)
{
    $v = trim((string) $v);
    return $v === '' ? '' : str_replace(' ', 'T', substr($v, 0, 16));   // bytes: ASCII date
}

/** Options of a <select> with the selection (values = keys of the map). */
function bk_opts($map, $selected)
{
    $sel = array_flip(array_map('strval', (array) $selected));
    $h = '';
    foreach ($map as $k => $lab) {
        $h .= '<option value="' . bk_e($k) . '"' . (isset($sel[(string) $k]) ? ' selected' : '') . '>'
            . bk_e($lab) . '</option>';
    }
    return $h;
}

/** Amount in a text field (2 decimals, the language's separator); empty when null/''. */
function bk_amt($v)
{
    return ($v === '' || $v === null) ? '' : number_format((float) $v, 2, bk_number_seps()['dec'], '');
}

/** A labelled field: <label class="bk-f"><span>label</span>input</label>. */
function bk_fld($label, $input)
{
    return '<label class="bk-f"><span>' . bk_e($label) . '</span>' . $input . '</label>';
}

/** A checkbox line; $label is markup from the language file or escaped by the caller. */
/**
 * Level 3, "Registration period" block: opening of each departure — open, closed or opening
 * once the earlier ones are full — with dates of its own replacing the general ones.
 */
function bk_adm_session_rules($tourId, $cfg, $sessions)
{
    if (!$sessions) return '';
    $rules  = bk_session_rules($tourId);
    $states = bk_session_states($tourId, $cfg, $sessions);
    $labels = array(BK_SES_OPEN => bk_t('AcSesOpen'), BK_SES_CLOSED => bk_t('AcSesClosed'), BK_SES_AFTER_FULL => bk_t('AcSesAfterFull'));
    $h = '<h3 class="bk-h3">' . bk_e(bk_t('AcSesTitle')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcSesHint')) . '</p>'
        . '<input type="hidden" name="ses_present" value="1"><div class="bk-scroll"><table class="bk-t bk-sesrules"><tr><th>'
        . bk_e(bk_t('SsDeparture')) . '</th><th>' . bk_e(bk_t('AcSesState')) . '</th><th>' . bk_e(bk_t('AcOpenFrom')) . '</th><th>'
        . bk_e(bk_t('AcOpenTo')) . '</th><th>' . bk_e(bk_t('AcSesNow')) . '</th></tr>';
    foreach ($sessions as $s) {
        $o = intval($s->SesOrder);
        $r = $rules[$o] ?? null;
        $state = $r ? intval($r->BdState) : BK_SES_OPEN;
        $opts = '';
        foreach ($labels as $k => $lab) $opts .= '<option value="' . $k . '"' . ($state === $k ? ' selected' : '') . '>' . bk_e($lab) . '</option>';
        $st = $states[$o] ?? array('open' => false, 'why' => '');
        $start = bk_session_start($s);
        $h .= '<tr><td>' . bk_e(bk_t('DepCap', $o)) . ($s->SesName ? ' — ' . bk_e($s->SesName) : '')
            . ($start !== '' ? '<br><span class="bk-hint">' . bk_e(bk_date_fr($start)) . '</span>' : '') . '</td>'
            . '<td><select name="ses[' . $o . '][state]">' . $opts . '</select></td>'
            . '<td><input type="datetime-local" name="ses[' . $o . '][from]" value="' . bk_e(bk_dtval($r->BdOpenFrom ?? null)) . '"></td>'
            . '<td><input type="datetime-local" name="ses[' . $o . '][to]" value="' . bk_e(bk_dtval($r->BdOpenTo ?? null)) . '"></td>'
            . '<td>' . ($st['open'] ? '<span class="bk-ses-on">' . bk_e(bk_t('AcSesIsOpen')) . '</span>'
                : '<span class="bk-ses-off">' . bk_e(bk_session_state_text($st) ?: bk_t('AcSesIsClosed')) . '</span>') . '</td></tr>';
    }
    return $h . '</table></div>';
}

function bk_chk($name, $on, $label, $style = '')
{
    return '<label class="bk-chk"' . ($style !== '' ? ' style="' . $style . '"' : '') . '><input type="checkbox" name="' . $name
        . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
}

/** A form with one confirmation, hidden fields and a submit button. */
function bk_post_form($fields, $button, $confirm = '', $attr = '')
{
    $h = '<form method="post"' . $attr . ($confirm !== '' ? ' onsubmit="return confirm('
        . htmlspecialchars(json_encode($confirm, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')"' : '') . '>' . bk_csrf_field();
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . $k . '" value="' . bk_e($v) . '">';
    return $h . $button . '</form>';
}

/** One category rule (server rendering AND script template when $i is '__i__'). */
function bk_cat_row($i, $rule, $divs, $classes, $cur)
{
    $n = 'cat[' . $i . ']';
    return '<div class="bk-cat-row">'
        . bk_fld(bk_t('ColLabel'), '<input type="text" name="' . $n . '[label]" value="' . bk_e($rule['label'] ?? '') . '" placeholder="' . bk_e(bk_t('AcCatLabelPh')) . '">')
        . bk_fld(bk_t('AcCatBows'), '<select name="' . $n . '[div][]" multiple size="4">' . bk_opts($divs, $rule['div'] ?? array()) . '</select>')
        . bk_fld(bk_t('AcCatClasses'), '<select name="' . $n . '[cls][]" multiple size="4">' . bk_opts($classes, $rule['cls'] ?? array()) . '</select>')
        . bk_fld(bk_t('AshPrice', $cur), '<input type="text" name="' . $n . '[price]" size="6" value="' . bk_e(bk_amt($rule['price'] ?? '')) . '">')
        . '<button type="button" class="bk-cat-del" title="' . bk_e(bk_t('AcCatDel')) . '" aria-label="' . bk_e(bk_t('AcCatDel')) . '">✕</button></div>';
}

$PAGE_TITLE = bk_t('Brand');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm { max-width: 100%; }
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkadm label { display:inline-block; margin:6px 10px 6px 0; font-size:13px; }
#bkadm input[type=text], #bkadm input[type=number], #bkadm input[type=datetime-local], #bkadm select {
    padding:6px 8px; border:1px solid #d2d4d6; border-radius:6px; font-size:14px; }
#bkadm .bk-row { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; }
#bkadm .bk-f { display:flex; flex-direction:column; gap:3px; }
#bkadm .bk-f > span { font-size:12px; color:#7d8183; }
#bkadm .bk-chk { display:block; margin:5px 0; font-size:13px; }
#bkadm .bk-btn { padding:9px 18px; border:1px solid #0254a8; border-radius:6px;
    background:#0254a8; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
#bkadm .bk-btn:hover { background:#01367c; border-color:#01367c; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm .bk-hint { margin:6px 0 0; font-size:12px; color:#7d8183; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:5px 10px; text-align:left; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-scroll { overflow-x:auto; }
#bkadm table.bk-sesrules { margin:6px 0 4px; }
#bkadm table.bk-sesrules select, #bkadm table.bk-sesrules input { font-size:13px; }
#bkadm .bk-ses-on { color:#04ac0b; font-weight:700; }
#bkadm .bk-ses-off { color:#a86b00; }
#bkadm .bk-gauge { display:inline-block; width:130px; height:9px; background:#e9ecef;
    border-radius:5px; overflow:hidden; vertical-align:middle; margin-right:7px; }
#bkadm .bk-gauge i { display:block; height:100%; background:#0254a8; }
#bkadm .bk-url { font-family:monospace; font-size:12px; background:#f0f4ff;
    border:1px solid #a7d6ff; border-radius:5px; padding:3px 7px; }
#bkadm .bk-adv > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:6px 0 10px; }
#bkadm .bk-adv h3 { font-size:13px; color:#01367c; margin:16px 0 4px; }
#bkadm .bk-cat-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
    border:1px solid #e2e6ee; border-radius:6px; padding:8px 10px; margin:0 0 8px; }
#bkadm .bk-cat-row select[multiple] { min-width:150px; padding:2px 4px; }
#bkadm .bk-cat-del { border:1px solid #d2d4d6; background:#fff; color:#c0392b; border-radius:6px;
    padding:6px 10px; cursor:pointer; font-size:13px; align-self:center; }
#bkadm .bk-cat-del:hover { background:#ffd6db; }
#bkadm .bk-add { background:#f0f4ff; color:#0254a8; border-color:#a7d6ff; margin:2px 0 4px; }
#bkadm .bk-sim-out { margin-top:10px; padding:10px 12px; border:1px solid #a7d6ff; border-radius:8px;
    background:#f7faff; max-width:340px; }
#bkadm .bk-sim-t { width:100%; border-collapse:collapse; font-size:13px; }
#bkadm .bk-sim-t td { padding:3px 0; border:0; }
#bkadm .bk-sim-t td:last-child { text-align:right; white-space:nowrap; }
#bkadm .bk-sim-tot { margin:8px 0 0; padding-top:8px; border-top:1px solid #cfe0f5;
    font-size:14px; color:#01367c; }
#bkadm .bk-sim-tot b { font-size:17px; }
#bkadm .bk-pay-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:7px 0; }
#bkadm .bk-pay-name { min-width:150px; margin:0; }
#bkadm .bk-pay-info { flex:1 1 240px; }
#bkadm .bk-levels { display:flex; gap:10px; flex-wrap:wrap; }
#bkadm .bk-lvl-form { margin:0; flex:1 1 200px; }
#bkadm .bk-lvl { width:100%; height:100%; text-align:left; cursor:pointer; display:block;
    border:1px solid #d2d4d6; border-radius:8px; background:#fff; padding:12px 14px; font:inherit; }
#bkadm .bk-lvl:hover { border-color:#0254a8; }
#bkadm .bk-lvl.on { border-color:#0254a8; background:#eaf1fb; box-shadow:0 0 0 1px #0254a8 inset; }
#bkadm .bk-lvl-n { display:inline-flex; width:22px; height:22px; border-radius:50%; background:#0254a8;
    color:#fff; align-items:center; justify-content:center; font-size:12px; font-weight:700; margin-right:6px; }
#bkadm .bk-lvl.on .bk-lvl-n { background:#01367c; }
#bkadm .bk-lvl-t { font-weight:700; color:#01367c; font-size:14px; }
#bkadm .bk-lvl-d { display:block; margin-top:5px; font-size:12px; color:#5b6470; line-height:1.35; }
#bkadm .bk-shortcuts { display:flex; flex-wrap:wrap; gap:8px; }
#bkadm .bk-shortcuts .bk-btn { text-decoration:none; }
#bkadm .bk-sec-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
#bkadm .bk-sec-head h2 { margin:0; }
#bkadm .bk-copy { font-size:13px; }
#bkadm .bk-copy > summary { cursor:pointer; color:#0254a8; font-weight:600; list-style:none;
    padding:5px 11px; border:1px solid #a7d6ff; border-radius:6px; background:#f0f4ff; white-space:nowrap; }
#bkadm .bk-copy > summary::-webkit-details-marker { display:none; }
#bkadm .bk-copy[open] > summary { background:#0254a8; color:#fff; border-color:#0254a8; }
#bkadm .bk-copy-body { margin-top:8px; padding:10px 12px; border:1px solid #d2d4d6; border-radius:8px;
    background:#fafbfc; width:min(420px, 90vw); }
#bkadm .bk-copy-body select, #bkadm .bk-copy-body input[type=text] { max-width:100%; width:100%; }
#bkadm .bk-copy-body .bk-btn { margin-top:8px; }
#bkadm .bk-pub-what { font-size:13px; line-height:1.5; margin:0 0 6px; padding:8px 10px;
    background:#eef4fb; border:1px solid #cddff2; border-radius:6px; color:#123a63; }
/* Autosave: state pill, floating at the bottom right. */
#bk-pill { position:fixed; right:14px; bottom:14px; z-index:60; padding:8px 13px;
    border-radius:20px; font-size:12px; font-weight:600; border:1px solid transparent;
    box-shadow:0 2px 10px rgba(0,0,0,.18); }
#bk-pill.wait { background:#fdf4e3; border-color:#e8cf9a; color:#7a5b12; }
#bk-pill.ok   { background:#eaf7ea; border-color:#bfe3bf; color:#1c6b1c; }
#bk-pill.err  { background:#fdecea; border-color:#e8b4ae; color:#a02015; }
#bkadm .bk-auto-note { font-size:12px; color:#5a6570; margin:10px 0 0; }
</style>
<?php
$out = '<div id="bkadm"><h1>' . bk_e(bk_t('Brand')) . '</h1>'
    . ($msg ? '<div class="bk-msg bk-ok">' . bk_e($msg) . '</div>' : '')
    . ($err ? '<div class="bk-msg bk-err">' . bk_e($err) . '</div>' : '');

// Report of a re-import just brought back (shown once).
$ar = bk_adopt_report_pull();
if ($ar && !empty($ar['ok'])) {
    $items = '';
    foreach (array('payments' => 'AcAdPay', 'relinked' => 'AcAdRelinked', 'reinjected' => 'AcAdReinjected',
        'imported' => 'AcAdImported', 'category' => 'AcAdCategory', 'reinject_fail' => 'AcAdFail') as $k => $key) {
        if (!empty($ar[$k])) $items .= '<li>' . bk_t($key, intval($ar[$k])) . '</li>';
    }
    $out .= '<div class="bk-msg" style="background:#eaf2fb;border:1px solid #b9d3f0;color:#123a63;text-align:left">'
        . '<b>' . bk_e(bk_t('AcAdTitle')) . '</b> ' . bk_e(bk_t('AcAdText')) . '<ul style="margin:6px 0 0 18px">' . $items . '</ul>'
        . '<p style="margin:8px 0 0">' . bk_e(bk_t('AcAdFoot')) . ' <a href="' . bk_e($ADMIN . 'reimport.php') . '" style="font-weight:600">'
        . bk_e(bk_t('AcAdCheck')) . '</a></p></div>';
}
// Lasting reminder while gaps remain to settle.
$openConf = bk_reimport_conflicts($TOUR);
if ($openConf) {
    $out .= '<div class="bk-msg" style="background:#fdf0ef;border:1px solid #e8b4ae;color:#8b1a1a;text-align:left">'
        . '<b>' . bk_e(bk_t('AcConfTitle', count($openConf))) . '</b> ' . bk_e(bk_t('AcConfText'))
        . ' <a href="' . bk_e($ADMIN . 'reimport.php') . '">' . bk_e(bk_t('AcConfLink')) . '</a></div>';
}

// Matches announced on the FFTA extranet (SYNCHRO_FFTA) against the events set up here.
$out .= bk_ffta_warning_html($TOUR);

// Licences missing from today's federation file (lib/licences.php, checked every night).
$licIssues = bk_licence_report($TOUR);
if ($licIssues) {
    $states = array('absent' => bk_t('LicStAbsent'), 'held' => bk_t('LicStHeld'), 'back' => bk_t('LicStBack'));
    $out .= '<div class="bk-msg" style="background:#fff8e1;border:1px solid #e0a800;color:#5b4300;text-align:left">'
        . '⚠ <b>' . bk_e(bk_t('LicIssuesTitle', count($licIssues))) . '</b><br>' . bk_e(bk_t('LicIssuesHint')) . '<ul style="margin:6px 0 0">';
    foreach ($licIssues as $li) {
        $out .= '<li>' . bk_e($li['name']) . ' (' . bk_e($li['code']) . ') — ' . bk_e($states[$li['state']]) . '</li>';
    }
    $out .= '</ul></div>';
}

// Archers without an FFTA licence registered online: their identity was checked by nobody
// (licence-lib.php). Registration goes on as for anyone; the organiser checks at the desk.
$foreign = aut_lic_foreign($TOUR);
if ($foreign) {
    require_once dirname(__DIR__) . '/lib/other.php';   // country names
    $ctry = bk_other_countries();
    $src = array('fed' => bk_t('ForSrcFed'), 'wa' => bk_t('ForSrcWa'), 'own' => bk_t('ForSrcOwn'));
    $out .= '<div class="bk-msg" style="background:#fff8e1;border:1px solid #e0a800;color:#5b4300;text-align:left">'
        . '⚠ <b>' . bk_e(bk_t('ForTitle', count($foreign))) . '</b><br>' . bk_e(bk_t('ForHint')) . '<ul style="margin:6px 0 0">';
    foreach ($foreign as $f) {
        $how = $src[(string) ($f->BaSource ?? 'fed')] ?? $src['own'];
        $out .= '<li>' . bk_e(trim($f->EnFirstName . ' ' . $f->EnName)) . ' (' . bk_e($f->EnCode)
            . ($f->EnIocCode !== '' ? ', ' . bk_e($ctry[$f->EnIocCode] ?? $f->EnIocCode) : '') . ') — ' . bk_e($how)
            . ($f->BrByRole !== 'SELF' && $f->BrBy !== '' ? ' — ' . bk_e(bk_t('ForBy', $f->BrBy)) : '') . '</li>';
    }
    $out .= '</ul></div>';
}

// Oversized departures (BK_BIG_SESSION_PLACES): slow for the whole server, whatever the level.
$bigSes = array();
foreach ($sessions as $s) {
    if (intval($s->Places) > BK_BIG_SESSION_PLACES) {
        $bigSes[] = bk_t('AcBigLine', array('n' => intval($s->SesOrder), 't' => intval($s->SesTar4Session),
            'a' => intval($s->SesAth4Target), 'p' => number_format(intval($s->Places), 0, '', bk_number_seps()['thousands'])));
    }
}
if ($bigSes) {
    $out .= '<div class="bk-msg" style="background:#fff8e1;border:1px solid #e0a800;color:#5b4300;text-align:left">'
        . '<b>' . bk_e(bk_t(count($bigSes) > 1 ? 'AcBigMany' : 'AcBigOne')) . '</b> : ' . bk_e(implode(' ; ', $bigSes)) . '. '
        . bk_t('AcBigText') . '</div>';
}

// Opening of the registration on this server: copy from…, 3-level bar.
$copy = '';
if ($copyAdmin) {
    $copy = bk_fld(bk_t('AcCopyCode'), '<input type="text" name="copy_src_text" placeholder="' . bk_e(bk_t('AcCopyCodePh')) . '" autocomplete="off" required>')
        . '<button type="submit" name="copy_from" value="1" class="bk-btn">' . bk_e(bk_t('AcCopyBtn')) . '</button>';
} elseif (!$copySources) {
    $copy = '<p class="bk-hint" style="margin:0">' . bk_e(bk_t('AcCopyNone')) . '</p>';
} else {
    $o = '<option value="">' . bk_e(bk_t('ChooseDash')) . '</option>';
    foreach ($copySources as $s) {
        $o .= '<option value="' . intval($s->ToId) . '">' . bk_e($s->ToName . ' (' . $s->ToCode . ') — ' . bk_date_fr($s->ToWhenFrom)) . '</option>';
    }
    $copy = bk_fld(bk_t('AcCopySrc'), '<select name="copy_src" required>' . $o . '</select>')
        . '<button type="submit" name="copy_from" value="1" class="bk-btn">' . bk_e(bk_t('AcCopyBtn')) . '</button>';
}
$levels = '';
foreach (array(1 => 'AcLvl1', 2 => 'AcLvl2', 3 => 'AcLvl3') as $n => $key) {
    $levels .= bk_post_form(array('set_level' => $n), '<button type="submit" class="bk-lvl ' . ($level === $n ? 'on' : '') . '">'
        . '<span class="bk-lvl-t"><span class="bk-lvl-n">' . $n . '</span>' . bk_e(bk_t($key)) . '</span>'
        . '<span class="bk-lvl-d">' . bk_e(bk_t($key . 'D')) . '</span></button>', '', ' class="bk-lvl-form"');
}
$out .= '<div class="bk-sec"><div class="bk-sec-head"><h2>' . bk_e(bk_t('AcOpenTitle')) . '</h2>'
    . '<details class="bk-copy"><summary>' . bk_e(bk_t('AcCopySummary')) . '</summary><div class="bk-copy-body">'
    . '<p class="bk-hint" style="margin:0 0 8px">' . bk_t('AcCopyHint') . '</p>'
    . '<form method="post" onsubmit="return confirm(' . htmlspecialchars(json_encode(bk_t('AcCopyConfirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')">'
    . bk_csrf_field() . $copy . '</form></div></details></div>'
    . '<p class="bk-pub-what">' . bk_t('AcPubWhat') . '</p>'
    . '<p class="bk-hint" style="margin-top:0">' . bk_t('AcNotIanseoNet') . '</p>'
    . '<div class="bk-levels">' . $levels . '</div></div>';

// Tariffs and payment methods: the same blocks at level 3 and on a closed competition that
// uses the payments and the shop (level 1, participants imported in ianseo).
$showTariffs = $level == 3 || ($level == 1 && !empty($cfg->BcPayments));
$cats = '';
foreach ($pricing['categories'] as $i => $rule) $cats .= bk_cat_row($i, $rule, $divs, $classes, $CUR);
$deps = '';
foreach ($sessions as $s) {
    $o = intval($s->SesOrder);
    $deps .= bk_fld(bk_t('DepCap', $o) . ($s->SesName ? ' — ' . $s->SesName : '') . ' (Δ ' . $CUR . ')',
        '<input type="text" name="dep[' . $o . ']" size="6" value="' . bk_e(bk_amt($pricing['departures'][(string) $o] ?? '')) . '">');
}
$simDiv = $simCls = '';
foreach ($divs as $k => $v) $simDiv .= '<option value="' . bk_e($k) . '">' . bk_e($v) . '</option>';
foreach ($classes as $k => $v) $simCls .= '<option value="' . bk_e($k) . '">' . bk_e($v) . '</option>';
$simSes = '<option value="0">—</option>';
foreach ($sessions as $s) $simSes .= '<option value="' . intval($s->SesOrder) . '">' . bk_e(bk_t('DepCap', intval($s->SesOrder))) . '</option>';
$payRows = '';
foreach (bk_payment_methods() as $mk => $ml) {
    $cur = $payByM[$mk] ?? null;
    $when = '';
    foreach (bk_payinfo_when_labels() as $wk => $wl) {
        $when .= '<option value="' . bk_e($wk) . '"' . (($cur && $cur['when'] === $wk) ? ' selected' : '') . '>' . bk_e($wl) . '</option>';
    }
    $payRows .= '<div class="bk-pay-row"><label class="bk-chk bk-pay-name"><input type="checkbox" name="pay[' . bk_e($mk) . '][on]" value="1"'
        . ($cur ? ' checked' : '') . '> <b>' . bk_e($ml) . '</b></label>'
        . '<select name="pay[' . bk_e($mk) . '][when]">' . $when . '</select>'
        . '<input type="text" class="bk-pay-info" name="pay[' . bk_e($mk) . '][info]" value="' . bk_e($cur['info'] ?? '') . '" placeholder="'
        . bk_e(bk_t('AcPayInfoPh')) . '"></div>';
}
$tariffBlocks = '<div class="bk-sec"><h2>' . bk_e(bk_t('MnFees')) . '</h2><div class="bk-row">'
    . bk_fld(bk_t('AcBaseFee', $CUR), '<input type="text" name="fee" size="8" value="' . bk_e(bk_amt((float) $cfg->BcFee)) . '">') . '</div>'
    . '<p class="bk-hint">' . bk_e(bk_t('AcBaseFeeHint')) . '</p>'
    . '<details class="bk-adv"' . (bk_pricing_is_advanced($pricing) ? ' open' : '') . '><summary>' . bk_e(bk_t('AcAdvSummary')) . '</summary>'
    . '<p class="bk-hint" style="margin:0 0 6px">' . bk_t('AcHowPrice', bk_e(bk_eur(0))) . '</p>'
    . '<h3>' . bk_e(bk_t('AcByCat')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcByCatHint')) . '</p>'
    . '<div id="bk-cat-list">' . $cats . '</div>'
    . '<button type="button" class="bk-btn bk-add" onclick="bkAddCat()">' . bk_e(bk_t('AcAddRule')) . '</button>'
    . '<template id="bk-cat-tpl">' . bk_cat_row('__i__', array(), $divs, $classes, $CUR) . '</template>'
    . '<h3>' . bk_e(bk_t('AcByDep')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcByDepHint')) . '</p>'
    . ($sessions ? '<div class="bk-row">' . $deps . '</div>' : '<p class="bk-hint">' . bk_e(bk_t('AcNoSessionYet')) . '</p>')
    . '<h3>' . bk_e(bk_t('AcByProv')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcByProvHint')) . '</p><div class="bk-row">'
    . bk_fld(bk_t('AcLocalDept'), '<input type="text" name="prov_deptcode" size="4" maxlength="2" value="' . bk_e($provDeptDef) . '">')
    . bk_fld(bk_t('AcDeltaDept', $CUR), '<input type="text" name="prov_dept" size="6" value="' . bk_e(bk_amt($pricing['prov']['dept'] ?: '')) . '">')
    . bk_fld(bk_t('AcLocalRegion'), '<input type="text" name="prov_regioncode" size="4" maxlength="2" value="' . bk_e($provRegionDef) . '">')
    . bk_fld(bk_t('AcDeltaRegion', $CUR), '<input type="text" name="prov_region" size="6" value="' . bk_e(bk_amt($pricing['prov']['region'] ?: '')) . '">')
    . '</div><h3>' . bk_e(bk_t('AcRankTitle')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcRankHint')) . '</p><div class="bk-row">'
    . bk_fld(bk_t('AcFromNth', array('n' => 2, 'cur' => $CUR)), '<input type="text" name="rank[2]" size="6" value="' . bk_e(bk_amt($pricing['rank']['2'] ?? '')) . '">')
    . bk_fld(bk_t('AcFromNth', array('n' => 3, 'cur' => $CUR)), '<input type="text" name="rank[3]" size="6" value="' . bk_e(bk_amt($pricing['rank']['3'] ?? '')) . '">')
    . '</div><h3>' . bk_e(bk_t('AcPreview')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcPreviewHint')) . '</p>'
    . '<div class="bk-row bk-sim-in">'
    . bk_fld(bk_t('AcSimBow'), '<select id="sim-div">' . $simDiv . '</select>')
    . bk_fld(bk_t('SsCategory'), '<select id="sim-cls">' . $simCls . '</select>')
    . bk_fld(bk_t('SsDeparture'), '<select id="sim-ses">' . $simSes . '</select>')
    . bk_fld(bk_t('AcSimOrigin'), '<select id="sim-prov"><option value="">' . bk_e(bk_t('AcSimOut')) . '</option><option value="region">'
        . bk_e(bk_t('AcSimRegion')) . '</option><option value="dept">' . bk_e(bk_t('AcSimDept')) . '</option></select>')
    . bk_fld(bk_t('AcSimRankNo'), '<select id="sim-rank"><option value="1">' . bk_e(bk_t('AcSimR1')) . '</option><option value="2">'
        . bk_e(bk_t('AcSimR2')) . '</option><option value="3">' . bk_e(bk_t('AcSimR3')) . '</option></select>')
    . '</div><div class="bk-sim-out"><table class="bk-sim-t"><tbody id="sim-lines"></tbody></table>'
    . '<p class="bk-sim-tot">' . bk_e(bk_t('AcSimTotal')) . ' <b id="sim-total">—</b></p></div></details></div>'
    . '<div class="bk-sec"><h2>' . bk_e(bk_t('PayMeansTitle')) . '</h2><p class="bk-hint">' . bk_e(bk_t('AcPayHint')) . '</p>' . $payRows . '</div>';

$saveBtn = '<button type="submit" class="bk-btn" data-manual-save="1">' . bk_e(bk_t('AmSave')) . '</button>';

if ($level == 1) {
    $out .= '<div class="bk-sec"><p class="bk-hint" style="margin:0">' . bk_t('AcL1Text') . '</p>'
        . bk_post_form(array('set_payments' => 1), '<label class="bk-chk"><input type="checkbox" name="payments" value="1" onchange="this.form.submit()"'
            . (!empty($cfg->BcPayments) ? ' checked' : '') . '> ' . bk_t('AcL1Box') . '</label>'
            . '<noscript><button type="submit" class="bk-btn">' . bk_e(bk_t('AcApply')) . '</button></noscript>', '', ' style="margin:12px 0 0"')
        . '<p class="bk-hint">' . bk_e(bk_t('AcL1Hint')) . '</p>'
        . (!empty($cfg->BcPayments) ? '<p class="bk-shortcuts"><a class="bk-btn" href="' . $SHOP_ADMIN . '">' . bk_e(shp_t('MnuTitle')) . ' →</a> '
            . '<a class="bk-btn" href="' . $ADMIN . 'dues.php">' . bk_e(bk_t('Payments')) . ' →</a></p>' : '')
        . '</div>';
    if (!empty($cfg->BcPayments)) {
        $out .= '<form method="post" id="bk-cfg" data-autosave="1">' . bk_csrf_field() . '<input type="hidden" name="save_payments" value="1">'
            . $tariffBlocks . $saveBtn . '</form><div id="bk-pill" hidden></div>';
    }
}

if ($level == 2) {
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('AcL2Title')) . '</h2><p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('AcL2Hint')) . '</p>'
        . '<form method="post" class="bk-row" style="margin:0 0 14px" data-autosave="1">' . bk_csrf_field() . '<input type="hidden" name="save_fee" value="1">'
        . bk_fld(bk_t('AcRegFee', $CUR), '<input type="text" name="fee" size="8" value="' . bk_e(bk_amt((float) $cfg->BcFee)) . '">')
        . '<button type="submit" class="bk-btn" data-manual-save="1" style="align-self:flex-end">' . bk_e(bk_t('AcSaveFee')) . '</button></form>'
        . '<p class="bk-hint" style="margin:0 0 6px">' . bk_t('AcL2FeeHint') . '</p><p class="bk-shortcuts">'
        . '<a class="bk-btn" href="' . $ADMIN . 'field.php">' . bk_e(bk_t('MnuField')) . ' →</a> '
        . '<a class="bk-btn" href="' . $SHOP_ADMIN . '">' . bk_e(shp_t('MnuTitle')) . ' →</a> '
        . '<a class="bk-btn" href="' . $ADMIN . 'dues.php">' . bk_e(bk_t('Payments')) . ' →</a> '
        . '<a class="bk-btn" href="' . $ADMIN . 'survey.php">' . bk_e(bk_t('MnuSurvey')) . ' →</a></p></div>';
}

if ($level == 3) {
    $kinds = '';
    foreach (bk_restrict_kinds() as $k => $lab) {
        $kinds .= '<option value="' . bk_e($k) . '"' . ($cfg->BcRestrictKind === $k ? ' selected' : '') . '>' . bk_e($lab) . '</option>';
    }
    $out .= '<form method="post" id="bk-cfg" data-autosave="1">' . bk_csrf_field()
        // Registration period.
        . '<div class="bk-sec"><h2>' . bk_e(bk_t('AcPeriod')) . '</h2><div class="bk-row">'
        . bk_fld(bk_t('AcOpenFrom'), '<input type="datetime-local" name="from" value="' . bk_e(bk_dtval($cfg->BcOpenFrom)) . '">')
        . bk_fld(bk_t('AcOpenTo'), '<input type="datetime-local" name="to" value="' . bk_e(bk_dtval($cfg->BcOpenTo)) . '">')
        . '</div><p class="bk-hint">' . bk_e(bk_t('AcPeriodHint')) . ' <b style="color: crimson;">'
        . bk_e(bk_t($cfg->BcIsOpen ? 'AcStateOpen' : 'AcStateOut')) . '</b>.</p>'
        . bk_adm_session_rules($TOUR, $cfg, $sessions) . '</div>'
        // Geographic restriction.
        . '<div class="bk-sec"><h2>' . bk_e(bk_t('AcGeo')) . '</h2><div class="bk-row">'
        . bk_fld(bk_t('AcGeoFor'), '<select name="kind">' . $kinds . '</select>')
        . bk_fld(bk_t('AcGeoCode'), '<input type="text" name="code" size="8" maxlength="12" value="' . bk_e($cfg->BcRestrictCode) . '">')
        . bk_fld(bk_t('AcGeoAll'), '<input type="datetime-local" name="restrict_to" value="' . bk_e(bk_dtval($cfg->BcRestrictTo)) . '">')
        . '</div><p class="bk-hint">' . bk_e(bk_t('AcGeoHint'))
        . ($cfg->BcRestrictKind !== '' ? ' ' . bk_e(bk_t('AcGeoState')) . ' <b>' . bk_e(bk_t($cfg->BcAllOpen ? 'AcGeoOpen' : 'AcGeoRestricted')) . '</b>.' : '')
        . '</p></div>'
        // Placement and validation.
        . '<div class="bk-sec"><h2>' . bk_e(bk_t('AcPlacement')) . '</h2>'
        . ($isDrom
            ? '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('AcDromHint')) . '</p><div class="bk-row">'
              . bk_fld(bk_t('AcMaxClub'), '<input type="number" name="max_club" min="1" max="20" value="' . intval($cfg->BcMaxPerClubPerTarget) . '">')
              . bk_fld(bk_t('AcMinClubs'), '<input type="number" name="min_clubs" min="1" max="50" value="' . intval($cfg->BcMinClubsPerSession) . '">') . '</div>'
            : '<p class="bk-hint" style="margin-top:0">' . bk_t('AcFedRules') . '</p>')
        . bk_chk('manual_validation', !empty($cfg->BcManualValidation), bk_t('AcManualBox'), 'margin-top:6px')
        . '<p class="bk-hint">' . bk_t('AcManualHint') . '</p>'
        . '<input type="hidden" name="single_reg_present" value="1">'
        . bk_chk('single_reg', !empty($cfg->BcSingleReg), bk_e(bk_t('AcSingleReg')), 'margin-top:6px')
        . '<p class="bk-hint">' . bk_e(bk_t('AcSingleRegHint')) . '</p></div>';

    // What the archers see.
    $hasMandate = trim((string) ($cfg->BcMandate ?? '')) !== '';
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('AcWhatSee')) . '</h2>'
        . bk_chk('show_gauges', $cfg->BcShowGauges, bk_e(bk_t('AcShowGauges')))
        . bk_chk('show_assign', $cfg->BcShowAssignment, bk_e(bk_t('AcShowAssign')))
        . bk_chk('scoresheet', $cfg->BcAllowScoresheet, bk_e(bk_t('AcScoresheet')))
        . ($hasMandate
            ? '<input type="hidden" name="show_mandate_present" value="1">'
              . bk_chk('show_mandate', bk_mandate_visible($cfg), bk_t('AcShowMandate', bk_e($ADMIN . 'mandate.php')))
            : '<p class="bk-hint" style="margin:6px 0 0">' . bk_t('AcNoMandate', bk_e($ADMIN . 'mandate.php')) . '</p>');

    // Documents. The address is no longer asked: it is rebuilt from the online id given with the
    // publication codes. Only whether to show it is left to decide.
    $ianseoUrl      = bk_ianseo_url($TOUR);
    $ianseoUrlSaved = trim((string) ($cfg->BcIanseoUrl ?? ''));
    $out .= '<h3 class="bk-h3">' . bk_e(bk_t('DocsTitle')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcDocsHint')) . '</p>';
    if ($ianseoUrl !== '') {
        $out .= '<input type="hidden" name="ianseo_present" value="1">'
            . bk_chk('show_ianseo', $ianseoUrlSaved !== '', bk_t('AcIanseoLink', $onlineId) . ' <a href="' . bk_e($ianseoUrl)
                . '" target="_blank" rel="noopener">' . bk_e($ianseoUrl) . '</a>');
    } elseif ($ianseoUrlSaved !== '') {
        // Derived value whose source is gone (re-import without an online id, or an address typed
        // when the field was free): said here, and the next save removes it — there is no page
        // to point to any more.
        $out .= '<input type="hidden" name="ianseo_present" value="1"><p class="bk-hint" style="margin:6px 0 0; color:#a86b00">'
            . bk_e(bk_t('AcIanseoStale', $ianseoUrlSaved)) . '</p>';
    }
    $dossardCard = bk_dossard_card($TOUR);
    $out .= '<p class="bk-hint" style="margin-top:12px">' . bk_e(bk_t('AcOfficialHint')) . '</p><input type="hidden" name="docs_present" value="1">'
        . bk_chk('show_program', !empty($cfg->BcShowProgram), bk_e(bk_t('AcShowProgram')))
        . bk_chk('show_participants', !empty($cfg->BcShowParticipants), bk_e(bk_t('AcShowParticipants')))
        . bk_chk('show_results', !empty($cfg->BcShowResults), bk_e(bk_t('AcShowResults')))
        . bk_chk('show_dossard', !empty($cfg->BcShowDossard), bk_t('AcShowDossard', bk_e($CFG->ROOT_DIR . 'Accreditation/IdCards.php?CardType=Q'))
            . ($dossardCard === null ? ' <span class="bk-hint" style="color:#a86b00">' . bk_e(bk_t('AcNoDossard')) . '</span>' : ''));

    // Wishes, waiting list, after the competition.
    $out .= '<h3 class="bk-h3">' . bk_e(bk_t('AcWishes')) . '</h3><p class="bk-hint">' . bk_e(bk_t('AcWishesHint')) . '</p>'
        . bk_chk('wish_letter', $cfg->BcWishLetter, bk_e(bk_t('AcWishLetter')))
        . bk_chk('wish_with', $cfg->BcWishWith, bk_e(bk_t('AcWishWith')))
        . bk_chk('wish_free', $cfg->BcWishFree, bk_e(bk_t('AcWishFree')))
        . '<input type="hidden" name="waitlist_present" value="1">'
        . bk_chk('waitlist', !isset($cfg->BcWaitlist) || !empty($cfg->BcWaitlist), bk_t('AcWaitBox'))
        . '<h3 class="bk-h3">' . bk_e(bk_t('AcAfter')) . '</h3><input type="hidden" name="survey_present" value="1">'
        . bk_chk('survey', !isset($cfg->BcSurvey) || !empty($cfg->BcSurvey), bk_t('AcSurveyBox', bk_e($ADMIN . 'survey.php')))
        . '<p class="bk-hint">' . bk_e(bk_t('AcSurveyHint')) . '</p></div>'
        . $tariffBlocks . $saveBtn . '</form>';
}

if ($level >= 2) {
    // Departures, read from ianseo.
    $out .= '<div class="bk-sec" style="margin-top:18px"><h2>' . bk_e(bk_t('TgDepartures')) . '</h2>';
    if (!$sessions) {
        $out .= '<p class="bk-hint">' . bk_t('AcNoDepConf') . '</p>';
    } else {
        $out .= '<table class="bk-t"><tr><th>' . bk_e(bk_t('SsDeparture')) . '</th><th>' . bk_e(bk_t('AcTargets')) . '</th><th>'
            . bk_e(bk_t('AcPerTarget')) . '</th><th>' . bk_e(bk_t('SsTotal')) . '</th><th>' . bk_e(bk_t('AcOccupancy')) . '</th></tr>';
        foreach ($sessions as $s) {
            $pl = intval($s->Places); $pr = intval($s->Pris);
            $pc = $pl > 0 ? min(100, round($pr * 100 / $pl)) : 0;
            $out .= '<tr><td>' . intval($s->SesOrder) . ($s->SesName ? ' — ' . bk_e($s->SesName) : '') . '</td><td>' . intval($s->SesTar4Session) . '</td>'
                . '<td>' . intval($s->SesAth4Target) . '</td><td>' . $pl . '</td>'
                . '<td><span class="bk-gauge"><i style="width:' . $pc . '%"></i></span>' . $pr . ' / ' . $pl . '</td></tr>';
        }
        $out .= '</table><p class="bk-hint">' . bk_e(bk_t('AcDepsHint')) . '</p>';
    }
    $out .= '<p style="margin:10px 0 0"><a class="bk-btn" style="text-decoration:none;display:inline-block" href="' . $ADMIN . 'field.php">'
        . bk_e(bk_t('MnuField')) . ' →</a><span class="bk-hint" style="display:block;margin-top:6px">' . bk_e(bk_t('AcFieldHint')) . '</span></p></div>';

    // Waiting list (lib/waitlist.php): order of arrival; register by hand (even on a full
    // departure: the organiser's call) or remove.
    if ($waitList['waiting'] || $waitList['done']) {
        $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('AcWaitTitle')) . '</h2>';
        if (!bk_waitlist_on($cfg)) $out .= '<p class="bk-hint">' . bk_e(bk_t('AcWaitOff')) . '</p>';
        if ($waitList['waiting']) {
            $out .= '<p class="bk-hint">' . bk_e(bk_t('AcWaitHint')) . (empty($cfg->BcIsOpen) ? ' ' . bk_t('AcWaitFrozen') : '') . '</p>'
                . '<table class="bk-t"><tr><th>#</th><th>' . bk_e(bk_t('ColArcher')) . '</th><th>' . bk_e(bk_t('Club')) . '</th><th>'
                . bk_e(bk_t('AcBowCat')) . '</th><th>' . bk_e(bk_t('AcWishedDep')) . '</th><th>' . bk_e(bk_t('AcPlannedPay')) . '</th><th>'
                . bk_e(bk_t('AcSince')) . '</th><th></th></tr>';
            foreach ($waitList['waiting'] as $i => $w) {
                $pc = explode('|', (string) $w->BwPayChoice . '|', 3);   // "method|when", or empty
                $opts = '';
                foreach ($sessions as $s) {
                    $o = intval($s->SesOrder);
                    $opts .= '<option value="' . $o . '"' . (intval($w->BwSession) === $o ? ' selected' : '') . '>'
                        . bk_e(bk_t('AcDepPl', array('dep' => bk_t('DepCap', $o), 'n' => max(0, intval($s->Places) - intval($s->Pris))))) . '</option>';
                }
                $out .= '<tr><td>' . ($i + 1) . '</td><td>' . bk_e(trim($w->LueFamilyName . ' ' . $w->LueName)) . ' <span class="bk-hint">'
                    . bk_e($w->BwLicence) . '</span></td><td>' . bk_e($w->LueCoDescr) . '</td><td>'
                    . bk_e(($w->DivDescription ?: $w->BwDivision) . ' / ' . ($w->ClDescription ?: $w->BwClass)) . '</td><td>'
                    . bk_e(intval($w->BwSession) ? bk_t('DepCap', intval($w->BwSession)) : bk_t('AcAnyDep')) . '</td><td>'
                    . bk_e(bk_payment_decl_label($pc[0], $pc[1])) . '</td><td>' . bk_e(bk_date_fr($w->BwCreated)) . '</td>'
                    . '<td style="white-space:nowrap">'
                    . bk_post_form(array('wait_action' => 'register', 'w' => intval($w->BwId)), '<select name="wait_session">' . $opts . '</select> '
                        . '<button type="submit" class="bk-btn">' . bk_e(bk_t('ClubRegisterBtn')) . '</button>', bk_t('AcWaitRegConfirm'), ' style="display:inline"') . ' '
                    . bk_post_form(array('wait_action' => 'remove', 'w' => intval($w->BwId)),
                        '<button type="submit" class="bk-btn">' . bk_e(bk_t('RiRemove')) . '</button>', bk_t('AcWaitRemoveConfirm'), ' style="display:inline"')
                    . '</td></tr>';
            }
            $out .= '</table>';
        }
        if ($waitList['done']) {
            $out .= '<h3 class="bk-h3">' . bk_e(bk_t('AcLastResults')) . '</h3><table class="bk-t"><tr><th>' . bk_e(bk_t('ColArcher')) . '</th><th>'
                . bk_e(bk_t('AcOn')) . '</th><th>' . bk_e(bk_t('AcResult')) . '</th></tr>';
            foreach ($waitList['done'] as $w) {
                $out .= '<tr><td>' . bk_e(trim($w->LueFamilyName . ' ' . $w->LueName)) . ' <span class="bk-hint">' . bk_e($w->BwLicence)
                    . '</span></td><td>' . bk_e(bk_date_fr($w->BwDone)) . '</td><td>'
                    . bk_e(intval($w->BwStatus) === 1 ? bk_t('AcWaitRegOn', intval($w->BwSession)) : bk_t('AcWaitRemovedX', $w->BwNote))
                    . '</td></tr>';
            }
            $out .= '</table>';
        }
        $out .= '</div>';
    }

    // Check of the rules.
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('TgRulesTitle')) . '</h2>';
    if (!$rules) {
        $out .= '<p class="bk-hint" style="margin:0">' . bk_e(bk_t('AcNoRules')) . '</p>';
    } else {
        $out .= '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('AcRulesHint')) . '</p><table class="bk-t"><tr><th>'
            . bk_e(bk_t('SsDeparture')) . '</th><th>' . bk_e(bk_t('TgRegistered')) . '</th><th>' . bk_e(bk_t('TgClubs')) . '</th><th>'
            . bk_e(bk_t('AcRules')) . '</th></tr>';
        foreach ($rules as $rc) {
            $out .= '<tr><td>' . intval($rc['depart']) . ($rc['nom'] ? ' — ' . bk_e($rc['nom']) : '') . '</td><td>' . intval($rc['archers']) . '</td>'
                . '<td>' . bk_e(bk_t('AcClubsMin', array('n' => intval($rc['clubs']), 'min' => intval($rc['minClubs'])))) . '</td><td>';
            if ($rc['ok']) {
                $out .= '<span style="color:#04ac0b;font-weight:600">' . bk_e(bk_t('AcCompliant')) . '</span>';
            } else {
                $li = '';
                if (!$rc['clubsOk']) $li .= '<li>' . bk_e(bk_t('AcLessClubs', intval($rc['minClubs']))) . '</li>';
                foreach ($rc['exces'] as $ex) {
                    $li .= '<li>' . bk_e(bk_t('AcTooManyLow', array('target' => intval($ex['cible']), 'n' => intval($ex['n']),
                        'club' => $ex['club'], 'max' => intval($rc['max'])))) . '</li>';
                }
                if (intval($rc['nonPlaces']) > 0) $li .= '<li>' . bk_e(bk_t('AcUnplaced', intval($rc['nonPlaces']))) . '</li>';
                if (!empty($rc['doublons'])) $li .= '<li>' . bk_e(bk_t('AcDupes')) . '</li>';
                $out .= '<span style="color:#c0392b;font-weight:600">' . bk_e(bk_t('AcReview')) . '</span>'
                    . '<ul class="bk-hint" style="margin:4px 0 0; padding-left:18px; color:#a80000">' . $li . '</ul>';
            }
            $out .= '</td></tr>';
        }
        $out .= '</table>';
    }
    $out .= '</div>';

    // Link for the archers.
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('AcLinkTitle')) . '</h2><p style="font-size:13px;margin:0">' . bk_e(bk_t('AcLinkGive'))
        . '<br><a class="bk-url" href="' . bk_e($publicUrl) . '" target="_blank" rel="noopener">' . bk_e($publicUrl) . '</a></p>'
        . '<p class="bk-hint">' . bk_e(bk_t('AcLinkHint')) . '</p></div><div id="bk-pill" hidden></div>';
}
echo $out . '</div>';

// Texts and number format for the two scripts below.
$seps = bk_number_seps();
$jsT = array(
    'lang' => aut_lang_code(), 'dec' => $seps['dec'], 'th' => $seps['thousands'], 'cur' => $CUR,
    'saving' => bk_t('AcJsSaving'), 'savedAt' => bk_t('AcJsSavedAt'), 'refused' => bk_t('AcJsRefused'),
    'offline' => bk_t('AcJsOffline'), 'auto' => bk_t('AcJsAuto'),
    'base' => bk_t('PriceBase'), 'cat' => bk_t('PriceCat'), 'catNamed' => bk_t('PriceCatNamed'), 'dep' => bk_t('DepCap'),
    'dept' => bk_t('PriceDept'), 'region' => bk_t('PriceRegion'), 'rank' => bk_t('PriceRank'),
);
echo '<script>var BK_T = ' . json_encode($jsT, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', BK_CATN = ' . count($pricing['categories']) . ';</script>';
$assets = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/assets/';
if ($level >= 2 || $showTariffs) echo '<script src="' . $assets . 'autosave.js?v=' . bk_e(bk_version()) . '"></script>';
if ($showTariffs) echo '<script src="' . $assets . 'tariffs.js?v=' . bk_e(bk_version()) . '"></script>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
