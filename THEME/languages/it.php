<?php
/**
 * Italian strings of the theme module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Tema';
$lang['Mode_auto'] = 'Automatico';
$lang['Mode_light'] = 'Chiaro';
$lang['Mode_dark'] = 'Scuro';
$lang['MenuColours'] = 'Colori…';
$lang['MenuUpdate'] = 'Aggiornamento modulo';
$lang['UpdateTitle'] = 'Tema — aggiornamento del modulo';
$lang['BackToPage'] = 'Torna al tema';

// Page
$lang['Lead'] = 'L\'aspetto di ianseo in questo browser: chiaro o scuro, e in quale colore.';
$lang['ModeTitle'] = 'Modalità';
$lang['ModeHint'] = 'Automatico segue l\'impostazione chiara o scura di questo computer, e cambia con essa.';
$lang['PaletteTitle'] = 'Colore';
$lang['PaletteHint'] = 'Date a ciascuna delle vostre installazioni di ianseo il suo colore, per distinguerle a colpo d\'occhio. Ogni colore ha una versione chiara e una scura.';
$lang['StoredNote'] = 'Queste impostazioni sono conservate da questo browser, solo per questa installazione di ianseo: le altre persone e le altre installazioni non ne sono toccate. Le stampe e i file PDF non cambiano.';
$lang['DebugNote'] = 'La modalità debug è attiva: i suoi colori arancioni sostituiscono il colore scelto, perché non passi inosservata.';

// Palettes
$lang['Palette_ianseo'] = 'Blu ianseo';
$lang['Palette_green'] = 'Verde abete';
$lang['Palette_teal'] = 'Turchese';
$lang['Palette_olive'] = 'Oliva';
$lang['Palette_violet'] = 'Viola';
$lang['Palette_raspberry'] = 'Lampone';
$lang['Palette_burgundy'] = 'Bordeaux';
$lang['Palette_slate'] = 'Ardesia';

// Pages without a menu (administrator)
$lang['HookTitle'] = 'Pagine senza menu';
$lang['HookText'] = 'Le finestre popup, come la scheda di un partecipante, e le pagine Speaker non mostrano il menu: il tema non può raggiungerle da solo. L\'attivazione aggiunge alcune righe a Common/DebugOverrides.php, un file che gli aggiornamenti di ianseo non toccano. Portano il tema su tutte le pagine disegnate con i colori standard di ianseo, senza lampi di colore chiaro durante il caricamento; gli schermi pubblici (uscita TV, applicazioni di punteggio) non sono toccati. Le righe non fanno più nulla una volta disinstallato il modulo.';
$lang['HookOn'] = 'Attivato.';
$lang['HookOff'] = 'Non attivato: solo le pagine con il menu seguono il tema.';
$lang['HookEnable'] = 'Attiva';
$lang['HookDisable'] = 'Disattiva';
$lang['HookDone'] = 'Fatto.';
$lang['HookFailed'] = 'Non è stato possibile scrivere il file.';
$lang['HookNotWritable'] = 'Il server web non può scrivere {$a}. Per attivarlo a mano, mettete queste righe all\'inizio del file, creandolo se non esiste:';
$lang['HookNotWritableOff'] = 'Il server web non può scrivere {$a}. Per disattivarlo a mano, togliete dal file le righe comprese tra THEME BEGIN e THEME END.';
