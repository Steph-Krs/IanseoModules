<?php
/**
 * "Create a training" from a World Archery competition, the second way of the
 * creation page (create.php?src=wa, included by create.php): find the
 * competition with filters, pick it, tick the archers of the training with
 * their category among those of the real competition, and create. The new
 * competition is opened and the coach lands on its dashboard.
 */

if (!defined('TRN_CREATE')) exit;

$f = [
    'from'    => (string)($_GET['from'] ?? date('Y-m-d', strtotime('-1 year'))),
    'to'      => (string)($_GET['to'] ?? date('Y-m-d')),
    'country' => mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', (string)($_GET['country'] ?? '')), 0, 3)),
    'disc'    => intval($_GET['disc'] ?? 0),
    'level'   => intval($_GET['level'] ?? 0),
    'name'    => mb_substr(trim((string)($_GET['name'] ?? '')), 0, 60),
];
foreach (['from', 'to'] as $k) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('create.php?src=wa');
    }
    $id = intval($_POST['comp'] ?? 0);
    $comp = $_SESSION['TRN_WA_FOUND'][$id] ?? null;
    $date = (string)($_POST['date'] ?? '');
    $time = (string)($_POST['time'] ?? '');
    $back = 'create.php?src=wa&wa=' . $id;
    if (!$comp) { trn_flash(trn_text('WaErrSearch'), 'err'); trn_redirect('create.php?src=wa'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { trn_flash(trn_text('ErrDate'), 'err'); trn_redirect($back); }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '09:00';
    // Hundreds of athletes and the brackets to read: more than the default time limit on a slow server.
    @set_time_limit(300);
    $res = trn_wa_create($comp, $date, $time);
    if (isset($res['error'])) { trn_flash(trn_text($res['error']), 'err'); trn_redirect($back); }
    $tourId = intval($res['id']);

    // The archers of the training, in the category chosen among the real competition's.
    $n = $skipped = 0;
    foreach (array_keys((array)($_POST['a'] ?? [])) as $aid) {
        $aid = intval($aid);
        if (!isset($archers[$aid])) continue;
        $cat = (string)($_POST['cat'][$aid] ?? '');
        // bytes: World Archery category codes are ASCII (division letter, then class).
        $ok = preg_match('/^[A-Z0-9]{2,8}$/', $cat) && trn_add_entry($tourId, $archers[$aid], substr($cat, 0, 1), substr($cat, 1), 1);
        if ($ok) $n++; else $skipped++;
    }
    trn_recalc($tourId);
    $left = !empty($_POST['place']) && $n ? trn_place_session($tourId, 1)['left'] : 0;

    trn_flash(trn_text('WaCreated', ['code' => $res['code'], 'n' => $res['athletes']]));
    if ($res['skipped']) trn_flash(trn_text('WaSkipped', $res['skipped']), 'err');
    if (!empty($res['noteams'])) trn_flash(trn_text('WaNoTeams'), 'err');
    if ($skipped) trn_flash(trn_text('CreatedSkipped', $skipped), 'err');
    if ($left) trn_flash(trn_text('PlacedLeft', $left), 'err');
    trn_flash(trn_text($n ? 'WaThenLive' : 'WaAddYours'));
    trn_redirect($n ? 'dashboard.php' : 'participants.php');
}

$chosen = intval($_GET['wa'] ?? 0);
$comp = $chosen ? ($_SESSION['TRN_WA_FOUND'][$chosen] ?? null) : null;
$found = null;
if (!$comp && isset($_GET['search'])) {
    $found = trn_wa_search($f);
    // Kept for the next steps: the forms carry only the identifier.
    $_SESSION['TRN_WA_FOUND'] = [];
    foreach ((array)$found as $c) $_SESSION['TRN_WA_FOUND'][$c['ID']] = $c;
}

trn_page_open(trn_text('CreateTitle'), ['assets/training.js']);
echo $tabs;
echo '<p class="trn-lead">' . trn_t('WaLead') . '</p>';

if ($comp) {
    // Step 2: the competition chosen, the day, the archers.
    $search = http_build_query(array_merge($f, ['src' => 'wa', 'search' => 1]));
    echo '<div class="trn-card"><p>' . trn_t('WaChosen') . ' <b>' . trn_esc($comp['Name']) . '</b> <span class="trn-note">'
        . trn_esc(trim($comp['Place'] . ' (' . $comp['Country'] . ')') . ' - ' . trn_date($comp['DFrom']) . ' - ' . trn_text('WaDisc' . $comp['ComDis'])) . '</span> '
        . '<a class="trn-btn" href="create.php?' . trn_esc($search) . '">' . trn_t('WaOther') . '</a></p></div>';

    // Individual categories of the real competition, with their sex.
    $cats = [];
    $api = trn_wa_get('CATEGORIES/', ['CompId' => intval($comp['ID'])]);
    foreach ((array)($api['items'] ?? []) as $c) {
        if (!is_array($c) || !empty($c['Mixed']) || !empty($c['ParaAr'])) continue;
        $g = (array)($c['Gender'] ?? []);
        $cats[(string)$c['Code']] = ['name' => (string)($c['Name'] ?? ''), 'sex' => in_array('W', $g, true) && !in_array('M', $g, true) ? 1 : 0];
    }
    if (!$cats && $api === null) {
        echo '<p class="trn-msg trn-err">' . trn_t('WaErrApi') . '</p>';
        trn_page_close();
        exit;
    }
    $year = intval(mb_substr(date('Y-m-d'), 0, 4));

    echo '<form method="post" id="trn-create-wa" onsubmit="this.querySelector(\'.trn-submit button\').disabled = true">' . trn_token_field() . '<input type="hidden" name="comp" value="' . intval($comp['ID']) . '">'
        . '<div class="trn-card"><div class="trn-grid">'
        . '<label>' . trn_t('FieldDate') . ' <input type="date" name="date" required value="' . date('Y-m-d') . '"></label>'
        . '<label>' . trn_t('FieldStart') . ' <input type="time" name="time" required value="09:00"></label></div>'
        . '<p><label><input type="checkbox" name="place" value="1" checked> ' . trn_t('PlaceAuto') . '</label></p></div>';

    echo '<h2>' . trn_t('CreateArchers') . '</h2><p class="trn-note">' . trn_t('WaArchersHelp') . '</p>';
    if (!$archers) {
        echo '<p class="trn-note">' . trn_t('ArchersNone') . ' <a href="archers.php">' . trn_t('MenuArchers') . '</a></p>';
    } elseif ($cats) {
        $groups = trn_groups();
        echo '<div class="trn-toolbar"><input type="search" class="trn-filter" data-target="#trn-pick" placeholder="' . trn_t('Filter') . '"> ';
        foreach ($groups as $gid => $gname) {
            echo '<button type="button" class="trn-btn trn-group-pick" data-group="' . $gid . '">' . trn_esc($gname) . '</button> ';
        }
        echo '<span class="trn-note" id="trn-count"></span></div>';
        echo '<table class="Tabella trn-table" id="trn-pick"><tr><th><input type="checkbox" class="trn-check-all" data-target="#trn-pick" title="'
            . trn_t('CheckAll') . '"></th><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('FieldClub') . '</th><th>' . trn_t('ColCategory') . '</th></tr>';
        foreach ($archers as $a) {
            $def = trn_wa_default_category($cats, $a, $year);
            $label = trim($a->ArFamilyName . ' ' . $a->ArGivenName);
            echo '<tr class="trn-row" data-groups="' . trn_esc(implode(',', $a->Groups)) . '">'
                . '<td class="Center"><input type="checkbox" class="trn-pick" name="a[' . intval($a->ArId) . ']" value="1" data-id="' . intval($a->ArId)
                . '" data-label="' . trn_esc($label) . '"></td>'
                . '<td>' . trn_esc($label) . '</td><td>' . trn_esc($a->ArClubCode) . '</td><td><select name="cat[' . intval($a->ArId) . ']">';
            foreach ($cats as $code => $c) {
                echo '<option value="' . trn_esc($code) . '"' . ($code === $def ? ' selected' : '') . '>' . trn_esc($code . ' - ' . $c['name']) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</table>';
    }
    echo '<p class="trn-note">' . trn_t('WaSlow') . '</p>'
        . '<p class="trn-submit"><button type="submit" class="trn-btn trn-primary">' . trn_t('CreateDo') . '</button></p></form>';
    trn_page_close();
    exit;
}

// Step 1: find the competition.
$opt = function ($value, $label, $current) {
    return '<option value="' . trn_esc($value) . '"' . ((string)$value === (string)$current ? ' selected' : '') . '>' . trn_esc($label) . '</option>';
};
echo '<form method="get" class="trn-card"><input type="hidden" name="src" value="wa"><input type="hidden" name="search" value="1"><div class="trn-grid">'
    . '<label>' . trn_t('WaFrom') . ' <input type="date" name="from" value="' . trn_esc($f['from']) . '"></label>'
    . '<label>' . trn_t('WaTo') . ' <input type="date" name="to" value="' . trn_esc($f['to']) . '"></label>'
    . '<label>' . trn_t('WaCountry') . ' <input type="text" name="country" size="4" maxlength="3" value="' . trn_esc($f['country']) . '" placeholder="FRA"></label>'
    . '<label>' . trn_t('WaDisc') . ' <select name="disc">' . $opt(0, trn_text('WaAny'), $f['disc']);
for ($d = 1; $d <= 4; $d++) echo $opt($d, trn_text('WaDisc' . $d), $f['disc']);
echo '</select></label><label>' . trn_t('WaLevel') . ' <select name="level">' . $opt(0, trn_text('WaAny'), $f['level']);
for ($l = 1; $l <= 8; $l++) echo $opt($l, trn_text('WaLevel' . $l), $f['level']);
echo '</select></label><label>' . trn_t('WaName') . ' <input type="search" name="name" value="' . trn_esc($f['name']) . '"></label>'
    . '<label><button type="submit" class="trn-btn trn-primary">' . trn_t('WaSearch') . '</button></label></div>'
    . '<p class="trn-note">' . trn_t('WaOnlyResults') . '</p></form>';

if ($found === null && isset($_GET['search'])) {
    echo '<p class="trn-msg trn-err">' . trn_t('WaErrApi') . '</p>';
} elseif (is_array($found)) {
    echo '<h2>' . trn_t('WaFound', count($found)) . '</h2>';
    if ($found) {
        $keep = array_merge($f, ['src' => 'wa']);
        echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColDate') . '</th><th>' . trn_t('ColName') . '</th><th>' . trn_t('WaDisc') . '</th><th>'
            . trn_t('WaLevel') . '</th><th></th></tr>';
        foreach ($found as $c) {
            echo '<tr><td>' . trn_esc(trn_date($c['DFrom'])) . ($c['DTo'] !== $c['DFrom'] ? ' - ' . trn_esc(trn_date($c['DTo'])) : '') . '</td>'
                . '<td><b>' . trn_esc($c['Name']) . '</b><br><span class="trn-note">' . trn_esc(trim($c['Place'] . ' (' . $c['Country'] . ')')) . '</span></td>'
                . '<td>' . trn_t('WaDisc' . $c['ComDis']) . '</td><td>' . trn_esc($c['ComLevel'] >= 1 && $c['ComLevel'] <= 8 ? trn_text('WaLevel' . $c['ComLevel']) : '') . '</td>'
                . '<td><a class="trn-btn trn-primary" href="create.php?' . trn_esc(http_build_query(array_merge($keep, ['wa' => $c['ID']]))) . '">'
                . trn_t('WaChoose') . '</a></td></tr>';
        }
        echo '</table>';
    }
}

trn_page_close();
