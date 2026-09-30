<?php
/**
 * Translations of the theme module, resolved the way the core's get_text() is.
 *
 * The core loads Common/Languages/en/<Module>.php, then merges the user's language
 * over it, so a key missing from a translation falls back to English instead of
 * disappearing. This does the same with the module's own languages/ folder: a
 * custom module cannot put files in Common/Languages/ without the next ianseo
 * update erasing them. The file format is the core's, one $lang array per file.
 *
 * Loaded by menu.php on every ianseo page, so it defines functions only and
 * touches neither the database nor the disk beyond reading its own files.
 */

/**
 * Language code currently selected in ianseo, lower-case.
 *
 * @return string Such as 'fr' or 'pt-br'.
 */
function thm_lang_code() {
    if (!function_exists('SelectLanguage')) return 'en';
    // bytes: a language code is ASCII by definition, nothing to fold.
    $code = strtolower((string)SelectLanguage());
    return $code !== '' ? $code : 'en';
}

/**
 * The whole string table for the current language, English underneath.
 *
 * @return array Key to translated string.
 */
function thm_lang_all() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $dir  = dirname(__DIR__) . '/languages/';
    $lang = [];
    if (is_file($dir . 'en.php')) include $dir . 'en.php';
    $strings = is_array($lang) ? $lang : [];

    $code = thm_lang_code();
    // Regional codes (fr-ca, pt-br) try the exact code, then the base language.
    foreach (array_unique([$code, mb_substr($code, 0, 2)]) as $try) {
        if ($try === 'en' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $try)) continue;
        if (!is_file($dir . $try . '.php')) continue;
        $lang = [];
        include $dir . $try . '.php';
        if (is_array($lang)) $strings = array_merge($strings, $lang);
        break;
    }
    return $cache = $strings;
}

/**
 * Translate a key.
 *
 * @param string $key Key defined in languages/en.php.
 * @param mixed $a Value substituted for {$a}.
 * @return string The translated text, or the core's marker for an unknown key.
 */
function thm_text($key, $a = null) {
    $strings = thm_lang_all();
    if (!isset($strings[$key])) {
        return '[[' . $key . ']@[' . thm_lang_code() . ']@[Theme]]';
    }
    return is_null($a) ? $strings[$key] : str_replace('{$a}', (string)$a, $strings[$key]);
}

/**
 * Escape a value for HTML text or a double-quoted attribute.
 *
 * @param mixed $s
 * @return string
 */
function thm_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Translated text, escaped for HTML.
 *
 * @param string $key
 * @param mixed $a
 * @return string
 */
function thm_t($key, $a = null) {
    return thm_esc(thm_text($key, $a));
}
