<?php
/**
 * Preparation page of a draw: titles, previous-season competition, categories
 * and teams with their history and notes, and the look of the public screen.
 *
 * The page itself is an empty frame. assets/edit.js loads the draw in one call
 * to api/manage.php and saves each field as it changes, so a preparation can be
 * interrupted at any moment without losing what was typed.
 */

require_once __DIR__ . '/lib/boot.php';

tir_require_admin();

$show = tir_show((int)($_GET['id'] ?? 0));
if (!$show) {
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = tir_text('EditTitle') . ' — ' . $show['title'];
$JS_SCRIPT  = [
    '<link rel="stylesheet" href="' . tir_esc(tir_asset('assets/tirage.css')) . '">',
    tir_js_strings(),
    '<script>window.TIR_PAGE = ' . json_encode([
        'id'   => $show['id'],
        'api'  => tir_url() . 'api/manage.php',
        'csrf' => tir_token(),
        'home' => tir_url() . 'index.php',
    ]) . ';</script>',
    '<script src="' . tir_esc(tir_asset('assets/common.js')) . '"></script>',
    '<script src="' . tir_esc(tir_asset('assets/edit.js')) . '" defer></script>',
];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

echo '<div id="tir" class="tir-page">'
    . '<div class="tir-actions" style="justify-content:space-between">'
    . '<h1 class="tir-h1">' . tir_t('EditTitle') . ' — <span id="tir-title-h">' . tir_esc($show['title']) . '</span></h1>'
    . '<span class="tir-actions">'
    . '<a class="tir-btn" href="' . tir_esc(tir_url() . 'index.php') . '">' . tir_t('BackToShows') . '</a>'
    . '<a class="tir-btn tir-btn-primary" href="' . tir_esc(tir_url() . 'control.php?id=' . $show['id']) . '">' . tir_t('OpenControl') . '</a>'
    . '</span></div>'
    . '<p class="tir-lead">' . tir_t('EditLead') . '</p>'
    . '<div id="tir-edit"><p>' . tir_t('Loading') . ' <span class="tir-dots"><i></i><i></i><i></i></span></p></div>'
    . '</div>';

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
