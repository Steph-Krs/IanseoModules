<?php
/**
 * Italian strings of the training module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Allenamento';
$lang['MenuCreate'] = 'Crea un allenamento';
$lang['MenuHome'] = 'Allenamenti';
$lang['MenuDashboard'] = 'Pannello dell\'allenamento';
$lang['MenuParticipants'] = 'Arcieri dell\'allenamento';
$lang['MenuTeams'] = 'Squadre';
$lang['MenuRanking'] = 'Classifica e scontri';
$lang['MenuResults'] = 'Risultati';
$lang['MenuFormats'] = 'Formati di riferimento';
$lang['MenuArchers'] = 'Arcieri abituali';
$lang['MenuFull'] = 'Menu completo su questo browser';
$lang['MenuSimple'] = 'Menu semplificato su questo browser';
$lang['MenuIsFull'] = 'Questo browser mostra il menu completo di ianseo.';
$lang['MenuIsSimple'] = 'Questo browser mostra il menu semplificato degli allenamenti.';
$lang['MenuUpdate'] = 'Aggiornamento modulo';
$lang['UpdateTitle'] = 'Allenamento - aggiornamento del modulo';
$lang['BackToPage'] = 'Torna agli allenamenti';
$lang['DateFmt'] = 'd/m/Y';

// Common words
$lang['Save'] = 'Salva';
$lang['Saved'] = 'Salvato.';
$lang['Cancel'] = 'Annulla';
$lang['Delete'] = 'Elimina';
$lang['Edit'] = 'Modifica';
$lang['Add'] = 'Aggiungi';
$lang['Remove'] = 'Togli';
$lang['Rename'] = 'Rinomina';
$lang['Open'] = 'Apri';
$lang['Choose'] = 'Scegli';
$lang['Filter'] = 'Filtra…';
$lang['CheckAll'] = 'Seleziona tutti';
$lang['BackDashboard'] = 'Torna al pannello';
$lang['SessionN'] = 'Turno {$a}';
$lang['SexM'] = 'Uomo';
$lang['SexF'] = 'Donna';
$lang['DuelsOnly'] = 'Solo scontri';
$lang['ErrToken'] = 'Il modulo era scaduto. Riprovare.';

// Columns
$lang['ColDate'] = 'Data';
$lang['ColName'] = 'Nome';
$lang['ColFormat'] = 'Formato';
$lang['ColArchers'] = 'Arcieri';
$lang['ColArcher'] = 'Arciere';
$lang['ColCategory'] = 'Categoria';
$lang['ColSession'] = 'Turno';
$lang['ColTarget'] = 'Piazzola';
$lang['ColContent'] = 'Contenuto';
$lang['ColUpdated'] = 'Aggiornato';

// Home
$lang['HomeTitle'] = 'Allenamenti';
$lang['HomeLead'] = 'Un allenamento è una vera gara ianseo creata in pochi clic da un formato di riferimento: scegliete il formato, la data e l\'ora di inizio, selezionate gli arcieri, e tutto il resto è pronto - categorie, eventi, bersagli, distanze, programma. L\'allenatore segue poi i passi del pannello dell\'allenamento.';
$lang['HomeRuns'] = 'Allenamenti già creati';
$lang['HomeNoRun'] = 'Ancora nessun allenamento.';
$lang['HomeNoFormat'] = 'Ancora nessun formato di riferimento. Preparate una gara in ianseo con tutte le impostazioni volute, apritela, poi salvatela nella pagina Formati di riferimento.';
$lang['HomePrepare'] = 'Preparare gli allenamenti';

// Formats
$lang['FormatsTitle'] = 'Formati di riferimento';
$lang['FormatsLead'] = 'Un formato è la copia di una gara intera senza i suoi arcieri: divisioni e classi, eventi, bersagli, distanze, immagini, impostazioni della gara e programma. Una volta salvato, la gara di origine non serve più e può essere eliminata.';
$lang['FormatsFromOpen'] = 'Salva la gara aperta';
$lang['FormatsNoOpen'] = 'Aprite prima la gara da salvare come formato.';
$lang['FormatsOpenIs'] = 'Gara aperta: {$a[code]} - {$a[name]}';
$lang['FormatName'] = 'Nome del formato';
$lang['FormatSimple'] = 'Menu semplificato per gli allenamenti creati da questo formato';
$lang['FormatSimpleShort'] = 'Menu semplificato';
$lang['FormatReplace'] = 'Sostituisci un formato esistente';
$lang['FormatReplaceNone'] = 'No, crea un nuovo formato';
$lang['FormatsWhatKept'] = 'Arcieri, squadre, punteggi e risultati non vengono conservati, né i codici ianseo.net della gara. Gli orari del programma sono conservati come scarti dal primo inizio.';
$lang['FormatSave'] = 'Salva come formato';
$lang['FormatSaved'] = 'Formato "{$a}" salvato.';
$lang['FormatReplaced'] = 'Formato "{$a}" sostituito dalla gara aperta.';
$lang['FormatsList'] = 'Formati salvati';
$lang['FormatContent'] = '{$a[type]} - {$a[sessions]} turno/i - eventi: {$a[events]}';
$lang['FormatSource'] = 'Da {$a}';
$lang['FormatDeleteConfirm'] = 'Eliminare il formato "{$a}"? Gli allenamenti già creati non cambiano.';
$lang['FormatDeleted'] = 'Formato eliminato.';

// Regular archers
$lang['ArchersTitle'] = 'Arcieri abituali';
$lang['ArchersLead'] = 'Gli arcieri che partecipano spesso agli allenamenti: basta selezionarli alla creazione di un allenamento. I gruppi selezionano più arcieri in una volta.';
$lang['ArcherAdd'] = 'Aggiungi un arciere';
$lang['ArcherEdit'] = 'Modifica un arciere';
$lang['LookupLabel'] = 'Cerca nell\'archivio delle tessere:';
$lang['LookupPlaceholder'] = 'tessera o cognome';
$lang['LookupNone'] = 'Nessuno trovato nell\'archivio delle tessere.';
$lang['FieldFamily'] = 'Cognome';
$lang['FieldGiven'] = 'Nome';
$lang['FieldSex'] = 'Sesso';
$lang['FieldDob'] = 'Data di nascita';
$lang['FieldCode'] = 'Tessera';
$lang['FieldIoc'] = 'Paese';
$lang['FieldClub'] = 'Società';
$lang['FieldClubName'] = 'Nome della società';
$lang['FieldDiv'] = 'Divisione (arco)';
$lang['FieldCls'] = 'Classe';
$lang['FieldActive'] = 'Proposto alla creazione di un allenamento';
$lang['FieldGroups'] = 'Gruppi';
$lang['FieldDate'] = 'Data';
$lang['FieldStart'] = 'Ora di inizio';
$lang['ErrNoName'] = 'Il cognome è obbligatorio.';
$lang['ArchersList'] = 'Arcieri ({$a})';
$lang['ArchersNone'] = 'Ancora nessun arciere abituale.';
$lang['ArcherDeleteConfirm'] = 'Togliere {$a} dagli arcieri abituali?';
$lang['ArcherDeleted'] = 'Arciere eliminato.';
$lang['GroupsTitle'] = 'Gruppi';
$lang['GroupNew'] = 'Nuovo gruppo';
$lang['GroupDeleteConfirm'] = 'Eliminare il gruppo "{$a}"? I suoi arcieri restano nell\'elenco.';
$lang['ImportTitle'] = 'Copia arcieri della gara aperta';
$lang['ImportNone'] = 'Tutti gli arcieri della gara aperta sono già nell\'elenco.';
$lang['ImportDo'] = 'Copia gli arcieri selezionati';
$lang['ArchersImported'] = '{$a} arciere/i copiato/i.';

// Creation
$lang['CreateTitle'] = 'Crea un allenamento';
$lang['ChooseFormat'] = '- scegliere un formato -';
$lang['CreateDays'] = 'Questo formato dura {$a} giorni: anche i giorni seguenti vengono spostati.';
$lang['CreateShift'] = 'Tutti gli orari del programma seguono l\'ora di inizio mantenendo gli scarti del formato.';
$lang['QualSim'] = 'Solo scontri (qualificazione simulata)';
$lang['QualSimHelp'] = 'Ogni arciere riceve un punto; l\'ordine dei tabelloni si sceglie poi nella pagina Classifica e scontri.';
$lang['PlaceAuto'] = 'Assegna le piazzole agli arcieri';
$lang['CreateArchers'] = 'Arcieri';
$lang['CountPicked'] = '{$a} arciere/i selezionato/i';
$lang['CreateDo'] = 'Crea l\'allenamento';
$lang['Created'] = 'Allenamento {$a[code]} creato con {$a[n]} arciere/i.';
$lang['CreatedSkipped'] = '{$a} arciere/i non iscritto/i: categoria assente dal formato.';
$lang['ErrNoFormat'] = 'Scegliete un formato.';
$lang['ErrDate'] = 'La data non è valida.';
$lang['ErrNoArcher'] = 'Selezionate almeno un arciere.';
$lang['ErrNoCode'] = 'Tutti i codici di quel giorno sono occupati (99 allenamenti).';
$lang['ErrImport'] = 'Il formato è stato salvato da un ianseo più recente: aggiornate prima ianseo.';

// Teams
$lang['TeamsTitleAll'] = 'Squadre';
$lang['TeamsTitle'] = 'Squadre';
$lang['MixedTitle'] = 'Squadre miste';
$lang['TeamN'] = 'Squadra {$a}';
$lang['MixedN'] = 'Mista {$a}';
$lang['TeamAdd'] = 'Aggiungi una squadra';
$lang['MixedAdd'] = 'Aggiungi una squadra mista';
$lang['AddArcher'] = '+ arciere';
$lang['RemoveTeam'] = 'Togli la squadra';
$lang['TeamsHelpCreate'] = 'Si possono scegliere solo gli arcieri selezionati. Le squadre si possono anche comporre o cambiare più tardi, dal pannello.';
$lang['TeamsHelp'] = 'Componete le squadre ora o in qualsiasi momento: prima, durante o dopo la qualificazione. Un arciere può essere in una squadra e in una squadra mista diversa. I tabelloni a squadre si compilano nella pagina Classifica e scontri.';
$lang['TeamsNone'] = 'Questo allenamento non ha eventi a squadre.';

// Dashboard
$lang['DashTitle'] = 'Pannello dell\'allenamento';
$lang['DashNotRun'] = 'Questa gara non è stata creata dal modulo Allenamento; il pannello funziona comunque.';
$lang['StepArchers'] = 'Arcieri';
$lang['StepTargets'] = 'Piazzole';
$lang['StepScorecards'] = 'Schede segnapunti';
$lang['StepScores'] = 'Punteggi';
$lang['StepQualSim'] = 'Qualificazione';
$lang['StepDuels'] = 'Scontri';
$lang['StepResults'] = 'Risultati';
$lang['DashArchers'] = '{$a} arciere/i nell\'allenamento.';
$lang['DashTeams'] = '{$a} squadra/e composta/e';
$lang['DashPlaced'] = '{$a[on]} arciere/i su {$a[all]} con una piazzola';
$lang['PlaceDo'] = 'Assegna gli arcieri senza piazzola';
$lang['PlaceAgain'] = 'Riassegna tutti';
$lang['PlaceAgainConfirm'] = 'Togliere tutte le piazzole di questo turno e riassegnarle a tutti?';
$lang['PlaceManual'] = 'Piano delle piazzole: spostare arcieri a mano';
$lang['Placed'] = '{$a} arciere/i assegnato/i.';
$lang['PlacedLeft'] = '{$a} arciere/i senza piazzola: piazzole insufficienti nel turno.';
$lang['ScorecardsOf'] = 'Schede segnapunti - {$a}';
$lang['MoreOptions'] = 'Altre opzioni';
$lang['DashScores'] = '{$a[done]} arciere/i su {$a[all]} hanno un punteggio.';
$lang['QualRanking'] = 'Classifica di qualificazione';
$lang['QualRankingTeam'] = 'Classifica di qualificazione a squadre';
$lang['QualSimDone'] = 'Simulata: ogni arciere ha un punto. Scegliete l\'ordine dei tabelloni nella pagina Classifica e scontri.';
$lang['EventLaunched'] = 'tabellone compilato';
$lang['EventWaiting'] = 'tabellone da compilare';
$lang['EventCore'] = 'eliminatorie o gironi: schermate di ianseo';
$lang['FinalRanking'] = 'Classifica finale';
$lang['FinalRankingTeam'] = 'Classifica finale a squadre';

// Participants
$lang['ParticipantsTitle'] = 'Arcieri dell\'allenamento';
$lang['ParticipantsIn'] = 'Nell\'allenamento ({$a})';
$lang['ParticipantsAdd'] = 'Aggiungi arcieri abituali';
$lang['ParticipantsAllIn'] = 'Tutti gli arcieri abituali sono già nell\'allenamento.';
$lang['ParticipantsAddDo'] = 'Aggiungi gli arcieri selezionati';
$lang['ArchersAdded'] = '{$a} arciere/i aggiunto/i.';
$lang['ArcherRemoveConfirm'] = 'Togliere {$a} dall\'allenamento, con i suoi punteggi?';
$lang['ArcherRemoved'] = 'Arciere tolto.';

// Ranking and duels
$lang['RankingTitle'] = 'Classifica e scontri';
$lang['RankingLead'] = 'Ogni elenco è l\'ordine del tabellone, il migliore per primo: l\'ordine della qualificazione, gli ex aequo uno accanto all\'altro. Trascinate i nomi, o usate le frecce, poi compilate il tabellone.';
$lang['RankingNone'] = 'Questo allenamento non ha eventi con scontri.';
$lang['RankingCore'] = 'Questo evento ha eliminatorie o gironi: usate la schermata di ianseo.';
$lang['OpenCore'] = 'Apri';
$lang['RankingNoArcher'] = 'Nessun arciere in questo evento.';
$lang['RankingNoTeam'] = 'Ancora nessuna squadra: componete le squadre, e verificate che i loro arcieri abbiano un punteggio.';
$lang['RankingQualified'] = 'I primi {$a[n]} su {$a[all]} entrano nel tabellone.';
$lang['Tie'] = 'ex aequo';
$lang['MoveUp'] = 'Su';
$lang['MoveDown'] = 'Giù';
$lang['Launch'] = 'Compila il tabellone';
$lang['Relaunch'] = 'Compila di nuovo il tabellone';
$lang['RelaunchConfirm'] = 'Il tabellone di {$a} è già compilato: compilarlo di nuovo cancella gli scontri già inseriti. Continuare?';
$lang['DuelsLaunched'] = 'Tabellone di {$a} compilato.';
$lang['ErrLaunch'] = 'Il tabellone di {$a} non ha potuto essere compilato: ricaricate la pagina e riprovate.';

// Import and export
$lang['MenuTransfer'] = 'Importa / esporta';
$lang['TransferTitle'] = 'Importa / esporta';
$lang['TransferLead'] = 'Trasferire i formati di riferimento, gli arcieri abituali e i loro gruppi su un\'altra installazione, per esempio per configurare allo stesso modo un altro centro di allenamento.';
$lang['ExportTitle'] = 'Esporta';
$lang['ExportArchers'] = 'Arcieri abituali ({$a[archers]}) e gruppi ({$a[groups]})';
$lang['ExportPersonal'] = 'Il file contiene allora dati personali (nomi, date di nascita, tessere): conservatelo come qualsiasi elenco di arcieri.';
$lang['ExportDo'] = 'Scarica il file';
$lang['ExportNothing'] = 'Scegliete almeno un formato, o gli arcieri.';
$lang['ImportFileTitle'] = 'Importa';
$lang['ImportReplace'] = 'Sostituisci un formato con lo stesso nome';
$lang['ImportHelp'] = 'Un arciere già nell\'elenco (stessa tessera, altrimenti stesso nome e stessa data di nascita) viene aggiornato dal file; gli altri vengono aggiunti. I gruppi sono riconosciuti dal nome. Un formato salvato da un ianseo più recente di questo viene rifiutato: aggiornate prima ianseo.';
$lang['ImportFileDo'] = 'Importa il file';
$lang['ImportedSummary'] = 'Importato: {$a[formats]} nuovo/i formato/i, {$a[replaced]} sostituito/i, {$a[archers]} nuovo/i arciere/i, {$a[updated]} aggiornato/i, {$a[groups]} nuovo/i gruppo/i.';
$lang['ImportRefused'] = 'Formati rifiutati (illeggibili, o salvati da un ianseo più recente): {$a}';
$lang['ErrFile'] = 'Questo file non è un\'esportazione del modulo Allenamento.';
$lang['ErrFileSize'] = 'Il file non ha potuto essere ricevuto: forse supera quanto il server accetta.';
$lang['ClassAuto'] = 'La classe si compila dalla data di nascita e dal sesso; viene ricalcolata per la data di ogni allenamento.';

// ISK-Lite
$lang['IskTitle'] = 'Inserimento dei punteggi con ISK-Lite';
$lang['IskLead'] = 'Ogni allenamento è configurato per l\'inserimento con l\'app ISK nella sua versione gratuita (ISK-Lite): le schede segnapunti riportano i QR code dell\'app, e i punteggi arrivano da soli in ianseo.';
$lang['IskUrl'] = 'Indirizzo del server per i telefoni:';
$lang['IskUrlHelp'] = 'Assegnato a ogni nuovo allenamento. I telefoni devono raggiungere questo indirizzo: indicate l\'indirizzo di questo computer in rete, non localhost.';
$lang['IskUrlNone'] = 'Ancora nessun indirizzo: gli allenamenti avranno ISK-Lite senza indirizzo del server, da inserire nelle informazioni della gara. Indicate l\'indirizzo di questo computer in rete, non localhost.';
$lang['IskUrlBad'] = 'Questo indirizzo non è leggibile. Esempio: http://192.168.1.10';

// Targets and rhythm
$lang['ManualQualEntry'] = 'Inserimento manuale dei punteggi di qualificazione';
$lang['Rhythm'] = 'Arcieri per piazzola:';
$lang['RhythmConfirm'] = 'Passare a {$a} e riassegnare tutti gli arcieri di questo turno?';
$lang['RhythmChanged'] = 'Numero di arcieri per piazzola cambiato: tutti riassegnati.';
$lang['TargetsMissing'] = '{$a[off]} arciere/i senza piazzola: il turno ha {$a[have]} piazzole, ne servono {$a[need]} per assegnare tutti in {$a[rhythm]}.';
$lang['TargetsOff'] = '{$a[off]} arciere/i senza piazzola: usate Assegna gli arcieri senza piazzola.';
$lang['TargetsHave'] = '{$a} piazzole';
$lang['TargetsAdjust'] = 'Porta a {$a} piazzole';
$lang['TargetsAdjusted'] = 'Il turno ha ora {$a} piazzole: tutti riassegnati.';
