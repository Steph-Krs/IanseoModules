<?php
/**
 * French strings for the shared module machinery.
 *
 * Keys must mirror languages/en.php, which is the fallback. Run
 * the bench's compliance checker on any module to list drift between the two.
 */

/* Source of the module */
$lang['Source']              = 'Source';
$lang['Repository']          = 'Dépôt';
$lang['Branch']              = 'Branche';
$lang['Folder']              = 'Dossier';
$lang['LocalModuleVersion']  = 'Version locale du module';
$lang['Unknown']             = 'inconnue';
$lang['NoRepoConfigured']    = 'Aucun dépôt GitHub configuré dans module.json.';

/* Checking and applying */
$lang['CheckHeading']        = 'Vérifier les mises à jour';
$lang['CheckNow']            = 'Vérifier maintenant';
$lang['ApplyHeading']        = 'Appliquer la mise à jour';
$lang['ForceUpdate']         = 'Forcer une mise à jour';
$lang['LocalVersion']        = 'Version locale';
$lang['RemoteVersion']       = 'Version distante';
$lang['StatusUpToDate']      = 'À jour';
$lang['StatusUpdate']        = 'Mise à jour disponible';
$lang['StatusNew']           = 'Nouveau';
$lang['UpdateModule']        = 'Mettre à jour le module';
$lang['UpdateModuleHint']    = 'Remplace les fichiers listés dans <code>version.json</code> par ceux du dépôt et synchronise <code>_shared</code>. La configuration locale (<code>module.json</code>) n\'est pas touchée.';
$lang['UpdateModuleConfirm'] = "Télécharger et remplacer les fichiers du module depuis GitHub ?\n\nLes fichiers listés dans version.json seront remplacés, et la bibliothèque commune _shared synchronisée.";
$lang['DefaultPageTitle']    = '{$a} — Mise à jour du module';

/* Shared library */
$lang['SharedLibrary']       = 'Bibliothèque commune';
$lang['SharedLocal']         = 'locale';
$lang['SharedRemote']        = 'distante';
$lang['SharedAutoNote']      = 'synchronisée automatiquement avec la mise à jour du module.';

/* Update messages */
$lang['MsgReadRemoteFailed'] = 'Impossible de lire le version.json distant : {$a}';
$lang['MsgNoFilesList']      = 'Le version.json distant ne contient pas de liste de fichiers (files[]).';
$lang['MsgModuleUpdated']    = 'Module mis à jour vers {$a[version]} ({$a[files]} fichier(s)).';
$lang['MsgFilesFailed']      = '{$a[ok]} fichier(s) OK. Échec : {$a[fail]}';
$lang['MsgSharedError']      = 'Bibliothèque commune _shared : {$a}';
$lang['MsgSharedFailed']     = 'Bibliothèque commune _shared : échec {$a}';
$lang['MsgSharedSynced']     = 'Bibliothèque commune _shared synchronisée (v{$a[version]}, {$a[files]} fichier(s)).';

/* Other modules of the repository */
$lang['OtherModules']        = 'Autres modules du dépôt';
$lang['OtherModulesHint']    = 'Installe d\'autres modules publiés dans le même dépôt GitHub, directement depuis ianseo.';
$lang['ShowAvailable']       = 'Voir les modules disponibles';
$lang['ColModule']           = 'Module';
$lang['ColState']            = 'État';
$lang['ThisModule']          = '(ce module)';
$lang['Installed']           = 'Installé';
$lang['Available']           = 'Disponible';
$lang['InstallConfirm']      = 'Installer le module {$a} depuis GitHub ?';
$lang['InstallButton']       = 'Installer';
$lang['NoModuleFound']       = 'Aucun module trouvé dans le dépôt.';
$lang['MsgBadModuleName']    = 'Nom de module invalide.';
$lang['MsgModuleNotInRepo']  = 'Le module « {$a} » est absent du dépôt.';
$lang['MsgInstallError']     = 'Installation de {$a[name]} : {$a[error]}';
$lang['MsgInstalled']        = 'Module « {$a[name]} » installé en version {$a[version]} ({$a[files]} fichier(s)){$a[extra]}. Rechargez ianseo pour le voir apparaître dans son menu.';
$lang['MsgFailures']         = ' (échecs : {$a})';

/* Danger zone on the update screen */
$lang['UninstallModule']     = 'Désinstaller le module';
$lang['UninstallRemoves']    = 'Supprime les fichiers du module.';
$lang['UninstallBackupHint'] = 'Une sauvegarde téléchargeable est proposée juste après (rien n\'est conservé sur le serveur).';
$lang['UninstallRecoverHint']= 'Les fichiers restent récupérables depuis le dépôt GitHub : une réinstallation les restaure.';
$lang['UninstallTablesHint'] = 'La suppression des données en base ({$a}) est proposée séparément, <b>décochée par défaut</b>.';
$lang['UninstallNoTables']   = 'Ce module ne crée aucune table : aucune donnée n\'est perdue.';
$lang['UninstallButton']     = 'Désinstaller {$a}…';

/* Uninstall screen */
$lang['UninstallTitle']      = 'Désinstaller un module';
$lang['UninstallDone']       = 'Le module {$a} a été désinstallé.';
$lang['BackupPrepared']      = 'Une sauvegarde des fichiers a été préparée. Téléchargez-la maintenant — <b>elle est supprimée du serveur dès le téléchargement</b> :';
$lang['DownloadBackup']      = 'Télécharger la sauvegarde (.zip)';
$lang['BackupPurgeNote']     = 'Si vous ne la téléchargez pas, elle est purgée automatiquement du dossier temporaire.';
$lang['NoBackupKept']        = 'Aucune sauvegarde conservée : les fichiers restent récupérables depuis le dépôt GitHub, et une réinstallation les restaure.';
$lang['TablesDropped']       = 'Tables supprimées : {$a}';
$lang['TablesKept']          = 'Les tables {$a} ont été <b>conservées</b> : une réinstallation retrouvera les données.';
$lang['BackHome']            = 'Retour à l\'accueil ianseo';
$lang['ChooseModule']        = 'Choisissez le module à désinstaller :';
$lang['OnlyModuleJson']      = 'Seuls les dossiers contenant un <code>module.json</code> sont listés.';
$lang['NoModuleInstalled']   = 'Aucun module géré par ce système n\'est installé.';
$lang['UninstallHeading']    = 'Désinstaller le module « {$a} »';
$lang['ThisWillDo']          = 'Cette action va :';
$lang['WillPrepareBackup']   = 'préparer une <b>sauvegarde téléchargeable</b> des fichiers (proposée juste après, puis supprimée du serveur) ;';
$lang['WillDeleteFolder']    = 'supprimer définitivement le dossier <code>{$a}</code>';
$lang['FilesRecoverable']    = ' (fichiers récupérables depuis le dépôt GitHub)';
$lang['SharedNeverRemoved']  = 'La bibliothèque commune <code>_shared/</code> n\'est jamais supprimée : d\'autres modules l\'utilisent.';
$lang['ModuleWarningTitle']  = 'Avertissement de ce module — à lire avant de continuer';
$lang['DropTablesLabel']     = 'Supprimer aussi les <b>données en base</b> :';
$lang['DropTablesNote']      = 'Décoché, les données sont conservées et une réinstallation les retrouve. <b>Coché, la suppression est irréversible.</b>';
$lang['ConfirmTypeName']     = 'Pour confirmer, tapez le nom du module ({$a}) :';
$lang['FinalConfirm']        = 'Dernière confirmation : désinstaller {$a} ?';
$lang['UninstallForever']    = 'Désinstaller définitivement';
$lang['Cancel']              = 'Annuler';

/* Errors raised by the library itself */
$lang['ErrGitHubUnreachable']       = 'Impossible de joindre GitHub (vérifiez la connexion)';
$lang['ErrBadJson']                 = 'Réponse JSON invalide';
$lang['ErrBadGitHubUrl']            = 'URL GitHub invalide dans module.json';
$lang['ErrRemoteVersionUnreadable'] = 'Impossible de lire le version.json distant';
$lang['ErrRemoteVersionInvalid']    = 'version.json distant absent ou invalide';
$lang['ErrSharedVersionUnreadable'] = 'Impossible de lire le _shared/version.json distant';
$lang['ErrSharedVersionInvalid']    = '_shared/version.json distant invalide';
$lang['ErrTreeUnreadable']          = 'Arborescence GitHub illisible';
$lang['ErrZipMissing']              = 'extension ZIP indisponible sur ce serveur';
$lang['ErrModuleDirMissing']        = 'dossier du module introuvable';
$lang['ErrZipCreate']               = 'impossible de créer {$a}';
$lang['ErrZipWrite']                = 'écriture de l\'archive interrompue';

/* Uninstall errors */
$lang['ErrModuleNotFound']   = 'Module introuvable ou non géré par ce système.';
$lang['ErrBadToken']         = 'Jeton de sécurité invalide. Rechargez la page et recommencez.';
$lang['ErrNameMismatch']     = 'Le nom saisi ne correspond pas à « {$a} ». Rien n\'a été supprimé.';
$lang['ErrBackupFailed']     = 'Sauvegarde impossible ({$a}). Désinstallation annulée.';
$lang['ErrDeleteFailed']     = 'Suppression des fichiers impossible. Vérifiez les droits sur le dossier.';
