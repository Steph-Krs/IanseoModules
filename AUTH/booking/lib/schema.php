<?php
/**
 * lib/schema.php — creation and migration of the BK_* tables.
 *
 * Collation: utf8mb4_unicode_ci. The ianseo tables may be in utf8mb4_0900_ai_ci or _as_ci
 * depending on the server; any join between a VARCHAR column of here and a VARCHAR column of
 * ianseo carries COLLATE utf8mb4_unicode_ci on the BK_ side, otherwise MySQL 8 gives error 1267.
 *
 * Migration rule (lesson of REPARTITION_EPREUVES): a bk_colonne($table, …) is ALWAYS placed
 * AFTER the CREATE TABLE IF NOT EXISTS of $table, even when the table seems "surely there
 * already" — otherwise the ALTER fails on a new installation and stops the whole function.
 */

if (!defined('BK_SCHEMA_VERSION')) define('BK_SCHEMA_VERSION', 29);

// Every library of the module loads this file: the right "now" and the texts come with it.
require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/lang.php';
require_once dirname(__DIR__, 2) . '/names-lib.php';

/** Collation suffix to put after a BK_ column joined to an ianseo one. */
function bk_coll()
{
    return ' COLLATE utf8mb4_unicode_ci ';
}

/** Adds a column when missing (MySQL < 8.0.29 has no ADD COLUMN IF NOT EXISTS). */
function bk_colonne($table, $colonne, $definition)
{
    // Never fatal: on a missing table the ALTER would kill the whole schema function
    // (safe_error exits). Returning false self-heals: the body replays next session.
    $t = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($table)));
    if (!$t || intval($t->n) === 0) return false;
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

/** Adds an index when missing ($definition: "KEY name (cols)" or "UNIQUE KEY …"); never fatal. */
function bk_index($table, $name, $definition)
{
    $t = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($table)));
    if (!$t || intval($t->n) === 0) return false;
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($table) . "
          AND INDEX_NAME = " . StrSafe_DB($name)));
    if ($r && intval($r->n) === 0) {
        safe_w_sql("ALTER TABLE `$table` ADD $definition");
        return true;
    }
    return false;
}

/** Creates the tables when needed. Idempotent, guarded by a session flag. */
function bk_schema()
{
    aut_table_names();   // before any CREATE: see names-lib.php
    $flag = '_bk_schema_v' . BK_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    // Licensee accounts. Empty BaPassword = SSO account (sentinel for the relay to the licensee
    // space, same convention as AuthUsers.AuPassword).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingArchers (
        BaId         INT AUTO_INCREMENT PRIMARY KEY,
        BaLicence    VARCHAR(25)  NOT NULL,
        BaPassword   VARCHAR(255) NOT NULL DEFAULT '',
        BaEmail      VARCHAR(128) NOT NULL DEFAULT '',
        BaFamilyName VARCHAR(60)  NOT NULL DEFAULT '',
        BaName       VARCHAR(30)  NOT NULL DEFAULT '',
        BaClubCode   VARCHAR(10)  NOT NULL DEFAULT '',
        BaActive     TINYINT      NOT NULL DEFAULT 1,
        BaLastLogin  DATETIME     NULL,
        BaCreated    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY BaLicenceIdx (BaLicence)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v18: Exalto id ("personne_id") read on the home page of the licensee space at sign-in —
    // needed for the URL of the licence certificate (…/pdf/p/{id}/{season}). It is NOT the
    // licence number (they differ). A plain internal federation id, not a secret; never hard
    // coded (captured for each archer).
    bk_colonne('BookingArchers', 'BaExaltoId', "VARCHAR(16) NOT NULL DEFAULT '' AFTER BaLicence");

    // v19: timestamped + versioned acceptance of the terms of use (BaCguVer = accepted
    // version; BaCguAt = date/time). Asked again when the version changes (legal-lib.php).
    bk_colonne('BookingArchers', 'BaCguVer', "VARCHAR(16) NOT NULL DEFAULT '' AFTER BaExaltoId");
    bk_colonne('BookingArchers', 'BaCguAt',  "DATETIME NULL AFTER BaCguVer");

    // OPTIONAL 2FA (TOTP), turned on by the licensee from their space (never forced). Same
    // scheme as AuthUsers (standalone copy, see lib/totp.php). Reset by the administrator
    // (lost phone) from the Accounts page.
    bk_colonne('BookingArchers', 'BaTotpSecret',   "VARCHAR(64) NOT NULL DEFAULT '' AFTER BaActive");
    bk_colonne('BookingArchers', 'BaTotpEnabled',  "TINYINT NOT NULL DEFAULT 0 AFTER BaTotpSecret");
    bk_colonne('BookingArchers', 'BaTotpLastSlot', "BIGINT NOT NULL DEFAULT 0 AFTER BaTotpEnabled");

    // Token sessions: only the HASH is stored (a PHP session dump gives no reusable secret).
    // Same principle as AuthSessions.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingSessions (
        BkId        INT AUTO_INCREMENT PRIMARY KEY,
        BkArcher    INT      NOT NULL,
        BkTokenHash CHAR(64) NOT NULL,
        BkCreated   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BkLastSeen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BkIP        VARCHAR(45)  NOT NULL DEFAULT '',
        BkUA        VARCHAR(160) NOT NULL DEFAULT '',
        UNIQUE KEY BkTokenIdx (BkTokenHash),
        KEY BkArcherIdx (BkArcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Log: also used against brute force and enumeration (creating an account queries the
    // licensee base — to protect as much as the sign-in itself).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingLog (
        BlId    INT AUTO_INCREMENT PRIMARY KEY,
        BlWhen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BlUser  VARCHAR(64) NOT NULL DEFAULT '',
        BlIP    VARCHAR(45) NOT NULL DEFAULT '',
        BlEvent VARCHAR(32) NOT NULL,
        KEY BlWhenIdx (BlWhen),
        KEY BlUserIdx (BlUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Opening of the registration per competition. The FIELD settings (targets, departures,
    // distances, faces, rhythm) are NOT copied here: they are read from Session /
    // DistanceInformation / TargetFaces / TournamentDistances.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingCompetitions (
        BcTournament   INT NOT NULL PRIMARY KEY,
        BcOpen         TINYINT  NOT NULL DEFAULT 0,
        BcOpenFrom     DATETIME NULL,
        BcOpenTo       DATETIME NULL,
        BcRestrictKind VARCHAR(4)  NOT NULL DEFAULT '',
        BcRestrictCode VARCHAR(16) NOT NULL DEFAULT '',
        BcRestrictTo   DATETIME NULL,
        BcMaxPerClubPerTarget TINYINT NOT NULL DEFAULT 2,
        BcMinClubsPerSession  TINYINT NOT NULL DEFAULT 3,
        BcShowAssignment TINYINT NOT NULL DEFAULT 0,
        BcShowGauges     TINYINT NOT NULL DEFAULT 1,
        BcAllowScoresheet TINYINT NOT NULL DEFAULT 0,
        BcFee          DECIMAL(6,2) NOT NULL DEFAULT 0,
        BcUpdated      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Wishes offered to the archer when registering (set by the organiser). Default: only the
    // position on the target; "on the same target as" and the free field are off by default.
    bk_colonne('BookingCompetitions', 'BcWishLetter', "TINYINT NOT NULL DEFAULT 1 AFTER BcAllowScoresheet");
    bk_colonne('BookingCompetitions', 'BcWishWith',   "TINYINT NOT NULL DEFAULT 0 AFTER BcWishLetter");
    bk_colonne('BookingCompetitions', 'BcWishFree',   "TINYINT NOT NULL DEFAULT 0 AFTER BcWishWith");

    // v4: advanced tariff (JSON). Empty = flat fee BcFee (original behaviour). Structure:
    // categories[] (fixed price per bow/class), departures{} (Δ per departure), prov{} (local
    // Δ department/league), rank{} (decreasing Δ for several registrations).
    bk_colonne('BookingCompetitions', 'BcPricing', "LONGTEXT NULL AFTER BcFee");

    // v5: shop (a generalised refreshment stall: souvenirs, accommodation, access…). The
    // shop's own deadline; empty = follows the opening of the registration.
    bk_colonne('BookingCompetitions', 'BcShopUntil', "DATETIME NULL AFTER BcPricing");

    // v6: leave a competition out of the competitor statistics (test / unofficial
    // competition). Default 0 = shown in the statistics.
    bk_colonne('BookingCompetitions', 'BcExcludeStats', "TINYINT NOT NULL DEFAULT 0 AFTER BcShopUntil");

    // v8: means of payment offered by the organiser (JSON). Empty = none.
    // Each entry: {m: means, when: before/onsite/both, info: text}.
    bk_colonne('BookingCompetitions', 'BcPayInfo', "LONGTEXT NULL AFTER BcExcludeStats");

    // v10: manual validation of the registrations. 0 = automatic (as before); 1 = each
    // registration must be validated by the organiser before placement.
    bk_colonne('BookingCompetitions', 'BcManualValidation', "TINYINT NOT NULL DEFAULT 0 AFTER BcPayInfo");

    // v11: competition mandate (JSON) — template, colour, logos shown and free text blocks.
    // Empty = never set up (the generator then offers its defaults).
    bk_colonne('BookingCompetitions', 'BcMandate', "LONGTEXT NULL AFTER BcManualValidation");

    // v12: visibility of the mandate to the archers. Three states (NULL = never chosen →
    // visible as soon as a mandate exists; 1 = visible; 0 = hidden). See bk_mandate_visible().
    bk_colonne('BookingCompetitions', 'BcShowMandate', "TINYINT NULL AFTER BcMandate");

    // v13: competition documents the archers may read. Link to the public ianseo.net page
    // (derived from ToOnlineId; empty = no link).
    bk_colonne('BookingCompetitions', 'BcIanseoUrl', "VARCHAR(255) NULL AFTER BcShowMandate");

    // v14: official ianseo documents offered to the archers (the organiser opts in; served
    // through a limited relay that generates the official PDF). 0 = hidden (default).
    bk_colonne('BookingCompetitions', 'BcShowProgram',      "TINYINT NOT NULL DEFAULT 0 AFTER BcIanseoUrl");
    bk_colonne('BookingCompetitions', 'BcShowParticipants', "TINYINT NOT NULL DEFAULT 0 AFTER BcShowProgram");
    bk_colonne('BookingCompetitions', 'BcShowResults',      "TINYINT NOT NULL DEFAULT 0 AFTER BcShowParticipants");

    // Bib: the archer prints their bib (Qualification badge) from the Documents page. Opt-in
    // like the other documents, but served PER ARCHER (each archer prints only their bib and
    // those they registered). ON by default at level 2.
    bk_colonne('BookingCompetitions', 'BcShowDossard',      "TINYINT NOT NULL DEFAULT 0 AFTER BcShowResults");

    // v15: publication level (3-level bar — ergonomic redesign).
    // 1 = no publication (private to the organiser); 2 = simple publication (all automatic);
    // 3 = advanced (everything adjustable). Default 1 (nothing published without an explicit
    // action). BcAdvancedBackup: JSON snapshot of the advanced settings, kept while the
    // competition is at level 2, restored on going back to level 3 ("keep but hide" the
    // advanced settings). The columns stay the EFFECTIVE settings.
    bk_colonne('BookingCompetitions', 'BcPublishLevel',  "TINYINT NOT NULL DEFAULT 1 AFTER BcShowResults");
    bk_colonne('BookingCompetitions', 'BcAdvancedBackup', "LONGTEXT NULL AFTER BcPublishLevel");
    // Migration: competitions already OPEN before the 3-level bar had been set up by hand →
    // level 3 (advanced). Idempotent (no row matches any more once migrated: level 2 sets
    // BcPublishLevel=2, level 1 BcOpen=0).
    safe_w_sql("UPDATE BookingCompetitions SET BcPublishLevel = 3 WHERE BcOpen = 1 AND BcPublishLevel = 1");

    // v16: stable anchor for re-imports. ianseo re-imports an existing competition (same
    // ToCode) by DELETING the old tournament and creating a new one with a DIFFERENT ToId
    // (Common/Fun_TourDelete.php) → every BK_ table (tied to the ToId) is orphaned and the
    // online registrations disappear. The ToCode (stable from one version to the next, like
    // PRONO_Config.PaCfTourCode) is therefore kept to reconnect the data to the new
    // competition. See lib/adopt.php. Filled while the competition is alive; the orphan of a
    // re-import made BEFORE v16 (original Tournament deleted) has no anchor and stays manual —
    // no consequence, the logic looks forward.
    $newCode = bk_colonne('BookingCompetitions', 'BcCode', "VARCHAR(20) NOT NULL DEFAULT '' AFTER BcTournament");
    safe_w_sql("UPDATE BookingCompetitions
        INNER JOIN Tournament ON ToId = BcTournament
        SET BcCode = ToCode
        WHERE BcCode = ''");
    if ($newCode) safe_w_sql("ALTER TABLE BookingCompetitions ADD KEY BcCodeIdx (BcCode)");

    // v17: coordinates of the competition for the MAP (geocoded once from the town ToVenue
    // through the Base Adresse Nationale, then cached). BcGeoSrc = the geocoded value (town) →
    // geocoded again when it changes. NULL = not geocoded yet.
    bk_colonne('BookingCompetitions', 'BcLat',    "DECIMAL(9,6) NULL AFTER BcAdvancedBackup");
    bk_colonne('BookingCompetitions', 'BcLng',    "DECIMAL(9,6) NULL AFTER BcLat");
    bk_colonne('BookingCompetitions', 'BcGeoSrc', "VARCHAR(160) NULL AFTER BcLng");

    // v20: satisfaction survey offered to the archers after the competition. On by
    // default (existing competitions included); forced on at level 2; only a level-3
    // organiser can switch it off. See lib/survey.php.
    bk_colonne('BookingCompetitions', 'BcSurvey', "TINYINT NOT NULL DEFAULT 1 AFTER BcShowDossard");
    // v22: waiting list offered when a departure is full (lib/waitlist.php). Same rule as
    // the survey: always on at level 2, a checkbox at level 3.
    bk_colonne('BookingCompetitions', 'BcWaitlist', "TINYINT NOT NULL DEFAULT 1 AFTER BcSurvey");

    // A registration = one ianseo Entries row + this tracking row (who registered, when, with
    // which special requests). Entries has no notion of who made a registration.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingRegistrations (
        BrId         INT AUTO_INCREMENT PRIMARY KEY,
        BrEnId       INT NOT NULL,
        BrTournament INT NOT NULL,
        BrArcher     INT NOT NULL DEFAULT 0,
        BrLicence    VARCHAR(25) NOT NULL DEFAULT '',
        BrByRole     VARCHAR(8)  NOT NULL DEFAULT 'SELF',
        BrBy         VARCHAR(64) NOT NULL DEFAULT '',
        BrRequest    TEXT NULL,
        BrCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY BrEnIdx (BrEnId),
        KEY BrTourIdx (BrTournament),
        KEY BrArcherIdx (BrArcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v3: STRUCTURED wishes, used by the automatic placement (BrRequest stays the free
    // comment, read by the organiser only).
    bk_colonne('BookingRegistrations', 'BrWantLetter', "VARCHAR(2)  NOT NULL DEFAULT '' AFTER BrRequest");
    bk_colonne('BookingRegistrations', 'BrWantWith',   "VARCHAR(25) NOT NULL DEFAULT '' AFTER BrWantLetter");

    // v10: manual validation. BrValidated=1 by default (automatic, as before); in manual mode
    // the new registration arrives at 0 and is NOT placed until the organiser validates it.
    bk_colonne('BookingRegistrations', 'BrValidated', "TINYINT NOT NULL DEFAULT 1 AFTER BrWantWith");

    // v16: snapshot of the registration, to INJECT it AGAIN into a new version of the
    // competition after a re-import (the original Entry is deleted and created again with
    // another EnId — see lib/adopt.php). Filled at registration (bk_register); filled back here
    // from the Entries/Qualifications still there for the registrations already stored.
    $newSnap = bk_colonne('BookingRegistrations', 'BrDivision', "VARCHAR(8) NOT NULL DEFAULT '' AFTER BrValidated");
    bk_colonne('BookingRegistrations', 'BrClass',   "VARCHAR(8) NOT NULL DEFAULT '' AFTER BrDivision");
    bk_colonne('BookingRegistrations', 'BrSession', "SMALLINT NOT NULL DEFAULT 0 AFTER BrClass");
    bk_colonne('BookingRegistrations', 'BrFace',    "INT NOT NULL DEFAULT 0 AFTER BrSession");
    if ($newSnap) {
        safe_w_sql("UPDATE BookingRegistrations
            INNER JOIN Entries ON EnId = BrEnId
            INNER JOIN Qualifications ON QuId = EnId
            SET BrDivision = EnDivision,
                BrClass    = EnClass,
                BrFace     = EnTargetFace,
                BrSession  = QuSession
            WHERE BrDivision = ''");
    }

    // Technical possibilities of the field, target by target and departure by departure.
    // A target WITHOUT a row here is not constrained (everything allowed): the default
    // behaviour, which lets a competition never set up behave exactly as before this table.
    // BtFaces: list of TargetFaces.TfId separated by commas. Empty = all.
    // Distances: a RANGE per target (min..max) plus a default distance, in metres — a target
    // moves between two physical bounds on the field. 0 = not set, so no constraint.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingTargetCaps (
        BtTournament INT NOT NULL,
        BtSession    SMALLINT NOT NULL,
        BtTarget     SMALLINT NOT NULL,
        BtDistances  VARCHAR(120) NOT NULL DEFAULT '',
        BtFaces      VARCHAR(120) NOT NULL DEFAULT '',
        BtUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (BtTournament, BtSession, BtTarget)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v3: the range replaces the list of distances (BtDistances becomes unused but stays —
    // dropping it would lose the settings of an installation that has not replayed the data
    // migration yet).
    bk_colonne('BookingTargetCaps', 'BtDistDef', "SMALLINT NOT NULL DEFAULT 0 AFTER BtDistances");
    bk_colonne('BookingTargetCaps', 'BtDistMin', "SMALLINT NOT NULL DEFAULT 0 AFTER BtDistDef");
    $bkNewMax = bk_colonne('BookingTargetCaps', 'BtDistMax', "SMALLINT NOT NULL DEFAULT 0 AFTER BtDistMin");
    if ($bkNewMax) {
        // Old lists taken over: the range becomes [min, max] of the declared values, the
        // default the smallest. Gated on the creation of the column → runs only once for the
        // whole installation.
        safe_w_sql("UPDATE BookingTargetCaps SET
            BtDistMin = CAST(SUBSTRING_INDEX(BtDistances, ',', 1) AS UNSIGNED),
            BtDistMax = CAST(SUBSTRING_INDEX(BtDistances, ',', -1) AS UNSIGNED),
            BtDistDef = CAST(SUBSTRING_INDEX(BtDistances, ',', 1) AS UNSIGNED)
            WHERE BtDistances <> ''");
    }

    // Standalone fallback when AUTH is absent: who may register for which club.
    // With AUTH, the scope comes from the session (AUTH_ROLE/AUTH_SCOPE).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingClubManagers (
        BmId      INT AUTO_INCREMENT PRIMARY KEY,
        BmArcher  INT NOT NULL,
        BmClub    VARCHAR(16) NOT NULL,
        BmCreated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY BmPairIdx (BmArcher, BmClub)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v5 — Shop. Items grouped in free sections (Refreshments, Souvenirs…). Empty SiOptionName
    // = simple item; otherwise variants in BookingShopVariants (own stock per variant). Stock 0 =
    // unlimited; SiMaxPerPerson 0 = unlimited.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingShopItems (
        SiId           INT AUTO_INCREMENT PRIMARY KEY,
        SiTournament   INT NOT NULL,
        SiSection      VARCHAR(60)  NOT NULL DEFAULT '',
        SiLabel        VARCHAR(120) NOT NULL DEFAULT '',
        SiDescription  VARCHAR(255) NOT NULL DEFAULT '',
        SiPrice        DECIMAL(7,2) NOT NULL DEFAULT 0,
        SiStock        INT NOT NULL DEFAULT 0,
        SiMaxPerPerson INT NOT NULL DEFAULT 0,
        SiOptionName   VARCHAR(40)  NOT NULL DEFAULT '',
        SiOrder        SMALLINT NOT NULL DEFAULT 0,
        SiActive       TINYINT  NOT NULL DEFAULT 1,
        SiUpdated      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY SiTourIdx (SiTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Variants of an item (size, menu…), each with its stock. SvStock 0 = unlimited.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingShopVariants (
        SvId    INT AUTO_INCREMENT PRIMARY KEY,
        SvItem  INT NOT NULL,
        SvLabel VARCHAR(80) NOT NULL DEFAULT '',
        SvStock INT NOT NULL DEFAULT 0,
        SvOrder SMALLINT NOT NULL DEFAULT 0,
        KEY SvItemIdx (SvItem)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Shop orders: quantity per (competition, licence, item, variant). SoVariant = 0 for an
    // item without variants. Editable while the shop is open.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingShopOrders (
        SoId         INT AUTO_INCREMENT PRIMARY KEY,
        SoTournament INT NOT NULL,
        SoLicence    VARCHAR(25) NOT NULL DEFAULT '',
        SoItem       INT NOT NULL,
        SoVariant    INT NOT NULL DEFAULT 0,
        SoQty        INT NOT NULL DEFAULT 0,
        SoUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY SoUnique (SoTournament, SoLicence, SoItem, SoVariant),
        KEY SoTourIdx (SoTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v7 — payment of an archer on a competition, one row per (competition, licence). Since
    // v25 it only holds the payment choice the archer declared (PyDecl*): what was paid lives
    // in the journal BookingLedger, and the former "paid" tick (PyPaid) is taken over there.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingPayments (
        PyId         INT AUTO_INCREMENT PRIMARY KEY,
        PyTournament INT NOT NULL,
        PyLicence    VARCHAR(25) NOT NULL DEFAULT '',
        PyPaid       TINYINT NOT NULL DEFAULT 0,
        PyMethod     VARCHAR(16) NOT NULL DEFAULT '',
        PyPaidAt     DATETIME NULL,
        PyBy         VARCHAR(64) NOT NULL DEFAULT '',
        PyUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY PyUnique (PyTournament, PyLicence),
        KEY PyTourIdx (PyTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v9: the competitor's declaration at registration — means wished + when (before/onsite).
    // Informs the organiser; separate from the PyMethod they validate.
    bk_colonne('BookingPayments', 'PyDeclMethod', "VARCHAR(16) NOT NULL DEFAULT '' AFTER PyMethod");
    bk_colonne('BookingPayments', 'PyDeclWhen', "VARCHAR(8) NOT NULL DEFAULT '' AFTER PyDeclMethod");

    // v16: inconsistencies found during a competition re-import, to be settled by the
    // organiser on a dedicated page (lib/adopt.php records them, admin/reimport.php settles
    // them). RcKind:
    //   'category'  same licence + same departure, but a DIFFERENT category (bow/class)
    //               between the booking registration and the import → the import is kept by
    //               default, the organiser confirms;
    //   'reinject'  booking registration missing from the import, injection impossible
    //               (licence unknown to the federation file, departure gone…) → to handle;
    //   'imported'  participant in the import but unknown to booking (entered outside the
    //               module) → made visible in their space, WITHOUT payment information.
    // RcBooking / RcImport: JSON snapshot of each version for display.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingReimportConflicts (
        RcId         INT AUTO_INCREMENT PRIMARY KEY,
        RcTournament INT NOT NULL,
        RcCode       VARCHAR(20) NOT NULL DEFAULT '',
        RcLicence    VARCHAR(25) NOT NULL DEFAULT '',
        RcName       VARCHAR(120) NOT NULL DEFAULT '',
        RcKind       VARCHAR(16) NOT NULL DEFAULT '',
        RcEnId       INT NOT NULL DEFAULT 0,
        RcBooking    LONGTEXT NULL,
        RcImport     LONGTEXT NULL,
        RcResolved   TINYINT NOT NULL DEFAULT 0,
        RcCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY RcTourIdx (RcTournament),
        KEY RcOpenIdx (RcTournament, RcResolved)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v20: one row per answer to the satisfaction survey. Ratings 1-5 (NULL = not
    // answered: nothing is mandatory), three free texts. BqLicence identifies the
    // archer only while the survey is open (no double answers, answers can be
    // edited); it is then replaced by "#<BqId>" every night (aut_log_purge).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingSurveys (
        BqId          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        BqTournament  INT UNSIGNED NOT NULL,
        BqLicence     VARCHAR(25)  NOT NULL,
        BqWelcome     TINYINT NULL,
        BqAccess      TINYINT NULL,
        BqBar         TINYINT NULL,
        BqBarValue    TINYINT NULL,
        BqVenue       TINYINT NULL,
        BqFacilities  TINYINT NULL,
        BqOrgComment  TEXT NULL,
        BqOrgIdeas    TEXT NULL,
        BqDuration    TINYINT NULL,
        BqSchedule    TINYINT NULL,
        BqResults     TINYINT NULL,
        BqAnimation   TINYINT NULL,
        BqCompComment TEXT NULL,
        BqCreated     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BqUpdated     DATETIME NULL,
        UNIQUE KEY BqTourLicence (BqTournament, BqLicence)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v21: who has answered, kept APART from the answers (an electoral roll next to the
    // ballot box). Never detached: it is what guarantees ONE answer per archer and per
    // competition for good — even once the answers are anonymised, if the dates are
    // changed and the window reopens, or after a re-import. Deliberately no timestamp
    // and no auto-increment: nothing (order, time) can link a row here to an answer.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingSurveyVoters (
        BvTournament INT UNSIGNED NOT NULL,
        BvLicence    VARCHAR(25)  NOT NULL,
        PRIMARY KEY (BvTournament, BvLicence)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Answers given before this table existed (idempotent: IGNORE on the primary key).
    safe_w_sql("INSERT IGNORE INTO BookingSurveyVoters (BvTournament, BvLicence)
        SELECT BqTournament, BqLicence FROM BookingSurveys WHERE BqLicence NOT LIKE '#%'");

    // v22: waiting list. A departure full for an archer's profile (weapon, category,
    // target face): the archer queues, and the first compatible one is registered
    // automatically when a place frees (lib/waitlist.php). Kept apart from Entries on
    // purpose: a queued archer must not appear in the organiser's lists and prints.
    // BwStatus: 0 waiting, 1 registered (BwEnId), 2 closed (can no longer succeed, BwNote).
    // BwSession 0 = any of the departures. BwSeen: the archer has seen the notice.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingWaitlist (
        BwId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        BwTournament INT UNSIGNED NOT NULL,
        BwLicence    VARCHAR(25)  NOT NULL,
        BwArcher     INT NOT NULL DEFAULT 0,
        BwByRole     VARCHAR(8)   NOT NULL DEFAULT 'SELF',
        BwBy         VARCHAR(64)  NOT NULL DEFAULT '',
        BwDivision   VARCHAR(8)   NOT NULL DEFAULT '',
        BwClass      VARCHAR(8)   NOT NULL DEFAULT '',
        BwFace       INT NOT NULL DEFAULT 0,
        BwSession    SMALLINT NOT NULL DEFAULT 0,
        BwWantLetter VARCHAR(2)   NOT NULL DEFAULT '',
        BwWantWith   VARCHAR(25)  NOT NULL DEFAULT '',
        BwRequest    TEXT NULL,
        BwPayChoice  VARCHAR(32)  NOT NULL DEFAULT '',
        BwCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BwStatus     TINYINT NOT NULL DEFAULT 0,
        BwEnId       INT UNSIGNED NOT NULL DEFAULT 0,
        BwDone       DATETIME NULL,
        BwNote       VARCHAR(120) NOT NULL DEFAULT '',
        BwSeen       TINYINT NOT NULL DEFAULT 0,
        KEY BwQueueIdx (BwTournament, BwStatus, BwId),
        KEY BwLicenceIdx (BwLicence),
        KEY BwArcherIdx (BwArcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // v23: payment choice made on the registration form ("method|when"), declared when
    // the archer is registered from the list.
    bk_colonne('BookingWaitlist', 'BwPayChoice', "VARCHAR(32) NOT NULL DEFAULT '' AFTER BwRequest");

    // v24: refunds the organiser owes, when a registration already paid is removed by the
    // server (anonymisation of a licensee, AUTH anonymise-lib.php). No name and no licence on
    // purpose — the person has just been anonymised: the club and the amount are what the
    // organiser needs to find the payment. BfDone: the organiser has refunded.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingRefunds (
        BfId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        BfTournament INT UNSIGNED NOT NULL,
        BfClubCode   VARCHAR(16)  NOT NULL DEFAULT '',
        BfClubName   VARCHAR(80)  NOT NULL DEFAULT '',
        BfAmount     DECIMAL(8,2) NOT NULL DEFAULT 0,
        BfMethod     VARCHAR(16)  NOT NULL DEFAULT '',
        BfReason     VARCHAR(16)  NOT NULL DEFAULT 'ANONYMISE',
        BfCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BfDone       TINYINT NOT NULL DEFAULT 0,
        BfDoneAt     DATETIME NULL,
        BfDoneBy     VARCHAR(64)  NOT NULL DEFAULT '',
        KEY BfTourIdx (BfTournament, BfDone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v25: payment journal (lib/payment.php). One row per movement on an account — a
    // competition and a licence ('#<EnId>' for a participant without licence). BlgAmount
    // is SIGNED, its effect on what has been paid: payment > 0, refund < 0, cancel =
    // minus the line it cancels (BlgCancels; the cancelled line gets BlgCancelled). Lines
    // are never deleted nor edited: a mistake is cancelled, the history stays whole.
    // BlgGroup ties the lines of one club payment. BlgWhen: date of the payment as entered;
    // BlgCreated: when it was recorded.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingLedger (
        BlgId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        BlgTournament INT UNSIGNED NOT NULL,
        BlgAccount    VARCHAR(25)  NOT NULL,
        BlgKind       VARCHAR(12)  NOT NULL DEFAULT 'payment',
        BlgAmount     DECIMAL(9,2) NOT NULL DEFAULT 0,
        BlgMethod     VARCHAR(16)  NOT NULL DEFAULT '',
        BlgLabel      VARCHAR(160) NOT NULL DEFAULT '',
        BlgGroup      INT UNSIGNED NOT NULL DEFAULT 0,
        BlgCancels    INT UNSIGNED NOT NULL DEFAULT 0,
        BlgCancelled  INT UNSIGNED NOT NULL DEFAULT 0,
        BlgWhen       DATETIME NULL,
        BlgCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BlgBy         VARCHAR(64)  NOT NULL DEFAULT '',
        KEY BlgAccountIdx (BlgTournament, BlgAccount)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // The former "paid" tick (PyPaid) becomes a journal line (bk_ledger_migrate, which needs
    // the pricing engine, hence not here); PyLedger marks the ticks already taken over.
    bk_colonne('BookingPayments', 'PyLedger', "TINYINT NOT NULL DEFAULT 0 AFTER PyBy");

    // v26 — a CLOSED competition (level 1) may still use the payments and the shop: imports
    // from ianseo only, with tariffs and an account per participant. Existing closed ones that
    // already have a shop or payments get it on, so that nothing they hold disappears from the
    // menus (once: only when the column is new).
    if (bk_colonne('BookingCompetitions', 'BcPayments', "TINYINT NOT NULL DEFAULT 0 AFTER BcPublishLevel")) {
        safe_w_sql("UPDATE BookingCompetitions SET BcPayments = 1 WHERE BcPublishLevel = 1 AND (
              BcTournament IN (SELECT SiTournament FROM BookingShopItems)
           OR BcTournament IN (SELECT BlgTournament FROM BookingLedger)
           OR BcTournament IN (SELECT PyTournament FROM BookingPayments WHERE PyPaid = 1))");
    }

    // v28 — the points of sale (AUTH/shop) write into the same journal, so that an archer has one
    // account per competition (registrations + refreshments + shop). BlgOrder/BlgStand/BlgStaff:
    // the shop order, stand and volunteer of a line. BlgProvider/BlgRef/BlgStatus: ready for an
    // online payment provider (a reference only, never card data; 'pending' until confirmed).
    // BlgIdem: idempotency key of a request sent from a phone. BlgBenef: the structure that
    // receives the money (owner of the competition).
    bk_colonne('BookingLedger', 'BlgOrder',    "INT UNSIGNED NOT NULL DEFAULT 0 AFTER BlgCancelled");
    bk_colonne('BookingLedger', 'BlgStand',    "INT UNSIGNED NOT NULL DEFAULT 0 AFTER BlgOrder");
    bk_colonne('BookingLedger', 'BlgStaff',    "INT UNSIGNED NOT NULL DEFAULT 0 AFTER BlgStand");
    bk_colonne('BookingLedger', 'BlgProvider', "VARCHAR(12) NOT NULL DEFAULT 'manual' AFTER BlgStaff");
    bk_colonne('BookingLedger', 'BlgRef',      "VARCHAR(80) NOT NULL DEFAULT '' AFTER BlgProvider");
    bk_colonne('BookingLedger', 'BlgStatus',   "VARCHAR(10) NOT NULL DEFAULT 'done' AFTER BlgRef");
    bk_colonne('BookingLedger', 'BlgIdem',     "CHAR(36) NULL AFTER BlgStatus");
    bk_colonne('BookingLedger', 'BlgBenef',    "VARCHAR(16) NOT NULL DEFAULT '' AFTER BlgIdem");
    // Indexes checked on their own, not with the column: a failure between the two would
    // otherwise leave the column without its index for good. Several NULL keys are allowed by a
    // UNIQUE index: lines written without a key do not collide.
    bk_index('BookingLedger', 'BlgIdemIdx', 'UNIQUE KEY BlgIdemIdx (BlgTournament, BlgIdem)');
    bk_index('BookingLedger', 'BlgOrderIdx', 'KEY BlgOrderIdx (BlgOrder)');

    // v29 — opening of each departure (ianseo Session) to the registration, at level 3
    // (lib/sessionrules.php). BdState: 1 open, 0 closed, 2 opens once the earlier departures
    // are full. BdOpenFrom / BdOpenTo replace, each on its own, the general period for this
    // departure; NULL = the general one. No row = open over the general period.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BookingSessionRules (
        BdTournament INT UNSIGNED NOT NULL,
        BdSession    TINYINT UNSIGNED NOT NULL,
        BdState      TINYINT NOT NULL DEFAULT 1,
        BdOpenFrom   DATETIME NULL,
        BdOpenTo     DATETIME NULL,
        BdUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (BdTournament, BdSession)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // v29 — at most one registration per archer on the competition, whatever the departure.
    bk_colonne('BookingCompetitions', 'BcSingleReg', "TINYINT NOT NULL DEFAULT 0 AFTER BcWaitlist");

    $_SESSION[$flag] = true;
}
