<?php
/**
 * SYNCHRO_FFTA — gestion des sessions FFTA (extranet + Espace Dirigeant), commune
 * à tous les flux du module.
 *
 * Deux espaces DISTINCTS, mêmes identifiants :
 *   - extranet   (extranet.ffta.fr / pprod)  → dépôt résultats, création : ExtranetClient
 *   - dirigeant  (dirigeant.ffta.fr)          → synchro licenciés : DirigeantClient
 *
 * Priorité pour chaque espace : cookie publié par AUTH (convention FFTA_*_COOKIE, si
 * même serveur) → cookie propre déjà ouvert par le module → sinon login. Les cookies
 * propres sont aussi PUBLIÉS sous la convention pour que d'autres modules les réutilisent.
 * Objectif : demander les identifiants un minimum de fois. Rien n'est stocké côté client ;
 * seuls des cookies de session en fichiers 0600 vivent, détruits à la déconnexion.
 */
require_once(__DIR__ . '/ExtranetClient.php');
require_once(__DIR__ . '/DirigeantClient.php');

/** Bases visées. En phase d'essai, l'extranet pointe la préproduction. */
function sfa_base(string $space): string
{
    return $space === 'dir'
        ? DirigeantClient::BASE_PROD
        : ExtranetClient::BASE_PPROD;
}

/** Clés de convention (publiées par AUTH ou par nous) selon l'espace. */
function sfa_conv_keys(string $space): array
{
    return $space === 'dir'
        ? ['FFTA_DIRIGEANT_COOKIE', 'FFTA_DIRIGEANT_BASE']
        : ['FFTA_EXTRANET_COOKIE', 'FFTA_EXTRANET_BASE'];
}

// ── Cookie propre au module (créé par un login depuis nos pages) ─────────────

function sfa_own_cookie(string $space, bool $create = false): ?string
{
    $key = 'SFA_COOKIE_' . $space;
    if ($create) {
        sfa_own_cookie_destroy($space);
        $f = tempnam(sys_get_temp_dir(), 'sfa_' . $space . '_');
        @chmod($f, 0600);
        $_SESSION[$key] = $f;
        sfa_publish_own($space);

        return $f;
    }

    $f = $_SESSION[$key] ?? null;

    return ($f && file_exists($f)) ? $f : null;
}

function sfa_own_cookie_destroy(string $space): void
{
    $key = 'SFA_COOKIE_' . $space;
    $f = $_SESSION[$key] ?? null;
    if ($f && file_exists($f)) {
        @unlink($f);
    }
    unset($_SESSION[$key]);

    // ne dé-publie que si la convention pointait NOTRE cookie (pas celui d'AUTH)
    [$ck, $bk] = sfa_conv_keys($space);
    if (($_SESSION[$ck] ?? null) === $f) {
        unset($_SESSION[$ck], $_SESSION[$bk]);
    }
}

/** Publie notre cookie sous la convention (pour les autres modules), si AUTH ne l'a pas déjà fait. */
function sfa_publish_own(string $space): void
{
    [$ck, $bk] = sfa_conv_keys($space);
    if (!empty($_SESSION[$ck]) && rtrim($_SESSION[$bk] ?? '', '/') === rtrim(sfa_base($space), '/')) {
        return;   // déjà publié (probablement par AUTH) — on n'écrase pas
    }
    $own = $_SESSION['SFA_COOKIE_' . $space] ?? null;
    if ($own && file_exists($own)) {
        $_SESSION[$ck] = $own;
        $_SESSION[$bk] = sfa_base($space);
    }
}

// ── Cookie publié par un autre module (AUTH), si même serveur ────────────────

function sfa_shared_cookie(string $space): ?string
{
    [$ck, $bk] = sfa_conv_keys($space);
    $c = $_SESSION[$ck] ?? '';
    $b = $_SESSION[$bk] ?? '';
    // ne pas confondre avec notre propre publication
    if ($c !== '' && $c === ($_SESSION['SFA_COOKIE_' . $space] ?? null)) {
        return null;
    }

    return ($c !== '' && rtrim($b, '/') === rtrim(sfa_base($space), '/') && file_exists($c)) ? $c : null;
}

function sfa_any_cookie(string $space): ?string
{
    return sfa_own_cookie($space) ?? sfa_shared_cookie($space);
}

function sfa_is_shared(string $space): bool
{
    return sfa_own_cookie($space) === null && sfa_shared_cookie($space) !== null;
}

// ── Login unifié : ouvre les deux espaces, stocke les deux cookies ───────────

/**
 * Ouvre les espaces demandés avec les identifiants fournis. Les identifiants sont
 * effacés ici. Retourne l'état par espace : ['ext'=>['ok'=>,'msg'=>], 'dir'=>[...]].
 *
 * @param array $spaces sous-ensemble de ['ext','dir'] (défaut : les deux)
 */
function sfa_login(string $user, string $pass, string $otp = '', array $spaces = ['ext', 'dir']): array
{
    $res = [];

    if (in_array('ext', $spaces, true)) {
        $client = new ExtranetClient(sfa_own_cookie('ext', true), sfa_base('ext'));
        $r = $client->login($user, $pass);   // extranet : pas de MFA
        if (!$r['ok']) {
            sfa_own_cookie_destroy('ext');
        }
        $res['ext'] = $r;
    }

    if (in_array('dir', $spaces, true)) {
        $client = new DirigeantClient(sfa_own_cookie('dir', true), sfa_base('dir'));
        $r = $client->login($user, $pass, $otp);   // dirigeant : MFA Fortify gérée
        if (!$r['ok']) {
            sfa_own_cookie_destroy('dir');
        }
        $res['dir'] = $r;
    }

    $user = str_repeat("\0", max(1, strlen($user)));
    $pass = str_repeat("\0", max(1, strlen($pass)));
    $otp  = str_repeat("\0", max(1, strlen($otp)));
    unset($user, $pass, $otp);

    return $res;
}

/** Détruit uniquement nos cookies (jamais ceux d'AUTH). */
function sfa_logout(): void
{
    sfa_own_cookie_destroy('ext');
    sfa_own_cookie_destroy('dir');
}

/**
 * Client prêt à l'emploi pour un espace, depuis la session disponible, ou JsonOut d'erreur.
 * 'ext' → ExtranetClient, 'dir' → DirigeantClient.
 */
function sfa_client(string $space)
{
    $f = sfa_any_cookie($space);
    if (!$f) {
        JsonOut([
            'ok'      => false,
            'msg'     => 'Aucune session ' . ($space === 'dir' ? 'Espace Dirigeant' : 'extranet') . ' — connecte-toi d\'abord.',
            'relogin' => true,
        ]);
    }

    return $space === 'dir'
        ? new DirigeantClient($f, sfa_base($space))
        : new ExtranetClient($f, sfa_base($space));
}
