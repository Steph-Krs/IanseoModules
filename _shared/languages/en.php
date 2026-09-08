<?php
/**
 * English strings for the shared module machinery: the update screen every
 * module renders, and the uninstall screen they all link to.
 *
 * Same format and semantics as the core language files in
 * Common/Languages/<code>/<Module>.php — the file defines a $lang array, looked
 * up by upd_text(). English is the fallback, so this file must carry EVERY key.
 *
 * These strings belong here rather than in each module: the update screen is one
 * implementation shared by all of them, and duplicating its wording per module
 * would mean translating the same sentences five times over.
 *
 * {$a} is replaced by the argument passed to upd_text(); {$a[name]} picks a
 * named value out of an array, exactly as the core's get_text() does it.
 */

/* Source of the module */
$lang['Source']              = 'Source';
$lang['Repository']          = 'Repository';
$lang['Branch']              = 'Branch';
$lang['Folder']              = 'Folder';
$lang['LocalModuleVersion']  = 'Local module version';
$lang['Unknown']             = 'unknown';
$lang['NoRepoConfigured']    = 'No GitHub repository is configured in module.json.';

/* Checking and applying */
$lang['CheckHeading']        = 'Check for updates';
$lang['CheckNow']            = 'Check now';
$lang['ApplyHeading']        = 'Apply the update';
$lang['ForceUpdate']         = 'Force an update';
$lang['LocalVersion']        = 'Local version';
$lang['RemoteVersion']       = 'Remote version';
$lang['StatusUpToDate']      = 'Up to date';
$lang['StatusUpdate']        = 'Update available';
$lang['StatusNew']           = 'New';
$lang['UpdateModule']        = 'Update the module';
$lang['UpdateModuleHint']    = 'Replaces the files listed in <code>version.json</code> with the repository versions and synchronises <code>_shared</code>. The local configuration (<code>module.json</code>) is left alone.';
$lang['UpdateModuleConfirm'] = "Download and replace the module files from GitHub?\n\nThe files listed in version.json will be replaced, and the shared _shared library synchronised.";
$lang['DefaultPageTitle']    = '{$a} — Module update';

/* Shared library */
$lang['SharedLibrary']       = 'Shared library';
$lang['SharedLocal']         = 'local';
$lang['SharedRemote']        = 'remote';
$lang['SharedAutoNote']      = 'synchronised automatically with the module update.';

/* Update messages */
$lang['MsgReadRemoteFailed'] = 'Could not read the remote version.json: {$a}';
$lang['MsgNoFilesList']      = 'The remote version.json carries no files[] list.';
$lang['MsgModuleUpdated']    = 'Module updated to {$a[version]} ({$a[files]} file(s)).';
$lang['MsgFilesFailed']      = '{$a[ok]} file(s) OK. Failed: {$a[fail]}';
$lang['MsgSharedError']      = 'Shared library _shared: {$a}';
$lang['MsgSharedFailed']     = 'Shared library _shared: failed {$a}';
$lang['MsgSharedSynced']     = 'Shared library _shared synchronised (v{$a[version]}, {$a[files]} file(s)).';

/* Other modules of the repository */
$lang['OtherModules']        = 'Other modules in this repository';
$lang['OtherModulesHint']    = 'Installs other modules published in the same GitHub repository, from inside ianseo.';
$lang['ShowAvailable']       = 'Show the available modules';
$lang['ColModule']           = 'Module';
$lang['ColState']            = 'State';
$lang['ThisModule']          = '(this module)';
$lang['Installed']           = 'Installed';
$lang['Available']           = 'Available';
$lang['InstallConfirm']      = 'Install the module {$a} from GitHub?';
$lang['InstallButton']       = 'Install';
$lang['NoModuleFound']       = 'No module found in the repository.';
$lang['MsgBadModuleName']    = 'Invalid module name.';
$lang['MsgModuleNotInRepo']  = 'Module "{$a}" is not in the repository.';
$lang['MsgInstallError']     = 'Installing {$a[name]}: {$a[error]}';
$lang['MsgInstalled']        = 'Module "{$a[name]}" installed, version {$a[version]} ({$a[files]} file(s)){$a[extra]}. Reload ianseo to see it appear in the menu.';
$lang['MsgFailures']         = ' (failures: {$a})';

/* Danger zone on the update screen */
$lang['UninstallModule']     = 'Uninstall the module';
$lang['UninstallRemoves']    = 'Deletes the module files.';
$lang['UninstallBackupHint'] = 'A downloadable backup is offered straight afterwards (nothing is kept on the server).';
$lang['UninstallRecoverHint']= 'The files stay recoverable from the GitHub repository: reinstalling restores them.';
$lang['UninstallTablesHint'] = 'Deleting the database data ({$a}) is offered separately, <b>unticked by default</b>.';
$lang['UninstallNoTables']   = 'This module creates no table, so no data is lost.';
$lang['UninstallButton']     = 'Uninstall {$a}…';

/* Uninstall screen */
$lang['UninstallTitle']      = 'Uninstall a module';
$lang['UninstallDone']       = 'The module {$a} has been uninstalled.';
$lang['BackupPrepared']      = 'A backup of the files has been prepared. Download it now — <b>it is deleted from the server as soon as it is downloaded</b>:';
$lang['DownloadBackup']      = 'Download the backup (.zip)';
$lang['BackupPurgeNote']     = 'If you do not download it, it is purged automatically from the temporary folder.';
$lang['NoBackupKept']        = 'No backup kept: the files stay recoverable from the GitHub repository, and reinstalling restores them.';
$lang['TablesDropped']       = 'Tables deleted: {$a}';
$lang['TablesKept']          = 'The tables {$a} have been <b>kept</b>: reinstalling will find the data again.';
$lang['BackHome']            = 'Back to the ianseo home page';
$lang['ChooseModule']        = 'Choose the module to uninstall:';
$lang['OnlyModuleJson']      = 'Only folders holding a <code>module.json</code> are listed.';
$lang['NoModuleInstalled']   = 'No module managed by this system is installed.';
$lang['UninstallHeading']    = 'Uninstall the module "{$a}"';
$lang['ThisWillDo']          = 'This will:';
$lang['WillPrepareBackup']   = 'prepare a <b>downloadable backup</b> of the files (offered straight afterwards, then deleted from the server);';
$lang['WillDeleteFolder']    = 'permanently delete the folder <code>{$a}</code>';
$lang['FilesRecoverable']    = ' (files recoverable from the GitHub repository)';
$lang['SharedNeverRemoved']  = 'The shared <code>_shared/</code> library is never deleted: other modules use it.';
$lang['ModuleWarningTitle']  = 'Warning from this module — read before continuing';
$lang['DropTablesLabel']     = 'Also delete the <b>database data</b>:';
$lang['DropTablesNote']      = 'Left unticked, the data is kept and reinstalling finds it again. <b>Ticked, the deletion cannot be undone.</b>';
$lang['ConfirmTypeName']     = 'To confirm, type the module name ({$a}):';
$lang['FinalConfirm']        = 'Last confirmation: uninstall {$a}?';
$lang['UninstallForever']    = 'Uninstall permanently';
$lang['Cancel']              = 'Cancel';

/* Errors raised by the library itself */
$lang['ErrGitHubUnreachable']       = 'Could not reach GitHub (check the connection)';
$lang['ErrBadJson']                 = 'Invalid JSON response';
$lang['ErrBadGitHubUrl']            = 'Invalid GitHub URL in module.json';
$lang['ErrRemoteVersionUnreadable'] = 'Could not read the remote version.json';
$lang['ErrRemoteVersionInvalid']    = 'The remote version.json is missing or invalid';
$lang['ErrSharedVersionUnreadable'] = 'Could not read the remote _shared/version.json';
$lang['ErrSharedVersionInvalid']    = 'The remote _shared/version.json is invalid';
$lang['ErrTreeUnreadable']          = 'Could not read the GitHub file tree';
$lang['ErrZipMissing']              = 'the ZIP extension is not available on this server';
$lang['ErrModuleDirMissing']        = 'the module folder could not be found';
$lang['ErrZipCreate']               = 'could not create {$a}';
$lang['ErrZipWrite']                = 'writing the archive was interrupted';

/* Uninstall errors */
$lang['ErrModuleNotFound']   = 'Module not found, or not managed by this system.';
$lang['ErrBadToken']         = 'Invalid security token. Reload the page and start again.';
$lang['ErrNameMismatch']     = 'The name typed does not match "{$a}". Nothing has been deleted.';
$lang['ErrBackupFailed']     = 'Backup failed ({$a}). Uninstall cancelled.';
$lang['ErrDeleteFailed']     = 'Could not delete the files. Check the folder permissions.';
