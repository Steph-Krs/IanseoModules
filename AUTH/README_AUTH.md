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

Internal tables created automatically: `AUT_` prefix (organiser accounts) and `BK_` (licensee
accounts, registrations, shop, payments).

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
| `UPDATE` | `Entries` | `anonymise-lib.php:349` | review scope by hand |
| `DELETE FROM` | `Photos` | `anonymise-lib.php:354` | — |
| `DELETE FROM` | `ExtraData` | `anonymise-lib.php:356` | — |
| `UPDATE` | `ExtraData` | `anonymise-lib.php:358` | — |
| `UPDATE` | `TournamentInvolved` | `anonymise-lib.php:363` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/adopt.php:223` | review scope by hand |
| `UPDATE` | `IdCards` | `booking/lib/mandate.php:586` | review scope by hand |
| `INSERT INTO` | `Countries` | `booking/lib/registration.php:298` | — |
| `UPDATE` | `Countries` | `booking/lib/registration.php:302` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/registration.php:359` | review scope by hand |
| `INSERT INTO` | `Entries` | `booking/lib/registration.php:485` | — |
| `UPDATE` | `Entries` | `booking/lib/registration.php:495` | review scope by hand |
| `INSERT INTO` | `Qualifications` | `booking/lib/registration.php:500` | — |
| `UPDATE` | `Qualifications` | `booking/lib/registration.php:501` | UNBOUNDED — must join Entries; review scope by hand |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:293` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:426` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:517` | — |
| `DELETE FROM` | `LookUpEntries` | `cron/sync-licences.php:131` | review scope by hand |
| `INSERT INTO` | `LookUpEntries` | `cron/sync-licences.php:148` | — |
| `UPDATE` | `LookUpPaths` | `cron/sync-licences.php:152` | review scope by hand |
| `DELETE FROM` | `LookUpEntries` | `cron/sync-licences.php:180` | review scope by hand |
| `INSERT IGNORE INTO` | `LookUpEntries` | `cron/sync-licences.php:192` | — |
| `INSERT INTO` | `LookUpPaths` | `cron/sync-licences.php:228` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:238` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:250` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:260` | — |
| `INSERT INTO` | `Flags` | `logos-lib.php:264` | — |
| `INSERT INTO` | `Flags` | `logos-lib.php:310` | — |

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `INSERT INTO` | `AUT_Users` | `admin/index.php:102` | — |
| `UPDATE` | `AUT_Users` | `admin/index.php:125` | — |
| `UPDATE` | `AUT_Users` | `admin/index.php:142` | — |
| `UPDATE` | `AUT_Users` | `admin/index.php:153` | — |
| `DELETE FROM` | `AUT_Users` | `admin/index.php:177` | — |
| `UPDATE` | `BK_Archers` | `admin/index.php:193` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:194` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:198` | — |
| `UPDATE` | `BK_Archers` | `admin/index.php:202` | — |
| `UPDATE` | `BK_Archers` | `admin/index.php:208` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:209` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:213` | — |
| `DELETE FROM` | `BK_Archers` | `admin/index.php:214` | — |
| `DELETE FROM` | `BK_ShopOrders` | `anonymise-lib.php:245` | — |
| `DELETE FROM` | `BK_Payments` | `anonymise-lib.php:246` | — |
| `UPDATE` | `BK_Registrations` | `anonymise-lib.php:275` | — |
| `UPDATE` | `BK_Registrations` | `anonymise-lib.php:276` | — |
| `UPDATE` | `BK_Registrations` | `anonymise-lib.php:277` | — |
| `UPDATE` | `BK_Ledger` | `anonymise-lib.php:281` | — |
| `UPDATE` | `BK_Surveys` | `anonymise-lib.php:289` | — |
| `DELETE FROM` | `BK_SurveyVoters` | `anonymise-lib.php:290` | — |
| `UPDATE` | `BK_Log` | `anonymise-lib.php:291` | — |
| `UPDATE` | `BK_ReimportConflicts` | `anonymise-lib.php:293` | — |
| `DELETE FROM` | `BK_Waitlist` | `anonymise-lib.php:332` | — |
| `DELETE FROM` | `BK_Sessions` | `anonymise-lib.php:371` | — |
| `DELETE FROM` | `BK_ClubManagers` | `anonymise-lib.php:372` | — |
| `DELETE FROM` | `BK_Archers` | `anonymise-lib.php:373` | — |
| `UPDATE` | `BK_Waitlist` | `anonymise-lib.php:378` | — |
| `UPDATE` | `BK_Waitlist` | `anonymise-lib.php:379` | — |
| `UPDATE` | `BK_Competitions` | `booking/admin/competition.php:124` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/admin/competition.php:128` | — |
| `UPDATE` | `BK_Competitions` | `booking/admin/competition.php:204` | — |
| `UPDATE` | `BK_Competitions` | `booking/admin/mandate.php:39` | — |
| `INSERT INTO` | `BK_ReimportConflicts` | `booking/lib/adopt.php:104` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:172` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:176` | — |
| `UPDATE` | `BK_ReimportConflicts` | `booking/lib/adopt.php:187` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:242` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:276` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:297` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:315` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/adopt.php:406` | — |
| `UPDATE` | `BK_TargetCaps` | `booking/lib/adopt.php:407` | — |
| `UPDATE` | `BK_ShopItems` | `booking/lib/adopt.php:408` | — |
| `UPDATE` | `BK_ShopOrders` | `booking/lib/adopt.php:409` | — |
| `UPDATE` | `BK_Payments` | `booking/lib/adopt.php:410` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:411` | — |
| `UPDATE` | `BK_Surveys` | `booking/lib/adopt.php:412` | — |
| `UPDATE` | `BK_SurveyVoters` | `booking/lib/adopt.php:413` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/adopt.php:416` | — |
| `UPDATE` | `BK_Refunds` | `booking/lib/adopt.php:417` | — |
| `UPDATE` | `BK_Ledger` | `booking/lib/adopt.php:420` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:481` | — |
| `INSERT INTO` | `BK_Registrations` | `booking/lib/adopt.php:533` | — |
| `INSERT INTO` | `BK_Log` | `booking/lib/archer.php:36` | — |
| `UPDATE` | `BK_Archers` | `booking/lib/archer.php:146` | — |
| `INSERT INTO` | `BK_Archers` | `booking/lib/archer.php:150` | — |
| `INSERT INTO` | `BK_Sessions` | `booking/lib/archer.php:163` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:167` | — |
| `UPDATE` | `BK_Archers` | `booking/lib/archer.php:169` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:182` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:242` | — |
| `UPDATE` | `BK_Sessions` | `booking/lib/archer.php:247` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:259` | — |
| `DELETE FROM` | `BK_TargetCaps` | `booking/lib/caps.php:207` | — |
| `INSERT INTO` | `BK_TargetCaps` | `booking/lib/caps.php:212` | — |
| `DELETE FROM` | `BK_TargetCaps` | `booking/lib/caps.php:220` | — |
| `INSERT IGNORE INTO` | `BK_Competitions` | `booking/lib/competition.php:223` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:232` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/competition.php:268` | — |
| `DELETE FROM` | `BK_ShopItems` | `booking/lib/competition.php:270` | — |
| `INSERT INTO` | `BK_ShopItems` | `booking/lib/competition.php:272` | — |
| `INSERT INTO` | `BK_ShopVariants` | `booking/lib/competition.php:278` | — |
| `DELETE FROM` | `BK_TargetCaps` | `booking/lib/competition.php:304` | — |
| `INSERT INTO` | `BK_TargetCaps` | `booking/lib/competition.php:312` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/competition.php:409` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:438` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/competition.php:461` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:464` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:469` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:507` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/competition.php:526` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:535` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:539` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:543` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:545` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:547` | — |
| `UPDATE` | `BK_Archers` | `booking/lib/ffta.php:114` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/geo.php:91` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/geo.php:95` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/mandate.php:164` | — |
| `INSERT INTO` | `BK_Payments` | `booking/lib/payment.php:104` | — |
| `UPDATE` | `BK_Payments` | `booking/lib/payment.php:238` | — |
| `INSERT IGNORE INTO` | `BK_Competitions` | `booking/lib/payment.php:388` | — |
| `INSERT INTO` | `BK_Ledger` | `booking/lib/payment.php:392` | — |
| `INSERT INTO` | `BK_Ledger` | `booking/lib/payment.php:418` | — |
| `UPDATE` | `BK_Ledger` | `booking/lib/payment.php:430` | — |
| `INSERT INTO` | `BK_Refunds` | `booking/lib/payment.php:492` | — |
| `UPDATE` | `BK_Refunds` | `booking/lib/payment.php:520` | — |
| `INSERT INTO` | `BK_Registrations` | `booking/lib/registration.php:541` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/registration.php:626` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/schema.php:193` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/schema.php:204` | — |
| `ALTER TABLE` | `BK_Competitions` | `booking/lib/schema.php:208` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/schema.php:260` | — |
| `UPDATE` | `BK_TargetCaps` | `booking/lib/schema.php:296` | — |
| `INSERT IGNORE INTO` | `BK_SurveyVoters` | `booking/lib/schema.php:441` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/schema.php:530` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/shop.php:162` | — |
| `INSERT INTO` | `BK_ShopOrders` | `booking/lib/shop.php:165` | — |
| `UPDATE` | `BK_ShopItems` | `booking/lib/shop.php:231` | — |
| `INSERT INTO` | `BK_ShopItems` | `booking/lib/shop.php:234` | — |
| `DELETE FROM` | `BK_ShopVariants` | `booking/lib/shop.php:243` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/shop.php:244` | — |
| `DELETE FROM` | `BK_ShopItems` | `booking/lib/shop.php:245` | — |
| `UPDATE` | `BK_ShopVariants` | `booking/lib/shop.php:257` | — |
| `INSERT INTO` | `BK_ShopVariants` | `booking/lib/shop.php:260` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/shop.php:268` | — |
| `DELETE FROM` | `BK_ShopVariants` | `booking/lib/shop.php:269` | — |
| `DELETE FROM` | `BK_Surveys` | `booking/lib/survey.php:185` | — |
| `DELETE FROM` | `BK_SurveyVoters` | `booking/lib/survey.php:186` | — |
| `INSERT INTO` | `BK_Surveys` | `booking/lib/survey.php:193` | — |
| `INSERT IGNORE INTO` | `BK_SurveyVoters` | `booking/lib/survey.php:196` | — |
| `UPDATE` | `BK_Surveys` | `booking/lib/survey.php:275` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/targets.php:493` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/targets.php:508` | — |
| `INSERT INTO` | `BK_Waitlist` | `booking/lib/waitlist.php:104` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:126` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:205` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:293` | — |
| `DELETE FROM` | `BK_Waitlist` | `booking/lib/waitlist.php:300` | — |
| `DELETE FROM` | `BK_Waitlist` | `booking/lib/waitlist.php:321` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:354` | — |
| `UPDATE` | `BK_Archers` | `booking/public/licence.php:26` | — |
| `UPDATE` | `BK_Archers` | `booking/public/security.php:34` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/public/security.php:35` | — |
| `UPDATE` | `BK_Archers` | `booking/public/security.php:54` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/public/security.php:56` | — |
| `INSERT INTO` | `AUT_Share` | `index.php:42` | — |
| `DELETE FROM` | `AUT_ShareClub` | `index.php:52` | — |
| `INSERT IGNORE INTO` | `AUT_ShareClub` | `index.php:54` | — |
| `UPDATE` | `AUT_Share` | `index.php:63` | — |
| `ALTER TABLE` | `AUT_Users` | `legal-lib.php:273` | — |
| `UPDATE` | `AUT_Users` | `legal-lib.php:291` | — |
| `UPDATE` | `BK_Archers` | `legal-lib.php:307` | — |
| `ALTER TABLE` | `AUT_Users` | `lib.php:178` | — |
| `ALTER TABLE` | `AUT_Users` | `lib.php:186` | — |
| `ALTER TABLE` | `AUT_Share` | `lib.php:207` | — |
| `ALTER TABLE` | `AUT_Share` | `lib.php:214` | — |
| `UPDATE` | `AUT_Share` | `lib.php:216` | — |
| `ALTER TABLE` | `AUT_Claim` | `lib.php:241` | — |
| `ALTER TABLE` | `AUT_Sessions` | `lib.php:272` | — |
| `ALTER TABLE` | `AUT_Sessions` | `lib.php:281` | — |
| `ALTER TABLE` | `AUT_Tickets` | `lib.php:312` | — |
| `ALTER TABLE` | `AUT_Tickets` | `lib.php:322` | — |
| `INSERT INTO` | `AUT_Log` | `lib.php:342` | — |
| `DELETE FROM` | `AUT_Log` | `lib.php:375` | — |
| `DELETE FROM` | `BK_Log` | `lib.php:378` | — |
| `INSERT INTO` | `AUT_Tickets` | `lib.php:451` | — |
| `UPDATE` | `AUT_Tickets` | `lib.php:492` | — |
| `UPDATE` | `AUT_Tickets` | `lib.php:514` | — |
| `UPDATE` | `AUT_Tickets` | `lib.php:529` | — |
| `DELETE FROM` | `AUT_Tickets` | `lib.php:535` | — |
| `INSERT INTO` | `AUT_Sessions` | `lib.php:763` | — |
| `DELETE FROM` | `AUT_Sessions` | `lib.php:768` | — |
| `DELETE FROM` | `AUT_Sessions` | `lib.php:793` | — |
| `UPDATE` | `AUT_Sessions` | `lib.php:797` | — |
| `DELETE FROM` | `AUT_Sessions` | `lib.php:806` | — |
| `DELETE FROM` | `AUT_Share` | `lib.php:890` | — |
| `DELETE FROM` | `AUT_ShareClub` | `lib.php:891` | — |
| `INSERT INTO` | `AUT_Claim` | `lib.php:926` | — |
| `INSERT INTO` | `AUT_Share` | `lib.php:1091` | — |
| `INSERT INTO` | `AUT_Share` | `lib.php:1178` | — |
| `DELETE FROM` | `AUT_Claim` | `lib.php:1184` | — |
| `DELETE FROM` | `AUT_Claim` | `lib.php:1188` | — |
| `UPDATE` | `AUT_Sessions` | `lib.php:1249` | — |
| `UPDATE` | `AUT_Sessions` | `lib.php:1258` | — |
| `UPDATE` | `AUT_Users` | `lib.php:1371` | — |
| `UPDATE` | `AUT_Users` | `lib.php:1486` | — |
| `UPDATE` | `AUT_Users` | `lib.php:2287` | — |
| `INSERT INTO` | `AUT_Users` | `lib.php:2293` | — |
| `UPDATE` | `BK_Archers` | `login.php:92` | — |
| `INSERT INTO` | `AUT_ClubLogos` | `logos-lib.php:155` | — |
| `UPDATE` | `AUT_ClubLogos` | `logos-lib.php:163` | — |
| `UPDATE` | `AUT_ClubLogos` | `logos-lib.php:174` | — |
| `ALTER TABLE` | `AUT_Usage` | `stats-usage.php:126` | — |
| `ALTER TABLE` | `AUT_UsageSeen` | `stats-usage.php:130` | — |
| `INSERT INTO` | `AUT_Usage` | `stats-usage.php:236` | — |
| `INSERT IGNORE INTO` | `AUT_UsageSeen` | `stats-usage.php:242` | — |
| `DELETE FROM` | `AUT_UsageSeen` | `stats-usage.php:266` | — |
| `DELETE FROM` | `AUT_Usage` | `stats-usage.php:267` | — |
| `UPDATE` | `AUT_Sessions` | `switch-view.php:25` | — |
| `UPDATE` | `AUT_Users` | `switch-view.php:28` | — |

<!-- END DATABASE WRITES -->
