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

Then, for each module described in `modules/<MODULE>.json`, on every database server of
the list (MariaDB 10.11 and 11.4, MySQL 8.0 and 8.4), each with its own default settings:

- **Fresh install** — on a database built from `install.sql`, never on a copy of a
  development database, the schema function of the module runs to the end, creates every
  table listed in `tables` of its `version.json` (spelled exactly: table names are
  case-sensitive on Linux), and a second run, which is a second session, changes nothing.
- **Scripts** — the module's own test scripts; exit code 0 means passed.

## Running it

```
tests/run.sh                  # every module, MariaDB 10.11
tests/run.sh --db mysql-8.0   # mariadb-10.11, mariadb-11.4, mysql-8.0, mysql-8.4
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
    "schema": { "require": ["lib/schema.php"], "call": ["mod_schema"] },
    "scripts": ["MODULE/tests/run.php"]
}
```

- `schema.require`: files of the module to load, relative to its folder;
- `schema.call`: the schema function(s), in the order the module's pages call them;
- `schema.legacy_tables`: patterns of old table names kept in `tables` so that
  uninstalling an old installation drops them too (a new installation never creates them);
- `scripts`: test scripts, relative to `Modules/Custom/`. They receive no database: a
  script that needs one builds it from the bench (see `lib/bench.php`).

Test data is always made up: no real name or licence number in this repository.

## Files

| File | Role |
|---|---|
| `run.sh` | the single command |
| `docker/Dockerfile`, `docker/compose.yml` | PHP environment and throwaway database server, images pinned by digest |
| `bin/core.php` | official package + installation + core update, cached for the day |
| `bin/check.php` | the checks of the modules |
| `lib/` | shared helpers and the child processes (ianseo stops the whole process on an SQL error, so every step that loads ianseo runs in a process of its own) |
| `modules/` | one description per module |
| `../.github/workflows/tests.yml` | the runs on GitHub |
