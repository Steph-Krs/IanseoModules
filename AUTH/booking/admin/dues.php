<?php
/**
 * admin/dues.php — Sommes dues par archer (organisateur).
 *
 * Pour la compétition ouverte : une ligne par archer inscrit en ligne, avec le
 * montant des inscriptions (moteur de tarification, comme le reçu) + la boutique
 * = total dû. Triable, avec total général. Base du futur paiement en ligne.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/shop.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$msg = '';

// Enregistrement des paiements (case + moyen par archer).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $msg = 'Session expirée — rechargez la page et réessayez.';
    } else {
        $who = $_SESSION['AUTH_User'] ?? 'organisateur';
        $paidArr = (array) ($_POST['paid'] ?? array());
        foreach ((array) ($_POST['method'] ?? array()) as $lic => $m) {
            bk_payment_set($TOUR, (string) $lic, isset($paidArr[$lic]), (string) $m, $who);
        }
        $msg = 'Paiements enregistrés.';
    }
}

$sort = $_REQUEST['sort'] ?? 'name';
if (!in_array($sort, array('name', 'club', 'amount'), true)) $sort = 'name';
$payMap  = bk_payment_map($TOUR);
$methods = bk_payment_methods();

$cfg     = bk_comp_config($TOUR);
$pricing = bk_pricing_norm($cfg->BcPricing ?? '');
$base    = $cfg->BcFee;

$tour = safe_fetch(safe_r_sql("SELECT ToName, ToWhenFrom, ToWhenTo FROM Tournament WHERE ToId = $TOUR"));

// Inscriptions en ligne + données de tarification.
$rs = safe_r_sql("SELECT r.BrEnId, r.BrLicence,
            e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
            c.CoCode, c.CoName, q.QuSession
    FROM BK_Registrations r
    INNER JOIN Entries e        ON e.EnId = r.BrEnId
    LEFT  JOIN Qualifications q ON q.QuId = e.EnId
    LEFT  JOIN Countries c      ON c.CoId = e.EnCountry
    WHERE r.BrTournament = $TOUR
    ORDER BY r.BrCreated, r.BrId");

$byLic = array();   // licence => [name, club, clubcode, count, reg, shop]
$rankMap = array();
while ($r = safe_fetch($rs)) {
    $lic = $r->BrLicence;
    if (!isset($byLic[$lic])) {
        $byLic[$lic] = array('name' => trim($r->EnFirstName . ' ' . $r->EnName),
            'club' => ($r->CoName ?: $r->CoCode), 'clubcode' => (string) $r->CoCode,
            'count' => 0, 'reg' => 0.0, 'shop' => 0.0);
    }
    if (!isset($rankMap[$lic])) $rankMap[$lic] = bk_rank_map($TOUR, $lic);
    $tier = bk_prov_tier($pricing, $r->CoCode);
    $rank = $rankMap[$lic][intval($r->BrEnId)] ?? 1;
    $byLic[$lic]['reg'] += bk_price_of($base, $pricing, $r->EnDivision, $r->EnClass, $r->QuSession, $tier, $rank);
    $byLic[$lic]['count']++;
}

// Boutique par archer (inscrits + éventuels acheteurs sans inscription).
foreach ($byLic as $lic => &$d) $d['shop'] = bk_shop_order_total($TOUR, $lic);
unset($d);
$rs = safe_r_sql("SELECT DISTINCT SoLicence FROM BK_ShopOrders WHERE SoTournament = $TOUR AND SoQty > 0");
while ($x = safe_fetch($rs)) {
    $lic = $x->SoLicence;
    if (isset($byLic[$lic])) continue;
    $shop = bk_shop_order_total($TOUR, $lic);
    if ($shop <= 0) continue;
    $nm = safe_fetch(safe_r_sql("SELECT BaFamilyName, BaName, BaClubCode FROM BK_Archers
        WHERE BaLicence = " . StrSafe_DB($lic)));
    $byLic[$lic] = array('name' => $nm ? trim($nm->BaFamilyName . ' ' . $nm->BaName) : $lic,
        'club' => $nm ? $nm->BaClubCode : '', 'clubcode' => $nm ? (string) $nm->BaClubCode : '',
        'count' => 0, 'reg' => 0.0, 'shop' => $shop);
}

// Lignes + tri.
$rows = array();
foreach ($byLic as $lic => $d) {
    $d['licence'] = $lic; $d['total'] = round($d['reg'] + $d['shop'], 2);
    $py = $payMap[$lic] ?? null;
    $d['paid'] = $py ? intval($py->PyPaid) : 0;
    $d['method'] = $py ? (string) $py->PyMethod : '';
    $d['decl'] = $py ? bk_payment_decl_label($py->PyDeclMethod ?? '', $py->PyDeclWhen ?? '') : '';
    $rows[] = $d;
}
usort($rows, function ($a, $b) use ($sort) {
    if ($sort === 'amount') return $b['total'] <=> $a['total'];
    if ($sort === 'club')   return strcasecmp($a['clubcode'] . $a['name'], $b['clubcode'] . $b['name']);
    return strcasecmp($a['name'], $b['name']);
});

$tReg = 0; $tShop = 0; $tTot = 0; $nPaid = 0; $paidAmount = 0;
foreach ($rows as $d) {
    $tReg += $d['reg']; $tShop += $d['shop']; $tTot += $d['total'];
    if ($d['paid']) { $nPaid++; $paidAmount += $d['total']; }
}
$hasShop = $tShop > 0;

function due_eur($n) { return number_format((float) $n, 2, ',', ' ') . ' €'; }
function due_url($sort) { global $CFG; return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/dues.php?sort=' . $sort; }

$PAGE_TITLE = 'Sommes dues';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkdue { max-width:900px; }
#bkdue h1 { font-size:22px; color:#01367c; margin:0 0 4px; }
#bkdue .bk-sub { color:#4c4e50; font-size:13px; margin:0 0 14px; }
#bkdue table { border-collapse:collapse; width:100%; font-size:14px; background:#fff; }
#bkdue th, #bkdue td { border:1px solid #d2d4d6; padding:7px 10px; text-align:left; }
#bkdue th { background:#f0f4ff; color:#01367c; }
#bkdue th a { color:#01367c; text-decoration:none; }
#bkdue th a.on { text-decoration:underline; }
#bkdue td.num, #bkdue th.num { text-align:right; white-space:nowrap; }
#bkdue tr.tot td { background:#eef4fb; font-weight:700; color:#01367c; }
#bkdue .bk-hint { font-size:12px; color:#7d8183; margin:10px 0 0; }
#bkdue .bk-btn { padding:8px 16px; border:1px solid #0254a8; border-radius:6px;
    background:#0254a8; color:#fff; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; }
#bkdue .bk-empty { color:#7d8183; font-style:italic; }
#bkdue .bk-decl { font-size:11px; color:#7d8183; }
#bkdue .bk-msg { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; padding:9px 12px;
    border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkdue tr.is-paid td { background:#f3fbf2; }
#bkdue select { padding:4px 6px; border:1px solid #d2d4d6; border-radius:6px; font-size:13px; }
#bkdue input[type=checkbox] { transform:scale(1.2); }
@media print { #bkdue .bk-noprint { display:none; } }
</style>

<div id="bkdue">
<h1>Sommes dues</h1>
<p class="bk-sub"><?= bk_e($tour->ToName ?? '') ?> — <?= count($rows) ?> archer<?= count($rows) > 1 ? 's' : '' ?> ·
   à encaisser <b><?= due_eur($tTot) ?></b> · déjà encaissé <b><?= due_eur($paidAmount) ?></b>
   (<?= $nPaid ?>/<?= count($rows) ?> payé<?= $nPaid > 1 ? 's' : '' ?>)</p>

<?php if ($msg): ?><div class="bk-msg"><?= bk_e($msg) ?></div><?php endif; ?>

<?php if (!$rows): ?>
  <p class="bk-empty">Aucune inscription en ligne pour l'instant sur cette compétition.</p>
<?php else: ?>
  <p class="bk-noprint" style="margin:0 0 12px">
    <button type="button" onclick="window.print()" class="bk-btn">Imprimer</button></p>
  <form method="post">
    <?= bk_csrf_field() ?>
    <input type="hidden" name="sort" value="<?= bk_e($sort) ?>">
    <table>
      <tr>
        <th><a class="<?= $sort === 'name' ? 'on' : '' ?>" href="<?= bk_e(due_url('name')) ?>">Archer</a></th>
        <th><a class="<?= $sort === 'club' ? 'on' : '' ?>" href="<?= bk_e(due_url('club')) ?>">Club</a></th>
        <th class="num">Départs</th>
        <th class="num">Inscriptions</th>
        <?php if ($hasShop): ?><th class="num">Boutique</th><?php endif; ?>
        <th class="num"><a class="<?= $sort === 'amount' ? 'on' : '' ?>" href="<?= bk_e(due_url('amount')) ?>">Total dû</a></th>
        <th class="bk-noprint">Payé</th>
        <th class="bk-noprint">Moyen</th>
      </tr>
      <?php foreach ($rows as $d): ?>
        <tr class="<?= $d['paid'] ? 'is-paid' : '' ?>">
          <td><?= bk_e($d['name']) ?></td>
          <td><?= bk_e($d['club']) ?></td>
          <td class="num"><?= intval($d['count']) ?></td>
          <td class="num"><?= due_eur($d['reg']) ?></td>
          <?php if ($hasShop): ?><td class="num"><?= due_eur($d['shop']) ?></td><?php endif; ?>
          <td class="num"><?= due_eur($d['total']) ?></td>
          <td class="bk-noprint" style="text-align:center">
            <input type="checkbox" name="paid[<?= bk_e($d['licence']) ?>]" value="1" <?= $d['paid'] ? 'checked' : '' ?>></td>
          <td class="bk-noprint">
            <select name="method[<?= bk_e($d['licence']) ?>]">
              <option value="">—</option>
              <?php foreach ($methods as $mk => $ml): ?>
                <option value="<?= bk_e($mk) ?>" <?= $d['method'] === $mk ? 'selected' : '' ?>><?= bk_e($ml) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($d['decl']): ?><div class="bk-decl">Souhait de l'archer : <?= bk_e($d['decl']) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="tot">
        <td colspan="3">Total — <?= count($rows) ?> archer<?= count($rows) > 1 ? 's' : '' ?></td>
        <td class="num"><?= due_eur($tReg) ?></td>
        <?php if ($hasShop): ?><td class="num"><?= due_eur($tShop) ?></td><?php endif; ?>
        <td class="num"><?= due_eur($tTot) ?></td>
        <td class="bk-noprint" colspan="2"><?= $nPaid ?> payé<?= $nPaid > 1 ? 's' : '' ?></td>
      </tr>
    </table>
    <p class="bk-noprint" style="margin-top:12px">
      <button type="submit" class="bk-btn">Enregistrer les paiements</button></p>
  </form>
  <p class="bk-hint">Montants calculés comme sur les reçus (tarif de base, catégorie, départ, provenance,
     dégressif) + boutique. Ce tableau reflète les inscriptions en ligne ; un archer saisi hors de ce
     module n'y figure pas. <b>Cocher « Payé » rend le reçu disponible pour l'archer</b> ; tant que ce
     n'est pas coché, il voit son montant dû marqué « paiement non validé ». (Pas de facture : les éléments
     légaux nécessaires ne sont pas réunis.)</p>
<?php endif; ?>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
