<?php
/**
 * AUTH module — Competitions & sharing.
 * Each structure (club, CD, CR, FED) sees the competitions it owns, those where it is invited
 * and those shared with its level.
 * The OWNER of a competition manages: the upward sharing (CD/CR/FFTA) and the list of invited
 * clubs (help with data entry — several clubs possible).
 * ADMIN: sees everything, can also give the competition another owner.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

aut_ensure_schema();

$authEnforced = !empty($CFG->USERAUTH) && !aut_is_localhost();
if ($authEnforced && empty($_SESSION['AUTH_User'])) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php');
    die();
}

$role    = $authEnforced ? ($_SESSION['AUTH_ROLE'] ?? '') : AUT_ROLE_ADMIN;
$scope   = $authEnforced ? ($_SESSION['AUTH_SCOPE'] ?? '') : '';
$isAdmin = ($role == AUT_ROLE_ADMIN);

$msgOk = '';
$msgErr = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'save') {
    if (!aut_csrf_check()) {
        $msgErr = htmlspecialchars(aut_t('ShSessionExpired'));
    } else {
        foreach (($_POST['codes'] ?? array()) as $code) {
            $code = trim($code);
            if ($code === '') continue;
            if (!$isAdmin && aut_code_status($code, $role, $scope) !== 'own') continue;   // not theirs

            $cd  = !empty($_POST['cd'][$code])  ? 1 : 0;
            $cr  = !empty($_POST['cr'][$code])  ? 1 : 0;
            $fed = !empty($_POST['fed'][$code]) ? 1 : 0;
            safe_w_sql("INSERT INTO AuthShare (AsToCode, AsShareCD, AsShareCR, AsShareFED)
                VALUES (" . StrSafe_DB($code) . ", $cd, $cr, $fed)
                ON DUPLICATE KEY UPDATE AsShareCD=$cd, AsShareCR=$cr, AsShareFED=$fed");

            // invited clubs: list of approval numbers separated by commas/spaces
            if (isset($_POST['clubs'][$code])) {
                $list = array();
                foreach (preg_split('/[\s,;]+/', trim($_POST['clubs'][$code])) as $c) {
                    if ($c !== '' && preg_match('/^[0-9A-Za-z]{5,12}$/', $c)) $list[strtolower($c)] = $c;
                }
                safe_w_sql("DELETE FROM AuthShareClub WHERE AscToCode=" . StrSafe_DB($code));
                foreach ($list as $c) {
                    safe_w_sql("INSERT IGNORE INTO AuthShareClub (AscToCode, AscScope) VALUES ("
                        . StrSafe_DB($code) . "," . StrSafe_DB($c) . ")");
                }
            }

            // new owner (admin only): '', approval number, CD60, CR07, FED
            if ($isAdmin && isset($_POST['owner'][$code])) {
                $oRole = ''; $oScope = '';
                if (aut_parse_owner($_POST['owner'][$code], $oRole, $oScope)) {
                    safe_w_sql("UPDATE AuthShare SET AsOwnerRole=" . StrSafe_DB($oRole)
                        . ", AsOwnerScope=" . StrSafe_DB($oScope) . " WHERE AsToCode=" . StrSafe_DB($code));
                } else {
                    $msgErr = htmlspecialchars(aut_t('ShBadOwner', $code));
                }
            }
        }
        if (!$msgErr) $msgOk = htmlspecialchars(aut_t('ShSaved'));
        // the session rights are computed again at the next request (bootstrap)
    }
}

/* ---- List of the visible competitions ---- */
$where = '1=1';
if (!$isAdmin) {
    // the session already holds exactly the reachable codes
    $codes = array_filter($_SESSION['AUTH_COMP'] ?? array(), function ($c) { return strpos($c, '%') === false; });
    $where = count($codes)
        ? 'ToCode IN (' . implode(',', array_map('StrSafe_DB', $codes)) . ')'
        : '1=0';
}

$rows = array();
$q = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere,
        DATE_FORMAT(ToWhenFrom,'%d/%m/%Y') AS DtFrom, DATE_FORMAT(ToWhenTo,'%d/%m/%Y') AS DtTo,
        AsOwnerRole, AsOwnerScope, AsShareCD, AsShareCR, AsShareFED, sc.Clubs
    FROM Tournament
    LEFT JOIN AuthShare ON AsToCode COLLATE utf8mb4_unicode_ci = ToCode
    LEFT JOIN (SELECT AscToCode, GROUP_CONCAT(AscScope ORDER BY AscScope SEPARATOR ', ') AS Clubs
               FROM AuthShareClub GROUP BY AscToCode) sc
           ON sc.AscToCode COLLATE utf8mb4_unicode_ci = ToCode
    WHERE $where
    ORDER BY ToWhenFrom DESC, ToCode ASC");
while ($r = safe_fetch($q)) $rows[] = $r;

$hasEditable = false;
foreach ($rows as $r) {
    $r->_own = $isAdmin
        || (strcasecmp($r->AsOwnerRole ?? '', $role) === 0 && strcasecmp($r->AsOwnerScope ?? '', $scope) === 0);
    if ($r->_own) $hasEditable = true;
}

$PAGE_TITLE = aut_t('ShTitle');
include('Common/Templates/head.php');

$e = function ($s) { return htmlspecialchars((string) $s); };
if ($hasEditable) {
    echo '<form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save">';
}
echo '<table class="Tabella">' . "\n"
    . '<tr><th class="Title" colspan="9">' . $e(aut_t('ShTitle')) . "</th></tr>\n";
if ($msgOk)  echo '<tr><td colspan="9" class="Center" style="background:#e8f4e8; color:#1a5c1a;">' . $msgOk . "</td></tr>\n";
if ($msgErr) echo '<tr><td colspan="9" class="Center" style="background:#fde8e8; color:#8b1a1a;">' . $msgErr . "</td></tr>\n";
echo '<tr><td colspan="9" style="font-size:11px;">' . aut_t('ShIntro')
    . ($isAdmin ? '<br>' . aut_t('ShIntroAdmin') : '') . "</td></tr>\n"
    . '<tr>'
    . '<th class="Title w-10">' . $e(aut_t('ShColCode')) . '</th>'
    . '<th class="Title w-22">' . $e(aut_t('ShColName')) . '</th>'
    . '<th class="Title w-12">' . $e(aut_t('ShColWhere')) . '</th>'
    . '<th class="Title w-12">' . $e(aut_t('ShColDates')) . '</th>'
    . '<th class="Title w-9">' . $e(aut_t('ShColOwner')) . '</th>'
    . '<th class="Title w-6">CD</th>'
    . '<th class="Title w-6">CR</th>'
    . '<th class="Title w-6">FFTA</th>'
    . '<th class="Title w-17">' . $e(aut_t('ShColInvited')) . '</th>'
    . "</tr>\n";
if (!count($rows)) {
    echo '<tr><td colspan="9" class="Center">' . $e(aut_t('ShNone')) . "</td></tr>\n";
}
foreach ($rows as $r) {
    $code = htmlspecialchars($r->ToCode);
    $own = $r->_own;
    $dis = $own ? '' : ' disabled';
    $ownerLbl = aut_owner_label($r->AsOwnerRole ?? '', $r->AsOwnerScope ?? '');
    print '<tr>';
    print '<td>' . $code . ($own ? '<input type="hidden" name="codes[]" value="' . $code . '">' : '') . '</td>';
    print '<td>' . htmlspecialchars($r->ToName) . '</td>';
    print '<td>' . htmlspecialchars($r->ToWhere ?? '') . '</td>';
    print '<td>' . $r->DtFrom . ' — ' . $r->DtTo . '</td>';
    if ($isAdmin) {
        print '<td class="Center"><input type="text" name="owner[' . $code . ']" size="7" value="'
            . htmlspecialchars($ownerLbl) . '" placeholder="' . $e(aut_t('ShOwnerHint')) . '"></td>';
    } else {
        print '<td class="Center">' . htmlspecialchars($ownerLbl ?: '—') . ($own ? ' <b>(' . $e(aut_t('ShYou')) . ')</b>' : '') . '</td>';
    }
    print '<td class="Center"><input type="checkbox" name="cd[' . $code . ']"' . ($r->AsShareCD ? ' checked' : '') . $dis . '></td>';
    print '<td class="Center"><input type="checkbox" name="cr[' . $code . ']"' . ($r->AsShareCR ? ' checked' : '') . $dis . '></td>';
    print '<td class="Center"><input type="checkbox" name="fed[' . $code . ']"' . ($r->AsShareFED ? ' checked' : '') . $dis . '></td>';
    if ($own) {
        print '<td><input type="text" name="clubs[' . $code . ']" style="width:95%;" value="'
            . htmlspecialchars($r->Clubs ?? '') . '" placeholder="' . $e(aut_t('ShClubsHint')) . '"></td>';
    } else {
        print '<td>' . htmlspecialchars($r->Clubs ?? '') . '</td>';
    }
    print "</tr>\n";
}
if ($hasEditable) {
    echo '<tr><td colspan="9" class="Center"><button type="submit">' . $e(aut_t('ShSave')) . "</button></td></tr>\n";
}
echo '<tr><td colspan="9" style="font-size:10px; color:#667;">' . aut_t('ShNote') . "</td></tr>\n"
    . "</table>\n";
if ($hasEditable) echo '</form>';
include('Common/Templates/tail.php');
