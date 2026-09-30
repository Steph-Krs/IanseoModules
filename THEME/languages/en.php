<?php
/**
 * English strings of the theme module: the reference list of keys. Other
 * languages fall back to this file for any key they lack.
 */

// Module and menu
$lang['ModuleName'] = 'Theme';
$lang['Mode_auto'] = 'Automatic';
$lang['Mode_light'] = 'Light';
$lang['Mode_dark'] = 'Dark';
$lang['MenuColours'] = 'Colours…';
$lang['MenuUpdate'] = 'Module update';
$lang['UpdateTitle'] = 'Theme — module update';
$lang['BackToPage'] = 'Back to the theme';

// Page
$lang['Lead'] = 'How ianseo looks in this browser: light or dark, and in which colour.';
$lang['ModeTitle'] = 'Mode';
$lang['ModeHint'] = 'Automatic follows the light or dark setting of this computer, and changes with it.';
$lang['PaletteTitle'] = 'Colour';
$lang['PaletteHint'] = 'Give each of your ianseo installations its own colour, to tell them apart at a glance. Every colour has a light and a dark version.';
$lang['StoredNote'] = 'These settings are kept by this browser, for this ianseo installation only: other people and other installations are not affected. Printouts and PDF files do not change.';
$lang['DebugNote'] = 'Debug mode is on: its own orange colours replace the chosen colour, so that it cannot be missed.';

// Palettes
$lang['Palette_ianseo'] = 'ianseo blue';
$lang['Palette_green'] = 'Fir green';
$lang['Palette_teal'] = 'Teal';
$lang['Palette_olive'] = 'Olive';
$lang['Palette_violet'] = 'Violet';
$lang['Palette_raspberry'] = 'Raspberry';
$lang['Palette_burgundy'] = 'Burgundy';
$lang['Palette_slate'] = 'Slate';

// Pages without a menu (administrator)
$lang['HookTitle'] = 'Pages without a menu';
$lang['HookText'] = 'Popup windows, such as the participant editor, and the Speaker pages print no menu, so the theme cannot reach them on its own. Enabling it adds a few lines to Common/DebugOverrides.php, a file that ianseo updates leave untouched. They bring the theme to every page drawn in ianseo\'s standard colours, with no flash of light colour while a page loads; public screens (TV output, scoring apps) are not affected. The lines do nothing once the module is uninstalled.';
$lang['HookOn'] = 'Enabled.';
$lang['HookOff'] = 'Not enabled: only pages with a menu follow the theme.';
$lang['HookEnable'] = 'Enable';
$lang['HookDisable'] = 'Disable';
$lang['HookDone'] = 'Done.';
$lang['HookFailed'] = 'The file could not be written.';
$lang['HookNotWritable'] = 'The web server may not write {$a}. To enable it by hand, put these lines at the very top of that file, creating it if it does not exist:';
$lang['HookNotWritableOff'] = 'The web server may not write {$a}. To disable it by hand, remove the lines between THEME BEGIN and THEME END from that file.';
