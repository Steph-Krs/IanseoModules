<?php
/**
 * Module AUTH — Synchronisation des LOGOS DE CLUB par cron (CLI uniquement).
 *
 * Objectif : plus aucune manipulation côté organisateur. Nativement, chacun doit
 * ouvrir « Participants › Charger table de correspondance » et cocher « Drapeaux »
 * pour SA compétition ; ici, un seul passage quotidien alimente un cache global puis
 * le recopie dans toutes les compétitions en cours — les impressions du cœur
 * (dossards, badges, listes) trouvent les logos sans que personne n'ait rien fait.
 *
 * Deux étapes, volontairement séparées (voir logos-lib.php) :
 *   1. TÉLÉCHARGEMENT (réseau) : un logo par agrément dans AUT_ClubLogos ;
 *   2. PROPAGATION (local) : cache → table Flags + fichiers TV/Photos/{ToCode}-Fl-*.jpg
 *      pour chaque compétition non terminée.
 * Une panne réseau n'empêche donc jamais la propagation de ce qui est déjà en cache.
 *
 * crontab (tous les jours à 04h15, après la synchro des licences de 03h15) :
 *   15 4 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-logos.php >> /var/log/ianseo-logosync.log 2>&1
 *
 * Options :
 *   --propagate-only   n'effectue que l'étape 2 (aucun accès réseau)
 *   --full             retélécharge tout, même les logos déjà rafraîchis aujourd'hui
 *   --limit=N          s'arrête après N téléchargements (mise au point)
 *
 * config.local.json (facultatif) :
 *   { "logos": { "enabled": true, "delay_ms": 120, "refresh_days": 0, "timeout": 15 } }
 *   « url » et « ioc » sont déduits de ianseo (LookUpPaths) si absents.
 *
 * CHANGEMENT DE LOGO : l'endpoint FFTA ne renvoie ni Last-Modified ni ETag (vérifié),
 * donc aucune requête conditionnelle n'est possible — il faut télécharger pour comparer.
 * D'où refresh_days = 0 par défaut (tout retélécharger). Le logo n'est réécrit que s'il
 * a VRAIMENT changé : comparaison d'empreinte md5 en cache (ClgHash) puis, à la
 * propagation, entre le fichier posé et le cache. Un logo modifié se répercute donc
 * automatiquement sur toutes les compétitions non terminées qui l'utilisent.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Script cron : exécution en ligne de commande uniquement.');
}

$SKIP_AUTH = 1;   // pas de bootstrap web en CLI
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/logos-lib.php');

ini_set('memory_limit', '512M');
@set_time_limit(0);

// Heure LOCALE (ianseo force PHP en UTC) — voir aut_log_time().
function lg_log($msg) { echo '[' . aut_log_time() . '] ' . $msg . "\n"; }
function lg_fail($msg) {
    lg_log('ERREUR : ' . $msg);
    aut_log('LOGOSYNC_FAIL', 'cron', 'cli');
    exit(1);
}

/* ---- Options ---- */
$argvAll        = implode(' ', array_slice($argv, 1));
$propagateOnly  = strpos($argvAll, '--propagate-only') !== false;
$full           = strpos($argvAll, '--full') !== false;
$limit          = preg_match('/--limit=(\d+)/', $argvAll, $m) ? intval($m[1]) : 0;

/* ---- Verrou anti-double-exécution (fichier distinct de la synchro licences) ---- */
$lock = fopen(__DIR__ . '/.logos.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    lg_fail('une synchronisation des logos est déjà en cours.');
}

if (!aut_logos_enabled()) {
    lg_log('Synchronisation des logos désactivée (config.local.json → logos.enabled = false).');
    exit(0);
}

aut_logos_schema();
$cfg     = aut_logos_config();
$delayMs = max(0, intval($cfg['delay_ms'] ?? 120));
// refresh_days = 0 (DÉFAUT) : tout retélécharger à chaque passage.
//
// C'est le seul réglage qui détecte un CHANGEMENT de logo : l'endpoint FFTA ne renvoie
// ni Last-Modified ni ETag (vérifié), donc aucune requête conditionnelle n'est possible
// — il faut télécharger pour comparer. Le coût est modeste (~1600 logos, ~50 Mo, ~7 min
// une fois par nuit) et seuls les logos réellement MODIFIÉS sont réécrits ensuite.
//
// N > 0 : ne reprendre que ce qui date de plus de N jours (économie de bande passante,
// au prix d'un délai de détection). ⚠️ Ne PAS mettre 1 avec un cron quotidien : le
// club téléchargé quelques minutes après le début de la passe précédente serait jugé
// « frais » à la passe suivante et sauté une nuit sur deux. Utiliser 0, ou 2 et plus.
$days    = max(0, intval($cfg['refresh_days'] ?? 0));
$timeout = max(3, intval($cfg['timeout'] ?? 15));

/* ================================================================= */
/* Étape 1 — téléchargement des logos manquants ou périmés            */
/* ================================================================= */
$dl = array('ok' => 0, 'same' => 0, 'none' => 0, 'fail' => 0);

if (!$propagateOnly) {
    lg_log('Source : ' . aut_logos_url());
    $codes = aut_logos_club_codes();
    lg_log(count($codes) . ' agrément(s) de club connus (fichier fédéral + compétitions).');

    // Par défaut (refresh_days = 0) : TOUT est retéléchargé, seule façon de repérer un
    // logo modifié. Le tri par ancienneté n'existe que si l'exploitant l'active.
    $todo = $codes;
    if (!$full && $days > 0) {
        $frais = array();
        $rs = safe_r_sql("SELECT ClgCode FROM AUT_ClubLogos
            WHERE ClgFetched IS NOT NULL AND ClgFetched > DATE_SUB(NOW(), INTERVAL $days DAY)", false, true);
        while ($rs && ($r = safe_fetch($rs))) $frais[trim($r->ClgCode)] = true;
        $todo = array_values(array_filter($codes, function ($c) use ($frais) { return empty($frais[$c]); }));
        lg_log(count($todo) . ' à rafraîchir (les autres datent de moins de ' . $days . ' j).');
    } else {
        lg_log('Tous seront retéléchargés — seul moyen de repérer un logo MODIFIÉ, '
            . 'la FFTA ne renvoyant ni Last-Modified ni ETag.');
    }

    $n = 0;
    foreach ($todo as $code) {
        if ($limit && $n >= $limit) { lg_log('Limite --limit=' . $limit . ' atteinte.'); break; }
        $r = aut_logos_fetch_one($code, $timeout);
        $dl[$r] = ($dl[$r] ?? 0) + 1;
        $n++;
        if ($n % 100 === 0) {
            lg_log("  … $n/" . count($todo) . " (nouveaux/màj {$dl['ok']}, inchangés {$dl['same']}, sans logo {$dl['none']}, échecs {$dl['fail']})");
        }
        if ($delayMs > 0) usleep($delayMs * 1000);   // rester poli avec le serveur fédéral
    }
    lg_log("Téléchargement terminé : {$dl['ok']} nouveaux/mis à jour, {$dl['same']} inchangés, "
        . "{$dl['none']} sans logo, {$dl['fail']} échecs.");

    // Un échec TOTAL est anormal (réseau coupé, endpoint déplacé) : on le signale,
    // mais on enchaîne quand même la propagation de ce qui est déjà en cache.
    if ($n > 0 && $dl['fail'] === $n) {
        lg_log('ATTENTION : aucun téléchargement n\'a abouti — vérifiez l\'accès à ' . aut_logos_url());
    }
}

/* ================================================================= */
/* Étape 2 — propagation locale vers les compétitions non terminées   */
/* ================================================================= */
$tours = aut_logos_active_tournaments();
lg_log(count($tours) . ' compétition(s) non terminée(s) à alimenter.');
$tot = array('ecrits' => 0, 'deja' => 0, 'absents' => 0, 'echecs' => 0);
$dirPhotos = $CFG->DOCUMENT_PATH . 'TV/Photos';
if (!is_writable($dirPhotos)) {
    lg_log('ATTENTION : ' . $dirPhotos . ' n\'est PAS accessible en écriture par ce compte — '
        . 'les logos ne pourront pas être posés (ianseo en a lui aussi besoin : drapeaux, '
        . 'photos, badges, fichier d\'état des mises à jour).');
}
foreach ($tours as $tid) {
    $r = aut_logos_sync_tournament($tid);
    foreach ($r as $k => $v) $tot[$k] = ($tot[$k] ?? 0) + $v;
    if ($r['ecrits']) lg_log("  compétition $tid : {$r['ecrits']} logo(s) posé(s).");
    if ($r['echecs']) lg_log("  compétition $tid : {$r['echecs']} ÉCHEC(S) d'écriture.");
}
lg_log("Propagation terminée : {$tot['ecrits']} fichier(s) écrit(s), {$tot['deja']} déjà à jour, "
    . "{$tot['absents']} club(s) sans logo en cache"
    . ($tot['echecs'] ? ", {$tot['echecs']} ÉCHEC(S) d'écriture (permissions de TV/Photos ?)" : '') . '.');
if ($tot['echecs']) {
    aut_log('LOGOSYNC_FAIL', 'cron', 'cli');
    lg_log('Terminé AVEC DES ÉCHECS.');
    exit(1);
}

$st = aut_logos_stats();
lg_log('Cache : ' . $st['avec'] . ' logos / ' . $st['total'] . ' clubs connus ('
    . $st['sans'] . ' sans logo côté FFTA, ' . round($st['octets'] / 1048576, 1) . ' Mo).');

aut_log('LOGOSYNC_OK', 'cron', 'cli');
lg_log('Terminé.');
