<?php
/**
 * English strings of the training module: the reference list of keys.
 */

// Module and menu
$lang['ModuleName'] = 'Training';
$lang['MenuCreate'] = 'Create a training';
$lang['MenuHome'] = 'Trainings';
$lang['MenuDashboard'] = 'Training dashboard';
$lang['MenuParticipants'] = 'Archers of the training';
$lang['MenuTeams'] = 'Teams';
$lang['MenuRanking'] = 'Ranking and duels';
$lang['MenuResults'] = 'Results';
$lang['MenuFormats'] = 'Reference formats';
$lang['MenuArchers'] = 'Regular archers';
$lang['MenuFull'] = 'Full menu on this browser';
$lang['MenuSimple'] = 'Simplified menu on this browser';
$lang['MenuIsFull'] = 'This browser shows the full ianseo menu.';
$lang['MenuIsSimple'] = 'This browser shows the simplified menu of the trainings.';
$lang['MenuUpdate'] = 'Module update';
$lang['UpdateTitle'] = 'Training - module update';
$lang['BackToPage'] = 'Back to the trainings';
$lang['DateFmt'] = 'd/m/Y';

// Common words
$lang['Save'] = 'Save';
$lang['Saved'] = 'Saved.';
$lang['Cancel'] = 'Cancel';
$lang['Delete'] = 'Delete';
$lang['Edit'] = 'Edit';
$lang['Add'] = 'Add';
$lang['Remove'] = 'Remove';
$lang['Rename'] = 'Rename';
$lang['Open'] = 'Open';
$lang['Choose'] = 'Choose';
$lang['Filter'] = 'Filter…';
$lang['CheckAll'] = 'Tick all';
$lang['BackDashboard'] = 'Back to the dashboard';
$lang['SessionN'] = 'Session {$a}';
$lang['SexM'] = 'Man';
$lang['SexF'] = 'Woman';
$lang['DuelsOnly'] = 'Duels only';
$lang['ErrToken'] = 'The form had expired. Please try again.';

// Columns
$lang['ColDate'] = 'Date';
$lang['ColName'] = 'Name';
$lang['ColFormat'] = 'Format';
$lang['ColArchers'] = 'Archers';
$lang['ColArcher'] = 'Archer';
$lang['ColCategory'] = 'Category';
$lang['ColSession'] = 'Session';
$lang['ColTarget'] = 'Target';
$lang['ColContent'] = 'Content';
$lang['ColUpdated'] = 'Updated';

// Home
$lang['HomeTitle'] = 'Trainings';
$lang['HomeLead'] = 'A training is a real ianseo competition made in a few clicks from a reference format: choose the format, the date and the starting time, tick the archers, and everything else is ready - categories, events, target faces, distances, programme. The coach then follows the steps of the training dashboard.';
$lang['HomeRuns'] = 'Trainings already created';
$lang['HomeNoRun'] = 'No training yet.';
$lang['HomeNoFormat'] = 'No reference format yet. Prepare a competition in ianseo with every setting you want, open it, then save it on the Reference formats page.';
$lang['HomePrepare'] = 'Preparing the trainings';

// Formats
$lang['FormatsTitle'] = 'Reference formats';
$lang['FormatsLead'] = 'A format is a copy of a whole competition without its archers: divisions and classes, events, target faces, distances, images, competition settings and programme. Once saved, the competition it comes from is no longer needed and may be deleted.';
$lang['FormatsFromOpen'] = 'Save the open competition';
$lang['FormatsNoOpen'] = 'Open the competition to save as a format first.';
$lang['FormatsOpenIs'] = 'Open competition: {$a[code]} - {$a[name]}';
$lang['FormatName'] = 'Name of the format';
$lang['FormatSimple'] = 'Simplified menu for the trainings made from this format';
$lang['FormatSimpleShort'] = 'Simplified menu';
$lang['FormatReplace'] = 'Replace an existing format';
$lang['FormatReplaceNone'] = 'No, create a new format';
$lang['FormatsWhatKept'] = 'Archers, teams, scores and results are not kept, nor the ianseo.net codes of the competition. The times of the programme are kept as gaps from the first start.';
$lang['FormatSave'] = 'Save as a format';
$lang['FormatSaved'] = 'Format "{$a}" saved.';
$lang['FormatReplaced'] = 'Format "{$a}" replaced by the open competition.';
$lang['FormatsList'] = 'Saved formats';
$lang['FormatContent'] = '{$a[type]} - {$a[sessions]} session(s) - events: {$a[events]}';
$lang['FormatSource'] = 'From {$a}';
$lang['FormatDeleteConfirm'] = 'Delete the format "{$a}"? The trainings already created are not affected.';
$lang['FormatDeleted'] = 'Format deleted.';

// Regular archers
$lang['ArchersTitle'] = 'Regular archers';
$lang['ArchersLead'] = 'The archers who often take part in the trainings: they only have to be ticked when a training is created. Groups tick several archers at once.';
$lang['ArcherAdd'] = 'Add an archer';
$lang['ArcherEdit'] = 'Edit an archer';
$lang['LookupLabel'] = 'Search the licence file:';
$lang['LookupPlaceholder'] = 'licence or family name';
$lang['LookupNone'] = 'Nobody found in the licence file.';
$lang['FieldFamily'] = 'Family name';
$lang['FieldGiven'] = 'Given name';
$lang['FieldSex'] = 'Sex';
$lang['FieldDob'] = 'Date of birth';
$lang['FieldCode'] = 'Licence';
$lang['FieldIoc'] = 'Country';
$lang['FieldClub'] = 'Club';
$lang['FieldClubName'] = 'Club name';
$lang['FieldDiv'] = 'Division (bow)';
$lang['FieldCls'] = 'Class';
$lang['FieldActive'] = 'Offered when creating a training';
$lang['FieldGroups'] = 'Groups';
$lang['FieldDate'] = 'Date';
$lang['FieldStart'] = 'Starting time';
$lang['ErrNoName'] = 'The family name is required.';
$lang['ArchersList'] = 'Archers ({$a})';
$lang['ArchersNone'] = 'No regular archer yet.';
$lang['ArcherDeleteConfirm'] = 'Delete {$a} from the regular archers?';
$lang['ArcherDeleted'] = 'Archer deleted.';
$lang['GroupsTitle'] = 'Groups';
$lang['GroupNew'] = 'New group';
$lang['GroupDeleteConfirm'] = 'Delete the group "{$a}"? Its archers stay in the list.';
$lang['ImportTitle'] = 'Copy archers of the open competition';
$lang['ImportNone'] = 'Every archer of the open competition is already in the list.';
$lang['ImportDo'] = 'Copy the ticked archers';
$lang['ArchersImported'] = '{$a} archer(s) copied.';

// Creation
$lang['CreateTitle'] = 'Create a training';
$lang['ChooseFormat'] = '- choose a format -';
$lang['CreateDays'] = 'This format lasts {$a} days: the following days are moved as well.';
$lang['CreateShift'] = 'Every time of the programme moves with the starting time and keeps the gaps of the format.';
$lang['QualSim'] = 'Duels only (simulated qualification round)';
$lang['QualSimHelp'] = 'Every archer gets one point; the order of the brackets is then chosen on the Ranking and duels page.';
$lang['PlaceAuto'] = 'Place the archers on the targets';
$lang['CreateArchers'] = 'Archers';
$lang['CountPicked'] = '{$a} archer(s) ticked';
$lang['CreateDo'] = 'Create the training';
$lang['Created'] = 'Training {$a[code]} created with {$a[n]} archer(s).';
$lang['CreatedSkipped'] = '{$a} archer(s) could not be entered: category missing from the format.';
$lang['ErrNoFormat'] = 'Choose a format.';
$lang['ErrDate'] = 'The date is not valid.';
$lang['ErrNoArcher'] = 'Tick at least one archer.';
$lang['ErrNoCode'] = 'Every code of that day is taken (99 trainings).';
$lang['ErrImport'] = 'The format was saved by a more recent ianseo: update ianseo first.';

// Teams
$lang['TeamsTitleAll'] = 'Teams';
$lang['TeamsTitle'] = 'Teams';
$lang['MixedTitle'] = 'Mixed teams';
$lang['TeamN'] = 'Team {$a}';
$lang['MixedN'] = 'Mixed {$a}';
$lang['TeamAdd'] = 'Add a team';
$lang['MixedAdd'] = 'Add a mixed team';
$lang['AddArcher'] = '+ archer';
$lang['RemoveTeam'] = 'Remove the team';
$lang['TeamsHelpCreate'] = 'Only the ticked archers can be chosen. The teams can also be composed or changed later, from the dashboard.';
$lang['TeamsHelp'] = 'Compose the teams now or at any time: before, during or after the qualification round. An archer may be in one team and in a different mixed team. The team brackets are filled on the Ranking and duels page.';
$lang['TeamsNone'] = 'This training has no team event.';

// Dashboard
$lang['DashTitle'] = 'Training dashboard';
$lang['DashNotRun'] = 'This competition was not created by the Training module; the dashboard still works.';
$lang['StepArchers'] = 'Archers';
$lang['StepTargets'] = 'Targets';
$lang['StepScorecards'] = 'Scorecards';
$lang['StepScores'] = 'Scores';
$lang['StepQualSim'] = 'Qualification round';
$lang['StepDuels'] = 'Duels';
$lang['StepResults'] = 'Results';
$lang['DashArchers'] = '{$a} archer(s) in the training.';
$lang['DashTeams'] = '{$a} team(s) composed';
$lang['DashPlaced'] = '{$a[on]} of {$a[all]} archer(s) on a target';
$lang['PlaceDo'] = 'Place the archers without a target';
$lang['PlaceAgain'] = 'Place everybody again';
$lang['PlaceAgainConfirm'] = 'Remove every target of this session and place everybody again?';
$lang['PlaceManual'] = 'Target plan: move archers by hand';
$lang['Placed'] = '{$a} archer(s) placed.';
$lang['PlacedLeft'] = '{$a} archer(s) could not be placed: not enough targets in the session.';
$lang['ScorecardsOf'] = 'Scorecards - {$a}';
$lang['MoreOptions'] = 'More options';
$lang['DashScores'] = '{$a[done]} of {$a[all]} archer(s) have a score.';
$lang['QualRanking'] = 'Qualification ranking';
$lang['QualRankingTeam'] = 'Team qualification ranking';
$lang['QualSimDone'] = 'Simulated: every archer has one point. Choose the order of the brackets on the Ranking and duels page.';
$lang['EventLaunched'] = 'brackets filled';
$lang['EventWaiting'] = 'brackets to fill';
$lang['EventCore'] = 'eliminations or pools: core screens';
$lang['FinalRanking'] = 'Final ranking';
$lang['FinalRankingTeam'] = 'Team final ranking';

// Participants
$lang['ParticipantsTitle'] = 'Archers of the training';
$lang['ParticipantsIn'] = 'In the training ({$a})';
$lang['ParticipantsAdd'] = 'Add regular archers';
$lang['ParticipantsAllIn'] = 'Every regular archer is already in the training.';
$lang['ParticipantsAddDo'] = 'Add the ticked archers';
$lang['ArchersAdded'] = '{$a} archer(s) added.';
$lang['ArcherRemoveConfirm'] = 'Take {$a} out of the training, with their scores?';
$lang['ArcherRemoved'] = 'Archer taken out.';

// Ranking and duels
$lang['RankingTitle'] = 'Ranking and duels';
$lang['RankingLead'] = 'Each list is the order of the bracket, best first: the qualification order, ties next to each other. Drag the names, or use the arrows, then fill the bracket.';
$lang['RankingNone'] = 'This training has no event with duels.';
$lang['RankingCore'] = 'This event has eliminations or pools: use the core screen.';
$lang['OpenCore'] = 'Open';
$lang['RankingNoArcher'] = 'No archer in this event.';
$lang['RankingNoTeam'] = 'No team yet: compose the teams, and make sure their archers have a score.';
$lang['RankingQualified'] = 'The first {$a[n]} of {$a[all]} go to the bracket.';
$lang['Tie'] = 'tie';
$lang['MoveUp'] = 'Up';
$lang['MoveDown'] = 'Down';
$lang['Launch'] = 'Fill the bracket';
$lang['Relaunch'] = 'Fill the bracket again';
$lang['RelaunchConfirm'] = 'The bracket of {$a} is already filled: filling it again erases the duels already entered. Continue?';
$lang['DuelsLaunched'] = 'Bracket of {$a} filled.';
$lang['ErrLaunch'] = 'The bracket of {$a} could not be filled: reload the page and try again.';

// Import and export
$lang['MenuTransfer'] = 'Import / export';
$lang['TransferTitle'] = 'Import / export';
$lang['TransferLead'] = 'Move the reference formats, the regular archers and their groups to another installation, for instance to set up another training centre the same way.';
$lang['ExportTitle'] = 'Export';
$lang['ExportArchers'] = 'Regular archers ({$a[archers]}) and groups ({$a[groups]})';
$lang['ExportPersonal'] = 'The file then holds personal data (names, dates of birth, licences): keep it like any list of archers.';
$lang['ExportDo'] = 'Download the file';
$lang['ExportNothing'] = 'Choose at least one format, or the archers.';
$lang['ImportFileTitle'] = 'Import';
$lang['ImportReplace'] = 'Replace a format that has the same name';
$lang['ImportHelp'] = 'An archer already in the list (same licence, otherwise same name and date of birth) is updated from the file; the others are added. Groups are matched by name. A format saved by a more recent ianseo than this one is refused: update ianseo first.';
$lang['ImportFileDo'] = 'Import the file';
$lang['ImportedSummary'] = 'Imported: {$a[formats]} new format(s), {$a[replaced]} replaced, {$a[archers]} new archer(s), {$a[updated]} updated, {$a[groups]} new group(s).';
$lang['ImportRefused'] = 'Formats refused (unreadable, or saved by a more recent ianseo): {$a}';
$lang['ErrFile'] = 'This file is not an export of the Training module.';
$lang['ErrFileSize'] = 'The file could not be received: it may be larger than what the server accepts.';
$lang['ClassAuto'] = 'The class is filled from the date of birth and the sex; it is computed again for the date of each training.';

// ISK-Lite
$lang['IskTitle'] = 'Scoring with ISK-Lite';
$lang['IskLead'] = 'Every training is set up for scoring with the ISK app in its free mode (ISK-Lite): the scorecards carry the QR codes of the app, and the scores reach ianseo on their own.';
$lang['IskUrl'] = 'Server address for the phones:';
$lang['IskUrlHelp'] = 'Given to every new training. The phones must reach this address: use the address of this computer on the network, not localhost.';
$lang['IskUrlNone'] = 'No address yet: the trainings will have ISK-Lite without a server address, to be entered in the competition settings. Use the address of this computer on the network, not localhost.';
$lang['IskUrlBad'] = 'This address cannot be read. Example: http://192.168.1.10';

// Targets and rhythm
$lang['ManualQualEntry'] = 'Manual entry of the qualification scores';
$lang['Rhythm'] = 'Archers per target:';
$lang['RhythmConfirm'] = 'Change to {$a} and place every archer of this session again?';
$lang['RhythmChanged'] = 'Archers per target changed: everybody placed again.';
$lang['TargetsMissing'] = '{$a[off]} archer(s) without a target: the session has {$a[have]} targets, {$a[need]} are needed to place everybody at {$a[rhythm]}.';
$lang['TargetsOff'] = '{$a[off]} archer(s) without a target: use Place the archers without a target.';
$lang['TargetsHave'] = '{$a} targets';
$lang['TargetsAdjust'] = 'Adjust to {$a} targets';
$lang['TargetsAdjusted'] = 'The session now has {$a} targets: everybody placed again.';
