<?php
/**
 * Italian strings for the shared module machinery.
 *
 * Keys must mirror languages/en.php, which is the fallback. Navigation wording
 * follows the core files (CmdCancel = Annulla, Close = Chiudi).
 *
 * Translated without a native reviewer: worth a proofread before release.
 */

/* Source of the module */
$lang['Source']              = 'Origine';
$lang['Repository']          = 'Repository';
$lang['Branch']              = 'Ramo';
$lang['Folder']              = 'Cartella';
$lang['LocalModuleVersion']  = 'Versione locale del modulo';
$lang['Unknown']             = 'sconosciuta';
$lang['NoRepoConfigured']    = 'Nessun repository GitHub configurato in module.json.';

/* Checking and applying */
$lang['CheckHeading']        = 'Controlla gli aggiornamenti';
$lang['CheckNow']            = 'Controlla adesso';
$lang['ApplyHeading']        = 'Applica l\'aggiornamento';
$lang['ForceUpdate']         = 'Forza un aggiornamento';
$lang['LocalVersion']        = 'Versione locale';
$lang['RemoteVersion']       = 'Versione remota';
$lang['StatusUpToDate']      = 'Aggiornato';
$lang['StatusUpdate']        = 'Aggiornamento disponibile';
$lang['StatusNew']           = 'Nuovo';
$lang['UpdateModule']        = 'Aggiorna il modulo';
$lang['UpdateModuleHint']    = 'Sostituisce i file elencati in <code>version.json</code> con quelli del repository e sincronizza <code>_shared</code>. La configurazione locale (<code>module.json</code>) non viene toccata.';
$lang['UpdateModuleConfirm'] = "Scaricare e sostituire i file del modulo da GitHub?\n\nI file elencati in version.json saranno sostituiti e la libreria comune _shared sincronizzata.";
$lang['DefaultPageTitle']    = '{$a} — Aggiornamento del modulo';

/* Shared library */
$lang['SharedLibrary']       = 'Libreria comune';
$lang['SharedLocal']         = 'locale';
$lang['SharedRemote']        = 'remota';
$lang['SharedAutoNote']      = 'sincronizzata automaticamente con l\'aggiornamento del modulo.';

/* Update messages */
$lang['MsgReadRemoteFailed'] = 'Impossibile leggere il version.json remoto: {$a}';
$lang['MsgNoFilesList']      = 'Il version.json remoto non contiene un elenco di file (files[]).';
$lang['MsgModuleUpdated']    = 'Modulo aggiornato a {$a[version]} ({$a[files]} file).';
$lang['MsgFilesFailed']      = '{$a[ok]} file OK. Non riusciti: {$a[fail]}';
$lang['MsgSharedError']      = 'Libreria comune _shared: {$a}';
$lang['MsgSharedFailed']     = 'Libreria comune _shared: errore {$a}';
$lang['MsgSharedSynced']     = 'Libreria comune _shared sincronizzata (v{$a[version]}, {$a[files]} file).';

/* Other modules of the repository */
$lang['OtherModules']        = 'Altri moduli del repository';
$lang['OtherModulesHint']    = 'Installa altri moduli pubblicati nello stesso repository GitHub, direttamente da ianseo.';
$lang['ShowAvailable']       = 'Mostra i moduli disponibili';
$lang['ColModule']           = 'Modulo';
$lang['ColState']            = 'Stato';
$lang['ThisModule']          = '(questo modulo)';
$lang['Installed']           = 'Installato';
$lang['Available']           = 'Disponibile';
$lang['InstallConfirm']      = 'Installare il modulo {$a} da GitHub?';
$lang['InstallButton']       = 'Installa';
$lang['NoModuleFound']       = 'Nessun modulo trovato nel repository.';
$lang['MsgBadModuleName']    = 'Nome del modulo non valido.';
$lang['MsgModuleNotInRepo']  = 'Il modulo "{$a}" non è presente nel repository.';
$lang['MsgInstallError']     = 'Installazione di {$a[name]}: {$a[error]}';
$lang['MsgInstalled']        = 'Modulo "{$a[name]}" installato nella versione {$a[version]} ({$a[files]} file){$a[extra]}. Ricarica ianseo per vederlo comparire nel menu.';
$lang['MsgFailures']         = ' (errori: {$a})';

/* Danger zone on the update screen */
$lang['UninstallModule']     = 'Disinstalla il modulo';
$lang['UninstallRemoves']    = 'Elimina i file del modulo.';
$lang['UninstallBackupHint'] = 'Subito dopo viene proposto un backup scaricabile (nulla resta sul server).';
$lang['UninstallRecoverHint']= 'I file restano recuperabili dal repository GitHub: reinstallare li ripristina.';
$lang['UninstallTablesHint'] = 'L\'eliminazione dei dati in banca dati ({$a}) è proposta a parte, <b>non selezionata per impostazione predefinita</b>.';
$lang['UninstallNoTables']   = 'Questo modulo non crea alcuna tabella: nessun dato viene perso.';
$lang['UninstallButton']     = 'Disinstalla {$a}…';

/* Uninstall screen */
$lang['UninstallTitle']      = 'Disinstallare un modulo';
$lang['UninstallDone']       = 'Il modulo {$a} è stato disinstallato.';
$lang['BackupPrepared']      = 'È stato preparato un backup dei file. Scaricalo adesso — <b>viene eliminato dal server non appena scaricato</b>:';
$lang['DownloadBackup']      = 'Scarica il backup (.zip)';
$lang['BackupPurgeNote']     = 'Se non lo scarichi, viene eliminato automaticamente dalla cartella temporanea.';
$lang['NoBackupKept']        = 'Nessun backup conservato: i file restano recuperabili dal repository GitHub e reinstallare li ripristina.';
$lang['TablesDropped']       = 'Tabelle eliminate: {$a}';
$lang['TablesKept']          = 'Le tabelle {$a} sono state <b>conservate</b>: reinstallando si ritrovano i dati.';
$lang['BackHome']            = 'Torna alla pagina iniziale di ianseo';
$lang['ChooseModule']        = 'Scegli il modulo da disinstallare:';
$lang['OnlyModuleJson']      = 'Sono elencate solo le cartelle che contengono un <code>module.json</code>.';
$lang['NoModuleInstalled']   = 'Nessun modulo gestito da questo sistema è installato.';
$lang['UninstallHeading']    = 'Disinstallare il modulo "{$a}"';
$lang['ThisWillDo']          = 'Questa azione:';
$lang['WillPrepareBackup']   = 'prepara un <b>backup scaricabile</b> dei file (proposto subito dopo, poi eliminato dal server);';
$lang['WillDeleteFolder']    = 'elimina definitivamente la cartella <code>{$a}</code>';
$lang['FilesRecoverable']    = ' (file recuperabili dal repository GitHub)';
$lang['SharedNeverRemoved']  = 'La libreria comune <code>_shared/</code> non viene mai eliminata: altri moduli la usano.';
$lang['ModuleWarningTitle']  = 'Avviso di questo modulo — da leggere prima di continuare';
$lang['DropTablesLabel']     = 'Elimina anche i <b>dati in banca dati</b>:';
$lang['DropTablesNote']      = 'Non selezionato, i dati restano e reinstallando si ritrovano. <b>Selezionato, l\'eliminazione è irreversibile.</b>';
$lang['ConfirmTypeName']     = 'Per confermare, digita il nome del modulo ({$a}):';
$lang['FinalConfirm']        = 'Ultima conferma: disinstallare {$a}?';
$lang['UninstallForever']    = 'Disinstalla definitivamente';
$lang['Cancel']              = 'Annulla';

/* Errors raised by the library itself */
$lang['ErrGitHubUnreachable']       = 'Impossibile raggiungere GitHub (controlla la connessione)';
$lang['ErrBadJson']                 = 'Risposta JSON non valida';
$lang['ErrBadGitHubUrl']            = 'URL GitHub non valido in module.json';
$lang['ErrRemoteVersionUnreadable'] = 'Impossibile leggere il version.json remoto';
$lang['ErrRemoteVersionInvalid']    = 'version.json remoto assente o non valido';
$lang['ErrSharedVersionUnreadable'] = 'Impossibile leggere il _shared/version.json remoto';
$lang['ErrSharedVersionInvalid']    = '_shared/version.json remoto non valido';
$lang['ErrTreeUnreadable']          = 'Struttura dei file GitHub illeggibile';
$lang['ErrZipMissing']              = 'estensione ZIP non disponibile su questo server';
$lang['ErrModuleDirMissing']        = 'cartella del modulo non trovata';
$lang['ErrZipCreate']               = 'impossibile creare {$a}';
$lang['ErrZipWrite']                = 'scrittura dell\'archivio interrotta';

/* Uninstall errors */
$lang['ErrModuleNotFound']   = 'Modulo non trovato o non gestito da questo sistema.';
$lang['ErrBadToken']         = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
$lang['ErrNameMismatch']     = 'Il nome digitato non corrisponde a "{$a}". Non è stato eliminato nulla.';
$lang['ErrBackupFailed']     = 'Backup non riuscito ({$a}). Disinstallazione annullata.';
$lang['ErrDeleteFailed']     = 'Impossibile eliminare i file. Controlla i permessi della cartella.';
