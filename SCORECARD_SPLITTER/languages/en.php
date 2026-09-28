<?php
/**
 * English strings of the scorecard splitter — the reference every other language
 * falls back to, so no key may be missing here.
 *
 * The labels of the options shared with the core's scorecard printout are not
 * here: the page takes them from the core's own language files.
 */

// Module and menu
$lang['ModuleName'] = 'Scorecards by club or archer';
$lang['MenuPrint'] = 'Print the scorecards';
$lang['MenuUpdate'] = 'Update module';
$lang['UpdateTitle'] = 'Scorecards by club or archer — module update';
$lang['BackToPage'] = 'Back to the scorecards';

// Page
$lang['Lead'] = 'The scorecards of the open competition, split into files: one per club, holding all its archers, or one per archer. Only the pages that carry at least one archer are printed, however many positions a target has; the free positions of a printed page stay as blank grids. ianseo\'s own scorecard printout is not affected.';
$lang['OptionsTitle'] = 'Scorecards';
$lang['SessionsTitle'] = 'Sessions';
$lang['SessionCards'] = '({$a} scorecards)';
$lang['LayoutTitle'] = 'Layout';
$lang['HideHeaderText'] = 'Hide the text of the page header (title, organiser, place, dates): its images only, for a header image that already says it all';
$lang['HideTarget'] = 'Hide the target number';
$lang['HideTargetHint'] = 'For a competition where the positions only serve to give each archer their own scorecards, such as a challenge shot in the clubs: the number means nothing to the archer.';

// Archive
$lang['ArchiveTitle'] = 'Archive';
$lang['ModeClub'] = 'One file per club, named after its code';
$lang['ModeArcher'] = 'One file per archer, named after their licence number';
$lang['ZipButton'] = 'Download the ZIP';
$lang['Starting'] = 'Preparing…';
$lang['Progress'] = '{$a[done]} / {$a[total]} files';
$lang['Packing'] = 'Packing the archive…';
$lang['Ready'] = 'The archive is ready: the download starts.';

// List of clubs
$lang['ClubsTitle'] = 'Clubs ({$a})';
$lang['ClubsHint'] = 'The PDF of a club uses the options above.';
$lang['ColCode'] = 'Code';
$lang['ColClub'] = 'Club';
$lang['ColArchers'] = 'Archers';
$lang['ColCards'] = 'Scorecards';
$lang['ColPdf'] = 'PDF';
$lang['ClubPdf'] = 'PDF of {$a}';
$lang['NoClub'] = 'No club';
$lang['Total'] = 'Total';

// What cannot be printed
$lang['NothingToPrint'] = 'No archer is on a target in the qualification sessions yet: there is nothing to print.';
$lang['NotTargetArchery'] = 'This module prints target archery scorecards, and this competition is a field or 3D one.';
$lang['WarnOutside'] = '{$a} scorecard(s) sit on a position outside the targets of their session, or beyond its number of archers per target. Like ianseo\'s own printout, the module does not print them.';
$lang['WarnTwice'] = '{$a} position(s) are given to several archers. Only one scorecard fits a position: check the target assignment.';
$lang['WarnUnplaced'] = '{$a} archer(s) have no target yet, and so no scorecard.';

// Errors
$lang['ErrAccess'] = 'No competition is open, or you may not print its scorecards.';
$lang['ErrToken'] = 'The session has expired. Reload the page and try again.';
$lang['ErrNoSession'] = 'Choose at least one session.';
$lang['ErrNothing'] = 'Nothing to print with these options.';
$lang['ErrZip'] = 'The zip extension of PHP is missing on this server: the PDF of each club can still be opened from the list below.';
$lang['ErrTemp'] = 'The temporary folder of the server cannot be written to.';
$lang['ErrJob'] = 'This download has expired or is unknown. Start it again.';
$lang['ErrServer'] = 'The server did not answer as expected (HTTP {$a[status]}).';
$lang['NotFound'] = 'Nothing to print for this file with these options.';
