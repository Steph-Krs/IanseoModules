<?php
/**
 * One file on its own: the scorecards of one club, or of one archer, as a PDF.
 *
 * Opened from the list of clubs with the options of the form in the address
 * (Mode, Key and the same fields as api/build.php), to print or send one club's
 * scorecards again without building the whole archive. Key is the name the file
 * has in the archive, without its extension.
 */

require_once __DIR__ . '/lib/boot.php';

scs_require_access();

$sessions = scs_sessions();
$comp     = scs_competition();
$opts     = scs_options($_GET, $sessions, $comp['distances']);
$key      = (string)($_GET['Key'] ?? '');

$files = ($comp['field'] || !$opts['sessions'])
    ? []
    : scs_split(scs_read_cards($opts['sessions'], $sessions), $opts['mode']);

if (!isset($files[$key])) {
    $PAGE_TITLE = scs_text('ModuleName');
    include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
    echo '<div class="scs-msg">' . scs_t('NotFound') . ' <a href="' . scs_esc(scs_url() . 'index.php') . '">'
        . scs_t('BackToPage') . '</a></div>';
    include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
    exit;
}

@set_time_limit(120);
$pdf = scs_new_pdf($opts);
scs_draw($pdf, $files[$key], $opts, $sessions);
$pdf->Output($key . '.pdf', 'I');
