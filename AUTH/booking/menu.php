<?php
/**
 * menu.php — menu entries of the online registration.
 *
 * WARNING: this file is included by get_which_menu() on EVERY ianseo page. A fatal error here
 * breaks the whole site — hence the guards on every optional call, and no write of any kind.
 *
 * Everything sits under "Modules › Online registration": the module's screens stay together
 * rather than being scattered in the core's menus.
 *
 * The licensee space (public/) does not appear here: it is for the archers, not the organisers.
 * Its address is given on the registration settings page.
 */

if (!function_exists('bk_t')) require_once __DIR__ . '/lib/lang.php';   // functions only, no query

$bkEntries = array();

// Screens of the open competition: for the organiser who manages the participants, not only
// the server administrator.
if (!empty($on) && isset($acl)) {
    // What this competition uses. Shop and payments: open on this server (levels 2-3), or
    // closed with the payments ticked. Survey: open and switched on. Read-only and guarded
    // ($force): the table or a column may not exist yet, then every entry is shown as before.
    $bkShowPay = $bkShowSurvey = true;
    $bkRs = safe_r_sql("SELECT BcPublishLevel, BcPayments, BcSurvey FROM BK_Competitions
        WHERE BcTournament = " . intval($_SESSION['TourId'] ?? 0), false, true);
    if ($bkRs) {
        $bkC = safe_fetch($bkRs);
        $bkLevel = $bkC ? intval($bkC->BcPublishLevel) : 1;
        $bkShowPay = $bkLevel >= 2 || ($bkC && intval($bkC->BcPayments) === 1);
        $bkShowSurvey = $bkLevel >= 2 && intval($bkC->BcSurvey) === 1;
    }
    $bkUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/';
    if (subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite) {
        $bkEntries[] = bk_t('MnuSettings') . '|' . $bkUrl . 'competition.php';
        if ($bkShowPay) {
            $bkEntries[] = bk_t('Shop') . '|' . $bkUrl . 'shop.php';
            $bkEntries[] = bk_t('Payments') . '|' . $bkUrl . 'dues.php';
        }
        $bkEntries[] = bk_t('MnuMandate') . '|' . $bkUrl . 'mandate.php';
    }
    if ($bkShowSurvey && subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadOnly) {
        $bkEntries[] = bk_t('MnuSurvey') . '|' . $bkUrl . 'survey.php';
    }
    if (subFeatureAcl($acl, AclParticipants, 'pTarget') >= AclReadWrite) {
        $bkEntries[] = bk_t('MnuField') . '|' . $bkUrl . 'field.php';
        $bkEntries[] = bk_t('MnuTargets') . '|' . $bkUrl . 'targets.php';
    }
}

if ($bkEntries) {
    // CLICKABLE section title (getSubMenuItem handles "Title|URL") → straight to the registration
    // settings page.
    $ret['MODS']['BOOKING'][] = bk_t('Brand') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';
    foreach ($bkEntries as $bkE) {
        $ret['MODS']['BOOKING'][] = $bkE;
    }
}

unset($bkEntries, $bkE, $bkShowPay, $bkShowSurvey, $bkRs, $bkC, $bkLevel, $bkUrl);
