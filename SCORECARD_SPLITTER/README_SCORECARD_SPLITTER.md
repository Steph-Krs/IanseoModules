# Scorecards by club or archer

Module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

Prints the **scorecards of the open competition split into files**: one PDF per club, holding all
its archers, or one PDF per archer — downloaded together as a ZIP archive. Made for competitions
where each club, or each archer, receives their own scorecards: a challenge shot in the clubs, for
instance, where a "target" only serves to give an archer the scorecards of their different levels.

ianseo's own scorecard printout is not changed: the module is a separate page.

## Features

- 📦 **One file per club** (`<club code>.pdf`) **or one per archer** (`<licence number>.pdf`), in a
  ZIP archive. The archive is built in short steps with a progress bar, so that a competition of
  several thousand archers never runs into a time limit of the server.
- 📄 **No empty page**, however many positions a target has: a target of eight positions holding
  three archers prints one page, not two. On a printed page, the free positions stay as **blank
  grids**, which a club can still use.
- 🙈 **Target number hidden** if wanted, for competitions where positions mean nothing to the
  archer.
- 🏷️ **The PDF of one club** can be opened on its own from the list of clubs, to print or send it
  again without building the whole archive.
- ⚙️ **The options of the core's printout**, under the core's own labels: sessions, distances,
  full-page header and footer, competition header and images, flags, archer information, barcode
  (with the Barcodes module).
- 📊 **Counts before printing**: archers and scorecards per club and per session, and a warning
  for anyone who would not be printed (archer without a target, position outside the session's
  layout, position given twice).

Each file holds only its owner's scorecards: a club never receives another club's archers, and
every scorecard keeps the place on the sheet that ianseo's printout gives it. Within a club's
file, scorecards follow the archers' names, all the scorecards of an archer together.

Scorecards are drawn by the core's own class (`ScorePDF`), four to a page as in the core's
printout. The competition's images are embedded at their printed size, at 300 dpi, so that each
file stays light: about 100 KB for an archer's file with the full-page header, instead of 300 KB.

## Access

- Any user who may print the scorecards of the open competition (read access to the
  qualification round, as for the core's printout). The module's menu entry appears while a
  competition is open.
- Update and uninstall: administrator.

## Database

The module **creates no table** and **writes nothing** to the database. It reads the open
competition: entries, qualification positions, sessions, clubs, distances and target faces.

The files of an archive are built in a working folder of the server's temporary directory (never
under `Modules/Custom/`, which the web server may serve), belong to the session that started them,
and are removed once the archive has been downloaded — or after a day if the download never
happened.

## Installation, update, uninstall

See the [general README](../README.md). In short: copy the `SCORECARD_SPLITTER/` and `_shared/`
folders into `Modules/Custom/` (or run `install.sh` / `install.ps1`). Updates and uninstall from
ianseo: menu **Modules → Scorecards by club or archer → Update module**, with a competition open.

The ZIP archive needs PHP's `zip` extension, present in most installations; without it, the PDF
of each club can still be opened from the list.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
