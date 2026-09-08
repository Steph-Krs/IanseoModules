<?php
/**
 * Shared path resolution for ianseo custom modules.
 *
 * WHY THIS FILE EXISTS
 * Modules used to locate the ianseo document root with a hard-coded depth:
 *
 *     define('HTDOCS', dirname(__DIR__, 4));   // Modules/Custom/<MOD>/admin/
 *
 * That "4" encodes where the module happens to sit today. Moving the module one
 * level up (for instance out of Modules/Custom/ and into Modules/) changes the
 * depth for every file at once, and the failure is silent: dirname() simply
 * returns a shorter path, config.php is not found, and the page dies with an
 * include error that points nowhere near the real cause.
 *
 * The helpers below discover the root by walking up the tree instead of
 * counting levels, so a module keeps working wherever it is installed.
 *
 * BOOTSTRAP CHICKEN-AND-EGG
 * A module cannot require this file before knowing where it is. The two-line
 * snippet below has no dependency and belongs at the top of each entry point
 * (page, AJAX endpoint, CLI script):
 *
 *     $d = __DIR__;
 *     while ($d !== dirname($d) && !is_file($d . '/config.php')) $d = dirname($d);
 *     define('HTDOCS', $d);
 *     require_once HTDOCS . '/config.php';
 *
 * Modules that already have a single bootstrap file (lib/boot.php,
 * lib/<mod>-lib.inc.php) put it there once; every other file of the module
 * reaches that bootstrap through a path relative to the module itself, which
 * never changes when the module moves.
 *
 * Once config.php is loaded, the functions here build filesystem paths and URLs
 * without any module ever spelling out "Modules/Custom/<MOD>/" again.
 */

/**
 * ianseo document root (the directory holding config.php), or null.
 *
 * The Common/ check matters: a module may ship its own config.php, and stopping
 * at the first one found would silently pick the wrong root.
 *
 * @param string|null $start File or directory to search upwards from.
 * @return string|null Absolute path without trailing slash.
 */
function cmod_root($start = null) {
    static $cache = [];

    $dir = $start === null ? __DIR__ : $start;
    if (isset($cache[$dir])) return $cache[$dir];

    $cur = is_dir($dir) ? $dir : dirname($dir);
    // 12 levels is far beyond any realistic install depth and stops runaway loops
    // on exotic filesystems where dirname() never reaches a fixed point.
    for ($i = 0; $i < 12; $i++) {
        if (is_file($cur . '/config.php') && is_dir($cur . '/Common')) {
            return $cache[$dir] = $cur;
        }
        $parent = dirname($cur);
        if ($parent === $cur) break;   // reached the filesystem root
        $cur = $parent;
    }
    return $cache[$dir] = null;
}

/**
 * Root directory of the module containing $start, identified by its module.json.
 *
 * module.json is the marker that distinguishes a managed module from any other
 * folder, and it is the same marker the updater and uninstaller rely on.
 *
 * @param string $start File or directory inside the module.
 * @return string|null Absolute path without trailing slash.
 */
function cmod_dir($start) {
    $cur  = is_dir($start) ? $start : dirname($start);
    $root = cmod_root($start);

    for ($i = 0; $i < 12; $i++) {
        if (is_file($cur . '/module.json')) return $cur;
        $parent = dirname($cur);
        // Never walk past the ianseo root: without this an installation whose
        // root itself carries a module.json would hand back the whole document
        // tree as if it were a module.
        if ($parent === $cur || ($root !== null && $cur === $root)) break;
        $cur = $parent;
    }
    return null;
}

/**
 * Web URL of a directory inside the ianseo tree, with a trailing slash.
 *
 * Derived from the filesystem position, so it follows the module when it moves
 * and honours whatever ROOT_DIR the installation is served under.
 *
 * @param string $dir Absolute path inside the ianseo document root.
 * @return string URL such as "/Modules/Custom/GUIDE/", or "" if outside the root.
 */
function cmod_url($dir) {
    global $CFG;

    $root = cmod_root($dir);
    if ($root === null) return '';

    $real = realpath(is_dir($dir) ? $dir : dirname($dir));
    $rroot = realpath($root);
    if ($real === false || $rroot === false) return '';

    // Compare with the separator appended so that a sibling directory sharing a
    // name prefix (…/htdocs2) is not mistaken for a child of …/htdocs.
    // bytes throughout here: these are filesystem paths compared and cut at
    // offsets strncmp and strlen produce, and those count bytes. An mb_ length
    // fed to a byte comparison is what would break it.
    if (strncmp($real . DIRECTORY_SEPARATOR, $rroot . DIRECTORY_SEPARATOR, strlen($rroot) + 1) !== 0) {
        return '';
    }

    // bytes: cutting the path at the byte offset strlen just produced.
    $rel  = str_replace('\\', '/', substr($real, strlen($rroot)));
    $base = isset($CFG->ROOT_DIR) ? rtrim($CFG->ROOT_DIR, '/') : '';
    return $base . rtrim($rel, '/') . '/';
}

/**
 * Web URL of a file inside the ianseo tree.
 *
 * @param string $file Absolute path to a file.
 * @return string URL, or "" if the file is outside the root.
 */
function cmod_file_url($file) {
    $dirUrl = cmod_url(dirname($file));
    return $dirUrl === '' ? '' : $dirUrl . basename($file);
}

/**
 * Cache-busting suffix for a static asset, based on its modification time.
 *
 * Browsers hold on to a stale guide.js or style.css long after a module update,
 * producing symptoms that look like server-side bugs (buttons that seem to need
 * two clicks, features that appear missing). Returns "" when the file is gone
 * so a caller can still build a usable URL.
 *
 * @param string $file Absolute path to the asset.
 * @return string Such as "?v=1716903254" — ready to append to the URL.
 */
function cmod_asset_version($file) {
    $t = @filemtime($file);
    return $t ? '?v=' . $t : '';
}
