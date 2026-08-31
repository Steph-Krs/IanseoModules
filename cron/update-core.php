<?php
/**
 * Module AUTH — mise à jour du CŒUR ianseo, sans navigateur (CLI uniquement).
 *
 * Rejoue exactement ce que fait la page /Update/ : elle n'est qu'une interface qui
 * appelle `Update/index-action.php` en AJAX ; le travail réel vit dans
 * `Update/UpdateIanseo.php`, lequel ne porte AUCUN contrôle d'accès (l'ACL est dans
 * index-action.php). On peut donc l'exécuter ici — ce qui lève le blocage identifié
 * côté serveur : automatiser /Update/ en HTTP supposerait de scripter une connexion
 * ADMIN + code TOTP, donc de stocker le secret 2FA en clair, ce qui annulerait
 * l'intérêt de la 2FA. En CLI, l'autorisation est celle du compte système, déjà au
 * moins aussi privilégiée qu'une session administrateur.
 *
 * Ce que fait la mise à jour : télécharge le différentiel depuis ianseo.net, écrit /
 * supprime les fichiers du cœur, rafraîchit les paquets de langue, puis déclenche les
 * migrations de base (`updateChkUp()` → `Common/UpdateDb-check.php`).
 *
 * ⚠️ Script SÉPARÉ, appelé en SOUS-PROCESSUS par cron/maintenance.php : UpdateIanseo.php
 * se termine par un `JsonOut()` qui fait `exit` — inclus en direct, il tuerait
 * l'orchestrateur avant la sortie du mode maintenance.
 *
 * Le résultat n'est pas rendu par le code de sortie (le `exit` du cœur vaut 0) mais
 * par le fichier d'état TV/Photos/updating.json, que l'appelant relit (clés
 * « error » et « finished »).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Script cron : exécution en ligne de commande uniquement.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));

// DIRNAME : ce que index-action.php définit comme le dossier du script appelant.
// UpdateIanseo.php en dérive le chemin du fichier d'état (dirname(DIRNAME).'/TV/Photos').
define('DIRNAME', HTDOCS . DIRECTORY_SEPARATOR . 'Update');

require_once(HTDOCS . '/config.php');

// UpdateIanseo.php fait des include RELATIFS (« FileList.php », « Language/lib.php »)
// qui, en web, se résolvent depuis le dossier du script. En CLI il faut s'y placer.
chdir(DIRNAME);

$statusFile = HTDOCS . '/TV/Photos/updating.json';

/** Même implémentation que Update/index-action.php (écriture atomique). */
if (!function_exists('writeStatusFile')) {
    function writeStatusFile($file, $data) {
        file_put_contents($file . '.tmp', json_encode($data));
        rename($file . '.tmp', $file);
    }
}

function uc_out($msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; }

// Une mise à jour déjà en cours ? (même garde que l'interface, sauf --force)
$force = in_array('--force', array_slice($argv, 1), true);
if (!$force && is_file($statusFile)) {
    $d = @json_decode((string) @file_get_contents($statusFile));
    if ($d && empty($d->finished)) {
        uc_out('ERREUR : une mise à jour est déjà en cours depuis ' . ($d->start ?? '?')
            . ' (utiliser --force pour passer outre).');
        exit(1);
    }
}

if (!is_writable(dirname($statusFile))) {
    uc_out('ERREUR : ' . dirname($statusFile) . ' n\'est pas accessible en écriture.');
    exit(1);
}
if (!is_writable(HTDOCS)) {
    uc_out('ERREUR : ' . HTDOCS . ' n\'est pas accessible en écriture — déverrouillez les '
        . 'fichiers du cœur avant la mise à jour (ianseo-unlock).');
    exit(1);
}

// État initial, exactement comme l'action « getFile » de l'interface.
$JSON = array('error' => 0, 'msg' => '', 'start' => date('Y-m-d H:i:s'), 'status' => '', 'finished' => 0);
writeStatusFile($statusFile, $JSON);

uc_out('Mise à jour du cœur ianseo (' . (defined('ProgramRelease') ? ProgramRelease : '?') . ') depuis ' . $CFG->IanseoServer . '…');

$IN_PHP = true;                       // exigé par UpdateIanseo.php
require_once DIRNAME . '/UpdateIanseo.php';   // se termine par JsonOut() → exit
