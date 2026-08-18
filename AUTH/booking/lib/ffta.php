<?php
/**
 * lib/ffta.php — relais d'authentification vers l'Espace Licencié FFTA
 * (monespace.ffta.fr).
 *
 * PRINCIPE DE SÉCURITÉ — le rattachement ne vient PAS de l'identifiant saisi.
 * L'identifiant de l'espace licencié n'est pas toujours le numéro de licence
 * (ce peut être un identifiant nominatif choisi par le licencié). La licence est donc
 * lue sur la page servie APRÈS connexion, c'est-à-dire déclarée par la FFTA
 * elle-même pour la session ouverte : c'est la seule source qu'un utilisateur
 * ne peut pas choisir. Une licence saisie par l'archer ne doit JAMAIS être
 * utilisée pour rattacher un compte.
 *
 * Corollaire : si la licence ne peut pas être lue de façon certaine, la
 * connexion est REFUSÉE. Mieux vaut un refus explicite qu'un compte rattaché
 * au mauvais archer.
 *
 * Ce n'est PAS un OAuth : c'est un relais de crédentiels (même technique que le
 * module AUTH pour dirigeant.ffta.fr, éprouvée en production). Le mot de passe
 * ne quitte jamais la mémoire de la requête : jamais stocké, jamais journalisé.
 * Recommandation long terme inchangée : demander un vrai OIDC à la fédération.
 *
 * Ce fichier ne dépend d'AUCUN autre module (AUTH peut être absent).
 */

if (defined('BK_FFTA_LOADED')) return;
define('BK_FFTA_LOADED', true);

/** Base par défaut, surchargeable par config.local.json → "sso": {"base": "..."} */
function bk_ffta_base()
{
    $c = bk_local_config();
    $b = $c['sso']['base'] ?? '';
    return $b !== '' ? rtrim($b, '/') : 'https://monespace.ffta.fr';
}

function bk_ffta_enabled()
{
    $c = bk_local_config();
    return !isset($c['sso']['enabled']) || !empty($c['sso']['enabled']);
}

function bk_local_config()
{
    static $cfg = null;
    if (is_null($cfg)) {
        $cfg = array();
        $f = dirname(__DIR__) . '/config.local.json';
        if (is_file($f)) $cfg = json_decode(file_get_contents($f), true) ?: array();
    }
    return $cfg;
}

/* ------------------------------------------------------------------ */
/* Débogage (désactivé par défaut)                                     */
/* ------------------------------------------------------------------ */

/**
 * Activation SANS toucher au code : créer le fichier vide
 * Modules/Custom/AUTH/booking/ffta-debug.on (ou "sso":{"debug":true} dans
 * config.local.json). Indispensable le jour où la FFTA modifie ses pages.
 * Ne trace JAMAIS un mot de passe ni un code MFA — que des métadonnées.
 */
function bk_ffta_debug_enabled()
{
    static $on = null;
    if (is_null($on)) {
        $c = bk_local_config();
        $on = is_file(dirname(__DIR__) . '/ffta-debug.on') || !empty($c['sso']['debug']);
    }
    return $on;
}

function bk_ffta_debug($msg)
{
    if (!bk_ffta_debug_enabled()) return;
    @file_put_contents(dirname(__DIR__) . '/ffta-debug.log',
        date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** Métadonnées d'une page (type, formulaire, champs) — jamais son contenu. */
function bk_ffta_debug_page($html)
{
    if (!bk_ffta_debug_enabled()) return '';
    $html = (string) $html;
    $kind = 'inconnue';
    if (preg_match('#/auth/two-factor-challenge#i', $html) ||
        preg_match('/(two[-_]?factor|deux.?[ée]tapes|double.?authentification)/i', $html)) {
        $kind = 'defi-mfa';
    } elseif (preg_match('#name="password"#i', $html) && preg_match('#name="username"#i', $html)) {
        $kind = 'formulaire-login';
    } elseif (trim($html) !== '') {
        $kind = 'page-connectee?';
    }
    $action = '';
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $html, $m)) $action = $m[1];
    $names = array();
    if (preg_match_all('#<input\b[^>]*name=["\']([^"\']+)["\']#i', $html, $mm)) {
        $names = array_slice(array_unique($mm[1]), 0, 12);
    }
    return 'type=' . $kind . ' len=' . strlen($html)
        . ' action=' . $action . ' champs=' . implode(',', $names);
}

/* ------------------------------------------------------------------ */
/* Relais de connexion                                                 */
/* ------------------------------------------------------------------ */

/**
 * Tente la connexion d'un licencié sur l'Espace Licencié FFTA.
 *
 * $identifiant : ce que l'archer saisit — numéro de licence OU identifiant
 * nominatif. Il sert uniquement à se connecter, JAMAIS à rattacher le compte.
 *
 * Retour : tableau
 *   ['ok' => true, 'licence' => '0000001B', 'displayName' => 'M NOM Prenom']
 *   ['ok' => false, 'err' => <code>, 'msg' => <message affichable>]
 * Codes d'erreur : NETWORK, NO_CSRF, BAD_CREDENTIALS, MFA_NEEDED, MFA_BAD_CODE,
 * NO_LICENCE, AMBIGUOUS_LICENCE.
 *
 * $otp : code de double authentification, vide si non demandé.
 */
function bk_ffta_login($identifiant, $password, $otp = '')
{
    $base = bk_ffta_base();

    $cookieFile = tempnam(sys_get_temp_dir(), 'bk_ck_');
    @chmod($cookieFile, 0600);
    register_shutdown_function(function () use ($cookieFile) {
        if (file_exists($cookieFile)) @unlink($cookieFile);
    });

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-booking)',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ));

    bk_ffta_debug('--- login identifiant=' . $identifiant . ' otp=' . ($otp !== '' ? 'fourni' : 'vide') . ' ---');

    // 1) Page de connexion : on récupère le jeton CSRF Laravel.
    curl_setopt($ch, CURLOPT_URL, $base . '/auth/login');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $loginPage = curl_exec($ch);
    bk_ffta_debug('GET /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . bk_ffta_debug_page($loginPage));

    if (!$loginPage || curl_errno($ch)) {
        $e = curl_error($ch);
        curl_close($ch);
        bk_ffta_debug('=> injoignable : ' . $e);
        return array('ok' => false, 'err' => 'NETWORK',
            'msg' => "L'espace licencié FFTA est momentanément injoignable. Réessayez dans quelques instants.");
    }

    $csrf = bk_ffta_csrf($loginPage);
    if (!$csrf) {
        curl_close($ch);
        bk_ffta_debug('=> CSRF INTROUVABLE');
        return array('ok' => false, 'err' => 'NO_CSRF',
            'msg' => "La page de connexion FFTA a changé : connexion impossible pour l'instant. Signalez-le à l'organisateur.");
    }

    // 2) POST des identifiants.
    $post = array('_token' => $csrf, 'username' => $identifiant, 'password' => $password);
    curl_setopt_array($ch, array(
        CURLOPT_URL        => $base . '/auth/login',
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $landing = curl_exec($ch);
    $post = null;                       // le mot de passe ne survit pas à l'appel
    $effUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    bk_ffta_debug('POST /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . $effUrl . ' ' . bk_ffta_debug_page($landing));

    if ($landing === false || curl_errno($ch)) {
        curl_close($ch);
        return array('ok' => false, 'err' => 'NETWORK',
            'msg' => "La connexion à l'espace licencié FFTA a été interrompue. Réessayez.");
    }

    // Succès : la page d'arrivée porte le lien « Me déconnecter » (vérifié sur
    // une vraie page d'accueil de l'espace licencié). Ce marqueur POSITIF est
    // plus sûr que la seule heuristique d'URL héritée d'AUTH, qu'on garde en
    // repli au cas où la page changerait.
    $stillOnLogin = !bk_ffta_is_connected($landing) && (strpos($effUrl, '/auth/login') !== false);
    $isMfa = bk_ffta_is_mfa($landing, $effUrl);

    if ($isMfa) {
        if ($otp === '') {
            curl_close($ch);
            bk_ffta_debug('=> défi MFA, code non saisi');
            return array('ok' => false, 'err' => 'MFA_NEEDED',
                'msg' => "Votre compte FFTA demande un code de double authentification.");
        }
        $landing = bk_ffta_mfa_step2($ch, $landing, $otp, $base);
        $effUrl  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if (bk_ffta_is_mfa($landing, $effUrl)) {
            curl_close($ch);
            bk_ffta_debug('=> code MFA refusé');
            return array('ok' => false, 'err' => 'MFA_BAD_CODE',
                'msg' => "Code de double authentification refusé ou expiré.");
        }
        $stillOnLogin = !bk_ffta_is_connected($landing) && (strpos($effUrl, '/auth/login') !== false);
        bk_ffta_debug('=> seconde étape MFA acceptée');
    }

    if ($stillOnLogin) {
        curl_close($ch);
        bk_ffta_debug('=> identifiants refusés');
        return array('ok' => false, 'err' => 'BAD_CREDENTIALS',
            'msg' => "Identifiant ou mot de passe incorrect.");
    }

    bk_ffta_debug('=> connexion acceptée, résolution de la licence');

    // 3) Résolution de la licence SUR la session ouverte. C'est le rattachement :
    //    seule la FFTA décide de quelle licence il s'agit.
    $found = bk_ffta_extract_licences($landing);
    $page  = (string) $landing;

    // La page d'arrivée peut être une redirection intermédiaire sans identité :
    // on tente alors explicitement l'accueil de l'espace licencié.
    if (count($found) !== 1) {
        bk_ffta_debug('licence non résolue sur la page d\'arrivée (' . count($found)
            . ' candidate(s)) — tentative sur l\'accueil');
        curl_setopt_array($ch, array(
            CURLOPT_URL => $base . '/', CURLOPT_HTTPGET => true, CURLOPT_POST => false,
        ));
        $home = curl_exec($ch);
        bk_ffta_debug('GET / http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
            . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . bk_ffta_debug_page($home));
        if ($home && bk_ffta_is_connected($home)) {
            $f2 = bk_ffta_extract_licences($home);
            if (count($f2) === 1) { $found = $f2; $page = (string) $home; }
            elseif (!$found)      { $found = $f2; $page = (string) $home; }
        }
    }

    curl_close($ch);

    if (!$found) {
        bk_ffta_debug('=> AUCUNE licence lisible : refus');
        return array('ok' => false, 'err' => 'NO_LICENCE',
            'msg' => "Connexion réussie, mais votre numéro de licence n'a pas pu être lu sur "
                   . "l'espace licencié. Signalez-le à l'organisateur.");
    }
    if (count($found) > 1) {
        // Plusieurs licences distinctes : impossible de trancher sans risquer de
        // rattacher le compte au mauvais archer. On refuse.
        bk_ffta_debug('=> licences AMBIGUËS (' . implode(',', $found) . ') : refus');
        return array('ok' => false, 'err' => 'AMBIGUOUS_LICENCE',
            'msg' => "Connexion réussie, mais plusieurs numéros de licence figurent sur la page. "
                   . "Signalez-le à l'organisateur.");
    }

    $lic = $found[0];
    bk_ffta_debug('=> licence résolue : ' . $lic);
    return array('ok' => true, 'licence' => $lic, 'displayName' => bk_ffta_extract_name($page));
}

/**
 * Nom affiché sur la page connectée (ex. « M NOM Prenom »), utilisé comme
 * contrôle de cohérence avec le fichier des licences. Chaîne vide si absent.
 */
function bk_ffta_extract_name($html)
{
    $html = (string) $html;
    // Fiche profil : <h6 …>M NOM Prenom<small>…
    if (preg_match('#<h6[^>]*>\s*([^<]{3,80}?)\s*<#u', $html, $m)) {
        $n = trim(preg_replace('/\s+/', ' ', $m[1]));
        if ($n !== '') return $n;
    }
    // Barre de navigation : texte précédant le badge de licence
    if (preg_match('#>\s*([^<>]{3,80}?)\s*<span[^>]*class=["\'][^"\']*\bbadge\b#u', $html, $m)) {
        $n = trim(preg_replace('/\s+/', ' ', $m[1]));
        if ($n !== '') return $n;
    }
    return '';
}

/** Jeton CSRF Laravel, cherché dans le formulaire puis dans la balise meta. */
function bk_ffta_csrf($html)
{
    foreach (array(
        '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
        '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]*name=["\']csrf-token["\']/',
    ) as $p) {
        if (preg_match($p, (string) $html, $m)) return $m[1];
    }
    return null;
}

/**
 * Page de défi MFA (Laravel Fortify) ?
 *
 * ⚠️ Piège vérifié sur une page réelle : l'accueil de l'espace licencié affiche
 * un badge « 2FA » et le texte « Authentification deux facteurs non confirmée »
 * quand la double authentification n'est pas activée. Une détection par simple
 * mot-clé prendrait donc une page CONNECTÉE pour un défi MFA, et la connexion
 * échouerait pour les comptes sans 2FA — soit la majorité. D'où :
 *  1. une page qui porte le lien de déconnexion est connectée, jamais un défi ;
 *  2. le motif exclut délibérément « 2fa », « otp » et « deux facteurs », trop
 *     présents dans l'habillage courant du site.
 */
function bk_ffta_is_mfa($html, $url = '')
{
    $html = (string) $html;
    if (bk_ffta_is_connected($html)) return false;
    if (strpos((string) $url, 'two-factor') !== false) return true;
    return (bool) preg_match('#(two[-_]?factor[-_]?challenge|deux.?[ée]tapes|code.?de.?v[ée]rification)#i', $html);
}

/**
 * Seconde étape MFA : renvoie le code au formulaire du défi, en découvrant son
 * action et le nom de son champ. 'recovery_code' est explicitement EXCLU (codes
 * de secours, pas le code de l'application). Le code n'est jamais journalisé.
 */
function bk_ffta_mfa_step2($ch, $page, $otp, $base)
{
    $csrf = bk_ffta_csrf($page);

    $action = $base . '/auth/two-factor-challenge';
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $page, $m) && $m[1] !== '') {
        $action = (strpos($m[1], 'http') === 0) ? $m[1] : $base . '/' . ltrim($m[1], '/');
    }

    $names = array();
    if (preg_match_all('#<input\b[^>]*>#i', $page, $inp)) {
        foreach ($inp[0] as $tag) {
            if (preg_match('/type=["\'](hidden|submit|password|checkbox|radio)["\']/i', $tag)) continue;
            if (preg_match('/name=["\']([^"\']+)["\']/', $tag, $mm)) $names[] = $mm[1];
        }
    }
    $field = '';
    foreach (array('code', 'otp', 'two_factor_code', 'authenticator_code', 'pin') as $cand) {
        if (in_array($cand, $names, true)) { $field = $cand; break; }
    }
    if ($field === '') {
        foreach ($names as $n) {
            if (stripos($n, 'recovery') !== false || strtolower($n) === '_token') continue;
            if (preg_match('/(code|otp|2fa|pin|digit|chiffre)/i', $n)) { $field = $n; break; }
        }
    }
    if ($field === '') $field = 'code';

    bk_ffta_debug('MFA step2 action=' . $action . ' champ=' . $field . ' csrf=' . ($csrf ? 'oui' : 'non'));

    $post = array($field => $otp);
    if ($csrf) $post['_token'] = $csrf;
    curl_setopt_array($ch, array(
        CURLOPT_URL        => $action,
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $res = curl_exec($ch);
    $post = null;
    bk_ffta_debug('MFA step2 POST http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . bk_ffta_debug_page($res));
    return (string) $res;
}

/**
 * Défense en profondeur : le nom affiché par la FFTA doit être cohérent avec la
 * fiche du fichier des licences pour la licence résolue. Attrape une lecture de
 * licence erronée, qui rattacherait le compte au mauvais archer.
 *
 * Tolérant par conception : retourne true si le nom n'a pas pu être lu (on ne
 * bloque pas sur une structure de page inconnue) ; false uniquement si le nom de
 * famille du fichier fédéral est totalement absent du nom affiché.
 */
function bk_ffta_name_matches($displayName, $lue)
{
    if ($displayName === '' || !$lue) return true;
    $shown  = bk_fold($displayName);
    $family = bk_fold($lue->LueFamilyName);
    if ($family === '' || $shown === '') return true;
    $ok = (strpos($shown, $family) !== false);
    bk_ffta_debug('cohérence nom : affiché=' . $displayName . ' fichier=' . $lue->LueFamilyName
        . ' => ' . ($ok ? 'OK' : 'CONTRADICTION'));
    return $ok;
}

/**
 * Marqueur POSITIF de session ouverte : le lien « Me déconnecter » de l'espace
 * licencié. Vérifié sur une page d'accueil réelle. Ne jamais se contenter de
 * « la page n'est pas le formulaire de connexion » : une page d'erreur ou une
 * redirection intermédiaire passerait ce test-là.
 */
function bk_ffta_is_connected($html)
{
    return (bool) preg_match('#/auth/logout#i', (string) $html);
}

/**
 * Extrait les numéros de licence d'une page de l'espace licencié.
 * Format FFTA : 6 à 7 chiffres suivis d'une lettre clé (ex. 0000001B).
 *
 * ⚠️ Fonction de SÉCURITÉ : c'est elle qui décide à quel archer un compte est
 * rattaché (l'identifiant saisi ne le dit pas — il peut être nominatif). Elle
 * doit donc renvoyer une réponse CERTAINE ou aucune : l'appelant refuse la
 * connexion s'il n'obtient pas exactement une licence.
 *
 * Motifs par fiabilité décroissante, relevés sur une page d'accueil réelle
 * (« Exemples pour Claude/FFTA - Accueil espace licencié.html ») :
 *   1. fiche profil : « Licencié N°0000001B »
 *   2. barre de nav : <span class="badge …">0000001B</span>
 *   3. repli        : n'importe quel motif de licence de la page
 * On s'arrête au PREMIER motif qui répond : les deux premiers désignent
 * explicitement le titulaire de la session, alors que le repli ramasserait
 * aussi une licence citée ailleurs dans la page (autre archer, exemple…).
 *
 * Le format exclut naturellement l'agrément d'un club (`LLDDCCC`, 7 chiffres
 * SANS lettre finale) et sa variante corse (`052A005`).
 */
function bk_ffta_extract_licences($html)
{
    $html = (string) $html;
    $out = array();

    foreach (array(
        '/N[°ºo]\s*(\d{6,7}[A-Za-z])\b/u',
        '/<span[^>]*class=["\'][^"\']*\bbadge\b[^"\']*["\'][^>]*>\s*(\d{6,7}[A-Za-z])\s*</i',
        '/\b(\d{6,7}[A-Za-z])\b/',
    ) as $p) {
        if (preg_match_all($p, $html, $m)) {
            foreach ($m[1] as $v) $out[strtoupper($v)] = true;
            break;
        }
    }
    return array_keys($out);
}
