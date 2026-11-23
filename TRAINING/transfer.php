<?php
/**
 * Import and export of the configuration: reference formats, regular archers
 * and groups, so that one installation can be set up from another.
 */

require_once __DIR__ . '/lib/boot.php';
trn_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('transfer.php');
    }
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'export') {
        $formats = (array)($_POST['formats'] ?? []);
        $archers = !empty($_POST['archers']);
        if (!$formats && !$archers) {
            trn_flash(trn_text('ExportNothing'), 'err');
            trn_redirect('transfer.php');
        }
        $body = json_encode(trn_export($formats, $archers), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="training-' . date('Ymd-His') . '.json"');
        // bytes: Content-Length counts bytes, not characters.
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }
    if ($act === 'import') {
        $file = $_FILES['file'] ?? null;
        $data = null;
        if ($file && intval($file['error']) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $data = json_decode((string)file_get_contents($file['tmp_name']), true);
        }
        if (!is_array($data) || ($data['kind'] ?? '') !== TRN_TRANSFER_KIND) {
            trn_flash(trn_text($file && intval($file['error']) !== UPLOAD_ERR_OK && intval($file['error']) !== UPLOAD_ERR_NO_FILE ? 'ErrFileSize' : 'ErrFile'), 'err');
            trn_redirect('transfer.php');
        }
        $res = trn_import($data, !empty($_POST['replace']));
        trn_flash(trn_text('ImportedSummary', $res));
        if ($res['refused']) trn_flash(trn_text('ImportRefused', implode(', ', $res['refused'])), 'err');
        trn_redirect('transfer.php');
    }
    trn_redirect('transfer.php');
}

$formats = trn_tpl_list();
$archers = trn_archers();
$groups = trn_groups();

trn_page_open(trn_text('TransferTitle'), ['assets/training.js']);
echo '<p class="trn-lead">' . trn_t('TransferLead') . '</p>';

echo '<h2>' . trn_t('ExportTitle') . '</h2>';
echo '<form method="post" class="trn-card">' . trn_token_field() . '<input type="hidden" name="act" value="export">';
if ($formats) {
    echo '<p><b>' . trn_t('MenuFormats') . '</b> <label class="trn-note"><input type="checkbox" class="trn-check-all" data-target="#trn-exp-formats" checked> '
        . trn_t('CheckAll') . '</label></p><div id="trn-exp-formats" class="trn-columns">';
    foreach ($formats as $f) {
        echo '<label><input type="checkbox" name="formats[]" value="' . intval($f->TmId) . '" checked> ' . trn_esc($f->TmName) . '</label>';
    }
    echo '</div>';
} else {
    echo '<p class="trn-note">' . trn_t('HomeNoFormat') . '</p>';
}
echo '<p><label><input type="checkbox" name="archers" value="1"' . ($archers ? ' checked' : ' disabled') . '> '
    . trn_t('ExportArchers', ['archers' => count($archers), 'groups' => count($groups)]) . '</label></p>'
    . '<p class="trn-note">' . trn_t('ExportPersonal') . '</p>'
    . '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('ExportDo') . '</button></p></form>';

echo '<h2>' . trn_t('ImportFileTitle') . '</h2>';
echo '<form method="post" enctype="multipart/form-data" class="trn-card">' . trn_token_field() . '<input type="hidden" name="act" value="import">'
    . '<p><input type="file" name="file" accept=".json,application/json" required></p>'
    . '<p><label><input type="checkbox" name="replace" value="1" checked> ' . trn_t('ImportReplace') . '</label></p>'
    . '<p class="trn-note">' . trn_t('ImportHelp') . '</p>'
    . '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('ImportFileDo') . '</button></p></form>';

trn_page_close();
