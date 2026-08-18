<?php
/**
 * public/boot.php — amorçage de l'espace licencié (face publique).
 *
 * $SKIP_AUTH court-circuite le bootstrap d'authentification ORGANISATEUR
 * (config.php:124) : ces pages doivent rester accessibles à un archer anonyme,
 * que le module AUTH soit installé ou non — aucune liste blanche à maintenir
 * ailleurs, donc aucune dépendance entre les deux modules. Mécanisme du cœur
 * ianseo, déjà utilisé par Api/ISK-NG.
 *
 * Contrepartie assumée : ces pages n'ont AUCUNE ACL ianseo. Toute lecture ou
 * écriture doit donc être gardée explicitement par bk_current_archer() et
 * bk_csrf_check(), et bornée à ce que le licencié connecté a le droit de voir.
 */

$SKIP_AUTH = 1;

define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/archer.php';
require_once dirname(__DIR__) . '/lib/ui.php';

bk_schema();
