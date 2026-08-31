<?php
/**
 * public/mandate.php — mandat de compétition consultable par les archers.
 *
 * Document autonome (bk_mandate_document, mutualisé avec l'aperçu organisateur),
 * en accès public borné : n'est servi que si l'organisateur a rendu le mandat
 * VISIBLE (bk_mandate_visible). Les logos passent par public/tourlogo.php (même
 * garde), pas par Common/TourLogo.php (qui exige une session organisateur).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$tourId = intval($_GET['t'] ?? 0);
$cfg    = $tourId ? bk_comp_config($tourId) : null;

if (!$cfg || !bk_mandate_visible($cfg) || !($data = bk_mandate_data($tourId))) {
    bk_head('Mandat', 'card');
    echo '<div class="bk-card"><h1>Mandat indisponible</h1>'
       . bk_msg('err', "Le mandat de cette compétition n'est pas disponible.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">Retour au calendrier</a></p></div>';
    bk_foot();
    exit;
}

$m = bk_mandate_get($cfg);

$scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$abs    = ($_SERVER['HTTP_HOST'] ?? '') ? $scheme . '://' . $_SERVER['HTTP_HOST'] : '';

bk_mandate_document($data, $m, array(
    // Face publique : logos via l'endpoint public borné (pas de session organisateur).
    'logo'    => function ($type, $w) use ($tourId, $CFG) {
        return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/tourlogo.php?t=' . $tourId
             . '&type=' . $type . '&w=' . intval($w);
    },
    'regUrl'  => $abs . bk_public_url('competition.php?t=' . $tourId),
    'shopUrl' => $abs . bk_public_url('shop.php?t=' . $tourId),
    'toolbar' => '<button type="button" class="mn-print" onclick="window.print()">Imprimer / enregistrer en PDF</button>'
               . '<a class="mn-close" href="' . bk_e(bk_public_url('competition.php?t=' . $tourId)) . '">Fermer</a>',
));
exit;
