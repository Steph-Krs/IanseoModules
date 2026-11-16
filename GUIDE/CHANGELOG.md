# Changelog — GUIDE

## 0.8.6 — 2026-11-16

Contact address of the module updated (credits page).

## 0.8.5 — 2026-10-07

The administration pages use the whole width of the screen instead of a fixed column.

Previously (0.8.4): Tables follow the core naming convention (GuideProgress, GuideVisits, GuidePrefs). Older installations are migrated automatically and the former names stay reachable as views.

Interface and content in English, French, Italian, German and Spanish. The interface goes through the core's own language mechanism; a course carries its translations inside its own file, where only the text of a field becomes a language map — the structure is never duplicated, so the languages cannot drift apart and point at different elements of ianseo. All twelve courses are complete in the five languages, quiz and challenge included, and their menu paths use the core's own wording so a reader finds what a course names. The editor has a language selector and a base language, and the authoring help is a section of its own so that it is not loaded on every ianseo page.

menu.php no longer creates the schema, and the one read it still makes is guarded: a database problem in this module can no longer take a whole ianseo installation down. The module root and its URLs are discovered rather than hard-coded to a directory depth.

The API answers through the core's JsonOut(), in the ianseo envelope — error 0 for success, msg for the reason. A challenge asks about all of its conditions in one request rather than one per condition every ten seconds, and a checklist does the same for the items that tick themselves.

Adds credits.php in the format the ianseo team proposed, reading its version and date from this file so the two cannot disagree. Code and comments in English, with a French README alongside the English one.
