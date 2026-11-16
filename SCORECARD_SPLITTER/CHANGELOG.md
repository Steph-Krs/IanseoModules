# Changelog — SCORECARD_SPLITTER

## 0.2.1 — 2026-11-16

Contact address of the module updated (credits page).

## 0.2.0 — 2026-09-28

Version 0.2.0.

The QR codes of the scoring applications (Ianseo ScoreKeeper) are printed on the scorecards, as in the core's printout, and are on by default: scanned on a device, each opens the target of the page, in its session and at its distance. The personal target-request QR code is offered too when the competition uses personal devices. The barcode is now off by default.

A new option prints the images of the full-page header without its text (title, organiser, place, dates), for a competition whose header image already carries all of it.

Version 0.1.0.

First version. The scorecards of the open competition, split into files: one PDF per club, holding all its archers, or one PDF per archer, downloaded together as a ZIP archive, or one club at a time from the list of clubs.

Only the pages that carry at least one archer are printed, however many positions a target has: a target of eight positions holding three archers prints one page, not two. The free positions of a printed page stay as blank grids. The target number can be hidden, for competitions where positions only serve to give each archer their own scorecards.

Scorecards are drawn by the core's own scorecard class, with the options of the core's printout (sessions, distances, header, flags, barcode). ianseo's own scorecard printout is not changed. The module creates no table and writes nothing to the database.
