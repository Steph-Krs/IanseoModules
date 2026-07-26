<?php
/** ajax/ordre-clubs.php — enregistre l'ordre manuel des clubs d'une épreuve. */
define('HTDOCS', dirname(__DIR__, 4));
require_once dirname(__DIR__) . '/lib/boot.php';
rep_check_token();

$action = $_POST['action'] ?? '';

if ($action === 'enregistrer') {
    $event = (string) ($_POST['event'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $event)) {
        JsonOut(['ok' => false, 'err' => 'Épreuve invalide.']);
    }
    $codes = $_POST['codes'] ?? '';
    $codes = array_filter(array_map('trim', explode(',', (string) $codes)));
    $n = rep_ordre_clubs_ecrire($REP_TOUR, $event, $codes);
    JsonOut(['ok' => true, 'enregistres' => $n]);
}

if ($action === 'reset') {
    $event = (string) ($_POST['event'] ?? '');
    safe_w_sql("DELETE FROM REP_OrdreClub WHERE OoTournament=$REP_TOUR
        AND OoEvent=" . StrSafe_DB($event));
    JsonOut(['ok' => true]);
}

JsonOut(['ok' => false, 'err' => 'Action inconnue.']);
