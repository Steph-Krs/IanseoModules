<?php
/**
 * admin/staff.php — the volunteers of the points of sale of the open competition (organiser).
 *
 * Used on a computer before the competition and on the organiser's PHONE during it: the layout
 * works down to 360 px. What the page does:
 *   - "Invite volunteers": a QR code (30 min, reusable, can be stopped) shown full screen;
 *   - waiting requests, refreshed by themselves: the organiser checks the 4-digit code shown on
 *     the volunteer's phone, picks a role preset for the stands ticked (or rights stand by stand)
 *     and the refund ceilings, then approves — or refuses;
 *   - the team: rights, ceilings, last activity; change rights, withdraw access, reset a password
 *     (single-use QR code, 10 min), unlock;
 *   - "Use the till on this device": the organiser becomes a volunteer with every right;
 *   - the retention notice (when accounts without a licence open and are erased), always shown;
 *   - the journal of the decisions taken on the team.
 *
 * ianseo look and ACL of the core (participants, read-write). The page script talks to this same
 * file in JSON (header X-Shp, CSRF token of the session in the body); the classic form (till on
 * this device) carries the CSRF field.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/staff-session.php';
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
$enabled = $settings && intval($settings->SgEnabled) === 1;
$window = shp_window($TOUR);
$over = !$window || $window['purge_due'];

/** Everything the page script shows, in one answer. */
function ss_state($tour)
{
    return array('pending' => shp_staff_pending($tour), 'team' => shp_staff_team($tour), 'invite' => shp_invite_running($tour));
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
    switch ((string) ($in['act'] ?? '')) {
        case 'invite':
            $r = ss_qr(shp_invite_create($TOUR, 'enrol', $BY));
            break;
        case 'invite_stop':
            shp_invite_stop($TOUR, 'enrol', $BY);
            $r = array('error' => 0, 'msg' => '');
            break;
        case 'approve':
            $r = shp_staff_approve($TOUR, $id, $rights, ss_amount($in['refund_max'] ?? ''), ss_amount($in['refund_total'] ?? ''), $BY);
            break;
        case 'refuse':
            $r = shp_staff_refuse($TOUR, $id, $BY);
            break;
        case 'update':
            $r = shp_staff_update($TOUR, $id, $rights, ss_amount($in['refund_max'] ?? ''), ss_amount($in['refund_total'] ?? ''), $BY);
            break;
        case 'revoke':
            $r = shp_staff_revoke($TOUR, $id, $BY);
            break;
        case 'unlock':
            $r = shp_staff_unlock($TOUR, $id, $BY);
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
/* "Use the till on this device"                                       */
/* ================================================================== */
$errors = array();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'till') {
    if (!bk_csrf_check()) {
        $errors[] = shp_t('ShStfErrSession');
    } elseif (!$enabled || $over) {
        $errors[] = shp_t($over ? 'ShStfErrOver' : 'ShErrShopOff');
    } else {
        $me = shp_staff_organiser($TOUR, $BY);
        if ($me) {
            shp_staff_session_open(intval($me->SfId));
            header('Location: ' . shp_url('staff/index.php'));
            exit;
        }
        $errors[] = shp_t('ShErrInternal');
    }
}

/* ================================================================== */
/* Page                                                                */
/* ================================================================== */

/** Journal of the decisions on the team (latest first), in the competition's local time. */
function ss_journal($tour)
{
    $ev = array('approve' => 'ShStfEvApprove', 'refuse' => 'ShStfEvRefuse', 'rights' => 'ShStfEvRights',
        'revoke' => 'ShStfEvRevoke', 'unlock' => 'ShStfEvUnlock', 'reset' => 'ShStfEvReset',
        'reset_done' => 'ShStfEvResetDone', 'lock' => 'ShStfEvLock', 'organiser' => 'ShStfEvOrganiser');
    // The connection of an organiser page runs in the competition's zone: NOW() - UTC gives its offset.
    $rs = safe_r_sql("SELECT SjStaff, SjEvent, SjBy,
            DATE_ADD(SjWhen, INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND) AS SjLocal
        FROM ShopStaffLog WHERE SjTournament = " . intval($tour) . " ORDER BY SjId DESC LIMIT 100");
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

$JS_SCRIPT = array(
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('admin.css')) . '">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('staff-admin.css')) . '">',
);
$PAGE_TITLE = shp_t('MnuStaff');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

// Title and navigation in the frame of the other organiser pages (#shpadm, admin.css); the body
// keeps its own scope (#shpstf): its fields and buttons are sized for the organiser's phone.
echo '<div id="shpadm"><h1>' . shp_e(shp_t('ShStfPageTitle')) . '</h1>' . shp_adm_nav('staff.php') . '</div>';
echo '<div id="shpstf">';

if (!$enabled) {
    echo '<div class="ss-msg ss-warn">' . shp_e(shp_t('ShStfShopOff')) . ' <a href="' . shp_e(shp_url('admin/index.php')) . '">'
        . shp_e(shp_t('MnuSettings')) . '</a></div></div>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

foreach ($errors as $e) echo '<div class="ss-msg ss-err" role="alert">' . shp_e($e) . '</div>';

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
        . '<button type="button" class="ss-btn ss-btn-primary ss-btn-big" id="ss-invite">' . shp_e(shp_t('ShStfInvite')) . '</button>'
        . '<form method="post" action="' . shp_e($SELF) . '">' . bk_csrf_field() . '<input type="hidden" name="act" value="till">'
        . '<button type="submit" class="ss-btn ss-btn-big">' . shp_e(shp_t('ShStfUseTill')) . '</button></form>'
        . '<a class="ss-btn ss-btn-big" target="_blank" href="' . shp_e(shp_url('admin/posters.php?pdf=till')) . '">'
        . '<img src="' . shp_e($CFG->ROOT_DIR . 'Common/Images/pdf_small.gif') . '" alt="" width="16" height="16">&nbsp;' . shp_e(shp_t('ShStfTillPoster')) . '</a>'
        . '</div>'
        . '<div class="ss-running" id="ss-running" hidden></div>';
}
echo '<div id="ss-flash" aria-live="polite"></div>';

echo '<section><h2>' . shp_e(shp_t('ShStfPendingTitle')) . ' <span class="ss-count" id="ss-pending-count">0</span></h2>'
    . '<div id="ss-pending"><p class="ss-muted">' . shp_e(shp_t('ShLoading')) . '</p></div></section>'
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
$perms = array();
foreach (shp_perms_all() as $p) $perms[] = array('key' => $p, 'label' => shp_perm_label($p));

echo shp_json_script('shp-texts', shp_ts(array(
        'ShStfPendingNone', 'ShStfTeamNone', 'ShStfCode', 'ShStfCodeCheck', 'ShStfAgo', 'ShStfAgoNow', 'ShStfSeenAgo',
        'ShStfSeenNever', 'ShStfRole', 'ShStfPresetCustom', 'ShStfStands', 'ShStfRefundMax', 'ShStfRefundTotal',
        'ShStfRefundZero', 'ShStfFine', 'ShStfApprove', 'ShStfRefuse', 'ShStfRefuseConfirm', 'ShStfSave', 'ShStfCancel',
        'ShStfEditRights', 'ShStfResetPwd', 'ShStfUnlock', 'ShStfRevoke', 'ShStfRevokeConfirm', 'ShStfStatusLocked',
        'ShStfStatusRevoked', 'ShStfAllRights', 'ShStfNoRefund', 'ShStfRefundSummary', 'ShStfRevokedTitle',
        'ShStfInviteTitle', 'ShStfInviteLeft', 'ShStfInviteOver', 'ShStfInviteRunning', 'ShStfInviteNew', 'ShStfInviteStop',
        'ShStfPendingWaiting', 'ShStfResetTitle2', 'ShStfResetLeft', 'ShStfHide', 'ShStfClose', 'ShStfDone',
        'ShStfNoStandSet', 'ShStfErrNoStand', 'ShStfStandInactive', 'ShStfQrMissing',
    )) + shp_base_texts())
    . shp_json_script('shp-cfg', shp_page_cfg($TOUR, 'staffadm', array(
        'self' => $SELF, 'csrf' => bk_csrf_token(), 'stands' => $stands, 'presets' => $presets, 'perms' => $perms,
        'default_preset' => 'versatile', 'state' => ss_state($TOUR), 'over' => $over,
    )));
echo '<script src="' . shp_e(shp_asset_url('shp.js')) . '"></script>'
    . '<script src="' . shp_e(shp_asset_url('staff-admin.js')) . '"></script>';
echo '</div>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
