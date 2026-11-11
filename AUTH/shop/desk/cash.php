<?php
/**
 * desk/cash.php — the till of the check-in desk, counted at the end of each departure: what was
 * taken by hand outside the stands (lib/desk-cash.php), by means of payment and by volunteer, and
 * the closing that ties the open till to the departure that just ended. One view at a time: the
 * open till (default) or a departure (its closings); the whole competition is the Payments page
 * (booking/admin/dues.php). Printable (the browser's print: the forms are hidden).
 *
 * ianseo look and ACL of the core, frame shared by AUTH's organiser pages (adm-lib.php): reading
 * with participants read-only, closing with read-write.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

require_once dirname(__DIR__) . '/lib/desk-cash.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';
require_once dirname(__DIR__, 2) . '/lib.php';

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$RW = hasFullACL(AclParticipants, 'pEntries', AclReadWrite) && !shp_impersonating();
$SELF = shp_url('desk/cash.php');
$sessions = shp_desk_sessions($TOUR);

/* ---- Actions: close the open till, undo the last closing ---- */
if ($RW && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    $back = $SELF;
    if (!bk_csrf_check()) {
        shp_adm_flash_set('err', shp_t('ShStfErrSession'));
    } elseif ($act === 'close') {
        $ses = intval($_POST['s'] ?? 0);
        if (!isset($sessions[$ses])) {
            shp_adm_flash_set('err', shp_t('DkCashErrSession'));
        } elseif (shp_cash_close($TOUR, $ses, shp_staff_by())) {
            shp_adm_flash_set('ok', shp_t('DkCashClosed', $sessions[$ses]['label']));
            $back = $SELF . '?v=' . $ses;
        } else {
            shp_adm_flash_set('warn', shp_t('DkCashNothing'));
        }
    } elseif ($act === 'reopen') {
        $ok = shp_cash_reopen($TOUR, intval($_POST['id'] ?? 0));
        shp_adm_flash_set($ok ? 'ok' : 'err', shp_t($ok ? 'DkCashReopened' : 'DkCashErrReopen'));
    }
    header('Location: ' . $back);
    exit;
}

/* ---- View ---- */
$v = (string) ($_GET['v'] ?? 'open');
if (ctype_digit($v) && isset($sessions[intval($v)])) $view = intval($v);
else $view = 'open';

$closes = shp_cash_closes($TOUR);
$lines = shp_cash_lines($TOUR, $view);
$tot = shp_cash_totals($lines);
$methods = bk_payment_methods();
$money = function ($a) use ($TOUR) { return shp_e(shp_money($a, $TOUR)); };
$mLabel = function ($m) use ($methods) { return $methods[$m] ?? ($m !== '' ? $m : '—'); };

// Totals of each view, for the choice of the view.
$sumOf = function ($v) use ($TOUR) { return shp_cash_totals(shp_cash_lines($TOUR, $v))['total']; };
$closedSes = array();
foreach ($closes as $c) $closedSes[$c['session']] = ($closedSes[$c['session']] ?? 0) + $c['total'];
$openTotal = $view === 'open' ? $tot['total'] : $sumOf('open');
$openCount = $view === 'open' ? count($lines) : count(shp_cash_lines($TOUR, 'open'));

$vOpts = array('open' => shp_t('DkCashViewOpen') . ' — ' . shp_money($openTotal, $TOUR));
foreach ($sessions as $o => $s) {
    $vOpts[(string) $o] = $s['label'] . ' — ' . (isset($closedSes[$o]) ? shp_money($closedSes[$o], $TOUR) : shp_t('DkCashNotClosed'));
}

// Departure proposed for the closing: the first one not closed yet, else the last one.
$next = 0;
foreach ($sessions as $o => $s) if (!isset($closedSes[$o])) { $next = $o; break; }
if (!$next && $sessions) $next = max(array_keys($sessions));

$names = array();
foreach (bk_accounts($TOUR) as $k => $a) $names[(string) $k] = $a;

/* ---- Page ---- */
$JS_SCRIPT = aut_adm_assets(array('<link rel="stylesheet" href="' . shp_e(shp_asset_url('desk-cash.css')) . '">'));
$PAGE_TITLE = shp_t('DkCashTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$print = '<button type="button" class="sa-btn dc-noprint" onclick="window.print()">' . shp_e(shp_t('DkCashPrint')) . '</button>';
$html = aut_adm_open('shop/desk/cash.php', shp_t('DkCashTitle'), $print, 'dc', shp_e(shp_t('DkCashHelp')));
if ($f = shp_adm_flash_take()) $html .= shp_adm_msg($f['type'], $f['lines']);
$html .= '<p class="sa-lead">' . shp_e(shp_t('DkCashLead')) . '</p>';

// Choice of the view.
$html .= '<div class="sa-card dc-noprint">' . aut_adm_title('h2', shp_t('DkCashView'))
    . '<form method="get" action="' . shp_e($SELF) . '" class="sa-row">'
    . '<select name="v" aria-label="' . shp_e(shp_t('DkCashView')) . '" onchange="this.form.submit()">' . shp_adm_options($vOpts, (string) $view) . '</select>'
    . '<noscript><button type="submit" class="sa-btn">' . shp_e(shp_t('DkLsApply')) . '</button></noscript></form></div>';

// Printed heading: what the sheet is.
$html .= '<h2 class="dc-printonly">' . shp_e($vOpts[(string) $view] ?? '') . '</h2>';

// Closing of the open till.
if ($view === 'open' && $RW) {
    $sOpts = array();
    foreach ($sessions as $o => $s) $sOpts[(string) $o] = $s['label'] . (isset($closedSes[$o]) ? ' (' . shp_t('DkCashAlready') . ')' : '');
    $html .= '<div class="sa-card dc-noprint">' . aut_adm_title('h2', shp_t('DkCashCloseTitle'));
    if (!$sessions) {
        $html .= '<p class="sa-muted">' . shp_e(shp_t('DkCashNoSession')) . '</p>';
    } elseif ($openCount === 0) {
        $html .= '<p class="sa-muted">' . shp_e(shp_t('DkCashNothing')) . '</p>';
    } else {
        $html .= '<p>' . shp_e(shp_t('DkCashCloseHint', array('n' => $openCount, 'total' => shp_money($openTotal, $TOUR)))) . '</p>'
            . '<form method="post" action="' . shp_e($SELF) . '" class="sa-row" onsubmit="return confirm(this.dataset.sure)" data-sure="' . shp_e(shp_t('DkCashCloseSure')) . '">'
            . bk_csrf_field() . '<input type="hidden" name="act" value="close">'
            . shp_adm_field(shp_t('DkCashCloseOn'), '<select name="s">' . shp_adm_options($sOpts, (string) $next) . '</select>')
            . '<button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t('DkCashCloseBtn')) . '</button></form>';
    }
    $html .= '</div>';
}

// Key figures: the total, then each means of payment.
$html .= '<div class="sa-kpis"><div class="sa-kpi"><span>' . shp_e(shp_t('DkCashTotal')) . '</span><b>' . $money($tot['total']) . '</b>'
    . '<small>' . shp_e(shp_t('DkCashCount', $tot['n'])) . '</small></div>';
foreach ($tot['methods'] as $m => $x) {
    $html .= '<div class="sa-kpi"><span>' . shp_e($mLabel($m)) . '</span><b>' . $money($x['amount']) . '</b>'
        . '<small>' . shp_e(shp_t('DkCashCount', $x['n'])) . '</small></div>';
}
$html .= '</div>';

if (!$lines) {
    $html .= '<p class="sa-muted">' . shp_e(shp_t('DkCashNone')) . '</p>';
} else {
    // By volunteer: what each one must hand over, by means of payment.
    $ms = array_keys($tot['methods']);
    $html .= aut_adm_title('h2', shp_t('DkCashByWho')) . '<div class="sa-scroll"><table class="sa-table dc-who"><thead><tr><th>' . shp_e(shp_t('DkCashWho')) . '</th>';
    foreach ($ms as $m) $html .= '<th class="sa-num">' . shp_e($mLabel($m)) . '</th>';
    $html .= '<th class="sa-num">' . shp_e(shp_t('DkCashTotal')) . '</th></tr></thead><tbody>';
    foreach ($tot['who'] as $who => $x) {
        $html .= '<tr><td>' . shp_e($who) . '</td>';
        foreach ($ms as $m) $html .= '<td class="sa-num">' . (isset($x[$m]) ? $money($x[$m]) : '') . '</td>';
        $html .= '<td class="sa-num"><b>' . $money($x['']) . '</b></td></tr>';
    }
    $html .= '</tbody></table></div>';
}

// Closings of the departure.
$shown = array_filter($closes, function ($c) use ($view) { return $c['session'] === $view; });
if ($shown) {
    $lastId = max(array_keys($closes));
    $html .= aut_adm_title('h2', shp_t('DkCashClosesTitle')) . '<div class="sa-scroll"><table class="sa-table"><thead><tr>'
        . '<th>' . shp_e(shp_t('DkCashWhen')) . '</th><th>' . shp_e(shp_t('DkSession')) . '</th><th>' . shp_e(shp_t('DkCashClosedBy')) . '</th>'
        . '<th class="sa-num">' . shp_e(shp_t('DkCashLinesN')) . '</th><th class="sa-num">' . shp_e(shp_t('DkCashTotal')) . '</th><th class="dc-noprint"></th></tr></thead><tbody>';
    foreach ($shown as $c) {
        $undo = '';
        if ($RW && $c['id'] === $lastId) {
            $undo = '<form method="post" action="' . shp_e($SELF) . '" onsubmit="return confirm(this.dataset.sure)" data-sure="' . shp_e(shp_t('DkCashReopenSure')) . '">'
                . bk_csrf_field() . '<input type="hidden" name="act" value="reopen"><input type="hidden" name="id" value="' . $c['id'] . '">'
                . '<button type="submit" class="sa-btn sa-btn-small">' . shp_e(shp_t('DkCashReopen')) . '</button></form>';
        }
        $html .= '<tr><td>' . shp_e(bk_date_time($c['when'])) . '</td><td>' . shp_e($sessions[$c['session']]['label'] ?? bk_t('DepCap', $c['session'])) . '</td>'
            . '<td>' . shp_e($c['by']) . '</td><td class="sa-num">' . $c['n'] . '</td><td class="sa-num">' . $money($c['total']) . '</td>'
            . '<td class="sa-acts dc-noprint">' . $undo . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}

// The lines themselves.
if ($lines) {
    $html .= aut_adm_title('h2', shp_t('DkCashLines')) . '<div class="sa-scroll"><table class="sa-table dc-lines"><thead><tr>'
        . '<th>' . shp_e(shp_t('DkCashWhen')) . '</th><th>' . shp_e(shp_t('DkCashArcher')) . '</th><th>' . shp_e(shp_t('DkCashLabel')) . '</th>'
        . '<th>' . shp_e(shp_t('DkCashMethod')) . '</th><th>' . shp_e(shp_t('DkCashWho')) . '</th><th class="sa-num">' . shp_e(shp_t('DkCashAmount')) . '</th></tr></thead><tbody>';
    foreach ($lines as $l) {
        $a = $names[$l['account']] ?? null;
        $who = $a ? '<b>' . shp_e($a['name']) . '</b>' . ($a['licence'] !== '' ? ' <small>' . shp_e($a['licence']) . '</small>' : '')
            . ($a['club_name'] !== '' ? '<br><small>' . shp_e($a['club_name']) . '</small>' : '')
            : shp_e($l['account']);
        $html .= '<tr' . ($l['cancelled'] ? ' class="dc-cancelled"' : '') . '>'
            . '<td class="sa-nowrap">' . shp_e(bk_date_time($l['when'])) . '</td><td>' . $who . '</td>'
            . '<td>' . shp_e($l['label']) . ($l['cancelled'] ? ' <span class="sa-badge sa-b-warn">' . shp_e(shp_t('DkCashCancelled')) . '</span>' : '') . '</td>'
            . '<td>' . shp_e($mLabel($l['method'])) . '</td><td>' . shp_e(shp_cash_who($l)) . '</td>'
            . '<td class="sa-num sa-nowrap">' . $money($l['amount']) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}

$html .= aut_adm_close();
echo $html;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
