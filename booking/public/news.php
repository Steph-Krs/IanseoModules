<?php
/**
 * public/news.php — endpoint JSON des actualités FFTA (flux RSS mis en cache).
 *
 * Chargé en asynchrone par « Mon espace » : c'est lui (et jamais la page d'accueil)
 * qui déclenche l'éventuel appel réseau. Réservé au licencié connecté — l'info est
 * publique, mais cela évite de transformer le serveur en proxy anonyme. Ne renvoie
 * que titre / lien / date, déjà bornés et échappés côté client (textContent).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/news.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

if (!bk_current_archer()) {
    echo json_encode(array('items' => array()));
    exit;
}

echo json_encode(array('items' => bk_news_items(6)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
