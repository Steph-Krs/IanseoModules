<?php
/**
 * lib/news.php — actualités de la FFTA (flux RSS public), pour « Mon espace ».
 *
 * Le flux est récupéré et parsé CÔTÉ SERVEUR, mais SERVI VIA UN ENDPOINT JSON
 * (public/news.php) chargé en asynchrone : la page d'accueil de l'archer ne
 * déclenche jamais d'appel réseau. Résultat mis en cache dans le dossier temporaire
 * (TTL 30 min) avec protection anti-troupeau : à chaque issue (succès OU échec) on
 * réécrit le cache horodaté, donc les autres requêtes ne re-sollicitent pas la FFTA.
 *
 * Robustesse : timeout court, jamais fatal (retour [] en cas de souci), parsing XML
 * durci contre les entités externes (XXE), liens bornés à http(s), sortie échappée.
 */

if (function_exists('bk_news_items')) return;

/** URL du flux (surchargeable via config.local.json → "news":{"url":"…"}). */
function bk_news_url()
{
    static $u = null;
    if ($u === null) {
        $u = 'https://www.ffta.fr/rss.xml';
        $f = dirname(__DIR__) . '/config.local.json';   // même fichier que le reste du module
        if (is_file($f)) {
            $raw = (string) @file_get_contents($f);
            // BOM UTF-8 d'un éditeur Windows : json_decode échouerait en silence.
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
            $c = json_decode($raw, true);
            $cu = is_array($c) ? (string) ($c['news']['url'] ?? '') : '';
            if (preg_match('#^https?://#i', $cu)) $u = $cu;
        }
    }
    return $u;
}

function bk_news_cache_file()
{
    return sys_get_temp_dir() . '/bk_ffta_news.json';
}

/** Date « 28 août 2026 » sans dépendre de la locale du serveur. */
function bk_news_date_fr($ts)
{
    $mois = array('', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');
    return date('j', $ts) . ' ' . $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Items du flux (titre/lien/date), depuis le cache si frais, sinon rafraîchis.
 * Ne lève jamais : retourne un tableau (éventuellement vide).
 */
function bk_news_items($limit = 6, $ttl = 1800)
{
    $file = bk_news_cache_file();
    $cached = array();
    $fresh = false;
    if (is_file($file)) {
        $j = json_decode((string) @file_get_contents($file), true);
        if (is_array($j) && isset($j['items']) && is_array($j['items'])) {
            $cached = $j['items'];
            if ((time() - intval($j['at'] ?? 0)) < $ttl) $fresh = true;
        }
    }
    if ($fresh) return array_slice($cached, 0, $limit);

    $new = bk_news_download();   // null en cas d'échec (réseau/parse), tableau sinon
    if ($new !== null) {
        @file_put_contents($file, json_encode(array('items' => $new, 'at' => time())), LOCK_EX);
        return array_slice($new, 0, $limit);
    }
    // Échec : réécrire l'ancien contenu avec un horodatage qui autorise un nouvel essai
    // dans ~5 min (pas avant), pour ne pas marteler la FFTA en cas de panne.
    @file_put_contents($file, json_encode(array('items' => $cached, 'at' => time() - $ttl + 300)), LOCK_EX);
    return array_slice($cached, 0, $limit);
}

/** Télécharge le flux et le parse. Retourne un tableau d'items, ou null en cas d'échec. */
function bk_news_download()
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init(bk_news_url());
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'ianseo-booking (actualites FFTA)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ACCEPT_ENCODING => '',   // gère gzip si proposé
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    return bk_news_parse($body);
}

/** Parse un flux RSS 2.0. Durci contre XXE ; ne garde que les liens http(s). */
function bk_news_parse($xml)
{
    if (!is_string($xml) || $xml === '') return null;
    $prev = libxml_use_internal_errors(true);
    // PHP ≥ 8 : chargement d'entités externes désactivé par défaut. LIBXML_NONET
    // interdit tout accès réseau du parseur ; on n'active JAMAIS LIBXML_NOENT.
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($sx === false || !isset($sx->channel->item)) return null;

    $out = array();
    foreach ($sx->channel->item as $it) {
        $title = trim(preg_replace('/\s+/', ' ', (string) $it->title));
        $link  = trim((string) $it->link);
        if ($title === '' || !preg_match('#^https?://#i', $link)) continue;   // liens sûrs uniquement
        $ts = strtotime((string) $it->pubDate);
        $out[] = array(
            'title' => $title,
            'link'  => $link,
            'date'  => $ts ? bk_news_date_fr($ts) : '',
        );
        if (count($out) >= 12) break;
    }
    return $out;
}
