<?php
/**
 * public/index.php — home of the signed-in licensee: survey invitations, waiting-list news,
 * sums still owed, then licence, history, next registrations, competitions suggested to the
 * archer (lib/suggest.php), and the federation news below.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/registration.php';

$archer = bk_require_archer();

// Current club, date of birth and sex: read again from the licence file rather than copied from
// the account — an archer may change club between two seasons.
$club = $archer->BaClubCode;
$clubName = '';
$lue = bk_lookup_licence($archer->BaLicence);
if ($lue) {
    $club     = $lue->LueCountry;
    $clubName = $lue->LueCoDescr;
}

// The volunteer space has its link under the archer's name here: no banner for it on this page.
define('BK_VOL_BOX', true);
bk_head(bk_t('NavHome'));
// The History block reuses the statistics' styles (next distinction, its picture and bar).
echo '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/stats.css')) . '?v=' . bk_e(bk_version()) . '">'
    . '<h1>' . bk_t('HomeHello', bk_e(bk_name_title($archer->BaName))) . '</h1>';

// Volunteer of a team with a post open now: the way in, kept here once the banner is closed.
$vol = bk_vol_competitions($archer);
if ($vol) {
    echo '<div class="bk-sv-banner bk-vol-box"><span class="bk-sv-banner-txt">'
       . (count($vol) === 1 ? bk_t('VolBoxOne', '<b>' . bk_e($vol[0]['name']) . '</b>') : bk_t('VolBoxMany', count($vol))) . '</span>'
       . '<a class="bk-btn bk-btn-primary" style="width:auto" href="' . bk_e(bk_vol_url($vol)) . '">'
       . bk_e(bk_t('VolBoxBtn')) . '</a></div>';
}

// Satisfaction surveys waiting for this archer (open, not answered yet), newest first.
require_once dirname(__DIR__) . '/lib/survey.php';
$svWaiting = array_filter(bk_survey_open_for($archer->BaLicence), function ($s) { return !intval($s->Answered); });
foreach (array_slice($svWaiting, 0, 2) as $sv) {
    echo '<div class="bk-sv-banner"><span class="bk-sv-banner-txt">'
       . bk_t('HomeSurveyBanner', array('name' => bk_e($sv->ToName), 'until' => bk_e(bk_date_fr($sv->CloseOn)))) . '</span>'
       . '<a class="bk-btn bk-btn-primary" style="width:auto" href="' . bk_e(bk_public_url('survey.php?t=' . intval($sv->ToId))) . '">'
       . bk_e(bk_t('SurveyGive')) . '</a></div>';
}

// Waiting lists: look for freed places in the competitions this archer waits on (the
// archer is told on the site only), then announce what happened since their last visit.
require_once dirname(__DIR__) . '/lib/waitlist.php';
bk_waitlist_process_for($archer->BaId, $archer->BaLicence);
foreach (bk_waitlist_for_archer($archer->BaId, $archer->BaLicence) as $w) {
    if (intval($w->BwStatus) === 0 || intval($w->BwSeen)) continue;
    $self = bk_clean_licence($w->BwLicence) === bk_clean_licence($archer->BaLicence);
    $txt = intval($w->BwStatus) === 1
        ? bk_t('HomeWaitGot', array('name' => bk_e($w->ToName), 'session' => intval($w->BwSession),
            'who' => $self ? bk_t('WaitYouIn') : bk_t('WaitOtherIn', bk_e(trim($w->LueFamilyName . ' ' . $w->LueName)))))
        : bk_t('HomeWaitRemoved', array('name' => bk_e($w->ToName), 'note' => bk_e($w->BwNote)));
    echo '<div class="bk-sv-banner"><span class="bk-sv-banner-txt">' . $txt . '</span>'
       . '<a class="bk-btn bk-btn-primary" style="width:auto" href="' . bk_e(bk_public_url('registrations.php')) . '">'
       . bk_e(bk_t('NavMyRegs')) . '</a></div>';
}

// Sums still owed to the organisers of competitions that are over, shown until paid: an
// account is often opened on site and settled at the end, and easily forgotten. Only where
// the organiser records payments here (bk_ledger_tracked).
require_once dirname(__DIR__) . '/lib/payment.php';
$owed = array_filter(bk_archer_accounts(bk_clean_licence($archer->BaLicence)), function ($x) {
    return $x['past'] && $x['tracked'] && $x['remaining'] > 0.005;
});
if ($owed) {
    echo '<div class="bk-owed"><h2>' . bk_e(bk_t('LeftToPay')) . '</h2><ul>';
    foreach ($owed as $x) {
        $ways = array();
        foreach ($x['payinfo'] as $pi) $ways[] = bk_e($pi['label']) . ($pi['info'] !== '' ? ' (' . bk_linkify($pi['info']) . ')' : '');
        echo '<li>' . bk_t('HomeOwedLine', array('amount' => bk_e(bk_eur($x['remaining'], false, $x['ToId'])), 'name' => bk_e($x['ToName']),
                'dates' => bk_e(bk_date_range($x['ToWhenFrom'], $x['ToWhenTo']))))
            . ' — <a href="' . bk_e(bk_public_url('receipt.php?comp=' . $x['ToId'])) . '">' . bk_e(bk_t('Detail')) . '</a>'
            . ($ways ? '<br><span class="bk-hint">' . bk_t('WaysAccepted', implode(', ', $ways)) . '</span>' : '') . '</li>';
    }
    echo '</ul></div>';
}

// The four blocks are built first, then shown in this order: licence, history, registrations,
// competitions — four side by side when there is room, two otherwise, one on a phone; the news
// below, on the same columns.

// Licence: three lines without labels — licence and age class, civility and name, club. An
// archer without an FFTA licence signs in with an identifier ("COUNTRY-licence", or "WA-id" when
// picked from World Archery: then a link to their WA page).
$isOther = ($archer->BaKind ?? 'FFTA') === 'OTHER';
$fromWa = $isOther && ($archer->BaSource ?? '') === 'wa' && intval($archer->BaWaId ?? 0) > 0;
if ($fromWa) require_once dirname(__DIR__) . '/lib/other.php';
$ageClass = $lue ? bk_age_class($lue->LueCtrlCode) : '';
$civility = !$lue ? '' : (intval($lue->LueSex) === 1 ? bk_t('CivF') : (intval($lue->LueSex) === 0 ? bk_t('CivM') : ''));
$clubLine = array_filter(array(trim((string) $clubName), trim((string) $club)), function ($v) { return $v !== ''; });
// The FFTA certificate exists for FFTA licensees only; the others keep their profile up to date.
$titleBtn = $isOther
    ? '<a class="bk-btn bk-btn-sm" href="' . bk_e(bk_public_url('profile.php')) . '">' . bk_e(bk_t('OtProfileTitle')) . '</a>'
    : '<a class="bk-btn bk-btn-sm" href="' . bk_e(bk_public_url('licence.php')) . '" target="_blank" rel="noopener">' . bk_e(bk_t('LicenceCertBtn')) . '</a>';
$blkLicence = '<section class="bk-block"><div class="bk-block-h"><h2>' . bk_e(bk_t('MyLicence')) . '</h2>' . $titleBtn . '</div>'
    . '<div class="bk-idcard"><p>' . bk_e($archer->BaLicence . ($ageClass !== '' ? ' - ' . $ageClass : ''))
    . ($fromWa ? ' <a class="bk-hint" href="' . bk_e(bk_wa_profile_url($archer->BaWaId, $archer->BaName, $archer->BaFamilyName))
        . '" target="_blank" rel="noopener noreferrer">' . bk_e(bk_t('OtWaPage')) . ' ↗</a>' : '') . '</p>'
    . '<p><b>' . bk_e(trim($civility . ' ' . mb_strtoupper((string) $archer->BaFamilyName, 'UTF-8') . ' ' . bk_name_title($archer->BaName))) . '</b></p>'
    . '<p>' . bk_e($clubLine ? implode(' - ', $clubLine) : '—') . '</p></div>'
    // Always on one line, sharing the width: deletion on the left, security on the right.
    . '<p class="bk-actions bk-actions-split">'
    . '<a class="bk-btn bk-btn-danger" href="' . bk_e(bk_public_url('delete-account.php')) . '">' . bk_e(bk_t('DelTitle')) . '</a>'
    . '<a class="bk-btn" href="' . bk_e(bk_public_url('security.php')) . '">'
    . bk_e(bk_t(!empty($archer->BaTotpEnabled) ? 'SecurityBtn2fa' : 'SecurityBtn')) . '</a></p></section>';

// Registrations: the next three competitions not over (the one under way included), one line
// per departure with its day and time. bk_my_registrations() lists one row per registration.
$next = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) {
    if (bk_is_finished($r->ToWhenTo)) continue;
    $t = intval($r->BrTournament);
    if (!isset($next[$t])) $next[$t] = array('c' => $r, 'ses' => array());
    // A registration suspended by the licence check keeps its departure aside (BrHoldSession).
    $ses = intval(!empty($r->BrLicHold) ? $r->BrHoldSession : $r->QuSession);
    if ($ses > 0) $next[$t]['ses'][$ses] = true;
}
uasort($next, function ($a, $b) { return strcmp($a['c']->ToWhenFrom, $b['c']->ToWhenFrom); });
$next = array_slice($next, 0, 3, true);
$blkRegs = '<section class="bk-block"><div class="bk-block-h"><h2>' . bk_e(bk_t('HomeRegsTitle')) . '</h2>'
    . '<a class="bk-btn bk-btn-sm" href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('AllMyRegs')) . '</a></div>';
if ($next) {
    $blkRegs .= '<ul class="bk-mini">';
    foreach ($next as $t => $g) {
        $c = $g['c'];
        // The town, as in the calendar; the name of the competition in the tooltip.
        $place = trim((string) $c->ToVenue) ?: (trim((string) $c->ToWhere) ?: $c->ToName);
        $starts = array();
        foreach (bk_comp_sessions($t) as $s) $starts[intval($s->SesOrder)] = bk_session_start($s);
        $deps = array_keys($g['ses']);
        usort($deps, function ($a, $b) use ($starts) {   // by start, departures without one last
            return strcmp((($starts[$a] ?? '') ?: '9999') . sprintf('|%04d', $a), (($starts[$b] ?? '') ?: '9999') . sprintf('|%04d', $b));
        });
        // The place leads to the archer's registrations there, "Infos" to the competition's page.
        $blkRegs .= '<li><div class="bk-mini-head"><a href="' . bk_e(bk_public_url('registrations.php?t=' . $t)) . '" title="' . bk_e($c->ToName) . '"><b>'
            . bk_e($place) . '</b></a><a class="bk-btn bk-btn-xs" href="' . bk_e(bk_public_url('competition.php?t=' . $t)) . '">'
            . bk_e(bk_t('InfoBtn')) . '</a></div>';
        foreach ($deps as $n) {
            $when = '';
            if (($starts[$n] ?? '') !== '') {
                $ts = strtotime($starts[$n]);
                $when = date(bk_t('HomeDepDateFmt'), $ts);
                if (date('H:i', $ts) !== '00:00') $when .= ' ' . date(bk_t('TimeFormat'), $ts);   // 00:00 = no time typed
            }
            $blkRegs .= '<span class="bk-hint bk-mini-line">' . bk_e(bk_t('DepCap', $n) . ($when !== '' ? ' - ' . $when : '')) . '</span>';
        }
        $blkRegs .= '</li>';
    }
    $blkRegs .= '</ul>';
} else {
    $blkRegs .= '<p class="bk-empty">' . bk_e(bk_t('NoRegYet')) . '</p>';
}
$blkRegs .= '</section>';

// Competitions: the three most likely to interest the archer (lib/suggest.php), on three lines —
// place and dates; discipline and form with or without duels; departures and how full they are
// (the gauge only where the organiser shows them). On a computer only: on a phone the calendar
// is one tap away in the bottom bar.
require_once dirname(__DIR__) . '/lib/suggest.php';
$labels = bk_disc_labels();
$blkComps = '<section class="bk-block bk-hide-phone"><div class="bk-block-h"><h2>' . bk_e(bk_t('HomeCompsTitle')) . '</h2>'
    . '<a class="bk-btn bk-btn-sm" href="' . bk_e(bk_public_url('calendar.php')) . '">' . bk_e(bk_t('NavCalendar')) . '</a></div>';
$sugg = bk_comp_suggest($archer, $lue, 3);
if ($sugg) {
    $blkComps .= '<ul class="bk-mini">';
    foreach ($sugg as $x) {
        $c = $x['c']; $t = intval($c->ToId);
        $place = trim((string) $c->ToVenue) ?: (trim((string) $c->ToWhere) ?: $c->ToName);
        $what = array_filter(array(($labels[$x['disc']['key']] ?? '') . ($x['disc']['para'] ? ' · ' . bk_t('Para') : ''),
            $x['format'], $x['duels'] ? bk_t('MnWithDuels') : ''));   // duels told, their absence not
        $left = max(0, $x['places'] - $x['taken']);
        $pc = $x['places'] > 0 ? min(100, round($x['taken'] * 100 / $x['places'])) : 0;
        $blkComps .= '<li><a href="' . bk_e(bk_public_url('competition.php?t=' . $t)) . '" title="' . bk_e($c->ToName) . '"><b>'
            . bk_e($place) . '</b></a> <span class="bk-hint">' . bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) . '</span>'
            . '<br><span class="bk-hint">' . bk_e(implode(' · ', $what)) . '</span>'
            . '<span class="bk-sugg-dep"><span class="bk-hint">' . bk_e(bk_t($x['sessions'] > 1 ? 'DepCountMany' : 'DepCountOne', $x['sessions'])) . '</span>'
            . ((!empty($c->BcShowGauges) && $x['places'] > 0)
                ? '<span class="bk-dep-gauge"><span class="bk-gauge' . ($x['full'] ? ' bk-gauge-full' : '') . '"><i style="width:' . $pc . '%"></i></span>'
                    . '<span class="bk-dep-num">' . bk_e(bk_t($left > 1 ? 'PlacesMany' : 'PlacesOne', $left)) . '</span></span>'
                : '')
            . '</span></li>';
    }
    $blkComps .= '</ul>';
} else {
    $blkComps .= '<p class="bk-empty">' . bk_e(bk_t('NoOpenComp')) . '</p>';
}
$blkComps .= '</section>';

// History (lib/stats.php): competitions shot on this server, arrows, the year of the first one,
// and the next national distinction to reach — in the discipline of the next competition the
// archer is registered in, else in the last one they shot. Before any competition, what will
// show here.
require_once dirname(__DIR__) . '/lib/stats.php';
$hist = bk_stats_rounds($archer->BaLicence);
$blkHistory = '<section class="bk-block"><div class="bk-block-h"><h2>' . bk_e(bk_t('HomeHistory')) . '</h2>'
    . '<a class="bk-btn bk-btn-sm" href="' . bk_e(bk_public_url('stats.php')) . '">' . bk_e(bk_t('MyFollowUp')) . '</a></div>';
if (!$hist) {
    $blkHistory .= '<p class="bk-hint">' . bk_e(bk_t('HomeHistNone')) . '</p>';
} else {
    $tot = bk_stats_totals($hist);
    $seps = bk_number_seps();
    $fig = function ($v, $l) { return '<div><span class="v">' . $v . '</span><span class="l">' . bk_e($l) . '</span></div>'; };
    $blkHistory .= '<div class="bk-hh-figs">'
        . $fig(number_format($tot['tours'], 0, $seps['dec'], $seps['thousands']), bk_t($tot['tours'] > 1 ? 'StatComps' : 'StatComp'))
        . $fig(number_format($tot['arrows'], 0, $seps['dec'], $seps['thousands']), bk_t('StatArrows'))
        . $fig(bk_e(substr($hist[0]['date'], 0, 4)), bk_t('HomeHistSince')) . '</div>';   // bytes: ASCII date

    $up = bk_stats_upcoming_ctx($archer->BaLicence);
    $ctx = $up['ctx'] ?? null;
    $ref = $up['round'] ?? null;
    if (!$ctx) {
        foreach (array_reverse($hist) as $r) {
            if ($c = bk_stats_round_ctx($r)) { $ctx = $c; $ref = $r; break; }
        }
    }
    if ($ctx) {
        // The age class of that competition: a new season may open (or close) a level.
        $b = bk_stats_ctx_badges($ctx, bk_stats_badges($hist), $ref['age']);
        $shot = false;
        foreach ($hist as $r) if ($r['combo'] === $ref['combo']) $shot = true;
        $blkHistory .= '<div class="bk-hh-next"><h3>' . bk_e(bk_t('StatNext')) . '</h3><p class="bk-hint">'
            . bk_e($up ? bk_t('HomeHistForNext', $ref['name']) : bk_t('HomeHistForLast')) . '<br>'
            . bk_e(bk_t('DistDisc' . $ref['disc']) . ' · ' . $ref['bowName'] . ($ref['dist'] > 0 ? ' · ' . $ref['dist'] . ' m' : '')) . '</p>'
            . bk_stats_next_html($b, bk_public_url(), 56)
            // The page of that combination, once the archer has shot it.
            . ($shot ? '<p class="bk-hh-more"><a href="' . bk_e(bk_public_url('stats-detail.php?k=' . rawurlencode($ref['combo']))) . '">'
                . bk_e(bk_t('HomeHistDetail')) . ' →</a></p>' : '')
            . '</div>';
    }
}
$blkHistory .= (bk_is_manager() ? '<p class="bk-actions"><a class="bk-btn" href="' . bk_e(bk_public_url('club.php')) . '">' . bk_e(bk_t('ClubBtn')) . '</a></p>' : '')
    . '</section>';

echo '<div class="bk-home-grid">' . $blkLicence . $blkHistory . $blkRegs . $blkComps . '</div>';

// Federation news, loaded by the script below: title and hint, the button beside them, then
// the news on the columns of the blocks above.
echo '<section class="bk-block bk-home-news" id="bk-news" style="display:none"><div class="bk-block-h"><div>'
    . '<h2>' . bk_e(bk_t('News')) . '</h2><p class="bk-hint">' . bk_e(bk_t('NewsHint')) . '</p></div>'
    . '<a class="bk-btn bk-btn-sm" href="https://www.ffta.fr/actualites" target="_blank" rel="noopener">' . bk_e(bk_t('NewsAll')) . '</a></div>'
    . '<ul class="bk-news-list" id="bk-news-list"></ul></section>';
?>
<script>
/* Federation news, loaded asynchronously (no network call while the page is built). Titles
   set through textContent (never HTML from the feed). The section stays hidden when the
   feed is unavailable. */
(function () {
  fetch(<?= json_encode(bk_public_url('news.php')) ?>, { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d || d.error !== 0 || !d.items || !d.items.length) return;
      var ul = document.getElementById('bk-news-list');
      d.items.forEach(function (it) {
        if (!it.link || !/^https?:\/\//i.test(it.link)) return;
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = it.link; a.target = '_blank'; a.rel = 'noopener';
        a.textContent = it.title || it.link;
        li.appendChild(a);
        if (it.date) {
          var s = document.createElement('span');
          s.className = 'bk-news-date';
          s.textContent = it.date;
          li.appendChild(s);
        }
        ul.appendChild(li);
      });
      document.getElementById('bk-news').style.display = '';
    })
    .catch(function () {});
})();
</script>
<?php bk_foot(); ?>
