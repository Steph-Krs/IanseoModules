<?php
/**
 * AUTH module — anonymise a licensee across every competition of the server.
 *
 * Server administrator only (same guard as config.php). Search by licence or name, then
 * a preview of everything that will change, then a confirmation by typing the licence
 * again. The work itself and its limits are described in anonymise-lib.php.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/anonymise-lib.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

function aan_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function aan_date($d) { $d = (string) $d; return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "$m[3]/$m[2]/$m[1]" : ''; }
/** One refund on a line: amount, payment method, club code and name. */
function aan_refund($f)
{
    $m = bk_payment_methods()[$f['method']] ?? '';
    return number_format((float) $f['amount'], 2, ',', ' ') . ' €' . ($m !== '' ? ' (' . $m . ')' : '')
        . ' — club ' . trim($f['club_code'] . ' ' . $f['club_name']);
}

$self = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/anonymise.php';
$q    = trim((string) ($_GET['q'] ?? ''));
$lic  = trim((string) ($_GET['lic'] ?? $_POST['lic'] ?? ''));
if (strcasecmp($lic, AUT_ANON_CODE) === 0) $lic = '';   // the shared anonymous code is nobody
$err  = ''; $done = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    if (!aut_csrf_check()) {
        $err = 'Session expirée : rien n\'a été modifié, réessayez.';
    } elseif ($lic === '' || strcasecmp(trim((string) ($_POST['confirm'] ?? '')), $lic) !== 0) {
        $err = 'La licence retapée ne correspond pas : rien n\'a été modifié.';
    } else {
        $done = aut_anon_apply($lic);
        // The journal says that it happened and who did it — never whom it was about.
        aut_log('ANONYMISE', $_SESSION['AUTH_User'] ?? 'local');
    }
}

$PAGE_TITLE = 'Multi-comptes — Anonymiser un licencié';
include('Common/Templates/head.php');

echo '<style>
#aan .hint{font-size:11px;color:#555}
#aan .ok{background:#e8f4e8;color:#1a5c1a} #aan .ko{background:#fde8e8;color:#8b1a1a}
#aan .warn{background:#fff8e1;color:#5b4300}
#aan ul{margin:4px 0 4px 18px}
#aan input[type=text]{width:260px}
</style>';
echo '<div id="aan"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">Anonymiser un licencié</th></tr>';
echo '<tr><td colspan="2" class="hint"><b>Compétitions à venir</b> (pas encore tirées par cette personne) : inscriptions '
    . 'et rôles d\'officiel supprimés ; la place libérée va à la liste d\'attente ; un paiement déjà validé est signalé à '
    . 'l\'organisateur comme remboursement à faire (club et montant, sans nom). <b>Compétitions tirées</b> : licence '
    . 'remplacée par <b>' . aan_h(AUT_ANON_CODE) . '</b>, nom, prénom et noms d\'affichage vidés, date de naissance supprimée '
    . '(la catégorie, enregistrée à l\'inscription, ne change pas), photo, légende et e-mail supprimés, officiels anonymisés '
    . 'de même ; les données sportives restent (scores, classements, matchs, club, catégorie). Partout : compte en ligne '
    . 'supprimé.</td></tr>';

if ($err) echo '<tr><td colspan="2" class="Center ko">' . aan_h($err) . '</td></tr>';

/* ---------------- Result of an anonymisation ---------------- */
if ($done !== null) {
    $doneRm = '';
    foreach ($done['removed'] as $rm) {
        $doneRm .= '<li>Retiré(e) de ' . aan_h($rm['code'] . ' — ' . $rm['name'])
            . ($rm['refund'] ? ' — <b>remboursement signalé à l\'organisateur : ' . aan_h(aan_refund($rm['refund'])) . '</b>' : '') . '</li>';
    }
    echo '<tr><td colspan="2" class="ok"><b>Licence ' . aan_h($lic) . ' anonymisée.</b><ul>' . $doneRm
        . '<li>' . $done['entries'] . ' participation(s) et ' . $done['officials'] . ' rôle(s) d\'officiel anonymisé(s)</li>'
        . '<li>' . $done['photos'] . ' photo(s), ' . $done['extra'] . ' donnée(s) complémentaire(s) supprimée(s)</li>'
        . '<li>' . ($done['account'] ? 'Compte en ligne supprimé' : 'Aucun compte en ligne') . '</li>'
        . ($done['waits'] ? '<li>' . $done['waits'] . ' demande(s) de liste d\'attente supprimée(s)</li>' : '')
        . '</ul></td></tr>';
    if ($done['locked']) {
        echo '<tr><td colspan="2" class="warn">Participants verrouillés par l\'organisateur : inscription <b>anonymisée</b> '
            . 'au lieu d\'être supprimée (aucun remboursement signalé). À voir avec lui :<ul>';
        foreach ($done['locked'] as $lk) {
            echo '<li>' . aan_h($lk['code'] . ' — ' . $lk['name']) . ($lk['refund'] ? ' — paiement validé : ' . aan_h(aan_refund($lk['refund'])) : '') . '</li>';
        }
        echo '</ul></td></tr>';
    }
    if ($done['online']) {
        echo '<tr><td colspan="2" class="warn">Compétitions publiées sur ianseo.net : le nom y reste jusqu\'à leur '
            . 'prochaine publication. À republier depuis chacune d\'elles :<ul>';
        foreach ($done['online'] as $code => $name) echo '<li>' . aan_h($code . ' — ' . $name) . '</li>';
        echo '</ul></td></tr>';
    }
    echo '<tr><td colspan="2" class="hint">Ces résultats ne sont plus rattachés à une licence : un export vers la fédération '
        . 'les enverrait sous « ' . aan_h(AUT_ANON_CODE) . ' ». Restent hors de portée : le fichier fédéral des licences '
        . '(rechargé chaque nuit, c\'est celui de la fédération), les résultats déjà transmis à la fédération, les données '
        . 'des autres modules, et les sauvegardes, qui disparaissent d\'elles-mêmes à la fin de leur durée de conservation. '
        . 'Si ce licencié se reconnecte à l\'espace licencié, un compte est recréé à partir du fichier fédéral.</td></tr>';
    $lic = '';
}

/* ---------------- Search ---------------- */
echo '<tr><td class="Bold" style="width:30%">Rechercher</td><td><form method="get" action="' . aan_h($self) . '">'
    . '<input type="text" name="q" value="' . aan_h($q) . '" placeholder="Licence, nom ou prénom" autofocus> '
    . '<button type="submit">Chercher</button><div class="hint">Licence exacte, ou des mots qui doivent tous figurer '
    . 'dans le nom ou le prénom (3 caractères au moins). Participants, officiels, comptes en ligne et fichier fédéral. '
    . 'Une personne déjà anonymisée n\'apparaît plus.</div>'
    . '</form></td></tr>';

if ($q !== '' && $lic === '') {
    $found = aut_anon_search($q);
    echo '<tr><th class="Title" colspan="2">' . count($found) . ' licence(s) trouvée(s)</th></tr>';
    if (!$found) {
        echo '<tr><td colspan="2" class="hint">Aucun résultat. Une personne inscrite sans numéro de licence ne peut pas '
            . 'être anonymisée ici.</td></tr>';
    }
    foreach ($found as $l => $p) {
        echo '<tr><td><a href="' . aan_h($self . '?lic=' . rawurlencode($l)) . '"><b>' . aan_h($l) . '</b></a></td><td>'
            . aan_h(trim($p['name']) ?: '(nom déjà vide)') . ($p['year'] ? ' — né(e) en ' . aan_h($p['year']) : '')
            . ($p['club'] ? ' — ' . aan_h($p['club']) : '') . '<br><span class="hint">' . $p['entries'] . ' participation(s), '
            . $p['officials'] . ' rôle(s) d\'officiel' . ($p['account'] ? ', compte en ligne' : '') . '</span></td></tr>';
    }
}

/* ---------------- Preview + confirmation ---------------- */
if ($lic !== '') {
    $p = aut_anon_person($lic);
    $who = $p['lue'] ? trim($p['lue']->LueFamilyName . ' ' . $p['lue']->LueName) : '';
    if ($who === '' && $p['entries']) $who = trim($p['entries'][0]->EnFirstName . ' ' . $p['entries'][0]->EnName);
    echo '<tr><th class="Title" colspan="2">Licence ' . aan_h($lic) . ($who !== '' ? ' — ' . aan_h($who) : '') . '</th></tr>';
    if (!$p['entries'] && !$p['officials'] && !$p['account']) {
        echo '<tr><td colspan="2" class="hint">Cette licence n\'apparaît dans aucune compétition du serveur et n\'a pas de '
            . 'compte en ligne : rien à anonymiser.</td></tr>';
    } else {
        $plan = aut_anon_plan($lic);   // competitions to come: removal instead of anonymisation
        if ($p['entries']) {
            echo '<tr><td class="Bold">Participations (' . count($p['entries']) . ')</td><td><ul>';
            foreach ($p['entries'] as $e) {
                echo '<li>' . aan_h($e->ToCode . ' — ' . $e->ToName) . ' (' . aan_h(aan_date($e->ToWhenFrom)) . ') — '
                    . aan_h($e->EnDivision . $e->EnClass) . ' — ' . (isset($plan[intval($e->EnTournament)])
                        ? '<b>à venir : inscription supprimée</b>'
                        : '<span class="hint">« ' . aan_h(trim($e->EnFirstName . ' ' . $e->EnName)) . ' » → vide ; '
                          . (intval($e->EnDob) > 0 ? aan_h(aan_date($e->EnDob)) . ' → supprimée' : 'pas de date') . '</span>')
                    . '</li>';
            }
            echo '</ul></td></tr>';
        }
        if ($p['officials']) {
            echo '<tr><td class="Bold">Officiel (' . count($p['officials']) . ')</td><td><ul>';
            foreach ($p['officials'] as $o) {
                echo '<li>' . aan_h($o->ToCode . ' — ' . $o->ToName) . ' (' . aan_h(aan_date($o->ToWhenFrom)) . ') — '
                    . aan_h($o->ItDescription) . ' — ' . (isset($plan[intval($o->TiTournament)])
                        ? '<b>à venir : rôle supprimé</b>'
                        : '<span class="hint">« ' . aan_h(trim($o->TiName . ' ' . $o->TiGivenName)) . ' » → vide</span>') . '</li>';
            }
            echo '</ul></td></tr>';
        }
        $refunds = array_filter($plan, function ($x) { return $x['refund'] !== null; });
        if ($refunds) {
            echo '<tr><td class="Bold">Remboursements</td><td class="warn"><ul>';
            foreach ($refunds as $x) {
                echo '<li>' . aan_h($x['code'] . ' — ' . $x['name']) . ' : paiement validé, <b>' . aan_h(aan_refund($x['refund']))
                    . '</b></li>';
            }
            echo '</ul><div class="hint">Signalé à l\'organisateur de chaque compétition (page Sommes dues), '
                . 'sans le nom de la personne : il rembourse, puis le marque comme fait.</div></td></tr>';
        }
        echo '<tr><td class="Bold">Également</td><td><ul>'
            . '<li>' . $p['photos'] . ' photo(s), ' . $p['extra'] . ' légende(s) ou e-mail(s)</li>'
            . '<li>' . ($p['account'] ? 'Compte en ligne <b>supprimé</b>'
                . ($p['account']->BaEmail !== '' ? ' (avec son e-mail)' : '') : 'Pas de compte en ligne') . '</li>'
            . ($p['waits'] ? '<li>' . $p['waits'] . ' demande(s) de liste d\'attente supprimée(s)</li>' : '')
            . ($p['conflicts'] ? '<li>Licence et nom retirés de ' . $p['conflicts'] . ' écart(s) de réimport</li>' : '')
            . '<li>Licence <b>' . aan_h($lic) . '</b> → <b>' . aan_h(AUT_ANON_CODE) . '</b> partout (participations, officiels, '
            . 'inscriptions en ligne, paiements, boutique, journal)</li>'
            . '</ul><div class="hint">Deux personnes anonymisées sur une même compétition tirée partagent le code '
            . aan_h(AUT_ANON_CODE) . ' : leurs sommes dues y sont alors regroupées.</div></td></tr>';
        echo '<tr><td class="Bold">Confirmer</td><td><form method="post" action="' . aan_h($self) . '"'
            . ' onsubmit="return confirm(\'Anonymiser définitivement cette licence ? Cette opération ne peut pas être annulée.\');">'
            . aut_csrf_field() . '<input type="hidden" name="action" value="apply"><input type="hidden" name="lic" value="' . aan_h($lic) . '">'
            . 'Retapez la licence : <input type="text" name="confirm" autocomplete="off" required> '
            . '<button type="submit">Anonymiser</button><div class="hint">Définitif : seule une sauvegarde antérieure '
            . 'permettrait de revenir en arrière.</div></form></td></tr>';
    }
}

echo '</table></div>';
include('Common/Templates/tail.php');
