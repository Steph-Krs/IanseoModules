<?php
/**
 * admin/reports.php — figures and cash closings of the points of sale of the open competition
 * (organiser, read-only right is enough: nothing here writes).
 *
 * A day (or the whole competition) and a stand (or all) select what is shown: key figures,
 * what was sold by article, category and hour, what was collected by means of payment, stand and
 * volunteer, refunds, cancellations, stocks, accounts still open. Documents: cash closing of a
 * stand for a day, summary of the competition (PDF made with the core's classes), and the lines
 * sold and the journal lines as CSV for a spreadsheet.
 *
 * Every figure comes from lib/report.php: order lines and the payment journal, never a cache.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

require_once dirname(__DIR__) . '/lib/report.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$settings = shp_settings($TOUR);
$enabled = $settings && intval($settings->SgEnabled) === 1;
$F = shp_rep_filter($TOUR, (string) ($_GET['day'] ?? ''), intval($_GET['stand'] ?? 0));
$stands = shp_stands($TOUR, false);
$code = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($_SESSION['TourCode'] ?? '')) ?: 'comp';

/* ================================================================== */
/* Downloads                                                           */
/* ================================================================== */
$dl = (string) ($_GET['csv'] ?? '');
if ($dl === 'lines' || $dl === 'cash') {
    $name = 'shop-' . ($dl === 'lines' ? 'sales' : 'cash') . '-' . $code . ($F['day'] !== '' ? '-' . $F['day'] : '') . '.csv';
    if ($dl === 'lines') shp_rep_csv_lines($F, $name);
    shp_rep_csv_cash($F, $name);
}
$pdf = (string) ($_GET['pdf'] ?? '');
if ($pdf === 'closing' || $pdf === 'summary') {
    require_once dirname(__DIR__) . '/lib/report-pdf.php';
    if ($pdf === 'closing') {
        if ($F['stand'] <= 0) shp_page_fail(shp_t('ShRepPickStand'));
        $bytes = shp_report_pdf_closing($TOUR, $F['stand'], $F['day']);
        bk_ledger_pdf_send($bytes, 'shop-closing-' . $code . '-' . $F['stand'] . ($F['day'] !== '' ? '-' . $F['day'] : '') . '.pdf');
    }
    bk_ledger_pdf_send(shp_report_pdf_summary($TOUR, $F['day'], $F['stand']),
        'shop-summary-' . $code . ($F['day'] !== '' ? '-' . $F['day'] : '') . ($F['stand'] > 0 ? '-' . $F['stand'] : '') . '.pdf');
}

/** Stops with a plain message (a download asked without what it needs). */
function shp_page_fail($msg)
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

/* ================================================================== */
/* Page pieces                                                         */
/* ================================================================== */

/** Amount in the competition's currency. */
function sr_m($v)
{
    global $TOUR;
    return shp_money($v, $TOUR);
}

/**
 * A table. $cols [[label, 'r' for a right-aligned column]], $rows [[cell]] where a cell is text
 * (escaped here) or array(markup) for markup already safe; $total an optional last row.
 */
function sr_table(array $cols, array $rows, array $total = array(), $class = '')
{
    $cell = function ($c) { return is_array($c) ? (string) $c[0] : shp_e($c); };
    $html = '<div class="sa-scroll"><table class="sa-table sr-table' . ($class !== '' ? ' ' . $class : '') . '"><thead><tr>';
    foreach ($cols as $c) $html .= '<th' . (($c[1] ?? '') === 'r' ? ' class="sr-r"' : '') . '>' . shp_e($c[0]) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr' . (isset($r['_class']) ? ' class="' . shp_e($r['_class']) . '"' : '') . '>';
        foreach ($cols as $i => $c) $html .= '<td' . (($c[1] ?? '') === 'r' ? ' class="sr-r"' : '') . '>' . $cell($r[$i] ?? '') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody>';
    if ($total) {
        $html .= '<tfoot><tr>';
        foreach ($cols as $i => $c) $html .= '<td' . (($c[1] ?? '') === 'r' ? ' class="sr-r"' : '') . '>' . $cell($total[$i] ?? '') . '</td>';
        $html .= '</tr></tfoot>';
    }
    return $html . '</table></div>';
}

/** A card with a title. */
function sr_card($title, $body, $hint = '')
{
    return '<section class="sa-card"><h2>' . shp_e($title) . '</h2>' . ($hint !== '' ? '<p class="sa-hint">' . shp_e($hint) . '</p>' : '') . $body . '</section>';
}

/** A key figure. */
function sr_tile($label, $value, $sub = '')
{
    return '<div class="sr-tile"><span>' . shp_e($label) . '</span><b>' . shp_e($value) . '</b>' . ($sub !== '' ? '<small>' . shp_e($sub) . '</small>' : '') . '</div>';
}

/** Link to a PDF, with the core's PDF icon beside its text. */
function sr_pdf_link($query, $label)
{
    global $CFG;
    return '<a class="sa-pdf" target="_blank" href="' . shp_e(shp_adm_url('reports.php') . '?' . $query) . '">'
        . '<img src="' . shp_e($CFG->ROOT_DIR . 'Common/Images/pdf_small.gif') . '" alt="" width="16" height="16"> ' . shp_e($label) . '</a>';
}

/** Query string keeping the page's filter, with some parameters added. */
function sr_query(array $extra = array(), $keep = true)
{
    global $F;
    $q = $keep ? array_filter(array('day' => $F['day'], 'stand' => $F['stand'] > 0 ? $F['stand'] : ''), 'strlen') : array();
    return http_build_query($extra + $q);
}

/** Cells of a cash group. */
function sr_cash_cells(array $g)
{
    return array(sr_m($g['payment']), sr_m($g['refund']), sr_m($g['other']), array('<b>' . shp_e(sr_m($g['net'])) . '</b>'));
}

/* ================================================================== */
/* Page                                                                */
/* ================================================================== */
$PAGE_TITLE = shp_t('MnuReports') . ' — ' . shp_t('MnuTitle');
$JS_SCRIPT = array(
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('admin.css')) . '">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('reports.css')) . '">',
);
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

echo '<div id="shpadm" class="sr-page"><h1>' . shp_e(shp_t('ShRepReportsTitle')) . '</h1>' . shp_adm_nav('reports.php');

if (!$enabled) {
    echo shp_adm_msg('warn', array(shp_t('ShRepShopOff'))) . '<p><a class="sa-btn sa-primary" href="' . shp_e(shp_adm_url('index.php')) . '">'
        . shp_e(shp_t('MnuSettings')) . '</a></p></div>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

bk_money_tour($TOUR);
$days = shp_rep_days($TOUR);
$win = shp_window($TOUR);

echo '<p class="sa-lead">' . shp_e(shp_t('ShRepReportsLead')) . '</p>';

// Names of volunteers without a licence disappear the day after the competition (decision D3).
if ($win && $win['from'] > '0000-00-00') {
    echo shp_adm_msg($win['purge_due'] ? 'warn' : 'info', array(shp_t($win['purge_due'] ? 'ShRepPurgeDone' : 'ShRepPurgeNotice', shp_adm_date($win['purge_from']))));
}

// Filter.
$dayOpts = array('' => shp_t('ShRepWholeComp'));
foreach ($days as $d) $dayOpts[$d] = shp_adm_date($d);
$standOpts = array('0' => shp_t('ShRepAllStands'));
foreach ($stands as $sd => $s) $standOpts[(string) $sd] = shp_rep_stand_name($s);
echo '<form method="get" class="sa-card sr-filter" action="' . shp_e(shp_adm_url('reports.php')) . '"><div class="sa-row">'
    . shp_adm_field(shp_t('ShRepFilterDay'), '<select name="day">' . shp_adm_options($dayOpts, $F['day']) . '</select>')
    . shp_adm_field(shp_t('ShRepFilterStand'), '<select name="stand">' . shp_adm_options($standOpts, (string) $F['stand']) . '</select>')
    . '<button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t('ShRepShow')) . '</button></div></form>';

$sum = shp_rep_summary($F);
$sales = shp_rep_sales($F);
$cash = shp_rep_cash($F);

// Key figures.
$left = round($sum['sales'] - $cash['total']['net'], 2);
echo '<div class="sr-tiles">'
    . sr_tile(shp_t('ShRepOrdersCount'), (string) $sum['orders'])
    . sr_tile(shp_t('ShRepSalesTotal'), sr_m($sum['sales']))
    . sr_tile(shp_t('ShRepCollectedNet'), sr_m($cash['total']['net']))
    . sr_tile(shp_t('ShRepStillToCollect'), sr_m($left), shp_t('ShRepStillHint'))
    . sr_tile(shp_t('ShRepOnTab', $sum['tab_orders']), sr_m($sum['tab_amount']))
    . sr_tile(shp_t('ShRepCancelledLine', array('orders' => $sum['cancelled_orders'], 'qty' => $sum['cancelled_qty'])), sr_m($sum['cancelled_value']))
    . '</div>';

// Documents.
$docs = '<div class="sr-docs"><div>' . sr_pdf_link(sr_query(array('pdf' => 'summary')), shp_t('ShRepPdfSummary')) . '</div>'
    . '<div><a class="sa-btn" href="' . shp_e(shp_adm_url('reports.php') . '?' . sr_query(array('csv' => 'lines'))) . '">' . shp_e(shp_t('ShRepCsvLines')) . '</a> '
    . '<a class="sa-btn" href="' . shp_e(shp_adm_url('reports.php') . '?' . sr_query(array('csv' => 'cash'))) . '">' . shp_e(shp_t('ShRepCsvCash')) . '</a></div></div>';
$closings = shp_rep_closings($TOUR);
$rows = array();
foreach ($closings as $c) {
    $s = $stands[$c['stand']] ?? null;
    $rows[] = array(shp_adm_date($c['day']), shp_rep_stand_name($s), (string) $c['orders'], sr_m($c['sales']), sr_m($c['cash']), sr_m($c['net']),
        array(sr_pdf_link(http_build_query(array('pdf' => 'closing', 'stand' => $c['stand'], 'day' => $c['day'])), shp_t('ShRepPdfClosing'))));
}
$docs .= $rows ? '<h3>' . shp_e(shp_t('ShRepClosingsTitle')) . '</h3>'
    . sr_table(array(array(shp_t('ShRepColDay')), array(shp_t('ShRepColStand')), array(shp_t('ShRepColOrders'), 'r'), array(shp_t('ShRepColSales'), 'r'),
        array(shp_t('ShRepColCashNet'), 'r'), array(shp_t('ShRepColNet'), 'r'), array('')), $rows)
    : '<p class="sa-muted">' . shp_e(shp_t('ShRepNoClosing')) . '</p>';
echo sr_card(shp_t('ShRepDocsTitle'), $docs, shp_t('ShRepDocsHint'));

// Collections.
$cashCols = array(array(shp_t('ShRepColMethod')), array(shp_t('ShRepColCollected'), 'r'), array(shp_t('ShRepColRefunded'), 'r'),
    array(shp_t('ShRepColCancelled2'), 'r'), array(shp_t('ShRepColNet'), 'r'));
$total = array_merge(array(shp_t('ShRepTotal')), sr_cash_cells($cash['total']));
$rows = array();
foreach ($cash['methods'] as $m => $g) $rows[] = array_merge(array(shp_rep_method_label((string) $m)), sr_cash_cells($g));
$body = $rows ? sr_table($cashCols, $rows, $total) : '<p class="sa-muted">' . shp_e(shp_t('ShRepNoMoney')) . '</p>';
if ($rows) {
    $cashCols[0] = array(shp_t('ShRepColStand'));
    $r2 = array();
    foreach ($cash['stands'] as $sd => $g) $r2[] = array_merge(array(shp_rep_stand_name($stands[$sd] ?? null)), sr_cash_cells($g));
    $body .= '<h3>' . shp_e(shp_t('ShRepByStand')) . '</h3>' . sr_table($cashCols, $r2);
    $cashCols[0] = array(shp_t('ShRepColStaff'));
    $r3 = array();
    foreach ($cash['staff'] as $id => $g) $r3[] = array_merge(array(shp_rep_staff_name($id)), sr_cash_cells($g));
    $body .= '<h3>' . shp_e(shp_t('ShRepByStaff')) . '</h3>' . sr_table($cashCols, $r3);
}
echo sr_card(shp_t('ShRepCollectionsTitle'), $body, shp_t('ShRepCollectionsHint'));

// Sales: by article, category, hour.
$body = '';
if (!$sales) {
    $body = '<p class="sa-muted">' . shp_e(shp_t('ShRepNothing')) . '</p>';
} else {
    $rows = array(); $q = 0; $a = 0.0; $last = null;
    foreach ($sales as $s) {
        if ($F['stand'] === 0 && $s['stand'] !== $last) {
            $last = $s['stand'];
            $rows[] = array(array('<b>' . shp_e(shp_rep_stand_name($stands[$s['stand']] ?? null)) . '</b>'), '', '', '', '_class' => 'sr-group');
        }
        $rows[] = array($s['label'], $s['category'] !== '' ? $s['category'] : '—', sr_m($s['unit']), (string) $s['qty'], sr_m($s['amount']));
        $q += $s['qty'];
        $a = round($a + $s['amount'], 2);
    }
    $body = sr_table(array(array(shp_t('ShRepColProduct')), array(shp_t('ShRepColCategory')), array(shp_t('ShRepColUnit'), 'r'),
        array(shp_t('ShRepColQty'), 'r'), array(shp_t('ShRepColAmount'), 'r')), $rows, array(shp_t('ShRepTotal'), '', '', (string) $q, sr_m($a)));
}
echo sr_card(shp_t('ShRepSalesByProduct'), $body);

$cats = shp_rep_by_category($sales);
if ($cats) {
    $rows = array();
    foreach ($cats as $c) $rows[] = array($c['category'] !== '' ? $c['category'] : shp_t('ShRepNoCategory'), (string) $c['qty'], sr_m($c['amount']));
    echo sr_card(shp_t('ShRepByCategory'), sr_table(array(array(shp_t('ShRepColCategory')), array(shp_t('ShRepColQty'), 'r'), array(shp_t('ShRepColAmount'), 'r')), $rows));
}

$hours = shp_rep_hours($F);
if ($hours) {
    $maxOrders = 0;
    foreach ($hours as $h) $maxOrders = max($maxOrders, $h['orders']);
    $rows = array();
    foreach ($hours as $h) {
        $pct = $maxOrders > 0 ? max(2, (int) round(100 * $h['orders'] / $maxOrders)) : 0;
        $rows[] = array(($F['day'] === '' ? shp_adm_date($h['day']) . ' ' : '') . sprintf('%02d:00', $h['hour']), (string) $h['orders'], sr_m($h['amount']),
            array('<span class="sr-bar" style="width:' . intval($pct) . '%"></span>'), '_class' => $h['orders'] === $maxOrders ? 'sr-peak' : '');
    }
    echo sr_card(shp_t('ShRepByHour'), sr_table(array(array(shp_t('ShRepColHour')), array(shp_t('ShRepColOrders'), 'r'), array(shp_t('ShRepColAmount'), 'r'), array('')), $rows, array(), 'sr-hours'),
        shp_t('ShRepByHourHint'));
}

// Refunds.
$refunds = shp_rep_refunds($F);
$rows = array();
foreach ($refunds as $r) {
    $rows[] = array(bk_date_time($r['when']), $r['order'], sr_m($r['amount']), shp_rep_method_label($r['method']),
        trim($r['label'] . ($r['cancelled'] ? ' — ' . shp_t('ShRepCancelledWord') : ''), ' —'), shp_rep_staff_name($r['staff']));
}
echo sr_card(shp_t('ShRepRefundsTitle'), $rows
    ? sr_table(array(array(shp_t('ShRepColDate')), array(shp_t('ShRepColOrder')), array(shp_t('ShRepColAmount'), 'r'), array(shp_t('ShRepColMethod')),
        array(shp_t('ShRepColLabel')), array(shp_t('ShRepColStaff'))), $rows)
    : '<p class="sa-muted">' . shp_e(shp_t('ShRepNoRefund')) . '</p>');

// Stock.
$stock = shp_rep_stock($TOUR, $F['stand']);
if ($stock) {
    $rows = array();
    foreach ($stock as $s) {
        $rows[] = array($s['label'], shp_rep_stand_name($stands[$s['stand']] ?? null), (string) $s['initial'], (string) $s['restock'], (string) $s['sold'],
            (string) $s['lost'], array('<b>' . shp_e((string) $s['left']) . '</b>' . ($s['low'] ? ' <span class="sr-low">' . shp_e(shp_t('ShRepLow')) . '</span>' : '')));
    }
    echo sr_card(shp_t('ShRepStockTitle'), sr_table(array(array(shp_t('ShRepColProduct')), array(shp_t('ShRepColStand')), array(shp_t('ShRepColInitial'), 'r'),
        array(shp_t('ShRepColRestock'), 'r'), array(shp_t('ShRepColSold'), 'r'), array(shp_t('ShRepColLost'), 'r'), array(shp_t('ShRepColLeft'), 'r')), $rows),
        shp_t('ShRepStockHint'));
}

// Accounts still open.
$acc = shp_rep_open_accounts($TOUR);
$duesFile = dirname(__DIR__, 2) . '/booking/admin/dues.php';
$duesLinks = is_file($duesFile) && ($settings && (intval($settings->SgTab) === 1 || bk_comp_payments_on(bk_comp_config($TOUR))));
$rows = array();
foreach (array_slice($acc['rows'], 0, 100) as $a) {
    $name = $a['name'] !== '' ? $a['name'] : $a['account'];
    $rows[] = array($duesLinks
        ? array('<a href="' . shp_e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/dues.php?a=' . rawurlencode($a['account'])) . '">' . shp_e($name) . '</a>')
        : $name, $a['club'], sr_m($a['shp']), sr_m($a['paid']), sr_m($a['remaining']));
}
$body = $rows ? sr_table(array(array(shp_t('ShRepColName')), array(shp_t('ShRepColClub')), array(shp_t('ShRepColShopDue'), 'r'), array(shp_t('ShRepColPaid'), 'r'),
        array(shp_t('ShRepColLeft'), 'r')), $rows, array(shp_t('ShRepAccountsCount') . ' : ' . count($acc['rows']), '', '', '', sr_m($acc['remaining'])))
    : '<p class="sa-muted">' . shp_e(shp_t('ShRepNoAccount')) . '</p>';
if ($duesLinks) $body .= '<p><a class="sa-btn" href="' . shp_e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/dues.php') . '">' . shp_e(shp_t('ShRepGoPayments')) . '</a></p>';
echo sr_card(shp_t('ShRepAccountsTitle'), $body, shp_t('ShRepAccountsHint'));

echo '</div>';
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
