<?php
/**
 * Create a training: a format, a date and a starting time, the archers ticked,
 * their category when it differs, and the teams when the format has team events.
 *
 * Every time of the format moves with the starting time, keeping its gaps. The
 * new competition is opened at once and the coach lands on its dashboard.
 */

require_once __DIR__ . '/lib/boot.php';
trn_require_admin();

$formats = trn_tpl_list();
$archers = trn_archers(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('create.php');
    }
    $tm = intval($_POST['tpl'] ?? 0);
    $fmt = $formats[$tm] ?? null;
    $date = (string)($_POST['date'] ?? '');
    $time = (string)($_POST['time'] ?? '');
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
    $chosen = array_values(array_filter(array_map('intval', array_keys((array)($_POST['a'] ?? []))),
        function ($id) use ($archers) { return isset($archers[$id]); }));
    $back = 'create.php?tpl=' . $tm;
    if (!$fmt) { trn_flash(trn_text('ErrNoFormat'), 'err'); trn_redirect('create.php'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { trn_flash(trn_text('ErrDate'), 'err'); trn_redirect($back); }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '09:00';
    if (!$chosen) { trn_flash(trn_text('ErrNoArcher'), 'err'); trn_redirect($back); }
    if ($name === '') $name = $fmt->TmName . ' - ' . trn_date($date);
    $qualSim = !empty($_POST['qualsim']);

    $res = trn_create_competition($tm, $name, $date, $time, $qualSim);
    if (isset($res['error'])) { trn_flash(trn_text($res['error']), 'err'); trn_redirect($back); }
    $tourId = intval($res['id']);

    require_once 'Common/UpdatePreOpen.inc.php';
    UpdatePreOpen($tourId);
    CreateTourSession($tourId);

    $sessions = trn_sessions($tourId);
    $firstSes = $sessions ? array_key_first($sessions) : 0;
    $map = [];
    $skipped = 0;
    $tourCats = trn_tour_categories($tourId);
    $endYear = intval(safe_fetch(safe_r_sql("SELECT YEAR(ToWhenTo) AS Y FROM Tournament WHERE ToId = $tourId"))->Y);
    foreach ($chosen as $aid) {
        $cat = explode('|', (string)($_POST['cat'][$aid] ?? ''), 2);
        if (count($cat) !== 2) $cat = explode('|', trn_default_category($tourCats, $archers[$aid], $endYear), 2);
        $ses = intval($_POST['ses'][$aid] ?? $firstSes);
        if (!isset($sessions[$ses])) $ses = $firstSes;
        $enId = trn_add_entry($tourId, $archers[$aid], $cat[0], $cat[1] ?? '', $ses);
        if ($enId) $map[(string)$aid] = $enId; else $skipped++;
    }

    trn_teams_save($tourId, trn_teams_from_post($_POST, $map));
    if ($qualSim) trn_simulate_qualification($tourId); else trn_recalc($tourId);

    $left = 0;
    if (!empty($_POST['place'])) {
        foreach (array_keys($sessions) as $s) $left += trn_place_session($tourId, $s)['left'];
    }

    trn_flash(trn_text('Created', ['code' => $res['code'], 'n' => count($map)]));
    if ($skipped) trn_flash(trn_text('CreatedSkipped', $skipped), 'err');
    if ($left) trn_flash(trn_text('PlacedLeft', $left), 'err');
    trn_redirect('dashboard.php');
}

$tm = intval($_GET['tpl'] ?? 0);
if (!$tm && count($formats) === 1) $tm = intval(array_key_first($formats));
$fmt = $formats[$tm] ?? null;

trn_page_open(trn_text('CreateTitle'), ['assets/training.js']);

if (!$formats) {
    echo '<p class="trn-note">' . trn_t('HomeNoFormat') . '</p>';
    trn_page_close();
    exit;
}

echo '<form method="get" class="trn-card"><label>' . trn_t('ColFormat') . ' <select name="tpl" onchange="this.form.submit()">';
echo '<option value="0">' . trn_t('ChooseFormat') . '</option>';
foreach ($formats as $f) {
    echo '<option value="' . intval($f->TmId) . '"' . (intval($f->TmId) === $tm ? ' selected' : '') . '>' . trn_esc($f->TmName) . '</option>';
}
echo '</select></label> <noscript><button type="submit" class="trn-btn">' . trn_t('Choose') . '</button></noscript></form>';

if (!$fmt) {
    trn_page_close();
    exit;
}

$s = $fmt->Summary;
$cats = $s['cats'] ?? [];
$classes = $s['classes'] ?? [];
$sessions = $s['sessions'] ?? [];
$hasDuels = false;
foreach ($s['events'] ?? [] as $e) if (!empty($e['duels'])) $hasDuels = true;
$ref = (string)$fmt->TmRefStart;
$refTime = mb_substr($ref, 11, 5);
if ($refTime === '' || $refTime === '00:00') $refTime = '09:00';
// Season year when the training starts today; the script recomputes it with the date chosen.
$year = trn_season_year(!empty($s['fr']), date('Y-m-d'), date('Y-m-d', strtotime('+' . max(0, intval($s['days'] ?? 1) - 1) . ' days')));

echo '<script type="application/json" id="trn-cats">' . json_encode(array_values($cats), JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
echo '<form method="post" id="trn-create" data-days="' . max(1, intval($s['days'] ?? 1)) . '" data-fr="' . (!empty($s['fr']) ? 1 : 0) . '">' . trn_token_field() . '<input type="hidden" name="tpl" value="' . $tm . '">';
echo '<div class="trn-card"><div class="trn-grid">'
    . '<label>' . trn_t('ColName') . ' <input type="text" name="name" size="40" maxlength="200" placeholder="' . trn_esc($fmt->TmName) . '"></label>'
    . '<label>' . trn_t('FieldDate') . ' <input type="date" name="date" required value="' . date('Y-m-d') . '"></label>'
    . '<label>' . trn_t('FieldStart') . ' <input type="time" name="time" required value="' . trn_esc($refTime) . '"></label>'
    . '</div>';
if (intval($s['days'] ?? 1) > 1) echo '<p class="trn-note">' . trn_t('CreateDays', intval($s['days'])) . '</p>';
echo '<p class="trn-note">' . trn_t('CreateShift') . '</p>';
if ($hasDuels) {
    echo '<p><label><input type="checkbox" name="qualsim" value="1"> ' . trn_t('QualSim') . '</label><br><span class="trn-note">' . trn_t('QualSimHelp') . '</span></p>';
}
echo '<p><label><input type="checkbox" name="place" value="1" checked> ' . trn_t('PlaceAuto') . '</label></p></div>';

// Archers.
echo '<h2>' . trn_t('CreateArchers') . '</h2>';
if (!$archers) {
    echo '<p class="trn-note">' . trn_t('ArchersNone') . ' <a href="archers.php">' . trn_t('MenuArchers') . '</a></p>';
} else {
    $groups = trn_groups();
    echo '<div class="trn-toolbar"><input type="search" class="trn-filter" data-target="#trn-pick" placeholder="' . trn_t('Filter') . '"> ';
    foreach ($groups as $gid => $gname) {
        echo '<button type="button" class="trn-btn trn-group-pick" data-group="' . $gid . '">' . trn_esc($gname) . '</button> ';
    }
    echo '<span class="trn-note" id="trn-count"></span></div>';
    echo '<table class="Tabella trn-table" id="trn-pick"><tr><th><input type="checkbox" class="trn-check-all" data-target="#trn-pick" title="'
        . trn_t('CheckAll') . '"></th><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('FieldClub') . '</th><th>' . trn_t('ColCategory') . '</th>'
        . (count($sessions) > 1 ? '<th>' . trn_t('ColSession') . '</th>' : '') . '</tr>';
    foreach ($archers as $a) {
        $def = trn_default_category($cats, $a, $year);
        $label = trim($a->ArFamilyName . ' ' . $a->ArGivenName);
        echo '<tr class="trn-row" data-groups="' . trn_esc(implode(',', $a->Groups)) . '">'
            . '<td class="Center"><input type="checkbox" class="trn-pick" name="a[' . intval($a->ArId) . ']" value="1" data-id="' . intval($a->ArId)
            . '" data-label="' . trn_esc($label) . '"></td>'
            . '<td>' . trn_esc($label) . '</td><td>' . trn_esc($a->ArClubCode) . '</td><td><select class="trn-cat" name="cat[' . intval($a->ArId) . ']"'
            . ' data-birth="' . (trn_is_date($a->ArDob) ? intval(mb_substr($a->ArDob, 0, 4)) : '') . '" data-sex="' . intval($a->ArSex) . '"'
            . ' data-div="' . trn_esc($a->ArDivision) . '" data-cls="' . trn_esc($a->ArClass) . '">';
        foreach ($cats as $c) {
            $k = $c['div'] . '|' . $c['cls'];
            echo '<option value="' . trn_esc($k) . '"' . ($k === $def ? ' selected' : '') . '>' . trn_esc($c['div'] . ' ' . $c['cls']
                . (isset($classes[$c['cls']]) && $classes[$c['cls']]['name'] !== '' ? ' - ' . $classes[$c['cls']]['name'] : '')) . '</option>';
        }
        echo '</select></td>';
        if (count($sessions) > 1) {
            echo '<td><select name="ses[' . intval($a->ArId) . ']">';
            foreach ($sessions as $o => $n) echo '<option value="' . intval($o) . '">' . trn_esc($n !== '' ? $n : trn_text('SessionN', $o)) . '</option>';
            echo '</select></td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}

// Teams.
if (!empty($s['team']) || !empty($s['mixed'])) {
    echo '<h2>' . trn_t('TeamsTitleAll') . '</h2><p class="trn-note">' . trn_t('TeamsHelpCreate') . '</p>';
    $people = [];
    foreach ($archers as $a) $people[(string)$a->ArId] = trim($a->ArFamilyName . ' ' . $a->ArGivenName);
    echo trn_teams_editor(['team' => !empty($s['team']), 'mixed' => !empty($s['mixed'])], ['team' => [], 'mixed' => []], $people);
}

echo '<p class="trn-submit"><button type="submit" class="trn-btn trn-primary">' . trn_t('CreateDo') . '</button></p></form>';

trn_page_close();
