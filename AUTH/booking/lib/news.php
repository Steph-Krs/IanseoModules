<?php
/**
 * lib/news.php — the federation's news (public RSS feed), for "My space".
 *
 * The feed is fetched and parsed BY THE SERVER, but SERVED THROUGH A JSON ENDPOINT
 * (public/news.php) loaded asynchronously: the archer's home page never makes a network call.
 * The result is cached in the temporary folder (TTL 30 min) with a stampede guard: whatever
 * the outcome (success OR failure) the timestamped cache is rewritten, so the other requests do
 * not hit the federation again.
 *
 * Robustness: short timeout, never fatal ([] when anything goes wrong), XML parsing hardened
 * against external entities (XXE), links limited to http(s), escaped output.
 */

if (function_exists('bk_news_items')) return;

/** URL of the feed (can be overridden in config.local.json → "news":{"url":"…"}). */
function bk_news_url()
{
    static $u = null;
    if ($u === null) {
        $u = 'https://www.ffta.fr/rss.xml';
        $f = dirname(__DIR__) . '/config.local.json';   // same file as the rest of the module
        if (is_file($f)) {
            $raw = (string) @file_get_contents($f);
            // UTF-8 BOM of a Windows editor: json_decode would fail silently.
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

/** Date "28 August 2026" in the visitor's language, whatever the server's locale. */
function bk_news_date($ts)
{
    return bk_t('DateLong', array('d' => date('j', $ts), 'm' => bk_t('MonthL' . date('n', $ts)), 'y' => date('Y', $ts)));
}

/** Dates of the items in the visitor's language (the cache keeps the timestamp). */
function bk_news_localise($items)
{
    foreach ($items as &$it) if (!empty($it['ts'])) $it['date'] = bk_news_date(intval($it['ts']));
    unset($it);
    return $items;
}

/**
 * Items of the feed (title/link/date), from the cache when fresh, refreshed otherwise.
 * Never throws: returns an array (possibly empty).
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
    if ($fresh) return bk_news_localise(array_slice($cached, 0, $limit));

    $new = bk_news_download();   // null on failure (network/parse), an array otherwise
    if ($new !== null) {
        @file_put_contents($file, json_encode(array('items' => $new, 'at' => time())), LOCK_EX);
        return bk_news_localise(array_slice($new, 0, $limit));
    }
    // Failure: write the old content back with a timestamp that allows a new try in ~5 min
    // (not before), so as not to hammer the federation during an outage.
    @file_put_contents($file, json_encode(array('items' => $cached, 'at' => time() - $ttl + 300)), LOCK_EX);
    return bk_news_localise(array_slice($cached, 0, $limit));
}

/** Downloads the feed and parses it. Returns an array of items, or null on failure. */
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
        CURLOPT_ACCEPT_ENCODING => '',   // handles gzip when offered
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    return bk_news_parse($body);
}

/** Parses an RSS 2.0 feed. Hardened against XXE; keeps http(s) links only. */
function bk_news_parse($xml)
{
    if (!is_string($xml) || $xml === '') return null;
    $prev = libxml_use_internal_errors(true);
    // PHP ≥ 8: loading of external entities is off by default. LIBXML_NONET forbids any
    // network access of the parser; LIBXML_NOENT is NEVER turned on.
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($sx === false || !isset($sx->channel->item)) return null;

    $out = array();
    foreach ($sx->channel->item as $it) {
        $title = trim(preg_replace('/\s+/', ' ', (string) $it->title));
        $link  = trim((string) $it->link);
        if ($title === '' || !preg_match('#^https?://#i', $link)) continue;   // safe links only
        $ts = strtotime((string) $it->pubDate);
        $out[] = array(
            'title' => $title,
            'link'  => $link,
            'ts'    => $ts ?: 0,
            'date'  => '',
        );
        if (count($out) >= 12) break;
    }
    return $out;
}
