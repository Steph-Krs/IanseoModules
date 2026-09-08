<?php
/**
 * English strings of the authoring documentation, the "help" section.
 *
 * Same format as every other language file and as the core's own: a $lang array
 * of strings, nothing else. The page's structure — headings, tables, lists —
 * lives in admin/help.php; only the wording is here.
 *
 * Inline emphasis (<b>, <i>, <code>) stays inside a string, because it is part
 * of the sentence and moves with it when translated.
 *
 * A section of its own rather than the module's main file, the way the core
 * separates Common.php from Tournament.php: the main strings are loaded on every
 * ianseo page, this documentation only on its own screen.
 */

/* Contents and shared table headers */
$lang['HlpContents']        = 'Contents';
$lang['HlpColField']        = 'Field';
$lang['HlpColPurpose']      = 'Purpose';
$lang['HlpColOption']       = 'Option';
$lang['HlpColEffect']       = 'Effect';
$lang['HlpColAchievement']  = 'Achievement';
$lang['HlpColEarnedBy']     = 'Earned by';

/* 1. The idea */
$lang['HlpIdeaTitle']       = 'The idea';
$lang['HlpIdeaP1']          = 'A <b>course</b> is a sequence of <b>steps</b> shown in a side panel, on top of any ianseo page. Each step explains one action, can point at it visually — a highlighted button, an arrow, a tooltip — and then waits until the action has really been performed before unlocking the next step.';
$lang['HlpIdeaP2']          = 'Everything is built from the visual editor (<b>New course</b> or <b>Edit</b>): no code needed. The JSON is generated for you, and stays reachable for advanced users in the folded "JSON source" section.';

/* 2. The course */
$lang['HlpCourseTitle']     = 'The course';
$lang['HlpCourseFTitle']    = 'Title';
$lang['HlpCourseVTitle']    = 'The name shown in the catalogue and at the top of the panel.';
$lang['HlpCourseFDesc']     = 'Description';
$lang['HlpCourseVDesc']     = 'One short sentence, shown under the title in the catalogue.';
$lang['HlpCourseFVersion']  = 'Version';
$lang['HlpCourseVVersion']  = 'A free-form number such as <code>1.0</code>. Raise it and the users who had finished the previous version are marked "previous version".';
$lang['HlpCourseFThumb']    = 'Thumbnail';
$lang['HlpCourseVThumb']    = 'An optional 16:9 image, shown in the catalogue so a course is recognisable at a glance.';
$lang['HlpCourseFId']       = 'ID';
$lang['HlpCourseVId']       = 'A unique identifier, generated for you. Do not change it once the course has been published: it is the key progress is recorded against.';

/* 3. The step */
$lang['HlpStepTitle']       = 'The step';
$lang['HlpStepIntro']       = 'Every step has:';
$lang['HlpStepLiTitle']     = 'a <b>title</b>;';
$lang['HlpStepLiContent']   = 'a <b>content</b>, formatted text — see §4;';
$lang['HlpStepLiImage']     = 'an optional <b>image</b> — see §5;';
$lang['HlpStepLiPage']      = 'a <b>default page</b> and its <b>triggers</b> — see §6;';
$lang['HlpStepLiOptions']   = '<b>options</b>: optional and non-permissive — see §9.';
$lang['HlpStepOutro']       = 'Use <b>+ Before</b> and <b>+ After</b> to insert steps, and the <b>◀ ▶</b> arrows to move between them. The left-hand panel is a <b>live preview</b>: what you see is exactly what the user will see.';

/* 4. Writing the content */
$lang['HlpContentTitle']    = 'Writing the content';
$lang['HlpContentIntro']    = 'The toolbar above the preview formats the text:';
$lang['HlpContentLiFormat'] = '<b>bold</b>, <i>italic</i>, <u>underline</u>, text colour;';
$lang['HlpContentLiLists']  = 'bulleted and numbered lists;';
$lang['HlpContentLiTip']    = '<b>💡 Tip</b> inserts a yellow box, for a piece of advice or a warning;';
$lang['HlpContentLiCode']   = '<b>&lt;/&gt;</b> puts the selected text in "code" style — useful for a button name or a path.';
$lang['HlpContentTip']      = '<b>Inside a tip box:</b> press <b>Shift + Enter</b> to start a new line <i>within</i> the box. Press <b>Enter</b> on its own to <i>leave</i> the box and carry on with ordinary text below it.';

/* 5. Images */
$lang['HlpImagesTitle']     = 'Images';
$lang['HlpImagesLiOne']     = 'At most one image per step, plus one thumbnail for the course.';
$lang['HlpImagesLiFormats'] = 'The usual formats are accepted, <b>animated GIFs included</b>.';
$lang['HlpImagesLiRatio']   = 'The image is shown in <b>16:9</b>: another aspect ratio simply gets <b>black bars</b>, and the image is never stretched or cropped.';
$lang['HlpImagesLiAbove']   = 'The image appears <b>above the text</b> of the step.';
$lang['HlpImagesLiOptional']= 'Images are <b>optional</b>: without one, the step displays normally.';
$lang['HlpImagesNote']      = 'Images are embedded in the course itself, as base64. Avoid heavy files — a GIF of several megabytes makes the course, and its synchronisation, slow. The editor warns you past 2 MB.';

/* 6. Triggers */
$lang['HlpTriggersTitle']   = 'Triggers';
$lang['HlpTriggersIntro']   = 'A trigger describes what the user has to do for the step to be complete. A step may hold several, and they fire <b>in order</b> — drag and drop to reorder them. There are two families.';
$lang['HlpTrigActionTitle'] = 'Action — the user does something';
$lang['HlpTrigFPage']       = 'Page';
$lang['HlpTrigVPage']       = 'The page the action happens on. Empty means the step\'s own default page. <code>*</code> means <b>any page</b>, which is what menus need since they exist everywhere.';
$lang['HlpTrigFType']       = 'Type';
$lang['HlpTrigVType']       = 'The event to wait for: click, double click, change, entry, focus, hover, submit… <code>— none</code> highlights the element and lets the user confirm by hand.';
$lang['HlpTrigFSelector']   = 'Selector';
$lang['HlpTrigVSelector']   = 'The element, as a CSS selector — <code>#btnSave</code>, <code>.menu-item</code>. It is highlighted, with an arrow.';
$lang['HlpTrigFTooltip']    = 'Tooltip';
$lang['HlpTrigVTooltip']    = 'Optional short text shown beside the highlighted element.';
$lang['HlpTrigFRequired']   = 'Required';
$lang['HlpTrigVRequired']   = 'When ticked, the step does not complete until the action is done.';
$lang['HlpTrigStateTitle']  = 'State — waiting for a condition to become true';
$lang['HlpTrigStateP1']     = 'Instead of an action, the step waits for a <b>condition</b> inside ianseo to be met — "a competition is open", for instance. While it is not, a message says so. The condition is checked again every time the user comes back to the step.';
$lang['HlpTrigStateP2']     = 'One built-in condition is always available: <b>📍 Current page</b>. It checks that the user is on a given page, named in the field that appears. That page <b>may differ</b> from the step\'s own page. Until the user gets there the step stays locked and a "Go to the page →" link is shown.';
$lang['HlpTrigStateP3']     = 'The other built-in condition is <b>🔎 Presence or absence of an element</b>. You give a <b>CSS selector</b> — prefix selectors such as <code>[id^="…"]</code> work here too — and say whether the element must be <b>present</b> or <b>absent</b>. Useful for waiting on a confirmation message, a window opening, a loading indicator disappearing. This condition is <b>re-checked automatically</b>, because such an element can appear or vanish without the page ever reloading.';
$lang['HlpTrigStarNote']    = '<b>The <code>*</code> page</b> works both as the step\'s default page and on each trigger individually. A trigger set to <code>*</code> stays active whatever page is on screen.';
$lang['HlpBranchTitle']     = 'Conditional branches (⎇ Active if…)';
$lang['HlpBranchIntro']     = 'Every trigger, action or state, has an <b>⎇</b> activation condition. By default a trigger is <b>always active</b>. It can be made conditional:';
$lang['HlpBranchLiIf']      = '<b>if</b> a condition is met — "if: a competition is open";';
$lang['HlpBranchLiIfNot']   = '<b>if NOT</b> a condition — "if NOT: a competition is open".';
$lang['HlpBranchP']         = 'A trigger whose condition is not satisfied is <b>skipped</b>, and the sequence moves to the next one. That is what lets a course take <b>different paths</b> depending on the state of ianseo and then rejoin a common sequence:';
$lang['HlpBranchTip']       = 'For example: "if NOT: a competition is open" on a trigger that walks the user through creating one; the triggers after it, <b>with no condition</b>, are common to both cases. For an "else", use two triggers — one <i>if X</i>, the other <i>if NOT X</i>.';

/* 7. Finding a CSS selector */
$lang['HlpSelectorsTitle']  = 'Finding a CSS selector';
$lang['HlpSelIntro']        = 'To target an element — a button, a field, a link:';
$lang['HlpSelLiInspect']    = 'On the page concerned, <b>right-click</b> the element and choose <b>Inspect</b> (or press <b>F12</b>).';
$lang['HlpSelLiId']         = 'Look for its <code>id</code>, such as <code>id="btnSave"</code>: the selector is <code>#btnSave</code>.';
$lang['HlpSelLiClass']      = 'With no <code>id</code>, use a class — <code>class="btn-primary"</code> gives <code>.btn-primary</code>.';
$lang['HlpSelLiStable']     = 'Always prefer something <b>stable and unique</b> on the page.';
$lang['HlpSelTip']          = 'In the inspector, right-click the element\'s line and choose <b>Copy</b> → <b>Copy selector</b>.';
$lang['HlpSelDynTitle']     = 'Numbered ids, which change every time';
$lang['HlpSelDynP1']        = 'Some ianseo ids carry a record number that changes with the participant or the competition, such as <code>#d_q_QuSession_25360</code> or <code>#d_QuD1Score_25360</code>. An exact selector would work once and never again. Use a <b>prefix selector</b>:';
$lang['HlpSelDynLiPrefix']  = '<code>[id^="d_q_QuSession_"]</code> — an id that <b>starts with</b> this prefix;';
$lang['HlpSelDynLiOther']   = '<code>[id$="_suffix"]</code> — ends with; <code>[id*="middle"]</code> — contains.';
$lang['HlpSelDynP2']        = 'The trigger then fires on <b>any</b> matching element — the Session box of any participant, say — and the arrow points at the first one found. The <b>trigger recorder</b> spots these numbered ids by itself and writes the prefix selector for you.';

/* 8. Recording triggers */
$lang['HlpRecordTitle']     = 'Recording triggers automatically';
$lang['HlpRecIntro']        = 'Rather than typing selectors by hand, <b>🔴 Record triggers</b>, in the step options, captures them by clicking directly in ianseo:';
$lang['HlpRecLiSaved']      = 'The course is <b>saved first</b>, then you are sent to the step\'s page (or to the home page).';
$lang['HlpRecLiPanel']      = 'A <b>red panel</b> appears. <b>Every click</b> you make in ianseo is recorded as a trigger, and the click still works normally — you can move between pages and the recording continues.';
$lang['HlpRecLiPage']       = '<b>📍 Current page</b> adds a state trigger checking that the user is on the page you are on.';
$lang['HlpRecLiPause']      = '<b>⏸ Pause</b> suspends the capture, so you can click without recording. <b>↶ Undo</b> removes the last trigger.';
$lang['HlpRecLiFinish']     = '<b>✓ Finish</b> returns to the editor and adds what was captured. <b>✕</b> abandons it.';
$lang['HlpRecTip']          = 'Afterwards, <b>read the triggers through</b> — type, tooltip, required — adjust what needs it, then <b>save the course</b>. The generated selectors are robust but not infallible on very dynamic elements.';

/* 9. Step options */
$lang['HlpOptionsTitle']    = 'Step options';
$lang['HlpOptFOptional']    = 'Optional';
$lang['HlpOptVOptional']    = 'Shows a "Mark as done" button: the user can complete the step without performing the action.';
$lang['HlpOptFStrict']      = 'Non-permissive';
$lang['HlpOptVStrict']      = 'Blocks every click <b>outside</b> the expected element, so the user can only interact with the target. The panel flashes red when a click is blocked.';

/* 10. Quiz and challenge */
$lang['HlpActivitiesTitle'] = 'Quiz and challenge — bronze, silver and gold';
$lang['HlpActIntro']        = 'A course can offer up to <b>three activities</b>: the step-by-step <b>guide</b>, a <b>quiz</b> and a <b>challenge</b>. At the end of the guide the user is invited on to the quiz, then the challenge, then the next course. Each activity passed raises the achievement:';
$lang['HlpActBronze']       = 'Finishing the guide';
$lang['HlpActSilver']       = 'The guide plus one other activity';
$lang['HlpActGold']         = 'Every activity the course offers';
$lang['HlpActLiQuiz']       = '<b>Quiz</b> (the "📝 Quiz" section of the editor): questions with two to four choices, <b>one or several</b> correct answers ticked — the user then has to select exactly the right set — an optional explanation, a pass mark (70% by default), and an option to show the answers in a <b>random order</b>.';
$lang['HlpActLiChallenge']  = '<b>Challenge</b> (the "🎯 Challenge" section): a brief, and <b>state conditions</b> that check the result inside ianseo, with the user working entirely unaided. Conditions are built in the <b>condition builder</b> (the ⚡ button in the administration), which can test them live against the competition currently open.';
$lang['HlpActShipped']      = 'The conditions shipped with the module cover the usual run of a competition:';
$lang['HlpActLiState']      = '<b>at least one participant registered</b>, <b>at least one judge declared</b>, <b>at least one target assigned</b>, <b>at least one score entered</b> — all checked against the open competition;';
$lang['HlpActLiVisited']    = '<b>a given page has been visited</b> — the "<b>Page visited</b>" check of the builder. A visit is remembered <b>per user and per competition</b>; tick "any competition" for a global one. Ideal for a challenge along the lines of "send your results to the federation".';

/* 11. Learning path and the other content types */
$lang['HlpPathTitle']       = 'Learning path, checklists, troubleshooting, contextual help';
$lang['HlpPathLiPath']      = '<b>Learning path</b>: the <b>Group</b>, <b>Subgroup</b> and <b>Order</b> fields organise the catalogue into ordered sections. The "next course" offered at the end follows that order.';
$lang['HlpPathLiChecklist'] = '<b>Checklists</b>: a few button questions, then a task list shaped by the answers. Items with a condition tick themselves. Created through "+ Checklist" (JSON editing).';
$lang['HlpPathLiTrouble']   = '<b>Troubleshooting</b>: a tree of question → answers → solution. Created through "+ Troubleshooting" (JSON editing).';
$lang['HlpPathLiContext']   = '<b>Contextual help</b>: on by default. The floating 🎯 button shows an orange dot when content exists for the ianseo page on screen. It can be turned off from the catalogue or the panel.';

/* 12. User accounts */
$lang['HlpAccountsTitle']   = 'User accounts (shared server)';
$lang['HlpAccountsP']       = 'When the server runs an account module, <b>each account has its own progress</b>: courses under way, steps completed, quizzes, challenges and achievements are recorded per user, without affecting anybody else. The "Learn to use ianseo" banner appears for an account that can see <b>no competition at all</b>, which is the sign of a new organiser. The <b>courses</b> themselves stay common to the whole server. With no account module nothing changes: the progress is simply the installation\'s.';

/* 13. Translating a course */
$lang['HlpTranslateTitle']  = 'Translating a course';
$lang['HlpTransP1']         = 'A course carries every language it has been written in, <b>inside its own file</b>: it stays one document with one version number, and the update mechanism has one thing to compare.';
$lang['HlpTransLiBase']     = '<b>Original language</b> says which language the course was written in — the language of every field not yet translated. The editing language cannot answer that: it says where you are typing now, not what the existing text already is. Without it, the first translation of a field would file the original text under the language you were typing, and the course would carry its original as its translation. It defaults to your own language and is only worth changing when you take over a course written by someone else.';
$lang['HlpTransLiEdit']     = '<b>Editing language</b> chooses which language the fields show and write. Switching it leaves the other languages untouched.';
$lang['HlpTransLiEmpty']    = 'A field with no translation yet shows <b>empty</b>, not the original text — so what is still missing is visible at a glance.';
$lang['HlpTransNote']       = 'Only the <b>text</b> of a field is translated. The structure around it — steps, triggers, selectors, pages — is never duplicated, so the languages cannot drift apart. A course whose translated version pointed at a different button than the original would be worse than a course with no translation at all.';
$lang['HlpTransP2']         = 'A reader gets the course in the language of their interface. Failing that, English; failing that, the language the course was written in — so a course translated into only one language is still served, never shown empty. A regional language counts as its parent along the way: a reader set to Canadian French gets the French text before English is considered.';
