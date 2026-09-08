<?php
/**
 * Administration home of the Interactive Guide module: the list of every
 * content item, with the way in to create, edit or delete one.
 *
 * Content lives in content/*.json, one file per item, and this page is a view
 * over that folder — nothing enumerates the items anywhere else, so dropping a
 * file in or removing one is enough.
 *
 * Three editors are reachable from here, chosen by content type: the visual
 * course editor (edit.php), the JSON editor used by checklists and
 * troubleshooting trees (edit-json.php), and the condition builder
 * (conditions.php) shared by both.
 */

// Walk up to the ianseo root instead of counting directory levels, so the module
// keeps working if it is installed somewhere other than Modules/Custom/.
$_guide_root = __DIR__;
while ($_guide_root !== dirname($_guide_root) && !is_file($_guide_root . '/config.php')) {
    $_guide_root = dirname($_guide_root);
}
define('HTDOCS', $_guide_root);
unset($_guide_root);

require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

// AclRoot is not enough on its own when an account module is installed — see
// guide_check_admin(). This aborts rather than degrading the page.
guide_check_admin();

$moduleUrl = function_exists('cmod_url') ? cmod_url(dirname(__DIR__)) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/';
$adminUrl  = $moduleUrl . 'admin/';

/* ---- Deletion ---- */

if (($_POST['action'] ?? '') === 'delete' && !empty($_POST['id'])) {
    // The identifier is reduced to lower-case letters, digits and hyphens, and
    // is then matched against the id INSIDE each file rather than used to build
    // a filename: a request can therefore never name a path of its own.
    $id = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['id']));

    foreach (glob(guide_content_dir() . '*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (isset($data['id']) && $data['id'] === $id) {
            unlink($file);
            break;
        }
    }
    header('Location: ' . $adminUrl);
    exit;
}

/* ---- Content list ---- */

$contents = [];
foreach (glob(guide_content_dir() . '*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['id'])) continue;

    // Text fields may carry several languages: resolve them for display, or
    // htmlspecialchars() would be handed an array and stop the page dead.
    $contents[] = [
        'id'          => $data['id'],
        'type'        => $data['type'] ?? 'formation',
        'title'       => guide_i18n($data['title'] ?? guide_text('Untitled')),
        'description' => guide_i18n($data['description'] ?? ''),
        'group'       => guide_i18n($data['group'] ?? ''),
        'steps_count' => count($data['steps'] ?? []),
        'has_quiz'    => !empty($data['quiz']['questions']),
        'has_chall'   => !empty($data['challenge']['conditions']),
        'version'     => $data['version'] ?? '',
        'file'        => basename($file),
    ];
}

// Grouped by type, then alphabetical. This is the editing view, so it is sorted
// for finding a known item — not by the learning path the catalogue uses.
usort($contents, function ($a, $b) {
    if ($a['type'] !== $b['type']) return strcmp($a['type'], $b['type']);
    return strcmp($a['title'], $b['title']);
});

$typeLabels = [
    'formation' => '🎓 ' . guide_text('AdmTypeCourse'),
    'checklist' => '🧰 ' . guide_text('Checklists'),
    'faq'       => '🛟 ' . guide_text('Troubleshooting'),
];

$PAGE_TITLE = guide_text('AdmTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
/* Scoped to this page's own class prefix: the module never restyles ianseo. */
.gadm-table { border-collapse: collapse; width: 100%; max-width: 900px; }
.gadm-table th { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; padding: 8px 12px; text-align: left; }
.gadm-table td { padding: 8px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
.gadm-table tr:hover td { background: #f7f9ff; }
.gadm-btn      { padding: 4px 12px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; }
.gadm-btn-edit { background: #0254a8; color: #fff; }
.gadm-btn-del  { background: #c0392b; color: #fff; }
.gadm-btn-new  { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; padding: 8px 20px; font-size: 14px; border-radius: 5px; border: none; cursor: pointer; }
.gadm-btn-upd  { background: #1a8a4a; color: #fff; padding: 8px 20px; font-size: 14px; border-radius: 5px; border: none; cursor: pointer; }
.gadm-btn-help { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; padding: 8px 20px; font-size: 14px; border-radius: 5px; cursor: pointer; }
.gadm-meta { color: #888; font-size: 12px; }
</style>

<?php
/**
 * The screen is produced in PHP rather than woven out of template tags: ianseo
 * asks that a file be in one language at a time, so the shape of the page — a
 * toolbar, then either an empty notice or a table — is readable in one place
 * instead of being spread over a loop that opens and closes screens apart.
 */

/** Every value that reaches the page goes through this. */
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

/** One link-wrapped button of the toolbar. */
$toolButton = function ($href, $class, $label) use ($esc) {
    return '<a href="' . $esc($href) . '"><button class="' . $class . '">'
         . $esc($label) . '</button></a>&nbsp;';
};

/**
 * What a row shows in its "content" column: a course is summarised by what it
 * carries, the other types have no comparable measure so their identifier is
 * the useful thing to show.
 */
$rowContent = function (array $c) use ($esc) {
    if ($c['type'] !== 'formation') return $esc($c['id']);
    $out = $esc(guide_text('StepsCount', $c['steps_count']));
    if ($c['has_quiz'])  $out .= ' · ' . $esc(guide_text('Quiz'));
    if ($c['has_chall']) $out .= ' · ' . $esc(guide_text('Challenge'));
    return $out;
};

/** One row of the table: what the item is, and what can be done to it. */
$row = function (array $c) use ($esc, $adminUrl, $typeLabels, $rowContent) {
    $editor  = $c['type'] === 'formation' ? 'edit.php' : 'edit-json.php';
    $confirm = $esc(json_encode(guide_text('AdmDeleteConfirm')));

    return '<tr>'
         . '<td class="gadm-meta">' . ($typeLabels[$c['type']] ?? $esc($c['type'])) . '</td>'
         . '<td><strong>' . $esc($c['title']) . '</strong><br>'
         . '<span class="gadm-meta">' . $esc($c['description']) . '</span></td>'
         . '<td class="gadm-meta">' . $esc($c['group']) . '</td>'
         . '<td class="gadm-meta">' . $rowContent($c) . '</td>'
         . '<td class="gadm-meta">' . $esc($c['version']) . '</td>'
         . '<td>'
         . '<a href="' . $esc($adminUrl . $editor) . '?id=' . urlencode($c['id']) . '">'
         . '<button class="gadm-btn gadm-btn-edit">' . $esc(guide_text('AdmEdit')) . '</button></a>&nbsp;'
         . '<form method="post" style="display:inline" onsubmit="return confirm(' . $confirm . ')">'
         . '<input type="hidden" name="action" value="delete">'
         . '<input type="hidden" name="id" value="' . $esc($c['id']) . '">'
         . '<button type="submit" class="gadm-btn gadm-btn-del">' . $esc(guide_text('AdmDelete')) . '</button>'
         . '</form>'
         . '</td></tr>';
};

$page = '<h1>' . $esc(guide_text('AdmHeading')) . '</h1><p>'
      . $toolButton($adminUrl . 'edit.php', 'gadm-btn-new', '+ ' . guide_text('AdmNewCourse'))
      . $toolButton($adminUrl . 'edit-json.php?new=checklist', 'gadm-btn-help', '+ ' . guide_text('AdmNewChecklist'))
      . $toolButton($adminUrl . 'edit-json.php?new=faq', 'gadm-btn-help', '+ ' . guide_text('AdmNewFaq'))
      . $toolButton($adminUrl . 'conditions.php', 'gadm-btn-help', '⚡ ' . guide_text('AdmConditions'))
      . $toolButton($adminUrl . 'update.php', 'gadm-btn-upd', '↑ ' . guide_text('AdmUpdates'))
      . $toolButton($adminUrl . 'help.php', 'gadm-btn-help', '❔ ' . guide_text('AdmHelp'))
      . '<a href="' . $esc($moduleUrl) . '">← ' . $esc(guide_text('AdmBackCatalogue')) . '</a>'
      . '</p>';

if (empty($contents)) {
    $page .= '<p><i>' . $esc(guide_text('AdmEmpty')) . '</i></p>';
} else {
    $headings = ['AdmColType', 'AdmColTitle', 'AdmColGroup', 'AdmColContent', 'AdmColVersion', 'AdmColActions'];
    $page .= '<table class="gadm-table"><thead><tr>';
    foreach ($headings as $key) $page .= '<th>' . $esc(guide_text($key)) . '</th>';
    $page .= '</tr></thead><tbody>';
    foreach ($contents as $c) $page .= $row($c);
    $page .= '</tbody></table>';
}

echo $page;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
