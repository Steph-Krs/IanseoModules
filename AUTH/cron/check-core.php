<?php
/**
 * AUTH module — is there an update of the ianseo core to install? (command line only)
 *
 * Asks ianseo.net exactly what Update/UpdateIanseo.php asks (the list of the core files with
 * their size and MD5), but installs nothing: it reads the first bytes of the answer and closes.
 * The nightly maintenance (cron/maintenance.php) closes the site only when something is to be
 * installed; without this question, it had to close it every night just to learn that the core
 * was up to date.
 *
 * Exit code: 0 = up to date, 10 = an update is available, 1 = could not tell (ianseo.net out of
 * reach, or a build the update refuses to touch without an ianseo.net account — reported as a
 * failure every night, as the update itself did).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');

function cc_out($msg) { echo $msg . "\n"; }

// Same rule as UpdateIanseo.php: only these builds update without an ianseo.net account.
if (!isProduction(array('STABLE', 'FITARCO'))) {
    cc_out('Core: this build (' . (defined('ProgramRelease') ? ProgramRelease : '?') . ') cannot be updated without an ianseo.net account.');
    exit(1);
}

// FileList.php is the core's own class, included the way UpdateIanseo.php does it.
chdir(HTDOCS . DIRECTORY_SEPARATOR . 'Update');
require_once 'FileList.php';
$list = new FileList($CFG->INCLUDE_PATH);
$list->EscludeFiles('^(\.)');
$list->ShowSize(true);
$list->ShowMD5(true);
if (!$list->Load()) {
    cc_out('Core: the list of the core files could not be read.');
    exit(1);
}

$ctx = stream_context_create(array('http' => array(
    'method'  => 'POST',
    'header'  => 'Content-type: application/x-www-form-urlencoded',
    'content' => http_build_query(array('Json' => gzcompress($list->serialize())), '', '&'),
    'timeout' => 60,
)));
$stream = @fopen($CFG->IanseoServer . 'Update.php', 'r', false, $ctx);
if ($stream === false) {
    $e = error_get_last();
    cc_out('Core: ianseo.net could not be reached' . ($e ? ' — ' . $e['message'] : '') . '.');
    exit(1);
}
// The answer is either a short word ("NothingToDo", an error key) or the compressed package:
// its first bytes are enough to tell, the package itself is downloaded by the update.
$head = '';
while (strlen($head) < 64 && !feof($stream)) {
    $part = fread($stream, 64 - strlen($head));
    if ($part === false || $part === '') break;
    $head .= $part;
}
fclose($stream);

if (trim($head) === 'NothingToDo') {
    cc_out('Core: up to date.');
    exit(0);
}
// bytes: zlib header of the package (gzcompress), "x" followed by a check byte
if (strlen($head) >= 2 && $head[0] === "\x78") {
    cc_out('Core: an update is available.');
    exit(10);
}
cc_out('Core: unexpected answer from ianseo.net — ' . trim(strip_tags(get_text(trim($head), 'Install'))));
exit(1);
