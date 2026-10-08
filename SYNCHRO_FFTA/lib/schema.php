<?php
/**
 * SYNCHRO_FFTA — creation and migration of the module's tables.
 *
 * Called by the pages of the creation flow only (create.php, ajax-create.php, create-run.php),
 * never by menu.php, which reads no table of this module.
 *
 * Collation imposed: utf8mb4_unicode_ci. A join between a VARCHAR column of here and a VARCHAR
 * column of ianseo carries COLLATE utf8mb4_unicode_ci on this side (see sfa_coll()), otherwise
 * MySQL 8 may answer error 1267 on a server where the two collations differ.
 */

if (!defined('SFA_SCHEMA_VERSION')) define('SFA_SCHEMA_VERSION', 1);

/** Collation suffix for a column of this module joined to an ianseo one. */
function sfa_coll(): string
{
    return ' COLLATE utf8mb4_unicode_ci ';
}

/**
 * Adds a column when it is missing; true only when it has just been added, so that a data
 * migration can be gated on it (the schema body runs again at every new session).
 * Never fatal: does nothing on a table that does not exist yet (an ALTER on a missing table is
 * error 1146, which safe_w_sql() turns into an exit and a half-installed module).
 */
function sfa_colonne(string $table, string $column, string $definition): bool
{
    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($table));
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r || (int) $r->n === 0) {
        return false;
    }

    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($table) . "
          AND COLUMN_NAME = " . StrSafe_DB($column));
    $r = $rs ? safe_fetch($rs) : null;
    if ($r && (int) $r->n === 0) {
        safe_w_sql("ALTER TABLE `$table` ADD COLUMN `$column` $definition");

        return true;
    }

    return false;
}

/** Creates the tables when needed. Idempotent, once per session and per schema version. */
function sfa_schema(): void
{
    $flag = '_sfa_schema_v' . SFA_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) {
        return;
    }

    // v1 — the FFTA calendar as read on the extranet, one row per Exalto event, for every module
    // of the server. FeId is the Exalto event number (EprvId); FeCode is the ianseo competition
    // code built from it (Tournament.ToCode). List columns are refreshed by every calendar search;
    // venue columns come from the event's « Détail » box. FeLatitude / FeLongitude stay only while
    // the organiser keeps the venue the extranet gives: FeVenueOwn = 1 once the organiser changed
    // it at creation, and the extranet coordinates are then never written again.
    safe_w_sql("CREATE TABLE IF NOT EXISTS FftaEvents (
        FeId           INT UNSIGNED NOT NULL,
        FeCode         VARCHAR(8) NOT NULL DEFAULT '',
        FeSeason       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        FeName         VARCHAR(255) NOT NULL DEFAULT '',
        FeOrgCode      VARCHAR(10) NOT NULL DEFAULT '',
        FeOrgName      VARCHAR(255) NOT NULL DEFAULT '',
        FeDateFrom     DATE NULL,
        FeDateTo       DATE NULL,
        FeState        CHAR(1) NOT NULL DEFAULT '',
        FeDiscipline   VARCHAR(100) NOT NULL DEFAULT '',
        FeFormat       VARCHAR(150) NOT NULL DEFAULT '',
        FeChampionship VARCHAR(150) NOT NULL DEFAULT '',
        FeValidePara   TINYINT NOT NULL DEFAULT 0,
        FeDuels        TINYINT NOT NULL DEFAULT 0,
        FeDistinction  VARCHAR(50) NOT NULL DEFAULT '',
        FeCity         VARCHAR(150) NOT NULL DEFAULT '',
        FeVenueName    VARCHAR(255) NOT NULL DEFAULT '',
        FeVenueStreet  VARCHAR(255) NOT NULL DEFAULT '',
        FeVenueZip     VARCHAR(10) NOT NULL DEFAULT '',
        FeVenueCity    VARCHAR(150) NOT NULL DEFAULT '',
        FeVenueCountry VARCHAR(100) NOT NULL DEFAULT '',
        FeLatitude     DECIMAL(9,6) NULL,
        FeLongitude    DECIMAL(9,6) NULL,
        FeVenueOwn     TINYINT NOT NULL DEFAULT 0,
        FeSeenAt       DATETIME NULL,
        FeDetailAt     DATETIME NULL,
        PRIMARY KEY (FeId),
        KEY FeCode (FeCode),
        KEY FeDateFrom (FeDateFrom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION[$flag] = true;
}
