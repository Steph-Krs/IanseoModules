<?php
/**
 * AUTH module — translations, in the format of the ianseo core.
 *
 * The core resolves get_text($key, $module) against Common/Languages/<code>/<Module>.php: it
 * loads English first, then merges the user's language over it, so a key missing from a
 * translation falls back to English instead of disappearing. A custom module cannot install
 * files into Common/Languages/ (the next ianseo update would erase them), so this reads the
 * module's own languages/ folder, in the identical format:
 *   languages/<code>.php            AUTH's own strings,
 *   languages/<section>/<code>.php  one section (booking: the archer space; …),
 * the same split as the core's Common.php, Tournament.php, Errors.php: a page loads only the
 * section it needs.
 *
 * The language is the one ianseo chose for this visitor (SelectLanguage: ?Lang=, the
 * UseLanguage cookie, then the browser), so the archer space follows the same rule as the
 * rest of ianseo, with no setting of its own.
 */

if (defined('AUT_LANG_LOADED')) return;
define('AUT_LANG_LOADED', true);

/**
 * Language code selected by ianseo, lower-case ('fr', 'en', 'pt-br'). English when the core
 * helper is not loaded (command line without config.php).
 */
function aut_lang_code()
{
    if (!function_exists('SelectLanguage')) return 'en';
    // bytes: a language code is ASCII by definition, nothing for mb_strtolower to fold differently.
    $code = strtolower((string) SelectLanguage());
    return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) ? $code : 'en';
}

/**
 * String table of a section for the current language, English underneath. A regional code
 * (fr-ca) tries the exact code, then the base language.
 *
 * @param string $section '' for AUTH's own strings, or a subfolder of languages/.
 * @return array Key => text.
 */
function aut_lang_all($section = '')
{
    static $cache = array();
    $section = preg_match('/^[a-z0-9_-]*$/', (string) $section) ? (string) $section : '';
    $code = aut_lang_code();
    if (isset($cache[$section][$code])) return $cache[$section][$code];

    $dir = __DIR__ . '/languages/' . ($section !== '' ? $section . '/' : '');
    $lang = array();
    if (is_file($dir . 'en.php')) include $dir . 'en.php';
    $strings = is_array($lang) ? $lang : array();
    foreach (array_unique(array($code, mb_substr($code, 0, 2))) as $try) {
        if ($try === 'en' || !is_file($dir . $try . '.php')) continue;
        $lang = array();
        include $dir . $try . '.php';
        if (is_array($lang)) $strings = array_merge($strings, $lang);
        break;
    }
    return $cache[$section][$code] = $strings;
}

/**
 * Translated text of a key, as the core's get_text(): {$a} is replaced by $a, or {$a[name]}
 * by $a['name'] when $a is an array. An unknown key gives the core's visible marker
 * [[Key]@[lang]@[Module]], loud in development and searchable.
 *
 * Texts may hold simple markup (<b>…</b>): they come from the language files, never from a
 * visitor. A caller putting one into an attribute or a plain-text place escapes it.
 */
function aut_text($key, $a = null, $section = '')
{
    $strings = aut_lang_all($section);
    if (!isset($strings[$key])) {
        return '[[' . $key . ']@[' . aut_lang_code() . ']@[AUTH' . ($section !== '' ? '/' . $section : '') . ']]';
    }
    $text = $strings[$key];
    if ($a === null) return $text;
    if (is_array($a)) {
        foreach ($a as $k => $v) $text = str_replace('{$a[' . $k . ']}', (string) $v, $text);
        return $text;
    }
    return str_replace('{$a}', (string) $a, $text);
}

/** Translated text of AUTH's own strings (languages/<code>.php). See aut_text() for $a. */
function aut_t($key, $a = null)
{
    return aut_text($key, $a, '');
}

/** Several keys of a section at once, for a page's script: [key => text], ready for json_encode. */
function aut_texts($keys, $section = '')
{
    $out = array();
    foreach ($keys as $k) $out[$k] = aut_text($k, null, $section);
    return $out;
}
