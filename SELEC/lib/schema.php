<?php
/**
 * lib/schema.php — création et migration des tables SELEC_*.
 *
 * Collation imposée : utf8mb4_unicode_ci. Les tables ianseo peuvent être en
 * utf8mb4_0900_ai_ci ou _as_ci selon le serveur ; toute jointure entre une
 * colonne VARCHAR d'ici et une colonne VARCHAR de ianseo (EvCode, EnCode…)
 * doit porter un COLLATE utf8mb4_unicode_ci côté SELEC_, sinon MySQL 8 renvoie
 * l'erreur 1267. Voir selec_coll().
 *
 * RÈGLE DE MIGRATION (bug déjà rencontré sur REPARTITION_EPREUVES) : un
 * selec_colonne($table, …) doit TOUJOURS être placé APRÈS le CREATE TABLE
 * IF NOT EXISTS de $table dans ce fichier — jamais avant. Sur une installation
 * neuve, un ALTER sur une table pas encore créée lève une exception qui
 * interrompt selec_schema() en plein milieu, et toutes les tables suivantes
 * ne sont jamais créées.
 */

if (!defined('SELEC_SCHEMA_VERSION')) define('SELEC_SCHEMA_VERSION', 2);

/** Suffixe de collation à coller derrière une colonne SELEC_ jointe à du ianseo. */
function selec_coll()
{
    return ' COLLATE utf8mb4_unicode_ci ';
}

/**
 * Ajoute une colonne si elle manque. MySQL n'accepte pas ADD COLUMN IF NOT EXISTS
 * avant la 8.0.29 : on interroge information_schema.
 * Retourne true seulement si la colonne vient d'être ajoutée — sert à gater une
 * migration de DONNÉES qui ne doit tourner qu'une fois pour toute l'installation
 * (selec_schema() rejoue son corps à chaque nouvelle session).
 */
function selec_colonne($table, $colonne, $definition)
{
    // Garde-fou : le module ne modifie JAMAIS le schéma de ianseo. Sur un
    // serveur de production, un ALTER TABLE sur une table du cœur casserait
    // toutes les compétitions et serait effacé à la mise à jour suivante. Les
    // deux noms sont aussi filtrés parce qu'ils entrent dans la requête en
    // identifiants, là où StrSafe_DB ne protège rien.
    if (!preg_match('/^SELEC_[A-Za-z0-9_]+$/', (string) $table)
        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $colonne)) {
        return false;
    }

    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = " . StrSafe_DB($table) . "
          AND COLUMN_NAME = " . StrSafe_DB($colonne));
    $r = $rs ? safe_fetch($rs) : null;
    if ($r && intval($r->n) === 0) {
        safe_w_sql("ALTER TABLE `$table` ADD COLUMN `$colonne` $definition");
        return true;
    }
    return false;
}

/** Crée les tables si besoin. Idempotent, protégé par un drapeau de session. */
function selec_schema()
{
    $flag = '_selec_schema_v' . SELEC_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    // ── Rattachement d'une compétition à un mode de sélection ────────────────
    // ScSnapshot fige le JSON du mode AU MOMENT du rattachement : si le
    // catalogue livré avec le module évolue (correction d'un barème, nouvelle
    // version du mode), les résultats déjà calculés d'une compétition en cours
    // ne bougent pas tout seuls. C'est la garantie de reproductibilité — la
    // seule façon de changer les règles d'une compétition est un ré-ancrage
    // explicite, tracé dans SELEC_Log.
    safe_w_sql("CREATE TABLE IF NOT EXISTS SELEC_Config (
        ScTournament INT UNSIGNED  NOT NULL,
        ScMode       VARCHAR(64)   NOT NULL DEFAULT '',
        ScModeVer    VARCHAR(16)   NOT NULL DEFAULT '',
        ScSnapshot   MEDIUMTEXT    NOT NULL,
        ScOptions    TEXT          NOT NULL,
        ScSnapDate   DATETIME      NULL DEFAULT NULL,
        ScUpdated    DATETIME      NULL DEFAULT NULL,
        PRIMARY KEY (ScTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Rattachement étape/rôle → épreuve ianseo, par catégorie ──────────────
    // Une « catégorie de sélection » est identifiée par le code de l'épreuve
    // ianseo qui porte ses qualifications (SbCategory). Les tournois, poules et
    // duels simulés vivent dans d'AUTRES épreuves ianseo : c'est ici qu'on dit
    // laquelle joue quel rôle (SbSlot : 'principal', 'consolante', 'poule'…).
    safe_w_sql("CREATE TABLE IF NOT EXISTS SELEC_Bind (
        SbTournament INT UNSIGNED NOT NULL,
        SbCategory   VARCHAR(10)  NOT NULL,
        SbStep       VARCHAR(24)  NOT NULL,
        SbSlot       VARCHAR(24)  NOT NULL,
        SbEvent      VARCHAR(10)  NOT NULL DEFAULT '',
        SbOrder      SMALLINT     NOT NULL DEFAULT 0,
        PRIMARY KEY (SbTournament, SbCategory, SbStep, SbSlot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Résultats calculés, une ligne par (compétition, catégorie, étape, archer)
    // SrValueNum/SrValueDen : la valeur classée est TOUJOURS stockée en fraction
    // entière (numérateur/dénominateur), jamais en flottant — une moyenne de set
    // ou une valeur de flèche comparée en flottant peut déclarer « ex aequo »
    // deux archers qui ne le sont pas (ou l'inverse). Les comparaisons se font
    // par produit croisé. SrValue reste là pour l'affichage seul.
    safe_w_sql("CREATE TABLE IF NOT EXISTS SELEC_Results (
        SrTournament INT UNSIGNED  NOT NULL,
        SrCategory   VARCHAR(10)   NOT NULL,
        SrStep       VARCHAR(24)   NOT NULL,
        SrEntry      INT UNSIGNED  NOT NULL,
        SrRank       SMALLINT      NOT NULL DEFAULT 0,
        SrPointsC    INT           NOT NULL DEFAULT 0,
        SrValue      DECIMAL(14,6) NOT NULL DEFAULT 0,
        SrValueNum   BIGINT        NOT NULL DEFAULT 0,
        SrValueDen   BIGINT        NOT NULL DEFAULT 1,
        SrTie        VARCHAR(80)   NOT NULL DEFAULT '',
        SrExAequo    TINYINT       NOT NULL DEFAULT 0,
        SrRetenu     TINYINT       NOT NULL DEFAULT 1,
        SrDetail     MEDIUMTEXT    NOT NULL,
        SrUpdated    DATETIME      NULL DEFAULT NULL,
        PRIMARY KEY (SrTournament, SrCategory, SrStep, SrEntry),
        KEY k_etape (SrTournament, SrStep),
        KEY k_archer (SrTournament, SrEntry)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Tirs de barrage saisis à la main ─────────────────────────────────────
    // SoOrder : 1 = gagnant du barrage. Ne s'applique QU'AUX archers encore à
    // égalité après toute la cascade de départage de l'étape.
    safe_w_sql("CREATE TABLE IF NOT EXISTS SELEC_Shootoff (
        SoTournament INT UNSIGNED NOT NULL,
        SoCategory   VARCHAR(10)  NOT NULL,
        SoStep       VARCHAR(24)  NOT NULL,
        SoEntry      INT UNSIGNED NOT NULL,
        SoOrder      SMALLINT     NOT NULL DEFAULT 0,
        SoNote       VARCHAR(80)  NOT NULL DEFAULT '',
        SoDate       DATETIME     NULL DEFAULT NULL,
        PRIMARY KEY (SoTournament, SoCategory, SoStep, SoEntry)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Gel d'une étape tirée ────────────────────────────────────────────────
    // `Qualifications` n'a qu'UNE ligne par archer : les scores d'une série
    // vivent dans QuD1..QuD8, et rien dans ianseo n'empêche une saisie manuelle
    // de réécrire par-dessus une série déjà tirée (la page de saisie propose
    // toutes les distances, quelle que soit la session en cours). Sur une
    // sélection, une qualification terminée doit devenir intouchable.
    //
    // On copie donc ici, série par série, ce que l'archer a réellement tiré :
    // score, 10, X, touchées et la CHAÎNE DE FLÈCHES — de quoi réimprimer une
    // feuille de marque des mois plus tard, et de quoi tout remettre en place si
    // une fausse manœuvre écrase la saisie.
    //
    // Une étape gelée n'est plus lue dans Qualifications par le moteur : son
    // classement ne peut donc plus bouger, quoi qu'il arrive ensuite en base.
    safe_w_sql("CREATE TABLE IF NOT EXISTS SELEC_Archive (
        SaTournament INT UNSIGNED  NOT NULL,
        SaStep       VARCHAR(24)   NOT NULL,
        SaEntry      INT UNSIGNED  NOT NULL,
        SaSession    TINYINT       NOT NULL DEFAULT 0,
        SaTarget     SMALLINT      NOT NULL DEFAULT 0,
        SaLetter     VARCHAR(1)    NOT NULL DEFAULT '',
        SaScore      INT           NOT NULL DEFAULT 0,
        SaGold       INT           NOT NULL DEFAULT 0,
        SaX          INT           NOT NULL DEFAULT 0,
        SaArrows     INT           NOT NULL DEFAULT 0,
        SaData       MEDIUMTEXT    NOT NULL,
        SaDate       DATETIME      NULL DEFAULT NULL,
        SaUser       VARCHAR(80)   NOT NULL DEFAULT '',
        PRIMARY KEY (SaTournament, SaStep, SaEntry),
        KEY k_etape (SaTournament, SaStep)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Journal : toute écriture et tout recalcul laissent une trace ─────────
    safe_w_sql("CREATE TABLE IF NOT EXISTS SELEC_Log (
        SlId         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        SlTournament INT UNSIGNED NOT NULL DEFAULT 0,
        SlCategory   VARCHAR(10)  NOT NULL DEFAULT '',
        SlDate       DATETIME     NULL DEFAULT NULL,
        SlUser       VARCHAR(80)  NOT NULL DEFAULT '',
        SlAction     VARCHAR(40)  NOT NULL DEFAULT '',
        SlDetail     TEXT         NOT NULL,
        PRIMARY KEY (SlId),
        KEY k_tour (SlTournament, SlDate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION[$flag] = 1;
}

/** Écrit une ligne de journal. Jamais bloquant : une trace ratée n'arrête rien. */
function selec_log($tourId, $action, $detail = '', $category = '')
{
    $user = '';
    if (!empty($_SESSION['AUTH_User']))      $user = (string) $_SESSION['AUTH_User'];
    elseif (!empty($_SESSION['UserLogged'])) $user = (string) $_SESSION['UserLogged'];
    if (!is_string($detail)) $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);

    try {
        safe_w_sql("INSERT INTO SELEC_Log
            SET SlTournament=" . intval($tourId) . ",
                SlCategory=" . StrSafe_DB($category) . ",
                SlDate=" . StrSafe_DB(date('Y-m-d H:i:s')) . ",
                SlUser=" . StrSafe_DB(mb_substr($user, 0, 80)) . ",
                SlAction=" . StrSafe_DB(mb_substr($action, 0, 40)) . ",
                SlDetail=" . StrSafe_DB((string) $detail));
    } catch (Exception $e) {
        // Ignoré volontairement.
    }
}
