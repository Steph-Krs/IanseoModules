<?php
/**
 * public/receipt.php — reçu d'inscription, par archer ou pour tout un club.
 *
 * `?enid=N`          : reçu d'une inscription (l'archer connecté uniquement).
 * `?club=1&t=<ToId>` : reçu groupé du club, réservé aux gestionnaires déclarés.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/documents.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/shop.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/club.php';

$archer   = bk_require_archer();
$lignes   = array();
$titre    = '';
$tour     = null;
$erreur   = '';
$shopTour = 0;          // >0 = reçu d'un archer sur une compétition → inclut sa boutique
$shopLic  = '';

if (!empty($_GET['club'])) {
    $tourId = intval($_GET['t'] ?? 0);
    $scopes = bk_manager_scopes($archer);
    if (!$scopes) {
        $erreur = "Votre compte n'est pas déclaré gestionnaire de club.";
    } elseif (!$tourId) {
        $erreur = "Compétition non précisée.";
    } else {
        $rs = safe_r_sql("SELECT r.BrEnId FROM BK_Registrations r
            INNER JOIN Entries e ON e.EnId = r.BrEnId
            LEFT  JOIN Countries c ON c.CoId = e.EnCountry
            WHERE r.BrTournament = $tourId AND " . bk_scopes_sql($scopes, 'c.CoCode') . "
            ORDER BY e.EnFirstName, e.EnName");
        while ($r = safe_fetch($rs)) {
            if ($e = bk_doc_entry(intval($r->BrEnId))) $lignes[] = $e;
        }
        if (!$lignes) $erreur = "Aucune inscription de votre club sur cette compétition.";
        else { $tour = $lignes[0]; $titre = 'Reçu d\'inscriptions — club'; }
    }
} elseif (!empty($_GET['comp'])) {
    // Reçu global : inscriptions + boutique de l'archer connecté sur une compétition.
    $tourId = intval($_GET['comp']);
    if (!$tourId) {
        $erreur = "Compétition non précisée.";
    } else {
        $shopTour = $tourId; $shopLic = $archer->BaLicence;
        $rs = safe_r_sql("SELECT r.BrEnId FROM BK_Registrations r
            WHERE r.BrTournament = $tourId AND r.BrLicence = " . StrSafe_DB($archer->BaLicence) . "
            ORDER BY r.BrEnId");
        while ($r = safe_fetch($rs)) {
            if (($e = bk_doc_entry(intval($r->BrEnId)))
                && bk_clean_licence($e->BrLicence) === bk_clean_licence($archer->BaLicence)) {
                $lignes[] = $e;
            }
        }
        $hasShop = bk_shop_order_total($tourId, $archer->BaLicence) > 0;
        if (!$lignes && !$hasShop) {
            $erreur = "Vous n'avez aucune inscription ni commande sur cette compétition.";
        } elseif ($lignes) {
            usort($lignes, function ($a, $b) { return intval($a->QuSession) - intval($b->QuSession); });
            $tour = $lignes[0]; $titre = "Reçu d'inscription" . (count($lignes) > 1 ? 's' : '');
        } else {
            // Que de la boutique (aucune inscription) : entête depuis la compétition.
            $tour = safe_fetch(safe_r_sql("SELECT ToName, ToWhere, ToWhenFrom, ToWhenTo
                FROM Tournament WHERE ToId = $tourId"));
            $titre = "Reçu — boutique";
        }
    }
} else {
    $e = bk_doc_entry(intval($_GET['enid'] ?? 0));
    if (!$e || bk_clean_licence($e->BrLicence) !== bk_clean_licence($archer->BaLicence)) {
        $erreur = "Cette inscription n'est pas la vôtre.";
    } else {
        $lignes = array($e); $tour = $e; $titre = "Reçu d'inscription";
    }
}

if ($erreur) {
    bk_head('Reçu', 'card');
    echo '<div class="bk-card"><h1>Indisponible</h1>' . bk_msg('err', $erreur)
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">Mes inscriptions</a></p></div>';
    bk_foot();
    exit;
}

// Reçu individuel : disponible seulement une fois le paiement encaissé par
// l'organisateur (sauf compétition gratuite). Le montant dû, lui, reste visible
// dans « Mes inscriptions » avec la mention « non encore encaissé ».
if ($shopTour) {
    $dueChk = bk_due_total($shopTour, $shopLic);
    if ($dueChk['total'] > 0 && !bk_payment_is_paid($shopTour, $shopLic)) {
        bk_head('Reçu', 'card');
        echo '<div class="bk-card"><h1>Reçu indisponible</h1>'
           . bk_msg('err', "Votre reçu sera disponible une fois le paiement validé par l'organisateur.")
           . '<p class="bk-fee">Montant dû : ' . bk_e(number_format($dueChk['total'], 2, ',', ' ')) . ' €</p>'
           . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">Mes inscriptions</a></p></div>';
        bk_foot();
        exit;
    }
}

// Prix par ligne via le moteur de tarification (le reçu est l'autorité).
$total = 0;
$pricCache = array();   // ToId => config normalisée
$rankCache = array();   // "ToId|licence" => [EnId => rang]
foreach ($lignes as $l) {
    $tid = intval($l->ToId);
    if (!isset($pricCache[$tid])) $pricCache[$tid] = bk_pricing_norm($l->BcPricing ?? '');
    $key = $tid . '|' . $l->BrLicence;
    if (!isset($rankCache[$key])) $rankCache[$key] = bk_rank_map($tid, $l->BrLicence);
    $rank = $rankCache[$key][intval($l->EnId)] ?? 1;
    $tier = bk_prov_tier($pricCache[$tid], $l->CoCode);
    $l->_price = bk_price_of($l->BcFee, $pricCache[$tid], $l->EnDivision, $l->EnClass, $l->QuSession, $tier, $rank);
    $total += $l->_price;
}

// Boutique de l'archer (uniquement sur un reçu individuel de compétition).
$shopLines = array(); $shopTotal = 0;
if ($shopTour) {
    $shopLines = bk_shop_order_lines($shopTour, $shopLic);
    $shopTotal = bk_shop_order_total($shopTour, $shopLic);
}
$grand = $total + $shopTotal;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= bk_e($titre) ?></title>
<link rel="stylesheet" href="<?= bk_e(bk_public_url('assets/bk.css')) ?>?v=<?= bk_e(bk_version()) ?>">
<link rel="stylesheet" href="<?= bk_e(bk_public_url('assets/print.css')) ?>?v=<?= bk_e(bk_version()) ?>">
</head>
<body>
<div id="bk" class="bk-doc">
  <p class="bk-noprint"><a href="<?= bk_e(bk_public_url('registrations.php')) ?>">← Mes inscriptions</a>
     &nbsp; <button onclick="window.print()" class="bk-btn bk-btn-primary">Imprimer</button></p>

  <header class="bk-doc-head">
    <h1><?= bk_e($titre) ?></h1>
    <p class="bk-doc-comp"><b><?= bk_e($tour->ToName) ?></b><br>
      <?= bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) ?>
      <?= $tour->ToWhere ? ' — ' . bk_e($tour->ToWhere) : '' ?></p>
    <p class="bk-doc-date">Édité le <?= bk_e(date('d/m/Y')) ?></p>
  </header>

  <?php if ($lignes): ?>
  <div class="bk-doc-scroll">
  <table class="bk-doc-grid bk-doc-lines">
    <tr><th>Licence</th><th>Archer</th><th>Club</th><th>Catégorie</th><th>Départ</th><th>Montant</th></tr>
    <?php foreach ($lignes as $l): ?>
      <tr>
        <td><?= bk_e($l->EnCode) ?></td>
        <td><?= bk_e($l->EnFirstName . ' ' . $l->EnName) ?></td>
        <td><?= bk_e($l->CoName ?: $l->CoCode) ?></td>
        <td><?= bk_e(($l->DivDescription ?: $l->EnDivision) . ' / ' . ($l->ClDescription ?: $l->EnClass)) ?></td>
        <td><?= intval($l->QuSession) ?></td>
        <td class="bk-doc-num"><?= bk_e(number_format((float) $l->_price, 2, ',', ' ')) ?> €</td>
      </tr>
    <?php endforeach; ?>
    <tr class="bk-doc-tot">
      <td colspan="5"><?= $shopLines ? 'Sous-total inscriptions' : 'Total' ?> — <?= count($lignes) ?> inscription<?= count($lignes) > 1 ? 's' : '' ?></td>
      <td class="bk-doc-num"><?= bk_e(number_format($total, 2, ',', ' ')) ?> €</td>
    </tr>
  </table>
  </div>
  <?php endif; ?>

  <?php if ($shopLines): ?>
  <div class="bk-doc-scroll"<?= $lignes ? ' style="margin-top:14px"' : '' ?>>
  <table class="bk-doc-grid bk-doc-lines">
    <tr><th colspan="5">Boutique</th><th>Montant</th></tr>
    <?php foreach ($shopLines as $sl): ?>
      <tr>
        <td colspan="5"><?= bk_e($sl['label']) ?> <span class="bk-doc-mut">× <?= intval($sl['qty']) ?></span></td>
        <td class="bk-doc-num"><?= bk_e(number_format((float) $sl['amount'], 2, ',', ' ')) ?> €</td>
      </tr>
    <?php endforeach; ?>
    <tr class="bk-doc-tot">
      <td colspan="5"><?= $lignes ? 'Sous-total boutique' : 'Total boutique' ?></td>
      <td class="bk-doc-num"><?= bk_e(number_format($shopTotal, 2, ',', ' ')) ?> €</td>
    </tr>
  </table>
  </div>
  <?php endif; ?>

  <?php if ($lignes && $shopLines): ?>
  <p class="bk-doc-grand">Total général : <b><?= bk_e(number_format($grand, 2, ',', ' ')) ?> €</b></p>
  <?php endif; ?>

  <p class="bk-doc-foot">
    <?php if ($grand <= 0): ?>
      Aucun tarif n'a été renseigné par l'organisateur : ce document vaut confirmation
      d'inscription, sans valeur comptable.
    <?php else: ?>
      Document de confirmation. Le règlement s'effectue selon les modalités indiquées par
      l'organisateur ; ce reçu ne constitue pas une preuve de paiement.
    <?php endif; ?>
  </p>
</div>
</body>
</html>
