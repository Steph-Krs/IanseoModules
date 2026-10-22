<?php
/**
 * vol-lib.php — volunteers in ianseo's own screens (1.4.20, user's request of 2026-10-22).
 *
 * A member of a competition's volunteer team (shop/lib/staff.php) may be given, beside the stands
 * and the check-in desk, GROUPS of ianseo screens — printouts, speaker, participants, scores,
 * sending the results — and then works in the normal ianseo look, with the menus of those screens
 * only, without any organiser right on the competition.
 *
 * Three locks, from the coarsest to the finest:
 *  1. The core's rights. The core asks this module for every feature it guards
 *     (dist/BlockFunction.php → aut_acl_* in lib.php → aut_vol_acl_* here): a volunteer gets the
 *     levels of their groups only, the core refuses the rest and hides its menus by itself.
 *  2. A list of scripts, everything else refused (aut_vol_bootstrap, before any code of the page).
 *     Needed because some rights of the core are too broad (checking a match's scorecard by its
 *     barcode needs the right to write the finals, which also opens their setup) and because some
 *     scripts of the core check no right at all (Tournament/PDFAward.php, Language/index.php…).
 *  3. The menu: an entry the list refuses is taken out — on the server for what is built before
 *     this module's menu.php, in the browser for the modules that add theirs after it.
 *
 * The session: AUTH_User = 'vol:<SfId>' (':' never occurs in an account name) and AUTH_Pwd = a
 * token whose hash is a row of ShopStaffSessions — the only two keys the core keeps when it opens
 * a competition. Checked at every request against the volunteer's row (shp_staff_core_state):
 * access withdrawn, rights removed, ianseo closed to the volunteers, 12 hours idle or the day after
 * the competition → the next request is refused. The competition is pinned: the volunteer cannot
 * open another one.
 *
 * Loaded by lib.php — functions only, no side effect: the ACL hooks reach it from the very start
 * of every request. Writes only from aut_vol_bootstrap() (last activity, refusals journal).
 */

if (defined('AUT_VOL_LOADED')) return;
define('AUT_VOL_LOADED', true);

define('AUT_VOL_PREFIX', 'vol:');
define('AUT_ROLE_VOL', 'VOL');

/* ------------------------------------------------------------------ */
/* What each group opens                                               */
/* ------------------------------------------------------------------ */

/**
 * The groups, in display order ('base' is given to every volunteer). Each:
 *   acl      levels of the core's features: feature => level (every sub-feature), or
 *            feature => [sub-feature => level] ('' = the feature itself);
 *   paths    scripts that may be requested, from the site root (a trailing / = a whole folder):
 *            the screens AND what they call behind them (forms, AJAX, PDF), found by reading each
 *            screen and its scripts;
 *   screens  the screens, named as the core's menu names them, for the organiser:
 *            [[menu text, module], [entry text, module], path] — texts of the core's language files.
 * More scripts per group without a new version: config.local.json → "vol_paths": {"print": […]}.
 * The ISK-NG screens (field control) will be a group of their own once the apps work online.
 */
function aut_vol_catalogue()
{
    static $c = null;
    if ($c !== null) return $c;
    $ro = AclReadOnly;
    $rw = AclReadWrite;
    $qual = array('MenuLM_Qualification', 'Common');
    $part = array('MenuLM_Participants', 'Common');
    $prnt = array('MenuLM_Printout', 'Common');
    $finI = array('MenuLM_Individual Finals', 'Common');
    $finT = array('MenuLM_Team Finals', 'Common');
    $robin = array('R-Session', 'Tournament');
    $comp = array('MenuLM_Competition', 'Common');
    $send = array('MenuLM_Send to ianseo.net', 'Common');
    return $c = array(
        'base' => array(
            'acl' => array(AclCompetition => array('' => $ro, 'cSchedule' => $ro)),
            // The language of the screens, chosen by each volunteer; installing or updating the
            // language packs stays the administrator's (aut_is_admin_only_script, checked first).
            'paths' => array('/Main.php', '/noAccess.php', '/credits.php', '/Language/index.php',
                '/Scheduler/index.php', '/Scheduler/PrnScheduler.php', '/Scheduler/OrisSchedule.php'),
            'screens' => array(
                array($comp, array('MenuLM_View Competition Info', 'Common'), '/Main.php'),
                array($comp, array('MenuLM_Scheduling', 'Common'), '/Scheduler/index.php'),
                array($comp, array('MenuLM_Select Language', 'Common'), '/Language/index.php'),
            ),
        ),
        'print' => array(
            'acl' => array(
                AclParticipants => array('pEntries' => $ro),
                AclQualification => $ro, AclIndividuals => $ro, AclTeams => $ro, AclRobin => $ro,
                // Final/ChangeEvent.php (events of a scorecard page) also asks for the TV output.
                AclOutput => array('outTv' => $ro),
                // cData: the French set adds its menu (other shoots, beursault scorecards) only with it.
                AclCompetition => array('cAward' => $ro, 'cPrintouts' => $rw, 'cData' => $ro),
            ),
            'paths' => array(
                '/Partecipants/PrintOut.php', '/Partecipants/OrisAlphabetical.php', '/Partecipants/OrisCountry.php',
                '/Partecipants/OrisListCountry.php', '/Partecipants/OrisStartList.php', '/Partecipants/OrisTeamList.php',
                '/Partecipants/PrnAlphabetical.php', '/Partecipants/PrnCategory.php', '/Partecipants/PrnCountry.php',
                '/Partecipants/PrnCountryDetails.php', '/Partecipants/PrnErrors.php', '/Partecipants/PrnSession.php',
                '/Partecipants/PrintOutStat.php', '/Partecipants/OrisStatCountry.php', '/Partecipants/OrisStatEvents.php',
                '/Partecipants/OrisStatRecBroken.php', '/Partecipants/OrisStatRecStanding.php', '/Partecipants/PrnBirthday.php',
                '/Partecipants/PrnStatClasses.php', '/Partecipants/PrnStatCountry.php', '/Partecipants/PrnStatEvents.php',
                '/Qualification/PrintScore.php', '/Qualification/PDFBigNames.php', '/Qualification/PDFScore.php',
                '/Qualification/PDFScoreCollect.php', '/Qualification/PrnGetScore.php', '/Qualification/PrnLabels.php',
                '/Qualification/SelectSession.php',
                '/Qualification/PrintOut.php', '/Qualification/PrnComplete.php', '/Qualification/PrnIndividual.php',
                '/Qualification/PrnMedalComp.php', '/Qualification/PrnMedalInd.php', '/Qualification/PrnMedalTeam.php',
                '/Qualification/PrnTeam.php',
                '/Qualification/PrintOutAbs.php', '/Qualification/OrisIndividual.php', '/Qualification/OrisTeam.php',
                '/Qualification/PrintIndividual.php', '/Qualification/PrintTeam.php', '/Qualification/PrnCompleteAbs.php',
                '/Qualification/PrnIndividualAbs.php', '/Qualification/PrnShootoff.php', '/Qualification/PrnTeamAbs.php',
                // French rules: other shoots, and the beursault scorecards that replace the core's.
                '/Modules/Sets/FR/Modules/AutresTirs/PrnAutresTirs.php',
                '/Modules/Sets/FR/pdf/PrintScore.php', '/Modules/Sets/FR/pdf/PDFScore.php',
                '/Final/Individual/PrintScore.php', '/Final/Individual/PDFScore.php', '/Final/Individual/PDFScoreMatch.php',
                '/Final/Individual/PrnLabels.php', '/Final/Individual/PrnName.php',
                '/Final/Team/PrintScore.php', '/Final/Team/PDFScore.php', '/Final/Team/PDFScoreMatch.php',
                '/Final/Team/PrnLabels.php', '/Final/Team/PrnName.php',
                '/Final/ChangeEvent.php', '/Final/ChangeEventPhase.php', '/Final/PrintScore-action.php',
                '/Modules/RoundRobin/PrintScore.php', '/Modules/RoundRobin/PrintScore-action.php',
                '/Modules/RoundRobin/PdfScorecards.php',
                '/Final/PrintOut.php', '/Final/PrintOut-getEvents.php', '/Final/OrisCompleteBook.php',
                '/Final/OrisCompleteBookChoose.php', '/Final/OrisMedalList.php', '/Final/OrisMedalStanding.php',
                '/Final/PDFMedalList.php', '/Final/PDFMedalStanding.php',
                '/Final/Individual/OrisBracket.php', '/Final/Individual/OrisIndividual.php', '/Final/Individual/OrisRanking.php',
                '/Final/Individual/PrnBracket.php', '/Final/Individual/PrnIndividual.php', '/Final/Individual/PrnRanking.php',
                '/Final/Team/OrisBracket.php', '/Final/Team/OrisRanking.php', '/Final/Team/OrisTeam.php',
                '/Final/Team/PrnBracket.php', '/Final/Team/PrnRanking.php', '/Final/Team/PrnTeam.php',
                '/Modules/RoundRobin/PrintOut.php', '/Modules/RoundRobin/PdfRobin.php',
                '/Tournament/PDFAward.php', '/Common/Sign/',
            ),
            'screens' => array(
                array($part, array('MenuLM_Printout', 'Common'), '/Partecipants/PrintOut.php'),
                array($part, array('MenuLM_Statistics', 'Common'), '/Partecipants/PrintOutStat.php'),
                array($qual, array('MenuLM_Scorecard Printout', 'Common'), '/Qualification/PrintScore.php'),
                array($qual, array('MenuLM_Category Result List', 'Common'), '/Qualification/PrintOut.php'),
                array($qual, array('MenuLM_Qualification Round', 'Common'), '/Qualification/PrintOutAbs.php'),
                array($finI, array('MenuLM_Scorecard Printout', 'Common'), '/Final/Individual/PrintScore.php'),
                array($finT, array('MenuLM_Scorecard Printout', 'Common'), '/Final/Team/PrintScore.php'),
                array($robin, array('MenuLM_Scorecard Printout', 'Common'), '/Modules/RoundRobin/PrintScore.php'),
                array($prnt, array('MenuLM_Final Rounds', 'Common'), '/Final/PrintOut.php'),
                array($robin, array('MenuLM_Printout', 'Common'), '/Modules/RoundRobin/PrintOut.php'),
                array($prnt, array('MenuLM_PrintAwards', 'Common'), '/Tournament/PDFAward.php'),
                array($prnt, array('MenuLM_Print Sign', 'Common'), '/Common/Sign/'),
            ),
        ),
        'speaker' => array(
            'acl' => array(AclSpeaker => $ro),
            'paths' => array('/Modules/Speaker/'),
            'screens' => array(
                array($part, array('MenuLM_Speaker', 'Common'), '/Modules/Speaker/participants.php'),
                array($qual, array('MenuLM_Speaker', 'Common'), '/Modules/Speaker/qualification.php'),
                array($finI, array('MenuLM_Speaker', 'Common'), '/Modules/Speaker/index.php'),
                array($robin, array('MenuLM_Speaker', 'Common'), '/Modules/Speaker/robin.php'),
            ),
        ),
        'entries' => array(
            'acl' => array(AclParticipants => array('pEntries' => $rw)),
            'paths' => array(
                '/Partecipants/index.php', '/Partecipants/PopEdit.php', '/Partecipants/DeleteRow.php',
                '/Partecipants/Set-UpdateField.php', '/Partecipants/Get-AgeClasses.php', '/Partecipants/Get-Classes.php',
                '/Partecipants/Get-Divisions.php', '/Partecipants/Get-Subclasses.php', '/Partecipants/CheckCtrlCode.php',
                '/Partecipants/CheckSession.php', '/Partecipants/CheckTargetNo.php', '/Partecipants/GetClassesByGender.php',
                '/Partecipants/HtmlFindArchers.php', '/Partecipants/Matr_FindOnEdit.php', '/Partecipants/SelectAgeClass.php',
                '/Partecipants/SelectCountryCode.php',
            ),
            'screens' => array(
                array($part, array('MenuLM_List (Simple)', 'Common'), '/Partecipants/index.php'),
            ),
        ),
        'scores' => array(
            'acl' => array(
                AclQualification => $rw, AclIndividuals => $rw, AclTeams => $rw, AclRobin => $rw,
                AclOutput => array('outTv' => $ro),
            ),
            'paths' => array(
                '/Qualification/index.php', '/Qualification/UpdateQuals.php', '/Qualification/index-action.php',
                '/Qualification/CalcRank.php', '/Qualification/MakeSnapshot.php', '/Qualification/MakeTeams.php',
                '/Qualification/MakeTeamsAbs.php', '/Qualification/SelectSession.php', '/Qualification/Went2Home.php',
                '/Qualification/WriteScoreCard.php', '/Qualification/UpdateArrow.php',
                '/Final/Individual/InsertPoint_Bra.php', '/Final/Individual/WriteScore_Bra.php',
                '/Final/Individual/InsertPoint1.php', '/Final/Individual/InsertPoint2.php',
                '/Final/Team/InsertPoint_Bra.php', '/Final/Team/WriteScore_Bra.php',
                '/Final/Team/InsertPoint1.php', '/Final/Team/InsertPoint2.php',
                '/Final/ChangeEvent.php', '/Final/ChangeEventPhase.php', '/Final/Move2NextPhase.php', '/Final/PrintScore-action.php',
                '/Modules/RoundRobin/InsertPoint.php', '/Modules/RoundRobin/InsertPoint-action.php',
                '/Modules/Barcodes/GetScoreBarCode.php', '/Modules/Barcodes/GetFinScoreBarCode.php',
                '/Modules/Barcodes/GetRobinScoreBarCode.php', '/Modules/Barcodes/GetScoreBarCodeMissing.php',
                '/Modules/Barcodes/GetScoreBarCodeReport.php',
            ),
            'screens' => array(
                array($qual, array('MenuLM_Standard Table', 'Common'), '/Qualification/index.php'),
                array($qual, array('MenuLM_GetScoreBarcode', 'Common'), '/Modules/Barcodes/GetScoreBarCode.php'),
                array($finI, array('MenuLM_Data insert (Bracket view)', 'Common'), '/Final/Individual/InsertPoint_Bra.php'),
                array($finI, array('MenuLM_Data insert (Table view)', 'Common'), '/Final/Individual/InsertPoint1.php'),
                array($finT, array('MenuLM_Data insert (Bracket view)', 'Common'), '/Final/Team/InsertPoint_Bra.php'),
                array($finT, array('MenuLM_Data insert (Table view)', 'Common'), '/Final/Team/InsertPoint1.php'),
                array($robin, array('MenuLM_Data insert (Table view)', 'Common'), '/Modules/RoundRobin/InsertPoint.php'),
                array($finI, array('MenuLM_Input Score', 'Common'), '/Modules/Barcodes/GetFinScoreBarCode.php'),
                array($robin, array('MenuLM_GetScoreBarcode', 'Common'), '/Modules/Barcodes/GetRobinScoreBarCode.php'),
            ),
        ),
        'publish' => array(
            'acl' => array(AclInternetPublish => array('ipSend' => $rw)),
            'paths' => array('/Tournament/UploadResults.php', '/Tournament/UploadResults-status.php',
                '/Tournament/UploadResults-upload.php'),
            'screens' => array(
                array($send, array('MenuLM_Send', 'Common'), '/Tournament/UploadResults.php'),
            ),
        ),
    );
}

/** The groups an organiser may give, in display order. */
function aut_vol_group_keys()
{
    return array_values(array_diff(array_keys(aut_vol_catalogue()), array('base')));
}

/** Groups (array or comma list in) → comma list in the catalogue's order, unknown ones dropped. */
function aut_vol_groups_clean($groups)
{
    $in = is_array($groups) ? $groups : explode(',', (string) $groups);
    return implode(',', array_values(array_intersect(aut_vol_group_keys(), array_map('trim', $in))));
}

/**
 * Can this server let volunteers into ianseo's screens? The account system must be on, and the
 * deployed ACL hooks must be those that ask this module (AUT_ACL_HOOKS, dist/BlockFunction.php):
 * an older deployment would give a volunteer an organiser's rights on the listed screens.
 */
function aut_vol_available()
{
    global $CFG;
    return !empty($CFG->USERAUTH) && defined('AUT_ACL_HOOKS');
}

/* ------------------------------------------------------------------ */
/* Session                                                             */
/* ------------------------------------------------------------------ */

/** Is this PHP session a volunteer's (valid or not)? */
function aut_vol_session()
{
    return strpos((string) ($_SESSION['AUTH_User'] ?? ''), AUT_VOL_PREFIX) === 0;
}

/**
 * The volunteer applied to this request by the bootstrap: ['id', 'tour', 'code', 'groups', 'name',
 * 'key'], or null. Only what the bootstrap wrote for this very session counts.
 */
function aut_vol_current()
{
    if (!aut_vol_session()) return null;
    $v = $_SESSION['AUTH_VOL'] ?? null;
    if (!is_array($v) || AUT_VOL_PREFIX . intval($v['id'] ?? 0) !== $_SESSION['AUTH_User']) return null;
    return $v;
}

/** Takes the volunteer's keys out of the PHP session (the rest of it stays: licensee space…). */
function aut_vol_forget()
{
    foreach (array('AUTH_User', 'AUTH_Pwd', 'AUTH_VOL', 'AUTH_VOL_BLK', 'AUTH_ROOT', 'AUTH_ROLE', 'AUTH_SCOPE',
        'AUTH_VIEWS', 'AUTH_SSO', 'AUTH_IMPERSONATE', 'AUTH_RO') as $k) unset($_SESSION[$k]);
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_COMP'] = array();
}

/** A page (not a script fetched by a page): where a refusal may send the browser. */
function aut_vol_wants_page()
{
    // bytes: HTTP method names are ASCII
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return false;
    if ((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '') return false;
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return $accept === '' || stripos($accept, 'text/html') !== false;
}

/** Address of the volunteer's own page in ianseo (shop/staff/ianseo.php), with its query. */
function aut_vol_page_url($query = '')
{
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/staff/ianseo.php' . ($query !== '' ? '?' . $query : '');
}

/** Ends the request: the browser to $url for a page, a bare status for a script. */
function aut_vol_stop($url, $http)
{
    if (aut_vol_wants_page()) {
        CD_redirect($url);
    } elseif (!headers_sent()) {
        http_response_code(intval($http));
    }
    die();
}

/**
 * Bootstrap of a volunteer's request (aut_request_bootstrap, before any code of the page). The
 * session no longer valid: its keys are dropped and the browser is told why (shop/staff/ianseo.php).
 * Valid: the session keys are set for the core (one competition, no root), the competition is put
 * back when another one is open, and a script out of the volunteer's list is refused. Returns only
 * when the request may go on.
 */
function aut_vol_bootstrap()
{
    global $CFG;
    $id = intval(substr((string) $_SESSION['AUTH_User'], strlen(AUT_VOL_PREFIX)));   // bytes: ASCII prefix and digits
    if (!aut_vol_available()) {
        aut_vol_forget();
        aut_vol_stop(aut_vol_page_url('end=deploy'), 503);
    }
    require_once __DIR__ . '/shop/lib/staff-session.php';
    $st = $_SESSION['AUTH_User'] === AUT_VOL_PREFIX . $id
        ? shp_staff_core_state($id, (string) ($_SESSION['AUTH_Pwd'] ?? ''))
        : array('staff' => null, 'reason' => 'signed_out');
    if (!$st['staff']) {
        aut_vol_forget();
        aut_vol_stop(aut_vol_page_url('end=' . rawurlencode($st['reason'])), 401);
    }
    $s = $st['staff'];
    $tour = intval($s->SfTournament);
    $set = shp_settings($tour);

    $_SESSION['AUTH_ENABLE'] = 1;
    foreach (array('AUTH_ROOT', 'AUTH_SSO', 'AUTH_IMPERSONATE', 'AUTH_RO') as $k) unset($_SESSION[$k]);
    $_SESSION['AUTH_ROLE']  = AUT_ROLE_VOL;
    $_SESSION['AUTH_SCOPE'] = '';
    $_SESSION['AUTH_VIEWS'] = array();
    $_SESSION['AUTH_COMP']  = array((string) $st['code']);
    $_SESSION['AUTH_VOL']   = array('id' => intval($s->SfId), 'tour' => $tour, 'code' => (string) $st['code'],
        'groups' => $st['groups'], 'name' => shp_staff_label(intval($s->SfId)), 'key' => $set ? (string) $set->SgPublicKey : '');

    $script = aut_script_rel();
    // The volunteer's competition, and no other: the core opens it (Common/TourOn.php, which also
    // refuses any other through AUTH_COMP) whenever another one, or none, is open in this session —
    // then comes back to the page asked for.
    if (strcasecmp($script, '/Common/TourOn.php') === 0 && intval($_REQUEST['ToId'] ?? 0) === $tour) return;
    if (intval($_SESSION['TourId'] ?? 0) !== $tour) {
        $back = (string) ($_SERVER['REQUEST_URI'] ?? '');
        aut_vol_stop($CFG->ROOT_DIR . 'Common/TourOn.php?ToId=' . $tour
            . ($back !== '' && $back[0] === '/' ? '&BackTo=' . rawurlencode($back) : ''), 409);
    }
    if ($script === '/' || strcasecmp($script, '/index.php') === 0) {
        CD_redirect($CFG->ROOT_DIR . 'Main.php');
        die();
    }
    if (!aut_is_admin_only_script() && aut_vol_path_allowed($script, $st['groups'])) return;

    // Refused: written once per script and session in the competition's journal of the team, so
    // that the organiser sees what was missing (shop/admin/ianseo.php). Sending the results without
    // the online codes leads the core to their setup: said as such, it is the organiser's job.
    $seen = $_SESSION['AUTH_VOL_BLK'] ?? array();
    if (!isset($seen[$script]) && count($seen) < 200) {
        $seen[$script] = 1;
        $_SESSION['AUTH_VOL_BLK'] = $seen;
        shp_staff_log($tour, intval($s->SfId), 'blocked', 'volunteer', mb_substr($script, 0, 160));
    }
    aut_vol_stop(aut_vol_page_url('denied=' . (strcasecmp($script, '/Tournament/SetCredentials.php') === 0 ? 'codes' : '1')), 403);
}

/* ------------------------------------------------------------------ */
/* Scripts and menus                                                   */
/* ------------------------------------------------------------------ */

/** Scripts of a group: the catalogue's, and those the administrator added (config.local.json). */
function aut_vol_paths($group)
{
    $p = aut_vol_catalogue()[$group]['paths'] ?? array();
    $more = function_exists('aut_local_config') ? (aut_local_config()['vol_paths'][$group] ?? array()) : array();
    foreach ((array) $more as $x) {
        if (is_string($x) && $x !== '' && $x[0] === '/') $p[] = $x;
    }
    return $p;
}

/** May a volunteer with $groups request this script ($path from the site root)? */
function aut_vol_path_allowed($path, array $groups)
{
    $path = (string) $path;
    foreach (array_merge(array('base'), $groups) as $g) {
        foreach (aut_vol_paths($g) as $p) {
            // bytes: paths, compared as the file system does
            if (substr($p, -1) === '/' ? stripos($path, $p) === 0 : strcasecmp($path, $p) === 0) return true;
        }
    }
    return false;
}

/**
 * The script a link of the menu leads to, from the site root ('/Common/Sign' → '/Common/Sign/index.php'),
 * or '' for a link that leaves the site or is no address.
 */
function aut_vol_url_path($url)
{
    global $CFG;
    $url = (string) $url;
    if ($url === '' || $url[0] === '#' || stripos($url, 'javascript:') === 0) return '';
    $p = parse_url($url);
    if ($p === false || isset($p['host']) || isset($p['scheme'])) return '';
    $path = (string) ($p['path'] ?? '');
    $root = rtrim((string) ($CFG->ROOT_DIR ?? '/'), '/');
    // bytes: a path, compared as the file system does
    if ($root !== '' && stripos($path, $root . '/') === 0) $path = substr($path, strlen($root));
    if ($path === '' || $path[0] !== '/') return '';
    if (substr($path, -1) === '/') $path .= 'index.php';
    elseif (pathinfo($path, PATHINFO_EXTENSION) === '') $path .= '/index.php';
    return $path;
}

/**
 * The menu of ianseo for the volunteer: every entry leading to a script out of their list taken
 * out, then the separators left alone and the sub-menus left empty. $items: a menu as
 * get_which_menu() builds it (first item = title).
 */
function aut_vol_menu_filter(array $items, array $groups)
{
    $div = defined('MENU_DIVIDER') ? MENU_DIVIDER : '---';
    $out = array();
    $first = true;
    foreach ($items as $k => $it) {
        if ($first) { $first = false; $out[$k] = $it; continue; }   // the title
        if (is_array($it)) {
            $sub = aut_vol_menu_filter($it, $groups);
            if (count($sub) > 1) $out[$k] = $sub;
            continue;
        }
        if ($it === $div) { $out[$k] = $it; continue; }
        $parts = explode('|', (string) $it);
        if (count($parts) > 1 && aut_vol_path_allowed(aut_vol_url_path($parts[1]), $groups)) $out[$k] = $it;
    }
    // Separators: never first, last or two in a row.
    $clean = array();
    $prevDiv = true;
    $n = 0;
    foreach ($out as $k => $it) {
        if ($n++ === 0) { $clean[$k] = $it; continue; }   // the title
        $isDiv = $it === $div;
        if ($isDiv && $prevDiv) continue;
        $clean[$k] = $it;
        $prevDiv = $isDiv;
    }
    while (count($clean) > 1 && end($clean) === $div) array_pop($clean);
    return $clean;
}

/** The whole menu ($ret of get_which_menu) for the volunteer applied to this request. */
function aut_vol_menu(array $ret)
{
    $v = aut_vol_current();
    $groups = $v ? $v['groups'] : array();
    // A menu left with its title only stays: a module after this one may still add to it (the
    // core drops the empty ones at the end).
    foreach ($ret as $k => $menu) {
        if (is_array($menu)) $ret[$k] = aut_vol_menu_filter($menu, $groups);
    }
    return $ret;
}

/**
 * The volunteer's bar on ianseo's pages: who, which competition, their volunteer space, sign out —
 * and the script taking out of the menu what the modules after this one added (the list of
 * allowed scripts goes to the page; refusing stays the server's job).
 */
function aut_vol_bar()
{
    global $CFG;
    $v = aut_vol_current();
    if (!$v) return '';
    require_once __DIR__ . '/shop/lib/lang.php';
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $exact = $folders = array();
    foreach (array_merge(array('base'), $v['groups']) as $g) {
        foreach (aut_vol_paths($g) as $p) {
            // bytes: paths
            if (substr($p, -1) === '/') $folders[] = mb_strtolower($p); else $exact[] = mb_strtolower($p);
        }
    }
    $cfg = array('root' => rtrim((string) $CFG->ROOT_DIR, '/'), 'exact' => array_values(array_unique($exact)),
        'folders' => array_values(array_unique($folders)));
    $out = aut_vol_page_url('out=' . aut_vol_out_token());
    // id ending in -bar: the shared convention of the floating bars (the next ones move below it).
    // Folded like the organiser's bar (menu.php): the name only, the rest when pointed at.
    return '<style>#aut-vol-bar { position:fixed; top:4px; right:8px; z-index:99990; background:#1a4f8b; color:#fff;'
        . ' font:11px Verdana,Arial,sans-serif; border-radius:14px; padding:4px 12px; opacity:.92;'
        . ' box-shadow:0 1px 4px rgba(0,0,0,.3); }'
        . ' #aut-vol-bar a { color:#cfe0f5; text-decoration:none; margin-left:10px; }'
        . ' #aut-vol-bar a:hover { color:#fff; text-decoration:underline; }'
        . ' #aut-vol-bar.aut-folded { cursor:pointer; opacity:.8; }'
        . ' #aut-vol-bar.aut-folded .aut-bar-more { display:none; }'
        . ' .WhatIanseoDoes { display:none !important; }</style>'
        . '<div id="aut-vol-bar">' . $e(shp_t('IanBarWho', $v['name'])) . '<span class="aut-bar-more"> — ' . $e(shp_t('IanBarRole'))
        . '<a href="' . $e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/staff/index.php') . '">' . $e(shp_t('ShStfSpaceTitle')) . '</a>'
        . '<a href="' . $e($out) . '" data-confirm="' . $e(shp_t('IanBarOutConfirm')) . '"'
        . ' onclick="return confirm(this.dataset.confirm);">' . $e(shp_t('ShPosQuit')) . '</a></span></div>'
        . '<script>var AUT_VOL=' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . <<<'JS'
<script>
(function () {
    // Folds 2 seconds after the pointer left it (and after the page opened); unfolds when pointed
    // at, focused or touched — as the organiser's bar.
    var b = document.getElementById('aut-vol-bar');
    if (!b) return;
    var t = null;
    function fold() { if (b.matches(':hover') || b.contains(document.activeElement)) { later(); return; } b.classList.add('aut-folded'); }
    function later() { clearTimeout(t); t = setTimeout(fold, 2000); }
    function open() { clearTimeout(t); b.classList.remove('aut-folded'); }
    b.addEventListener('mouseenter', open);
    b.addEventListener('mouseleave', later);
    b.addEventListener('focusin', open);
    b.addEventListener('focusout', later);
    b.addEventListener('click', function () { if (b.classList.contains('aut-folded')) { open(); later(); } });
    later();
})();
document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('navigation'), c = window.AUT_VOL;
    if (!nav || !c) return;
    function allowed(path) {
        path = path.toLowerCase();
        if (c.root && path.indexOf(c.root.toLowerCase() + '/') === 0) path = path.slice(c.root.length);
        if (path.slice(-1) === '/') path += 'index.php';
        else if (path.split('/').pop().indexOf('.') < 0) path += '/index.php';
        if (c.exact.indexOf(path) >= 0) return true;
        return c.folders.some(function (f) { return path.indexOf(f) === 0; });
    }
    function usable(u) { return u && u.origin === location.origin && u.protocol !== 'javascript:' && allowed(u.pathname); }
    function url(h) { try { return new URL(h, location.href); } catch (err) { return null; } }
    // The bars other modules put in the same block (forms, links): one without anything the
    // volunteer may open goes.
    Array.prototype.slice.call(nav.children).forEach(function (el) {
        if (el.tagName === 'UL' || el.id === 'aut-vol-bar') return;
        var targets = Array.prototype.slice.call(el.querySelectorAll('a[href], form[action]'))
            .map(function (x) { return x.getAttribute(x.tagName === 'FORM' ? 'action' : 'href'); })
            .filter(function (h) { return h && h.charAt(0) !== '#'; });
        if (targets.length && !targets.some(function (h) { return usable(url(h)); })) el.remove();
    });
    // The menu itself.
    nav.querySelectorAll(':scope > ul a[href]').forEach(function (a) {
        var h = a.getAttribute('href');
        if (!h || h === '#url' || h.charAt(0) === '#') return;
        var u;
        try { u = new URL(h, location.href); } catch (err) { u = null; }
        if (u && u.protocol === 'javascript:') return;
        if (!u || u.origin !== location.origin || !allowed(u.pathname)) {
            var li = a.closest('li');
            if (li) li.remove(); else a.remove();
        }
    });
    // Sub-menus left without any entry, deepest first, then separators left alone.
    var uls = Array.prototype.slice.call(nav.querySelectorAll(':scope > ul li > ul')).reverse();
    uls.forEach(function (ul) { if (!ul.querySelector('li')) ul.parentNode.remove(); });
    nav.querySelectorAll(':scope > ul, :scope > ul ul').forEach(function (ul) {
        var prevHr = true;
        Array.prototype.slice.call(ul.children).forEach(function (n) {
            var hr = n.tagName === 'HR';
            if (hr && prevHr) n.remove(); else prevHr = hr;
        });
        while (ul.lastElementChild && ul.lastElementChild.tagName === 'HR') ul.lastElementChild.remove();
    });
});
</script>

JS;
}

/** Token of the sign-out link of the bar: tied to this session, so that another site cannot sign the volunteer out. */
function aut_vol_out_token()
{
    // bytes: a digest, cut to a fixed ASCII length
    return substr(hash('sha256', 'vol-out|' . (string) ($_SESSION['AUTH_Pwd'] ?? '')), 0, 24);
}

/* ------------------------------------------------------------------ */
/* Rights given to the core (through aut_acl_* of lib.php)             */
/* ------------------------------------------------------------------ */

/** Levels of a set of groups: [feature => [sub-feature => level, '*' => every sub-feature]]. */
function aut_vol_matrix(array $groups)
{
    static $cache = array();
    $key = implode(',', $groups);
    if (isset($cache[$key])) return $cache[$key];
    $m = array();
    foreach (array_merge(array('base'), $groups) as $g) {
        foreach (aut_vol_catalogue()[$g]['acl'] ?? array() as $f => $l) {
            foreach (is_array($l) ? $l : array('*' => $l) as $sub => $lvl) {
                $m[$f][(string) $sub] = max($m[$f][(string) $sub] ?? AclNoAccess, $lvl);
            }
        }
    }
    // A level given to every sub-feature lifts the ones named apart.
    foreach ($m as $f => $subs) {
        if (!isset($subs['*'])) continue;
        foreach ($subs as $sub => $lvl) $m[$f][$sub] = max($lvl, $subs['*']);
    }
    return $cache[$key] = $m;
}

/** Level of the volunteer of this request on a feature (or the best of several) and a sub-feature. */
function aut_vol_level($feature, $sub = '')
{
    $v = aut_vol_current();
    if (!$v) return AclNoAccess;
    $m = aut_vol_matrix($v['groups']);
    $best = AclNoAccess;
    foreach ((array) $feature as $f) {
        $f = intval($f);
        if (!isset($m[$f])) continue;
        $best = max($best, $m[$f][(string) $sub] ?? ($m[$f]['*'] ?? AclNoAccess));
    }
    return $best;
}

/**
 * The core asks whether the volunteer has $level on $feature/$sub of the competition $toCode:
 * the level they have (an int, a grant) or false (refused — the core then sends to noAccess).
 * Never on another competition than theirs.
 */
function aut_vol_acl_check($feature, $sub, $level, $toCode)
{
    $v = aut_vol_current();
    if (!$v || strcasecmp((string) $toCode, (string) $v['code']) !== 0) return false;
    $have = aut_vol_level($feature, $sub);
    return $have >= intval($level) ? $have : false;
}

/** The core's table of levels per feature (actualACL): the best level of each feature. */
function aut_vol_acl_actual(&$acl)
{
    $v = aut_vol_current();
    $m = $v ? aut_vol_matrix($v['groups']) : array();
    foreach ($acl as $f => $x) $acl[$f] = isset($m[$f]) ? max($m[$f]) : AclNoAccess;
}
