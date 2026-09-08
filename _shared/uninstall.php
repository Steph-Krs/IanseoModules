<?php
/**
 * Uninstall screen, shared by every custom module.
 *
 * WHY THIS LIVES IN _shared/ AND NOT IN THE MODULE
 * A script that deletes its own folder while it is running works on Linux and
 * fails on Windows, where Apache and PHP hold the running file open. From
 * _shared/, nothing that is executing is ever deleted, so the behaviour is the
 * same on both.
 *
 * THE SAFEGUARDS, none of which may be weakened
 *   - upd_admin_guard(): AclRoot AND the server administrator view when an
 *     account module is installed;
 *   - upd_valid_module(): null byte rejected, basename, character whitelist, and
 *     a module.json requirement — so no ianseo folder can ever be named;
 *   - upd_rrmdir(): containment, refusing any path outside Custom/ and Custom/
 *     itself;
 *   - a CSRF token in the session, plus typing the module name to confirm;
 *   - table names filtered before any SQL, because version.json comes from a
 *     GitHub repository and is untrusted input;
 *   - _shared/ is never deleted from here: other modules depend on it.
 *
 * Backups are NOT made by default. The files stay recoverable from the
 * repository, and an archive would only duplicate whatever secrets a local
 * configuration holds. A module that keeps genuinely unversioned data asks for
 * one with "uninstall_backup": true, and it is then offered as a single
 * download and deleted from the server immediately afterwards.
 */

// Walk up to the ianseo root instead of counting directory levels, so this keeps
// working if the modules are installed somewhere other than Modules/Custom/.
$_upd_root = __DIR__;
while ($_upd_root !== dirname($_upd_root) && !is_file($_upd_root . '/config.php')) {
    $_upd_root = dirname($_upd_root);
}
define('HTDOCS', $_upd_root);
unset($_upd_root);

require_once(HTDOCS . '/config.php');
require_once __DIR__ . '/update-lib.php';
require_once __DIR__ . '/paths.php';

upd_admin_guard();

/* ---- Single-use download of the backup ----
 * Sent, then deleted: nothing is left on the server. The token is compared with
 * hash_equals so a wrong guess takes the same time as a right one. */
if (isset($_GET['download'])) {
    $dl = $_SESSION['upd_backup_dl'] ?? null;
    if (is_array($dl) && hash_equals((string)$dl['token'], (string)$_GET['download']) && is_file($dl['file'])) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($dl['file']) . '"');
        header('Content-Length: ' . filesize($dl['file']));
        header('X-Content-Type-Options: nosniff');
        readfile($dl['file']);
        @unlink($dl['file']);
        unset($_SESSION['upd_backup_dl']);
        exit;
    }
    unset($_SESSION['upd_backup_dl']);   // invalid or expired token
}

$modules    = upd_list_modules();
$dir        = upd_valid_module($_REQUEST['module'] ?? '');
$name       = $dir ? basename($dir) : '';
$tables     = $dir ? upd_module_tables($dir) : [];
$warning    = $dir ? upd_uninstall_warning($dir) : '';
$wantBackup = $dir ? upd_uninstall_backup($dir) : false;

$messages      = [];
$done          = false;
$downloadToken = null;
$droppedTables = [];

if (empty($_SESSION['upd_uninstall_token'])) {
    $_SESSION['upd_uninstall_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['upd_uninstall_token'];

/* ---- The uninstall itself ---- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'uninstall') {
    $confirm = trim((string)($_POST['confirm'] ?? ''));
    $dropDb  = !empty($_POST['drop_tables']);

    if (!$dir) {
        $messages[] = ['err', upd_text('ErrModuleNotFound')];
    } elseif (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $messages[] = ['err', upd_text('ErrBadToken')];
    } elseif ($confirm !== $name) {
        $messages[] = ['err', upd_text('ErrNameMismatch', $name)];
    } else {
        upd_purge_old_backups();   // no accumulation of leftover archives

        $backupPath = null;
        $backupErr  = null;
        if ($wantBackup) {
            $backup = upd_backup_module($dir, $name);
            if (isset($backup['_error'])) $backupErr = $backup['_error'];
            else                          $backupPath = $backup['file'];
        }

        if ($backupErr !== null) {
            // A backup that was asked for and failed aborts the whole thing: the
            // module asked for it precisely because its data is not recoverable.
            $messages[] = ['err', upd_text('ErrBackupFailed', $backupErr)];
        } else {
            if ($dropDb && $tables) $droppedTables = upd_drop_tables($tables);

            if (upd_rrmdir($dir)) {
                $done = true;
                unset($_SESSION['upd_uninstall_token']);
                if ($backupPath) {
                    $downloadToken = bin2hex(random_bytes(16));
                    $_SESSION['upd_backup_dl'] = ['file' => $backupPath, 'token' => $downloadToken];
                }
            } else {
                $messages[] = ['err', upd_text('ErrDeleteFailed')];
                // Leave nothing lying around if the deletion did not happen.
                if ($backupPath && is_file($backupPath)) @unlink($backupPath);
            }
        }
    }
}

$PAGE_TITLE = upd_text('UninstallTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
/* Scoped to this page's own class prefix. */
.uns-section { max-width: 760px; margin-bottom: 28px; }
.uns-msg { padding: 8px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
.uns-msg-ok  { background: #e8faf0; border-left: 3px solid #1a8a4a; color: #1a5a33; }
.uns-msg-err { background: #fde8e8; border-left: 3px solid #c0392b; color: #8a1a1a; }
.uns-box { border: 1px solid #e8b4ae; background: #fdf6f5; border-radius: 8px; padding: 18px 20px; }
.uns-box h2 { color: #c0392b; font-size: 16px; margin: 0 0 12px; }
.uns-list { font-size: 13px; color: #444; line-height: 1.9; margin: 0 0 14px; padding-left: 18px; }
.uns-list code { background: #fff; border: 1px solid #eadad8; border-radius: 3px; padding: 1px 5px; }
.uns-danger { background: #fff4e5; border-left: 3px solid #f5a623; padding: 10px 14px; border-radius: 0 6px 6px 0; font-size: 13px; margin: 14px 0; }
.uns-confirm { margin: 16px 0 8px; font-size: 13px; }
.uns-confirm input[type=text] { padding: 7px 10px; border: 1px solid #c8b4b0; border-radius: 5px; font-size: 13px; width: 220px; }
.uns-btn { padding: 9px 22px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.uns-btn-danger { background: #c0392b; color: #fff; }
.uns-btn-cancel { background: #f0f0f4; color: #444; border: 1px solid #ccc; text-decoration: none; display: inline-block; }
.uns-hint { font-size: 12px; color: #888; margin-top: 10px; }
.uns-warning { background: #fdecea; border: 1px solid #c0392b; border-left-width: 4px; border-radius: 0 6px 6px 0; padding: 12px 14px; margin: 16px 0; font-size: 13px; color: #8a1a1a; line-height: 1.6; }
.uns-path { font-family: monospace; font-size: 12px; background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 4px; padding: 6px 10px; display: inline-block; word-break: break-all; }
</style>

<?php
/* =======================================================================
 * Rendering
 *
 * Produced in PHP rather than woven out of template tags: ianseo asks that a
 * file be in one language at a time, and this screen has three mutually
 * exclusive states — done, no module chosen, confirmation — which are far
 * easier to read as three branches than as a chain of "elseif" spread across
 * a hundred lines of markup.
 *
 * Several of the strings below carry deliberate markup from the language files
 * (a bold module name, a link), so those are echoed as they are and only the
 * values substituted into them are escaped.
 * =====================================================================*/

$backHome = '<p style="margin-top:20px"><a href="' . $CFG->ROOT_DIR . 'index.php">← '
          . upd_esc(upd_text('BackHome')) . '</a></p>';

$page = '<h1>' . upd_esc(upd_text('UninstallTitle')) . '</h1>';

foreach ($messages as [$type, $text]) {
    $page .= '<div class="uns-msg ' . ($type === 'ok' ? 'uns-msg-ok' : 'uns-msg-err') . '">'
           . upd_esc($text) . '</div>';
}

if ($done) {

    $page .= '<div class="uns-section"><div class="uns-msg uns-msg-ok">'
           . upd_text('UninstallDone', '<b>' . upd_esc($name) . '</b>') . '</div>';

    if ($downloadToken) {
        $page .= '<p style="font-size:13px">' . upd_text('BackupPrepared') . '</p>'
               . '<p style="margin:10px 0">'
               . '<a class="uns-btn" style="background:#0254a8;color:#fff;text-decoration:none;display:inline-block"'
               . ' href="?download=' . urlencode($downloadToken) . '">&#11015; '
               . upd_esc(upd_text('DownloadBackup')) . '</a></p>'
               . '<p class="uns-hint" style="margin-top:0">' . upd_esc(upd_text('BackupPurgeNote')) . '</p>';
    } else {
        $page .= '<p style="font-size:13px">' . upd_esc(upd_text('NoBackupKept')) . '</p>';
    }

    if ($droppedTables) {
        $page .= '<p style="font-size:13px;margin-top:14px">'
               . upd_text('TablesDropped', '<b>' . upd_esc(implode(', ', $droppedTables)) . '</b>') . '</p>';
    } elseif ($tables) {
        $page .= '<p style="font-size:13px;margin-top:14px">'
               . upd_text('TablesKept', '<b>' . upd_esc(implode(', ', $tables)) . '</b>') . '</p>';
    }

    $page .= $backHome . '</div>';

} elseif (!$dir) {

    // No module named, or a name that did not survive the safeguards: offer the
    // list of what is actually installed rather than an error.
    $page .= '<div class="uns-section">';
    if ($modules) {
        $page .= '<p style="font-size:13px">' . upd_esc(upd_text('ChooseModule')) . '</p><ul class="uns-list">';
        foreach ($modules as $m) {
            $page .= '<li><a href="?module=' . urlencode($m) . '">' . upd_esc($m) . '</a></li>';
        }
        $page .= '</ul><p class="uns-hint">' . upd_text('OnlyModuleJson') . '</p>';
    } else {
        $page .= '<p style="font-size:13px">' . upd_esc(upd_text('NoModuleInstalled')) . '</p>';
    }
    $page .= $backHome . '</div>';

} else {

    $page .= '<div class="uns-section"><div class="uns-box">'
           . '<h2>&#9888; ' . upd_esc(upd_text('UninstallHeading', $name)) . '</h2>'
           . '<p style="font-size:13px;margin:0 0 10px">' . upd_esc(upd_text('ThisWillDo')) . '</p>'
           . '<ul class="uns-list">';

    if ($wantBackup) $page .= '<li>' . upd_text('WillPrepareBackup') . '</li>';

    $page .= '<li>' . upd_text('WillDeleteFolder', 'Modules/Custom/' . upd_esc($name) . '/')
           . ($wantBackup ? '' : upd_text('FilesRecoverable')) . '.</li>'
           . '</ul>'
           . '<p class="uns-hint" style="margin-top:-6px">' . upd_text('SharedNeverRemoved') . '</p>';

    if ($warning) {
        $page .= '<div class="uns-warning"><b>&#9888; ' . upd_esc(upd_text('ModuleWarningTitle')) . '</b>'
               . '<div style="margin-top:6px">' . nl2br(upd_esc($warning)) . '</div></div>';
    }

    $page .= '<form method="post">'
           . '<input type="hidden" name="action" value="uninstall">'
           . '<input type="hidden" name="module" value="' . upd_esc($name) . '">'
           . '<input type="hidden" name="token" value="' . upd_esc($token) . '">';

    // Dropping the tables is offered only when the module declared any, and the
    // box is unchecked: keeping the data is the safe default.
    if ($tables) {
        $codes = [];
        foreach ($tables as $t) $codes[] = '<code>' . upd_esc($t) . '</code>';
        $page .= '<div class="uns-danger"><label style="cursor:pointer">'
               . '<input type="checkbox" name="drop_tables" value="1"> '
               . upd_text('DropTablesLabel') . ' ' . implode(', ', $codes) . '</label>'
               . '<div style="margin-top:6px;color:#8a5a00">' . upd_text('DropTablesNote') . '</div></div>';
    }

    $page .= '<div class="uns-confirm">'
           . upd_text('ConfirmTypeName', '<b>' . upd_esc($name) . '</b>') . '<br>'
           . '<input type="text" name="confirm" autocomplete="off" required placeholder="'
           . upd_esc($name) . '" style="margin-top:6px"></div>'
           . '<p style="margin-top:16px">'
           . '<button type="submit" class="uns-btn uns-btn-danger" onclick="return confirm('
           . upd_esc(json_encode(upd_text('FinalConfirm', $name))) . ')">&#128465; '
           . upd_esc(upd_text('UninstallForever')) . '</button>'
           . '<a class="uns-btn uns-btn-cancel" style="margin-left:8px" href="'
           . $CFG->ROOT_DIR . 'index.php">' . upd_esc(upd_text('Cancel')) . '</a>'
           . '</p></form></div></div>';
}

echo $page;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
