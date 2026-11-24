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
    } elseif ($act === 'ghosts_add') {
        $cat = explode('|', (string)($_POST['cat'] ?? ''), 2);
        $sessions = trn_sessions($tourId);
        $ses = intval($_POST['ses'] ?? 0);
        if (!isset($sessions[$ses])) $ses = $sessions ? array_key_first($sessions) : 0;
        $levels = trn_ghost_levels($_POST);
        $why = count($cat) === 2 ? trn_ghost_check($tourId, $cat[0], $cat[1], $levels) : '';
        if ($why !== '') {
            trn_flash($why, 'err');
            trn_redirect('participants.php#trn-ghosts');
        }
        $n = count($cat) === 2 ? trn_ghost_add($tourId, intval($_POST['count'] ?? 1), $cat[0], $cat[1], $ses, $levels) : 0;
        if ($sim) trn_simulate_qualification($tourId); else trn_recalc($tourId);
        trn_flash(trn_text('GhostsAdded', $n));
    } elseif ($act === 'ghost_levels') {
        $ghosts = trn_ghosts($tourId);
        $all = trn_entries($tourId);
        $refused = 0;
        foreach ((array)($_POST['lv'] ?? []) as $en => $levels) {
            $en = intval($en);
            if (!isset($ghosts[$en]) || intval($ghosts[$en]->GhKind) !== 0 || !isset($all[$en])) continue;
            $levels = trn_ghost_levels((array)$levels);
            $why = trn_ghost_check($tourId, $all[$en]->EnDivision, $all[$en]->EnClass, $levels);
            if ($why !== '') {
                trn_flash(trim($all[$en]->EnFirstName . ' ' . $all[$en]->EnName) . ' : ' . $why, 'err');
                $refused++;
                continue;
            }
            // Unchanged levels draw nothing again.
            $g = $ghosts[$en];
            if (intval($g->GhQualScore) === $levels['qscore'] && floatval($g->GhQualDelta) == $levels['qdelta']
                && floatval($g->GhDuelAvg) == $levels['davg'] && floatval($g->GhDuelAvgDelta) == $levels['ddelta']
                && intval($g->GhDuelScore) === $levels['dscore'] && floatval($g->GhDuelScoreDelta) == $levels['dsdelta']) continue;
            trn_ghost_set_levels($tourId, $en, $levels);
        }
        if (!$refused) trn_flash(trn_text('Saved'));
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
$ghosts = trn_ghosts($tourId);
$cats = trn_tour_categories($tourId);
$waCount = 0;
foreach ($entries as $id => $e) {
    if ($e->GhostKind !== null && intval($e->GhostKind) === 1) { $waCount++; unset($entries[$id]); }
}
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

echo '<h2>' . trn_t('ParticipantsIn', count($entries) + $waCount) . '</h2>';
if ($waCount) echo '<p class="trn-note">' . trn_t('WaAthletesIn', $waCount) . ' <a class="trn-btn" href="live.php">' . trn_t('LiveTitle') . '</a></p>';
if ($entries) {
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('FieldClub') . '</th><th>'
        . trn_t('ColCategory') . '</th><th>' . trn_t('ColTarget') . '</th><th></th></tr>';
    foreach ($entries as $e) {
        $form = 'trn-c' . intval($e->EnId);
        $g = $ghosts[intval($e->EnId)] ?? null;
        $ghost = $g ? ' <span class="trn-badge">' . trn_t('GhostBadge', intval($g->GhQualScore)) . '</span>' : '';
        echo '<tr><td>' . trn_esc(trim($e->EnFirstName . ' ' . $e->EnName)) . $ghost . '</td><td>' . trn_esc($e->CoCode) . '</td>'
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

// Fictitious competitors.
$qmax = trn_cat_qual_max($tourId);
$kinds = trn_duel_kinds($tourId);
$pmax = $kinds['points'] ? max($kinds['points']) : 0;
$firstMax = $cats ? intval($qmax[$cats[0]['div'] . '|' . $cats[0]['cls']] ?? 0) : 0;
$num = function ($name, $value, $step, $cls = '') {
    return '<input type="number" name="' . $name . '" min="0" step="' . $step . '" value="' . trn_esc($value) . '" class="trn-num-in' . ($cls !== '' ? ' ' . $cls : '') . '">';
};
$levelFields = function ($prefix, $l) use ($kinds, $num, $sim, $firstMax, $pmax) {
    $d = TRN_GHOST_DEFAULTS;
    // New competitors: about four fifths of the maximum, as a starting point.
    if (!$l) {
        $d['qscore'] = intval(round(0.8 * $firstMax));
        $d['dscore'] = intval(round(0.85 * $pmax));
    }
    $v = function ($k, $col) use ($l, $d) { return $l ? rtrim(rtrim(number_format(floatval($l->$col), 2, '.', ''), '0'), '.') : $d[$k]; };
    $name = function ($k) use ($prefix) { return $prefix === '' ? $k : $prefix . '[' . $k . ']'; };
    $out = '';
    if (!$sim) {
        $out .= '<label>' . trn_t('GhostsQual') . ' <span>' . $num($name('qscore'), $v('qscore', 'GhQualScore'), 1, $prefix === '' ? 'trn-qmax-in' : '') . ' &plusmn; '
            . $num($name('qdelta'), $v('qdelta', 'GhQualDelta'), 1) . '</span></label>';
    }
    if ($kinds['sets']) {
        $out .= '<label>' . trn_t('GhostsDuelSets') . ' <span>' . $num($name('davg'), $v('davg', 'GhDuelAvg'), 0.1) . ' &plusmn; '
            . $num($name('ddelta'), $v('ddelta', 'GhDuelAvgDelta'), 0.1) . '</span></label>';
    }
    if ($kinds['points']) {
        $out .= '<label>' . trn_t('GhostsDuelPoints') . ' <span>' . $num($name('dscore'), $v('dscore', 'GhDuelScore'), 1) . ' &plusmn; '
            . $num($name('dsdelta'), $v('dsdelta', 'GhDuelScoreDelta'), 1) . '</span></label>';
    }
    return $out;
};

echo '<h2 id="trn-ghosts">' . trn_t('GhostsTitle') . '</h2>';
echo '<form method="post" class="trn-card">' . trn_token_field() . '<input type="hidden" name="act" value="ghosts_add">'
    . '<p class="trn-note">' . trn_t('GhostsLead') . '</p><div class="trn-grid">'
    . '<label>' . trn_t('GhostsCount') . ' <input type="number" name="count" min="1" max="32" value="4"></label>'
    . '<label>' . trn_t('ColCategory') . ' <select name="cat" class="trn-qmax-src" data-qmax="' . trn_esc(json_encode($qmax)) . '">';
foreach ($cats as $c) {
    $k = $c['div'] . '|' . $c['cls'];
    echo '<option value="' . trn_esc($k) . '">' . trn_esc($c['div'] . ' ' . $c['cls']) . '</option>';
}
echo '</select></label>';
if (count($sessions) > 1) {
    echo '<label>' . trn_t('ColSession') . ' <select name="ses">';
    foreach ($sessions as $o => $ses) echo '<option value="' . intval($o) . '">' . trn_esc($ses->SesName !== '' ? $ses->SesName : trn_text('SessionN', $o)) . '</option>';
    echo '</select></label>';
}
echo $levelFields('', null) . '</div>';
if (!$sim) echo '<p class="trn-note">' . trn_t('GhostsQualMax') . ' <b class="trn-qmax-out"></b></p>';
if ($pmax) echo '<p class="trn-note">' . trn_t('GhostsPointsMax', $pmax) . '</p>';
echo '<p class="trn-note">' . trn_t('GhostsPace') . '</p>'
    . '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('GhostsAdd') . '</button></p></form>';

$fict = array_filter($ghosts, function ($g) { return intval($g->GhKind) === 0; });
if ($fict) {
    $names = [];
    foreach (trn_entries($tourId) as $e) $names[intval($e->EnId)] = trim($e->EnFirstName . ' ' . $e->EnName) . ' (' . $e->EnDivision . ' ' . $e->EnClass . ')';
    echo '<h3>' . trn_t('GhostsLevels') . '</h3><form method="post">' . trn_token_field() . '<input type="hidden" name="act" value="ghost_levels">'
        . '<table class="Tabella trn-table">';
    foreach ($fict as $id => $g) {
        echo '<tr><td>' . trn_esc($names[$id] ?? '') . '</td><td><div class="trn-grid">' . $levelFields('lv[' . intval($id) . ']', $g) . '</div></td></tr>';
    }
    echo '</table><p class="trn-note">' . trn_t('GhostsLevelsNote') . '</p><p><button type="submit" class="trn-btn trn-primary">' . trn_t('Save') . '</button></p></form>';
}

trn_page_close();
