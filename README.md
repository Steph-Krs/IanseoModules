# IanseoModules

*Version française : [README_FR.md](README_FR.md)*

Modules for the I@nseo project:
- https://www.ianseo.net/
- https://www.facebook.com/ianseoarchery

## Available modules

<!-- KEEP UP TO DATE: one line per published module, format "[Name](FOLDER/README_FOLDER.md) — summary"; same line in French in README_FR.md. -->

- [National Mixed Team Trophy](TNM/README_TNM.md) — Running a mixed-team competition:
  qualifications, Round Robin pools (2 rounds) then a final Big Shoot Off, printouts (pool sheets,
  rankings, scorecards), and entry / commentator view of the shoot-offs.
- [Interactive guide](GUIDE/README_GUIDE.md) — Step-by-step help panel shown on every ianseo page:
  multi-step courses, quizzes and challenges, highlighting of page elements and progress tracking.
  Designed for clubs starting out with ianseo.
- [Pools — commentator view](POULES/README_POULES.md) — Live view of what is at stake in a team
  Round Robin pool phase: real-time ranking, range of final ranks each team can still reach,
  detection of the deciding matches. Read only.
- [FFTA extranet gateway](SYNCHRO_FFTA/README_SYNCHRO_FFTA.md) — Upload of the results (TXT file)
  and creation of an ianseo competition from an event of the FFTA extranet.
- [Live predictions](PRONO/README_PRONO.md) — Points-based prediction game (no stake, no money) on
  qualifications and eliminations, individual and team: odds computed arrow by arrow from the
  qualifications (and from the FFTA national ranking if the REPARTITION_EPREUVES module is
  installed) and updated end after end, installable public page for phones, venue screen.
- [List export](EXPORT_LISTE/README_EXPORT_LISTE.md) — CSV export of the participants in the format
  of the list import (10 columns), with the club's registration number and name. Read only.
- [Multi-account & online registration](AUTH/README_AUTH.md) — Turns an ianseo server hosted online
  into a **shared server for several organisers** (each organisation sees only its own
  competitions, controlled sharing, central sign-in, 2FA, log) **with online registration**:
  licensed archers sign in with their federation credentials (no password stored), browse the
  calendar of open competitions and register themselves. Automatic target assignment (federation
  rules, including target faces sharing a boss), group registration by a clubmate, payment
  tracking, invitation and scorecards. On site: food stand and shop with orders by phone, check-in
  desk and equipment inspection, volunteer team on phones. Includes the `booking/`
  sub-module (formerly "Online registration").
- [French national team selection](SELEC/README_SELEC.md) — Rankings of a national selection
  event: qualifications, days with cumulative points, up/down tournaments, simulated pools and
  matches. The rules are plain configuration files; every ranking keeps its intermediate values
  and the criterion that broke the tie.
- [Event allocation](REPARTITION_EPREUVES/README_REPARTITION_EPREUVES.md) — Bulk target assignment
  for large competitions: download of the national rankings, plan of the sessions by blocks of
  events, placement rules (ranking, club order, serpentine) and checks before assignment.
- [Live draw](TIRAGE/README_TIRAGE.md) — Screens for a public draw (order of the teams of a
  division…), with no competition open: animated public screen revealing each team drawn, control
  room, and a commentators' screen with each team's history and its previous season read from
  ianseo (ranking, matches, archers and their national ranking).
- [Scorecards by club or archer](SCORECARD_SPLITTER/README_SCORECARD_SPLITTER.md) — Scorecard
  printout split into files, one PDF per club or per archer, gathered in a ZIP archive: no blank
  page even with more than four places per target, ISK entry QR code on every page, target number
  and header text can be hidden. The core printout is unchanged. Read only.
- [Automatic sending](AUTO_SEND/README_AUTO_SEND.md) — Opening and closing of ISK-NG scoring and
  sending of the results to ianseo.net at set times, with no browser open: scheduled task of the
  server, daylight saving handled, the same lists as the core's sending page, simulation mode,
  history, and outside monitoring (healthchecks.io) that warns when sending stops.
- [Theme](THEME/README_THEME.md) — Dark mode (automatic, light or dark) and eight colours to tell
  your installations apart at a glance, each colour in a light and a dark version. Set per browser,
  printouts unchanged, no change to the core. No table.
- [Scorecard reading](OCR/README_OCR.md) — Photograph a scorecard printed by ianseo (computer
  camera or phone): grid found and straightened, archer identified by the barcode or the target,
  every box read and checked, then written into ianseo; and match scorecards (brackets and pools):
  match recognised by the barcode or the names, set points and winner checked on both cards, match
  confirmed as by barcode verification. In development. No table.

## Automatic installation (Mac / Linux)

A **single command** to paste into the terminal. It downloads then runs the installer.

```bash
curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash
```

## Automatic installation (Windows)

The Windows equivalent, to paste into **PowerShell**.

```powershell
irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
```

## Manual installation (Windows, Mac, Linux)

An ianseo module is just a **folder** to put in `Modules/Custom/` of your ianseo installation.
This `Custom/` folder is the **only one ianseo never overwrites** during its official updates.

Installing a module therefore means copying, into `…/Modules/Custom/`:
- the **module's folder** (e.g. `GUIDE/` or `TNM/`);
- the **shared folder `_shared/`**, used by every module.

Where `Modules/Custom/` is, depending on your system:

| System | Usual location |
|---|---|
| Windows (XAMPP) | `C:\ianseo\htdocs\Modules\Custom\` |
| Linux | `/opt/ianseo/Modules/Custom/` or `/var/www/ianseo/Modules/Custom/` |
| Mac (MAMP) | `/Applications/MAMP/htdocs/Modules/Custom/` |

Without a terminal, this method works everywhere:

1. Download the repository as a ZIP: **Code → Download ZIP** button on
   https://github.com/Steph-Krs/IanseoModules
   (or directly https://github.com/Steph-Krs/IanseoModules/archive/refs/heads/main.zip).
2. Unzip the archive.
3. Copy the **module's folder** you want (e.g. `GUIDE`) **and** the **`_shared`** folder into your
   `…/Modules/Custom/` (see the table of locations above).
4. That's it: open ianseo, the module's entry appears in the menu.

## Updating a module

Once installed, a module is updated **from ianseo**, without any command line: the module's
administration page → **Check / Update** (`admin/update.php`, for the **Root** profile only). The
module compares its local version (`version.json`) with the repository's and downloads the
changed files again.

Running `install.sh` again reinstalls cleanly (idempotent: your local `module.json`, with its
GitHub token if any, is kept).

## Uninstalling a module

Uninstalling is done **from ianseo**, without any command line, by the **administrator** only: the
module's administration page → **Update** → folded section **"Uninstall the module"**.

How it goes, and the safeguards:

- Confirmation by **typing the module's name**.
- **No backup by default**: the files can be recovered from GitHub (a reinstallation restores
  them). A sensitive module may offer a **downloadable ZIP backup**, deleted from the server as
  soon as it is downloaded.
- Only folders holding a `module.json` are concerned; `_shared/` is never deleted.
- If the module creates tables, a box **unticked by default** offers to delete them too (otherwise
  they are kept and a reinstallation finds the data again).

> With the **AUTH** accounts module active, this page — like updating and installing other modules
> — is **visible and accessible to the server administrator only**.
