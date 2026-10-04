<?php
/**
 * lib/ui.php — HTML shell of the archer space (public side).
 *
 * This space does NOT go through Common/Templates/head.php: that template renders the
 * organiser menu and expects an open competition. The page is therefore standalone, in the
 * colours of the charter (see CHARTE_GRAPHIQUE.md), with CSS scoped by #bk (no style shared
 * between modules). Texts: lib/lang.php (bk_t).
 */

if (defined('BK_UI_LOADED')) return;
define('BK_UI_LOADED', true);

require_once __DIR__ . '/lang.php';

function bk_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function bk_public_url($page = '')
{
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/' . $page;
}

/** Navigation icon (inline SVG, stroke = current colour). On a phone the text label is
 *  hidden and only the icon stays, to fit the width. */
function bk_nav_icon($name)
{
    static $p = array(
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 9.5V20h14V9.5"/>',
        'cal'  => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        'club' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c0-3 2.7-5 5.5-5s5.5 2 5.5 5"/><path d="M16.5 5.6a3.2 3.2 0 010 5.6M17 14.6c2.3.5 3.5 2.2 3.5 4.4"/>',
        'out'  => '<path d="M15 4.5h4.5v15H15"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h9"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 3.5L16 11H5"/>',
        'stat' => '<path d="M4 20V4M4 20h16"/><path d="M8 20v-5M13 20v-9M18 20v-3"/>',
        'map'  => '<path d="M12 21s-6-5.3-6-10a6 6 0 1112 0c0 4.7-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>',
    );
    $inner = $p[$name] ?? '';
    return '<svg class="bk-nav-ic" width="17" height="17" viewBox="0 0 24 24" fill="none"'
         . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true">' . $inner . '</svg>';
}

/**
 * Page head. $layout:
 *  - 'card' : centred card (sign-in, errors);
 *  - 'page' : full width with the navigation bar (signed-in space).
 */
function bk_head($title, $layout = 'page')
{
    $archer = function_exists('bk_current_archer') ? bk_current_archer() : null;
    echo '<!DOCTYPE html>' . "\n" . '<html lang="' . bk_e(mb_substr(aut_lang_code(), 0, 2)) . '">' . "\n<head>\n"
        . '<meta charset="utf-8">' . "\n" . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '<title>' . bk_e($title) . ' — ' . bk_e(bk_t('ShellTitle')) . "</title>\n"
        . '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/bk.css')) . '?v=' . bk_e(bk_version()) . '">' . "\n"
        . "</head>\n<body>\n" . '<div id="bk" class="bk-' . bk_e($layout) . '">' . "\n";

    // Maintenance coming: the same warning as on the organiser side, so that nobody loses a
    // registration being made. Function of the parent AUTH module, called IF it exists; it
    // returns an empty string while no window is planned or near.
    $mnt = function_exists('aut_maintenance_notice') ? aut_maintenance_notice() : '';
    if ($mnt !== '') echo '  <div class="bk-maint">⏳ ' . bk_e($mnt) . "</div>\n";

    if (function_exists('bk_impersonating') && ($imp = bk_impersonating())) {
        echo '  <div class="bk-imp">👁 ' . bk_e(bk_t('ImpView')) . ' — <b>' . bk_e((string) ($imp['label'] ?? '')) . '</b> — '
            . bk_e(bk_t('ImpReadOnly')) . ' <a class="bk-imp-x" href="'
            . bk_e($GLOBALS['CFG']->ROOT_DIR . 'Modules/Custom/AUTH/admin/impersonate.php?exit=1&aut_csrf='
                . rawurlencode((string) ($_SESSION['AUT_CSRF'] ?? '')))
            . '">' . bk_e(bk_t('ImpExit')) . "</a>\n  </div>\n";
    }

    if ($layout === 'page') {
        echo '  <header class="bk-top">' . "\n" . '    <a class="bk-brand" href="' . bk_e(bk_public_url()) . '">'
            . bk_e(bk_t('Brand')) . "</a>\n";
        if ($archer) {
            $link = function ($page, $icon, $title, $label, $class = '') {
                return '        <a' . ($class !== '' ? ' class="' . $class . '"' : '') . ' href="' . bk_e(bk_public_url($page))
                    . '" title="' . bk_e($title) . '">' . bk_nav_icon($icon) . '<span class="bk-nav-lab">' . bk_e($label) . "</span></a>\n";
            };
            echo '      <nav class="bk-nav">' . "\n"
                . $link('', 'home', bk_t('NavHome'), bk_t('NavHome'))
                . $link('calendar.php', 'cal', bk_t('NavCalendar'), bk_t('NavCalendar'))
                . $link('map.php', 'map', bk_t('NavMap'), bk_t('NavMap'))
                . $link('registrations.php', 'list', bk_t('NavMyRegs'), bk_t('NavMyRegs'))
                . $link('stats.php', 'stat', bk_t('NavStatsTitle'), bk_t('NavStats'))
                . (bk_is_manager() ? $link('club.php', 'club', bk_t('NavClub'), bk_t('NavClub')) : '')
                . $link('tickets.php', 'flag', bk_t('NavReportTitle'), bk_t('NavReport'))
                . '        <span class="bk-who">' . bk_e($archer->BaName . ' ' . $archer->BaFamilyName) . "</span>\n"
                . $link('logout.php', 'out', bk_t('NavLogout'), bk_t('NavLogout'), 'bk-out')
                . "      </nav>\n";
        }
        echo "  </header>\n";
        if ($archer) {
            $cur = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $on = function ($f) use ($cur) { return $cur === $f ? ' on' : ''; };
            echo '  <nav class="bk-botnav" aria-label="' . bk_e(bk_t('NavAria')) . '">' . "\n"
                . '    <a class="bk-bn' . $on('index.php') . '" href="' . bk_e(bk_public_url()) . '">' . bk_nav_icon('home')
                . '<span>' . bk_e(bk_t('BotHome')) . "</span></a>\n"
                . '    <a class="bk-bn' . $on('map.php') . '" href="' . bk_e(bk_public_url('map.php')) . '">' . bk_nav_icon('map')
                . '<span>' . bk_e(bk_t('NavMap')) . "</span></a>\n"
                . '    <a class="bk-bn bk-bn-center' . $on('calendar.php') . '" href="' . bk_e(bk_public_url('calendar.php')) . '">'
                . '<span class="bk-bn-ic">' . bk_nav_icon('cal') . '</span><span>' . bk_e(bk_t('NavCalendar')) . "</span></a>\n"
                . '    <a class="bk-bn' . $on('tickets.php') . '" href="' . bk_e(bk_public_url('tickets.php')) . '">' . bk_nav_icon('flag')
                . '<span>' . bk_e(bk_t('NavReport')) . "</span></a>\n"
                . '    <a class="bk-bn" href="' . bk_e(bk_public_url('logout.php')) . '">' . bk_nav_icon('out')
                . '<span>' . bk_e(bk_t('BotQuit')) . "</span></a>\n"
                . "  </nav>\n";
        }
    }
    echo '  <main class="bk-main">' . "\n";
}

function bk_foot()
{
    echo "  </main>\n</div>\n</body>\n</html>\n";
}

/** Version of the module — breaks the browser cache of the assets after an update. */
function bk_version()
{
    static $v = null;
    if ($v === null) {
        // The AUTH module's version.json: booking has had none of its own since it was merged
        // into AUTH, and reading that missing file gave "0" forever — stylesheets and scripts
        // then never changed address, and browsers kept the old ones after an update.
        $j = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/version.json'), true);
        $v = (is_array($j) && !empty($j['version'])) ? $j['version'] : '0';
    }
    return $v;
}

/**
 * Is the signed-in archer the manager of a club? Decides whether the menu entry shows.
 * lib/club.php is loaded only if present: the shell must work on a page without it.
 */
function bk_is_manager()
{
    static $r = null;
    if ($r !== null) return $r;
    $r = false;
    $a = function_exists('bk_current_archer') ? bk_current_archer() : null;
    if ($a) {
        if (!function_exists('bk_manager_scopes')) {
            $f = __DIR__ . '/club.php';
            if (is_file($f)) require_once $f;
        }
        if (function_exists('bk_manager_scopes')) $r = (bool) bk_manager_scopes($a);
    }
    return $r;
}

/** Short date, day first (2026-08-15 → 15/08/2026). */
function bk_date_fr($d)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $d), $m)) return '';
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/** "15/08/2026 at 09:30" in the visitor's language; the date alone when there is no time (00:00). */
function bk_date_time($dt)
{
    $d = bk_date_fr($dt);
    if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/', trim((string) $dt), $m)) return $d;
    if ($m[1] === '00' && $m[2] === '00') return $d;
    return bk_t('DateAt', array('date' => $d, 'time' => date(bk_t('TimeFormat'), mktime(intval($m[1]), intval($m[2]), 0, 1, 1, 2000))));
}

/** "on 15/08/2026" or "from 13/08/2026 to 16/08/2026", in the visitor's language. */
function bk_date_range($from, $to)
{
    $f = bk_date_fr($from);
    $t = bk_date_fr($to);
    if ($f === '' && $t === '') return '';
    if ($f === '' || $f === $t) return bk_t('DateOn', $f ?: $t);
    if ($t === '') return bk_t('DateOn', $f);
    return bk_t('DateFromTo', array('from' => $f, 'to' => $t));
}

function bk_msg($type, $text)
{
    return '<div class="bk-msg bk-msg-' . bk_e($type) . '">' . bk_e($text) . '</div>';
}

/** Redirects then ends the script (never any output before a header). */
function bk_redirect($page)
{
    header('Location: ' . bk_public_url($page));
    exit;
}

/**
 * "Seen from another account" (administrator, read only): refuses any write. Called by
 * public/boot.php on every POST during an observation. Renders a standalone message and
 * ends — no data is changed.
 */
function bk_impersonation_block()
{
    header('HTTP/1.1 403 Forbidden');
    $imp  = function_exists('bk_impersonating') ? bk_impersonating() : null;
    $back = htmlspecialchars((string) ($_SERVER['HTTP_REFERER'] ?? bk_public_url()), ENT_QUOTES, 'UTF-8');
    bk_head(bk_t('ImpBlockTitle'), 'card');
    echo '<div class="bk-msg bk-msg-err" style="text-align:center">👁 <b>' . bk_e(bk_t('ImpBlockHead')) . '</b><br>'
       . bk_t('ImpBlockText', bk_e((string) ($imp['label'] ?? bk_t('ImpThisArcher'))))
       . '<br><br><a class="bk-btn" href="' . $back . '">' . bk_e(bk_t('Back')) . '</a></div>';
    bk_foot();
    exit;
}

/** Requires a signed-in licensee; to the sign-in otherwise. Then the terms-of-use guard. */
function bk_require_archer()
{
    $a = bk_current_archer();
    if (!$a) {
        // Back to this page after signing in (a link given to the archers, such as the page of a
        // competition): AUTH's login.php reads it, see bk_next_after_login(). Only our own page
        // name and its query are kept — the address is rebuilt from them, never taken as is.
        $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && preg_match('/^[a-z0-9_-]+\.php$/i', $page)
            && !in_array($page, array('login.php', 'logout.php', 'index.php'), true) && mb_strlen($query) <= 300) {
            $_SESSION['BK_NEXT'] = array('page' => $page . ($query !== '' ? '?' . $query : ''), 'at' => time());
        }
        bk_redirect('login.php');
    }
    // Terms of use: acceptance required (timestamped, versioned), except on the acceptance page
    // itself (no loop). legal-lib.php lives in the parent AUTH module. Skipped during an
    // administrator's observation: they cannot accept anything for the licensee (POST blocked)
    // and would be stuck on legal-accept.php.
    if (empty($a->BK_IMP)
        && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'legal-accept.php') {
        require_once dirname(__DIR__, 2) . '/legal-lib.php';
        // Only when the operator has set up the legal information (see the organiser guard).
        if (aut_legal_configured() && !aut_legal_archer_ok($a)) bk_redirect('legal-accept.php');
    }
    // Audience measurement (aggregated) — the archer is counted by identity, with no cookie.
    // stats-usage.php lives in the parent AUTH module; guarded and isolated.
    require_once dirname(__DIR__, 2) . '/stats-usage.php';
    if (empty($a->BK_IMP) && function_exists('aut_track')) aut_track('archer', $a->BaId);   // nothing counted while observing
    return $a;
}
