<?php
/**
 * Passage du moteur, en ligne de commande ou par tâche planifiée.
 *
 * En usage normal, aucune tâche planifiée n'est nécessaire : api.php déclenche le
 * moteur (throttlé, sous verrou) à chaque requête de la face publique. Ce script
 * sert au diagnostic, aux tests, et aux serveurs où l'on préfère un rythme fixe.
 *
 *   php cron/poll.php            → compétition ouverte aux pronostics
 *   php cron/poll.php 190        → compétition explicite
 *   php cron/poll.php 190 force  → force le recalcul complet du modèle
 *   php cron/poll.php 190 loop   → boucle de 55 s (pour une tâche planifiée /minute)
 */
require_once dirname(__DIR__) . '/lib/engine.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    // Hors CLI, exiger un jeton partagé : ce point d'entrée ne doit pas être
    // déclenchable par n'importe qui depuis le réseau.
    $expected = trim((string) @file_get_contents(prono_root() . '/data/poll.key'));
    if ($expected === '' || (($_GET['key'] ?? '') !== $expected)) {
        http_response_code(403);
        exit("interdit\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$args  = $cli ? array_slice($argv, 1) : [];
$tid   = 0;
$force = false;
$loop  = false;
foreach ($args as $a) {
    if (ctype_digit($a))          $tid = (int) $a;
    elseif ($a === 'force')       $force = true;
    elseif ($a === 'loop')        $loop = true;
}
if (!$tid) $tid = (int) ($_GET['t'] ?? 0);
if (!$tid) $tid = prono_active_tournament();

if (!$tid) {
    echo "Aucune compétition ouverte aux pronostics.\n";
    exit(1);
}
if (!prono_tables_exist()) {
    echo "Tables PRONO_ absentes — ouvrir la console d'administration du module.\n";
    exit(1);
}

$deadline = $loop ? time() + 55 : 0;
do {
    $t0  = microtime(true);
    $res = prono_poll($tid, $force);
    $force = false;                       // le forçage ne vaut que pour le premier tour
    printf("[%s] tournoi %d : %s (%.2f s)\n",
        date('H:i:s'), $tid, json_encode($res, JSON_UNESCAPED_UNICODE), microtime(true) - $t0);
    if ($loop) sleep(5);
} while ($loop && time() < $deadline);
