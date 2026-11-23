<?php
/**
 * Reference formats: save the open competition as a format, rename, choose the
 * menu of the trainings made from it, replace, delete.
 *
 * Once saved, a format no longer needs its competition: the snapshot holds
 * everything, and the competition may be deleted from ianseo.
 */

require_once __DIR__ . '/lib/boot.php';
trn_require_admin();

$tourId = intval($_SESSION['TourId'] ?? 0);
$open = $tourId ? safe_fetch(safe_r_sql("SELECT ToCode, ToName FROM Tournament WHERE ToId = $tourId")) : null;
if (!$open) $tourId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('templates.php');
    }
    $act = (string)($_POST['act'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    if ($act === 'save' && $tourId) {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $name = (string)$open->ToName;
        $replace = intval($_POST['replace'] ?? 0);
        $simple = !empty($_POST['simple']);
        if ($replace) {
            $old = trn_tpl_list()[$replace] ?? null;
            if ($old) {
                trn_tpl_save($tourId, $old->TmName, intval($old->TmSimple), $replace);
                trn_flash(trn_text('FormatReplaced', $old->TmName));
            }
        } elseif (trn_tpl_save($tourId, $name, $simple)) {
            trn_flash(trn_text('FormatSaved', $name));
        }
    } elseif ($act === 'update' && $id) {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        if ($name !== '') {
            safe_w_sql("UPDATE TrainingTemplates SET TmName = " . StrSafe_DB($name)
                . ", TmSimple = " . (!empty($_POST['simple']) ? 1 : 0) . " WHERE TmId = $id");
            trn_flash(trn_text('Saved'));
        }
    } elseif ($act === 'isk') {
        $ok = trn_isk_url_set($_POST['url'] ?? '');
        trn_flash(trn_text($ok ? 'Saved' : 'IskUrlBad'), $ok ? 'ok' : 'err');
    } elseif ($act === 'delete' && $id) {
        safe_w_sql("DELETE FROM TrainingTemplates WHERE TmId = $id");
        trn_flash(trn_text('FormatDeleted'));
    }
    trn_redirect('templates.php');
}

$formats = trn_tpl_list();
$isRun = $tourId ? trn_run($tourId) : null;

trn_page_open(trn_text('FormatsTitle'));
echo '<p class="trn-lead">' . trn_t('FormatsLead') . '</p>';

echo '<h2>' . trn_t('FormatsFromOpen') . '</h2>';
if (!$tourId) {
    echo '<p class="trn-note">' . trn_t('FormatsNoOpen') . '</p>';
} else {
    echo '<form method="post" class="trn-card">' . trn_token_field() . '<input type="hidden" name="act" value="save">'
        . '<p>' . trn_t('FormatsOpenIs', ['code' => $open->ToCode, 'name' => $open->ToName]) . '</p>'
        . '<p><label>' . trn_t('FormatName') . ' <input type="text" name="name" maxlength="100" size="40" value="'
        . trn_esc($open->ToName) . '"></label></p>'
        . '<p><label><input type="checkbox" name="simple" value="1" checked> ' . trn_t('FormatSimple') . '</label></p>';
    if ($formats) {
        echo '<p><label>' . trn_t('FormatReplace') . ' <select name="replace"><option value="0">' . trn_t('FormatReplaceNone') . '</option>';
        foreach ($formats as $f) {
            $sel = ($isRun && intval($isRun->RnTemplate) === intval($f->TmId)) ? ' selected' : '';
            echo '<option value="' . intval($f->TmId) . '"' . $sel . '>' . trn_esc($f->TmName) . '</option>';
        }
        echo '</select></label></p>';
    }
    echo '<p class="trn-note">' . trn_t('FormatsWhatKept') . '</p>'
        . '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('FormatSave') . '</button></p></form>';
}

// Scoring by ISK-Lite: the server address handed to every training, set here only — it never
// appears on the creation page nor in the simplified menu.
$iskUrl = trn_isk_url();
$suggest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $CFG->ROOT_DIR;
echo '<h2>' . trn_t('IskTitle') . '</h2>';
echo '<form method="post" class="trn-card">' . trn_token_field() . '<input type="hidden" name="act" value="isk">'
    . '<p>' . trn_t('IskLead') . '</p>'
    . '<p><label>' . trn_t('IskUrl') . ' <input type="text" name="url" size="45" maxlength="200" value="' . trn_esc($iskUrl)
    . '" placeholder="' . trn_esc($suggest) . '"></label> <button type="submit" class="trn-btn">' . trn_t('Save') . '</button></p>'
    . '<p class="trn-note">' . trn_t($iskUrl === '' ? 'IskUrlNone' : 'IskUrlHelp') . '</p></form>';

echo '<h2>' . trn_t('FormatsList') . '</h2>';
if (!$formats) {
    echo '<p class="trn-note">' . trn_t('HomeNoFormat') . '</p>';
} else {
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('FormatName') . '</th><th>' . trn_t('ColContent') . '</th><th>'
        . trn_t('FormatSimpleShort') . '</th><th>' . trn_t('ColUpdated') . '</th><th></th></tr>';
    foreach ($formats as $f) {
        $s = $f->Summary;
        $events = [];
        foreach ($s['events'] ?? [] as $e) $events[] = $e['code'];
        $content = trn_text('FormatContent', [
            'type' => trim(get_text($s['type'] ?? '', 'Tournament') . ' ' . ($s['subrule'] ?? '')),
            'sessions' => count($s['sessions'] ?? []),
            'events' => $events ? implode(', ', $events) : '-',
        ]);
        $form = 'trn-f' . intval($f->TmId);
        echo '<tr><td><form method="post" id="' . $form . '">' . trn_token_field()
            . '<input type="hidden" name="act" value="update"><input type="hidden" name="id" value="' . intval($f->TmId) . '">'
            . '<input type="text" name="name" maxlength="100" size="30" value="' . trn_esc($f->TmName) . '"></form>'
            . '<span class="trn-note">' . trn_t('FormatSource', $f->TmSource) . '</span></td>'
            . '<td>' . trn_esc($content) . '</td>'
            . '<td class="Center"><input type="checkbox" name="simple" value="1" form="' . $form . '"' . (intval($f->TmSimple) ? ' checked' : '') . '></td>'
            . '<td>' . trn_esc(trn_date(mb_substr((string)$f->TmUpdated, 0, 10))) . '</td>'
            . '<td class="trn-actions"><button type="submit" class="trn-btn" form="' . $form . '">' . trn_t('Save') . '</button> '
            . '<form method="post" class="trn-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
            . trn_esc(trn_text('FormatDeleteConfirm', $f->TmName)) . '">' . trn_token_field()
            . '<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="' . intval($f->TmId) . '">'
            . '<button type="submit" class="trn-btn trn-danger">' . trn_t('Delete') . '</button></form></td></tr>';
    }
    echo '</table>';
}

trn_page_close();
