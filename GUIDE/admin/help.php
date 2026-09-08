<?php
/**
 * Authoring documentation for the Interactive Guide module.
 *
 * Explains to whoever writes a course what a step, a trigger and a condition
 * are, and how to use the trigger recorder — the knowledge needed to build
 * content, as opposed to the code documentation that lives in the README.
 *
 * THE MARKUP IS HERE, THE WORDS ARE IN THE LANGUAGE FILES
 * Exactly as the ianseo core does it: a page holds its own HTML and calls
 * get_text() for every piece of text, while Common/Languages/<code>/<Module>.php
 * holds nothing but a $lang array of strings. Structure in one place, wording in
 * another, so a translator never edits markup and a change to the layout never
 * has to be repeated in five files.
 *
 * The strings live in the "help" section — languages/help/<code>.php — because
 * sections are how the core separates Common.php from Tournament.php from
 * Errors.php, loading only the one it needs. That matters here: the module's
 * main strings are loaded on EVERY ianseo page, since menu.php injects the panel
 * everywhere, whereas this documentation is wanted on one screen.
 *
 * It is kept inside the module rather than on an external wiki, so it travels
 * with the version of the module it describes.
 */

// Walk up to the ianseo root instead of counting directory levels, so the module
// keeps working if it is installed somewhere other than Modules/Custom/.
$_guide_root = __DIR__;
while ($_guide_root !== dirname($_guide_root) && !is_file($_guide_root . '/config.php')) {
    $_guide_root = dirname($_guide_root);
}
define('HTDOCS', $_guide_root);
unset($_guide_root);

require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

guide_check_admin();

$adminUrl = (function_exists('cmod_url') ? cmod_url(dirname(__DIR__)) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/') . 'admin/';

/**
 * One string of the authoring documentation.
 *
 * A short name for guide_text($key, null, 'help'), so the markup below stays
 * readable at a hundred and fifty calls. Every piece of text on this page goes
 * through it; nothing is written inline.
 *
 * The strings carry their own inline emphasis — <b>, <i>, <code> — because that
 * emphasis is part of the sentence and moves with it when translated. The
 * structure around them, headings, tables and lists, is markup and stays here.
 *
 * @param string $key Key defined in languages/help/en.php.
 * @return string The translated text, already containing its inline markup.
 */
function hlp($key) {
    return guide_text($key, null, 'help');
}

/** The sections, in order: anchor and title key. Drives the contents list and
 *  the numbering, so the two can never disagree. */
$sections = [
    'idea'       => 'HlpIdeaTitle',
    'course'     => 'HlpCourseTitle',
    'step'       => 'HlpStepTitle',
    'content'    => 'HlpContentTitle',
    'images'     => 'HlpImagesTitle',
    'triggers'   => 'HlpTriggersTitle',
    'selectors'  => 'HlpSelectorsTitle',
    'record'     => 'HlpRecordTitle',
    'options'    => 'HlpOptionsTitle',
    'activities' => 'HlpActivitiesTitle',
    'path'       => 'HlpPathTitle',
    'accounts'   => 'HlpAccountsTitle',
    'translate'  => 'HlpTranslateTitle',
];
$num = array_flip(array_keys($sections));   // anchor to its position

/**
 * Heading of one section, numbered from the list above.
 *
 * @param string $anchor Key of $sections.
 * @return string HTML.
 */
function hlpHeading($anchor) {
    global $sections, $num;
    return '<h2 id="' . $anchor . '">' . ($num[$anchor] + 1) . '. '
         . htmlspecialchars(hlp($sections[$anchor])) . '</h2>';
}

$PAGE_TITLE = guide_text('HelpTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
/* Scoped to this page's own class prefix. */
.help-wrap { max-width: 860px; line-height: 1.6; color: #333; }
.help-wrap h2 { color: #0254a8; font-size: 18px; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.help-wrap h3 { color: #082c7c; font-size: 15px; margin: 18px 0 6px; }
.help-wrap p  { margin: 0 0 10px; }
.help-wrap ul { margin: 0 0 12px; padding-left: 20px; }
.help-wrap li { margin-bottom: 5px; }
.help-wrap code { background: #eef2ff; border: 1px solid #c5cef5; border-radius: 3px; padding: 1px 6px; font-size: 12px; color: #082c7c; font-weight: 600; }
.help-wrap table { border-collapse: collapse; width: 100%; margin: 6px 0 14px; font-size: 13px; }
.help-wrap th { background: #0254a8; color: #fff; padding: 7px 11px; text-align: left; }
.help-wrap td { padding: 7px 11px; border-bottom: 1px solid #eef0f8; vertical-align: top; }
.help-wrap tr:hover td { background: #f7f9ff; }
.help-tip { background: #fff8e6; border-left: 3px solid #f5a623; padding: 9px 13px; border-radius: 0 6px 6px 0; margin: 10px 0; color: #664d00; font-size: 13px; }
.help-note { background: #eef4ff; border-left: 3px solid #0254a8; padding: 9px 13px; border-radius: 0 6px 6px 0; margin: 10px 0; font-size: 13px; }
.help-toc { background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 8px; padding: 12px 18px; margin-bottom: 10px; }
.help-toc a { color: #0254a8; text-decoration: none; }
.help-toc a:hover { text-decoration: underline; }
</style>

<h1><?= htmlspecialchars(guide_text('HelpHeading')) ?></h1>
<p><a href="<?= htmlspecialchars($adminUrl) ?>">← <?= htmlspecialchars(guide_text('AdmBackAdmin')) ?></a></p>

<div class="help-wrap">

<div class="help-toc">
  <b><?= htmlspecialchars(hlp('HlpContents')) ?></b>
  <ul style="margin:6px 0 0">
    <?php foreach ($sections as $anchor => $key): ?>
      <li><a href="#<?= $anchor ?>"><?= ($num[$anchor] + 1) . '. ' . htmlspecialchars(hlp($key)) ?></a></li>
    <?php endforeach; ?>
  </ul>
</div>

<?= hlpHeading('idea') ?>
<p><?= hlp('HlpIdeaP1') ?></p>
<p><?= hlp('HlpIdeaP2') ?></p>

<?= hlpHeading('course') ?>
<table>
  <tr><th><?= htmlspecialchars(hlp('HlpColField')) ?></th><th><?= htmlspecialchars(hlp('HlpColPurpose')) ?></th></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpCourseFTitle')) ?></b></td><td><?= hlp('HlpCourseVTitle') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpCourseFDesc')) ?></b></td><td><?= hlp('HlpCourseVDesc') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpCourseFVersion')) ?></b></td><td><?= hlp('HlpCourseVVersion') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpCourseFThumb')) ?></b></td><td><?= hlp('HlpCourseVThumb') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpCourseFId')) ?></b></td><td><?= hlp('HlpCourseVId') ?></td></tr>
</table>

<?= hlpHeading('step') ?>
<p><?= hlp('HlpStepIntro') ?></p>
<ul>
  <li><?= hlp('HlpStepLiTitle') ?></li>
  <li><?= hlp('HlpStepLiContent') ?></li>
  <li><?= hlp('HlpStepLiImage') ?></li>
  <li><?= hlp('HlpStepLiPage') ?></li>
  <li><?= hlp('HlpStepLiOptions') ?></li>
</ul>
<p><?= hlp('HlpStepOutro') ?></p>

<?= hlpHeading('content') ?>
<p><?= hlp('HlpContentIntro') ?></p>
<ul>
  <li><?= hlp('HlpContentLiFormat') ?></li>
  <li><?= hlp('HlpContentLiLists') ?></li>
  <li><?= hlp('HlpContentLiTip') ?></li>
  <li><?= hlp('HlpContentLiCode') ?></li>
</ul>
<div class="help-tip"><?= hlp('HlpContentTip') ?></div>

<?= hlpHeading('images') ?>
<ul>
  <li><?= hlp('HlpImagesLiOne') ?></li>
  <li><?= hlp('HlpImagesLiFormats') ?></li>
  <li><?= hlp('HlpImagesLiRatio') ?></li>
  <li><?= hlp('HlpImagesLiAbove') ?></li>
  <li><?= hlp('HlpImagesLiOptional') ?></li>
</ul>
<div class="help-note"><?= hlp('HlpImagesNote') ?></div>

<?= hlpHeading('triggers') ?>
<p><?= hlp('HlpTriggersIntro') ?></p>

<h3>⚡ <?= htmlspecialchars(hlp('HlpTrigActionTitle')) ?></h3>
<table>
  <tr><th><?= htmlspecialchars(hlp('HlpColField')) ?></th><th><?= htmlspecialchars(hlp('HlpColPurpose')) ?></th></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpTrigFPage')) ?></b></td><td><?= hlp('HlpTrigVPage') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpTrigFType')) ?></b></td><td><?= hlp('HlpTrigVType') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpTrigFSelector')) ?></b></td><td><?= hlp('HlpTrigVSelector') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpTrigFTooltip')) ?></b></td><td><?= hlp('HlpTrigVTooltip') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpTrigFRequired')) ?></b></td><td><?= hlp('HlpTrigVRequired') ?></td></tr>
</table>

<h3>✓ <?= htmlspecialchars(hlp('HlpTrigStateTitle')) ?></h3>
<p><?= hlp('HlpTrigStateP1') ?></p>
<p><?= hlp('HlpTrigStateP2') ?></p>
<p><?= hlp('HlpTrigStateP3') ?></p>
<div class="help-note"><?= hlp('HlpTrigStarNote') ?></div>

<h3><?= htmlspecialchars(hlp('HlpBranchTitle')) ?></h3>
<p><?= hlp('HlpBranchIntro') ?></p>
<ul>
  <li><?= hlp('HlpBranchLiIf') ?></li>
  <li><?= hlp('HlpBranchLiIfNot') ?></li>
</ul>
<p><?= hlp('HlpBranchP') ?></p>
<div class="help-tip"><?= hlp('HlpBranchTip') ?></div>

<?= hlpHeading('selectors') ?>
<p><?= hlp('HlpSelIntro') ?></p>
<ul>
  <li><?= hlp('HlpSelLiInspect') ?></li>
  <li><?= hlp('HlpSelLiId') ?></li>
  <li><?= hlp('HlpSelLiClass') ?></li>
  <li><?= hlp('HlpSelLiStable') ?></li>
</ul>
<div class="help-tip"><?= hlp('HlpSelTip') ?></div>

<h3><?= htmlspecialchars(hlp('HlpSelDynTitle')) ?></h3>
<p><?= hlp('HlpSelDynP1') ?></p>
<ul>
  <li><?= hlp('HlpSelDynLiPrefix') ?></li>
  <li><?= hlp('HlpSelDynLiOther') ?></li>
</ul>
<p><?= hlp('HlpSelDynP2') ?></p>

<?= hlpHeading('record') ?>
<p><?= hlp('HlpRecIntro') ?></p>
<ul>
  <li><?= hlp('HlpRecLiSaved') ?></li>
  <li><?= hlp('HlpRecLiPanel') ?></li>
  <li><?= hlp('HlpRecLiPage') ?></li>
  <li><?= hlp('HlpRecLiPause') ?></li>
  <li><?= hlp('HlpRecLiFinish') ?></li>
</ul>
<div class="help-tip"><?= hlp('HlpRecTip') ?></div>

<?= hlpHeading('options') ?>
<table>
  <tr><th><?= htmlspecialchars(hlp('HlpColOption')) ?></th><th><?= htmlspecialchars(hlp('HlpColEffect')) ?></th></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpOptFOptional')) ?></b></td><td><?= hlp('HlpOptVOptional') ?></td></tr>
  <tr><td><b><?= htmlspecialchars(hlp('HlpOptFStrict')) ?></b></td><td><?= hlp('HlpOptVStrict') ?></td></tr>
</table>

<?= hlpHeading('activities') ?>
<p><?= hlp('HlpActIntro') ?></p>
<table>
  <tr><th><?= htmlspecialchars(hlp('HlpColAchievement')) ?></th><th><?= htmlspecialchars(hlp('HlpColEarnedBy')) ?></th></tr>
  <tr><td>🎯 <?= htmlspecialchars(guide_text('TargetBronze')) ?></td><td><?= hlp('HlpActBronze') ?></td></tr>
  <tr><td>🎯 <?= htmlspecialchars(guide_text('TargetSilver')) ?></td><td><?= hlp('HlpActSilver') ?></td></tr>
  <tr><td>🎯 <?= htmlspecialchars(guide_text('TargetGold')) ?></td><td><?= hlp('HlpActGold') ?></td></tr>
</table>
<ul>
  <li><?= hlp('HlpActLiQuiz') ?></li>
  <li><?= hlp('HlpActLiChallenge') ?></li>
</ul>
<p><?= hlp('HlpActShipped') ?></p>
<ul>
  <li><?= hlp('HlpActLiState') ?></li>
  <li><?= hlp('HlpActLiVisited') ?></li>
</ul>

<?= hlpHeading('path') ?>
<ul>
  <li><?= hlp('HlpPathLiPath') ?></li>
  <li><?= hlp('HlpPathLiChecklist') ?></li>
  <li><?= hlp('HlpPathLiTrouble') ?></li>
  <li><?= hlp('HlpPathLiContext') ?></li>
</ul>

<?= hlpHeading('accounts') ?>
<p><?= hlp('HlpAccountsP') ?></p>

<?= hlpHeading('translate') ?>
<p><?= hlp('HlpTransP1') ?></p>
<ul>
  <li><?= hlp('HlpTransLiBase') ?></li>
  <li><?= hlp('HlpTransLiEdit') ?></li>
  <li><?= hlp('HlpTransLiEmpty') ?></li>
</ul>
<div class="help-note"><?= hlp('HlpTransNote') ?></div>
<p><?= hlp('HlpTransP2') ?></p>

</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
