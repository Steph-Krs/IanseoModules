<?php
/**
 * lib/lang.php — texts of the online-registration space, in the visitor's language.
 *
 * bk_t('Key') reads AUTH/languages/booking/<code>.php through the module's loader
 * (AUTH/lang-lib.php: the core's format and fallback, English underneath). Every library of
 * booking loads schema.php, which loads this file: bk_t() is always there.
 */

if (defined('BK_LANG_LOADED')) return;
define('BK_LANG_LOADED', true);

require_once dirname(__DIR__, 2) . '/lang-lib.php';

/** Translated text of the archer space (section "booking"). See aut_text() for $a. */
function bk_t($key, $a = null)
{
    return aut_text($key, $a, 'booking');
}

/** Several texts at once, for a page's script (json_encode). */
function bk_ts($keys)
{
    return aut_texts($keys, 'booking');
}

/**
 * Competition whose currency the amounts of the page are written in, when a caller gives
 * none: set once by a page about one competition (bk_money_tour($tourId)), read otherwise.
 */
function bk_money_tour($tourId = null)
{
    static $current = 0;
    if ($tourId !== null) $current = intval($tourId);
    return $current;
}

/**
 * Currency of a competition, as the organiser set it in ianseo (Tournament.ToCurrency); '€'
 * when not set, as the core's PDFs do (Common/OrisFunctions.php).
 */
function bk_currency($tourId)
{
    static $cache = array();
    $tourId = intval($tourId);
    if ($tourId <= 0) return '€';
    if (!isset($cache[$tourId])) {
        $r = safe_fetch(safe_r_sql("SELECT ToCurrency FROM Tournament WHERE ToId = $tourId"));
        $cache[$tourId] = ($r && trim((string) $r->ToCurrency) !== '') ? trim((string) $r->ToCurrency) : '€';
    }
    return $cache[$tourId];
}

/**
 * An amount with the competition's currency, written as the core writes it (Accreditation,
 * bills): NumFormat() — the separators of the visitor's language, from the core's language
 * files — then a space and the currency ("12,50 €" in French, "12.50 €" in English).
 * $signed: an explicit + for a positive adjustment; a negative amount shows a minus sign (−).
 * $tourId: the competition; by default the one set by bk_money_tour().
 */
function bk_eur($n, $signed = false, $tourId = null)
{
    $n = (float) $n;
    $sign = $n < 0 ? '−' : ($signed ? '+' : '');
    if (!function_exists('NumFormat')) require_once dirname(__DIR__, 5) . '/Common/Fun_Number.inc.php';
    return $sign . NumFormat(abs($n), 2) . ' ' . bk_currency($tourId === null ? bk_money_tour() : $tourId);
}

/** Decimal and thousands separators of the visitor's language (core files), for a page's script. */
function bk_number_seps()
{
    return array('dec' => get_text('NumberDecimalSeparator'), 'thousands' => get_text('NumberThousandsSeparator'));
}
