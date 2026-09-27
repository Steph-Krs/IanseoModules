<?php
/**
 * English strings of the live draw module — the reference every other language
 * falls back to, so no key may be missing here.
 */

// Module and menu
$lang['ModuleName'] = 'Live draw';
$lang['MenuShows'] = 'Draws';
$lang['MenuUpdate'] = 'Update module';
$lang['UpdateTitle'] = 'Live draw — module update';

// Home page
$lang['HomeLead'] = 'Screens for a live draw ceremony: a public screen for the room or the broadcast, a control page to record each team as it is drawn, and a screen for the commentators. A draw belongs to no competition.';
$lang['ShowsTitle'] = 'Draws';
$lang['ShowsNone'] = 'No draw yet. Create one below, or import a save of the former draw page.';
$lang['ColTitle'] = 'Draw';
$lang['ColProgress'] = 'Progress';
$lang['ColUpdated'] = 'Last change';
$lang['ColScreens'] = 'Screens';
$lang['Progress'] = '{$a[drawn]} of {$a[total]} places drawn';
$lang['OpenControl'] = 'Control';
$lang['OpenEdit'] = 'Settings and teams';
$lang['OpenDisplay'] = 'Public screen';
$lang['OpenSpeaker'] = 'Commentators';
$lang['Duplicate'] = 'Copy';
$lang['DuplicateHint'] = 'Copy the lists, history, notes and look into a new draw with no place drawn — this is how next year\'s draw starts.';
$lang['ConfirmDeleteShow'] = 'Delete the draw "{$a}" with its lists, history and notes?';
$lang['Delete'] = 'Delete';
$lang['SourceNone'] = 'No previous-season competition';
$lang['CreateTitle'] = 'New draw';
$lang['FieldTitle'] = 'Title';
$lang['FieldTitlePlaceholder'] = 'First division draw 2027';
$lang['FieldSource'] = 'Previous-season competition';
$lang['FieldSourceHint'] = 'Optional. The ianseo competition of the season just played: its teams can be imported, and the commentators get each team\'s season, results and archers.';
$lang['Create'] = 'Create';
$lang['ImportTitle'] = 'Import a save of the former draw page';
$lang['ImportLead'] = 'The stand-alone HTML page used before this module exported its state as a JSON file. Give that file, and the page itself if you still have it: category names and complete team lists are only written in the page. Places drawn are not imported.';
$lang['ImportSave'] = 'Save (.json)';
$lang['ImportPage'] = 'Former page (.html), optional';
$lang['Import'] = 'Import';
$lang['CopyOf'] = 'Copy of {$a}';
$lang['LegacyTitle'] = 'Imported draw';
$lang['LegacyCategory'] = 'Category {$a}';
$lang['EditTitle'] = 'Settings and teams';
$lang['ControlTitle'] = 'Control';
$lang['SpeakerTitle'] = 'Commentators';
$lang['BackToShows'] = 'All draws';
$lang['Loading'] = 'Loading…';

// Errors and messages
$lang['ErrToken'] = 'The session has expired. Reload the page and try again.';
$lang['ErrNameEmpty'] = 'A name is required.';
$lang['ErrNoShow'] = 'This draw no longer exists.';
$lang['ErrNoCategory'] = 'This category no longer exists.';
$lang['ErrNoTeam'] = 'This team no longer exists.';
$lang['ErrNoSource'] = 'Choose the previous-season competition first.';
$lang['ErrField'] = 'Unknown field.';
$lang['ErrAction'] = 'Unknown action.';
$lang['ErrImage'] = 'The file is not an accepted image (JPEG, PNG, WebP or GIF, 5 MB at most).';
$lang['ErrLink'] = 'This screen link is not valid. It may have been renewed: ask for the new one.';
$lang['ErrLegacyFormat'] = 'This file is not a save of the former draw page.';
$lang['ErrLegacyEmpty'] = 'The save holds no category.';
$lang['ErrNetwork'] = 'The server did not answer. Check the connection and try again.';
$lang['ErrSeasonApplied'] = 'This season has already been added to the history.';
$lang['ErrNoEventChecked'] = 'Tick at least one event.';
$lang['MsgImported'] = '{$a} team(s) imported.';
$lang['MsgLinked'] = '{$a} team(s) linked to their club.';
$lang['MsgSeasonApplied'] = 'History updated for {$a[updated]} team(s); previous rank cleared for {$a[cleared]} team(s) absent from that season.';

// Preparation page
$lang['SectionGeneral'] = 'Draw';
$lang['FieldSubtitle'] = 'Subtitle';
$lang['LinksTitle'] = 'Screen links';
$lang['LinksHint'] = 'Open these links on the machines showing the screens. No ianseo account is needed: whoever has a link sees that screen, so give the commentators\' link to the commentators only.';
$lang['Copy'] = 'Copy';
$lang['Copied'] = 'Link copied.';
$lang['Open'] = 'Open';
$lang['NewTokens'] = 'Renew the links';
$lang['NewTokensHint'] = 'The current links stop working at once.';
$lang['ConfirmNewTokens'] = 'Renew both links? Screens already open stop updating until they are opened with the new links.';
$lang['SectionSeasonNone'] = 'Previous season';
$lang['SeasonNoSource'] = 'Choose the previous-season competition above to import its teams and give the commentators each team\'s season.';
$lang['SectionSeason'] = 'Previous season — {$a}';
$lang['ImportEventsLead'] = 'Each ticked team event becomes a category holding its teams, linked to their club. An event already listed only receives the teams it is missing.';
$lang['AlreadyListed'] = 'already listed';
$lang['NoTeamEvent'] = 'This competition has no team event.';
$lang['ImportEvents'] = 'Import the teams';
$lang['LinkClubs'] = 'Link teams to clubs';
$lang['LinkClubsHint'] = 'For teams typed or imported by name: finds their club in the linked event by comparing names.';
$lang['ApplySeason'] = 'Add the {$a} season to the history';
$lang['ApplySeasonHint'] = 'Done once: +1 participation for each team of that season, +1 win for the champion, +1 podium for the top three, previous rank replaced by the final rank.';
$lang['SeasonAppliedPill'] = '{$a} season added to the history';
$lang['ConfirmApplySeason'] = 'Add the {$a} season to the history of every linked team? This can only be done once.';
$lang['NationalOn'] = 'National rankings available: the commentators see each archer\'s national rank.';
$lang['NationalOff'] = 'National rankings not available. They are downloaded by the REPARTITION_EPREUVES module.';
$lang['FieldName'] = 'Name';
$lang['FieldEvent'] = 'Previous-season event';
$lang['EventNone'] = 'No linked event';
$lang['TeamCount'] = '{$a} team(s)';
$lang['MoveUp'] = 'Move up';
$lang['MoveDown'] = 'Move down';
$lang['DeleteCategory'] = 'Delete the category';
$lang['ConfirmDeleteCategory'] = 'Delete this category and all its teams?';
$lang['ColTeam'] = 'Team';
$lang['ColClub'] = 'Club code';
$lang['ColHint'] = 'Last season';
$lang['ColHintTitle'] = 'Final rank in the linked event of the previous-season competition';
$lang['ColNote'] = 'Note for the commentators';
$lang['NoTeam'] = 'No team in this category.';
$lang['DeleteTeam'] = 'Delete the team';
$lang['ConfirmDeleteTeam'] = 'Delete the team "{$a}"?';
$lang['AddTeams'] = 'Add teams — one per line, optionally followed by ; and the club code';
$lang['AddTeamsPlaceholder'] = 'RIOM;0163157';
$lang['Add'] = 'Add';
$lang['AddCategory'] = 'Add a category';
$lang['AddCategoryHint'] = 'A category is one list of its own: teams whose order is drawn, or the season\'s stages shown one by one as the speaker announces them.';
$lang['SectionLook'] = 'Public screen';
$lang['Preview'] = 'Preview';
$lang['LookBg'] = 'Background colour';
$lang['LookAccent'] = 'Accent colour';
$lang['LookText'] = 'Text colour';
$lang['LookFontTitle'] = 'Title font';
$lang['LookFontBody'] = 'Text font';
$lang['LookSizeTitle'] = 'Title size';
$lang['LookSizeTeam'] = 'Team name size';
$lang['LookSizeRank'] = 'Place number size';
$lang['LookMargin'] = 'Top and bottom margins';
$lang['LookOverlay'] = 'Veil over the image';
$lang['LookAmbient'] = 'Background animation';
$lang['LookSlots'] = 'Show the places still to draw';
$lang['LookImage'] = 'Background image';
$lang['LookImageHint'] = 'JPEG, PNG, WebP or GIF, 5 MB at most. The veil, in the background colour, keeps the text readable.';
$lang['ImageSet'] = 'Image in place';
$lang['ImageNone'] = 'No image';
$lang['ImageClear'] = 'Remove the image';

// History columns
$lang['StatParticipations'] = 'Participations';
$lang['StatWins'] = 'Wins';
$lang['StatPodiums'] = 'Podiums';
$lang['StatRank'] = 'Previous rank';
$lang['StatParticipationsShort'] = 'Part.';
$lang['StatWinsShort'] = 'Wins';
$lang['StatPodiumsShort'] = 'Podiums';
$lang['StatRankShort'] = 'Prev.';

// Control page
$lang['OnAir'] = 'On the public screen';
$lang['SceneIdle'] = 'Waiting';
$lang['SceneCategory'] = 'Category being drawn';
$lang['SceneSummary'] = 'Summary';
$lang['Categories'] = 'Categories';
$lang['StatsShown'] = 'History shown';
$lang['UndoLast'] = 'Undo the last place';
$lang['ResetCategory'] = 'Reset the category';
$lang['ConfirmReset'] = 'Withdraw every place drawn in "{$a}"?';
$lang['SearchTeam'] = 'Search a team — Enter draws it when only one matches';
$lang['NextPlace'] = 'Next place';
$lang['CategoryComplete'] = 'Every place is drawn';
$lang['PlaceN'] = 'Place {$a}';
$lang['Unassign'] = 'Withdraw this place';
$lang['NoMatch'] = 'No team matches.';
$lang['NoCategory'] = 'This draw has no category yet: add them on the preparation page.';
$lang['NothingDrawn'] = 'Nothing drawn yet.';
$lang['DrawOrder'] = 'Order drawn';
$lang['LeftToDraw'] = 'Still to draw';
$lang['PreviewTitle'] = 'Public screen now';

// Public screen
$lang['DrawKicker'] = 'Draw';
$lang['WaitingDraw'] = 'Waiting for the draw…';

// Commentators' screen
$lang['SpeakerPick'] = 'Pick a team in the lists.';
$lang['NotDrawnYet'] = 'Not drawn yet';
$lang['NewInEvent'] = 'Not in this event in {$a}';
$lang['SeasonTitle'] = '{$a} season';
$lang['NotInEvent'] = 'This team did not take part in this event in {$a[year]}.';
$lang['AlsoIn'] = 'The club also had a team in:';
$lang['FactRank'] = 'Final rank';
$lang['FactPoints'] = 'Ranking points';
$lang['FactMatches'] = 'Matches won–lost';
$lang['FactAvg'] = 'Points per arrow';
$lang['FactBest'] = 'Best match, per arrow';
$lang['FactStreak'] = 'Longest winning run';
$lang['FactShootOffs'] = 'Shoot-offs won–lost';
$lang['FactSets'] = 'Set points for–against';
$lang['FactForm'] = 'Last matches:';
$lang['FactStage'] = 'Stage';
$lang['FactQualification'] = 'Qualification';
$lang['FactBonus'] = 'bonus {$a}';
$lang['CompositionTitle'] = 'Archers in {$a}';
$lang['ColArcher'] = 'Archer';
$lang['NationalOutdoor'] = 'National rank, outdoor {$a}';
$lang['NationalIndoor'] = 'National rank, indoor {$a}';
$lang['SpeakerNoClub'] = 'No club code for this team, so its previous season cannot be looked up. Add it on the preparation page.';
$lang['Won'] = 'Won';
$lang['Lost'] = 'Lost';
$lang['Ordinal1'] = '#{$a}';
$lang['OrdinalN'] = '#{$a}';

// Version 0.2.0: stages, list for the ianseo competition, medals, settings page
$lang['FieldType'] = 'Kind of list';
$lang['TypeTeams'] = 'Teams to draw';
$lang['TypeStages'] = 'Stages shown one by one';
$lang['StageCount'] = '{$a} stage(s)';
$lang['StageN'] = 'Stage {$a}';
$lang['ColStageName'] = 'Venue';
$lang['ColStageDetail'] = 'Dates and details';
$lang['AddStages'] = 'Add stages — one per line, optionally followed by ; and the dates';
$lang['AddStagesPlaceholder'] = 'Smarves;17 and 18 April 2027';
$lang['StagesHint'] = 'Nothing is drawn here: the public screen shows the stages in this order, one card at a time, as the speaker announces them.';
$lang['NoStage'] = 'No stage in this list.';
$lang['DeleteStage'] = 'Delete the stage';
$lang['ShowStage'] = 'Show';
$lang['ShowNextStage'] = 'Show the next stage';
$lang['HideStage'] = 'Hide this stage again';
$lang['StagesShown'] = '{$a[shown]} of {$a[total]} stages shown';
$lang['AllStagesShown'] = 'Every stage is shown';
$lang['StagesList'] = 'Stages';
$lang['StageOnScreen'] = 'on screen';
$lang['StageUpcoming'] = 'not shown yet';
$lang['PasteTitle'] = 'List for the ianseo competition';
$lang['PasteHint'] = 'Club codes in drawn order. Paste them into the text box under column {$a} of the Setup screen of the new season\'s first division competition.';
$lang['PasteHintNoEvent'] = 'Club codes in drawn order. Paste them into the text box of the matching column of the Setup screen of the new season\'s first division competition.';
$lang['PasteMissingCode'] = 'No club code for: {$a}. Add it on the settings page first — without it every following team would move up one place in ianseo.';
$lang['PasteIncomplete'] = 'Only {$a[drawn]} of {$a[total]} places drawn so far';
$lang['CopyList'] = 'Copy the list';
$lang['ListCopied'] = 'List copied.';
$lang['MedalGold'] = 'Best in the category';
$lang['MedalSilver'] = 'Second best in the category';
$lang['MedalBronze'] = 'Third best in the category';
$lang['EditLead'] = 'Every change is saved as soon as you leave the field: there is no button to press. Come back here from the list of draws, or from the control page.';

// Version 0.2.1: commentators' screen
$lang['BackToLive'] = 'Back to live';

// Version 0.2.3: spacing inside a slot of the public screen
$lang['LookRankSpace'] = 'Space: place number to team';
$lang['LookStatsSpace'] = 'Space: team to statistics';
$lang['LookStatGap'] = 'Space between statistics';
$lang['LookStatWidth'] = 'Width of a statistic';
