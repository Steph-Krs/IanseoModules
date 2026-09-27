<?php
/**
 * German strings of the live draw module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Live-Auslosung';
$lang['MenuShows'] = 'Auslosungen';
$lang['MenuUpdate'] = 'Modul aktualisieren';
$lang['UpdateTitle'] = 'Live-Auslosung — Modulaktualisierung';

// Home page
$lang['HomeLead'] = 'Die Bildschirme einer öffentlichen Auslosung: ein Bildschirm für den Saal oder die Übertragung, eine Regie zum Erfassen jeder gezogenen Mannschaft und ein Bildschirm für die Kommentatoren. Eine Auslosung gehört zu keinem Wettkampf.';
$lang['ShowsTitle'] = 'Auslosungen';
$lang['ShowsNone'] = 'Noch keine Auslosung. Legen Sie unten eine an oder importieren Sie eine Sicherung der früheren Auslosungsseite.';
$lang['ColTitle'] = 'Auslosung';
$lang['ColProgress'] = 'Fortschritt';
$lang['ColUpdated'] = 'Letzte Änderung';
$lang['ColScreens'] = 'Bildschirme';
$lang['Progress'] = '{$a[drawn]} von {$a[total]} Plätzen gezogen';
$lang['OpenControl'] = 'Regie';
$lang['OpenEdit'] = 'Einstellungen und Mannschaften';
$lang['OpenDisplay'] = 'Öffentlicher Bildschirm';
$lang['OpenSpeaker'] = 'Kommentatoren';
$lang['Duplicate'] = 'Kopieren';
$lang['DuplicateHint'] = 'Listen, Historie, Notizen und Aussehen in eine neue Auslosung ohne gezogene Plätze kopieren — so beginnt die Auslosung des nächsten Jahres.';
$lang['ConfirmDeleteShow'] = 'Die Auslosung „{$a}“ mit ihren Listen, ihrer Historie und ihren Notizen löschen?';
$lang['Delete'] = 'Löschen';
$lang['SourceNone'] = 'Kein Wettkampf der Vorsaison';
$lang['CreateTitle'] = 'Neue Auslosung';
$lang['FieldTitle'] = 'Titel';
$lang['FieldTitlePlaceholder'] = 'Auslosung erste Liga 2027';
$lang['FieldSource'] = 'Wettkampf der Vorsaison';
$lang['FieldSourceHint'] = 'Optional. Der ianseo-Wettkampf der gerade gespielten Saison: seine Mannschaften können importiert werden, und die Kommentatoren sehen Saison, Ergebnisse und Schützen jeder Mannschaft.';
$lang['Create'] = 'Anlegen';
$lang['ImportTitle'] = 'Sicherung der früheren Auslosungsseite importieren';
$lang['ImportLead'] = 'Die eigenständige HTML-Seite, die vor diesem Modul verwendet wurde, exportierte ihren Zustand als JSON-Datei. Geben Sie diese Datei an, und die Seite selbst, falls noch vorhanden: Kategorienamen und vollständige Mannschaftslisten stehen nur in der Seite. Gezogene Plätze werden nicht importiert.';
$lang['ImportSave'] = 'Sicherung (.json)';
$lang['ImportPage'] = 'Frühere Seite (.html), optional';
$lang['Import'] = 'Importieren';
$lang['CopyOf'] = 'Kopie von {$a}';
$lang['LegacyTitle'] = 'Importierte Auslosung';
$lang['LegacyCategory'] = 'Kategorie {$a}';
$lang['EditTitle'] = 'Einstellungen und Mannschaften';
$lang['ControlTitle'] = 'Regie';
$lang['SpeakerTitle'] = 'Kommentatoren';
$lang['BackToShows'] = 'Alle Auslosungen';
$lang['Loading'] = 'Wird geladen…';

// Errors and messages
$lang['ErrToken'] = 'Die Sitzung ist abgelaufen. Laden Sie die Seite neu und versuchen Sie es erneut.';
$lang['ErrNameEmpty'] = 'Ein Name ist erforderlich.';
$lang['ErrNoShow'] = 'Diese Auslosung existiert nicht mehr.';
$lang['ErrNoCategory'] = 'Diese Kategorie existiert nicht mehr.';
$lang['ErrNoTeam'] = 'Diese Mannschaft existiert nicht mehr.';
$lang['ErrNoSource'] = 'Wählen Sie zuerst den Wettkampf der Vorsaison.';
$lang['ErrField'] = 'Unbekanntes Feld.';
$lang['ErrAction'] = 'Unbekannte Aktion.';
$lang['ErrImage'] = 'Die Datei ist kein zulässiges Bild (JPEG, PNG, WebP oder GIF, höchstens 5 MB).';
$lang['ErrLink'] = 'Dieser Bildschirm-Link ist ungültig. Vielleicht wurde er erneuert: fragen Sie nach dem neuen.';
$lang['ErrLegacyFormat'] = 'Diese Datei ist keine Sicherung der früheren Auslosungsseite.';
$lang['ErrLegacyEmpty'] = 'Die Sicherung enthält keine Kategorie.';
$lang['ErrNetwork'] = 'Der Server hat nicht geantwortet. Prüfen Sie die Verbindung und versuchen Sie es erneut.';
$lang['ErrSeasonApplied'] = 'Diese Saison wurde bereits in die Historie übernommen.';
$lang['ErrNoEventChecked'] = 'Wählen Sie mindestens einen Wettbewerb aus.';
$lang['MsgImported'] = '{$a} Mannschaft(en) importiert.';
$lang['MsgLinked'] = '{$a} Mannschaft(en) ihrem Verein zugeordnet.';
$lang['MsgSeasonApplied'] = 'Historie für {$a[updated]} Mannschaft(en) aktualisiert; Vorjahresplatz für {$a[cleared]} in jener Saison nicht vertretene Mannschaft(en) gelöscht.';

// Preparation page
$lang['SectionGeneral'] = 'Auslosung';
$lang['FieldSubtitle'] = 'Untertitel';
$lang['LinksTitle'] = 'Bildschirm-Links';
$lang['LinksHint'] = 'Öffnen Sie diese Links auf den Rechnern, die die Bildschirme zeigen. Kein ianseo-Konto nötig: wer einen Link hat, sieht diesen Bildschirm — geben Sie den Kommentatoren-Link also nur den Kommentatoren.';
$lang['Copy'] = 'Kopieren';
$lang['Copied'] = 'Link kopiert.';
$lang['Open'] = 'Öffnen';
$lang['NewTokens'] = 'Links erneuern';
$lang['NewTokensHint'] = 'Die aktuellen Links funktionieren sofort nicht mehr.';
$lang['ConfirmNewTokens'] = 'Beide Links erneuern? Bereits geöffnete Bildschirme werden erst wieder aktualisiert, wenn sie mit den neuen Links geöffnet werden.';
$lang['SectionSeasonNone'] = 'Vorsaison';
$lang['SeasonNoSource'] = 'Wählen Sie oben den Wettkampf der Vorsaison, um dessen Mannschaften zu importieren und den Kommentatoren die Saison jeder Mannschaft zu zeigen.';
$lang['SectionSeason'] = 'Vorsaison — {$a}';
$lang['ImportEventsLead'] = 'Jeder ausgewählte Mannschaftswettbewerb wird zu einer Kategorie mit seinen Mannschaften, die ihrem Verein zugeordnet sind. Ein bereits vorhandener Wettbewerb erhält nur die fehlenden Mannschaften.';
$lang['AlreadyListed'] = 'bereits vorhanden';
$lang['NoTeamEvent'] = 'Dieser Wettkampf hat keinen Mannschaftswettbewerb.';
$lang['ImportEvents'] = 'Mannschaften importieren';
$lang['LinkClubs'] = 'Mannschaften den Vereinen zuordnen';
$lang['LinkClubsHint'] = 'Für nach Namen eingegebene oder importierte Mannschaften: findet ihren Verein im verknüpften Wettbewerb durch Namensvergleich.';
$lang['ApplySeason'] = 'Saison {$a} in die Historie übernehmen';
$lang['ApplySeasonHint'] = 'Nur einmal: +1 Teilnahme je Mannschaft jener Saison, +1 Sieg für den Meister, +1 Podium für die ersten drei, Vorjahresplatz durch die Endplatzierung ersetzt.';
$lang['SeasonAppliedPill'] = 'Saison {$a} in die Historie übernommen';
$lang['ConfirmApplySeason'] = 'Saison {$a} in die Historie aller zugeordneten Mannschaften übernehmen? Das geht nur einmal.';
$lang['NationalOn'] = 'Nationale Ranglisten verfügbar: die Kommentatoren sehen den nationalen Rang jedes Schützen.';
$lang['NationalOff'] = 'Nationale Ranglisten nicht verfügbar. Sie werden vom Modul REPARTITION_EPREUVES heruntergeladen.';
$lang['FieldName'] = 'Name';
$lang['FieldEvent'] = 'Wettbewerb der Vorsaison';
$lang['EventNone'] = 'Kein verknüpfter Wettbewerb';
$lang['TeamCount'] = '{$a} Mannschaft(en)';
$lang['MoveUp'] = 'Nach oben';
$lang['MoveDown'] = 'Nach unten';
$lang['DeleteCategory'] = 'Kategorie löschen';
$lang['ConfirmDeleteCategory'] = 'Diese Kategorie und alle ihre Mannschaften löschen?';
$lang['ColTeam'] = 'Mannschaft';
$lang['ColClub'] = 'Vereinsnummer';
$lang['ColHint'] = 'Vorsaison';
$lang['ColHintTitle'] = 'Endplatzierung im verknüpften Wettbewerb des Vorsaison-Wettkampfs';
$lang['ColNote'] = 'Notiz für die Kommentatoren';
$lang['NoTeam'] = 'Keine Mannschaft in dieser Kategorie.';
$lang['DeleteTeam'] = 'Mannschaft löschen';
$lang['ConfirmDeleteTeam'] = 'Die Mannschaft „{$a}“ löschen?';
$lang['AddTeams'] = 'Mannschaften hinzufügen — eine pro Zeile, bei Bedarf gefolgt von ; und der Vereinsnummer';
$lang['AddTeamsPlaceholder'] = 'RIOM;0163157';
$lang['Add'] = 'Hinzufügen';
$lang['AddCategory'] = 'Kategorie hinzufügen';
$lang['AddCategoryHint'] = 'Eine Kategorie ist eine eigene Liste: Mannschaften, deren Reihenfolge gelost wird, oder die Etappen der Saison, einzeln vorgestellt, so wie der Sprecher sie ankündigt.';
$lang['SectionLook'] = 'Öffentlicher Bildschirm';
$lang['Preview'] = 'Vorschau';
$lang['LookBg'] = 'Hintergrundfarbe';
$lang['LookAccent'] = 'Akzentfarbe';
$lang['LookText'] = 'Textfarbe';
$lang['LookFontTitle'] = 'Schrift der Titel';
$lang['LookFontBody'] = 'Schrift des Textes';
$lang['LookSizeTitle'] = 'Titelgröße';
$lang['LookSizeTeam'] = 'Größe der Mannschaftsnamen';
$lang['LookSizeRank'] = 'Größe der Platznummern';
$lang['LookMargin'] = 'Ränder oben und unten';
$lang['LookOverlay'] = 'Schleier über dem Bild';
$lang['LookAmbient'] = 'Hintergrundanimation';
$lang['LookSlots'] = 'Noch auszulosende Plätze anzeigen';
$lang['LookImage'] = 'Hintergrundbild';
$lang['LookImageHint'] = 'JPEG, PNG, WebP oder GIF, höchstens 5 MB. Der Schleier in der Hintergrundfarbe hält den Text lesbar.';
$lang['ImageSet'] = 'Bild vorhanden';
$lang['ImageNone'] = 'Kein Bild';
$lang['ImageClear'] = 'Bild entfernen';

// History columns
$lang['StatParticipations'] = 'Teilnahmen';
$lang['StatWins'] = 'Siege';
$lang['StatPodiums'] = 'Podien';
$lang['StatRank'] = 'Vorjahresplatz';
$lang['StatParticipationsShort'] = 'Teiln.';
$lang['StatWinsShort'] = 'Siege';
$lang['StatPodiumsShort'] = 'Podien';
$lang['StatRankShort'] = 'Vorj.';

// Control page
$lang['OnAir'] = 'Auf dem öffentlichen Bildschirm';
$lang['SceneIdle'] = 'Warten';
$lang['SceneCategory'] = 'Laufende Kategorie';
$lang['SceneSummary'] = 'Übersicht';
$lang['Categories'] = 'Kategorien';
$lang['StatsShown'] = 'Angezeigte Historie';
$lang['UndoLast'] = 'Letzten Platz rückgängig machen';
$lang['ResetCategory'] = 'Kategorie zurücksetzen';
$lang['ConfirmReset'] = 'Alle in „{$a}“ gezogenen Plätze zurücknehmen?';
$lang['SearchTeam'] = 'Mannschaft suchen — Enter zieht sie, wenn nur eine passt';
$lang['NextPlace'] = 'Nächster Platz';
$lang['CategoryComplete'] = 'Alle Plätze sind gezogen';
$lang['PlaceN'] = 'Platz {$a}';
$lang['Unassign'] = 'Diesen Platz zurücknehmen';
$lang['NoMatch'] = 'Keine Mannschaft passt.';
$lang['NoCategory'] = 'Diese Auslosung hat noch keine Kategorie: fügen Sie sie auf der Vorbereitungsseite hinzu.';
$lang['NothingDrawn'] = 'Noch nichts gezogen.';
$lang['DrawOrder'] = 'Gezogene Reihenfolge';
$lang['LeftToDraw'] = 'Noch auszulosen';
$lang['PreviewTitle'] = 'Der öffentliche Bildschirm jetzt';

// Public screen
$lang['DrawKicker'] = 'Auslosung';
$lang['WaitingDraw'] = 'Warten auf die Auslosung…';

// Commentators' screen
$lang['SpeakerPick'] = 'Wählen Sie eine Mannschaft in den Listen.';
$lang['NotDrawnYet'] = 'Noch nicht gezogen';
$lang['NewInEvent'] = '{$a} nicht in diesem Wettbewerb';
$lang['SeasonTitle'] = 'Saison {$a}';
$lang['NotInEvent'] = 'Diese Mannschaft hat {$a[year]} nicht an diesem Wettbewerb teilgenommen.';
$lang['AlsoIn'] = 'Der Verein hatte auch eine Mannschaft in:';
$lang['FactRank'] = 'Endplatzierung';
$lang['FactPoints'] = 'Ranglistenpunkte';
$lang['FactMatches'] = 'Matches gewonnen–verloren';
$lang['FactAvg'] = 'Ringe pro Pfeil';
$lang['FactBest'] = 'Bestes Match, pro Pfeil';
$lang['FactStreak'] = 'Längste Siegesserie';
$lang['FactShootOffs'] = 'Stechen gewonnen–verloren';
$lang['FactSets'] = 'Satzpunkte für–gegen';
$lang['FactForm'] = 'Letzte Matches:';
$lang['FactStage'] = 'Etappe';
$lang['FactQualification'] = 'Qualifikation';
$lang['FactBonus'] = 'Bonus {$a}';
$lang['CompositionTitle'] = 'Schützen {$a}';
$lang['ColArcher'] = 'Schütze';
$lang['NationalOutdoor'] = 'Nationale Rangliste im Freien {$a}';
$lang['NationalIndoor'] = 'Nationale Rangliste Halle {$a}';
$lang['SpeakerNoClub'] = 'Keine Vereinsnummer für diese Mannschaft: ihre Vorsaison kann nicht gefunden werden. Tragen Sie sie auf der Vorbereitungsseite ein.';
$lang['Won'] = 'Gewonnen';
$lang['Lost'] = 'Verloren';
$lang['Ordinal1'] = '{$a}.';
$lang['OrdinalN'] = '{$a}.';

// Version 0.2.0: stages, list for the ianseo competition, medals, settings page
$lang['FieldType'] = 'Art der Liste';
$lang['TypeTeams'] = 'Auszulosende Mannschaften';
$lang['TypeStages'] = 'Etappen, einzeln vorgestellt';
$lang['StageCount'] = '{$a} Etappe(n)';
$lang['StageN'] = 'Etappe {$a}';
$lang['ColStageName'] = 'Ort';
$lang['ColStageDetail'] = 'Termine und Details';
$lang['AddStages'] = 'Etappen hinzufügen — eine pro Zeile, bei Bedarf gefolgt von ; und den Terminen';
$lang['AddStagesPlaceholder'] = 'Smarves;17. und 18. April 2027';
$lang['StagesHint'] = 'Hier wird nichts gelost: der öffentliche Bildschirm zeigt die Etappen in dieser Reihenfolge, eine Karte nach der anderen, so wie der Sprecher sie ankündigt.';
$lang['NoStage'] = 'Keine Etappe in dieser Liste.';
$lang['DeleteStage'] = 'Etappe löschen';
$lang['ShowStage'] = 'Zeigen';
$lang['ShowNextStage'] = 'Nächste Etappe zeigen';
$lang['HideStage'] = 'Diese Etappe wieder verbergen';
$lang['StagesShown'] = '{$a[shown]} von {$a[total]} Etappen gezeigt';
$lang['AllStagesShown'] = 'Alle Etappen sind gezeigt';
$lang['StagesList'] = 'Etappen';
$lang['StageOnScreen'] = 'auf dem Bildschirm';
$lang['StageUpcoming'] = 'noch nicht gezeigt';
$lang['PasteTitle'] = 'Liste für den ianseo-Wettkampf';
$lang['PasteHint'] = 'Vereinsnummern in gezogener Reihenfolge. Fügen Sie sie in das Textfeld unter der Spalte {$a} des Setup-Bildschirms des Erstliga-Wettkampfs der neuen Saison ein.';
$lang['PasteHintNoEvent'] = 'Vereinsnummern in gezogener Reihenfolge. Fügen Sie sie in das Textfeld der passenden Spalte des Setup-Bildschirms des Erstliga-Wettkampfs der neuen Saison ein.';
$lang['PasteMissingCode'] = 'Keine Vereinsnummer für: {$a}. Tragen Sie sie zuerst in den Einstellungen ein — ohne sie rückten in ianseo alle folgenden Mannschaften einen Platz auf.';
$lang['PasteIncomplete'] = 'Bisher erst {$a[drawn]} von {$a[total]} Plätzen gezogen';
$lang['CopyList'] = 'Liste kopieren';
$lang['ListCopied'] = 'Liste kopiert.';
$lang['MedalGold'] = 'Bester Wert der Kategorie';
$lang['MedalSilver'] = 'Zweitbester Wert der Kategorie';
$lang['MedalBronze'] = 'Drittbester Wert der Kategorie';
$lang['EditLead'] = 'Jede Änderung wird gespeichert, sobald Sie das Feld verlassen: es gibt keine Schaltfläche zum Bestätigen. Hierher gelangen Sie über die Liste der Auslosungen oder über die Regie.';

// Version 0.2.1: commentators' screen
$lang['BackToLive'] = 'Zurück zum Live-Geschehen';

// Version 0.2.3: spacing inside a slot of the public screen
$lang['LookRankSpace'] = 'Abstand Platznummer → Mannschaft';
$lang['LookStatsSpace'] = 'Abstand Mannschaft → Statistiken';
$lang['LookStatGap'] = 'Abstand zwischen Statistiken';
$lang['LookStatWidth'] = 'Breite einer Statistik';
