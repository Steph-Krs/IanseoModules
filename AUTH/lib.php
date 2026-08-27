<?php
/**
 * Module AUTH — hébergement multi-comptes ianseo (FFTA).
 *
 * Bibliothèque partagée entre :
 *  - les fichiers déployés dans Modules/Authentication/ (hooks natifs ianseo)
 *  - les pages du module (partage, admin)
 *
 * ATTENTION : ce fichier est inclus très tôt (depuis BlockFunction.php via
 * Common/BlockDefines.php) → uniquement des définitions, aucun effet de bord.
 *
 * Sécurité (v1.1) :
 *  - la session ne contient JAMAIS de secret réutilisable : AUTH_Pwd porte un
 *    jeton aléatoire par connexion, stocké haché (SHA-256) dans AUT_Sessions
 *  - expiration : 12 h d'inactivité, 7 jours absolu ; révocation individuelle
 *    ou globale (changement/RàZ de mot de passe, désactivation, admin)
 *  - 2FA TOTP (RFC 6238) optionnelle, OBLIGATOIRE pour les comptes ADMIN
 *  - anti-brute-force : 8 échecs (mdp ou TOTP) / 15 min par IP ou identifiant
 *  - journal DB + fichier optionnel (fail2ban)
 */

if (defined('AUT_LIB_LOADED')) return;
define('AUT_LIB_LOADED', true);

define('AUT_ROLE_CLUB',  'CLUB');
define('AUT_ROLE_CD',    'CD');
define('AUT_ROLE_CR',    'CR');
define('AUT_ROLE_FED',   'FED');
define('AUT_ROLE_ADMIN', 'ADMIN');

define('AUT_SESSION_IDLE_H', 12);   // heures d'inactivité avant expiration
define('AUT_SESSION_ABS_D',  7);    // durée de vie absolue en jours
define('AUT_2FA_PENDING_S',  300);  // validité de l'étape "mot de passe OK, code attendu"

function aut_roles() {
    return array(
        AUT_ROLE_CLUB  => 'Club (organisateur)',
        AUT_ROLE_CD    => 'Comité départemental',
        AUT_ROLE_CR    => 'Comité régional',
        AUT_ROLE_FED   => 'Fédération',
        AUT_ROLE_ADMIN => 'Administrateur serveur',
    );
}

function aut_module_dir() {
    return __DIR__;
}

function aut_is_localhost() {
    global $CFG;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip == '127.0.0.1' || $ip == '::1' || in_array($ip, $CFG->ACLExcluded ?? array());
}

function aut_local_config() {
    static $cfg = null;
    if (is_null($cfg)) {
        $cfg = array();
        $f = aut_module_dir() . '/config.local.json';
        if (is_file($f)) {
            $cfg = json_decode(file_get_contents($f), true) ?: array();
        }
    }
    return $cfg;
}

/* ------------------------------------------------------------------ */
/* Schéma DB                                                           */
/* ------------------------------------------------------------------ */

function aut_ensure_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!empty($_SESSION['_aut_schema_v8'])) return;

    $q = safe_r_sql("SHOW TABLES LIKE 'AUT_Users'");
    if (!safe_fetch($q)) {
        safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Users (
            AuId            INT AUTO_INCREMENT PRIMARY KEY,
            AuUsername      VARCHAR(64)  NOT NULL,
            AuPassword      VARCHAR(255) NOT NULL,
            AuName          VARCHAR(128) NOT NULL DEFAULT '',
            AuEmail         VARCHAR(128) NOT NULL DEFAULT '',
            AuRole          ENUM('CLUB','CD','CR','FED','ADMIN') NOT NULL DEFAULT 'CLUB',
            AuScope         VARCHAR(16)  NOT NULL DEFAULT '',
            AuActive        TINYINT      NOT NULL DEFAULT 1,
            AuMustChangePwd TINYINT      NOT NULL DEFAULT 1,
            AuTotpSecret    VARCHAR(64)  NOT NULL DEFAULT '',
            AuTotpEnabled   TINYINT      NOT NULL DEFAULT 0,
            AuTotpLastSlot  BIGINT       NOT NULL DEFAULT 0,
            AuStructs       TEXT         NULL,
            AuLastRole      VARCHAR(8)   NOT NULL DEFAULT '',
            AuLastScope     VARCHAR(16)  NOT NULL DEFAULT '',
            AuLastLogin     DATETIME     NULL,
            AuCreated       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY AuUsernameIdx (AuUsername)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // migration v1 → v1.1
        $q = safe_r_sql("SHOW COLUMNS FROM AUT_Users LIKE 'AuTotpSecret'");
        if (!safe_fetch($q)) {
            safe_w_sql("ALTER TABLE AUT_Users
                ADD COLUMN AuTotpSecret   VARCHAR(64) NOT NULL DEFAULT '',
                ADD COLUMN AuTotpEnabled  TINYINT     NOT NULL DEFAULT 0,
                ADD COLUMN AuTotpLastSlot BIGINT      NOT NULL DEFAULT 0");
        }
        // migration v0.1.6 → v0.1.7 : vues multiples (structures SSO + dernière vue)
        $q = safe_r_sql("SHOW COLUMNS FROM AUT_Users LIKE 'AuStructs'");
        if (!safe_fetch($q)) {
            safe_w_sql("ALTER TABLE AUT_Users
                ADD COLUMN AuStructs   TEXT        NULL,
                ADD COLUMN AuLastRole  VARCHAR(8)  NOT NULL DEFAULT '',
                ADD COLUMN AuLastScope VARCHAR(16) NOT NULL DEFAULT ''");
        }
    }

    // AUT_Share = registre des compétitions : propriétaire (rôle + périmètre :
    // club, CD, CR ou FED) + drapeaux de partage montant. Une ligne par compétition.
    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Share (
        AsToCode     VARCHAR(50) NOT NULL PRIMARY KEY,
        AsOwnerRole  VARCHAR(8)  NOT NULL DEFAULT '',
        AsOwnerScope VARCHAR(16) NOT NULL DEFAULT '',
        AsOwnerUser  VARCHAR(64) NOT NULL DEFAULT '',
        AsShareCD    TINYINT NOT NULL DEFAULT 0,
        AsShareCR    TINYINT NOT NULL DEFAULT 0,
        AsShareFED   TINYINT NOT NULL DEFAULT 0,
        AsUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Share LIKE 'AsOwnerScope'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Share
            ADD COLUMN AsOwnerRole  VARCHAR(8)  NOT NULL DEFAULT '' AFTER AsToCode,
            ADD COLUMN AsOwnerScope VARCHAR(16) NOT NULL DEFAULT '' AFTER AsOwnerRole,
            ADD COLUMN AsOwnerUser  VARCHAR(64) NOT NULL DEFAULT '' AFTER AsOwnerScope");
    } else {
        $q = safe_r_sql("SHOW COLUMNS FROM AUT_Share LIKE 'AsOwnerRole'");
        if (!safe_fetch($q)) {
            safe_w_sql("ALTER TABLE AUT_Share
                ADD COLUMN AsOwnerRole VARCHAR(8) NOT NULL DEFAULT '' AFTER AsToCode");
            safe_w_sql("UPDATE AUT_Share SET AsOwnerRole='CLUB' WHERE AsOwnerScope!='' AND AsOwnerRole=''");
        }
    }

    // partage descendant : clubs invités à accéder à une compétition
    // (plusieurs clubs possibles ; géré par le propriétaire ou un admin)
    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_ShareClub (
        AscToCode VARCHAR(50) NOT NULL,
        AscScope  VARCHAR(16) NOT NULL,
        AscAdded  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (AscToCode, AscScope),
        KEY AscScopeIdx (AscScope)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // revendications de codes en cours de création (nettoyées à l'adoption ou à 24 h)
    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Claim (
        AcCode  VARCHAR(50) NOT NULL PRIMARY KEY,
        AcRole  VARCHAR(8)  NOT NULL DEFAULT 'CLUB',
        AcScope VARCHAR(16) NOT NULL DEFAULT '',
        AcUser  VARCHAR(64) NOT NULL DEFAULT '',
        AcWhen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY AcUserIdx (AcUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Claim LIKE 'AcRole'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Claim ADD COLUMN AcRole VARCHAR(8) NOT NULL DEFAULT 'CLUB' AFTER AcCode");
    }

    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Log (
        AlId    INT AUTO_INCREMENT PRIMARY KEY,
        AlWhen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AlUser  VARCHAR(64) NOT NULL DEFAULT '',
        AlIP    VARCHAR(45) NOT NULL DEFAULT '',
        AlEvent VARCHAR(32) NOT NULL,
        KEY AlWhenIdx (AlWhen),
        KEY AlUserIdx (AlUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Sessions (
        AsnId        INT AUTO_INCREMENT PRIMARY KEY,
        AsnUser      INT NOT NULL,
        AsnTokenHash CHAR(64) NOT NULL,
        AsnRole      VARCHAR(8)  NOT NULL DEFAULT '',
        AsnScope     VARCHAR(16) NOT NULL DEFAULT '',
        AsnCreated   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AsnLastSeen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AsnIP        VARCHAR(45)  NOT NULL DEFAULT '',
        AsnUA        VARCHAR(160) NOT NULL DEFAULT '',
        UNIQUE KEY AsnTokenIdx (AsnTokenHash),
        KEY AsnUserIdx (AsnUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // migration v1.1 → v1.2 (rôle/périmètre par session, pour le choix de
    // structure SSO façon espace dirigeant)
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Sessions LIKE 'AsnRole'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Sessions
            ADD COLUMN AsnRole  VARCHAR(8)  NOT NULL DEFAULT '' AFTER AsnTokenHash,
            ADD COLUMN AsnScope VARCHAR(16) NOT NULL DEFAULT '' AFTER AsnRole");
    }

    // v… : observation « depuis un autre compte » (impersonation) — persistée par
    // session pour survivre à CreateTourSession (voir aut_imp_*). JSON ou NULL.
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Sessions LIKE 'AsnImp'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Sessions ADD COLUMN AsnImp TEXT NULL DEFAULT NULL AFTER AsnScope");
    }

    // v6 : tickets (bugs / demandes d'évolution) déposés par les organisateurs,
    // triés par l'administrateur du serveur. TkScore = indice de précision calculé
    // au dépôt (favorise les demandes bien décrites).
    // v7 : TkResponse = réponse de l'admin visible du déposant ; TkChannel = origine
    // ('org' organisateur / 'archer' compétiteur) — le déposant ne voit QUE les siens.
    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Tickets (
        TkId       INT AUTO_INCREMENT PRIMARY KEY,
        TkCreated  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        TkUser     VARCHAR(64)  NOT NULL DEFAULT '',
        TkRole     VARCHAR(48)  NOT NULL DEFAULT '',
        TkChannel  VARCHAR(8)   NOT NULL DEFAULT 'org',
        TkKind     VARCHAR(10)  NOT NULL DEFAULT 'bug',
        TkTitle    VARCHAR(160) NOT NULL DEFAULT '',
        TkBody     TEXT NULL,
        TkExpected TEXT NULL,
        TkPage     VARCHAR(255) NOT NULL DEFAULT '',
        TkTour     VARCHAR(160) NOT NULL DEFAULT '',
        TkStatus   VARCHAR(12)  NOT NULL DEFAULT 'new',
        TkResponse TEXT NULL,
        TkScore    SMALLINT     NOT NULL DEFAULT 0,
        TkUpdated  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY TkCreatedIdx (TkCreated),
        KEY TkStatusIdx (TkStatus),
        KEY TkWhoIdx (TkChannel, TkUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // migration v6 → v7 pour une table existante
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Tickets LIKE 'TkResponse'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Tickets
            ADD COLUMN TkChannel  VARCHAR(8) NOT NULL DEFAULT 'org' AFTER TkRole,
            ADD COLUMN TkResponse TEXT NULL AFTER TkStatus,
            ADD KEY TkWhoIdx (TkChannel, TkUser)");
    }
    // v8 : compétition concernée par le ticket (libellé « Nom (Code) »), renseignée à la
    // création si une compétition est sélectionnée (organisateur : compétition ouverte ;
    // archer : la fiche d'où il vient). Vide sinon. Non modifiable ensuite.
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Tickets LIKE 'TkTour'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Tickets ADD COLUMN TkTour VARCHAR(160) NOT NULL DEFAULT '' AFTER TkPage");
    }

    $_SESSION['_aut_schema_v8'] = true;
}

/* ------------------------------------------------------------------ */
/* Utilisateurs / journal                                              */
/* ------------------------------------------------------------------ */

function aut_get_user($username) {
    aut_ensure_schema();
    $q = safe_r_sql("SELECT * FROM AUT_Users WHERE AuUsername=" . StrSafe_DB($username));
    $r = safe_fetch($q);
    return $r ?: null;
}

function aut_log($event, $user = '', $ip = null) {
    aut_ensure_schema();
    if (is_null($ip)) $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    safe_w_sql("INSERT INTO AUT_Log (AlEvent, AlUser, AlIP) VALUES ("
        . StrSafe_DB($event) . "," . StrSafe_DB(substr($user, 0, 64)) . "," . StrSafe_DB(substr($ip, 0, 45)) . ")");

    // fichier optionnel pour fail2ban ({"log_file": "/var/log/ianseo-auth.log"})
    $f = aut_local_config()['log_file'] ?? '';
    if ($f) {
        $clean = function ($s) { return preg_replace('/[^\x20-\x7E]/', '', (string)$s); };
        @file_put_contents($f,
            date('Y-m-d H:i:s') . ' ianseo-auth ' . $clean($ip) . ' ' . $clean($user) . ' ' . $clean($event) . "\n",
            FILE_APPEND | LOCK_EX);
    }
}

/* ------------------------------------------------------------------ */
/* Rétention des journaux (AUT_Log + BK_Log)                           */
/*                                                                     */
/* Sans purge, les journaux grossissent indéfiniment (à l'échelle      */
/* fédérale : des millions de lignes/an) et la mention RGPD « conservés */
/* quelques mois » serait fausse. Durée configurable, défaut 180 j.    */
/* La sécurité (anti-bruteforce) n'utilise QUE la fenêtre 15 min → non  */
/* affectée. Chunké (LIMIT) pour éviter une suppression massive d'un    */
/* coup ; les exécutions répétées rattrapent le retard.                */
/* ------------------------------------------------------------------ */

/** Durée de rétention des journaux en jours (config.local.json → "log_retention_days", défaut 180). */
function aut_log_retention_days() {
    $d = intval(aut_local_config()['log_retention_days'] ?? 180);
    return ($d >= 7 && $d <= 3650) ? $d : 180;   // borne : 1 semaine à 10 ans ; sinon défaut
}

/** Supprime les événements plus vieux que la rétention (AUT_Log, et BK_Log si présent). */
function aut_log_purge() {
    $days = aut_log_retention_days();
    safe_w_sql("DELETE FROM AUT_Log WHERE AlWhen < DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 20000");
    $r = safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BK_Log'"));
    if ($r) safe_w_sql("DELETE FROM BK_Log WHERE BlWhen < DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 20000");
    // Mesure d'audience : UsageSeen suit la rétention des journaux, agrégats à 25 mois.
    require_once __DIR__ . '/stats-usage.php';
    if (function_exists('aut_stats_purge')) aut_stats_purge();
}

/** Purge AU PLUS une fois par jour (marqueur fichier), pour ne pas la refaire à chaque requête. */
function aut_log_purge_daily() {
    $marker = sys_get_temp_dir() . '/aut_logpurge_' . substr(hash('sha256', __DIR__), 0, 16);
    $today  = date('Y-m-d');
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === $today) return;
    @file_put_contents($marker, $today);   // marque avant de purger : une seule tentative/jour même si la purge échoue
    aut_log_purge();
}

/* ------------------------------------------------------------------ */
/* Tickets (bugs / demandes d'évolution)                               */
/* ------------------------------------------------------------------ */

/** Types de tickets. */
function aut_ticket_kinds() {
    return array('bug' => 'Bug', 'evolution' => 'Évolution');
}

/** Statuts de tickets. Le déposant ne peut modifier que tant que 'new'. */
function aut_ticket_statuses() {
    return array('new' => 'Nouveau', 'in_progress' => 'En cours', 'done' => 'Traité', 'rejected' => 'Rejeté');
}

/** Un ticket est-il modifiable par son déposant ? (statut 'new' + propriété). */
function aut_ticket_editable($t, $user, $channel) {
    if (!$t) return false;
    $channel = ($channel === 'archer') ? 'archer' : 'org';
    return $t->TkStatus === 'new' && $t->TkChannel === $channel
        && $t->TkUser === substr((string) $user, 0, 64);
}

/**
 * Indice de précision (0-100) : favorise les demandes bien décrites — titre
 * explicite, corps détaillé, « comment / rendu attendu » renseigné.
 */
function aut_ticket_score($title, $body, $expected, $page) {
    $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen(trim((string) $s)) : strlen(trim((string) $s)); };
    $s = 0;
    if ($len($title) >= 6) $s += 10;
    $s += min(35, intdiv($len($body), 6));
    if (trim((string) $expected) !== '') $s += 20;
    $s += min(25, intdiv($len($expected), 6));
    if (trim((string) $page) !== '') $s += 10;
    return max(0, min(100, $s));
}

/** Dépose un ticket. $channel : 'org' (organisateur) ou 'archer' (compétiteur).
 *  $tour : libellé de la compétition concernée (« Nom (Code) »), vide si aucune. */
function aut_ticket_add($kind, $title, $body, $expected, $page, $user, $role, $channel = 'org', $tour = '') {
    aut_ensure_schema();
    $kind = array_key_exists($kind, aut_ticket_kinds()) ? $kind : 'bug';
    $channel = ($channel === 'archer') ? 'archer' : 'org';
    $score = aut_ticket_score($title, $body, $expected, $page);
    safe_w_sql("INSERT INTO AUT_Tickets (TkUser, TkRole, TkChannel, TkKind, TkTitle, TkBody, TkExpected, TkPage, TkTour, TkScore)
        VALUES (" . StrSafe_DB(substr((string) $user, 0, 64)) . ","
        . StrSafe_DB(substr((string) $role, 0, 48)) . ","
        . StrSafe_DB($channel) . ","
        . StrSafe_DB($kind) . ","
        . StrSafe_DB(substr(trim((string) $title), 0, 160)) . ","
        . StrSafe_DB(substr((string) $body, 0, 5000)) . ","
        . StrSafe_DB(substr((string) $expected, 0, 5000)) . ","
        . StrSafe_DB(substr((string) $page, 0, 255)) . ","
        . StrSafe_DB(substr((string) $tour, 0, 160)) . ","
        . intval($score) . ")");
    aut_log('TICKET_NEW', $user);
}

/** Liste des tickets, triés par date ('date') ou précision ('score'), filtrés par statut. */
function aut_ticket_list($sort = 'date', $status = '') {
    aut_ensure_schema();
    $w = array_key_exists($status, aut_ticket_statuses()) ? "WHERE TkStatus = " . StrSafe_DB($status) : "";
    $order = ($sort === 'score') ? "TkScore DESC, TkCreated DESC" : "TkCreated DESC";
    $q = safe_r_sql("SELECT * FROM AUT_Tickets $w ORDER BY $order");
    $out = array();
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/** Tickets d'un déposant (pour qu'il suive les siens et leur réponse). */
function aut_ticket_my($user, $channel = 'org') {
    aut_ensure_schema();
    $channel = ($channel === 'archer') ? 'archer' : 'org';
    $q = safe_r_sql("SELECT * FROM AUT_Tickets
        WHERE TkChannel = " . StrSafe_DB($channel) . "
          AND TkUser = " . StrSafe_DB(substr((string) $user, 0, 64)) . "
        ORDER BY TkCreated DESC");
    $out = array();
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/** Réponse de l'admin au déposant (visible par lui). */
function aut_ticket_set_response($id, $text) {
    aut_ensure_schema();
    safe_w_sql("UPDATE AUT_Tickets SET TkResponse = " . StrSafe_DB(substr((string) $text, 0, 5000))
        . " WHERE TkId = " . intval($id));
}

/** Un ticket par son id, ou null. */
function aut_ticket_get($id) {
    aut_ensure_schema();
    return safe_fetch(safe_r_sql("SELECT * FROM AUT_Tickets WHERE TkId = " . intval($id))) ?: null;
}

/**
 * Modification d'un ticket par SON déposant. Refuse (retourne false) si le ticket
 * n'appartient pas à ($user, $channel) ou n'est plus 'new' (pris en charge/clôturé) —
 * garde revérifiée ICI, jamais confiée au client. Recalcule l'indice de précision.
 */
function aut_ticket_update($id, $user, $channel, $kind, $title, $body, $expected, $page) {
    aut_ensure_schema();
    $id = intval($id);
    $t = aut_ticket_get($id);
    if (!aut_ticket_editable($t, $user, $channel)) return false;
    $kind = array_key_exists($kind, aut_ticket_kinds()) ? $kind : 'bug';
    $score = aut_ticket_score($title, $body, $expected, $page);
    safe_w_sql("UPDATE AUT_Tickets SET
        TkKind = " . StrSafe_DB($kind) . ",
        TkTitle = " . StrSafe_DB(substr(trim((string) $title), 0, 160)) . ",
        TkBody = " . StrSafe_DB(substr((string) $body, 0, 5000)) . ",
        TkExpected = " . StrSafe_DB(substr((string) $expected, 0, 5000)) . ",
        TkPage = " . StrSafe_DB(substr((string) $page, 0, 255)) . ",
        TkScore = " . intval($score) . "
        WHERE TkId = $id");
    return true;
}

/** Change le statut d'un ticket. */
function aut_ticket_set_status($id, $status) {
    aut_ensure_schema();
    if (!array_key_exists($status, aut_ticket_statuses())) return;
    safe_w_sql("UPDATE AUT_Tickets SET TkStatus = " . StrSafe_DB($status) . " WHERE TkId = " . intval($id));
}

/** Supprime un ticket. */
function aut_ticket_delete($id) {
    aut_ensure_schema();
    safe_w_sql("DELETE FROM AUT_Tickets WHERE TkId = " . intval($id));
}

/** Comptes par statut (+ 'all'). */
function aut_ticket_counts() {
    aut_ensure_schema();
    $c = array('new' => 0, 'in_progress' => 0, 'done' => 0, 'rejected' => 0, 'all' => 0);
    $q = safe_r_sql("SELECT TkStatus, COUNT(*) n FROM AUT_Tickets GROUP BY TkStatus");
    while ($r = safe_fetch($q)) { if (isset($c[$r->TkStatus])) $c[$r->TkStatus] = intval($r->n); $c['all'] += intval($r->n); }
    return $c;
}

/** Compte les échecs mot de passe ET TOTP des 15 dernières minutes. */
function aut_too_many_failures($username) {
    aut_ensure_schema();
    $ip = StrSafe_DB($_SERVER['REMOTE_ADDR'] ?? '');
    $un = StrSafe_DB($username);
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM AUT_Log
        WHERE AlEvent IN ('LOGIN_FAIL','TOTP_FAIL') AND AlWhen > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND (AlIP=$ip OR AlUser=$un)");
    $r = safe_fetch($q);
    return $r && $r->n >= 8;
}

function aut_password_ok($pwd) {
    return strlen($pwd) >= 10 && preg_match('/[a-z]/i', $pwd) && preg_match('/[0-9]/', $pwd);
}

function aut_gen_password($len = 12) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

/**
 * Format des agréments FFTA : LLDDCCC (ligue 2 + département 2 + club 3),
 * ex. 0760171 = ligue 07, dept 60, club 171. D'où :
 *  - CLUB : scope = agrément complet (préfixe des codes de compétition)
 *  - CD   : scope = 2 chiffres du département → codes LIKE '__DD%'
 *  - CR   : scope = 2 chiffres de la ligue    → codes LIKE 'LL%'
 * Un scope contenant % ou _ est utilisé tel quel comme motif LIKE (cas
 * particuliers DOM-TOM ou découpages atypiques, réglé par un admin).
 */
function aut_scope_error($role, $scope) {
    if (in_array($role, array(AUT_ROLE_FED, AUT_ROLE_ADMIN))) return '';
    if (preg_match('/^[0-9A-Za-z_%]{2,12}$/', $scope) && preg_match('/[_%]/', $scope)) return '';
    if (!preg_match('/^[0-9A-Za-z]{2,10}$/', $scope)) {
        return 'Le périmètre doit contenir 2 à 10 caractères alphanumériques (ou un motif avec % / _).';
    }
    if ($role == AUT_ROLE_CLUB && strlen($scope) < 5) return 'Périmètre club : n° d\'agrément complet attendu (ex. 0760171).';
    if ($role == AUT_ROLE_CD && strlen($scope) != 2)  return 'Périmètre CD : 2 chiffres du département attendus (ex. 60 pour l\'Oise).';
    if ($role == AUT_ROLE_CR && strlen($scope) != 2)  return 'Périmètre CR : 2 chiffres de la ligue/région attendus (ex. 07).';
    return '';
}

/** Motif LIKE des codes de compétition couverts par un rôle/périmètre. */
function aut_scope_like($role, $scope) {
    if (preg_match('/[_%]/', $scope)) return $scope;           // motif expert tel quel
    if ($role == AUT_ROLE_CD) return '__' . $scope . '%';      // dept en position 3-4
    return $scope . '%';                                       // CLUB / CR : préfixe
}

/**
 * Arborescence FFTA : région (2 chiffres, = préfixe ligue des agréments et
 * n° de CR) → départements qui la composent (n° de dept sur 2 caractères).
 * Sert à retrouver les CD d'une ligue (un CD a pour périmètre son n° de dept,
 * sans préfixe ligue). Éditable via config.local.json → "regions" (fusionné).
 * Les CR d'outre-mer sans CD listé ne rattachent que les clubs (par préfixe).
 */
function aut_ffta_regions() {
    static $map = null;
    if (!is_null($map)) return $map;
    $map = array(
        '01' => array('69','01','03','07','43','42','26','15','74','38','63','73'), // AURA
        '02' => array('21','25','39','70','58','71','90','89'),                     // Bourgogne-FC
        '03' => array('22','29','35','56'),                                         // Bretagne
        '04' => array('18','28','36','37','41','45'),                               // Centre-Val de Loire
        '05' => array('2A','2B'),                                                   // Corse
        '06' => array('08','10','67','52','51','54','55','57','88','68'),           // Grand Est
        '07' => array('02','59','60','62','80'),                                    // Hauts-de-France
        '08' => array('91','92','75','77','93','95','94','78'),                     // Île-de-France
        '09' => array('14','27','61','50','76'),                                    // Normandie
        '10' => array('16','17','19','23','79','24','33','87','40','47','64','86'), // Nouvelle-Aquitaine
        '11' => array('11','12','48','30','32','31','65','34','46','66','81','82'), // Occitanie
        '12' => array('85','44','49','53','72'),                                    // Pays de la Loire
        '13' => array('04','06','13','83','84'),                                    // PACA
        '38' => array('992'),                                                       // Nouvelle-Calédonie
    );
    foreach ((aut_local_config()['regions'] ?? array()) as $reg => $depts) {
        if (is_array($depts)) $map[$reg] = $depts;
    }
    return $map;
}

/** Départements d'une région (vide si inconnue). */
function aut_region_depts($region) {
    $m = aut_ffta_regions();
    return $m[$region] ?? array();
}

/* ------------------------------------------------------------------ */
/* TOTP (RFC 6238) — sans dépendance externe                           */
/* ------------------------------------------------------------------ */

function aut_base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    $out = '';
    foreach (str_split($b32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) == 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function aut_totp_new_secret() {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $alphabet[random_int(0, 31)];
    return $s;
}

function aut_totp_code($secretB32, $slot) {
    $key = aut_base32_decode($secretB32);
    $bin = pack('N', 0) . pack('N', $slot);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $code = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/**
 * Vérifie un code (fenêtre ±1 pas de 30 s). $minSlot = dernier slot déjà
 * utilisé (anti-rejeu) ; $usedSlot reçoit le slot accepté.
 */
function aut_totp_verify($secretB32, $code, $minSlot, &$usedSlot) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) != 6 || $secretB32 === '') return false;
    $slot = (int)floor(time() / 30);
    foreach (array(0, -1, 1) as $d) {
        $s = $slot + $d;
        if ($s > $minSlot && hash_equals(aut_totp_code($secretB32, $s), $code)) {
            $usedSlot = $s;
            return true;
        }
    }
    return false;
}

/**
 * DIAGNOSTIC (jamais une acceptation). Un code TOTP correct rejeté vient presque
 * toujours d'une HORLOGE SERVEUR déréglée : le code du téléphone est calculé à
 * l'heure réelle, le serveur à son heure à lui. Après un échec, on cherche dans
 * une large fenêtre (±$maxSlots pas de 30 s) le décalage auquel le code AURAIT
 * correspondu, pour transformer un « code incorrect » énigmatique en diagnostic
 * clair (« horloge décalée de ~N min, synchronisez le NTP »). Retourne l'écart en
 * SECONDES (slot trouvé − slot courant), ou null. N'accepte JAMAIS : la
 * vérification reste aut_totp_verify (fenêtre ±1). ±120 pas = ±1 h.
 */
function aut_totp_skew($secretB32, $code, $maxSlots = 120) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) != 6 || (string)$secretB32 === '') return null;
    $slot = (int)floor(time() / 30);
    for ($d = -$maxSlots; $d <= $maxSlots; $d++) {
        if (abs($d) <= 1) continue;   // la fenêtre normale a déjà tranché
        if (hash_equals(aut_totp_code($secretB32, $slot + $d), $code)) return $d * 30;
    }
    return null;
}

function aut_totp_uri($username, $secret) {
    $issuer = rawurlencode('ianseo FFTA');
    return 'otpauth://totp/' . $issuer . ':' . rawurlencode($username)
        . '?secret=' . $secret . '&issuer=' . $issuer . '&digits=6&period=30';
}

/**
 * QR code (SVG inline) d'un texte, via l'encodeur QR de TCPDF déjà fourni par
 * ianseo — aucune dépendance externe, le secret ne quitte jamais le serveur.
 * Retourne '' si l'encodeur est indisponible (repli sur la saisie manuelle).
 */
function aut_qr_svg($text, $sizePx = 210) {
    global $CFG;
    $file = $CFG->DOCUMENT_PATH . 'Common/tcpdf/include/barcodes/qrcode.php';
    if (!is_file($file)) return '';
    require_once($file);
    try {
        $qr = new QRcode($text, 'M');
        $arr = $qr->getBarcodeArray();
    } catch (\Throwable $e) {
        return '';
    }
    if (empty($arr['num_rows']) || empty($arr['bcode'])) return '';
    $rows = $arr['num_rows'];
    $cols = $arr['num_cols'];
    $margin = 4;
    $dim = max($rows, $cols) + 2 * $margin;
    $path = '';
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if (!empty($arr['bcode'][$r][$c])) {
                $path .= 'M' . ($c + $margin) . ' ' . ($r + $margin) . 'h1v1h-1z';
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $sizePx . '" height="' . $sizePx . '"'
        . ' viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR code 2FA">'
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/>'
        . '<path fill="#000" d="' . $path . '"/></svg>';
}

/* ------------------------------------------------------------------ */
/* Sessions applicatives (jetons révocables)                           */
/* ------------------------------------------------------------------ */

/**
 * Ouvre une session : jeton aléatoire stocké haché en DB, valeur en clair
 * uniquement dans la session PHP (clé AUTH_Pwd — seule clé, avec AUTH_User,
 * qui survit à CreateTourSession/EraseTourSession du cœur ianseo).
 * $role/$scope : structure choisie à la connexion SSO (sinon ceux du compte).
 */
function aut_session_open($u, $role = '', $scope = '') {
    $token = bin2hex(random_bytes(32));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160);
    safe_w_sql("INSERT INTO AUT_Sessions (AsnUser, AsnTokenHash, AsnRole, AsnScope, AsnIP, AsnUA) VALUES (
        {$u->AuId}, '" . hash('sha256', $token) . "',"
        . StrSafe_DB($role) . "," . StrSafe_DB($scope) . ","
        . StrSafe_DB(substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)) . "," . StrSafe_DB($ua) . ")");
    // ménage opportuniste des sessions mortes
    safe_w_sql("DELETE FROM AUT_Sessions WHERE AsnLastSeen < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $_SESSION['AUTH_User'] = $u->AuUsername;
    $_SESSION['AUTH_Pwd']  = $token;
    aut_extranet_bind();    // le cookie extranet ouvert au login prend son chemin définitif
    aut_dirigeant_bind();   // idem pour le cookie Espace Dirigeant capté du login SSO
    aut_session_apply($u, $role, $scope);
}

/**
 * Valide le jeton courant. Retourne l'objet session DB ou null.
 * Expiration calculée côté SQL (NOW()) pour éviter les décalages de fuseau
 * (ianseo change le time_zone MySQL par compétition).
 */
function aut_session_validate($u) {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    if ($token === '' || strlen($token) != 64) return null;
    $hash = hash('sha256', $token);
    $q = safe_r_sql("SELECT *,
            (AsnCreated  < DATE_SUB(NOW(), INTERVAL " . AUT_SESSION_ABS_D . " DAY)
          OR AsnLastSeen < DATE_SUB(NOW(), INTERVAL " . AUT_SESSION_IDLE_H . " HOUR)) AS expired,
            (AsnLastSeen < DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS stale
        FROM AUT_Sessions WHERE AsnTokenHash='$hash' AND AsnUser={$u->AuId}");
    $s = safe_fetch($q);
    if (!$s) return null;
    if ($s->expired) {
        safe_w_sql("DELETE FROM AUT_Sessions WHERE AsnId={$s->AsnId}");
        return null;
    }
    if ($s->stale) {
        safe_w_sql("UPDATE AUT_Sessions SET AsnLastSeen=NOW(),
            AsnIP=" . StrSafe_DB(substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)) . " WHERE AsnId={$s->AsnId}");
    }
    return $s;
}

/** Révoque les sessions d'un utilisateur ($exceptTokenHash : garder la courante). */
function aut_sessions_revoke($userId, $exceptTokenHash = null) {
    $userId = intval($userId);
    $sql = "DELETE FROM AUT_Sessions WHERE AsnUser=$userId";
    if ($exceptTokenHash && preg_match('/^[0-9a-f]{64}$/', $exceptTokenHash)) {
        $sql .= " AND AsnTokenHash != '$exceptTokenHash'";
    }
    safe_w_sql($sql);
}

function aut_current_token_hash() {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    return $token !== '' ? hash('sha256', $token) : '';
}

/* ------------------------------------------------------------------ */
/* Session / droits                                                    */
/* ------------------------------------------------------------------ */

/**
 * Liste AUTH_COMP au format attendu par le cœur ianseo (codes exacts).
 * Le nommage des compétitions est LIBRE : la propriété vient du registre
 * AUT_Share (rôle + périmètre du créateur : club, CD, CR ou FED).
 *  - chacun voit les compétitions dont il est PROPRIÉTAIRE ;
 *  - CLUB : + celles où son agrément est INVITÉ (AUT_ShareClub — partage
 *    descendant d'un CD/CR/FED ou d'un autre club, ex. aide à la saisie) ;
 *  - CD : + compétitions de CLUBS du département partagées (AsShareCD) ;
 *  - CR : + compétitions de CLUBS de la ligue (préfixe agrément) ET de CD de
 *    la ligue (via l'arborescence dept→région) partagées (AsShareCR) ;
 *  - FED : + toutes les compétitions partagées FFTA.
 */
function aut_compute_comp($role, $scope) {
    if (!in_array($role, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) return array();

    $owned = "(AsOwnerRole=" . StrSafe_DB($role) . " AND AsOwnerScope=" . StrSafe_DB($scope) . ")";
    if ($role == AUT_ROLE_CLUB) {
        $where = $owned;
    } elseif ($role == AUT_ROLE_FED) {
        $where = "($owned OR AsShareFED=1)";
    } elseif ($role == AUT_ROLE_CD) {
        $where = "($owned OR (AsShareCD=1 AND AsOwnerRole='CLUB' AND AsOwnerScope LIKE "
            . StrSafe_DB(aut_scope_like($role, $scope)) . "))";
    } else { // CR : clubs de la ligue (préfixe) + CD des départements de la ligue
        $parts = array($owned);
        $parts[] = "(AsShareCR=1 AND AsOwnerRole='CLUB' AND AsOwnerScope LIKE "
            . StrSafe_DB(aut_scope_like($role, $scope)) . ")";
        $depts = aut_region_depts($scope);
        if ($depts) {
            $in = implode(',', array_map('StrSafe_DB', $depts));
            $parts[] = "(AsShareCR=1 AND AsOwnerRole='CD' AND AsOwnerScope IN ($in))";
        }
        $where = '(' . implode(' OR ', $parts) . ')';
    }

    $codes = array();
    $q = safe_r_sql("SELECT ToCode FROM Tournament
        INNER JOIN AUT_Share ON AsToCode COLLATE utf8mb4_unicode_ci = ToCode
        WHERE $where");
    while ($r = safe_fetch($q)) $codes[$r->ToCode] = true;

    if ($role == AUT_ROLE_CLUB) {
        $q = safe_r_sql("SELECT ToCode FROM Tournament
            INNER JOIN AUT_ShareClub ON AscToCode COLLATE utf8mb4_unicode_ci = ToCode
            WHERE AscScope=" . StrSafe_DB($scope));
        while ($r = safe_fetch($q)) $codes[$r->ToCode] = true;
    }
    return array_keys($codes);
}

/**
 * État d'un code de compétition pour une structure (rôle + périmètre) :
 * 'free' (disponible), 'own' (sa compétition), 'other' (autre structure),
 * 'unowned' (existe sans propriétaire → admin), 'invalid'.
 * Purge au passage la ligne de registre orpheline d'une compétition supprimée.
 */
function aut_code_status($code, $role, $scope) {
    aut_ensure_schema();
    $code = trim($code);
    if ($code === '') return 'invalid';

    $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($code));
    $exists = (bool)safe_fetch($q);
    $q = safe_r_sql("SELECT AsOwnerRole, AsOwnerScope FROM AUT_Share WHERE AsToCode=" . StrSafe_DB($code));
    $own = safe_fetch($q);

    if (!$exists) {
        if ($own) {
            safe_w_sql("DELETE FROM AUT_Share WHERE AsToCode=" . StrSafe_DB($code));
            safe_w_sql("DELETE FROM AUT_ShareClub WHERE AscToCode=" . StrSafe_DB($code));
        }
        return 'free';
    }
    if (!$own || $own->AsOwnerRole === '') return 'unowned';
    return (strcasecmp($own->AsOwnerRole, $role) === 0 && strcasecmp($own->AsOwnerScope, $scope) === 0)
        ? 'own' : 'other';
}

/** Message utilisateur pour un refus de code. */
function aut_code_reason($state, $code, $isImport = false) {
    $c = '« ' . htmlspecialchars($code) . ' »';
    switch ($state) {
        case 'invalid':
            return 'Code de compétition vide ou invalide.';
        case 'other':
            return "Le code $c est déjà utilisé par la compétition d'une autre structure (club, comité ou fédération). Choisissez un autre code.";
        case 'unowned':
            return "Le code $c est déjà utilisé par une compétition existante (gérée par l'administrateur du serveur). Choisissez un autre code.";
        case 'own':
            return $isImport ? '' : "Le code $c est déjà utilisé par une de vos compétitions. Choisissez un autre code — ou passez par Compétition → Importer si vous vouliez restaurer une sauvegarde de celle-ci.";
    }
    return '';
}

/**
 * Une structure (club, CD, CR, FED) peut-elle créer/importer une compétition
 * sous ce code ? Règle anti-écrasement : un code déjà porté par une
 * compétition existante n'est JAMAIS réutilisable par une autre structure ;
 * le propriétaire ne peut le réutiliser que par ré-import (restauration de sa
 * propre sauvegarde), pas par une nouvelle création. Code libre →
 * revendication enregistrée, la propriété est actée par aut_adopt_claims()
 * une fois la compétition créée. $reason reçoit le message en cas de refus.
 */
function aut_can_use_code($code, $role, $scope, $user, $isImport, &$reason = '') {
    $state = aut_code_status($code, $role, $scope);
    if ($state == 'free') {
        safe_w_sql("INSERT INTO AUT_Claim (AcCode, AcRole, AcScope, AcUser) VALUES ("
            . StrSafe_DB(trim($code)) . "," . StrSafe_DB($role) . "," . StrSafe_DB($scope) . "," . StrSafe_DB($user) . ")
            ON DUPLICATE KEY UPDATE AcRole=VALUES(AcRole), AcScope=VALUES(AcScope), AcUser=VALUES(AcUser), AcWhen=NOW()");
        return true;
    }
    if ($state == 'own' && $isImport) return true;
    $reason = aut_code_reason($state, $code, $isImport);
    return false;
}

/* ------------------------------------------------------------------ */
/* Vues (une personne = plusieurs structures, bascule à la volée)      */
/* ------------------------------------------------------------------ */

/** Niveau d'une vue — sert à déterminer « le droit maximum ». */
function aut_view_rank($role) {
    $ranks = array(AUT_ROLE_ADMIN => 5, AUT_ROLE_FED => 4, AUT_ROLE_CR => 3, AUT_ROLE_CD => 2, AUT_ROLE_CLUB => 1);
    return $ranks[$role] ?? 0;
}

/**
 * Vues disponibles pour un compte, triées du niveau le plus haut au plus bas :
 *  - compte ADMIN : vue Administrateur + ses structures espace dirigeant ;
 *  - compte SSO   : ses structures (AuStructs, rafraîchies à chaque login) ;
 *  - compte LOCAL non admin : sa seule structure (AuRole/AuScope).
 * Un compte SSO non admin sans structure active n'a AUCUNE vue (accès refusé).
 */
function aut_user_views($u) {
    $views = array();
    if ($u->AuRole == AUT_ROLE_ADMIN) {
        $views[] = array('role' => AUT_ROLE_ADMIN, 'scope' => '', 'label' => 'Administrateur serveur');
    }
    foreach ((json_decode($u->AuStructs ?? '', true) ?: array()) as $st) {
        if (!is_array($st) || !in_array($st['role'] ?? '', array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) continue;
        $views[] = array('role' => $st['role'], 'scope' => (string)($st['scope'] ?? ''),
                         'label' => (string)($st['label'] ?? ($st['role'] . ' ' . ($st['scope'] ?? ''))));
    }
    if (!count($views) && $u->AuPassword !== '' && in_array($u->AuRole, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) {
        $views[] = array('role' => $u->AuRole, 'scope' => $u->AuScope,
                         'label' => aut_roles()[$u->AuRole] . ($u->AuScope !== '' ? ' ' . $u->AuScope : ''));
    }
    // tri niveau décroissant (stable) + dédoublonnage rôle+périmètre
    $seen = array();
    $out = array();
    foreach (array(5, 4, 3, 2, 1) as $rank) {
        foreach ($views as $v) {
            $k = $v['role'] . '|' . strtolower($v['scope']);
            if (aut_view_rank($v['role']) == $rank && empty($seen[$k])) {
                $seen[$k] = true;
                $out[] = $v;
            }
        }
    }
    return $out;
}

/**
 * Vue à activer à la connexion : la dernière utilisée si elle est encore
 * disponible, sinon le niveau maximum. null si aucune vue.
 */
function aut_pick_view($u, $views = null) {
    if (is_null($views)) $views = aut_user_views($u);
    if (!count($views)) return null;
    foreach ($views as $v) {
        if (strcasecmp($v['role'], $u->AuLastRole ?? '') === 0
            && strcasecmp($v['scope'], $u->AuLastScope ?? '') === 0) return $v;
    }
    return $views[0];   // déjà triées par niveau décroissant
}

/** Libellé court d'un propriétaire (page partage, admin). */
function aut_owner_label($role, $scope) {
    switch ($role) {
        case AUT_ROLE_CLUB: return $scope;
        case AUT_ROLE_CD:   return 'CD' . $scope;
        case AUT_ROLE_CR:   return 'CR' . $scope;
        case AUT_ROLE_FED:  return 'FED';
    }
    return '';
}

/** Analyse la saisie admin d'un propriétaire : '', agrément, CD60, CR07, FED. */
function aut_parse_owner($str, &$role, &$scope) {
    $str = trim($str);
    $role = '';
    $scope = '';
    if ($str === '') return true;                                    // sans propriétaire
    if (preg_match('/^FED$/i', $str)) { $role = AUT_ROLE_FED; return true; }
    if (preg_match('/^(CD|CR)([0-9A-Za-z]{2})$/i', $str, $m)) {
        $role = strtoupper($m[1]);
        $scope = $m[2];
        return true;
    }
    if (preg_match('/^[0-9A-Za-z]{5,12}$/', $str)) { $role = AUT_ROLE_CLUB; $scope = $str; return true; }
    return false;
}

/* Message "flash" affiché une seule fois par la barre du module (menu.php). */
function aut_flash_set($msg) {
    $_SESSION['AUT_Flash'] = $msg;
}

function aut_flash_get() {
    $m = $_SESSION['AUT_Flash'] ?? '';
    unset($_SESSION['AUT_Flash']);
    return $m;
}

/**
 * Politique ISK d'un SERVEUR EN LIGNE : seuls « aucun ISK » et « ISK-NG lite » sont
 * autorisés. Les modes ng-pro et ng-live déclenchent (côté ianseo) un trigger qui
 * RÉVOQUE la licence du serveur — inacceptable sur un serveur partagé en ligne. Ces
 * deux fonctions constituent la source de vérité, réutilisée par l'UI (menu.php,
 * SYNCHRO_FFTA) et l'application (rebascule vers lite).
 */
function aut_isk_blocked_modes() {
    return array('ng-pro', 'ng-live');
}

/** Retire les modes interdits d'une liste $IskType (clé => libellé). */
function aut_isk_filter($iskType) {
    if (!is_array($iskType)) return $iskType;
    foreach (aut_isk_blocked_modes() as $m) unset($iskType[$m]);
    return $iskType;
}

/**
 * Ramène le mode ISK d'une compétition à « lite » s'il est pro/live (import d'une
 * compétition configurée en pro, ou choix forcé sur la page cœur non modifiable).
 * Renvoie true si une rétrogradation a eu lieu. Sûr et bon marché (un SELECT indexé).
 */
function aut_isk_enforce($tourId = 0) {
    if (!function_exists('getModuleParameter') || !function_exists('setModuleParameter')) return false;
    if (empty($tourId)) $tourId = intval($_SESSION['TourId'] ?? 0);
    if ($tourId <= 0) return false;
    $mode = getModuleParameter('ISK-NG', 'Mode', '', $tourId, true);
    if (in_array($mode, aut_isk_blocked_modes(), true)) {
        setModuleParameter('ISK-NG', 'Mode', 'ng-lite', $tourId);
        aut_log('ISK_DOWNGRADE', 'tour=' . $tourId . ' from=' . $mode);
        // Informer l'organisateur (le filet était muet) : « 2e message si détecté »
        // à l'import d'une compétition configurée en ISK Pro/Live. Ne pas écraser un
        // flash déjà en attente (ex. refus d'import — cas sans compétition ouverte).
        if (($_SESSION['AUT_Flash'] ?? '') === '') {
            aut_flash_set('⚠️ Cette compétition utilisait la saisie <b>ISK '
                . ($mode === 'ng-pro' ? 'Pro' : 'Live') . '</b>, non prise en charge sur ce '
                . 'serveur partagé (elle en révoquerait la licence). Elle a été ramenée en '
                . '<b>ISK Lite</b> ; adaptez la saisie si besoin depuis la page de la compétition.');
        }
        return true;
    }
    return false;
}

/**
 * Adopte la compétition pointée par la session si elle n'a pas encore de
 * propriétaire. Le cœur ianseo pose $_SESSION['TourId'] à la création SANS
 * passer par TourOn ; pour un organisateur, un TourId ne peut venir que de
 * TourOn (filtré par AUTH_COMP → compétition déjà possédée/partagée) ou d'une
 * création par lui → l'adoption est sûre.
 */
function aut_adopt_current($u, $role, $scope) {
    $tid = intval($_SESSION['TourId'] ?? 0);
    if ($tid <= 0 || $role === '') return;
    $q = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId=$tid");
    if (!($t = safe_fetch($q))) return;
    $q = safe_r_sql("SELECT AsOwnerRole FROM AUT_Share WHERE AsToCode=" . StrSafe_DB($t->ToCode));
    $own = safe_fetch($q);
    if ($own && $own->AsOwnerRole !== '') return;
    // NB : les affectations d'un ON DUPLICATE s'évaluent de gauche à droite →
    // scope/user (conditionnés par l'ancien AsOwnerRole) AVANT AsOwnerRole
    safe_w_sql("INSERT INTO AUT_Share (AsToCode, AsOwnerRole, AsOwnerScope, AsOwnerUser) VALUES ("
        . StrSafe_DB($t->ToCode) . "," . StrSafe_DB($role) . "," . StrSafe_DB($scope) . "," . StrSafe_DB($u->AuUsername) . ")
        ON DUPLICATE KEY UPDATE
            AsOwnerScope=IF(AsOwnerRole='', VALUES(AsOwnerScope), AsOwnerScope),
            AsOwnerUser =IF(AsOwnerRole='', VALUES(AsOwnerUser),  AsOwnerUser),
            AsOwnerRole =IF(AsOwnerRole='', VALUES(AsOwnerRole),  AsOwnerRole)");
    aut_log('COMP_ADOPT', $u->AuUsername . ' ' . $t->ToCode);
}

/**
 * Barrière anti-écrasement à l'enregistrement d'une compétition.
 * La garde du cœur (Tournament/index.php:34) ne s'applique que si
 * $_SESSION['TourId'] == -1 : une session fraîche (TourId absent) la
 * contourne totalement. On revalide donc ici, AVANT le code de la page
 * (le bootstrap s'exécute depuis config.php).
 */
function aut_guard_tournament_save($role) {
    global $CFG;
    if (!empty($_SESSION['AUTH_ROOT'])) return;
    if (strcasecmp(aut_script_rel(), '/Tournament/index.php') !== 0) return;
    if (($_REQUEST['Command'] ?? '') !== 'SAVE') return;

    // Vue « depuis un autre compte » (lecture seule) : aucun enregistrement de
    // compétition, quelles que soient les vérifications propres du cœur.
    if (!empty($_SESSION['AUTH_RO'])) {
        aut_log('SAVE_BLOCK', ($_SESSION['AUTH_User'] ?? '') . ' RO');
        aut_flash_set('Vue en lecture seule — enregistrement impossible.');
        CD_redirect($CFG->ROOT_DIR . 'Tournament/index.php' . (isset($_REQUEST['New']) ? '?New=' : ''));
        die();
    }

    // même normalisation du code que le cœur
    $newCode = preg_replace('/[^0-9a-z._-]+/sim', '_', $_REQUEST['d_ToCode'] ?? '');
    $reason = '';
    $scope = $_SESSION['AUTH_SCOPE'] ?? '';
    $user  = $_SESSION['AUTH_User'] ?? '';
    $organizer = in_array($role, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED));

    if (isset($_REQUEST['New'])) {
        if (!$organizer) {
            $ok = false;
            $reason = 'Votre compte ne permet pas de créer une compétition sur ce serveur.';
        } else {
            $ok = aut_can_use_code($newCode, $role, $scope, $user, false, $reason);
        }
    } else {
        // modification : seul un CHANGEMENT de code doit être revalidé
        $cur = $_SESSION['TourCode'] ?? '';
        if ($newCode === '' || strcasecmp($newCode, $cur) === 0) {
            $ok = true;
        } elseif (!$organizer || aut_code_status($cur, $role, $scope) !== 'own') {
            // un invité (aide à la saisie) peut modifier, mais pas renommer
            $ok = false;
            $reason = 'Seul le propriétaire de la compétition (ou un administrateur) peut changer son code.';
        } else {
            $ok = aut_can_use_code($newCode, $role, $scope, $user, false, $reason);
        }
    }
    if (!$ok) {
        aut_log('SAVE_BLOCK', ($_SESSION['AUTH_User'] ?? '') . ' ' . substr($newCode, 0, 40));
        // la saisie est conservée : menu.php la réinjecte dans le formulaire
        $data = array();
        foreach ($_POST as $k => $v) {
            if (is_string($v) && preg_match('/^(d_|x_|xx_)/', $k)) $data[$k] = $v;
        }
        $_SESSION['AUT_SaveBlock'] = array('msg' => $reason, 'data' => $data);
        CD_redirect($CFG->ROOT_DIR . 'Tournament/index.php' . (isset($_REQUEST['New']) ? '?New=' : ''));
        die();
    }
}

/**
 * Acte la propriété des compétitions effectivement créées depuis les
 * revendications de l'utilisateur (appelé à chaque requête d'un CLUB —
 * la création passe par le cœur ianseo, ce hook est notre seul point sûr).
 */
function aut_adopt_claims($u) {
    $q = safe_r_sql("SELECT * FROM AUT_Claim WHERE AcUser=" . StrSafe_DB($u->AuUsername));
    $claims = array();
    while ($r = safe_fetch($q)) $claims[] = $r;
    if (!count($claims)) {
        return;
    }
    foreach ($claims as $c) {
        $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($c->AcCode));
        if (safe_fetch($q)) {
            // owner posé seulement s'il est encore vide (course avec un admin) ;
            // affectations évaluées de gauche à droite → AsOwnerRole en dernier
            safe_w_sql("INSERT INTO AUT_Share (AsToCode, AsOwnerRole, AsOwnerScope, AsOwnerUser) VALUES ("
                . StrSafe_DB($c->AcCode) . "," . StrSafe_DB($c->AcRole) . "," . StrSafe_DB($c->AcScope) . "," . StrSafe_DB($c->AcUser) . ")
                ON DUPLICATE KEY UPDATE
                    AsOwnerScope=IF(AsOwnerRole='', VALUES(AsOwnerScope), AsOwnerScope),
                    AsOwnerUser =IF(AsOwnerRole='', VALUES(AsOwnerUser),  AsOwnerUser),
                    AsOwnerRole =IF(AsOwnerRole='', VALUES(AsOwnerRole),  AsOwnerRole)");
            safe_w_sql("DELETE FROM AUT_Claim WHERE AcCode=" . StrSafe_DB($c->AcCode));
            aut_log('COMP_ADOPT', $u->AuUsername . ' ' . $c->AcCode);
        }
    }
    safe_w_sql("DELETE FROM AUT_Claim WHERE AcWhen < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

/** $role/$scope non vides = vue de la session, sinon ceux du compte. */
function aut_session_apply($u, $role = '', $scope = '') {
    if ($role === '') { $role = $u->AuRole; $scope = $u->AuScope; }
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_ROLE']   = $role;
    $_SESSION['AUTH_SCOPE']  = $scope;
    $_SESSION['AUTH_VIEWS']  = aut_user_views($u);   // pour le sélecteur de vue (barre)
    aut_extranet_publish();    // convention FFTA_EXTRANET_* : effacée par le cœur, republiée ici
    aut_dirigeant_publish();   // convention FFTA_DIRIGEANT_* : idem
    if ($role == AUT_ROLE_ADMIN) {
        $_SESSION['AUTH_ROOT'] = 1;
        $_SESSION['AUTH_COMP'] = array();
    } else {
        unset($_SESSION['AUTH_ROOT']);
        $_SESSION['AUTH_COMP'] = aut_compute_comp($role, $scope);
    }
}

function aut_session_clear() {
    foreach (array('AUTH_User', 'AUTH_Pwd', 'AUTH_ROOT', 'AUTH_ROLE', 'AUTH_SCOPE', 'AUTH_SSO', 'AUTH_VIEWS') as $k) {
        unset($_SESSION[$k]);
    }
    unset($_SESSION['AUTH_IMPERSONATE'], $_SESSION['AUTH_RO']);   // fin de session = fin d'observation
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_COMP']   = array();
}

/* ------------------------------------------------------------------ */
/* Vue « depuis un autre compte » (impersonation) — ADMIN, LECTURE     */
/* SEULE. Ouverte UNIQUEMENT par la page admin (admin/impersonate.php, */
/* gardée AclRoot + AUTH_ROOT). CANONIQUE EN BASE (AUT_Sessions.AsnImp) */
/* car CreateTourSession vide la session à chaque ouverture de         */
/* compétition (seuls AUTH_User/AUTH_Pwd survivent) : un simple drapeau */
/* de session METTRAIT FIN à l'observation en laissant l'admin en       */
/* lecture-ÉCRITURE sur la compétition d'un tiers. La session ne porte  */
/* qu'un MIROIR (AUTH_IMPERSONATE), reconstruit à chaque requête par le  */
/* bootstrap depuis la ligne de session — l'espace archer (qui ne lit   */
/* que la session, sans dépendre d'AUTH) s'appuie dessus. La lecture    */
/* seule organisateur est IMPOSÉE PAR LE CŒUR via AUTH_RO (plafond ACL, */
/* dist/BlockFunction.php), jamais seulement masquée.                  */
/* ------------------------------------------------------------------ */
function aut_imp_get() {
    $i = $_SESSION['AUTH_IMPERSONATE'] ?? null;
    return is_array($i) ? $i : null;
}

/** Reconstruit le miroir de session depuis la ligne de session ($s->AsnImp). */
function aut_imp_load($s) {
    $raw = is_object($s) ? (string) ($s->AsnImp ?? '') : '';
    $i = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($i) && isset($i['type'])) $_SESSION['AUTH_IMPERSONATE'] = $i;
    else unset($_SESSION['AUTH_IMPERSONATE'], $_SESSION['AUTH_RO']);
}

/** Ouvre une observation : persiste en base (survit à CreateTourSession) + miroir. */
function aut_imp_store(array $imp) {
    $h = aut_current_token_hash();
    if ($h !== '') {
        safe_w_sql("UPDATE AUT_Sessions SET AsnImp=" . StrSafe_DB(json_encode($imp))
            . " WHERE AsnTokenHash='" . $h . "'");
    }
    $_SESSION['AUTH_IMPERSONATE'] = $imp;
}

/** Ferme l'observation : base + miroir + plafond ACL. */
function aut_imp_forget() {
    $h = aut_current_token_hash();
    if ($h !== '') safe_w_sql("UPDATE AUT_Sessions SET AsnImp=NULL WHERE AsnTokenHash='" . $h . "'");
    unset($_SESSION['AUTH_IMPERSONATE'], $_SESSION['AUTH_RO']);
}

/**
 * Applique l'impersonation ORGANISATEUR sur la session courante, APRÈS
 * aut_session_apply (qui a posé la vue admin). L'admin voit alors le compte
 * cible en LECTURE SEULE : rôle/scope/AUTH_COMP de la cible, AUTH_ROOT retiré,
 * AUTH_RO posé (plafond ACL). Gardes : n'agit que si l'admin l'est TOUJOURS et
 * que la cible existe et n'est PAS admin ; sinon ferme l'observation.
 */
function aut_imp_apply_org($u) {
    $i = aut_imp_get();
    if (!$i || ($i['type'] ?? '') !== 'org') return;
    if ($u->AuRole != AUT_ROLE_ADMIN) {              // l'observateur n'est plus admin → on coupe
        aut_log('IMPERSONATE_REVOKE', $u->AuUsername);
        aut_imp_forget();
        return;
    }
    $t = aut_get_user((string) ($i['user'] ?? ''));
    if (!$t || $t->AuRole == AUT_ROLE_ADMIN) {       // cible disparue ou devenue admin → on coupe
        aut_imp_forget();
        return;
    }
    $_SESSION['AUTH_ROLE']  = $t->AuRole;
    $_SESSION['AUTH_SCOPE'] = $t->AuScope;
    $_SESSION['AUTH_COMP']  = aut_compute_comp($t->AuRole, $t->AuScope);
    unset($_SESSION['AUTH_ROOT']);                   // pas de root dans la vue observée
    $_SESSION['AUTH_RO']    = 1;                      // plafond lecture seule (BlockFunction)
    $_SESSION['AUTH_VIEWS'] = array();               // masque le sélecteur de vue pendant l'observation
}

/** Un code de compétition correspond-il aux droits de la session ? */
function aut_code_allowed($code, $list = null) {
    if (is_null($list)) $list = $_SESSION['AUTH_COMP'] ?? array();
    foreach ($list as $p) {
        if (strpos($p, '%') !== false || strpos($p, '_') !== false) {
            $rx = '/^' . str_replace(array('%', '_'), array('.*', '.'), preg_quote($p, '/')) . '$/i';
            if (preg_match($rx, $code)) return true;
        } elseif (strcasecmp($p, $code) === 0) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* Bootstrap de requête (appelé par Modules/Authentication/AuthFunctions.php) */
/* ------------------------------------------------------------------ */

function aut_script_rel() {
    global $CFG;
    $s = $_SERVER['SCRIPT_NAME'] ?? '';
    $root = rtrim($CFG->ROOT_DIR ?? '/', '/');
    if ($root && strpos($s, $root) === 0) $s = substr($s, strlen($root));
    return $s ?: '/';
}

/** Chemins accessibles sans connexion (extensibles via config.local.json). */
function aut_public_paths() {
    static $paths = null;
    if (!is_null($paths)) return $paths;
    // NB : /index.php (racine ianseo) n'est PLUS public → un visiteur anonyme y
    // est redirigé vers la page de connexion unifiée (voir aut_request_bootstrap).
    $paths = array(
        '/noAccess.php', '/credits.php',
        '/Modules/Authentication/',            // login/logout/2FA organisateur (déployés)
        '/Modules/Custom/AUTH/login.php',      // page de connexion unifiée
        '/Modules/Custom/AUTH/booking/public/',     // espace licencié (face publique, $SKIP_AUTH)
    );
    foreach ((aut_local_config()['public_paths'] ?? array()) as $p) {
        if (is_string($p) && $p !== '') $paths[] = $p;
    }
    return $paths;
}

/** URL de la page de connexion unifiée (choix organisateur / compétiteur). */
function aut_unified_login_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php';
}

/**
 * Un compétiteur (session BOOKING) est-il connecté ? Chargé seulement si un
 * jeton BOOKING est présent — aucune dépendance d'AUTH envers BOOKING sinon.
 */
function aut_booking_archer_logged() {
    global $CFG;
    if (empty($_SESSION['BK_Token'])) return false;
    $dir = $CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/';
    if (!is_file($dir . 'archer.php')) return false;
    require_once($dir . 'schema.php');
    require_once($dir . 'archer.php');
    return function_exists('bk_current_archer') && (bool) bk_current_archer();
}

/* ------------------------------------------------------------------ */
/* Connexion ORGANISATEUR — handlers réutilisés par login.php (page    */
/* unifiée) ET par le LogIn.php déployé. Source unique du flux.        */
/* ------------------------------------------------------------------ */

/**
 * Finalise la connexion organisateur : la vue activée est la dernière utilisée
 * si encore disponible, sinon le niveau maximum. Régénère l'id de session,
 * puis redirige (ChangePassword si mot de passe temporaire) et termine.
 */
function aut_finish_login($u, $event = 'LOGIN_OK') {
    global $CFG;
    unset($_SESSION['AUT_2FA_User'], $_SESSION['AUT_2FA_Time']);
    session_regenerate_id(true);
    $view  = aut_pick_view($u);
    $role  = $view ? $view['role']  : '';
    $scope = $view ? $view['scope'] : '';
    aut_session_open($u, $role, $scope);
    safe_w_sql("UPDATE AUT_Users SET AuLastLogin=NOW(), AuLastRole=" . StrSafe_DB($role)
        . ", AuLastScope=" . StrSafe_DB($scope) . " WHERE AuId={$u->AuId}");
    aut_log($event, $u->AuUsername);
    CD_redirect($u->AuMustChangePwd
        ? $CFG->ROOT_DIR . 'Modules/Authentication/ChangePassword.php'
        : $CFG->ROOT_DIR);
    die();
}

/**
 * Étape 1 organisateur (identifiant + mot de passe) — lit $_POST.
 * Compte LOCAL : mot de passe + TOTP éventuel. Compte SSO : relais Espace
 * Dirigeant (+ cookie dirigeant capté, extranet ouvert en repli silencieux),
 * synchro des structures. Sur succès : redirige et termine (aut_finish_login) ;
 * sinon renseigne $err, et $stage='totp' si un code TOTP serveur est attendu.
 */
function aut_handle_org_login(&$err, &$stage) {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $otp      = trim($_POST['otp'] ?? '');
    // hash factice : temps de réponse constant que l'identifiant existe ou non
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuvJltUS3sTfTLNQKQ2wZ2gJ0S9nO2/xdC';

    if ($username === '' || $password === '') {
        $err = 'Identifiant et mot de passe requis.';
        return;
    }
    if (aut_too_many_failures($username)) {
        aut_log('LOGIN_BLOCK', $username);
        $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
        return;
    }
    $u = aut_get_user($username);

    if ($u && $u->AuPassword !== '') {
        /* Compte local (ADMIN…) : mot de passe local + TOTP éventuel */
        if (password_verify($password, $u->AuPassword) && $u->AuActive) {
            if ($u->AuTotpEnabled) {
                $_SESSION['AUT_2FA_User'] = $u->AuUsername;
                $_SESSION['AUT_2FA_Time'] = time();
                $stage = 'totp';
            } else {
                aut_finish_login($u);
            }
        } else {
            aut_log('LOGIN_FAIL', $username);
            $err = 'Identifiant ou mot de passe incorrect.';
        }
    } elseif (aut_sso_enabled()) {
        /* SSO Espace Dirigeant : relais + synchro des structures */
        $structures = array();
        $ssoErr = '';
        $dirCookie = null;
        if (aut_ffta_verify($username, $password, $otp, $structures, $ssoErr, $dirCookie)) {
            aut_dirigeant_stash($dirCookie);      // cookie session dirigeant réutilisable (synchro)
            aut_extranet_open($username, $password); // 2e auth extranet, échec silencieux
            $syncErr = '';
            $u = aut_sso_sync($username, $structures, $syncErr);
            if (!$u) {
                aut_log('LOGIN_FAIL', $username);
                $err = $syncErr;
            } elseif (!count(aut_user_views($u))) {
                aut_log('LOGIN_FAIL', $username);
                $err = $ssoErr ?: 'Aucune de vos structures FFTA ne donne accès à ce serveur (rôle Gestionnaire requis).';
            } elseif ($u->AuTotpEnabled) {
                // 2FA (obligatoire ADMIN, optionnelle pour les autres) : étape code.
                $_SESSION['AUT_2FA_User'] = $u->AuUsername;
                $_SESSION['AUT_2FA_Time'] = time();
                $stage = 'totp';
            } else {
                aut_finish_login($u, 'SSO_OK');   // admin sans TOTP → Setup2FA forcé via bootstrap
            }
        } else {
            aut_log('LOGIN_FAIL', $username);
            $err = $ssoErr;
        }
    } else {
        password_verify($password, $dummyHash);   // temps constant
        aut_log('LOGIN_FAIL', $username);
        $err = 'Identifiant ou mot de passe incorrect.';
    }
}

/** Étape 2 organisateur : code TOTP du serveur (comptes ADMIN). Lit $_POST. */
function aut_handle_org_totp(&$err, &$stage) {
    $username = $_SESSION['AUT_2FA_User'] ?? '';
    if ($username === '' || (time() - ($_SESSION['AUT_2FA_Time'] ?? 0)) > AUT_2FA_PENDING_S) {
        unset($_SESSION['AUT_2FA_User'], $_SESSION['AUT_2FA_Time']);
        $err = 'Délai dépassé, recommencez.';
        return;
    }
    if (aut_too_many_failures($username)) {
        aut_log('LOGIN_BLOCK', $username);
        unset($_SESSION['AUT_2FA_User'], $_SESSION['AUT_2FA_Time']);
        $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
        return;
    }
    $u = aut_get_user($username);
    $usedSlot = 0;
    $code = $_POST['code'] ?? '';
    if ($u && $u->AuActive && aut_totp_verify($u->AuTotpSecret, $code, intval($u->AuTotpLastSlot), $usedSlot)) {
        safe_w_sql("UPDATE AUT_Users SET AuTotpLastSlot=$usedSlot WHERE AuId={$u->AuId}");
        aut_finish_login($u);
    }
    // Échec : est-ce un mauvais code, ou une horloge serveur déréglée ? Si le code
    // correspond à un slot très éloigné, c'est l'horloge — on le DIT (au lieu d'un
    // « code incorrect » incompréhensible) et on NE compte PAS cet échec dans
    // l'anti-bruteforce (TOTP_SKEW, hors du filtre LOGIN_FAIL/TOTP_FAIL) : un code
    // juste rejeté par un décalage d'horloge ne doit pas verrouiller l'admin.
    $skew = ($u && $u->AuActive) ? aut_totp_skew($u->AuTotpSecret, $code) : null;
    if ($skew !== null) {
        aut_log('TOTP_SKEW', $username);
        $mins = round(abs($skew) / 60);
        $err = "Code refusé : l'horloge de CE serveur est décalée d'environ {$mins} min par rapport à "
             . "l'heure réelle (le code de votre application est basé sur l'heure). Synchronisez l'heure "
             . "du serveur (NTP) puis réessayez — votre code est bon.";
    } else {
        aut_log('TOTP_FAIL', $username);
        $err = 'Code incorrect.';
    }
    $stage = 'totp';
}

function aut_is_public_script() {
    $s = aut_script_rel();
    if ($s == '/') return true;
    foreach (aut_public_paths() as $p) {
        if (substr($p, -1) == '/') {
            if (stripos($s, $p) === 0) return true;
        } elseif (strcasecmp($s, $p) === 0) {
            return true;
        }
    }
    return false;
}

function aut_is_auth_script() {
    return stripos(aut_script_rel(), '/Modules/Authentication/') === 0;
}

/** Pages légales (consultation + acceptation) : exemptes de la garde CGU (anti-boucle). */
function aut_is_legal_script() {
    $s = aut_script_rel();
    return stripos($s, '/Modules/Custom/AUTH/legal.php') === 0
        || stripos($s, '/Modules/Custom/AUTH/legal-accept.php') === 0;
}

/**
 * Scripts touchant TOUT le serveur (toute la base) → administrateur uniquement.
 * Filet de sécurité central : certaines de ces pages du cœur ianseo ne
 * vérifient AUCUNE ACL (ex. RepairTables lance REPAIR/OPTIMIZE sur toutes les
 * tables ; RepairXAMPP redémarre MySQL) — les bloquer ici évite qu'un simple
 * organisateur les déclenche et perturbe les compétitions des autres.
 * Surchargeable via config.local.json → "admin_only_paths" (fusionné).
 */
function aut_admin_only_paths() {
    static $paths = null;
    if (!is_null($paths)) return $paths;
    $paths = array(
        '/Update/',                            // mise à jour de la base (ALTER, migrations)
        '/Install/',                           // (ré)installation
        '/Modules/Help/RepairTables.php',      // REPAIR + OPTIMIZE de toutes les tables
        '/Modules/Help/LoadDebug.php',
        '/RepairXAMPP.php',                    // aria_chk + redémarrage mysqld
    );
    foreach ((aut_local_config()['admin_only_paths'] ?? array()) as $p) {
        if (is_string($p) && $p !== '') $paths[] = $p;
    }
    return $paths;
}

function aut_is_admin_only_script() {
    $s = aut_script_rel();
    foreach (aut_admin_only_paths() as $p) {
        if (substr($p, -1) === '/') {
            if (stripos($s, $p) === 0) return true;
        } elseif (strcasecmp($s, $p) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Exécuté sur CHAQUE requête (via config.php → AuthFunctions.php) quand
 * $CFG->USERAUTH est actif. Revalide l'utilisateur + son jeton de session et
 * recalcule ses droits : AUTH_ENABLE / AUTH_ROOT / AUTH_COMP sont effacés par
 * ianseo à chaque ouverture/fermeture de compétition (CreateTourSession) —
 * seuls AUTH_User / AUTH_Pwd survivent, d'où le recalcul systématique.
 */
function aut_request_bootstrap() {
    global $CFG;

    // Soin de session : à la création d'une compétition, le cœur pose
    // $_SESSION['TourId'] SANS TourCode (Tournament/index.php) → warnings
    // dans define_session_flags() à chaque page. On complète depuis la DB.
    if (!empty($_SESSION['TourId']) && $_SESSION['TourId'] > 0 && !isset($_SESSION['TourCode'])) {
        $q = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId=" . intval($_SESSION['TourId']));
        if ($r = safe_fetch($q)) {
            $_SESSION['TourCode'] = $r->ToCode;
        } else {
            $_SESSION['TourId'] = -1;
            $_SESSION['TourCode'] = '';
        }
    }

    if (aut_is_localhost()) return;   // console serveur : comportement ianseo classique

    aut_ensure_schema();
    aut_log_purge_daily();   // rétention des journaux (au plus 1×/jour, cf. marqueur)

    if (!empty($_SESSION['AUTH_User'])) {
        $u = aut_get_user($_SESSION['AUTH_User']);
        $s = ($u && $u->AuActive) ? aut_session_validate($u) : null;
        if ($s) {
            aut_imp_load($s);   // miroir d'observation (canonique en base, survit à CreateTourSession)
            $_SESSION['AUTH_SSO'] = ($u->AuPassword === '') ? 1 : 0;
            // vue courante = celle de la session (bascule à la volée via
            // switch-view.php) ; repli sur le rôle de base du compte
            $role  = $s->AsnRole !== '' ? $s->AsnRole  : $u->AuRole;
            $scope = $s->AsnRole !== '' ? $s->AsnScope : $u->AuScope;
            // la vue Administrateur exige que le compte le soit encore
            if ($role == AUT_ROLE_ADMIN && $u->AuRole != AUT_ROLE_ADMIN) {
                $role = $u->AuRole;
                $scope = $u->AuScope;
            }
            if (in_array($role, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) {
                aut_adopt_claims($u);                   // compétitions créées via import/claims
                aut_adopt_current($u, $role, $scope);   // compétition tout juste créée (TourId posé par le cœur)
            }
            aut_session_apply($u, $role, $scope);
            aut_imp_apply_org($u);               // vue « depuis un autre compte » (admin, lecture seule)
            aut_guard_tournament_save($role);    // barrière anti-écrasement (celle du cœur est contournable)
            // pages serveur (mise à jour / réparation) : administrateur uniquement
            if (empty($_SESSION['AUTH_ROOT']) && aut_is_admin_only_script()) {
                aut_log('ADMIN_PATH_BLOCK', $u->AuUsername . ' ' . aut_script_rel());
                CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
                die();
            }
            if (!aut_is_auth_script()) {
                if ($u->AuMustChangePwd) {
                    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/ChangePassword.php');
                    die();
                }
                // 2FA obligatoire pour les administrateurs
                if ($u->AuRole == AUT_ROLE_ADMIN && !$u->AuTotpEnabled) {
                    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/Setup2FA.php');
                    die();
                }
                // CGU : acceptation obligatoire (bloquant tant que non accepté pour la version
                // courante) — SEULEMENT si l'exploitant a renseigné ses infos légales (sinon on ne
                // force pas l'acceptation d'un texte à trous, et l'admin peut atteindre admin/legal.php).
                // Cache en session pour éviter une requête par page. Pages légales exemptes.
                if (!aut_is_legal_script()) {
                    require_once __DIR__ . '/legal-lib.php';
                    if (aut_legal_configured()) {
                        $cguVer = aut_legal_version();
                        if (($_SESSION['AUTH_CGU_OK'] ?? '') !== $cguVer) {
                            if (aut_legal_org_ok($u->AuUsername)) {
                                $_SESSION['AUTH_CGU_OK'] = $cguVer;
                            } else {
                                CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/legal-accept.php');
                                die();
                            }
                        }
                    }
                }
            }
            // Mesure d'audience (agrégée, sans donnée personnelle ; l'organisateur est
            // compté par identité de compte, aucun cookie). Page réellement servie
            // (après les gardes qui redirigent). Auto-gardée et isolée : jamais fatale.
            require_once __DIR__ . '/stats-usage.php';
            if (!aut_imp_get() && function_exists('aut_track')) aut_track('org', $u->AuId);   // pas de compteur pendant l'observation
            return;
        }
        // jeton expiré/révoqué, compte désactivé ou supprimé
        aut_session_clear();
    }

    aut_session_clear();
    if (!aut_is_public_script()) {
        // Compétiteur déjà connecté (session BOOKING) → son espace, jamais le
        // login organisateur ; sinon → page de connexion unifiée (choix des rôles).
        if (aut_booking_archer_logged()) {
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/index.php');
        } else {
            CD_redirect(aut_unified_login_url());
        }
        die();
    }
}

/* ------------------------------------------------------------------ */
/* SSO Espace Dirigeant FFTA (dirigeant.ffta.fr)                       */
/*                                                                      */
/* Validation des identifiants par tentative de connexion à l'espace   */
/* dirigeant (relais de crédentiels, pas un vrai SSO OAuth — voir      */
/* SERVEUR.md). Le mot de passe n'est JAMAIS stocké ni journalisé.     */
/* Les structures rattachées (menu select-structure) donnent le rôle.  */
/* ------------------------------------------------------------------ */

define('AUT_FFTA_BASE', 'https://dirigeant.ffta.fr');

function aut_sso_enabled() {
    $sso = aut_local_config()['sso'] ?? array();
    return !array_key_exists('enabled', $sso) || !empty($sso['enabled']);
}

/* ---- Débogage du flux SSO (trace cURL serveur) --------------------- *
 * Activation : soit config.local.json → "sso":{"debug":true}, soit la
 * présence du fichier interrupteur Modules/Custom/AUTH/ffta-debug.on
 * (pratique en démo : le créer, reproduire, lire ffta-debug.log, supprimer).
 * NE JOURNALISE JAMAIS mot de passe ni code MFA — seulement URLs, codes HTTP,
 * type de page atteinte et noms de champs de formulaire.                */
function aut_ffta_debug_enabled() {
    if (!empty(aut_local_config()['sso']['debug'])) return true;
    return is_file(__DIR__ . '/ffta-debug.on');
}

function aut_ffta_debug($msg) {
    if (!aut_ffta_debug_enabled()) return;
    @file_put_contents(__DIR__ . '/ffta-debug.log',
        date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** Résumé "sûr" d'une page HTML pour le log : type détecté + champs de formulaire. */
function aut_ffta_debug_page($html) {
    $html = (string)$html;
    $info = array('len=' . strlen($html));
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $info[] = 'title="' . trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))) . '"';
    }
    $info[] = 'select-structure=' . (strpos($html, '/auth/select-structure/') !== false ? 'oui' : 'non');
    $info[] = 'champ-password=' . (preg_match('/name=["\']password["\']/', $html) ? 'oui' : 'non');
    // marqueurs d'une étape MFA / double authentification
    $mfa = preg_match('/(two[-_]?factor|double.?authentification|v[ée]rification|authenticator|code.?(de.?)?s[ée]curit|otp|2fa)/i', $html);
    $info[] = 'marqueur-MFA=' . ($mfa ? 'oui' : 'non');
    // action du formulaire + noms de champs (valeurs masquées)
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $html, $m)) {
        $info[] = 'form-action="' . $m[1] . '"';
    }
    if (preg_match_all('/name=["\']([^"\']+)["\']/', $html, $m)) {
        $info[] = 'champs=[' . implode(',', array_slice(array_unique($m[1]), 0, 15)) . ']';
    }
    return implode('  ', $info);
}

/**
 * Connexion à l'espace dirigeant (flux repris de l'intégration FR existante :
 * GET login → _token CSRF Laravel → POST identifiants → échec si l'URL finale
 * contient /login). Retourne un handle curl connecté + le HTML de la page
 * d'atterrissage via $landing, ou null ($error renseigné).
 */
function aut_ffta_curl_login($username, $password, $otp, &$landing, &$error, &$cookieFileOut = null) {
    $error = '';
    $landing = '';
    $cookieFile = tempnam(sys_get_temp_dir(), 'aut_ck_');
    @chmod($cookieFile, 0600);
    $cookieFileOut = $cookieFile;   // exposé pour capter la session Espace Dirigeant
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-ffta/auth)',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ));

    aut_ffta_debug('--- login ' . $username . ' otp=' . ($otp !== '' ? 'fourni' : 'vide') . ' ---');
    curl_setopt($ch, CURLOPT_URL, AUT_FFTA_BASE . '/auth/login');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $loginPage = curl_exec($ch);
    aut_ffta_debug('GET /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . aut_ffta_debug_page($loginPage));
    if (!$loginPage || curl_errno($ch)) {
        $error = 'Espace dirigeant injoignable (' . curl_error($ch) . ')';
        curl_close($ch);
        return null;
    }

    $csrf = null;
    foreach (array(
        '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
        '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]*name=["\']csrf-token["\']/',
    ) as $p) {
        if (preg_match($p, $loginPage, $m)) { $csrf = $m[1]; break; }
    }
    aut_ffta_debug('CSRF ' . ($csrf ? 'trouvé' : 'INTROUVABLE'));
    if (!$csrf) {
        $error = 'Token CSRF introuvable (page de connexion FFTA modifiée ?)';
        curl_close($ch);
        return null;
    }

    $post = array('_token' => $csrf, 'username' => $username, 'password' => $password);
    if ($otp !== '') $post['otp'] = $otp;
    curl_setopt_array($ch, array(
        CURLOPT_URL        => AUT_FFTA_BASE . '/auth/login',
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $landing = curl_exec($ch);
    $post = null;
    $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    aut_ffta_debug('POST /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . $effUrl . ' ' . aut_ffta_debug_page($landing));

    if (strpos($effUrl, '/login') !== false) {
        $error = 'Identifiants espace dirigeant incorrects (ou MFA requise et non renseignée).';
        curl_close($ch);
        return null;
    }
    // Page intermédiaire MFA à 2 étapes (Laravel Fortify : /auth/two-factor-challenge)
    if (strpos($landing, '/auth/select-structure/') === false
        && preg_match('/(two[-_]?factor|deux.?[ée]tapes|double.?authentification|authenticator|otp|2fa)/i', $landing)) {
        aut_ffta_debug('=> page de défi MFA détectée');
        if ($otp === '') {
            $error = 'MFA_NEEDED';        // le code n'a pas été saisi
            curl_close($ch);
            return null;
        }
        $landing = aut_ffta_mfa_second_step($ch, $landing, $otp);
        // encore la page de défi = code refusé/expiré ; sinon connecté
        if (strpos($landing, '/auth/select-structure/') === false
            && preg_match('/two[-_]?factor|deux.?[ée]tapes/i', $landing)) {
            aut_ffta_debug('=> code MFA refusé (toujours la page de défi)');
            $error = 'MFA_BAD_CODE';
            curl_close($ch);
            return null;
        }
        aut_ffta_debug('=> seconde étape MFA acceptée');
    }
    return $ch;   // connecté ; l'appelant DOIT curl_close()
}

/**
 * Seconde étape MFA (best-effort, non testé contre la vraie FFTA) : renvoie le
 * code MFA au formulaire de la page de défi, en découvrant dynamiquement son
 * action et le nom du champ. Retourne le HTML de la page résultante.
 */
function aut_ffta_mfa_second_step($ch, $page, $otp) {
    // CSRF frais de la page de défi
    $csrf = null;
    foreach (array(
        '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
        '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]*name=["\']csrf-token["\']/',
    ) as $p) {
        if (preg_match($p, $page, $m)) { $csrf = $m[1]; break; }
    }
    // action du formulaire (repli sur l'endpoint de login)
    $action = AUT_FFTA_BASE . '/auth/login';
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $page, $m) && $m[1] !== '') {
        $action = (strpos($m[1], 'http') === 0) ? $m[1] : AUT_FFTA_BASE . '/' . ltrim($m[1], '/');
    }
    // nom du champ code : on PRIVILÉGIE 'code' (Fortify) et on EXCLUT
    // 'recovery_code' (codes de secours, pas le code de l'application)
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
    aut_ffta_debug('MFA step2 action=' . $action . ' champ=' . $field . ' csrf=' . ($csrf ? 'oui' : 'non'));

    $post = array($field => $otp);
    if ($csrf) $post['_token'] = $csrf;
    curl_setopt_array($ch, array(
        CURLOPT_URL        => $action,
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $res = curl_exec($ch);
    $post = null;
    aut_ffta_debug('MFA step2 POST http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . aut_ffta_debug_page($res));
    return (string)$res;
}

/**
 * Extrait les structures du menu "select-structure" de l'espace dirigeant.
 * Retour : array de array('id','code','name','roles').
 */
function aut_ffta_parse_structures($html) {
    $out = array();
    if (!preg_match_all('#<a\s+href="[^"]*/auth/select-structure/(\d+)".*?</a>#s', $html, $blocks, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($blocks as $b) {
        $s = array('id' => intval($b[1]), 'code' => '', 'name' => '', 'roles' => '');
        if (preg_match('#<span class="badge[^"]*"[^>]*>\s*([0-9]+)\s*</span>\s*([^<]+)#', $b[0], $m)) {
            $s['code'] = trim($m[1]);
            $s['name'] = trim($m[2]);
        }
        if (preg_match('#<span class="ml-3[^"]*">\s*(.*?)\s*</span>#s', $b[0], $m)) {
            $s['roles'] = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        }
        if ($s['code'] !== '' || $s['name'] !== '') $out[] = $s;
    }
    return $out;
}

/**
 * Structure espace dirigeant → rôle/périmètre ianseo, ou null si non gérée.
 * Discrimination par le libellé des rôles (les codes CD/CR se ressemblent) :
 *  - « Fédération »            → FED
 *  - « Comité Départemental »  → CD, scope = 2 premiers chiffres (60000 → 60)
 *  - « Comité Régional »       → CR, scope = 2 premiers chiffres
 *  - sinon badge ≥ 5 chiffres  → CLUB, scope = agrément complet
 * Le rôle de la personne doit correspondre à sso.required_role_regex
 * (défaut : Gestionnaire ou Administrateur).
 */
function aut_ffta_map_structure($st) {
    $cfg = aut_local_config()['sso'] ?? array();
    $required = $cfg['required_role_regex'] ?? 'Gestionnaire|Administrateur';
    if ($required !== '' && !preg_match('/' . str_replace('/', '\/', $required) . '/iu', $st['roles'])) {
        return null;
    }
    $hay = $st['roles'] . ' ' . $st['name'];
    // n° de dept/ligue = 2 premiers chiffres du code (robuste que le badge soit
    // "60000", "CR07" ou "0700000" — on garde les chiffres, 2A/2B préservés)
    $digits = preg_replace('/[^0-9AB]/i', '', strtoupper($st['code']));
    $twoDigits = substr($digits, 0, 2);
    if (preg_match('/F[ée]d[ée]ration/iu', $st['roles']) || $st['code'] === '0') {
        return array('role' => AUT_ROLE_FED, 'scope' => '', 'label' => $st['name']);
    }
    if (preg_match('/Comit[ée]\s+D[ée]partemental/iu', $hay)) {
        return array('role' => AUT_ROLE_CD, 'scope' => $twoDigits,
                     'label' => $st['name'] . ' (dept ' . $twoDigits . ')');
    }
    if (preg_match('/Comit[ée]\s+R[ée]gional/iu', $hay)) {
        return array('role' => AUT_ROLE_CR, 'scope' => $twoDigits,
                     'label' => $st['name'] . ' (ligue ' . $twoDigits . ')');
    }
    if (strlen($st['code']) >= 5) {
        return array('role' => AUT_ROLE_CLUB, 'scope' => $st['code'],
                     'label' => $st['name'] . ' (' . $st['code'] . ')');
    }
    return null;
}

/**
 * Valide les identifiants sur l'espace dirigeant et retourne les structures
 * ianseo exploitables. true = OK ($structures remplie), false = refus/erreur.
 */
function aut_ffta_verify($username, $password, $otp, &$structures, &$error, &$cookieFileOut = null) {
    $structures = array();
    $landing = '';
    $error = '';
    $ch = aut_ffta_curl_login($username, $password, $otp, $landing, $error, $cookieFileOut);
    if (!$ch) {
        // messages MFA lisibles pour l'utilisateur
        if ($error === 'MFA_NEEDED') {
            $error = 'Ce compte utilise la double authentification : saisissez le code à 6 chiffres '
                   . 'de votre application d\'authentification dans le champ « Code MFA ».';
        } elseif ($error === 'MFA_BAD_CODE') {
            $error = 'Code de double authentification incorrect ou expiré. Réessayez avec un code frais.';
        }
        return false;
    }

    $raw = aut_ffta_parse_structures($landing);
    if (!count($raw)) {
        // la page d'atterrissage varie (après MFA notamment) : retenter sur l'accueil
        curl_setopt_array($ch, array(CURLOPT_URL => AUT_FFTA_BASE . '/', CURLOPT_HTTPGET => true, CURLOPT_POST => false));
        $home = curl_exec($ch);
        aut_ffta_debug('GET / (retry structures) http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
            . ' ' . aut_ffta_debug_page($home));
        $raw = aut_ffta_parse_structures($home);
    }
    curl_close($ch);

    foreach ($raw as $st) {
        if ($m = aut_ffta_map_structure($st)) $structures[] = $m;
    }
    aut_ffta_debug('verify: structures brutes=' . count($raw) . ' exploitables=' . count($structures));

    // authentification RÉUSSIE même sans structure exploitable : l'appelant
    // décide (un compte ADMIN garde sa vue admin ; un simple compte est refusé)
    if (!count($structures)) {
        $error = count($raw)
            ? 'Aucune de vos structures FFTA ne donne accès à ce serveur (rôle Gestionnaire requis).'
            : 'Connexion FFTA réussie mais structures introuvables (page espace dirigeant modifiée ?).';
    }
    return true;
}

/**
 * Crée/actualise le compte d'un utilisateur SSO après authentification FFTA :
 * mémorise la liste de ses structures (AuStructs). Pour un compte non admin,
 * AuRole/AuScope = structure de niveau maximum (base de repli). Le rôle ADMIN
 * n'est JAMAIS posé ni retiré ici (octroi explicite uniquement).
 * Retourne l'objet utilisateur ou null ($error renseigné).
 */
function aut_sso_sync($username, $structures, &$error) {
    $error = '';
    $username = strtolower(trim($username));
    $clean = array();
    foreach ($structures as $st) {
        if (($st['role'] ?? '') == AUT_ROLE_ADMIN) continue;   // jamais d'admin via SSO
        $clean[] = array('role' => $st['role'], 'scope' => (string)$st['scope'], 'label' => (string)$st['label']);
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

    // structure de niveau max (base de repli pour les comptes non admin)
    $best = null;
    foreach ($clean as $st) {
        if (!$best || aut_view_rank($st['role']) > aut_view_rank($best['role'])) $best = $st;
    }

    $u = aut_get_user($username);
    if ($u) {
        if (!$u->AuActive) { $error = 'Compte désactivé sur ce serveur.'; return null; }
        if ($u->AuPassword !== '') {
            $error = 'Ce compte est géré localement (connexion par mot de passe local).';
            return null;
        }
        $set = "AuStructs=" . StrSafe_DB($json);
        if ($u->AuRole != AUT_ROLE_ADMIN && $best) {
            $set .= ", AuRole=" . StrSafe_DB($best['role']) . ", AuScope=" . StrSafe_DB($best['scope']);
        }
        safe_w_sql("UPDATE AUT_Users SET $set WHERE AuId={$u->AuId}");
    } else {
        if (!$best) { $error = 'Aucune de vos structures FFTA ne donne accès à ce serveur (rôle Gestionnaire requis).'; return null; }
        safe_w_sql("INSERT INTO AUT_Users (AuUsername, AuPassword, AuRole, AuScope, AuMustChangePwd, AuName, AuStructs)
            VALUES (" . StrSafe_DB($username) . ", '', " . StrSafe_DB($best['role']) . ","
            . StrSafe_DB($best['scope']) . ", 0, 'SSO espace dirigeant', " . StrSafe_DB($json) . ")");
        aut_log('SSO_PROVISION', $username);
    }
    return aut_get_user($username);
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/* Session extranet FFTA (extranet.ffta.fr) — convention inter-modules  */
/*                                                                      */
/* L'extranet est une application DISTINCTE de l'espace dirigeant       */
/* (Kareline/PHPSESSID, sans MFA) : le cookie de l'un n'ouvre rien sur  */
/* l'autre. Les identifiants étant synchronisés, AUTH ouvre au login    */
/* une SECONDE session, sur l'extranet, et n'en garde que le cookie.    */
/*                                                                      */
/* Convention publiée pour les autres modules (voir CLAUDE.md racine) : */
/*   $_SESSION['FFTA_EXTRANET_COOKIE'] = chemin du cookie jar (0600)    */
/*   $_SESSION['FFTA_EXTRANET_BASE']   = URL de base de l'extranet      */
/* Les modules consommateurs les utilisent SI elles existent, et gardent*/
/* leur propre formulaire de connexion en repli (AUTH peut être absent, */
/* le compte peut être local, la session extranet peut avoir expiré).   */
/* ------------------------------------------------------------------ */

define('AUT_EXTRANET_BASE', 'https://extranet.ffta.fr');

function aut_extranet_base() {
    $c = aut_local_config()['extranet'] ?? array();
    return rtrim($c['base'] ?? AUT_EXTRANET_BASE, '/');
}

function aut_extranet_enabled() {
    $c = aut_local_config()['extranet'] ?? array();
    return !array_key_exists('enabled', $c) || !empty($c['enabled']);
}

/**
 * Chemin du cookie jar, dérivé du jeton de session (AUTH_Pwd) : il survit donc
 * aux CreateTourSession/EraseTourSession du cœur, qui vident tout le reste.
 */
function aut_extranet_cookie_path() {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    if ($token === '') return '';
    return sys_get_temp_dir() . '/ffta_ext_' . hash('sha256', 'extranet|' . $token) . '.ck';
}

/**
 * Ouvre la session extranet avec les identifiants de l'espace dirigeant.
 * Appelée pendant le login, AVANT que la session ianseo n'existe : le cookie
 * atterrit dans un fichier temporaire que aut_extranet_bind() déplacera.
 * Échec silencieux : l'extranet ne doit jamais bloquer la connexion à ianseo.
 */
function aut_extranet_open($username, $password) {
    if (!aut_extranet_enabled()) return false;

    $base = aut_extranet_base();
    $tmp  = tempnam(sys_get_temp_dir(), 'aut_ex_');
    @chmod($tmp, 0600);

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $tmp,
        CURLOPT_COOKIEFILE     => $tmp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-ffta/auth)',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ));

    // GET : dépose le PHPSESSID, puis POST du formulaire d'identification
    curl_setopt($ch, CURLOPT_URL, $base . '/');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_exec($ch);

    curl_setopt_array($ch, array(
        CURLOPT_URL        => $base . '/',
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'login[identifiant]' => $username,
            'login[idpassword]'  => $password,
        )),
    ));
    $body = curl_exec($ch);
    $fail = curl_errno($ch) || $body === false
        || strpos((string)$body, 'name="login[identifiant]"') !== false;   // page de login re-servie
    curl_close($ch);

    if ($fail) {
        @unlink($tmp);
        aut_log('EXTRANET_FAIL', $username);
        return false;
    }

    $_SESSION['FFTA_EXTRANET_TMP'] = $tmp;
    aut_log('EXTRANET_OK', $username);
    return true;
}

/** Session ianseo ouverte (jeton posé) : le cookie extranet prend son chemin définitif. */
function aut_extranet_bind() {
    $tmp = $_SESSION['FFTA_EXTRANET_TMP'] ?? '';
    unset($_SESSION['FFTA_EXTRANET_TMP']);
    $path = aut_extranet_cookie_path();
    if ($tmp !== '' && $path !== '' && file_exists($tmp)) {
        @rename($tmp, $path);
        @chmod($path, 0600);
    }
}

/** Publie la convention en session (appelée à chaque requête). */
function aut_extranet_publish() {
    $path = aut_extranet_cookie_path();
    if ($path !== '' && file_exists($path)) {
        $_SESSION['FFTA_EXTRANET_COOKIE'] = $path;
        $_SESSION['FFTA_EXTRANET_BASE']   = aut_extranet_base();
    } else {
        unset($_SESSION['FFTA_EXTRANET_COOKIE'], $_SESSION['FFTA_EXTRANET_BASE']);
    }
}

/** Déconnexion ianseo : le cookie extranet est détruit avec la session. */
function aut_extranet_forget() {
    $path = aut_extranet_cookie_path();
    if ($path !== '' && file_exists($path)) @unlink($path);
    $tmp = $_SESSION['FFTA_EXTRANET_TMP'] ?? '';
    if ($tmp !== '' && file_exists($tmp)) @unlink($tmp);
    unset($_SESSION['FFTA_EXTRANET_COOKIE'], $_SESSION['FFTA_EXTRANET_BASE'], $_SESSION['FFTA_EXTRANET_TMP']);
}

/* ------------------------------------------------------------------ */
/* Session Espace Dirigeant (dirigeant.ffta.fr) — convention partagée   */
/*                                                                      */
/* Contrairement à l'extranet, on NE relance PAS de login : le login    */
/* SSO (aut_ffta_curl_login, MFA comprise) ouvre déjà une session       */
/* Espace Dirigeant. On capture SON cookie (aut_dirigeant_stash) pour    */
/* le publier — le code MFA étant à usage unique, un second login        */
/* échouerait. Publie :                                                  */
/*   $_SESSION['FFTA_DIRIGEANT_COOKIE'] = chemin du cookie jar (0600)    */
/*   $_SESSION['FFTA_DIRIGEANT_BASE']   = URL de base de l'espace        */
/* ------------------------------------------------------------------ */

define('AUT_DIRIGEANT_BASE', AUT_FFTA_BASE);   // dirigeant.ffta.fr

function aut_dirigeant_enabled() {
    $c = aut_local_config()['dirigeant'] ?? array();
    return !array_key_exists('enabled', $c) || !empty($c['enabled']);
}
function aut_dirigeant_base() {
    $c = aut_local_config()['dirigeant'] ?? array();
    return rtrim($c['base'] ?? AUT_DIRIGEANT_BASE, '/');
}
function aut_dirigeant_cookie_path() {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    if ($token === '') return '';
    return sys_get_temp_dir() . '/ffta_dir_' . hash('sha256', 'dirigeant|' . $token) . '.ck';
}
/**
 * Capture le cookie de la session Espace Dirigeant ouverte par le login SSO.
 * Appelée au login (avant la session ianseo) : copie dans un temporaire que
 * aut_dirigeant_bind() déplacera vers le chemin dérivé du jeton.
 */
function aut_dirigeant_stash($cookieFile) {
    if (!aut_dirigeant_enabled() || !$cookieFile || !file_exists($cookieFile)) return;
    $tmp = tempnam(sys_get_temp_dir(), 'aut_dir_');
    @chmod($tmp, 0600);
    if (@copy($cookieFile, $tmp)) {
        $_SESSION['FFTA_DIRIGEANT_TMP'] = $tmp;
    } else {
        @unlink($tmp);
    }
}
function aut_dirigeant_bind() {
    $tmp = $_SESSION['FFTA_DIRIGEANT_TMP'] ?? '';
    unset($_SESSION['FFTA_DIRIGEANT_TMP']);
    $path = aut_dirigeant_cookie_path();
    if ($tmp !== '' && $path !== '' && file_exists($tmp)) {
        @rename($tmp, $path);
        @chmod($path, 0600);
    }
}
function aut_dirigeant_publish() {
    $path = aut_dirigeant_cookie_path();
    if ($path !== '' && file_exists($path)) {
        $_SESSION['FFTA_DIRIGEANT_COOKIE'] = $path;
        $_SESSION['FFTA_DIRIGEANT_BASE']   = aut_dirigeant_base();
    } else {
        unset($_SESSION['FFTA_DIRIGEANT_COOKIE'], $_SESSION['FFTA_DIRIGEANT_BASE']);
    }
}
function aut_dirigeant_forget() {
    $path = aut_dirigeant_cookie_path();
    if ($path !== '' && file_exists($path)) @unlink($path);
    $tmp = $_SESSION['FFTA_DIRIGEANT_TMP'] ?? '';
    if ($tmp !== '' && file_exists($tmp)) @unlink($tmp);
    unset($_SESSION['FFTA_DIRIGEANT_COOKIE'], $_SESSION['FFTA_DIRIGEANT_BASE'], $_SESSION['FFTA_DIRIGEANT_TMP']);
}

function aut_csrf_token() {
    if (empty($_SESSION['AUT_CSRF'])) $_SESSION['AUT_CSRF'] = bin2hex(random_bytes(16));
    return $_SESSION['AUT_CSRF'];
}

function aut_csrf_field() {
    return '<input type="hidden" name="aut_csrf" value="' . aut_csrf_token() . '">';
}

function aut_csrf_check() {
    return isset($_POST['aut_csrf'], $_SESSION['AUT_CSRF'])
        && hash_equals($_SESSION['AUT_CSRF'], $_POST['aut_csrf']);
}

/* ------------------------------------------------------------------ */
/* Déploiement des fichiers dist/ vers Modules/Authentication/         */
/* ------------------------------------------------------------------ */

function aut_dist_files() {
    return array('AuthFunctions.php', 'BlockFunction.php', 'LogIn.php', 'LogOut.php',
        'ChangePassword.php', 'Setup2FA.php', 'index.php');
}

function aut_dist_dir() {
    return aut_module_dir() . '/dist';
}

function aut_auth_dir() {
    global $CFG;
    return $CFG->DOCUMENT_PATH . 'Modules/Authentication';
}

function aut_dist_status() {
    $st = array('deployed' => true, 'drift' => false, 'files' => array());
    foreach (aut_dist_files() as $f) {
        $src = aut_dist_dir() . '/' . $f;
        $dst = aut_auth_dir() . '/' . $f;
        $ok = is_file($dst);
        $same = $ok && is_file($src) && md5_file($src) === md5_file($dst);
        if (!$ok) $st['deployed'] = false;
        if ($ok && !$same) $st['drift'] = true;
        $st['files'][$f] = array('deployed' => $ok, 'same' => $same);
    }
    return $st;
}

function aut_deploy(&$errors = array()) {
    $dst = aut_auth_dir();
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        $errors[] = "Impossible de créer $dst";
        return false;
    }
    $ok = true;
    foreach (aut_dist_files() as $f) {
        if (!@copy(aut_dist_dir() . '/' . $f, $dst . '/' . $f)) {
            $errors[] = "Copie échouée : $f";
            $ok = false;
        }
    }
    // Pose le filet d'auto-redéploiement (best-effort : ne bloque pas le déploiement).
    $e = '';
    if (!aut_ensure_selfheal($e)) $errors[] = "Filet auto-redéploiement : $e";
    return $ok;
}

/**
 * Bloc PHP d'AUTO-REDÉPLOIEMENT à écrire dans Common/config.inc.php (fichier local,
 * préservé aux MaJ ianseo, chargé AVANT Common/BlockDefines.php). Si USERAUTH est actif
 * mais que Modules/Authentication/BlockFunction.php a été effacé par une MaJ ianseo, il
 * recopie les hooks depuis dist/ (préservé) → plus d'erreur fatale « fail-closed », plus
 * de redéploiement manuel. Encadré de marqueurs pour une insertion IDEMPOTENTE.
 */
function aut_selfheal_block() {
    return "// === AUTH-SELFHEAL BEGIN (module Custom/AUTH — ne pas éditer à la main) ===\n"
        . "if (!empty(\$CFG->USERAUTH)) {\n"
        . "    \$bkAuthDir  = \$CFG->DOCUMENT_PATH . 'Modules/Authentication';\n"
        . "    \$bkAuthDist = \$CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/dist';\n"
        . "    if (!is_file(\$bkAuthDir . '/BlockFunction.php') && is_dir(\$bkAuthDist)) {\n"
        . "        @mkdir(\$bkAuthDir, 0755, true);\n"
        . "        foreach (array('AuthFunctions.php','BlockFunction.php','LogIn.php','LogOut.php','ChangePassword.php','Setup2FA.php','index.php') as \$bkF) @copy(\$bkAuthDist.'/'.\$bkF, \$bkAuthDir.'/'.\$bkF);\n"
        . "        unset(\$bkF);\n"
        . "    }\n"
        . "    unset(\$bkAuthDir, \$bkAuthDist);\n"
        . "}\n"
        . "// === AUTH-SELFHEAL END ===";
}

/**
 * S'assure que le bloc d'auto-redéploiement est présent dans Common/config.inc.php.
 * Idempotent : ne fait rien s'il est déjà là (marqueur AUTH-SELFHEAL). Inséré juste
 * avant la dernière balise de fermeture PHP. Appelé au déploiement et à l'activation.
 */
function aut_ensure_selfheal(&$error = '') {
    global $CFG;
    $f = $CFG->DOCUMENT_PATH . 'Common/config.inc.php';
    if (!is_file($f)) { $error = 'Common/config.inc.php introuvable.'; return false; }
    $c = file_get_contents($f);
    if ($c === false) { $error = 'Lecture de config.inc.php impossible.'; return false; }
    if (strpos($c, 'AUTH-SELFHEAL') !== false) return true;   // déjà présent

    $block = aut_selfheal_block();
    $pos = strrpos($c, '?>');
    if ($pos !== false) {
        $c = substr($c, 0, $pos) . $block . "\n" . substr($c, $pos);
    } else {
        $c = rtrim($c) . "\n\n" . $block . "\n";
    }
    @copy($f, $f . '.bak');
    if (@file_put_contents($f, $c) === false) { $error = 'Écriture de config.inc.php impossible.'; return false; }
    return true;
}

/** État du flag USERAUTH dans Common/config.inc.php : 'on' | 'off' | 'absent' | 'nofile' */
function aut_userauth_flag_state() {
    global $CFG;
    $f = $CFG->DOCUMENT_PATH . 'Common/config.inc.php';
    if (!is_file($f)) return 'nofile';
    $c = file_get_contents($f);
    if (!preg_match('/\$CFG->USERAUTH\s*=\s*(true|false)/i', $c, $m)) return 'absent';
    return strtolower($m[1]) == 'true' ? 'on' : 'off';
}

/**
 * Active/désactive USERAUTH dans Common/config.inc.php (survit aux MaJ ianseo,
 * contrairement à config.php qui est écrasé). Sauvegarde .bak avant écriture.
 */
function aut_set_userauth($on, &$error = '') {
    global $CFG;
    $f = $CFG->DOCUMENT_PATH . 'Common/config.inc.php';
    if (!is_file($f)) { $error = 'Common/config.inc.php introuvable.'; return false; }
    $c = file_get_contents($f);
    $line = '$CFG->USERAUTH = ' . ($on ? 'true' : 'false') . ';';
    if (preg_match('/\$CFG->USERAUTH\s*=\s*(true|false)\s*;/i', $c)) {
        $new = preg_replace('/\$CFG->USERAUTH\s*=\s*(true|false)\s*;/i', $line, $c, 1);
    } elseif (strpos($c, '?>') !== false) {
        $new = str_replace('?>', "\n// Multi-comptes (module Custom/AUTH)\n$line\n?>", $c);
    } else {
        $new = $c . "\n// Multi-comptes (module Custom/AUTH)\n$line\n";
    }
    if (!@copy($f, $f . '.bak')) { $error = 'Sauvegarde .bak impossible.'; return false; }
    if (@file_put_contents($f, $new) === false) { $error = 'Écriture de config.inc.php impossible.'; return false; }
    // À l'activation, poser le filet d'auto-redéploiement (survit aux MaJ ianseo).
    if ($on) { $e = ''; aut_ensure_selfheal($e); }
    return true;
}
