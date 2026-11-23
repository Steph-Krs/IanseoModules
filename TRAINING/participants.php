<?php
/**
 * Archers of the open training: add regular archers, change a category, take
 * an archer out. Teams have their own page.
 */

require_once __DIR__ . '/lib/boot.php';
$tourId = trn_require_tour();
$run = trn_run($tourId);
$sim = $run && intval($run->RnQualSim);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('participants.php');
    }
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'add') {
        $archers = trn_archers();
        $sessions = trn_sessions($tourId);
        $first = $sessions ? array_key_first($sessions) : 0;
        $n = 0;
        foreach (array_keys((array)($_POST['a'] ?? [])) as $aid) {
            $aid = intval($aid);
            if (!isset($archers[$aid])) continue;
            $cat = explode('|', (string)($_POST['cat'][$aid] ?? ''), 2);
            $ses = intval($_POST['ses'][$aid] ?? $first);
            if (!isset($sessions[$ses])) $ses = $first;
            if (count($cat) === 2 && trn_add_entry($tourId, $archers[$aid], $cat[0], $cat[1], $ses)) $n++;
        }
        if ($sim) trn_simulate_qualification($tourId); else trn_recalc($tourId);
        if ($n && !empty($_POST['place']) && !$sim) {
            foreach (array_keys($sessions) as $s) trn_place_session($tourId, $s);
        }
        trn_flash(trn_text('ArchersAdded', $n));
    } elseif ($act === 'remove') {
        trn_remove_entry($tourId, intval($_POST['en'] ?? 0));
        trn_recalc($tourId);
        trn_flash(trn_text('ArcherRemoved'));
    } elseif ($act === 'category') {
        $cat = explode('|', (string)($_POST['cat'] ?? ''), 2);
        if (count($cat) === 2) {
            trn_set_category($tourId, intval($_POST['en'] ?? 0), $cat[0], $cat[1]);
            trn_recalc($tourId);
            trn_flash(trn_text('Saved'));
        }
    }
    trn_redirect('participants.php');
}

$entries = trn_entries($tourId);
$cats = trn_tour_categories($tourId);
$sessions = trn_sessions($tourId);
$year = intval(safe_fetch(safe_r_sql("SELECT YEAR(ToWhenTo) AS Y FROM Tournament WHERE ToId = $tourId"))->Y);
$catSelect = function ($name, $current, $form = '') use ($cats) {
    $out = '<select name="' . $name . '"' . ($form !== '' ? ' form="' . $form . '" onchange="this.form.submit()"' : '') . '>';
    foreach ($cats as $c) {
        $k = $c['div'] . '|' . $c['cls'];
        $out .= '<option value="' . trn_esc($k) . '"' . ($k === $current ? ' selected' : '') . '>' . trn_esc($c['div'] . ' ' . $c['cls']) . '</option>';
    }
    return $out . '</select>';
};

trn_page_open(trn_text('ParticipantsTitle'), ['assets/training.js']);
echo '<p><a class="trn-btn" href="dashboard.php">' . trn_t('BackDashboard') . '</a></p>';

echo '<h2>' . trn_t('ParticipantsIn', count($entries)) . '</h2>';
if ($entries) {
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('FieldClub') . '</th><th>'
        . trn_t('ColCategory') . '</th><th>' . trn_t('ColTarget') . '</th><th></th></tr>';
    foreach ($entries as $e) {
        $form = 'trn-c' . intval($e->EnId);
        echo '<tr><td>' . trn_esc(trim($e->EnFirstName . ' ' . $e->EnName)) . '</td><td>' . trn_esc($e->CoCode) . '</td>'
            . '<td><form method="post" id="' . $form . '">' . trn_token_field() . '<input type="hidden" name="act" value="category">'
            . '<input type="hidden" name="en" value="' . intval($e->EnId) . '"></form>' . $catSelect('cat', $e->EnDivision . '|' . $e->EnClass, $form) . '</td>'
            . '<td>' . (intval($e->QuTarget) ? trn_esc($e->QuSession . ' - ' . $e->QuTarget . $e->QuLetter) : '') . '</td>'
            . '<td><form method="post" class="trn-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
            . trn_esc(trn_text('ArcherRemoveConfirm', trim($e->EnFirstName . ' ' . $e->EnName))) . '">' . trn_token_field()
            . '<input type="hidden" name="act" value="remove"><input type="hidden" name="en" value="' . intval($e->EnId) . '">'
            . '<button type="submit" class="trn-btn trn-danger">' . trn_t('Remove') . '</button></form></td></tr>';
    }
    echo '</table>';
}

// Regular archers not yet in the training.
$inCodes = $inNames = [];
foreach ($entries as $e) {
    if ($e->EnCode !== '') $inCodes[$e->EnCode] = true;
    $inNames[mb_strtoupper($e->EnFirstName . '|' . $e->EnName)] = true;
}
$free = array_filter(trn_archers(true), function ($a) use ($inCodes, $inNames) {
    if ($a->ArCode !== '') return !isset($inCodes[$a->ArCode]);
    return !isset($inNames[mb_strtoupper($a->ArFamilyName . '|' . $a->ArGivenName)]);
});

echo '<h2>' . trn_t('ParticipantsAdd') . '</h2>';
if (!$free) {
    echo '<p class="trn-note">' . trn_t('ParticipantsAllIn') . '</p>';
} else {
    echo '<form method="post">' . trn_token_field() . '<input type="hidden" name="act" value="add">'
        . '<div class="trn-toolbar"><input type="search" class="trn-filter" data-target="#trn-pick" placeholder="' . trn_t('Filter') . '"></div>'
        . '<table class="Tabella trn-table" id="trn-pick"><tr><th></th><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('FieldClub') . '</th><th>'
        . trn_t('ColCategory') . '</th>' . (count($sessions) > 1 ? '<th>' . trn_t('ColSession') . '</th>' : '') . '</tr>';
    foreach ($free as $a) {
        echo '<tr class="trn-row"><td class="Center"><input type="checkbox" name="a[' . intval($a->ArId) . ']" value="1"></td>'
            . '<td>' . trn_esc(trim($a->ArFamilyName . ' ' . $a->ArGivenName)) . '</td><td>' . trn_esc($a->ArClubCode) . '</td>'
            . '<td>' . $catSelect('cat[' . intval($a->ArId) . ']', trn_default_category($cats, $a, $year)) . '</td>';
        if (count($sessions) > 1) {
            echo '<td><select name="ses[' . intval($a->ArId) . ']">';
            foreach ($sessions as $o => $ses) echo '<option value="' . intval($o) . '">' . trn_esc($ses->SesName !== '' ? $ses->SesName : trn_text('SessionN', $o)) . '</option>';
            echo '</select></td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    if (!$sim) echo '<p><label><input type="checkbox" name="place" value="1" checked> ' . trn_t('PlaceAuto') . '</label></p>';
    echo '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('ParticipantsAddDo') . '</button></p></form>';
}

trn_page_close();
