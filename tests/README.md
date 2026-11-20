# Test bench

Automated checks of the modules, run by GitHub on every pull request and every push, and
once a week. They can also be run on any machine with Docker. Nothing in this folder is
distributed: it is in no module's `files[]`.

## What is checked

The bench prepares ianseo **the way a new installation gets it**:

1. the official package published on ianseo.net, checked against its SHA-256;
2. installed: `Install/install.sql`, then the core database migrations;
3. brought to the latest release by the core's own update system (the "Update ianseo"
   page). The release reached is printed at the top of every run: when a check turns red
   with no change in this repository, compare it with the last green run.

**Compliance**, for every module of the repository: `bin/compliance.php` checks the rules
the ianseo team asks of contributed modules (exact case of core table names, database
writes, `menu.php` safety, migration order, comments in English, untranslated text, byte
string functions, BOM, `JsonOut()`, no browser printing, file name case…). Modules written
before a rule existed carry known debt, counted in `compliance-baseline.json` per module,
check and file: only errors beyond it fail. When you pay some debt down, rewrite the
baseline in the same commit (`php tests/bin/compliance.php <modules> --write-baseline=tests/compliance-baseline.json`).

Then, for each module described in `modules/<MODULE>.json`, on every database server of
the list (MariaDB 10.11 and 11.4, MySQL 8.0 and 8.4), each with its own default settings:

- **Fresh install** — on a database built from `install.sql`, never on a copy of a
  development database, the schema function of the module runs to the end, creates every
  table listed in `tables` of its `version.json` (spelled exactly: table names are
  case-sensitive on Linux), and a second run, which is a second session, changes nothing.
- **Scripts** — the module's own test scripts; exit code 0 means passed.
- **Pages** — every page listed for the module, in every language it offers, served by a
  real web server, on a database holding the tables of every module and one made-up
  competition created through ianseo's own "new competition" form (French rules, 18 m
  indoor, made-up archers). `pages` are opened without a competition, `tour_pages` after
  opening the made-up one, in a session that keeps its cookies like a browser. A page
  fails when it does not answer 200, sends the visitor elsewhere (redirects are not
  followed: a page bouncing to the home page used to look fine), shows a translation key
  instead of its text (`[[Key]@…]`), or raises a PHP warning, notice or fatal error in a
  file of a module. Warnings of the core are counted, not failed.
- **Browser** — `dom/<MODULE>.js`, for pages that build their content in JavaScript: the
  real page is loaded with its script in jsdom, then checked (no `[object Object]`, no
  raw translation key, the content is really built).
- **PHPUnit** — `phpunit/<MODULE>/*Test.php`: the module's libraries called the way its
  pages call them, on a database built from `install.sql`, the core migrations and the
  schemas of every module, put back in its reference state before each test. A fresh
  install only creates the tables; these tests run the queries (issue 13 was such a query).

Everything runs on PHP 8.3 and on PHP 8.0, the oldest version the ianseo installer accepts.

## Running it

```
tests/run.sh                  # every module, MariaDB 10.11, PHP 8.3
tests/run.sh --db mysql-8.0   # mariadb-10.11, mariadb-11.4, mysql-8.0, mysql-8.4
tests/run.sh --php 8.0        # 8.3, 8.0
tests/run.sh AUTH TIRAGE      # some modules only
tests/run.sh --force-core     # download and update the ianseo core again
```

The core is downloaded and updated at most once a day (`tests/.cache/`, ignored by git).
The repository is mounted read-only, no port is opened, and the database server keeps its
data in memory for the duration of the run.

## Adding a module

Create `modules/<MODULE>.json`:

```json
{
    "pages": ["/Modules/Custom/MODULE/admin/update.php"],
    "tour_pages": ["/Modules/Custom/MODULE/index.php"],
    "languages": ["en", "fr"],
    "schema": { "require": ["lib/schema.php"], "call": ["mod_schema"] },
    "scripts": ["MODULE/tests/run.php"]
}
```

- `pages` / `tour_pages`: pages opened without / inside the made-up competition. Only pages
  that display something on a plain visit: not a login page, not an action URL;

- `schema.require`: files of the module to load, relative to its folder;
- `schema.call`: the schema function(s), in the order the module's pages call them;
- `schema.legacy_tables`: patterns of old table names kept in `tables` so that
  uninstalling an old installation drops them too (a new installation never creates them);
- `scripts`: test scripts, relative to `Modules/Custom/`. They receive no database: a
  script that needs one builds it from the bench (see `lib/bench.php`).

Test data is always made up: no real name or licence number in this repository.

## Adding PHPUnit tests

Put them in `phpunit/<MODULE>/`, one `*Test.php` file per subject, and add the module's test
suite to `phpunit/phpunit.xml`. PHPUnit is 9.6, the version that runs on PHP 8.0 as well:
data providers are declared with `@dataProvider` in the docblock, not with attributes.

- An integration test extends `IanseoTestCase` (database reset before each test), usually
  through a base class of the module that loads its libraries (`phpunit/AUTH/AuthTestCase.php`).
- `TestDb` reads and writes the test database beside ianseo's own connections;
  `Fixtures` creates made-up competitions, clubs and archers, with the queries of the core
  screens when the test is about what ianseo writes.
- ianseo stops the process on an SQL error (`safe_error()`): the run then fails with the
  error printed, and the tests after it do not run.

## Files

| File | Role |
|---|---|
| `run.sh` | the single command |
| `docker/Dockerfile`, `docker/compose.yml` | PHP environment and throwaway database server, images pinned by digest |
| `bin/core.php` | official package + installation + core update, cached for the day |
| `bin/check.php` | compliance, fresh install and scripts |
| `bin/compliance.php`, `compliance-baseline.json` | the static rules, and the known debt of older modules |
| `bin/pages.php` | web server, made-up competition, pages × languages, browser checks |
| `bin/phpunit.php`, `phpunit/` | PHPUnit tests of the modules (`composer.lock`: PHPUnit, installed in the image) |
| `dom/` | browser-side checks (jsdom, installed from `package-lock.json` in the image) |
| `lib/` | shared helpers and the child processes (ianseo stops the whole process on an SQL error, so every step that loads ianseo runs in a process of its own) |
| `modules/` | one description per module |
| `../.github/workflows/tests.yml` | the runs on GitHub |
