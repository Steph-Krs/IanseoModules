# Interactive Guide

A module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

It puts a **persistent side panel** on every ianseo page and walks users through the software
step by step, highlighting the element to act on and waiting until the action has actually been
performed. It was written for clubs running their first competitions on ianseo, and for anyone
meeting a part of the software they have never used before.

Courses are content files, not code: an organiser or a federation can write their own without
touching the module.

## Features

- 📖 **Multi-step courses** with previous/next navigation and a progress bar.
- 🎯 **On-page highlighting**: an overlay and an animated arrow point at the element to use, and
  a tooltip says what to do with it. The highlight survives ianseo re-rendering part of the page.
- ⚡ **Reactive steps**: a step can wait for a DOM event (a click, an entry) or for a *condition*
  evaluated on the server — a competition is open, an event is configured, at least eight archers
  share a category. A step only advances once the thing has really been done.
- 🧩 **Quizzes and challenges**: besides the walkthrough, a course can carry a quiz and a
  challenge to be completed unaided in the real software. Passing them earns a bronze, silver or
  gold target.
- 🧭 **Checklists and troubleshooting**: two further content types — a checklist filtered by the
  answers to a few questions, and a decision tree that leads to a solution.
- 💾 **Progress tracking**, stored on the server and separated per user, so several organisers
  can share one installation without sharing their progress.
- 💡 **Contextual help**: on any ianseo page, the module can offer the courses that talk about it.
- ✏️ **Built-in editor** (administrator): build a course visually, record the triggers by simply
  clicking through the software, import and export courses, preview as you write.

## Conditions

A condition asks the ianseo database whether something has been accomplished. They are what makes
the module more than a slideshow: a step can require that targets have actually been assigned, and
a challenge can be graded on the state of the competition rather than on a multiple-choice answer.

Conditions are defined in `conditions.json` and built from the administration screens, without
writing SQL. All of them read; none of them write.

## Internationalisation

**The interface** follows the core's own language mechanism: `languages/<code>.php` files defining
a `$lang` array, English being the fallback for any missing key. English, French, Italian, German
and Spanish are shipped. The strings the course player needs in the browser are published to it by
`menu.php`; every key named `Js…` travels automatically, so adding one is a matter of naming it.

**Course content** is translated inside the content file itself, so a course stays one document
with one version number. Only the *text* of a field becomes a language map:

```json
"title": "My first competition",
"title": { "en": "My first competition", "fr": "Ma première compétition" }
```

Both forms are valid, and a plain string is assumed to be in the language named by the file's
`lang` field (English if absent). The structure around the text — steps, triggers, selectors,
pages — is **never** duplicated: duplicating it would let the languages drift apart, and a course
whose French version points at a different element than its English one is worse than a course
with no French at all.

A reader gets the course in the language of their interface. Failing that, English; failing that,
the language the course was written in — so a course translated only into Italian is still served,
never shown empty. A regional language counts as its parent along the way: a reader set to
Canadian French gets the French text before English is considered.

The editor has a language selector: pick a language and the fields show that language, leaving
the others untouched. A field with no translation yet shows **empty** rather than the original
text, so what is missing is visible at a glance.

Every screen is translated into the five languages: the catalogue, the panel, the course player,
the content list, both editors, the condition builder, the update screen and the authoring help.

Translations are split into **sections**, exactly as the core splits its own into `Common.php`,
`Tournament.php`, `Errors.php` and so on, with `get_text($key, $module)` loading only the one it
needs. Here the module's own strings are `languages/<code>.php`, and the authoring documentation
is the `help` section, `languages/help/<code>.php` — the same `$lang` array format, read with
`guide_text($key, $a, 'help')`. That split earns its keep: the main strings are loaded on **every**
ianseo page, since the module injects its panel everywhere, whereas the documentation is several
kilobytes wanted on one screen.

A language file holds **nothing but strings**, again as the core's do. All the markup of the
authoring documentation — headings, tables, lists — lives in `admin/help.php`, which calls
`guide_text()` for every sentence; only inline emphasis (`<b>`, `<i>`, `<code>`) stays inside a
string, because it is part of the sentence and moves with it when translated. A translator
therefore never edits markup, and a change to the layout is made once instead of five times.

The generic wording of the update and uninstall screens comes from `_shared/languages/`, so it is
translated once for every module rather than once per module.

Every course shipped with the module is written in the five languages, quiz and challenge included.
Wherever a course names a menu entry, the wording is taken from the core's own language files
rather than translated freely: a course that named something the reader cannot find would be worse
than one they cannot read. Condition labels carry their languages the same way.

## Database

Three tables, created the first time a page of the module is opened:

| Table | Holds |
|---|---|
| `GuideProgress` | Where each user has got to in each course, and which activities they passed |
| `GuideVisits` | Pages a user has opened, for the conditions that ask about it |
| `GuidePrefs` | Per-user preferences, such as whether contextual help is on |

Courses, checklists and troubleshooting trees are JSON files under `content/`, not database rows.

Earlier versions of the module named these tables `GUIDE_Progress`, `GUIDE_Visits` and
`GUIDE_Prefs`. They are renamed automatically, keeping their data, and a view under the old name
is left in place so anything still using it keeps working.

## Access

- Seeing the panel and the catalogue: any signed-in user.
- Administration (creating and editing content): administrator only. When an account module is
  installed, the server administrator view is required as well — an account module grants
  `AclRoot` to every signed-in organiser on pages outside a competition, so that test alone would
  let any organiser edit the courses.

## Installation, update, removal

See the [general README](../README.md). In short: copy the `GUIDE/` and `_shared/` folders into
`Modules/Custom/` (or run `install.sh` / `install.ps1`). Updates and removal are done from inside
ianseo: the module's menu → **Administration** → **Update**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `GuideProgress` | `admin/update.php:226` | — |
| `UPDATE` | `GuideProgress` | `guide-api.php:135` | — |
| `INSERT INTO` | `GuideProgress` | `guide-api.php:144` | — |
| `UPDATE` | `GuideProgress` | `guide-api.php:181` | — |
| `INSERT IGNORE INTO` | `GuideProgress` | `guide-api.php:351` | — |
| `UPDATE` | `GuideProgress` | `guide-api.php:356` | — |
| `INSERT INTO` | `GuidePrefs` | `lib/guide-lib.inc.php:413` | — |
| `DROP TABLE` | `GuideProgress` | `lib/guide-lib.inc.php:503` | — |
| `ALTER TABLE` | `GuideProgress` | `lib/guide-lib.inc.php:531` | — |
| `UPDATE IGNORE` | `GuideProgress` | `lib/guide-lib.inc.php:564` | — |
| `INSERT IGNORE INTO` | `GuideVisits` | `lib/guide-lib.inc.php:653` | — |

<!-- END DATABASE WRITES -->
