# Changelog — THEME

## 0.1.1 — 2026-11-16

Contact address of the module updated (credits page).

## 0.1.0 — 2026-09-30

First version.

A dark mode for ianseo, and eight colours to tell installations apart at a glance: ianseo blue, fir green, teal, olive, violet, raspberry, burgundy and slate, each with a light and a dark version. The mode (automatic, light or dark) is chosen from the Modules menu; automatic follows the light or dark setting of the computer. The colours are chosen on the module's page, where each one is previewed.

The choice is kept by each browser, for each installation: two people, or two installations reached on the same computer, each keep their own. Printouts and PDF files never change, and debug mode keeps its orange colours, dark version included.

The theme works through the colour variables the core already declares, so it needs no change to the core. Pages that print no menu (popup windows, the Speaker pages) can be given the theme by the administrator, from the module's page: this adds a few lines to Common/DebugOverrides.php, a file ianseo updates leave untouched, and the lines do nothing once the module is removed.

The module creates no table and writes nothing to the database.
