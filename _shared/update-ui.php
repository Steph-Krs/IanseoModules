<?php
/**
 * Shared rendering of the module update screens.
 *
 * One implementation of the behaviour — check, update, synchronise _shared,
 * install another module of the repository, uninstall — so every module's update
 * screen looks and behaves the same, and a fix made here reaches all of them.
 *
 * Two ways to use it:
 *   - a plain module: its admin/update.php is one call to
 *     upd_render_common_page();
 *   - a module with sections of its own (GUIDE and its courses, for instance):
 *     it assembles the page itself out of the upd_ui_*() blocks and the
 *     upd_*_handle() POST handlers.
 *
 * Every string here goes through upd_text(), so all of these screens are
 * translated once for every module rather than once per module.
 *
 * The palette is the one the modules share (#0254a8). A module's printouts keep
 * their own styling; this file is only about the administration page.
 */

require_once __DIR__ . '/update-lib.php';

/**
 * The stylesheet shared by every update screen.
 *
 * A superset: it also covers the tables a module adds in its own sections, so a
 * module with extra blocks does not need a stylesheet of its own.
 *
 * @return string A <style> element.
 */
function upd_ui_styles() {
    return <<<'CSS'
<style>
.upd-section { max-width: 860px; margin-bottom: 32px; }
.upd-section h2 { color: #0254a8; font-size: 16px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.upd-section h3 { font-size: 14px; color: #333; margin: 0 0 8px; }
.upd-btn { padding: 8px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.upd-btn-check  { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; }
.upd-btn-apply  { background: #1a8a4a; color: #fff; }
.upd-btn-module { background: #082c7c; color: #fff; }
.upd-btn-danger { background: #c0392b; color: #fff; }
.upd-btn + .upd-btn { margin-left: 8px; }
.upd-msg { padding: 8px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
.upd-msg-ok  { background: #e8faf0; border-left: 3px solid #1a8a4a; color: #1a5a33; }
.upd-msg-err { background: #fde8e8; border-left: 3px solid #c0392b; color: #8a1a1a; }
.upd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.upd-table th { background: #0254a8; color: #fff; padding: 8px 12px; text-align: left; font-weight: 600; }
.upd-table td { padding: 8px 12px; border-bottom: 1px solid #eef0f8; }
.upd-table tr:hover td { background: #f7f9ff; }
.upd-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; }
.upd-ok     { background: #d4f0de; color: #1a7a3a; }
.upd-new    { background: #e8f0ff; color: #0254a8; }
.upd-update { background: #fff0d4; color: #7a4a00; }
.upd-hint { font-size: 12px; color: #888; margin-top: 6px; }
.upd-source { font-size: 13px; color: #555; background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 6px; padding: 10px 14px; display: inline-block; line-height: 1.8; }
.upd-source b { color: #082c7c; }
/* pre-line: release notes are escaped before they reach the page, so their
   paragraph breaks are real newlines and not markup. Without this they would
   collapse into one block of running text. */
.upd-notes { font-size: 12px; color: #555; background: #fffbea; border-left: 3px solid #f5a623; padding: 6px 10px; border-radius: 0 6px 6px 0; margin-top: 6px; white-space: pre-line; }
.upd-warn { font-size: 13px; color: #8a1a1a; background: #fdecea; border: 1px solid #c0392b; border-left-width: 4px; border-radius: 0 6px 6px 0; padding: 10px 14px; margin: 0 0 14px; line-height: 1.6; }
details.upd-force > summary {
  cursor: pointer; list-style: none;
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 18px; border-radius: 6px;
  border: 1px solid #c8d4ec; background: #f0f4ff;
  color: #0254a8; font-size: 13px; font-weight: 600; user-select: none;
}
details.upd-force > summary::-webkit-details-marker { display: none; }
details.upd-force > summary::before { content: '\25B6'; font-size: 10px; display: inline-block; transition: transform .15s; }
details.upd-force[open] > summary::before { transform: rotate(90deg); }
details.upd-force .upd-force-body { margin-top: 16px; }
</style>
CSS;
}

/**
 * The "Source" block: where this module came from, read-only.
 *
 * Deliberately not editable: module.json is local configuration, and letting a
 * web form point a module at another repository would turn the update button
 * into a way of installing arbitrary code.
 *
 * @param array $cfg Module configuration.
 * @param array|null $localVer Decoded local version.json.
 * @param string|null $localModVer Local version string.
 * @return string HTML.
 */
function upd_ui_source($cfg, $localVer, $localModVer) {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    $html = '<div class="upd-section"><h2>' . upd_esc(upd_text('Source')) . '</h2>';

    if (!$repo) {
        return $html . '<p style="color:#c0392b;font-size:13px">&#9888; '
             . upd_esc(upd_text('NoRepoConfigured')) . '</p></div>';
    }

    $folder = empty($cfg['github_path']) ? ''
        : '&nbsp;|&nbsp; ' . upd_esc(upd_text('Folder')) . ' : <b>' . upd_esc($cfg['github_path']) . '</b>';
    $date = ($localVer && !empty($localVer['date'])) ? '&nbsp;(' . upd_esc($localVer['date']) . ')' : '';

    return $html
         . '<p class="upd-source">'
         . upd_esc(upd_text('Repository')) . ' : <b>' . upd_esc($cfg['github_url']) . '</b><br>'
         . upd_esc(upd_text('Branch')) . ' : <b>' . upd_esc($cfg['github_branch'] ?: 'main') . '</b>'
         . $folder
         . '<br>' . upd_esc(upd_text('LocalModuleVersion')) . ' : '
         . '<b>' . upd_esc($localModVer ?? upd_text('Unknown')) . '</b>' . $date
         . '</p></div>';
}

/**
 * POST handler: install another module of the repository.
 *
 * Must be called BEFORE upd_others_state(), so a module that was just installed
 * shows up as installed in the list rather than as still available.
 *
 * The name is checked against the repository listing before anything is written:
 * a name that passes the character whitelist but is not actually published must
 * not create a folder.
 *
 * @param array $cfg Module configuration.
 * @param array $messages Message list, appended to.
 */
function upd_install_handle($cfg, &$messages) {
    if (($_POST['action'] ?? '') !== 'install-module') return;

    $name = $_POST['name'] ?? '';
    if (!upd_valid_module_name($name)) {
        $messages[] = ['err', upd_text('MsgBadModuleName')];
        return;
    }

    $remote = upd_remote_modules($cfg);
    if (isset($remote['_error'])) {
        $messages[] = ['err', 'GitHub: ' . htmlspecialchars($remote['_error'])];
        return;
    }
    if (!in_array($name, $remote, true)) {
        $messages[] = ['err', upd_text('MsgModuleNotInRepo', htmlspecialchars($name))];
        return;
    }

    $res = upd_install_module($cfg, $name);
    if (isset($res['_error'])) {
        $messages[] = ['err', upd_text('MsgInstallError',
            ['name' => htmlspecialchars($name), 'error' => htmlspecialchars($res['_error'])])];
        return;
    }

    $extra = empty($res['fail'])
        ? ''
        : upd_text('MsgFailures', htmlspecialchars(implode(', ', $res['fail'])));

    $messages[] = ['ok', upd_text('MsgInstalled', [
        'name'    => htmlspecialchars($name),
        'version' => htmlspecialchars($res['version']),
        'files'   => (int)$res['ok'],
        'extra'   => $extra,
    ])];
}

/**
 * POST handler: the repository's module catalogue.
 *
 * @param array $cfg Module configuration.
 * @return array ['checked' => bool, 'error' => ?string, 'modules' => [['name','installed'], …]].
 */
function upd_others_state($cfg) {
    $state  = ['checked' => false, 'error' => null, 'modules' => []];
    $action = $_POST['action'] ?? '';

    // Only built on demand: it costs a GitHub API call, and the rate limit for
    // unauthenticated requests is low enough that doing it on every page load
    // would exhaust it.
    if ($action !== 'check-others' && $action !== 'install-module') return $state;

    $remote = upd_remote_modules($cfg);
    if (isset($remote['_error'])) {
        $state['error'] = $remote['_error'];
        return $state;
    }

    $state['checked'] = true;
    $installed = upd_list_modules();
    foreach ($remote as $n) {
        $state['modules'][] = ['name' => $n, 'installed' => in_array($n, $installed, true)];
    }
    return $state;
}

/**
 * The "other modules of this repository" block, folded away by default.
 *
 * @param array $cfg Module configuration.
 * @param array $state Output of upd_others_state().
 * @param string $selfName Name of the module rendering the page.
 * @return string HTML, empty when no repository is configured.
 */
function upd_ui_others_block($cfg, $state, $selfName) {
    if (!upd_parse_repo($cfg['github_url'] ?? '')) return '';

    // Opened when there is something to read inside — a listing or an error.
    $open = ($state['checked'] || $state['error']) ? ' open' : '';

    $html = '<div class="upd-section"><details class="upd-force"' . $open . '>'
          . '<summary>' . upd_esc(upd_text('OtherModules')) . '</summary>'
          . '<div class="upd-force-body">'
          . '<p class="upd-hint" style="margin-top:0">' . upd_esc(upd_text('OtherModulesHint')) . '</p>'
          . '<form method="post" style="display:inline">'
          . '<input type="hidden" name="action" value="check-others">'
          . '<button type="submit" class="upd-btn upd-btn-check">&#128269; '
          . upd_esc(upd_text('ShowAvailable')) . '</button></form>';

    if ($state['error']) {
        $html .= '<p class="upd-msg upd-msg-err" style="margin-top:12px">GitHub: '
               . upd_esc($state['error']) . '</p>';
    } elseif ($state['checked']) {
        $html .= '<table class="upd-table" style="margin-top:12px"><thead><tr>'
               . '<th>' . upd_esc(upd_text('ColModule')) . '</th>'
               . '<th>' . upd_esc(upd_text('ColState')) . '</th>'
               . '<th></th></tr></thead><tbody>';

        foreach ($state['modules'] as $m) {
            $self = $m['name'] === $selfName
                ? ' <span class="upd-hint">' . upd_esc(upd_text('ThisModule')) . '</span>' : '';
            $badge = $m['installed']
                ? '<span class="upd-badge upd-ok">&#10003; ' . upd_esc(upd_text('Installed')) . '</span>'
                : '<span class="upd-badge upd-new">' . upd_esc(upd_text('Available')) . '</span>';

            // Only a module that is not here yet can be installed.
            $action = '';
            if (!$m['installed']) {
                $action = '<form method="post" style="margin:0" onsubmit="return confirm('
                        . upd_esc(json_encode(upd_text('InstallConfirm', $m['name']))) . ')">'
                        . '<input type="hidden" name="action" value="install-module">'
                        . '<input type="hidden" name="name" value="' . upd_esc($m['name']) . '">'
                        . '<button type="submit" class="upd-btn upd-btn-module">&#8595; '
                        . upd_esc(upd_text('InstallButton')) . '</button></form>';
            }

            $html .= '<tr><td>' . upd_esc($m['name']) . $self . '</td>'
                   . '<td>' . $badge . '</td>'
                   . '<td>' . $action . '</td></tr>';
        }

        if (empty($state['modules'])) {
            $html .= '<tr><td colspan="3" style="color:#888;font-style:italic">'
                   . upd_esc(upd_text('NoModuleFound')) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    return $html . '</div></details></div>';
}

/**
 * The danger zone: the way in to uninstalling this module.
 *
 * Folded away and styled apart, and it only links to the uninstall screen —
 * nothing is deleted from here. The wording states exactly what will and will
 * not survive, because that is the question an administrator actually has.
 *
 * @param string $module_dir Module root.
 * @return string HTML.
 */
function upd_ui_danger_zone($module_dir) {
    global $CFG;

    $name    = basename($module_dir);
    $tables  = upd_module_tables($module_dir);
    $warning = upd_uninstall_warning($module_dir);
    $sharedUrl = function_exists('cmod_url')
        ? cmod_url(__DIR__)
        : $CFG->ROOT_DIR . 'Modules/Custom/_shared/';

    // Stated up front when deleting has effects outside the module's own folder.
    $warn = $warning ? '<div class="upd-warn"><b>&#9888;</b> ' . nl2br(upd_esc($warning)) . '</div>' : '';

    $recover = upd_esc(upd_uninstall_backup($module_dir)
        ? upd_text('UninstallBackupHint')
        : upd_text('UninstallRecoverHint'));

    // UninstallTablesHint carries its own markup, so only the table list is escaped.
    $tablesHint = $tables
        ? upd_text('UninstallTablesHint', upd_esc(implode(', ', $tables)))
        : upd_esc(upd_text('UninstallNoTables'));

    return '<div class="upd-section"><details class="upd-force">'
         . '<summary style="border-color:#e8b4ae;background:#fdf0ef;color:#c0392b">'
         . upd_esc(upd_text('UninstallModule')) . '</summary>'
         . '<div class="upd-force-body">'
         . $warn
         . '<p class="upd-hint" style="margin-top:0">'
         . upd_esc(upd_text('UninstallRemoves')) . ' ' . $recover . ' ' . $tablesHint . '</p>'
         . '<a class="upd-btn upd-btn-danger" style="text-decoration:none;display:inline-block"'
         . ' href="' . upd_esc($sharedUrl) . 'uninstall.php?module=' . urlencode($name) . '">&#128465; '
         . upd_esc(upd_text('UninstallButton', $name)) . '</a>'
         . '</div></details></div>';
}

/**
 * One line about the shared library, shown inside the check results.
 *
 * @param array|null $sharedCheck ['local', 'remote', 'update'], or null.
 * @return string HTML, empty when the library was not checked.
 */
function upd_ui_shared_status($sharedCheck) {
    if (!$sharedCheck) return '';

    $badge = $sharedCheck['update']
        ? '<span class="upd-badge upd-update">' . upd_esc(upd_text('StatusUpdate')) . '</span>'
        : '<span class="upd-badge upd-ok">&#10003; ' . upd_esc(upd_text('StatusUpToDate')) . '</span>';

    return '<p style="font-size:13px;margin-top:10px">'
         . upd_esc(upd_text('SharedLibrary')) . ' <code>_shared</code> : '
         . upd_esc(upd_text('SharedLocal')) . ' <b>'
         . upd_esc($sharedCheck['local'] ?? upd_text('Unknown')) . '</b>'
         . '&nbsp;|&nbsp; ' . upd_esc(upd_text('SharedRemote')) . ' <b>'
         . upd_esc($sharedCheck['remote']) . '</b>&nbsp;' . $badge
         . '<span class="upd-hint">— ' . upd_esc(upd_text('SharedAutoNote')) . '</span></p>';
}

/**
 * POST handler shared by every module: check, and update the module itself.
 *
 * @param array $cfg Module configuration.
 * @param string $module_dir Module root.
 * @param array $messages Message list, appended to.
 * @param array $opts after_update => callable():string, for a per-module reminder.
 * @return array ['checkResult' => ?array, 'sharedCheck' => ?array].
 */
function upd_module_handle($cfg, $module_dir, &$messages, $opts = []) {
    $out    = ['checkResult' => null, 'sharedCheck' => null];
    $action = $_POST['action'] ?? '';

    $localVer    = upd_local_version($module_dir);
    $localModVer = $localVer['version'] ?? null;

    if ($action === 'check' || $action === 'update-module') {
        $rs = upd_remote_shared_version($cfg);
        if (!isset($rs['_error'])) {
            $ls  = upd_local_shared_version();
            $lsv = $ls['version'] ?? null;
            $out['sharedCheck'] = [
                'local'  => $lsv,
                'remote' => $rs['version'],
                'update' => ($lsv === null || version_compare($rs['version'], $lsv, '>')),
            ];
        }
    }

    if ($action === 'check') {
        $remoteVer = upd_remote_version($cfg);
        if (isset($remoteVer['_error'])) {
            $messages[] = ['err', 'GitHub: ' . htmlspecialchars($remoteVer['_error'])];
        } else {
            $out['checkResult'] = [
                'local'  => $localModVer,
                'remote' => $remoteVer['version'],
                'notes'  => $remoteVer['notes'] ?? null,
                'date'   => $remoteVer['date']  ?? null,
                'update' => ($localModVer === null || version_compare($remoteVer['version'], $localModVer, '>')),
            ];
        }
    }

    if ($action === 'update-module') {
        $remoteVer = upd_remote_version($cfg);

        if (isset($remoteVer['_error'])) {
            $messages[] = ['err', upd_text('MsgReadRemoteFailed', htmlspecialchars($remoteVer['_error']))];
        } elseif (empty($remoteVer['files'])) {
            $messages[] = ['err', upd_text('MsgNoFilesList')];
        } else {
            $result = upd_sync_files($cfg, $module_dir, $remoteVer['files']);
            if (empty($result['fail'])) {
                $messages[] = ['ok', upd_text('MsgModuleUpdated',
                    ['version' => htmlspecialchars($remoteVer['version']), 'files' => (int)$result['ok']])];
            } else {
                $messages[] = ['err', upd_text('MsgFilesFailed',
                    ['ok' => (int)$result['ok'], 'fail' => htmlspecialchars(implode(', ', $result['fail']))])];
            }

            // The shared library is realigned in the same move, so an update can
            // never leave it behind the code that depends on it.
            $sh = upd_sync_shared($cfg);
            if (isset($sh['_error'])) {
                $messages[] = ['err', upd_text('MsgSharedError', htmlspecialchars($sh['_error']))];
            } elseif (!empty($sh['fail'])) {
                $messages[] = ['err', upd_text('MsgSharedFailed', htmlspecialchars(implode(', ', $sh['fail'])))];
            } elseif ($sh['ok']) {
                $messages[] = ['ok', upd_text('MsgSharedSynced',
                    ['version' => htmlspecialchars($sh['version']), 'files' => (int)$sh['ok']])];
            }

            // Re-read the local version: version.json is one of the files that
            // was just replaced, so the value held in memory is now stale.
            $localVer    = upd_local_version($module_dir);
            $localModVer = $localVer['version'] ?? null;

            $out['checkResult'] = [
                'local'  => $localModVer,
                'remote' => $remoteVer['version'],
                'notes'  => $remoteVer['notes'] ?? null,
                'date'   => $remoteVer['date']  ?? null,
                'update' => version_compare($remoteVer['version'], $localModVer ?? '0', '>'),
            ];

            // Some modules have something to say after an update — AUTH has to
            // redeploy its dist/ files, for instance.
            if (!empty($opts['after_update']) && is_callable($opts['after_update'])) {
                $extra = call_user_func($opts['after_update']);
                if (is_string($extra) && $extra !== '') $messages[] = ['ok', $extra];
            }
        }
    }
    return $out;
}

/**
 * The "check / apply" block for the module itself.
 *
 * When everything is up to date the update button is folded away behind "force
 * an update", so the common case is a page with nothing tempting to click.
 *
 * @param array $cfg Module configuration.
 * @param array|null $checkResult Output of upd_module_handle().
 * @param array|null $sharedCheck Output of upd_module_handle().
 * @return string HTML, empty when no repository is configured.
 */
function upd_ui_module_block($cfg, $checkResult, $sharedCheck) {
    if (!upd_parse_repo($cfg['github_url'] ?? '')) return '';

    $allUpToDate = $checkResult !== null && !$checkResult['update']
                   && !($sharedCheck && $sharedCheck['update']);

    $html = '<div class="upd-section"><h2>' . upd_esc(upd_text('CheckHeading')) . '</h2>'
          . '<form method="post" style="display:inline">'
          . '<input type="hidden" name="action" value="check">'
          . '<button type="submit" class="upd-btn upd-btn-check">&#128269; '
          . upd_esc(upd_text('CheckNow')) . '</button></form>';

    if ($checkResult) {
        $date = $checkResult['date']
            ? ' <span style="color:#888;font-size:11px">(' . upd_esc($checkResult['date']) . ')</span>'
            : '';
        $badge = $checkResult['update']
            ? '<span class="upd-badge upd-update">' . upd_esc(upd_text('StatusUpdate')) . '</span>'
            : '<span class="upd-badge upd-ok">&#10003; ' . upd_esc(upd_text('StatusUpToDate')) . '</span>';

        $html .= '<p style="font-size:13px;margin-top:14px">'
               . upd_esc(upd_text('LocalVersion')) . ' : <b>'
               . upd_esc($checkResult['local'] ?? upd_text('Unknown')) . '</b>&nbsp;|&nbsp;'
               . upd_esc(upd_text('RemoteVersion')) . ' : <b>'
               . upd_esc($checkResult['remote']) . '</b>' . $date . '&nbsp;' . $badge . '</p>';

        if ($checkResult['notes']) {
            $html .= '<p class="upd-notes">' . upd_esc($checkResult['notes']) . '</p>';
        }
    }

    $html .= upd_ui_shared_status($sharedCheck) . '</div>';

    /* Everything already up to date? Then the update button folds away behind
       "force an update", so the ordinary case is a page with nothing tempting. */
    $html .= '<div class="upd-section">'
           . ($allUpToDate
                ? '<details class="upd-force"><summary>' . upd_esc(upd_text('ForceUpdate'))
                  . '</summary><div class="upd-force-body">'
                : '<h2>' . upd_esc(upd_text('ApplyHeading')) . '</h2>')
           . '<form method="post" style="display:inline" onsubmit="return confirm('
           . upd_esc(json_encode(upd_text('UpdateModuleConfirm'))) . ')">'
           . '<input type="hidden" name="action" value="update-module">'
           . '<button type="submit" class="upd-btn upd-btn-module">&#8595; '
           . upd_esc(upd_text('UpdateModule')) . '</button></form>'
           /* The hint carries deliberate markup from the language files. */
           . '<p class="upd-hint">' . upd_text('UpdateModuleHint') . '</p>'
           . ($allUpToDate ? '</div></details>' : '')
           . '</div>';

    return $html;
}

/**
 * Render the message list.
 *
 * The text is NOT escaped here: messages are built by the handlers above, which
 * escape the parts that come from outside and keep their own markup deliberate.
 *
 * @param array $messages Entries of [type, text].
 * @return string HTML.
 */
function upd_ui_messages($messages) {
    ob_start();
    foreach ($messages as [$type, $text]) {
        $cls = $type === 'ok' ? 'upd-msg-ok' : 'upd-msg-err';
        echo '<div class="upd-msg ' . $cls . '">' . $text . '</div>';
    }
    return ob_get_clean();
}

/**
 * The complete update page for a plain module.
 *
 * @param string $module_dir Module root.
 * @param array $opts h1 (page heading), title (browser title, defaults to h1),
 *              back => ['url', 'label'], after_update => callable():string.
 */
function upd_render_common_page($module_dir, $opts = []) {
    global $CFG;

    $cfg   = upd_load_config($module_dir);
    $name  = basename($module_dir);
    $h1    = $opts['h1'] ?? upd_text('DefaultPageTitle', $name);
    $title = $opts['title'] ?? $h1;

    $localVer    = upd_local_version($module_dir);
    $localModVer = $localVer['version'] ?? null;

    $messages = [];
    upd_install_handle($cfg, $messages);
    $mod = upd_module_handle($cfg, $module_dir, $messages, $opts);

    // Re-read after the handler: an update replaces version.json.
    $localVer    = upd_local_version($module_dir);
    $localModVer = $localVer['version'] ?? null;

    $othersState = upd_others_state($cfg);

    $PAGE_TITLE = $title;
    include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

    echo upd_ui_styles();
    echo '<h1>' . htmlspecialchars($h1) . '</h1>';

    if (!empty($opts['back']['url'])) {
        echo '<p><a href="' . htmlspecialchars($opts['back']['url']) . '">← '
           . htmlspecialchars($opts['back']['label'] ?? upd_text('BackHome')) . '</a></p>';
    }

    echo upd_ui_messages($messages);
    echo upd_ui_source($cfg, $localVer, $localModVer);
    echo upd_ui_module_block($cfg, $mod['checkResult'], $mod['sharedCheck']);
    echo upd_ui_others_block($cfg, $othersState, $name);
    echo upd_ui_danger_zone($module_dir);

    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
}
