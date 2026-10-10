<?php
/**
 * public/licence.php — the federation's licence certificate (PDF of the licensee space).
 *
 * Two ways, in this order (product choice):
 *  1) RELAY through the licensee-space session cookie KEPT at sign-in (bk_ffta_fetch_pdf) —
 *     the archer has nothing to type again. The cookie is only read, never rewritten.
 *  2) FALLBACK (no cookie, or the licensee-space session expired): redirection to the direct
 *     URL of the certificate → the archer signs in to THEIR licensee space and gets it there.
 *
 * The Exalto id (in the URL …/pdf/p/{id}/{season}) is captured at sign-in (BaExaltoId), never
 * typed. Without it (account signed in before this feature), the archer is asked to sign in again.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/ffta.php';

$archer = bk_require_archer();
$exalto = preg_replace('/\D/', '', (string) $archer->BaExaltoId);
$season = bk_ffta_season();

// Unknown Exalto id (account signed in before the feature, or charter already accepted at
// sign-in): resolved ON DEMAND through the kept cookie, and remembered.
if ($exalto === '') {
    $exalto = preg_replace('/\D/', '', bk_ffta_resolve_exalto());
    if ($exalto !== '') {
        safe_w_sql("UPDATE BookingArchers SET BaExaltoId = " . StrSafe_DB($exalto)
            . " WHERE BaId = " . intval($archer->BaId));
    }
}

// Still unknown (licensee-space session expired): ask to sign in again.
if ($exalto === '') {
    bk_head(bk_t('LicCertTitle'));
    echo '<div class="bk-block"><h1>' . bk_e(bk_t('LicCertTitle')) . '</h1>'
        . '<p class="bk-hint">' . bk_t('LicCertRelog') . '</p>'
        . '<p><a class="bk-btn" href="' . bk_e(bk_public_url()) . '">' . bk_e(bk_t('BackMySpace')) . '</a></p></div>';
    bk_foot();
    exit;
}

$url = bk_ffta_attestation_url($exalto, $season);

/** Sends a certificate and ends the script. */
$serve = function ($pdf, $s) use ($archer) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="attestation-licence-'
        . preg_replace('/[^A-Za-z0-9]/', '', (string) $archer->BaLicence) . '-' . $s . '.pdf"');
    header('Content-Length: ' . strlen($pdf));   // bytes on purpose: an HTTP length
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
};

// 1) Relay through the kept cookie.
$res = bk_ffta_fetch_pdf($url);
if (!empty($res['pdf'])) $serve($res['pdf'], $season);

// No certificate for the new season at its start (licence not renewed yet): the federation
// answers an empty page. From 1 September to 15 October, the season before is shown instead.
if (!empty($res['missing']) && bk_ffta_previous_season_window()) {
    $prevUrl = bk_ffta_attestation_url($exalto, $season - 1);
    $prev = bk_ffta_fetch_pdf($prevUrl);
    if (!empty($prev['pdf'])) $serve($prev['pdf'], $season - 1);
    $url = $prevUrl;
}

// 2) Fallback: no cookie, or expired → the archer goes to their licensee space (and signs in there).
header('Location: ' . $url);
exit;
