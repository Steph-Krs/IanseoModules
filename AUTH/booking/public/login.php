<?php
/**
 * public/login.php — ENTRÉE UNIQUE.
 *
 * Cette page ne gère plus la connexion : elle REDIRIGE vers la page de connexion
 * UNIFIÉE du serveur (Custom/AUTH/login.php), onglet compétiteur (?p=comp). Une seule
 * porte d'entrée pour tout le serveur (organisateur + compétiteur), au lieu de deux —
 * ce qui referme le piège des deux pages de connexion (cf. le cookie d'attestation qui
 * n'était pas conservé parce que seule la page unifiée le stockait).
 *
 * Tous les anciens liens et redirections (bk_require_archer → 'login.php') aboutissent
 * donc à la page unifiée, qui traite le flux compétiteur (relais FFTA, MFA, conservation
 * du cookie de session) et renvoie vers l'espace licencié une fois connecté.
 */
require_once __DIR__ . '/boot.php';
global $CFG;
header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp');
exit;
