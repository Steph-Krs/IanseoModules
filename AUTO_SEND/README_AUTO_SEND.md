# Scheduled upload

Module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

Opens and closes the scoring and **uploads the results to ianseo.net on its own**, at set times,
with no computer left on the upload page. Made for competitions whose scores are entered online
over several days or weeks (ISK-NG on phones), where the upload page used to stay open on a
computer for the whole period — and where an upload that silently stopped could go unnoticed for
days.

## Features

- 🗓️ **A period**: start and end (date and time), with the **clock changes handled** — a period
  that starts in winter time and ends in summer time keeps the times typed in. A time that does
  not exist (02:00–03:00 on the spring change night) is refused.
- 📱 **Scoring opened and closed** (the ISK-NG sessions of the core page *Manage locked
  sessions*): opened at the start, closed at the end, once each — a session locked by hand during
  the period stays locked. At the end, the scoring is closed **before** a last upload, so that it
  carries every score.
- ⏱️ **Upload every N minutes** (5 or more), set on the page.
- 📋 **The same lists as the core upload page**: qualification rankings, category rankings,
  eliminations, round robins, brackets, final rankings, medals. An item ianseo does not offer yet
  (brackets before the shoot-offs, medals before they are awarded) is sent from the moment it does
  — never an empty bracket.
- 🧪 **Simulation**: everything runs for real except the upload, which is imitated. The results are
  built exactly as for a real upload, which gives the real cost on the server.
- 🚨 **Failures that cannot go unnoticed**: status and history on the page, retries after 1, 2, 4…
  minutes, and an optional **outside monitoring** (healthchecks.io) that warns you by email or text
  message when the uploads stop — including when the server itself is down.
- ▶️ **Upload now** button, to check the whole chain at any time.

## How it works

PHP only runs when a page is requested: nothing inside ianseo can wake up on its own at 08:00. The
server's scheduler (cron) therefore runs the module's task **every minute**. Each run reads the
settings, does what is due — open the scoring, upload, close the scoring — and stops. Almost always
nothing is due, and the run costs two small database queries.

The upload itself is **ianseo's own code**, unchanged: `Tournament/UploadResults-upload.php`
already accepts a call without a browser, with the ianseo.net codes saved with the competition. The
module prepares the same form fields the core page would post, and runs that code in a separate
process with a time limit (10 minutes).

Each run starts from scratch: nothing is kept between two runs except what is in the database. A
failure costs that run only; the next one starts over.

### When something goes wrong

| Situation | What happens |
|---|---|
| Internet connection lost on the server | Uploads fail within a second (the codes are checked before the results are built) and are retried after 1, 2, 4, 8… minutes, then at the normal pace. **Nothing is lost**: each upload carries the complete results, so the first one after the connection returns publishes everything. Opening and closing the scoring do not need the Internet. If monitoring is set up, it warns you once the grace period is over. |
| ianseo.net unreachable or refusing | Same retries; the reason is recorded in the history. |
| Database restarting (night updates) | That run fails or is skipped; the next minute resumes. |
| Server down at the start or end time | Caught up on the first run after it is back: opening, closing, and the last upload (retried for 48 hours after the end). |
| Upload stuck | Stopped after 10 minutes, recorded as a failure, next attempt scheduled. |
| An upload longer than a minute | The following runs step aside (database lock); never two uploads at once. |
| The scheduled task is not running | The page says so in red. Only outside monitoring can tell you when you are not looking at the page. |

### Cost on the server

Measured on a real challenge (9,027 archers, 17 events, individual qualification rankings and
category ranking): about **2 seconds** of computation to build the results, **774 KB** sent, 60 MB
of memory, for a total of about 4.5 seconds per upload including the process start and the codes
check (development machine). This is the work the core upload page already made the server do at
each automatic upload; the module adds none, and saves the list reload the page made after each
upload. Every upload's duration and size are shown in the history.

## Installation

1. Copy the `AUTO_SEND/` and `_shared/` folders into `Modules/Custom/` (or run `install.sh` /
   `install.ps1`, see the [general README](../README.md)).
2. Open a competition, then **Modules → Scheduled upload → Settings and status**: the tables are
   created.
3. **Install the scheduled task** (once per server, see below). The page shows the exact line for
   the installation and turns green when the task runs.
4. Save the ianseo.net codes of the competition through the core menu, **ticking "remember"**: the
   task uploads with no one signed in. The module's page says when they are missing.

### The scheduled task on Linux (Debian, Ubuntu)

As administrator of the server. Replace `/opt/ianseo` with the folder of your ianseo installation
(the module's page shows the line with the right path), and `www-data` with the user of the web
server if it differs:

```sh
echo '* * * * * www-data nice -n 10 /usr/bin/php /opt/ianseo/Modules/Custom/AUTO_SEND/cron.php 2>&1 | logger -t ianseo-autosend' | sudo tee /etc/cron.d/ianseo-autosend
```

- `nice -n 10` gives priority to the web server: the scores entered on the phones come first.
- The task prints nothing when all is well; an error goes to the system log.
- A file of `/etc/cron.d/` must have no dot in its name, and cron reads it without a restart.

Check that it runs:

```sh
# The page of the module shows "The scheduled task runs" within a minute. From the command line:
sudo grep ianseo-autosend /var/log/syslog | tail        # errors only (silent when all is well)
sudo grep 'AUTO_SEND/cron.php' /var/log/syslog | tail -3 # one line per run, every minute
sudo -u www-data php /opt/ianseo/Modules/Custom/AUTO_SEND/cron.php   # one run by hand: prints nothing if fine
php -m | grep -i mysqli                                  # the command-line PHP needs mysqli, like the site
```

Pause everything: untick **Schedule active** on the page (recommended), or comment the line:

```sh
sudo sed -i 's/^\*/#*/' /etc/cron.d/ianseo-autosend      # pause
sudo sed -i 's/^#\*/*/' /etc/cron.d/ianseo-autosend      # resume
sudo rm /etc/cron.d/ianseo-autosend                      # remove
```

### The scheduled task on Windows (XAMPP)

In a command prompt run as administrator (adapt the two paths; the module's page shows them):

```bat
schtasks /Create /TN "ianseo AUTO_SEND" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"C:\ianseo\php\php.exe\" \"C:\ianseo\htdocs\Modules\Custom\AUTO_SEND\cron.php\""
schtasks /Query /TN "ianseo AUTO_SEND"
schtasks /Delete /TN "ianseo AUTO_SEND" /F
```

## Outside monitoring with healthchecks.io (optional)

**Why outside**: a warning shown on a screen, or even an email sent by the server, cannot report
that the server itself is down, or that its network is cut. A "dead man's switch" works the other
way round: the server **calls** the service after each upload; if the calls **stop**, the service
raises the alarm. Nothing is installed on the server — the module only calls an address.

1. Create a free account on [healthchecks.io](https://healthchecks.io) (an email address is enough).
2. **Add Check**. Name it (e.g. "ianseo — challenge"). In **Schedule**: *Period* = the upload
   interval (10 minutes), *Grace time* = 20 minutes — enough to absorb a night-time restart or a
   retry without a false alarm.
3. Copy its **ping URL** (`https://hc-ping.com/…`) into **Monitoring address** on the module's
   page, and save.
4. In **Integrations**, the account's email is active by default; text message, Telegram, Signal
   or push notifications (ntfy) can be added.
5. The check stays grey ("new") until the first call: nothing is sent in simulation. Once the real
   period has started, it turns green.
6. **After the period**, untick *Schedule active* on the page **and** pause the check in
   healthchecks.io — otherwise, the calls having stopped, it warns you.

What the service receives: an empty call after each successful upload (and as a sign of life
between uploads), and a call to `…/fail` with the outcome code and the error message after a
failure (for instance *ianseo.net unreachable*). No personal data.

## Access

- Settings and status: a competition open, and the right to send it to ianseo.net (the one the core
  upload page asks for). Opening and closing the scoring additionally needs the right of the core's
  ISK-NG session page; without it, those settings are read-only.
- The scheduled task runs on the server itself, with no account.
- Update and uninstall: administrator.

## Database

Two tables of the module's own, created when its page is opened (never by the scheduled task):

| Table | Content |
|---|---|
| `AutoSendPlans` | one row per competition: the period, the interval, the scoring sessions, the lists to upload, the monitoring address, and what the task has done (opening, closing, last upload, failures) |
| `AutoSendRuns` | the history: one row per upload, opening or closing, with its outcome, duration and size; kept 60 days |

ianseo data changed **through the core's own functions**:

- the list of locked ISK-NG sessions of the competition (`ModulesParameters`, module `ISK-NG`,
  parameter `LockedSessions`), through `getModuleParameter()` / `setModuleParameter()`, exactly as
  the core page *Manage locked sessions* does;
- the upload is the core's own code, run unchanged: it updates what it updates when the upload page
  is used (the competition's online code `Tournament.ToOnlineId`, and the menu flags that ianseo
  refreshes whenever a competition is opened).

## Update, uninstall

From ianseo: **Modules → Scheduled upload → Update the module**. Uninstalling removes the files and,
if asked, the two tables; **remove the scheduled task from the server as well**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `AutoSendPlans` | `api/state.php:25` | — |
| `INSERT IGNORE INTO` | `AutoSendPlans` | `lib/plan.php:134` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:47` | — |
| `DELETE FROM` | `AutoSendRuns` | `lib/runner.php:62` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:103` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:108` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:127` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:138` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:148` | — |
| `INSERT INTO` | `AutoSendRuns` | `lib/runner.php:314` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:352` | — |
| `UPDATE` | `AutoSendPlans` | `lib/settings.php:103` | — |

<!-- END DATABASE WRITES -->
