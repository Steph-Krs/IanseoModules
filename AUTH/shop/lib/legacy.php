<?php
/**
 * lib/legacy.php — moves the former shop of the online registration (tables BookingShopItems,
 * BookingShopVariants, BookingShopOrders) over to the points of sale. Called at the end of
 * shp_schema().
 *
 * Each competition that has former items gets, in ONE transaction:
 *  - its ShopSettings (created if needed), "on my account" allowed, switched on when the former
 *    shop was visible to the archers (items on sale and payments used by the competition);
 *  - a point of sale of kind 'shop' (direct), whose products and variants are the former items,
 *    pre-orderable only (as before), with the REMAINING stock (stock entered minus ordered);
 *  - one pre-order 'on my account' per licensee (status placed), lines at the current price —
 *    the same amount the former shop counted, so what each archer owes does not change by a
 *    cent. These orders carry ShLegacy = 1: the payments of the former shop were recorded on
 *    the payments page only, so an account holding them is not "known" by the stand rule
 *    (bk_account_known);
 *  - SgOldShop = 1, written last under the lock of the settings row: a competition is moved
 *    once, and a failure half-way rolls everything back to be tried again at the next session.
 * The former tables are left as they are (kept one version, then dropped).
 */

if (defined('SHP_LEGACY_LOADED')) return;
define('SHP_LEGACY_LOADED', true);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/catalog.php';

/** Competitions whose former shop is still to move; then each one moved. */
function shp_legacy_migrate()
{
    $t = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('BookingShopItems', 'BookingShopVariants', 'BookingShopOrders')"));
    if (!$t || intval($t->n) < 3) return;
    $rs = safe_r_sql("SELECT DISTINCT SiTournament FROM BookingShopItems
        INNER JOIN Tournament ON ToId = SiTournament
        WHERE SiTournament NOT IN (SELECT SgTournament FROM ShopSettings WHERE SgOldShop = 1)");
    $tours = array();
    while ($r = safe_fetch($rs)) $tours[] = intval($r->SiTournament);
    foreach ($tours as $tourId) shp_legacy_move($tourId);
}

/** Name of a licensee for the volunteers' screens: account, then entries, then the federal file. */
function shp_legacy_name($tourId, $licence)
{
    $l = StrSafe_DB($licence);
    $r = safe_fetch(safe_r_sql("SELECT BaName AS g, BaFamilyName AS f FROM BookingArchers WHERE BaLicence = $l"))
        // ianseo's EnFirstName holds the family name, EnName the given name.
        ?: safe_fetch(safe_r_sql("SELECT EnName AS g, EnFirstName AS f FROM Entries
            WHERE EnTournament = " . intval($tourId) . " AND EnCode = $l LIMIT 1"))
        ?: safe_fetch(safe_r_sql("SELECT LueName AS g, LueFamilyName AS f FROM LookUpEntries
            WHERE LueCode = $l ORDER BY LueDefault DESC LIMIT 1"));
    return $r ? mb_substr(trim($r->g . ' ' . $r->f), 0, 60) : '';
}

/** Moves the former shop of one competition (see the file header). */
function shp_legacy_move($tourId)
{
    $tourId = intval($tourId);
    if (!shp_settings_ensure($tourId)) return;

    // Everything is read before the transaction.
    $items = array();
    $rs = safe_r_sql("SELECT * FROM BookingShopItems WHERE SiTournament = $tourId ORDER BY SiOrder, SiId");
    while ($r = safe_fetch($rs)) $items[intval($r->SiId)] = $r;
    $variants = array();
    $rs = safe_r_sql("SELECT BookingShopVariants.* FROM BookingShopVariants
        INNER JOIN BookingShopItems ON SiId = SvItem WHERE SiTournament = $tourId ORDER BY SvOrder, SvId");
    while ($r = safe_fetch($rs)) $variants[intval($r->SvItem)][intval($r->SvId)] = $r;
    $ordered = array();
    $orders = array();
    $rs = safe_r_sql("SELECT SoLicence, SoItem, SoVariant, SoQty, SoUpdated FROM BookingShopOrders
        INNER JOIN BookingShopItems ON SiId = SoItem
        WHERE SoTournament = $tourId AND SoQty > 0 AND SoLicence <> '' ORDER BY SoLicence, SiOrder, SiId, SoVariant");
    while ($r = safe_fetch($rs)) {
        $item = intval($r->SoItem);
        $var = intval($r->SoVariant);
        // An order on a variant that no longer exists counted at the item's price: kept, without variant.
        if ($var > 0 && !isset($variants[$item][$var])) $var = 0;
        $k = $item . ':' . $var;
        $ordered[$k] = ($ordered[$k] ?? 0) + intval($r->SoQty);
        $orders[(string) $r->SoLicence][] = array('item' => $item, 'variant' => $var, 'qty' => intval($r->SoQty),
            'when' => (string) $r->SoUpdated);
    }
    $cfg = safe_fetch(safe_r_sql("SELECT BcPublishLevel, BcPayments, BcOpen, BcOpenTo, BcShopUntil FROM BookingCompetitions
        WHERE BcTournament = $tourId"));
    $tour = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = $tourId"));
    $hasActive = false;
    foreach ($items as $it) if (intval($it->SiActive) === 1) $hasActive = true;
    $visible = $hasActive && $cfg && (intval($cfg->BcPublishLevel) >= 2 || intval($cfg->BcPayments) === 1);
    // Pre-orders end where the former shop closed: its own deadline, else the end of the
    // registration window, else the eve of the competition (the shop now sells on the spot).
    $until = null;
    if ($cfg && trim((string) $cfg->BcShopUntil) !== '' && strpos((string) $cfg->BcShopUntil, '0000') !== 0) {
        $until = (string) $cfg->BcShopUntil;
    } elseif ($cfg && intval($cfg->BcPublishLevel) >= 2 && intval($cfg->BcOpen) === 1 && (string) $cfg->BcOpenTo !== '') {
        $until = (string) $cfg->BcOpenTo;
    } elseif ($tour && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $tour->ToWhenFrom) && $tour->ToWhenFrom > '0001-01-01') {
        $until = date('Y-m-d 23:59:00', strtotime($tour->ToWhenFrom . ' -1 day'));
    }
    $names = array();
    foreach (array_keys($orders) as $lic) $names[$lic] = shp_legacy_name($tourId, (string) $lic);
    $now = shp_local_now_sql($tourId);

    safe_w_BeginTransaction();
    $s = safe_fetch(safe_w_sql("SELECT SgOldShop FROM ShopSettings WHERE SgTournament = $tourId FOR UPDATE"));
    if (!$s || intval($s->SgOldShop) === 1) {
        safe_w_Rollback();   // moved by a concurrent request meanwhile
        return;
    }

    // The point of sale, with a letter its competition does not use yet.
    $used = array();
    $max = 0;
    $rs = safe_w_sql("SELECT SdPrefix, SdOrder FROM ShopStands WHERE SdTournament = $tourId");
    while ($r = safe_fetch($rs)) { $used[(string) $r->SdPrefix] = true; $max = max($max, intval($r->SdOrder)); }
    $prefix = 'A';
    foreach (range('A', 'Z') as $c) if (!isset($used[$c])) { $prefix = $c; break; }
    safe_w_sql("INSERT INTO ShopStands SET SdTournament = $tourId, SdKind = 'shop', SdName = " . StrSafe_DB(mb_substr(shp_t('ShKindShop'), 0, 60))
        . ", SdMode = 'direct', SdPayWhen = 'pickup', SdOnline = 1, SdOpen = 0, SdPrepMin = 3, SdParallel = 1, SdPrefix = "
        . StrSafe_DB($prefix) . ", SdNextNo = 0, SdOrder = " . ($max + 1) . ", SdActive = 1");
    $standId = intval(safe_w_last_id());

    // Catalogue: remaining stock = stock entered - ordered (the former 0 meant "unlimited").
    $left = function ($stock, $key) use ($ordered) {
        return intval($stock) > 0 ? max(0, intval($stock) - ($ordered[$key] ?? 0)) : null;
    };
    $prodMap = array();
    $varMap = array();
    $unit = array();
    $label = array();
    foreach ($items as $id => $it) {
        $hasVars = trim((string) $it->SiOptionName) !== '' && !empty($variants[$id]);
        $stock = $hasVars ? null : $left($it->SiStock, $id . ':0');
        safe_w_sql("INSERT INTO ShopProducts SET SpTournament = $tourId, SpStand = $standId, SpCategory = " . StrSafe_DB($it->SiSection)
            . ", SpName = " . StrSafe_DB($it->SiLabel) . ", SpDescription = " . StrSafe_DB($it->SiDescription)
            . ", SpPrice = " . StrSafe_DB(number_format((float) $it->SiPrice, 2, '.', ''))
            . ", SpStock = " . ($stock === null ? 'NULL' : $stock) . ", SpStockAlert = NULL, SpMaxPer = " . min(32767, intval($it->SiMaxPerPerson))
            . ", SpOptionName = " . StrSafe_DB($hasVars ? $it->SiOptionName : '') . ", SpPreorder = 1, SpOnsite = 0, SpAvailable = 1"
            . ", SpOrder = " . intval($it->SiOrder) . ", SpActive = " . (intval($it->SiActive) === 1 ? 1 : 0) . ", SpUpdated = $now");
        $prodMap[$id] = intval(safe_w_last_id());
        $unit[$id] = round((float) $it->SiPrice, 2);
        $label[$id . ':0'] = (string) $it->SiLabel;
        foreach ($hasVars ? $variants[$id] : array() as $vid => $v) {
            $vs = $left($v->SvStock, $id . ':' . $vid);
            safe_w_sql("INSERT INTO ShopVariants SET SwProduct = " . $prodMap[$id] . ", SwLabel = " . StrSafe_DB($v->SvLabel)
                . ", SwPrice = NULL, SwStock = " . ($vs === null ? 'NULL' : $vs) . ", SwAvailable = 1, SwOrder = " . intval($v->SvOrder));
            $varMap[$vid] = intval(safe_w_last_id());
            $label[$id . ':' . $vid] = $it->SiLabel . ' — ' . $v->SvLabel;
        }
    }

    // One pre-order per licensee; its stock moves make "initial = left + sold" hold.
    foreach ($orders as $lic => $lines) {
        $lic = (string) $lic;   // a licence made of digits only became an integer key
        safe_w_sql("UPDATE ShopStands SET SdNextNo = LAST_INSERT_ID(SdNextNo + 1) WHERE SdId = $standId");
        $seq = intval(safe_w_last_id());
        $total = 0.0;
        $first = '';
        foreach ($lines as $ln) {
            $total += $unit[$ln['item']] * $ln['qty'];
            if ($first === '' || ($ln['when'] !== '' && $ln['when'] < $first)) $first = $ln['when'];
        }
        // bytes: a hexadecimal digest, ASCII
        $idem = 'legacy-' . substr(sha1((string) $lic), 0, 29);
        safe_w_sql("INSERT INTO ShopOrders SET ShTournament = $tourId, ShStand = $standId, ShSeq = $seq, ShNumber = "
            . StrSafe_DB(sprintf('%s-%03d', $prefix, $seq)) . ", ShCustKind = 'ARCHER', ShLicence = " . StrSafe_DB($lic)
            . ", ShGuest = 0, ShCustLabel = " . StrSafe_DB($names[$lic]) . ", ShChannel = 'preorder', ShStatus = 'placed'"
            . ", ShPayMode = 'tab', ShPayState = 'tab', ShTotal = " . StrSafe_DB(number_format(round($total, 2), 2, '.', ''))
            . ", ShPaid = 0, ShIdem = " . StrSafe_DB($idem) . ", ShNote = '', ShCreated = "
            . ($first !== '' && strpos($first, '0000') !== 0 ? StrSafe_DB($first) : $now) . ", ShLegacy = 1");
        $orderId = intval(safe_w_last_id());
        foreach ($lines as $ln) {
            $pid = $prodMap[$ln['item']];
            $vid = $ln['variant'] > 0 && isset($varMap[$ln['variant']]) ? $varMap[$ln['variant']] : 0;
            safe_w_sql("INSERT INTO ShopOrderLines SET SnOrder = $orderId, SnProduct = $pid, SnVariant = $vid, SnLabel = "
                . StrSafe_DB(mb_substr($label[$ln['item'] . ':' . ($vid ? $ln['variant'] : 0)] ?? $label[$ln['item'] . ':0'], 0, 160))
                . ", SnUnit = " . StrSafe_DB(number_format($unit[$ln['item']], 2, '.', '')) . ", SnQty = " . min(32767, $ln['qty']));
            $limited = $vid ? intval($variants[$ln['item']][$ln['variant']]->SvStock) > 0 : intval($items[$ln['item']]->SiStock) > 0;
            if ($limited) {
                safe_w_sql("INSERT INTO ShopStockMoves SET SmTournament = $tourId, SmProduct = $pid, SmVariant = $vid, SmDelta = "
                    . (-$ln['qty']) . ", SmReason = 'order', SmOrder = $orderId, SmStaff = 0, SmWhen = $now");
            }
        }
    }

    safe_w_sql("UPDATE ShopSettings SET SgOldShop = 1, SgTab = 1"
        . ($visible ? ", SgEnabled = 1" : "")
        . ($until !== null ? ", SgPreorderUntil = COALESCE(SgPreorderUntil, " . StrSafe_DB($until) . ")" : "")
        . ", SgUpdated = $now WHERE SgTournament = $tourId");
    safe_w_Commit();
    shp_settings($tourId, true);
}
