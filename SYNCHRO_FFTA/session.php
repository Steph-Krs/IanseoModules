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
require_once(__DIR__ . '/mapping.php');   // sfa_normalize(), utilisé par sfa_auth_matching_role()

/**
 * Bases visées par espace. Toutes deux en PRODUCTION (fonctionnement validé) :
 * 'ext' = extranet — création de compétition (lecture du calendrier fédéral) ET dépôt des
 *         résultats TXT (ce dernier via sa propre base $ITXT_BASE dans ajax.php, qui
 *         n'utilise pas cette fonction — deux calculs séparés, à garder synchronisés).
 * 'dir' = Espace Dirigeant.
 */
function sfa_base(string $space): string
{
    return $space === 'dir'
        ? DirigeantClient::BASE_PROD
        : ExtranetClient::BASE_PROD;
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

// ── Rôle extranet aligné sur la vue AUTH (aucune UI de sélection quand AUTH est présent) ─

/**
 * AUTH (module de comptes) est-il actif pour cette session ? Même condition que la garde
 * de création (create.php/ajax-create.php) : installé (USERAUTH) ET son bootstrap a tourné
 * (AUTH_ENABLE), donc AUTH_ROLE/AUTH_SCOPE sont disponibles en session.
 */
function sfa_auth_present(): bool
{
    global $CFG;

    return !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
}

/**
 * Nom de la structure AUTH actuellement active (rôle + périmètre), tel que connu depuis
 * dirigeant.ffta.fr (AUTH_VIEWS, publié par aut_session_apply — même source de noms que
 * l'extranet, les deux étant opérés par la FFTA). Le label de AUTH est « Nom (code) » pour
 * CD/CR/CLUB (aut_ffta_map_structure) — on retire le suffixe entre parenthèses.
 * Retourne '' si indéterminable (AUTH absent, pas de vue correspondante).
 */
function sfa_auth_view_name(): string
{
    $role  = $_SESSION['AUTH_ROLE']  ?? '';
    $scope = (string) ($_SESSION['AUTH_SCOPE'] ?? '');
    foreach (($_SESSION['AUTH_VIEWS'] ?? []) as $v) {
        if (($v['role'] ?? '') === $role && (string) ($v['scope'] ?? '') === $scope) {
            return trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($v['label'] ?? '')));
        }
    }

    return '';
}

/**
 * Valeur de rôle extranet (chxMxDrx) correspondant à la vue AUTH courante (AUTH_ROLE), ou
 * null si AUTH absent ou aucune correspondance trouvée dans les rôles disponibles.
 *
 * Les libellés extranet portent le NIVEAU (Fédération/Département/Régional/Club) mais pas
 * de code d'agrément. Pour un niveau qui n'a qu'un seul candidat, ça suffit. Pour CLUB, un
 * compte peut gérer PLUSIEURS clubs à la fois (cas réel vérifié : un compte peut être à la
 * fois « Gestionnaire Club Club Fédération » — une structure administrative bien réelle,
 * pas un leurre à écarter systématiquement — ET gestionnaire d'un club d'archers) : on
 * départage alors par le NOM de la structure AUTH active (sfa_auth_view_name), le seul
 * signal fiable puisque l'extranet ne donne aucun code d'agrément dans ses libellés.
 */
function sfa_auth_matching_role(array $roles): ?string
{
    $authRole = $_SESSION['AUTH_ROLE'] ?? '';
    $pattern  = [
        'FED'   => '/F[ée]d[ée]ration/iu',   // vue Administrateur : le plus proche est Fédération
        'ADMIN' => '/F[ée]d[ée]ration/iu',
        'CR'    => '/R[ée]gional|Ligue/iu',
        'CD'    => '/D[ée]partement(al)?/iu',
        'CLUB'  => '/Club/iu',
    ][$authRole] ?? null;

    if ($pattern === null) {
        return null;
    }

    $candidates = [];
    foreach ($roles as $r) {
        // « Mes informations personnelles » : gestion de compte, pas un niveau organisationnel.
        if (stripos($r['label'], 'informations personnelles') !== false) {
            continue;
        }
        if (preg_match($pattern, $r['label'])) {
            $candidates[] = $r;
        }
    }

    if (!$candidates) {
        return null;
    }
    if (count($candidates) === 1) {
        return $candidates[0]['value'];
    }

    // Plusieurs candidats au même niveau (typiquement CLUB) : on départage par le nom.
    $name = sfa_normalize(sfa_auth_view_name());
    if ($name !== '') {
        foreach ($candidates as $c) {
            if (strpos(sfa_normalize($c['label']), $name) !== false) {
                return $c['value'];
            }
        }
    }

    return $candidates[0]['value'];   // repli : premier trouvé si le nom ne permet pas de trancher
}

/**
 * Si AUTH est présent, aligne le rôle extranet sur sa vue courante (bascule silencieuse,
 * sans UI) et retourne les rôles à jour. Sans AUTH, ou sans correspondance, retourne les
 * rôles reçus tels quels — le sélecteur manuel de la page reste alors la seule voie.
 */
function sfa_sync_role_with_auth(ExtranetClient $client, array $roles): array
{
    if (!sfa_auth_present()) {
        return $roles;
    }
    $target = sfa_auth_matching_role($roles);
    if ($target === null) {
        return $roles;
    }

    $current = null;
    foreach ($roles as $r) {
        if (!empty($r['selected'])) {
            $current = $r['value'];
            break;
        }
    }
    if ($current === $target) {
        return $roles;
    }

    $sw = $client->switchRole($target);

    return !empty($sw['ok']) ? $sw['roles'] : $roles;
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
