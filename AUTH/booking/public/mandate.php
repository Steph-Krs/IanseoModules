<?php
/**
 * public/mandate.php — the competition's mandate, for the archers.
 *
 * On screen: standalone document (bk_mandate_document, shared with the organiser's preview).
 * On paper (?pdf=1): the same content through the core's PDF classes (lib/mandate-pdf.php).
 * Public but limited: served only when the organiser made the mandate VISIBLE
 * (bk_mandate_visible). Logos go through public/tourlogo.php (same guard), not
 * Common/TourLogo.php (which needs an organiser session).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$tourId = intval($_GET['t'] ?? 0);
$cfg    = $tourId ? bk_comp_config($tourId) : null;

if (!$cfg || !bk_mandate_visible($cfg) || !($data = bk_mandate_data($tourId))) {
    bk_head(bk_t('Mandate'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('MandateUnavailable')) . '</h1>'
       . bk_msg('err', bk_t('MandateNone'))
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">' . bk_e(bk_t('BackCalendarPlain')) . '</a></p></div>';
    bk_foot();
    exit;
}

$m = bk_mandate_get($cfg);

// bytes: an ASCII server variable
$scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$abs    = ($_SERVER['HTTP_HOST'] ?? '') ? $scheme . '://' . $_SERVER['HTTP_HOST'] : '';
$regUrl  = $abs . bk_public_url('competition.php?t=' . $tourId);
$shopUrl = $abs . bk_public_url('shop.php?t=' . $tourId);

if (!empty($_GET['pdf'])) {
    require_once dirname(__DIR__) . '/lib/mandate-pdf.php';
    bk_mandate_pdf($tourId, $data, $m, array('regUrl' => $regUrl, 'shopUrl' => $shopUrl));
}

bk_mandate_document($data, $m, array(
    // Public side: logos through the limited public endpoint (no organiser session).
    'logo'    => function ($type, $w) use ($tourId, $CFG) {
        return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/tourlogo.php?t=' . $tourId
             . '&type=' . $type . '&w=' . intval($w);
    },
    'regUrl'  => $regUrl,
    'shopUrl' => $shopUrl,
    'toolbar' => '<a class="mn-print" href="' . bk_e(bk_public_url('mandate.php?t=' . $tourId . '&pdf=1')) . '" target="_blank" rel="noopener">'
               . '<img src="' . bk_e($CFG->ROOT_DIR . 'Common/Images/pdf.gif') . '" alt=""> ' . bk_e(bk_t('PrintOrPdf')) . '</a>'
               . '<a class="mn-close" href="' . bk_e(bk_public_url('competition.php?t=' . $tourId)) . '">' . bk_e(bk_t('Close')) . '</a>',
));
exit;
