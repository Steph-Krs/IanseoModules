<?php
/**
 * Settings and status page of the scheduled upload module, for the open competition.
 *
 * The form is written here in PHP; the status panel is filled by
 * assets/auto-send.js from api/state.php, and refreshes itself.
 */

require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/settings.php';

aus_require_access();

$tour = (int)$_SESSION['TourId'];
$canSessions = aus_can_manage_sessions();
$sessionsCatalog = aus_sessions_catalog();
$itemsCatalog = aus_items_catalog($tour);

// A new plan opens and closes every scoring session and uploads every
// qualification ranking: what a challenge needs, and a visible starting point.
$defaultItems = [];
foreach ($itemsCatalog['items'] as $it) {
    if ($it['list'] === 'QualificationInd' || $it['list'] === 'QualificationTeam') {
        $defaultItems[$it['list']][] = $it['value'];
    }
}
aus_plan_ensure($tour, array_column($sessionsCatalog, 'key'), $defaultItems);
$plan = aus_plan_load($tour);

$errors = [];
$values = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!aus_token_ok()) {
        $errors[] = aus_text('ErrToken');
    } else {
        [$errors, $values, $flash] = aus_settings_save($plan, $_POST, $sessionsCatalog, $canSessions);
        if (!$errors) {
            $_SESSION['AUS_FLASH'] = $flash;
            CD_redirect(aus_url() . 'index.php');
        }
    }
}
$flash = (string)($_SESSION['AUS_FLASH'] ?? '');
unset($_SESSION['AUS_FLASH']);

// What the form shows: the values just posted when they were refused, the plan otherwise.
if ($values === null) {
    $tz = aus_valid_zone($plan->AsTimeZone) ? $plan->AsTimeZone : 'Europe/Paris';
    $values = [
        'enabled'    => (bool)$plan->AsEnabled,
        'simulation' => (bool)$plan->AsSimulation,
        'timezone'   => $tz,
        'start'      => aus_utc_to_local($plan->AsStart, $tz),
        'end'        => aus_utc_to_local($plan->AsEnd, $tz),
        'interval'   => (int)$plan->AsInterval,
        'sessions'   => (bool)$plan->AsSessions,
        'keys'       => aus_json_array($plan->AsSessionKeys),
        'items'      => aus_items_clean(aus_json_array($plan->AsItems)),
        'ping'       => $plan->AsPingUrl,
    ];
}

$credentials = getModuleParameter('SendToIanseo', 'Credentials', (object)['OnlineId' => 0, 'OnlineAuth' => ''], $tour);
$hasCredentials = is_object($credentials) && !empty($credentials->OnlineId) && (string)$credentials->OnlineAuth !== '';
$locked = aus_sessions_locked($tour);

/**
 * A checkbox with its label.
 *
 * @param string $name
 * @param string $value
 * @param bool $checked
 * @param string $label Already escaped HTML.
 * @param bool $disabled
 * @return string
 */
function aus_checkbox($name, $value, $checked, $label, $disabled = false) {
    return '<label class="aus-check"><input type="checkbox" name="' . aus_esc($name) . '" value="' . aus_esc($value) . '"'
        . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> ' . $label . '</label>';
}

/**
 * The line to add to the server's scheduler, for this installation.
 *
 * @return string
 */
function aus_cron_line() {
    $cron = realpath(__DIR__ . '/cron.php');
    if (PHP_OS_FAMILY === 'Windows') {
        $php = realpath(HTDOCS . '/../php/php.exe') ?: 'php.exe';
        return 'schtasks /Create /TN "ianseo AUTO_SEND" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"' . $php . '\" \"' . $cron . '\""';
    }
    $php = @is_file('/usr/bin/php') ? '/usr/bin/php' : 'php';
    return "echo '* * * * * www-data nice -n 10 $php $cron 2>&1 | logger -t ianseo-autosend' | sudo tee /etc/cron.d/ianseo-autosend";
}

$PAGE_TITLE = aus_text('PageTitle');
$JS_SCRIPT = [
    '<link rel="stylesheet" href="' . aus_asset('assets/auto-send.css') . '">',
    aus_js_strings(),
    '<script>window.AUS = ' . json_encode([
        'state' => aus_url() . 'api/state.php',
        'csrf'  => aus_token(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>',
    '<script src="' . aus_asset('assets/auto-send.js') . '"></script>',
];

include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$h = '<div id="aus">';
$h .= '<h1 class="aus-h1">' . aus_t('PageTitle') . '</h1>';
$h .= '<p class="aus-lead">' . aus_t('PageLead') . '</p>';

if ($flash !== '') $h .= '<div class="aus-msg aus-msg-ok">' . aus_t($flash) . '</div>';
if ($errors) {
    $h .= '<div class="aus-msg aus-msg-err"><ul>';
    foreach ($errors as $e) $h .= '<li>' . aus_esc($e) . '</li>';
    $h .= '</ul></div>';
}
if (!$hasCredentials) {
    $h .= '<div class="aus-msg aus-msg-err">' . aus_t('WarnNoCredentials') . ' <a href="' . aus_esc($CFG->ROOT_DIR
        . 'Tournament/SetCredentials.php?return=' . rawurlencode('Modules/Custom/' . basename(__DIR__) . '/index.php'))
        . '">' . aus_esc(get_text('SetCredentials', 'Tournament')) . '</a></div>';
}
if (IsBlocked(BIT_BLOCK_PUBBLICATION)) {
    $h .= '<div class="aus-msg aus-msg-err">' . aus_t('OutErrPublicationLocked') . '</div>';
}
if ((int)$_SESSION['TourType'] === 48) {
    $h .= '<div class="aus-msg aus-msg-err">' . aus_t('ErrRunArchery') . '</div>';
}
if ($plan->AsSimulation) {
    $h .= '<div class="aus-msg aus-msg-warn">' . aus_t('WarnSimulation') . '</div>';
}

// Status, filled and refreshed by the script.
$h .= '<div class="aus-card"><h2>' . aus_t('StatusTitle') . '</h2>'
    . '<div id="aus-status"><p class="aus-hint">' . aus_t('Loading') . '</p></div></div>';

$h .= '<form method="post" action="' . aus_esc(aus_url() . 'index.php') . '" class="aus-form">';
$h .= '<input type="hidden" name="csrf" value="' . aus_esc(aus_token()) . '">';

// Period.
$h .= '<div class="aus-card"><h2>' . aus_t('PeriodTitle') . '</h2>';
$h .= aus_checkbox('enabled', '1', $values['enabled'], '<b>' . aus_t('Enabled') . '</b>');
$h .= '<p class="aus-hint">' . aus_t('EnabledHint') . '</p>';
$h .= '<div class="aus-row">';
$h .= '<label>' . aus_t('Start') . '<input type="datetime-local" name="start" value="' . aus_esc($values['start']) . '"></label>';
$h .= '<label>' . aus_t('End') . '<input type="datetime-local" name="end" value="' . aus_esc($values['end']) . '"></label>';
$h .= '<label>' . aus_t('TimeZone') . '<select name="timezone">';
foreach (DateTimeZone::listIdentifiers() as $z) {
    $h .= '<option value="' . aus_esc($z) . '"' . ($z === $values['timezone'] ? ' selected' : '') . '>' . aus_esc($z) . '</option>';
}
$h .= '</select></label>';
$h .= '<label>' . aus_t('Interval') . '<input type="number" name="interval" min="' . AUS_INTERVAL_MIN . '" max="'
    . AUS_INTERVAL_MAX . '" value="' . (int)$values['interval'] . '" class="aus-num"> ' . aus_t('Minutes') . '</label>';
$h .= '</div>';
if ($plan->AsStart !== null && $plan->AsEnd !== null) {
    $h .= '<p class="aus-hint">' . aus_t('PeriodSaved', [
        'start' => aus_display_time($plan->AsStart, $values['timezone']),
        'end'   => aus_display_time($plan->AsEnd, $values['timezone']),
    ]) . '</p>';
}
$h .= '<p class="aus-hint">' . aus_t('TimeZoneHint') . '</p>';
$h .= '<p class="aus-hint">' . aus_t('IntervalHint', AUS_INTERVAL_MIN) . '</p>';
$h .= '</div>';

// Scoring sessions.
$h .= '<div class="aus-card"><h2>' . aus_t('SessionsTitle') . '</h2>';
if (!$canSessions) $h .= '<p class="aus-hint">' . aus_t('SessionsNoRight') . '</p>';
$h .= aus_checkbox('sessions', '1', $values['sessions'], '<b>' . aus_t('SessionsManage') . '</b>', !$canSessions);
$h .= '<p class="aus-hint">' . aus_t('SessionsHint') . '</p>';
if (!$sessionsCatalog) {
    $h .= '<p class="aus-hint">' . aus_t('SessionsNone') . '</p>';
} else {
    $h .= '<div class="aus-list">';
    foreach ($sessionsCatalog as $s) {
        $state = in_array($s['key'], $locked, true)
            ? '<span class="aus-badge aus-badge-ko">' . aus_t('JsClosed') . '</span>'
            : '<span class="aus-badge aus-badge-ok">' . aus_t('JsOpen') . '</span>';
        $h .= aus_checkbox('keys[]', $s['key'], in_array($s['key'], $values['keys'], true),
            aus_esc($s['label']) . ' ' . $state, !$canSessions);
    }
    $h .= '</div>';
}
$h .= '</div>';

// What is uploaded.
$h .= '<div class="aus-card"><h2>' . aus_t('ItemsTitle') . '</h2>';
$h .= '<p class="aus-hint">' . aus_t('ItemsHint') . '</p>';
$byList = [];
foreach ($itemsCatalog['items'] as $it) $byList[$it['list']][$it['value']] = $it;
$h .= '<div class="aus-families">';
foreach (AUS_ITEM_LISTS as $list) {
    $chosen = $values['items'][$list] ?? [];
    $known = $byList[$list] ?? [];
    $gone = array_diff($chosen, array_keys($known));
    if (!$known && !$gone) continue;

    $h .= '<fieldset class="aus-family"><legend>' . aus_esc(aus_item_family_label($list))
        . ' <button type="button" class="aus-link" data-aus-all="' . aus_esc($list) . '">' . aus_t('JsAll') . '</button>'
        . ' <button type="button" class="aus-link" data-aus-none="' . aus_esc($list) . '">' . aus_t('JsNone') . '</button>'
        . '</legend>';
    foreach ($known as $value => $it) {
        $label = aus_esc($it['code'] . ' - ' . $it['name']);
        if (!$it['available']) $label .= ' <span class="aus-hint">' . aus_t('ItemWaiting') . '</span>';
        $h .= aus_checkbox($list . '[]', $value, in_array($value, $chosen, true), $label);
    }
    foreach ($gone as $value) {
        $h .= aus_checkbox($list . '[]', $value, true, aus_esc($value) . ' <span class="aus-hint">' . aus_t('ItemMissing') . '</span>');
    }
    $h .= '</fieldset>';
}
$h .= '<fieldset class="aus-family"><legend>' . aus_esc(get_text('Rankings')) . '</legend>';
foreach (AUS_ITEM_FLAGS as $flag) {
    $label = aus_esc(aus_item_family_label($flag));
    if (($flag === 'MEDSTD' || $flag === 'MEDLST') && !$itemsCatalog['medals']) {
        $label .= ' <span class="aus-hint">' . aus_t('ItemWaiting') . '</span>';
    }
    $h .= aus_checkbox($flag, '1', !empty($values['items'][$flag]), $label);
}
$h .= '</fieldset></div></div>';

// Monitoring.
$h .= '<div class="aus-card"><h2>' . aus_t('PingTitle') . '</h2>';
$h .= '<label class="aus-wide">' . aus_t('PingUrl') . '<input type="text" name="ping" value="' . aus_esc($values['ping'])
    . '" placeholder="https://hc-ping.com/…"></label>';
$h .= '<p class="aus-hint">' . aus_t('PingHint') . '</p>';
$h .= '</div>';

// Simulation.
$h .= '<div class="aus-card"><h2>' . aus_t('SimulationTitle') . '</h2>';
$h .= aus_checkbox('simulation', '1', $values['simulation'], '<b>' . aus_t('Simulation') . '</b>');
$h .= '<p class="aus-hint">' . aus_t('SimulationHint') . '</p>';
$h .= '</div>';

$h .= '<p><button type="submit" class="aus-btn aus-btn-primary">' . aus_t('Save') . '</button></p>';
$h .= '</form>';

// The scheduled task.
$h .= '<details class="aus-card" id="aus-task"><summary><h2>' . aus_t('TaskTitle') . '</h2></summary>';
$h .= '<p>' . aus_t('TaskExplain') . '</p>';
$h .= '<p>' . aus_t(PHP_OS_FAMILY === 'Windows' ? 'TaskInstallWindows' : 'TaskInstallLinux') . '</p>';
$h .= '<pre class="aus-code">' . aus_esc(aus_cron_line()) . '</pre>';
$h .= '<p class="aus-hint">' . aus_t('TaskReadme') . '</p>';
$h .= '</details>';

$h .= '</div>';
echo $h;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
