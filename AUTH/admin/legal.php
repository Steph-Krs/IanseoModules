<?php
/**
 * admin/legal.php — legal information of the server's operator (ADMIN only).
 *
 * The operator types here their identity, their host, their contacts, etc. The module then
 * GENERATES a complete legal notice / terms of use / privacy policy / cookies page (public
 * page legal.php). Each text can be OVERRIDDEN (free area); left empty, the generated text
 * applies. Storage: legal.local.json (not versioned).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/legal-lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

$fields = aut_legal_fields();
$docs   = aut_legal_docs();
$ok = ''; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $err = aut_t('LoginSessionExpired');
    } else {
        $conf = aut_legal_conf();
        $op = array();
        foreach (array_keys($fields) as $k) $op[$k] = trim((string) ($_POST['op'][$k] ?? ''));
        $conf['operator'] = $op;
        $custom = array();
        foreach (array_keys($docs) as $k) $custom[$k] = trim((string) ($_POST['custom'][$k] ?? ''));
        $conf['custom'] = $custom;
        $ver = trim((string) ($_POST['version'] ?? '1'));
        $conf['version'] = ($ver !== '') ? mb_substr($ver, 0, 16) : '1';
        if (aut_legal_save($conf)) {
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/legal.php?ok=1'); die();
        }
        $err = aut_t('AlWriteFailed');
    }
}
if (isset($_GET['ok'])) $ok = aut_t('AlSaved');

$conf = aut_legal_conf();
$op   = aut_legal_operator();
$statuses = array('' => '—') + aut_legal_statuses();
// A free value from an older configuration stays selectable, so saving does not lose it.
if (!isset($statuses[$op['status']])) $statuses[$op['status']] = $op['status'];

// Follow-up of the acceptances of the terms (current version). Archers are only counted when
// the booking table exists (online registration installed).
aut_legal_ensure_schema();
$curVer = aut_legal_version();
$cnt = function ($sql) { $r = safe_fetch(safe_r_sql($sql)); return $r ? intval($r->n) : 0; };
$orgOk  = $cnt("SELECT COUNT(*) n FROM AuthUsers WHERE AuCguVer = " . StrSafe_DB($curVer));
$orgTot = $cnt("SELECT COUNT(*) n FROM AuthUsers");
$hasArchers = $cnt("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingArchers'") > 0;
$arcOk = $arcTot = 0;
if ($hasArchers) {
    $arcOk  = $cnt("SELECT COUNT(*) n FROM BookingArchers WHERE BaCguVer = " . StrSafe_DB($curVer));
    $arcTot = $cnt("SELECT COUNT(*) n FROM BookingArchers");
}

$PAGE_TITLE = aut_t('MenuLegal');
include('Common/Templates/head.php');
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
?>
<style>
#aut-lg { max-width:820px; }
#aut-lg h1 { font-size:22px; color:#01367c; margin:0 0 4px; }
#aut-lg .lead { color:#4c4e50; font-size:14px; margin:0 0 16px; max-width:680px; }
#aut-lg .sec { background:#fff; border:1px solid #d2d4d6; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08);
    padding:16px 18px; margin:0 0 14px; }
#aut-lg .sec h2 { font-size:15px; color:#0254a8; margin:0 0 10px; }
#aut-lg label { display:block; font-weight:600; font-size:13px; color:#01367c; margin:12px 0 3px; }
#aut-lg .help { font-weight:400; color:#7d8183; font-size:12px; }
#aut-lg input[type=text], #aut-lg textarea, #aut-lg select { width:100%; max-width:560px; padding:8px 10px;
    border:1px solid #cfd3d6; border-radius:6px; font:inherit; font-size:14px; }
#aut-lg textarea { min-height:70px; resize:vertical; max-width:100%; }
#aut-lg .msg { padding:10px 13px; border-radius:6px; margin:0 0 14px; font-size:14px; }
#aut-lg .ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-lg .err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#aut-lg .btn { padding:9px 18px; border:1px solid #0254a8; border-radius:6px; background:#0254a8;
    color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
#aut-lg .btn:hover { background:#01367c; }
#aut-lg .btn-2 { background:#f7f7f7; color:#20263d; border-color:#d2d4d6; text-decoration:none; display:inline-block; }
#aut-lg .prev { display:flex; flex-wrap:wrap; gap:8px; margin:6px 0 0; }
#aut-lg details.adv > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:4px 0; }
#aut-lg .warn { background:#fdf0ef; border:1px solid #e8b4ae; color:#8b1a1a; border-radius:6px; padding:10px 13px; font-size:13px; margin:0 0 14px; }
</style>
<?php
$root = $e($CFG->ROOT_DIR);
echo '<div id="aut-lg">' . "\n"
    . '<h1>' . $e(aut_t('AlTitle')) . "</h1>\n"
    . '<p class="lead">' . aut_t('AlLead') . "</p>\n";
if ($ok)  echo '<div class="msg ok">' . $e($ok) . "</div>\n";
if ($err) echo '<div class="msg err">' . $e($err) . "</div>\n";
if (!aut_legal_configured()) echo '<div class="warn">⚠️ ' . aut_t('AlNotConfigured') . "</div>\n";

echo '<div class="sec">'
    . '<h2>' . $e(aut_t('AlFollowUp', $curVer)) . '</h2>'
    . '<p style="margin:0 0 6px; font-size:14px">'
    . aut_t('AlOrgCount', array('ok' => intval($orgOk), 'total' => intval($orgTot)))
    . ($hasArchers ? '<br>' . aut_t('AlArcCount', array('ok' => intval($arcOk), 'total' => intval($arcTot))) : '')
    . '</p>'
    . '<p class="help" style="margin:0">' . aut_t('AlDetail', '<a href="' . $root . 'Modules/Custom/AUTH/admin/">'
        . $e(aut_t('MenuTitle') . ' › ' . aut_t('MenuUsers')) . '</a>') . '</p>'
    . "</div>\n";

echo '<form method="post">' . aut_csrf_field() . "\n"
    . '<div class="sec"><h2>' . $e(aut_t('AlOperator')) . "</h2>\n";
foreach ($fields as $k => $f) {
    $id = 'op_' . $e($k);
    $name = 'op[' . $e($k) . ']';
    echo '<label for="' . $id . '">' . $e($f[0]) . ($f[1] ? ' <span class="help">' . $e($f[1]) . '</span>' : '') . "</label>\n";
    if ($k === 'status') {
        echo '<select id="' . $id . '" name="' . $name . '">';
        foreach ($statuses as $sv => $sl) {
            echo '<option value="' . $e($sv) . '"' . ($op[$k] === $sv ? ' selected' : '') . '>' . $e($sl) . '</option>';
        }
        echo "</select>\n";
    } elseif ($k === 'address' || $k === 'host_address') {
        echo '<textarea id="' . $id . '" name="' . $name . '" rows="2">' . $e($op[$k]) . "</textarea>\n";
    } else {
        echo '<input type="text" id="' . $id . '" name="' . $name . '" value="' . $e($op[$k]) . "\">\n";
    }
}
echo "</div>\n";

echo '<div class="sec"><h2>' . $e(aut_t('AlVersionH')) . '</h2>'
    . '<label for="version">' . $e(aut_t('AlVersionNo')) . ' <span class="help">' . $e(aut_t('AlVersionHelp')) . '</span></label>'
    . '<input type="text" id="version" name="version" maxlength="16" style="max-width:160px" value="' . $e(aut_legal_version()) . '">'
    . "</div>\n";

echo '<div class="sec"><h2>' . $e(aut_t('AlGenerated')) . '</h2>'
    . '<p class="help" style="margin:0 0 8px">' . $e(aut_t('AlPreview')) . '</p>'
    . '<div class="prev">';
foreach ($docs as $k => $d) {
    echo '<a class="btn btn-2" href="' . $root . 'Modules/Custom/AUTH/legal.php?doc=' . $e($d[1]) . '" target="_blank" rel="noopener">' . $e($d[0]) . ' ↗</a>';
}
echo "</div>\n"
    . '<details class="adv" style="margin-top:14px"><summary>' . $e(aut_t('AlOverride')) . '</summary>'
    . '<p class="help" style="margin:6px 0">' . aut_t('AlOverrideHelp') . "</p>\n";
foreach ($docs as $k => $d) {
    echo '<label for="custom_' . $e($k) . '">' . $e($d[0]) . '</label>'
        . '<textarea id="custom_' . $e($k) . '" name="custom[' . $e($k) . ']" rows="4" placeholder="' . $e(aut_t('AlOverridePh')) . '">'
        . $e($conf['custom'][$k] ?? '') . "</textarea>\n";
}
echo "</details>\n</div>\n"
    . '<p><button type="submit" class="btn">' . $e(aut_t('ShSave')) . "</button></p>\n"
    . "</form>\n</div>\n";

include('Common/Templates/tail.php');
