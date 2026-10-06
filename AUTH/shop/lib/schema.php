<?php
/**
 * lib/schema.php — creation and migration of the Shop* tables (points of sale of a competition:
 * refreshment bar, food, shop).
 *
 * Collation: utf8mb4_unicode_ci, like the Booking* tables. Any join between a VARCHAR column of
 * here and a VARCHAR column of an ianseo core table carries bk_coll() on the Shop side.
 *
 * Migration rule (lesson of REPARTITION_EPREUVES): a shp_colonne($table, …) is ALWAYS placed
 * AFTER the CREATE TABLE IF NOT EXISTS of $table — otherwise the ALTER fails on a new
 * installation and stops the whole function.
 *
 * Time stamps: the MySQL connection is in UTC on the public pages and in the competition's zone
 * on the organiser pages (Common/Globals.inc.php). So nothing here relies on
 * DEFAULT CURRENT_TIMESTAMP: TECHNICAL stamps (sessions, invitations, expiries) are written and
 * compared with UTC_TIMESTAMP(); BUSINESS stamps shown to people (orders, stock moves) are the
 * local time of the competition (bk_local_now_sql()).
 */

if (defined('SHP_SCHEMA_LOADED')) return;
define('SHP_SCHEMA_LOADED', true);

if (!defined('SHP_SCHEMA_VERSION')) define('SHP_SCHEMA_VERSION', 5);

// The shop shares the payment journal and the licensee accounts of the online registration
// (same module): booking's schema, clock and texts come first.
require_once dirname(__DIR__, 2) . '/booking/lib/schema.php';
require_once __DIR__ . '/lang.php';

/** Adds a column when missing; never fatal (see bk_colonne). True when it was just added. */
function shp_colonne($table, $colonne, $definition)
{
    return bk_colonne($table, $colonne, $definition);
}

/** Creates the tables when needed. Idempotent, guarded by a session flag. */
function shp_schema()
{
    static $busy = false;
    bk_schema();   // table names (aut_table_names), booking tables, extended payment journal
    $flag = '_shp_schema_v' . SHP_SCHEMA_VERSION;
    // $busy: the move of the former shop (v4) calls functions that call shp_schema() again.
    if (!empty($_SESSION[$flag]) || $busy) return;
    $busy = true;

    $opt = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Settings of a competition. SgPublicKey: random key of the public addresses (QR codes),
    // so that shops cannot be found by counting competition ids; the organiser can change it.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopSettings (
        SgTournament    INT UNSIGNED NOT NULL PRIMARY KEY,
        SgEnabled       TINYINT      NOT NULL DEFAULT 0,
        SgPublicKey     CHAR(10)     NOT NULL,
        SgGuests        TINYINT      NOT NULL DEFAULT 1,
        SgTab           TINYINT      NOT NULL DEFAULT 0,
        SgPreorderUntil DATETIME     NULL,
        SgTrustGate     TINYINT      NOT NULL DEFAULT 1,
        SgGuestMaxOpen  TINYINT      NOT NULL DEFAULT 2,
        SgNotice        VARCHAR(255) NOT NULL DEFAULT '',
        SgCreated       DATETIME     NULL,
        SgUpdated       DATETIME     NULL,
        UNIQUE KEY SgKeyIdx (SgPublicKey)
    )$opt");

    // Points of sale. SdKind: bar, food, shop. SdMode: direct (handed over at once) or prep
    // (preparation queue). SdPayWhen: order (paid before preparation) or pickup. SdOpen: open
    // to the customers' phone orders right now. SdNextNo: counter of the order numbers.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopStands (
        SdId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SdTournament INT UNSIGNED NOT NULL,
        SdKind       VARCHAR(8)   NOT NULL DEFAULT 'bar',
        SdName       VARCHAR(60)  NOT NULL DEFAULT '',
        SdMode       VARCHAR(8)   NOT NULL DEFAULT 'direct',
        SdPayWhen    VARCHAR(8)   NOT NULL DEFAULT 'order',
        SdOnline     TINYINT      NOT NULL DEFAULT 1,
        SdOpen       TINYINT      NOT NULL DEFAULT 0,
        SdPrepMin    SMALLINT     NOT NULL DEFAULT 3,
        SdParallel   TINYINT      NOT NULL DEFAULT 1,
        SdPrefix     CHAR(1)      NOT NULL DEFAULT 'A',
        SdNextNo     INT UNSIGNED NOT NULL DEFAULT 0,
        SdOrder      SMALLINT     NOT NULL DEFAULT 0,
        SdActive     TINYINT      NOT NULL DEFAULT 1,
        KEY SdTourIdx (SdTournament)
    )$opt");

    // Catalogue. SpStock is the REMAINING quantity (NULL = unlimited); every change of it goes
    // through ShopStockMoves. A product with an option (SpOptionName) keeps its stock on its
    // variants. SpAvailable: the volunteers' quick "sold out" switch.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopProducts (
        SpId          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SpTournament  INT UNSIGNED NOT NULL,
        SpStand       INT UNSIGNED NOT NULL,
        SpCategory    VARCHAR(60)  NOT NULL DEFAULT '',
        SpName        VARCHAR(120) NOT NULL DEFAULT '',
        SpDescription VARCHAR(255) NOT NULL DEFAULT '',
        SpPrice       DECIMAL(8,2) NOT NULL DEFAULT 0,
        SpStock       INT          NULL,
        SpStockAlert  INT          NULL,
        SpMaxPer      SMALLINT     NOT NULL DEFAULT 0,
        SpOptionName  VARCHAR(40)  NOT NULL DEFAULT '',
        SpPreorder    TINYINT      NOT NULL DEFAULT 0,
        SpOnsite      TINYINT      NOT NULL DEFAULT 1,
        SpAvailable   TINYINT      NOT NULL DEFAULT 1,
        SpOrder       SMALLINT     NOT NULL DEFAULT 0,
        SpActive      TINYINT      NOT NULL DEFAULT 1,
        SpUpdated     DATETIME     NULL,
        KEY SpStandIdx (SpTournament, SpStand)
    )$opt");

    // Variants of a product (size, filling…). SwPrice NULL = the product's price.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopVariants (
        SwId        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SwProduct   INT UNSIGNED NOT NULL,
        SwLabel     VARCHAR(80)  NOT NULL DEFAULT '',
        SwPrice     DECIMAL(8,2) NULL,
        SwStock     INT          NULL,
        SwAvailable TINYINT      NOT NULL DEFAULT 1,
        SwOrder     SMALLINT     NOT NULL DEFAULT 0,
        KEY SwProductIdx (SwProduct)
    )$opt");

    // Orders. The order status (ShStatus) and the payment state (ShPayState) are two separate
    // axes. ShPaid is a cache of the journal (BookingLedger), which stays the truth. ShIdem:
    // idempotency key sent by the phone, so that a double tap or a resent request never creates
    // two orders. Business stamps in the competition's local time.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopOrders (
        ShId          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ShTournament  INT UNSIGNED  NOT NULL,
        ShStand       INT UNSIGNED  NOT NULL,
        ShSeq         INT UNSIGNED  NOT NULL DEFAULT 0,
        ShNumber      VARCHAR(8)    NOT NULL DEFAULT '',
        ShCustKind    VARCHAR(8)    NOT NULL DEFAULT 'COUNTER',
        ShLicence     VARCHAR(25)   NOT NULL DEFAULT '',
        ShGuest       INT UNSIGNED  NOT NULL DEFAULT 0,
        ShCustLabel   VARCHAR(60)   NOT NULL DEFAULT '',
        ShChannel     VARCHAR(8)    NOT NULL DEFAULT 'counter',
        ShStatus      VARCHAR(10)   NOT NULL DEFAULT 'placed',
        ShPayMode     VARCHAR(8)    NOT NULL DEFAULT 'now',
        ShPayState    VARCHAR(8)    NOT NULL DEFAULT 'unpaid',
        ShTotal       DECIMAL(9,2)  NOT NULL DEFAULT 0,
        ShPaid        DECIMAL(9,2)  NOT NULL DEFAULT 0,
        ShIdem        CHAR(36)      NOT NULL,
        ShNote        VARCHAR(160)  NOT NULL DEFAULT '',
        ShCreated     DATETIME      NULL,
        ShStartedAt   DATETIME      NULL,
        ShReadyAt     DATETIME      NULL,
        ShDeliveredAt DATETIME      NULL,
        ShCancelledAt DATETIME      NULL,
        ShByStaff     INT UNSIGNED  NOT NULL DEFAULT 0,
        ShPrepStaff   INT UNSIGNED  NOT NULL DEFAULT 0,
        ShDelivStaff  INT UNSIGNED  NOT NULL DEFAULT 0,
        ShSeen        TINYINT       NOT NULL DEFAULT 0,
        UNIQUE KEY ShIdemIdx (ShTournament, ShIdem),
        KEY ShQueueIdx (ShTournament, ShStand, ShStatus),
        KEY ShLicenceIdx (ShTournament, ShLicence),
        KEY ShGuestIdx (ShTournament, ShGuest)
    )$opt");

    // Lines of an order. Label and price are COPIED: changing the catalogue never rewrites a past
    // order. SnCancelled: quantity cancelled afterwards.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopOrderLines (
        SnId        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SnOrder     INT UNSIGNED NOT NULL,
        SnProduct   INT UNSIGNED NOT NULL,
        SnVariant   INT UNSIGNED NOT NULL DEFAULT 0,
        SnLabel     VARCHAR(160) NOT NULL DEFAULT '',
        SnUnit      DECIMAL(8,2) NOT NULL DEFAULT 0,
        SnQty       SMALLINT     NOT NULL DEFAULT 0,
        SnCancelled SMALLINT     NOT NULL DEFAULT 0,
        KEY SnOrderIdx (SnOrder),
        KEY SnProductIdx (SnProduct)
    )$opt");

    // Stock movements of the products with a limited stock. SmReason: order, cancel, restock,
    // adjust, loss. SmDelta signed.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopStockMoves (
        SmId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SmTournament INT UNSIGNED NOT NULL,
        SmProduct    INT UNSIGNED NOT NULL,
        SmVariant    INT UNSIGNED NOT NULL DEFAULT 0,
        SmDelta      INT          NOT NULL DEFAULT 0,
        SmReason     VARCHAR(10)  NOT NULL DEFAULT 'adjust',
        SmOrder      INT UNSIGNED NOT NULL DEFAULT 0,
        SmStaff      INT UNSIGNED NOT NULL DEFAULT 0,
        SmWhen       DATETIME     NULL,
        KEY SmProductIdx (SmTournament, SmProduct)
    )$opt");

    // Volunteers of a competition. SfKind: LICENSEE (licensee account), LOCAL (not licensed,
    // name + password, erased the day after the competition), ORGANISER (an organiser holding
    // the till). SfStatus: pending, active, locked, revoked, ended, purged. SfCode: check code
    // shown on both screens while the organiser approves. Rights are per stand
    // (ShopStaffStands); the refund ceilings are per volunteer. Technical stamps in UTC.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopStaff (
        SfId          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SfTournament  INT UNSIGNED NOT NULL,
        SfKind        VARCHAR(10)  NOT NULL DEFAULT 'LOCAL',
        SfArcher      INT UNSIGNED NOT NULL DEFAULT 0,
        SfLicence     VARCHAR(25)  NOT NULL DEFAULT '',
        SfAuthUser    VARCHAR(64)  NOT NULL DEFAULT '',
        SfFamilyName  VARCHAR(60)  NOT NULL DEFAULT '',
        SfGivenName   VARCHAR(60)  NOT NULL DEFAULT '',
        SfPassword    VARCHAR(255) NOT NULL DEFAULT '',
        SfStatus      VARCHAR(8)   NOT NULL DEFAULT 'pending',
        SfCode        CHAR(4)      NOT NULL DEFAULT '',
        SfRefundMax   DECIMAL(8,2) NOT NULL DEFAULT 0,
        SfRefundTotal DECIMAL(8,2) NOT NULL DEFAULT 0,
        SfFails       TINYINT      NOT NULL DEFAULT 0,
        SfApprovedBy  VARCHAR(64)  NOT NULL DEFAULT '',
        SfApprovedAt  DATETIME     NULL,
        SfCreated     DATETIME     NULL,
        SfLastSeen    DATETIME     NULL,
        KEY SfTourIdx (SfTournament, SfStatus),
        KEY SfArcherIdx (SfArcher)
    )$opt");

    // Rights of a volunteer, stand by stand: sell, prepare, cash, refund, stock, manage. No row =
    // no right on that stand. An ORGANISER volunteer has every right without rows.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopStaffStands (
        StStaff INT UNSIGNED NOT NULL,
        StStand INT UNSIGNED NOT NULL,
        StPerms VARCHAR(64)  NOT NULL DEFAULT '',
        PRIMARY KEY (StStaff, StStand)
    )$opt");

    // Volunteer sessions: only the HASH of the token is stored, the token itself lives in the
    // phone's cookie. (Prefix Sk: Ss is taken by a core table, SyncServers.)
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopStaffSessions (
        SkId        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SkStaff     INT UNSIGNED NOT NULL,
        SkTokenHash CHAR(64)     NOT NULL,
        SkCreated   DATETIME     NULL,
        SkLastSeen  DATETIME     NULL,
        SkIP        VARCHAR(45)  NOT NULL DEFAULT '',
        SkUA        VARCHAR(160) NOT NULL DEFAULT '',
        UNIQUE KEY SkTokenIdx (SkTokenHash),
        KEY SkStaffIdx (SkStaff)
    )$opt");

    // QR codes given by the organiser: 'enrol' (volunteers join, reusable while valid) and
    // 'reset' (new password of one volunteer, single use). Hashed token, UTC stamps.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopInvites (
        SqId        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SqTournament INT UNSIGNED NOT NULL,
        SqKind      VARCHAR(8)   NOT NULL DEFAULT 'enrol',
        SqTokenHash CHAR(64)     NOT NULL,
        SqStaff     INT UNSIGNED NOT NULL DEFAULT 0,
        SqCreatedBy VARCHAR(64)  NOT NULL DEFAULT '',
        SqCreated   DATETIME     NULL,
        SqExpires   DATETIME     NULL,
        SqUses      INT          NOT NULL DEFAULT 0,
        SqMaxUses   INT          NOT NULL DEFAULT 0,
        SqRevoked   TINYINT      NOT NULL DEFAULT 0,
        UNIQUE KEY SqTokenIdx (SqTokenHash),
        KEY SqTourIdx (SqTournament)
    )$opt");

    // Customers without an account: a nickname so that the volunteers can call them, tied to a
    // cookie of the phone (hashed token). Erased the day after the competition once their
    // account is settled.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopGuests (
        SuId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SuTournament INT UNSIGNED NOT NULL,
        SuTokenHash  CHAR(64)     NOT NULL,
        SuPseudo     VARCHAR(40)  NOT NULL DEFAULT '',
        SuCreated    DATETIME     NULL,
        SuLastSeen   DATETIME     NULL,
        SuBlocked    TINYINT      NOT NULL DEFAULT 0,
        UNIQUE KEY SuTokenIdx (SuTokenHash),
        KEY SuTourIdx (SuTournament)
    )$opt");

    // v2 — journal of the organisers' decisions on volunteers (approve, refuse, revoke, rights,
    // password reset, lock, unlock), with their author. SjStaff only: no name is copied here,
    // so erasing a volunteer the day after the competition leaves nothing personal behind.
    // UTC stamp (technical).
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopStaffLog (
        SjId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SjTournament INT UNSIGNED NOT NULL,
        SjStaff      INT UNSIGNED NOT NULL DEFAULT 0,
        SjEvent      VARCHAR(16)  NOT NULL DEFAULT '',
        SjBy         VARCHAR(64)  NOT NULL DEFAULT '',
        SjDetail     VARCHAR(160) NOT NULL DEFAULT '',
        SjWhen       DATETIME     NULL,
        KEY SjTourIdx (SjTournament, SjId)
    )$opt");

    // v3 — idempotency key of a stock change made from a phone (restock, loss): sent again after
    // a network cut, it is applied once. NULL for the changes made by sales and by the organiser.
    shp_colonne('ShopStockMoves', 'SmIdem', "CHAR(36) NULL AFTER SmStaff");
    bk_index('ShopStockMoves', 'SmIdemIdx', 'UNIQUE KEY SmIdemIdx (SmTournament, SmIdem)');

    // v4 — the former shop of the online registration moves here (lib/legacy.php, each competition
    // once, in one transaction): SgOldShop marks a competition already moved, ShLegacy the orders
    // that came from it. Index on the licence alone: the accounts of an archer across competitions
    // (bk_archer_accounts, anonymisation).
    shp_colonne('ShopSettings', 'SgOldShop', "TINYINT NOT NULL DEFAULT 0 AFTER SgNotice");
    shp_colonne('ShopOrders', 'ShLegacy', "TINYINT NOT NULL DEFAULT 0 AFTER ShSeen");
    bk_index('ShopOrders', 'ShLicIdx', 'KEY ShLicIdx (ShLicence)');
    require_once __DIR__ . '/legacy.php';
    shp_legacy_migrate();

    // v5 — times of an order, both in the competition's local time: ShWantedAt, when the customer
    // (or the volunteer at the counter) wants it served; ShReadyBy, when it should be ready, set
    // and adjusted by the volunteers (shown to the customer as the waiting time).
    shp_colonne('ShopOrders', 'ShWantedAt', "DATETIME NULL AFTER ShNote");
    // A visitor whose access was closed the day after the competition (the nickname stays).
    shp_colonne('ShopGuests', 'SuClosed', "TINYINT NOT NULL DEFAULT 0 AFTER SuBlocked");
    shp_colonne('ShopOrders', 'ShReadyBy', "DATETIME NULL AFTER ShWantedAt");

    // v5 — notifications on the customer's phone (Web Push): one row per subscription of a
    // browser, tied to the customer (licence or visitor) of one competition. Only what the push
    // service needs: the address it gave and the two public keys of the browser. Deleted the
    // day after the competition. SyHash: SHA-256 of the address, to find it again.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopPush (
        SyId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SyTournament INT UNSIGNED NOT NULL,
        SyGuest      INT UNSIGNED NOT NULL DEFAULT 0,
        SyLicence    VARCHAR(25)  NOT NULL DEFAULT '',
        SyHash       CHAR(64)     NOT NULL,
        SyEndpoint   VARCHAR(1000) NOT NULL DEFAULT '',
        SyP256dh     VARCHAR(120) NOT NULL DEFAULT '',
        SyAuth       VARCHAR(40)  NOT NULL DEFAULT '',
        SyLang       VARCHAR(5)   NOT NULL DEFAULT 'en',
        SyCreated    DATETIME     NULL,
        SyLastOk     DATETIME     NULL,
        SyFails      TINYINT      NOT NULL DEFAULT 0,
        UNIQUE KEY SyHashIdx (SyHash),
        KEY SyTourIdx (SyTournament, SyGuest, SyLicence)
    )$opt");

    // v5 — the server's own key pair for Web Push (VAPID), made once: one row, SzId = 1. The
    // private key never leaves the database; the public key is given to the browsers.
    safe_w_sql("CREATE TABLE IF NOT EXISTS ShopPushKeys (
        SzId       TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        SzPublic   VARCHAR(120) NOT NULL DEFAULT '',
        SzPrivate  TEXT         NULL,
        SzSubject  VARCHAR(200) NOT NULL DEFAULT '',
        SzCreated  DATETIME     NULL
    )$opt");

    $_SESSION[$flag] = true;
    $busy = false;
}
