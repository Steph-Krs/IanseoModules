<?php
/**
 * The block of Common/DebugOverrides.php that loads hook.php on every request.
 *
 * That file belongs to the installation, not to ianseo: the core includes it
 * from config.php when it exists, and its updater never replaces it. It does not
 * exist in a fresh installation. The block is written between two markers so it
 * can be found, and removed, without touching anything else the file may hold:
 *   - no file: it is created with the block alone;
 *   - a file without the block: the block is put in front of its content, as a
 *     PHP section of its own, whatever the rest of the file looks like;
 *   - removing the block leaves the rest as it was, and deletes a file that
 *     held nothing else.
 *
 * The block is inert without the module (it checks that hook.php exists) and
 * cannot break a page even if hook.php could not be compiled: from PHP 7 on, a
 * parse error in an included file is an exception, and the block catches it.
 *
 * Only the module's page calls these functions, for an administrator.
 */

define('THM_OVR_BEGIN', '// === THEME BEGIN');
define('THM_OVR_END', '// === THEME END ===');

/**
 * Path of the core's overrides file.
 *
 * @return string
 */
function thm_ovr_file() {
    global $CFG;
    return $CFG->DOCUMENT_PATH . 'Common/DebugOverrides.php';
}

/**
 * The block, as written in the file.
 *
 * The path to hook.php is relative to Common/, so the block keeps working if
 * the installation is moved to another folder.
 *
 * @return string
 */
function thm_ovr_block() {
    $hook = "__DIR__ . '/../Modules/Custom/" . basename(dirname(__DIR__)) . "/hook.php'";
    return "<?php\n"
        . THM_OVR_BEGIN . " - colour theme of the pages without a menu. Managed from the page of the module\n"
        . "// Modules/Custom/" . basename(dirname(__DIR__)) . "/, does nothing once that module is removed.\n"
        . "try { if (is_file(" . $hook . ")) include_once " . $hook . "; } catch (\\Throwable \$thmError) {}\n"
        . THM_OVR_END . "\n"
        . "?>\n";
}

/**
 * Current state of the block.
 *
 * @return array ['on' => bool, 'writable' => bool, 'file' => string]
 */
function thm_ovr_state() {
    $file = thm_ovr_file();
    $exists = is_file($file);
    $content = $exists ? (string)@file_get_contents($file) : '';
    return [
        'on'       => strpos($content, THM_OVR_BEGIN) !== false,
        'writable' => $exists ? is_writable($file) : is_writable(dirname($file)),
        'file'     => $file,
    ];
}

/**
 * Add the block, unless it is already there.
 *
 * @return bool True when the block is in the file afterwards.
 */
function thm_ovr_enable() {
    $state = thm_ovr_state();
    if ($state['on']) return true;
    if (!$state['writable']) return false;

    $file = $state['file'];
    $content = is_file($file) ? (string)file_get_contents($file) : '';
    // The block's closing tag swallows the newline after it, so what follows
    // prints nothing more than it did before.
    return @file_put_contents($file, thm_ovr_block() . $content, LOCK_EX) !== false;
}

/**
 * Remove the block, and the file if it held nothing else.
 *
 * @return bool True when the block is gone afterwards.
 */
function thm_ovr_disable() {
    $state = thm_ovr_state();
    if (!$state['on']) return true;
    if (!$state['writable']) return false;

    $file = $state['file'];
    $content = (string)file_get_contents($file);
    $pattern = '/<\?php\s*' . preg_quote(THM_OVR_BEGIN, '/') . '.*?' . preg_quote(THM_OVR_END, '/') . '\s*\?>\n?/s';
    $rest = preg_replace($pattern, '', $content, 1);
    if ($rest === null || strpos($rest, THM_OVR_BEGIN) !== false) return false;

    if (trim($rest) === '') return @unlink($file);
    return @file_put_contents($file, $rest, LOCK_EX) !== false;
}
