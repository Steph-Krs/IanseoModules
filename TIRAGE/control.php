<?php
/**
 * Control page of a draw, used by the person entering the result of each draw.
 *
 * The teams are drawn by hand in the room; this page records the order. One
 * click gives the team the next place of its category and puts that category
 * on the public screen, which reveals the team. The page also chooses what the
 * public screen shows between two categories, and which statistics it lists.
 *
 * assets/control.js polls the draw's state, so two operators, or an operator
 * and the preparation page, never work from a stale picture.
 */

require_once __DIR__ . '/lib/boot.php';

tir_require_admin();

$show = tir_show((int)($_GET['id'] ?? 0));
if (!$show) {
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = tir_text('ControlTitle') . ' — ' . $show['title'];
$JS_SCRIPT  = [
    '<link rel="stylesheet" href="' . tir_esc(tir_asset('assets/tirage.css')) . '">',
    tir_js_strings(),
    '<script>window.TIR_PAGE = ' . json_encode([
        'id'      => $show['id'],
        'api'     => tir_url() . 'api/manage.php',
        'csrf'    => tir_token(),
        'display' => tir_url() . 'display.php?t=' . $show['token'],
        'speaker' => tir_url() . 'speaker.php?t=' . $show['speakerToken'],
        'edit'    => tir_url() . 'edit.php?id=' . $show['id'],
    ]) . ';</script>',
    '<script src="' . tir_esc(tir_asset('assets/common.js')) . '"></script>',
    '<script src="' . tir_esc(tir_asset('assets/control.js')) . '" defer></script>',
];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

echo '<div id="tir" class="tir-page">'
    . '<div class="tir-actions" style="justify-content:space-between">'
    . '<h1 class="tir-h1">' . tir_t('ControlTitle') . ' — <span id="tir-title-h">' . tir_esc($show['title']) . '</span></h1>'
    . '<span class="tir-actions">'
    . '<a class="tir-btn" href="' . tir_esc(tir_url() . 'index.php') . '">' . tir_t('BackToShows') . '</a>'
    . '<a class="tir-btn" href="' . tir_esc(tir_url() . 'edit.php?id=' . $show['id']) . '">' . tir_t('OpenEdit') . '</a>'
    . '<a class="tir-btn" target="_blank" href="' . tir_esc(tir_url() . 'display.php?t=' . $show['token']) . '">' . tir_t('OpenDisplay') . '</a>'
    . '<a class="tir-btn" target="_blank" href="' . tir_esc(tir_url() . 'speaker.php?t=' . $show['speakerToken']) . '">' . tir_t('OpenSpeaker') . '</a>'
    . '</span></div>'
    . '<div id="tir-control"><p>' . tir_t('Loading') . ' <span class="tir-dots"><i></i><i></i><i></i></span></p></div>'
    . '</div>';

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
