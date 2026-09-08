<?php
/**
 * German strings for the shared module machinery.
 *
 * Keys must mirror languages/en.php, which is the fallback. Navigation wording
 * follows the core files (Close = Schliessen, Back = Zurück).
 *
 * Translated without a native reviewer: worth a proofread before release.
 */

/* Source of the module */
$lang['Source']              = 'Quelle';
$lang['Repository']          = 'Repository';
$lang['Branch']              = 'Zweig';
$lang['Folder']              = 'Ordner';
$lang['LocalModuleVersion']  = 'Lokale Modulversion';
$lang['Unknown']             = 'unbekannt';
$lang['NoRepoConfigured']    = 'In module.json ist kein GitHub-Repository konfiguriert.';

/* Checking and applying */
$lang['CheckHeading']        = 'Auf Aktualisierungen prüfen';
$lang['CheckNow']            = 'Jetzt prüfen';
$lang['ApplyHeading']        = 'Aktualisierung anwenden';
$lang['ForceUpdate']         = 'Aktualisierung erzwingen';
$lang['LocalVersion']        = 'Lokale Version';
$lang['RemoteVersion']       = 'Version im Repository';
$lang['StatusUpToDate']      = 'Aktuell';
$lang['StatusUpdate']        = 'Aktualisierung verfügbar';
$lang['StatusNew']           = 'Neu';
$lang['UpdateModule']        = 'Modul aktualisieren';
$lang['UpdateModuleHint']    = 'Ersetzt die in <code>version.json</code> aufgeführten Dateien durch die des Repositorys und synchronisiert <code>_shared</code>. Die lokale Konfiguration (<code>module.json</code>) bleibt unberührt.';
$lang['UpdateModuleConfirm'] = "Die Moduldateien von GitHub herunterladen und ersetzen?\n\nDie in version.json aufgeführten Dateien werden ersetzt und die gemeinsame Bibliothek _shared synchronisiert.";
$lang['DefaultPageTitle']    = '{$a} — Modulaktualisierung';

/* Shared library */
$lang['SharedLibrary']       = 'Gemeinsame Bibliothek';
$lang['SharedLocal']         = 'lokal';
$lang['SharedRemote']        = 'im Repository';
$lang['SharedAutoNote']      = 'wird zusammen mit der Modulaktualisierung automatisch synchronisiert.';

/* Update messages */
$lang['MsgReadRemoteFailed'] = 'Die version.json im Repository konnte nicht gelesen werden: {$a}';
$lang['MsgNoFilesList']      = 'Die version.json im Repository enthält keine Dateiliste (files[]).';
$lang['MsgModuleUpdated']    = 'Modul auf {$a[version]} aktualisiert ({$a[files]} Datei(en)).';
$lang['MsgFilesFailed']      = '{$a[ok]} Datei(en) OK. Fehlgeschlagen: {$a[fail]}';
$lang['MsgSharedError']      = 'Gemeinsame Bibliothek _shared: {$a}';
$lang['MsgSharedFailed']     = 'Gemeinsame Bibliothek _shared: Fehler {$a}';
$lang['MsgSharedSynced']     = 'Gemeinsame Bibliothek _shared synchronisiert (v{$a[version]}, {$a[files]} Datei(en)).';

/* Other modules of the repository */
$lang['OtherModules']        = 'Weitere Module dieses Repositorys';
$lang['OtherModulesHint']    = 'Installiert weitere im selben GitHub-Repository veröffentlichte Module, direkt aus ianseo heraus.';
$lang['ShowAvailable']       = 'Verfügbare Module anzeigen';
$lang['ColModule']           = 'Modul';
$lang['ColState']            = 'Status';
$lang['ThisModule']          = '(dieses Modul)';
$lang['Installed']           = 'Installiert';
$lang['Available']           = 'Verfügbar';
$lang['InstallConfirm']      = 'Das Modul {$a} von GitHub installieren?';
$lang['InstallButton']       = 'Installieren';
$lang['NoModuleFound']       = 'Im Repository wurde kein Modul gefunden.';
$lang['MsgBadModuleName']    = 'Ungültiger Modulname.';
$lang['MsgModuleNotInRepo']  = 'Das Modul "{$a}" ist nicht im Repository.';
$lang['MsgInstallError']     = 'Installation von {$a[name]}: {$a[error]}';
$lang['MsgInstalled']        = 'Modul "{$a[name]}" in Version {$a[version]} installiert ({$a[files]} Datei(en)){$a[extra]}. Laden Sie ianseo neu, damit es im Menü erscheint.';
$lang['MsgFailures']         = ' (Fehler: {$a})';

/* Danger zone on the update screen */
$lang['UninstallModule']     = 'Modul deinstallieren';
$lang['UninstallRemoves']    = 'Löscht die Moduldateien.';
$lang['UninstallBackupHint'] = 'Unmittelbar danach wird eine herunterladbare Sicherung angeboten (auf dem Server bleibt nichts zurück).';
$lang['UninstallRecoverHint']= 'Die Dateien bleiben über das GitHub-Repository wiederherstellbar: eine Neuinstallation bringt sie zurück.';
$lang['UninstallTablesHint'] = 'Das Löschen der Daten in der Datenbank ({$a}) wird getrennt angeboten und ist <b>standardmässig nicht angehakt</b>.';
$lang['UninstallNoTables']   = 'Dieses Modul legt keine Tabelle an: es gehen keine Daten verloren.';
$lang['UninstallButton']     = '{$a} deinstallieren…';

/* Uninstall screen */
$lang['UninstallTitle']      = 'Ein Modul deinstallieren';
$lang['UninstallDone']       = 'Das Modul {$a} wurde deinstalliert.';
$lang['BackupPrepared']      = 'Eine Sicherung der Dateien wurde vorbereitet. Laden Sie sie jetzt herunter — <b>sie wird beim Herunterladen vom Server gelöscht</b>:';
$lang['DownloadBackup']      = 'Sicherung herunterladen (.zip)';
$lang['BackupPurgeNote']     = 'Wird sie nicht heruntergeladen, verschwindet sie automatisch aus dem temporären Ordner.';
$lang['NoBackupKept']        = 'Keine Sicherung aufbewahrt: die Dateien bleiben über das GitHub-Repository wiederherstellbar, und eine Neuinstallation bringt sie zurück.';
$lang['TablesDropped']       = 'Gelöschte Tabellen: {$a}';
$lang['TablesKept']          = 'Die Tabellen {$a} wurden <b>behalten</b>: eine Neuinstallation findet die Daten wieder.';
$lang['BackHome']            = 'Zurück zur ianseo-Startseite';
$lang['ChooseModule']        = 'Wählen Sie das zu deinstallierende Modul:';
$lang['OnlyModuleJson']      = 'Aufgeführt sind nur Ordner mit einer <code>module.json</code>.';
$lang['NoModuleInstalled']   = 'Es ist kein von diesem System verwaltetes Modul installiert.';
$lang['UninstallHeading']    = 'Das Modul "{$a}" deinstallieren';
$lang['ThisWillDo']          = 'Diese Aktion wird:';
$lang['WillPrepareBackup']   = 'eine <b>herunterladbare Sicherung</b> der Dateien vorbereiten (unmittelbar danach angeboten, dann vom Server gelöscht);';
$lang['WillDeleteFolder']    = 'den Ordner <code>{$a}</code> endgültig löschen';
$lang['FilesRecoverable']    = ' (Dateien über das GitHub-Repository wiederherstellbar)';
$lang['SharedNeverRemoved']  = 'Die gemeinsame Bibliothek <code>_shared/</code> wird nie gelöscht: andere Module verwenden sie.';
$lang['ModuleWarningTitle']  = 'Hinweis dieses Moduls — vor dem Fortfahren lesen';
$lang['DropTablesLabel']     = 'Auch die <b>Daten in der Datenbank</b> löschen:';
$lang['DropTablesNote']      = 'Nicht angehakt bleiben die Daten erhalten und eine Neuinstallation findet sie wieder. <b>Angehakt ist das Löschen endgültig.</b>';
$lang['ConfirmTypeName']     = 'Tippen Sie zur Bestätigung den Modulnamen ({$a}):';
$lang['FinalConfirm']        = 'Letzte Bestätigung: {$a} deinstallieren?';
$lang['UninstallForever']    = 'Endgültig deinstallieren';
$lang['Cancel']              = 'Abbrechen';

/* Errors raised by the library itself */
$lang['ErrGitHubUnreachable']       = 'GitHub ist nicht erreichbar (Verbindung prüfen)';
$lang['ErrBadJson']                 = 'Ungültige JSON-Antwort';
$lang['ErrBadGitHubUrl']            = 'Ungültige GitHub-URL in module.json';
$lang['ErrRemoteVersionUnreadable'] = 'Die version.json im Repository konnte nicht gelesen werden';
$lang['ErrRemoteVersionInvalid']    = 'version.json im Repository fehlt oder ist ungültig';
$lang['ErrSharedVersionUnreadable'] = 'Die _shared/version.json im Repository konnte nicht gelesen werden';
$lang['ErrSharedVersionInvalid']    = '_shared/version.json im Repository ist ungültig';
$lang['ErrTreeUnreadable']          = 'GitHub-Dateibaum nicht lesbar';
$lang['ErrZipMissing']              = 'die ZIP-Erweiterung ist auf diesem Server nicht verfügbar';
$lang['ErrModuleDirMissing']        = 'der Modulordner wurde nicht gefunden';
$lang['ErrZipCreate']               = '{$a} konnte nicht erstellt werden';
$lang['ErrZipWrite']                = 'das Schreiben des Archivs wurde abgebrochen';

/* Uninstall errors */
$lang['ErrModuleNotFound']   = 'Modul nicht gefunden oder nicht von diesem System verwaltet.';
$lang['ErrBadToken']         = 'Ungültiges Sicherheitstoken. Laden Sie die Seite neu und beginnen Sie erneut.';
$lang['ErrNameMismatch']     = 'Der eingegebene Name stimmt nicht mit "{$a}" überein. Es wurde nichts gelöscht.';
$lang['ErrBackupFailed']     = 'Sicherung fehlgeschlagen ({$a}). Deinstallation abgebrochen.';
$lang['ErrDeleteFailed']     = 'Die Dateien konnten nicht gelöscht werden. Prüfen Sie die Ordnerrechte.';
