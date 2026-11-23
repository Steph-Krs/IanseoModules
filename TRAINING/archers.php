<?php
/**
 * Regular archers: the people ticked when a training is created.
 *
 * Filled by hand, from the licence file already loaded in ianseo, or from the
 * archers of the open competition; grouped so that a whole group is ticked at
 * once.
 */

require_once __DIR__ . '/lib/boot.php';
trn_require_admin();

if (isset($_GET['lookup'])) {
    JsonOut(['error' => 0, 'msg' => '', 'rows' => trn_lookup_search((string)$_GET['lookup'])]);
}

$tourId = intval($_SESSION['TourId'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('archers.php');
    }
    $act = (string)($_POST['act'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    if ($act === 'save') {
        $data = trn_archer_clean($_POST);
        if ($data['ArFamilyName'] === '') {
            trn_flash(trn_text('ErrNoName'), 'err');
        } else {
            $id = trn_archer_save($data, $id);
            trn_archer_set_groups($id, $_POST['groups'] ?? []);
            trn_flash(trn_text('Saved'));
        }
    } elseif ($act === 'delete' && $id) {
        trn_archer_delete($id);
        trn_flash(trn_text('ArcherDeleted'));
    } elseif ($act === 'group_save') {
        trn_group_save($_POST['name'] ?? '', $id);
    } elseif ($act === 'group_delete' && $id) {
        trn_group_delete($id);
    } elseif ($act === 'import' && $tourId) {
        $n = trn_import_from_competition($tourId, $_POST['en'] ?? []);
        trn_flash(trn_text('ArchersImported', $n));
    }
    trn_redirect('archers.php');
}

$archers = trn_archers();
$groups = trn_groups();
$edit = $archers[intval($_GET['edit'] ?? 0)] ?? null;

// Division and class codes offered as suggestions: those of the formats. The class bounds go to
// the script, which fills the class from the date of birth (the first format defining a code wins).
$codes = ['div' => [], 'cls' => []];
$classRules = [];
foreach (trn_tpl_list() as $f) {
    foreach ($f->Summary['divs'] ?? [] as $k => $v) $codes['div'][$k] = $v['name'];
    foreach ($f->Summary['classes'] ?? [] as $k => $v) {
        $codes['cls'][$k] = $v['name'];
        if (!isset($classRules[$k])) $classRules[$k] = ['cls' => (string)$k, 'sex' => $v['sex'], 'from' => $v['from'], 'to' => $v['to'],
            'divs' => $v['divs'], 'order' => $v['order']];
    }
}
uasort($classRules, function ($a, $b) { return $a['order'] <=> $b['order']; });

trn_page_open(trn_text('ArchersTitle'), ['assets/training.js']);
echo '<p class="trn-lead">' . trn_t('ArchersLead') . '</p>';

// Add or edit.
$v = function ($col) use ($edit) { return $edit ? trn_esc($edit->$col) : ''; };
echo '<h2>' . trn_t($edit ? 'ArcherEdit' : 'ArcherAdd') . '</h2>';
echo '<div class="trn-card">';
echo '<p class="trn-search"><label>' . trn_t('LookupLabel') . ' <input type="search" id="trn-lookup" size="30" placeholder="'
    . trn_t('LookupPlaceholder') . '"></label></p><div id="trn-lookup-res"></div>';
echo '<script type="application/json" id="trn-classes">' . json_encode(array_values($classRules), JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
echo '<form method="post" id="trn-archer-form" data-year="' . trn_season_year(true, date('Y-m-d'), date('Y-m-d')) . '">' . trn_token_field()
    . '<input type="hidden" name="act" value="save"><input type="hidden" name="id" value="' . ($edit ? intval($edit->ArId) : 0) . '">'
    . '<div class="trn-grid">'
    . '<label>' . trn_t('FieldFamily') . ' <input type="text" name="family" required maxlength="60" value="' . $v('ArFamilyName') . '"></label>'
    . '<label>' . trn_t('FieldGiven') . ' <input type="text" name="given" maxlength="30" value="' . $v('ArGivenName') . '"></label>'
    . '<label>' . trn_t('FieldSex') . ' <select name="sex"><option value="0">' . trn_t('SexM') . '</option><option value="1"'
    . ($edit && intval($edit->ArSex) ? ' selected' : '') . '>' . trn_t('SexF') . '</option></select></label>'
    . '<label>' . trn_t('FieldDob') . ' <input type="date" name="dob" value="' . ($edit && trn_is_date($edit->ArDob) ? trn_esc($edit->ArDob) : '') . '"></label>'
    . '<label>' . trn_t('FieldCode') . ' <input type="text" name="code" maxlength="25" value="' . $v('ArCode') . '"></label>'
    . '<label>' . trn_t('FieldIoc') . ' <input type="text" name="ioc" maxlength="5" size="5" value="' . $v('ArIocCode') . '"></label>'
    . '<label>' . trn_t('FieldClub') . ' <input type="text" name="club" maxlength="10" size="10" value="' . $v('ArClubCode') . '"></label>'
    . '<label>' . trn_t('FieldClubName') . ' <input type="text" name="clubname" maxlength="80" value="' . $v('ArClubName') . '"></label>'
    . '<label>' . trn_t('FieldDiv') . ' <input type="text" name="div" maxlength="4" size="5" list="trn-divs" value="' . ($edit ? $v('ArDivision') : TRN_DEFAULT_DIVISION) . '"></label>'
    . '<label>' . trn_t('FieldCls') . ' <input type="text" name="cls" maxlength="6" size="6" list="trn-clss" value="' . $v('ArClass') . '"></label>'
    . '<label><input type="checkbox" name="active" value="1"' . (!$edit || intval($edit->ArActive) ? ' checked' : '') . '> ' . trn_t('FieldActive') . '</label>'
    . '</div><p class="trn-note">' . trn_t('ClassAuto') . '</p>';
foreach (['div' => 'trn-divs', 'cls' => 'trn-clss'] as $k => $list) {
    echo '<datalist id="' . $list . '">';
    foreach ($codes[$k] as $code => $name) echo '<option value="' . trn_esc($code) . '">' . trn_esc($name) . '</option>';
    echo '</datalist>';
}
if ($groups) {
    echo '<p>' . trn_t('FieldGroups') . ' ';
    foreach ($groups as $gid => $gname) {
        $on = $edit && in_array($gid, $edit->Groups, true);
        echo '<label class="trn-chip"><input type="checkbox" name="groups[]" value="' . $gid . '"' . ($on ? ' checked' : '') . '> ' . trn_esc($gname) . '</label> ';
    }
    echo '</p>';
}
echo '<p><button type="submit" class="trn-btn trn-primary">' . trn_t('Save') . '</button>'
    . ($edit ? ' <a class="trn-btn" href="archers.php">' . trn_t('Cancel') . '</a>' : '') . '</p></form></div>';

// The list.
echo '<h2>' . trn_t('ArchersList', count($archers)) . '</h2>';
if (!$archers) {
    echo '<p class="trn-note">' . trn_t('ArchersNone') . '</p>';
} else {
    echo '<p><input type="search" class="trn-filter" data-target="#trn-archer-table" placeholder="' . trn_t('Filter') . '"></p>';
    echo '<table class="Tabella trn-table" id="trn-archer-table"><tr><th>' . trn_t('FieldFamily') . '</th><th>' . trn_t('FieldGiven')
        . '</th><th>' . trn_t('FieldCode') . '</th><th>' . trn_t('FieldClub') . '</th><th>' . trn_t('ColCategory') . '</th><th>'
        . trn_t('FieldGroups') . '</th><th></th></tr>';
    foreach ($archers as $a) {
        $gn = [];
        foreach ($a->Groups as $g) if (isset($groups[$g])) $gn[] = $groups[$g];
        echo '<tr class="trn-row' . (intval($a->ArActive) ? '' : ' trn-off') . '"><td>' . trn_esc($a->ArFamilyName) . '</td><td>' . trn_esc($a->ArGivenName)
            . '</td><td>' . trn_esc($a->ArCode) . '</td><td>' . trn_esc(trim($a->ArClubCode . ' ' . $a->ArClubName)) . '</td><td>'
            . trn_esc(trim($a->ArDivision . ' ' . $a->ArClass)) . '</td><td>' . trn_esc(implode(', ', $gn)) . '</td>'
            . '<td class="trn-actions"><a class="trn-btn" href="archers.php?edit=' . intval($a->ArId) . '">' . trn_t('Edit') . '</a> '
            . '<form method="post" class="trn-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
            . trn_esc(trn_text('ArcherDeleteConfirm', trim($a->ArFamilyName . ' ' . $a->ArGivenName))) . '">' . trn_token_field()
            . '<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="' . intval($a->ArId) . '">'
            . '<button type="submit" class="trn-btn trn-danger">' . trn_t('Delete') . '</button></form></td></tr>';
    }
    echo '</table>';
}

// Groups.
echo '<h2>' . trn_t('GroupsTitle') . '</h2><div class="trn-card">';
foreach ($groups as $gid => $gname) {
    echo '<form method="post" class="trn-inline">' . trn_token_field() . '<input type="hidden" name="act" value="group_save">'
        . '<input type="hidden" name="id" value="' . $gid . '"><input type="text" name="name" maxlength="64" value="' . trn_esc($gname) . '"> '
        . '<button type="submit" class="trn-btn">' . trn_t('Rename') . '</button></form> '
        . '<form method="post" class="trn-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
        . trn_esc(trn_text('GroupDeleteConfirm', $gname)) . '">' . trn_token_field()
        . '<input type="hidden" name="act" value="group_delete"><input type="hidden" name="id" value="' . $gid . '">'
        . '<button type="submit" class="trn-btn trn-danger">' . trn_t('Delete') . '</button></form><br>';
}
echo '<form method="post">' . trn_token_field() . '<input type="hidden" name="act" value="group_save">'
    . '<input type="text" name="name" maxlength="64" placeholder="' . trn_t('GroupNew') . '"> '
    . '<button type="submit" class="trn-btn">' . trn_t('Add') . '</button></form></div>';

// Copy from the open competition.
if ($tourId) {
    $known = [];
    foreach ($archers as $a) if ($a->ArCode !== '') $known[$a->ArCode] = true;
    $list = array_filter(trn_competition_archers($tourId), function ($e) use ($known) { return $e->EnCode === '' || !isset($known[$e->EnCode]); });
    echo '<h2>' . trn_t('ImportTitle') . '</h2>';
    if (!$list) {
        echo '<p class="trn-note">' . trn_t('ImportNone') . '</p>';
    } else {
        echo '<form method="post" class="trn-card">' . trn_token_field() . '<input type="hidden" name="act" value="import">'
            . '<p><label><input type="checkbox" class="trn-check-all" data-target="#trn-import"> ' . trn_t('CheckAll') . '</label></p>'
            . '<div id="trn-import" class="trn-columns">';
        foreach ($list as $e) {
            echo '<label><input type="checkbox" name="en[]" value="' . intval($e->EnId) . '"> '
                . trn_esc(trim($e->EnFirstName . ' ' . $e->EnName)) . ' <span class="trn-note">' . trn_esc($e->EnDivision . $e->EnClass) . '</span></label>';
        }
        echo '</div><p><button type="submit" class="trn-btn trn-primary">' . trn_t('ImportDo') . '</button></p></form>';
    }
}

trn_page_close();
