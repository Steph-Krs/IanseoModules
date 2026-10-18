<?php
/**
 * "A new version is available" notice on the ianseo home page.
 *
 * The core already tells the administrator when its own update check is getting
 * old (index.php, CheckLastSWUpdate). This does the same for the custom modules:
 * one line on the home page naming the module, the version waiting in the
 * repository, and a link to the module's update screen.
 *
 * WHAT IT MUST NEVER DO
 *   - Slow a page down. The page never waits for the network: the menu reads one
 *     row of the core Parameters table, and only when the cached answer is too
 *     old does the browser ask notice.php — once the page has finished loading.
 *     That endpoint releases the session lock before going on the network, so a
 *     slow or missing connection cannot hold up the user's other pages either.
 *   - Break a page. upd_notice_menu() runs from menu.php, which ianseo includes
 *     on every page: from there it writes nothing, reads only a core table with
 *     $force, and every failure ends in silence.
 *   - Spend data. Only the version number is wanted, so only the first bytes of
 *     the remote version.json are asked for (an HTTP Range: about a hundred bytes
 *     instead of the whole file and its release notes, 19 KB for AUTH), and the
 *     answer is cached for the whole installation.
 *
 * STORAGE: two rows of the core Parameters table, no table of our own.
 *   UpdNoticeOff    comma-separated names of the modules whose notice the
 *                   administrator turned off on their update screen. Absent
 *                   means on: the notice is on by default.
 *   UpdNoticeCache  JSON, per module: {"v": remote version, "t": unix time}.
 *                   "v" is empty when the last read failed. Written by
 *                   notice.php and by the update screen, never from a menu.
 * Two rows rather than one so that a cache write, which reads and rewrites the
 * whole value, can never undo an administrator's choice made at the same time.
 *
 * TO USE IT IN A MODULE
 *   menu.php (anywhere; $acl is the core's, in scope in every menu.php):
 *       if (is_file(dirname(__DIR__) . '/_shared/notice-lib.php')) {
 *           require_once dirname(__DIR__) . '/_shared/notice-lib.php';
 *           if (function_exists('upd_notice_menu')) upd_notice_menu(__DIR__, isset($acl) ? $acl : array());
 *       }
 *   admin/update.php: 'notice' => true in the options of upd_render_common_page(),
 *   which adds the on/off switch to the update screen.
 * The guards matter: an installation whose _shared is older than the module
 * simply shows no notice instead of failing on every page.
 */

/** Seconds a successful read stays valid. */
const UPD_NOTICE_TTL_OK = 43200;
/** Seconds before a failed read (no connection, GitHub down) is tried again. */
const UPD_NOTICE_TTL_FAIL = 3600;
/** Bytes asked of the remote version.json: "version" is its first key. */
const UPD_NOTICE_BYTES = 128;

/**
 * Is the page being built the ianseo home page (the root index.php)?
 *
 * Compared as files rather than URLs, so it holds whatever ROOT_DIR the
 * installation is served under and whether the visitor typed "/" or
 * "/index.php".
 *
 * @return bool
 */
function upd_notice_on_home() {
    global $CFG;

    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $home   = realpath((string)($CFG->DOCUMENT_PATH ?? '') . 'index.php');
    if ($script === false || $home === false) return false;

    // Windows paths are case-insensitive, and Apache and PHP do not always
    // agree on the case of the drive letter.
    return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($script, $home) === 0 : $script === $home;
}

/**
 * May the current user see the notice?
 *
 * The rule of the update screen itself (upd_is_admin), written against the
 * menu's $acl: hasFullACL() would rewrite the ACL request the core records for
 * the page being built. With an account module, only the server administrator
 * view; without one, ianseo's own AclRoot.
 *
 * @param array $acl The core's ACL array, as computed for the menu.
 * @return bool
 */
function upd_notice_allowed($acl) {
    if (!function_exists('subFeatureAcl') || !is_array($acl)) return false;
    if (subFeatureAcl($acl, AclRoot, '') < AclReadWrite) return false;
    return trim((string)($_SESSION['AUTH_User'] ?? '')) === '' || !empty($_SESSION['AUTH_ROOT']);
}

/**
 * The two Parameters rows, read in a single query.
 *
 * $force makes a failed query return false instead of ending the page — this
 * runs from menu.php.
 *
 * @param bool $reload Read again after a write.
 * @return array ['off' => [module names], 'cache' => [name => ['v', 't']]].
 */
function upd_notice_state($reload = false) {
    static $state = null;
    if ($state !== null && !$reload) return $state;

    $state = ['off' => [], 'cache' => []];
    $rs = safe_r_sql("SELECT ParId, ParValue FROM Parameters
        WHERE ParId IN ('UpdNoticeOff', 'UpdNoticeCache')", false, true);
    while ($rs && ($row = safe_fetch($rs))) {
        if ($row->ParId === 'UpdNoticeOff') {
            $state['off'] = array_values(array_filter(explode(',', (string)$row->ParValue)));
        } else {
            $cache = json_decode((string)$row->ParValue, true);
            if (is_array($cache)) $state['cache'] = $cache;
        }
    }
    return $state;
}

/**
 * Is the notice on for this module? On unless turned off.
 *
 * @param string $name Module name.
 * @return bool
 */
function upd_notice_is_on($name) {
    return !in_array($name, upd_notice_state()['off'], true);
}

/**
 * Turn a module's notice on or off.
 *
 * @param string $name Module name, already validated.
 * @param bool $on
 */
function upd_notice_set_on($name, $on) {
    $off = array_values(array_diff(upd_notice_state(true)['off'], [$name]));
    if (!$on) $off[] = $name;
    sort($off);

    if ($off) SetParameter('UpdNoticeOff', implode(',', $off));
    else      DelParameter('UpdNoticeOff');
    upd_notice_state(true);
}

/**
 * Record remote versions in the cache, in one write.
 *
 * @param array $entries name => remote version, '' for a failed read.
 */
function upd_notice_store($entries) {
    $cache = upd_notice_state(true)['cache'];
    foreach ($entries as $name => $remote) {
        $cache[$name] = ['v' => (string)$remote, 't' => time()];
    }
    SetParameter('UpdNoticeCache', json_encode($cache));
    upd_notice_state(true);
}

/**
 * Is a cache entry recent enough to be trusted?
 *
 * abs(): a clock set back (a laptop crossing time zones, an NTP correction)
 * must not freeze an entry for as long as it moved.
 *
 * @param array|null $entry ['v', 't'].
 * @return bool
 */
function upd_notice_fresh($entry) {
    if (!is_array($entry) || !isset($entry['t'])) return false;
    $ttl = ($entry['v'] ?? '') !== '' ? UPD_NOTICE_TTL_OK : UPD_NOTICE_TTL_FAIL;
    return abs(time() - (int)$entry['t']) < $ttl;
}

/**
 * What a version number may look like. Also what makes it safe to put on a
 * page: the remote one comes from the repository.
 */
const UPD_NOTICE_VERSION = '[0-9][0-9A-Za-z.+-]{0,31}';

/**
 * A version number, or null when it does not look like one.
 *
 * @param mixed $v
 * @return string|null
 */
function upd_notice_clean($v) {
    return is_string($v) && preg_match('/^' . UPD_NOTICE_VERSION . '$/', $v) ? $v : null;
}

/**
 * The "version" value at the start of a version.json, without decoding it.
 *
 * The text may be cut anywhere after the version: it is the first bytes of the
 * file.
 *
 * @param string|false $text Beginning of a version.json.
 * @return string|null
 */
function upd_notice_parse($text) {
    if (!is_string($text)) return null;
    if (!preg_match('/"version"\s*:\s*"(' . UPD_NOTICE_VERSION . ')"/', $text, $m)) return null;
    return $m[1];
}

/**
 * Version of the installed module, read from the start of its version.json.
 *
 * @param string $module_dir Module root.
 * @return string|null
 */
function upd_notice_local($module_dir) {
    $f = $module_dir . '/version.json';
    if (!is_file($f)) return null;

    $v = upd_notice_parse(@file_get_contents($f, false, null, 0, UPD_NOTICE_BYTES));
    if ($v !== null) return $v;

    // A version.json whose "version" is not its first key: read it whole.
    require_once __DIR__ . '/update-lib.php';
    return upd_notice_clean(upd_local_version($module_dir)['version'] ?? null);
}

/**
 * Version published in the repository, for the least data possible.
 *
 * A Range request for the first bytes of version.json; raw.githubusercontent.com
 * honours it (206 Partial Content). A server that ignores it sends the whole file
 * (200), which parses just as well. Only a partial answer that does not hold the
 * version — a version.json whose first key is something else — costs a second,
 * complete read.
 *
 * @param array $cfg Module configuration (upd_load_config).
 * @return string The version, or '' when it could not be read.
 */
function upd_notice_fetch($cfg) {
    require_once __DIR__ . '/update-lib.php';

    $base = upd_raw_base($cfg);
    if ($base === '') return '';

    $headers = "User-Agent: ianseo-custom-module/1.0\r\n"
             . 'Range: bytes=0-' . (UPD_NOTICE_BYTES - 1) . "\r\n";
    if (!empty($cfg['github_token'])) $headers .= "Authorization: token {$cfg['github_token']}\r\n";

    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    $raw    = @file_get_contents("$base/version.json", false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)
            ? (int)$m[1] : 0;

    if ($status !== 200 && $status !== 206) return '';

    $v = upd_notice_parse($raw);
    if ($v === null && $status === 206) {
        $v = upd_notice_clean(upd_remote_version($cfg)['version'] ?? null);
    }
    return $v ?? '';
}

/**
 * The notice for one module, when the repository holds a newer version.
 *
 * Text and link label are translated here; the page script only lays them out.
 *
 * @param string $module_dir Module root.
 * @param string $local Installed version.
 * @param string $remote Remote version, '' when unknown.
 * @return array|null ['m', 'text', 'link', 'url'], or null when there is nothing to say.
 */
function upd_notice_item($module_dir, $local, $remote) {
    global $CFG;

    if ($remote === '' || !version_compare($remote, $local, '>')) return null;

    require_once __DIR__ . '/update-lib.php';
    if (is_file(__DIR__ . '/paths.php')) require_once __DIR__ . '/paths.php';

    $name = basename($module_dir);
    $url  = '';
    if (is_file($module_dir . '/admin/update.php')) {
        $base = function_exists('cmod_url') ? cmod_url($module_dir) : '';
        $url  = ($base !== '' ? $base : $CFG->ROOT_DIR . 'Modules/Custom/' . $name . '/') . 'admin/update.php';
    }

    return [
        'm'    => $name,
        'text' => upd_text('NoticeNewVersion', ['name' => $name, 'remote' => $remote, 'local' => $local]),
        'link' => upd_text('NoticeOpenUpdate'),
        'url'  => $url,
    ];
}

/**
 * Called from a module's menu.php: leave on the home page what the browser needs.
 *
 * Three outcomes, cheapest first:
 *   - not the home page, not an administrator, notice off, or a recent cache
 *     saying the module is up to date: nothing at all is written to the page;
 *   - a recent cache saying a newer version exists: the notice itself;
 *   - no recent cache: the module's name, for the browser to ask notice.php
 *     about once the page has loaded.
 * Several modules calling this on one page share one script and one request.
 *
 * @param string $module_dir Module root (__DIR__ of its menu.php).
 * @param array $acl The core's ACL array, in scope in menu.php.
 */
function upd_notice_menu($module_dir, $acl) {
    if (!upd_notice_on_home() || !upd_notice_allowed($acl)) return;

    $name = basename($module_dir);
    if (!empty($GLOBALS['_upd_notice_seen'][$name])) return;
    $GLOBALS['_upd_notice_seen'][$name] = true;

    if (!is_file($module_dir . '/module.json') || !upd_notice_is_on($name)) return;

    $local = upd_notice_local($module_dir);
    if ($local === null) return;

    $entry = upd_notice_state()['cache'][$name] ?? null;
    if (upd_notice_fresh($entry)) {
        $item = upd_notice_item($module_dir, $local, (string)$entry['v']);
        if ($item === null) return;
    } else {
        $item = ['m' => $name, 'check' => 1];
    }

    $data = json_encode($item, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo upd_notice_script() . '<script>(window.UpdNotice = window.UpdNotice || []).push(' . $data . ');</script>' . "\n";
}

/**
 * The page script, once per page.
 *
 * It waits for "load" — the page and everything it pulls in — before doing
 * anything, then shows the notices already known and asks notice.php, in one
 * request, about the modules whose cache was too old. The rows copy the core's
 * own update notice in index.php: a divider, then a bold centred cell spanning
 * the table, right under the title. "#Content": the page header (#TourInfo)
 * holds a table of the same class, before the one of the page.
 *
 * @return string A <script> element, or '' when already written.
 */
function upd_notice_script() {
    global $CFG;

    if (!empty($GLOBALS['_upd_notice_js'])) return '';
    $GLOBALS['_upd_notice_js'] = true;

    if (is_file(__DIR__ . '/paths.php')) require_once __DIR__ . '/paths.php';
    $endpoint = function_exists('cmod_url') ? cmod_url(__DIR__) : '';
    if ($endpoint === '') $endpoint = $CFG->ROOT_DIR . 'Modules/Custom/_shared/';
    $endpoint = json_encode($endpoint . 'notice.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return <<<JS
<script>
window.addEventListener('load', function () {
    var items = window.UpdNotice || [], known = [], ask = [];
    items.forEach(function (i) { if (i.check) ask.push(i.m); else known.push(i); });

    var after = null;
    function show(list) {
        var table = document.querySelector('#Content table.Tabella');
        if (!table || !table.rows.length) return;
        list.forEach(function (n) {
            var divider = document.createElement('tr'), row = document.createElement('tr'), cell = document.createElement('td');
            divider.className = 'Divider';
            divider.appendChild(document.createElement('td')).colSpan = 6;
            cell.colSpan = 6;
            cell.className = 'Bold Center';
            cell.appendChild(document.createTextNode(n.text + ' '));
            if (n.url) {
                var a = cell.appendChild(document.createElement('a'));
                a.href = n.url;
                a.className = 'Link';
                a.textContent = n.link;
            }
            row.appendChild(cell);
            var ref = (after || table.rows[0]).nextSibling, parent = table.rows[0].parentNode;
            parent.insertBefore(divider, ref);
            parent.insertBefore(row, ref);
            after = row;
        });
    }

    show(known);
    if (!ask.length || !window.fetch) return;
    fetch({$endpoint} + '?m=' + encodeURIComponent(ask.join(',')), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && !j.error && j.notices) show(j.notices); })
        .catch(function () {});
});
</script>

JS;
}
