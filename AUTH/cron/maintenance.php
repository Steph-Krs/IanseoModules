<?php
/**
 * Module AUTH — fenêtre de maintenance nocturne, en UN seul script (CLI uniquement).
 *
 * Enchaîne, chaque étape n'étant lancée qu'une fois la précédente terminée :
 *
 *   maintenance ON → SAUVEGARDE → déverrouillage → MàJ cœur ianseo → MàJ des modules Custom
 *   → redéploiement AUTH → synchro licences → synchro logos → verrouillage
 *   → maintenance OFF
 *
 * INVARIANT NON NÉGOCIABLE : la maintenance est TOUJOURS coupée à la fin, y compris
 * si une étape échoue, si le script est interrompu (Ctrl-C, SIGTERM) ou s'il meurt sur
 * une erreur fatale — sans quoi le serveur resterait indéfiniment en page 503. D'où le
 * register_shutdown_function posé AVANT toute chose et les gestionnaires de signaux.
 *
 * Chaque étape est INDÉPENDANTE et désactivable ; l'échec de l'une n'empêche pas les
 * suivantes (une panne réseau chez la FFTA ne doit pas priver le serveur de sa MàJ de
 * module, ni laisser la maintenance active).
 *
 * Les étapes lourdes tournent en SOUS-PROCESSUS. Ce n'est pas un détail :
 *  - sync-licences.php / sync-logos.php / update-core.php se terminent par `exit()` —
 *    inclus en direct, ils tueraient l'orchestrateur avant la sortie de maintenance ;
 *  - après une MàJ du cœur, ianseo a effacé Modules/Authentication/ et remplacé des
 *    fichiers : un processus neuf recharge la bonne version du code ;
 *  - une erreur fatale dans l'un d'eux reste confinée.
 *
 * crontab (une seule ligne remplace celles des synchros) :
 *   15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php >> /var/log/ianseo-maintenance.log 2>&1
 *
 * config.local.json (les commandes sont propres au serveur ; une commande vide = étape
 * ignorée, ce qui rend le script inoffensif sur un poste de développement) :
 *   { "maintenance": {
 *       "on":     "sudo /usr/local/bin/ianseo-maintenance-on",
 *       "off":    "sudo /usr/local/bin/ianseo-maintenance-off",
 *       "unlock": "",   ← vides : déverrouillage fait par root dans la ligne cron
 *       "lock":   "",   ← (serveur/cron/ianseo-nightly), jamais en sudo pour www-data
 *       "steps":  { "core": false, "modules": true, "licences": true, "logos": true },
 *       "ping_url": ""   ← optional heartbeat (healthchecks.io…), see aut_backup_ping()
 *   },
 *   "backup": { "enabled": true, "dir": "/var/backups/ianseo", "keep_days": 14, … } }
 *   (détail : backup-lib.php ; réglable depuis admin/config.php)
 *
 * Options : --dry-run (n'exécute rien, affiche le plan), --core (force la MàJ cœur
 * pour cette exécution), --no-core, --no-backup, --only=backup,modules,licences,logos
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Script cron : exécution en ligne de commande uniquement.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');

@set_time_limit(0);
ini_set('memory_limit', '512M');

$T0 = microtime(true);
// Heure LOCALE : ianseo force PHP en UTC, et ce journal est relu à côté des lignes
// des scripts système (heure locale). Voir aut_log_time().
function mt_log($msg) { echo '[' . aut_log_time() . '] ' . $msg . "\n"; }
function mt_step($t)  { mt_log(''); mt_log('=== ' . $t . ' ==='); }

/* ------------------------------------------------------------------ */
/* Options                                                             */
/* ------------------------------------------------------------------ */
$args    = array_slice($argv, 1);
$argsStr = implode(' ', $args);
$dryRun  = in_array('--dry-run', $args, true);
$only    = preg_match('/--only=([a-z,]+)/i', $argsStr, $m) ? array_filter(explode(',', strtolower($m[1]))) : null;

$cfg   = aut_local_config()['maintenance'] ?? array();
$steps = is_array($cfg['steps'] ?? null) ? $cfg['steps'] : array();

/** Une étape est-elle demandée ? (--only prime, puis la config, puis le défaut) */
function mt_want($name, $default) {
    global $only, $steps;
    if ($only !== null) return in_array($name, $only, true);
    return array_key_exists($name, $steps) ? !empty($steps[$name]) : $default;
}

// La MàJ du CŒUR est désactivée par défaut : elle réécrit des fichiers de ianseo et
// applique des migrations de base, sans retour arrière. À n'activer qu'avec des
// sauvegardes en place (voir SERVEUR.md).
$doCore     = mt_want('core', false);
if (in_array('--core', $args, true))    $doCore = true;
if (in_array('--no-core', $args, true)) $doCore = false;
// Sauvegarde : pilotée par config.local.json → backup.enabled (défaut : oui), et non
// par steps — c'est une fonction à part entière, réglable depuis admin/config.php.
require_once(dirname(__DIR__) . '/backup-lib.php');
$bkCfg      = aut_backup_config();
$doBackup   = $bkCfg['enabled'] && ($only === null || in_array('backup', $only, true));
if (in_array('--no-backup', $args, true)) $doBackup = false;
$doModules  = mt_want('modules',  true);
$doLicences = mt_want('licences', true);
$doLogos    = mt_want('logos',    true);

/* ------------------------------------------------------------------ */
/* Verrou : jamais deux fenêtres de maintenance à la fois              */
/* ------------------------------------------------------------------ */
$lock = fopen(__DIR__ . '/.maintenance.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    mt_log('ERREUR : une fenêtre de maintenance est déjà en cours.');
    exit(1);
}

/* ------------------------------------------------------------------ */
/* Sortie de maintenance GARANTIE                                      */
/* ------------------------------------------------------------------ */
$GLOBALS['MT_ON'] = false;   // la maintenance a-t-elle été activée PAR NOUS ?

function mt_exec($cmd, $label) {
    global $dryRun;
    $cmd = trim((string) $cmd);
    if ($cmd === '') { mt_log("  ($label : aucune commande configurée — ignoré)"); return true; }
    if ($dryRun)     { mt_log("  [dry-run] $label : $cmd"); return true; }
    $out = array(); $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    foreach ($out as $l) mt_log('    | ' . $l);
    mt_log('  ' . $label . ' : ' . ($rc === 0 ? 'ok' : "ÉCHEC (code $rc)"));
    return $rc === 0;
}

function mt_maintenance_off() {
    if (empty($GLOBALS['MT_ON'])) return;
    $GLOBALS['MT_ON'] = false;
    $cfg = aut_local_config()['maintenance'] ?? array();
    mt_log('Sortie du mode maintenance.');
    mt_exec($cfg['off'] ?? '', 'maintenance OFF');
}

// Posé AVANT toute action : couvre l'erreur fatale, le die() et la fin normale.
register_shutdown_function(function () {
    if (!empty($GLOBALS['MT_ON'])) {
        mt_log('!! Fin inattendue du script — sortie de maintenance de sécurité.');
        mt_maintenance_off();
    }
});
// Interruptions (Ctrl-C, arrêt du service) : même garantie.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach (array(SIGINT, SIGTERM, SIGHUP) as $sig) {
        @pcntl_signal($sig, function ($s) { mt_log("!! Signal $s reçu."); mt_maintenance_off(); exit(1); });
    }
}

/**
 * Runs a module PHP script in the BACKGROUND, detached, its output appended to the same
 * log as this script. Used for the off-site copy of the backups, which can take far
 * longer than the maintenance itself (17 min for 125 MB on the first server): it must
 * neither keep the site closed nor delay the re-locking of the files by root (the cron
 * line locks as soon as this script ends). Returns false when detaching is impossible
 * (not Linux, output not redirected to a file — manual run in a terminal): the caller
 * then uploads in the foreground.
 */
function mt_php_detached($script, $args = '') {
    global $dryRun;
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
    if ($dryRun) { mt_log('  [dry-run] en arrière-plan : ' . $cmd); return true; }
    if (DIRECTORY_SEPARATOR !== '/') return false;
    $log = @readlink('/proc/self/fd/1');   // the file cron redirected this script's output to
    if ($log === false || $log === '' || $log[0] !== '/' || !is_file($log) || !is_writable($log)) return false;
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
    exec($setsid . 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &');
    return true;
}

/** Lance un script PHP du module dans un processus NEUF. $rc reçoit son code de sortie. */
function mt_php($script, $args = '', &$rc = 0) {
    global $dryRun;
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
    if ($dryRun) { mt_log('  [dry-run] ' . $cmd); $rc = 0; return true; }
    $out = array(); $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    // Les avertissements « libpng warning: iCCP … » viennent de la bibliothèque C
    // (profils ICC légèrement malformés dans les logos), sont totalement inoffensifs
    // et peuvent représenter des dizaines de lignes par nuit : on les compte au lieu
    // de les recopier, pour que le journal reste lisible. Tout le reste est conservé.
    $bruit = 0;
    foreach ($out as $l) {
        if (stripos(ltrim($l), 'libpng warning:') === 0) { $bruit++; continue; }
        mt_log('  | ' . $l);
    }
    if ($bruit) mt_log("  | ($bruit avertissement(s) libpng ignoré(s) — profils ICC des logos, sans conséquence)");
    return $rc === 0;
}

/* ================================================================== */
/* Déroulé                                                             */
/* ================================================================== */
mt_log('Fenêtre de maintenance — début' . ($dryRun ? ' [DRY-RUN]' : ''));
mt_log('Étapes : sauvegarde=' . ($doBackup ? 'oui' : 'non') . ', cœur=' . ($doCore ? 'oui' : 'non') . ', modules=' . ($doModules ? 'oui' : 'non')
    . ', licences=' . ($doLicences ? 'oui' : 'non') . ', logos=' . ($doLogos ? 'oui' : 'non'));

$echecs = array();

/* ---- 1. Maintenance ON ---- */
mt_step('1/8 Mise en maintenance');
if (trim((string) ($cfg['on'] ?? '')) !== '' && !$dryRun) $GLOBALS['MT_ON'] = true;
if (!mt_exec($cfg['on'] ?? '', 'maintenance ON')) {
    // Si l'activation échoue, ne pas enchaîner des MàJ sur un serveur ouvert au public.
    $GLOBALS['MT_ON'] = false;
    mt_log('ARRÊT : impossible d\'activer le mode maintenance — aucune mise à jour lancée.');
    aut_log('MAINT_FAIL', 'cron: maintenance ON', 'cli');
    if (!$dryRun) aut_backup_ping(true);
    exit(1);
}

/* ---- 2. Sauvegarde base + fichiers ---- */
// Site fermé → copie cohérente. Prise AVANT la MàJ du cœur : c'est elle qui permet
// de revenir en arrière si une migration de base tourne mal (aucun retour arrière
// n'existe dans ianseo). Pas de sauvegarde locale ⇒ pas de MàJ du cœur cette nuit
// (réglable : backup.required_for_core). Un échec de la seule copie EN LIGNE ne
// bloque rien : la copie locale suffit à revenir en arrière.
$backupOk = false;
if ($doBackup) {
    mt_step('2/8 Sauvegarde de la base et des fichiers');
    $bkRc = 1;
    // LOCAL copy only: the off-site upload waits until the site has reopened (end of script).
    mt_php(__DIR__ . '/backup.php', '--local', $bkRc);
    $backupOk = ($bkRc === 0);
    if ($backupOk) mt_log('  sauvegarde locale : ok');
    else           { $echecs[] = 'sauvegarde'; mt_log('  sauvegarde : ÉCHEC' . ($bkRc !== 1 ? " (code $bkRc)" : '')); }
}
if ($doCore && !$dryRun && !$backupOk && $bkCfg['required_for_core']) {
    $doCore = false;
    $echecs[] = 'cœur (non lancé)';
    mt_log('');
    mt_log('!! MàJ du cœur NON lancée cette nuit : pas de sauvegarde valide pour revenir en arrière.'
        . ($bkCfg['enabled'] ? '' : ' (sauvegarde désactivée)')
        . ' Réglage : config.local.json → backup.required_for_core.');
}

/* ---- 3. Déverrouillage des fichiers (nécessaire à la MàJ du cœur) ---- */
if ($doCore) {
    mt_step('3/8 Déverrouillage des fichiers');
    if (!mt_exec($cfg['unlock'] ?? '', 'unlock')) {
        // Fichiers du cœur toujours en lecture seule : la MàJ échouerait à coup sûr
        // (« … must be writable by the server »). Rien n'ayant été déverrouillé, le
        // reverrouillage final est sauté aussi.
        $echecs[] = 'unlock';
        $doCore = false;
        mt_log('!! MàJ du cœur NON lancée : les fichiers n\'ont pas pu être déverrouillés.'
            . ' maintenance.unlock doit rester vide : le déverrouillage se fait par root dans /etc/cron.d/ianseo-nightly.');
    }
}

/* ---- 4. Mise à jour du cœur ianseo ---- */
if ($doCore) {
    mt_step('4/8 Mise à jour du cœur ianseo');
    $statusFile = HTDOCS . '/TV/Photos/updating.json';
    @unlink($statusFile);
    mt_php(__DIR__ . '/update-core.php');
    // Le cœur sort par un exit(0) même en erreur : le verdict est dans le fichier d'état.
    if (!$dryRun) {
        $d = is_file($statusFile) ? @json_decode((string) @file_get_contents($statusFile)) : null;
        // "Already up to date": ianseo.net answers "NothingToDo" and the core files it as
        // error=1 — yet it is the NORMAL outcome of almost every night. Counting it as a
        // failure raised a false alarm every night (seen for real on 2026-09-29).
        // Recognised by the text of the language key, in the current language, with the
        // English wording as a fallback.
        $coreMsg     = trim(strip_tags((string) ($d->msg ?? '')));
        $nothingToDo = function_exists('get_text') ? trim(strip_tags((string) get_text('NothingToDo', 'Install'))) : '';
        $upToDate    = $d && $coreMsg !== ''
            && (($nothingToDo !== '' && $coreMsg === $nothingToDo) || stripos($coreMsg, 'is up to date') !== false);
        if ($upToDate) {
            mt_log('  MàJ cœur : déjà à jour');
        } elseif (!$d || !empty($d->error) || empty($d->finished)) {
            $echecs[] = 'cœur';
            mt_log('  MàJ cœur : ÉCHEC ou inachevée' . ($d && !empty($d->msg) ? ' — ' . strip_tags((string) $d->msg) : ''));
        } else {
            mt_log('  MàJ cœur : ok');
        }
    }
}

/* ---- 5. Mise à jour des modules Custom ---- */
if ($doModules) {
    mt_step('5/8 Mise à jour des modules');
    $shared = HTDOCS . '/Modules/Custom/_shared/update-lib.php';
    if (!is_file($shared)) {
        mt_log('  _shared/update-lib.php absent — étape ignorée.');
    } else {
        require_once $shared;
        // Un module géré = un dossier de Custom/ contenant module.json (invariant du standard).
        foreach (glob(HTDOCS . '/Modules/Custom/*/module.json') as $mj) {
            $dir  = dirname($mj);
            $name = basename($dir);
            $mcfg = upd_load_config($dir);
            $loc  = upd_local_version($dir);
            $rem  = upd_remote_version($mcfg);
            if (!empty($rem['_error'])) {
                mt_log("  $name : impossible de lire la version distante (" . $rem['_error'] . ')');
                $echecs[] = "module:$name";
                continue;
            }
            $lv = $loc['version'] ?? '0';
            $rv = $rem['version'];
            if (upd_compare($lv, $rv) !== 'update') { mt_log("  $name : à jour (v$lv)"); continue; }
            mt_log("  $name : v$lv → v$rv" . ($dryRun ? ' [dry-run]' : ''));
            if ($dryRun) continue;
            $r = upd_sync_files($mcfg, $dir, $rem['files'] ?? array());
            upd_sync_shared($mcfg);   // la bibliothèque commune suit chaque MàJ
            mt_log("    {$r['ok']} fichier(s) mis à jour" . ($r['fail'] ? ', ÉCHECS : ' . implode(', ', $r['fail']) : ''));
            if ($r['fail']) $echecs[] = "module:$name";
        }
    }
}

/* ---- 6. Redéploiement de l'authentification ---- */
// Une MàJ du cœur efface Modules/Authentication/, et une MàJ du module AUTH peut
// modifier dist/. L'auto-réparation le referait à la première requête web, mais
// autant repartir d'un serveur cohérent avant les synchros.
if (($doCore || $doModules) && !$dryRun) {
    mt_step('6/8 Redéploiement de l\'authentification');
    if (function_exists('aut_dist_status') && function_exists('aut_deploy')) {
        $st = aut_dist_status();
        if (!$st['deployed'] || $st['drift']) {
            $errs = array();
            $ok = aut_deploy($errs);
            mt_log('  redéploiement : ' . ($ok ? 'ok' : 'ÉCHEC — ' . implode(' ; ', $errs)));
            if (!$ok) $echecs[] = 'deploy';
        } else {
            mt_log('  fichiers déployés déjà conformes.');
        }
    }
}

/* ---- 7. Synchros (licences puis logos) ---- */
if ($doLicences) {
    mt_step('7a/8 Synchronisation des licences');
    if (!mt_php(__DIR__ . '/sync-licences.php')) { $echecs[] = 'licences'; mt_log('  licences : ÉCHEC'); }
    else mt_log('  licences : ok');
}
if ($doLogos) {
    // Volontairement APRÈS les licences : la liste des clubs en est déduite.
    mt_step('7b/8 Synchronisation des logos de club');
    if (!mt_php(__DIR__ . '/sync-logos.php')) { $echecs[] = 'logos'; mt_log('  logos : ÉCHEC'); }
    else mt_log('  logos : ok');
}

/* ---- 8. Reverrouillage + sortie de maintenance ---- */
if ($doCore) {
    mt_step('8/8 Reverrouillage des fichiers');
    if (!mt_exec($cfg['lock'] ?? '', 'lock')) $echecs[] = 'lock';
}

mt_step('Sortie de maintenance');
mt_maintenance_off();

/* ---- Site reopened: off-site copy of the backups ---- */
if ($doBackup && ($backupOk || $dryRun) && $bkCfg['remote'] !== '') {
    mt_step('Copie en ligne des sauvegardes (site rouvert)');
    if (mt_php_detached(__DIR__ . '/backup.php', '--upload')) {
        if (!$dryRun) mt_log('  lancée en arrière-plan : son résultat s\'ajoute à la suite de ce journal (« Copie en ligne : … »).');
    } else {
        $upRc = 0;
        mt_php(__DIR__ . '/backup.php', '--upload', $upRc);
        if ($upRc === 0) mt_log('  copie en ligne : ok');
        else { $echecs[] = 'sauvegarde en ligne'; mt_log('  copie en ligne : ÉCHEC' . ($upRc !== 2 ? " (code $upRc)" : '')); }
    }
}

$duree = round(microtime(true) - $T0);
// The failed steps travel in the journal's user column (64 bytes, cut on a character
// boundary): the administrator banner (menu.php) names them without reading this log.
// A dry run is not a night: it must not tell the banner that the nightly job works.
if ($echecs) {
    mt_log('Terminé en ' . $duree . ' s — ÉCHECS : ' . implode(', ', $echecs));
    if (!$dryRun) {
        aut_log('MAINT_PARTIAL', mb_strcut('cron: ' . implode(', ', $echecs), 0, 64, 'UTF-8'), 'cli');
        aut_backup_ping(true);
    }
    exit(1);
}
mt_log('Terminé en ' . $duree . ' s — tout est ok.');
if (!$dryRun) {
    aut_log('MAINT_OK', 'cron', 'cli');
    aut_backup_ping(false);
}
