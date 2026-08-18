<?php
/**
 * lib/shop.php — Boutique de la compétition (buvette généralisée : souvenirs,
 * hébergement, accès…).
 *
 * Un article (BK_ShopItems) appartient à une section libre (Buvette, Souvenirs…).
 * S'il a un nom d'option (SiOptionName, ex. « Taille »), il porte des variantes
 * (BK_ShopVariants, ex. S/M/L), chacune avec son propre stock. Sinon c'est un
 * article simple à stock unique. Les commandes (BK_ShopOrders) sont une quantité
 * par (compétition, licence, article, variante), éditables tant que la boutique
 * est ouverte. Stock 0 = illimité ; SiMaxPerPerson 0 = illimité.
 *
 * Le serveur est l'autorité : tout contrôle de stock/plafond est refait ici.
 */

if (defined('BK_SHOP_LOADED')) return;
define('BK_SHOP_LOADED', true);

require_once __DIR__ . '/schema.php';

/** La boutique accepte-t-elle des commandes ? Date limite propre, sinon suit les inscriptions. */
function bk_shop_open($cfg)
{
    $until = trim((string) ($cfg->BcShopUntil ?? ''));
    if ($until === '' || strpos($until, '0000') === 0) return !empty($cfg->BcIsOpen);
    // Comparaison en temps SQL (le fuseau MySQL peut différer de PHP selon la compétition).
    $rs = safe_r_sql("SELECT (" . StrSafe_DB($until) . " >= NOW()) AS o");
    $r = safe_fetch($rs);
    return $r ? (bool) $r->o : false;
}

/** Au moins un article actif dans la boutique de cette compétition. */
function bk_shop_has_items($tourId)
{
    bk_schema();
    $rs = safe_r_sql("SELECT 1 FROM BK_ShopItems
        WHERE SiTournament = " . intval($tourId) . " AND SiActive = 1 LIMIT 1");
    return (bool) safe_fetch($rs);
}

/**
 * Articles de la boutique, avec variantes et stock restant. Si $licence est
 * fournie, chaque article/variante porte aussi 'mine' (quantité déjà commandée).
 * Retour : [SiId => [id, section, label, description, price, stock, maxper,
 *                    option, active, remaining, mine, variants=[SvId => [...]]]].
 */
function bk_shop_items($tourId, $activeOnly = false, $licence = null)
{
    bk_schema();
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT * FROM BK_ShopItems WHERE SiTournament = $tourId"
        . ($activeOnly ? " AND SiActive = 1" : "") . " ORDER BY SiOrder, SiId");
    $items = array();
    while ($r = safe_fetch($rs)) {
        $items[intval($r->SiId)] = array(
            'id' => intval($r->SiId), 'section' => (string) $r->SiSection, 'label' => (string) $r->SiLabel,
            'description' => (string) $r->SiDescription, 'price' => (float) $r->SiPrice,
            'stock' => intval($r->SiStock), 'maxper' => intval($r->SiMaxPerPerson),
            'option' => (string) $r->SiOptionName, 'active' => intval($r->SiActive),
            'remaining' => null, 'mine' => 0, 'variants' => array(),
        );
    }
    if (!$items) return array();
    $ids = implode(',', array_map('intval', array_keys($items)));

    $rs = safe_r_sql("SELECT * FROM BK_ShopVariants WHERE SvItem IN ($ids) ORDER BY SvOrder, SvId");
    while ($r = safe_fetch($rs)) {
        $it = intval($r->SvItem);
        if (isset($items[$it])) $items[$it]['variants'][intval($r->SvId)] = array(
            'id' => intval($r->SvId), 'label' => (string) $r->SvLabel,
            'stock' => intval($r->SvStock), 'remaining' => null, 'mine' => 0,
        );
    }

    $rs = safe_r_sql("SELECT SoItem, SoVariant, SUM(SoQty) q FROM BK_ShopOrders
        WHERE SoTournament = $tourId GROUP BY SoItem, SoVariant");
    $ord = array();
    while ($r = safe_fetch($rs)) $ord[intval($r->SoItem) . ':' . intval($r->SoVariant)] = intval($r->q);

    $mine = array();
    if ($licence !== null) {
        $rs = safe_r_sql("SELECT SoItem, SoVariant, SoQty FROM BK_ShopOrders
            WHERE SoTournament = $tourId AND SoLicence = " . StrSafe_DB($licence));
        while ($r = safe_fetch($rs)) $mine[intval($r->SoItem) . ':' . intval($r->SoVariant)] = intval($r->SoQty);
    }

    foreach ($items as $id => &$it) {
        if ($it['variants']) {
            foreach ($it['variants'] as $vid => &$v) {
                $o = $ord["$id:$vid"] ?? 0;
                $v['remaining'] = $v['stock'] > 0 ? max(0, $v['stock'] - $o) : null;
                $v['mine'] = $mine["$id:$vid"] ?? 0;
            }
            unset($v);
        } else {
            $o = $ord["$id:0"] ?? 0;
            $it['remaining'] = $it['stock'] > 0 ? max(0, $it['stock'] - $o) : null;
            $it['mine'] = $mine["$id:0"] ?? 0;
        }
    }
    unset($it);
    return $items;
}

/**
 * Enregistre une quantité commandée (upsert, ou suppression si 0), avec contrôle
 * de stock et de plafond par personne. Retour ['ok'=>bool, 'msg'=>?].
 */
function bk_shop_order_set($tourId, $licence, $itemId, $variantId, $qty)
{
    bk_schema();
    $tourId = intval($tourId); $itemId = intval($itemId);
    $variantId = intval($variantId); $qty = max(0, intval($qty));
    $lic = StrSafe_DB($licence);

    $rs = safe_r_sql("SELECT * FROM BK_ShopItems WHERE SiId = $itemId AND SiTournament = $tourId AND SiActive = 1");
    $it = safe_fetch($rs);
    if (!$it) return array('ok' => false, 'msg' => "Article indisponible.");

    if (trim((string) $it->SiOptionName) !== '') {
        if ($variantId <= 0) return array('ok' => false, 'msg' => "Choisissez une option.");
        $rs = safe_r_sql("SELECT SvStock FROM BK_ShopVariants WHERE SvId = $variantId AND SvItem = $itemId");
        $v = safe_fetch($rs);
        if (!$v) return array('ok' => false, 'msg' => "Option invalide.");
        $stock = intval($v->SvStock);
    } else {
        $variantId = 0;
        $stock = intval($it->SiStock);
    }

    $rs = safe_r_sql("SELECT SoQty FROM BK_ShopOrders WHERE SoTournament = $tourId
        AND SoLicence = $lic AND SoItem = $itemId AND SoVariant = $variantId");
    $cur = safe_fetch($rs); $mineOld = $cur ? intval($cur->SoQty) : 0;

    $rs = safe_r_sql("SELECT COALESCE(SUM(SoQty),0) q FROM BK_ShopOrders
        WHERE SoTournament = $tourId AND SoItem = $itemId AND SoVariant = $variantId");
    $others = intval(safe_fetch($rs)->q) - $mineOld;
    if ($stock > 0 && $qty > $stock - $others) {
        return array('ok' => false, 'msg' => "Stock insuffisant : il reste " . max(0, $stock - $others) . ".");
    }

    $maxper = intval($it->SiMaxPerPerson);
    if ($maxper > 0) {
        $rs = safe_r_sql("SELECT COALESCE(SUM(SoQty),0) q FROM BK_ShopOrders
            WHERE SoTournament = $tourId AND SoLicence = $lic AND SoItem = $itemId AND SoVariant <> $variantId");
        if (intval(safe_fetch($rs)->q) + $qty > $maxper) {
            return array('ok' => false, 'msg' => "Maximum $maxper par personne pour cet article.");
        }
    }

    if ($qty === 0) {
        safe_w_sql("DELETE FROM BK_ShopOrders WHERE SoTournament = $tourId
            AND SoLicence = $lic AND SoItem = $itemId AND SoVariant = $variantId");
    } else {
        safe_w_sql("INSERT INTO BK_ShopOrders (SoTournament, SoLicence, SoItem, SoVariant, SoQty)
            VALUES ($tourId, $lic, $itemId, $variantId, $qty)
            ON DUPLICATE KEY UPDATE SoQty = $qty");
    }
    return array('ok' => true);
}

/** Total boutique d'un archer sur une compétition (pour le reçu). */
function bk_shop_order_total($tourId, $licence)
{
    bk_schema();
    $rs = safe_r_sql("SELECT COALESCE(SUM(o.SoQty * i.SiPrice), 0) t FROM BK_ShopOrders o
        INNER JOIN BK_ShopItems i ON i.SiId = o.SoItem
        WHERE o.SoTournament = " . intval($tourId) . " AND o.SoLicence = " . StrSafe_DB($licence) . " AND o.SoQty > 0");
    $r = safe_fetch($rs);
    return $r ? (float) $r->t : 0.0;
}

/** Lignes détaillées de la commande boutique d'un archer (reçu / récap). */
function bk_shop_order_lines($tourId, $licence)
{
    bk_schema();
    $rs = safe_r_sql("SELECT i.SiLabel, i.SiSection, i.SiPrice, v.SvLabel, o.SoQty
        FROM BK_ShopOrders o
        INNER JOIN BK_ShopItems i ON i.SiId = o.SoItem
        LEFT  JOIN BK_ShopVariants v ON v.SvId = o.SoVariant
        WHERE o.SoTournament = " . intval($tourId) . " AND o.SoLicence = " . StrSafe_DB($licence) . " AND o.SoQty > 0
        ORDER BY i.SiOrder, i.SiId");
    $out = array();
    while ($r = safe_fetch($rs)) {
        $out[] = array(
            'label' => $r->SiLabel . ($r->SvLabel ? ' — ' . $r->SvLabel : ''),
            'section' => (string) $r->SiSection, 'qty' => intval($r->SoQty),
            'unit' => (float) $r->SiPrice, 'amount' => intval($r->SoQty) * (float) $r->SiPrice,
        );
    }
    return $out;
}

/** Fixe la date limite propre à la boutique ('' = suit les inscriptions). */
function bk_shop_set_deadline($tourId, $dt)
{
    bk_schema();
    $tourId = intval($tourId);
    $dt = trim((string) $dt);
    $val = $dt === '' ? 'NULL' : StrSafe_DB(str_replace('T', ' ', substr($dt, 0, 16)));
    safe_w_sql("INSERT INTO BK_Competitions SET BcTournament = $tourId, BcShopUntil = $val
        ON DUPLICATE KEY UPDATE BcShopUntil = $val");
}

/* ----- CRUD organisateur ----- */

function bk_shop_item_upsert($tourId, $d)
{
    bk_schema();
    $tourId = intval($tourId);
    $set = "SiSection = " . StrSafe_DB(substr(trim((string) ($d['section'] ?? '')), 0, 60))
        . ", SiLabel = " . StrSafe_DB(substr(trim((string) ($d['label'] ?? '')), 0, 120))
        . ", SiDescription = " . StrSafe_DB(substr(trim((string) ($d['description'] ?? '')), 0, 255))
        . ", SiPrice = " . StrSafe_DB(number_format((float) str_replace(',', '.', (string) ($d['price'] ?? 0)), 2, '.', ''))
        . ", SiStock = " . max(0, intval($d['stock'] ?? 0))
        . ", SiMaxPerPerson = " . max(0, intval($d['maxper'] ?? 0))
        . ", SiOptionName = " . StrSafe_DB(substr(trim((string) ($d['option'] ?? '')), 0, 40))
        . ", SiOrder = " . max(0, intval($d['order'] ?? 0))
        . ", SiActive = " . (empty($d['active']) ? 0 : 1);
    $id = intval($d['id'] ?? 0);
    if ($id > 0) {
        safe_w_sql("UPDATE BK_ShopItems SET $set WHERE SiId = $id AND SiTournament = $tourId");
        return $id;
    }
    safe_w_sql("INSERT INTO BK_ShopItems SET SiTournament = $tourId, $set");
    return intval(safe_w_last_id());   // id sur la connexion d'ÉCRITURE (READ_CON renverrait 0)
}

function bk_shop_item_delete($tourId, $itemId)
{
    bk_schema();
    $tourId = intval($tourId); $itemId = intval($itemId);
    if (!safe_fetch(safe_r_sql("SELECT SiId FROM BK_ShopItems WHERE SiId = $itemId AND SiTournament = $tourId"))) return;
    safe_w_sql("DELETE FROM BK_ShopVariants WHERE SvItem = $itemId");
    safe_w_sql("DELETE FROM BK_ShopOrders WHERE SoItem = $itemId AND SoTournament = $tourId");
    safe_w_sql("DELETE FROM BK_ShopItems WHERE SiId = $itemId AND SiTournament = $tourId");
}

function bk_shop_variant_upsert($itemId, $d)
{
    bk_schema();
    $itemId = intval($itemId);
    $set = "SvLabel = " . StrSafe_DB(substr(trim((string) ($d['label'] ?? '')), 0, 80))
        . ", SvStock = " . max(0, intval($d['stock'] ?? 0))
        . ", SvOrder = " . max(0, intval($d['order'] ?? 0));
    $id = intval($d['id'] ?? 0);
    if ($id > 0) {
        safe_w_sql("UPDATE BK_ShopVariants SET $set WHERE SvId = $id AND SvItem = $itemId");
        return $id;
    }
    safe_w_sql("INSERT INTO BK_ShopVariants SET SvItem = $itemId, $set");
    return intval(safe_w_last_id());   // id sur la connexion d'ÉCRITURE (READ_CON renverrait 0)
}

function bk_shop_variant_delete($variantId)
{
    bk_schema();
    $variantId = intval($variantId);
    safe_w_sql("DELETE FROM BK_ShopOrders WHERE SoVariant = $variantId");
    safe_w_sql("DELETE FROM BK_ShopVariants WHERE SvId = $variantId");
}
