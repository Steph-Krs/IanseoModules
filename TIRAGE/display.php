<?php
/**
 * Public screen of a draw, meant for a projector, a TV or a video mixer input.
 *
 * Opened by its secret link, with no ianseo session and none of ianseo's page
 * furniture: the whole window is the screen. The markup is an empty stage;
 * assets/display.js polls the draw and plays each scene — the waiting loop,
 * the category being drawn with every new team revealed, the final summary.
 *
 * An unknown link answers a plain page saying so, rather than an error status:
 * a screen left open after its link was renewed should explain itself.
 */

require_once __DIR__ . '/lib/boot.php';
session_write_close();

$token = (string)($_GET['t'] ?? '');
[$show] = tir_show_by_token($token);

$config = [
    'live'    => tir_url() . 'api/live.php',
    'image'   => tir_url() . 'image.php',
    'token'   => $show ? $token : '',
    'preview' => !empty($_GET['preview']),
];

$html = '<!DOCTYPE html>'
    . '<html lang="' . tir_esc(tir_lang_code()) . '" class="tir-html-display"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex, nofollow">'
    . '<title>' . tir_esc($show ? $show['title'] : tir_text('ModuleName')) . '</title>'
    . '<link rel="stylesheet" href="' . tir_esc(tir_asset('assets/screen.css')) . '">'
    . tir_js_strings()
    . '<script>window.TIR_SCREEN = ' . json_encode($config) . ';</script>'
    . '<script src="' . tir_esc(tir_asset('assets/common.js')) . '"></script>'
    . '<script src="' . tir_esc(tir_asset('assets/display.js')) . '" defer></script>'
    . '</head><body class="tir-display' . ($config['preview'] ? ' tir-is-preview' : '') . '">'
    . '<div id="tir-screen">'
    . '<div class="tir-bg" id="tir-bg"></div>'
    . '<div class="tir-ambient" aria-hidden="true"><i></i><i></i><i></i></div>'
    . '<main id="tir-stage">'
    . ($show ? '' : '<div class="tir-idle"><div class="tir-sub">' . tir_t('ErrLink') . '</div></div>')
    . '</main>'
    . '<div id="tir-reveal" aria-live="polite"></div>'
    . '</div></body></html>';

echo $html;
