<?php
/**
 * AUTH module — means of payment and payment providers, shared by the online registration
 * (booking) and the food & shop (shop).
 *
 * Today every payment is MANUAL: cash, cheque, the club's card terminal, transfer… collected
 * by a person and recorded in the payments journal (BookingLedger). Nothing about a card ever
 * reaches this server, and nothing ever will: an online provider keeps the card on its own
 * pages and only tells the server "payment X of Y is done" (BlgProvider, BlgRef, BlgStatus).
 *
 * Providers are listed by aut_pay_providers(). 'manual' is built in. Any other one lives in its
 * own file, pay-provider.php, at the root of a module of Custom/: the file returns
 * ['code' => …, 'online' => bool, 'enabled' => bool] and defines its operations (functions
 * named aut_pay_<code>_<operation>). For an online provider:
 *
 *   available($tourId)
 *       Can the payers of this competition pay with it?
 *
 *   link($tourId)
 *       HTML of the link that takes a payer to it, shown next to what they still owe.
 *
 *   offerable($tourId, $keys), info($tourId, $text) — optional
 *       The means of payment an organiser may offer, and the text shown with each.
 *
 *   session($tourId, $account, $amount, array $orders, $returnUrl, $idem)
 *       Opens a payment on the provider's hosted page for $amount on journal account $account
 *       (and, for the shop, the orders paid by it). Writes a journal line BlgStatus = 'pending',
 *       BlgProvider = <code>, BlgRef = the provider's id of the payment, BlgIdem = $idem.
 *       Returns ['error' => 0, 'url' => page to send the payer to (or to show as a QR code)].
 *
 *   webhook($rawBody, array $headers)
 *       Called by the provider's server on a public address. Verifies the signature FIRST (the
 *       secret lives in config.local.json, never in the code nor in the repository), ignores an
 *       event already handled (its id is the idempotency key), then: payment completed → the
 *       pending line becomes 'done' and the shop orders are recomputed; payment failed → the
 *       pending line becomes 'failed'; refund → a 'refund' line; dispute → a 'reject' line
 *       (an incident of the payer trust index). Answers 2xx once recorded, and only then.
 *
 *   refund($ledgerId, $amount, $by)
 *       Asks the provider to give back $amount of a 'done' line it collected; the 'refund'
 *       line is written when the provider confirms it (webhook), not before.
 *
 * Without such a file aut_pay_online_available() is false everywhere and no page offers to pay
 * online.
 */

if (defined('AUT_PAY_LOADED')) return;
define('AUT_PAY_LOADED', true);

require_once __DIR__ . '/lang-lib.php';

/**
 * Means of payment a person can collect, by context: 'booking' (payments page, registration
 * settings) or 'shop' (till of a stand). [code => label]. In the shop, 'online' (the
 * organiser's own online form, recorded by hand on the payments page) is not something a
 * volunteer collects, and 'tab' — "put it on my account", for a signed-in licensee — is
 * offered instead: it is not a payment, it moves the amount to the account, settled later.
 */
function aut_pay_methods($context = 'booking')
{
    $out = array(
        'cash' => aut_text('PayCash', null, 'booking'),
        'cheque' => aut_text('PayCheque', null, 'booking'),
        'cb' => aut_text('PayCard', null, 'booking'),
        'virement' => aut_text('PayTransfer', null, 'booking'),
        'online' => aut_text('PayOnline', null, 'booking'),
        'autre' => aut_text('PayOther', null, 'booking'),
    );
    if ($context === 'shop') {
        unset($out['online'], $out['virement']);
        $out['tab'] = aut_text('ShPayMethodTab', null, 'shop');
    }
    return $out;
}

/** Codes of the means that are real money collected (written to the journal). */
function aut_pay_ledger_method($code)
{
    return in_array((string) $code, array('cash', 'cheque', 'cb', 'virement', 'online', 'autre'), true);
}

/**
 * Registered payment providers: [code => ['online' => bool, 'enabled' => bool]]. 'manual' is
 * a person collecting the money; it is always there. The others are the pay-provider.php files
 * of the modules of Custom/, read once per request.
 */
function aut_pay_providers()
{
    static $out = null;
    if ($out !== null) return $out;
    $out = array('manual' => array('online' => false, 'enabled' => true));
    foreach ((array) glob(dirname(__DIR__) . '/*/pay-provider.php') as $file) {
        $p = include $file;
        if (is_array($p) && preg_match('/^[a-z]{1,12}$/', (string) ($p['code'] ?? '')) && $p['code'] !== 'manual') {
            $out[$p['code']] = array('online' => !empty($p['online']), 'enabled' => !empty($p['enabled']));
        }
    }
    return $out;
}

/** Is $code a registered provider? (journal lines keep it in BlgProvider) */
function aut_pay_provider_ok($code)
{
    return isset(aut_pay_providers()[(string) $code]);
}

/**
 * Can the payers of competition $tourId pay online? Yes when an online provider enabled on the
 * server says so: it is the provider that knows whether the organising structure is connected to
 * it and whether the organiser agreed for this competition.
 */
function aut_pay_online_available($tourId)
{
    foreach (aut_pay_providers() as $code => $p) {
        $fn = 'aut_pay_' . $code . '_available';
        if ($p['online'] && $p['enabled'] && function_exists($fn) && $fn(intval($tourId))) return true;
    }
    return false;
}

/**
 * Means of payment an organiser may offer on competition $tourId, among $keys: all of them,
 * unless an enabled provider narrows the list (operation offerable($tourId, $keys)).
 */
function aut_pay_offerable($tourId, array $keys)
{
    foreach (aut_pay_providers() as $code => $p) {
        $fn = 'aut_pay_' . $code . '_offerable';
        if ($p['enabled'] && function_exists($fn)) $keys = array_values(array_intersect($keys, (array) $fn(intval($tourId), $keys)));
    }
    return $keys;
}

/**
 * Text an organiser wrote about a means of payment, as shown on competition $tourId: unchanged,
 * unless an enabled provider rewrites it (operation info($tourId, $text)).
 */
function aut_pay_info_text($tourId, $text)
{
    foreach (aut_pay_providers() as $code => $p) {
        $fn = 'aut_pay_' . $code . '_info';
        if ($p['enabled'] && function_exists($fn)) $text = (string) $fn(intval($tourId), $text);
    }
    return $text;
}

/**
 * HTML of the link to pay online what is left on competition $tourId, given by the first online
 * provider that offers it; '' when none does.
 */
function aut_pay_online_link($tourId)
{
    foreach (aut_pay_providers() as $code => $p) {
        $fn = 'aut_pay_' . $code . '_link';
        if (!$p['online'] || !$p['enabled'] || !function_exists($fn)) continue;
        $html = (string) $fn(intval($tourId));
        if ($html !== '') return $html;
    }
    return '';
}
