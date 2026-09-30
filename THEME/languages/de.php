<?php
/**
 * German strings of the theme module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Farbschema';
$lang['Mode_auto'] = 'Automatisch';
$lang['Mode_light'] = 'Hell';
$lang['Mode_dark'] = 'Dunkel';
$lang['MenuColours'] = 'Farben…';
$lang['MenuUpdate'] = 'Modul-Aktualisierung';
$lang['UpdateTitle'] = 'Farbschema — Aktualisierung des Moduls';
$lang['BackToPage'] = 'Zurück zum Farbschema';

// Page
$lang['Lead'] = 'Wie ianseo in diesem Browser aussieht: hell oder dunkel, und in welcher Farbe.';
$lang['ModeTitle'] = 'Modus';
$lang['ModeHint'] = 'Automatisch folgt der hellen oder dunklen Einstellung dieses Computers und wechselt mit ihr.';
$lang['PaletteTitle'] = 'Farbe';
$lang['PaletteHint'] = 'Geben Sie jeder Ihrer ianseo-Installationen eine eigene Farbe, um sie auf einen Blick zu unterscheiden. Jede Farbe gibt es in einer hellen und einer dunklen Version.';
$lang['StoredNote'] = 'Diese Einstellungen speichert dieser Browser, nur für diese ianseo-Installation: andere Personen und andere Installationen sind nicht betroffen. Ausdrucke und PDF-Dateien ändern sich nicht.';
$lang['DebugNote'] = 'Der Debug-Modus ist aktiv: seine orangen Farben ersetzen die gewählte Farbe, damit er nicht übersehen wird.';

// Palettes
$lang['Palette_ianseo'] = 'ianseo-Blau';
$lang['Palette_green'] = 'Tannengrün';
$lang['Palette_teal'] = 'Türkis';
$lang['Palette_olive'] = 'Oliv';
$lang['Palette_violet'] = 'Violett';
$lang['Palette_raspberry'] = 'Himbeere';
$lang['Palette_burgundy'] = 'Bordeaux';
$lang['Palette_slate'] = 'Schiefer';

// Pages without a menu (administrator)
$lang['HookTitle'] = 'Seiten ohne Menü';
$lang['HookText'] = 'Popup-Fenster wie die Teilnehmerkarte und die Speaker-Seiten zeigen kein Menü, daher erreicht das Farbschema sie nicht von selbst. Das Aktivieren fügt einige Zeilen in Common/DebugOverrides.php ein, eine Datei, die ianseo-Aktualisierungen nicht verändern. Sie bringen das Farbschema auf jede Seite in den Standardfarben von ianseo, ohne helles Aufblitzen beim Laden; öffentliche Bildschirme (TV-Ausgabe, Wertungs-Apps) sind nicht betroffen. Nach der Deinstallation des Moduls bewirken die Zeilen nichts mehr.';
$lang['HookOn'] = 'Aktiviert.';
$lang['HookOff'] = 'Nicht aktiviert: nur Seiten mit Menü folgen dem Farbschema.';
$lang['HookEnable'] = 'Aktivieren';
$lang['HookDisable'] = 'Deaktivieren';
$lang['HookDone'] = 'Erledigt.';
$lang['HookFailed'] = 'Die Datei konnte nicht geschrieben werden.';
$lang['HookNotWritable'] = 'Der Webserver darf {$a} nicht schreiben. Um es von Hand zu aktivieren, setzen Sie diese Zeilen ganz an den Anfang der Datei und legen sie an, falls sie fehlt:';
$lang['HookNotWritableOff'] = 'Der Webserver darf {$a} nicht schreiben. Um es von Hand zu deaktivieren, entfernen Sie die Zeilen zwischen THEME BEGIN und THEME END aus der Datei.';
