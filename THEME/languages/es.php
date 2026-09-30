<?php
/**
 * Spanish strings of the theme module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Tema';
$lang['Mode_auto'] = 'Automático';
$lang['Mode_light'] = 'Claro';
$lang['Mode_dark'] = 'Oscuro';
$lang['MenuColours'] = 'Colores…';
$lang['MenuUpdate'] = 'Actualización del módulo';
$lang['UpdateTitle'] = 'Tema — actualización del módulo';
$lang['BackToPage'] = 'Volver al tema';

// Page
$lang['Lead'] = 'El aspecto de ianseo en este navegador: claro u oscuro, y en qué color.';
$lang['ModeTitle'] = 'Modo';
$lang['ModeHint'] = 'Automático sigue el ajuste claro u oscuro de este ordenador, y cambia con él.';
$lang['PaletteTitle'] = 'Color';
$lang['PaletteHint'] = 'Dé a cada una de sus instalaciones de ianseo su propio color, para distinguirlas de un vistazo. Cada color tiene una versión clara y otra oscura.';
$lang['StoredNote'] = 'Este navegador guarda estos ajustes, solo para esta instalación de ianseo: las demás personas y las demás instalaciones no se ven afectadas. Las impresiones y los archivos PDF no cambian.';
$lang['DebugNote'] = 'El modo debug está activo: sus colores naranjas sustituyen al color elegido, para que no pase desapercibido.';

// Palettes
$lang['Palette_ianseo'] = 'Azul ianseo';
$lang['Palette_green'] = 'Verde abeto';
$lang['Palette_teal'] = 'Turquesa';
$lang['Palette_olive'] = 'Oliva';
$lang['Palette_violet'] = 'Violeta';
$lang['Palette_raspberry'] = 'Frambuesa';
$lang['Palette_burgundy'] = 'Burdeos';
$lang['Palette_slate'] = 'Pizarra';

// Pages without a menu (administrator)
$lang['HookTitle'] = 'Páginas sin menú';
$lang['HookText'] = 'Las ventanas emergentes, como la ficha de un participante, y las páginas Speaker no muestran el menú: el tema no puede llegar a ellas por sí solo. Al activarlo se añaden unas líneas a Common/DebugOverrides.php, un archivo que las actualizaciones de ianseo no tocan. Llevan el tema a todas las páginas dibujadas con los colores estándar de ianseo, sin destello de color claro durante la carga; las pantallas públicas (salida TV, aplicaciones de puntuación) no se ven afectadas. Las líneas dejan de hacer nada una vez desinstalado el módulo.';
$lang['HookOn'] = 'Activado.';
$lang['HookOff'] = 'No activado: solo las páginas con menú siguen el tema.';
$lang['HookEnable'] = 'Activar';
$lang['HookDisable'] = 'Desactivar';
$lang['HookDone'] = 'Hecho.';
$lang['HookFailed'] = 'No se ha podido escribir el archivo.';
$lang['HookNotWritable'] = 'El servidor web no puede escribir {$a}. Para activarlo a mano, ponga estas líneas al principio del archivo, creándolo si no existe:';
$lang['HookNotWritableOff'] = 'El servidor web no puede escribir {$a}. Para desactivarlo a mano, quite del archivo las líneas comprendidas entre THEME BEGIN y THEME END.';
