<?php
/**
 * Italian strings of the scorecard splitter. Keys missing here fall back to the
 * English file, which holds the reference list. The vocabulary follows the
 * core's Italian files ("score", "piazzola", "turno", "società").
 */

// Module and menu
$lang['ModuleName'] = 'Score per società o arciere';
$lang['MenuPrint'] = 'Stampa gli score';
$lang['MenuUpdate'] = 'Aggiorna modulo';
$lang['UpdateTitle'] = 'Score per società o arciere — aggiornamento del modulo';
$lang['BackToPage'] = 'Torna agli score';

// Page
$lang['Lead'] = 'Gli score della gara aperta, divisi in file: uno per società, con tutti i suoi arcieri, oppure uno per arciere. Vengono stampate solo le pagine con almeno un arciere, qualunque sia il numero di posizioni per piazzola; le posizioni libere di una pagina stampata restano griglie vuote. La stampa degli score di ianseo non cambia.';
$lang['OptionsTitle'] = 'Score';
$lang['SessionsTitle'] = 'Turni';
$lang['SessionCards'] = '({$a} score)';
$lang['LayoutTitle'] = 'Impaginazione';
$lang['HideTarget'] = 'Nascondi il numero di piazzola';
$lang['HideTargetHint'] = 'Per una gara in cui le posizioni servono solo a dare a ogni arciere i propri score, come una sfida tirata nelle società: il numero non dice nulla all\'arciere.';

// Archive
$lang['ArchiveTitle'] = 'Archivio';
$lang['ModeClub'] = 'Un file per società, con il nome del suo codice';
$lang['ModeArcher'] = 'Un file per arciere, con il nome del suo numero di tessera';
$lang['ZipButton'] = 'Scarica lo ZIP';
$lang['Starting'] = 'Preparazione…';
$lang['Progress'] = '{$a[done]} / {$a[total]} file';
$lang['Packing'] = 'Creazione dell\'archivio…';
$lang['Ready'] = 'L\'archivio è pronto: il download inizia.';

// List of clubs
$lang['ClubsTitle'] = 'Società ({$a})';
$lang['ClubsHint'] = 'Il PDF di una società usa le opzioni qui sopra.';
$lang['ColCode'] = 'Codice';
$lang['ColClub'] = 'Società';
$lang['ColArchers'] = 'Arcieri';
$lang['ColCards'] = 'Score';
$lang['ColPdf'] = 'PDF';
$lang['ClubPdf'] = 'PDF di {$a}';
$lang['NoClub'] = 'Senza società';
$lang['Total'] = 'Totale';

// What cannot be printed
$lang['NothingToPrint'] = 'Nessun arciere è ancora su una piazzola nei turni di qualificazione: non c\'è nulla da stampare.';
$lang['NotTargetArchery'] = 'Questo modulo stampa score di tiro alla targa, e questa gara è di campagna o 3D.';
$lang['WarnOutside'] = '{$a} score sono su una posizione fuori dalle piazzole del loro turno, o oltre il suo numero di arcieri per piazzola. Come la stampa di ianseo, il modulo non li stampa.';
$lang['WarnTwice'] = '{$a} posizioni sono assegnate a più arcieri. In una posizione entra un solo score: controlla l\'assegnazione delle piazzole.';
$lang['WarnUnplaced'] = '{$a} arcieri non hanno ancora una piazzola, e quindi nessuno score.';

// Errors
$lang['ErrAccess'] = 'Nessuna gara è aperta, oppure non hai il diritto di stamparne gli score.';
$lang['ErrToken'] = 'La sessione è scaduta. Ricarica la pagina e riprova.';
$lang['ErrNoSession'] = 'Scegli almeno un turno.';
$lang['ErrNothing'] = 'Niente da stampare con queste opzioni.';
$lang['ErrZip'] = 'Su questo server manca l\'estensione zip di PHP: il PDF di ogni società resta disponibile dall\'elenco qui sotto.';
$lang['ErrTemp'] = 'Impossibile scrivere nella cartella temporanea del server.';
$lang['ErrJob'] = 'Questo download è scaduto o sconosciuto. Rilancialo.';
$lang['ErrServer'] = 'Il server non ha risposto come previsto (HTTP {$a[status]}).';
$lang['NotFound'] = 'Niente da stampare per questo file con queste opzioni.';
