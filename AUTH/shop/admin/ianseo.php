<?php
/**
 * admin/ianseo.php — ianseo's own screens opened to the volunteers of the open competition
 * (organiser; AUTH/vol-lib.php for the rules). Same idea as the check-in desk (desk/list.php):
 *   - the one switch: the screens opened to the volunteers (ShopSettings.SgIanseo), then the
 *     address of the volunteers' sign-in for their computer (they reach ianseo from their space);
 *   - what each group of screens opens, named as ianseo's menu names them — the groups are given
 *     to each volunteer on the Volunteers page, with the other rights;
 *   - the volunteers holding groups, and when they were last seen;
 *   - the screens a volunteer asked for and was refused (journal of the team, event 'blocked'):
 *     what a group may be missing.
 *
 * ianseo look and ACL of the core, frame shared by AUTH's organiser pages (adm-lib.php): reading
 * with participants read-only, switching with read-write.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

require_once dirname(__DIR__) . '/lib/staff-session.php';
require_once dirname(__DIR__) . '/lib/purge.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';
require_once dirname(__DIR__, 2) . '/booking/lib/ui.php';   // bk_date_time
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_qr_svg, vol-lib.php

shp_schema();
shp_purge_due();

$TOUR = intval($_SESSION['TourId']);
$RW = hasFullACL(AclParticipants, 'pEntries', AclReadWrite);
$SELF = shp_url('admin/ianseo.php');
$available = aut_vol_available();

$errors = array();
if ($RW && !shp_impersonating() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!bk_csrf_check()) {
        $errors[] = shp_t('ShStfErrSession');
    } elseif ($act === 'on' || $act === 'off') {
        shp_settings_save($TOUR, array('ianseo' => $act === 'on'));
        header('Location: ' . $SELF);
        exit;
    }
}

$settings = shp_settings($TOUR);
$on = $settings && intval($settings->SgIanseo ?? 0) === 1;

/* ---- Page ---- */
$JS_SCRIPT = aut_adm_assets(array('<style>#shpadm.ia .ia-groups td { vertical-align: top; }'
    . ' #shpadm.ia .ia-groups td:first-child { width: 30%; } #shpadm.ia .ia-groups ul { margin: 0; padding-left: 18px; }</style>'));
$PAGE_TITLE = shp_t('IanMenu');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$html = aut_adm_open('shop/admin/ianseo.php', shp_t('IanPageTitle'), '', 'ia', shp_e(shp_t('IanPageLead')));
foreach ($errors as $e) $html .= shp_adm_msg('err', $e);
if (!$available) $html .= shp_adm_msg('warn', shp_t('IanErrDeploy'));

// The switch, and the address of the volunteers' sign-in once open.
list($qS, $hS) = aut_adm_help(shp_e(shp_t('IanSwitchHint')));
$html .= '<div class="sa-card">' . aut_adm_title('h2', shp_t('ShSetSectSwitch'))
    . '<div class="sa-switch' . ($on && $available ? ' sa-on' : '') . '"><div><b>' . shp_e(shp_t('IanSwitch')) . '</b>' . $qS . $hS . '</div>'
    . '<span class="sa-tag">' . shp_e(shp_t($on && $available ? 'DkOn' : 'DkOff')) . '</span></div>';
if ($RW && !shp_impersonating()) {
    $html .= '<div class="sa-bar"><form method="post" action="' . shp_e($SELF) . '">' . bk_csrf_field()
        . '<input type="hidden" name="act" value="' . ($on ? 'off' : 'on') . '">'
        . '<button type="submit" class="sa-btn' . ($on ? '' : ' sa-primary') . '">' . shp_e(shp_t($on ? 'DkTurnOff' : 'DkTurnOn')) . '</button></form>'
        . '<a class="sa-btn" href="' . shp_e(shp_url('admin/staff.php')) . '">' . shp_e(shp_t('DkVolunteers')) . '</a></div>';
}
if ($on && $available && $settings) {
    $url = shp_abs_url('staff/login.php?k=' . rawurlencode((string) $settings->SgPublicKey));
    $html .= '<details class="sa-access"><summary>' . shp_e(shp_t('IanAddressTitle')) . '</summary><div class="sa-address">'
        . '<div class="sa-qr">' . aut_qr_svg($url, 180) . '</div><div class="sa-address-text"><p>' . shp_e(shp_t('IanAddressHint')) . '</p>'
        . '<input type="text" class="sa-url" readonly value="' . shp_e($url) . '" onclick="this.select()"></div></div></details>';
}
$html .= '</div>';

// What each group opens, as ianseo's menu names it.
$screen = function ($sc) {
    $t = function ($x) { return get_text($x[0], $x[1]); };
    return '<li>' . shp_e($t($sc[0])) . ' › ' . shp_e($t($sc[1])) . '</li>';
};
$cat = aut_vol_catalogue();
$rows = '<tr><td><b>' . shp_e(shp_t('IanGrpBase')) . '</b><br><small class="sa-muted">' . shp_e(shp_t('IanGrpBaseHint')) . '</small></td><td><ul>';
foreach ($cat['base']['screens'] as $sc) $rows .= $screen($sc);
$rows .= '</ul></td></tr>';
foreach (aut_vol_group_keys() as $g) {
    $rows .= '<tr><td><b>' . shp_e(shp_ianseo_label($g)) . '</b></td><td><ul>';
    foreach ($cat[$g]['screens'] as $sc) $rows .= $screen($sc);
    if ($g === 'print') $rows .= '<li>' . shp_e(shp_t('IanScrOtherShoots')) . '</li>';
    $rows .= '</ul></td></tr>';
}
$presets = array();
foreach (array('speaker', 'results') as $p) {
    $groups = array_map('shp_ianseo_label', explode(',', shp_staff_presets()[$p]['ianseo']));
    $presets[] = shp_t('IanPresetIs', array('access' => shp_staff_preset_label($p), 'groups' => implode(', ', $groups)));
}
$html .= '<div class="sa-card">' . aut_adm_title('h2', shp_t('IanGroupsTitle'), shp_e(shp_t('IanGroupsHint')))
    . '<div class="sa-scroll"><table class="sa-table ia-groups"><thead><tr><th>' . shp_e(shp_t('IanGroup')) . '</th><th>'
    . shp_e(shp_t('IanScreens')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
    . '<p class="sa-muted">' . shp_e(implode(' · ', $presets)) . '</p></div>';

// The volunteers holding groups.
$team = array_filter(shp_staff_team($TOUR), function ($m) { return $m['ianseo'] && $m['status'] !== 'revoked'; });
$html .= '<div class="sa-card">' . aut_adm_title('h2', shp_t('IanTeamTitle'));
if (!$team) {
    $html .= '<p class="sa-muted">' . shp_e(shp_t('IanTeamNone')) . '</p>';
} else {
    $html .= '<div class="sa-scroll"><table class="sa-table"><thead><tr><th>' . shp_e(shp_t('ShStfJournalWho')) . '</th><th>'
        . shp_e(shp_t('IanRightsShort')) . '</th><th>' . shp_e(shp_t('IanSeen')) . '</th></tr></thead><tbody>';
    foreach ($team as $m) {
        $seen = $m['idle'] === null ? shp_t('ShStfSeenNever') : ($m['idle'] < 1 ? shp_t('ShStfAgoNow') : shp_t('ShStfAgo', $m['idle']));
        $html .= '<tr><td><b>' . shp_e($m['name']) . '</b> <small class="sa-muted">' . shp_e($m['kind_label']) . '</small></td><td>'
            . shp_e(implode(', ', array_map('shp_ianseo_label', $m['ianseo']))) . '</td><td>' . shp_e($seen) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= '</div>';

// Screens refused to a volunteer (latest first, in the competition's local time).
$rs = safe_r_sql("SELECT SjStaff, SjDetail,
        DATE_ADD(SjWhen, INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND) AS SjLocal
    FROM ShopStaffLog WHERE SjTournament = $TOUR AND SjEvent = 'blocked' ORDER BY SjId DESC LIMIT 100");
$lines = '';
while ($r = safe_fetch($rs)) {
    $lines .= '<tr><td>' . shp_e(bk_date_time($r->SjLocal)) . '</td><td>' . shp_e(shp_staff_label(intval($r->SjStaff))) . '</td><td><code>'
        . shp_e($r->SjDetail) . '</code></td></tr>';
}
$html .= '<details class="sa-card"><summary>' . shp_e(shp_t('IanBlockedTitle')) . '</summary><p class="sa-muted">' . shp_e(shp_t('IanBlockedHint')) . '</p>'
    . ($lines === '' ? '<p class="sa-muted">' . shp_e(shp_t('IanBlockedNone')) . '</p>'
        : '<div class="sa-scroll"><table class="sa-table"><thead><tr><th>' . shp_e(shp_t('ShStfJournalWhen')) . '</th><th>'
            . shp_e(shp_t('ShStfJournalWho')) . '</th><th>' . shp_e(shp_t('IanBlockedPage')) . '</th></tr></thead><tbody>' . $lines
            . '</tbody></table></div>')
    . '</details>';

$html .= aut_adm_close();
echo $html;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
