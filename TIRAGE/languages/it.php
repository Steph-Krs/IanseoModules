<?php
/**
 * Italian strings of the live draw module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Sorteggio dal vivo';
$lang['MenuShows'] = 'Sorteggi';
$lang['MenuUpdate'] = 'Aggiorna modulo';
$lang['UpdateTitle'] = 'Sorteggio dal vivo — aggiornamento del modulo';

// Home page
$lang['HomeLead'] = 'Gli schermi di un sorteggio in pubblico: uno schermo per la sala o la diretta, una regia per registrare ogni squadra sorteggiata e uno schermo per i commentatori. Un sorteggio non appartiene ad alcuna gara.';
$lang['ShowsTitle'] = 'Sorteggi';
$lang['ShowsNone'] = 'Nessun sorteggio. Creane uno qui sotto, oppure importa un salvataggio della vecchia pagina di sorteggio.';
$lang['ColTitle'] = 'Sorteggio';
$lang['ColProgress'] = 'Avanzamento';
$lang['ColUpdated'] = 'Ultima modifica';
$lang['ColScreens'] = 'Schermi';
$lang['Progress'] = '{$a[drawn]} posti sorteggiati su {$a[total]}';
$lang['OpenControl'] = 'Regia';
$lang['OpenEdit'] = 'Parametri e squadre';
$lang['OpenDisplay'] = 'Schermo pubblico';
$lang['OpenSpeaker'] = 'Commentatori';
$lang['Duplicate'] = 'Copia';
$lang['DuplicateHint'] = 'Copia liste, storico, note e aspetto in un nuovo sorteggio senza posti sorteggiati — così inizia il sorteggio dell\'anno successivo.';
$lang['ConfirmDeleteShow'] = 'Eliminare il sorteggio "{$a}" con le sue liste, lo storico e le note?';
$lang['Delete'] = 'Elimina';
$lang['SourceNone'] = 'Nessuna gara della stagione precedente';
$lang['CreateTitle'] = 'Nuovo sorteggio';
$lang['FieldTitle'] = 'Titolo';
$lang['FieldTitlePlaceholder'] = 'Sorteggio prima divisione 2027';
$lang['FieldSource'] = 'Gara della stagione precedente';
$lang['FieldSourceHint'] = 'Facoltativo. La gara ianseo della stagione appena giocata: le sue squadre possono essere importate e i commentatori vedono la stagione, i risultati e gli arcieri di ogni squadra.';
$lang['Create'] = 'Crea';
$lang['ImportTitle'] = 'Importa un salvataggio della vecchia pagina di sorteggio';
$lang['ImportLead'] = 'La pagina HTML autonoma usata prima di questo modulo esportava il suo stato in un file JSON. Fornisci quel file, e la pagina stessa se l\'hai ancora: i nomi delle categorie e le liste complete delle squadre sono scritti solo nella pagina. I posti sorteggiati non vengono importati.';
$lang['ImportSave'] = 'Salvataggio (.json)';
$lang['ImportPage'] = 'Vecchia pagina (.html), facoltativa';
$lang['Import'] = 'Importa';
$lang['CopyOf'] = 'Copia di {$a}';
$lang['LegacyTitle'] = 'Sorteggio importato';
$lang['LegacyCategory'] = 'Categoria {$a}';
$lang['EditTitle'] = 'Parametri e squadre';
$lang['ControlTitle'] = 'Regia';
$lang['SpeakerTitle'] = 'Commentatori';
$lang['BackToShows'] = 'Tutti i sorteggi';
$lang['Loading'] = 'Caricamento…';

// Errors and messages
$lang['ErrToken'] = 'La sessione è scaduta. Ricarica la pagina e riprova.';
$lang['ErrNameEmpty'] = 'Il nome è obbligatorio.';
$lang['ErrNoShow'] = 'Questo sorteggio non esiste più.';
$lang['ErrNoCategory'] = 'Questa categoria non esiste più.';
$lang['ErrNoTeam'] = 'Questa squadra non esiste più.';
$lang['ErrNoSource'] = 'Scegli prima la gara della stagione precedente.';
$lang['ErrField'] = 'Campo sconosciuto.';
$lang['ErrAction'] = 'Azione sconosciuta.';
$lang['ErrImage'] = 'Il file non è un\'immagine accettata (JPEG, PNG, WebP o GIF, al massimo 5 MB).';
$lang['ErrLink'] = 'Questo link di schermo non è valido. Forse è stato rinnovato: chiedi quello nuovo.';
$lang['ErrLegacyFormat'] = 'Questo file non è un salvataggio della vecchia pagina di sorteggio.';
$lang['ErrLegacyEmpty'] = 'Il salvataggio non contiene alcuna categoria.';
$lang['ErrNetwork'] = 'Il server non ha risposto. Controlla la connessione e riprova.';
$lang['ErrSeasonApplied'] = 'Questa stagione è già stata aggiunta allo storico.';
$lang['ErrNoEventChecked'] = 'Seleziona almeno un evento.';
$lang['MsgImported'] = '{$a} squadra/e importata/e.';
$lang['MsgLinked'] = '{$a} squadra/e collegata/e alla propria società.';
$lang['MsgSeasonApplied'] = 'Storico aggiornato per {$a[updated]} squadra/e; piazzamento precedente cancellato per {$a[cleared]} squadra/e assente/i in quella stagione.';

// Preparation page
$lang['SectionGeneral'] = 'Sorteggio';
$lang['FieldSubtitle'] = 'Sottotitolo';
$lang['LinksTitle'] = 'Link degli schermi';
$lang['LinksHint'] = 'Apri questi link sui computer che mostrano gli schermi. Non serve alcun account ianseo: chi ha un link vede quello schermo, quindi dai il link dei commentatori solo ai commentatori.';
$lang['Copy'] = 'Copia';
$lang['Copied'] = 'Link copiato.';
$lang['Open'] = 'Apri';
$lang['NewTokens'] = 'Rinnova i link';
$lang['NewTokensHint'] = 'I link attuali smettono subito di funzionare.';
$lang['ConfirmNewTokens'] = 'Rinnovare entrambi i link? Gli schermi già aperti non si aggiorneranno finché non verranno riaperti con i nuovi link.';
$lang['SectionSeasonNone'] = 'Stagione precedente';
$lang['SeasonNoSource'] = 'Scegli qui sopra la gara della stagione precedente per importarne le squadre e dare ai commentatori la stagione di ogni squadra.';
$lang['SectionSeason'] = 'Stagione precedente — {$a}';
$lang['ImportEventsLead'] = 'Ogni evento a squadre selezionato diventa una categoria con le sue squadre, collegate alla loro società. Un evento già presente riceve solo le squadre mancanti.';
$lang['AlreadyListed'] = 'già presente';
$lang['NoTeamEvent'] = 'Questa gara non ha eventi a squadre.';
$lang['ImportEvents'] = 'Importa le squadre';
$lang['LinkClubs'] = 'Collega le squadre alle società';
$lang['LinkClubsHint'] = 'Per le squadre inserite o importate per nome: trova la loro società nell\'evento collegato confrontando i nomi.';
$lang['ApplySeason'] = 'Aggiungi la stagione {$a} allo storico';
$lang['ApplySeasonHint'] = 'Una sola volta: +1 partecipazione per ogni squadra di quella stagione, +1 vittoria per la campione, +1 podio per le prime tre, piazzamento precedente sostituito dal piazzamento finale.';
$lang['SeasonAppliedPill'] = 'Stagione {$a} aggiunta allo storico';
$lang['ConfirmApplySeason'] = 'Aggiungere la stagione {$a} allo storico di tutte le squadre collegate? Si può fare una sola volta.';
$lang['NationalOn'] = 'Classifiche nazionali disponibili: i commentatori vedono il piazzamento nazionale di ogni arciere.';
$lang['NationalOff'] = 'Classifiche nazionali non disponibili. Vengono scaricate dal modulo REPARTITION_EPREUVES.';
$lang['FieldName'] = 'Nome';
$lang['FieldEvent'] = 'Evento della stagione precedente';
$lang['EventNone'] = 'Nessun evento collegato';
$lang['TeamCount'] = '{$a} squadra/e';
$lang['MoveUp'] = 'Sposta su';
$lang['MoveDown'] = 'Sposta giù';
$lang['DeleteCategory'] = 'Elimina la categoria';
$lang['ConfirmDeleteCategory'] = 'Eliminare questa categoria e tutte le sue squadre?';
$lang['ColTeam'] = 'Squadra';
$lang['ColClub'] = 'Codice società';
$lang['ColHint'] = 'Stagione precedente';
$lang['ColHintTitle'] = 'Piazzamento finale nell\'evento collegato della gara della stagione precedente';
$lang['ColNote'] = 'Nota per i commentatori';
$lang['NoTeam'] = 'Nessuna squadra in questa categoria.';
$lang['DeleteTeam'] = 'Elimina la squadra';
$lang['ConfirmDeleteTeam'] = 'Eliminare la squadra "{$a}"?';
$lang['AddTeams'] = 'Aggiungi squadre — una per riga, seguita se serve da ; e dal codice società';
$lang['AddTeamsPlaceholder'] = 'RIOM;0163157';
$lang['Add'] = 'Aggiungi';
$lang['AddCategory'] = 'Aggiungi una categoria';
$lang['AddCategoryHint'] = 'Una categoria è una lista a sé: squadre di cui si sorteggia l\'ordine, oppure le tappe della stagione presentate una alla volta man mano che lo speaker le annuncia.';
$lang['SectionLook'] = 'Schermo pubblico';
$lang['Preview'] = 'Anteprima';
$lang['LookBg'] = 'Colore di sfondo';
$lang['LookAccent'] = 'Colore d\'accento';
$lang['LookText'] = 'Colore del testo';
$lang['LookFontTitle'] = 'Carattere dei titoli';
$lang['LookFontBody'] = 'Carattere del testo';
$lang['LookSizeTitle'] = 'Dimensione del titolo';
$lang['LookSizeTeam'] = 'Dimensione dei nomi delle squadre';
$lang['LookSizeRank'] = 'Dimensione dei numeri di posto';
$lang['LookMargin'] = 'Margini alto e basso';
$lang['LookOverlay'] = 'Velo sull\'immagine';
$lang['LookAmbient'] = 'Animazione di sfondo';
$lang['LookSlots'] = 'Mostra i posti ancora da sorteggiare';
$lang['LookImage'] = 'Immagine di sfondo';
$lang['LookImageHint'] = 'JPEG, PNG, WebP o GIF, al massimo 5 MB. Il velo, nel colore di sfondo, mantiene leggibile il testo.';
$lang['ImageSet'] = 'Immagine presente';
$lang['ImageNone'] = 'Nessuna immagine';
$lang['ImageClear'] = 'Rimuovi l\'immagine';

// History columns
$lang['StatParticipations'] = 'Partecipazioni';
$lang['StatWins'] = 'Vittorie';
$lang['StatPodiums'] = 'Podi';
$lang['StatRank'] = 'Piazzamento precedente';
$lang['StatParticipationsShort'] = 'Part.';
$lang['StatWinsShort'] = 'Vitt.';
$lang['StatPodiumsShort'] = 'Podi';
$lang['StatRankShort'] = 'Prec.';

// Control page
$lang['OnAir'] = 'Sullo schermo pubblico';
$lang['SceneIdle'] = 'Attesa';
$lang['SceneCategory'] = 'Categoria in corso';
$lang['SceneSummary'] = 'Riepilogo';
$lang['Categories'] = 'Categorie';
$lang['StatsShown'] = 'Storico mostrato';
$lang['UndoLast'] = 'Annulla l\'ultimo posto';
$lang['ResetCategory'] = 'Azzera la categoria';
$lang['ConfirmReset'] = 'Ritirare tutti i posti sorteggiati in "{$a}"?';
$lang['SearchTeam'] = 'Cerca una squadra — Invio la sorteggia quando ne corrisponde una sola';
$lang['NextPlace'] = 'Prossimo posto';
$lang['CategoryComplete'] = 'Tutti i posti sono sorteggiati';
$lang['PlaceN'] = 'Posto {$a}';
$lang['Unassign'] = 'Ritira questo posto';
$lang['NoMatch'] = 'Nessuna squadra corrisponde.';
$lang['NoCategory'] = 'Questo sorteggio non ha ancora categorie: aggiungile nella pagina di preparazione.';
$lang['NothingDrawn'] = 'Ancora nulla di sorteggiato.';
$lang['DrawOrder'] = 'Ordine sorteggiato';
$lang['LeftToDraw'] = 'Ancora da sorteggiare';
$lang['PreviewTitle'] = 'Lo schermo pubblico adesso';

// Public screen
$lang['DrawKicker'] = 'Sorteggio';
$lang['WaitingDraw'] = 'In attesa del sorteggio…';

// Commentators' screen
$lang['SpeakerPick'] = 'Scegli una squadra nelle liste.';
$lang['NotDrawnYet'] = 'Non ancora sorteggiata';
$lang['NewInEvent'] = 'Assente da questo evento nel {$a}';
$lang['SeasonTitle'] = 'Stagione {$a}';
$lang['NotInEvent'] = 'Questa squadra non ha partecipato a questo evento nel {$a[year]}.';
$lang['AlsoIn'] = 'La società aveva una squadra anche in:';
$lang['FactRank'] = 'Piazzamento finale';
$lang['FactPoints'] = 'Punti in classifica';
$lang['FactMatches'] = 'Incontri vinti–persi';
$lang['FactAvg'] = 'Punti per freccia';
$lang['FactBest'] = 'Miglior incontro, per freccia';
$lang['FactStreak'] = 'Serie di vittorie più lunga';
$lang['FactShootOffs'] = 'Spareggi vinti–persi';
$lang['FactSets'] = 'Punti set a favore–contro';
$lang['FactForm'] = 'Ultimi incontri:';
$lang['FactStage'] = 'Tappa';
$lang['FactQualification'] = 'Qualificazione';
$lang['FactBonus'] = 'bonus {$a}';
$lang['CompositionTitle'] = 'Arcieri nel {$a}';
$lang['ColArcher'] = 'Arciere';
$lang['NationalOutdoor'] = 'Classifica nazionale all\'aperto {$a}';
$lang['NationalIndoor'] = 'Classifica nazionale al chiuso {$a}';
$lang['SpeakerNoClub'] = 'Nessun codice società per questa squadra: la sua stagione precedente non può essere trovata. Aggiungilo nella pagina di preparazione.';
$lang['Won'] = 'Vinto';
$lang['Lost'] = 'Perso';
$lang['Ordinal1'] = '{$a}º';
$lang['OrdinalN'] = '{$a}º';

// Version 0.2.0: stages, list for the ianseo competition, medals, settings page
$lang['FieldType'] = 'Tipo di lista';
$lang['TypeTeams'] = 'Squadre da sorteggiare';
$lang['TypeStages'] = 'Tappe presentate una alla volta';
$lang['StageCount'] = '{$a} tappa/e';
$lang['StageN'] = 'Tappa {$a}';
$lang['ColStageName'] = 'Luogo';
$lang['ColStageDetail'] = 'Date e dettagli';
$lang['AddStages'] = 'Aggiungi tappe — una per riga, seguita se serve da ; e dalle date';
$lang['AddStagesPlaceholder'] = 'Smarves;17 e 18 aprile 2027';
$lang['StagesHint'] = 'Qui non si sorteggia nulla: lo schermo pubblico presenta le tappe in questo ordine, una carta dopo l\'altra, man mano che lo speaker le annuncia.';
$lang['NoStage'] = 'Nessuna tappa in questa lista.';
$lang['DeleteStage'] = 'Elimina la tappa';
$lang['ShowStage'] = 'Mostra';
$lang['ShowNextStage'] = 'Mostra la tappa successiva';
$lang['HideStage'] = 'Nascondi di nuovo questa tappa';
$lang['StagesShown'] = '{$a[shown]} tappe mostrate su {$a[total]}';
$lang['AllStagesShown'] = 'Tutte le tappe sono mostrate';
$lang['StagesList'] = 'Tappe';
$lang['StageOnScreen'] = 'sullo schermo';
$lang['StageUpcoming'] = 'non ancora mostrata';
$lang['PasteTitle'] = 'Lista per la gara ianseo';
$lang['PasteHint'] = 'Codici società nell\'ordine sorteggiato. Incollali nella casella di testo sotto la colonna {$a} della schermata Setup della gara di prima divisione della nuova stagione.';
$lang['PasteHintNoEvent'] = 'Codici società nell\'ordine sorteggiato. Incollali nella casella di testo della colonna corrispondente della schermata Setup della gara di prima divisione della nuova stagione.';
$lang['PasteMissingCode'] = 'Nessun codice società per: {$a}. Aggiungilo prima nei parametri — senza di esso tutte le squadre successive salirebbero di un posto in ianseo.';
$lang['PasteIncomplete'] = 'Finora solo {$a[drawn]} posti sorteggiati su {$a[total]}';
$lang['CopyList'] = 'Copia la lista';
$lang['ListCopied'] = 'Lista copiata.';
$lang['MedalGold'] = 'Miglior valore della categoria';
$lang['MedalSilver'] = 'Secondo valore della categoria';
$lang['MedalBronze'] = 'Terzo valore della categoria';
$lang['EditLead'] = 'Ogni modifica viene salvata appena si lascia il campo: non c\'è alcun pulsante da premere. Si torna qui dalla lista dei sorteggi o dalla regia.';

// Version 0.2.1: commentators' screen
$lang['BackToLive'] = 'Torna alla diretta';

// Version 0.2.3: spacing inside a slot of the public screen
$lang['LookRankSpace'] = 'Spazio numero → squadra';
$lang['LookStatsSpace'] = 'Spazio squadra → statistiche';
$lang['LookStatGap'] = 'Spazio tra le statistiche';
$lang['LookStatWidth'] = 'Larghezza di una statistica';
