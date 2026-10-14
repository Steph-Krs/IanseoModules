<?php
/**
 * admin/competition.php — opening of the online registration for the competition currently
 * open in ianseo.
 *
 * ORGANISER page (computer): unlike public/, it sits in the ianseo look and follows the core's
 * ACL, in the frame shared by AUTH's organiser pages (adm-lib.php). ONE form and ONE save button
 * fixed at the bottom right: nothing is written while the organiser types, and the level chosen
 * (closed, simple publication, detailed settings) is applied when saving too — after the settings
 * of the level shown. Markup produced in PHP; the scripts at the end get their texts from the
 * page.
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
require_once dirname(__DIR__, 2) . '/adm-lib.php';         // frame of the organiser pages

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

/** Level 1 (closed): use the payments and the shop or not, and their tariffs when used. */
function bk_adm_save_level1($tourId, $post)
{
    $tourId = intval($tourId);
    $pay = empty($post['payments']) ? 0 : 1;
    safe_w_sql("INSERT INTO BookingCompetitions (BcTournament, BcPayments) VALUES ($tourId, $pay)
        ON DUPLICATE KEY UPDATE BcPayments = VALUES(BcPayments)");
    // Tariffs and payment methods: only what the archers are charged — nothing here opens the
    // competition to them.
    if ($pay && !empty($post['tariffs_present'])) {
        $pricingJson = bk_adm_pricing_from_post($post);
        $payJson = bk_payinfo_from_post($post['pay'] ?? array());
        bk_comp_set_effective($tourId, array(
            'BcFee' => number_format((float) str_replace(',', '.', (string) ($post['fee'] ?? 0)), 2, '.', ''),
            'BcPricing' => $pricingJson === '' ? null : $pricingJson,
            'BcPayInfo' => $payJson === '' ? null : $payJson,
        ));
    }
}

/**
 * Level 2 (simple publication): the base fee only — its own field (fee_simple): the tariffs of
 * levels 1 and 3, in the same page, have theirs.
 */
function bk_adm_save_level2($tourId, $post)
{
    $fee = number_format((float) str_replace(',', '.', (string) ($post['fee_simple'] ?? $post['fee'] ?? 0)), 2, '.', '');
    safe_w_sql("UPDATE BookingCompetitions SET BcFee = " . StrSafe_DB($fee) . " WHERE BcTournament = " . intval($tourId));
}

/** Level 3: what is refused before anything is written. Returns '' or the error shown. */
function bk_adm_check_level3($post)
{
    $kind = (string) ($post['kind'] ?? '');
    if (!array_key_exists($kind, bk_restrict_kinds())) $kind = '';
    return bk_scope_error($kind, $post['code'] ?? '');
}

/** Level 3 (detailed settings): every setting of the form. Returns '' or the error shown. */
function bk_adm_save_level3($tourId, $post)
{
    $tourId = intval($tourId);
    $kind = (string) ($post['kind'] ?? '');
    if (!array_key_exists($kind, bk_restrict_kinds())) $kind = '';
    $err = bk_scope_error($kind, $post['code'] ?? '');
    if ($err !== '') return $err;
    $pricingJson = bk_adm_pricing_from_post($post);

    // Placement rules: federation values, editable only overseas.
    $isDromPost = bk_is_dromtom(bk_org_agrement($tourId));
    $save = array(
        'open'        => 1,   // level 3 = published (the level drives the publication)
        'from'        => $post['from'] ?? '',
        'to'          => $post['to'] ?? '',
        'kind'        => $kind,
        'code'        => $post['code'] ?? '',
        'restrict_to' => $kind === '' ? '' : ($post['restrict_to'] ?? ''),
        'max_club'    => $isDromPost ? ($post['max_club'] ?? 2) : 2,
        'min_clubs'   => $isDromPost ? ($post['min_clubs'] ?? 3) : 3,
        'show_assign' => !empty($post['show_assign']),
        'show_gauges' => !empty($post['show_gauges']),
        'scoresheet'  => !empty($post['scoresheet']),
        'wish_letter' => !empty($post['wish_letter']),
        'wish_with'   => !empty($post['wish_with']),
        'wish_free'   => !empty($post['wish_free']),
        'manual_validation' => !empty($post['manual_validation']),
        'fee'         => $post['fee'] ?? 0,
        'pricing'     => $pricingJson,
        'payinfo'     => bk_payinfo_from_post($post['pay'] ?? array()),
        'docs_present'      => 1,
        'show_program'      => !empty($post['show_program']),
        'show_participants' => !empty($post['show_participants']),
        'show_results'      => !empty($post['show_results']),
        'show_dossard'      => !empty($post['show_dossard']),
    );
    // Mandate visibility: written only when the box was there (it only is when a mandate
    // exists) — keeps the three states.
    if (!empty($post['show_mandate_present'])) $save['show_mandate'] = !empty($post['show_mandate']);
    // Same for the ianseo.net link: the box is only shown when the competition has its
    // publication codes. Without this guard, every save made without the box would erase the
    // link. The ADDRESS itself is never posted: bk_comp_save() rebuilds it from ToOnlineId.
    if (!empty($post['ianseo_present'])) {
        $save['ianseo_present'] = 1;
        $save['show_ianseo'] = !empty($post['show_ianseo']);
    }
    // Satisfaction survey, waiting list, one registration per archer: level 3 only.
    if (!empty($post['survey_present'])) $save['survey'] = !empty($post['survey']);
    if (!empty($post['waitlist_present'])) $save['waitlist'] = !empty($post['waitlist']);
    if (!empty($post['single_reg_present'])) $save['single_reg'] = !empty($post['single_reg']);
    // Categories closed to online registration (grid of the Restrictions tab, level 3 only).
    // One list per departure ([SesOrder => "DIV|CLS,…"]).
    if (!empty($post['cats_present'])) $save['cat_forbid'] = is_array($post['cat_forbid'] ?? null) ? $post['cat_forbid'] : array();
    bk_comp_save($tourId, $save);
    // Opening of each departure (lib/sessionrules.php).
    if (!empty($post['ses_present']) && is_array($post['ses'] ?? null)) bk_session_rules_save($tourId, $post['ses']);
    safe_w_sql("UPDATE BookingCompetitions SET BcPublishLevel = 3 WHERE BcTournament = $tourId");
    return '';
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
        } elseif (($nStaff = bk_comp_copy_from($TOUR, $srcId, !empty($_POST['copy_staff']))) !== false) {
            header('Location: ' . $SELF . '?copied=1' . (!empty($_POST['copy_staff']) ? '&staff=' . intval($nStaff) : ''));
            exit;
        } else {
            $err = bk_t('AcCopyFailed');
        }
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
    } elseif (isset($_POST['save_all'])) {
        // The page's save button. Every level is in the page and the one chosen shows its settings
        // at once, so the organiser sets a level up completely before it goes live (user's
        // request). Order: what was typed for the CURRENT level, then the level chosen (its
        // transition: leaving level 3 puts its settings aside, coming back restores them — so they
        // carry what was just typed), then what was typed for the CHOSEN level. Level 3 is checked
        // first: a refused restriction leaves everything as it was.
        $curLevel = intval(bk_comp_config($TOUR)->BcPublishLevel ?? 1);
        $newLevel = intval($_POST['level'] ?? $curLevel);
        if (!in_array($newLevel, array(1, 2, 3), true)) $newLevel = $curLevel;
        if ($curLevel === 3 || $newLevel === 3) $err = bk_adm_check_level3($_POST);
        $saveLevel = function ($lvl) use ($TOUR) {
            if ($lvl === 3) return bk_adm_save_level3($TOUR, $_POST);
            if ($lvl === 2) bk_adm_save_level2($TOUR, $_POST);
            else bk_adm_save_level1($TOUR, $_POST);
            return '';
        };
        if ($err === '') $err = $saveLevel($curLevel);
        if ($err === '' && $newLevel !== $curLevel) {
            bk_comp_set_level($TOUR, $newLevel);
            $err = $saveLevel($newLevel);
        }
        if ($err === '') {
            header('Location: ' . $SELF . '?saved=1');
            exit;
        }
    }
}

if (isset($_GET['copied'])) $msg = bk_t('AcCopied') . (isset($_GET['staff']) ? ' ' . bk_t('AcCopiedStaff', intval($_GET['staff'])) : '');
if (isset($_GET['saved'])) $msg = bk_t('AcSaved');

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
$rules    = bk_rules_check($TOUR, $cfg);   // shown by levels 2 and 3, which the page may choose
// The detailed settings as level 3 would have them. At level 2 — or at 1 after it — they are put
// aside in BcAdvancedBackup (bk_comp_set_level) and come back when level 3 is chosen again: the
// form must show THOSE, or saving would write the automatic values of level 2 over them. $cfg
// stays the effective setup (states, departures, waiting list).
$cfg3 = clone $cfg;
$aside = json_decode((string) ($cfg->BcAdvancedBackup ?? ''), true);
if (is_array($aside)) {
    foreach (bk_comp_advanced_cols() as $c) if (array_key_exists($c, $aside)) $cfg3->$c = $aside[$c];
}
$cfg3->BcPublishLevel = 3;   // bk_mandate_visible: the rule of level 3
// Address given to the archers: the page of this competition in their space. Not signed in,
// they sign in first and come back to it (bk_require_archer / bk_next_after_login).
$publicUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://'
    . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/competition.php?t=' . $TOUR;

// Tariff: the settings of levels 1 and 3 (one block for both) + lists of categories for the editor.
$pricing = bk_pricing_get($cfg3);
$payinfo = bk_payinfo_get($cfg3);
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

/** A labelled field: <label class="bk-f"><span>label</span>input</label>; $q: its "?" (bk_help). */
function bk_fld($label, $input, $q = '')
{
    return '<label class="bk-f"><span>' . bk_e($label) . $q . '</span>' . $input . '</label>';
}

/** Explanation shown on demand ("?" that unfolds it): [button, text] — aut_adm_help (adm-lib.php). */
function bk_help($html)
{
    return aut_adm_help($html);
}

/** A heading (h2, or h3.bk-h3) with its explanation on demand; $help: markup, or ''. */
function bk_title($tag, $title, $help = '')
{
    return aut_adm_title($tag, $title, $help, $tag === 'h3' ? ' class="bk-h3"' : '');
}

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
    $h = bk_title('h3', bk_t('AcSesTitle'), bk_e(bk_t('AcSesHint')))
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

/** A checkbox line; $label is markup from the language file or escaped by the caller; $help: its
    explanation on demand (markup), or ''. */
function bk_chk($name, $on, $label, $style = '', $help = '')
{
    $box = '<label class="bk-chk"' . ($style !== '' ? ' style="' . $style . '"' : '') . '><input type="checkbox" name="' . $name
        . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
    if ($help === '') return $box;
    list($q, $h) = bk_help($help);
    return '<div class="bk-opt sa-opt">' . $box . $q . $h . '</div>';
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
$JS_SCRIPT = aut_adm_assets();
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:10px 12px; margin:0 0 10px; }
#bkadm .bk-sec h2 { margin:0 0 6px; font-size:14px; color:#0254a8; }
#bkadm .bk-h3 { font-size:13px; color:#01367c; margin:10px 0 4px; }
#bkadm label { display:inline-block; margin:3px 8px 3px 0; font-size:13px; }
#bkadm input[type=text], #bkadm input[type=number], #bkadm input[type=datetime-local], #bkadm select {
    padding:4px 6px; border:1px solid #d2d4d6; border-radius:5px; font-size:13px; }
#bkadm .bk-row { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:flex-end; }
#bkadm .bk-f { display:flex; flex-direction:column; gap:3px; }
#bkadm .bk-f > span { font-size:12px; color:#7d8183; }
#bkadm .bk-chk { display:block; margin:3px 0; font-size:13px; }
#bkadm .bk-opt { margin:3px 0; }
#bkadm .bk-opt > .bk-chk { display:inline; margin:0; }
#bkadm .bk-btn { display:inline-block; padding:4px 10px; border:1px solid #d2d4d6; border-radius:5px;
    background:#f7f7f7; color:#20263d; font-size:13px; cursor:pointer; text-decoration:none; }
#bkadm .bk-btn:hover { background:#f0f4ff; border-color:#a7d6ff; }
#bkadm .bk-msg { padding:6px 10px; border-radius:6px; margin:0 0 10px; font-size:13px; }
#bkadm .bk-hint { margin:3px 0 0; font-size:12px; color:#7d8183; }
#bkadm .bk-state { margin:6px 0 0; font-size:12px; color:#4c4e50; }
#bkadm .bk-state b { color:crimson; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:3px 8px; text-align:left; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-scroll { overflow-x:auto; }
#bkadm table.bk-sesrules { margin:4px 0; }
#bkadm table.bk-sesrules select, #bkadm table.bk-sesrules input { font-size:13px; }
#bkadm .bk-ses-on { color:#04ac0b; font-weight:700; }
#bkadm .bk-ses-off { color:#a86b00; }
#bkadm .bk-gauge { display:inline-block; width:130px; height:9px; background:#e9ecef;
    border-radius:5px; overflow:hidden; vertical-align:middle; margin-right:7px; }
#bkadm .bk-gauge i { display:block; height:100%; background:#0254a8; }
#bkadm .bk-adv > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:6px 0 6px; }
#bkadm .bk-adv h3 { font-size:13px; color:#01367c; margin:10px 0 4px; }
#bkadm .bk-cat-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;
    border:1px solid #e2e6ee; border-radius:6px; padding:6px 8px; margin:0 0 6px; }
#bkadm .bk-cat-row select[multiple] { min-width:150px; padding:2px 4px; }
#bkadm .bk-cat-del { border:1px solid #d2d4d6; background:#fff; color:#c0392b; border-radius:5px;
    padding:4px 8px; cursor:pointer; font-size:13px; align-self:center; }
#bkadm .bk-cat-del:hover { background:#ffd6db; }
#bkadm .bk-add { background:#f0f4ff; color:#0254a8; border-color:#a7d6ff; margin:2px 0 4px; }
#bkadm .bk-sim-out { margin-top:8px; padding:8px 10px; border:1px solid #a7d6ff; border-radius:8px;
    background:#f7faff; max-width:340px; }
#bkadm .bk-sim-t { width:100%; border-collapse:collapse; font-size:13px; }
#bkadm .bk-sim-t td { padding:2px 0; border:0; }
#bkadm .bk-sim-t td:last-child { text-align:right; white-space:nowrap; }
#bkadm .bk-sim-tot { margin:6px 0 0; padding-top:6px; border-top:1px solid #cfe0f5; font-size:13px; color:#01367c; }
#bkadm .bk-sim-tot b { font-size:16px; }
#bkadm .bk-pay-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:4px 0; }
#bkadm .bk-pay-name { min-width:150px; margin:0; }
#bkadm .bk-pay-info { flex:1 1 240px; }
#bkadm .bk-levels { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-start; }
#bkadm .bk-lvl { flex:1 1 200px; margin:0; text-align:left; cursor:pointer; display:block;
    border:1px solid #d2d4d6; border-radius:8px; background:#fff; padding:6px 10px; font:inherit; }
#bkadm .bk-lvl input { position:absolute; opacity:0; pointer-events:none; }
#bkadm .bk-lvl:focus-within { outline:2px solid #a7d6ff; }
#bkadm .bk-lvl:hover { border-color:#0254a8; }
#bkadm .bk-lvl.on { border-color:#0254a8; background:#eaf1fb; box-shadow:0 0 0 1px #0254a8 inset; }
#bkadm .bk-lvl-n { display:inline-flex; width:20px; height:20px; border-radius:50%; background:#0254a8;
    color:#fff; align-items:center; justify-content:center; font-size:12px; font-weight:700; margin-right:6px; }
#bkadm .bk-lvl.on .bk-lvl-n { background:#01367c; }
#bkadm .bk-lvl.chosen { border-color:#e57b10; box-shadow:0 0 0 1px #e57b10 inset; }
#bkadm .bk-lvl-t { font-weight:700; color:#01367c; font-size:13px; }
#bkadm .bk-lvl .sa-help { margin:4px 0 0; }
#bkadm .bk-lvl-wait { margin:8px 0 0; padding:6px 10px; border-radius:6px; background:#fdebd6;
    border:1px solid #cb8137; color:#7a4a10; }
#bkadm .bk-link { margin:8px 0 0; font-size:13px; }
#bkadm .bk-link a { font-family:Consolas, monospace; font-size:12px; }
#bkadm .bk-shortcuts { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:8px 0 0; }

/* Tabs of levels 2 and 3: display only, every field stays in the form. */
#bkadm .bk-tabs { display:flex; flex-wrap:wrap; gap:2px; margin:12px 0 8px; border-bottom:2px solid #0254a8; }
#bkadm .bk-tab, #bkadm .bk-cat-tab { padding:5px 12px; border:1px solid #d2d4d6; border-bottom:0; border-radius:6px 6px 0 0; background:#f7f7f7;
    color:#20263d; font:inherit; font-size:13px; cursor:pointer; }
#bkadm .bk-tab:hover, #bkadm .bk-cat-tab:hover { background:#f0f4ff; }
#bkadm .bk-tab.on, #bkadm .bk-cat-tab.on { background:#0254a8; border-color:#0254a8; color:#fff; font-weight:600; }
#bkadm .bk-tab-n, #bkadm .bk-tab-w { display:inline-block; margin-left:6px; padding:0 6px; border-radius:8px; font-size:11px; font-weight:700; }
#bkadm .bk-tab-n { background:#e57b10; color:#fff; }
#bkadm .bk-tab-w { background:#c0392b; color:#fff; }

/* Categories open to online registration: a grid painted with the mouse. */
#bkadm .bk-cat-bar { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:4px 0 8px; }
/* One grid per departure: its tabs (the look of the level tabs), the copy bar of the field page. */
#bkadm .bk-cat-tabs { display:flex; flex-wrap:wrap; gap:2px; margin:8px 0 6px; border-bottom:2px solid #0254a8; }
#bkadm .bk-cat-sep { width:1px; align-self:stretch; background:#d2d4d6; margin:0 2px; }
#bkadm .bk-cat-bar label { white-space:nowrap; }
#bkadm .bk-cat-msg { color:#035c07; font-weight:600; }
#bkadm .bk-cat-msg.ko { color:#a80000; }
#bkadm .bk-cat-n { margin-right:6px; }
#bkadm .bk-cat-scroll { overflow-x:auto; }
#bkadm .bk-cats { border-collapse:separate; border-spacing:2px; user-select:none; font-size:11px; }
#bkadm .bk-cats th { font-weight:600; color:#01367c; white-space:nowrap; }
#bkadm .bk-cats thead th { text-align:center; }
#bkadm .bk-cats tbody th { text-align:center; }
#bkadm .bk-cat-h { border:0; background:none; padding:2px 4px; font:inherit; font-weight:600; color:#01367c; cursor:pointer; border-radius:4px; }
#bkadm .bk-cat-h:hover { background:#eaf1fb; }
/* A header fills its cell, its text centred: classes over their column, divisions beside their row. */
#bkadm .bk-cats .bk-cat-h { display:block; width:100%; text-align:center; }
#bkadm .bk-cat-h small { font-weight:400; color:#5b6470; }
#bkadm .bk-cat { min-width:40px; padding:3px 4px; text-align:center; line-height:1.15; cursor:pointer; border-radius:4px;
    background:#eef0f2; color:#9aa0a6; border:1px solid #dfe2e5; }
#bkadm .bk-cat.on { background:#0254a8; color:#fff; border-color:#0254a8; font-weight:600; }
#bkadm .bk-cat:hover { box-shadow:0 0 0 2px #a7d6ff; }
#bkadm .bk-cat-na { background:transparent; }
</style>
<?php
// "Copy from…": at the right of the title, its own form.
$copy = '';
if ($copyAdmin) {
    $copy = bk_fld(bk_t('AcCopyCode'), '<input type="text" name="copy_src_text" placeholder="' . bk_e(bk_t('AcCopyCodePh')) . '" autocomplete="off" required>')
        . '<button type="submit" name="copy_from" value="1" class="sa-btn sa-primary">' . bk_e(bk_t('AcCopyBtn')) . '</button>';
} elseif (!$copySources) {
    $copy = '<p class="sa-hint">' . bk_e(bk_t('AcCopyNone')) . '</p>';
} else {
    $o = '<option value="">' . bk_e(bk_t('ChooseDash')) . '</option>';
    foreach ($copySources as $s) {
        $o .= '<option value="' . intval($s->ToId) . '">' . bk_e($s->ToName . ' (' . $s->ToCode . ') — ' . bk_date_fr($s->ToWhenFrom)) . '</option>';
    }
    $copy = bk_fld(bk_t('AcCopySrc'), '<select name="copy_src" required>' . $o . '</select>')
        . '<button type="submit" name="copy_from" value="1" class="sa-btn sa-primary">' . bk_e(bk_t('AcCopyBtn')) . '</button>';
}
// The licensed volunteers come along only when asked (unticked by default: user's choice).
if ($copyAdmin || $copySources) {
    $copy = str_replace('<button type="submit" name="copy_from"', '<label class="sa-chk"><input type="checkbox" name="copy_staff" value="1"> <span>'
        . bk_e(bk_t('AcCopyStaff')) . '</span></label><button type="submit" name="copy_from"', $copy);
}
$copyBox = '<details class="sa-copy"><summary>' . bk_e(bk_t('AcCopySummary')) . '</summary><div class="sa-copy-body">'
    . '<p class="sa-hint">' . bk_t('AcCopyHint') . '</p>'
    . '<form method="post" onsubmit="return confirm(' . htmlspecialchars(json_encode(bk_t('AcCopyConfirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')">'
    . bk_csrf_field() . $copy . '</form></div></details>';

$out = aut_adm_open('booking/admin/competition.php', bk_t('Brand'), $copyBox) . '<div id="bkadm">'
    . ($msg ? '<div class="sa-msg sa-ok" data-sa-toast>' . bk_e($msg) . '</div>' : '')
    . ($err ? '<div class="sa-msg sa-err">' . bk_e($err) . '</div>' : '');

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

/*
 * The page shows every level; competition.js shows the parts of the level chosen ([data-lvl])
 * and, at levels 2 and 3, of its tab ([data-tab]). Every field stays in the form: a level picked
 * and left again keeps what was typed in it. What is visible before the script runs is the
 * current level on its first tab — the same thing.
 */
$tabsOf = array(
    2 => array('fin' => bk_t('AcL2Title'), 'state' => bk_t('AcTabState')),
    3 => array('period' => bk_t('AcPeriod'), 'restrict' => bk_t('AcTabRestrict'), 'see' => bk_t('AcWhatSee'),
        'tariffs' => bk_t('MnFees'), 'state' => bk_t('AcTabState')),
);
$panel = function ($levels, $tab, $html) use ($level, $tabsOf, $cfg) {
    $lv = array_map('intval', explode(' ', $levels));
    $shown = in_array($level, $lv, true);
    if ($shown && $tab !== '' && isset($tabsOf[$level])) $shown = $tab === array_keys($tabsOf[$level])[0];
    if ($shown && $tab === 'tariffs' && $level === 1) $shown = !empty($cfg->BcPayments);
    return '<div class="bk-panel" data-lvl="' . $levels . '"' . ($tab !== '' ? ' data-tab="' . $tab . '"' : '')
        . ($shown ? '' : ' hidden') . '>' . $html . '</div>';
};

// Opening of the registration on this server: the level, chosen in the form, applied when saving.
// The registration link right under it, for the levels that publish.
list($qPub, $hPub) = bk_help(bk_t('AcPubWhat') . '<br>' . bk_t('AcNotIanseoNet'));
$levels = '';
foreach (array(1 => 'AcLvl1', 2 => 'AcLvl2', 3 => 'AcLvl3') as $n => $key) {
    list($q, $h) = bk_help(bk_e(bk_t($key . 'D')));
    $levels .= '<label class="bk-lvl ' . ($level === $n ? 'on' : '') . '"><input type="radio" name="level" value="' . $n . '"'
        . ($level === $n ? ' checked' : '') . '>'
        . '<span class="bk-lvl-t"><span class="bk-lvl-n">' . $n . '</span>' . bk_e(bk_t($key)) . $q . '</span>' . $h . '</label>';
}
list($qLink, $hLink) = bk_help(bk_e(bk_t('AcLinkHint')));
$out .= '<form method="post" id="bk-cfg" data-level="' . $level . '">' . aut_adm_default_submit() . bk_csrf_field()
    . '<input type="hidden" name="save_all" value="1">'
    . '<div class="bk-sec"><h2>' . bk_e(bk_t('AcOpenTitle')) . $qPub . '</h2>' . $hPub
    . '<div class="bk-levels">' . $levels . '</div>'
    . '<div class="bk-link" data-lvl="2 3"' . ($level >= 2 ? '' : ' hidden') . '>' . bk_e(bk_t('AcRegLink'))
    . ' <a href="' . bk_e($publicUrl) . '" target="_blank" rel="noopener">' . bk_e($publicUrl) . '</a>' . $qLink . $hLink . '</div>'
    . '<p class="bk-lvl-wait" id="bk-lvl-wait" hidden>' . bk_e(bk_t('AcLvlPending')) . '</p></div>';

// ---- Level 1 (closed): the payments and the shop used or not; their tariffs below as soon as
//      the box is ticked (saved with it).
list($q, $h) = bk_help(bk_e(bk_t('AcL1Hint')));
$out .= $panel('1', '', '<div class="bk-sec"><div class="bk-opt"><label class="bk-chk"><input type="checkbox" name="payments" value="1" id="bk-payments"'
    . (!empty($cfg->BcPayments) ? ' checked' : '') . '> ' . bk_t('AcL1Box') . '</label>' . $q . '</div>' . $h
    . (!empty($cfg->BcPayments) ? '<p class="bk-shortcuts"><a class="bk-btn" href="' . $SHOP_ADMIN . '">' . bk_e(shp_t('MnuTitle')) . ' →</a> '
        . '<a class="bk-btn" href="' . $ADMIN . 'dues.php">' . bk_e(bk_t('Payments')) . ' →</a></p>' : '')
    . '</div>');

// ---- Tabs of levels 2 and 3. The registration state carries what needs looking at.
$waitN = count($waitList['waiting']);
$rulesKo = 0;
foreach ($rules as $rc) if (!$rc['ok']) $rulesKo++;
$stateBadge = ($waitN ? '<span class="bk-tab-n">' . $waitN . '</span>' : '') . ($rulesKo ? '<span class="bk-tab-w">!</span>' : '');
foreach ($tabsOf as $l => $tabs) {
    $bar = '';
    $first = true;
    foreach ($tabs as $t => $lab) {
        $bar .= '<button type="button" class="bk-tab' . ($first ? ' on' : '') . '" data-tab="' . $t . '" role="tab" aria-selected="'
            . ($first ? 'true' : 'false') . '">' . bk_e($lab) . ($t === 'state' ? $stateBadge : '') . '</button>';
        $first = false;
    }
    $out .= '<div class="bk-tabs" data-tabs="' . $l . '" role="tablist"' . ($level === $l ? '' : ' hidden') . '>' . $bar . '</div>';
}

// ---- Level 2, "To finish": the fee (its own field, kept equal to the one of the tariffs by the
//      script), and the constraints of the field.
list($qFee, $hFee) = bk_help(bk_t('AcL2FeeHint'));
list($qFld, $hFld) = bk_help(bk_e(bk_t('AcFieldHint')));
$out .= $panel('2', 'fin', '<div class="bk-sec"><div class="bk-row">'
    . bk_fld(bk_t('AcRegFee', $CUR), '<input type="text" name="fee_simple" data-fee size="8" value="' . bk_e(bk_amt((float) $cfg->BcFee)) . '">', $qFee)
    . '</div>' . $hFee
    . '<p class="bk-shortcuts"><a class="bk-btn" href="' . $ADMIN . 'field.php">' . bk_e(bk_t('MnuField')) . ' →</a>' . $qFld . '</p>' . $hFld . '</div>');

// ---- Level 3, registration period. The current state only when it is level 3's own.
$kinds = '';
foreach (bk_restrict_kinds() as $k => $lab) {
    $kinds .= '<option value="' . bk_e($k) . '"' . ($cfg3->BcRestrictKind === $k ? ' selected' : '') . '>' . bk_e($lab) . '</option>';
}
list($q, $h) = bk_help(bk_e(bk_t('AcPeriodHint')));
$out .= $panel('3', 'period', '<div class="bk-sec"><div class="bk-row">'
    . bk_fld(bk_t('AcOpenFrom'), '<input type="datetime-local" name="from" value="' . bk_e(bk_dtval($cfg3->BcOpenFrom)) . '">')
    . bk_fld(bk_t('AcOpenTo'), '<input type="datetime-local" name="to" value="' . bk_e(bk_dtval($cfg3->BcOpenTo)) . '">', $q)
    . '</div>' . $h
    . ($level === 3 ? '<p class="bk-state">' . bk_e(bk_t('AcGeoState')) . ' <b>' . bk_e(bk_t($cfg->BcIsOpen ? 'AcStateOpen' : 'AcStateOut')) . '</b></p>' : '')
    . bk_adm_session_rules($TOUR, $cfg, $sessions) . '</div>');

/**
 * Categories open to online registration (user's choice, 2026-10-14), one grid per departure
 * (2026-10-15): divisions in rows, classes in columns, one cell per pair the class takes (empty
 * otherwise); coloured = open, greyed = closed. Painted with the mouse, a header switches its row
 * or column (assets/competition.js); a tab per departure, and the copy bar of the field page
 * (take from / copy to) working in the page. The closed ones go in a hidden field per departure,
 * saved with the form. $raw: the value of BcCategories shown (the settings level 2 put aside).
 */
function bk_adm_cats_grid($tourId, $raw, $sessions)
{
    $g = bk_cat_grid($tourId, array());
    $map = bk_cat_decode($tourId, $raw);
    if (!$g['divs'] || !$g['classes'] || !$map) return '';
    $head = '<tr><th class="bk-cat-corner"></th>';
    foreach ($g['classes'] as $c) {
        $head .= '<th><button type="button" class="bk-cat-h" data-col="' . bk_e($c[0]) . '" title="' . bk_e($c[1]) . '">' . bk_e($c[0]) . '</button></th>';
    }
    $names = array();
    foreach ($sessions as $s) $names[intval($s->SesOrder)] = (string) $s->SesName;
    $many = count($map) > 1;
    $tabs = $panes = '';
    $first = true;
    foreach ($map as $o => $forbid) {
        $rows = '';
        $closed = array();
        $on = $all = 0;
        foreach ($g['divs'] as $d) {
            $rows .= '<tr><th><button type="button" class="bk-cat-h" data-row="' . bk_e($d[0]) . '" title="' . bk_e($d[1]) . '">'
                . bk_e($d[0]) . ' <small>' . bk_e($d[1]) . '</small></button></th>';
            foreach ($g['classes'] as $c) {
                if (!isset($g['cells'][$d[0]][$c[0]])) { $rows .= '<td class="bk-cat-na"></td>'; continue; }
                $open = !isset($forbid[$d[0] . '|' . $c[0]]);
                $all++;
                if ($open) $on++; else $closed[] = $d[0] . '|' . $c[0];
                $rows .= '<td class="bk-cat' . ($open ? ' on' : '') . '" data-c="' . bk_e($d[0] . '|' . $c[0]) . '" data-row="' . bk_e($d[0])
                    . '" data-col="' . bk_e($c[0]) . '" title="' . bk_e($d[1] . ' — ' . $c[1]) . '">' . bk_e($d[0]) . '<br>' . bk_e($c[0]) . '</td>';
            }
            $rows .= '</tr>';
        }
        $copy = '';
        if ($many) {
            $tabs .= '<button type="button" class="bk-cat-tab' . ($first ? ' on' : '') . '" data-ses="' . $o . '" role="tab" aria-selected="'
                . ($first ? 'true' : 'false') . '">' . bk_e(bk_t('DepCap', $o) . (($names[$o] ?? '') !== '' ? ' — ' . $names[$o] : '')) . '</button>';
            $opts = '<option value="">' . bk_e(bk_t('FldDepPh')) . '</option>';
            foreach (array_keys($map) as $x) if ($x !== $o) $opts .= '<option value="' . $x . '">' . bk_e(bk_t('DepCap', $x)) . '</option>';
            $copy = '<span class="bk-cat-sep"></span>'
                . '<label>' . bk_e(bk_t('FldCopyFrom')) . ' <select data-copy-sel="from">' . $opts . '</select></label>'
                . '<button type="button" class="sa-btn" data-copy="from" title="' . bk_e(bk_t('FldCopyFromTip')) . '">' . bk_e(bk_t('FldCopyFromBtn')) . '</button>'
                . '<span class="bk-cat-sep"></span>'
                . '<label>' . bk_e(bk_t('FldCopyTo')) . ' <select data-copy-sel="to">' . $opts . '</select></label>'
                . '<button type="button" class="sa-btn" data-copy="to" title="' . bk_e(bk_t('FldCopyToTip')) . '">' . bk_e(bk_t('FldCopyToBtn')) . '</button>'
                . '<span class="bk-cat-msg" aria-live="polite"></span>';
        }
        $panes .= '<div class="bk-cat-pane" data-ses="' . $o . '"' . ($first ? '' : ' hidden') . '>'
            . '<div class="bk-cat-bar"><span class="bk-cat-n"><b class="bk-cat-on">' . $on . '</b> / ' . $all . ' ' . bk_e(bk_t('AcCatsCount')) . '</span>'
            . '<button type="button" class="sa-btn" data-cats="1">' . bk_e(bk_t('AcCatsAll')) . '</button>'
            . '<button type="button" class="sa-btn" data-cats="0">' . bk_e(bk_t('AcCatsNone')) . '</button>' . $copy . '</div>'
            . '<div class="bk-cat-scroll"><table class="bk-cats"><thead>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<input type="hidden" name="cat_forbid[' . $o . ']" data-forbid value="' . bk_e(implode(',', $closed)) . '"></div>';
        $first = false;
    }
    return '<div class="bk-sec" id="bk-cats">' . bk_title('h2', bk_t('AcCats'), bk_e(bk_t('AcCatsHint')))
        . ($many ? '<div class="bk-cat-tabs" role="tablist">' . $tabs . '</div>' : '')
        . $panes . '<input type="hidden" name="cats_present" value="1"></div>';
}

// ---- Level 3, restrictions: geographic, placement and validation, then the categories allowed.
$out .= $panel('3', 'restrict', '<div class="bk-sec">' . bk_title('h2', bk_t('AcGeo'), bk_e(bk_t('AcGeoHint'))) . '<div class="bk-row">'
    . bk_fld(bk_t('AcGeoFor'), '<select name="kind">' . $kinds . '</select>')
    . bk_fld(bk_t('AcGeoCode'), '<input type="text" name="code" size="8" maxlength="12" value="' . bk_e($cfg3->BcRestrictCode) . '">')
    . bk_fld(bk_t('AcGeoAll'), '<input type="datetime-local" name="restrict_to" value="' . bk_e(bk_dtval($cfg3->BcRestrictTo)) . '">')
    . '</div>'
    . ($level === 3 && $cfg->BcRestrictKind !== ''
        ? '<p class="bk-state">' . bk_e(bk_t('AcGeoState')) . ' <b>' . bk_e(bk_t($cfg->BcAllOpen ? 'AcGeoOpen' : 'AcGeoRestricted')) . '</b></p>' : '')
    . '</div>'
    . '<div class="bk-sec">' . bk_title('h2', bk_t('AcPlacement'), $isDrom ? bk_e(bk_t('AcDromHint')) : bk_t('AcFedRules'))
    . ($isDrom
        ? '<div class="bk-row">'
          . bk_fld(bk_t('AcMaxClub'), '<input type="number" name="max_club" min="1" max="20" value="' . intval($cfg3->BcMaxPerClubPerTarget) . '">')
          . bk_fld(bk_t('AcMinClubs'), '<input type="number" name="min_clubs" min="1" max="50" value="' . intval($cfg3->BcMinClubsPerSession) . '">') . '</div>'
        : '')
    . bk_chk('manual_validation', !empty($cfg3->BcManualValidation), bk_t('AcManualBox'), '', bk_t('AcManualHint'))
    . '<input type="hidden" name="single_reg_present" value="1">'
    . bk_chk('single_reg', !empty($cfg3->BcSingleReg), bk_e(bk_t('AcSingleReg')), '', bk_e(bk_t('AcSingleRegHint')))
    . '</div>'
    . bk_adm_cats_grid($TOUR, (string) ($cfg3->BcCategories ?? ''), $sessions));

// ---- Level 3, what the archers see. Documents: the ianseo.net address is no longer asked, it
//      is rebuilt from the online id given with the publication codes — only whether to show it.
$hasMandate = trim((string) ($cfg3->BcMandate ?? '')) !== '';
$see = '<div class="bk-sec">'
    . bk_chk('show_gauges', $cfg3->BcShowGauges, bk_e(bk_t('AcShowGauges')))
    . bk_chk('show_assign', $cfg3->BcShowAssignment, bk_e(bk_t('AcShowAssign')))
    . bk_chk('scoresheet', $cfg3->BcAllowScoresheet, bk_e(bk_t('AcScoresheet')))
    . ($hasMandate
        ? '<input type="hidden" name="show_mandate_present" value="1">'
          . bk_chk('show_mandate', bk_mandate_visible($cfg3), bk_t('AcShowMandate', bk_e($ADMIN . 'mandate.php')))
        : '<p class="bk-hint">' . bk_t('AcNoMandate', bk_e($ADMIN . 'mandate.php')) . '</p>');
$ianseoUrl      = bk_ianseo_url($TOUR);
$ianseoUrlSaved = trim((string) ($cfg3->BcIanseoUrl ?? ''));
$see .= bk_title('h3', bk_t('DocsTitle'), bk_e(bk_t('AcDocsHint')) . '<br>' . bk_e(bk_t('AcOfficialHint')));
if ($ianseoUrl !== '') {
    $see .= '<input type="hidden" name="ianseo_present" value="1">'
        . bk_chk('show_ianseo', $ianseoUrlSaved !== '', bk_t('AcIanseoLink', $onlineId) . ' <a href="' . bk_e($ianseoUrl)
            . '" target="_blank" rel="noopener">' . bk_e($ianseoUrl) . '</a>');
} elseif ($ianseoUrlSaved !== '') {
    // Derived value whose source is gone (re-import without an online id, or an address typed
    // when the field was free): said here, and the next save removes it — there is no page to
    // point to any more.
    $see .= '<input type="hidden" name="ianseo_present" value="1"><p class="bk-hint" style="color:#a86b00">'
        . bk_e(bk_t('AcIanseoStale', $ianseoUrlSaved)) . '</p>';
}
$dossardCard = bk_dossard_card($TOUR);
$see .= '<input type="hidden" name="docs_present" value="1">'
    . bk_chk('show_program', !empty($cfg3->BcShowProgram), bk_e(bk_t('AcShowProgram')))
    . bk_chk('show_participants', !empty($cfg3->BcShowParticipants), bk_e(bk_t('AcShowParticipants')))
    . bk_chk('show_results', !empty($cfg3->BcShowResults), bk_e(bk_t('AcShowResults')))
    . bk_chk('show_dossard', !empty($cfg3->BcShowDossard), bk_t('AcShowDossard', bk_e($CFG->ROOT_DIR . 'Accreditation/IdCards.php?CardType=Q'))
        . ($dossardCard === null ? ' <span class="bk-hint" style="color:#a86b00">' . bk_e(bk_t('AcNoDossard')) . '</span>' : ''))
    // Wishes, waiting list, after the competition.
    . bk_title('h3', bk_t('AcWishes'), bk_e(bk_t('AcWishesHint')))
    . bk_chk('wish_letter', $cfg3->BcWishLetter, bk_e(bk_t('AcWishLetter')))
    . bk_chk('wish_with', $cfg3->BcWishWith, bk_e(bk_t('AcWishWith')))
    . bk_chk('wish_free', $cfg3->BcWishFree, bk_e(bk_t('AcWishFree')))
    . '<input type="hidden" name="waitlist_present" value="1">'
    . bk_chk('waitlist', !isset($cfg3->BcWaitlist) || !empty($cfg3->BcWaitlist), bk_t('AcWaitBox'))
    . bk_title('h3', bk_t('AcAfter')) . '<input type="hidden" name="survey_present" value="1">'
    . bk_chk('survey', !isset($cfg3->BcSurvey) || !empty($cfg3->BcSurvey), bk_t('AcSurveyBox', bk_e($ADMIN . 'survey.php')), '', bk_e(bk_t('AcSurveyHint')))
    . '</div>';
$out .= $panel('3', 'see', $see);

// ---- Tariffs and payment methods: ONE block for level 3 and for a closed competition that
//      uses the payments and the shop (level 1, participants imported in ianseo).
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
list($q, $h) = bk_help(bk_e(bk_t('AcBaseFeeHint')));
$out .= $panel('1 3', 'tariffs', '<input type="hidden" name="tariffs_present" value="1">'
    . '<div class="bk-sec">' . bk_title('h2', bk_t('MnFees')) . '<div class="bk-row">'
    . bk_fld(bk_t('AcBaseFee', $CUR), '<input type="text" name="fee" data-fee size="8" value="' . bk_e(bk_amt((float) $cfg->BcFee)) . '">', $q) . '</div>' . $h
    . '<details class="bk-adv"' . (bk_pricing_is_advanced($pricing) ? ' open' : '') . '><summary>' . bk_e(bk_t('AcAdvSummary')) . '</summary>'
    . bk_title('h3', bk_t('AcByCat'), bk_t('AcHowPrice', bk_e(bk_eur(0))) . '<br>' . bk_e(bk_t('AcByCatHint')))
    . '<div id="bk-cat-list">' . $cats . '</div>'
    . '<button type="button" class="bk-btn bk-add" onclick="bkAddCat()">' . bk_e(bk_t('AcAddRule')) . '</button>'
    . '<template id="bk-cat-tpl">' . bk_cat_row('__i__', array(), $divs, $classes, $CUR) . '</template>'
    . bk_title('h3', bk_t('AcByDep'), bk_e(bk_t('AcByDepHint')))
    . ($sessions ? '<div class="bk-row">' . $deps . '</div>' : '<p class="bk-hint">' . bk_e(bk_t('AcNoSessionYet')) . '</p>')
    . bk_title('h3', bk_t('AcByProv'), bk_e(bk_t('AcByProvHint'))) . '<div class="bk-row">'
    . bk_fld(bk_t('AcLocalDept'), '<input type="text" name="prov_deptcode" size="4" maxlength="2" value="' . bk_e($provDeptDef) . '">')
    . bk_fld(bk_t('AcDeltaDept', $CUR), '<input type="text" name="prov_dept" size="6" value="' . bk_e(bk_amt($pricing['prov']['dept'] ?: '')) . '">')
    . bk_fld(bk_t('AcLocalRegion'), '<input type="text" name="prov_regioncode" size="4" maxlength="2" value="' . bk_e($provRegionDef) . '">')
    . bk_fld(bk_t('AcDeltaRegion', $CUR), '<input type="text" name="prov_region" size="6" value="' . bk_e(bk_amt($pricing['prov']['region'] ?: '')) . '">')
    . '</div>' . bk_title('h3', bk_t('AcRankTitle'), bk_e(bk_t('AcRankHint'))) . '<div class="bk-row">'
    . bk_fld(bk_t('AcFromNth', array('n' => 2, 'cur' => $CUR)), '<input type="text" name="rank[2]" size="6" value="' . bk_e(bk_amt($pricing['rank']['2'] ?? '')) . '">')
    . bk_fld(bk_t('AcFromNth', array('n' => 3, 'cur' => $CUR)), '<input type="text" name="rank[3]" size="6" value="' . bk_e(bk_amt($pricing['rank']['3'] ?? '')) . '">')
    . '</div>' . bk_title('h3', bk_t('AcPreview'), bk_e(bk_t('AcPreviewHint')))
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
    . '<div class="bk-sec">' . bk_title('h2', bk_t('PayMeansTitle'), bk_e(bk_t('AcPayHint'))) . $payRows . '</div>');

// End of the form (its save button is fixed at the bottom right).
$out .= '</form>' . aut_adm_save('bk-cfg');

// ---- Levels 2 and 3, registration state: read from ianseo and the waiting list; its own small
//      forms (waiting list), hence outside the settings form.
list($qFld, $hFld) = bk_help(bk_e(bk_t('AcFieldHint')));
$state = '<div class="bk-sec">' . bk_title('h2', bk_t('TgDepartures'), bk_e(bk_t('AcDepsHint')));
if (!$sessions) {
    $state .= '<p class="bk-hint">' . bk_t('AcNoDepConf') . '</p>';
} else {
    $state .= '<table class="bk-t"><tr><th>' . bk_e(bk_t('SsDeparture')) . '</th><th>' . bk_e(bk_t('AcTargets')) . '</th><th>'
        . bk_e(bk_t('AcPerTarget')) . '</th><th>' . bk_e(bk_t('SsTotal')) . '</th><th>' . bk_e(bk_t('AcOccupancy')) . '</th></tr>';
    foreach ($sessions as $s) {
        $pl = intval($s->Places); $pr = intval($s->Pris);
        $pc = $pl > 0 ? min(100, round($pr * 100 / $pl)) : 0;
        $state .= '<tr><td>' . intval($s->SesOrder) . ($s->SesName ? ' — ' . bk_e($s->SesName) : '') . '</td><td>' . intval($s->SesTar4Session) . '</td>'
            . '<td>' . intval($s->SesAth4Target) . '</td><td>' . $pl . '</td>'
            . '<td><span class="bk-gauge"><i style="width:' . $pc . '%"></i></span>' . $pr . ' / ' . $pl . '</td></tr>';
    }
    $state .= '</table>';
}
$state .= '<p class="bk-shortcuts"><a class="bk-btn" href="' . $ADMIN . 'field.php">' . bk_e(bk_t('MnuField')) . ' →</a>' . $qFld . '</p>' . $hFld . '</div>';

// Waiting list (lib/waitlist.php): order of arrival; register by hand (even on a full departure:
// the organiser's call) or remove.
if ($waitList['waiting'] || $waitList['done']) {
    $state .= '<div class="bk-sec">' . bk_title('h2', bk_t('AcWaitTitle'), $waitList['waiting'] ? bk_e(bk_t('AcWaitHint')) : '');
    if (!bk_waitlist_on($cfg)) $state .= '<p class="bk-hint">' . bk_e(bk_t('AcWaitOff')) . '</p>';
    if ($waitList['waiting']) {
        $state .= (empty($cfg->BcIsOpen) ? '<p class="bk-hint">' . bk_t('AcWaitFrozen') . '</p>' : '')
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
            $state .= '<tr><td>' . ($i + 1) . '</td><td>' . bk_e(trim($w->LueFamilyName . ' ' . $w->LueName)) . ' <span class="bk-hint">'
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
        $state .= '</table>';
    }
    if ($waitList['done']) {
        $state .= '<h3 class="bk-h3">' . bk_e(bk_t('AcLastResults')) . '</h3><table class="bk-t"><tr><th>' . bk_e(bk_t('ColArcher')) . '</th><th>'
            . bk_e(bk_t('AcOn')) . '</th><th>' . bk_e(bk_t('AcResult')) . '</th></tr>';
        foreach ($waitList['done'] as $w) {
            $state .= '<tr><td>' . bk_e(trim($w->LueFamilyName . ' ' . $w->LueName)) . ' <span class="bk-hint">' . bk_e($w->BwLicence)
                . '</span></td><td>' . bk_e(bk_date_fr($w->BwDone)) . '</td><td>'
                . bk_e(intval($w->BwStatus) === 1 ? bk_t('AcWaitRegOn', intval($w->BwSession)) : bk_t('AcWaitRemovedX', $w->BwNote))
                . '</td></tr>';
        }
        $state .= '</table>';
    }
    $state .= '</div>';
}

// Check of the rules.
$state .= '<div class="bk-sec">' . bk_title('h2', bk_t('TgRulesTitle'), $rules ? bk_e(bk_t('AcRulesHint')) : '');
if (!$rules) {
    $state .= '<p class="bk-hint">' . bk_e(bk_t('AcNoRules')) . '</p>';
} else {
    $state .= '<table class="bk-t"><tr><th>' . bk_e(bk_t('SsDeparture')) . '</th><th>' . bk_e(bk_t('TgRegistered')) . '</th><th>'
        . bk_e(bk_t('TgClubs')) . '</th><th>' . bk_e(bk_t('AcRules')) . '</th></tr>';
    foreach ($rules as $rc) {
        $state .= '<tr><td>' . intval($rc['depart']) . ($rc['nom'] ? ' — ' . bk_e($rc['nom']) : '') . '</td><td>' . intval($rc['archers']) . '</td>'
            . '<td>' . bk_e(bk_t('AcClubsMin', array('n' => intval($rc['clubs']), 'min' => intval($rc['minClubs'])))) . '</td><td>';
        if ($rc['ok']) {
            $state .= '<span style="color:#04ac0b;font-weight:600">' . bk_e(bk_t('AcCompliant')) . '</span>';
        } else {
            $li = '';
            if (!$rc['clubsOk']) $li .= '<li>' . bk_e(bk_t('AcLessClubs', intval($rc['minClubs']))) . '</li>';
            foreach ($rc['exces'] as $ex) {
                $li .= '<li>' . bk_e(bk_t('AcTooManyLow', array('target' => intval($ex['cible']), 'n' => intval($ex['n']),
                    'club' => $ex['club'], 'max' => intval($rc['max'])))) . '</li>';
            }
            if (intval($rc['nonPlaces']) > 0) $li .= '<li>' . bk_e(bk_t('AcUnplaced', intval($rc['nonPlaces']))) . '</li>';
            if (!empty($rc['doublons'])) $li .= '<li>' . bk_e(bk_t('AcDupes')) . '</li>';
            $state .= '<span style="color:#c0392b;font-weight:600">' . bk_e(bk_t('AcReview')) . '</span>'
                . '<ul class="bk-hint" style="margin:4px 0 0; padding-left:18px; color:#a80000">' . $li . '</ul>';
        }
        $state .= '</td></tr>';
    }
    $state .= '</table>';
}
$state .= '</div>';
$out .= $panel('2 3', 'state', $state);

echo $out . '</div>' . aut_adm_close();

// Texts and number format for the two scripts below.
$seps = bk_number_seps();
$jsT = array(
    'lang' => aut_lang_code(), 'dec' => $seps['dec'], 'th' => $seps['thousands'], 'cur' => $CUR,
    'base' => bk_t('PriceBase'), 'cat' => bk_t('PriceCat'), 'catNamed' => bk_t('PriceCatNamed'), 'dep' => bk_t('DepCap'),
    'dept' => bk_t('PriceDept'), 'region' => bk_t('PriceRegion'), 'rank' => bk_t('PriceRank'),
    'catsFrom' => bk_t('AcCatsFrom'), 'catsTo' => bk_t('AcCatsTo'), 'pickSrc' => bk_t('FjPickSrc'), 'pickDest' => bk_t('FjPickDest'),
);
echo '<script>var BK_T = ' . json_encode($jsT, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', BK_CATN = ' . count($pricing['categories']) . ', BK_TOUR = ' . $TOUR . ';</script>';
$assets = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/assets/';
echo '<script src="' . $assets . 'competition.js?v=' . bk_e(bk_version()) . '"></script>';
echo '<script src="' . $assets . 'tariffs.js?v=' . bk_e(bk_version()) . '"></script>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
