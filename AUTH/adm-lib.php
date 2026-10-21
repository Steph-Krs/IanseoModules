<?php
/**
 * adm-lib.php — the organiser side of AUTH as one whole: its sections in the order they are used
 * (online registration before the competition, check-in, judges, food & shop, volunteers on the
 * day, then the accounts of the server), the pages of each, and the frame every organiser page
 * shares: title, navigation between the pages of its section, stylesheet (assets/admin.css),
 * save button fixed at the bottom right (assets/admin.js).
 *
 * menu.php builds the Modules menu from aut_adm_sections() too: the menu and the navigation of
 * the pages cannot disagree.
 *
 * WARNING: loaded by menu.php on EVERY ianseo page. Functions only; every read of a module
 * table is guarded ($force), nothing is written.
 */

if (defined('AUT_ADM_LOADED')) return;
define('AUT_ADM_LOADED', true);

require_once __DIR__ . '/lang-lib.php';
require_once __DIR__ . '/booking/lib/lang.php';   // bk_t (functions only)
require_once __DIR__ . '/shop/lib/lang.php';      // shp_t (functions only)

/** Address of a page of the module, from the site root. */
function aut_adm_url($file)
{
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/' . ltrim((string) $file, '/');
}

/**
 * Who is looking, for a page (outside menu.php): ['can' => fn(feature, sub, level), 'tour',
 * 'admin', 'logged']. Same rules as menu.php: the module administrator is the ADMIN account, or
 * anybody with the root right when the sign-in system is off (local console, development).
 */
function aut_adm_context()
{
    global $CFG;
    $can = function ($f, $sub, $lvl) { return function_exists('hasFullACL') && hasFullACL($f, $sub, $lvl); };
    $admin = !empty($_SESSION['AUTH_ROOT'])
        || (empty($_SESSION['AUTH_ENABLE']) && $can(AclRoot, '', AclReadWrite));
    return array('can' => $can, 'tour' => intval($_SESSION['TourId'] ?? 0), 'admin' => $admin,
        'logged' => !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_User']));
}

/**
 * The sections of the organiser side that this person may open, in menu order:
 * [key => ['title', 'pages' => [['file', 'label']]]] — a section without any page is left out.
 * $can(feature, sub, level): the ianseo rights; $tour: the open competition (0 = none).
 *
 * What a competition uses decides some pages (payments, survey, food & shop): read guarded,
 * the tables may not exist yet — every page is then offered, as before.
 */
function aut_adm_sections($can, $tour, $admin, $logged)
{
    $tour = intval($tour);
    $out = array();
    if ($tour > 0) {
        $rw = $can(AclParticipants, 'pEntries', AclReadWrite);
        $ro = $can(AclParticipants, 'pEntries', AclReadOnly);
        $tg = $can(AclParticipants, 'pTarget', AclReadWrite);

        // What the competition uses.
        $showPay = $showSurvey = true;
        $rs = safe_r_sql("SELECT BcPublishLevel, BcPayments, BcSurvey FROM BookingCompetitions WHERE BcTournament = $tour", false, true);
        if ($rs) {
            $c = safe_fetch($rs);
            $level = $c ? intval($c->BcPublishLevel) : 1;
            $showPay = $level >= 2 || ($c && intval($c->BcPayments) === 1);
            $showSurvey = $level >= 2 && $c && intval($c->BcSurvey) === 1;
        }
        // SELECT *: a column may be missing on a server not updated yet.
        $rs = safe_r_sql("SELECT * FROM ShopSettings WHERE SgTournament = $tour", false, true);
        if ($rs && ($c = safe_fetch($rs))) {
            // "On my account" at the stands needs the payments page.
            if (intval($c->SgTab ?? 0) === 1) $showPay = true;
        }

        // Online registration: before the competition, then its payments, then the survey.
        $p = array();
        if ($rw) $p[] = array('booking/admin/competition.php', bk_t('MnuSettings'));
        if ($rw) $p[] = array('booking/admin/mandate.php', bk_t('MnuMandate'));
        if ($tg) $p[] = array('booking/admin/field.php', bk_t('MnuField'));
        if ($tg) $p[] = array('booking/admin/targets.php', bk_t('MnuTargets'));
        if ($rw && $showPay) $p[] = array('booking/admin/dues.php', bk_t('Payments'));
        if ($ro && $showSurvey) $p[] = array('booking/admin/survey.php', bk_t('MnuSurvey'));
        $out['booking'] = array('title' => bk_t('Brand'), 'pages' => $p);

        // Check-in and judges: one page for now, two views of it.
        $out['desk'] = array('title' => shp_t('DkMenu'), 'pages' => $ro ? array(array('shop/desk/list.php', shp_t('DkMnuList'))) : array());
        $out['judges'] = array('title' => shp_t('DkJudges'), 'pages' => $ro ? array(array('shop/desk/list.php?v=equip', shp_t('DkJudgesList'))) : array());

        // Food & shop: settings and points of sale, catalogue, live orders, reports.
        // Every page, opened or not: the organiser prepares everything beforehand (user's choice).
        $p = array();
        if ($rw) $p[] = array('shop/admin/index.php', shp_t('MnuSettings'));
        if ($rw) $p[] = array('shop/admin/catalog.php', shp_t('MnuCatalog'));
        if ($ro) $p[] = array('shop/admin/orders.php', shp_t('MnuOrders'));
        if ($ro) $p[] = array('shop/admin/reports.php', shp_t('MnuReports'));
        $out['shop'] = array('title' => shp_t('MnuTitle'), 'pages' => $p);

        // ianseo's own screens opened to the volunteers (vol-lib.php): the switch and what each group opens.
        $out['ianseo'] = array('title' => shp_t('IanMenu'), 'pages' => $ro ? array(array('shop/admin/ianseo.php', shp_t('IanMnuPage'))) : array());

        // Volunteers: one team for the stands, the check-in and ianseo's screens.
        $out['staff'] = array('title' => shp_t('MnuStaff'), 'pages' => $rw ? array(array('shop/admin/staff.php', shp_t('MnuStaffPage'))) : array());
    }

    // Accounts of the server: what every organiser uses, then the administrator's pages, from
    // setting the server up to following it.
    $p = array();
    if ($logged || $admin) {
        $p[] = array('index.php', aut_t('MenuShare'));
        $p[] = array('tickets.php', aut_t('MenuReport'));
    }
    if ($admin) {
        $p[] = array('admin/index.php', aut_t('MenuUsers'));
        $p[] = array('admin/config.php', aut_t('MenuConfig'));
        $p[] = array('admin/deploy.php', aut_t('MenuDeploy'));
        $p[] = array('admin/legal.php', aut_t('MenuLegal'));
        if (function_exists('aut_sfa_present') && aut_sfa_present()) $p[] = array('admin/sfa.php', aut_t('MenuSfaOnly'));
        $p[] = array('admin/trust.php', aut_t('MenuTrust'));
        $p[] = array('admin/tickets.php', aut_t('MenuTickets'));
        $p[] = array('admin/anonymise.php', aut_t('MenuAnonymise'));
        $p[] = array('admin/distinctions.php', aut_t('MenuDistinctions'));
        $p[] = array('admin/stats.php', aut_t('MenuStats'));
        $p[] = array('admin/update.php', aut_t('MenuUpdate'));
    }
    $out['auth'] = array('title' => aut_t('MenuTitle'), 'pages' => $p);

    return array_filter($out, function ($s) { return !empty($s['pages']); });
}

/**
 * Modules menu entries of the module, ready for $ret['MODS']: the sections in order, each a
 * clickable title (its first page) and its pages, with a separator between the online
 * registration and the day of the competition, before the accounts of the server, and around
 * the module (MENU_DIVIDER: the core draws an <hr>).
 */
function aut_adm_menu($can, $tour, $admin, $logged, $othersFollow)
{
    $sections = aut_adm_sections($can, $tour, $admin, $logged);
    $groups = array(array('booking'), array('desk', 'judges', 'shop', 'ianseo', 'staff'), array('auth'));
    $out = array();
    $sep = defined('MENU_DIVIDER') ? MENU_DIVIDER : '---';
    foreach ($groups as $g) {
        $block = array();
        foreach ($g as $k) {
            if (empty($sections[$k])) continue;
            $s = $sections[$k];
            $items = array($s['title'] . '|' . aut_adm_url($s['pages'][0][0]));
            foreach ($s['pages'] as $pg) $items[] = $pg[1] . '|' . aut_adm_url($pg[0]);
            $block['AUT_' . strtoupper($k)] = $items;   // bytes: our own ASCII section keys
        }
        if (!$block) continue;
        $out[] = $sep;
        foreach ($block as $k => $v) $out[$k] = $v;
    }
    if ($out && $othersFollow) $out[] = $sep;
    return $out;
}

/** The section and page of the script being shown: [section key, page entry] or [null, null]. */
function aut_adm_where($sections, $file, $query = '')
{
    $want = ltrim($file, '/') . ($query !== '' ? '?' . $query : '');
    foreach ($sections as $k => $s) {
        foreach ($s['pages'] as $pg) if ($pg[0] === $want) return array($k, $pg);
    }
    return array(null, null);
}

/**
 * Stylesheets and scripts of an organiser page, for $JS_SCRIPT (printed in the page head by the
 * core template). $extra: more lines. $viewport: false for the pages that keep the core's look
 * (aut_adm_open_plain), laid out like every ianseo page.
 */
function aut_adm_assets(array $extra = array(), $viewport = true)
{
    $v = '';
    $vf = __DIR__ . '/version.json';
    if (is_file($vf)) { $j = json_decode((string) file_get_contents($vf), true); $v = (string) ($j['version'] ?? ''); }
    $q = '?v=' . rawurlencode($v);
    return array_merge($viewport ? array('<meta name="viewport" content="width=device-width, initial-scale=1">') : array(), array(
        '<link rel="stylesheet" href="' . htmlspecialchars(aut_adm_url('assets/admin.css') . $q, ENT_QUOTES) . '">',
        '<script defer src="' . htmlspecialchars(aut_adm_url('assets/admin.js') . $q, ENT_QUOTES) . '"></script>',
    ), $extra);
}

/** Navigation between the pages of a section, or '' when it has a single page. */
function aut_adm_nav($section, $page, $class = 'sa-nav')
{
    if (count($section['pages']) < 2) return '';
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $html = '<nav class="' . $e($class) . '" aria-label="' . $e($section['title']) . '">';
    foreach ($section['pages'] as $pg) {
        $on = $pg[0] === $page;
        $html .= '<a href="' . $e(aut_adm_url($pg[0])) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . $e($pg[1]) . '</a>';
    }
    return $html . '</nav>';
}

/**
 * Opening of an organiser page, after the core template: the frame (#shpadm), the title and,
 * when the section has several pages, the navigation between them. $page: the page's file
 * relative to AUTH/ with its query when it is one of two views ('shop/desk/list.php?v=equip').
 * $actions: markup shown at the right of the title (buttons, already escaped). $class: extra
 * class of the frame, for the page's own styles. $help: what the page is for (markup), behind a
 * "?" next to the title (aut_adm_help).
 */
function aut_adm_open($page, $title, $actions = '', $class = '', $help = '')
{
    $ctx = aut_adm_context();
    $sections = aut_adm_sections($ctx['can'], $ctx['tour'], $ctx['admin'], $ctx['logged']);
    $parts = explode('?', $page, 2);
    list($key, ) = aut_adm_where($sections, $parts[0], $parts[1] ?? '');
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $q = $h = '';
    if ($help !== '') list($q, $h) = aut_adm_help($help);
    $html = '<div id="shpadm" class="sa-page' . ($class !== '' ? ' ' . $e($class) : '') . '">'
        . '<div class="sa-title"><h1>' . $e($title) . $q . '</h1>' . ($actions !== '' ? '<div class="sa-title-act">' . $actions . '</div>' : '') . '</div>'
        . $h;
    if ($key !== null) $html .= aut_adm_nav($sections[$key], $page);
    return $html;
}

/**
 * An explanation shown on demand (user's choice, 2026-10-11: regular users do not need them and
 * they took the room; of the three ways tried, the fold-out was kept): [the "?" button, the text].
 * The button goes next to the title, field or option it explains, the text right after it — out
 * of a checkbox's label, or clicking the text would tick the box. A click unfolds the text under
 * it (assets/admin.js). $html: markup, escaped by the caller.
 */
function aut_adm_help($html)
{
    static $n = 0;
    $id = 'sa-help-' . (++$n);
    return array(
        '<button type="button" class="sa-q" aria-expanded="false" aria-controls="' . $id . '" title="'
            . htmlspecialchars(aut_t('AdmHelpQ'), ENT_QUOTES, 'UTF-8') . '">?</button>',
        '<span class="sa-help" id="' . $id . '" hidden>' . $html . '</span>',
    );
}

/**
 * Tabs of a page — display only, everything is in the page (assets/admin.js; the tab shown is
 * kept for the visit). $group: name of the set (unique in the page); $tabs: [key => [label
 * (markup, escaped by the caller), content]]. The first tab shows before the script runs.
 */
function aut_adm_tabs($group, array $tabs)
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $bar = $panels = '';
    $first = true;
    foreach ($tabs as $k => $t) {
        $bar .= '<button type="button" class="sa-tab' . ($first ? ' on' : '') . '" role="tab" aria-selected="' . ($first ? 'true' : 'false')
            . '" data-tab="' . $e($k) . '">' . $t[0] . '</button>';
        $panels .= '<div class="sa-tabpanel" role="tabpanel" data-tabpanel="' . $e($k) . '"' . ($first ? '' : ' hidden') . '>' . $t[1] . '</div>';
        $first = false;
    }
    return '<div class="sa-tabset" data-tabset="' . $e($group) . '"><div class="sa-tabbar" role="tablist">' . $bar . '</div>' . $panels . '</div>';
}

/** A heading with its explanation on demand: <hN>title ?</hN> + the text. $help: markup or ''. */
function aut_adm_title($tag, $title, $help = '', $attr = '')
{
    $q = $h = '';
    if ($help !== '') list($q, $h) = aut_adm_help($help);
    return '<' . $tag . $attr . '>' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . $q . '</' . $tag . '>' . $h;
}

/**
 * Opening of a page of the accounts of the server (Multi-comptes). Those keep the look of the
 * core's pages — its tables, its font, its compactness (user's choice): the frame only adds the
 * navigation between the pages of the section, on one line in the size of the ianseo menus
 * (assets/admin.js makes it smaller when the window is too narrow), and the fixed save button
 * (aut_adm_save). No title: the page keeps its own. Closed by aut_adm_close().
 */
function aut_adm_open_plain($page)
{
    $ctx = aut_adm_context();
    $sections = aut_adm_sections($ctx['can'], $ctx['tour'], $ctx['admin'], $ctx['logged']);
    list($key, ) = aut_adm_where($sections, $page);
    return '<div id="autadm">' . ($key !== null ? aut_adm_nav($sections[$key], $page, 'sa-nav sa-fit') : '');
}

/** End of the frame opened by aut_adm_open() or aut_adm_open_plain(). */
function aut_adm_close()
{
    return '</div>';
}

/**
 * The page's save button, fixed at the bottom right of the screen (assets/admin.css, admin.js:
 * moved aside when the GUIDE module's panel sits there). $form: id of the form it submits.
 */
function aut_adm_save($form, $label = '')
{
    $label = $label !== '' ? $label : aut_t('AdmSave');
    return '<button type="submit" form="' . htmlspecialchars((string) $form, ENT_QUOTES) . '" class="sa-btn sa-primary sa-save" data-sa-save>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
}

/**
 * First element of a form whose save button is fixed outside it: Enter in a field submits the
 * form through the FIRST submit button in it — without this one, a "Delete" button of a line.
 */
function aut_adm_default_submit()
{
    return '<button type="submit" class="sa-default-submit" tabindex="-1" aria-hidden="true"></button>';
}
