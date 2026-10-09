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
- 🗺️ **Map** of the competitions and **directions** to the venue (Google Maps, Waze, Apple Maps);
  with the SYNCHRO_FFTA module, the exact venue read on the FFTA extranet.
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
  assigned when the organiser allowed it — for all competitions, or for one from its page.
- 💶 **Account on each competition**, on its page: due, paid, means of payment (an online
  payment address given by the organiser is a link), receipt.
- 🖨️ **Individual scorecard**: the official sheet produced by ianseo (PDF), for that archer
  only, filled with their results — when the organiser turns the option on; in the documents
  of the competition.
- 🧾 **Receipt** per archer, or grouped for a whole club.

### For the organiser

Everything is in the **Modules › Online registration** menu, with the competition open.

- ⚙️ **Open / configure the registrations**: opening period, restriction to the archers of a
  department or a region with a **later opening to everyone**, fee, and what the archers are
  allowed to see.
- 🗓️ **Opening session by session**: open, closed, or open by itself once the earlier sessions
  are full, with dates of its own when needed. Option **one registration per archer**, whatever
  the session.
- 📄 **Invitation** that meets the rules, filled from the competition: competition format (with
  or without matches), events, target faces, the **full ianseo programme** and the **competition
  officials**; on screen and as PDF.
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

Tables created automatically, all prefixed `BK_`: `BookingArchers` (licensee accounts),
`BookingSessions`, `BookingLog` (log and rate limiting), `BookingCompetitions` (opening of the
registrations), `BookingRegistrations` (traceability), `BookingSessionRules` (opening of each session), `BookingClubManagers`.

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
