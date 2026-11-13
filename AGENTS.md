# AGENTS.md

Guidance for coding agents working on this repository. Humans: see [CONTRIBUTING.md](CONTRIBUTING.md).

## What this repository is

Custom modules for [ianseo](https://www.ianseo.net), the archery competition software (PHP,
MySQL/MariaDB, jQuery). The repository root is `Modules/Custom/` of an ianseo
installation; each top-level folder holding a `module.json` is one module (`AUTH`, `GUIDE`,
`TNM`, …), and `_shared/` is the update library they all use.

This repository is also the **update channel**: anything that reaches `main` is offered at once
to every installation. Contributions arrive as pull requests and are re-integrated by the
maintainer, never merged as they are.

## Before you change anything

- Read the module's `README_<MODULE>.md`: features, tables, access rights.
- Find the existing helper before writing one. ianseo and the modules already have functions for
  SQL, sessions, ACL, PDF, rankings, money, dates and translations.
- Keep the change to the subject of the issue. No drive-by refactoring, no new feature that was not
  asked for.

## Hard rules

Breaking one of these causes a real failure, usually on a server you cannot see.

1. **Never edit a file outside `Modules/Custom/`.** An ianseo update overwrites the rest.
2. **A module never `require`s another module.** Read another module's table only if it exists.
3. **An SQL error kills the page**: `safe_error()` sends a 404 and calls `exit`, and no `try/catch`
   catches it. Since `menu.php` is included on every ianseo page, an error there takes down the
   whole site. In `menu.php`: read only (no `INSERT`, `UPDATE`, `CREATE`, no file writes), and
   guard every read of a module table with `safe_r_sql($sql, false, true)`.
4. **Schema changes go in the module's schema function** (`lib/schema.php`, called from the
   module's own pages), never in a manual SQL script. Put `CREATE TABLE IF NOT EXISTS` before any
   `ALTER` of the same table. Raise the schema version constant. A data migration must run only
   when its column has just been added, because the schema function runs again in every new session.
5. **Exact case for core table names**: `Tournament`, `Entries`, `Qualifications`, `Events`.
   Lowercase works on Windows and fails on Linux.
6. **`Qualifications` has no competition column.** Every `UPDATE` on it joins `Entries` and filters
   `EnTournament`.
7. **No user-facing text in code.** Add a key to every language file of the module (`languages/`),
   keeping the same keys in every language.
8. **Paper output uses the core PDF classes** (`Common/pdf/IanseoPdf.php` and its subclasses).
   Never `window.print()`.
9. **Do not touch `version.json` or `CHANGELOG.md`**, except to add a new distributed file to
   `files[]`. The maintainer sets the version when releasing.

## Conventions

- Code, identifiers and comments in **English**. Comments explain *why*, not *what*.
- SQL: `safe_r_sql()` / `safe_w_sql()` / `safe_fetch()`, and every value through `StrSafe_DB()` or
  `intval()`. No table aliases (ianseo column names are unique thanks to their prefixes), except
  for a table joined twice. `INNER JOIN Qualifications ON QuId = EnId` (one-to-one).
- New tables: the module's prefix, PascalCase columns with a 2–3 letter prefix,
  `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; add `COLLATE utf8mb4_unicode_ci` on the
  module side of a join on a core text column. A column holding `ToId` or `EnId` is `INT UNSIGNED`.
- Targets: `QuTarget` + `QuLetter`, not `QuTargetNo`.
- Rankings: `Obj_RankFactory::create()` and the `Rank` classes, never a copied ranking query.
- AJAX: `JsonOut($array)` with `error` (0 = success) and `msg`. Fetch lists in one call.
- Strings typed by people: `mb_substr`, `mb_strlen`… Byte functions only on ASCII data, with a
  comment containing the word "bytes".
- Pages: continuous PHP with `echo`, not markup interleaved with `<?php ?>` on every line.
- Access: `checkFullACL()` at the top of a page, `hasFullACL()` inline.
- Files: UTF-8 **without BOM**, which breaks JSON responses. Windows PowerShell 5.1
  `Set-Content -Encoding UTF8` writes one.

## Checking your work

- `php -l` on every PHP file you touched.
- **Fresh install**: run the module's schema function on an empty database, holding only the core
  tables it references. Check that every table and column is created, and that a second run changes
  nothing. A development database already has the tables and hides ordering bugs.
- Open every page you changed in each language the module offers. Look for missing keys (shown as
  `[[Key]@…]`), PHP warnings, and a broken layout at phone width.
- Database changes: say in the pull request which tables and columns are created or altered.

## Pull request

One subject per pull request, linked to its issue (`Closes #N`). Describe what changes, how, the
database changes and what you tested. Use conventional commits in English: `fix(MODULE): …`,
`feat(MODULE): …`.
