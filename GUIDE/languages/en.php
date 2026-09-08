<?php
/**
 * English strings for the Interactive Guide module.
 *
 * Same format and semantics as the core language files in
 * Common/Languages/<code>/<Module>.php: the file defines a $lang array whose
 * keys are looked up by guide_text(). English is the fallback, so this file must
 * carry EVERY key — a key missing here is missing everywhere.
 *
 * {$a} is replaced by the argument passed to guide_text(), exactly as the core's
 * get_text() does it.
 *
 * When this module joins the ianseo distribution these files move to
 * Common/Languages/<code>/Guide.php unchanged, and guide_text() gives way to
 * get_text($key, 'Guide').
 */

/* Module identity and menu */
$lang['ModuleName']          = 'Interactive Guide';
$lang['MenuCourses']         = 'Available courses';
$lang['MenuAdmin']           = 'Administration';

/* Catalogue page */
$lang['CatalogueTitle']      = 'Interactive Guide';
$lang['CatalogueIntro']      = 'Every course offers up to three activities: the <b>step-by-step guide</b>, a <b>quiz</b> and a <b>challenge</b> to complete unaided. Pass all three to earn the <b>gold target</b>.';
$lang['CatalogueEmpty']      = 'No course is available yet.';
$lang['DefaultGroup']        = 'Courses';
$lang['Checklists']          = 'Checklists';
$lang['Troubleshooting']     = 'Troubleshooting';
$lang['OpenChecklist']       = 'Open the checklist';
$lang['OpenTroubleshooting'] = 'Open troubleshooting';
$lang['StatCompleted']       = 'Courses completed';
$lang['StatGold']            = 'Gold targets';
$lang['StepsCount']          = '{$a} steps';
$lang['Quiz']                = 'Quiz';
$lang['Challenge']           = 'Challenge';
$lang['CmdGuide']            = 'Guide';
$lang['CmdResume']           = 'Resume';
$lang['InProgressStep']      = 'In progress — step {$a}';
$lang['PreviousVersion']     = 'previous version';
$lang['Untitled']            = '(untitled)';
$lang['ContextHelpToggle']   = 'Contextual help — suggests the courses related to the ianseo page on screen';

/* Achievement levels */
$lang['TargetBronze']        = 'Bronze target';
$lang['TargetSilver']        = 'Silver target';
$lang['TargetGold']          = 'Gold target';

/* Side panel */
$lang['PanelMove']           = 'Move';
$lang['PanelMinimise']       = 'Minimise';
$lang['PanelMaximise']       = 'Maximise';
$lang['PanelCloseCourse']    = 'Close the course';
$lang['CmdMarkDone']         = 'Mark as done';
$lang['CmdPrev']             = 'Prev.';
$lang['CmdNextStep']         = 'Next';
$lang['CmdRestartHints']     = 'Restart the hints for this step';
$lang['CmdBackHint']         = 'Back to the previous hint';

/* Home page banner, shown to an account that has no competition yet */
$lang['BannerTitle']         = 'Learn to use ianseo';
$lang['BannerText']          = 'Step-by-step interactive courses, quizzes and challenges';

/* Administration — content list */
$lang['AdmTitle']            = 'Interactive Guide — Administration';
$lang['AdmHeading']          = 'Interactive Guide — Content administration';
$lang['AdmNewCourse']        = 'New course';
$lang['AdmNewChecklist']     = 'Checklist';
$lang['AdmNewFaq']           = 'Troubleshooting';
$lang['AdmConditions']       = 'Conditions';
$lang['AdmUpdates']          = 'Updates';
$lang['AdmHelp']             = 'Help';
$lang['AdmBackCatalogue']    = 'Back to the catalogue';
$lang['AdmBackAdmin']        = 'Back to administration';
$lang['AdmEmpty']            = 'No content yet. Create the first one.';
$lang['AdmColType']          = 'Type';
$lang['AdmColTitle']         = 'Title';
$lang['AdmColGroup']         = 'Group';
$lang['AdmColContent']       = 'Content';
$lang['AdmColVersion']       = 'Version';
$lang['AdmColActions']       = 'Actions';
$lang['AdmTypeCourse']       = 'Course';
$lang['AdmEdit']             = 'Edit';
$lang['AdmDelete']           = 'Delete';
$lang['AdmDeleteConfirm']    = 'Delete this content permanently?';

/* Administration — updates */
$lang['UpdTitle']            = 'Interactive Guide — Updates';
$lang['UpdCourses']          = 'Courses';
$lang['UpdEngineFiles']      = 'Module (engine files)';
$lang['UpdNoCourseFound']    = 'No course found in the repository.';
$lang['UpdMerge']            = 'Merge (keep local courses)';
$lang['UpdReplace']          = 'Replace (overwrite everything)';
$lang['UpdMergeHint']        = '<b>Merge</b> downloads the courses that are new or newer, and keeps local courses the repository does not carry.';
$lang['UpdReplaceHint']      = '<b>Replace</b> makes the repository the only reference: local courses absent from it are deleted.';
$lang['UpdMergeConfirm']     = 'Update the courses? Progress completed on an earlier version will be marked obsolete.';
$lang['UpdReplaceConfirm']   = 'WARNING: this deletes the local courses that are absent from the repository. Continue?';
$lang['UpdCoursesResult']    = 'Courses: {$a[updated]} changed, {$a[added]} added, {$a[skipped]} already up to date.';

/* Visual course editor */
$lang['EdErrWrite']          = 'Could not write the file.';
$lang['EdTitleEdit']         = 'Edit: {$a}';
$lang['EdTitleNew']          = 'New course';
$lang['EdHeadingEdit']       = 'Edit the course';
$lang['EdBackList']          = 'Back to the list';
$lang['EdExport']            = 'Export (.ianseo)';
$lang['EdExportHint']        = 'A compressed .ianseo file — lighter, and the same format as ianseo\'s own exports';
$lang['EdHelpLink']          = 'Authoring help';
$lang['EdFieldTitle']        = 'Course title';
$lang['EdTitlePlaceholder']  = 'My first competition';
$lang['EdFieldDesc']         = 'Description';
$lang['EdDescPlaceholder']   = 'Short description…';
$lang['EdFieldVersion']      = 'Version';
$lang['EdFieldGroup']        = 'Group';
$lang['EdGroupHint']         = '(learning path)';
$lang['EdGroupPlaceholder']  = 'The basics';
$lang['EdFieldSubgroup']     = 'Subgroup';
$lang['EdSubgroupPlaceholder'] = '(optional)';
$lang['EdFieldOrder']        = 'Order';
$lang['EdFieldEditLang']     = 'Editing language';
$lang['EdFieldBaseLang']     = 'Original language';
$lang['EdToTranslate']       = 'to translate';
$lang['EdIdHint']            = '— unique identifier, generated automatically, editable through the JSON if needed';
$lang['EdThumbnail']         = 'Course thumbnail';
$lang['EdThumbnailHint']     = '(optional · 16:9 · GIF accepted · shown in the catalogue)';
$lang['EdBold']              = 'Bold (Ctrl+B)';
$lang['EdItalic']            = 'Italic (Ctrl+I)';
$lang['EdUnderline']         = 'Underline (Ctrl+U)';
$lang['EdBullets']           = 'Bulleted list';
$lang['EdNumbered']          = 'Numbered list';
$lang['EdCode']              = 'Inline code';
$lang['EdTip']               = 'Tip';
$lang['EdTipHint']           = 'Add a tip box';
$lang['EdEditorBadge']       = 'editor';
$lang['EdStepTitlePh']       = 'Step title…';
$lang['EdContentPh']         = 'Click here to write the content (HTML)…';
$lang['EdAddBefore']         = 'Before';
$lang['EdAddAfter']          = 'After';
$lang['EdAddBeforeHint']     = 'Insert a step before this one';
$lang['EdAddAfterHint']      = 'Insert a step after this one';
$lang['EdDeleteStepHint']    = 'Delete this step';
$lang['EdStepOptions']       = 'Step options';
$lang['EdDefaultPage']       = 'Default page';
$lang['EdDefaultPageHint']   = '(for triggers with no page of their own · <code>*</code> = every page)';
$lang['EdStepImage']         = 'Step image';
$lang['EdStepImageHint']     = '(optional · 16:9 · GIF accepted)';
$lang['EdOptional']          = 'Optional';
$lang['EdOptionalHint']      = '("Mark as done" is shown — the user can force the step through)';
$lang['EdStrict']            = 'Non-permissive';
$lang['EdStrictHint']        = '(blocks every click outside the expected element)';
$lang['EdTriggersHint']      = '— fired in order; drag and drop to reorder';
$lang['EdRecordHint']        = 'Move through ianseo and click the elements to record triggers automatically';
$lang['EdQuizSummary']       = 'Quiz';
$lang['EdQuizHint']          = '(optional — offered at the end of the guide, counts towards the silver and gold target)';
$lang['EdPassScore']         = 'Minimum score to pass (%)';
$lang['EdShuffle']           = 'Answers shown in a random order';
$lang['EdChallengeSummary']  = 'Challenge';
$lang['EdChallengeHint']     = '(optional — the user works unaided, and state conditions decide)';
$lang['EdBrief']             = 'Brief';
$lang['EdBriefHint']         = '(HTML allowed)';
$lang['EdBriefPh']           = '<p>Create a competition with two sessions…</p>';
$lang['EdConditionsToMeet']  = 'Conditions to meet';
$lang['EdConditionsHint']    = '(all of them)';
$lang['EdConditionsLink']    = 'Create new conditions with the <a href="conditions.php">condition builder</a>.';
$lang['EdNoConditions']      = 'No condition defined.';
$lang['EdApplyJson']         = 'Apply the JSON to the visual editor';
$lang['EdCorrectAnswer']     = 'Correct answer';
$lang['EdAnswerPh']          = 'Answer {$a}';
$lang['EdOptionalSuffix']    = ' (optional)';
$lang['EdAnswersLabel']      = 'Answers — tick the correct one or ones (at least one):';
$lang['EdExplainLabel']      = 'Explanation (shown after the answer, optional):';
$lang['EdExplainPh']         = 'Why this answer…';
$lang['EdQuestionPh']        = 'Wording of the question…';
$lang['EdNewStep']           = 'New step';
$lang['EdNewStepBody']       = '<p>Content of the step.</p>';
$lang['EdErrOneStep']        = 'A course must have at least one step.';
$lang['EdDelStepConfirm']    = 'Delete the step "{$a}"?';
$lang['EdStepFallback']      = 'step {$a}';
$lang['EdCondCss']           = 'Presence or absence of an element';
$lang['EdDragHint']          = 'Move';
$lang['EdKindAction']        = 'Action';
$lang['EdKindState']         = 'State';
$lang['EdPagePh']            = '/page or *';
$lang['EdPageHint']          = 'Page: empty = the step\'s own page · * = every page';
$lang['EdTriggerInput']      = 'entry (live)';
$lang['EdTriggerKeyup']      = 'key released';
$lang['EdTriggerKeydown']    = 'key pressed';
$lang['EdSelectorPh']        = '#css-selector';
$lang['EdSelectorHint']      = 'A numbered id such as #d_q_QuSession_25360? Use a prefix selector instead: [id^="d_q_QuSession_"]';
$lang['EdCondPagePh']        = '/page to check, or *';
$lang['EdCondPageHint']      = 'The page the user must have open (it may differ from the step\'s page)';
$lang['EdCondCssPh']         = '#css-selector';
$lang['EdCondCssHint']       = 'Element to detect (a prefix selector works here too, for numbered ids)';
$lang['EdCssModeHint']       = 'The element must be…';
$lang['EdCssPresent']        = 'present';
$lang['EdImageBig']          = "This image is {\$a} MB.\nLarge images make the course, and its synchronisation, heavy.\nContinue anyway?";
$lang['EdRecConfirm']        = "Start recording triggers?\n\nThe course is saved first, then you are sent into ianseo. Click the elements you want: they are added to this step.";
$lang['EdSaveFailed']        = 'Save failed: {$a}';
$lang['EdUnknown']           = 'unknown';
$lang['EdNetworkSave']       = 'Network error while saving.';
$lang['EdTriggersAdded']     = "{\$a[n]} recorded trigger(s) were added to the step \"{\$a[step]}\".\nCheck them, then save the course.";
$lang['EdImported']          = 'Course imported — check it, then save';
$lang['EdNetworkImport']     = 'Network error during the import.';
$lang['EdSaved']             = 'Course saved';
$lang['EdNetworkErr']        = 'Network error';
$lang['EdImport']            = 'Import (.ianseo)';
$lang['EdSave']              = 'Save the course';
$lang['EdSaving']            = 'Saving…';
$lang['EdChoose']            = 'Choose';
$lang['EdChooseImage']       = 'Choose an image';
$lang['EdRemove']            = 'Remove';
$lang['EdTextColour']        = 'Text colour';
$lang['EdRemoveFormat']      = 'Remove the formatting';
$lang['EdOptPagePh']         = '/path/to/file.php or *';
$lang['EdAddTrigger']        = 'Add a trigger';
$lang['EdRecord']            = 'Record triggers';
$lang['EdAddQuestion']       = 'Add a question';
$lang['EdQuestion']          = 'Question';
$lang['EdJsonToggle']        = 'JSON source — for experts, and for import and export';
$lang['EdUntitled']          = '(course title)';
$lang['EdChooseCondition']   = '— choose a condition…';
$lang['EdCondPage']          = 'Current page';
$lang['EdGateAlways']        = 'always active';
$lang['EdGateIf']            = 'if:';
$lang['EdGateIfNot']         = 'if NOT:';
$lang['EdGateHint']          = 'Conditional branch: this trigger counts only if the condition is met';
$lang['EdTriggerKind']       = 'Kind of trigger';
$lang['EdHintPh']            = 'Tooltip (optional)';
$lang['EdRequiredShort']     = 'Req.';
$lang['EdDelete']            = 'Delete';
$lang['EdTriggerNone']       = '— none';
$lang['EdTriggerClick']      = 'click';
$lang['EdTriggerDblClick']   = 'double click';
$lang['EdTriggerChange']     = 'change';
$lang['EdTriggerFocus']      = 'focus';
$lang['EdTriggerSubmit']     = 'submit';
$lang['EdTriggerHover']      = 'hover';
$lang['EdCssAbsent']         = 'absent';
$lang['EdNoTrigger']         = 'No trigger — the Next button is always free.';
$lang['EdTipBody']           = 'Text of the tip or the warning';
$lang['EdErrorLabel']        = 'Error:';
$lang['EdErrJson']           = 'Invalid JSON.';
$lang['EdErrJsonNoId']       = 'Invalid JSON, or the "id" field is missing.';
$lang['EdErrIdChars']        = 'The id may contain only lowercase letters, digits and hyphens.';
$lang['EdErrFileMissing']    = 'File missing, or the upload failed.';
$lang['EdErrFileNotCourse']  = 'Invalid file: it was not recognised as a course.';
$lang['EdErrNotImage']       = 'Please choose an image (PNG, JPG, GIF…).';
$lang['EdImportPrefix']      = 'Import: {$a}';
$lang['EdUnknownError']      = 'Unknown error';

/* Condition builder */
$lang['CndTitle']            = 'Interactive Guide — Conditions';
$lang['CndHeading']          = 'Interactive Guide — Condition builder';
$lang['CndSaved']            = 'Conditions saved.';
$lang['CndIntro']            = 'A condition reads the state of the competition — the session, the ianseo tables, the pages visited — and never writes. Conditions drive <b>state triggers</b>, <b>conditional branches</b>, <b>challenges</b> and <b>self-ticking checklists</b>. <b>Test</b> evaluates the condition against the competition currently open, for the signed-in user.';
$lang['CndErrJson']          = 'Invalid JSON.';
$lang['CndErrIncomplete']    = 'Every condition needs an id, a label and at least one check.';
$lang['CndErrNoCheck']      = 'Invalid condition: it has no check.';
$lang['CndErrWrite']         = 'Could not write conditions.json. Check the folder permissions.';
$lang['CndColLabel']         = 'Label';
$lang['CndColChecks']        = 'Checks';
$lang['CndNew']              = 'New condition';
$lang['CndEditTitle']        = 'Edit: {$a}';
$lang['CndIdHint']           = '(lower case, digits, _ )';
$lang['CndLabelField']       = 'Label (shown to the user)';
$lang['CndLabelPlaceholder'] = 'At least one session defined';
$lang['CndChecksField']      = 'Checks (all must be true)';
$lang['CndAddCheck']         = 'Add a check';
$lang['CndTestNow']          = 'Test now';
$lang['CndApply']            = 'Confirm this condition';
$lang['CndSaveAll']          = 'Save every condition';
$lang['CndDirty']            = 'unsaved changes';
$lang['CndRawJson']          = 'Raw JSON (experts)';
$lang['CndApplyRaw']         = 'Apply the JSON';
$lang['CndTest']             = 'Test';
$lang['CndNone']             = 'No condition.';
$lang['CndDelConfirm']       = "Delete the condition \"{\$a}\"?\nCheck that no course or challenge uses it.";
$lang['CndMet']              = 'met';
$lang['CndNotMet']           = 'not met';
$lang['CndNetwork']          = 'network';
$lang['CndNoCheck']          = 'no check';
$lang['CndTypeSession']      = 'Session variable';
$lang['CndTypeCount']        = 'Number of rows (COUNT)';
$lang['CndTypeColumn']       = 'Value of a column';
$lang['CndTypeVisited']      = 'Page visited';
$lang['CndKey']              = 'Key';
$lang['CndTable']            = 'Table';
$lang['CndRowCount']         = 'number of rows';
$lang['CndJoinOptional']     = 'join (optional): table';
$lang['CndJoinOn']           = 'on';
$lang['CndAddWhere']         = 'add WHERE criterion';
$lang['CndWhereHint']        = 'The "= session" operator compares the column with a session variable — the value is the key name, such as TourId. The "in list" operator means the column must be one of a comma-separated list of values, such as 1,5,20.';
$lang['CndPagePath']         = 'Page path';
$lang['CndAnyTournament']    = 'any competition';
$lang['CndVisitedHint']      = 'True once the user has opened this page — the path is relative to the ianseo root, without parameters, and a trailing /index.php is optional. By default the visit must have happened during the competition currently open. Visits are recorded per user, from the moment the condition exists.';
$lang['CndColumn']           = 'column';
$lang['CndJoin']             = 'join';
$lang['CndWhereColumn']      = 'Column';
$lang['CndOpInList']         = 'in list';
$lang['CndOpSession']        = '= session';
$lang['CndErrIdLabel']       = 'ID and label are required.';
$lang['CndErrNoChecks']      = 'Add at least one check.';
$lang['CndErrIdExists']      = 'That ID already exists.';
$lang['CndErrArrayExpected'] = 'an array was expected';
$lang['CndErrRawJson']       = 'Invalid JSON: {$a}';

/* JSON editor, for checklists and troubleshooting trees */
$lang['JedTitle']            = 'Interactive Guide — JSON editor';
$lang['JedHeading']          = 'JSON editor — checklists and troubleshooting';
$lang['JedDocChecklist']     = '<b>Checklist</b>: <code>questions[]</code> (every choice carries <code>tags</code>) then <code>items[]</code> — an item without tags is always shown, an item with tags appears only when a matching choice was selected. <code>condition</code> (optional) ticks the item automatically once it is met; <code>page</code> adds a link.';
$lang['JedDocFaq']           = '<b>Troubleshooting</b>: <code>nodes</code> — the tree starts at the <code>start</code> node. A node holds either <code>q</code> and <code>answers[]</code> (each with <code>next</code>), or a <code>solution</code> in HTML with optional <code>page</code> and <code>formation</code>.';
$lang['JedDocCommon']        = 'Learning-path fields shared by every content type: <code>group</code>, <code>subgroup</code>, <code>order</code>.';
$lang['JedValidate']         = 'Validate the JSON';
$lang['JedSave']             = 'Save';
$lang['JedValid']            = 'Valid JSON';
$lang['JedErrInvalidJson']   = 'Invalid JSON, or the "id" field is missing.';
$lang['JedErrBadId']         = 'The id may contain only lower-case letters, digits and hyphens.';
$lang['JedErrWrite']         = 'Could not write the file. Check the folder permissions.';
$lang['JedErrNoId']          = 'the "id" field is missing';
$lang['JedErrBadType']       = 'the "type" field must be "checklist" or "faq"';
$lang['JedErrNoItems']       = 'the "items" field is missing';
$lang['JedErrNoStart']       = 'the "start" node is missing from "nodes"';

/* Course player (assets/guide.js).
   Every key prefixed Js is published to the browser by menu.php as window.GUIDE_T
   — see guide_js_strings(). Adding one here is enough; there is no second list. */
$lang['JsMoveRight']         = 'Move to the right';
$lang['JsMoveLeft']          = 'Move to the left';
$lang['JsCourseNotFound']    = 'Course not found.';
$lang['JsCloseKeepLocal']    = "Close?\nYour local progress is kept.";
$lang['JsLeaveCourse']       = "Leave the course?\nYour progress is saved — you can pick it up here.";
$lang['JsStep']              = 'Step';
$lang['JsMustBeOnPage']      = 'You need to be on this page:';
$lang['JsPageUndefined']     = '(not set)';
$lang['JsWaitElementGone']   = 'Waiting: the highlighted element must disappear.';
$lang['JsWaitElementShown']  = 'Waiting: the highlighted element must appear on screen.';
$lang['JsOtherCompetition']  = '⚠️ <b>This course was started in another competition.</b><br>Some menus or elements may not exist in this one.';
$lang['JsStepOnOtherPage']   = 'This step takes place on another page:';
$lang['JsGoldHint']          = 'Pass every activity to earn the gold target.';
$lang['JsGuideFinished']     = '<b>Well done!</b> You have finished the guide of this course.';
$lang['JsTakeChallenge']     = 'Take the challenge';
$lang['JsCourseComplete']    = 'Course complete!';
$lang['JsMultipleAnswers']   = 'More than one answer may apply — select them, then confirm.';
$lang['JsConfirmAnswer']     = 'Confirm the answer';
$lang['JsRightAnswer']       = 'Correct!';
$lang['JsWrongAnswer']       = 'Wrong answer.';
$lang['JsSeeResult']         = 'See the result';
$lang['JsQuizPassedScore']   = '<b>Quiz passed!</b> Score: ';
$lang['JsRetry']             = 'Try again';
$lang['JsQuizPassed']        = 'Quiz passed!';
$lang['JsQuizResult']        = 'Quiz result';
$lang['JsNoChallenge']       = 'This course has no challenge.';
$lang['JsCheckNow']          = 'Check now';
$lang['JsAutoCheck']         = 'Checked automatically every 10 seconds.';
$lang['JsChallengeSuffix']   = ' — Challenge';
$lang['JsChallengeDone']     = '<b>Well done!</b> Every condition of the challenge is met.';
$lang['JsChallengePassed']   = 'Challenge passed!';
$lang['JsStartLinkedCourse'] = 'Start the related course';
$lang['JsCtxIntro']          = 'Interactive Guide content related to this page:';
$lang['JsDisableCtx']        = 'Turn off contextual help';
$lang['JsAbortRecording']    = "Abandon the recording?\nThe recorded triggers will be lost.";
$lang['JsClickToHide']       = 'Click to hide';
$lang['JsGoToPage']          = 'Go to the page';
$lang['JsAchievement']       = 'Achievement:';
$lang['JsBackToCatalogue']   = 'Back to the catalogue';
$lang['JsWholeCatalogue']    = 'The whole catalogue';
$lang['JsNextQuestion']      = 'Next question';
$lang['JsNextCourse']        = 'Next course';
$lang['JsScoreFailed']       = 'Score: {score}% ({ok}/{total}) — at least {pass}% is needed.';

/* Trigger recorder, used by the course editor */
$lang['RecTitle']            = 'Recording';
$lang['RecAbort']            = 'Abort the recording';
$lang['RecHint']             = 'Click the elements to record as triggers. Navigation still works.';
$lang['RecPause']            = 'Pause';
$lang['RecCurrentPage']      = 'Current page';
$lang['RecCurrentPageHint']  = 'Record the current page as a state condition';
$lang['RecUndo']             = 'Undo';
$lang['RecUndoHint']         = 'Undo the last recorded trigger';
$lang['RecDone']             = 'Finish';
$lang['JsFinish']            = 'Finish';
$lang['JsNoQuiz']            = 'This course has no quiz.';
$lang['JsBack']              = 'Back';
$lang['JsRestart']           = 'Start over';
$lang['JsContextHelp']       = 'Contextual help';
$lang['JsDone']              = 'Done';
$lang['Cancel']              = 'Cancel';
$lang['EdErrNoId']             = 'The course must have an identifier.';
$lang['LangEn']                = 'English (en)';
$lang['LangFr']                = 'French (fr)';
$lang['LangIt']                = 'Italian (it)';
$lang['LangDe']                = 'German (de)';
$lang['LangEs']                = 'Spanish (es)';
$lang['HelpTitle']             = 'Interactive Guide — Authoring help';
$lang['HelpHeading']           = 'Interactive Guide — How to write a course';
$lang['JsFaqEmpty']          = 'Empty troubleshooting tree, or its "start" node is missing.';
$lang['JsPanelNormalSize']   = 'Normal size';
$lang['JsConditionNotMet']   = 'A required condition is not met:';
$lang['JsContentNotFound']   = 'Content not found.';
$lang['JsRedoQuestions']     = 'Answer the questions again';
$lang['JsChecklist']         = 'Checklist';
$lang['JsQuestion']          = 'Question';
