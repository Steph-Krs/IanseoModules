<?php
/**
 * German strings of the training module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Training';
$lang['MenuCreate'] = 'Training anlegen';
$lang['MenuHome'] = 'Trainings';
$lang['MenuDashboard'] = 'Übersicht des Trainings';
$lang['MenuParticipants'] = 'Schützen des Trainings';
$lang['MenuTeams'] = 'Mannschaften';
$lang['MenuRanking'] = 'Rangliste und Duelle';
$lang['MenuResults'] = 'Ergebnisse';
$lang['MenuFormats'] = 'Vorlagen';
$lang['MenuArchers'] = 'Stammschützen';
$lang['MenuFull'] = 'Vollständiges Menü in diesem Browser';
$lang['MenuSimple'] = 'Vereinfachtes Menü in diesem Browser';
$lang['MenuIsFull'] = 'Dieser Browser zeigt das vollständige ianseo-Menü.';
$lang['MenuIsSimple'] = 'Dieser Browser zeigt das vereinfachte Menü der Trainings.';
$lang['MenuUpdate'] = 'Modul aktualisieren';
$lang['UpdateTitle'] = 'Training - Aktualisierung des Moduls';
$lang['BackToPage'] = 'Zurück zu den Trainings';
$lang['DateFmt'] = 'd.m.Y';

// Common words
$lang['Save'] = 'Speichern';
$lang['Saved'] = 'Gespeichert.';
$lang['Cancel'] = 'Abbrechen';
$lang['Delete'] = 'Löschen';
$lang['Edit'] = 'Bearbeiten';
$lang['Add'] = 'Hinzufügen';
$lang['Remove'] = 'Entfernen';
$lang['Rename'] = 'Umbenennen';
$lang['Open'] = 'Öffnen';
$lang['Choose'] = 'Wählen';
$lang['Filter'] = 'Filtern…';
$lang['CheckAll'] = 'Alle markieren';
$lang['BackDashboard'] = 'Zurück zur Übersicht';
$lang['SessionN'] = 'Durchgang {$a}';
$lang['SexM'] = 'Mann';
$lang['SexF'] = 'Frau';
$lang['DuelsOnly'] = 'Nur Duelle';
$lang['ErrToken'] = 'Das Formular war abgelaufen. Bitte erneut versuchen.';

// Columns
$lang['ColDate'] = 'Datum';
$lang['ColName'] = 'Name';
$lang['ColFormat'] = 'Vorlage';
$lang['ColArchers'] = 'Schützen';
$lang['ColArcher'] = 'Schütze';
$lang['ColCategory'] = 'Klasse';
$lang['ColSession'] = 'Durchgang';
$lang['ColTarget'] = 'Scheibe';
$lang['ColContent'] = 'Inhalt';
$lang['ColUpdated'] = 'Aktualisiert';

// Home
$lang['HomeTitle'] = 'Trainings';
$lang['HomeLead'] = 'Ein Training ist ein echtes ianseo-Turnier, das mit wenigen Klicks aus einer Vorlage entsteht: Vorlage, Datum und Startzeit wählen, Schützen markieren, und alles andere ist bereit - Klassen, Wettbewerbe, Scheibenauflagen, Entfernungen, Zeitplan. Der Trainer folgt dann den Schritten der Übersicht.';
$lang['HomeRuns'] = 'Bereits angelegte Trainings';
$lang['HomeNoRun'] = 'Noch kein Training.';
$lang['HomeNoFormat'] = 'Noch keine Vorlage. Bereiten Sie ein Turnier in ianseo mit allen gewünschten Einstellungen vor, öffnen Sie es und speichern Sie es auf der Seite Vorlagen.';
$lang['HomePrepare'] = 'Trainings vorbereiten';

// Formats
$lang['FormatsTitle'] = 'Vorlagen';
$lang['FormatsLead'] = 'Eine Vorlage ist die Kopie eines ganzen Turniers ohne seine Schützen: Bogenklassen und Altersklassen, Wettbewerbe, Scheibenauflagen, Entfernungen, Bilder, Turniereinstellungen und Zeitplan. Einmal gespeichert, wird das ursprüngliche Turnier nicht mehr benötigt und kann gelöscht werden.';
$lang['FormatsFromOpen'] = 'Das geöffnete Turnier speichern';
$lang['FormatsNoOpen'] = 'Öffnen Sie zuerst das Turnier, das als Vorlage gespeichert werden soll.';
$lang['FormatsOpenIs'] = 'Geöffnetes Turnier: {$a[code]} - {$a[name]}';
$lang['FormatName'] = 'Name der Vorlage';
$lang['FormatSimple'] = 'Vereinfachtes Menü für die Trainings aus dieser Vorlage';
$lang['FormatSimpleShort'] = 'Vereinfachtes Menü';
$lang['FormatReplace'] = 'Eine vorhandene Vorlage ersetzen';
$lang['FormatReplaceNone'] = 'Nein, eine neue Vorlage anlegen';
$lang['FormatsWhatKept'] = 'Schützen, Mannschaften, Ergebnisse werden nicht übernommen, ebenso wenig die ianseo.net-Zugangsdaten des Turniers. Die Zeiten des Zeitplans werden als Abstände zum ersten Start gespeichert.';
$lang['FormatSave'] = 'Als Vorlage speichern';
$lang['FormatSaved'] = 'Vorlage „{$a}" gespeichert.';
$lang['FormatReplaced'] = 'Vorlage „{$a}" durch das geöffnete Turnier ersetzt.';
$lang['FormatsList'] = 'Gespeicherte Vorlagen';
$lang['FormatContent'] = '{$a[type]} - {$a[sessions]} Durchgang/Durchgänge - Wettbewerbe: {$a[events]}';
$lang['FormatSource'] = 'Aus {$a}';
$lang['FormatDeleteConfirm'] = 'Die Vorlage „{$a}" löschen? Bereits angelegte Trainings bleiben unverändert.';
$lang['FormatDeleted'] = 'Vorlage gelöscht.';

// Regular archers
$lang['ArchersTitle'] = 'Stammschützen';
$lang['ArchersLead'] = 'Die Schützen, die oft an den Trainings teilnehmen: Sie müssen beim Anlegen eines Trainings nur markiert werden. Gruppen markieren mehrere Schützen auf einmal.';
$lang['ArcherAdd'] = 'Schützen hinzufügen';
$lang['ArcherEdit'] = 'Schützen bearbeiten';
$lang['LookupLabel'] = 'In der Lizenzdatei suchen:';
$lang['LookupPlaceholder'] = 'Lizenz oder Nachname';
$lang['LookupNone'] = 'Niemand in der Lizenzdatei gefunden.';
$lang['FieldFamily'] = 'Nachname';
$lang['FieldGiven'] = 'Vorname';
$lang['FieldSex'] = 'Geschlecht';
$lang['FieldDob'] = 'Geburtsdatum';
$lang['FieldCode'] = 'Lizenz';
$lang['FieldIoc'] = 'Land';
$lang['FieldClub'] = 'Verein';
$lang['FieldClubName'] = 'Name des Vereins';
$lang['FieldDiv'] = 'Bogenklasse';
$lang['FieldCls'] = 'Altersklasse';
$lang['FieldActive'] = 'Beim Anlegen eines Trainings angeboten';
$lang['FieldGroups'] = 'Gruppen';
$lang['FieldDate'] = 'Datum';
$lang['FieldStart'] = 'Startzeit';
$lang['ErrNoName'] = 'Der Nachname ist erforderlich.';
$lang['ArchersList'] = 'Schützen ({$a})';
$lang['ArchersNone'] = 'Noch keine Stammschützen.';
$lang['ArcherDeleteConfirm'] = '{$a} aus den Stammschützen entfernen?';
$lang['ArcherDeleted'] = 'Schütze gelöscht.';
$lang['GroupsTitle'] = 'Gruppen';
$lang['GroupNew'] = 'Neue Gruppe';
$lang['GroupDeleteConfirm'] = 'Die Gruppe „{$a}" löschen? Ihre Schützen bleiben in der Liste.';
$lang['ImportTitle'] = 'Schützen des geöffneten Turniers kopieren';
$lang['ImportNone'] = 'Alle Schützen des geöffneten Turniers sind schon in der Liste.';
$lang['ImportDo'] = 'Markierte Schützen kopieren';
$lang['ArchersImported'] = '{$a} Schütze(n) kopiert.';

// Creation
$lang['CreateTitle'] = 'Training anlegen';
$lang['ChooseFormat'] = '- Vorlage wählen -';
$lang['CreateDays'] = 'Diese Vorlage dauert {$a} Tage: die folgenden Tage werden ebenfalls verschoben.';
$lang['CreateShift'] = 'Alle Zeiten des Zeitplans folgen der Startzeit und behalten die Abstände der Vorlage.';
$lang['QualSim'] = 'Nur Duelle (simulierte Qualifikation)';
$lang['QualSimHelp'] = 'Jeder Schütze erhält einen Punkt; die Reihenfolge der Finalbäume wird danach auf der Seite Rangliste und Duelle gewählt.';
$lang['PlaceAuto'] = 'Schützen den Scheiben zuteilen';
$lang['CreateArchers'] = 'Schützen';
$lang['CountPicked'] = '{$a} Schütze(n) markiert';
$lang['CreateDo'] = 'Training anlegen';
$lang['Created'] = 'Training {$a[code]} mit {$a[n]} Schütze(n) angelegt.';
$lang['CreatedSkipped'] = '{$a} Schütze(n) konnten nicht gemeldet werden: Klasse fehlt in der Vorlage.';
$lang['ErrNoFormat'] = 'Wählen Sie eine Vorlage.';
$lang['ErrDate'] = 'Das Datum ist ungültig.';
$lang['ErrNoArcher'] = 'Markieren Sie mindestens einen Schützen.';
$lang['ErrNoCode'] = 'Alle Codes dieses Tages sind vergeben (99 Trainings).';
$lang['ErrImport'] = 'Die Vorlage wurde von einem neueren ianseo gespeichert: aktualisieren Sie zuerst ianseo.';

// Teams
$lang['TeamsTitleAll'] = 'Mannschaften';
$lang['TeamsTitle'] = 'Mannschaften';
$lang['MixedTitle'] = 'Mixed-Mannschaften';
$lang['TeamN'] = 'Mannschaft {$a}';
$lang['MixedN'] = 'Mixed {$a}';
$lang['TeamAdd'] = 'Mannschaft hinzufügen';
$lang['MixedAdd'] = 'Mixed-Mannschaft hinzufügen';
$lang['AddArcher'] = '+ Schütze';
$lang['RemoveTeam'] = 'Mannschaft entfernen';
$lang['TeamsHelpCreate'] = 'Nur die markierten Schützen können gewählt werden. Die Mannschaften lassen sich auch später in der Übersicht zusammenstellen oder ändern.';
$lang['TeamsHelp'] = 'Stellen Sie die Mannschaften jetzt oder jederzeit zusammen: vor, während oder nach der Qualifikation. Ein Schütze kann in einer Mannschaft und in einer anderen Mixed-Mannschaft sein. Die Mannschafts-Finalbäume werden auf der Seite Rangliste und Duelle gefüllt.';
$lang['TeamsNone'] = 'Dieses Training hat keinen Mannschaftswettbewerb.';

// Dashboard
$lang['DashTitle'] = 'Übersicht des Trainings';
$lang['DashNotRun'] = 'Dieses Turnier wurde nicht vom Modul Training angelegt; die Übersicht funktioniert trotzdem.';
$lang['StepArchers'] = 'Schützen';
$lang['StepTargets'] = 'Scheiben';
$lang['StepScorecards'] = 'Schießzettel';
$lang['StepScores'] = 'Ergebnisse';
$lang['StepQualSim'] = 'Qualifikation';
$lang['StepDuels'] = 'Duelle';
$lang['StepResults'] = 'Ergebnislisten';
$lang['DashArchers'] = '{$a} Schütze(n) im Training.';
$lang['DashTeams'] = '{$a} Mannschaft(en) zusammengestellt';
$lang['DashPlaced'] = '{$a[on]} von {$a[all]} Schütze(n) mit Scheibe';
$lang['PlaceDo'] = 'Schützen ohne Scheibe zuteilen';
$lang['PlaceAgain'] = 'Alle neu zuteilen';
$lang['PlaceAgainConfirm'] = 'Alle Scheiben dieses Durchgangs entfernen und alle neu zuteilen?';
$lang['PlaceManual'] = 'Scheibenplan: Schützen von Hand verschieben';
$lang['Placed'] = '{$a} Schütze(n) zugeteilt.';
$lang['PlacedLeft'] = '{$a} Schütze(n) ohne Scheibe: nicht genug Scheiben im Durchgang.';
$lang['ScorecardsOf'] = 'Schießzettel - {$a}';
$lang['MoreOptions'] = 'Weitere Optionen';
$lang['DashScores'] = '{$a[done]} von {$a[all]} Schütze(n) haben ein Ergebnis.';
$lang['QualRanking'] = 'Rangliste der Qualifikation';
$lang['QualRankingTeam'] = 'Mannschaftsrangliste der Qualifikation';
$lang['QualSimDone'] = 'Simuliert: jeder Schütze hat einen Punkt. Wählen Sie die Reihenfolge der Finalbäume auf der Seite Rangliste und Duelle.';
$lang['EventLaunched'] = 'Finalbaum gefüllt';
$lang['EventWaiting'] = 'Finalbaum zu füllen';
$lang['EventCore'] = 'Eliminationen oder Gruppen: ianseo-Seiten';
$lang['FinalRanking'] = 'Endrangliste';
$lang['FinalRankingTeam'] = 'Mannschafts-Endrangliste';

// Participants
$lang['ParticipantsTitle'] = 'Schützen des Trainings';
$lang['ParticipantsIn'] = 'Im Training ({$a})';
$lang['ParticipantsAdd'] = 'Stammschützen hinzufügen';
$lang['ParticipantsAllIn'] = 'Alle Stammschützen sind bereits im Training.';
$lang['ParticipantsAddDo'] = 'Markierte Schützen hinzufügen';
$lang['ArchersAdded'] = '{$a} Schütze(n) hinzugefügt.';
$lang['ArcherRemoveConfirm'] = '{$a} mit seinen Ergebnissen aus dem Training entfernen?';
$lang['ArcherRemoved'] = 'Schütze entfernt.';

// Ranking and duels
$lang['RankingTitle'] = 'Rangliste und Duelle';
$lang['RankingLead'] = 'Jede Liste ist die Reihenfolge des Finalbaums, der Beste zuerst: die Reihenfolge der Qualifikation, Gleichstände nebeneinander. Ziehen Sie die Namen oder nutzen Sie die Pfeile, dann füllen Sie den Finalbaum.';
$lang['RankingNone'] = 'Dieses Training hat keinen Wettbewerb mit Duellen.';
$lang['RankingCore'] = 'Dieser Wettbewerb hat Eliminationen oder Gruppen: nutzen Sie die ianseo-Seite.';
$lang['OpenCore'] = 'Öffnen';
$lang['RankingNoArcher'] = 'Kein Schütze in diesem Wettbewerb.';
$lang['RankingNoTeam'] = 'Noch keine Mannschaft: stellen Sie die Mannschaften zusammen und prüfen Sie, dass ihre Schützen ein Ergebnis haben.';
$lang['RankingQualified'] = 'Die ersten {$a[n]} von {$a[all]} kommen in den Finalbaum.';
$lang['Tie'] = 'Gleichstand';
$lang['MoveUp'] = 'Nach oben';
$lang['MoveDown'] = 'Nach unten';
$lang['Launch'] = 'Finalbaum füllen';
$lang['Relaunch'] = 'Finalbaum erneut füllen';
$lang['RelaunchConfirm'] = 'Der Finalbaum von {$a} ist bereits gefüllt: ein erneutes Füllen löscht die bereits eingegebenen Duelle. Fortfahren?';
$lang['DuelsLaunched'] = 'Finalbaum von {$a} gefüllt.';
$lang['ErrLaunch'] = 'Der Finalbaum von {$a} konnte nicht gefüllt werden: laden Sie die Seite neu und versuchen Sie es erneut.';

// Import and export
$lang['MenuTransfer'] = 'Import / Export';
$lang['TransferTitle'] = 'Import / Export';
$lang['TransferLead'] = 'Die Vorlagen, die Stammschützen und ihre Gruppen auf eine andere Installation übertragen, zum Beispiel um ein anderes Trainingszentrum genauso einzurichten.';
$lang['ExportTitle'] = 'Exportieren';
$lang['ExportArchers'] = 'Stammschützen ({$a[archers]}) und Gruppen ({$a[groups]})';
$lang['ExportPersonal'] = 'Die Datei enthält dann personenbezogene Daten (Namen, Geburtsdaten, Lizenzen): bewahren Sie sie wie jede Schützenliste auf.';
$lang['ExportDo'] = 'Datei herunterladen';
$lang['ExportNothing'] = 'Wählen Sie mindestens eine Vorlage oder die Schützen.';
$lang['ImportFileTitle'] = 'Importieren';
$lang['ImportReplace'] = 'Eine Vorlage mit demselben Namen ersetzen';
$lang['ImportHelp'] = 'Ein Schütze, der schon in der Liste ist (gleiche Lizenz, sonst gleicher Name und gleiches Geburtsdatum), wird aus der Datei aktualisiert; die anderen werden hinzugefügt. Gruppen werden am Namen erkannt. Eine Vorlage, die von einem neueren ianseo gespeichert wurde, wird abgelehnt: aktualisieren Sie zuerst ianseo.';
$lang['ImportFileDo'] = 'Datei importieren';
$lang['ImportedSummary'] = 'Importiert: {$a[formats]} neue Vorlage(n), {$a[replaced]} ersetzt, {$a[archers]} neue(r) Schütze(n), {$a[updated]} aktualisiert, {$a[groups]} neue Gruppe(n).';
$lang['ImportRefused'] = 'Abgelehnte Vorlagen (unlesbar oder von einem neueren ianseo gespeichert): {$a}';
$lang['ErrFile'] = 'Diese Datei ist kein Export des Moduls Training.';
$lang['ErrFileSize'] = 'Die Datei konnte nicht empfangen werden: sie ist vielleicht größer als das, was der Server annimmt.';
$lang['ClassAuto'] = 'Die Altersklasse wird aus Geburtsdatum und Geschlecht ermittelt; sie wird für das Datum jedes Trainings neu berechnet.';

// ISK-Lite
$lang['IskTitle'] = 'Ergebniseingabe mit ISK-Lite';
$lang['IskLead'] = 'Jedes Training ist für die Eingabe mit der ISK-App in ihrer kostenlosen Version (ISK-Lite) eingerichtet: die Schießzettel tragen die QR-Codes der App, und die Ergebnisse kommen von selbst in ianseo an.';
$lang['IskUrl'] = 'Serveradresse für die Telefone:';
$lang['IskUrlHelp'] = 'Wird jedem neuen Training mitgegeben. Die Telefone müssen diese Adresse erreichen: geben Sie die Adresse dieses Computers im Netzwerk an, nicht localhost.';
$lang['IskUrlNone'] = 'Noch keine Adresse: die Trainings erhalten ISK-Lite ohne Serveradresse, die in den Turnierdaten einzutragen ist. Geben Sie die Adresse dieses Computers im Netzwerk an, nicht localhost.';
$lang['IskUrlBad'] = 'Diese Adresse ist nicht lesbar. Beispiel: http://192.168.1.10';

// Targets and rhythm
$lang['ManualQualEntry'] = 'Manuelle Eingabe der Qualifikationsergebnisse';
$lang['Rhythm'] = 'Schützen pro Scheibe:';
$lang['RhythmConfirm'] = 'Auf {$a} umstellen und alle Schützen dieses Durchgangs neu zuteilen?';
$lang['RhythmChanged'] = 'Schützen pro Scheibe geändert: alle neu zugeteilt.';
$lang['TargetsMissing'] = '{$a[off]} Schütze(n) ohne Scheibe: der Durchgang hat {$a[have]} Scheiben, für alle werden {$a[need]} bei {$a[rhythm]} benötigt.';
$lang['TargetsOff'] = '{$a[off]} Schütze(n) ohne Scheibe: nutzen Sie Schützen ohne Scheibe zuteilen.';
$lang['TargetsHave'] = '{$a} Scheiben';
$lang['TargetsAdjust'] = 'Auf {$a} Scheiben anpassen';
$lang['TargetsAdjusted'] = 'Der Durchgang hat jetzt {$a} Scheiben: alle neu zugeteilt.';

// Fictitious competitors and follow-up
$lang['GhostName'] = 'Fiktiv';
$lang['GhostClub'] = 'Fiktive Teilnehmer';
$lang['GhostsScored'] = 'Ergebnisse für {$a} fiktive(n) Teilnehmer gezogen.';
$lang['DashGhosts'] = '{$a} fiktive(r) Teilnehmer: ihre Ergebnisse werden um ihren Durchschnitt gezogen.';
$lang['GhostsRedraw'] = 'Ergebnisse neu ziehen';
$lang['GhostsAdded'] = '{$a} fiktive(r) Teilnehmer hinzugefügt.';
$lang['GhostBadge'] = 'fiktiv, {$a} pro Pfeil';
$lang['GhostsTitle'] = 'Fiktive Teilnehmer';
$lang['GhostsLead'] = 'Gegner ohne Schützen dahinter: ihre Qualifikationsergebnisse werden um einen Durchschnitt pro Pfeil gezogen, und sie nehmen an den Ranglisten, Mannschaften und Finalbäumen teil. Sie haben weder Scheibe noch Schießzettel; ihre Duelle werden wie alle anderen eingegeben.';
$lang['GhostsCount'] = 'Wie viele';
$lang['GhostsAvg'] = 'Durchschnitt pro Pfeil';
$lang['GhostsSpread'] = 'Streuung pro Pfeil';
$lang['GhostsAdd'] = 'Fiktive Teilnehmer hinzufügen';
$lang['MenuFollowup'] = 'Verlauf der Schützen';
$lang['FollowupTitle'] = 'Verlauf der Schützen';
$lang['FollowupLead'] = 'Alle Schüsse eines Schützen auf diesem Server, Trainings und echte Turniere, nach Situation. Trainings bleiben erhalten, auch wenn sie aus ianseo gelöscht wurden. Suchen Sie jeden Schützen nach Lizenz oder Namen; die Stammschützen sind unten aufgeführt.';
$lang['FollowupEmpty'] = 'Noch kein Ergebnis.';
$lang['FollowupOf'] = 'Verlauf - {$a}';
$lang['FollowupNone'] = 'Kein Ergebnis für diesen Schützen.';
$lang['BackFollowup'] = 'Zurück zur Liste';
$lang['Search'] = 'Suchen';
$lang['SituationsTitle'] = 'Nach Situation';
$lang['ChartTitle'] = 'Durchschnitt pro Pfeil';
$lang['HistoryTitle'] = 'Alle Schüsse';
$lang['KindTraining'] = 'Training';
$lang['KindCompetition'] = 'Turnier';
$lang['DecimalSep'] = ',';
$lang['ColKind'] = 'Art';
$lang['ColShoots'] = 'Schüsse';
$lang['ColAvg'] = 'Durchschnitt';
$lang['ColPerArrow'] = 'Pro Pfeil';
$lang['ColBest'] = 'Bestes';
$lang['ColLast'] = 'Letztes';
$lang['ColDuels'] = 'Duelle gewonnen - verloren';
$lang['ColTrainings'] = 'Trainings';
$lang['ColCompetitions'] = 'Turniere';
$lang['ColLastShoot'] = 'Letzter Schuss';
$lang['CsvRun'] = 'Ergebnisse dieses Trainings (CSV)';
$lang['CsvAll'] = 'Alle Trainings (CSV)';
$lang['CsvArcher'] = 'Dieser Schütze (CSV)';
$lang['CsvDate'] = 'Datum';
$lang['CsvCode'] = 'Code';
$lang['CsvName'] = 'Turnier';
$lang['CsvKind'] = 'Art';
$lang['CsvFormat'] = 'Vorlage';
$lang['CsvLicence'] = 'Lizenz';
$lang['CsvFamily'] = 'Nachname';
$lang['CsvGiven'] = 'Vorname';
$lang['CsvDivision'] = 'Bogenklasse';
$lang['CsvClass'] = 'Altersklasse';
$lang['CsvDistances'] = 'Entfernungen';
$lang['CsvFace'] = 'Scheibenauflage';
$lang['CsvScore'] = 'Ergebnis';
$lang['CsvArrows'] = 'Pfeile';
$lang['CsvGolds'] = '10er';
$lang['CsvXNine'] = 'X/9';
$lang['CsvQualRank'] = 'Platz Qualifikation';
$lang['CsvEvent'] = 'Wettbewerb';
$lang['CsvFinalRank'] = 'Endplatz';
$lang['CsvWon'] = 'Duelle gewonnen';
$lang['CsvLost'] = 'Duelle verloren';
