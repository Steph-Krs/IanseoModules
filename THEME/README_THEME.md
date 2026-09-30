# Theme

Module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

A **dark mode** for ianseo, and **eight colours** to see at a glance which installation you are
working on: the club's server, a laptop, an online server…

The module changes nothing in the ianseo core: it gives new values to the colour variables ianseo
already declares (`Common/Styles/colors.css`), the way debug mode does.

## Features

- 🌙 **Three modes**, chosen from the **Modules → Theme** menu: **Automatic** (follows the light
  or dark setting of the computer, and changes with it — the default), **Light** or **Dark**.
- 🎨 **Eight colours**, each with a light and a dark version: ianseo blue, fir green, teal, olive,
  violet, raspberry, burgundy, slate. The **Modules → Theme → Colours…** page previews each one.
- 🔍 **Measured readability**: the light versions keep the lightness of each colour of ianseo's
  blue; every text and background pair has a contrast ratio of at least 6.5:1 (the recommended
  minimum is 4.5:1).
- 🚦 **Status colours keep their meaning** in dark mode: green for an archer who may shoot, yellow
  for what is incomplete, red for an error…
- 🖨️ **Printouts unchanged**: the theme only applies to the screen, PDF files and printouts stay
  the same.
- 🐞 **Debug mode stays visible**: its orange colours replace the chosen colour, dark version
  included.

The choice **belongs to each browser, for each installation**: two people, or two installations
opened on the same computer (even on `localhost` with different ports), each keep their own.

### Pages without a menu

Popup windows (the participant editor, for instance) and the Speaker pages print no menu, so no
file of any module is loaded there. The administrator can give them the theme from the
**Colours…** page: a button adds a few lines to `Common/DebugOverrides.php`, a file ianseo
includes on every page and that its updates leave alone. These lines:

- load the theme in the head of **every page drawn in ianseo's standard colours**, which also
  removes the brief light flash while the other pages load;
- leave public screens (TV output, scoring apps), JSON and PDF responses and downloads untouched;
- do nothing once the module is uninstalled; the same button removes them.

If the web server may not write that file, the page shows the lines to copy into it by hand.

## Access

- Choosing a mode and a colour: every user. The setting only affects their own browser.
- Pages without a menu, update and uninstall: administrator.

## Database

The module **creates no table** and **writes nothing** to the database. Each user's choice is kept
by their browser, in a cookie.

## Installation, update, uninstall

See the [main README](../README.md). In short: copy the `THEME/` and `_shared/` folders into
`Modules/Custom/` (or run `install.sh` / `install.ps1`). Updates and uninstalling from ianseo:
**Modules → Theme → Module update** menu.

If the pages without a menu were given the theme, disabling the option before uninstalling also
removes the lines from `Common/DebugOverrides.php`. Left behind, they have no effect.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
