<?php
/**
 * lib/archer.php — comptes licenciés : identité, mot de passe, sessions.
 *
 * Modèle repris de Modules/Custom/AUTH (jamais inclus : les modules restent
 * autonomes) : la session PHP ne porte qu'un JETON aléatoire, dont seul le
 * haché SHA-256 est en base — un dump de session ne donne aucun secret
 * réutilisable. Expirations calculées côté SQL (NOW()) : ianseo change le
 * time_zone MySQL par compétition, ne jamais comparer à time() PHP.
 *
 * BaPassword vide = compte SSO (sentinelle réservée au futur relais
 * monespace.ffta.fr) : un tel compte ne peut PAS se connecter par mot de passe.
 */

if (defined('BK_ARCHER_LOADED')) return;
define('BK_ARCHER_LOADED', true);

require_once __DIR__ . '/schema.php';

define('BK_SESSION_IDLE_H', 12);   // heures d'inactivité avant expiration
define('BK_SESSION_ABS_D',  7);    // durée de vie absolue en jours
define('BK_MAX_LOGIN_FAIL', 8);    // échecs de connexion / 15 min
define('BK_MAX_IDENT_FAIL', 10);   // échecs d'identification / 15 min

/* ------------------------------------------------------------------ */
/* Journal & limitation de débit                                       */
/* ------------------------------------------------------------------ */

function bk_ip()
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function bk_log($event, $user = '')
{
    bk_schema();
    safe_w_sql("INSERT INTO BK_Log (BlEvent, BlUser, BlIP) VALUES ("
        . StrSafe_DB(substr($event, 0, 32)) . ","
        . StrSafe_DB(substr($user, 0, 64)) . ","
        . StrSafe_DB(bk_ip()) . ")");
}

/**
 * Compte les échecs récents pour une famille d'événements. Le filtre porte sur
 * l'IP OU l'identifiant : un attaquant qui change de licence à chaque essai est
 * bloqué par l'IP, un bourrage distribué sur une licence l'est par l'identifiant.
 */
function bk_too_many($events, $max, $user = '')
{
    bk_schema();
    $in = implode(',', array_map('StrSafe_DB', (array) $events));
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM BK_Log
        WHERE BlEvent IN ($in)
          AND BlWhen > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
          AND (BlIP = " . StrSafe_DB(bk_ip()) . " OR BlUser = " . StrSafe_DB($user) . ")");
    $r = safe_fetch($q);
    return $r && intval($r->n) >= $max;
}

/* ------------------------------------------------------------------ */
/* Base licenciés (LookUpEntries, alimentée par la synchro fédérale)   */
/* ------------------------------------------------------------------ */

/** Normalise un numéro de licence (espaces, casse). */
function bk_clean_licence($licence)
{
    return strtoupper(preg_replace('/\s+/', '', (string) $licence));
}

/** Comparaison de noms tolérante (casse, accents, tirets, espaces). */
function bk_fold($s)
{
    $s = (string) $s;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) $s = $t;
    }
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
}

/**
 * Fiche d'un licencié dans le fichier fédéral, par son numéro de licence.
 *
 * L'identité est prouvée en amont par la connexion à l'espace licencié FFTA
 * (l'identifiant y EST le numéro de licence) : cette lecture ne sert donc plus
 * à authentifier, seulement à renseigner nom, prénom et club.
 *
 * Rappel de nommage LookUpEntries (vérifié sur la base réelle) :
 * LueFamilyName = NOM, LueName = prénom, LueCtrlCode = date de naissance,
 * LueCountry = agrément du club (LLDDCCC).
 */
function bk_lookup_licence($licence)
{
    $licence = bk_clean_licence($licence);
    if ($licence === '') return null;

    $q = safe_r_sql("SELECT LueCode, LueFamilyName, LueName, LueCtrlCode, LueSex,
                LueCountry, LueCoDescr, LueDivision, LueClass, LueSubClass,
                LueStatus, LueStatusValidUntil, LueIocCode
        FROM LookUpEntries
        WHERE LueCode = " . StrSafe_DB($licence) . "
        ORDER BY LueDefault DESC
        LIMIT 1");
    return safe_fetch($q) ?: null;
}

/* ------------------------------------------------------------------ */
/* Comptes                                                             */
/* ------------------------------------------------------------------ */

function bk_get_archer_by_licence($licence)
{
    bk_schema();
    $q = safe_r_sql("SELECT * FROM BK_Archers WHERE BaLicence = " . StrSafe_DB(bk_clean_licence($licence)));
    return safe_fetch($q) ?: null;
}

function bk_get_archer($id)
{
    bk_schema();
    $q = safe_r_sql("SELECT * FROM BK_Archers WHERE BaId = " . intval($id));
    return safe_fetch($q) ?: null;
}

/**
 * Provisionne (ou rafraîchit) le compte d'un licencié dont l'identité vient
 * d'être prouvée par l'espace licencié FFTA.
 *
 * BaPassword reste TOUJOURS vide : ce module ne gère aucun mot de passe: la
 * sécurité du compte est celle de l'espace licencié FFTA. La sentinelle est la
 * même que AUT_Users.AuPassword côté AUTH.
 *
 * Nom, prénom et club sont réalignés à chaque connexion sur le fichier fédéral
 * — un archer change de club entre deux saisons.
 *
 * Retourne l'id du compte, ou 0 en cas d'échec.
 */
function bk_provision_archer($lue)
{
    bk_schema();
    $lic = bk_clean_licence($lue->LueCode);

    $set = "BaFamilyName = " . StrSafe_DB($lue->LueFamilyName)
         . ", BaName = "     . StrSafe_DB($lue->LueName)
         . ", BaClubCode = " . StrSafe_DB($lue->LueCountry);

    $a = bk_get_archer_by_licence($lic);
    if ($a) {
        safe_w_sql("UPDATE BK_Archers SET $set WHERE BaId = " . intval($a->BaId));
        return intval($a->BaId);
    }

    safe_w_sql("INSERT INTO BK_Archers SET BaLicence = " . StrSafe_DB($lic)
        . ", BaPassword = '', $set");
    $a = bk_get_archer_by_licence($lic);
    return $a ? intval($a->BaId) : 0;
}

/* ------------------------------------------------------------------ */
/* Sessions à jetons                                                   */
/* ------------------------------------------------------------------ */

function bk_session_open($archer)
{
    $token = bin2hex(random_bytes(32));
    safe_w_sql("INSERT INTO BK_Sessions (BsArcher, BsTokenHash, BsIP, BsUA) VALUES ("
        . intval($archer->BaId) . ",'" . hash('sha256', $token) . "',"
        . StrSafe_DB(bk_ip()) . ","
        . StrSafe_DB(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160)) . ")");
    safe_w_sql("DELETE FROM BK_Sessions WHERE BsLastSeen < DATE_SUB(NOW(), INTERVAL 30 DAY)");

    safe_w_sql("UPDATE BK_Archers SET BaLastLogin = NOW() WHERE BaId = " . intval($archer->BaId));

    $_SESSION['BK_Token'] = $token;
}

function bk_current_token_hash()
{
    $t = (string) ($_SESSION['BK_Token'] ?? '');
    return ($t !== '' && strlen($t) === 64) ? hash('sha256', $t) : '';
}

function bk_sessions_revoke($archerId, $exceptTokenHash = null)
{
    $sql = "DELETE FROM BK_Sessions WHERE BsArcher = " . intval($archerId);
    if ($exceptTokenHash && preg_match('/^[0-9a-f]{64}$/', $exceptTokenHash)) {
        $sql .= " AND BsTokenHash != '$exceptTokenHash'";
    }
    safe_w_sql($sql);
}

/**
 * Licencié connecté, ou null. Revalide le jeton à chaque appel (le compte a pu
 * être désactivé ou la session révoquée entre deux requêtes) et rafraîchit
 * BsLastSeen au plus une fois par minute.
 */
/**
 * Vue « depuis un autre compte » côté ARCHER (admin serveur, LECTURE SEULE).
 * Lit le MIROIR de session posé par AUTH (convention de session — aucun appel
 * ni require d'AUTH, conformément à l'indépendance des modules) : ce drapeau
 * n'est écrit que par la page admin (admin/impersonate.php), et on exige que
 * l'observateur (AUTH_User) en soit toujours l'auteur. Renvoie le drapeau ou null.
 */
function bk_impersonating()
{
    $i = $_SESSION['AUTH_IMPERSONATE'] ?? null;
    if (!is_array($i) || ($i['type'] ?? '') !== 'archer') return null;
    $by = (string) ($i['by'] ?? '');
    if ($by === '' || $by !== (string) ($_SESSION['AUTH_User'] ?? '')) return null;
    return $i;
}

function bk_current_archer()
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;

    // Observation admin : renvoie l'archer cible chargé par son id, sans passer
    // par BK_Sessions. Les écritures sont bloquées en amont (public/boot.php).
    $imp = bk_impersonating();
    if ($imp) {
        bk_schema();
        $q = safe_r_sql("SELECT a.* FROM BK_Archers a WHERE a.BaId=" . intval($imp['archer']), false, true);
        $r = $q ? safe_fetch($q) : null;
        if ($r) { $r->BK_IMP = 1; $cache = $r; }
        return $cache;
    }

    $hash = bk_current_token_hash();
    if ($hash === '') return null;

    bk_schema();
    $q = safe_r_sql("SELECT a.*, s.BsId, s.BsLastSeen,
            (s.BsCreated  < DATE_SUB(NOW(), INTERVAL " . BK_SESSION_ABS_D . " DAY)
          OR s.BsLastSeen < DATE_SUB(NOW(), INTERVAL " . BK_SESSION_IDLE_H . " HOUR)) AS expired,
            (s.BsLastSeen < DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS stale
        FROM BK_Sessions s
        INNER JOIN BK_Archers a ON a.BaId = s.BsArcher
        WHERE s.BsTokenHash = '$hash'");
    $r = safe_fetch($q);
    if (!$r) return null;

    if ($r->expired || !$r->BaActive) {
        safe_w_sql("DELETE FROM BK_Sessions WHERE BsId = " . intval($r->BsId));
        unset($_SESSION['BK_Token']);
        return null;
    }
    if ($r->stale) {
        safe_w_sql("UPDATE BK_Sessions SET BsLastSeen = NOW(), BsIP = "
            . StrSafe_DB(bk_ip()) . " WHERE BsId = " . intval($r->BsId));
    }

    $cache = $r;
    return $cache;
}

function bk_logout()
{
    $hash = bk_current_token_hash();
    if ($hash !== '') {
        safe_w_sql("DELETE FROM BK_Sessions WHERE BsTokenHash = '$hash'");
    }
    unset($_SESSION['BK_Token']);
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function bk_csrf_token()
{
    if (empty($_SESSION['BK_CSRF'])) $_SESSION['BK_CSRF'] = bin2hex(random_bytes(16));
    return $_SESSION['BK_CSRF'];
}

function bk_csrf_field()
{
    return '<input type="hidden" name="bk_csrf" value="' . bk_csrf_token() . '">';
}

function bk_csrf_check()
{
    return isset($_POST['bk_csrf'], $_SESSION['BK_CSRF'])
        && hash_equals($_SESSION['BK_CSRF'], (string) $_POST['bk_csrf']);
}
