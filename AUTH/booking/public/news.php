<?php
/**
 * public/news.php — JSON endpoint of the federation's news (cached RSS feed).
 *
 * Loaded asynchronously by "My space": this endpoint (never the home page) makes the network
 * call, if any. For the connected licensee only — the news is public, but this keeps the
 * server from being an anonymous proxy. Returns only title / link / date, limited, and escaped
 * on the client side (textContent).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/news.php';

// Replaces the "no-store" of JsonOut(): the feed itself is cached for an hour.
$cache = array('Cache-Control: private, max-age=300');
if (!bk_current_archer()) JsonOut(array('error' => 1, 'msg' => '', 'items' => array()), false, $cache);
JsonOut(array('error' => 0, 'msg' => '', 'items' => bk_news_items(6)), false, $cache);
