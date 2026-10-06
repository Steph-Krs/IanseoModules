<?php
/**
 * lib/customer.php — who is ordering: a licensee signed in to the online registration, or a
 * guest known by a nickname and a cookie of the phone.
 *
 * A licensee may also order as a guest (the licensee-space password is not always at hand in
 * a sports hall): they only lose "put it on my account".
 *
 * The guest cookie is one per competition (shp_g<ToId>), so that a phone used at two
 * competitions keeps both. Its token is random; only its hash is stored.
 */

if (defined('SHP_CUSTOMER_LOADED')) return;
define('SHP_CUSTOMER_LOADED', true);

require_once __DIR__ . '/common.php';

function shp_guest_cookie_name($tourId)
{
    return 'shp_g' . intval($tourId);
}

/**
 * The customer of this request for a competition:
 *   ['kind' => 'ARCHER', 'licence', 'archer' (BaId), 'label'], or
 *   ['kind' => 'GUEST', 'guest' (SuId), 'label', 'blocked'], or null.
 * The licensee wins over a guest cookie of the same phone.
 */
function shp_customer($tourId)
{
    $tourId = intval($tourId);
    if ($tourId <= 0) return null;
    $a = function_exists('bk_current_archer') ? bk_current_archer() : null;
    if ($a) {
        return array('kind' => 'ARCHER', 'licence' => bk_clean_licence($a->BaLicence), 'archer' => intval($a->BaId),
            'label' => trim($a->BaName . ' ' . $a->BaFamilyName));
    }
    $hash = shp_token_hash(shp_cookie_get(shp_guest_cookie_name($tourId)));
    if ($hash === '') return null;
    shp_schema();
    $g = safe_fetch(safe_r_sql("SELECT SuId, SuPseudo, SuBlocked,
            (SuLastSeen IS NULL OR SuLastSeen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)) AS stale
        FROM ShopGuests WHERE SuTokenHash = '$hash' AND SuTournament = $tourId"));
    if (!$g || trim((string) $g->SuPseudo) === '') return null;   // erased after the competition
    if ($g->stale && !shp_impersonating()) {
        safe_w_sql("UPDATE ShopGuests SET SuLastSeen = UTC_TIMESTAMP() WHERE SuId = " . intval($g->SuId));
    }
    return array('kind' => 'GUEST', 'guest' => intval($g->SuId), 'label' => (string) $g->SuPseudo,
        'blocked' => intval($g->SuBlocked) === 1);
}

/** Cleans a nickname: no control characters, single spaces, 2 to 30 characters. '' if unusable. */
function shp_pseudo_clean($pseudo)
{
    $p = preg_replace('/[\p{C}]+/u', ' ', (string) $pseudo);
    $p = trim(preg_replace('/\s+/u', ' ', (string) $p));
    $p = mb_substr($p, 0, 30);
    return mb_strlen($p) >= 2 ? $p : '';
}

/**
 * Creates the guest of this phone for a competition (or renames it when the phone already has
 * one) and sets its cookie until the day after the competition.
 * Returns ['error' => 0, 'customer' => …] or ['error' => 1, 'code', 'msg'].
 */
function shp_guest_create($tourId, $pseudo)
{
    $tourId = intval($tourId);
    $s = shp_settings($tourId);
    if (!$s || intval($s->SgEnabled) !== 1) return array('error' => 1, 'code' => 'shop_off', 'msg' => shp_t('ShErrShopOff'));
    if (intval($s->SgGuests) !== 1) return array('error' => 1, 'code' => 'guests_off', 'msg' => shp_t('ShErrGuestsOff'));
    $p = shp_pseudo_clean($pseudo);
    if ($p === '') return array('error' => 1, 'code' => 'pseudo', 'msg' => shp_t('ShErrPseudo'));

    $cur = shp_customer($tourId);
    if ($cur && $cur['kind'] === 'GUEST') {
        if ($cur['blocked']) return array('error' => 1, 'code' => 'blocked', 'msg' => shp_t('ShErrBlocked'));
        safe_w_sql("UPDATE ShopGuests SET SuPseudo = " . StrSafe_DB($p) . " WHERE SuId = " . intval($cur['guest']));
        $cur['label'] = $p;
        return array('error' => 0, 'customer' => $cur);
    }

    list($token, $hash) = shp_token_new();
    safe_w_sql("INSERT INTO ShopGuests SET SuTournament = $tourId, SuTokenHash = '$hash',
        SuPseudo = " . StrSafe_DB($p) . ", SuCreated = UTC_TIMESTAMP(), SuLastSeen = UTC_TIMESTAMP()");
    $id = intval(safe_w_last_id());
    $w = shp_window($tourId);
    $until = ($w && $w['purge_from'] !== '') ? strtotime($w['purge_from'] . ' 23:59:59 UTC') : 0;
    if ($until < time()) $until = time() + 86400;
    shp_cookie_set(shp_guest_cookie_name($tourId), $token, $until);
    return array('error' => 0, 'customer' => array('kind' => 'GUEST', 'guest' => $id, 'label' => $p, 'blocked' => false));
}

/** Blocks or unblocks a guest (a stand manager, after fanciful orders). */
function shp_guest_block($tourId, $guestId, $on)
{
    safe_w_sql("UPDATE ShopGuests SET SuBlocked = " . ($on ? 1 : 0)
        . " WHERE SuId = " . intval($guestId) . " AND SuTournament = " . intval($tourId));
}

/**
 * Account of a customer in the payment journal (BookingLedger): the licence, 'G<id>' for a
 * guest, 'C' for a counter sale without a name (one shared account per competition).
 */
function shp_account_key(array $customer)
{
    $kind = (string) ($customer['kind'] ?? '');
    if ($kind === 'ARCHER' && !empty($customer['licence'])) return bk_clean_licence($customer['licence']);
    if ($kind === 'GUEST' && intval($customer['guest'] ?? 0) > 0) return 'G' . intval($customer['guest']);
    return 'C';
}

/** Account of an order (same rule, from its header row or array). */
function shp_order_account($order)
{
    $o = (array) $order;
    $kind = (string) ($o['ShCustKind'] ?? $o['cust_kind'] ?? '');
    return shp_account_key(array(
        'kind' => $kind,
        'licence' => (string) ($o['ShLicence'] ?? $o['licence'] ?? ''),
        'guest' => intval($o['ShGuest'] ?? $o['guest'] ?? 0),
    ));
}
