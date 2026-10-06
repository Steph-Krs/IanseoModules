<?php
/**
 * staff/login.php?k=<public key> — a volunteer of the team signs in again on a phone (new
 * phone, cookie lost, signed out).
 *
 *   licensee          sign-in to the licensee space, back here; let in when they are in the team;
 *   without licence   family name, given name, password.
 *
 * Brute force: SHP_LOCK_FAILS consecutive failures lock the account (the organiser unlocks
 * it); per address, a generous ceiling (a whole club behind one 4G address) counting FAILURES
 * only. Failures go to AUTH's journal (AuthLog, and its file for fail2ban when configured):
 * SHOP_LOGIN_FAIL for each failure — not banned by itself —, LOGIN_BLOCK once the address goes
 * past the ceiling, which fail2ban counts.
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_log (journal + fail2ban file)

define('SHP_LOGIN_IP_MAX', 30);   // failures per address in 15 minutes

$key = (string) ($_GET['k'] ?? '');
$TOUR = shp_tour_by_key($key);
if ($TOUR <= 0 || !shp_enabled($TOUR)) {
    shp_page_message(shp_t('ShUnavailableTitle'), shp_t('ShUnavailable'), 'info', 404);
}
$SELF = shp_url('staff/login.php?k=' . $key);
$TILL = shp_url('staff/index.php');
$window = shp_window($TOUR);
if (!$window || $window['purge_due']) shp_page_message(shp_t('ShStfTillTitle'), shp_t('ShStfErrOver'));

$state = shp_staff_session_state();
if ($state['reason'] === 'ok' && intval($state['staff']->SfTournament) === $TOUR) {
    header('Location: ' . $TILL);
    exit;
}

/** Failures of this address in the last 15 minutes (same connection clock as the writes). */
function sl_ip_failures()
{
    aut_ensure_schema();
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM AuthLog WHERE AlEvent = 'SHOP_LOGIN_FAIL'
        AND AlIP = " . StrSafe_DB(bk_ip()) . " AND AlWhen > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"));
    return $r ? intval($r->n) : 0;
}

$errors = array();
$info = '';
$readOnly = shp_impersonating();
$archer = bk_current_archer();
$mode = (string) ($_GET['m'] ?? '');

/* ---- Licensee ---- */
if ($mode === 'lic' && !$readOnly) {
    if (!$archer) {
        $_SESSION['BK_NEXT'] = array('page' => 'shop/staff/login.php?k=' . $key, 'at' => time());
        header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp');
        exit;
    }
}
// A signed-in licensee of the team is let in at once (also when back from the sign-in).
if ($archer && !$readOnly) {
    $s = safe_fetch(safe_r_sql("SELECT SfId FROM ShopStaff WHERE SfTournament = $TOUR AND SfKind = 'LICENSEE'
        AND SfArcher = " . intval($archer->BaId) . " AND SfStatus = 'active' ORDER BY SfId DESC LIMIT 1"));
    if ($s) {
        shp_staff_session_open(intval($s->SfId));
        header('Location: ' . $TILL);
        exit;
    }
    if ($mode === 'lic') $info = shp_t('ShStfNotInTeam');
}

/* ---- Without a licence ---- */
$family = $given = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $family = shp_staff_name_clean($_POST['family'] ?? '');
    $given = shp_staff_name_clean($_POST['given'] ?? '');
    $pwd = (string) ($_POST['pwd'] ?? '');
    $who = 'shop:' . $TOUR;   // no space: one field of the fail2ban line
    if (!bk_csrf_check()) {
        $errors[] = shp_t('ShStfErrSession');
    } elseif (sl_ip_failures() >= SHP_LOGIN_IP_MAX) {
        aut_log('LOGIN_BLOCK', $who);
        $errors[] = shp_t('ShStfErrTooMany');
    } elseif ($family === '' || $given === '' || $pwd === '') {
        $errors[] = shp_t('ShStfErrLoginFields');
    } else {
        // Candidates: same name, loosely (case, accents), with or without the complement
        // added for a homonym. Locked accounts too, to say so once the password is right.
        $key2 = shp_staff_name_key($family, $given);
        $cands = array();
        $rs = safe_r_sql("SELECT SfId, SfFamilyName, SfGivenName, SfPassword, SfStatus, SfFails FROM ShopStaff
            WHERE SfTournament = $TOUR AND SfKind = 'LOCAL' AND SfStatus IN ('active', 'locked') AND SfPassword <> ''");
        while ($r = safe_fetch($rs)) {
            if (shp_staff_name_key($r->SfFamilyName, $r->SfGivenName) === $key2
                    || shp_staff_name_key(shp_staff_name_base($r->SfFamilyName), $r->SfGivenName) === $key2) {
                $cands[] = $r;
            }
        }
        $match = null;
        foreach ($cands as $c) {
            if (password_verify($pwd, $c->SfPassword)) { $match = $c; break; }
        }
        // Same time whether the name exists or not (no way to probe the team's names).
        if (!$cands) password_verify($pwd, '$2y$10$Edq3f8kFpCuYXQyuU8D9DuZSp2m6hLqg6ouWiuf2RpBLcNTKFd5RG');
        $pwd = null;

        if ($match && $match->SfStatus === 'locked') {
            $errors[] = shp_t('ShStfErrLocked');
        } elseif ($match) {
            safe_w_sql("UPDATE ShopStaff SET SfFails = 0 WHERE SfId = " . intval($match->SfId));
            shp_staff_session_open(intval($match->SfId));
            header('Location: ' . $TILL);
            exit;
        } else {
            aut_log('SHOP_LOGIN_FAIL', $who . ($cands ? ':' . intval($cands[0]->SfId) : ''));
            foreach ($cands as $c) {
                if ($c->SfStatus !== 'active') continue;
                $id = intval($c->SfId);
                safe_w_sql("UPDATE ShopStaff SET SfFails = LEAST(SfFails + 1, 100) WHERE SfId = $id");
                safe_w_sql("UPDATE ShopStaff SET SfStatus = 'locked' WHERE SfId = $id AND SfStatus = 'active' AND SfFails >= " . SHP_LOCK_FAILS);
                // Phones already signed in keep working (shp_staff_working): only new sign-ins stop.
                if (safe_w_affected_rows() > 0) shp_staff_log($TOUR, $id, 'lock', 'system', SHP_LOCK_FAILS . ' failures');
            }
            $errors[] = shp_t('ShStfErrLogin');
        }
    }
}

$title = shp_t('ShStfLoginTitle');
$body = '<div class="shp-card"><h1>' . shp_e($title) . '</h1><p class="sj-tour">' . shp_e(shp_staff_tour_name($TOUR)) . '</p>';
if ($info !== '') $body .= shp_msg('info', $info);
$licLabel = $archer ? shp_t('ShStfIAmLicensedAs', trim($archer->BaName . ' ' . $archer->BaFamilyName)) : shp_t('ShStfIAmLicensed');
$body .= '<p><a class="shp-btn shp-btn-block shp-btn-big" href="' . shp_e($SELF . '&m=lic') . '">' . shp_e($licLabel) . '</a></p>'
    . '<h2>' . shp_e(shp_t('ShStfIAmNotLicensed')) . '</h2>'
    . ($errors ? shp_msg('err', implode(' ', $errors)) : '')
    . '<form method="post" action="' . shp_e($SELF) . '" autocomplete="off">' . bk_csrf_field()
    . '<label for="sl-family">' . shp_e(shp_t('ShStfFamilyName')) . '</label>'
    . '<input type="text" id="sl-family" name="family" required maxlength="60" autocomplete="family-name" value="' . shp_e($family) . '">'
    . '<label for="sl-given">' . shp_e(shp_t('ShStfGivenName')) . '</label>'
    . '<input type="text" id="sl-given" name="given" required maxlength="40" autocomplete="given-name" value="' . shp_e($given) . '">'
    . '<label for="sl-pwd">' . shp_e(shp_t('ShStfPassword')) . '</label>'
    . '<input type="password" id="sl-pwd" name="pwd" required autocomplete="current-password">'
    . '<p><button type="submit" class="shp-btn shp-btn-primary shp-btn-block shp-btn-big"' . ($readOnly ? ' disabled' : '') . '>'
    . shp_e(shp_t('ShStfLoginSubmit')) . '</button></p>'
    . '</form>'
    . '<p class="shp-muted">' . shp_e(shp_t('ShStfLoginHelp')) . '</p>'
    . '<p class="shp-muted">' . shp_e(shp_t('ShStfLoginOrganiser')) . '</p></div>';

shp_head($title, array('layout' => 'card', 'css' => array('staff.css')));
echo '  <main class="shp-main">' . $body . "</main>\n";
shp_foot();
