<?php
/**
 * AUTH module — menu.php
 * Included on EVERY page by get_which_menu() (Common/Menu.php).
 * Adds the menu entries + the user bar (signed in / sign out).
 */

require_once(__DIR__ . '/lib.php');

// Merged sub-module (former BOOKING: online registration + shop). The ianseo glob only sweeps
// Custom/*/menu.php → booking/menu.php no longer loads itself, so it is included here (it fills
// $ret from $on/$acl, in this same context).
if (is_file(__DIR__ . '/booking/menu.php')) include(__DIR__ . '/booking/menu.php');
// Points of sale (refreshment bar, food, shop): same reason, same context.
if (is_file(__DIR__ . '/shop/menu.php')) include(__DIR__ . '/shop/menu.php');

$_aut_on     = !empty($CFG->USERAUTH);
$_aut_logged = $_aut_on && !empty($_SESSION['AUTH_User']);
$_aut_root   = !empty($_SESSION['AUTH_ROOT']);
// Module admin: ADMIN account signed in, or a context without authentication (local console / dev)
$_aut_admin  = $_aut_root
    || (empty($_SESSION['AUTH_ENABLE']) && isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite);

/* ---- Modules menu ---- */
$ret['MODS']['AUTH'][] = aut_t('MenuTitle');
if ($_aut_logged || $_aut_admin) {
    $ret['MODS']['AUTH'][] = aut_t('MenuShare') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/';
    $ret['MODS']['AUTH'][] = aut_t('MenuReport') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/tickets.php';
}
if ($_aut_admin) {
    $ret['MODS']['AUTH'][] = aut_t('MenuUsers') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/';
    $ret['MODS']['AUTH'][] = aut_t('MenuAnonymise') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/anonymise.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuTrust') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/trust.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuStats') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/stats.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuTickets') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/tickets.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuLegal') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/legal.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuDeploy') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/deploy.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuConfig') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/config.php';
    $ret['MODS']['AUTH'][] = aut_t('MenuUpdate') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/update.php';
}

/* ---- Competitions created only through SYNCHRO_FFTA (option, lib.php): the core's "New" is
       hidden for everyone but the administrator (the guard of lib.php refuses it anyway).
       SYNCHRO_FFTA's own entry, placed before "New" when there is one, then ends the menu. ---- */
if (!empty($ret['COMP']) && is_array($ret['COMP']) && aut_sfa_create_only() && !$_aut_admin) {
    $ret['COMP'] = array_values(array_filter($ret['COMP'], function ($item) {
        return !is_string($item) || strpos($item, 'Tournament/index.php?New=') === false;
    }));
}
if ($_aut_admin && aut_sfa_present()) {
    $ret['MODS']['AUTH'][] = aut_t('MenuSfaOnly') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/sfa.php';
}

/* ---- User bar (once per page) ---- */
if (!empty($GLOBALS['_aut_bar_done'])) return;
$GLOBALS['_aut_bar_done'] = true;

/* ISK policy of an online server: ng-pro / ng-live revoke the ianseo licence → the open
   competition is brought back to "lite" when such a mode was saved (import or core page).
   Server safety net; the UI also hides the choice (below + SYNCHRO_FFTA). */
if ($_aut_on) aut_isk_enforce();

$_aut_close = function ($top) {
    return '<span onclick="this.parentNode.remove()" title="' . htmlspecialchars(aut_t('BarClose'))
        . '" style="position:absolute; top:' . $top . 'px; right:12px; cursor:pointer; font-weight:bold;">✕</span>';
};

/* ---- "From another account" view (impersonation): permanent, clearly visible banner while
       the admin observes an account, with a one-click exit. ---- */
if ($_aut_on && ($_aut_imp = aut_imp_get())) {
    $_aut_impx = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/impersonate.php?exit=1&aut_csrf='
        . rawurlencode(aut_csrf_token());
    echo '<style>#aut-imp{position:fixed;top:6px;left:50%;transform:translateX(-50%);z-index:99998;'
        . 'background:#5b2a86;color:#fff;font:12px Verdana,Arial,sans-serif;padding:5px 14px;'
        . 'border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.3);opacity:.96;}'
        . '#aut-imp a{color:#fff;font-weight:bold;margin-left:12px;background:rgba(255,255,255,.2);'
        . 'padding:1px 10px;border-radius:10px;text-decoration:none;}</style>'
        . '<div id="aut-imp">👁 ' . htmlspecialchars(aut_t('BarImpView')) . ' — <b>'
        . htmlspecialchars((string) ($_aut_imp['label'] ?? '')) . '</b> — ' . htmlspecialchars(aut_t('BarReadOnly'))
        . '<a href="' . htmlspecialchars($_aut_impx) . '">' . htmlspecialchars(aut_t('BarQuit')) . '</a></div>';
    unset($_aut_imp, $_aut_impx);
}

if ($_aut_logged) {
    $_aut_r = $CFG->ROOT_DIR;
    $_aut_views = $_SESSION['AUTH_VIEWS'] ?? array();
    $_aut_curRole  = $_SESSION['AUTH_ROLE'] ?? '';
    $_aut_curScope = $_SESSION['AUTH_SCOPE'] ?? '';
    echo '<style>
#aut-bar { position:fixed; top:4px; right:8px; z-index:99990; background:#1a4f8b; color:#fff;
    font:11px Verdana,Arial,sans-serif; border-radius:14px; padding:4px 12px; opacity:.92;
    box-shadow:0 1px 4px rgba(0,0,0,.3); }
#aut-bar a { color:#cfe0f5; text-decoration:none; margin-left:10px; }
#aut-bar a:hover { color:#fff; text-decoration:underline; }
#aut-bar select { font:11px Verdana,Arial,sans-serif; margin-left:6px; max-width:230px;
    border-radius:8px; border:0; padding:1px 4px; background:#eef4fb; color:#14396b; }
#aut-warn { position:fixed; top:30px; right:8px; z-index:99990; background:#8b1a1a; color:#fff;
    font:11px Verdana,Arial,sans-serif; border-radius:4px; padding:4px 12px; }
#aut-warn a { color:#ffd7d7; }
/* Organiser signed in: hide the ianseo presentation block of the home page. */
.WhatIanseoDoes { display:none !important; }
</style>
';
    echo '<div id="aut-bar">👤 ' . htmlspecialchars($_SESSION['AUTH_User']) . "\n";
    if (count($_aut_views) > 1) {
        // view switched on the fly
        $_aut_curIdx = 0;
        foreach ($_aut_views as $_aut_i => $_aut_v) {
            if (strcasecmp($_aut_v['role'], $_aut_curRole) === 0 && strcasecmp($_aut_v['scope'], $_aut_curScope) === 0) { $_aut_curIdx = $_aut_i; break; }
        }
        echo '<form method="post" action="' . $_aut_r . 'Modules/Custom/AUTH/switch-view.php" style="display:inline;">'
            . aut_csrf_field()
            . '<select name="view" data-cur="' . $_aut_curIdx . '" data-confirm="' . htmlspecialchars(aut_t('BarSwitchConfirm')) . '"'
            . ' onchange="if(confirm(this.dataset.confirm)){this.form.submit();}else{this.selectedIndex=this.dataset.cur;}">';
        foreach ($_aut_views as $_aut_i => $_aut_v) {
            echo '<option value="' . $_aut_i . '"' . ($_aut_i == $_aut_curIdx ? ' selected' : '') . '>'
                . htmlspecialchars($_aut_v['label']) . '</option>';
        }
        echo "</select></form>\n";
    } else {
        echo ' — ' . htmlspecialchars((aut_roles()[$_aut_curRole] ?? '') . ($_aut_curScope !== '' ? ' ' . $_aut_curScope : '')) . "\n";
    }
    echo '<a href="' . $_aut_r . 'Modules/Custom/AUTH/">' . htmlspecialchars(aut_t('BarShare')) . "</a>\n"
        . '<a href="' . $_aut_r . 'Modules/Custom/AUTH/tickets.php" title="' . htmlspecialchars(aut_t('BarReportTitle')) . '">'
        . htmlspecialchars(aut_t('BarReport')) . "</a>\n";
    if ($_aut_root) {
        echo '<a href="' . $_aut_r . 'Modules/Custom/AUTH/admin/">' . htmlspecialchars(aut_t('BarAccounts')) . "</a>\n";
    }
    if (!aut_imp_get()) {
        echo '<a href="' . $_aut_r . 'Modules/Authentication/Setup2FA.php" title="' . htmlspecialchars(aut_t('Bar2faTitle')) . "\">2FA</a>\n";
    }
    if (empty($_SESSION['AUTH_SSO'])) {
        echo '<a href="' . $_aut_r . 'Modules/Authentication/ChangePassword.php">' . htmlspecialchars(aut_t('BarPassword')) . "</a>\n";
    }
    echo '<a href="' . $_aut_r . 'Modules/Authentication/LogOut.php">' . htmlspecialchars(aut_t('BarLogout')) . "</a>\n"
        . "</div>\n";
    unset($_aut_r, $_aut_views, $_aut_curRole, $_aut_curScope, $_aut_curIdx, $_aut_i, $_aut_v);
}

/* ---- Imminent maintenance: banner for EVERYONE (not only the admins), during the minutes
       before the nightly window. See aut_maintenance_notice() — silent while nothing is
       scheduled. ---- */
if ($_aut_on && function_exists('aut_maintenance_notice')
        && ($_aut_mnt = aut_maintenance_notice()) !== '') {
    echo '<div id="aut-mnt" style="position:fixed; top:0; left:0; right:0; z-index:99999;'
        . ' background:#8a4b00; color:#fff; font:13px Verdana,Arial,sans-serif; text-align:center;'
        . ' padding:7px 34px 7px 12px; box-shadow:0 2px 6px rgba(0,0,0,.3);">'
        . '⏳ ' . htmlspecialchars($_aut_mnt) . $_aut_close(5) . '</div>';
    unset($_aut_mnt);
}

/* ---- Flash message (e.g. import refused) — shown once ---- */
if ($_aut_on) {
    $_aut_flash = aut_flash_get();
    if ($_aut_flash !== '') {
        echo '<div id="aut-flash" style="position:fixed; top:40px; left:50%; transform:translateX(-50%);'
            . ' z-index:99995; max-width:640px; background:#fde8e8; border:2px solid #c0392b; color:#8b1a1a;'
            . ' font:13px Verdana,Arial,sans-serif; border-radius:6px; padding:12px 40px 12px 16px;'
            . ' box-shadow:0 4px 16px rgba(0,0,0,.25);">'
            . $_aut_flash . $_aut_close(8) . '</div>';
    }
    unset($_aut_flash);
}

/* ---- Competition form: live check of the code + the entry given back after a server
       refusal (aut_guard_tournament_save) ---- */
if ($_aut_logged && empty($_SESSION['AUTH_ROOT'])
    && strcasecmp(aut_script_rel(), '/Tournament/index.php') === 0) {
    $_aut_blk = $_SESSION['AUT_SaveBlock'] ?? null;
    unset($_SESSION['AUT_SaveBlock']);
    echo '<script>var AUT_CODE = ' . json_encode(array(
        'cur'   => $_SESSION['TourCode'] ?? '',
        'url'   => $CFG->ROOT_DIR . 'Modules/Custom/AUTH/check-code.php',
        'taken' => aut_t('CodeTaken'),
        'other' => aut_t('CodeTakenChoose'),
        'blk'   => $_aut_blk ? array('data' => $_aut_blk['data'], 'msg' => $_aut_blk['msg']) : null,
    )) . ";</script>\n";
    echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var codeEl = document.querySelector('input[name="d_ToCode"]');
    if (!codeEl) return;
    var curCode = AUT_CODE.cur;

    var msgEl = document.createElement('div');
    msgEl.style.cssText = 'display:none; margin-top:3px; padding:5px 8px; border-radius:4px;'
        + ' background:#fde8e8; border:1px solid #c0392b; color:#8b1a1a; font:11px Verdana,sans-serif;'
        + ' max-width:420px;';
    codeEl.parentNode.appendChild(msgEl);

    var codeFree = null;   // null = not checked yet
    function showMsg(t) { msgEl.textContent = t; msgEl.style.display = t ? 'block' : 'none'; }
    function checkCode() {
        var v = codeEl.value.trim();
        if (!v || (curCode && v.toLowerCase() === curCode.toLowerCase())) { codeFree = true; showMsg(''); return; }
        fetch(AUT_CODE.url + '?code=' + encodeURIComponent(v), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { codeFree = !!j.free; showMsg(j.free ? '' : (j.msg || AUT_CODE.taken)); })
            .catch(function () { codeFree = null; showMsg(''); });   // when in doubt, the server guard decides
    }
    codeEl.addEventListener('change', checkCode);
    codeEl.addEventListener('keyup', function () { codeFree = null; });
    if (codeEl.form) {
        codeEl.form.addEventListener('submit', function (e) {
            if (codeFree === false) {
                e.preventDefault();
                showMsg(msgEl.textContent || AUT_CODE.other);
                codeEl.focus(); codeEl.select();
            }
        });
    }
    if (AUT_CODE.blk) {
        /* server refusal: the entry is given back, the user only changes the code */
        var blkData = AUT_CODE.blk.data;
        var applyBlk = function () {
            for (var k in blkData) {
                var el = document.querySelector('[name="' + k + '"]');
                if (!el) continue;
                if (el.type === 'checkbox') { el.checked = blkData[k] !== '' && blkData[k] !== '0'; }
                else { el.value = blkData[k]; }
            }
        };
        applyBlk();
        ['d_Rule', 'd_ToType'].forEach(function (n) {   // runs the dependent lists again
            var el = document.querySelector('[name="' + n + '"]');
            if (el) el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        setTimeout(applyBlk, 600);
        showMsg(AUT_CODE.blk.msg);
        codeFree = false;
        codeEl.focus(); codeEl.select();
    }
});
</script>

JS;
    unset($_aut_blk);
}

/* ---- ISK policy: removes the forbidden modes (pro/live) from the selector of the competition
       page. The server enforcement (aut_isk_enforce) stays the safety net. ---- */
if ($_aut_on && strcasecmp(aut_script_rel(), '/Tournament/index.php') === 0) {
    echo '<script>var AUT_ISK_BLOCKED = ' . json_encode(array_values(aut_isk_blocked_modes())) . ";</script>\n";
    echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var blocked = AUT_ISK_BLOCKED;
    var sel = document.getElementById('IskSelect');
    if (!sel) return;
    blocked.forEach(function (v) {
        var o = sel.querySelector('option[value="' + v + '"]');
        if (o) o.remove();
    });
    if (blocked.indexOf(sel.value) >= 0) {           // a forbidden mode was saved
        var lite = sel.querySelector('option[value="ng-lite"]');
        sel.value = lite ? 'ng-lite' : '';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
});
</script>

JS;
}

/* ---- Import page of a competition (ianseo core, not editable): warns that a competition set
       up outside this server is not guaranteed to work. The ISK Pro/Live case is reported AFTER
       the import (flash of aut_isk_enforce, "when detected"). ---- */
if ($_aut_on && strcasecmp(aut_script_rel(), '/Tournament/TournamentImport.php') === 0) {
    echo '<script>var AUT_IMPORT_WARN = ' . json_encode(aut_t('ImportWarn')) . ";</script>\n";
    echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('form[enctype="multipart/form-data"]');
    if (!form) return;
    var box = document.createElement('div');
    box.style.cssText = 'max-width:640px; margin:14px auto; text-align:left; background:#fff8e1;'
        + ' border:1px solid #e0a800; border-radius:6px; padding:12px 16px; color:#5b4300;'
        + ' font:13px Verdana,Arial,sans-serif; line-height:1.5;';
    box.innerHTML = AUT_IMPORT_WARN;
    form.parentNode.insertBefore(box, form);
});
</script>

JS;
}

// admin warning (ADMIN account signed in OR localhost browsing): deployed files missing or
// different from dist/ (e.g. after an update of the module/ianseo)
if ($_aut_on && $_aut_admin) {
    $_aut_w = array();
    $_aut_st = aut_dist_status();
    if (!$_aut_st['deployed'] || $_aut_st['drift']) {
        $_aut_w[] = htmlspecialchars(aut_t('WarnRedeploy')) . ' — '
            . '<a href="' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/deploy.php">' . htmlspecialchars(aut_t('MenuDeploy')) . '</a>';
    }
    // A failed or missing night (backup, updates) must not go unnoticed until the day a
    // restore is needed. Read-only and guarded (aut_backup_alerts passes $force).
    require_once(__DIR__ . '/backup-lib.php');
    foreach (aut_backup_alerts() as $_aut_a) {
        $_aut_w[] = htmlspecialchars($_aut_a) . ' — <a href="' . $CFG->ROOT_DIR
            . 'Modules/Custom/AUTH/admin/config.php">' . htmlspecialchars(aut_t('MenuConfig')) . '</a>';
    }
    if ($_aut_w) {
        echo '<style>#aut-warn { position:fixed; top:30px; right:8px; z-index:99990; background:#8b1a1a;'
            . ' color:#fff; font:11px Verdana,Arial,sans-serif; border-radius:4px; padding:4px 12px;'
            . ' max-width:560px; line-height:1.5; }'
            . ' #aut-warn a { color:#ffd7d7; }</style>'
            . '<div id="aut-warn">⚠ ' . implode('<br>⚠ ', $_aut_w) . '</div>';
    }
    unset($_aut_st, $_aut_w, $_aut_a);
}
unset($_aut_on, $_aut_logged, $_aut_root, $_aut_admin, $_aut_close);
