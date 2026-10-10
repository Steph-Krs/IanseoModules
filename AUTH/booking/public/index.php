<?php
/**
 * public/index.php — home of the signed-in licensee: survey invitations, waiting-list news,
 * sums still owed, licence, latest registrations, open competitions, federation news.
 */
require_once __DIR__ . '/boot.php';

$archer = bk_require_archer();

// Current club: read again from the licence file rather than copied from the account — an
// archer may change club between two seasons.
$club = $archer->BaClubCode;
$clubName = '';
$q = safe_r_sql("SELECT LueCountry, LueCoDescr FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . "
    ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) {
    $club     = $r->LueCountry;
    $clubName = $r->LueCoDescr;
}

bk_head(bk_t('NavHome'));
echo '<h1>' . bk_t('HomeHello', bk_e($archer->BaName)) . '</h1>';

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

echo '<div class="bk-grid">';

// Licence. An archer picked from World Archery signs in with their WA identifier.
$fromWa = ($archer->BaKind ?? 'FFTA') === 'OTHER' && ($archer->BaSource ?? '') === 'wa' && intval($archer->BaWaId ?? 0) > 0;
if ($fromWa) require_once dirname(__DIR__) . '/lib/other.php';
echo '<section class="bk-block"><h2>' . bk_e(bk_t('MyLicence')) . '</h2><dl class="bk-dl">'
    . '<dt>' . bk_e(bk_t($fromWa ? 'OtWaIdLabel' : 'Licence')) . '</dt><dd>' . bk_e($archer->BaLicence)
    . ($fromWa ? ' <a class="bk-hint" href="' . bk_e(bk_wa_profile_url($archer->BaWaId, $archer->BaName, $archer->BaFamilyName))
        . '" target="_blank" rel="noopener noreferrer">' . bk_e(bk_t('OtWaPage')) . ' ↗</a>' : '') . '</dd>'
    . '<dt>' . bk_e(bk_t('FamilyName')) . '</dt><dd>' . bk_e($archer->BaFamilyName) . '</dd>'
    . '<dt>' . bk_e(bk_t('GivenName')) . '</dt><dd>' . bk_e($archer->BaName) . '</dd>'
    . '<dt>' . bk_e(bk_t('Club')) . '</dt><dd>' . bk_e($clubName ?: '—') . ($club ? ' <span class="bk-code">' . bk_e($club) . '</span>' : '') . '</dd>'
    . '</dl><p class="bk-actions">'
    // The FFTA certificate exists for FFTA licensees only; the others keep their profile up to date.
    . ((($archer->BaKind ?? 'FFTA') === 'OTHER')
        ? '<a class="bk-btn" href="' . bk_e(bk_public_url('profile.php')) . '">' . bk_e(bk_t('OtProfileTitle')) . '</a> '
        : '<a class="bk-btn" href="' . bk_e(bk_public_url('licence.php')) . '" target="_blank" rel="noopener">' . bk_e(bk_t('LicenceCertBtn')) . '</a> ')
    . '<a class="bk-btn" href="' . bk_e(bk_public_url('security.php')) . '">'
    . bk_e(bk_t(!empty($archer->BaTotpEnabled) ? 'SecurityBtn2fa' : 'SecurityBtn')) . '</a> '
    . '<a class="bk-btn bk-btn-danger" href="' . bk_e(bk_public_url('delete-account.php')) . '">' . bk_e(bk_t('DelTitle')) . '</a></p></section>';

// Latest registrations.
require_once dirname(__DIR__) . '/lib/registration.php';
$mine = bk_my_registrations($archer->BaLicence);
echo '<section class="bk-block"><h2>' . bk_e(bk_t('NavMyRegs')) . '</h2>';
if ($mine) {
    echo '<ul class="bk-mini">';
    foreach (array_slice($mine, 0, 4) as $r) {
        echo '<li><b>' . bk_e($r->ToName) . '</b><br><span class="bk-hint">' . bk_e(bk_date_range($r->ToWhenFrom, $r->ToWhenTo))
            . ' — ' . bk_e(bk_t('DepLower', intval($r->QuSession))) . '</span></li>';
    }
    echo '</ul><p><a class="bk-btn" href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('AllMyRegs')) . '</a></p>';
} else {
    echo '<p class="bk-empty">' . bk_e(bk_t('NoRegYet')) . '</p>';
}
echo '</section>';

// Competitions open for registration.
require_once dirname(__DIR__) . '/lib/competition.php';
$n = count(bk_comp_calendar());
echo '<section class="bk-block"><h2>' . bk_e(bk_t('OpenComps')) . '</h2>';
if ($n) {
    echo '<p>' . bk_e(bk_t($n > 1 ? 'OpenCountMany' : 'OpenCountOne', $n)) . '</p>'
        . '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('calendar.php')) . '">' . bk_e(bk_t('SeeCalendar')) . '</a></p>';
} else {
    echo '<p class="bk-empty">' . bk_e(bk_t('NoOpenComp')) . '</p>';
}
echo '</section>';

// Follow-up.
echo '<section class="bk-block"><h2>' . bk_e(bk_t('MyFollowUp')) . '</h2>'
    . '<p class="bk-hint">' . bk_e(bk_t(bk_is_manager() ? 'FollowHintClub' : 'FollowHint')) . '</p><p class="bk-actions">'
    . '<a class="bk-btn" href="' . bk_e(bk_public_url('stats.php')) . '">' . bk_e(bk_t('StatsBtn')) . '</a> '
    . '<a class="bk-btn" href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('RegsBtn')) . '</a>'
    . (bk_is_manager() ? ' <a class="bk-btn" href="' . bk_e(bk_public_url('club.php')) . '">' . bk_e(bk_t('ClubBtn')) . '</a>' : '')
    . '</p></section>';

// Federation news, loaded by the script below.
echo '<section class="bk-block" id="bk-news" style="display:none"><h2>' . bk_e(bk_t('News')) . '</h2>'
    . '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('NewsHint')) . '</p>'
    . '<ul class="bk-news-list" id="bk-news-list"></ul>'
    . '<p><a class="bk-btn" href="https://www.ffta.fr/actualites" target="_blank" rel="noopener">' . bk_e(bk_t('NewsAll')) . '</a></p>'
    . '</section></div>';
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
