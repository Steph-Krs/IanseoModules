<?php
/**
 * Shoot-offs of the duels, in one place for the coach: every match whose arrows
 * are all shot without a winner, with the shoot-off arrows to enter and, when
 * they are equal, the archer closest to the centre to name. Saved through the
 * core's own functions (lib/live.php), the winner then goes on to the next round.
 */

require_once __DIR__ . '/lib/boot.php';
$tourId = trn_require_tour();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('shootoffs.php');
    }
    $arrows = [];
    foreach ((array)($_POST['so'] ?? []) as $no => $list) $arrows[intval($no)] = implode('', array_map('strval', (array)$list));
    $done = trn_shootoff_save($tourId, intval($_POST['team'] ?? 0), (string)($_POST['event'] ?? ''), intval($_POST['m'] ?? 0),
        $arrows, isset($_POST['closest']) && $_POST['closest'] !== '' ? intval($_POST['closest']) : -1);
    trn_flash(trn_text($done ? 'SoDecided' : 'SoSaved'));
    trn_redirect('shootoffs.php');
}

$list = trn_shootoffs($tourId);

trn_page_open(trn_text('SoTitle'));
echo '<p><a class="trn-btn" href="dashboard.php">' . trn_t('BackDashboard') . '</a> <a class="trn-btn" href="live.php">' . trn_t('LiveTitle') . '</a></p>';
echo '<p class="trn-lead">' . trn_t('SoLead') . '</p>';
if (!$list) echo '<p class="trn-msg trn-ok">' . trn_t('SoNone') . '</p>';

foreach ($list as $s) {
    $phase = $s['phase'] === 0 ? trn_text('SoGold') : ($s['phase'] === 1 ? trn_text('SoBronze') : '1/' . $s['phase']);
    echo '<form method="post" class="trn-step">' . trn_token_field()
        . '<input type="hidden" name="team" value="' . $s['team'] . '"><input type="hidden" name="event" value="' . trn_esc($s['event']) . '">'
        . '<input type="hidden" name="m" value="' . $s['m'] . '">'
        . '<h2>' . trn_esc($s['name']) . ' <span class="trn-note">' . trn_esc($phase) . '</span></h2>'
        . '<table class="Tabella trn-table"><tr><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('SoArrows') . '</th><th>' . trn_t('SoClosest') . '</th></tr>';
    // The round being shot, as trn_shootoff_save() reads it.
    $lens = array_map(function ($x) { return strlen($x['tb']); }, $s['sides']); // bytes: arrow letters are ASCII.
    $round = max(1, intval(ceil(max($lens) / $s['so'])));
    $start = ($round - 1) * $s['so'];
    foreach ($s['sides'] as $no => $side) {
        $prev = $start > 0 ? ' <span class="trn-note">' . trn_esc(implode(' ', array_map(function ($l) use ($s) { return $s['letters'][$l] ?? $l; }, str_split(substr($side['tb'], 0, $start))))) . '</span> ' : ''; // bytes: arrow letters are ASCII.
        $cell = '';
        if ($side['sim']) {
            $mine = substr($side['tb'], $start, $s['so']); // bytes: arrow letters are ASCII.
            $cell = $mine !== '' ? trn_esc(implode(' ', array_map(function ($l) use ($s) { return $s['letters'][$l] ?? $l; }, str_split($mine)))) // bytes: arrow letters are ASCII.
                : '<span class="trn-note">' . trn_t('SoSimWaits') . '</span>';
        } else {
            for ($i = 0; $i < $s['so']; $i++) {
                $cur = substr($side['tb'], $start + $i, 1); // bytes: arrow letters are ASCII.
                $cell .= '<select name="so[' . $no . '][]"><option value=""></option>';
                foreach ($s['letters'] as $l => $label) {
                    $cell .= '<option value="' . trn_esc($l) . '"' . ($l === $cur ? ' selected' : '') . '>' . trn_esc($label) . '</option>';
                }
                $cell .= '</select> ';
            }
        }
        echo '<tr><td>' . trn_esc($side['name']) . ($side['sim'] ? ' <span class="trn-badge">' . trn_t('LiveSimBadge') . '</span>' : '') . '</td>'
            . '<td>' . $prev . $cell . '</td>'
            . '<td class="Center"><input type="radio" name="closest" value="' . $no . '"' . ($side['closest'] ? ' checked' : '') . '></td></tr>';
    }
    echo '</table><p class="trn-note">' . trn_t('SoHelp') . '</p>'
        . '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('Save') . '</button></p></form>';
}

trn_page_close();
