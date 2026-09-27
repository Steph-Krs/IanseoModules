<?php
/**
 * Database schema of the live draw module.
 *
 * Tables are created when a page of the module opens, never from menu.php. The
 * body is gated by a session flag carrying the schema version, so it costs one
 * pass per session and replays after an update of the module. It therefore runs
 * on EVERY new session: anything added here that rewrites existing rows must be
 * gated on a column having just been created, never on appearances.
 *
 * Three tables, one prefix each, none shared with the core:
 *   DrawShows       (Dw) one draw ceremony: titles, screen tokens, what is on air;
 *   DrawCategories  (Dc) one list: teams to draw, or the season's stages shown
 *                        one after the other (DcType);
 *   DrawTeams       (Dt) one entry of a list — a team, or a stage — with its
 *                        place, its history and its notes.
 *
 * DwSource and DwSeasonApplied hold a Tournament.ToId and take its type,
 * INT UNSIGNED: an AUTO_INCREMENT counter can sit far above the number of
 * competitions that exist, and a narrower column truncates without a warning.
 *
 * Version 2 added DcType, DtDetail and DtOrder. They are declared in the CREATE
 * statements for new installations and added afterwards for older ones, through
 * the shared non-fatal helper, always after the CREATE of their table.
 */

const TIR_SCHEMA_VERSION = 2;

/**
 * Create the module's tables if they are missing, and bring older ones up to date.
 */
function tir_schema() {
    $flag = '_tir_schema_v' . TIR_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    safe_w_sql("CREATE TABLE IF NOT EXISTS DrawShows (
        DwId            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        DwTitle         VARCHAR(120) NOT NULL DEFAULT '',
        DwSubtitle      VARCHAR(160) NOT NULL DEFAULT '',
        DwSource        INT UNSIGNED NOT NULL DEFAULT 0,
        DwSeasonApplied INT UNSIGNED NOT NULL DEFAULT 0,
        DwToken         CHAR(32)     NOT NULL,
        DwSpeakerToken  CHAR(32)     NOT NULL,
        DwScene         VARCHAR(12)  NOT NULL DEFAULT 'idle',
        DwCategory      INT UNSIGNED NOT NULL DEFAULT 0,
        DwStats         VARCHAR(60)  NOT NULL DEFAULT '',
        DwLook          TEXT         NULL,
        DwImage         MEDIUMTEXT   NULL,
        DwImageType     VARCHAR(40)  NOT NULL DEFAULT '',
        DwRevision      INT UNSIGNED NOT NULL DEFAULT 1,
        DwDataRevision  INT UNSIGNED NOT NULL DEFAULT 1,
        DwCreated       DATETIME     NULL,
        DwUpdated       DATETIME     NULL,
        PRIMARY KEY (DwId),
        UNIQUE KEY DwToken (DwToken),
        UNIQUE KEY DwSpeakerToken (DwSpeakerToken)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS DrawCategories (
        DcId     INT UNSIGNED NOT NULL AUTO_INCREMENT,
        DcShow   INT UNSIGNED NOT NULL,
        DcOrder  SMALLINT     NOT NULL DEFAULT 0,
        DcName   VARCHAR(120) NOT NULL DEFAULT '',
        DcEvent  VARCHAR(10)  NOT NULL DEFAULT '',
        DcType   VARCHAR(10)  NOT NULL DEFAULT 'teams',
        PRIMARY KEY (DcId),
        KEY DcShow (DcShow, DcOrder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS DrawTeams (
        DtId             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        DtCategory       INT UNSIGNED NOT NULL,
        DtName           VARCHAR(120) NOT NULL DEFAULT '',
        DtDetail         VARCHAR(160) NOT NULL DEFAULT '',
        DtClub           VARCHAR(10)  NOT NULL DEFAULT '',
        DtPosition       SMALLINT     NOT NULL DEFAULT 0,
        DtOrder          SMALLINT     NOT NULL DEFAULT 0,
        DtDrawn          DATETIME     NULL,
        DtParticipations SMALLINT     NULL,
        DtWins           SMALLINT     NULL,
        DtPodiums        SMALLINT     NULL,
        DtRankPrev       SMALLINT     NULL,
        DtNote           TEXT         NULL,
        PRIMARY KEY (DtId),
        KEY DtCategory (DtCategory, DtPosition)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Version 2, for tables created by version 1. Structure only: no data to rewrite,
    // the defaults describe version 1 rows exactly (team lists, entry order).
    // Without the shared library the migration waits: the flag stays unset so the
    // next request tries again once _shared/ has been restored.
    $lib = dirname(__DIR__, 2) . '/_shared/schema-lib.php';
    if (!is_file($lib)) return;
    require_once $lib;
    cmod_add_column('DrawCategories', 'DcType', "VARCHAR(10) NOT NULL DEFAULT 'teams'", 'DcEvent');
    cmod_add_column('DrawTeams', 'DtDetail', "VARCHAR(160) NOT NULL DEFAULT ''", 'DtName');
    cmod_add_column('DrawTeams', 'DtOrder', "SMALLINT NOT NULL DEFAULT 0", 'DtPosition');

    $_SESSION[$flag] = true;
}
