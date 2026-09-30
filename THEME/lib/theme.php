<?php
/**
 * What the theme puts on a page, and how it gets there.
 *
 * Loaded by menu.php on every page that prints the menu, and by hook.php on
 * every request when an administrator has enabled the pages without a menu. So
 * this file defines functions only, reads no table, writes nothing, and never
 * outputs anything by itself.
 *
 * THE CHOICE. Two independent settings: the mode (auto, light, dark) and the
 * palette (see palettes.php). Both live in one cookie, so each browser keeps its
 * own. The cookie is named after the installation (host, port and root folder),
 * because browsers do not tell ports apart: two installations reached as
 * localhost and localhost:8080 would otherwise share one choice, and the point
 * of the palettes is precisely to tell installations apart.
 *
 * WHAT GOES ON THE PAGE, in this order, after the core's stylesheets:
 *   1. the palette's light variables (none for ianseo's own blue);
 *   2. css/theme.css: the few colours the core writes as fixed values where a
 *      variable was meant, so that they follow the palette;
 *   3. css/dark.css and the palette's dark variables, with media="screen" in
 *      dark mode, or only while the computer is set to dark in automatic mode.
 * Every piece is limited to the screen: printouts never change. Light mode with
 * ianseo's blue adds nothing at all.
 */

require_once __DIR__ . '/palettes.php';

/**
 * The modes, in menu order.
 *
 * @return string[]
 */
function thm_modes() {
    return ['auto', 'light', 'dark'];
}

/**
 * Name of the cookie holding the choice for this installation.
 *
 * @return string Such as "ianseo_theme_3f2a9c1d".
 */
function thm_cookie_name() {
    global $CFG;
    $where = (string)($_SERVER['HTTP_HOST'] ?? '') . '|' . (string)($CFG->ROOT_DIR ?? '/');
    return 'ianseo_theme_' . hash('crc32b', $where);
}

/**
 * The current choice, validated, with the defaults for anything missing.
 *
 * @return array ['mode' => string, 'palette' => string]
 */
function thm_choice() {
    $choice = ['mode' => 'auto', 'palette' => 'ianseo'];
    $raw = (string)($_COOKIE[thm_cookie_name()] ?? '');
    $parts = explode('.', $raw, 2);
    if (in_array($parts[0], thm_modes(), true)) $choice['mode'] = $parts[0];
    if (isset($parts[1]) && in_array($parts[1], thm_palette_ids(), true)) $choice['palette'] = $parts[1];
    return $choice;
}

/**
 * Store a choice in the cookie, for about a year (browsers cap cookies at 400 days).
 *
 * @param array $choice ['mode' => string, 'palette' => string], already validated.
 */
function thm_store($choice) {
    global $CFG;
    $value = $choice['mode'] . '.' . $choice['palette'];
    setcookie(thm_cookie_name(), $value, [
        'expires'  => time() + 400 * 86400,
        'path'     => (string)($CFG->ROOT_DIR ?? '/'),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[thm_cookie_name()] = $value;
}

/**
 * Web URL of the module folder, with a trailing slash.
 *
 * @return string
 */
function thm_url() {
    global $CFG;
    return (string)($CFG->ROOT_DIR ?? '/') . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/';
}

/**
 * Cache-busting URL of a file of the module.
 *
 * @param string $rel Path relative to the module folder.
 * @return string
 */
function thm_asset($rel) {
    $t = @filemtime(dirname(__DIR__) . '/' . $rel);
    return thm_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * A palette's variables as a :root rule.
 *
 * @param array $vars Variable name (without the dashes) => colour.
 * @return string
 */
function thm_vars_css($vars) {
    $css = '';
    foreach ($vars as $name => $colour) $css .= '--' . $name . ':' . $colour . ';';
    return ':root{' . $css . '}';
}

/**
 * The elements to add to the page for a choice.
 *
 * @param array $choice As returned by thm_choice().
 * @param bool $debug Whether the core's debug mode is on.
 * @return string HTML, or '' when the page stays as the core draws it.
 */
function thm_markup($choice, $debug) {
    $palettes = thm_palettes();
    $light = ($debug || $choice['palette'] === 'ianseo') ? [] : $palettes[$choice['palette']]['light'];
    $darkMedia = ['auto' => 'screen and (prefers-color-scheme: dark)', 'dark' => 'screen', 'light' => ''][$choice['mode']];

    if (!$light && $darkMedia === '') return '';

    $html = '';
    if ($light) $html .= '<style media="screen">' . thm_vars_css($light) . '</style>';
    $html .= '<link href="' . thm_asset('css/theme.css') . '" media="screen" rel="stylesheet" type="text/css">';
    if ($darkMedia !== '') {
        $dark = $palettes[$debug ? 'debug' : $choice['palette']]['dark'];
        $html .= '<link href="' . thm_asset('css/dark.css') . '" media="' . $darkMedia . '" rel="stylesheet" type="text/css">';
        $html .= '<style media="' . $darkMedia . '">' . thm_vars_css($dark) . '</style>';
    }
    return $html;
}

/**
 * The elements for the current request.
 *
 * @return string
 */
function thm_page_markup() {
    return thm_markup(thm_choice(), !empty($_SESSION['debug']));
}

/**
 * Start filtering the response, for hook.php.
 *
 * Nothing is started when the choice adds nothing to a page, so a browser that
 * keeps ianseo's light blue costs the server nothing at all.
 */
function thm_hook_start() {
    $choice = thm_choice();
    if ($choice['mode'] === 'light' && $choice['palette'] === 'ianseo') return;
    $GLOBALS['_thm_hooked'] = true;
    // A chunk size keeps the page streaming: once the head has gone by, every
    // later chunk is handed on untouched.
    ob_start('thm_ob_filter', 4096);
}

/**
 * Is the theme's output filter still in place for this request?
 *
 * A page may discard every output buffer before it prints anything, taking the
 * filter away with them; menu.php then adds the theme itself.
 *
 * @return bool
 */
function thm_hook_active() {
    return !empty($GLOBALS['_thm_hooked']) && in_array('thm_ob_filter', ob_list_handlers(), true);
}

/**
 * Could the response being sent be an HTML page?
 *
 * @return bool False for anything declared as another type, for downloads, and
 *              for a response whose length is already announced.
 */
function thm_response_is_html() {
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) return false;
        if (stripos($header, 'content-disposition:') === 0) return false;
        if (stripos($header, 'content-length:') === 0) return false;
    }
    return true;
}

/**
 * Output filter: adds the theme at the end of the page's head.
 *
 * Only pages that link the core's colour variables (Common/Styles/colors.css)
 * are touched, since those are the pages the theme knows how to recolour. TV
 * screens, the scoring apps, JSON, PDF and every other kind of response pass
 * through unchanged. The search stops at the first "</head>" or "<body" (some of
 * the core's templates never close their head), and gives up after 64 KB.
 *
 * @param string $buffer
 * @param int $phase PHP_OUTPUT_HANDLER_* flags.
 * @return string
 */
function thm_ob_filter($buffer, $phase) {
    static $done = false, $styled = false, $seen = 0;

    // A chunk being discarded by ob_clean() is not the page: leave the state as it is.
    if ($done || ($phase & PHP_OUTPUT_HANDLER_CLEAN) || $buffer === '') return $buffer;

    try {
        if (!thm_response_is_html()) {
            $done = true;
            return $buffer;
        }

        $end = stripos($buffer, '</head>');
        if ($end === false) $end = stripos($buffer, '<body');

        $colours = stripos($buffer, 'Common/Styles/colors.css');
        if ($colours !== false && ($end === false || $colours < $end)) $styled = true;

        if ($end === false) {
            // bytes: only a budget for how far into the response to look.
            $seen += strlen($buffer);
            if ($seen > 65536) $done = true;
            return $buffer;
        }

        $done = true;
        if (!$styled) return $buffer;

        $markup = thm_page_markup();
        if ($markup === '') return $buffer;
        // bytes: $end is a byte offset returned by stripos().
        return substr($buffer, 0, $end) . $markup . substr($buffer, $end);
    } catch (\Throwable $e) {
        $done = true;
        return $buffer;
    }
}
