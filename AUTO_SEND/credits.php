<?php
/**
 * Who wrote this module, for the ianseo credits page.
 *
 * The format is the one the ianseo team proposed for external modules: four
 * plain variables, no function call, no dependency — so the file can be read by
 * anything, including an include() from a page that has not booted the module.
 *
 * The version and the date are read from version.json rather than repeated here:
 * that file is what the update mechanism compares, and two copies of a version
 * number stay equal exactly as long as nobody forgets one.
 */

$Version = '0.0.0';
$Date    = '';

$_credits_manifest = __DIR__ . '/version.json';
if (is_file($_credits_manifest)) {
    $_credits_data = json_decode(file_get_contents($_credits_manifest), true);
    if (is_array($_credits_data)) {
        if (!empty($_credits_data['version'])) $Version = (string)$_credits_data['version'];
        if (!empty($_credits_data['date']))    $Date    = (string)$_credits_data['date'];
    }
    unset($_credits_data);
}
unset($_credits_manifest);

$Authors = ['Stéphane Kraus'];
$Email   = 's.kraus@ffta.fr';   // direct contact for this module
