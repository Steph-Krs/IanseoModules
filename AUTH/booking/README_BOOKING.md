# Online registration

A module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

Opens the registrations to the archers themselves: each licensee signs in with the credentials
of their federation licensee space, looks at the calendar of the open competitions and registers
in a few clicks. Designed for ianseo servers reachable online.

## Features

### For the archer

- 👤 **Sign-in without a new password**: the credentials of the federation licensee space are
  passed on to the federation, which alone checks them. **No password is kept by this site.**
  Two-factor authentication supported. The account is created automatically at the first
  sign-in.
- 📅 **Calendar** of the competitions open for registration: filters (name, place, dates,
  type) and places left per session.
- 📝 **Registration in a few clicks**: name, club and date of birth taken from the licence
  file; bows, categories, target faces and sessions offered from the configuration of the
  competition.
- 🙋 **Wishes taken into account automatically**: position on the target and "on the same
  target as" (among the archers of their club already registered). The placement is computed
  again at each registration to meet as many requests as possible, without ever breaking the
  rules nor the field assignment constraints.
- ➕ **Several sessions possible**: with the same bow, only the first registration counts for
  the events, the next ones are extra shoots; a different bow opens its own event.
- 📋 **My registrations**: viewing, cancelling while the registrations are open, target
  assigned when the organiser allowed it.
- 🖨️ **Individual scorecard** to print, in the real format of the event (ends, arrows,
  distances and target face read from ianseo) — when the organiser turns the option on.
- 🧾 **Receipt** per archer, or grouped for a whole club.

### For the organiser

Everything is in the **Modules › Online registration** menu, with the competition open.

- ⚙️ **Open / configure the registrations**: opening period, restriction to the archers of a
  department or a region with a **later opening to everyone**, fee, and what the archers are
  allowed to see.
- 🏹 **Field assignment constraints**: graphical editor of what each target allows, session by
  session. Each target is a **vertical box** on a shared distance axis: the minimum, maximum and
  default distance are set by dragging the handles. The allowed target faces are dragged from
  the palette. Multiple selection by click-and-drag to handle a row at once, copy from one
  session to another, and a size slider to show 50 to 70 targets at a glance. A target without
  settings accepts everything. ("Field plan" means the visual target plan of the DragDropTarget
  module.)
- 🎯 **Target assignment**: automatic placement following the field assignment constraints,
  with clubs mixed, target plan, and **rule checks** — archers of the same club per target,
  different clubs per session, duplicates on a same session, archers not placed.
- 🏛️ **Club managers**: a designated licensee can register the archers of their club (or of
  their department / region). Works with an accounts module as well as without.

## Database

Tables created automatically, all prefixed `BK_`: `BK_Archers` (licensee accounts),
`BK_Sessions`, `BK_Log` (log and rate limiting), `BK_Competitions` (opening of the
registrations), `BK_Registrations` (traceability), `BK_ClubManagers`.

The registrations themselves are written into the **ianseo** tables (participants and targets),
exactly like a manual entry: they show normally in every screen and export of the software.

The field configuration is **not** asked again: the module reads the one already entered in
ianseo (sessions, number of targets, distances, target faces, shooting rhythm).

## Archers' sign-in

The archers use the **credentials of their federation licensee space** (the identifier may be a
licence number or a personal identifier). A forgotten password is recovered with the
federation.

The licence number is then read on the licensee space itself — never asked of the archer — so
that each account is tied to the right licensee.

Optional settings in `config.local.json` (not versioned):

```json
{ "sso": { "enabled": true, "base": "https://monespace.ffta.fr", "debug": false } }
```

## Access

- **Licensee space** (archers): `Modules/Custom/AUTH/booking/public/` — reachable without an
  organiser account. Give this link to your licensees; it is shown again on the page that opens
  the registrations.
- **Every organiser screen**: **Modules › Online registration** menu. Opening the registrations
  and the target assignment only appear when a competition is open; the club managers and the
  update are reserved to the administrator.

## Installation, update, uninstallation

See the [general README](../README.md). In short: copy the `BOOKING/` and `_shared/` folders into
`Modules/Custom/` (or `install.sh` / `install.ps1`). Updates and uninstallation from ianseo:
**Modules › Online registration › Update** menu.

French version: [README_BOOKING_FR.md](README_BOOKING_FR.md).
