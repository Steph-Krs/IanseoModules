<?php
/**
 * public/calendar.php — calendar of the open competitions, as a monthly GRID.
 *
 * Density kept under control by a filter: discipline (picture) + region (by default the
 * licensee's). The grid only shows what an organiser explicitly opened (bk_comp_calendar);
 * eligibility is checked again at registration (this screen informs, it does not allow).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';

$archer = bk_require_archer();

// Region of the licensee (first 2 digits of their club's agreement number), read again from
// the licence file (they may have changed club).
$club = $archer->BaClubCode;
$q = safe_r_sql("SELECT LueCountry FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . " ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) $club = $r->LueCountry;
$archerRegion = strtoupper(substr((string) $club, 0, 2));   // bytes: ASCII agreement number

$facets = bk_comp_facets();
$labels = bk_disc_labels();

// Active filters
$disc = (string) ($_GET['disc'] ?? '');
if ($disc !== '' && $disc !== 'para' && !isset($facets['disc'][$disc])) $disc = '';

// Region: the choice is remembered for the next visit (cookie). Default = the licensee's
// region AS LONG AS no choice was made here. Sentinel 'ALL' = "All" (different from "not
// chosen yet") so that "All" can be remembered — it used to fall back on the licensee's
// region as soon as a competition was open there.
$rcookie = 'bk_cal_region';
if (isset($_GET['region'])) {
    // bytes: a region code is ASCII digits
    $raw = strtoupper(trim((string) $_GET['region']));
    // bytes: reduced to ASCII letters and digits first.
    $region = ($raw === 'ALL' || $raw === '') ? '' : substr(preg_replace('/[^0-9A-Za-z]/', '', $raw), 0, 2);
    @setcookie($rcookie, ($region === '' ? 'ALL' : $region), time() + 31536000, $CFG->ROOT_DIR ?: '/');
    $_COOKIE[$rcookie] = $region === '' ? 'ALL' : $region;
} elseif (!empty($_COOKIE[$rcookie])) {
    $raw = strtoupper(substr((string) $_COOKIE[$rcookie], 0, 3));   // bytes: our own ASCII value
    $region = ($raw === 'ALL') ? '' : substr($raw, 0, 2);
} else {
    $region = $archerRegion;
    // First visit, region without any open competition → All (no silent emptiness).
    if ($region !== '' && !isset($facets['regions'][$region])) $region = '';
}
$regionParam = $region === '' ? 'ALL' : $region;   // value passed in the URLs

// Competitions matching the filters (all dates), sorted by date
$comps = bk_comp_calendar(array('disc' => $disc, 'region' => $region));

// Competitions this archer already booked (badge on the tile)
$already = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) $already[intval($r->BrTournament)] = true;

// Month shown: the current one, or the month of the first competition to come
$today = bk_today();   // server-zone date (date() alone is UTC on these pages)
$ym = (new DateTime($today))->format('Y-m');
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month'])) {
    $ym = $_GET['month'];
} else {
    foreach ($comps as $c) {
        // bytes: ASCII dates.
        if (substr($c->ToWhenTo, 0, 10) >= $today) {
            $m = substr($c->ToWhenFrom, 0, 7);
            if ($m > $ym) $ym = $m;
            break;
        }
    }
}
$firstTs     = strtotime($ym . '-01');
$daysInMonth = (int) date('t', $firstTs);
$leading     = ((int) date('N', $firstTs)) - 1;     // 0 = Monday
$monthName = function ($ts) { return bk_t('Month' . (int) date('n', $ts)); };

// Competitions per day of the month (YYYY-MM-DD string comparison)
$byDay = array();
foreach ($comps as $c) {
    $from = substr($c->ToWhenFrom, 0, 10);   // bytes: ASCII dates
    $to   = substr($c->ToWhenTo, 0, 10);
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $day = sprintf('%s-%02d', $ym, $d);
        if ($day >= $from && $day <= $to) $byDay[$d][] = $c;
    }
}
$shownThisMonth = 0;
foreach ($byDay as $l) $shownThisMonth += count($l);   // >0 = at least one competition this month

// Rendered as a GRID OF CELLS + bars on top: a competition over several days spans from its
// first to its last cell. Each row is complete (Monday→Sunday): days of the neighbouring
// months are shown (faded) to fill the row. cellDate: date Y-m-d at the 0-based position of
// the grid (date arithmetic, safe across daylight saving — never adding 86400 s).
$totalCells = $leading + $daysInMonth;
$weeks = (int) ceil($totalCells / 7);
$cellDate = function ($pos) use ($ym, $leading) {
    $off = $pos - $leading;
    return date('Y-m-d', strtotime("$ym-01 " . ($off >= 0 ? '+' : '') . $off . ' days'));
};

/** Keeps the filters while changing one parameter. */
function bk_cal_url($over = array())
{
    global $disc, $regionParam, $ym;
    $p = array('disc' => $disc, 'month' => $ym);
    foreach ($over as $k => $v) if ($k !== 'region') $p[$k] = $v;
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    // The region is ALWAYS passed (sentinel 'ALL' = All) so that it can be remembered.
    $reg = array_key_exists('region', $over) ? (string) $over['region'] : $regionParam;
    $p['region'] = $reg === '' ? 'ALL' : $reg;
    return bk_public_url('calendar.php') . '?' . http_build_query($p);
}

$prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
$nextYm = date('Y-m', strtotime($ym . '-01 +1 month'));

bk_head(bk_t('CalTitle'));

// Filters: discipline chips, region list.
echo '<div class="bk-cal-filters"><div class="bk-cal-disc">'
    . '<a class="bk-dchip' . ($disc === '' ? ' on' : '') . '" href="' . bk_e(bk_cal_url(array('disc' => ''))) . '">' . bk_e(bk_t('All')) . '</a>';
foreach ($labels as $k => $lab) {
    if (empty($facets['disc'][$k])) continue;
    echo '<a class="bk-dchip' . ($disc === $k ? ' on' : '') . '" href="' . bk_e(bk_cal_url(array('disc' => $k))) . '">'
        . bk_disc_icon($k, 18) . '<span>' . bk_e($lab) . '</span></a>';
}
if (!empty($facets['para'])) {
    echo '<a class="bk-dchip' . ($disc === 'para' ? ' on' : '') . '" href="' . bk_e(bk_cal_url(array('disc' => 'para'))) . '">'
        . bk_disc_icon_para(18) . '<span>' . bk_e(bk_t('Para')) . '</span></a>';
}
echo '</div><label class="bk-cal-region">' . bk_e(bk_t('Region')) . ' <select onchange="location.href=this.value">'
    . '<option value="' . bk_e(bk_cal_url(array('region' => 'ALL'))) . '"' . ($region === '' ? ' selected' : '') . '>' . bk_e(bk_t('All')) . '</option>';
if ($region !== '' && !isset($facets['regions'][$region])) {
    echo '<option value="' . bk_e(bk_cal_url(array('region' => $region))) . '" selected>'
        . bk_e($region . ' — ' . bk_region_name($region)) . ' (0)</option>';
}
foreach ($facets['regions'] as $code => $n) {
    echo '<option value="' . bk_e(bk_cal_url(array('region' => $code))) . '"' . ($region === $code ? ' selected' : '') . '>'
        . bk_e($code . ' — ' . bk_region_name($code)) . ' (' . $n . ')</option>';
}
echo '</select></label></div>';

// Month navigation.
echo '<div class="bk-cal-nav">'
    . '<a class="bk-btn" href="' . bk_e(bk_cal_url(array('month' => $prevYm))) . '">← ' . bk_e($monthName(strtotime($prevYm . '-01'))) . '</a>'
    // bytes: a Y-m date is ASCII
    . '<b class="bk-cal-title"><span class="bk-cal-year">' . bk_e(substr($ym, 0, 4)) . '</span>' . bk_e($monthName($firstTs)) . '</b>'
    . '<a class="bk-btn" href="' . bk_e(bk_cal_url(array('month' => $nextYm))) . '">' . bk_e($monthName(strtotime($nextYm . '-01'))) . ' →</a>'
    . '</div>';

if (!$comps) {
    echo '<p class="bk-empty">' . bk_e(bk_t('CalNone')) . '</p>';
} else {
    if (!$shownThisMonth) echo '<p class="bk-hint">' . bk_e(bk_t('CalNothingMonth')) . '</p>';
    echo '<div class="bk-cal-month"><div class="bk-cal-dows">';
    for ($j = 1; $j <= 7; $j++) echo '<div class="bk-cal-dow">' . bk_e(bk_t('Dow' . $j)) . '</div>';
    echo '</div>';
    for ($w = 0; $w < $weeks; $w++) {
        // Dates of the 7 columns of the week (neighbouring months included).
        $colDate = array();
        for ($col = 1; $col <= 7; $col++) $colDate[$col] = $cellDate($w * 7 + ($col - 1));
        // Competition segments present in this week (limited to its 7 cells).
        $segs = array();
        foreach ($comps as $c) {
            $from = substr($c->ToWhenFrom, 0, 10);   // bytes: ASCII dates
            $to   = substr($c->ToWhenTo, 0, 10);
            $sc = null; $ec = null;
            for ($col = 1; $col <= 7; $col++) {
                $d = $colDate[$col];
                if ($d >= $from && $d <= $to) { if ($sc === null) $sc = $col; $ec = $col; }
            }
            if ($sc === null) continue;
            $segs[] = array('c' => $c, 'sc' => $sc, 'len' => $ec - $sc + 1,
                'contl' => ($from < $colDate[$sc]), 'contr' => ($to > $colDate[$ec]));
        }
        // Lanes (stacking without overlapping columns): they decide the height of the cells.
        usort($segs, function ($a, $b) { return $a['sc'] - $b['sc']; });
        $lanes = array();
        foreach ($segs as &$sg) {
            $end = $sg['sc'] + $sg['len'] - 1; $put = null;
            foreach ($lanes as $li => $occ) {
                $ok = true;
                foreach ($occ as $r) if (!($sg['sc'] > $r[1] || $end < $r[0])) { $ok = false; break; }
                if ($ok) { $put = $li; break; }
            }
            if ($put === null) { $put = count($lanes); $lanes[$put] = array(); }
            $lanes[$put][] = array($sg['sc'], $end); $sg['lane'] = $put;
        }
        unset($sg);

        echo '<div class="bk-cal-week" style="--lanes:' . intval(count($lanes)) . '"><div class="bk-cal-cells">';
        for ($col = 1; $col <= 7; $col++) {
            $d = $colDate[$col];
            $inMonth = (substr($d, 0, 7) === $ym);   // bytes: ASCII date
            $we = in_array((int) date('N', strtotime($d)), array(6, 7));
            $cls = ($inMonth ? '' : ' bk-cal-other') . ($d === $today ? ' bk-cal-today' : ($we ? ' bk-cal-we' : ''));
            echo '<div class="bk-cal-cell' . $cls . '"><span class="bk-cal-num">' . (int) substr($d, 8, 2) . '</span></div>';
        }
        echo '</div>';
        if ($segs) {
            echo '<div class="bk-cal-overlay">';
            foreach ($segs as $sg) {
                $c = $sg['c'];
                $dd  = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
                $lab = $sg['len'] > 1 ? $c->ToName : ($c->ToWhere ?: $c->ToName);
                echo '<a class="bk-cal-comp bk-cal-bar' . ($sg['len'] === 1 ? ' bk-cal-single' : '')
                    . (isset($already[intval($c->ToId)]) ? ' bk-cal-in' : '') . ($sg['contl'] ? ' bk-cal-contl' : '')
                    . ($sg['contr'] ? ' bk-cal-contr' : '') . '"'
                    . ' style="grid-column: ' . intval($sg['sc']) . ' / span ' . intval($sg['len']) . '; grid-row: ' . (intval($sg['lane']) + 1) . '"'
                    . ' href="' . bk_e(bk_public_url('competition.php?t=' . intval($c->ToId))) . '"'
                    . ' title="' . bk_e($c->ToName . ' — ' . $c->ToWhere) . '">'
                    . '<span class="bk-cal-ic">' . bk_disc_icon($dd['key'], 16) . ($dd['para'] ? bk_disc_icon_para(12) : '') . '</span>'
                    . '<span class="bk-cal-loc">' . bk_e($lab) . '</span></a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div><p class="bk-hint">' . bk_e(bk_t('CalClickHint'))
        . ($region !== '' || $disc !== ''
            ? ' <a href="' . bk_e(bk_cal_url(array('disc' => '', 'region' => ''))) . '">' . bk_e(bk_t('CalSeeAll')) . '</a>.' : '')
        . '</p>';
}

echo '<div id="bk-preview" class="bk-modal" hidden><div class="bk-modal-backdrop" data-close></div>'
    . '<div class="bk-modal-sheet" role="dialog" aria-modal="true" aria-label="' . bk_e(bk_t('CompDetailAria')) . '">'
    . '<button type="button" class="bk-modal-x" data-close aria-label="' . bk_e(bk_t('Close')) . '">×</button>'
    . '<div class="bk-modal-body"></div>'
    . '<div class="bk-modal-hint" aria-hidden="true"><span>' . bk_e(bk_t('ScrollMore')) . '</span></div></div></div>';
?>
<script>
(function () {
  var modal = document.getElementById('bk-preview');
  if (!modal || !window.fetch) return;                 // fallback: the tiles stay links
  var body = modal.querySelector('.bk-modal-body'),
      sheet = modal.querySelector('.bk-modal-sheet'), last = null;
  var LOAD = '<p class="bk-hint">' + <?= json_encode(bk_e(bk_t('Loading')), JSON_UNESCAPED_UNICODE) ?> + '</p>';
  // Scroll hint: shown while there is content below the visible area;
  // hidden once at the bottom.
  function updateHint() {
    var more = sheet.scrollHeight - sheet.clientHeight - sheet.scrollTop > 8;
    sheet.classList.toggle('bk-can-scroll', more);
  }
  sheet.addEventListener('scroll', updateHint);
  window.addEventListener('resize', updateHint);
  function open()  { last = document.activeElement; body.innerHTML = LOAD; modal.hidden = false;
                     document.body.classList.add('bk-modal-open'); }
  function close() { modal.hidden = true; document.body.classList.remove('bk-modal-open');
                     body.innerHTML = ''; sheet.classList.remove('bk-can-scroll'); if (last && last.focus) last.focus(); }
  modal.addEventListener('click', function (e) { if (e.target.hasAttribute('data-close')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
  Array.prototype.forEach.call(document.querySelectorAll('.bk-cal-comp'), function (a) {
    a.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;   // new tab: let it go
      e.preventDefault();
      var url = a.getAttribute('href'), sep = url.indexOf('?') >= 0 ? '&' : '?';
      open();
      fetch(url + sep + 'embed=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { if (!r.ok) throw 0; return r.text(); })
        .then(function (html) { body.innerHTML = html; sheet.scrollTop = 0; updateHint(); })
        .catch(function () { window.location.href = url; });                 // fallback: full page
    });
  });
})();
</script>
<?php bk_foot(); ?>
