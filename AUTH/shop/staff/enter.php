<?php
/**
 * staff/enter.php[?t=<competition>] — the way from the archer space into the volunteer space
 * (banner, home page): opens the volunteer session of the signed-in licensee on that competition
 * and sends them to their first open post. A volunteer with an account no longer needs the
 * organiser's address (user's choice, 2026-10-12). Without t: the only competition, or the choice
 * among those where a post is open to them; with none, a page says so.
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/posts.php';

$t = intval($_GET['t'] ?? 0);
$archer = bk_current_archer();
if (!$archer) {
    // To the licensee sign-in, then back here (bk_next_after_login accepts this exact page).
    $_SESSION['BK_NEXT'] = array('page' => 'shop/staff/enter.php' . ($t > 0 ? '?t=' . $t : ''), 'at' => time());
    header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp');
    exit;
}
$title = shp_t('ShStfSpaceTitle');
if (shp_impersonating()) shp_page_message($title, shp_t('ShErrReadOnly'));

$tours = shp_archer_volunteer_tours($archer);
if ($t <= 0 && count($tours) === 1) $t = $tours[0]['tour'];
foreach ($tours as $v) {
    if ($v['tour'] !== $t) continue;
    // The session of this phone is kept when it already is this volunteer's.
    $st = shp_staff_session_state();
    shp_staff_attach_archer($v['staff'], $archer);
    if (!($st['staff'] && intval($st['staff']->SfId) === intval($v['staff']->SfId) && $st['reason'] === 'ok')) {
        shp_staff_session_open(intval($v['staff']->SfId));
    }
    header('Location: ' . $v['first']);
    exit;
}

$back = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/index.php';
shp_head($title, array('layout' => 'card'));
$html = '  <main class="shp-main"><div class="shp-card"><h1>' . shp_e($title) . '</h1>';
if ($tours) {
    $html .= '<p>' . shp_e(shp_t('ShStfChoose')) . '</p><div class="shp-stack">';
    foreach ($tours as $v) {
        $dates = bk_date_fr($v['from']) . ($v['to'] !== $v['from'] ? ' – ' . bk_date_fr($v['to']) : '');
        $html .= '<a class="shp-btn shp-btn-block shp-choice" href="' . shp_e(shp_url('staff/enter.php?t=' . $v['tour'])) . '"><b>'
            . shp_e($v['name']) . '</b><small>' . shp_e($dates) . '</small></a>';
    }
    $html .= '</div>';
} else {
    $html .= shp_msg('info', shp_t('ShStfNoPost'));
}
$html .= '<p><a class="shp-btn shp-btn-block" href="' . shp_e($back) . '">' . shp_e(shp_t('ShStfBackArcher')) . '</a></p>'
    . "</div></main>\n";
echo $html;
shp_foot();
