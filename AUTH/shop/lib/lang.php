<?php
/**
 * lib/lang.php — texts of the points of sale, in the visitor's language.
 *
 * shp_t('Key') reads AUTH/languages/shop/<code>.php through the module's loader
 * (AUTH/lang-lib.php: the core's format and fallback, English underneath). Functions only, no
 * query: menu.php loads this file on every page.
 */

if (defined('SHP_LANG_LOADED')) return;
define('SHP_LANG_LOADED', true);

require_once dirname(__DIR__, 2) . '/lang-lib.php';

/** Translated text of the points of sale (section "shop"). See aut_text() for $a. */
function shp_t($key, $a = null)
{
    return aut_text($key, $a, 'shop');
}

/** Several texts at once, for a page's script (json_encode). */
function shp_ts($keys)
{
    return aut_texts($keys, 'shop');
}
