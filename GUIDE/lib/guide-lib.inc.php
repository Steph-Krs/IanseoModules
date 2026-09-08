<?php
/**
 * Internal library of the Interactive Guide module.
 *
 * Included by guide-api.php, menu.php, index.php and every admin page. It holds
 * everything those files share:
 *   - the current user, and the permission rules that depend on whether an
 *     account module is installed;
 *   - translations, following the core's language-file mechanism;
 *   - the database schema (per-user course progress, page visits, preferences)
 *     including the migration from the module's earlier table names;
 *   - condition evaluation, which reads ianseo's own tables to decide whether a
 *     step of a course has been accomplished;
 *   - the content catalogue (courses, checklists, troubleshooting trees).
 *
 * The module writes only to its own tables. It never writes to an ianseo core
 * table; the conditions below read them and nothing more.
 */

/* =======================================================================
 * Shared libraries
 * =====================================================================*/

/**
 * Locate the _shared folder that sits beside the module.
 *
 * Walking up rather than counting directory levels keeps this working if the
 * module is installed somewhere other than Modules/Custom/ — see the note at the
 * top of _shared/paths.php.
 */
$_guide_shared = null;
for ($_d = __DIR__, $_i = 0; $_i < 6; $_i++) {
    if (is_file($_d . '/_shared/schema-lib.php')) { $_guide_shared = $_d . '/_shared'; break; }
    $_p = dirname($_d);
    if ($_p === $_d) break;
    $_d = $_p;
}
if ($_guide_shared !== null) {
    require_once $_guide_shared . '/paths.php';
    require_once $_guide_shared . '/schema-lib.php';
}
unset($_guide_shared, $_d, $_p, $_i);

/* =======================================================================
 * Translations
 * =====================================================================*/

/**
 * Translate a key, using the same mechanism and fallback as the ianseo core.
 *
 * The core resolves get_text($key, $module) against
 * Common/Languages/<code>/<Module>.php: it loads English first, then merges the
 * user's language over it, so a key missing from a translation falls back to
 * English instead of disappearing. This function does exactly that, but reads
 * the module's own languages/ folder — a custom module cannot install files into
 * Common/Languages/ without them being erased by the next ianseo update.
 *
 * The file format is identical to the core's, so when this module joins the
 * ianseo distribution the files move to Common/Languages/<code>/Guide.php
 * unchanged and every call here becomes get_text($key, 'Guide').
 *
 * @param string $key Key defined in languages/en.php, or in the section's en.php.
 * @param mixed $a Value substituted for {$a}, or an array for {$a[name]}.
 * @param string $section '' for the module's own strings, or a subfolder name —
 *        the same idea as the $module argument of the core's get_text().
 * @return string The translated text, or a visible marker when the key is unknown.
 */
function guide_text($key, $a = null, $section = '') {
    $strings = guide_lang_all($section);

    if (!isset($strings[$key])) {
        // Same shape as the core's marker for a missing key: loud in development,
        // harmless in production, and searchable.
        return '<b>[[' . $key . ']@[' . guide_lang_code() . ']@[Guide'
             . ($section !== '' ? '/' . $section : '') . ']]</b>';
    }

    $result = $strings[$key];
    if (is_null($a)) return $result;

    if (is_scalar($a)) {
        return str_replace(['{$a}', '$a'], (string)$a, $result);
    }
    if (is_array($a)) {
        foreach ($a as $k => $v) {
            $result = str_replace(['{$a[' . $k . ']}', '$a[' . $k . ']'], (string)$v, $result);
        }
    }
    return $result;
}

/**
 * The string table of one section, for the current language, English underneath.
 *
 * SECTIONS MIRROR THE CORE'S MODULES
 * ianseo splits its own translations into one $lang file per area —
 * Common/Languages/<code>/Common.php, Tournament.php, Errors.php — and
 * get_text($key, $module) loads only the one it needs. This does the same: the
 * default section is the module's own languages/<code>.php, and a named section
 * is languages/<section>/<code>.php, in the identical format.
 *
 * That is not decoration. The interface strings are loaded on EVERY ianseo page,
 * because menu.php injects the panel everywhere, while the authoring
 * documentation is several kilobytes wanted on exactly one screen. Splitting
 * them is what the core's own mechanism is for.
 *
 * @param string $section '' for the module's own strings, or a subfolder name.
 * @return array Key to translated string.
 */
function guide_lang_all($section = '') {
    static $cache = [];

    $section = preg_match('/^[a-z0-9_-]*$/', $section) ? $section : '';
    if (isset($cache[$section])) return $cache[$section];

    $dir = dirname(__DIR__) . '/languages/' . ($section !== '' ? $section . '/' : '');

    $lang = [];
    if (is_file($dir . 'en.php')) include $dir . 'en.php';
    $strings = is_array($lang) ? $lang : [];

    $code = guide_lang_code();
    // ianseo language codes may be regional (fr-ca, pt-br, zh-cn). Try the exact
    // code, then the base language, so a regional user still gets a translation
    // instead of falling all the way back to English.
    foreach (array_unique([$code, mb_substr($code, 0, 2)]) as $try) {
        if ($try === 'en' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $try)) continue;
        if (!is_file($dir . $try . '.php')) continue;

        $lang = [];
        include $dir . $try . '.php';
        if (is_array($lang)) $strings = array_merge($strings, $lang);
        break;
    }
    return $cache[$section] = $strings;
}

/**
 * Language code currently selected in ianseo, lower-case.
 *
 * Falls back to English when the core helper is unavailable, which happens in
 * command-line contexts where config.php has not been loaded.
 *
 * @return string Such as 'fr' or 'pt-br'.
 */
function guide_lang_code() {
    if (!function_exists('SelectLanguage')) return 'en';
    // bytes: a language code is ASCII by definition (fr, pt-br), so there is no
    // character here that mb_strtolower would fold differently.
    $code = strtolower((string)SelectLanguage());
    return $code !== '' ? $code : 'en';
}

/**
 * The strings the browser side needs, as an array ready for json_encode().
 *
 * The panel, the recorder and the course player run entirely in JavaScript, so
 * their wording cannot go through guide_text() at render time. menu.php publishes
 * this table once per page as window.GUIDE_T and the client reads it from there.
 *
 * @return array Key to translated string.
 */
function guide_js_strings() {
    // Every key the browser needs carries the Js prefix, so adding a string to
    // the player is a matter of naming it Js… in the language files — there is
    // no second list here to forget to update. The handful of shared keys that
    // the server side also uses are named explicitly.
    $shared = [
        'ModuleName', 'CmdMarkDone', 'CmdPrev', 'CmdNextStep', 'CmdRestartHints', 'CmdBackHint',
        'PanelMove', 'PanelMinimise', 'PanelMaximise', 'PanelCloseCourse',
        'Quiz', 'Challenge', 'CmdGuide', 'CmdResume', 'Troubleshooting',
        'TargetBronze', 'TargetSilver', 'TargetGold',
        'InProgressStep', 'PreviousVersion',
        'RecTitle', 'RecAbort', 'RecHint', 'RecPause', 'RecCurrentPage',
        'RecCurrentPageHint', 'RecUndo', 'RecUndoHint', 'RecDone',
    ];

    $all = guide_lang_all();
    $out = [];
    foreach ($all as $k => $v) {
        if (strpos($k, 'Js') === 0) $out[$k] = $v;
    }
    foreach ($shared as $k) {
        if (isset($all[$k])) $out[$k] = $all[$k];
    }
    return $out;
}

/* =======================================================================
 * Multilingual content
 *
 * A content file carries every language it has been written in, inside the file
 * itself, so a course travels as one document and the update mechanism has one
 * version number to compare. Only the TEXT of a field becomes a language map:
 *
 *     "title": "My first competition"                     (one language)
 *     "title": { "en": "My first competition",
 *                "it": "La mia prima gara" }              (several)
 *
 * The structure around it — steps, triggers, selectors, pages — is never
 * duplicated. Duplicating it would let the languages drift apart, and a course
 * whose translated version points at a different element than its original is
 * worse than a course with no French at all.
 *
 * A plain string stays valid, so existing content keeps working untouched and a
 * translation can be added one field at a time.
 * =====================================================================*/

/**
 * Is this value a language map rather than ordinary content?
 *
 * True only when the array is non-empty and EVERY key looks like a language
 * code, which no real content field does.
 *
 * @param mixed $value
 * @return bool
 */
function guide_is_lang_map($value) {
    if (!is_array($value) || !$value) return false;

    foreach ($value as $k => $v) {
        if (!is_string($k) || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $k)) return false;
        if (!is_string($v)) return false;
    }
    return true;
}

/**
 * Resolve one language map to a single string.
 *
 * Falls back the way the interface does: the exact language, then the base
 * language of a regional code, then English, then whatever the file does have —
 * a course written only in Italian is still better than an empty panel.
 *
 * @param mixed $value A string, or a language map.
 * @param string $code Language code; the current interface language by default.
 * @return mixed The string to show, or $value untouched when it is not a map.
 */
function guide_i18n($value, $code = null) {
    if (!guide_is_lang_map($value)) return $value;

    // bytes: a language code is ASCII by definition, so folding its case needs
    // no multibyte handling.
    $code = $code === null ? guide_lang_code() : strtolower($code);
    foreach ([$code, mb_substr($code, 0, 2), 'en'] as $try) {
        if (isset($value[$try]) && $value[$try] !== '') return $value[$try];
    }
    return reset($value);
}

/**
 * Resolve every language map in a decoded content file.
 *
 * Walks the whole structure, so a new translatable field needs no change here:
 * writing it as a language map is enough.
 *
 * @param mixed $data Decoded content, or any part of it.
 * @param string|null $code Language code; the current interface language by default.
 * @return mixed The same structure with each language map replaced by one string.
 */
function guide_i18n_resolve($data, $code = null) {
    if (guide_is_lang_map($data)) return guide_i18n($data, $code);
    if (!is_array($data))         return $data;

    $out = [];
    foreach ($data as $k => $v) $out[$k] = guide_i18n_resolve($v, $code);
    return $out;
}

/**
 * Languages a content file carries, in the order they first appear.
 *
 * Used by the editor to offer the languages already present and to say which
 * fields are still missing one.
 *
 * @param mixed $data Decoded content.
 * @return array Language codes.
 */
function guide_content_languages($data) {
    $found = [];

    $walk = function ($node) use (&$walk, &$found) {
        if (guide_is_lang_map($node)) {
            foreach (array_keys($node) as $code) $found[$code] = true;
            return;
        }
        if (!is_array($node)) return;
        foreach ($node as $v) $walk($v);
    };
    $walk($data);

    return array_keys($found);
}

/* =======================================================================
 * Current user (account module is optional)
 * =====================================================================*/

/**
 * Identifier of the signed-in user, or '' when no account module is installed.
 *
 * $_SESSION['AUTH_User'] is the ianseo core convention (USERAUTH): an
 * authentication module sets it and revalidates it on every request. When it is
 * absent the installation is a classic single-machine one, and everybody shares
 * the progress of user ''.
 *
 * mb_substr, not substr: a user name is an international field, and cutting it
 * at 64 BYTES can split a character in half — the row then holds an invalid
 * sequence, and the identifier no longer matches the one the account module
 * set. VARCHAR(64) counts characters, so 64 characters is also what the column
 * actually holds.
 *
 * @return string At most 64 characters, matching the column width.
 */
function guide_current_user() {
    return mb_substr(trim((string)($_SESSION['AUTH_User'] ?? '')), 0, 64);
}

/**
 * How many competitions the current user can see.
 *
 * With an account module the list is restricted by $_SESSION['AUTH_COMP'], which
 * holds exact codes or LIKE patterns — the same convention the core's own home
 * page uses. Used to decide whether this account is new enough to deserve the
 * "learn to use ianseo" banner.
 *
 * @return int
 */
function guide_visible_tournament_count() {
    if (guide_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])
        && isset($_SESSION['AUTH_COMP']) && is_array($_SESSION['AUTH_COMP'])) {

        $parts = [];
        foreach ($_SESSION['AUTH_COMP'] as $p) {
            $p = (string)$p;
            $parts[] = (strpos($p, '%') !== false || strpos($p, '_') !== false)
                ? 'ToCode LIKE ' . StrSafe_DB($p)
                : 'ToCode = ' . StrSafe_DB($p);
        }
        if (!count($parts)) return 0;
        $rs = safe_r_sql("SELECT COUNT(*) AS cnt FROM Tournament WHERE " . implode(' OR ', $parts));
    } else {
        $rs = safe_r_sql("SELECT COUNT(*) AS cnt FROM Tournament");
    }
    $row = safe_fetch($rs);
    return $row ? (int)$row->cnt : 0;
}

/**
 * May the current user administer the guide?
 *
 * AclRoot alone is not enough. When an account module is installed, its
 * authCheckACL() grants AclReadWrite to any signed-in organiser on pages outside
 * a competition, so every organiser would pass a plain AclRoot test. The server
 * administrator view (AUTH_ROOT) is therefore required as well. Without any
 * account module the classic ianseo ACL applies unchanged.
 *
 * @return bool
 */
function guide_is_admin() {
    if (guide_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) return false;
    return hasFullACL(AclRoot, '', AclReadWrite);
}

/**
 * Guard for the admin pages: aborts to noAccess when the user is not allowed.
 */
function guide_check_admin() {
    global $CFG;

    checkFullACL(AclRoot, '', AclReadWrite);
    if (guide_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) {
        CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
        die();
    }
}

/* =======================================================================
 * Per-user preferences
 * =====================================================================*/

/**
 * Is contextual help enabled for the current user?
 *
 * Stored server-side so the preference follows the account from one machine to
 * another. Without an account module the client keeps it in localStorage instead.
 *
 * @return int 1 when enabled (the default), 0 otherwise.
 */
function guide_pref_ctx() {
    // Deliberately does NOT create the schema: menu.php calls this on every
    // ianseo page, and creating tables from there would turn an installation
    // problem in this module into an unreachable site. Before the tables exist,
    // the default answer is the right one anyway.
    if (!guide_tables_ready()) return 1;

    $rs  = safe_r_sql("SELECT GfCtxHelp FROM GuidePrefs WHERE GfUser="
        . StrSafe_DB(guide_current_user()), false, true);
    if (!$rs) return 1;

    $row = safe_fetch($rs);
    return $row ? (int)$row->GfCtxHelp : 1;
}

/**
 * Turn contextual help on or off for the current user.
 *
 * @param bool $on
 */
function guide_pref_set_ctx($on) {
    guide_ensure_schema();

    safe_w_sql("INSERT INTO GuidePrefs (GfUser, GfCtxHelp, GfUpdatedAt) VALUES ("
        . StrSafe_DB(guide_current_user()) . ", " . ($on ? 1 : 0) . ", "
        . StrSafe_DB(date('Y-m-d H:i:s')) . ")
        ON DUPLICATE KEY UPDATE GfCtxHelp=VALUES(GfCtxHelp), GfUpdatedAt=VALUES(GfUpdatedAt)");
}

/* =======================================================================
 * Database schema
 * =====================================================================*/

/**
 * Are the module's tables present? Never creates anything.
 *
 * THE RULE THIS EXISTS TO ENFORCE
 * menu.php is included on EVERY ianseo page by get_which_menu(). safe_r_sql()
 * and safe_w_sql() route any SQL error to safe_error(), which sends a 404 header
 * and calls exit() — no try/catch intercepts it. So a single missing table
 * (error 1146) reached from menu.php does not break this module: it makes the
 * entire installation unreachable, on every page, for every user.
 *
 * Code that runs on every page therefore asks this first and does without when
 * the answer is no. Creating the schema belongs to guide_ensure_schema(), which
 * only the module's own pages and AJAX endpoints call.
 *
 * Cached in the session because the answer only changes once, when the module is
 * first opened.
 *
 * @return bool
 */
function guide_tables_ready() {
    if (!empty($_SESSION['_guide_schema_v6'])) return true;
    if (!empty($_SESSION['_guide_tables_ready'])) return true;
    if (!function_exists('cmod_table_exists')) return false;

    // information_schema is a catalog: this answers "no" for a missing table
    // instead of raising error 1146, which is what makes it safe to call from
    // code that runs on every page.
    if (!cmod_table_exists('GuideVisits') || !cmod_table_exists('GuidePrefs')) return false;

    return $_SESSION['_guide_tables_ready'] = true;
}

/**
 * Create and migrate the module's tables.
 *
 * ONLY the module's own pages and AJAX endpoints may call this — never
 * menu.php, for the reason spelled out above guide_tables_ready().
 *
 * The session flag carries the schema version, so the body replays after a
 * module update. It therefore also replays on EVERY new session, which is why
 * any migration that rewrites existing values must be gated on the boolean
 * returned by cmod_add_column(): true only when the column was just created.
 * A migration keyed on anything else re-runs and corrupts rows it has already
 * converted.
 *
 * Statement order below is deliberate and must not be rearranged:
 *   1. renames, BEFORE any CREATE of the new names. In the other order CREATE
 *      makes an empty table, the rename then refuses to run because its target
 *      exists, and the module serves empty data while the real rows stay under
 *      the old name — with no SQL error anywhere.
 *   2. CREATE TABLE for each table.
 *   3. column migrations, AFTER the CREATE of the table they alter. Before it,
 *      they do nothing on a fresh installation.
 */
function guide_ensure_schema() {
    $flag = '_guide_schema_v6';
    if (!empty($_SESSION[$flag])) return;

    /* --- 1. Renames, before every CREATE --------------------------------
     * The module used to prefix its tables GUIDE_, which no core table does.
     * Renaming brings them in line with the ianseo convention. A view under the
     * old name is left behind so anything still referring to it keeps working
     * during the transition; drop the views with cmod_drop_compat_view() once
     * nothing does. */
    if (function_exists('cmod_rename_table')) {
        cmod_rename_table('GUIDE_Progress', 'GuideProgress');
        cmod_rename_table('GUIDE_Visits',   'GuideVisits');
        cmod_rename_table('GUIDE_Prefs',    'GuidePrefs');
    }

    /* --- 2. Progress ----------------------------------------------------- */

    // A shape older than the per-course unique key cannot be migrated in place,
    // because existing rows may violate the key that has to be added. The table
    // only holds progress through the courses, so recreating it costs a user
    // their place in a course and nothing else.
    if (cmod_table_exists('GuideProgress')) {
        $hasTour = cmod_column_exists('GuideProgress', 'GpTourId');
        $oldKey  = safe_r_sql("SHOW INDEX FROM GuideProgress WHERE Key_name='uq_form_tour'", false, true);
        if (!$hasTour || ($oldKey && safe_fetch($oldKey))) {
            cmod_try_write("DROP TABLE GuideProgress");
        }
    }

    safe_w_sql("CREATE TABLE IF NOT EXISTS GuideProgress (
        GpId        INT AUTO_INCREMENT PRIMARY KEY,
        GpUser      VARCHAR(64)  NOT NULL DEFAULT '',
        GpFormId    VARCHAR(30)  NOT NULL,
        GpFormVer   VARCHAR(20)  NOT NULL DEFAULT '1.0',
        GpTourId    INT          NOT NULL DEFAULT 0,
        GpStep      INT          NOT NULL DEFAULT 0,
        GpStatus    ENUM('en_cours','termine','obsolete') NOT NULL DEFAULT 'en_cours',
        GpQuiz      TINYINT(1)   NOT NULL DEFAULT 0,
        GpChallenge TINYINT(1)   NOT NULL DEFAULT 0,
        GpValidated TEXT,
        GpUpdatedAt DATETIME     NOT NULL,
        UNIQUE KEY uq_user_form (GpUser, GpFormId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Quiz and challenge results, added when a course became a container for
    // three activities rather than a single walkthrough.
    cmod_add_column('GuideProgress', 'GpQuiz',      'TINYINT(1) NOT NULL DEFAULT 0');
    cmod_add_column('GuideProgress', 'GpChallenge', 'TINYINT(1) NOT NULL DEFAULT 0');

    // Per-user progress, added when account modules appeared. Existing rows
    // become the progress of user '', which is what a single-machine install
    // uses anyway, so nothing is lost. The unique key has to move with it.
    if (cmod_add_column('GuideProgress', 'GpUser', "VARCHAR(64) NOT NULL DEFAULT ''", 'GpId')) {
        cmod_try_write("ALTER TABLE GuideProgress
            DROP INDEX uq_form,
            ADD UNIQUE KEY uq_user_form (GpUser, GpFormId)");
    }

    /* The courses shipped with the module used to carry a readable id derived
     * from their subject. They now carry an opaque one, like the first course
     * always did, so that an id says nothing about the course and two authors
     * writing on the same subject cannot collide. Progress is stored against
     * the id, so without this every reader would silently lose their place.
     *
     * Safe to replay, which matters because this whole function runs again on
     * every new session: after the first pass no row carries an old id, so the
     * statements match nothing. UPDATE IGNORE rather than UPDATE because the
     * unique key spans (GpUser, GpFormId) — a reader who somehow held both ids
     * keeps the newer row instead of the update failing.
     *
     * localStorage cannot be migrated from here. A reader without an account
     * therefore restarts these courses; their badge is lost, nothing else. */
    $renamed = [
        'duels-18m'              => 'ow1nhpo59nsqx1c6xyi4',
        'epreuves-equipes'       => '9vclui9hslrhbpkanruc',
        'programme-plan-cible'   => '98wiyk9lnjqltgqgejiq',
        'import-tableur'         => '5foza8hhqyrhkd14as7x',
        'logos-ecriteaux'        => '909qweo299fcw4peo0z9',
        'epreuves-parentes'      => 'i233dj7cn2c9n8pxa5nb',
        'poules'                 => '6gahyhped45dy80it29d',
        'isk-ng-lite'            => '3wrmhl4ee0qdono4xgpy',
        'publication-ianseo-net' => 'j77bebae7hmd14oa3vcj',
        'accreditation-dossards' => 'vrb9wn3tkcoo37uz4cnd',
        'sorties-tv'             => '7xjsix6ivre9fysy36lh',
    ];
    foreach ($renamed as $old => $new) {
        cmod_try_write("UPDATE IGNORE GuideProgress SET GpFormId = " . StrSafe_DB($new)
            . " WHERE GpFormId = " . StrSafe_DB($old));
    }

    /* --- 3. Page visits --------------------------------------------------
     * Feeds the "visited" conditions: a course step can require that the user
     * has actually opened a given ianseo page. */
    safe_w_sql("CREATE TABLE IF NOT EXISTS GuideVisits (
        GvId     INT AUTO_INCREMENT PRIMARY KEY,
        GvUser   VARCHAR(64)  NOT NULL DEFAULT '',
        GvTourId INT          NOT NULL DEFAULT 0,
        GvPath   VARCHAR(120) NOT NULL,
        GvWhen   DATETIME     NOT NULL,
        UNIQUE KEY uq_user_tour_path (GvUser, GvTourId, GvPath)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* --- 4. Preferences -------------------------------------------------- */
    safe_w_sql("CREATE TABLE IF NOT EXISTS GuidePrefs (
        GfUser      VARCHAR(64) NOT NULL PRIMARY KEY,
        GfCtxHelp   TINYINT(1)  NOT NULL DEFAULT 1,
        GfUpdatedAt DATETIME    NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION[$flag] = true;
}

/* =======================================================================
 * Page visits
 * =====================================================================*/

/**
 * Path of the running script, relative to the ianseo root and normalised.
 *
 * @return string Such as '/Tournament/' or '/Partecipants/ListLoad.php'.
 */
function guide_script_rel() {
    global $CFG;

    $s    = $_SERVER['SCRIPT_NAME'] ?? '';
    $root = rtrim($CFG->ROOT_DIR ?? '/', '/');
    // bytes: a URL path, and the offset comes from strpos/strlen, which count
    // bytes too. Mixing mb_substr with a byte offset is what would break here.
    if ($root && strpos($s, $root) === 0) $s = substr($s, strlen($root));

    return guide_norm_path($s !== '' ? $s : '/');
}

/**
 * Every path some condition watches, taken from the "visited" checks.
 *
 * @return array Normalised paths.
 */
function guide_tracked_paths() {
    static $paths = null;
    if (!is_null($paths)) return $paths;

    $paths = [];
    foreach (guide_load_conditions() as $c) {
        foreach (($c['checks'] ?? []) as $ch) {
            if (($ch['source'] ?? '') === 'visited' && !empty($ch['path'])) {
                $paths[] = guide_norm_path($ch['path']);
            }
        }
    }
    return $paths = array_values(array_unique($paths));
}

/**
 * Record a visit to the current page, if a condition watches it.
 *
 * This is the module's ONE write that happens outside its own pages: menu.php
 * calls it, so it runs on every ianseo page. Three things keep that acceptable,
 * and none of them may be removed:
 *
 *   1. the path test comes first, so the pages nobody watches — the vast
 *      majority — cost no query at all;
 *   2. it never creates the schema. If the tables are absent the visit is simply
 *      not recorded, and opening any page of the module creates them;
 *   3. the INSERT cannot be fatal, so a database problem here degrades to a lost
 *      visit rather than an unreachable installation.
 *
 * INSERT IGNORE against the unique key (GvUser, GvTourId, GvPath) means a page
 * visited a hundred times still holds one row.
 */
function guide_track_visit() {
    $path = guide_script_rel();
    if (!in_array($path, guide_tracked_paths())) return;
    if (!guide_tables_ready()) return;

    cmod_try_write("INSERT IGNORE INTO GuideVisits (GvUser, GvTourId, GvPath, GvWhen) VALUES ("
        . StrSafe_DB(guide_current_user()) . ", "
        . max(0, (int)($_SESSION['TourId'] ?? 0)) . ", "
        . StrSafe_DB($path) . ", "
        . StrSafe_DB(date('Y-m-d H:i:s')) . ")");
}

/* =======================================================================
 * Conditions
 *
 * A condition asks the ianseo database whether something has been accomplished
 * — a competition is open, archers are registered, targets are assigned. Course
 * steps use them to know when to move on, and challenges use them as their pass
 * criteria. Everything here READS; none of it writes.
 * =====================================================================*/

/**
 * Load the condition catalogue.
 *
 * @return array Decoded conditions.json, or an empty array.
 */
function guide_load_conditions() {
    $f = dirname(__DIR__) . '/conditions.json';
    if (!is_file($f)) return [];
    return json_decode(file_get_contents($f), true) ?: [];
}

/**
 * Save the condition catalogue.
 *
 * @param array $conditions
 * @return bool
 */
function guide_save_conditions($conditions) {
    $f = dirname(__DIR__) . '/conditions.json';
    return file_put_contents(
        $f,
        json_encode(array_values($conditions), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

/**
 * Is a condition satisfied? All of its checks must pass.
 *
 * Short-circuits on the first failing check. That matters when testing a new
 * condition: a leading "a competition is open" check stops the SQL checks after
 * it from running at all, so a condition can look broken purely because no
 * competition is open.
 *
 * @param array $cond One entry of conditions.json.
 * @return bool
 */
function guide_evaluate_condition($cond) {
    foreach ($cond['checks'] as $check) {
        if (!guide_evaluate_check($check)) return false;
    }
    return true;
}

/**
 * Optional INNER JOIN clause of an aggregate check.
 *
 * @param array $check
 * @return string SQL fragment, empty when the check declares no join.
 */
function guide_build_join($check) {
    if (empty($check['join']['table'])
        || !preg_match('/^(\w+)\s*=\s*(\w+)$/', $check['join']['on'] ?? '', $m)) return '';

    $jt = preg_replace('/[^a-zA-Z0-9_]/', '', $check['join']['table']);
    return " INNER JOIN `$jt` ON `{$m[1]}` = `{$m[2]}`";
}

/**
 * WHERE clause of an aggregate check.
 *
 * Column names are stripped of anything but word characters and values go
 * through StrSafe_DB, because conditions.json is editable from the admin screens.
 *
 * @param array $wheres Clause definitions.
 * @return string SQL fragment including the WHERE keyword, or empty.
 */
function guide_build_where($wheres) {
    $out = [];
    foreach ($wheres as $w) {
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $w['column']);

        if (isset($w['source']) && $w['source'] === 'session') {
            $out[] = "`$col` = " . (int)($_SESSION[$w['key']] ?? 0);
        } elseif (($w['op'] ?? '') === 'in') {
            $vals = array_map('trim', explode(',', (string)$w['value']));
            $out[] = "`$col` IN (" . implode(',', array_map('StrSafe_DB', $vals)) . ")";
        } else {
            $ops = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
            $op  = $ops[$w['op'] ?? 'eq'] ?? '=';
            $out[] = "`$col` $op " . StrSafe_DB($w['value']);
        }
    }
    return $out ? ' WHERE ' . implode(' AND ', $out) : '';
}

/**
 * Evaluate one check of a condition.
 *
 * Four kinds are supported, in the order tested below:
 *   - session: a value held in the ianseo session, such as the open competition;
 *   - visited: this user has opened a given page, recorded by guide_track_visit();
 *   - aggregate: a COUNT over a table, or max_group, the size of the largest
 *     group — which answers "at least N archers in one and the same category";
 *   - column: a single column value, joined to the session.
 *
 * @param array $check
 * @return bool
 */
function guide_evaluate_check($check) {
    if (isset($check['source']) && $check['source'] === 'session') {
        $val = isset($_SESSION[$check['key']]) ? (int)$_SESSION[$check['key']] : 0;
        return guide_compare($val, $check['op'], $check['value']);
    }

    // Restricted to the open competition unless "any_tournament" is set, so that
    // visiting a page during one competition does not satisfy a course being
    // followed during another.
    if (isset($check['source']) && $check['source'] === 'visited') {
        $path = guide_norm_path((string)($check['path'] ?? ''));
        if ($path === '') return false;

        guide_ensure_schema();
        $sql = "SELECT 1 FROM GuideVisits WHERE GvUser=" . StrSafe_DB(guide_current_user())
             . " AND GvPath=" . StrSafe_DB($path);
        if (empty($check['any_tournament'])) {
            $sql .= " AND GvTourId=" . max(0, (int)($_SESSION['TourId'] ?? 0));
        }
        $rs = safe_r_sql($sql . " LIMIT 1");
        return (bool)safe_fetch($rs);
    }

    $agg = $check['aggregate'] ?? '';
    if ($agg === 'count' || $agg === 'max_group') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $sql   = "FROM `$table`" . guide_build_join($check) . guide_build_where($check['where'] ?? []);

        if ($agg === 'max_group') {
            $cols = [];
            foreach ((array)($check['group_by'] ?? []) as $g) {
                $cols[] = '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $g) . '`';
            }
            if (!$cols) return false;
            $rs = safe_r_sql("SELECT COUNT(*) AS cnt $sql GROUP BY " . implode(',', $cols)
                . " ORDER BY cnt DESC LIMIT 1");
        } else {
            $rs = safe_r_sql("SELECT COUNT(*) AS cnt $sql");
        }
        $row = safe_fetch($rs);
        return guide_compare($row ? (int)$row->cnt : 0, $check['op'], $check['value']);
    }

    if (isset($check['table']) && isset($check['column'])) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $col   = preg_replace('/[^a-zA-Z0-9_]/', '', $check['column']);
        $where = '';

        // Join format "ToId = TourId" means WHERE `ToId` = $_SESSION['TourId'].
        if (isset($check['join']) && preg_match('/^(\w+)\s*=\s*(\w+)$/', $check['join'], $m)) {
            $jcol  = preg_replace('/[^a-zA-Z0-9_]/', '', $m[1]);
            $jkey  = preg_replace('/[^a-zA-Z0-9_]/', '', $m[2]);
            $where = " WHERE `$jcol` = " . (int)($_SESSION[$jkey] ?? 0);
        }

        $rs  = safe_r_sql("SELECT `$col` FROM `$table`$where LIMIT 1");
        $row = safe_fetch($rs);
        if (!$row) return false;
        return guide_compare($row->$col, $check['op'], $check['value']);
    }

    return false;
}

/**
 * Compare two values with one of the named operators.
 *
 * @param mixed $actual Value read from the database or the session.
 * @param string $op eq, neq, gt, gte, lt or lte.
 * @param mixed $expected Value declared in the condition.
 * @return bool False for an unknown operator, so a malformed condition never
 *              reports success.
 */
function guide_compare($actual, $op, $expected) {
    switch ($op) {
        case 'eq':  return $actual == $expected;
        case 'neq': return $actual != $expected;
        case 'gt':  return $actual >  $expected;
        case 'gte': return $actual >= $expected;
        case 'lt':  return $actual <  $expected;
        case 'lte': return $actual <= $expected;
        default:    return false;
    }
}

/* =======================================================================
 * Content catalogue
 * =====================================================================*/

/**
 * Folder holding the content files.
 *
 * @return string Absolute path with a trailing slash.
 */
function guide_content_dir() {
    return dirname(__DIR__) . '/content/';
}

/**
 * Every content item — course, checklist or troubleshooting tree — sorted for
 * the catalogue and for "next course" navigation.
 *
 * The pages[] entry of each item is what contextual help matches against: it
 * lists the ianseo pages the item talks about.
 *
 * @param bool $with_image Include the base64 thumbnail. Heavy, so the catalogue
 *             asks for it and everything else does not.
 * @return array Items sorted by order then title.
 */
function guide_content_list($with_image = false) {
    $out = [];

    foreach (glob(guide_content_dir() . '*.json') as $file) {
        $d = json_decode(file_get_contents($file), true);
        if (!$d || empty($d['id'])) continue;

        $type  = $d['type'] ?? 'formation';
        $pages = [];

        if ($type === 'formation') {
            foreach (($d['steps'] ?? []) as $s) {
                if (!empty($s['page']) && $s['page'] !== '*') $pages[] = $s['page'];
                foreach (($s['triggers'] ?? []) as $t) {
                    if (!empty($t['page']) && $t['page'] !== '*') $pages[] = $t['page'];
                }
            }
        } elseif ($type === 'checklist') {
            foreach (($d['items'] ?? []) as $it) {
                if (!empty($it['page'])) $pages[] = $it['page'];
            }
        } elseif ($type === 'faq') {
            foreach (($d['nodes'] ?? []) as $n) {
                if (!empty($n['page'])) $pages[] = $n['page'];
            }
        }

        // Text fields may be language maps; everything else is taken as it is.
        $entry = [
            'id'            => $d['id'],
            'type'          => $type,
            'title'         => guide_i18n($d['title'] ?? guide_text('Untitled')),
            'description'   => guide_i18n($d['description'] ?? ''),
            'version'       => $d['version'] ?? '1.0',
            'group'         => guide_i18n($d['group'] ?? ''),
            'subgroup'      => guide_i18n($d['subgroup'] ?? ''),
            'order'         => isset($d['order']) ? (int)$d['order'] : 9999,
            'steps_count'   => count($d['steps'] ?? []),
            'has_quiz'      => !empty($d['quiz']['questions']),
            'has_challenge' => !empty($d['challenge']['conditions']),
            'pages'         => array_values(array_unique($pages)),
            'file'          => basename($file),
        ];
        if ($with_image) $entry['image'] = $d['image'] ?? '';
        $out[] = $entry;
    }

    usort($out, function ($a, $b) {
        if ($a['order'] !== $b['order']) return $a['order'] - $b['order'];
        return strcmp($a['title'], $b['title']);
    });
    return $out;
}

/**
 * Courses only, in the order of the learning path.
 *
 * @return array
 */
function guide_formations_ordered() {
    $out = [];
    foreach (guide_content_list() as $c) {
        if ($c['type'] === 'formation') $out[] = $c;
    }
    return $out;
}

/**
 * Normalise a page path so two spellings of the same page compare equal.
 *
 * Drops the query string and reduces '/dir/index.php' to '/dir/'.
 *
 * @param string $p
 * @return string
 */
function guide_norm_path($p) {
    // bytes: a URL path cut at an offset strpos returned, which is a byte offset.
    $q = strpos($p, '?');
    if ($q !== false) $p = substr($p, 0, $q);
    return preg_replace('#/index\.php$#', '/', $p);
}
