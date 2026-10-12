<?php
/**
 * lib/posts.php — the posts of a volunteer on their phone: the stands of the till they have
 * rights on, the registry (documents, payments) and the judges' equipment check. One selector at
 * the top of every volunteer page switches between them, and the name of the volunteer opens a
 * small menu (their archer space, sign out) — user's choice, 2026-10-12.
 *
 * A post is offered only while it is open to this volunteer (stands: shp_till_open; registry and
 * equipment: shp_desk_rights). A volunteer whose page is closed goes to their next open post; with
 * none, a page says so (shp_staff_no_post_page).
 */

if (defined('SHP_POSTS_LOADED')) return;
define('SHP_POSTS_LOADED', true);

require_once __DIR__ . '/desk.php';   // staff, staff session, rights at the desk
require_once __DIR__ . '/till.php';   // shp_till_stands, shp_till_first_name

/** Posts open to this volunteer now, in the selector's order: [['key', 'label', 'url']]. */
function shp_staff_posts($staff)
{
    $s = shp_staff_row($staff);
    if (!$s) return array();
    $tour = intval($s->SfTournament);
    $out = array();
    if (shp_till_open($tour, $s)) {
        foreach (shp_till_stands($s) as $st) {
            $out[] = array('key' => 'stand-' . $st['id'], 'label' => $st['name'], 'url' => shp_url('staff/index.php?s=' . $st['id']));
        }
    }
    if (shp_desk_can($s, 'reg') || shp_desk_can($s, 'pay')) {
        $out[] = array('key' => 'reg', 'label' => shp_t('DkMenu'), 'url' => shp_url('desk/index.php?v=reg'));
    }
    if (shp_desk_can($s, 'equip')) {
        $out[] = array('key' => 'equip', 'label' => shp_t('DkPostJudges'), 'url' => shp_url('desk/index.php?v=equip'));
    }
    return $out;
}

/** Address of the first open post of this volunteer, or '' when none is open. */
function shp_staff_first_post($staff)
{
    $p = shp_staff_posts($staff);
    return $p ? $p[0]['url'] : '';
}

/**
 * What the top bar of a volunteer page needs: the posts, the current one ('stand-<id>', 'reg',
 * 'equip'), the name and the menu behind it — back to the licensee space for a licensee, to the
 * organiser's page for an organiser —, the addresses to sign out and in again, and the licensee
 * space (offered when the access is lost).
 */
function shp_staff_bar_cfg($staff, $current)
{
    $s = shp_staff_row($staff);
    $tour = intval($s->SfTournament);
    $set = shp_settings($tour);
    $back = $backLabel = '';
    if ($s->SfKind === 'LICENSEE') {
        $back = shp_licensee_space_url();
        $backLabel = shp_t('ShStfMenuArcher');
    } elseif ($s->SfKind === 'ORGANISER') {
        $back = shp_url($current === 'equip' ? 'desk/list.php?v=equip' : ($current === 'reg' ? 'desk/list.php' : 'admin/orders.php'));
        $backLabel = shp_t('ShStfMenuOrg');
    }
    return array(
        'posts' => array_map(function ($p) { return array('key' => $p['key'], 'label' => $p['label'], 'url' => $p['url']); }, shp_staff_posts($s)),
        'post' => (string) $current,
        'name' => shp_till_first_name($s), 'full' => shp_staff_label(intval($s->SfId)), 'tour' => shp_staff_tour_name($tour),
        'back' => $back, 'back_label' => $backLabel,
        // Signing out leaves the volunteer space AND the licensee space (staff/api/logout.php), then
        // the volunteers' sign-in page: nobody is let in again by themselves (user's choice).
        'logout' => shp_url('staff/api/logout.php'),
        'login' => $set ? shp_url('staff/login.php?k=' . rawurlencode((string) $set->SgPublicKey)) : '',
        'archer' => shp_licensee_space_url(),
    );
}

/**
 * Competitions where this archer (licensee-space account: BaId, BaLicence) is in the team with at
 * least one post open to them now, until the volunteers' data are erased the day after the
 * competition — the quick access of the licensee space (banner, home page, staff/enter.php). Rows
 * added by the organiser before the account was linked count (by the licence). Sorted by date:
 * [['tour', 'name', 'from', 'to', 'staff' (ShopStaff row), 'first' (address of the first post)]].
 */
function shp_archer_volunteer_tours($archer)
{
    if (intval($archer->BaId ?? 0) <= 0) return array();
    shp_schema();
    $rs = safe_r_sql("SELECT ShopStaff.*, ToName, ToWhenFrom, ToWhenTo FROM ShopStaff
        INNER JOIN Tournament ON ToId = SfTournament
        WHERE SfKind = 'LICENSEE' AND " . shp_staff_archer_sql($archer) . " AND SfStatus = 'active'
        ORDER BY SfId DESC");
    $out = $seen = array();
    while ($r = safe_fetch($rs)) {
        $tour = intval($r->SfTournament);
        if (isset($seen[$tour])) continue;   // the latest membership of a competition counts
        $seen[$tour] = true;
        $w = shp_window($tour);
        if (!$w || $w['purge_due']) continue;
        $posts = shp_staff_posts($r);
        if (!$posts) continue;
        $out[] = array('tour' => $tour, 'name' => (string) $r->ToName, 'from' => (string) $r->ToWhenFrom,
            'to' => (string) $r->ToWhenTo, 'staff' => $r, 'first' => $posts[0]['url']);
    }
    usort($out, function ($a, $b) { return strcmp($a['from'], $b['from']) ?: strcasecmp($a['name'], $b['name']); });
    return $out;
}

/**
 * Page "no post is open for you on this competition right now", with the way back: the archer
 * space for a licensee, signing out for anybody (the next volunteer may take the phone).
 */
function shp_staff_no_post_page($staff)
{
    $s = shp_staff_row($staff);
    $title = shp_t('ShStfSpaceTitle');
    shp_head($title, array('layout' => 'card'));
    $bar = $s ? shp_staff_bar_cfg($s, '') : array('back' => '', 'login' => '', 'logout' => shp_url('staff/api/logout.php'));
    echo '  <main class="shp-main"><div class="shp-card"><h1>' . shp_e($title) . '</h1>'
        . ($s ? '<p class="shp-muted">' . shp_e(shp_staff_tour_name(intval($s->SfTournament))) . '</p>' : '')
        . shp_msg('info', shp_t('ShStfNoPost'))
        . ($bar['back'] !== '' && $s && $s->SfKind === 'LICENSEE'
            ? '<p><a class="shp-btn shp-btn-primary shp-btn-block" href="' . shp_e($bar['back']) . '">' . shp_e(shp_t('ShStfBackArcher')) . '</a></p>' : '')
        . '<p><button type="button" class="shp-btn shp-btn-block" data-shp-logout="' . shp_e($bar['logout']) . '" data-to="'
        . shp_e($bar['login'] !== '' ? $bar['login'] : $bar['back']) . '">' . shp_e(shp_t('ShPosQuit')) . '</button></p>'
        . "</div></main>\n";
    shp_foot();
    exit;
}
