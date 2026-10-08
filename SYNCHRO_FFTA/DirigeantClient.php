<?php
/**
 * HTTP client of the FFTA Espace Dirigeant (dirigeant.ffta.fr).
 *
 * A space DISTINCT from the extranet. Used by the licence holders sync (download of
 * parametres_ianseo.ffta). Laravel Fortify login with a two-step MFA.
 *
 * MFA: when the AUTH module is present (federal server), ITS proven login is reused
 * (aut_ffta_curl_login + aut_ffta_mfa_second_step) — it follows the changes of the FFTA page and
 * handles two-factor authentication. Otherwise (standalone module), built-in one-step login
 * (best effort; standalone Fortify MFA is not guaranteed).
 *
 * Every request of this client goes through FftaHttp (pacing, pauses, anti-bot detection).
 */
require_once(__DIR__ . '/FftaHttp.php');

class DirigeantClient
{
    const BASE_PROD = 'https://dirigeant.ffta.fr';

    private $cookieFile;
    private $base;
    private $http;

    public function __construct(string $cookieFile, string $base = self::BASE_PROD)
    {
        $this->cookieFile = $cookieFile;
        $this->base       = rtrim($base, '/');
        $this->http       = new FftaHttp($cookieFile);
    }

    public function base(): string
    {
        return $this->base;
    }

    /** Readable MFA message from AUTH's error code. */
    private static function msg(string $err): string
    {
        if ($err === 'MFA_NEEDED') {
            return 'Ce compte utilise la double authentification : saisissez le code à 6 chiffres.';
        }
        if ($err === 'MFA_BAD_CODE') {
            return 'Code de double authentification incorrect ou expiré. Réessayez avec un code frais.';
        }

        return $err !== '' ? $err : 'Identifiants Espace Dirigeant incorrects.';
    }

    /**
     * Login. Returns ['ok'=>bool, 'msg'=>?]. The authenticated cookie lands in $this->cookieFile.
     */
    public function login(string $user, string $pass, string $otp = ''): array
    {
        // ── AUTH path (robust, Fortify MFA) ─────────────────────────────────
        if (function_exists('aut_ffta_curl_login')) {
            $landing = '';
            $error   = '';
            $ckOut   = null;
            $ch = aut_ffta_curl_login($user, $pass, $otp, $landing, $error, $ckOut);
            if (!$ch) {
                return ['ok' => false, 'msg' => self::msg($error)];
            }
            if ($ckOut && file_exists($ckOut)) {
                @copy($ckOut, $this->cookieFile);   // the authenticated cookie is taken over
                @chmod($this->cookieFile, 0600);
            }
            curl_close($ch);

            return ['ok' => true];
        }

        // ── Standalone path (without AUTH): one-step login (best-effort MFA) ─
        return $this->loginBuiltin($user, $pass, $otp);
    }

    private function loginBuiltin(string $user, string $pass, string $otp): array
    {
        $page = $this->http->request($this->base . '/auth/login');
        if ($f = FftaHttp::failure($page, $this->base)) {
            return $f;
        }

        $csrf = null;
        foreach ([
            '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
            '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        ] as $p) {
            if (preg_match($p, $page['body'], $m)) { $csrf = $m[1]; break; }
        }
        if (!$csrf) {
            return ['ok' => false, 'msg' => 'Token CSRF introuvable (page de connexion modifiée ?).'];
        }

        $post = ['_token' => $csrf, 'username' => $user, 'password' => $pass];
        if ($otp !== '') { $post['otp'] = $otp; }
        $r = $this->http->request($this->base . '/auth/login', $post);
        if ($f = FftaHttp::failure($r, $this->base)) {
            return $f;
        }

        if (strpos($r['url'], '/login') !== false) {
            if ($r['captcha']) {
                return ['ok' => false, 'blocked' => true, 'msg' => FftaHttp::blockMessage('captcha', $this->base)];
            }

            return ['ok' => false, 'msg' => 'Identifiants incorrects (ou MFA requise — nécessite le module AUTH).'];
        }

        return ['ok' => true];
    }

    /** Is the session carried by the cookie still open? */
    public function session(): bool
    {
        $r = $this->http->request($this->base . '/');

        return FftaHttp::failure($r, $this->base) === null && strpos($r['url'], '/login') === false;
    }

    /**
     * Downloads the licence holders file (parametres_ianseo.ffta) with the current cookie.
     * @return array ['ok'=>bool, 'code'=>int, 'body'=>string, 'error'=>string, 'relogin'=>bool]
     */
    public function downloadLicences(): array
    {
        $r = $this->http->request($this->base . '/ianseo/download/parametres_ianseo.ffta');

        if ($f = FftaHttp::failure($r, $this->base)) {
            return ['ok' => false, 'code' => $r['code'], 'body' => '', 'error' => $f['msg'],
                    'offline' => !empty($f['offline']), 'relogin' => false];
        }
        // redirected to the login page = expired session
        if (strpos($r['url'], '/login') !== false) {
            return ['ok' => false, 'code' => $r['code'], 'body' => '', 'error' => 'Session expirée', 'relogin' => true];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'code' => $r['code'], 'body' => '', 'error' => 'HTTP ' . $r['code'], 'relogin' => false];
        }

        return ['ok' => true, 'code' => $r['code'], 'body' => $r['body'], 'error' => '', 'relogin' => false];
    }
}
