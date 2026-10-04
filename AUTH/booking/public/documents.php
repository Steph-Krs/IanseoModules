<?php
/**
 * public/documents.php — documents of a competition, for the connected archer.
 *
 * Gathers the documents the organiser made available (mandate, ianseo.net link, official
 * programme / participants / results). For connected archers only (bk_require_archer); each
 * document also has its own guard (e.g. bk_mandate_visible for the mandate).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? 0);
$cfg    = $tourId ? bk_comp_config($tourId) : null;
$tour   = $tourId ? safe_fetch(safe_r_sql("SELECT ToName, ToWhere, ToWhenFrom, ToWhenTo
    FROM Tournament WHERE ToId = " . intval($tourId))) : null;
$docs   = $cfg ? bk_docs_list($cfg, $tourId) : array();
// Bibs THIS archer may print (their own and those of the licensees they registered), when the
// option is on and the competition has a bib template.
$bibs   = ($cfg && bk_dossard_available($cfg, $tourId)) ? bk_dossard_entries($tourId, $archer) : array();

bk_head(bk_t('Documents'));
echo '<h1>' . bk_e(bk_t('DocsTitle')) . '</h1>';
if ($tour) {
    echo '<div class="bk-block" style="margin-bottom:16px"><h2>' . bk_e($tour->ToName) . '</h2><p class="bk-meta">'
        . '<span>' . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . '</span>'
        . ($tour->ToWhere ? '<span>' . bk_e($tour->ToWhere) . '</span>' : '') . '</p></div>';
}

if (!$docs && !$bibs) {
    echo '<p class="bk-empty">' . bk_e(bk_t('NoDocs')) . ' <a href="' . bk_e(bk_public_url('calendar.php')) . '">'
        . bk_e(bk_t('BackCalendarPlain')) . '</a>.</p>';
    bk_foot();
    exit;
}

if ($docs) {
    echo '<div class="bk-doclist">';
    foreach ($docs as $d) {
        echo '<a class="bk-doc" href="' . bk_e($d['url']) . '" target="_blank" rel="noopener">'
            . '<span class="bk-doc-ic">' . $d['icon'] . '</span><span class="bk-doc-lab">' . bk_e($d['label'])
            . (!empty($d['external']) ? ' <span class="bk-doc-ext">' . bk_e(bk_t('ExternalSite')) . '</span>' : '')
            . '</span></a>';
    }
    echo '</div>';
}

if ($bibs) {
    echo '<div class="bk-block" style="margin-top:16px"><h2>' . bk_e(bk_t('BibsTitle')) . '</h2>'
        . '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t(count($bibs) > 1 ? 'BibsHintMany' : 'BibsHintOne')) . '</p>'
        . '<div class="bk-doclist">';
    foreach ($bibs as $b) {
        $name = trim($b->EnFirstName . ' ' . $b->EnName);   // EnFirstName = FAMILY name, EnName = given name (FFTA import)
        $mate = ((string) $b->BrLicence !== (string) $archer->BaLicence);
        $cat = trim(($b->DivDescription ?: '') . ' ' . ($b->ClDescription ?: ''));
        echo '<a class="bk-doc" href="' . bk_e(bk_public_url('bib.php?enid=' . intval($b->BrEnId))) . '" target="_blank" rel="noopener">'
            . '<span class="bk-doc-ic">🎫</span><span class="bk-doc-lab">' . bk_e($name ?: $b->BrLicence)
            . ' <span class="bk-doc-ext">' . bk_e(($cat !== '' ? $cat . ' — ' : '') . bk_t('DepX', intval($b->QuSession))
                . ($mate ? ' · ' . bk_t('RegisteredByYou') : '')) . '</span></span></a>';
    }
    echo '</div>';
    if (count($bibs) > 1) {
        echo '<p style="margin-top:12px"><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('bib.php?all=1&t=' . $tourId))
            . '" target="_blank" rel="noopener">' . bk_e(bk_t('PrintAllBibs', count($bibs))) . '</a></p>';
    }
    echo '</div>';
}
bk_foot();
