<?php
/**
 * Spanish strings of the scorecard splitter. Keys missing here fall back to the
 * English file, which holds the reference list. The vocabulary follows the
 * core's Spanish files ("hoja de puntuación", "diana", "turno", "arquero").
 */

// Module and menu
$lang['ModuleName'] = 'Hojas de puntuación por club o arquero';
$lang['MenuPrint'] = 'Imprimir las hojas de puntuación';
$lang['MenuUpdate'] = 'Actualizar módulo';
$lang['UpdateTitle'] = 'Hojas de puntuación por club o arquero — actualización del módulo';
$lang['BackToPage'] = 'Volver a las hojas de puntuación';

// Page
$lang['Lead'] = 'Las hojas de puntuación de la competición abierta, divididas en archivos: uno por club, con todos sus arqueros, o uno por arquero. Solo se imprimen las páginas que llevan al menos un arquero, sea cual sea el número de posiciones por diana; las posiciones libres de una página impresa quedan como cuadrículas vacías. La impresión de hojas de puntuación de ianseo no cambia.';
$lang['OptionsTitle'] = 'Hojas de puntuación';
$lang['SessionsTitle'] = 'Turnos';
$lang['SessionCards'] = '({$a} hojas)';
$lang['LayoutTitle'] = 'Diseño';
$lang['HideTarget'] = 'Ocultar el número de diana';
$lang['HideTargetHint'] = 'Para una competición en la que las posiciones solo sirven para dar a cada arquero sus propias hojas, como un desafío tirado en los clubes: el número no le dice nada al arquero.';

// Archive
$lang['ArchiveTitle'] = 'Archivo comprimido';
$lang['ModeClub'] = 'Un archivo por club, con el nombre de su código';
$lang['ModeArcher'] = 'Un archivo por arquero, con el nombre de su número de licencia';
$lang['ZipButton'] = 'Descargar el ZIP';
$lang['Starting'] = 'Preparando…';
$lang['Progress'] = '{$a[done]} / {$a[total]} archivos';
$lang['Packing'] = 'Creando el archivo comprimido…';
$lang['Ready'] = 'El archivo comprimido está listo: la descarga comienza.';

// List of clubs
$lang['ClubsTitle'] = 'Clubes ({$a})';
$lang['ClubsHint'] = 'El PDF de un club usa las opciones de arriba.';
$lang['ColCode'] = 'Código';
$lang['ColClub'] = 'Club';
$lang['ColArchers'] = 'Arqueros';
$lang['ColCards'] = 'Hojas';
$lang['ColPdf'] = 'PDF';
$lang['ClubPdf'] = 'PDF de {$a}';
$lang['NoClub'] = 'Sin club';
$lang['Total'] = 'Total';

// What cannot be printed
$lang['NothingToPrint'] = 'Ningún arquero está todavía en una diana en los turnos de clasificación: no hay nada que imprimir.';
$lang['NotTargetArchery'] = 'Este módulo imprime hojas de puntuación de tiro a diana, y esta competición es de recorrido o 3D.';
$lang['WarnOutside'] = '{$a} hoja(s) están en una posición fuera de las dianas de su turno, o más allá de su número de arqueros por diana. Como la impresión de ianseo, el módulo no las imprime.';
$lang['WarnTwice'] = '{$a} posición(es) están asignadas a varios arqueros. En una posición solo cabe una hoja: compruebe la asignación de dianas.';
$lang['WarnUnplaced'] = '{$a} arquero(s) aún no tienen diana, y por lo tanto no tienen hoja.';

// Errors
$lang['ErrAccess'] = 'No hay ninguna competición abierta, o no tiene permiso para imprimir sus hojas de puntuación.';
$lang['ErrToken'] = 'La sesión ha caducado. Vuelva a cargar la página e inténtelo de nuevo.';
$lang['ErrNoSession'] = 'Elija al menos un turno.';
$lang['ErrNothing'] = 'Nada que imprimir con estas opciones.';
$lang['ErrZip'] = 'Falta la extensión zip de PHP en este servidor: el PDF de cada club sigue disponible en la lista de abajo.';
$lang['ErrTemp'] = 'No se puede escribir en la carpeta temporal del servidor.';
$lang['ErrJob'] = 'Esta descarga ha caducado o es desconocida. Vuelva a lanzarla.';
$lang['ErrServer'] = 'El servidor no respondió como se esperaba (HTTP {$a[status]}).';
$lang['NotFound'] = 'Nada que imprimir para este archivo con estas opciones.';
