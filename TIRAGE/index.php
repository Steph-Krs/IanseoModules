<?php
/**
 * Home page of the live draw module: the list of draws, and how to start one.
 *
 * A draw is created empty, copied from an earlier one (next year's draw starts
 * from this year's teams and history), or imported from a save of the
 * stand-alone HTML page used before the module existed. Each line links to the
 * four screens of a draw: preparation, control, public screen, commentators.
 *
 * The page answers its own forms (POST, then redirect), so it works without
 * any script; the only script asks for confirmation before a deletion.
 */

require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/facts.php';
require_once __DIR__ . '/lib/legacy.php';

tir_require_admin();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tir_token_ok()) {
        $error = tir_text('ErrToken');
    } else {
        $do = (string)($_POST['do'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($do === 'create') {
            $title = tir_clean_name($_POST['title'] ?? '');
            if ($title === '') {
                $error = tir_text('ErrNameEmpty');
            } else {
                $newId = tir_create_show($title, (int)($_POST['source'] ?? 0));
                header('Location: edit.php?id=' . $newId);
                exit;
            }
        } elseif ($do === 'import') {
            $json = is_uploaded_file($_FILES['save']['tmp_name'] ?? '') ? (string)file_get_contents($_FILES['save']['tmp_name']) : '';
            $html = is_uploaded_file($_FILES['page']['tmp_name'] ?? '') ? (string)file_get_contents($_FILES['page']['tmp_name']) : '';
            $res  = $json === '' ? ['error' => tir_text('ErrLegacyFormat')] : tir_legacy_import($json, $html);
            if (isset($res['id'])) {
                header('Location: edit.php?id=' . $res['id']);
                exit;
            }
            $error = $res['error'];
        } elseif ($do === 'duplicate' && tir_show($id)) {
            tir_duplicate_show($id);
            header('Location: index.php');
            exit;
        } elseif ($do === 'delete' && tir_show($id)) {
            tir_delete_show($id);
            header('Location: index.php');
            exit;
        }
    }
}

$PAGE_TITLE = tir_text('ModuleName');
$JS_SCRIPT  = ['<link rel="stylesheet" href="' . tir_esc(tir_asset('assets/tirage.css')) . '">'];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$csrf = '<input type="hidden" name="csrf" value="' . tir_esc(tir_token()) . '">';
$base = tir_url();

$html  = '<div id="tir" class="tir-page">';
$html .= '<h1 class="tir-h1">' . tir_t('ModuleName') . '</h1>';
$html .= '<p class="tir-lead">' . tir_t('HomeLead') . '</p>';
if ($error !== '') $html .= '<div class="tir-msg tir-msg-err">' . tir_esc($error) . '</div>';

// --- Existing draws -------------------------------------------------------------
$shows = tir_show_list();
$html .= '<div class="tir-card"><h2>' . tir_t('ShowsTitle') . '</h2>';
if (!$shows) {
    $html .= '<p class="tir-hint">' . tir_t('ShowsNone') . '</p>';
} else {
    $html .= '<div class="tir-scroll"><table class="tir-table"><thead><tr>'
        . '<th>' . tir_t('ColTitle') . '</th><th>' . tir_t('ColProgress') . '</th>'
        . '<th>' . tir_t('ColUpdated') . '</th><th>' . tir_t('ColScreens') . '</th><th></th></tr></thead><tbody>';
    foreach ($shows as $s) {
        $pct = $s['teams'] ? round(100 * $s['drawn'] / $s['teams']) : 0;
        $html .= '<tr>'
            . '<td><a class="tir-showlink" href="' . tir_esc($base . 'edit.php?id=' . $s['id']) . '" title="' . tir_t('OpenEdit') . '"><b>'
            . tir_esc($s['title']) . '</b></a>'
            . ($s['subtitle'] !== '' ? '<br><span class="tir-hint">' . tir_esc($s['subtitle']) . '</span>' : '') . '</td>'
            . '<td><div class="tir-bar"><i style="width:' . $pct . '%"></i></div>'
            . '<span class="tir-hint">' . tir_t('Progress', ['drawn' => $s['drawn'], 'total' => $s['teams']]) . '</span></td>'
            . '<td class="tir-nowrap">' . tir_esc($s['updated']) . '</td>'
            . '<td><div class="tir-actions">'
            . '<a class="tir-btn tir-btn-primary" href="' . tir_esc($base . 'control.php?id=' . $s['id']) . '">' . tir_t('OpenControl') . '</a>'
            . '<a class="tir-btn" href="' . tir_esc($base . 'edit.php?id=' . $s['id']) . '">' . tir_t('OpenEdit') . '</a>'
            . '<a class="tir-btn" target="_blank" href="' . tir_esc($base . 'display.php?t=' . $s['token']) . '">' . tir_t('OpenDisplay') . '</a>'
            . '<a class="tir-btn" target="_blank" href="' . tir_esc($base . 'speaker.php?t=' . $s['speakerToken']) . '">' . tir_t('OpenSpeaker') . '</a>'
            . '</div></td><td><div class="tir-actions">'
            . '<form method="post">' . $csrf . '<input type="hidden" name="do" value="duplicate">'
            . '<input type="hidden" name="id" value="' . $s['id'] . '">'
            . '<button class="tir-btn" type="submit" title="' . tir_t('DuplicateHint') . '">' . tir_t('Duplicate') . '</button></form>'
            . '<form method="post" class="tir-confirm" data-confirm="' . tir_t('ConfirmDeleteShow', $s['title']) . '">' . $csrf
            . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . $s['id'] . '">'
            . '<button class="tir-btn tir-btn-danger" type="submit">' . tir_t('Delete') . '</button></form>'
            . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= '</div>';

// --- New draw -------------------------------------------------------------------
$options = '<option value="0">' . tir_t('SourceNone') . '</option>';
foreach (tir_competitions() as $c) {
    $options .= '<option value="' . $c['id'] . '">' . tir_esc($c['date'] . ' — ' . $c['code'] . ' — ' . $c['name']) . '</option>';
}
$html .= '<div class="tir-grid2">';
$html .= '<div class="tir-card"><h2>' . tir_t('CreateTitle') . '</h2>'
    . '<form method="post" class="tir-form">' . $csrf . '<input type="hidden" name="do" value="create">'
    . '<label>' . tir_t('FieldTitle') . '<input type="text" name="title" required maxlength="120" placeholder="' . tir_t('FieldTitlePlaceholder') . '"></label>'
    . '<label>' . tir_t('FieldSource') . '<select name="source">' . $options . '</select></label>'
    . '<p class="tir-hint">' . tir_t('FieldSourceHint') . '</p>'
    . '<button class="tir-btn tir-btn-primary" type="submit">' . tir_t('Create') . '</button>'
    . '</form></div>';

// --- Import of an old save ------------------------------------------------------
$html .= '<div class="tir-card"><h2>' . tir_t('ImportTitle') . '</h2>'
    . '<p class="tir-hint">' . tir_t('ImportLead') . '</p>'
    . '<form method="post" enctype="multipart/form-data" class="tir-form">' . $csrf . '<input type="hidden" name="do" value="import">'
    . '<label>' . tir_t('ImportSave') . '<input type="file" name="save" accept=".json,application/json" required></label>'
    . '<label>' . tir_t('ImportPage') . '<input type="file" name="page" accept=".html,.htm,text/html"></label>'
    . '<button class="tir-btn" type="submit">' . tir_t('Import') . '</button>'
    . '</form></div>';
$html .= '</div></div>';

echo $html;
?>
<script>
/* Deletion asks first: a draw removed is gone with its history and notes. */
document.querySelectorAll('#tir form.tir-confirm').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
});
</script>
<?php
include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
