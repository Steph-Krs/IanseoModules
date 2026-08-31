<?php
/**
 * Module AUTH — fenêtre de maintenance nocturne, en UN seul script (CLI uniquement).
 *
 * Enchaîne, chaque étape n'étant lancée qu'une fois la précédente terminée :
 *
 *   maintenance ON → déverrouillage → MàJ cœur ianseo → MàJ des modules Custom
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
 *       "unlock": "sudo /usr/local/bin/ianseo-unlock",
 *       "lock":   "sudo /usr/local/bin/ianseo-lock",
 *       "steps":  { "core": false, "modules": true, "licences": true, "logos": true }
 *   } }
 *
 * Options : --dry-run (n'exécute rien, affiche le plan), --core (force la MàJ cœur
 * pour cette exécution), --no-core, --only=modules,licences,logos
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

/** Lance un script PHP du module dans un processus NEUF. */
function mt_php($script, $args = '') {
    global $dryRun;
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
    if ($dryRun) { mt_log('  [dry-run] ' . $cmd); return true; }
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
mt_log('Étapes : cœur=' . ($doCore ? 'oui' : 'non') . ', modules=' . ($doModules ? 'oui' : 'non')
    . ', licences=' . ($doLicences ? 'oui' : 'non') . ', logos=' . ($doLogos ? 'oui' : 'non'));

$echecs = array();

/* ---- 1. Maintenance ON ---- */
mt_step('1/7 Mise en maintenance');
if (trim((string) ($cfg['on'] ?? '')) !== '' && !$dryRun) $GLOBALS['MT_ON'] = true;
if (!mt_exec($cfg['on'] ?? '', 'maintenance ON')) {
    // Si l'activation échoue, ne pas enchaîner des MàJ sur un serveur ouvert au public.
    $GLOBALS['MT_ON'] = false;
    mt_log('ARRÊT : impossible d\'activer le mode maintenance — aucune mise à jour lancée.');
    aut_log('MAINT_FAIL', 'cron', 'cli');
    exit(1);
}

/* ---- 2. Déverrouillage des fichiers (nécessaire à la MàJ du cœur) ---- */
if ($doCore) {
    mt_step('2/7 Déverrouillage des fichiers');
    if (!mt_exec($cfg['unlock'] ?? '', 'unlock')) $echecs[] = 'unlock';
}

/* ---- 3. Mise à jour du cœur ianseo ---- */
if ($doCore) {
    mt_step('3/7 Mise à jour du cœur ianseo');
    $statusFile = HTDOCS . '/TV/Photos/updating.json';
    @unlink($statusFile);
    mt_php(__DIR__ . '/update-core.php');
    // Le cœur sort par un exit(0) même en erreur : le verdict est dans le fichier d'état.
    if (!$dryRun) {
        $d = is_file($statusFile) ? @json_decode((string) @file_get_contents($statusFile)) : null;
        if (!$d || !empty($d->error) || empty($d->finished)) {
            $echecs[] = 'cœur';
            mt_log('  MàJ cœur : ÉCHEC ou inachevée' . ($d && !empty($d->msg) ? ' — ' . strip_tags((string) $d->msg) : ''));
        } else {
            mt_log('  MàJ cœur : ok');
        }
    }
}

/* ---- 4. Mise à jour des modules Custom ---- */
if ($doModules) {
    mt_step('4/7 Mise à jour des modules');
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

/* ---- 5. Redéploiement de l'authentification ---- */
// Une MàJ du cœur efface Modules/Authentication/, et une MàJ du module AUTH peut
// modifier dist/. L'auto-réparation le referait à la première requête web, mais
// autant repartir d'un serveur cohérent avant les synchros.
if (($doCore || $doModules) && !$dryRun) {
    mt_step('5/7 Redéploiement de l\'authentification');
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

/* ---- 6. Synchros (licences puis logos) ---- */
if ($doLicences) {
    mt_step('6a/7 Synchronisation des licences');
    if (!mt_php(__DIR__ . '/sync-licences.php')) { $echecs[] = 'licences'; mt_log('  licences : ÉCHEC'); }
    else mt_log('  licences : ok');
}
if ($doLogos) {
    // Volontairement APRÈS les licences : la liste des clubs en est déduite.
    mt_step('6b/7 Synchronisation des logos de club');
    if (!mt_php(__DIR__ . '/sync-logos.php')) { $echecs[] = 'logos'; mt_log('  logos : ÉCHEC'); }
    else mt_log('  logos : ok');
}

/* ---- 7. Reverrouillage + sortie de maintenance ---- */
if ($doCore) {
    mt_step('7/7 Reverrouillage des fichiers');
    if (!mt_exec($cfg['lock'] ?? '', 'lock')) $echecs[] = 'lock';
}

mt_step('Sortie de maintenance');
mt_maintenance_off();

$duree = round(microtime(true) - $T0);
if ($echecs) {
    mt_log('Terminé en ' . $duree . ' s — ÉCHECS : ' . implode(', ', $echecs));
    aut_log('MAINT_PARTIAL', 'cron', 'cli');
    exit(1);
}
mt_log('Terminé en ' . $duree . ' s — tout est ok.');
aut_log('MAINT_OK', 'cron', 'cli');
