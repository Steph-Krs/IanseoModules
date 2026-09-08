<?php
/**
 * Update screen of the Interactive Guide module.
 *
 * TWO INDEPENDENT UPDATE CHANNELS, deliberately kept apart:
 *
 *   1. COURSES (content/*.json). Each content file carries its own `version`
 *      field and is compared against the repository file by file, so a site can
 *      take a new version of one course without touching the others, and can
 *      keep courses of its own that the repository knows nothing about. This is
 *      why content is NOT listed in version.json files[]: syncing it wholesale
 *      would overwrite locally written courses on every module update.
 *
 *   2. THE MODULE ITSELF (the engine files listed in version.json files[]),
 *      synced as a set, together with the shared _shared/ library.
 *
 * Most of the page is assembled from the shared building blocks in
 * _shared/update-ui.php, so every module's update screen looks and behaves the
 * same; the course section above is what this module adds of its own.
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
require_once dirname(dirname(__DIR__)) . '/_shared/update-ui.php';   // shared rendering and POST handlers
require_once dirname(__DIR__) . '/lib/guide-lib.inc.php';

guide_check_admin();

$PAGE_TITLE = guide_text('UpdTitle');
$MODULE_DIR = dirname(__DIR__);
$moduleUrl  = function_exists('cmod_url') ? cmod_url($MODULE_DIR) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/';

/* =======================================================================
 * Course helpers, specific to this module
 * =====================================================================*/

/**
 * The courses installed locally, keyed by their content id.
 *
 * Keyed by the id declared INSIDE the file, not by filename: renaming a file
 * must not look like deleting one course and adding another.
 *
 * @return array id => ['version', 'file', 'title']
 */
function guide_local_formations() {
    global $MODULE_DIR;

    $result = [];
    foreach (glob($MODULE_DIR . '/content/*.json') as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['id'])) continue;

        $result[$d['id']] = [
            'version' => $d['version'] ?? '1.0',
            'file'    => $f,
            // A title may carry several languages; resolve it for display.
            'title'   => guide_i18n($d['title'] ?? $d['id']),
        ];
    }
    return $result;
}

/* =======================================================================
 * Setup
 * =====================================================================*/

$cfg      = upd_load_config($MODULE_DIR);
$messages = [];
$action   = $_POST['action'] ?? '';

// Installing another module of the repository, handled before the catalogue of
// other modules is built so the list reflects what just happened.
upd_install_handle($cfg, $messages);

$localVer    = upd_local_version($MODULE_DIR);
$localModVer = $localVer['version'] ?? null;

$checkResults      = null;
$moduleCheckResult = null;
$sharedCheck       = null;

/* =======================================================================
 * POST actions
 * =====================================================================*/

if ($action === 'check') {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) {
        $messages[] = ['err', upd_text('ErrBadGitHubUrl')];
    } else {
        $branch  = $cfg['github_branch'] ?: 'main';
        $token   = $cfg['github_token']  ?: '';
        $prefix  = upd_remote_prefix($cfg);
        $apiBase = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}";

        // Courses: one API call for the folder listing, then one raw download per
        // file, because the version to compare lives inside each file.
        $contentList = upd_gh_fetch("$apiBase/contents/{$prefix}content?ref=$branch", $token);
        if (isset($contentList['_error'])) {
            $messages[] = ['err', 'GitHub: ' . $contentList['_error']];
        } else {
            $local        = guide_local_formations();
            $checkResults = [];

            foreach ($contentList as $item) {
                if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;

                $raw  = upd_gh_raw($item['download_url'], $token);
                $data = $raw ? json_decode($raw, true) : null;
                if (!$data || empty($data['id'])) continue;

                $id     = $data['id'];
                $remVer = $data['version'] ?? '1.0';
                $locVer = $local[$id]['version'] ?? null;

                // version_compare handles X.Y.Z properly; a string comparison
                // would rank 1.10 below 1.9.
                $status = ($locVer === null) ? 'new'
                        : (version_compare($remVer, $locVer, '>') ? 'update' : 'ok');

                $checkResults[] = [
                    'id'         => $id,
                    'title'      => guide_i18n($data['title'] ?? $id),
                    'local_ver'  => $locVer,
                    'remote_ver' => $remVer,
                    'status'     => $status,
                    'filename'   => $item['name'],
                    'raw_url'    => $item['download_url'],
                ];
            }
        }

        $remoteVer = upd_remote_version($cfg);
        if (isset($remoteVer['_error'])) {
            $messages[] = ['err', 'Module: ' . $remoteVer['_error']];
        } else {
            $moduleCheckResult = [
                'local'  => $localModVer,
                'remote' => $remoteVer['version'],
                'notes'  => $remoteVer['notes'] ?? null,
                'date'   => $remoteVer['date']  ?? null,
                'update' => ($localModVer === null || version_compare($remoteVer['version'], $localModVer, '>')),
            ];
        }
    }
}

if ($action === 'update-formations') {
    $mode = $_POST['mode'] ?? 'merge';
    $repo = upd_parse_repo($cfg['github_url'] ?? '');

    if (!$repo) {
        $messages[] = ['err', upd_text('ErrBadGitHubUrl')];
    } else {
        // The obsolete-progress update below writes to the module's tables, so
        // the schema has to exist before we get there.
        guide_ensure_schema();

        $branch      = $cfg['github_branch'] ?: 'main';
        $token       = $cfg['github_token']  ?: '';
        $prefix      = upd_remote_prefix($cfg);
        $apiBase     = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}";
        $contentList = upd_gh_fetch("$apiBase/contents/{$prefix}content?ref=$branch", $token);
        $local       = guide_local_formations();
        $contentDir  = $MODULE_DIR . '/content/';
        $updated = $added = $skipped = 0;

        if (isset($contentList['_error'])) {
            $messages[] = ['err', 'GitHub: ' . $contentList['_error']];
        } else {
            // Replace mode deletes the local courses the repository does not
            // carry. The remote ids are collected in full FIRST: deleting while
            // discovering would remove a course the listing had not reached yet.
            if ($mode === 'replace') {
                $remoteIds = [];
                foreach ($contentList as $item) {
                    if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;
                    $raw  = upd_gh_raw($item['download_url'], $token);
                    $data = $raw ? json_decode($raw, true) : null;
                    if ($data && !empty($data['id'])) $remoteIds[] = $data['id'];
                }
                foreach ($local as $lid => $ldata) {
                    if (!in_array($lid, $remoteIds)) unlink($ldata['file']);
                }
            }

            foreach ($contentList as $item) {
                if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;

                $raw  = upd_gh_raw($item['download_url'], $token);
                $data = $raw ? json_decode($raw, true) : null;
                if (!$data || empty($data['id']) || !is_string($raw)) continue;

                $id     = $data['id'];
                $remVer = $data['version'] ?? '1.0';
                $locVer = $local[$id]['version'] ?? null;

                // Merge mode leaves alone anything that is not strictly newer,
                // so a locally edited course is not silently reverted.
                if ($locVer !== null && !version_compare($remVer, $locVer, '>') && $mode === 'merge') {
                    $skipped++;
                    continue;
                }

                // Written verbatim rather than re-encoded: the file is already
                // the JSON the client expects, and re-encoding a base64 image
                // for nothing is expensive.
                file_put_contents($contentDir . $item['name'], $raw);

                if ($locVer !== null) {
                    $updated++;

                    // A course that gained a new version invalidates the badge of
                    // anyone who completed the previous one: they are marked
                    // obsolete so the catalogue can invite them to run it again.
                    if (version_compare($remVer, $locVer, '>')) {
                        $now = date('Y-m-d H:i:s');
                        safe_w_sql("UPDATE GuideProgress
                            SET GpStatus='obsolete', GpUpdatedAt=" . StrSafe_DB($now) . "
                            WHERE GpFormId=" . StrSafe_DB($id) . "
                            AND GpStatus='termine'
                            AND GpFormVer != " . StrSafe_DB($remVer));
                    }
                } else {
                    $added++;
                }
            }

            $messages[] = ['ok', guide_text('UpdCoursesResult',
                ['updated' => $updated, 'added' => $added, 'skipped' => $skipped])];
        }
    }
}

if ($action === 'update-module') {
    $remoteVer = upd_remote_version($cfg);

    if (isset($remoteVer['_error'])) {
        $messages[] = ['err', 'version.json: ' . $remoteVer['_error']];
    } elseif (empty($remoteVer['files'])) {
        $messages[] = ['err', 'The remote version.json carries no files[] list.'];
    } else {
        $result = upd_sync_files($cfg, $MODULE_DIR, $remoteVer['files']);

        // Re-read the local version: version.json is one of the files that was
        // just replaced, so the value held in memory is now stale.
        $localVer    = upd_local_version($MODULE_DIR);
        $localModVer = $localVer['version'] ?? null;

        if (empty($result['fail'])) {
            $messages[] = ['ok', upd_text('MsgModuleUpdated',
                ['version' => $remoteVer['version'], 'files' => $result['ok']])];
        } else {
            $messages[] = ['err', "{$result['ok']} file(s) OK. Failed: " . implode(', ', $result['fail'])];
        }

        // The shared library is realigned in the same move, so a module update
        // can never leave it behind the code that depends on it.
        $sh = upd_sync_shared($cfg);
        if (isset($sh['_error']))    $messages[] = ['err', '_shared: ' . $sh['_error']];
        elseif (!empty($sh['fail'])) $messages[] = ['err', '_shared: failed ' . implode(', ', $sh['fail'])];
        elseif ($sh['ok'])           $messages[] = ['ok', "_shared synchronised (v{$sh['version']}, {$sh['ok']} file(s))."];
    }
}

// State of the shared library and catalogue of the repository's other modules.
if ($action === 'check' || $action === 'update-module') {
    $rs = upd_remote_shared_version($cfg);
    if (!isset($rs['_error'])) {
        $ls  = upd_local_shared_version();
        $lsv = $ls['version'] ?? null;
        $sharedCheck = [
            'local'  => $lsv,
            'remote' => $rs['version'],
            'update' => ($lsv === null || version_compare($rs['version'], $lsv, '>')),
        ];
    }
}
$othersState = upd_others_state($cfg);

/* Everything up to date? Only then are the update buttons folded away behind
   "force an update", so the common case is a page with nothing to click. */
$repo        = upd_parse_repo($cfg['github_url'] ?? '');
$allUpToDate = false;
if ($checkResults !== null) {
    $hasCourseUpdates = !empty(array_filter($checkResults, function ($r) { return $r['status'] !== 'ok'; }));
    $hasModuleUpdate  = $moduleCheckResult && $moduleCheckResult['update'];
    $hasSharedUpdate  = $sharedCheck && $sharedCheck['update'];
    $allUpToDate      = !$hasCourseUpdates && !$hasModuleUpdate && !$hasSharedUpdate && !empty($checkResults);
}

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

/* =======================================================================
 * Rendering
 *
 * The page is produced in PHP rather than woven out of template tags: ianseo
 * asks that a file be in one language at a time, and this screen is exactly the
 * kind that suffers otherwise — nested conditions, two tables and half a dozen
 * forms, whose structure was only readable by matching each opening tag with an
 * "endif" pages further down.
 *
 * Anything a sibling module also shows comes from _shared/update-ui.php, so the
 * source block, the shared-library line, the catalogue of other modules and the
 * danger zone are written once for all modules.
 * =====================================================================*/

/** Every value that reaches the page goes through this. */
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

/** The sub-headings of this page all look alike. */
$h3 = ' style="font-size:14px;color:#333;margin:0 0 8px"';

/**
 * One submit button in a form of its own, behind a confirmation.
 *
 * Each action is its own form because they post different hidden fields; the
 * confirmation text is JSON-encoded so a quote in a translation cannot break
 * out of the attribute.
 */
$applyForm = function (array $fields, $confirm, $class, $label, $style = '') use ($esc) {
    $html = '<form method="post" style="display:inline" onsubmit="return confirm('
          . $esc(json_encode($confirm)) . ')">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $esc($name) . '" value="' . $esc($value) . '">';
    }
    return $html . '<button type="submit" class="' . $class . '"'
         . ($style !== '' ? ' style="' . $style . '"' : '') . '>'
         . $esc($label) . '</button></form>';
};

$page = upd_ui_styles()
      . '<h1>' . $esc(guide_text('UpdTitle')) . '</h1>'
      . '<p><a href="' . $esc($moduleUrl . 'admin/') . '">← '
      . $esc(guide_text('AdmBackAdmin')) . '</a></p>';

foreach ($messages as [$type, $text]) {
    $page .= '<div class="upd-msg ' . ($type === 'ok' ? 'upd-msg-ok' : 'upd-msg-err') . '">'
           . $esc($text) . '</div>';
}

$page .= upd_ui_source($cfg, $localVer, $localModVer);

// Nothing below makes sense without a repository to compare against.
if ($repo) {
    $page .= '<div class="upd-section">'
           . '<h2>' . $esc(upd_text('CheckHeading')) . '</h2>'
           . '<form method="post" style="display:inline">'
           . '<input type="hidden" name="action" value="check">'
           . '<button type="submit" class="upd-btn upd-btn-check">🔍 '
           . $esc(upd_text('CheckNow')) . '</button></form>';

    if ($checkResults !== null) {
        $headings = [
            guide_text('AdmColTitle'), 'ID', upd_text('LocalVersion'),
            upd_text('RemoteVersion'), upd_text('ColState'),
        ];
        $page .= '<br><br><h3' . $h3 . '>' . $esc(guide_text('UpdCourses')) . '</h3>'
               . '<table class="upd-table"><thead><tr>';
        foreach ($headings as $heading) $page .= '<th>' . $esc($heading) . '</th>';
        $page .= '</tr></thead><tbody>';

        foreach ($checkResults as $r) {
            if ($r['status'] === 'ok') {
                $badge = '<span class="upd-badge upd-ok">✓ ' . $esc(upd_text('StatusUpToDate')) . '</span>';
            } elseif ($r['status'] === 'new') {
                $badge = '<span class="upd-badge upd-new">' . $esc(upd_text('StatusNew')) . '</span>';
            } else {
                $badge = '<span class="upd-badge upd-update">' . $esc(upd_text('StatusUpdate')) . '</span>';
            }
            $page .= '<tr><td>' . $esc($r['title']) . '</td>'
                   . '<td style="font-family:monospace;font-size:11px;color:#666">' . $esc($r['id']) . '</td>'
                   . '<td>' . $esc($r['local_ver'] ?? '—') . '</td>'
                   . '<td>' . $esc($r['remote_ver']) . '</td>'
                   . '<td>' . $badge . '</td></tr>';
        }
        if (empty($checkResults)) {
            $page .= '<tr><td colspan="5" style="color:#888;font-style:italic">'
                   . $esc(guide_text('UpdNoCourseFound')) . '</td></tr>';
        }
        $page .= '</tbody></table>';

        if ($moduleCheckResult) {
            $date = $moduleCheckResult['date']
                ? ' <span style="color:#888;font-size:11px">(' . $esc($moduleCheckResult['date']) . ')</span>'
                : '';
            $state = $moduleCheckResult['update']
                ? '<span class="upd-badge upd-update">' . $esc(upd_text('StatusUpdate')) . '</span>'
                : '<span class="upd-badge upd-ok">' . $esc(upd_text('StatusUpToDate')) . '</span>';

            $page .= '<br><h3' . $h3 . '>' . $esc(guide_text('UpdEngineFiles')) . '</h3>'
                   . '<p style="font-size:13px">'
                   . $esc(upd_text('LocalVersion')) . ' : <b>'
                   . $esc($moduleCheckResult['local'] ?? upd_text('Unknown')) . '</b>&nbsp;|&nbsp;'
                   . $esc(upd_text('RemoteVersion')) . ' : <b>'
                   . $esc($moduleCheckResult['remote']) . '</b>' . $date . '&nbsp;' . $state . '</p>';

            if ($moduleCheckResult['notes']) {
                $page .= '<p class="upd-notes">' . $esc($moduleCheckResult['notes']) . '</p>';
            }
        }

        $page .= upd_ui_shared_status($sharedCheck);
    }
    $page .= '</div>';

    /* Everything already up to date? Then the buttons fold away behind "force an
       update", so the ordinary case is a page with nothing to click. */
    $page .= '<div class="upd-section">';
    $page .= $allUpToDate
        ? '<details class="upd-force"><summary>' . $esc(upd_text('ForceUpdate'))
          . '</summary><div class="upd-force-body">'
        : '<h2>' . $esc(upd_text('ApplyHeading')) . '</h2>';

    $page .= '<div style="margin-bottom:20px">'
           . '<h3' . $h3 . '>' . $esc(guide_text('UpdCourses')) . '</h3>'
           . $applyForm(['action' => 'update-formations', 'mode' => 'merge'],
                        guide_text('UpdMergeConfirm'), 'upd-btn upd-btn-apply',
                        '↓ ' . guide_text('UpdMerge'))
           . $applyForm(['action' => 'update-formations', 'mode' => 'replace'],
                        guide_text('UpdReplaceConfirm'), 'upd-btn upd-btn-danger',
                        '↓ ' . guide_text('UpdReplace'), 'margin-left:8px')
           /* The two hints carry deliberate markup from the language files. */
           . '<p class="upd-hint">' . guide_text('UpdMergeHint') . '<br>'
           . guide_text('UpdReplaceHint') . '</p>'
           . '</div>';

    $page .= '<div><h3' . $h3 . '>' . $esc(guide_text('UpdEngineFiles')) . '</h3>'
           . $applyForm(['action' => 'update-module'], upd_text('UpdateModuleConfirm'),
                        'upd-btn upd-btn-module', '↓ ' . upd_text('UpdateModule'))
           . '<p class="upd-hint">' . upd_text('UpdateModuleHint') . '</p>'
           . '</div>';

    if ($allUpToDate) $page .= '</div></details>';
    $page .= '</div>';
}

$page .= upd_ui_others_block($cfg, $othersState, 'GUIDE')
       . upd_ui_danger_zone($MODULE_DIR);

echo $page;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
