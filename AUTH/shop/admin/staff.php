<?php
/**
 * admin/staff.php — the volunteers of the points of sale, of the check-in desk and of ianseo's own
 * screens of the open competition (organiser). Open as soon as the stands, the registry, the
 * equipment check or ianseo's screens (AUTH/vol-lib.php, admin/ianseo.php) are open to the volunteers.
 *
 * One team for the stands and the check-in desk, managed here (menu "Volunteers"). For a
 * computer, in the frame shared by AUTH's organiser pages (adm-lib.php). What the page does:
 *   - "Invite volunteers": a QR code (30 min, reusable, can be stopped) shown full screen;
 *   - or a licensee found by licence number or name (licence file, licensee space) and added at
 *     once with their rights — ahead of the competition and at a distance, no QR code (user's
 *     choice, 2026-10-12); they sign in with their licensee-space account;
 *   - beside the waiting requests, the officials of ianseo's field crew without an access yet
 *     (Tournament/ManStaffField.php), given one in a click; every licensed volunteer has an
 *     "Access" (preset rights) and a "Function" (ianseo's), the one proposing the other, the
 *     function written in ianseo's field crew (lib/officials.php — user's choice, 2026-10-13);
 *   - waiting requests, refreshed by themselves WHILE an invitation runs or requests wait (no
 *     request to the server otherwise): the organiser checks the 4-digit code shown on
 *     the volunteer's phone, picks a role preset for the stands ticked (or rights stand by stand),
 *     the rights at the check-in desk and the refund ceilings, then approves — or refuses;
 *   - the team: rights, ceilings, last activity (shown two minutes, then hidden: it is not
 *     refreshed — reload the page); change rights, withdraw access, reset a password
 *     (single-use QR code, 10 min), unlock; give a withdrawn access back (Cancel);
 *   - nothing else: the till and the desk on the organiser's own device are opened from their
 *     pages (user's choice: here only the volunteers);
 *   - the retention notice (when accounts without a licence open and are erased), always shown;
 *   - the journal of the decisions taken on the team.
 *
 * ianseo look and ACL of the core (participants, read-write). The page script talks to this same
 * file in JSON (header X-Shp, CSRF token of the session in the body).
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/staff-session.php';
require_once dirname(__DIR__) . '/lib/officials.php';
require_once dirname(__DIR__) . '/lib/invite.php';
require_once dirname(__DIR__) . '/lib/purge.php';
require_once dirname(__DIR__) . '/lib/ui.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_qr_svg

shp_schema();
shp_purge_due();

$TOUR = intval($_SESSION['TourId']);
$BY = shp_staff_by();
$SELF = shp_url('admin/staff.php');
$settings = shp_settings($TOUR);
$shopOn = $settings && intval($settings->SgEnabled) === 1;
$deskOn = shp_desk_on($TOUR);       // registry opened to the volunteers
$judgesOn = shp_judges_on($TOUR);   // equipment check opened to the volunteers
$ianseoOn = shp_ianseo_on($TOUR);   // ianseo's own screens opened to the volunteers (AUTH/vol-lib.php)
$enabled = $shopOn || $deskOn || $judgesOn || $ianseoOn;
$window = shp_window($TOUR);
$over = !$window || $window['purge_due'];
// The functions are ianseo's field crew: changed with the right the core asks on its own page.
$canFn = hasFullACL(AclCompetition, 'cData', AclReadWrite);

/** Everything the page script shows, in one answer. */
function ss_state($tour)
{
    return array('pending' => shp_fn_decorate(shp_staff_pending($tour), $tour), 'team' => shp_fn_decorate(shp_staff_team($tour), $tour),
        'invite' => shp_invite_running($tour), 'officials' => shp_fn_officials($tour));
}

/** The function chosen on a card, written in ianseo's field crew (and journaled) when allowed. */
function ss_function($tour, $staffId, array $in, $by)
{
    global $canFn;
    if (!$canFn || !array_key_exists('fn', $in) || $in['fn'] === null || $in['fn'] === '') return;
    $r = shp_fn_save($tour, intval($staffId), intval($in['fn_was'] ?? 0), intval($in['fn']));
    if ($r['changed']) shp_staff_log($tour, intval($staffId), 'function', $by, 'fn:' . $r['from'] . '>' . $r['to']);
}

/** An amount typed by the organiser ("12,50"), 0 when unreadable. */
function ss_amount($v)
{
    $v = str_replace(array(' ', "\u{00A0}"), '', str_replace(',', '.', trim((string) $v)));
    return preg_match('/^\d{1,4}(\.\d{1,2})?$/', $v) ? round((float) $v, 2) : 0.0;
}

/** QR code of an address, for the page script. */
function ss_qr(array $r)
{
    if (!empty($r['url'])) $r['svg'] = aut_qr_svg($r['url'], 640);
    unset($r['token']);
    return $r;
}

/* ================================================================== */
/* JSON for the page script                                            */
/* ================================================================== */
if ((string) ($_SERVER['HTTP_X_SHP'] ?? '') === '1') {
    // bytes: HTTP method names are ASCII
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        session_write_close();
        if (isset($_GET['find'])) {
            $f = shp_staff_find($TOUR, (string) $_GET['find']);
            $f['rows'] = shp_fn_decorate($f['rows'], $TOUR);
            shp_json(array('error' => 0) + $f);
        }
        shp_json(ss_state($TOUR));
    }
    if ($method !== 'POST' || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
    }
    $in = shp_json_in();
    if (!hash_equals(bk_csrf_token(), (string) ($in['csrf'] ?? ''))) shp_json_error('session', shp_t('ShStfErrSession'), 403);
    session_write_close();
    if (!$enabled) shp_json_error('shop_off', shp_t('ShErrShopOff'));
    $id = intval($in['id'] ?? 0);
    $rights = is_array($in['rights'] ?? null) ? $in['rights'] : array();
    $access = (string) ($in['access'] ?? '');
    $ianseo = (string) ($in['ianseo'] ?? '');
    switch ((string) ($in['act'] ?? '')) {
        case 'invite':
            $r = ss_qr(shp_invite_create($TOUR, 'enrol', $BY));
            break;
        case 'invite_stop':
            shp_invite_stop($TOUR, 'enrol', $BY);
            $r = array('error' => 0, 'msg' => '');
            break;
        case 'approve':
            $r = shp_staff_approve($TOUR, $id, $rights, ss_amount($in['refund_max'] ?? ''), ss_amount($in['refund_total'] ?? ''), $BY,
                (string) ($in['desk'] ?? ''), $access, $ianseo);
            if (!$r['error']) ss_function($TOUR, $id, $in, $BY);
            break;
        case 'add':
            if ($over) shp_json_error('over', shp_t('ShStfErrOver'));
            $r = shp_staff_add_licensee($TOUR, (string) ($in['licence'] ?? ''), $rights, ss_amount($in['refund_max'] ?? ''),
                ss_amount($in['refund_total'] ?? ''), $BY, (string) ($in['desk'] ?? ''), $access, $ianseo);
            if (!$r['error']) ss_function($TOUR, intval($r['id']), $in, $BY);
            break;
        case 'refuse':
            $r = shp_staff_refuse($TOUR, $id, $BY);
            break;
        case 'update':
            $r = shp_staff_update($TOUR, $id, $rights, ss_amount($in['refund_max'] ?? ''), ss_amount($in['refund_total'] ?? ''), $BY,
                (string) ($in['desk'] ?? ''), $access, $ianseo);
            if (!$r['error']) ss_function($TOUR, $id, $in, $BY);
            break;
        case 'revoke':
            $r = shp_staff_revoke($TOUR, $id, $BY);
            // The function stays in ianseo's field crew (user's choice): the organiser is told.
            if (!$r['error']) {
                $s = shp_staff_get($id);
                $m = shp_fn_decorate(array(array('licence' => $s ? (string) $s->SfLicence : '')), $TOUR)[0];
                if ($m['fn']) $r['fn_note'] = shp_t('ShStfRevokedFn', array('name' => shp_staff_label($id), 'fn' => $m['fn_label']));
            }
            break;
        case 'unlock':
            $r = shp_staff_unlock($TOUR, $id, $BY);
            break;
        case 'restore':
            // A withdrawn access given back. Withdrawing erased the password of a volunteer
            // without a licence: the reset QR code comes with the answer, the page shows it.
            $r = shp_staff_restore($TOUR, $id, $BY);
            if (!$r['error'] && !empty($r['need_password'])) {
                $r = array_merge($r, ss_qr(shp_invite_create($TOUR, 'reset', $BY, $id)));
                if (!$r['error']) $r['name'] = shp_staff_label($id);
            }
            break;
        case 'reset':
            $r = ss_qr(shp_invite_create($TOUR, 'reset', $BY, $id));
            if (!$r['error']) $r['name'] = shp_staff_label($id);
            break;
        default:
            shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
    }
    $r = array_merge(array('code' => ''), $r, ss_state($TOUR));
    if (!empty($r['error'])) $r['error'] = 1;
    shp_json($r);
}

/* ================================================================== */
/* Page                                                                */
/* ================================================================== */

/** Journal of the decisions on the team (latest first), in the competition's local time. */
function ss_journal($tour)
{
    $ev = array('approve' => 'ShStfEvApprove', 'add' => 'ShStfEvAdd', 'function' => 'ShStfEvFunction', 'refuse' => 'ShStfEvRefuse', 'rights' => 'ShStfEvRights',
        'revoke' => 'ShStfEvRevoke', 'unlock' => 'ShStfEvUnlock', 'reset' => 'ShStfEvReset',
        'reset_done' => 'ShStfEvResetDone', 'lock' => 'ShStfEvLock', 'organiser' => 'ShStfEvOrganiser', 'restore' => 'ShStfEvRestore');
    // The connection of an organiser page runs in the competition's zone: NOW() - UTC gives its offset.
    $rs = safe_r_sql("SELECT SjStaff, SjEvent, SjBy,
            DATE_ADD(SjWhen, INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND) AS SjLocal
        FROM ShopStaffLog WHERE SjTournament = " . intval($tour) . " AND SjEvent <> 'blocked' ORDER BY SjId DESC LIMIT 100");
    $rows = '';
    while ($r = safe_fetch($rs)) {
        $who = $r->SjBy === 'system' ? shp_t('ShStfBySystem') : ($r->SjBy === 'volunteer' ? shp_t('ShStfByVolunteer') : $r->SjBy);
        $rows .= '<tr><td>' . shp_e(bk_date_time($r->SjLocal)) . '</td><td>' . shp_e(shp_staff_label(intval($r->SjStaff))) . '</td>'
            . '<td>' . shp_e(isset($ev[$r->SjEvent]) ? shp_t($ev[$r->SjEvent]) : $r->SjEvent) . '</td><td>' . shp_e($who) . '</td></tr>';
    }
    if ($rows === '') return '<p class="ss-muted">' . shp_e(shp_t('ShStfJournalEmpty')) . '</p>';
    return '<div class="ss-scroll"><table class="ss-table"><thead><tr><th>' . shp_e(shp_t('ShStfJournalWhen')) . '</th><th>'
        . shp_e(shp_t('ShStfJournalWho')) . '</th><th>' . shp_e(shp_t('ShStfJournalWhat')) . '</th><th>'
        . shp_e(shp_t('ShStfJournalBy')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

$JS_SCRIPT = aut_adm_assets(array('<link rel="stylesheet" href="' . shp_e(shp_asset_url('staff-admin.css')) . '">'));
$PAGE_TITLE = shp_t('MnuStaff');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

// Frame of the organiser pages (#shpadm, assets/admin.css); the body keeps its own scope (#shpstf,
// staff-admin.css) for the cards drawn by its script.
// The title says what the team works at: one post alone, the stands and the desk, or "Volunteers".
$deskVol = $deskOn || $judgesOn;
$title = $ianseoOn ? ($shopOn || $deskVol ? 'MnuStaff' : 'IanPageTitle')
    : ($deskVol ? ($shopOn ? 'DkStaffTitleBoth' : 'DkStaffTitleDesk') : ($shopOn ? 'ShStfPageTitle' : 'MnuStaff'));
echo aut_adm_open('shop/admin/staff.php', shp_t($title));
echo '<div id="shpstf">';

if (!$enabled) {
    echo '<div class="ss-msg ss-warn">' . shp_e(shp_t('DkStaffOff')) . ' <a href="' . shp_e(shp_url('admin/index.php')) . '">'
        . shp_e(shp_t('MnuSettings')) . '</a> · <a href="' . shp_e(shp_url('desk/list.php')) . '">'
        . shp_e(shp_t('DkMenu')) . '</a> · <a href="' . shp_e(shp_url('admin/ianseo.php')) . '">'
        . shp_e(shp_t('IanMenu')) . '</a></div></div>' . aut_adm_close();
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

// Retention notice: always shown (decision D3/D4/D5 — the organiser must know).
echo '<div class="ss-notice">';
if ($window && $window['from'] > '0000-00-00') {
    echo '<p>' . shp_e(shp_t('ShStfRetention', array('open' => shp_staff_date($window['open_from']),
        'erase' => shp_staff_date($window['purge_from'])))) . '</p>';
} else {
    echo '<p>' . shp_e(shp_t('ShStfRetentionNoDates')) . '</p>';
}
echo '<p>' . shp_e(shp_t('ShStfRetentionLicensee')) . '</p>';
if ($over) echo '<p><b>' . shp_e(shp_t('ShStfErrOver')) . '</b></p>';
echo '</div>';

if (!$over) {
    echo '<div class="ss-actions">'
        . '<button type="button" class="ss-btn ss-btn-primary ss-btn-big" id="ss-invite">' . shp_e(shp_t('ShStfInvite')) . '</button>';
    if ($shopOn) {
        echo '<a class="ss-btn ss-btn-big" target="_blank" href="' . shp_e(shp_url('admin/posters.php?pdf=till')) . '">'
            . '<img src="' . shp_e($CFG->ROOT_DIR . 'Common/Images/pdf_small.gif') . '" alt="" width="16" height="16">&nbsp;' . shp_e(shp_t('ShStfTillPoster')) . '</a>';
    }
    // Adding a licensee without the QR code: one line right of the buttons, the licensees found
    // under it across the page.
    echo '<form class="ss-find-form" id="ss-find-form" role="search">'
        . '<input type="search" id="ss-find-q" maxlength="60" autocomplete="off" placeholder="' . shp_e(shp_t('ShStfFindPh')) . '"'
        . ' aria-label="' . shp_e(shp_t('ShStfFindPh')) . '">'
        . '<button type="submit" class="ss-btn ss-btn-big">' . shp_e(shp_t('ShStfFind')) . '</button></form>'
        . '</div>'
        . '<div class="ss-find" id="ss-find" hidden aria-live="polite"></div>'
        . '<div class="ss-running" id="ss-running" hidden></div>';
}
echo '<div id="ss-flash" aria-live="polite"></div>';

// Beside the waiting requests (no line added: user's choice), the officials of ianseo's field
// crew without an access, and the way to that page of the core.
echo '<div class="ss-cols"><section class="ss-col"><h2>' . shp_e(shp_t('ShStfPendingTitle')) . ' <span class="ss-count" id="ss-pending-count">0</span></h2>'
    . '<div id="ss-pending"><p class="ss-muted">' . shp_e(shp_t('ShLoading')) . '</p></div></section>'
    . '<section class="ss-col"><h2>' . shp_e(shp_t('ShStfOfficialsTitle')) . ' <span class="ss-count" id="ss-off-count">0</span>'
    . '<a class="ss-btn ss-h-btn" href="' . shp_e($CFG->ROOT_DIR . 'Tournament/ManStaffField.php') . '">' . shp_e(get_text('MenuLM_Field Crew')) . '</a></h2>'
    . '<div id="ss-officials"></div></section></div>'
    . '<section><h2>' . shp_e(shp_t('ShStfTeamTitle')) . '</h2><div id="ss-team"></div></section>'
    . '<details class="ss-journal"><summary>' . shp_e(shp_t('ShStfJournalTitle')) . '</summary>' . ss_journal($TOUR) . '</details>';

// Full-screen QR code (invitation, password reset).
echo '<div class="ss-overlay" id="ss-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="ss-ov-title">'
    . '<div class="ss-ov-box"><h2 id="ss-ov-title"></h2><p class="ss-ov-name" id="ss-ov-name"></p>'
    . '<div class="ss-ov-qr" id="ss-ov-qr"></div><p class="ss-ov-timer" id="ss-ov-timer"></p>'
    . '<p class="ss-ov-pend" id="ss-ov-pend" aria-live="polite"></p>'
    . '<div class="ss-ov-btns"><button type="button" class="ss-btn ss-btn-danger" id="ss-ov-stop">' . shp_e(shp_t('ShStfInviteStop')) . '</button>'
    . '<button type="button" class="ss-btn" id="ss-ov-close">' . shp_e(shp_t('ShStfHide')) . '</button></div></div></div>';

$stands = array();
foreach (shp_stands($TOUR, false) as $sd => $st) {
    $stands[] = array('id' => $sd, 'name' => $st->SdName !== '' ? $st->SdName : (shp_stand_kinds()[$st->SdKind] ?? $st->SdKind),
        'active' => intval($st->SdActive) === 1);
}
$presets = array();
foreach (shp_staff_presets() as $k => $p) $presets[] = array('key' => $k, 'label' => shp_staff_preset_label($k)) + $p;
$functions = array();
foreach (shp_fn_list() as $f) $functions[] = array('id' => $f['id'], 'label' => $f['label'], 'group' => $f['group']);
$perms = array();
foreach (shp_perms_all() as $p) $perms[] = array('key' => $p, 'label' => shp_perm_label($p));
// Rights at the desk, in two posts opened apart: registry (documents, payments), judges (equipment).
$deskPerms = array();
foreach (shp_desk_perms_all() as $p) $deskPerms[] = array('key' => $p, 'label' => shp_desk_perm_label($p), 'post' => $p === 'equip' ? 'judges' : 'desk');
// Groups of ianseo's own screens (AUTH/vol-lib.php), for the whole competition.
$ianseoGroups = array();
foreach (aut_vol_group_keys() as $g) $ianseoGroups[] = array('key' => $g, 'label' => shp_ianseo_label($g));

echo shp_json_script('shp-texts', shp_ts(array(
        'ShStfPendingNone', 'ShStfTeamNone', 'ShStfCode', 'ShStfCodeCheck', 'ShStfAgo', 'ShStfAgoNow', 'ShStfSeenAgo',
        'ShStfSeenNever', 'ShStfRole', 'ShStfPresetCustom', 'ShStfStands', 'ShStfRefundMax', 'ShStfRefundTotal',
        'ShStfRefundZero', 'ShStfFine', 'ShStfApprove', 'ShStfRefuse', 'ShStfRefuseConfirm', 'ShStfSave', 'ShStfCancel',
        'ShStfEditRights', 'ShStfResetPwd', 'ShStfUnlock', 'ShStfRevoke', 'ShStfRevokeConfirm', 'ShStfStatusLocked',
        'ShStfStatusRevoked', 'ShStfAllRights', 'ShStfNoRefund', 'ShStfRefundSummary', 'ShStfRevokedTitle',
        'ShStfInviteTitle', 'ShStfInviteLeft', 'ShStfInviteOver', 'ShStfInviteRunning', 'ShStfInviteNew', 'ShStfInviteStop',
        'ShStfPendingWaiting', 'ShStfResetTitle2', 'ShStfResetLeft', 'ShStfHide', 'ShStfClose', 'ShStfDone',
        'ShStfNoStandSet', 'ShStfErrNoStand', 'ShStfStandInactive', 'ShStfQrMissing', 'DkRightsTitle', 'DkErrNoRight',
        'DkRightsShort', 'DkJudgesRightsTitle', 'DkJudges', 'ShStfRestore', 'ShStfRestoreDone', 'IanRightsTitle', 'IanRightsShort',
        'ShStfFunction', 'ShStfAccessIs', 'ShStfFunctionIs', 'ShStfRevokeKeepFn', 'ShStfOfficialsNone', 'ShStfOfficialsAdd', 'ShStfFnKept',
        'ShStfFamilyName', 'ShStfGivenName',
        'ShStfFindTitle', 'ShStfFindNone', 'ShStfFindMore', 'ShStfFindLicence', 'ShStfFindName', 'ShStfFindClub',
        'ShStfFindBorn', 'ShStfFindSpace', 'ShStfFindYes', 'ShStfFindNo', 'ShStfFindAdd', 'ShStfFindInTeam',
        'ShStfFindPending', 'ShStfFindNoAccount', 'ShStfAddConfirm', 'ShStfAdded', 'ShStfKindLicensee',
    )) + shp_base_texts())
    . shp_json_script('shp-cfg', shp_page_cfg($TOUR, 'staffadm', array(
        'self' => $SELF, 'csrf' => bk_csrf_token(), 'stands' => $stands, 'presets' => $presets, 'perms' => $perms,
        'desk_perms' => $deskPerms, 'desk_on' => $deskOn, 'judges_on' => $judgesOn, 'shop_on' => $shopOn,
        'ianseo_groups' => $ianseoGroups, 'ianseo_on' => $ianseoOn,
        'default_preset' => $shopOn ? 'shop' : ($deskOn ? 'registry' : ($judgesOn ? 'judge' : ($ianseoOn ? 'results' : ''))),
        'state' => ss_state($TOUR), 'over' => $over,
        'functions' => $functions, 'fn_access' => shp_staff_fn_access(), 'fn_edit' => $canFn,
    )));
echo '<script src="' . shp_e(shp_asset_url('shp.js')) . '"></script>'
    . '<script src="' . shp_e(shp_asset_url('staff-admin.js')) . '"></script>';
echo '</div>' . aut_adm_close();

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
