<?php
/**
 * SYNCHRO_FFTA — the single HTTP transport for every call the module makes to the FFTA sites
 * (extranet, Espace Dirigeant, public logo endpoint).
 *
 * The extranet provider is restricting automated access. Pacing, pauses and block detection must
 * therefore behave the same for every flow, and change in one place:
 *
 *  - pacing: a minimum gap between two requests to the same host, shared by all PHP processes of
 *    this server (every organiser of a shared server reaches the extranet from the same address);
 *  - pause: after a 429 (or a 503 carrying Retry-After) the host is left alone for the delay it
 *    asked for; callers get that delay back (`wait`) so the page can tell the user and retry,
 *    instead of a silent long sleep on the server;
 *  - detection: an anti-bot answer is reported as such (`blocked`), instead of being read as
 *    "wrong credentials" or "nothing found".
 */
class FftaHttp
{
    const USER_AGENT = 'Mozilla/5.0 (compatible; ianseo/synchro-ffta)';

    // Gap between two requests to one host. Short enough to stay invisible to the user.
    const MIN_GAP_MS = 500;

    // Beyond this queue the request is not sent: the caller is told how long to wait.
    const MAX_QUEUE_MS = 4000;

    // Pause applied when the host throttles without saying for how long, and its upper bound.
    const DEFAULT_PAUSE = 30;
    const MAX_PAUSE     = 600;

    // Interstitial pages of the common anti-bot services. Only consulted on a 403/503 answer:
    // some of these scripts are also injected into normal pages.
    const CHALLENGE_RE = '~<title>\s*(?:Just a moment|Attention Required|Access denied|Un instant)'
        . '|cf_chl_opt|/cdn-cgi/challenge-platform/|_Incapsula_Resource|ddos-guard'
        . '|captcha-delivery\.com|challenges\.cloudflare\.com~i';

    // Captcha widgets actually embedded by the page (script source or widget class). The bare word
    // "captcha" is not enough: saved copies of normal extranet pages already contain it.
    const CAPTCHA_RE = '~challenges\.cloudflare\.com/turnstile|google\.com/recaptcha|recaptcha\.net/recaptcha'
        . '|hcaptcha\.com/1/api\.js|js\.hcaptcha\.com|friendlycaptcha|captcha-delivery\.com'
        . '|class=["\'][^"\']*\b(?:g-recaptcha|h-captcha|cf-turnstile)\b~i';

    private $cookieFile;

    public function __construct(string $cookieFile = '')
    {
        $this->cookieFile = $cookieFile;
    }

    /**
     * @param array|null $post  form fields (null = GET); sent urlencoded, or as multipart when
     *                          $opts['multipart'] is set (CURLFile values allowed)
     * @param array      $opts  'timeout' (s), 'headers' (bool: return the response headers),
     *                          'multipart' (bool), 'httpHeaders' (string[])
     * @return array 'code', 'url', 'body', 'headers', 'error', 'errno',
     *               'wait'    (s, > 0 = nothing usable: retry after this delay),
     *               'waitWhy' ('pause': the host asked to slow down, 'queue': this server is busy),
     *               'blocked' ('' or 'challenge' | 'denied' | 'unavailable'),
     *               'captcha' (bool: a captcha widget is embedded in the answer)
     */
    public function request(string $url, ?array $post = null, array $opts = []): array
    {
        $res = ['code' => 0, 'url' => $url, 'body' => '', 'headers' => '', 'error' => '', 'errno' => 0,
                'wait' => 0, 'waitWhy' => '', 'blocked' => '', 'captcha' => false];

        $host = (string) parse_url($url, PHP_URL_HOST);
        $wait = self::reserve($host);
        if ($wait['wait'] > 0) {
            return array_merge($res, $wait);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_TIMEOUT        => $opts['timeout'] ?? 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER         => true,   // Retry-After is needed even when the caller wants no headers
        ]);
        if ($this->cookieFile !== '') {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        }
        if (!empty($opts['httpHeaders'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['httpHeaders']);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, empty($opts['multipart']) ? http_build_query($post) : $post);
        }

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $res['code']  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $res['url']   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $res['errno'] = $errno;
        $res['error'] = $errno ? curl_error($ch) : '';
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false) {
            return $res;
        }
        // bytes: HEADER_SIZE is a byte offset
        $headers = substr($raw, 0, $hsize);
        $res['body'] = (string) substr($raw, $hsize);
        if (!empty($opts['headers'])) {
            $res['headers'] = $headers;
        }

        $res['captcha'] = (bool) preg_match(self::CAPTCHA_RE, $res['body']);
        $res['blocked'] = self::classify($res['code'], $res['body']);

        // The host asks to slow down: leave it alone for the delay it gives (or a default one),
        // for every user of this server, and hand the delay back to the page.
        $retry = self::retryAfter($headers);
        if ($res['code'] === 429 || ($res['code'] === 503 && $retry > 0)) {
            $pause = min(self::MAX_PAUSE, $retry > 0 ? $retry : self::DEFAULT_PAUSE);
            self::pause($host, $pause);
            $res['wait']    = $pause;
            $res['waitWhy'] = 'pause';
            $res['blocked'] = '';
        }

        return $res;
    }

    /** Reason of an anti-bot or refusal answer, or '' for a normal answer. */
    private static function classify(int $code, string $body): string
    {
        if ($code !== 403 && $code !== 503) {
            return '';
        }
        if (preg_match(self::CHALLENGE_RE, $body)) {
            return 'challenge';
        }

        return $code === 403 ? 'denied' : 'unavailable';
    }

    /** Retry-After header in seconds (delta form only), 0 when absent. */
    private static function retryAfter(string $headers): int
    {
        return preg_match('/^Retry-After:\s*(\d+)\s*$/mi', $headers, $m) ? (int) $m[1] : 0;
    }

    // ── Pacing shared by the whole server ────────────────────────────────────

    private static function paceFile(string $host): string
    {
        return sys_get_temp_dir() . '/sfa_pace_' . preg_replace('/[^a-z0-9.-]/i', '_', $host) . '.json';
    }

    /**
     * Books a sending slot for this host. Sleeps until the slot when it is close (the user does
     * not notice half a second); otherwise sends nothing and returns the delay to show.
     * Pacing is a courtesy: a temp file that cannot be opened never blocks the module.
     *
     * @return array ['wait'=>int seconds, 'waitWhy'=>string]
     */
    private static function reserve(string $host): array
    {
        $fh = @fopen(self::paceFile($host), 'c+');
        if (!$fh) {
            return ['wait' => 0, 'waitWhy' => ''];
        }
        flock($fh, LOCK_EX);
        $state = json_decode((string) stream_get_contents($fh), true) ?: [];
        $now   = microtime(true);

        $until = (float) ($state['pause'] ?? 0);
        if ($until > $now) {
            flock($fh, LOCK_UN);
            fclose($fh);

            return ['wait' => (int) ceil($until - $now), 'waitWhy' => 'pause'];
        }

        $slot = max($now, (float) ($state['last'] ?? 0) + self::MIN_GAP_MS / 1000);
        if (($slot - $now) * 1000 > self::MAX_QUEUE_MS) {
            flock($fh, LOCK_UN);
            fclose($fh);

            return ['wait' => (int) ceil($slot - $now), 'waitWhy' => 'queue'];
        }

        $state['last'] = $slot;
        self::save($fh, $state);
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($slot > $now) {
            usleep((int) (($slot - $now) * 1000000));
        }

        return ['wait' => 0, 'waitWhy' => ''];
    }

    /** Leaves the host alone for $seconds, for every request of this server. */
    private static function pause(string $host, int $seconds): void
    {
        $fh = @fopen(self::paceFile($host), 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $state = json_decode((string) stream_get_contents($fh), true) ?: [];
        $state['pause'] = max((float) ($state['pause'] ?? 0), microtime(true) + $seconds);
        self::save($fh, $state);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private static function save($fh, array $state): void
    {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /**
     * cURL codes meaning "no connection / unreachable server": 5 proxy, 6 DNS, 7 refused,
     * 28 timeout, 35 TLS failure. (Numbers: constant names differ between PHP versions.)
     */
    public static function isOffline(int $errno): bool
    {
        return in_array($errno, [5, 6, 7, 28, 35], true);
    }

    /** Network error as a user message: no connection is not a credentials problem. */
    public static function netMessage(int $errno, string $err, string $base): string
    {
        if (self::isOffline($errno)) {
            return 'Cette étape nécessite une connexion à Internet, et ' . $base . ' est injoignable. '
                 . 'Vérifiez votre connexion réseau puis réessayez. '
                 . 'Il ne s\'agit ni d\'un problème d\'identifiants, ni d\'un calendrier vide.';
        }

        return 'Erreur réseau : ' . $err;
    }

    /** Why the page must wait — the page adds the countdown itself. */
    public static function waitMessage(string $why): string
    {
        return $why === 'queue'
            ? 'D\'autres demandes vers le site FFTA partent déjà de ce serveur : la vôtre est mise en attente.'
            : 'Le site FFTA a demandé de ralentir les échanges : le module patiente avant de le solliciter à nouveau.';
    }

    /** Factual message for an anti-bot or refusal answer. */
    public static function blockMessage(string $kind, string $base): string
    {
        switch ($kind) {
            case 'challenge':
                return $base . ' a répondu par une page de vérification anti-robot au lieu de la page '
                     . 'demandée. Le module ne peut pas franchir cette vérification. Réessayez plus tard.';
            case 'captcha':
                return $base . ' demande de résoudre un captcha. Le module ne peut pas le faire à votre place. '
                     . 'Réessayez plus tard.';
            case 'unavailable':
                return $base . ' est momentanément indisponible (erreur 503). Réessayez plus tard.';
        }

        return $base . ' a refusé la requête (erreur 403).';
    }

    /**
     * Failure array of a request that brought nothing usable, or null when the answer can be read.
     * Order matters: a delayed request sent nothing, so it is neither a network error nor a block.
     */
    public static function failure(array $r, string $base): ?array
    {
        if ($r['wait'] > 0) {
            return ['ok' => false, 'wait' => $r['wait'], 'msg' => self::waitMessage($r['waitWhy'])];
        }
        if ($r['error'] !== '') {
            return ['ok' => false, 'msg' => self::netMessage($r['errno'], $r['error'], $base),
                    'offline' => self::isOffline($r['errno'])];
        }
        if ($r['blocked'] !== '') {
            return ['ok' => false, 'blocked' => true, 'msg' => self::blockMessage($r['blocked'], $base)];
        }

        return null;
    }
}
