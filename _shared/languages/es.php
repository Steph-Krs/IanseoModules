<?php
/**
 * Spanish strings for the shared module machinery.
 *
 * Keys must mirror languages/en.php, which is the fallback. Navigation wording
 * follows the core files (CmdCancel = Anular, Close = Cerrar).
 *
 * Translated without a native reviewer: worth a proofread before release.
 */

/* Source of the module */
$lang['Source']              = 'Origen';
$lang['Repository']          = 'Repositorio';
$lang['Branch']              = 'Rama';
$lang['Folder']              = 'Carpeta';
$lang['LocalModuleVersion']  = 'Versión local del módulo';
$lang['Unknown']             = 'desconocida';
$lang['NoRepoConfigured']    = 'No hay ningún repositorio GitHub configurado en module.json.';

/* Checking and applying */
$lang['CheckHeading']        = 'Comprobar actualizaciones';
$lang['CheckNow']            = 'Comprobar ahora';
$lang['ApplyHeading']        = 'Aplicar la actualización';
$lang['ForceUpdate']         = 'Forzar una actualización';
$lang['LocalVersion']        = 'Versión local';
$lang['RemoteVersion']       = 'Versión remota';
$lang['StatusUpToDate']      = 'Actualizado';
$lang['StatusUpdate']        = 'Actualización disponible';
$lang['StatusNew']           = 'Nuevo';
$lang['UpdateModule']        = 'Actualizar el módulo';
$lang['UpdateModuleHint']    = 'Reemplaza los archivos listados en <code>version.json</code> por los del repositorio y sincroniza <code>_shared</code>. La configuración local (<code>module.json</code>) no se toca.';
$lang['UpdateModuleConfirm'] = "¿Descargar y reemplazar los archivos del módulo desde GitHub?\n\nLos archivos listados en version.json se reemplazarán y la biblioteca común _shared se sincronizará.";
$lang['DefaultPageTitle']    = '{$a} — Actualización del módulo';

/* Shared library */
$lang['SharedLibrary']       = 'Biblioteca común';
$lang['SharedLocal']         = 'local';
$lang['SharedRemote']        = 'remota';
$lang['SharedAutoNote']      = 'se sincroniza automáticamente con la actualización del módulo.';

/* Update messages */
$lang['MsgReadRemoteFailed'] = 'No se ha podido leer el version.json remoto: {$a}';
$lang['MsgNoFilesList']      = 'El version.json remoto no contiene una lista de archivos (files[]).';
$lang['MsgModuleUpdated']    = 'Módulo actualizado a {$a[version]} ({$a[files]} archivo(s)).';
$lang['MsgFilesFailed']      = '{$a[ok]} archivo(s) OK. Con error: {$a[fail]}';
$lang['MsgSharedError']      = 'Biblioteca común _shared: {$a}';
$lang['MsgSharedFailed']     = 'Biblioteca común _shared: error {$a}';
$lang['MsgSharedSynced']     = 'Biblioteca común _shared sincronizada (v{$a[version]}, {$a[files]} archivo(s)).';

/* Other modules of the repository */
$lang['OtherModules']        = 'Otros módulos del repositorio';
$lang['OtherModulesHint']    = 'Instala otros módulos publicados en el mismo repositorio GitHub, directamente desde ianseo.';
$lang['ShowAvailable']       = 'Ver los módulos disponibles';
$lang['ColModule']           = 'Módulo';
$lang['ColState']            = 'Estado';
$lang['ThisModule']          = '(este módulo)';
$lang['Installed']           = 'Instalado';
$lang['Available']           = 'Disponible';
$lang['InstallConfirm']      = '¿Instalar el módulo {$a} desde GitHub?';
$lang['InstallButton']       = 'Instalar';
$lang['NoModuleFound']       = 'No se ha encontrado ningún módulo en el repositorio.';
$lang['MsgBadModuleName']    = 'Nombre de módulo no válido.';
$lang['MsgModuleNotInRepo']  = 'El módulo "{$a}" no está en el repositorio.';
$lang['MsgInstallError']     = 'Instalación de {$a[name]}: {$a[error]}';
$lang['MsgInstalled']        = 'Módulo "{$a[name]}" instalado en la versión {$a[version]} ({$a[files]} archivo(s)){$a[extra]}. Recarga ianseo para verlo aparecer en el menú.';
$lang['MsgFailures']         = ' (errores: {$a})';

/* Danger zone on the update screen */
$lang['UninstallModule']     = 'Desinstalar el módulo';
$lang['UninstallRemoves']    = 'Elimina los archivos del módulo.';
$lang['UninstallBackupHint'] = 'Justo después se ofrece una copia de seguridad descargable (no queda nada en el servidor).';
$lang['UninstallRecoverHint']= 'Los archivos siguen siendo recuperables desde el repositorio GitHub: reinstalar los restaura.';
$lang['UninstallTablesHint'] = 'La eliminación de los datos en la base ({$a}) se ofrece aparte, <b>desmarcada por defecto</b>.';
$lang['UninstallNoTables']   = 'Este módulo no crea ninguna tabla: no se pierde ningún dato.';
$lang['UninstallButton']     = 'Desinstalar {$a}…';

/* Uninstall screen */
$lang['UninstallTitle']      = 'Desinstalar un módulo';
$lang['UninstallDone']       = 'El módulo {$a} se ha desinstalado.';
$lang['BackupPrepared']      = 'Se ha preparado una copia de seguridad de los archivos. Descárgala ahora — <b>se elimina del servidor en cuanto se descarga</b>:';
$lang['DownloadBackup']      = 'Descargar la copia de seguridad (.zip)';
$lang['BackupPurgeNote']     = 'Si no la descargas, se purga automáticamente de la carpeta temporal.';
$lang['NoBackupKept']        = 'No se conserva ninguna copia: los archivos siguen siendo recuperables desde el repositorio GitHub, y reinstalar los restaura.';
$lang['TablesDropped']       = 'Tablas eliminadas: {$a}';
$lang['TablesKept']          = 'Las tablas {$a} se han <b>conservado</b>: al reinstalar se recuperan los datos.';
$lang['BackHome']            = 'Volver a la página de inicio de ianseo';
$lang['ChooseModule']        = 'Elige el módulo que quieres desinstalar:';
$lang['OnlyModuleJson']      = 'Solo se listan las carpetas que contienen un <code>module.json</code>.';
$lang['NoModuleInstalled']   = 'No hay instalado ningún módulo gestionado por este sistema.';
$lang['UninstallHeading']    = 'Desinstalar el módulo "{$a}"';
$lang['ThisWillDo']          = 'Esta acción va a:';
$lang['WillPrepareBackup']   = 'preparar una <b>copia de seguridad descargable</b> de los archivos (ofrecida justo después y luego eliminada del servidor);';
$lang['WillDeleteFolder']    = 'eliminar definitivamente la carpeta <code>{$a}</code>';
$lang['FilesRecoverable']    = ' (archivos recuperables desde el repositorio GitHub)';
$lang['SharedNeverRemoved']  = 'La biblioteca común <code>_shared/</code> nunca se elimina: otros módulos la utilizan.';
$lang['ModuleWarningTitle']  = 'Advertencia de este módulo — léela antes de continuar';
$lang['DropTablesLabel']     = 'Eliminar también los <b>datos en la base</b>:';
$lang['DropTablesNote']      = 'Desmarcado, los datos se conservan y al reinstalar se recuperan. <b>Marcado, la eliminación es irreversible.</b>';
$lang['ConfirmTypeName']     = 'Para confirmar, escribe el nombre del módulo ({$a}):';
$lang['FinalConfirm']        = 'Última confirmación: ¿desinstalar {$a}?';
$lang['UninstallForever']    = 'Desinstalar definitivamente';
$lang['Cancel']              = 'Anular';

/* Errors raised by the library itself */
$lang['ErrGitHubUnreachable']       = 'No se ha podido contactar con GitHub (comprueba la conexión)';
$lang['ErrBadJson']                 = 'Respuesta JSON no válida';
$lang['ErrBadGitHubUrl']            = 'URL de GitHub no válida en module.json';
$lang['ErrRemoteVersionUnreadable'] = 'No se ha podido leer el version.json remoto';
$lang['ErrRemoteVersionInvalid']    = 'version.json remoto ausente o no válido';
$lang['ErrSharedVersionUnreadable'] = 'No se ha podido leer el _shared/version.json remoto';
$lang['ErrSharedVersionInvalid']    = '_shared/version.json remoto no válido';
$lang['ErrTreeUnreadable']          = 'Árbol de archivos de GitHub ilegible';
$lang['ErrZipMissing']              = 'la extensión ZIP no está disponible en este servidor';
$lang['ErrModuleDirMissing']        = 'no se ha encontrado la carpeta del módulo';
$lang['ErrZipCreate']               = 'no se ha podido crear {$a}';
$lang['ErrZipWrite']                = 'la escritura del archivo se ha interrumpido';

/* Uninstall errors */
$lang['ErrModuleNotFound']   = 'Módulo no encontrado o no gestionado por este sistema.';
$lang['ErrBadToken']         = 'Token de seguridad no válido. Recarga la página y vuelve a empezar.';
$lang['ErrNameMismatch']     = 'El nombre escrito no coincide con "{$a}". No se ha eliminado nada.';
$lang['ErrBackupFailed']     = 'Copia de seguridad fallida ({$a}). Desinstalación anulada.';
$lang['ErrDeleteFailed']     = 'No se han podido eliminar los archivos. Comprueba los permisos de la carpeta.';
