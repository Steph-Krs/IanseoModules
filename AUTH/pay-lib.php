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
 * A provider is registered in aut_pay_providers(). Only 'manual' exists. An online provider,
 * when one is added, implements these operations (functions named aut_pay_<code>_<operation>):
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
 * Until then aut_pay_online_available() is false everywhere and no page offers to pay online.
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
 * a person collecting the money; it is always there.
 */
function aut_pay_providers()
{
    return array(
        'manual' => array('online' => false, 'enabled' => true),
    );
}

/** Is $code a registered provider? (journal lines keep it in BlgProvider) */
function aut_pay_provider_ok($code)
{
    return isset(aut_pay_providers()[(string) $code]);
}

/**
 * Can the payers of competition $tourId pay online? No provider does it yet: always false.
 * Later: an online provider enabled on the server, the competition's organising structure
 * connected to it, and the organiser's consent for this competition.
 */
function aut_pay_online_available($tourId)
{
    return false;
}
