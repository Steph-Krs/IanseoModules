<?php
/**
 * AUTH module — management of the user accounts (ADMIN only).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/legal-lib.php');   // acceptance of the terms (columns + version)
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
// same lock as Update/index.php: reserved to the ADMIN account when the authentication is on
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();
aut_legal_ensure_schema();   // AuCguVer/AuCguAt columns

// Is the online registration (booking) installed? → "Archers" tab (BookingArchers accounts).
$hasArchers = (bool) safe_fetch(safe_r_sql("SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingArchers'"));
// Active tab (the archer forms carry tab=archers → the page stays there after an action).
$activeTab = (($_REQUEST['tab'] ?? '') === 'archers' && $hasArchers) ? 'archers' : 'org';

$msgOk = '';
$msgErr = '';
$tmpPwd = '';   // temporary password shown only once

function aut_admin_count() {
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM AuthUsers WHERE AuRole='ADMIN' AND AuActive=1");
    $r = safe_fetch($q);
    return $r ? intval($r->n) : 0;
}

/** Renders a log (filter by event + user search + "load more" paging). */
function aut_journal_block($root, $title, $rows, $more, $events, $curEv, $curU, $off, $lim, $pEv, $pU, $pOff, $tab) {
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
    $url = function ($ev, $u, $o) use ($root, $tab, $pEv, $pU, $pOff) {
        $a = array('tab' => $tab);
        if ($ev !== '') $a[$pEv] = $ev;
        if ($u  !== '') $a[$pU]  = $u;
        if ($o  > 0)    $a[$pOff] = $o;
        return $root . 'index.php?' . http_build_query($a);
    };
    $out = '<h3 style="margin:22px 0 8px; color:#01367c; font-size:15px;">' . $e($title) . "</h3>\n"
        . '<form method="get" action="' . $e($root) . 'index.php" style="margin:0 0 10px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">'
        . '<input type="hidden" name="tab" value="' . $e($tab) . '">'
        . '<label style="font-size:12px; color:#4c4e50;">' . $e(aut_t('UsLogEvent')) . '<br>'
        . '<select name="' . $e($pEv) . '" onchange="this.form.submit()">'
        . '<option value="">' . $e(aut_t('UsLogAll')) . '</option>';
    foreach ($events as $ev) {
        $out .= '<option value="' . $e($ev) . '"' . ($curEv === $ev ? ' selected' : '') . '>' . $e($ev) . '</option>';
    }
    $out .= '</select></label>'
        . '<label style="font-size:12px; color:#4c4e50;">' . $e(aut_t('UsLogUser')) . '<br>'
        . '<input type="text" name="' . $e($pU) . '" value="' . $e($curU) . '" placeholder="' . $e(aut_t('UsLogUserPh')) . '" size="18">'
        . '</label>'
        . '<button type="submit">' . $e(aut_t('UsLogFilter')) . '</button>'
        . ($curEv !== '' || $curU !== '' ? '<a style="font-size:13px;" href="' . $e($url('', '', 0)) . '">' . $e(aut_t('UsLogReset')) . '</a>' : '')
        . "</form>\n"
        . '<table class="Tabella">'
        . '<tr><th class="Title w-20">' . $e(aut_t('UsLogDate')) . '</th><th class="Title w-20">' . $e(aut_t('UsLogUser'))
        . '</th><th class="Title w-20">IP</th><th class="Title w-40">' . $e(aut_t('UsLogEvent')) . "</th></tr>\n";
    foreach ($rows as $l) {
        $out .= '<tr><td>' . $e($l->w) . '</td><td>' . $e($l->u) . '</td><td>' . $e($l->ip) . '</td><td>' . $e($l->ev) . "</td></tr>\n";
    }
    if (!count($rows)) $out .= '<tr><td colspan="4" class="Center">' . $e(aut_t('UsLogNone')) . "</td></tr>\n";
    $out .= "</table>\n"
        . '<div style="display:flex; gap:16px; align-items:center; margin:8px 0 0; font-size:13px;">'
        . ($off > 0 ? '<a href="' . $e($url($curEv, $curU, max(0, $off - $lim))) . '">← ' . $e(aut_t('UsLogNewer')) . '</a>' : '')
        . '<span style="color:#7d8183;">' . (count($rows) ? ($off + 1) . '–' . ($off + count($rows)) : '0') . '</span>'
        . ($more ? '<a href="' . $e($url($curEv, $curU, $off + $lim)) . '">' . $e(aut_t('UsLogOlder')) . ' →</a>' : '')
        . "</div>\n";
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!aut_csrf_check()) {
        $msgErr = htmlspecialchars(aut_t('UsSessionExpired'));
    } else {
        $action = $_POST['action'] ?? '';
        $id = intval($_POST['id'] ?? 0);

        if ($action == 'create') {
            $username = trim($_POST['username'] ?? '');
            $role  = $_POST['role'] ?? AUT_ROLE_CLUB;
            $scope = in_array($role, array(AUT_ROLE_FED, AUT_ROLE_ADMIN)) ? '' : trim($_POST['scope'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if (!preg_match('/^[0-9a-z._-]{3,64}$/i', $username)) {
                $msgErr = htmlspecialchars(aut_t('UsBadId'));
            } elseif (!array_key_exists($role, aut_roles())) {
                $msgErr = htmlspecialchars(aut_t('UsBadRole'));
            } elseif ($e = aut_scope_error($role, $scope)) {
                $msgErr = htmlspecialchars($e);
            } elseif (aut_get_user($username)) {
                $msgErr = htmlspecialchars(aut_t('UsIdTaken'));
            } else {
                $tmpPwd = aut_gen_password();
                safe_w_sql("INSERT INTO AuthUsers (AuUsername, AuPassword, AuName, AuEmail, AuRole, AuScope, AuMustChangePwd)
                    VALUES (" . StrSafe_DB($username) . "," . StrSafe_DB(password_hash($tmpPwd, PASSWORD_DEFAULT)) . ","
                    . StrSafe_DB($name) . "," . StrSafe_DB($email) . "," . StrSafe_DB($role) . "," . StrSafe_DB($scope) . ", 1)");
                aut_log('USER_CREATE', $username);
                $msgOk = aut_t('UsCreated', htmlspecialchars($username));
            }
        }

        if ($action == 'save' && $id) {
            $role  = $_POST['role'] ?? AUT_ROLE_CLUB;
            $scope = in_array($role, array(AUT_ROLE_FED, AUT_ROLE_ADMIN)) ? '' : trim($_POST['scope'] ?? '');
            $active = !empty($_POST['active']) ? 1 : 0;
            $q = safe_r_sql("SELECT * FROM AuthUsers WHERE AuId=$id");
            $u = safe_fetch($q);
            if (!$u) {
                $msgErr = htmlspecialchars(aut_t('UsNotFound'));
            } elseif (!array_key_exists($role, aut_roles())) {
                $msgErr = htmlspecialchars(aut_t('UsBadRole'));
            } elseif ($e = aut_scope_error($role, $scope)) {
                $msgErr = htmlspecialchars($e);
            } elseif ($u->AuRole == 'ADMIN' && $u->AuActive && ($role != 'ADMIN' || !$active) && aut_admin_count() <= 1) {
                $msgErr = htmlspecialchars(aut_t('UsLastAdmin'));
            } else {
                safe_w_sql("UPDATE AuthUsers SET
                    AuName="  . StrSafe_DB(trim($_POST['name'] ?? ''))  . ",
                    AuEmail=" . StrSafe_DB(trim($_POST['email'] ?? '')) . ",
                    AuRole="  . StrSafe_DB($role) . ",
                    AuScope=" . StrSafe_DB($scope) . ",
                    AuActive=$active
                    WHERE AuId=$id");
                if (!$active) aut_sessions_revoke($id);
                aut_log('USER_SAVE', $u->AuUsername);
                $msgOk = aut_t('UsSaved', htmlspecialchars($u->AuUsername));
            }
        }

        if ($action == 'resetpwd' && $id) {
            $q = safe_r_sql("SELECT * FROM AuthUsers WHERE AuId=$id");
            if ($u = safe_fetch($q)) {
                $tmpPwd = aut_gen_password();
                safe_w_sql("UPDATE AuthUsers SET AuPassword=" . StrSafe_DB(password_hash($tmpPwd, PASSWORD_DEFAULT))
                    . ", AuMustChangePwd=1 WHERE AuId=$id");
                aut_sessions_revoke($id);
                aut_log('USER_PWDRESET', $u->AuUsername);
                $msgOk = aut_t('UsPwdReset', htmlspecialchars($u->AuUsername));
            }
        }

        if ($action == 'reset2fa' && $id) {
            $q = safe_r_sql("SELECT * FROM AuthUsers WHERE AuId=$id");
            if ($u = safe_fetch($q)) {
                safe_w_sql("UPDATE AuthUsers SET AuTotpSecret='', AuTotpEnabled=0, AuTotpLastSlot=0 WHERE AuId=$id");
                aut_sessions_revoke($id);
                aut_log('TOTP_RESET', $u->AuUsername);
                $msgOk = aut_t('Us2faReset', htmlspecialchars($u->AuUsername));
            }
        }

        if ($action == 'killsessions' && $id) {
            $q = safe_r_sql("SELECT * FROM AuthUsers WHERE AuId=$id");
            if ($u = safe_fetch($q)) {
                aut_sessions_revoke($id);
                aut_log('SESSIONS_REVOKE', $u->AuUsername);
                $msgOk = aut_t('UsSessionsKilled', htmlspecialchars($u->AuUsername));
            }
        }

        if ($action == 'delete' && $id) {
            $q = safe_r_sql("SELECT * FROM AuthUsers WHERE AuId=$id");
            $u = safe_fetch($q);
            if (!$u) {
                $msgErr = htmlspecialchars(aut_t('UsNotFound'));
            } elseif ($u->AuRole == 'ADMIN' && $u->AuActive && aut_admin_count() <= 1) {
                $msgErr = htmlspecialchars(aut_t('UsLastAdmin'));
            } else {
                safe_w_sql("DELETE FROM AuthUsers WHERE AuId=$id");
                aut_sessions_revoke($id);
                aut_log('USER_DELETE', $u->AuUsername);
                $msgOk = aut_t('UsDeleted', htmlspecialchars($u->AuUsername));
            }
        }

        // ---- Actions on the ARCHER accounts (BookingArchers) ----
        if ($hasArchers && strpos($action, 'archer_') === 0 && $id) {
            $r = safe_fetch(safe_r_sql("SELECT BaId, BaLicence FROM BookingArchers WHERE BaId=$id"));
            if (!$r) {
                $msgErr = htmlspecialchars(aut_t('UsArcNotFound'));
            } else {
                $lic = htmlspecialchars($r->BaLicence);
                if ($action == 'archer_save') {
                    $active = !empty($_POST['active']) ? 1 : 0;
                    safe_w_sql("UPDATE BookingArchers SET BaActive=$active WHERE BaId=$id");
                    if (!$active) safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher=$id");   // deactivation → sign-out
                    aut_log('ARCHER_SAVE', $r->BaLicence);
                    $msgOk = aut_t($active ? 'UsArcSaved' : 'UsArcSavedOff', $lic);
                } elseif ($action == 'archer_killsessions') {
                    safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher=$id");
                    aut_log('ARCHER_SESSIONS', $r->BaLicence);
                    $msgOk = aut_t('UsArcSessionsKilled', $lic);
                } elseif ($action == 'archer_resetcgu') {
                    safe_w_sql("UPDATE BookingArchers SET BaCguVer='', BaCguAt=NULL WHERE BaId=$id");
                    aut_log('ARCHER_CGU_RESET', $r->BaLicence);
                    $msgOk = aut_t('UsArcCguReset', $lic);
                } elseif ($action == 'archer_reset2fa') {
                    // Lost phone: removes the 2FA + signs out (the archer can sign in again without
                    // a code, then turn it on again if they wish).
                    safe_w_sql("UPDATE BookingArchers SET BaTotpSecret='', BaTotpEnabled=0, BaTotpLastSlot=0 WHERE BaId=$id");
                    safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher=$id");
                    aut_log('ARCHER_TOTP_RESET', $r->BaLicence);
                    $msgOk = aut_t('UsArc2faReset', $lic);
                } elseif ($action == 'archer_delete') {
                    safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher=$id");
                    safe_w_sql("DELETE FROM BookingArchers WHERE BaId=$id");
                    aut_log('ARCHER_DELETE', $r->BaLicence);
                    $msgOk = aut_t('UsArcDeleted', $lic);
                }
            }
        }
    }
}

$users = array();
$q = safe_r_sql("SELECT * FROM AuthUsers ORDER BY AuRole, AuScope, AuUsername");
while ($r = safe_fetch($q)) $users[] = $r;

$sessCount = array();
$q = safe_r_sql("SELECT AsnUser, COUNT(*) AS n FROM AuthSessions
    WHERE AsnLastSeen > DATE_SUB(NOW(), INTERVAL " . AUT_SESSION_IDLE_H . " HOUR) GROUP BY AsnUser");
while ($r = safe_fetch($q)) $sessCount[$r->AsnUser] = intval($r->n);

// ARCHER accounts (when the online registration is installed).
$archers = array();
$arcSess = array();
if ($hasArchers) {
    $q = safe_r_sql("SELECT * FROM BookingArchers ORDER BY BaFamilyName, BaName, BaLicence");
    while ($r = safe_fetch($q)) $archers[] = $r;
    $q = safe_r_sql("SELECT BkArcher, COUNT(*) AS n FROM BookingSessions
        WHERE BkLastSeen > DATE_SUB(NOW(), INTERVAL 12 HOUR) GROUP BY BkArcher");
    while ($r = safe_fetch($q)) $arcSess[intval($r->BkArcher)] = intval($r->n);
}

// Logs per audience: org = AuthLog, archer = BookingLog. Can be filtered (event + search) + paged.
$LOG_LIM = 150;
$logFetch = function ($table, $p, $ev, $user, $off, $lim) {
    $w = "1=1";
    if ($ev !== '')   $w .= " AND {$p}Event = " . StrSafe_DB($ev);
    if ($user !== '') $w .= " AND {$p}User LIKE " . StrSafe_DB('%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $user) . '%');
    $rs = safe_r_sql("SELECT {$p}When AS w, {$p}User AS u, {$p}IP AS ip, {$p}Event AS ev
        FROM $table WHERE $w ORDER BY {$p}Id DESC LIMIT " . (intval($lim) + 1) . " OFFSET " . intval($off));
    $out = array(); while ($r = safe_fetch($rs)) $out[] = $r; return $out;
};
$logEvents = function ($table, $p) {
    $rs = safe_r_sql("SELECT DISTINCT {$p}Event AS ev FROM $table ORDER BY {$p}Event LIMIT 200");
    $out = array(); while ($r = safe_fetch($rs)) if ((string) $r->ev !== '') $out[] = $r->ev; return $out;
};

$oEv = trim((string) ($_GET['oev'] ?? '')); $oU = trim((string) ($_GET['ou'] ?? '')); $oOff = max(0, intval($_GET['ooff'] ?? 0));
$orgLogs = $logFetch('AuthLog', 'Al', $oEv, $oU, $oOff, $LOG_LIM);
$orgMore = count($orgLogs) > $LOG_LIM; if ($orgMore) array_pop($orgLogs);
$orgEvents = $logEvents('AuthLog', 'Al');

$hasBkLog = $hasArchers && (bool) safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingLog'"));
$arcLogs = array(); $arcMore = false; $arcEvents = array();
$aEv = trim((string) ($_GET['aev'] ?? '')); $aU = trim((string) ($_GET['au'] ?? '')); $aOff = max(0, intval($_GET['aoff'] ?? 0));
if ($hasBkLog) {
    $arcLogs = $logFetch('BookingLog', 'Bl', $aEv, $aU, $aOff, $LOG_LIM);
    $arcMore = count($arcLogs) > $LOG_LIM; if ($arcMore) array_pop($arcLogs);
    $arcEvents = $logEvents('BookingLog', 'Bl');
}

$roles = aut_roles();
$PAGE_TITLE = aut_t('UsTitle');
include('Common/Templates/head.php');
?>
<style>
#aut-tabs { display:flex; gap:8px; margin:6px 0 14px; }
#aut-tabs button { padding:9px 18px; border:1px solid #c9d4df; border-radius:6px 6px 0 0;
    background:#eef2f6; color:#334; font-size:14px; cursor:pointer; }
#aut-tabs button.on { background:#1a4f8b; color:#fff; border-color:#1a4f8b; font-weight:600; }
.aut-pane { display:none; }
.aut-pane.on { display:block; }
.aut-banner { padding:10px 14px; border-radius:6px; margin:0 0 14px; font-size:14px; }
.aut-banner.ok  { background:#e8f4e8; color:#1a5c1a; border:1px solid #9ccf9c; }
.aut-banner.err { background:#fde8e8; color:#8b1a1a; border:1px solid #e0a0a0; }
.aut-banner.pwd { background:#fff6df; color:#6b5a1a; border:1px solid #e6d18a; }
</style>
<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
// A button of a row's form, with a confirmation question in a data attribute (never in the
// script itself, where a name would have to be escaped for JavaScript too).
$btn = function ($form, $action, $label, $confirm = '', $title = '') use ($e) {
    return '<button form="' . $form . '" type="submit"' . ($action !== '' ? ' name="action" value="' . $action . '"' : '')
        . ($title !== '' ? ' title="' . $e($title) . '"' : '')
        . ($confirm !== '' ? ' data-confirm="' . $e($confirm) . '" onclick="return confirm(this.dataset.confirm);"' : '')
        . '>' . $e($label) . '</button>';
};
$cguCell = function ($ver, $at, $resetBtn) use ($e) {
    if (empty($at)) return null;
    $cur = ((string) $ver === (string) aut_legal_version());
    return '<span style="color:' . ($cur ? '#1a5c1a' : '#8a6d1a') . '" title="' . $e(aut_t('UsCguVersionTip', $ver)) . '">v' . $e($ver) . '<br>'
        . $e(date('d/m/Y H:i', strtotime($at))) . '</span>'
        . ($cur ? '' : '<br><small style="color:#8a6d1a">(' . $e(aut_t('UsCguAgain')) . ')</small>')
        . $resetBtn;
};
$view = function ($type, $field, $value, $title) use ($e, $CFG) {
    return '<form method="post" action="' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/impersonate.php" style="display:inline;">'
        . aut_csrf_field()
        . '<input type="hidden" name="type" value="' . $type . '">'
        . '<input type="hidden" name="' . $field . '" value="' . $e($value) . '">'
        . '<button type="submit" title="' . $e($title) . '">👁 ' . $e(aut_t('UsView')) . '</button></form>';
};

if ($msgOk)  echo '<div class="aut-banner ok">' . $msgOk . '</div>';
if ($msgErr) echo '<div class="aut-banner err">' . $msgErr . '</div>';
if ($tmpPwd) {
    echo '<div class="aut-banner pwd">' . aut_t('UsTmpPwd', '<b style="font-family:monospace; font-size:16px;">' . htmlspecialchars($tmpPwd) . '</b>') . '</div>';
}

echo '<div id="aut-tabs">'
    . '<button type="button" data-pane="org" class="' . ($activeTab === 'org' ? 'on' : '') . '">🏹 ' . $e(aut_t('UsTabOrg', count($users))) . '</button>'
    . ($hasArchers ? '<button type="button" data-pane="archers" class="' . ($activeTab === 'archers' ? 'on' : '') . '">🎯 ' . $e(aut_t('UsTabArc', count($archers))) . '</button>' : '')
    . "</div>\n";

/* ---- Organisers ---- */
echo '<div class="aut-pane' . ($activeTab === 'org' ? ' on' : '') . '" id="pane-org">' . "\n"
    . '<table class="Tabella">' . "\n"
    . '<tr><th class="Title" colspan="11">' . $e(aut_t('UsOrgTitle')) . "</th></tr>\n"
    . '<tr><td colspan="11" style="font-size:11px;">' . aut_t('UsOrgIntro') . "</td></tr>\n"
    . '<tr>';
foreach (array('UsColId', 'UsColName', 'UsColEmail', 'UsColRole', 'UsColScope', 'UsColActive', 'UsCol2fa', 'UsColSessions', 'UsColLastLogin', 'UsColCgu', 'UsColActions') as $k) {
    echo '<th class="Title">' . $e(aut_t($k)) . '</th>';
}
echo "</tr>\n";
foreach ($users as $u) {
    $isSso = ($u->AuPassword === '');
    $f = 'f' . $u->AuId;
    $name = $e($u->AuUsername);
    $roleOpts = '';
    foreach ($roles as $k => $lbl) $roleOpts .= '<option value="' . $k . '"' . ($u->AuRole == $k ? ' selected' : '') . '>' . $e($lbl) . '</option>';
    $cgu = $cguCell($u->AuCguVer ?? '', $u->AuCguAt ?? '', '');
    echo '<tr>'
        . '<td><b>' . $name . '</b>'
        . ($isSso ? ' <span style="background:#1a4f8b; color:#fff; font-size:9px; padding:1px 6px; border-radius:8px;">SSO</span>' : '')
        . '<form method="post" action="" id="' . $f . '">' . aut_csrf_field()
        . '<input type="hidden" name="id" value="' . $u->AuId . '"><input type="hidden" name="action" value="save"></form></td>'
        . '<td><input form="' . $f . '" type="text" name="name" size="16" value="' . $e($u->AuName) . '"></td>'
        . '<td><input form="' . $f . '" type="text" name="email" size="18" value="' . $e($u->AuEmail) . '"></td>'
        . '<td><select form="' . $f . '" name="role">' . $roleOpts . '</select></td>'
        . '<td><input form="' . $f . '" type="text" name="scope" size="8" value="' . $e($u->AuScope) . '"></td>'
        . '<td class="Center"><input form="' . $f . '" type="checkbox" name="active"' . ($u->AuActive ? ' checked' : '') . '></td>'
        . '<td class="Center" style="white-space:nowrap;">' . ($u->AuTotpEnabled ? '✅ ' . $btn($f, 'reset2fa', aut_t('UsReset'), aut_t('UsConfirm2fa', $u->AuUsername)) : '—') . '</td>'
        . '<td class="Center" style="white-space:nowrap;">' . ($sessCount[$u->AuId] ?? 0)
        . (!empty($sessCount[$u->AuId]) ? ' ' . $btn($f, 'killsessions', aut_t('UsSignOut'), aut_t('UsConfirmSessions', $u->AuUsername)) : '') . '</td>'
        . '<td class="Center">' . ($u->AuLastLogin ? $e($u->AuLastLogin) : '—') . '</td>'
        . '<td class="Center" style="white-space:nowrap; font-size:11px;">'
        . ($cgu !== null ? $cgu : '<span style="color:#a0006d">' . $e(aut_t('UsCguNotAccepted')) . '</span>') . '</td>'
        . '<td class="Center" style="white-space:nowrap;">'
        . $btn($f, '', aut_t('ShSave')) . ' '
        . (!$isSso ? $btn($f, 'resetpwd', aut_t('UsResetPwd'), aut_t('UsConfirmPwd', $u->AuUsername)) . ' ' : '')
        . $btn($f, 'delete', aut_t('UsDelete'), aut_t('UsConfirmDelete', $u->AuUsername))
        . ($u->AuRole != 'ADMIN' ? ' ' . $view('org', 'user', $u->AuUsername, aut_t('UsViewOrgTip')) : '')
        . "</td></tr>\n";
}
if (!count($users)) echo '<tr><td colspan="11" class="Center">' . $e(aut_t('UsNone')) . "</td></tr>\n";

$roleOpts = '';
foreach ($roles as $k => $lbl) $roleOpts .= '<option value="' . $k . '">' . $e($lbl) . '</option>';
echo '<tr><th class="Title" colspan="11">' . $e(aut_t('UsCreate')) . "</th></tr>\n"
    . '<tr>'
    . '<td><input form="fnew" type="text" name="username" size="14" placeholder="' . $e(aut_t('UsPhId')) . '"></td>'
    . '<td><input form="fnew" type="text" name="name" size="16" placeholder="' . $e(aut_t('UsPhName')) . '"></td>'
    . '<td><input form="fnew" type="text" name="email" size="18" placeholder="' . $e(aut_t('UsPhEmail')) . '"></td>'
    . '<td><select form="fnew" name="role">' . $roleOpts . '</select></td>'
    . '<td><input form="fnew" type="text" name="scope" size="8" placeholder="' . $e(aut_t('UsPhScope')) . '"></td>'
    . '<td colspan="5" style="font-size:10px;">' . $e(aut_t('UsTmpPwdNote')) . '</td>'
    . '<td class="Center"><form method="post" action="" id="fnew">' . aut_csrf_field() . '<input type="hidden" name="action" value="create"></form>'
    . $btn('fnew', '', aut_t('UsCreateBtn')) . '</td>'
    . "</tr>\n</table>\n";

echo aut_journal_block($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/', aut_t('UsLogOrg'),
    $orgLogs, $orgMore, $orgEvents, $oEv, $oU, $oOff, $LOG_LIM, 'oev', 'ou', 'ooff', 'org');
echo "</div>\n";

/* ---- Archers ---- */
if ($hasArchers) {
    echo '<div class="aut-pane' . ($activeTab === 'archers' ? ' on' : '') . '" id="pane-archers">' . "\n"
        . '<table class="Tabella">' . "\n"
        . '<tr><th class="Title" colspan="9">' . $e(aut_t('UsArcTitle')) . "</th></tr>\n"
        . '<tr><td colspan="9" style="font-size:11px;">' . aut_t('UsArcIntro') . "</td></tr>\n"
        . '<tr>';
    foreach (array('UsColLicence', 'UsColName', 'UsColClub', 'UsColEmail', 'UsColActive', 'UsColSessions', 'UsColLastLogin', 'UsColCgu', 'UsColActions') as $k) {
        echo '<th class="Title">' . $e(aut_t($k)) . '</th>';
    }
    echo "</tr>\n";
    foreach ($archers as $a) {
        $aid = intval($a->BaId);
        $f = 'a' . $aid;
        $cgu = $cguCell($a->BaCguVer ?? '', $a->BaCguAt ?? '',
            '<br>' . $btn($f, 'archer_resetcgu', aut_t('UsReset'), aut_t('UsConfirmCgu', $a->BaLicence)));
        echo '<tr>'
            . '<td><b>' . $e($a->BaLicence) . '</b>'
            . '<form method="post" action="" id="' . $f . '">' . aut_csrf_field()
            . '<input type="hidden" name="tab" value="archers"><input type="hidden" name="id" value="' . $aid . '"></form></td>'
            . '<td>' . ($e(trim($a->BaFamilyName . ' ' . $a->BaName)) ?: '—') . '</td>'
            . '<td>' . ($e($a->BaClubCode) ?: '—') . '</td>'
            . '<td>' . ($e($a->BaEmail) ?: '—') . '</td>'
            . '<td class="Center"><input form="' . $f . '" type="checkbox" name="active"' . ($a->BaActive ? ' checked' : '') . '></td>'
            . '<td class="Center" style="white-space:nowrap;">' . ($arcSess[$aid] ?? 0)
            . (!empty($arcSess[$aid]) ? ' ' . $btn($f, 'archer_killsessions', aut_t('UsSignOut'), aut_t('UsConfirmSessions', $a->BaLicence)) : '') . '</td>'
            . '<td class="Center">' . ($a->BaLastLogin ? $e($a->BaLastLogin) : '—') . '</td>'
            . '<td class="Center" style="white-space:nowrap; font-size:11px;">'
            . ($cgu !== null ? $cgu : '<span style="color:#a0006d">' . $e(aut_t('UsCguNo')) . '</span>') . '</td>'
            . '<td class="Center" style="white-space:nowrap;">'
            . $btn($f, 'archer_save', aut_t('ShSave')) . ' '
            . $btn($f, 'archer_delete', aut_t('UsDelete'), aut_t('UsConfirmArcDelete', $a->BaLicence)) . ' '
            . $view('archer', 'licence', $a->BaLicence, aut_t('UsViewArcTip'))
            . (!empty($a->BaTotpEnabled) ? ' ' . $btn($f, 'archer_reset2fa', '🔒 ' . aut_t('UsReset2fa'), aut_t('UsConfirmArc2fa', $a->BaLicence), aut_t('UsReset2faTip')) : '')
            . "</td></tr>\n";
    }
    if (!count($archers)) echo '<tr><td colspan="9" class="Center">' . $e(aut_t('UsArcNone')) . "</td></tr>\n";
    echo "</table>\n";
    if ($hasBkLog) {
        echo aut_journal_block($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/', aut_t('UsLogArc'),
            $arcLogs, $arcMore, $arcEvents, $aEv, $aU, $aOff, $LOG_LIM, 'aev', 'au', 'aoff', 'archers');
    }
    echo "</div>\n";
}

echo '<script>var AUT_TAB = ' . json_encode($activeTab) . ";</script>\n";
?>
<script>
(function () {
  var active = AUT_TAB;
  var tabs = [].slice.call(document.querySelectorAll('#aut-tabs button'));
  function show(p) {
    if (!document.getElementById('pane-' + p)) p = 'org';
    [].forEach.call(document.querySelectorAll('.aut-pane'), function (x) { x.classList.toggle('on', x.id === 'pane-' + p); });
    tabs.forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-pane') === p); });
    try { history.replaceState(null, '', location.pathname + '?tab=' + p); } catch (e) {}
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-pane')); }); });
  show(active);
})();
</script>
<?php
include('Common/Templates/tail.php');
