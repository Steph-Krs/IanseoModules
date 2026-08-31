<?php
/**
 * Module AUTH — Compétitions & partage.
 * Chaque structure (club, CD, CR, FED) voit les compétitions qu'elle possède,
 * celles où elle est invitée et celles partagées avec son niveau.
 * Le PROPRIÉTAIRE d'une compétition gère : le partage montant (CD/CR/FFTA)
 * et la liste des clubs invités (aide à la saisie — plusieurs clubs possibles).
 * ADMIN : voit tout, peut aussi réattribuer le propriétaire.
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
        $msgErr = 'Session expirée : rien n\'a été enregistré, réessayez.';
    } else {
        foreach (($_POST['codes'] ?? array()) as $code) {
            $code = trim($code);
            if ($code === '') continue;
            if (!$isAdmin && aut_code_status($code, $role, $scope) !== 'own') continue;   // pas la sienne

            $cd  = !empty($_POST['cd'][$code])  ? 1 : 0;
            $cr  = !empty($_POST['cr'][$code])  ? 1 : 0;
            $fed = !empty($_POST['fed'][$code]) ? 1 : 0;
            safe_w_sql("INSERT INTO AUT_Share (AsToCode, AsShareCD, AsShareCR, AsShareFED)
                VALUES (" . StrSafe_DB($code) . ", $cd, $cr, $fed)
                ON DUPLICATE KEY UPDATE AsShareCD=$cd, AsShareCR=$cr, AsShareFED=$fed");

            // clubs invités : liste d'agréments séparés par virgules/espaces
            if (isset($_POST['clubs'][$code])) {
                $list = array();
                foreach (preg_split('/[\s,;]+/', trim($_POST['clubs'][$code])) as $c) {
                    if ($c !== '' && preg_match('/^[0-9A-Za-z]{5,12}$/', $c)) $list[strtolower($c)] = $c;
                }
                safe_w_sql("DELETE FROM AUT_ShareClub WHERE AscToCode=" . StrSafe_DB($code));
                foreach ($list as $c) {
                    safe_w_sql("INSERT IGNORE INTO AUT_ShareClub (AscToCode, AscScope) VALUES ("
                        . StrSafe_DB($code) . "," . StrSafe_DB($c) . ")");
                }
            }

            // réattribution du propriétaire (admin uniquement) : '', agrément, CD60, CR07, FED
            if ($isAdmin && isset($_POST['owner'][$code])) {
                $oRole = ''; $oScope = '';
                if (aut_parse_owner($_POST['owner'][$code], $oRole, $oScope)) {
                    safe_w_sql("UPDATE AUT_Share SET AsOwnerRole=" . StrSafe_DB($oRole)
                        . ", AsOwnerScope=" . StrSafe_DB($oScope) . " WHERE AsToCode=" . StrSafe_DB($code));
                } else {
                    $msgErr = 'Propriétaire invalide pour « ' . htmlspecialchars($code)
                        . ' » (formats : agrément, CD60, CR07, FED, ou vide).';
                }
            }
        }
        if (!$msgErr) $msgOk = 'Enregistré.';
        // les droits de session seront recalculés à la prochaine requête (bootstrap)
    }
}

/* ---- Liste des compétitions visibles ---- */
$where = '1=1';
if (!$isAdmin) {
    // la session contient déjà exactement les codes accessibles
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
    LEFT JOIN AUT_Share ON AsToCode COLLATE utf8mb4_unicode_ci = ToCode
    LEFT JOIN (SELECT AscToCode, GROUP_CONCAT(AscScope ORDER BY AscScope SEPARATOR ', ') AS Clubs
               FROM AUT_ShareClub GROUP BY AscToCode) sc
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

$PAGE_TITLE = 'Compétitions & partage';
include('Common/Templates/head.php');

if ($hasEditable) {
    echo '<form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save">';
}
?>
<table class="Tabella">
<tr><th class="Title" colspan="9">Compétitions &amp; partage</th></tr>
<?php
if ($msgOk)  echo '<tr><td colspan="9" class="Center" style="background:#e8f4e8; color:#1a5c1a;">' . $msgOk . '</td></tr>';
if ($msgErr) echo '<tr><td colspan="9" class="Center" style="background:#fde8e8; color:#8b1a1a;">' . $msgErr . '</td></tr>';
?>
<tr><td colspan="9" style="font-size:11px;">
    Vous voyez ici les compétitions que vous <b>possédez</b> (créées/importées par votre structure),
    celles où votre club est <b>invité</b> et celles <b>partagées</b> avec votre niveau.
    Sur vos compétitions : les cases CD / CR / FFTA les partagent vers votre comité, votre ligue ou la
    fédération ; le champ <b>Clubs invités</b> donne accès (lecture + écriture, ex. aide à la saisie)
    aux clubs listés — agréments séparés par des virgules, ex. <code>0760171, 0760023</code>.
    Le code d'une nouvelle compétition est libre mais doit être <b>unique</b> sur le serveur.
    <?php if ($isAdmin) echo '<br><b>Admin</b> : champ propriétaire = agrément (club), <code>CD60</code>, <code>CR07</code>, <code>FED</code>, ou vide (non attribuée).'; ?>
</td></tr>
<tr>
    <th class="Title w-10">Code</th>
    <th class="Title w-22">Nom</th>
    <th class="Title w-12">Lieu</th>
    <th class="Title w-12">Dates</th>
    <th class="Title w-9">Propriétaire</th>
    <th class="Title w-6">CD</th>
    <th class="Title w-6">CR</th>
    <th class="Title w-6">FFTA</th>
    <th class="Title w-17">Clubs invités</th>
</tr>
<?php if (!count($rows)) { ?>
<tr><td colspan="9" class="Center">Aucune compétition — créez-en une via le menu Compétition (le code doit être unique sur le serveur).</td></tr>
<?php } ?>
<?php
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
            . htmlspecialchars($ownerLbl) . '" placeholder="agrément"></td>';
    } else {
        print '<td class="Center">' . htmlspecialchars($ownerLbl ?: '—') . ($own ? ' <b>(vous)</b>' : '') . '</td>';
    }
    print '<td class="Center"><input type="checkbox" name="cd[' . $code . ']"' . ($r->AsShareCD ? ' checked' : '') . $dis . '></td>';
    print '<td class="Center"><input type="checkbox" name="cr[' . $code . ']"' . ($r->AsShareCR ? ' checked' : '') . $dis . '></td>';
    print '<td class="Center"><input type="checkbox" name="fed[' . $code . ']"' . ($r->AsShareFED ? ' checked' : '') . $dis . '></td>';
    if ($own) {
        print '<td><input type="text" name="clubs[' . $code . ']" style="width:95%;" value="'
            . htmlspecialchars($r->Clubs ?? '') . '" placeholder="agréments, séparés par virgules"></td>';
    } else {
        print '<td>' . htmlspecialchars($r->Clubs ?? '') . '</td>';
    }
    print '</tr>';
}
?>
<?php if ($hasEditable) { ?>
<tr><td colspan="9" class="Center"><button type="submit">Enregistrer</button></td></tr>
<?php } ?>
<tr><td colspan="9" style="font-size:10px; color:#667;">
    Note : la case <b>CD</b> partage une compétition de club vers son comité départemental ; la case
    <b>CR</b> partage une compétition de club <i>ou de CD</i> vers son comité régional (rattachement par
    l'arborescence FFTA). Pour une compétition portée par un CR ou la fédération, utilisez la case
    <b>FFTA</b> et/ou les <b>clubs invités</b>.
</td></tr>
</table>
<?php
if ($hasEditable) echo '</form>';
include('Common/Templates/tail.php');
