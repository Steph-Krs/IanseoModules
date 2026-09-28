<?php
/**
 * German strings of the scorecard splitter. Keys missing here fall back to the
 * English file, which holds the reference list. The vocabulary follows the
 * core's German files ("Scorekarte", "Scheibe", "Durchgang", "Schütze").
 */

// Module and menu
$lang['ModuleName'] = 'Scorekarten nach Verein oder Schütze';
$lang['MenuPrint'] = 'Scorekarten drucken';
$lang['MenuUpdate'] = 'Modul aktualisieren';
$lang['UpdateTitle'] = 'Scorekarten nach Verein oder Schütze — Modulaktualisierung';
$lang['BackToPage'] = 'Zurück zu den Scorekarten';

// Page
$lang['Lead'] = 'Die Scorekarten des geöffneten Turniers, aufgeteilt in Dateien: eine pro Verein mit allen seinen Schützen oder eine pro Schütze. Gedruckt werden nur Seiten mit mindestens einem Schützen, gleich wie viele Positionen eine Scheibe hat; die freien Positionen einer gedruckten Seite bleiben leere Raster. Der Scorekartendruck von ianseo selbst bleibt unverändert.';
$lang['OptionsTitle'] = 'Scorekarten';
$lang['SessionsTitle'] = 'Durchgänge';
$lang['SessionCards'] = '({$a} Scorekarten)';
$lang['LayoutTitle'] = 'Layout';
$lang['HideHeaderText'] = 'Text der Seitenkopfzeile ausblenden (Titel, Veranstalter, Ort, Daten): nur ihre Bilder, wenn das Kopfbild schon alles zeigt';
$lang['HideTarget'] = 'Scheibennummer ausblenden';
$lang['HideTargetHint'] = 'Für ein Turnier, in dem die Positionen nur dazu dienen, jedem Schützen seine eigenen Scorekarten zu geben, etwa bei einem in den Vereinen geschossenen Wettbewerb: Die Nummer sagt dem Schützen nichts.';

// Archive
$lang['ArchiveTitle'] = 'Archiv';
$lang['ModeClub'] = 'Eine Datei pro Verein, benannt nach seinem Code';
$lang['ModeArcher'] = 'Eine Datei pro Schütze, benannt nach seiner Lizenznummer';
$lang['ZipButton'] = 'ZIP herunterladen';
$lang['Starting'] = 'Vorbereitung…';
$lang['Progress'] = '{$a[done]} / {$a[total]} Dateien';
$lang['Packing'] = 'Archiv wird erstellt…';
$lang['Ready'] = 'Das Archiv ist fertig: Der Download beginnt.';

// List of clubs
$lang['ClubsTitle'] = 'Vereine ({$a})';
$lang['ClubsHint'] = 'Das PDF eines Vereins verwendet die Optionen oben.';
$lang['ColCode'] = 'Code';
$lang['ColClub'] = 'Verein';
$lang['ColArchers'] = 'Schützen';
$lang['ColCards'] = 'Scorekarten';
$lang['ColPdf'] = 'PDF';
$lang['ClubPdf'] = 'PDF von {$a}';
$lang['NoClub'] = 'Ohne Verein';
$lang['Total'] = 'Gesamt';

// What cannot be printed
$lang['NothingToPrint'] = 'In den Qualifikationsdurchgängen steht noch kein Schütze auf einer Scheibe: Es gibt nichts zu drucken.';
$lang['NotTargetArchery'] = 'Dieses Modul druckt Scorekarten für das Scheibenschießen, und dieses Turnier ist ein Feld- oder 3D-Turnier.';
$lang['WarnOutside'] = '{$a} Scorekarte(n) liegen auf einer Position außerhalb der Scheiben ihres Durchgangs oder jenseits seiner Anzahl Schützen pro Scheibe. Wie der Druck von ianseo druckt das Modul sie nicht.';
$lang['WarnTwice'] = '{$a} Position(en) sind mehreren Schützen zugewiesen. Auf eine Position passt nur eine Scorekarte: Prüfen Sie die Scheibenzuteilung.';
$lang['WarnUnplaced'] = '{$a} Schütze(n) haben noch keine Scheibe und daher keine Scorekarte.';

// Errors
$lang['ErrAccess'] = 'Es ist kein Turnier geöffnet, oder Sie dürfen dessen Scorekarten nicht drucken.';
$lang['ErrToken'] = 'Die Sitzung ist abgelaufen. Laden Sie die Seite neu und versuchen Sie es erneut.';
$lang['ErrNoSession'] = 'Wählen Sie mindestens einen Durchgang.';
$lang['ErrNothing'] = 'Mit diesen Optionen gibt es nichts zu drucken.';
$lang['ErrZip'] = 'Auf diesem Server fehlt die PHP-Erweiterung zip: Das PDF jedes Vereins bleibt über die Liste unten verfügbar.';
$lang['ErrTemp'] = 'In den temporären Ordner des Servers kann nicht geschrieben werden.';
$lang['ErrJob'] = 'Dieser Download ist abgelaufen oder unbekannt. Starten Sie ihn erneut.';
$lang['ErrServer'] = 'Der Server hat nicht wie erwartet geantwortet (HTTP {$a[status]}).';
$lang['NotFound'] = 'Für diese Datei gibt es mit diesen Optionen nichts zu drucken.';
