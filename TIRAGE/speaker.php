<?php
/**
 * Commentators' screen of a draw: what to say about each team as it is drawn.
 *
 * Opened by its own secret link, distinct from the public screen's because it
 * also shows the notes written for the commentators. It follows the draw — the
 * team just drawn comes up on its own — and any team can be looked up in
 * advance: its history, its previous season from the ianseo competition, and
 * the archers who shot for it with their national rankings when available.
 *
 * Laid out for a laptop or a tablet at the commentary desk; the markup is built
 * by assets/speaker.js.
 */

require_once __DIR__ . '/lib/boot.php';
session_write_close();

$token = (string)($_GET['t'] ?? '');
[$show, $role] = tir_show_by_token($token);
$valid = $show && $role === 'speaker';

$config = [
    'live'  => tir_url() . 'api/live.php',
    'token' => $valid ? $token : '',
];

echo '<!DOCTYPE html>'
    . '<html lang="' . tir_esc(tir_lang_code()) . '"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex, nofollow">'
    . '<title>' . tir_t('SpeakerTitle') . ($valid ? ' — ' . tir_esc($show['title']) : '') . '</title>'
    . '<link rel="stylesheet" href="' . tir_esc(tir_asset('assets/screen.css')) . '">'
    . tir_js_strings()
    . '<script>window.TIR_SCREEN = ' . json_encode($config) . ';</script>'
    . '<script src="' . tir_esc(tir_asset('assets/common.js')) . '"></script>'
    . '<script src="' . tir_esc(tir_asset('assets/speaker.js')) . '" defer></script>'
    . '</head><body class="tir-speaker">'
    . '<div id="tir-speaker">'
    . ($valid ? '<p class="tir-sp-muted" style="padding:16px">' . tir_t('Loading') . '</p>'
              : '<p class="tir-sp-muted" style="padding:16px">' . tir_t('ErrLink') . '</p>')
    . '</div></body></html>';
