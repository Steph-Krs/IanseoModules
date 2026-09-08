<?php
/**
 * Shared schema helpers for ianseo custom modules: safe existence tests, safe
 * column additions, and table/column renames that survive being replayed.
 *
 * WHY THESE HELPERS ARE NOT OPTIONAL
 *
 * 1. A module's schema function runs on EVERY new session, not once per
 *    installation. It is gated by a $_SESSION flag carrying the schema version,
 *    so the whole body replays whenever a user opens a new session. Anything
 *    that rewrites existing data must therefore be gated on "did this migration
 *    just happen", which is what the boolean returned by cmod_add_column() is
 *    for — never on "does the module look up to date".
 *
 * 2. safe_w_sql() reports failures through safe_error(), which sends a 404
 *    header and calls exit(). Nothing catches that. A single failing statement
 *    inside a schema function therefore aborts it in the middle, and every table
 *    declared after the failure is never created. The helpers here test with
 *    information_schema first (a catalog, so it never errors on a missing table)
 *    and wrap their own writes, so an unexpected failure degrades to "not done
 *    yet, retry next session" instead of a broken half-installation.
 *
 * 3. Renames have an ordering rule that is the MIRROR of the one for columns,
 *    and getting it wrong loses data silently:
 *
 *        cmod_rename_table('GUIDE_Progress', 'GuiProgress');   // FIRST
 *        safe_w_sql("CREATE TABLE IF NOT EXISTS GuiProgress (…)");  // THEN
 *
 *    In the other order, CREATE makes an empty GuiProgress, the rename then
 *    refuses to run because its target exists, and the module happily serves an
 *    empty table while the real rows sit in GUIDE_Progress. No SQL error, no
 *    warning — just silently missing data. Columns are the opposite: add them
 *    AFTER the CREATE of their table.
 *
 * Usage:
 *     require_once dirname(__DIR__) . '/_shared/schema-lib.php';
 */

/**
 * What kind of object carries this name in the current database.
 *
 * Reads information_schema rather than issuing SHOW TABLES / DESCRIBE, because
 * a catalog query returns an empty set for a missing object instead of raising
 * error 1146 — the difference between "no" and a dead page.
 *
 * Note on comparison: MySQL and MariaDB compare table names case-insensitively
 * unless lower_case_table_names=0 (the Linux default). This function therefore
 * matches the way the server itself will resolve the name, and callers must not
 * rely on it to detect a casing mistake. Casing is checked statically instead,
 * by the bench's compliance checker.
 *
 * @param string $name Table or view name.
 * @return string|null 'BASE TABLE', 'VIEW', or null when nothing carries the name.
 */
function cmod_table_type($name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) return null;

    $rs = safe_r_sql(
        "SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($name) . " LIMIT 1",
        false,
        true   // $force: return false instead of killing the page
    );
    if (!$rs) return null;

    $row = safe_fetch($rs);
    return $row ? $row->TABLE_TYPE : null;
}

/**
 * Does a base table (not a view) with this name exist?
 *
 * @param string $name Table name.
 * @return bool
 */
function cmod_table_exists($name) {
    return cmod_table_type($name) === 'BASE TABLE';
}

/**
 * Does this column exist on this table?
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @return bool False when the table itself is missing.
 */
function cmod_column_exists($table, $column) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;

    $rs = safe_r_sql(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "  . StrSafe_DB($table) . "
           AND COLUMN_NAME = " . StrSafe_DB($column) . " LIMIT 1",
        false,
        true
    );
    return $rs ? (bool)safe_fetch($rs) : false;
}

/**
 * Run a write that must never be able to abort the caller.
 *
 * safe_w_sql() takes a list of tolerated mysqli error numbers as its third
 * argument, but it can also raise mysqli_sql_exception depending on the driver's
 * error reporting mode, so the call needs a try/catch as well as the list.
 *
 * @param string $sql Statement to execute.
 * @param array $tolerate mysqli error numbers to accept as success.
 * @return bool True when the statement ran without an untolerated error.
 */
function cmod_try_write($sql, $tolerate = []) {
    try {
        safe_w_sql($sql, false, array_merge([0], $tolerate));
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Add a column only if it is missing, without ever being fatal.
 *
 * MUST be called AFTER the CREATE TABLE of the same table in the same schema
 * function. Placed before it, this returns false on a fresh installation (the
 * table does not exist yet) and the column is simply missing until the next
 * session — recoverable, but the module runs broken in the meantime.
 *
 * @param string $table Table to alter.
 * @param string $column Column to add.
 * @param string $definition SQL type and clauses, e.g. "TINYINT(1) NOT NULL DEFAULT 0".
 * @param string $after Optional column name to place it after.
 * @return bool TRUE ONLY when the column was just added. Gate every data
 *              migration on this: the schema body replays each session, and a
 *              migration keyed on anything else will re-run and corrupt rows it
 *              already converted.
 */
function cmod_add_column($table, $column, $definition, $after = '') {
    // Missing table: refuse rather than let an ALTER take the page down. The
    // schema body replays next session, by which time the CREATE will have run.
    if (!cmod_table_exists($table)) return false;
    if (cmod_column_exists($table, $column)) return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;

    $pos = ($after !== '' && preg_match('/^[A-Za-z0-9_]+$/', $after)) ? " AFTER `$after`" : '';
    if (!cmod_try_write("ALTER TABLE `$table` ADD COLUMN `$column` $definition$pos")) return false;

    // Confirm rather than trust: an ALTER that was tolerated as a known error
    // code would otherwise be reported as a successful migration.
    return cmod_column_exists($table, $column);
}

/**
 * Rename a table, keeping the data, and optionally leave the old name usable.
 *
 * MUST be called BEFORE the CREATE TABLE of $new in the same schema function —
 * see the ordering note at the top of this file.
 *
 * Idempotent across sessions. The four states it distinguishes:
 *   - old is a base table, new absent      -> rename (and optionally add a view)
 *   - old is a view, new is a base table   -> already migrated, nothing to do
 *   - both are base tables                 -> refuse: two sets of real rows, a
 *                                             human has to decide which wins
 *   - old absent                           -> nothing to do
 *
 * @param string $old Current table name.
 * @param string $new Target table name.
 * @param bool $compat_view Leave a view named $old pointing at $new, so code and
 *             queries still referring to the old name keep working during a
 *             progressive migration. Simple views like this one accept reads and
 *             writes. Drop it with cmod_drop_compat_view() once nothing uses it.
 * @return string 'renamed', 'already', 'conflict', 'absent' or 'failed'.
 */
function cmod_rename_table($old, $new, $compat_view = true) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $old)) return 'failed';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $new)) return 'failed';
    if ($old === $new) return 'already';

    $oldType = cmod_table_type($old);
    $newType = cmod_table_type($new);

    if ($oldType === null)                                   return 'absent';
    if ($oldType === 'VIEW' && $newType === 'BASE TABLE')    return 'already';
    if ($oldType === 'BASE TABLE' && $newType !== null)      return 'conflict';
    if ($oldType !== 'BASE TABLE')                           return 'failed';

    if (!cmod_try_write("RENAME TABLE `$old` TO `$new`")) return 'failed';
    if (!cmod_table_exists($new)) return 'failed';

    if ($compat_view) {
        // Best effort: losing the compatibility view is a nuisance, not a data
        // problem, so a failure here does not turn the rename into a failure.
        cmod_try_write("CREATE OR REPLACE VIEW `$old` AS SELECT * FROM `$new`");
    }
    return 'renamed';
}

/**
 * Rename a column, keeping the data.
 *
 * Uses CHANGE rather than RENAME COLUMN, which MariaDB only understands from
 * 10.5.2 and MySQL from 8.0 — CHANGE works everywhere, at the cost of having to
 * restate the column definition.
 *
 * @param string $table Table holding the column.
 * @param string $old Current column name.
 * @param string $new Target column name.
 * @param string $definition Full type and clauses, e.g. "VARCHAR(64) NOT NULL DEFAULT ''".
 * @return string 'renamed', 'already', 'conflict', 'absent' or 'failed'.
 */
function cmod_rename_column($table, $old, $new, $definition) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return 'failed';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $old))   return 'failed';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $new))   return 'failed';
    if (!cmod_table_exists($table))               return 'absent';

    $hasOld = cmod_column_exists($table, $old);
    $hasNew = cmod_column_exists($table, $new);

    if (!$hasOld && $hasNew)  return 'already';
    if (!$hasOld)             return 'absent';
    if ($hasOld && $hasNew)   return 'conflict';   // both present: a human decides

    if (!cmod_try_write("ALTER TABLE `$table` CHANGE `$old` `$new` $definition")) return 'failed';
    return cmod_column_exists($table, $new) ? 'renamed' : 'failed';
}

/**
 * Drop a compatibility view left behind by cmod_rename_table().
 *
 * Refuses to touch a base table, so calling it with the wrong name cannot
 * destroy data.
 *
 * @param string $name View name.
 * @return bool True when the view is gone (including when it never existed).
 */
function cmod_drop_compat_view($name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) return false;

    $type = cmod_table_type($name);
    if ($type === null)  return true;
    if ($type !== 'VIEW') return false;

    return cmod_try_write("DROP VIEW `$name`");
}

/**
 * Collation clause for joining a module VARCHAR column to an ianseo one.
 *
 * ianseo core tables and freshly created module tables can end up with
 * different utf8mb4 collations depending on the server (utf8mb4_0900_ai_ci
 * versus utf8mb4_general_ci and friends). Joining two VARCHAR columns across
 * that boundary raises error 1267 and takes the page down. Append this on the
 * MODULE side of such a join:
 *
 *     JOIN Events e ON e.EvCode = c.BcEvent COLLATE utf8mb4_unicode_ci
 *
 * @return string The COLLATE clause, with a leading space.
 */
function cmod_collate() {
    return ' COLLATE utf8mb4_unicode_ci';
}
