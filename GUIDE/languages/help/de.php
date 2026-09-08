<?php
/**
 * German strings of the authoring documentation, the "help" section.
 *
 * Keys must mirror languages/help/en.php, which is the fallback. Only wording
 * here: the page's structure lives in admin/help.php.
 *
 * Translated without a native reviewer: worth a proofread.
 */

/* Contents and shared table headers */
$lang['HlpContents']        = 'Inhalt';
$lang['HlpColField']        = 'Feld';
$lang['HlpColPurpose']      = 'Zweck';
$lang['HlpColOption']       = 'Option';
$lang['HlpColEffect']       = 'Wirkung';
$lang['HlpColAchievement']  = 'Auszeichnung';
$lang['HlpColEarnedBy']     = 'Erreicht durch';

/* 1. The idea */
$lang['HlpIdeaTitle']       = 'Die Idee';
$lang['HlpIdeaP1']          = 'Ein <b>Kurs</b> ist eine Folge von <b>Schritten</b>, die in einem Seitenbereich über jeder ianseo-Seite erscheint. Jeder Schritt erklärt eine Aktion, kann sie sichtbar zeigen — hervorgehobene Schaltfläche, Pfeil, Kurzhinweis — und wartet dann, bis die Aktion wirklich ausgeführt wurde, bevor der nächste Schritt freigegeben wird.';
$lang['HlpIdeaP2']          = 'Alles entsteht im visuellen Editor (<b>Neuer Kurs</b> oder <b>Bearbeiten</b>): kein Code nötig. Das JSON wird für Sie erzeugt und bleibt für erfahrene Anwender im eingeklappten Bereich „JSON-Quelltext" erreichbar.';

/* 2. The course */
$lang['HlpCourseTitle']     = 'Der Kurs';
$lang['HlpCourseFTitle']    = 'Titel';
$lang['HlpCourseVTitle']    = 'Der Name im Katalog und oben im Seitenbereich.';
$lang['HlpCourseFDesc']     = 'Beschreibung';
$lang['HlpCourseVDesc']     = 'Ein kurzer Satz, im Katalog unter dem Titel.';
$lang['HlpCourseFVersion']  = 'Version';
$lang['HlpCourseVVersion']  = 'Eine frei wählbare Nummer, etwa <code>1.0</code>. Erhöhen Sie sie, gelten Benutzer, die die vorherige Fassung abgeschlossen hatten, als „vorherige Version".';
$lang['HlpCourseFThumb']    = 'Vorschaubild';
$lang['HlpCourseVThumb']    = 'Ein optionales Bild im Format 16:9, im Katalog gezeigt, damit ein Kurs auf einen Blick erkennbar ist.';
$lang['HlpCourseFId']       = 'ID';
$lang['HlpCourseVId']       = 'Eine eindeutige Kennung, automatisch erzeugt. Nach der Veröffentlichung nicht mehr ändern: Der Fortschritt wird darunter gespeichert.';

/* 3. The step */
$lang['HlpStepTitle']       = 'Der Schritt';
$lang['HlpStepIntro']       = 'Jeder Schritt besitzt:';
$lang['HlpStepLiTitle']     = 'einen <b>Titel</b>;';
$lang['HlpStepLiContent']   = 'einen <b>Inhalt</b>, formatierter Text — siehe §4;';
$lang['HlpStepLiImage']     = 'ein optionales <b>Bild</b> — siehe §5;';
$lang['HlpStepLiPage']      = 'eine <b>Standardseite</b> und ihre <b>Auslöser</b> — siehe §6;';
$lang['HlpStepLiOptions']   = '<b>Optionen</b>: optional und nicht nachgiebig — siehe §9.';
$lang['HlpStepOutro']       = 'Mit <b>+ Davor</b> und <b>+ Danach</b> fügen Sie Schritte ein, mit den Pfeilen <b>◀ ▶</b> wechseln Sie zwischen ihnen. Der linke Bereich ist eine <b>Live-Vorschau</b>: Was Sie sehen, sieht der Benutzer genauso.';

/* 4. Writing the content */
$lang['HlpContentTitle']    = 'Den Inhalt schreiben';
$lang['HlpContentIntro']    = 'Die Werkzeugleiste über der Vorschau formatiert den Text:';
$lang['HlpContentLiFormat'] = '<b>fett</b>, <i>kursiv</i>, <u>unterstrichen</u>, Textfarbe;';
$lang['HlpContentLiLists']  = 'Aufzählungen und nummerierte Listen;';
$lang['HlpContentLiTip']    = '<b>💡 Hinweis</b> fügt einen gelben Kasten ein, für einen Rat oder eine Warnung;';
$lang['HlpContentLiCode']   = '<b>&lt;/&gt;</b> stellt den markierten Text im Stil „Code" dar — nützlich für einen Schaltflächennamen oder einen Pfad.';
$lang['HlpContentTip']      = '<b>Innerhalb eines Hinweiskastens:</b> <b>Umschalt + Eingabe</b> beginnt eine neue Zeile <i>im</i> Kasten. <b>Eingabe</b> allein <i>verlässt</i> den Kasten und setzt darunter mit normalem Text fort.';

/* 5. Images */
$lang['HlpImagesTitle']     = 'Bilder';
$lang['HlpImagesLiOne']     = 'Höchstens ein Bild je Schritt, dazu ein Vorschaubild für den Kurs.';
$lang['HlpImagesLiFormats'] = 'Die üblichen Formate werden angenommen, <b>animierte GIFs eingeschlossen</b>.';
$lang['HlpImagesLiRatio']   = 'Das Bild erscheint im Format <b>16:9</b>: ein anderes Seitenverhältnis erhält schlicht <b>schwarze Balken</b>, das Bild wird nie verzerrt oder beschnitten.';
$lang['HlpImagesLiAbove']   = 'Das Bild steht <b>über dem Text</b> des Schrittes.';
$lang['HlpImagesLiOptional']= 'Bilder sind <b>optional</b>: ohne Bild wird der Schritt normal angezeigt.';
$lang['HlpImagesNote']      = 'Bilder liegen als base64 im Kurs selbst. Vermeiden Sie große Dateien — ein GIF von mehreren Megabyte macht den Kurs und seine Synchronisierung langsam. Der Editor warnt ab 2 MB.';

/* 6. Triggers */
$lang['HlpTriggersTitle']   = 'Auslöser';
$lang['HlpTriggersIntro']   = 'Ein Auslöser beschreibt, was der Benutzer tun muss, damit der Schritt abgeschlossen ist. Ein Schritt kann mehrere enthalten; sie greifen <b>der Reihe nach</b> — zum Umsortieren ziehen. Es gibt zwei Familien.';
$lang['HlpTrigActionTitle'] = 'Aktion — der Benutzer tut etwas';
$lang['HlpTrigFPage']       = 'Seite';
$lang['HlpTrigVPage']       = 'Die Seite, auf der die Aktion geschieht. Leer bedeutet die Standardseite des Schrittes. <code>*</code> bedeutet <b>jede Seite</b> — was Menüs brauchen, da es sie überall gibt.';
$lang['HlpTrigFType']       = 'Typ';
$lang['HlpTrigVType']       = 'Das erwartete Ereignis: Klick, Doppelklick, Änderung, Eingabe, Fokus, Überfahren, Absenden… <code>— keines</code> hebt das Element nur hervor und lässt den Benutzer von Hand bestätigen.';
$lang['HlpTrigFSelector']   = 'Selektor';
$lang['HlpTrigVSelector']   = 'Das Element als CSS-Selektor — <code>#btnSave</code>, <code>.menu-item</code>. Es wird hervorgehoben, mit einem Pfeil.';
$lang['HlpTrigFTooltip']    = 'Kurzhinweis';
$lang['HlpTrigVTooltip']    = 'Optionaler kurzer Text neben dem hervorgehobenen Element.';
$lang['HlpTrigFRequired']   = 'Pflicht';
$lang['HlpTrigVRequired']   = 'Ist das Feld angehakt, gilt der Schritt erst nach der Aktion als erledigt.';
$lang['HlpTrigStateTitle']  = 'Zustand — auf eine Bedingung warten';
$lang['HlpTrigStateP1']     = 'Statt einer Aktion wartet der Schritt darauf, dass eine <b>Bedingung</b> in ianseo erfüllt ist — etwa „ein Wettkampf ist geöffnet". Solange sie es nicht ist, weist eine Meldung darauf hin. Die Bedingung wird bei jeder Rückkehr zum Schritt erneut geprüft.';
$lang['HlpTrigStateP2']     = 'Eine eingebaute Bedingung steht immer bereit: <b>📍 Aktuelle Seite</b>. Sie prüft, ob der Benutzer sich auf einer bestimmten Seite befindet, die im erscheinenden Feld genannt wird. Diese Seite <b>darf sich unterscheiden</b> von der des Schrittes. Bis der Benutzer dort ist, bleibt der Schritt gesperrt und der Verweis „Zur Seite →" wird gezeigt.';
$lang['HlpTrigStateP3']     = 'Die zweite eingebaute Bedingung ist <b>🔎 Vorhandensein oder Fehlen eines Elements</b>. Sie geben einen <b>CSS-Selektor</b> an — Präfix-Selektoren wie <code>[id^="…"]</code> gehen hier ebenfalls — und legen fest, ob das Element <b>vorhanden</b> oder <b>abwesend</b> sein muss. Nützlich, um auf eine Bestätigungsmeldung, ein sich öffnendes Fenster oder das Verschwinden einer Ladeanzeige zu warten. Diese Bedingung wird <b>selbsttätig erneut geprüft</b>, denn ein solches Element kann erscheinen oder verschwinden, ohne dass die Seite neu lädt.';
$lang['HlpTrigStarNote']    = '<b>Die Seite <code>*</code></b> wirkt sowohl als Standardseite des Schrittes als auch je Auslöser einzeln. Ein auf <code>*</code> gesetzter Auslöser bleibt aktiv, gleich welche Seite gerade angezeigt wird.';
$lang['HlpBranchTitle']     = 'Bedingte Zweige (⎇ Aktiv wenn…)';
$lang['HlpBranchIntro']     = 'Jeder Auslöser, ob Aktion oder Zustand, trägt eine Aktivierungsbedingung <b>⎇</b>. Standardmäßig ist ein Auslöser <b>immer aktiv</b>. Er lässt sich bedingt machen:';
$lang['HlpBranchLiIf']      = '<b>wenn</b> eine Bedingung erfüllt ist — „wenn: ein Wettkampf ist geöffnet";';
$lang['HlpBranchLiIfNot']   = '<b>wenn NICHT</b> eine Bedingung — „wenn NICHT: ein Wettkampf ist geöffnet".';
$lang['HlpBranchP']         = 'Ein Auslöser, dessen Bedingung nicht erfüllt ist, wird <b>übersprungen</b>, und die Folge geht zum nächsten über. So kann ein Kurs je nach Zustand von ianseo <b>verschiedene Wege</b> nehmen und danach wieder in eine gemeinsame Folge münden:';
$lang['HlpBranchTip']       = 'Zum Beispiel: „wenn NICHT: ein Wettkampf ist geöffnet" an einem Auslöser, der durch das Anlegen eines Wettkampfs führt; die folgenden Auslöser, <b>ohne Bedingung</b>, gelten für beide Fälle. Für ein „sonst" nehmen Sie zwei Auslöser — einen <i>wenn X</i>, den anderen <i>wenn NICHT X</i>.';

/* 7. Finding a CSS selector */
$lang['HlpSelectorsTitle']  = 'Einen CSS-Selektor finden';
$lang['HlpSelIntro']        = 'Um ein Element anzusprechen — Schaltfläche, Feld, Verweis:';
$lang['HlpSelLiInspect']    = 'Auf der betreffenden Seite <b>rechts klicken</b> und <b>Untersuchen</b> wählen (oder <b>F12</b> drücken).';
$lang['HlpSelLiId']         = 'Suchen Sie die <code>id</code>, etwa <code>id="btnSave"</code>: der Selektor lautet <code>#btnSave</code>.';
$lang['HlpSelLiClass']      = 'Ohne <code>id</code> nehmen Sie eine Klasse — <code>class="btn-primary"</code> ergibt <code>.btn-primary</code>.';
$lang['HlpSelLiStable']     = 'Bevorzugen Sie stets etwas <b>Stabiles und Eindeutiges</b> auf der Seite.';
$lang['HlpSelTip']          = 'Im Inspektor auf die Zeile des Elements rechts klicken, dann <b>Kopieren</b> → <b>Selektor kopieren</b>.';
$lang['HlpSelDynTitle']     = 'Nummerierte Kennungen, die sich jedes Mal ändern';
$lang['HlpSelDynP1']        = 'Manche Kennungen in ianseo tragen eine Datensatznummer, die sich mit Teilnehmer oder Wettkampf ändert, etwa <code>#d_q_QuSession_25360</code> oder <code>#d_QuD1Score_25360</code>. Ein genauer Selektor funktionierte nur ein einziges Mal. Nehmen Sie einen <b>Präfix-Selektor</b>:';
$lang['HlpSelDynLiPrefix']  = '<code>[id^="d_q_QuSession_"]</code> — eine Kennung, die mit diesem Präfix <b>beginnt</b>;';
$lang['HlpSelDynLiOther']   = '<code>[id$="_suffix"]</code> — endet mit; <code>[id*="teil"]</code> — enthält.';
$lang['HlpSelDynP2']        = 'Der Auslöser greift dann bei <b>jedem</b> passenden Element — etwa dem Feld Durchgang eines beliebigen Teilnehmers — und der Pfeil zeigt auf das erste gefundene. Der <b>Auslöser-Rekorder</b> erkennt solche nummerierten Kennungen selbst und schreibt den Präfix-Selektor für Sie.';

/* 8. Recording triggers */
$lang['HlpRecordTitle']     = 'Auslöser selbsttätig aufzeichnen';
$lang['HlpRecIntro']        = 'Statt Selektoren von Hand einzutippen, nimmt <b>🔴 Auslöser aufzeichnen</b> in den Schritt-Optionen sie durch Klicken unmittelbar in ianseo auf:';
$lang['HlpRecLiSaved']      = 'Der Kurs wird <b>zuerst gespeichert</b>, dann gelangen Sie auf die Seite des Schrittes (oder auf die Startseite).';
$lang['HlpRecLiPanel']      = 'Ein <b>roter Bereich</b> erscheint. <b>Jeder Klick</b> in ianseo wird als Auslöser aufgezeichnet, und der Klick wirkt weiterhin normal — Sie können zwischen Seiten wechseln, die Aufzeichnung läuft weiter.';
$lang['HlpRecLiPage']       = '<b>📍 Aktuelle Seite</b> fügt einen Zustandsauslöser hinzu, der das Vorhandensein auf der gerade gezeigten Seite prüft.';
$lang['HlpRecLiPause']      = '<b>⏸ Pause</b> unterbricht die Aufnahme, sodass Sie klicken können, ohne aufzuzeichnen. <b>↶ Rückgängig</b> entfernt den letzten Auslöser.';
$lang['HlpRecLiFinish']     = '<b>✓ Beenden</b> kehrt zum Editor zurück und übernimmt das Aufgezeichnete. <b>✕</b> verwirft es.';
$lang['HlpRecTip']          = 'Lesen Sie danach die <b>Auslöser durch</b> — Typ, Kurzhinweis, Pflicht — passen Sie an, was nötig ist, und <b>speichern Sie den Kurs</b>. Die erzeugten Selektoren sind robust, bei sehr dynamischen Elementen aber nicht unfehlbar.';

/* 9. Step options */
$lang['HlpOptionsTitle']    = 'Schritt-Optionen';
$lang['HlpOptFOptional']    = 'Optional';
$lang['HlpOptVOptional']    = 'Zeigt eine Schaltfläche „Als erledigt markieren": Der Benutzer kann den Schritt abschließen, ohne die Aktion auszuführen.';
$lang['HlpOptFStrict']      = 'Nicht nachgiebig';
$lang['HlpOptVStrict']      = 'Blockiert jeden Klick <b>außerhalb</b> des erwarteten Elements, sodass der Benutzer nur mit dem Ziel umgehen kann. Der Bereich blinkt rot, wenn ein Klick blockiert wird.';

/* 10. Quiz and challenge */
$lang['HlpActivitiesTitle'] = 'Quiz und Aufgabe — Bronze, Silber und Gold';
$lang['HlpActIntro']        = 'Ein Kurs kann bis zu <b>drei Aktivitäten</b> anbieten: die schrittweise <b>Führung</b>, ein <b>Quiz</b> und eine <b>Aufgabe</b>. Am Ende der Führung wird der Benutzer zum Quiz eingeladen, dann zur Aufgabe, dann zum nächsten Kurs. Jede bestandene Aktivität hebt die Auszeichnung:';
$lang['HlpActBronze']       = 'Die Führung abschließen';
$lang['HlpActSilver']       = 'Die Führung und eine weitere Aktivität';
$lang['HlpActGold']         = 'Alle Aktivitäten, die der Kurs anbietet';
$lang['HlpActLiQuiz']       = '<b>Quiz</b> (Bereich „📝 Quiz" im Editor): Fragen mit zwei bis vier Antworten, <b>eine oder mehrere</b> richtige angehakt — der Benutzer muss dann genau die richtige Menge wählen — eine optionale Erklärung, eine Bestehensgrenze (voreingestellt 70 %) und eine Option, die Antworten in <b>zufälliger Reihenfolge</b> zu zeigen.';
$lang['HlpActLiChallenge']  = '<b>Aufgabe</b> (Bereich „🎯 Aufgabe"): eine Anweisung und <b>Zustandsbedingungen</b>, die das Ergebnis in ianseo prüfen, während der Benutzer ganz ohne Hilfe arbeitet. Bedingungen entstehen im <b>Bedingungsbaukasten</b> (Schaltfläche ⚡ in der Verwaltung), der sie am geöffneten Wettkampf live erproben kann.';
$lang['HlpActShipped']      = 'Die mitgelieferten Bedingungen decken den üblichen Ablauf eines Wettkampfs ab:';
$lang['HlpActLiState']      = '<b>mindestens ein Teilnehmer angemeldet</b>, <b>mindestens ein Kampfrichter erfasst</b>, <b>mindestens eine Scheibe zugewiesen</b>, <b>mindestens ein Ergebnis eingegeben</b> — alle am geöffneten Wettkampf geprüft;';
$lang['HlpActLiVisited']    = '<b>eine bestimmte Seite wurde besucht</b> — die Prüfung „<b>Seite besucht</b>" des Baukastens. Ein Besuch wird <b>je Benutzer und je Wettkampf</b> gemerkt; haken Sie „beliebiger Wettkampf" an für einen übergreifenden. Ideal für eine Aufgabe der Art „senden Sie Ihre Ergebnisse an den Verband".';

/* 11. Learning path and the other content types */
$lang['HlpPathTitle']       = 'Lernpfad, Prüflisten, Fehlersuche, Kontexthilfe';
$lang['HlpPathLiPath']      = '<b>Lernpfad</b>: Die Felder <b>Gruppe</b>, <b>Untergruppe</b> und <b>Reihenfolge</b> gliedern den Katalog in geordnete Abschnitte. Der am Ende angebotene „nächste Kurs" folgt dieser Reihenfolge.';
$lang['HlpPathLiChecklist'] = '<b>Prüflisten</b>: einige Fragen mit Schaltflächen, danach eine Aufgabenliste, die sich nach den Antworten richtet. Einträge mit Bedingung haken sich selbst ab. Anlegen über „+ Prüfliste" (JSON-Bearbeitung).';
$lang['HlpPathLiTrouble']   = '<b>Fehlersuche</b>: ein Baum aus Frage → Antworten → Lösung. Anlegen über „+ Fehlersuche" (JSON-Bearbeitung).';
$lang['HlpPathLiContext']   = '<b>Kontexthilfe</b>: voreingestellt eingeschaltet. Die schwebende Schaltfläche 🎯 zeigt einen orangen Punkt, wenn zur angezeigten ianseo-Seite Inhalte vorliegen. Abschaltbar über den Katalog oder den Seitenbereich.';

/* 12. User accounts */
$lang['HlpAccountsTitle']   = 'Benutzerkonten (gemeinsamer Server)';
$lang['HlpAccountsP']       = 'Betreibt der Server ein Kontenmodul, hat <b>jedes Konto seinen eigenen Fortschritt</b>: laufende Kurse, erledigte Schritte, Quiz, Aufgaben und Auszeichnungen werden je Benutzer gespeichert, ohne andere zu berühren. Das Banner „ianseo benutzen lernen" erscheint für ein Konto, das <b>überhaupt keinen Wettkampf</b> sieht — das Kennzeichen eines neuen Veranstalters. Die <b>Kurse</b> selbst bleiben für den ganzen Server gemeinsam. Ohne Kontenmodul ändert sich nichts: Der Fortschritt ist schlicht der der Installation.';

/* 13. Translating a course */
$lang['HlpTranslateTitle']  = 'Einen Kurs übersetzen';
$lang['HlpTransP1']         = 'Ein Kurs führt alle Sprachen, in denen er geschrieben wurde, <b>in seiner eigenen Datei</b>: Er bleibt ein Dokument mit einer Versionsnummer, und die Aktualisierung hat nur eines zu vergleichen.';
$lang['HlpTransLiBase']     = '<b>Ursprungssprache</b> gibt an, in welcher Sprache der Kurs verfasst wurde — die Sprache aller noch nicht übersetzten Felder. Die Bearbeitungssprache kann das nicht beantworten: Sie sagt, wo Sie gerade schreiben, nicht was der vorhandene Text bereits ist. Ohne sie würde die erste Übersetzung eines Feldes den Originaltext unter der Sprache ablegen, in der Sie gerade schrieben, und der Kurs trüge sein Original als Übersetzung. Standardmäßig ist es Ihre eigene Sprache; zu ändern nur, wenn Sie einen von jemand anderem verfassten Kurs übernehmen.';
$lang['HlpTransLiEdit']     = '<b>Bearbeitungssprache</b> wählt, welche Sprache die Felder zeigen und schreiben. Ein Wechsel lässt die übrigen Sprachen unberührt.';
$lang['HlpTransLiEmpty']    = 'Ein noch nicht übersetztes Feld erscheint <b>leer</b>, nicht mit dem Ursprungstext — so ist auf einen Blick zu sehen, was fehlt.';
$lang['HlpTransNote']       = 'Übersetzt wird nur der <b>Text</b> eines Feldes. Die Struktur ringsum — Schritte, Auslöser, Selektoren, Seiten — wird nie verdoppelt, sodass die Sprachen nicht auseinanderlaufen können. Ein Kurs, dessen Übersetzung auf eine andere Schaltfläche zeigte als das Original, wäre schlimmer als ein Kurs ganz ohne Übersetzung.';
$lang['HlpTransP2']         = 'Der Leser erhält den Kurs in der Sprache seiner Oberfläche. Fehlt sie, Englisch; fehlt auch das, die Sprache, in der der Kurs verfasst wurde — ein nur in eine Sprache übersetzter Kurs wird also trotzdem ausgeliefert und nie leer angezeigt. Eine regionale Sprache zählt dabei wie ihre Muttersprache: Ein Leser in kanadischem Französisch erhält den französischen Text, bevor Englisch in Betracht kommt.';
