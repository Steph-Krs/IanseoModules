<?php
/**
 * Database schema of the training module.
 *
 * Tables are created when a page of the module opens, never from menu.php. The
 * body is gated by a session flag carrying the schema version, so it costs one
 * pass per session and replays after an update of the module. It therefore runs
 * on EVERY new session: anything added here that rewrites existing rows must be
 * gated on a column having just been created, never on appearances.
 *
 * Seven tables, one prefix each, none shared with the core:
 *   TrainingTemplates     (Tm) a reference format: a snapshot of a whole
 *                              competition without its archers, as the core's
 *                              export builds it, plus a summary for the forms;
 *   TrainingArchers       (Ar) the regular archers, ticked when a training is
 *                              created;
 *   TrainingGroups        (Gp) named groups of regular archers;
 *   TrainingGroupMembers  (Gm) who belongs to which group;
 *   TrainingRuns          (Rn) the competitions created by the module, whether
 *                              they show the simplified menu, and whether their
 *                              qualification round is simulated (duels only);
 *   TrainingResults       (Rs) the results of each archer of each training, kept
 *                              so that they outlive the competition;
 *   TrainingGhosts        (Gh) the fictitious competitors of a training and the
 *                              average their scores are drawn around.
 *
 * Version 2 added TrainingResults and TrainingGhosts (new tables only).
 *
 * RnTournament, RsTournament, GhTournament hold a Tournament.ToId, RsEntry and
 * GhEntry an Entries.EnId, and take their type, INT UNSIGNED: an
 * AUTO_INCREMENT counter can sit far above the number of competitions that
 * exist, and a narrower column truncates without a warning.
 */

const TRN_SCHEMA_VERSION = 2;

/**
 * Create the module's tables if they are missing.
 */
function trn_schema() {
    $flag = '_trn_schema_v' . TRN_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingTemplates (
        TmId        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        TmName      VARCHAR(100) NOT NULL DEFAULT '',
        TmSimple    TINYINT UNSIGNED NOT NULL DEFAULT 1,
        TmSource    VARCHAR(8)   NOT NULL DEFAULT '',
        TmRefStart  DATETIME     NULL,
        TmSummary   MEDIUMTEXT   NULL,
        TmData      LONGBLOB     NULL,
        TmCreated   DATETIME     NULL,
        TmUpdated   DATETIME     NULL,
        PRIMARY KEY (TmId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingArchers (
        ArId         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ArCode       VARCHAR(25)  NOT NULL DEFAULT '',
        ArIocCode    VARCHAR(5)   NOT NULL DEFAULT '',
        ArFamilyName VARCHAR(60)  NOT NULL DEFAULT '',
        ArGivenName  VARCHAR(30)  NOT NULL DEFAULT '',
        ArSex        TINYINT UNSIGNED NOT NULL DEFAULT 0,
        ArDob        DATE         NULL,
        ArClubCode   VARCHAR(10)  NOT NULL DEFAULT '',
        ArClubName   VARCHAR(80)  NOT NULL DEFAULT '',
        ArDivision   VARCHAR(4)   NOT NULL DEFAULT '',
        ArClass      VARCHAR(6)   NOT NULL DEFAULT '',
        ArActive     TINYINT UNSIGNED NOT NULL DEFAULT 1,
        ArCreated    DATETIME     NULL,
        PRIMARY KEY (ArId),
        KEY ArCode (ArCode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingGroups (
        GpId    INT UNSIGNED NOT NULL AUTO_INCREMENT,
        GpName  VARCHAR(64)  NOT NULL DEFAULT '',
        PRIMARY KEY (GpId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingGroupMembers (
        GmGroup  INT UNSIGNED NOT NULL,
        GmArcher INT UNSIGNED NOT NULL,
        PRIMARY KEY (GmGroup, GmArcher),
        KEY GmArcher (GmArcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingRuns (
        RnTournament INT UNSIGNED NOT NULL,
        RnTemplate   INT UNSIGNED NOT NULL DEFAULT 0,
        RnSimple     TINYINT UNSIGNED NOT NULL DEFAULT 1,
        RnQualSim    TINYINT UNSIGNED NOT NULL DEFAULT 0,
        RnCreated    DATETIME     NULL,
        PRIMARY KEY (RnTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingResults (
        RsTournament INT UNSIGNED NOT NULL,
        RsEntry      INT UNSIGNED NOT NULL,
        RsCode       VARCHAR(8)   NOT NULL DEFAULT '',
        RsName       VARCHAR(200) NOT NULL DEFAULT '',
        RsDate       DATE         NULL,
        RsFormat     VARCHAR(100) NOT NULL DEFAULT '',
        RsSim        TINYINT UNSIGNED NOT NULL DEFAULT 0,
        RsKey        VARCHAR(100) NOT NULL DEFAULT '',
        RsLicence    VARCHAR(25)  NOT NULL DEFAULT '',
        RsFamily     VARCHAR(60)  NOT NULL DEFAULT '',
        RsGiven      VARCHAR(30)  NOT NULL DEFAULT '',
        RsDivision   VARCHAR(4)   NOT NULL DEFAULT '',
        RsClass      VARCHAR(6)   NOT NULL DEFAULT '',
        RsDistances  VARCHAR(60)  NOT NULL DEFAULT '',
        RsFace       VARCHAR(80)  NOT NULL DEFAULT '',
        RsScore      INT          NOT NULL DEFAULT 0,
        RsGolds      INT          NOT NULL DEFAULT 0,
        RsXNine      INT          NOT NULL DEFAULT 0,
        RsArrows     INT          NOT NULL DEFAULT 0,
        RsQualRank   INT          NOT NULL DEFAULT 0,
        RsEvent      VARCHAR(10)  NOT NULL DEFAULT '',
        RsFinalRank  INT          NOT NULL DEFAULT 0,
        RsWon        INT          NOT NULL DEFAULT 0,
        RsLost       INT          NOT NULL DEFAULT 0,
        RsUpdated    DATETIME     NULL,
        PRIMARY KEY (RsTournament, RsEntry),
        KEY RsKey (RsKey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TrainingGhosts (
        GhEntry      INT UNSIGNED NOT NULL,
        GhTournament INT UNSIGNED NOT NULL,
        GhAverage    DECIMAL(4,2) NOT NULL DEFAULT 8.00,
        GhSpread     DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        PRIMARY KEY (GhEntry),
        KEY GhTournament (GhTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION[$flag] = true;
}
