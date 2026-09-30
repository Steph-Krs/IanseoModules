<?php
/**
 * Database schema of the scheduled upload module.
 *
 * Tables are created when a page of the module opens, never from menu.php and
 * never from the scheduled task: cron.php only reads them, with the core's
 * $force argument, and does nothing until they exist. The body is gated by a
 * session flag carrying the schema version, so it costs one pass per session
 * and replays after an update of the module. It therefore runs on EVERY new
 * session: anything added here that rewrites existing rows must be gated on a
 * column having just been created, never on appearances.
 *
 * Two tables, one column prefix each, none shared with the core:
 *   AutoSendPlans (As) one row per competition: the schedule, what to send, and
 *                      the state the scheduled task keeps between two runs;
 *   AutoSendRuns  (Ar) one row per action of the scheduled task (upload,
 *                      opening or closing of the scoring), kept 60 days.
 *
 * Every DATETIME is UTC and is written from PHP, never with NOW(): ianseo sets
 * the MySQL session to the competition's offset once a competition is open, so
 * NOW() does not mean the same thing on a page and in the scheduled task.
 *
 * AsTournament and ArTournament hold a Tournament.ToId and take its type,
 * INT UNSIGNED: an AUTO_INCREMENT counter can sit far above the number of
 * competitions that exist, and a narrower column truncates without a warning.
 */

const AUS_SCHEMA_VERSION = 1;

/**
 * Create the module's tables if they are missing.
 */
function aus_schema() {
    $flag = '_aus_schema_v' . AUS_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    safe_w_sql("CREATE TABLE IF NOT EXISTS AutoSendPlans (
        AsTournament   INT UNSIGNED      NOT NULL,
        AsEnabled      TINYINT(1)        NOT NULL DEFAULT 0,
        AsSimulation   TINYINT(1)        NOT NULL DEFAULT 1,
        AsTimeZone     VARCHAR(64)       NOT NULL DEFAULT 'Europe/Paris',
        AsStart        DATETIME          NULL,
        AsEnd          DATETIME          NULL,
        AsInterval     SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        AsSessions     TINYINT(1)        NOT NULL DEFAULT 1,
        AsSessionKeys  TEXT              NULL,
        AsItems        TEXT              NULL,
        AsPingUrl      VARCHAR(255)      NOT NULL DEFAULT '',
        AsOpened       DATETIME          NULL,
        AsClosed       DATETIME          NULL,
        AsFinalSent    DATETIME          NULL,
        AsNextSend     DATETIME          NULL,
        AsSendNow      TINYINT(1)        NOT NULL DEFAULT 0,
        AsLastTry      DATETIME          NULL,
        AsLastSuccess  DATETIME          NULL,
        AsFailures     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        AsLastCode     VARCHAR(40)       NOT NULL DEFAULT '',
        AsLastDetail   TEXT              NULL,
        AsLastPing     DATETIME          NULL,
        AsHeartbeat    DATETIME          NULL,
        AsUpdated      DATETIME          NULL,
        PRIMARY KEY (AsTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS AutoSendRuns (
        ArId          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        ArTournament  INT UNSIGNED  NOT NULL,
        ArWhen        DATETIME      NOT NULL,
        ArKind        VARCHAR(10)   NOT NULL DEFAULT 'send',
        ArOk          TINYINT(1)    NOT NULL DEFAULT 0,
        ArSimulation  TINYINT(1)    NOT NULL DEFAULT 0,
        ArSeconds     DECIMAL(8,2)  NOT NULL DEFAULT 0,
        ArBytes       INT UNSIGNED  NOT NULL DEFAULT 0,
        ArCode        VARCHAR(40)   NOT NULL DEFAULT '',
        ArDetail      TEXT          NULL,
        PRIMARY KEY (ArId),
        KEY ArTournament (ArTournament, ArWhen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION[$flag] = true;
}
