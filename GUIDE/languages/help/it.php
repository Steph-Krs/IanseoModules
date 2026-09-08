<?php
/**
 * Italian strings of the authoring documentation, the "help" section.
 *
 * Keys must mirror languages/help/en.php, which is the fallback. Only wording
 * here: the page's structure lives in admin/help.php.
 *
 * Translated without a native reviewer: worth a proofread.
 */

/* Contents and shared table headers */
$lang['HlpContents']        = 'Indice';
$lang['HlpColField']        = 'Campo';
$lang['HlpColPurpose']      = 'Ruolo';
$lang['HlpColOption']       = 'Opzione';
$lang['HlpColEffect']       = 'Effetto';
$lang['HlpColAchievement']  = 'Riconoscimento';
$lang['HlpColEarnedBy']     = 'Si ottiene';

/* 1. The idea */
$lang['HlpIdeaTitle']       = 'Il principio';
$lang['HlpIdeaP1']          = 'Un <b>corso</b> è una sequenza di <b>passi</b> mostrati in un pannello laterale, sopra qualsiasi pagina di ianseo. Ogni passo spiega un\'azione, può indicarla visivamente — pulsante evidenziato, freccia, suggerimento — e poi attende che sia stata davvero compiuta prima di sbloccare il passo successivo.';
$lang['HlpIdeaP2']          = 'Tutto si costruisce dall\'editor visuale (<b>Nuovo corso</b> o <b>Modifica</b>): non serve scrivere codice. Il JSON viene generato per te e resta accessibile agli utenti esperti nella sezione ripiegata "JSON grezzo".';

/* 2. The course */
$lang['HlpCourseTitle']     = 'Il corso';
$lang['HlpCourseFTitle']    = 'Titolo';
$lang['HlpCourseVTitle']    = 'Il nome mostrato nel catalogo e in cima al pannello.';
$lang['HlpCourseFDesc']     = 'Descrizione';
$lang['HlpCourseVDesc']     = 'Una frase breve, mostrata sotto il titolo nel catalogo.';
$lang['HlpCourseFVersion']  = 'Versione';
$lang['HlpCourseVVersion']  = 'Un numero libero, per esempio <code>1.0</code>. Se lo aumenti, gli utenti che avevano completato la versione precedente vengono segnalati come "versione precedente".';
$lang['HlpCourseFThumb']    = 'Miniatura';
$lang['HlpCourseVThumb']    = 'Un\'immagine 16:9 facoltativa, mostrata nel catalogo per riconoscere il corso a colpo d\'occhio.';
$lang['HlpCourseFId']       = 'ID';
$lang['HlpCourseVId']       = 'Un identificatore unico, generato per te. Da non cambiare una volta pubblicato il corso: è la chiave su cui viene registrato il progresso.';

/* 3. The step */
$lang['HlpStepTitle']       = 'Il passo';
$lang['HlpStepIntro']       = 'Ogni passo ha:';
$lang['HlpStepLiTitle']     = 'un <b>titolo</b>;';
$lang['HlpStepLiContent']   = 'un <b>contenuto</b>, testo formattato — vedi §4;';
$lang['HlpStepLiImage']     = 'un\'<b>immagine</b> facoltativa — vedi §5;';
$lang['HlpStepLiPage']      = 'una <b>pagina predefinita</b> e i suoi <b>trigger</b> — vedi §6;';
$lang['HlpStepLiOptions']   = 'delle <b>opzioni</b>: facoltativo e non permissivo — vedi §9.';
$lang['HlpStepOutro']       = 'Usa <b>+ Prima</b> e <b>+ Dopo</b> per inserire passi, e le frecce <b>◀ ▶</b> per spostarti fra loro. Il pannello di sinistra è un\'<b>anteprima in tempo reale</b>: quello che vedi è esattamente quello che vedrà l\'utente.';

/* 4. Writing the content */
$lang['HlpContentTitle']    = 'Scrivere il contenuto';
$lang['HlpContentIntro']    = 'La barra degli strumenti sopra l\'anteprima formatta il testo:';
$lang['HlpContentLiFormat'] = '<b>grassetto</b>, <i>corsivo</i>, <u>sottolineato</u>, colore del testo;';
$lang['HlpContentLiLists']  = 'elenchi puntati e numerati;';
$lang['HlpContentLiTip']    = '<b>💡 Consiglio</b> inserisce un riquadro giallo, per un consiglio o un avviso;';
$lang['HlpContentLiCode']   = '<b>&lt;/&gt;</b> mette il testo selezionato in stile "codice" — utile per il nome di un pulsante o un percorso.';
$lang['HlpContentTip']      = '<b>Dentro un riquadro di consiglio:</b> premi <b>Maiusc + Invio</b> per andare a capo <i>dentro</i> il riquadro. Premi <b>Invio</b> da solo per <i>uscire</i> dal riquadro e riprendere con testo normale sotto.';

/* 5. Images */
$lang['HlpImagesTitle']     = 'Le immagini';
$lang['HlpImagesLiOne']     = 'Al massimo un\'immagine per passo, più una miniatura per il corso.';
$lang['HlpImagesLiFormats'] = 'I formati usuali sono accettati, <b>GIF animate comprese</b>.';
$lang['HlpImagesLiRatio']   = 'L\'immagine è mostrata in <b>16:9</b>: un altro formato riceve semplicemente delle <b>bande nere</b>, l\'immagine non viene mai deformata né tagliata.';
$lang['HlpImagesLiAbove']   = 'L\'immagine compare <b>sopra il testo</b> del passo.';
$lang['HlpImagesLiOptional']= 'Le immagini sono <b>facoltative</b>: senza immagine il passo si mostra normalmente.';
$lang['HlpImagesNote']      = 'Le immagini sono incorporate nel corso stesso, in base64. Evita file pesanti — una GIF di parecchi megabyte rallenta il corso e la sua sincronizzazione. L\'editor ti avvisa oltre i 2 MB.';

/* 6. Triggers */
$lang['HlpTriggersTitle']   = 'I trigger';
$lang['HlpTriggersIntro']   = 'Un trigger descrive che cosa deve fare l\'utente perché il passo sia completato. Un passo può contenerne diversi: si attivano <b>nell\'ordine</b> — trascina per riordinarli. Esistono due famiglie.';
$lang['HlpTrigActionTitle'] = 'Azione — l\'utente fa qualcosa';
$lang['HlpTrigFPage']       = 'Pagina';
$lang['HlpTrigVPage']       = 'La pagina su cui avviene l\'azione. Vuoto = la pagina predefinita del passo. <code>*</code> = <b>qualsiasi pagina</b>, come serve ai menu, che esistono ovunque.';
$lang['HlpTrigFType']       = 'Tipo';
$lang['HlpTrigVType']       = 'L\'evento atteso: clic, doppio clic, cambiamento, digitazione, focus, passaggio del mouse, invio… <code>— nessuno</code> evidenzia l\'elemento e lascia che l\'utente confermi a mano.';
$lang['HlpTrigFSelector']   = 'Selettore';
$lang['HlpTrigVSelector']   = 'L\'elemento, in formato CSS — <code>#btnSave</code>, <code>.menu-item</code>. Viene evidenziato, con una freccia.';
$lang['HlpTrigFTooltip']    = 'Suggerimento';
$lang['HlpTrigVTooltip']    = 'Testo breve facoltativo mostrato accanto all\'elemento evidenziato.';
$lang['HlpTrigFRequired']   = 'Obbl.';
$lang['HlpTrigVRequired']   = 'Se spuntato, il passo non si completa finché l\'azione non è stata fatta.';
$lang['HlpTrigStateTitle']  = 'Stato — si attende che una condizione diventi vera';
$lang['HlpTrigStateP1']     = 'Invece di un\'azione, il passo attende che una <b>condizione</b> dentro ianseo sia soddisfatta — per esempio "una gara è aperta". Finché non lo è, un messaggio lo indica. La condizione viene ricontrollata ogni volta che l\'utente torna sul passo.';
$lang['HlpTrigStateP2']     = 'Una condizione integrata è sempre disponibile: <b>📍 Pagina attiva</b>. Verifica che l\'utente si trovi su una pagina data, indicata nel campo che compare. Quella pagina <b>può essere diversa</b> da quella del passo. Finché l\'utente non ci arriva, il passo resta bloccato e compare il collegamento "Vai alla pagina →".';
$lang['HlpTrigStateP3']     = 'L\'altra condizione integrata è <b>🔎 Presenza o assenza di un elemento</b>. Si indica un <b>selettore CSS</b> — funzionano anche i selettori per prefisso <code>[id^="…"]</code> — e si dice se l\'elemento deve essere <b>presente</b> o <b>assente</b>. Utile per attendere un messaggio di conferma, l\'apertura di una finestra, la scomparsa di un indicatore di caricamento. Questa condizione viene <b>ricontrollata automaticamente</b>, perché un elemento del genere può comparire o sparire senza che la pagina si ricarichi.';
$lang['HlpTrigStarNote']    = '<b>La pagina <code>*</code></b> funziona sia come pagina predefinita del passo sia su ogni trigger singolarmente. Un trigger impostato su <code>*</code> resta attivo qualunque pagina sia a schermo.';
$lang['HlpBranchTitle']     = 'Rami condizionali (⎇ Attivo se…)';
$lang['HlpBranchIntro']     = 'Ogni trigger, di azione o di stato, porta una condizione di attivazione <b>⎇</b>. Per impostazione predefinita un trigger è <b>sempre attivo</b>. Lo si può rendere condizionale:';
$lang['HlpBranchLiIf']      = '<b>se</b> una condizione è soddisfatta — "se: una gara è aperta";';
$lang['HlpBranchLiIfNot']   = '<b>se NON</b> una condizione — "se NON: una gara è aperta".';
$lang['HlpBranchP']         = 'Un trigger la cui condizione non è soddisfatta viene <b>saltato</b>, e la sequenza passa al successivo. È così che un corso può prendere <b>strade diverse</b> a seconda dello stato di ianseo e poi tornare a una sequenza comune:';
$lang['HlpBranchTip']       = 'Per esempio: "se NON: una gara è aperta" su un trigger che guida la creazione di una gara; i trigger successivi, <b>senza condizione</b>, sono comuni ai due casi. Per un "altrimenti", metti due trigger — uno <i>se X</i>, l\'altro <i>se NON X</i>.';

/* 7. Finding a CSS selector */
$lang['HlpSelectorsTitle']  = 'Trovare un selettore CSS';
$lang['HlpSelIntro']        = 'Per puntare a un elemento — pulsante, campo, collegamento:';
$lang['HlpSelLiInspect']    = 'Sulla pagina interessata, fai <b>clic destro</b> sull\'elemento e scegli <b>Ispeziona</b> (o premi <b>F12</b>).';
$lang['HlpSelLiId']         = 'Individua il suo <code>id</code>, per esempio <code>id="btnSave"</code>: il selettore è <code>#btnSave</code>.';
$lang['HlpSelLiClass']      = 'Senza <code>id</code>, usa una classe — <code>class="btn-primary"</code> dà <code>.btn-primary</code>.';
$lang['HlpSelLiStable']     = 'Preferisci sempre qualcosa di <b>stabile e unico</b> nella pagina.';
$lang['HlpSelTip']          = 'Nell\'ispettore, clic destro sulla riga dell\'elemento, poi <b>Copia</b> → <b>Copia selettore</b>.';
$lang['HlpSelDynTitle']     = 'Gli id numerati, che cambiano ogni volta';
$lang['HlpSelDynP1']        = 'Alcuni id di ianseo portano un numero di record che cambia con il partecipante o con la gara, come <code>#d_q_QuSession_25360</code> o <code>#d_QuD1Score_25360</code>. Un selettore esatto funzionerebbe una volta sola. Usa un <b>selettore per prefisso</b>:';
$lang['HlpSelDynLiPrefix']  = '<code>[id^="d_q_QuSession_"]</code> — un id che <b>inizia con</b> questo prefisso;';
$lang['HlpSelDynLiOther']   = '<code>[id$="_suffisso"]</code> — che finisce con; <code>[id*="parte"]</code> — che contiene.';
$lang['HlpSelDynP2']        = 'Il trigger si attiva allora su <b>qualsiasi</b> elemento corrispondente — la casella Sessione di un partecipante qualunque, per esempio — e la freccia indica il primo trovato. Il <b>registratore di trigger</b> riconosce da solo questi id numerati e scrive il selettore per prefisso al posto tuo.';

/* 8. Recording triggers */
$lang['HlpRecordTitle']     = 'Registrare i trigger automaticamente';
$lang['HlpRecIntro']        = 'Invece di digitare i selettori a mano, <b>🔴 Registra i trigger</b>, nelle opzioni del passo, li cattura cliccando direttamente in ianseo:';
$lang['HlpRecLiSaved']      = 'Il corso viene <b>prima salvato</b>, poi vieni portato alla pagina del passo (o alla home).';
$lang['HlpRecLiPanel']      = 'Compare un <b>pannello rosso</b>. <b>Ogni clic</b> che fai in ianseo viene registrato come trigger, e il clic funziona normalmente — puoi navigare fra le pagine, la registrazione continua.';
$lang['HlpRecLiPage']       = '<b>📍 Pagina attiva</b> aggiunge un trigger di stato che verifica la presenza sulla pagina in cui ti trovi.';
$lang['HlpRecLiPause']      = '<b>⏸ Pausa</b> sospende la cattura, per cliccare senza registrare. <b>↶ Annulla</b> toglie l\'ultimo trigger.';
$lang['HlpRecLiFinish']     = '<b>✓ Termina</b> torna all\'editor e aggiunge quanto catturato. <b>✕</b> abbandona.';
$lang['HlpRecTip']          = 'Poi <b>rileggi i trigger</b> — tipo, suggerimento, obbligatorio — sistema quello che serve e <b>salva il corso</b>. I selettori generati sono robusti ma non infallibili sugli elementi molto dinamici.';

/* 9. Step options */
$lang['HlpOptionsTitle']    = 'Opzioni del passo';
$lang['HlpOptFOptional']    = 'Facoltativo';
$lang['HlpOptVOptional']    = 'Mostra un pulsante "Segna come fatto": l\'utente può completare il passo senza compiere l\'azione.';
$lang['HlpOptFStrict']      = 'Non permissivo';
$lang['HlpOptVStrict']      = 'Blocca ogni clic <b>fuori</b> dall\'elemento atteso: l\'utente può interagire solo con il bersaglio. Il pannello lampeggia in rosso quando un clic viene bloccato.';

/* 10. Quiz and challenge */
$lang['HlpActivitiesTitle'] = 'Quiz e sfida — bronzo, argento e oro';
$lang['HlpActIntro']        = 'Un corso può proporre fino a <b>tre attività</b>: la <b>guida</b> passo passo, un <b>quiz</b> e una <b>sfida</b>. Alla fine della guida l\'utente è invitato a proseguire con il quiz, poi la sfida, poi il corso successivo. Ogni attività superata fa salire il riconoscimento:';
$lang['HlpActBronze']       = 'Completando la guida';
$lang['HlpActSilver']       = 'La guida più un\'altra attività';
$lang['HlpActGold']         = 'Tutte le attività proposte dal corso';
$lang['HlpActLiQuiz']       = '<b>Quiz</b> (sezione "📝 Quiz" dell\'editor): domande con da due a quattro scelte, <b>una o più</b> risposte corrette spuntate — l\'utente deve allora selezionare esattamente l\'insieme giusto — una spiegazione facoltativa, un punteggio minimo (70% per impostazione predefinita) e un\'opzione per mostrare le risposte in <b>ordine casuale</b>.';
$lang['HlpActLiChallenge']  = '<b>Sfida</b> (sezione "🎯 Sfida"): una consegna e delle <b>condizioni di stato</b> che verificano il risultato dentro ianseo, con l\'utente che agisce senza alcun aiuto. Le condizioni si creano nel <b>costruttore di condizioni</b> (pulsante ⚡ dell\'amministrazione), che sa provarle dal vivo sulla gara aperta.';
$lang['HlpActShipped']      = 'Le condizioni fornite con il modulo coprono lo svolgimento tipico di una gara:';
$lang['HlpActLiState']      = '<b>almeno un partecipante iscritto</b>, <b>almeno un giudice dichiarato</b>, <b>almeno un bersaglio assegnato</b>, <b>almeno un punteggio inserito</b> — tutte verificate sulla gara aperta;';
$lang['HlpActLiVisited']    = '<b>una data pagina è stata visitata</b> — il controllo "<b>Pagina visitata</b>" del costruttore. Una visita è ricordata <b>per utente e per gara</b>; spunta "qualsiasi gara" per una visita globale. Ideale per una sfida del tipo "invia i tuoi risultati alla federazione".';

/* 11. Learning path and the other content types */
$lang['HlpPathTitle']       = 'Percorso, liste di controllo, risoluzione dei problemi, aiuto contestuale';
$lang['HlpPathLiPath']      = '<b>Percorso</b>: i campi <b>Gruppo</b>, <b>Sottogruppo</b> e <b>Ordine</b> organizzano il catalogo in sezioni ordinate. Il "corso successivo" proposto alla fine segue quest\'ordine.';
$lang['HlpPathLiChecklist'] = '<b>Liste di controllo</b>: qualche domanda a pulsanti, poi un elenco di compiti adattato alle risposte. Le voci con una condizione si spuntano da sole. Creazione con "+ Lista di controllo" (modifica JSON).';
$lang['HlpPathLiTrouble']   = '<b>Risoluzione dei problemi</b>: un albero domanda → risposte → soluzione. Creazione con "+ Risoluzione dei problemi" (modifica JSON).';
$lang['HlpPathLiContext']   = '<b>Aiuto contestuale</b>: attivo per impostazione predefinita. Il pulsante flottante 🎯 mostra un pallino arancione quando esiste un contenuto per la pagina ianseo visualizzata. Disattivabile dal catalogo o dal pannello.';

/* 12. User accounts */
$lang['HlpAccountsTitle']   = 'Account utente (server condiviso)';
$lang['HlpAccountsP']       = 'Quando il server usa un modulo di account, <b>ogni account ha il proprio progresso</b>: corsi in corso, passi completati, quiz, sfide e riconoscimenti sono registrati per utente, senza toccare gli altri. Il banner "Impara a usare ianseo" compare per un account che non vede <b>nessuna gara</b>, segno di un nuovo organizzatore. I <b>corsi</b> restano comuni a tutto il server. Senza modulo di account nulla cambia: il progresso è semplicemente quello dell\'installazione.';

/* 13. Translating a course */
$lang['HlpTranslateTitle']  = 'Tradurre un corso';
$lang['HlpTransP1']         = 'Un corso porta tutte le lingue in cui è stato scritto <b>dentro il proprio file</b>: resta un solo documento con un solo numero di versione, e il meccanismo di aggiornamento ha una cosa sola da confrontare.';
$lang['HlpTransLiBase']     = '<b>Lingua originale</b> indica in che lingua è stato scritto il corso — la lingua di tutti i campi non ancora tradotti. La lingua di modifica non può rispondere a questa domanda: dice dove stai scrivendo ora, non che cosa è già il testo esistente. Senza di essa, la prima traduzione di un campo archivierebbe il testo originale sotto la lingua che stavi scrivendo, e il corso porterebbe il proprio originale come traduzione. Vale per impostazione predefinita la tua lingua e va cambiata solo se riprendi un corso scritto da qualcun altro.';
$lang['HlpTransLiEdit']     = '<b>Lingua di modifica</b> sceglie quale lingua i campi mostrano e scrivono. Cambiarla non tocca le altre lingue.';
$lang['HlpTransLiEmpty']    = 'Un campo non ancora tradotto appare <b>vuoto</b>, non con il testo originale — così quello che manca si vede a colpo d\'occhio.';
$lang['HlpTransNote']       = 'Solo il <b>testo</b> di un campo viene tradotto. La struttura attorno — passi, trigger, selettori, pagine — non è mai duplicata, il che impedisce alle lingue di divergere. Un corso la cui versione tradotta indicasse un pulsante diverso dall\'originale sarebbe peggio di un corso senza traduzione.';
$lang['HlpTransP2']         = 'Il lettore ottiene il corso nella lingua della propria interfaccia. In mancanza, l\'inglese; in mancanza, la lingua in cui il corso è stato scritto — un corso tradotto in una sola lingua viene quindi comunque servito, mai mostrato vuoto. Una lingua regionale vale come la lingua madre lungo il percorso: un lettore in francese canadese ottiene il testo francese prima che si consideri l\'inglese.';
