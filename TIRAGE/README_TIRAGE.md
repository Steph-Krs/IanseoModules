# Live draw

Module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

The screens of a **public draw ceremony** — for instance the order of the teams of a national
division: a screen for the room or the broadcast, a control page to record each team as it is drawn,
and a screen for the speaker and the commentators. The draw itself is made by hand, in the room; the
module accompanies it. **It belongs to no competition**: it is used with no competition open.

## Features

- 📺 **Public screen**, full screen (projector, TV, video mixer): waiting loop over an animated
  background, the list being drawn with each team revealed in the middle of the screen, final summary
  of every team list. Colours, fonts, sizes, margins, background image and veil can be set.
- 🗓️ **The season's stages**: a dedicated list where nothing is drawn — each stage (venue, dates)
  appears on its own card, one after the other, as the speaker announces it.
- 🎛️ **Control page**: one click gives a team the next place of its list and shows it; keyboard
  search (Enter draws the team when only one matches), withdrawing a place, undoing the last one,
  resetting a list, choosing the scene on screen and the history columns, preview of the public
  screen.
- 📋 **List ready to paste into ianseo**: the club codes in drawn order, for the Setup screen of the
  new season's first division competition (refused while a team has no club code, so that nobody
  is shifted).
- 🎙️ **Commentators' screen**: the team just drawn comes up on its own, any other can be looked up in
  advance, and a **"Back to live"** button returns to the last place given. History (participations, wins, podiums, previous rank), free note, and — when the draw is
  tied to the previous season's ianseo competition — final rank, matches won and lost, points per
  arrow, recent form, results and qualification stage by stage. Figures among the three best of the
  category are shown in **gold, silver or bronze**. And the team's archers with their
  **national ranking** (when the [Event allocation](../REPARTITION_EPREUVES/README_REPARTITION_EPREUVES.md)
  module has downloaded the rankings).
- 🔗 **Synchronised through the server**: public screen, control page and commentators' tablets can
  run on different machines; nothing is lost when a browser closes.
- 📋 **Preparation**: teams imported from the team events of an ianseo competition, teams linked to
  their club automatically, the previous season added to the history in one click, a draw copied to
  prepare next year's, and import of a save of the former stand-alone HTML draw page.

Rankings are read through the ianseo core's `Rank` classes: for the French first division these are
the classes of the French rule set, the same ones the printouts use.

## Access

- Draw list, preparation and control: the right to create a competition (`AclRoot`, read-write).
- Public screen and commentators' screen: **secret link, no ianseo account**. Two distinct links per
  draw — only the commentators' one shows the notes. Links can be renewed.
- Update and uninstall: administrator.

## Database

Three tables of the module's own, created when one of its pages is opened:

| Table | Content |
|---|---|
| `DrawShows` | a draw: titles, previous-season competition, screen links, scene on screen, look, background image |
| `DrawCategories` | a list: teams to draw (optionally tied to an event of the previous-season competition) or stages |
| `DrawTeams` | a team or a stage: drawn place, club code, dates, history, note for the commentators |

The module **reads** ianseo's tables (competitions, teams, round robins, team components) and, when
they exist, the national ranking tables of the Event allocation module. It **writes to no ianseo
table**.

## Installation, update, uninstall

See the [general README](../README.md). In short: copy the `TIRAGE/` and `_shared/` folders into
`Modules/Custom/` (or run `install.sh` / `install.ps1`). Updates and uninstall from ianseo: menu
**Modules → Live draw → Update module**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `DrawShows` | `api/manage.php:182` | — |
| `UPDATE` | `DrawCategories` | `api/manage.php:213` | — |
| `UPDATE` | `DrawCategories` | `api/manage.php:230` | — |
| `DELETE FROM` | `DrawTeams` | `api/manage.php:238` | — |
| `DELETE FROM` | `DrawCategories` | `api/manage.php:239` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:240` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:283` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:298` | — |
| `DELETE FROM` | `DrawTeams` | `api/manage.php:307` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:364` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:389` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:398` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:402` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:413` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:421` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:428` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:461` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:469` | — |
| `UPDATE` | `DrawCategories` | `lib/legacy.php:94` | — |
| `UPDATE` | `DrawShows` | `lib/legacy.php:154` | — |
| `UPDATE` | `DrawShows` | `lib/store.php:239` | — |
| `INSERT INTO` | `DrawShows` | `lib/store.php:273` | — |
| `DELETE FROM` | `DrawTeams` | `lib/store.php:286` | — |
| `DELETE FROM` | `DrawCategories` | `lib/store.php:287` | — |
| `DELETE FROM` | `DrawShows` | `lib/store.php:288` | — |
| `INSERT INTO` | `DrawShows` | `lib/store.php:304` | — |
| `INSERT INTO` | `DrawCategories` | `lib/store.php:313` | — |
| `INSERT INTO` | `DrawTeams` | `lib/store.php:316` | — |
| `INSERT INTO` | `DrawCategories` | `lib/store.php:337` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:371` | — |
| `INSERT INTO` | `DrawTeams` | `lib/store.php:413` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:542` | — |
| `UPDATE` | `DrawShows` | `lib/store.php:543` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:555` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:556` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:566` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:576` | — |

<!-- END DATABASE WRITES -->
