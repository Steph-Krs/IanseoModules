<?php
/**
 * public/licence.php — attestation de licence FFTA (PDF de l'espace licencié).
 *
 * Deux voies, dans cet ordre (choix produit) :
 *  1) RELAIS via le cookie de session monespace CONSERVÉ au login (bk_ffta_fetch_pdf) —
 *     l'archer n'a rien à ressaisir. Lecture seule du cookie, jamais réécrit.
 *  2) REPLI (cookie absent ou session monespace expirée) : redirection vers l'URL
 *     directe de l'attestation → l'archer se connecte à SON espace licencié et l'obtient.
 *
 * L'id Exalto (dans l'URL …/pdf/p/{id}/{saison}) est capté à la connexion (BaExaltoId),
 * jamais saisi. Sans lui (compte connecté avant cette fonctionnalité), on invite à se
 * reconnecter.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/ffta.php';

$archer = bk_require_archer();
$exalto = preg_replace('/\D/', '', (string) $archer->BaExaltoId);
$season = bk_ffta_season();

// Id Exalto inconnu (compte connecté avant la fonctionnalité, ou charte déjà acceptée au
// login) : on tente de le résoudre À LA DEMANDE via le cookie conservé, et on le mémorise.
if ($exalto === '') {
    $exalto = preg_replace('/\D/', '', bk_ffta_resolve_exalto());
    if ($exalto !== '') {
        safe_w_sql("UPDATE BK_Archers SET BaExaltoId = " . StrSafe_DB($exalto)
            . " WHERE BaId = " . intval($archer->BaId));
    }
}

// Toujours introuvable (session monespace expirée) : on invite à se reconnecter.
if ($exalto === '') {
    bk_head('Attestation de licence');
    ?>
    <div class="bk-block">
      <h1>Attestation de licence</h1>
      <p class="bk-hint">Votre attestation n'est pas accessible pour l'instant. <b>Déconnectez-vous puis
         reconnectez-vous</b> : l'information nécessaire sera relue depuis votre espace licencié.</p>
      <p><a class="bk-btn" href="<?= bk_e(bk_public_url()) ?>">← Mon espace</a></p>
    </div>
    <?php
    bk_foot();
    exit;
}

$url = bk_ffta_attestation_url($exalto, $season);

// 1) Relais via le cookie conservé.
$res = bk_ffta_fetch_pdf($url);
if (!empty($res['pdf'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="attestation-licence-'
        . preg_replace('/[^A-Za-z0-9]/', '', (string) $archer->BaLicence) . '-' . $season . '.pdf"');
    header('Content-Length: ' . strlen($res['pdf']));
    header('X-Content-Type-Options: nosniff');
    echo $res['pdf'];
    exit;
}

// 2) Repli : cookie absent / expiré → l'archer va sur son espace licencié (il s'y connecte).
header('Location: ' . $url);
exit;
