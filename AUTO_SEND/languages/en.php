<?php
/**
 * English strings of the scheduled upload module — the reference every other language
 * falls back to, so no key may be missing here.
 */

$lang['ModuleName'] = 'Scheduled upload';
$lang['MenuSettings'] = 'Settings and status';
$lang['MenuUpdate'] = 'Update the module';
$lang['UpdateTitle'] = 'Scheduled upload — update';
$lang['BackToSettings'] = 'Back to the settings';

$lang['PageTitle'] = 'Scheduled upload to ianseo.net';
$lang['PageLead'] = 'Opens and closes the scoring and sends the results to ianseo.net on its own, at the times set below, with no computer left on the upload page. The work is done by a task the server runs every minute.';

$lang['WarnNoCredentials'] = 'The ianseo.net codes of this competition are not saved, so no upload can be made. Enter them with the "remember" box ticked:';
$lang['WarnSimulation'] = 'Simulation mode: the results are built as for a real upload, but nothing is sent to ianseo.net. Untick it below before the real period.';

$lang['StatusTitle'] = 'Status';
$lang['Loading'] = 'Loading…';

$lang['PeriodTitle'] = 'Period';
$lang['Enabled'] = 'Schedule active';
$lang['EnabledHint'] = 'Unticked, nothing happens automatically and the scoring stays as it is.';
$lang['Start'] = 'Start';
$lang['End'] = 'End';
$lang['TimeZone'] = 'Time zone';
$lang['Interval'] = 'Upload every';
$lang['Minutes'] = 'minutes';
$lang['PeriodSaved'] = 'Saved period: from {$a[start]} to {$a[end]}.';
$lang['TimeZoneHint'] = 'Times are local to the chosen time zone, clock changes included: a period that starts in winter time and ends in summer time keeps the times typed here. A time that does not exist on the night of the change (02:00–03:00 in spring) is refused.';
$lang['IntervalHint'] = 'At least {$a} minutes. After a failure the next attempt comes sooner (1, 2, 4… minutes), and the normal pace resumes after the first success.';

$lang['SessionsTitle'] = 'Scoring (ISK-NG)';
$lang['SessionsNoRight'] = 'Your access does not allow managing the ISK-NG scoring: these settings are shown read-only.';
$lang['SessionsManage'] = 'Open the scoring at the start and close it at the end';
$lang['SessionsHint'] = 'Ticked sessions are opened at the start and closed at the end, once each: a session locked by hand during the period stays locked. At the end the scoring is closed before the last upload, so that it carries every score entered.';
$lang['SessionsNone'] = 'No scoring session in this competition yet.';

$lang['ItemsTitle'] = 'What is uploaded';
$lang['ItemsHint'] = 'The same lists as on the ianseo upload page. An item marked "not available yet" is sent from the moment ianseo offers it (brackets once the shoot-offs are resolved, medals once awarded).';
$lang['ItemWaiting'] = '(not available yet)';
$lang['ItemMissing'] = '(no longer exists in the competition)';

$lang['PingTitle'] = 'Outside monitoring (optional)';
$lang['PingUrl'] = 'Monitoring address (healthchecks.io or compatible)';
$lang['PingHint'] = 'The server calls this address after each upload and at least once per interval. If the calls stop — server down, network down, uploads failing — the monitoring service warns you by email or text message. Nothing is sent in simulation. See the README of the module.';

$lang['SimulationTitle'] = 'Simulation';
$lang['Simulation'] = 'Simulation: send nothing to ianseo.net';
$lang['SimulationHint'] = 'Everything runs for real — schedule, scoring, building of the results — except the upload itself, which is imitated. Useful to check the settings and measure the cost on the server before the real period.';

$lang['Save'] = 'Save';

$lang['TaskTitle'] = 'Scheduled task on the server';
$lang['TaskExplain'] = 'PHP only runs when a page is requested: nothing inside ianseo can wake up on its own at a given time. The server\'s scheduler therefore runs the module\'s task every minute; it looks at what is due (opening, upload, closing) and stops at once when nothing is. Without this task, nothing happens.';
$lang['TaskInstallLinux'] = 'To install once, as administrator of the server (Linux, web server running as www-data):';
$lang['TaskInstallWindows'] = 'To install once, in a command prompt run as administrator:';
$lang['TaskReadme'] = 'The README of the module explains how to check that the task runs and how to remove it.';

$lang['HeartbeatOk'] = 'The scheduled task runs.';
$lang['HeartbeatNever'] = 'The scheduled task has never run on this server: see "Scheduled task on the server" below.';
$lang['HeartbeatLate'] = 'The scheduled task has not run for {$a} minutes.';

$lang['PhaseDisabled'] = 'Schedule inactive.';
$lang['PhaseIncomplete'] = 'Schedule incomplete: the start or the end is missing.';
$lang['PhaseWaiting'] = 'Waiting for the start, on {$a}.';
$lang['PhaseRunning'] = 'Period running, until {$a}.';
$lang['PhaseFinishing'] = 'Period over: last upload in progress.';
$lang['PhaseDone'] = 'Period over since {$a}.';

$lang['KindSend'] = 'Upload';
$lang['KindManual'] = 'Upload on request';
$lang['KindFinal'] = 'Last upload';
$lang['KindOpen'] = 'Scoring opened';
$lang['KindClose'] = 'Scoring closed';

$lang['OutSent'] = 'Sent to ianseo.net.';
$lang['OutSentSimulated'] = 'Simulated: built, nothing sent.';
$lang['OutNothingYet'] = 'Nothing to send yet: the chosen items are not available.';
$lang['OutSessionsOpened'] = 'Scoring opened:';
$lang['OutSessionsClosed'] = 'Scoring closed:';
$lang['OutErrNoPlan'] = 'No schedule for this competition.';
$lang['OutErrNoCompetition'] = 'The competition no longer exists.';
$lang['OutErrNothingSelected'] = 'Nothing is chosen to be uploaded.';
$lang['OutErrNoCredentials'] = 'The ianseo.net codes of the competition are not saved.';
$lang['OutErrPublicationLocked'] = 'Publication is locked for this competition.';
$lang['OutErrCredentialsCheck'] = 'ianseo.net unreachable, or codes refused:';
$lang['OutErrIanseoNet'] = 'ianseo.net refused the upload:';
$lang['OutErrWorker'] = 'The upload stopped with an error:';
$lang['OutErrTimeout'] = 'The upload took more than {$a} seconds and was stopped.';
$lang['OutErrStart'] = 'The upload could not be started on the server.';

$lang['ErrToken'] = 'The form has expired: reload the page and try again.';
$lang['ErrAccess'] = 'Access refused.';
$lang['ErrTimeZone'] = 'Unknown time zone.';
$lang['ErrDate'] = 'invalid date or time.';
$lang['ErrTimeGap'] = 'this time does not exist in this time zone (clock change).';
$lang['ErrEndBeforeStart'] = 'The end must come after the start.';
$lang['ErrInterval'] = 'The interval must be between {$a[min]} and {$a[max]} minutes.';
$lang['ErrPingUrl'] = 'The monitoring address must be a web address starting with https:// or http://.';
$lang['ErrDatesRequired'] = 'An active schedule needs a start and an end.';
$lang['ErrEndPast'] = 'The end is already past.';
$lang['ErrNoSession'] = 'Tick at least one scoring session, or untick the opening and closing of the scoring.';
$lang['ErrRunArchery'] = 'Run Archery competitions are not supported by this module.';

$lang['SavedDisabled'] = 'Saved. The schedule is inactive.';
$lang['SavedRunning'] = 'Saved. The period has begun: the scheduled task acts within the minute.';
$lang['SavedWaiting'] = 'Saved. The scheduled task will act at the start.';

$lang['JsLoadError'] = 'The status could not be read.';
$lang['JsTask'] = 'Task';
$lang['JsSimulation'] = 'simulation';
$lang['JsLastSuccess'] = 'Last successful upload:';
$lang['JsNever'] = 'never';
$lang['JsNextSend'] = 'Next upload:';
$lang['JsFailures'] = 'Consecutive failures:';
$lang['JsSessions'] = 'Scoring:';
$lang['JsOpen'] = 'open';
$lang['JsClosed'] = 'closed';
$lang['JsSendNow'] = 'Upload now';
$lang['JsSendNowPending'] = 'Upload requested…';
$lang['JsSendNowHint'] = 'The scheduled task makes it within the minute, imitated if simulation is ticked.';
$lang['JsHistory'] = 'History';
$lang['JsNoRun'] = 'Nothing done yet.';
$lang['JsWhen'] = 'When';
$lang['JsKind'] = 'Action';
$lang['JsResult'] = 'Result';
$lang['JsDuration'] = 'Duration';
$lang['JsSize'] = 'Size';
$lang['JsDetail'] = 'Detail';
$lang['JsOk'] = 'OK';
$lang['JsKo'] = 'failed';
$lang['JsAll'] = 'all';
$lang['JsNone'] = 'none';
