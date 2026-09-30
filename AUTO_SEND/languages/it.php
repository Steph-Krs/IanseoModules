<?php
/**
 * Italian strings of the scheduled upload module. Keys missing here fall back
 * to the English file, which holds the reference list.
 */

$lang['ModuleName'] = 'Invio programmato';
$lang['MenuSettings'] = 'Impostazioni e stato';
$lang['MenuUpdate'] = 'Aggiorna il modulo';
$lang['UpdateTitle'] = 'Invio programmato — aggiornamento';
$lang['BackToSettings'] = 'Torna alle impostazioni';

$lang['PageTitle'] = 'Invio programmato a ianseo.net';
$lang['PageLead'] = 'Apre e chiude l\'inserimento dei punteggi e invia i risultati a ianseo.net da solo, agli orari impostati qui sotto, senza un computer lasciato sulla pagina di invio. Il lavoro è svolto da un\'attività che il server esegue ogni minuto.';

$lang['WarnNoCredentials'] = 'I codici ianseo.net di questa gara non sono salvati: nessun invio è possibile. Inseriteli spuntando la casella «ricorda»:';
$lang['WarnSimulation'] = 'Modalità simulazione: i risultati vengono costruiti come per un invio reale, ma nulla viene inviato a ianseo.net. Toglietela qui sotto prima del periodo reale.';

$lang['StatusTitle'] = 'Stato';
$lang['Loading'] = 'Caricamento…';

$lang['PeriodTitle'] = 'Periodo';
$lang['Enabled'] = 'Programmazione attiva';
$lang['EnabledHint'] = 'Se non spuntata, nulla avviene automaticamente e l\'inserimento resta com\'è.';
$lang['Start'] = 'Inizio';
$lang['End'] = 'Fine';
$lang['TimeZone'] = 'Fuso orario';
$lang['Interval'] = 'Invio ogni';
$lang['Minutes'] = 'minuti';
$lang['PeriodSaved'] = 'Periodo salvato: dal {$a[start]} al {$a[end]}.';
$lang['TimeZoneHint'] = 'Gli orari sono quelli del fuso scelto, cambi d\'ora compresi: un periodo che inizia con l\'ora solare e finisce con l\'ora legale mantiene gli orari inseriti qui. Un orario che non esiste nella notte del cambio (02:00–03:00 in primavera) viene rifiutato.';
$lang['IntervalHint'] = 'Almeno {$a} minuti. Dopo un errore il tentativo successivo arriva prima (1, 2, 4… minuti), e il ritmo normale riprende al primo successo.';

$lang['SessionsTitle'] = 'Inserimento dei punteggi (ISK-NG)';
$lang['SessionsNoRight'] = 'I vostri permessi non consentono di gestire l\'inserimento ISK-NG: queste impostazioni sono in sola lettura.';
$lang['SessionsManage'] = 'Aprire l\'inserimento all\'inizio e chiuderlo alla fine';
$lang['SessionsHint'] = 'Le sessioni spuntate vengono aperte all\'inizio e chiuse alla fine, una sola volta ciascuna: una sessione bloccata a mano durante il periodo resta bloccata. Alla fine l\'inserimento viene chiuso prima dell\'ultimo invio, perché contenga tutti i punteggi inseriti.';
$lang['SessionsNone'] = 'Ancora nessuna sessione di inserimento in questa gara.';

$lang['ItemsTitle'] = 'Cosa viene inviato';
$lang['ItemsHint'] = 'Le stesse liste della pagina di invio di ianseo. Un elemento indicato «non ancora disponibile» viene inviato non appena ianseo lo propone (tabelloni una volta risolti gli spareggi, medaglie una volta assegnate).';
$lang['ItemWaiting'] = '(non ancora disponibile)';
$lang['ItemMissing'] = '(non esiste più nella gara)';

$lang['PingTitle'] = 'Sorveglianza esterna (facoltativa)';
$lang['PingUrl'] = 'Indirizzo di sorveglianza (healthchecks.io o compatibile)';
$lang['PingHint'] = 'Il server chiama questo indirizzo dopo ogni invio e almeno una volta per intervallo. Se le chiamate si fermano — server spento, rete interrotta, invii in errore — il servizio di sorveglianza vi avvisa via e-mail o SMS. Nulla viene inviato in simulazione. Vedere il README del modulo.';

$lang['SimulationTitle'] = 'Simulazione';
$lang['Simulation'] = 'Simulazione: non inviare nulla a ianseo.net';
$lang['SimulationHint'] = 'Tutto avviene davvero — orari, inserimento, costruzione dei risultati — tranne l\'invio stesso, che viene imitato. Utile per verificare le impostazioni e misurare il carico sul server prima del periodo reale.';

$lang['Save'] = 'Salva';

$lang['TaskTitle'] = 'Attività pianificata sul server';
$lang['TaskExplain'] = 'PHP viene eseguito solo quando una pagina viene richiesta: nulla dentro ianseo può svegliarsi da solo a un orario dato. Il pianificatore del server esegue quindi l\'attività del modulo ogni minuto; questa guarda cosa è dovuto (apertura, invio, chiusura) e si ferma subito quando non c\'è nulla da fare. Senza questa attività non succede nulla.';
$lang['TaskInstallLinux'] = 'Da installare una volta, come amministratore del server (Linux, server web con l\'utente www-data):';
$lang['TaskInstallWindows'] = 'Da installare una volta, in un prompt dei comandi aperto come amministratore:';
$lang['TaskReadme'] = 'Il README del modulo spiega come verificare che l\'attività giri e come rimuoverla.';

$lang['HeartbeatOk'] = 'L\'attività pianificata è in funzione.';
$lang['HeartbeatNever'] = 'L\'attività pianificata non è mai stata eseguita su questo server: vedere «Attività pianificata sul server» più in basso.';
$lang['HeartbeatLate'] = 'L\'attività pianificata non viene eseguita da {$a} minuti.';

$lang['PhaseDisabled'] = 'Programmazione inattiva.';
$lang['PhaseIncomplete'] = 'Programmazione incompleta: manca l\'inizio o la fine.';
$lang['PhaseWaiting'] = 'In attesa dell\'inizio, il {$a}.';
$lang['PhaseRunning'] = 'Periodo in corso, fino al {$a}.';
$lang['PhaseFinishing'] = 'Periodo terminato: ultimo invio in corso.';
$lang['PhaseDone'] = 'Periodo terminato dal {$a}.';

$lang['KindSend'] = 'Invio';
$lang['KindManual'] = 'Invio richiesto';
$lang['KindFinal'] = 'Ultimo invio';
$lang['KindOpen'] = 'Inserimento aperto';
$lang['KindClose'] = 'Inserimento chiuso';

$lang['OutSent'] = 'Inviato a ianseo.net.';
$lang['OutSentSimulated'] = 'Simulato: costruito, nulla inviato.';
$lang['OutNothingYet'] = 'Ancora nulla da inviare: gli elementi scelti non sono disponibili.';
$lang['OutSessionsOpened'] = 'Inserimento aperto:';
$lang['OutSessionsClosed'] = 'Inserimento chiuso:';
$lang['OutErrNoPlan'] = 'Nessuna programmazione per questa gara.';
$lang['OutErrNoCompetition'] = 'La gara non esiste più.';
$lang['OutErrNothingSelected'] = 'Non è stato scelto nulla da inviare.';
$lang['OutErrNoCredentials'] = 'I codici ianseo.net della gara non sono salvati.';
$lang['OutErrPublicationLocked'] = 'La pubblicazione è bloccata per questa gara.';
$lang['OutErrCredentialsCheck'] = 'ianseo.net irraggiungibile, o codici rifiutati:';
$lang['OutErrIanseoNet'] = 'ianseo.net ha rifiutato l\'invio:';
$lang['OutErrWorker'] = 'L\'invio si è interrotto con un errore:';
$lang['OutErrTimeout'] = 'L\'invio è durato più di {$a} secondi ed è stato interrotto.';
$lang['OutErrStart'] = 'Non è stato possibile avviare l\'invio sul server.';

$lang['ErrToken'] = 'Il modulo è scaduto: ricaricate la pagina e riprovate.';
$lang['ErrAccess'] = 'Accesso negato.';
$lang['ErrTimeZone'] = 'Fuso orario sconosciuto.';
$lang['ErrDate'] = 'data o ora non valida.';
$lang['ErrTimeGap'] = 'questo orario non esiste in questo fuso (cambio d\'ora).';
$lang['ErrEndBeforeStart'] = 'La fine deve venire dopo l\'inizio.';
$lang['ErrInterval'] = 'L\'intervallo deve essere compreso tra {$a[min]} e {$a[max]} minuti.';
$lang['ErrPingUrl'] = 'L\'indirizzo di sorveglianza deve essere un indirizzo web che inizia con https:// o http://.';
$lang['ErrDatesRequired'] = 'Una programmazione attiva richiede un inizio e una fine.';
$lang['ErrEndPast'] = 'La fine è già passata.';
$lang['ErrNoSession'] = 'Spuntate almeno una sessione di inserimento, oppure togliete l\'apertura e la chiusura dell\'inserimento.';
$lang['ErrRunArchery'] = 'Le gare di Run Archery non sono gestite da questo modulo.';

$lang['SavedDisabled'] = 'Salvato. La programmazione è inattiva.';
$lang['SavedRunning'] = 'Salvato. Il periodo è iniziato: l\'attività pianificata agisce entro un minuto.';
$lang['SavedWaiting'] = 'Salvato. L\'attività pianificata agirà all\'inizio del periodo.';

$lang['JsLoadError'] = 'Impossibile leggere lo stato.';
$lang['JsTask'] = 'Attività';
$lang['JsSimulation'] = 'simulazione';
$lang['JsLastSuccess'] = 'Ultimo invio riuscito:';
$lang['JsNever'] = 'mai';
$lang['JsNextSend'] = 'Prossimo invio:';
$lang['JsFailures'] = 'Errori consecutivi:';
$lang['JsSessions'] = 'Inserimento:';
$lang['JsOpen'] = 'aperto';
$lang['JsClosed'] = 'chiuso';
$lang['JsSendNow'] = 'Invia ora';
$lang['JsSendNowPending'] = 'Invio richiesto…';
$lang['JsSendNowHint'] = 'L\'attività pianificata lo esegue entro un minuto, imitato se la simulazione è spuntata.';
$lang['JsHistory'] = 'Cronologia';
$lang['JsNoRun'] = 'Ancora nulla di fatto.';
$lang['JsWhen'] = 'Quando';
$lang['JsKind'] = 'Azione';
$lang['JsResult'] = 'Esito';
$lang['JsDuration'] = 'Durata';
$lang['JsSize'] = 'Dimensione';
$lang['JsDetail'] = 'Dettaglio';
$lang['JsOk'] = 'OK';
$lang['JsKo'] = 'errore';
$lang['JsAll'] = 'tutti';
$lang['JsNone'] = 'nessuno';
