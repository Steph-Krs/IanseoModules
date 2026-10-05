# Multi-account hosting

French version: [README_AUTH_FR.md](README_AUTH_FR.md).

A module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

It turns an ianseo installation hosted online into a **multi-organiser server with online
registration**: each structure has its own account and only sees / changes its own
competitions (sharing is possible to help with data entry), and **licensed archers register by
themselves** for the open competitions, from their own space.

The interface follows the language ianseo chose for the visitor. The texts live in `languages/`
(the core's format, English being the fallback for any missing key). Available languages: English,
French, Spanish, German and Italian.

> This README deliberately **does not detail** the inner workings nor the security mechanisms.

## ⚠️ Before deploying — security of the accounts used

There is no **official SSO** (OAuth / OpenID Connect) provided by the federation yet. Signing in
therefore works by **credential relay**: at each sign-in, the user's identifier and password
**pass through THIS server** to be checked with the online spaces (officers / licensees). The
password is **never stored nor logged**, but it goes through the server's memory for the time
of the request.

**Consequence, as long as a real SSO is not in place: the security of the users' accounts
depends directly on the security AND the reliability of this server (and of its operator).**
Users are told so on the sign-in page. This is why the deployment must follow the hardening
described in `SERVEUR.md`, and why a real OIDC is still to be asked of the provider of the
online spaces (the module is ready to switch the day it comes).

## Features

### Organiser side (multi-account)
- 👤 One account per organiser; each account only sees its own competitions
- 🤝 Controlled sharing of a competition (help with data entry, visibility for the parent structure)
- 🔑 Central sign-in, with local accounts as a fallback
- 🛠️ Account administration and activity log

### Competitor side (online registration — `booking/` sub-module)
- 🎯 Licensee space: calendar of the open competitions, registration in a few clicks
- 🧩 Automatic session/target allocation following the federation rules (including the sharing
  of target faces)
- ⏳ Waiting list when a session is full, from the usual registration form: as soon as a place
  is freed, the first matching archer is registered automatically and told in their space
- 👥 Registration of a club mate along with oneself; shop
- 💶 **Payments**: one account per participant (registered online or entered in ianseo) — total
  due from the competition's fees, already paid, left to pay; history of the payments,
  cancellations and refunds; settlement of a club in one go, of the chosen amount; receipts and
  list as PDF. The archer sees their account and prints their receipt at any time, and the home
  page reminds them of what they still owe for a finished competition. Also for a competition
  **closed** on the server (participants imported into ianseo): one box turns on fees, payments
  and shop without opening the online registration
- 🧾 Invitation, documents and scorecards of the competition available to the archers
- 🗳️ Satisfaction survey after the competition (less than 2 minutes, nothing mandatory); the
  organiser sees its anonymous results, as simple charts, compared with the other competitions

### Server administrator side
- 🌙 Automatic nightly maintenance: update of ianseo and of the modules, synchronisations
- 💾 Nightly backup of the database and files, plus a copy of the database every 6 hours without
  blocking anything, with an optional encrypted online copy (Google Drive, Dropbox, OneDrive,
  NAS…) — without a valid backup, ianseo is not updated
- ♻️ Guided restore, and checking of a copy without touching the site
- 🚨 Warning to the administrator when the night fails or no longer runs; optional sign of life
  to a monitoring service
- 🩺 **Server state** page: reports the settings that slow down or block an online ianseo server
  (MySQL 8, connections, memory, sessions, import size, oversized sessions…), with the fix to
  apply
- 🕶️ **Anonymise a licensee** across every competition of the server (erasure request):
  registrations to come deleted (the organiser is told of a refund to make when a payment was
  recorded); elsewhere, licence replaced by "ANON", surname, first name, date of birth and photo
  removed, sports results kept; online account deleted
- ⚙️ **Server configuration** page: settings that can be changed without the command line
  (passwords never shown; commands and paths reserved to the command line)

## Database

Internal tables created automatically: `Auth` prefix (organiser accounts) and `Booking` (licensee
accounts, registrations, shop, payments).

Up to version 1.1.17 they were named `AUT_*` and `BK_*`. A server updated from such a version
switches by itself, at the first page opened: each table is renamed in place (rows, indexes and
counters kept), and a view keeps the old name, so that the files deployed in
`Modules/Authentication` keep working until the next deployment. When the MySQL account of ianseo
lacks the `CREATE VIEW` right, the views are left out: deploy again right after the update.
`ianseo-restore` also restores a copy taken before the rename, and migrates it before reopening
the site.

## Access

- Account management and administration: **server administrator**.
- Each organiser: only their own competitions (and those shared with them).
- Each licensee: their own registration space (sign-in with their federation account).

## Installation, update, uninstallation

See the [general README](../README.md) for the common principle.

> **Sensitive module**: it carries the authentication of the server. Installing, updating and
> removing it must be done by the administrator.
> A removal done without care can make the site unreachable.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

| Statement | Table | Location | Notes |
|---|---|---|---|
| `DELETE FROM` | `TournamentInvolved` | `anonymise-lib.php:244` | — |
| `UPDATE` | `Entries` | `anonymise-lib.php:352` | review scope by hand |
| `DELETE FROM` | `Photos` | `anonymise-lib.php:357` | — |
| `DELETE FROM` | `ExtraData` | `anonymise-lib.php:359` | — |
| `UPDATE` | `ExtraData` | `anonymise-lib.php:361` | — |
| `UPDATE` | `TournamentInvolved` | `anonymise-lib.php:366` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/adopt.php:223` | review scope by hand |
| `UPDATE` | `IdCards` | `booking/lib/mandate.php:592` | review scope by hand |
| `INSERT INTO` | `Countries` | `booking/lib/registration.php:300` | — |
| `UPDATE` | `Countries` | `booking/lib/registration.php:304` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/registration.php:361` | review scope by hand |
| `INSERT INTO` | `Entries` | `booking/lib/registration.php:487` | — |
| `UPDATE` | `Entries` | `booking/lib/registration.php:497` | review scope by hand |
| `INSERT INTO` | `Qualifications` | `booking/lib/registration.php:502` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:295` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:431` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:522` | — |
| `DELETE FROM` | `LookUpEntries` | `cron/sync-licences.php:132` | review scope by hand |
| `INSERT INTO` | `LookUpEntries` | `cron/sync-licences.php:149` | — |
| `UPDATE` | `LookUpPaths` | `cron/sync-licences.php:153` | review scope by hand |
| `DELETE FROM` | `LookUpEntries` | `cron/sync-licences.php:181` | review scope by hand |
| `INSERT IGNORE INTO` | `LookUpEntries` | `cron/sync-licences.php:193` | — |
| `INSERT INTO` | `LookUpPaths` | `cron/sync-licences.php:229` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:239` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:251` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:261` | — |
| `INSERT INTO` | `Flags` | `logos-lib.php:267` | — |
| `INSERT INTO` | `Flags` | `logos-lib.php:313` | — |

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `INSERT INTO` | `AuthUsers` | `admin/index.php:102` | — |
| `UPDATE` | `AuthUsers` | `admin/index.php:125` | — |
| `UPDATE` | `AuthUsers` | `admin/index.php:142` | — |
| `UPDATE` | `AuthUsers` | `admin/index.php:153` | — |
| `DELETE FROM` | `AuthUsers` | `admin/index.php:177` | — |
| `UPDATE` | `BookingArchers` | `admin/index.php:193` | — |
| `DELETE FROM` | `BookingSessions` | `admin/index.php:194` | — |
| `DELETE FROM` | `BookingSessions` | `admin/index.php:198` | — |
| `UPDATE` | `BookingArchers` | `admin/index.php:202` | — |
| `UPDATE` | `BookingArchers` | `admin/index.php:208` | — |
| `DELETE FROM` | `BookingSessions` | `admin/index.php:209` | — |
| `DELETE FROM` | `BookingSessions` | `admin/index.php:213` | — |
| `DELETE FROM` | `BookingArchers` | `admin/index.php:214` | — |
| `DELETE FROM` | `BookingShopOrders` | `anonymise-lib.php:245` | — |
| `DELETE FROM` | `BookingPayments` | `anonymise-lib.php:246` | — |
| `UPDATE` | `BookingRegistrations` | `anonymise-lib.php:275` | — |
| `UPDATE` | `BookingRegistrations` | `anonymise-lib.php:276` | — |
| `UPDATE` | `BookingRegistrations` | `anonymise-lib.php:277` | — |
| `UPDATE` | `BookingLedger` | `anonymise-lib.php:281` | — |
| `UPDATE IGNORE` | `BookingPayments` | `anonymise-lib.php:283` | — |
| `DELETE FROM` | `BookingPayments` | `anonymise-lib.php:284` | — |
| `UPDATE IGNORE` | `BookingShopOrders` | `anonymise-lib.php:287` | — |
| `DELETE FROM` | `BookingShopOrders` | `anonymise-lib.php:288` | — |
| `UPDATE` | `BookingSurveys` | `anonymise-lib.php:292` | — |
| `DELETE FROM` | `BookingSurveyVoters` | `anonymise-lib.php:293` | — |
| `UPDATE` | `BookingLog` | `anonymise-lib.php:294` | — |
| `UPDATE` | `BookingReimportConflicts` | `anonymise-lib.php:296` | — |
| `DELETE FROM` | `BookingWaitlist` | `anonymise-lib.php:335` | — |
| `DELETE FROM` | `BookingSessions` | `anonymise-lib.php:374` | — |
| `DELETE FROM` | `BookingClubManagers` | `anonymise-lib.php:375` | — |
| `DELETE FROM` | `BookingArchers` | `anonymise-lib.php:376` | — |
| `UPDATE` | `BookingWaitlist` | `anonymise-lib.php:381` | — |
| `UPDATE` | `BookingWaitlist` | `anonymise-lib.php:382` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/competition.php:124` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/admin/competition.php:128` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/competition.php:204` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/mandate.php:40` | — |
| `INSERT INTO` | `BookingReimportConflicts` | `booking/lib/adopt.php:104` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:172` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:176` | — |
| `UPDATE` | `BookingReimportConflicts` | `booking/lib/adopt.php:187` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:242` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:276` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:297` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:315` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/adopt.php:406` | — |
| `UPDATE` | `BookingTargetCaps` | `booking/lib/adopt.php:407` | — |
| `UPDATE` | `BookingShopItems` | `booking/lib/adopt.php:408` | — |
| `UPDATE` | `BookingShopOrders` | `booking/lib/adopt.php:409` | — |
| `UPDATE` | `BookingPayments` | `booking/lib/adopt.php:410` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:411` | — |
| `UPDATE` | `BookingSurveys` | `booking/lib/adopt.php:412` | — |
| `UPDATE` | `BookingSurveyVoters` | `booking/lib/adopt.php:413` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/adopt.php:416` | — |
| `UPDATE` | `BookingRefunds` | `booking/lib/adopt.php:417` | — |
| `UPDATE` | `BookingLedger` | `booking/lib/adopt.php:420` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:481` | — |
| `INSERT INTO` | `BookingRegistrations` | `booking/lib/adopt.php:533` | — |
| `INSERT INTO` | `BookingLog` | `booking/lib/archer.php:36` | — |
| `UPDATE` | `BookingArchers` | `booking/lib/archer.php:147` | — |
| `INSERT INTO` | `BookingArchers` | `booking/lib/archer.php:151` | — |
| `INSERT INTO` | `BookingSessions` | `booking/lib/archer.php:164` | — |
| `DELETE FROM` | `BookingSessions` | `booking/lib/archer.php:168` | — |
| `UPDATE` | `BookingArchers` | `booking/lib/archer.php:170` | — |
| `DELETE FROM` | `BookingSessions` | `booking/lib/archer.php:184` | — |
| `DELETE FROM` | `BookingSessions` | `booking/lib/archer.php:244` | — |
| `UPDATE` | `BookingSessions` | `booking/lib/archer.php:249` | — |
| `DELETE FROM` | `BookingSessions` | `booking/lib/archer.php:261` | — |
| `DELETE FROM` | `BookingTargetCaps` | `booking/lib/caps.php:207` | — |
| `INSERT INTO` | `BookingTargetCaps` | `booking/lib/caps.php:212` | — |
| `DELETE FROM` | `BookingTargetCaps` | `booking/lib/caps.php:220` | — |
| `INSERT IGNORE INTO` | `BookingCompetitions` | `booking/lib/competition.php:225` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:234` | — |
| `DELETE FROM` | `BookingShopOrders` | `booking/lib/competition.php:270` | — |
| `DELETE FROM` | `BookingShopItems` | `booking/lib/competition.php:272` | — |
| `INSERT INTO` | `BookingShopItems` | `booking/lib/competition.php:274` | — |
| `INSERT INTO` | `BookingShopVariants` | `booking/lib/competition.php:280` | — |
| `DELETE FROM` | `BookingTargetCaps` | `booking/lib/competition.php:306` | — |
| `INSERT INTO` | `BookingTargetCaps` | `booking/lib/competition.php:314` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/competition.php:411` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:440` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/competition.php:463` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:466` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:471` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:509` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/competition.php:528` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:537` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:541` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:545` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:547` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:549` | — |
| `UPDATE` | `BookingArchers` | `booking/lib/ffta.php:116` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/geo.php:91` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/geo.php:95` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/mandate.php:167` | — |
| `INSERT INTO` | `BookingPayments` | `booking/lib/payment.php:104` | — |
| `UPDATE` | `BookingPayments` | `booking/lib/payment.php:238` | — |
| `INSERT IGNORE INTO` | `BookingCompetitions` | `booking/lib/payment.php:388` | — |
| `INSERT INTO` | `BookingLedger` | `booking/lib/payment.php:392` | — |
| `INSERT INTO` | `BookingLedger` | `booking/lib/payment.php:418` | — |
| `UPDATE` | `BookingLedger` | `booking/lib/payment.php:430` | — |
| `INSERT INTO` | `BookingRefunds` | `booking/lib/payment.php:492` | — |
| `UPDATE` | `BookingRefunds` | `booking/lib/payment.php:520` | — |
| `INSERT INTO` | `BookingRegistrations` | `booking/lib/registration.php:543` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/registration.php:628` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:195` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:206` | — |
| `ALTER TABLE` | `BookingCompetitions` | `booking/lib/schema.php:210` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/schema.php:262` | — |
| `UPDATE` | `BookingTargetCaps` | `booking/lib/schema.php:298` | — |
| `INSERT IGNORE INTO` | `BookingSurveyVoters` | `booking/lib/schema.php:443` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:532` | — |
| `DELETE FROM` | `BookingShopOrders` | `booking/lib/shop.php:162` | — |
| `INSERT INTO` | `BookingShopOrders` | `booking/lib/shop.php:165` | — |
| `UPDATE` | `BookingShopItems` | `booking/lib/shop.php:231` | — |
| `INSERT INTO` | `BookingShopItems` | `booking/lib/shop.php:234` | — |
| `DELETE FROM` | `BookingShopVariants` | `booking/lib/shop.php:243` | — |
| `DELETE FROM` | `BookingShopOrders` | `booking/lib/shop.php:244` | — |
| `DELETE FROM` | `BookingShopItems` | `booking/lib/shop.php:245` | — |
| `UPDATE` | `BookingShopVariants` | `booking/lib/shop.php:257` | — |
| `INSERT INTO` | `BookingShopVariants` | `booking/lib/shop.php:260` | — |
| `DELETE FROM` | `BookingShopOrders` | `booking/lib/shop.php:268` | — |
| `DELETE FROM` | `BookingShopVariants` | `booking/lib/shop.php:269` | — |
| `DELETE FROM` | `BookingSurveys` | `booking/lib/survey.php:185` | — |
| `DELETE FROM` | `BookingSurveyVoters` | `booking/lib/survey.php:186` | — |
| `INSERT INTO` | `BookingSurveys` | `booking/lib/survey.php:193` | — |
| `INSERT IGNORE INTO` | `BookingSurveyVoters` | `booking/lib/survey.php:196` | — |
| `UPDATE` | `BookingSurveys` | `booking/lib/survey.php:275` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/targets.php:498` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/targets.php:513` | — |
| `INSERT INTO` | `BookingWaitlist` | `booking/lib/waitlist.php:104` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:126` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:205` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:293` | — |
| `DELETE FROM` | `BookingWaitlist` | `booking/lib/waitlist.php:300` | — |
| `DELETE FROM` | `BookingWaitlist` | `booking/lib/waitlist.php:321` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:354` | — |
| `UPDATE` | `BookingArchers` | `booking/public/licence.php:26` | — |
| `UPDATE` | `BookingArchers` | `booking/public/security.php:34` | — |
| `DELETE FROM` | `BookingSessions` | `booking/public/security.php:35` | — |
| `UPDATE` | `BookingArchers` | `booking/public/security.php:54` | — |
| `DELETE FROM` | `BookingSessions` | `booking/public/security.php:56` | — |
| `INSERT INTO` | `AuthShare` | `index.php:42` | — |
| `DELETE FROM` | `AuthShareClub` | `index.php:52` | — |
| `INSERT IGNORE INTO` | `AuthShareClub` | `index.php:54` | — |
| `UPDATE` | `AuthShare` | `index.php:63` | — |
| `ALTER TABLE` | `AuthUsers` | `legal-lib.php:293` | — |
| `UPDATE` | `AuthUsers` | `legal-lib.php:311` | — |
| `UPDATE` | `BookingArchers` | `legal-lib.php:327` | — |
| `ALTER TABLE` | `AuthUsers` | `lib.php:181` | — |
| `ALTER TABLE` | `AuthUsers` | `lib.php:189` | — |
| `ALTER TABLE` | `AuthShare` | `lib.php:210` | — |
| `ALTER TABLE` | `AuthShare` | `lib.php:217` | — |
| `UPDATE` | `AuthShare` | `lib.php:219` | — |
| `ALTER TABLE` | `AuthClaim` | `lib.php:244` | — |
| `ALTER TABLE` | `AuthSessions` | `lib.php:275` | — |
| `ALTER TABLE` | `AuthSessions` | `lib.php:284` | — |
| `ALTER TABLE` | `AuthTickets` | `lib.php:315` | — |
| `ALTER TABLE` | `AuthTickets` | `lib.php:325` | — |
| `INSERT INTO` | `AuthLog` | `lib.php:345` | — |
| `DELETE FROM` | `AuthLog` | `lib.php:378` | — |
| `DELETE FROM` | `BookingLog` | `lib.php:381` | — |
| `INSERT INTO` | `AuthTickets` | `lib.php:456` | — |
| `UPDATE` | `AuthTickets` | `lib.php:497` | — |
| `UPDATE` | `AuthTickets` | `lib.php:519` | — |
| `UPDATE` | `AuthTickets` | `lib.php:534` | — |
| `DELETE FROM` | `AuthTickets` | `lib.php:540` | — |
| `INSERT INTO` | `AuthSessions` | `lib.php:775` | — |
| `DELETE FROM` | `AuthSessions` | `lib.php:780` | — |
| `DELETE FROM` | `AuthSessions` | `lib.php:806` | — |
| `UPDATE` | `AuthSessions` | `lib.php:810` | — |
| `DELETE FROM` | `AuthSessions` | `lib.php:819` | — |
| `DELETE FROM` | `AuthShare` | `lib.php:903` | — |
| `DELETE FROM` | `AuthShareClub` | `lib.php:904` | — |
| `INSERT INTO` | `AuthClaim` | `lib.php:939` | — |
| `INSERT INTO` | `AuthShare` | `lib.php:1105` | — |
| `INSERT INTO` | `AuthShare` | `lib.php:1192` | — |
| `DELETE FROM` | `AuthClaim` | `lib.php:1198` | — |
| `DELETE FROM` | `AuthClaim` | `lib.php:1202` | — |
| `UPDATE` | `AuthSessions` | `lib.php:1263` | — |
| `UPDATE` | `AuthSessions` | `lib.php:1272` | — |
| `UPDATE` | `AuthUsers` | `lib.php:1386` | — |
| `UPDATE` | `AuthUsers` | `lib.php:1502` | — |
| `UPDATE` | `AuthUsers` | `lib.php:2314` | — |
| `INSERT INTO` | `AuthUsers` | `lib.php:2320` | — |
| `UPDATE` | `BookingArchers` | `login.php:92` | — |
| `INSERT INTO` | `AuthClubLogos` | `logos-lib.php:158` | — |
| `UPDATE` | `AuthClubLogos` | `logos-lib.php:166` | — |
| `UPDATE` | `AuthClubLogos` | `logos-lib.php:177` | — |
| `ALTER TABLE` | `AuthUsage` | `stats-usage.php:128` | — |
| `ALTER TABLE` | `AuthUsageSeen` | `stats-usage.php:132` | — |
| `INSERT INTO` | `AuthUsage` | `stats-usage.php:241` | — |
| `INSERT IGNORE INTO` | `AuthUsageSeen` | `stats-usage.php:247` | — |
| `DELETE FROM` | `AuthUsageSeen` | `stats-usage.php:271` | — |
| `DELETE FROM` | `AuthUsage` | `stats-usage.php:272` | — |
| `UPDATE` | `AuthSessions` | `switch-view.php:25` | — |
| `UPDATE` | `AuthUsers` | `switch-view.php:28` | — |

<!-- END DATABASE WRITES -->
