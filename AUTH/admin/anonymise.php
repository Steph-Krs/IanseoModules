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
/** One refund on a line: amount in the competition's currency, payment method, club code and name. */
function aan_refund($f, $tourId)
{
    $m = bk_payment_methods()[$f['method']] ?? '';
    return bk_eur((float) $f['amount'], false, $tourId) . ($m !== '' ? ' (' . $m . ')' : '')
        . ' — ' . aut_t('AnClub', trim($f['club_code'] . ' ' . $f['club_name']));
}
/** "<li>" list of competitions: [tourId => ['code', 'name', …]], with an optional extra per item. */
function aan_comps($list, $extra = null)
{
    $out = '<ul>';
    foreach ($list as $t => $c) $out .= '<li>' . aan_h($c['code'] . ' — ' . $c['name']) . ($extra ? $extra($t, $c) : '') . '</li>';
    return $out . '</ul>';
}

$self = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/anonymise.php';
$q    = trim((string) ($_GET['q'] ?? ''));
$lic  = trim((string) ($_GET['lic'] ?? $_POST['lic'] ?? ''));
if (strcasecmp($lic, AUT_ANON_CODE) === 0) $lic = '';   // the shared anonymous code is nobody
$err  = ''; $done = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    if (!aut_csrf_check()) {
        $err = aut_t('AnSessionExpired');
    } elseif ($lic === '' || strcasecmp(trim((string) ($_POST['confirm'] ?? '')), $lic) !== 0) {
        $err = aut_t('AnMismatch');
    } else {
        $done = aut_anon_apply($lic);
        // The journal says that it happened and who did it — never whom it was about.
        aut_log('ANONYMISE', $_SESSION['AUTH_User'] ?? 'local');
    }
}

$PAGE_TITLE = aut_t('MenuTitle') . ' — ' . aut_t('MenuAnonymise');
include('Common/Templates/head.php');

$anon = '<b>' . aan_h(AUT_ANON_CODE) . '</b>';
echo '<style>
#aan .hint{font-size:11px;color:#555}
#aan .ok{background:#e8f4e8;color:#1a5c1a} #aan .ko{background:#fde8e8;color:#8b1a1a}
#aan .warn{background:#fff8e1;color:#5b4300}
#aan ul{margin:4px 0 4px 18px}
#aan input[type=text]{width:260px}
</style>';
echo '<div id="aan"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">' . aan_h(aut_t('MenuAnonymise')) . '</th></tr>';
echo '<tr><td colspan="2" class="hint">' . aut_t('AnIntro', $anon) . '</td></tr>';

if ($err) echo '<tr><td colspan="2" class="Center ko">' . aan_h($err) . '</td></tr>';

/* ---------------- Result of an anonymisation ---------------- */
if ($done !== null) {
    $doneRm = '';
    foreach ($done['removed'] as $t => $rm) {
        $doneRm .= '<li>' . aut_t('AnRemovedFrom', aan_h($rm['code'] . ' — ' . $rm['name']))
            . ($rm['refund'] ? ' — <b>' . aut_t('AnRefundFlagged', aan_h(aan_refund($rm['refund'], $t))) . '</b>' : '') . '</li>';
    }
    echo '<tr><td colspan="2" class="ok"><b>' . aut_t('AnDone', aan_h($lic)) . '</b><ul>' . $doneRm
        . '<li>' . aan_h(aut_t('AnDoneEntries', array('e' => $done['entries'], 'o' => $done['officials']))) . '</li>'
        . '<li>' . aan_h(aut_t('AnDonePhotos', array('p' => $done['photos'], 'x' => $done['extra']))) . '</li>'
        . '<li>' . aan_h(aut_t($done['account'] ? 'AnAccountDeleted' : 'AnNoAccount')) . '</li>'
        . ($done['waits'] ? '<li>' . aan_h(aut_t('AnWaitsDeleted', $done['waits'])) . '</li>' : '')
        . '</ul></td></tr>';
    if ($done['locked']) {
        echo '<tr><td colspan="2" class="warn">' . aut_t('AnLocked')
            . aan_comps($done['locked'], function ($t, $lk) {
                return $lk['refund'] ? ' — ' . aan_h(aut_t('AnPaymentValidated', aan_refund($lk['refund'], $t))) : '';
            }) . '</td></tr>';
    }
    if ($done['online']) {
        $online = array();
        foreach ($done['online'] as $code => $name) $online[] = array('code' => $code, 'name' => $name);
        echo '<tr><td colspan="2" class="warn">' . aan_h(aut_t('AnOnline')) . aan_comps($online) . '</td></tr>';
    }
    echo '<tr><td colspan="2" class="hint">' . aan_h(aut_t('AnOutOfReach', AUT_ANON_CODE)) . '</td></tr>';
    $lic = '';
}

/* ---------------- Search ---------------- */
echo '<tr><td class="Bold" style="width:30%">' . aan_h(aut_t('AnSearch')) . '</td><td><form method="get" action="' . aan_h($self) . '">'
    . '<input type="text" name="q" value="' . aan_h($q) . '" placeholder="' . aan_h(aut_t('AnSearchPh')) . '" autofocus> '
    . '<button type="submit">' . aan_h(aut_t('AnSearchBtn')) . '</button><div class="hint">' . aan_h(aut_t('AnSearchHint')) . '</div>'
    . '</form></td></tr>';

if ($q !== '' && $lic === '') {
    $found = aut_anon_search($q);
    echo '<tr><th class="Title" colspan="2">' . aan_h(aut_t('AnFound', count($found))) . '</th></tr>';
    if (!$found) {
        echo '<tr><td colspan="2" class="hint">' . aan_h(aut_t('AnNoResult')) . '</td></tr>';
    }
    foreach ($found as $l => $p) {
        echo '<tr><td><a href="' . aan_h($self . '?lic=' . rawurlencode($l)) . '"><b>' . aan_h($l) . '</b></a></td><td>'
            . aan_h(trim($p['name']) ?: aut_t('AnNameEmpty')) . ($p['year'] ? ' — ' . aan_h(aut_t('AnBorn', $p['year'])) : '')
            . ($p['club'] ? ' — ' . aan_h($p['club']) : '') . '<br><span class="hint">'
            . aan_h(aut_t('AnFoundLine', array('e' => $p['entries'], 'o' => $p['officials'])) . ($p['account'] ? aut_t('AnFoundAccount') : ''))
            . '</span></td></tr>';
    }
}

/* ---------------- Preview + confirmation ---------------- */
if ($lic !== '') {
    $p = aut_anon_person($lic);
    $who = $p['lue'] ? trim($p['lue']->LueFamilyName . ' ' . $p['lue']->LueName) : '';
    if ($who === '' && $p['entries']) $who = trim($p['entries'][0]->EnFirstName . ' ' . $p['entries'][0]->EnName);
    echo '<tr><th class="Title" colspan="2">' . aan_h(aut_t('AnLicence', $lic)) . ($who !== '' ? ' — ' . aan_h($who) : '') . '</th></tr>';
    if (!$p['entries'] && !$p['officials'] && !$p['account']) {
        echo '<tr><td colspan="2" class="hint">' . aan_h(aut_t('AnNothing')) . '</td></tr>';
    } else {
        $plan = aut_anon_plan($lic);   // competitions to come: removal instead of anonymisation
        if ($p['entries']) {
            echo '<tr><td class="Bold">' . aan_h(aut_t('AnEntries', count($p['entries']))) . '</td><td><ul>';
            foreach ($p['entries'] as $e) {
                echo '<li>' . aan_h($e->ToCode . ' — ' . $e->ToName) . ' (' . aan_h(aan_date($e->ToWhenFrom)) . ') — '
                    . aan_h($e->EnDivision . $e->EnClass) . ' — ' . (isset($plan[intval($e->EnTournament)])
                        ? '<b>' . aan_h(aut_t('AnToComeEntry')) . '</b>'
                        : '<span class="hint">' . aan_h(aut_t('AnNameToEmpty', trim($e->EnFirstName . ' ' . $e->EnName))) . ' ; '
                          . aan_h(intval($e->EnDob) > 0 ? aut_t('AnDobRemoved', aan_date($e->EnDob)) : aut_t('AnNoDob')) . '</span>')
                    . '</li>';
            }
            echo '</ul></td></tr>';
        }
        if ($p['officials']) {
            echo '<tr><td class="Bold">' . aan_h(aut_t('AnOfficials', count($p['officials']))) . '</td><td><ul>';
            foreach ($p['officials'] as $o) {
                echo '<li>' . aan_h($o->ToCode . ' — ' . $o->ToName) . ' (' . aan_h(aan_date($o->ToWhenFrom)) . ') — '
                    . aan_h($o->ItDescription) . ' — ' . (isset($plan[intval($o->TiTournament)])
                        ? '<b>' . aan_h(aut_t('AnToComeRole')) . '</b>'
                        : '<span class="hint">' . aan_h(aut_t('AnNameToEmpty', trim($o->TiName . ' ' . $o->TiGivenName))) . '</span>') . '</li>';
            }
            echo '</ul></td></tr>';
        }
        $refunds = array_filter($plan, function ($x) { return $x['refund'] !== null; });
        if ($refunds) {
            echo '<tr><td class="Bold">' . aan_h(aut_t('AnRefunds')) . '</td><td class="warn"><ul>';
            foreach ($refunds as $t => $x) {
                echo '<li>' . aan_h($x['code'] . ' — ' . $x['name']) . ' : ' . aut_t('AnRefundLine', aan_h(aan_refund($x['refund'], $t))) . '</li>';
            }
            echo '</ul><div class="hint">' . aan_h(aut_t('AnRefundsHint')) . '</div></td></tr>';
        }
        echo '<tr><td class="Bold">' . aan_h(aut_t('AnAlso')) . '</td><td><ul>'
            . '<li>' . aan_h(aut_t('AnAlsoPhotos', array('p' => $p['photos'], 'x' => $p['extra']))) . '</li>'
            . '<li>' . ($p['account'] ? aut_t($p['account']->BaEmail !== '' ? 'AnAlsoAccountEmail' : 'AnAlsoAccount') : aan_h(aut_t('AnAlsoNoAccount'))) . '</li>'
            . ($p['waits'] ? '<li>' . aan_h(aut_t('AnWaitsDeleted', $p['waits'])) . '</li>' : '')
            . ($p['conflicts'] ? '<li>' . aan_h(aut_t('AnConflicts', $p['conflicts'])) . '</li>' : '')
            . '<li>' . aut_t('AnLicenceEverywhere', array('lic' => '<b>' . aan_h($lic) . '</b>', 'anon' => $anon)) . '</li>'
            . '</ul><div class="hint">' . aan_h(aut_t('AnShared', AUT_ANON_CODE)) . '</div></td></tr>';
        echo '<tr><td class="Bold">' . aan_h(aut_t('AnConfirm')) . '</td><td><form method="post" action="' . aan_h($self) . '"'
            . ' data-confirm="' . aan_h(aut_t('AnConfirmQ')) . '" onsubmit="return confirm(this.dataset.confirm);">'
            . aut_csrf_field() . '<input type="hidden" name="action" value="apply"><input type="hidden" name="lic" value="' . aan_h($lic) . '">'
            . aan_h(aut_t('AnRetype')) . ' <input type="text" name="confirm" autocomplete="off" required> '
            . '<button type="submit">' . aan_h(aut_t('AnApply')) . '</button><div class="hint">' . aan_h(aut_t('AnFinal')) . '</div></form></td></tr>';
    }
}

echo '</table></div>';
include('Common/Templates/tail.php');
