<?php
/**
 * Table names of the module. Up to 1.1.17 they were AUT_* and BK_*; ianseo asks module tables
 * to follow the core convention (PascalCase, no underscore), so they became Auth* and
 * Booking*. Three tables also had a column prefix that a core table already uses: their
 * columns are renamed as well (AUT_Claim Ac* -> Cm*, AUT_ClubLogos Clg* -> Lg*,
 * BK_Sessions Bs* -> Bk*).
 *
 * aut_table_names() moves an installation to the new names in place. RENAME TABLE keeps the
 * rows, indexes and AUTO_INCREMENT counter, and is atomic for each table. A view is then left
 * under every old name, so code that still uses it keeps working during the transition: the
 * copies deployed in Modules/Authentication until the next deployment, or a rollback of the
 * code alone. These views are simple, so they accept writes as well as reads.
 *
 * It must run before anything touches a table of the module: it is the first call of the
 * per-request bootstrap and of every schema function. Each step tests the state first and
 * tolerates a concurrent request doing the same step, so a failure only leaves work for the
 * next request. This file does not use _shared/schema-lib.php on purpose: it runs on every
 * page of the site (Modules/Authentication), and must not depend on a file that another
 * update delivers.
 */

/**
 * Old name => new name, and for the three tables whose columns change, old column => [new
 * column, definition]. Definitions are those of the CREATE TABLE: CHANGE restates them.
 */
function aut_table_map()
{
    return array(
        'AUT_Users'            => array('AuthUsers'),
        'AUT_Share'            => array('AuthShare'),
        'AUT_ShareClub'        => array('AuthShareClub'),
        'AUT_Claim'            => array('AuthClaim', array(
            'AcCode'  => array('CmCode',  "VARCHAR(50) NOT NULL"),
            'AcRole'  => array('CmRole',  "VARCHAR(8) NOT NULL DEFAULT 'CLUB'"),
            'AcScope' => array('CmScope', "VARCHAR(16) NOT NULL DEFAULT ''"),
            'AcUser'  => array('CmUser',  "VARCHAR(64) NOT NULL DEFAULT ''"),
            'AcWhen'  => array('CmWhen',  "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"),
        )),
        'AUT_Log'              => array('AuthLog'),
        'AUT_Sessions'         => array('AuthSessions'),
        'AUT_Tickets'          => array('AuthTickets'),
        'AUT_Usage'            => array('AuthUsage'),
        'AUT_UsageSeen'        => array('AuthUsageSeen'),
        'AUT_ClubLogos'        => array('AuthClubLogos', array(
            'ClgCode'    => array('LgCode',    "VARCHAR(10) NOT NULL"),
            'ClgJpg'     => array('LgJpg',     "MEDIUMBLOB NULL"),
            'ClgHash'    => array('LgHash',    "CHAR(32) NOT NULL DEFAULT ''"),
            'ClgBytes'   => array('LgBytes',   "INT NOT NULL DEFAULT 0"),
            'ClgMissing' => array('LgMissing', "TINYINT NOT NULL DEFAULT 0"),
            'ClgFetched' => array('LgFetched', "DATETIME NULL"),
            'ClgTried'   => array('LgTried',   "DATETIME NULL"),
        )),
        'BK_Archers'           => array('BookingArchers'),
        'BK_Sessions'          => array('BookingSessions', array(
            'BsId'        => array('BkId',        "INT NOT NULL AUTO_INCREMENT"),
            'BsArcher'    => array('BkArcher',    "INT NOT NULL"),
            'BsTokenHash' => array('BkTokenHash', "CHAR(64) NOT NULL"),
            'BsCreated'   => array('BkCreated',   "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"),
            'BsLastSeen'  => array('BkLastSeen',  "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"),
            'BsIP'        => array('BkIP',        "VARCHAR(45) NOT NULL DEFAULT ''"),
            'BsUA'        => array('BkUA',        "VARCHAR(160) NOT NULL DEFAULT ''"),
        )),
        'BK_Log'               => array('BookingLog'),
        'BK_Competitions'      => array('BookingCompetitions'),
        'BK_Registrations'     => array('BookingRegistrations'),
        'BK_TargetCaps'        => array('BookingTargetCaps'),
        'BK_ClubManagers'      => array('BookingClubManagers'),
        'BK_ShopItems'         => array('BookingShopItems'),
        'BK_ShopVariants'      => array('BookingShopVariants'),
        'BK_ShopOrders'        => array('BookingShopOrders'),
        'BK_Payments'          => array('BookingPayments'),
        'BK_ReimportConflicts' => array('BookingReimportConflicts'),
        'BK_Surveys'           => array('BookingSurveys'),
        'BK_SurveyVoters'      => array('BookingSurveyVoters'),
        'BK_Waitlist'          => array('BookingWaitlist'),
        'BK_Refunds'           => array('BookingRefunds'),
        'BK_Ledger'            => array('BookingLedger'),
    );
}

/** Runs a migration statement; true when it went through. Never fatal (see the file header). */
function aut_names_write($sql)
{
    try {
        // 1050 exists, 1051/1146 unknown table, 1054 unknown column, 1060 duplicate column,
        // 1142 command denied (no CREATE VIEW right), 1347 not a view: a concurrent request
        // got there first, or the database user lacks a right the transition can do without.
        safe_w_sql($sql, false, array(0, 1050, 1051, 1054, 1060, 1142, 1146, 1347));
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Moves the database to the new table names (see the file header). Once per session, one
 * catalog query when there is nothing left to do.
 */
function aut_table_names()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = '_aut_names_v1';
    if (!empty($_SESSION[$flag])) return;

    $map = aut_table_map();
    $names = array();
    foreach ($map as $old => $m) $names[] = StrSafe_DB($old) . ',' . StrSafe_DB($m[0]);
    $rs = safe_r_sql("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', $names) . ")", false, true);
    if (!$rs) return;
    // Keyed in lower case: with lower_case_table_names=1 the catalog returns the names folded.
    $type = array();
    // bytes: table names are ASCII
    while ($r = safe_fetch($rs)) $type[strtolower($r->TABLE_NAME)] = $r->TABLE_TYPE;

    $complete = true;
    foreach ($map as $old => $m) {
        // bytes: table names are ASCII
        if (!aut_table_name_move($old, $m[0], $m[1] ?? array(), $type[strtolower($old)] ?? null, $type[strtolower($m[0])] ?? null)) {
            $complete = false;
        }
    }
    if ($complete) $_SESSION[$flag] = true;
}

/** Is this table empty? (A missing or unreadable one counts as not empty: never dropped.) */
function aut_table_empty($table)
{
    $rs = safe_r_sql("SELECT 1 FROM `$table` LIMIT 1", false, true);
    return $rs && !safe_fetch($rs);
}

/**
 * One table. $oldType / $newType: 'BASE TABLE', 'VIEW' or null. Returns false when work is
 * left for a next request.
 */
function aut_table_name_move($old, $new, $cols, $oldType, $newType)
{
    $renamed = false;   // the old name was a table here: a view takes its place
    // Two real tables: a CREATE of the new name ran before the rename, or a copy taken before
    // 1.2.0 was restored next to the new tables. The empty one gives way; when both hold
    // rows, nothing is dropped and the new one stays in use (ianseo-restore clears that case
    // before loading such a copy).
    if ($oldType === 'BASE TABLE' && $newType === 'BASE TABLE') {
        if (aut_table_empty($new) && aut_names_write("DROP TABLE `$new`")) {
            $newType = null;
        } elseif (aut_table_empty($old) && aut_names_write("DROP TABLE `$old`")) {
            $oldType = null;
            $renamed = true;
        } else {
            error_log("AUTH: tables $old and $new both hold rows; $new is used, $old is left untouched.");
            return true;
        }
    }

    if ($oldType === 'BASE TABLE' && $newType === null) {
        if (!aut_names_write("RENAME TABLE `$old` TO `$new`")) return false;
        $renamed = true;
        $newType = 'BASE TABLE';
    }
    if ($newType !== 'BASE TABLE') return true;   // fresh installation: the schema creates it

    if ($cols) {
        $have = array();
        $rs = safe_r_sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($new), false, true);
        while ($rs && ($r = safe_fetch($rs))) $have[$r->COLUMN_NAME] = true;
        $change = array();
        foreach ($cols as $oc => $c) {
            if (isset($have[$oc]) && !isset($have[$c[0]])) $change[] = "CHANGE `$oc` `{$c[0]}` {$c[1]}";
        }
        // One statement for all the columns: either all are renamed or none.
        if ($change && !aut_names_write("ALTER TABLE `$new` " . implode(', ', $change))) return false;
    }

    // Only next to a table that had the old name: a fresh installation has no old code to serve.
    if ($renamed) {
        $select = '*';
        if ($cols) {
            $list = array();
            foreach ($cols as $oc => $c) $list[] = "`{$c[0]}` AS `$oc`";
            $select = implode(', ', $list);
        }
        if (aut_names_write("CREATE VIEW `$old` AS SELECT $select FROM `$new`")
            && !safe_r_sql("SHOW CREATE VIEW `$old`", false, true)) {
            // mysqldump reads a view through SHOW CREATE VIEW: without that right, the nightly
            // backup would fail on it. A missing view only matters to outdated code.
            aut_names_write("DROP VIEW IF EXISTS `$old`");
        }
    }
    return true;
}
