<?php
/**
 * staff/join.php — where a volunteer lands after scanning the organiser's QR code.
 *
 *   enrol code  → "I have a licence" (sign-in to the licensee space, back here automatically)
 *                 or "I have no licence" (name, given name, password — only from the day
 *                 before the competition). A join request is created and a session opened on
 *                 this phone; the screen shows a 4-digit code to show to the organiser, and
 *                 switches to the till by itself once approved (staff/api/join-status.php).
 *   reset code  → the volunteer named by the organiser chooses a new password.
 *   no code     → the waiting screen of this phone's request, if any.
 *
 * No ianseo ACL here ($SKIP_AUTH): the code of the address and the organiser's approval are
 * the guards. Forms carry the CSRF token of the visitor's session.
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/invite.php';

$token = (string) ($_GET['t'] ?? '');
$mode = (string) ($_GET['m'] ?? '');
$state = shp_staff_session_state();
$SELF = shp_url('staff/join.php');
$TILL = shp_url('staff/index.php');

/** Redirect within the points of sale, then stop. */
function sj_go($url)
{
    header('Location: ' . $url);
    exit;
}

/** Page shell of this screen. */
function sj_page($title, $body, $scripts = array())
{
    shp_head($title, array('layout' => 'card', 'css' => array('staff.css')));
    echo '  <main class="shp-main" id="sj">' . $body . "</main>\n";
    shp_foot($scripts);
    exit;
}

/** Waiting screen: the code in very large digits, and the page asks every 3 s. */
function sj_waiting($row)
{
    $code = (string) $row->SfCode;
    $title = shp_t('ShStfWaitTitle');
    $body = '<div class="shp-card sj-wait"><h1>' . shp_e($title) . '</h1>'
        . '<p class="sj-name">' . shp_e(trim($row->SfGivenName . ' ' . $row->SfFamilyName)) . '</p>'
        . '<p>' . shp_e(shp_t('ShStfWaitShow')) . '</p>'
        . '<p class="sj-code" aria-label="' . shp_e(shp_t('ShStfCodeLabel', implode(' ', str_split($code)))) . '">'   // bytes: 4 ASCII digits
        . shp_e(implode(' ', str_split($code))) . '</p>'   // bytes: 4 ASCII digits
        . '<p class="shp-muted" id="sj-status" aria-live="polite">' . shp_e(shp_t('ShStfWaitHint')) . '</p>'
        . '</div>'
        . shp_json_script('shp-texts', shp_ts(array('ShStfWaitHint', 'ShStfErrRefused', 'ShStfErrExpired', 'ShStfErrRevoked',
            'ShStfApproved', 'ShStfStartAgain')) + shp_base_texts())
        . shp_json_script('shp-cfg', shp_page_cfg(intval($row->SfTournament), 'join', array(
            'status' => shp_url('staff/api/join-status.php'), 'till' => shp_url('staff/index.php'))));
    sj_page($title, $body, array('staff-join.js'));
}

/** A message with an optional big button. */
function sj_message($text, $type = 'warn', $btnLabel = '', $btnUrl = '')
{
    $title = shp_t('ShStfJoinTitle');
    $body = '<div class="shp-card"><h1>' . shp_e($title) . '</h1>' . shp_msg($type, $text);
    if ($btnLabel !== '') {
        $body .= '<p><a class="shp-btn shp-btn-primary shp-btn-block" href="' . shp_e($btnUrl) . '">' . shp_e($btnLabel) . '</a></p>';
    }
    sj_page($title, $body . '</div>');
}

/* ---- No code in the address: this phone's own request ---- */
if ($token === '') {
    if ($state['reason'] === 'ok') sj_go($TILL);
    if ($state['reason'] === 'pending') sj_waiting($state['row']);
    if (in_array($state['reason'], array('refused', 'revoked', 'locked'), true)) sj_message(shp_staff_reason_msg($state['reason']));
    sj_message(shp_t('ShStfJoinScan'), 'info');
}

/* ---- A code: check it ---- */
$chk = shp_invite_check($token);
if (!$chk['ok']) {
    // A volunteer reopening the address of a code that has since expired: their own request
    // or till matters more than the code.
    $inv = $chk['invite'];
    if ($inv && $state['row'] && intval($state['row']->SfTournament) === intval($inv->SqTournament)) {
        if ($state['reason'] === 'ok') sj_go($TILL);
        if ($state['reason'] === 'pending') sj_waiting($state['row']);
    }
    sj_message(shp_invite_reason_msg($chk['reason']) . ' ' . shp_t('ShStfInvAskNew'));
}
$inv = $chk['invite'];
$TOUR = intval($inv->SqTournament);
$window = shp_window($TOUR);
if (!$window || $window['purge_due']) sj_message(shp_t('ShStfErrOver'));
$here = $SELF . '?t=' . $token;
$errors = array();

/* ================================================================== */
/* Reset code: new password of one volunteer without a licence         */
/* ================================================================== */
if ($inv->SqKind === 'reset') {
    $who = shp_staff_get(intval($inv->SqStaff));
    if (!$who || intval($who->SfTournament) !== $TOUR || $who->SfKind !== 'LOCAL'
            || !in_array($who->SfStatus, array('active', 'locked'), true)) {
        sj_message(shp_t('ShStfErrGone'));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!bk_csrf_check()) {
            $errors[] = shp_t('ShStfErrSession');
        } else {
            $p1 = (string) ($_POST['pwd'] ?? '');
            $p2 = (string) ($_POST['pwd2'] ?? '');
            if (mb_strlen($p1) < SHP_PWD_MIN || !hash_equals($p1, $p2)) {
                $errors[] = mb_strlen($p1) < SHP_PWD_MIN ? shp_t('ShStfErrPassword', SHP_PWD_MIN) : shp_t('ShStfErrPasswordTwice');
            } elseif (!shp_invite_use(intval($inv->SqId))) {
                sj_message(shp_invite_reason_msg('used') . ' ' . shp_t('ShStfInvAskNew'));
            } else {
                $r = shp_staff_set_password(intval($who->SfId), $p1, $p2);
                $p1 = $p2 = null;
                if ($r['error']) sj_message($r['msg']);
                shp_staff_session_open(intval($who->SfId));
                shp_staff_log($TOUR, intval($who->SfId), 'reset_done', 'volunteer');
                sj_go($TILL);
            }
        }
    }
    $title = shp_t('ShStfResetTitle');
    $body = '<div class="shp-card"><h1>' . shp_e($title) . '</h1>'
        . '<p class="sj-name">' . shp_e(trim($who->SfGivenName . ' ' . $who->SfFamilyName)) . '</p>'
        . ($errors ? shp_msg('err', implode(' ', $errors)) : '')
        . '<form method="post" action="' . shp_e($here) . '" autocomplete="off">' . bk_csrf_field()
        . '<label for="sj-pwd">' . shp_e(shp_t('ShStfNewPassword', SHP_PWD_MIN)) . '</label>'
        . '<input type="password" id="sj-pwd" name="pwd" required minlength="' . SHP_PWD_MIN . '" autocomplete="new-password">'
        . '<label for="sj-pwd2">' . shp_e(shp_t('ShStfPasswordAgain')) . '</label>'
        . '<input type="password" id="sj-pwd2" name="pwd2" required minlength="' . SHP_PWD_MIN . '" autocomplete="new-password">'
        . '<p><button type="submit" class="shp-btn shp-btn-primary shp-btn-block shp-btn-big">' . shp_e(shp_t('ShStfResetSave')) . '</button></p>'
        . '</form></div>';
    sj_page($title, $body);
}

/* ================================================================== */
/* Enrolment code                                                      */
/* ================================================================== */
if ($state['row'] && intval($state['row']->SfTournament) === $TOUR) {
    if ($state['reason'] === 'ok') sj_go($TILL);
    if ($state['reason'] === 'pending') sj_waiting($state['row']);
}

/** Joins as the signed-in licensee: let in again at once when already in the team. */
function sj_join_licensee($tour, $archer, $inv)
{
    global $TILL, $SELF;
    $r = shp_staff_join_licensee($tour, $archer);
    if ($r['state'] === 'full') sj_message(shp_t('ShStfErrFull'));
    shp_invite_use(intval($inv->SqId));
    shp_staff_session_open(intval($r['staff']->SfId));
    unset($_SESSION['SHP_JOIN_LIC']);
    sj_go($r['state'] === 'active' ? $TILL : $SELF);
}

$archer = bk_current_archer();
$readOnly = shp_impersonating();

// Back from the licensee sign-in started below: carry on without a second tap.
if ($archer && !$readOnly && intval($_SESSION['SHP_JOIN_LIC'] ?? 0) === intval($inv->SqId)) {
    sj_join_licensee($TOUR, $archer, $inv);
}

if ($mode === 'lic' && !$readOnly) {
    if ($archer) sj_join_licensee($TOUR, $archer, $inv);
    // To the licensee sign-in, then back here (bk_next_after_login accepts this exact page).
    $_SESSION['SHP_JOIN_LIC'] = intval($inv->SqId);
    $_SESSION['BK_NEXT'] = array('page' => 'shop/staff/join.php?t=' . $token, 'at' => time());
    sj_go($CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp');
}

$form = array('family' => '', 'given' => '', 'complement' => '');
$askComplement = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'local') {
    $mode = 'local';
    foreach ($form as $k => $v) $form[$k] = shp_staff_name_clean($_POST[$k] ?? '', $k === 'complement' ? 20 : 40);
    if (!bk_csrf_check()) {
        $errors[] = shp_t('ShStfErrSession');
    } elseif (bk_too_many(array('SHP_JOIN'), 30)) {
        $errors[] = shp_t('ShStfErrTooMany');
    } else {
        $r = shp_staff_join_local($TOUR, $form['family'], $form['given'], (string) ($_POST['pwd'] ?? ''),
            (string) ($_POST['pwd2'] ?? ''), $form['complement']);
        if ($r['error']) {
            $errors[] = $r['msg'];
            $askComplement = $r['code'] === 'duplicate';
        } else {
            bk_log('SHP_JOIN', 'shop:' . $TOUR);
            shp_invite_use(intval($inv->SqId));
            shp_staff_session_open(intval($r['staff']->SfId));
            sj_go($SELF);
        }
    }
}

$tourName = shp_staff_tour_name($TOUR);
$title = shp_t('ShStfJoinTitle');
$body = '<div class="shp-card"><h1>' . shp_e($title) . '</h1><p class="sj-tour">' . shp_e($tourName) . '</p>';

if ($mode === 'local') {
    if (!$window['local_open']) {
        $body .= shp_msg('info', shp_t('ShStfJoinLocalClosed', shp_staff_date($window['open_from'])))
            . '<p><a class="shp-btn shp-btn-block" href="' . shp_e($here) . '">' . shp_e(shp_t('ShStfBack')) . '</a></p>';
    } else {
        $body .= ($errors ? shp_msg('err', implode(' ', $errors)) : '')
            . '<form method="post" action="' . shp_e($here . '&m=local') . '" autocomplete="off">' . bk_csrf_field()
            . '<input type="hidden" name="act" value="local">'
            . '<label for="sj-family">' . shp_e(shp_t('ShStfFamilyName')) . '</label>'
            . '<input type="text" id="sj-family" name="family" required maxlength="40" autocomplete="family-name" value="' . shp_e($form['family']) . '">'
            . '<label for="sj-given">' . shp_e(shp_t('ShStfGivenName')) . '</label>'
            . '<input type="text" id="sj-given" name="given" required maxlength="40" autocomplete="given-name" value="' . shp_e($form['given']) . '">';
        if ($askComplement || $form['complement'] !== '') {
            $body .= '<label for="sj-complement">' . shp_e(shp_t('ShStfComplement')) . '</label>'
                . '<input type="text" id="sj-complement" name="complement" maxlength="20" value="' . shp_e($form['complement']) . '"'
                . ' placeholder="' . shp_e(shp_t('ShStfComplementHint')) . '">';
        }
        $body .= '<label for="sj-pwd">' . shp_e(shp_t('ShStfChoosePassword', SHP_PWD_MIN)) . '</label>'
            . '<input type="password" id="sj-pwd" name="pwd" required minlength="' . SHP_PWD_MIN . '" autocomplete="new-password">'
            . '<label for="sj-pwd2">' . shp_e(shp_t('ShStfPasswordAgain')) . '</label>'
            . '<input type="password" id="sj-pwd2" name="pwd2" required minlength="' . SHP_PWD_MIN . '" autocomplete="new-password">'
            . '<p class="shp-muted">' . shp_e(shp_t('ShStfLocalNotice', shp_staff_date($window['purge_from']))) . '</p>'
            . '<p><button type="submit" class="shp-btn shp-btn-primary shp-btn-block shp-btn-big"' . ($readOnly ? ' disabled' : '') . '>'
            . shp_e(shp_t('ShStfSendRequest')) . '</button></p>'
            . '<p><a class="shp-btn shp-btn-block" href="' . shp_e($here) . '">' . shp_e(shp_t('ShStfBack')) . '</a></p>'
            . '</form>';
    }
} else {
    $licLabel = $archer ? shp_t('ShStfIAmLicensedAs', trim($archer->BaName . ' ' . $archer->BaFamilyName)) : shp_t('ShStfIAmLicensed');
    $body .= '<p>' . shp_e(shp_t('ShStfJoinIntro')) . '</p>'
        . '<p><a class="shp-btn shp-btn-primary shp-btn-block shp-btn-big" href="' . shp_e($here . '&m=lic') . '">' . shp_e($licLabel) . '</a></p>'
        . '<p><a class="shp-btn shp-btn-block shp-btn-big" href="' . shp_e($here . '&m=local') . '">' . shp_e(shp_t('ShStfIAmNotLicensed')) . '</a></p>';
    if (!$window['local_open']) {
        $body .= '<p class="shp-muted">' . shp_e(shp_t('ShStfJoinLocalClosed', shp_staff_date($window['open_from']))) . '</p>';
    }
}
sj_page($title, $body . '</div>');
