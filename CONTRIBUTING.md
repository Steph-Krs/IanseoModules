# Contributing to the modules

*[Version française](CONTRIBUTING_FR.md)*

Thank you for wanting to improve these modules! Bug reports, ideas and fixes are welcome, in
English or in French (*les issues et pull requests en français sont aussi les bienvenues*).

## Reporting a problem or suggesting an idea

Open an **issue** before writing code. One subject per issue.

For a problem, three parts are enough:

- **What happens**: what you see, on which screen, in which module and which version
  (the module's "Update" page);
- **What should happen**: the expected behaviour;
- **How to reproduce**: the steps, on a test competition if possible.

A security flaw is **not** reported in a public issue: use **Security › Report a vulnerability**
on the repository page.

## Proposing a change (pull request)

- **One pull request per subject**, linked to its issue (`Closes #N` in the description).
- **No need to change `version.json` or `CHANGELOG.md`.** The version number is worked out at
  publication time, grouping what is released together; two pull requests that each number a
  version conflict with each other.
- In the description: what the pull request fixes or adds, how, **the database changes**
  (tables, columns) and what you tested.
- Commit messages in the conventional format, in English: `fix(MODULE): …`, `feat(MODULE): …`.

### How a contribution is integrated

The repository is also the **update channel**: whatever reaches the `main` branch is offered at
once to every installation. A pull request is therefore **not merged directly**. It is taken into
a commit of the module, run through the test bench (fresh installation, every language, checks
against the ianseo rules), adjusted if needed, then released with its version.

Your work stays credited: you are **co-author of the commit** (`Co-authored-by`, which shows it in
your GitHub contributions) and **named in the module's `CHANGELOG.md`**. The pull request is then
closed with a message pointing to the commit: it shows as "Closed", not "Merged". That is expected.

## Code rules

These rules come from exchanges with the ianseo team and from real failures. A pull request that
does not follow them can still be taken, but it will need more work before release.

### Where to write

- A module is a folder of `Modules/Custom/` that holds a `module.json`. **Never change a file of
  the ianseo core**: an official update resets the whole of `htdocs/`, except `Modules/Custom/`.
- A module works **on its own**: it never `require`s another module and never assumes it is
  there. Modules exchange through conventions (session keys, tables read if they exist), with a
  fallback when the other module is missing.
- A new distributed file is added to `files[]` in `version.json`, otherwise it never reaches the
  users.
- No new external dependency without discussing it first in an issue.

### Languages

- **Code, variable names and comments in English**: the ianseo core is maintained by an
  international team.
- **No user-facing text in the code**: everything goes through the module's language files, in
  every language it offers.
- On text typed by a person (name, club, description), use the `mb_*` functions (`mb_substr`,
  `mb_strlen`…): `substr()` can cut an accented character in two.

### Database

- Queries through the core functions: `safe_r_sql()`, `safe_w_sql()`, `safe_fetch()`, and
  `StrSafe_DB()` for every value.
- ⚠ **An SQL error stops the page** (`safe_error()` calls `exit`, which no `try/catch` catches).
  In `menu.php`, included on every ianseo page, it takes the whole site down. `menu.php` **only
  reads**, and every read of a module table there is guarded (3rd argument `$force` of
  `safe_r_sql()`).
- Module tables are created **when a page of the module opens**, by its schema function
  (`CREATE TABLE IF NOT EXISTS`, and only then the `ALTER`s), never from `menu.php`. There is no
  SQL script to run by hand. Test on an **empty database**: a development database already has
  the tables and hides ordering mistakes.
- New tables: the module's prefix, `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, and
  `COLLATE utf8mb4_unicode_ci` on the module side of a join on a core text column.
- A column that copies a core identifier takes **its type** (`ToId`, `EnId`: `INT UNSIGNED`).
- Core table names **with their exact case** (`Tournament`, `Entries`, `Qualifications`): a Linux
  server tells upper and lower case apart, a Windows machine does not.
- No table aliases: ianseo column names are unique (prefixes `En`, `Qu`, `To`…). Only exception:
  the same table joined twice.
- `Qualifications` is one-to-one with `Entries` (`INNER JOIN`) and **has no competition column**:
  every `UPDATE` goes through `Entries`, otherwise it hits every competition.
- Targets: read `QuTarget` and `QuLetter`. `QuTargetNo` is no longer updated by the ianseo screens.

### Pages, PDF and responses

- **Paper documents: the core PDF classes** (`Common/pdf/IanseoPdf.php` and its subclasses), never
  an HTML page with `window.print()`.
- **Rankings: the core `Rank` classes** (`Obj_RankFactory::create()`), without copying their
  queries.
- AJAX endpoints: answer with `JsonOut()`, with `error` (0 = success) and `msg`. Load a list in a
  single call, not one call per row.
- Build a page in continuous PHP (`echo`), without switching between markup and `<?php … ?>` on
  every line.
- A button carries translated text, or an icon supplied by ianseo (`Common/Images/`); a symbol on
  its own has at least a translated `title`.
- Files in UTF-8 **without BOM**: the three BOM bytes, sent before a JSON response, make it
  unreadable.
- Access: `checkFullACL()` at the top of a page, `hasFullACL()` for a check inside the page.

## Licence

By contributing, you agree that your contribution is published under the licence of the module
concerned: its `LICENCE.md` file if it has one, otherwise the [`LICENSE`](LICENSE) file at the
root of the repository.
