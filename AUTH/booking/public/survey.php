<?php
/**
 * public/survey.php — the archer's satisfaction survey for one competition (?t=ToId).
 *
 * Open from the day after the competition, for BK_SURVEY_DAYS days, to archers with a
 * qualification score. Nothing is mandatory; the archer can come back and change the
 * answers until the survey closes. Rules and anonymity: lib/survey.php.
 * Markup produced in PHP (no template alternation).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/survey.php';

$archer = bk_require_archer();
$tourId = intval($_GET['t'] ?? ($_POST['t'] ?? 0));
$acc = bk_survey_access($tourId, $archer->BaLicence);
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (!$acc['ok']) {
        $err = bk_t('SvNotOpenSaved');
    } else {
        $r = bk_survey_save($tourId, $archer->BaLicence, $_POST);
        bk_log($r === 'saved' ? 'SURVEY_SAVED' : 'SURVEY_EMPTY', $archer->BaLicence);
        bk_redirect('survey.php?t=' . $tourId . '&done=' . $r);
    }
}

$comp = $acc['comp'];
$ans = $acc['answer'];
$done = (string) ($_GET['done'] ?? '');

bk_head(bk_t('SvHead'));
$h = '';
if (!$comp) {
    $h .= bk_msg('err', bk_t('CompNotFound'));
    $h .= '<p><a class="bk-btn" href="' . bk_e(bk_public_url()) . '">' . bk_e(bk_t('BackMySpace')) . '</a></p>';
    echo $h;
    bk_foot();
    exit;
}

$h .= '<h1>' . bk_e(bk_t('SvTitle', $comp->ToName)) . '</h1>';
$h .= '<p class="bk-hint bk-sv-where">' . bk_e(trim($comp->ToWhere . ' — ' . bk_date_range($comp->ToWhenFrom, $comp->ToWhenTo), ' —')) . '</p>';
if ($err !== '') $h .= bk_msg('err', $err);
if ($done === 'saved') {
    $h .= bk_msg('ok', bk_t('SvSaved', bk_date_fr($comp->CloseOn)));
} elseif ($done === 'empty') {
    $h .= bk_msg('ok', bk_t('SvEmpty'));
}

if (!$acc['ok']) {
    $why = array(
        'off'             => bk_t('SvWhyOff'),
        'not_yet'         => bk_t('SvWhyNotYet', bk_date_fr($comp->OpenOn)),
        'closed'          => bk_t('SvWhyClosed', bk_date_fr($comp->ClosedOn)),
        'not_participant' => bk_t('SvWhyNotRanked'),
        'already'         => bk_t('SvWhyAlready'),
    );
    $h .= '<div class="bk-block"><p class="bk-empty">' . bk_e($why[$acc['reason']] ?? bk_t('SvUnavailable')) . '</p>'
        . '<p class="bk-actions"><a class="bk-btn" href="' . bk_e(bk_public_url()) . '">' . bk_e(bk_t('BackMySpace')) . '</a></p></div>';
    echo $h;
    bk_foot();
    exit;
}

$h .= '<div class="bk-sv-intro">' . bk_t('SvIntro', bk_e(bk_date_fr($comp->CloseOn))) . '</div>';

$h .= '<form method="post" action="' . bk_e(bk_public_url('survey.php?t=' . $tourId)) . '" class="bk-sv">'
    . bk_csrf_field() . '<input type="hidden" name="t" value="' . $tourId . '">';
$n = 0;
foreach (bk_survey_questions() as $sec) {
    $n++;
    $h .= '<section class="bk-block bk-sv-sec"><h2>' . $n . ') ' . bk_e($sec['title']) . '</h2>'
        . '<p class="bk-hint bk-sv-secintro">' . bk_e($sec['intro']) . '</p>';
    foreach ($sec['items'] as $it) {
        $cur = $ans ? intval($ans->{$it['col']}) : 0;
        $h .= '<fieldset class="bk-sv-q"><legend>' . bk_e($it['label'])
            . (!empty($it['hint']) ? ' <span class="bk-hint">' . bk_e($it['hint']) . '</span>' : '') . '</legend>'
            . '<div class="bk-sv-scale">';
        for ($v = 1; $v <= 5; $v++) {
            $h .= '<label><input type="radio" name="' . $it['col'] . '" value="' . $v . '"' . ($cur === $v ? ' checked' : '') . '>'
                . '<span>' . $v . '</span></label>';
        }
        $h .= '</div><div class="bk-sv-ends"><span>' . bk_e($it['low'] ?? bk_t('SvBad')) . ' 👎</span><span>' . bk_e(bk_t('SvExcellent')) . ' 👍</span></div>'
            . '</fieldset>';
    }
    foreach ($sec['texts'] as $tx) {
        $val = $ans ? (string) $ans->{$tx['col']} : '';
        $h .= '<label class="bk-sv-t"><span>' . bk_e($tx['label']) . '</span>'
            . '<textarea name="' . $tx['col'] . '" rows="3" maxlength="' . BK_SURVEY_TEXT_MAX . '">' . bk_e($val) . '</textarea></label>';
    }
    $h .= '</section>';
}
$h .= '<p class="bk-sv-send"><button type="submit" class="bk-btn bk-btn-primary">'
    . bk_e(bk_t($ans ? 'SvUpdate' : 'SvSend')) . '</button></p></form>';
echo $h;
?>
<script>
/* Ratings are optional: tapping the selected value again clears it. The label forwards
   a second, synthetic click to its radio (already checked by then): ignore that one. */
[].forEach.call(document.querySelectorAll('#bk .bk-sv-scale label'), function (lb) {
  lb.addEventListener('click', function (e) {
    if (e.target.tagName === 'INPUT') return;
    var r = lb.querySelector('input');
    if (r && r.checked) { e.preventDefault(); r.checked = false; }
  });
});
</script>
<?php
bk_foot();
