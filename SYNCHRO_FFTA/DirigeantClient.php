<?php
/**
 * Client HTTP de l'Espace Dirigeant FFTA (dirigeant.ffta.fr).
 *
 * Espace DISTINCT de l'extranet. Sert à la synchro des licenciés (téléchargement de
 * parametres_ianseo.ffta). Login Laravel Fortify avec MFA à deux étapes.
 *
 * MFA : si le module AUTH est présent (serveur fédéral), on réutilise SON login éprouvé
 * (aut_ffta_curl_login + aut_ffta_mfa_second_step) — qui suit les évolutions de la page
 * FFTA et gère la double authentification. Sinon (module autonome), login intégré en
 * une étape (best-effort ; la MFA Fortify autonome n'est pas garantie).
 */
class DirigeantClient
{
    const BASE_PROD = 'https://dirigeant.ffta.fr';

    private $cookieFile;
    private $base;

    public function __construct(string $cookieFile, string $base = self::BASE_PROD)
    {
        $this->cookieFile = $cookieFile;
        $this->base       = rtrim($base, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    private function curl()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo/synchro-ffta)',
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        return $ch;
    }

    /** Message MFA lisible à partir du code d'erreur d'AUTH. */
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
     * Connexion. Retourne ['ok'=>bool, 'msg'=>?]. Le cookie authentifié atterrit dans
     * $this->cookieFile.
     */
    public function login(string $user, string $pass, string $otp = ''): array
    {
        // ── Voie AUTH (robuste, MFA Fortify) ────────────────────────────────
        if (function_exists('aut_ffta_curl_login')) {
            $landing = '';
            $error   = '';
            $ckOut   = null;
            $ch = aut_ffta_curl_login($user, $pass, $otp, $landing, $error, $ckOut);
            if (!$ch) {
                return ['ok' => false, 'msg' => self::msg($error)];
            }
            if ($ckOut && file_exists($ckOut)) {
                @copy($ckOut, $this->cookieFile);   // on récupère le cookie authentifié
                @chmod($this->cookieFile, 0600);
            }
            curl_close($ch);

            return ['ok' => true];
        }

        // ── Voie autonome (sans AUTH) : login en une étape (best-effort MFA) ─
        return $this->loginBuiltin($user, $pass, $otp);
    }

    private function loginBuiltin(string $user, string $pass, string $otp): array
    {
        $ch = $this->curl();

        curl_setopt($ch, CURLOPT_URL, $this->base . '/auth/login');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $page = curl_exec($ch);
        if (!$page || curl_errno($ch)) {
            $e = curl_error($ch);
            curl_close($ch);

            return ['ok' => false, 'msg' => 'Espace Dirigeant injoignable : ' . $e];
        }

        $csrf = null;
        foreach ([
            '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
            '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        ] as $p) {
            if (preg_match($p, $page, $m)) { $csrf = $m[1]; break; }
        }
        if (!$csrf) {
            curl_close($ch);

            return ['ok' => false, 'msg' => 'Token CSRF introuvable (page de connexion modifiée ?).'];
        }

        $post = ['_token' => $csrf, 'username' => $user, 'password' => $pass];
        if ($otp !== '') { $post['otp'] = $otp; }
        curl_setopt_array($ch, [
            CURLOPT_URL        => $this->base . '/auth/login',
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
        ]);
        curl_exec($ch);
        $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (strpos($effUrl, '/login') !== false) {
            return ['ok' => false, 'msg' => 'Identifiants incorrects (ou MFA requise — nécessite le module AUTH).'];
        }

        return ['ok' => true];
    }

    /** La session portée par le cookie est-elle encore ouverte ? */
    public function session(): bool
    {
        $ch = $this->curl();
        curl_setopt($ch, CURLOPT_URL, $this->base . '/');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $body = curl_exec($ch);
        $ok   = $body !== false && !curl_errno($ch)
            && strpos($effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), '/login') === false;
        curl_close($ch);

        return $ok;
    }

    /**
     * Télécharge le fichier des licenciés (parametres_ianseo.ffta) avec le cookie courant.
     * @return array ['ok'=>bool, 'code'=>int, 'body'=>string, 'error'=>string, 'relogin'=>bool]
     */
    public function downloadLicences(): array
    {
        $ch = $this->curl();
        curl_setopt($ch, CURLOPT_URL, $this->base . '/ianseo/download/parametres_ianseo.ffta');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $body   = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err    = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        // redirigé vers le login = session expirée
        if (strpos($effUrl, '/login') !== false) {
            return ['ok' => false, 'code' => $code, 'body' => '', 'error' => 'Session expirée', 'relogin' => true];
        }
        if ($err || $body === false || $code !== 200) {
            return ['ok' => false, 'code' => $code, 'body' => '', 'error' => $err ?: ('HTTP ' . $code), 'relogin' => false];
        }

        return ['ok' => true, 'code' => $code, 'body' => (string) $body, 'error' => '', 'relogin' => false];
    }
}
