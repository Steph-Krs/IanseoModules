# Scorecard reader

Module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

**Photograph a scorecard printed by ianseo** — with the computer's camera or a phone — to compare it
with what ianseo holds, and in time to enter it when no scoring devices were used.

> **In development (0.1).** This version finds the grid in the photo, identifies the archer, cuts out
> every cell and reads the handwritten values, checking the sums and running totals. The reading is
> still learnt from generic handwritten digits: it will improve with real scorecards. Saving into
> ianseo comes next.

## Features

- 🧭 **Asked first, in this order**: the session, the distance, the archers per sheet. The last
  offers only what ianseo can print for the competition's sessions (2 side by side up to 2 archers
  per target, 3 for 3, 5 or 6, 4 otherwise), and takes by itself the chosen session's. The capture
  shows once all three are given, kept for the competition in the browser: every reading depends on
  them.
- 📷 **Three modes with the camera, or an image imported**, in one window:
  - **Automatic**: the camera is fixed, facing the table, and only the sheets go under it. Until a
    grid is found, the live view is greyed but where the grids are to lie (where the last whole
    sheet's lay, else the target's cards side by side). As soon as every grid of the sheet is seen whole
    and large enough for its header and cells to be read, totals included, and the view is still,
    **the picture takes itself**: a white flash tells so. The page first waits a few seconds for the
    live view to identify the cards, whose headers it reads best. The window then freezes on the
    picture, which is read as a photo is, with no stray movement to disturb it. It folds away to
    leave the page to the archers' grids, and "Back to the live view" goes on to the next sheet.
    While waiting, the view of the moment is compared with the one before: a sheet changed, moved
    or taken away shows as soon as it is let go, and nothing is read while it is handled. Once grids
    are found, only their area counts: a thing passing beside the sheets disturbs nothing.
    Brightness and contrast are evened out, so that the camera's own exposure setting is not taken
    for another sheet.
  - **Manual**: the same live view, with the places to lay the sheet, and the picture is taken on
    the pause button, then read likewise.
  - **Video**: the camera in hand, to come close to the cells; the video alone is read (below).
  - **Photo or image file**: the phone's camera, or an image dropped on the page; the camera stops,
    it is one or the other.
- 🎚️ **Nothing to set before the first sheet** (automatic and manual modes): the places of the
  grids of a sheet read whole are kept by themselves in the browser, for the competition and the
  camera, and become the places shown next. In the automatic mode, the size of the first whole
  sheet's tables leaves out grids of another size (part of a table, two tables run together).
- 🪞 **Turn a quarter or mirror** the picture shown (the two buttons in the view's corners; four
  quarters: as it was), for a camera mounted another way: the display only, kept in the browser,
  never the reading. The view **folds** away or **unfolds** with the triangle in the middle of the
  bar; folded, nothing is drawn in it, and the reading goes on. Right of the bar: take the picture
  (viewfinder), back to the live view (video camera), stop the camera (camera struck out, red).
- 📱 **On a phone or a tablet**, the page takes the screen's width and the buttons are larger. Only
  the **Photo** button, which opens the phone's camera (handier than the live view), and the triangle
  are left, on one line: no automatic, manual or video mode, no camera buttons.
- 🔁 **Read again** (among each card's actions, right of "Validated in ianseo"): a grid set a line
  too high or too low (the header read as cells, say) is read again a line lower, then a line
  higher; at the next click, or at once when no grid was fitted or no archer told, the grid is
  **looked for again** on the photo cut around the card, closer, then read again. A place left
  without a grid, or a photo where no grid was found, offers to search the whole photo again. The
  **cells' size** is set with a bar (20 to 50 pixels), kept in the browser.
- 🔍 **Reading as the video goes** (video mode): the sheet is followed through the video frame
  after frame, whatever the camera does (moved, turned, brought closer, the sheet lost from sight
  and found again), by keypoints of the print and the writing. Its grids are drawn over the video,
  each in its colour with its archer once known; the slow work (grids, headers, cells, checks) is
  done aside, never stopping the video. Each cell is read as soon as the camera shows it large and
  sharp enough, and again when it shows it better; the readings of a cell are put together, the
  best weighing most. A cell read for sure takes a **green veil**: the camera is to be brought to
  the others. Each grid is put back on its own printed lines now and then (the header row and the
  number column bound the table), so that its outline keeps the table's shape as the camera moves.
  The **next sheet** is shown without stopping the camera. The view starts afresh on the new sheet
  in three cases:
  - the sheet is lost for more than a moment;
  - the header of a card identified, read again now and then (and at once when its cells read
    otherwise than they did), shows another archer;
  - the grids are found again elsewhere than the cards (a camera that stays where it is, the sheet
    changed under it).

  While the camera moves, the page says to hold it still: cells are read on still frames. The cards
  per sheet given, the page keeps no more grids than that, laid out as printed; the bowing of a
  webcam's lens is measured once and kept for that camera. When that measure misses its bowing and
  the still sheet shows fewer grids than expected, a few lenses are tried on the picture side by
  side, and the one that shows every grid is kept for that camera. As soon as the sheet's cards are
  identified, they **open by themselves** with their arrows, from a frame showing them whole, and
  keep being brought up to date as the camera goes on.
- 🟥 **What is drawn over the picture**, in every mode, told by a legend that folds:
  - a **red** cell tells a sure mistake: an arrow read for sure that ianseo holds otherwise (an
    entry mistake), or a running total or total that the arrows, all sure up to it, do not make (an
    addition mistake on the sheet);
  - the grey cells printed (arrow and end numbers) are drawn **purple**, where the grid is taken to
    lie: a grid a line off shows at once;
  - what is being looked at (cells in doubt, cells read, header) is always shown in the automatic
    and manual modes, as an option in the video mode.
- 🔳 **ISK's QR code** printed on the sheet, when there is one, gives its target, session and
  distance: the page sets its own session and distance, and finds each card among that target's
  archers.
- 🗂️ **The whole sheet of a target at once**: every scorecard of the photo (A, B, C… side by side) is
  found and read in turn; a bar shows each with where it stands (sure, to check, to identify), a
  click on it or on the photo shows it.
- 📐 **The grid is found and straightened** however the scorecard is held: tilted, in perspective,
  upside down or sideways, and the bowing of a wide-angle webcam's lens is corrected first. Only
  qualification scorecards drawn by ianseo are supported; the layout
  (number of lines, arrows per line, grand total column or not) follows from the archer's distance
  and is checked against the photo.
- 🏷️ **The archer is identified by the scorecard's barcode**, or else by the **target and the name
  printed on it**: the target is read first, then the name checks it among the archers of that
  target. In the live view, once a card of the sheet is identified, the others are read among the
  few archers left on that target, and a card beside it is given the archer its place calls for
  (the core prints a target's cards side by side in letter order), unless its name reads otherwise;
  such a card says so, to be checked; four cards are side by side, or two by two (A, B above C, D).
  How many ends were shot is told by a few arrows: an end whose first two are empty was not entered,
  nor the ones after it (checked on the next end's first two and the card's last two: six cells tell
  a card not shot); its cells are not read, drawn grey. A cell read for sure (on two checks in a row)
  keeps its value and is not read again, unless the card as a whole falls in doubt. The session is chosen in the page's filters; a scorecard of an archer from another
  session is pointed out, with a button to switch to it. If nothing reads well enough, the target is
  typed in. Name, target, category and distance are shown large, to check that it is the right
  scorecard.
- ✂️ **Every cell is cut out** — arrows, sums, running totals, counts of 10s and Xs, Total row — and
  shown beside the values ianseo already holds (entered on a scoring device or by hand).
- 🔢 **Values read and checked**: every arrow is read, every written number among the values its
  cell can hold with the archer's own target face at that distance (three arrows make 30 at most,
  and on a face scoring 6 to 10 neither 1 to 5 nor 11; a running total only what the arrows shot so
  far can make; a count no more than the arrows it counts), then the sums, running totals and counts the
  archer wrote decide between doubtful readings; a count of 10s or Xs left blank weighs little, as
  archers often forget it, and a line's sum cell holding the whole end's total (written there
  instead, on an end shot over two lines) is read as such. The **Total row** is what the archer
  signs (the points and the counts of the whole card): left blank by the archer, it stays blank and
  shows **yellow** to tell so; wrong, the right total is kept, **red**: the operator corrects the
  paper sheet; read otherwise than the arrows give without being sure, it is framed **orange**, to
  check: never taken silently. **A grid set a line off** shows by the cells the core prints grey:
  the header row over the arrows, and the end numbers' column, but not the distance cell above it.
  That is enough to tell which way to move the lines, and the grid is moved at once. On ends of two
  lines, the cells the print crosses out confirm it: a reading still in doubt is read again one line
  over, and kept if sound. All grids of a sheet face one way (header and name at the top, Total row
  at the bottom): a grid found the other way up from the others is fitted again their way, or left
  out. A sure value shows in green, one to check in
  orange, an arrow that differs from ianseo is framed in red, and an addition mistake of the archer,
  carried on to the later running totals and the Total row, is flagged on those totals (the arrows
  are what counts). A click on an arrow offers the values of the archer's target face to correct it.
- 🧾 **The target's cards side by side**, in the order A, B, C, D, a place without archer marked
  "---". Each has a coloured **button**:
  - **green**: every arrow and the three totals read for sure, right, and as ianseo holds them when
    it holds values already;
  - **orange**: some cells not read for sure;
  - **red**: a sure mistake, the archer's addition or against ianseo;
  - **moving blue**: being read.

  The button carries the reading's **eye** (green: all sure; orange: all read; red: some cells not
  read) and the **three totals**, each with its sign: checked (right), cross (the archer's addition
  mistake) or warning (not what ianseo holds). Each sign is green or red when the reading is sure,
  orange when it is not. The button ends with the **number of alerts** (mistakes and differences
  with ianseo). A legend that folds tells it all.
- 🔲 **The cells**: their picture is underlined orange when not read for sure, greyed and crossed when
  not read; a value corrected by hand becomes sure. Arrows are green when sure, or when they are
  what ianseo holds; an arrow other than in ianseo is red, framed red. The ends' column has no
  heading and "Total" is written upwards, the width left to the cells. Each written number (sums,
  running totals, counts, totals) shows "read / calculated from the arrows", with what ianseo holds
  in blue. The read one is green when right, red when not; the calculated one is red when ianseo
  holds otherwise. A number wrong for sure or not what ianseo holds is framed **red** on the Total
  row (the signed total, tens and Xs: what is written into ianseo), **orange** elsewhere (an end's
  sum, running total, counts: they only serve the checks). Each **signed total** can be given with one click what the arrows make ("= value":
  a total misread, or the archer's mistake kept right), or 0 when left blank ("= 0": blank, it
  counts 0, with no recount, as the "Remove" buttons of the barcode check do; one button when the
  arrows make 0 too); "undo" goes back to
  the reading. Correcting an arrow offers only the values of the archer's face, highest first (X,
  10, 9… 1, M, as the discipline has them), then "blank".
  Two quick checks on each card: "Accept the n arrows in doubt" takes them as read (most of the
  time right, only unsure; an arrow other than in ianseo stays to be corrected on its own), then,
  every arrow sure, "Accept the totals calculated" sets the three totals to what the arrows make.
  Writing into ianseo is offered only then. These buttons are in the card's coloured head, right of
  the archer and the totals, and come again on the Total row (but Read again). An end's sum, running total or count read unsurely is underlined yellow:
  they only serve the checks; the arrows and the signed totals are underlined orange.
- 💾 **Writing into ianseo**: "Import the data" while ianseo does not hold every arrow of the
  distance (none, or some missing: the first whole reading of the sheet), "Validate the data" then,
  by a later reading (see Database). A value read otherwise than ianseo holds is never written nor
  validated: the operator types the right one. When ianseo holds part of the arrows only, its
  totals are shown, not compared. Two boxes, as with ISK, import by themselves, either:
  - the whole sheet, once all its readings are sure; a grid printed at a place without archer
    does not hold it back;
  - each archer, as soon as all their values are.

  A write the server refuses is not tried again by itself: the card then shows what ianseo holds,
  and only the operator's click tries again. The sheet written in full, the view goes back to live
  for the next one; the sheet left under the camera is not taken again until the view changes.
- 🎞️ **Series capture**: the camera stays on and every scorecard shown is photographed as soon as its
  grid is found and stays still, with a beep; the next one follows without touching anything, in the
  same place if you like. Without the live camera, "Photo" opens the phone's camera and "Send photos"
  sends those of the gallery all at once. The photos are kept on the server for the open
  competition, and can be downloaded as one ZIP archive.

## Privacy

On the reading page, the photo is analysed **in the browser** and never sent to the server: the
barcode, or the printed target and name, are read there, against the list of the competition's
archers that the page gets from the server.

The photos of the series capture are sent to the server: they are kept in its temporary directory
(never under the web site's root), per competition, for the users who may enter scores, and are
**deleted on their own after 7 days**.

Two options for the module's development also send something to the server, kept the same way.
**Recording the trial** (the camera's video and what the page made of it, deleted after 7 days) is
off at first. **Sending the cells in doubt** is on: a small picture of each cell the reading could
not make sure of, handwritten figures only and never a name, deleted after 60 days. Both can be
unticked on the page, and the choice is kept in the browser. The cells in doubt are
shown on the page **Cells to check**, a few dozen at a time with what was read typed in already,
each with what it is on the card and the highest number it can hold (only the readings its archer's
face can make are offered, a number it cannot make is flagged): the person checking corrects those that are wrong,
marks with one click (or "*", on the number pad) a cell cut out badly (across two cells, off the grid), and validates with Enter.
Each cell is shown large with its neighbours, a dozen at a time, with its target and distance to find
the sheet again. What is typed there is
what the reading networks learn from next.

## Access

- Reading: any user with access to the qualification round of the open competition. Series
  capture, cells to check: the right to enter qualification scores. The menu entries appear in the **Modules** tab
  while a competition is open.
- The live camera needs a secure page: https, or the server's own computer. A local ianseo is used
  over http, and the firewall rule its installer makes opens the http ports only. From another
  computer or a phone, with Chrome: a setting made once on the device ("Insecure origins treated as
  secure", in `chrome://flags`), which the module's pages explain with the exact address to paste —
  nothing to change on the server. Without it, the phone's camera and gallery remain usable on both
  pages.
- **Development tools** (series capture, cells to check, the reading page's trial options):
  hidden at first. The administrator shows them, for the whole installation, on the module's update
  page ("Become a developer"); hidden, no menu, no options, and their pages and endpoints refuse to
  serve. On a shared server, everyone who may enter the qualification scores sees them once shown.
- Update and uninstall: administrator.

## Reading the handwriting

Three small neural networks, shipped with the module (`assets/model/`), read the cells in the
browser, with no outside service: one for the arrows, one for single digits, one for the numbers
read whole (sums, running totals, counts), which needs no parting of digits that touch or run into
each other. They were trained on the **EMNIST** handwritten digits and letters of NIST (*National
Institute of Standards and Technology*), then on photos of real scorecards of past competitions,
labelled by the arrows their database holds, and on the cells a person checked on the page Cells
to check (an X written "+" or "10+" is read as an X); nothing of those photos is shipped, only the
networks' weights.

Each cell is cut out with a **margin**: a stroke running over from it is read with it, one running
over from the next cell is left out, the grid's lines are taken out. Touching digits are parted
along the lightest way between them, and numbers are also read whole, the two readings combined.
In the video mode only, each cell is also **reframed** on its own lines before it is read: each
border is set on the nearest line, in a margin around the cell. That gives more sure cells from the
video, and nothing on a picture. These settings are each mode's and are no longer chosen on the
page. Three options remain, for the module's development:
- show what is being looked at, in the video mode only;
- record the trial;
- send the cells in doubt.

QR codes are read by the browser's own reader where there is one, otherwise by
[jsQR](https://github.com/cozmo/jsQR) 1.4.0 (`assets/js/vendor/jsQR.js`), under the Apache License
2.0 (`assets/js/vendor/jsQR-LICENSE.txt`).

When the numbers the archer wrote agree too little with the arrows read (a blurred or too small
photo), or when the ends' totals mostly read blank (a grid set a line off), the reading is shown as
uncertain and every value is to be checked: it is never shown as sure; nor are the arrows of an end
where the checks have the archer add wrong. Before that, two ways of writing that archers use are
tried: the end's total in a line's sum cell, and a running total written across the end's total cell
and its own ("1 | 12" for 112), as one person writes a whole sheet. **With a webcam** (2 Mpx, 1920 × 1080), lay the scorecard **sideways**, so that its length
runs along the width of the picture and it fills it, in good light: held upright, it only gets the
picture's 1080 pixels of height and its cells come out too small. The barcode needs a phone photo;
with a webcam, the printed target and name are what identify the archer.

A third small network reads the printed header (target, name, category), trained on text drawn in
the Helvetica the core prints with (and Arial, which printers may use in its place).

## Database

The module **creates no table**. It reads the open competition: entries, qualification places and
arrows, distances, target faces. It writes only when the operator asks, or the automatic import
they ticked, and only into the core's qualification table (`Qualifications`), for the archer and
distance of the card read:
- **Import the data**: while ianseo does not hold every arrow of that distance (none, or some
  missing) and the card is not confirmed. The arrows entered already must be the reading's, but
  those the operator corrected; the reading completes the others. The module writes the distance's
  arrows and totals, then the archer's totals. The totals are calculated again by the server from
  the arrows; a signed total left blank and set to 0 by the operator counts 0.
- **Validate the data**: once every arrow is entered, so by a later reading for a card imported. It
  is confirmed as the core's barcode check does (the distance's bit in `QuConfirm`), only when the
  reading is what is entered. The cells the operator corrected are written first.

Every write goes through the open competition (a join on `Entries`). The ranks are then calculated
again by the core's classes, as after an ISK import: category, teams, events and their shoot-offs.

One setting, for the whole installation, in the core's `Parameters` table: the row `OcrDevTools`
(value `1`) when the administrator has shown the development tools; removed when they hide them.

## Installation, update, uninstall

See the [main README](../README.md). In short: copy the `OCR/` and `_shared/` folders into
`Modules/Custom/` (or run `install.sh` / `install.ps1`). Updates and uninstall from ianseo: menu
**Modules → Scorecard reader → Module update**, with a competition open.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `Qualifications` | `lib/score.php:143` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/score.php:149` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/score.php:160` | review scope by hand |

### Tables owned by this module

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
