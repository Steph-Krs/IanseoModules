<?php
/**
 * German strings of the scheduled upload module. Keys missing here fall back
 * to the English file, which holds the reference list.
 */

$lang['ModuleName'] = 'Geplanter Upload';
$lang['MenuSettings'] = 'Einstellungen und Status';
$lang['MenuUpdate'] = 'Modul aktualisieren';
$lang['UpdateTitle'] = 'Geplanter Upload — Aktualisierung';
$lang['BackToSettings'] = 'Zurück zu den Einstellungen';

$lang['PageTitle'] = 'Geplanter Upload zu ianseo.net';
$lang['PageLead'] = 'Öffnet und schließt die Ergebniserfassung und sendet die Ergebnisse selbstständig an ianseo.net, zu den unten eingestellten Zeiten, ohne einen Computer, der auf der Upload-Seite offen bleibt. Die Arbeit erledigt eine Aufgabe, die der Server jede Minute ausführt.';

$lang['WarnNoCredentials'] = 'Die ianseo.net-Codes dieses Turniers sind nicht gespeichert: Es ist kein Upload möglich. Geben Sie sie mit angekreuztem Feld „merken“ ein:';
$lang['WarnSimulation'] = 'Simulationsmodus: Die Ergebnisse werden wie für einen echten Upload erstellt, aber nichts wird an ianseo.net gesendet. Vor dem echten Zeitraum unten abwählen.';

$lang['StatusTitle'] = 'Status';
$lang['Loading'] = 'Wird geladen…';

$lang['PeriodTitle'] = 'Zeitraum';
$lang['Enabled'] = 'Planung aktiv';
$lang['EnabledHint'] = 'Nicht angekreuzt geschieht nichts automatisch, und die Erfassung bleibt, wie sie ist.';
$lang['Start'] = 'Beginn';
$lang['End'] = 'Ende';
$lang['TimeZone'] = 'Zeitzone';
$lang['Interval'] = 'Upload alle';
$lang['Minutes'] = 'Minuten';
$lang['PeriodSaved'] = 'Gespeicherter Zeitraum: vom {$a[start]} bis {$a[end]}.';
$lang['TimeZoneHint'] = 'Die Zeiten gelten in der gewählten Zeitzone, Zeitumstellungen inbegriffen: Ein Zeitraum, der in der Winterzeit beginnt und in der Sommerzeit endet, behält die hier eingegebenen Zeiten. Eine Zeit, die es in der Nacht der Umstellung nicht gibt (02:00–03:00 im Frühjahr), wird abgelehnt.';
$lang['IntervalHint'] = 'Mindestens {$a} Minuten. Nach einem Fehler kommt der nächste Versuch früher (1, 2, 4… Minuten), und der normale Takt setzt nach dem ersten Erfolg wieder ein.';

$lang['SessionsTitle'] = 'Ergebniserfassung (ISK-NG)';
$lang['SessionsNoRight'] = 'Ihre Rechte erlauben es nicht, die ISK-NG-Erfassung zu verwalten: Diese Einstellungen sind schreibgeschützt.';
$lang['SessionsManage'] = 'Erfassung zu Beginn öffnen und am Ende schließen';
$lang['SessionsHint'] = 'Die angekreuzten Sitzungen werden zu Beginn geöffnet und am Ende geschlossen, jeweils nur einmal: Eine während des Zeitraums von Hand gesperrte Sitzung bleibt gesperrt. Am Ende wird die Erfassung vor dem letzten Upload geschlossen, damit dieser alle erfassten Ergebnisse enthält.';
$lang['SessionsNone'] = 'Noch keine Erfassungssitzung in diesem Turnier.';

$lang['ItemsTitle'] = 'Was gesendet wird';
$lang['ItemsHint'] = 'Dieselben Listen wie auf der Upload-Seite von ianseo. Ein als „noch nicht verfügbar“ markiertes Element wird gesendet, sobald ianseo es anbietet (Brackets nach entschiedenen Stechen, Medaillen nach ihrer Vergabe).';
$lang['ItemWaiting'] = '(noch nicht verfügbar)';
$lang['ItemMissing'] = '(existiert im Turnier nicht mehr)';

$lang['PingTitle'] = 'Externe Überwachung (optional)';
$lang['PingUrl'] = 'Überwachungsadresse (healthchecks.io oder kompatibel)';
$lang['PingHint'] = 'Der Server ruft diese Adresse nach jedem Upload und mindestens einmal pro Intervall auf. Bleiben die Aufrufe aus — Server aus, Netzwerk unterbrochen, Uploads fehlgeschlagen —, warnt Sie der Überwachungsdienst per E-Mail oder SMS. In der Simulation wird nichts gesendet. Siehe README des Moduls.';

$lang['SimulationTitle'] = 'Simulation';
$lang['Simulation'] = 'Simulation: nichts an ianseo.net senden';
$lang['SimulationHint'] = 'Alles läuft echt ab — Zeiten, Erfassung, Erstellung der Ergebnisse — außer dem Upload selbst, der nachgeahmt wird. Nützlich, um die Einstellungen zu prüfen und die Last auf dem Server vor dem echten Zeitraum zu messen.';

$lang['Save'] = 'Speichern';

$lang['TaskTitle'] = 'Geplante Aufgabe auf dem Server';
$lang['TaskExplain'] = 'PHP läuft nur, wenn eine Seite aufgerufen wird: Nichts in ianseo kann von selbst zu einer bestimmten Zeit aufwachen. Der Planer des Servers führt deshalb die Aufgabe des Moduls jede Minute aus; sie prüft, was fällig ist (Öffnen, Upload, Schließen), und endet sofort, wenn nichts fällig ist. Ohne diese Aufgabe geschieht nichts.';
$lang['TaskInstallLinux'] = 'Einmalig zu installieren, als Administrator des Servers (Linux, Webserver unter dem Benutzer www-data):';
$lang['TaskInstallWindows'] = 'Einmalig zu installieren, in einer als Administrator geöffneten Eingabeaufforderung:';
$lang['TaskReadme'] = 'Das README des Moduls erklärt, wie man prüft, ob die Aufgabe läuft, und wie man sie entfernt.';

$lang['HeartbeatOk'] = 'Die geplante Aufgabe läuft.';
$lang['HeartbeatNever'] = 'Die geplante Aufgabe ist auf diesem Server noch nie gelaufen: siehe „Geplante Aufgabe auf dem Server“ weiter unten.';
$lang['HeartbeatLate'] = 'Die geplante Aufgabe ist seit {$a} Minuten nicht gelaufen.';

$lang['PhaseDisabled'] = 'Planung inaktiv.';
$lang['PhaseIncomplete'] = 'Planung unvollständig: Beginn oder Ende fehlt.';
$lang['PhaseWaiting'] = 'Warten auf den Beginn am {$a}.';
$lang['PhaseRunning'] = 'Zeitraum läuft, bis {$a}.';
$lang['PhaseFinishing'] = 'Zeitraum beendet: letzter Upload läuft.';
$lang['PhaseDone'] = 'Zeitraum beendet seit {$a}.';

$lang['KindSend'] = 'Upload';
$lang['KindManual'] = 'Upload auf Anfrage';
$lang['KindFinal'] = 'Letzter Upload';
$lang['KindOpen'] = 'Erfassung geöffnet';
$lang['KindClose'] = 'Erfassung geschlossen';

$lang['OutSent'] = 'An ianseo.net gesendet.';
$lang['OutSentSimulated'] = 'Simuliert: erstellt, nichts gesendet.';
$lang['OutNothingYet'] = 'Noch nichts zu senden: Die gewählten Elemente sind nicht verfügbar.';
$lang['OutSessionsOpened'] = 'Erfassung geöffnet:';
$lang['OutSessionsClosed'] = 'Erfassung geschlossen:';
$lang['OutErrNoPlan'] = 'Keine Planung für dieses Turnier.';
$lang['OutErrNoCompetition'] = 'Das Turnier existiert nicht mehr.';
$lang['OutErrNothingSelected'] = 'Nichts ist zum Senden ausgewählt.';
$lang['OutErrNoCredentials'] = 'Die ianseo.net-Codes des Turniers sind nicht gespeichert.';
$lang['OutErrPublicationLocked'] = 'Die Veröffentlichung ist für dieses Turnier gesperrt.';
$lang['OutErrCredentialsCheck'] = 'ianseo.net nicht erreichbar oder Codes abgelehnt:';
$lang['OutErrIanseoNet'] = 'ianseo.net hat den Upload abgelehnt:';
$lang['OutErrWorker'] = 'Der Upload wurde mit einem Fehler abgebrochen:';
$lang['OutErrTimeout'] = 'Der Upload dauerte länger als {$a} Sekunden und wurde abgebrochen.';
$lang['OutErrStart'] = 'Der Upload konnte auf dem Server nicht gestartet werden.';

$lang['ErrToken'] = 'Das Formular ist abgelaufen: Seite neu laden und erneut versuchen.';
$lang['ErrAccess'] = 'Zugriff verweigert.';
$lang['ErrTimeZone'] = 'Unbekannte Zeitzone.';
$lang['ErrDate'] = 'ungültiges Datum oder ungültige Uhrzeit.';
$lang['ErrTimeGap'] = 'diese Uhrzeit gibt es in dieser Zeitzone nicht (Zeitumstellung).';
$lang['ErrEndBeforeStart'] = 'Das Ende muss nach dem Beginn liegen.';
$lang['ErrInterval'] = 'Das Intervall muss zwischen {$a[min]} und {$a[max]} Minuten liegen.';
$lang['ErrPingUrl'] = 'Die Überwachungsadresse muss eine Webadresse sein, die mit https:// oder http:// beginnt.';
$lang['ErrDatesRequired'] = 'Eine aktive Planung braucht einen Beginn und ein Ende.';
$lang['ErrEndPast'] = 'Das Ende liegt bereits in der Vergangenheit.';
$lang['ErrNoSession'] = 'Kreuzen Sie mindestens eine Erfassungssitzung an, oder wählen Sie das Öffnen und Schließen der Erfassung ab.';
$lang['ErrRunArchery'] = 'Run-Archery-Turniere werden von diesem Modul nicht unterstützt.';

$lang['SavedDisabled'] = 'Gespeichert. Die Planung ist inaktiv.';
$lang['SavedRunning'] = 'Gespeichert. Der Zeitraum hat begonnen: Die geplante Aufgabe handelt innerhalb einer Minute.';
$lang['SavedWaiting'] = 'Gespeichert. Die geplante Aufgabe handelt zu Beginn des Zeitraums.';

$lang['JsLoadError'] = 'Der Status konnte nicht gelesen werden.';
$lang['JsTask'] = 'Aufgabe';
$lang['JsSimulation'] = 'Simulation';
$lang['JsLastSuccess'] = 'Letzter erfolgreicher Upload:';
$lang['JsNever'] = 'nie';
$lang['JsNextSend'] = 'Nächster Upload:';
$lang['JsFailures'] = 'Fehler in Folge:';
$lang['JsSessions'] = 'Erfassung:';
$lang['JsOpen'] = 'offen';
$lang['JsClosed'] = 'geschlossen';
$lang['JsSendNow'] = 'Jetzt senden';
$lang['JsSendNowPending'] = 'Upload angefordert…';
$lang['JsSendNowHint'] = 'Die geplante Aufgabe erledigt ihn innerhalb einer Minute, nachgeahmt, wenn die Simulation angekreuzt ist.';
$lang['JsHistory'] = 'Verlauf';
$lang['JsNoRun'] = 'Noch nichts geschehen.';
$lang['JsWhen'] = 'Wann';
$lang['JsKind'] = 'Aktion';
$lang['JsResult'] = 'Ergebnis';
$lang['JsDuration'] = 'Dauer';
$lang['JsSize'] = 'Größe';
$lang['JsDetail'] = 'Detail';
$lang['JsOk'] = 'OK';
$lang['JsKo'] = 'Fehler';
$lang['JsAll'] = 'alle';
$lang['JsNone'] = 'keine';
