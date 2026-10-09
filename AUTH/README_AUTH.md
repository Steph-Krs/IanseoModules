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

### Food & shop — points of sale online
Full management of a competition's points of sale (cafeteria, catering, shop) with ordering from
competitors' and visitors' phones, and a mobile till for volunteers.

- 🏪 **Setup and catalogue**: create points of sale (direct delivery or queue mode, pay before or
  at pickup), products with variants, stock tracking, per-order and per-person limits, pre-ordering
  up to a set date
- 🛒 **Ordering**: product selection, cart remembered per phone, live status tracking ("received" →
  "being prepared" → "ready"), estimated wait time, cancellation before pickup; order or pre-order
  **for a given day and time** (lunch ordered in the morning is not prepared too early)
- 🔔 **Phone notification** when the order is ready, even with the page closed (Android; iPhone once
  the page is added to the home screen, iOS 16.4 or later; site served over HTTPS)
- 📺 **Public display** as in fast-food restaurants: orders waiting (with their payment state), being
  prepared (with the estimated time), ready; each column shows how many orders it holds, and each
  order the customer's given name and initial, or nickname
- 👥 **Customers**: licensed archers signed into their archer space, or visitors with a nickname
  (auto-registered at first order; access closed the day after the competition, the nickname stays
  on the orders to tell which ones were honoured)
- 🧾 **Volunteers' till**: sale, payment, order queue sorted by requested time, **wait time set per
  order** (entered at payment, adjusted by the preparer with +2 / +5 / −2 / −5 min buttons); stable
  address and a QR code poster to come back to the till
- 💰 **Payments**: cash, cheque, card terminal, other means set by the organiser; tab for connected
  licensees (settlement at end of competition); no payment card data is stored on the server
- 👔 **Volunteers**: invited by QR code with organiser validation and a 4-digit verification code,
  pre-set roles (seller, preparer, all-in-one, stand supervisor), refund ceilings, rights given
  **per stand** for better autonomy, immediate revocation possible; unlicensed accounts open from
  day-before to day-after (auto-deleted next day)
- 📊 **Reports**: breakdown per stand, per day, per payment method, per volunteer; till closure as
  PDF and CSV exports; open accounts (tab) linked to the Payments page
- 📋 **Copy**: replicate settings and catalogue from a previous competition; stock keeps initial
  quantity
- 🔐 **Payer trust index**: unpaid and late payments computed each night on the competitions whose
  organiser records the payments; only slows down deferred payment (registration paid later,
  pre-order, on account), never an immediate payment; modes: off, observation (administrator only,
  the default), alert (organisers), block (credit refused to a red archer or club unless the
  organiser accepts them); archers see their own level
- 🔁 **The shop of the online registration is taken over**: items, options, stock and orders move
  automatically into food & shop on update, without changing what any archer owes; old links and QR
  codes lead to the new shop

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
| `DELETE FROM` | `TournamentInvolved` | `anonymise-lib.php:249` | — |
| `UPDATE` | `Entries` | `anonymise-lib.php:374` | review scope by hand |
| `DELETE FROM` | `Photos` | `anonymise-lib.php:379` | — |
| `DELETE FROM` | `ExtraData` | `anonymise-lib.php:381` | — |
| `UPDATE` | `ExtraData` | `anonymise-lib.php:383` | — |
| `UPDATE` | `TournamentInvolved` | `anonymise-lib.php:388` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/adopt.php:224` | review scope by hand |
| `UPDATE` | `IdCards` | `booking/lib/mandate.php:688` | review scope by hand |
| `INSERT INTO` | `Countries` | `booking/lib/registration.php:312` | — |
| `UPDATE` | `Countries` | `booking/lib/registration.php:316` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/registration.php:373` | review scope by hand |
| `INSERT INTO` | `Entries` | `booking/lib/registration.php:509` | — |
| `UPDATE` | `Entries` | `booking/lib/registration.php:519` | review scope by hand |
| `INSERT INTO` | `Qualifications` | `booking/lib/registration.php:524` | — |
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
| `DELETE FROM` | `BookingShopOrders` | `anonymise-lib.php:250` | — |
| `DELETE FROM` | `BookingPayments` | `anonymise-lib.php:251` | — |
| `UPDATE` | `BookingRegistrations` | `anonymise-lib.php:287` | — |
| `UPDATE` | `BookingRegistrations` | `anonymise-lib.php:288` | — |
| `UPDATE` | `BookingRegistrations` | `anonymise-lib.php:289` | — |
| `UPDATE` | `BookingLedger` | `anonymise-lib.php:293` | — |
| `UPDATE IGNORE` | `BookingPayments` | `anonymise-lib.php:295` | — |
| `DELETE FROM` | `BookingPayments` | `anonymise-lib.php:296` | — |
| `UPDATE IGNORE` | `BookingShopOrders` | `anonymise-lib.php:299` | — |
| `DELETE FROM` | `BookingShopOrders` | `anonymise-lib.php:300` | — |
| `UPDATE` | `ShopOrders` | `anonymise-lib.php:303` | — |
| `DELETE FROM` | `ShopStaffSessions` | `anonymise-lib.php:306` | — |
| `UPDATE` | `ShopStaff` | `anonymise-lib.php:308` | — |
| `UPDATE` | `BookingSurveys` | `anonymise-lib.php:314` | — |
| `DELETE FROM` | `BookingSurveyVoters` | `anonymise-lib.php:315` | — |
| `UPDATE` | `BookingLog` | `anonymise-lib.php:316` | — |
| `UPDATE` | `BookingReimportConflicts` | `anonymise-lib.php:318` | — |
| `DELETE FROM` | `BookingWaitlist` | `anonymise-lib.php:357` | — |
| `DELETE FROM` | `BookingSessions` | `anonymise-lib.php:396` | — |
| `DELETE FROM` | `BookingClubManagers` | `anonymise-lib.php:397` | — |
| `DELETE FROM` | `BookingArchers` | `anonymise-lib.php:398` | — |
| `UPDATE` | `BookingWaitlist` | `anonymise-lib.php:403` | — |
| `UPDATE` | `BookingWaitlist` | `anonymise-lib.php:404` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/competition.php:127` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/admin/competition.php:131` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/competition.php:214` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/mandate.php:40` | — |
| `INSERT INTO` | `BookingReimportConflicts` | `booking/lib/adopt.php:105` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:173` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:177` | — |
| `UPDATE` | `BookingReimportConflicts` | `booking/lib/adopt.php:188` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:243` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:277` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:298` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/adopt.php:316` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/adopt.php:413` | — |
| `UPDATE` | `BookingTargetCaps` | `booking/lib/adopt.php:414` | — |
| `UPDATE` | `BookingShopItems` | `booking/lib/adopt.php:415` | — |
| `UPDATE` | `BookingShopOrders` | `booking/lib/adopt.php:416` | — |
| `UPDATE` | `BookingPayments` | `booking/lib/adopt.php:417` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:418` | — |
| `UPDATE` | `BookingSurveys` | `booking/lib/adopt.php:419` | — |
| `UPDATE` | `BookingSurveyVoters` | `booking/lib/adopt.php:420` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/adopt.php:423` | — |
| `UPDATE` | `BookingRefunds` | `booking/lib/adopt.php:424` | — |
| `UPDATE` | `BookingLedger` | `booking/lib/adopt.php:427` | — |
| `DELETE FROM` | `ShopSettings` | `booking/lib/adopt.php:431` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/adopt.php:503` | — |
| `INSERT INTO` | `BookingRegistrations` | `booking/lib/adopt.php:555` | — |
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
| `DELETE FROM` | `BookingTargetCaps` | `booking/lib/caps.php:218` | — |
| `INSERT INTO` | `BookingTargetCaps` | `booking/lib/caps.php:223` | — |
| `DELETE FROM` | `BookingTargetCaps` | `booking/lib/caps.php:231` | — |
| `INSERT IGNORE INTO` | `BookingCompetitions` | `booking/lib/competition.php:257` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:266` | — |
| `DELETE FROM` | `BookingSessionRules` | `booking/lib/competition.php:283` | — |
| `INSERT INTO` | `BookingSessionRules` | `booking/lib/competition.php:284` | — |
| `DELETE FROM` | `BookingTargetCaps` | `booking/lib/competition.php:329` | — |
| `INSERT INTO` | `BookingTargetCaps` | `booking/lib/competition.php:337` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/competition.php:438` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:467` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/competition.php:490` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:493` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:498` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:537` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/competition.php:556` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:565` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:569` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:573` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:578` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/competition.php:580` | — |
| `UPDATE` | `BookingArchers` | `booking/lib/ffta.php:116` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/geo.php:91` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/geo.php:95` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/mandate.php:168` | — |
| `INSERT INTO` | `BookingPayments` | `booking/lib/payment.php:187` | — |
| `UPDATE` | `BookingPayments` | `booking/lib/payment.php:322` | — |
| `INSERT IGNORE INTO` | `BookingCompetitions` | `booking/lib/payment.php:594` | — |
| `INSERT INTO` | `BookingLedger` | `booking/lib/payment.php:598` | — |
| `INSERT INTO` | `BookingLedger` | `booking/lib/payment.php:653` | — |
| `UPDATE` | `BookingLedger` | `booking/lib/payment.php:668` | — |
| `INSERT INTO` | `BookingRefunds` | `booking/lib/payment.php:748` | — |
| `UPDATE` | `BookingRefunds` | `booking/lib/payment.php:776` | — |
| `INSERT INTO` | `BookingRegistrations` | `booking/lib/registration.php:565` | — |
| `DELETE FROM` | `BookingRegistrations` | `booking/lib/registration.php:650` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:211` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:222` | — |
| `ALTER TABLE` | `BookingCompetitions` | `booking/lib/schema.php:226` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/schema.php:278` | — |
| `UPDATE` | `BookingTargetCaps` | `booking/lib/schema.php:314` | — |
| `INSERT IGNORE INTO` | `BookingSurveyVoters` | `booking/lib/schema.php:459` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:548` | — |
| `ALTER TABLE` | `BookingSessionRules` | `booking/lib/schema.php:592` | — |
| `UPDATE` | `BookingCompetitions` | `booking/lib/schema.php:602` | — |
| `DELETE FROM` | `BookingSessionRules` | `booking/lib/sessionrules.php:132` | — |
| `INSERT INTO` | `BookingSessionRules` | `booking/lib/sessionrules.php:135` | — |
| `DELETE FROM` | `BookingSurveys` | `booking/lib/survey.php:185` | — |
| `DELETE FROM` | `BookingSurveyVoters` | `booking/lib/survey.php:186` | — |
| `INSERT INTO` | `BookingSurveys` | `booking/lib/survey.php:193` | — |
| `INSERT IGNORE INTO` | `BookingSurveyVoters` | `booking/lib/survey.php:196` | — |
| `UPDATE` | `BookingSurveys` | `booking/lib/survey.php:275` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/targets.php:498` | — |
| `UPDATE` | `BookingRegistrations` | `booking/lib/targets.php:513` | — |
| `INSERT INTO` | `BookingWaitlist` | `booking/lib/waitlist.php:104` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:126` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:209` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:297` | — |
| `DELETE FROM` | `BookingWaitlist` | `booking/lib/waitlist.php:304` | — |
| `DELETE FROM` | `BookingWaitlist` | `booking/lib/waitlist.php:325` | — |
| `UPDATE` | `BookingWaitlist` | `booking/lib/waitlist.php:358` | — |
| `UPDATE` | `BookingArchers` | `booking/public/licence.php:26` | — |
| `UPDATE` | `BookingArchers` | `booking/public/security.php:34` | — |
| `DELETE FROM` | `BookingSessions` | `booking/public/security.php:35` | — |
| `UPDATE` | `BookingArchers` | `booking/public/security.php:54` | — |
| `DELETE FROM` | `BookingSessions` | `booking/public/security.php:56` | — |
| `INSERT INTO` | `AuthShare` | `index.php:42` | — |
| `DELETE FROM` | `AuthShareClub` | `index.php:52` | — |
| `INSERT IGNORE INTO` | `AuthShareClub` | `index.php:54` | — |
| `UPDATE` | `AuthShare` | `index.php:63` | — |
| `ALTER TABLE` | `AuthUsers` | `legal-lib.php:298` | — |
| `UPDATE` | `AuthUsers` | `legal-lib.php:316` | — |
| `UPDATE` | `BookingArchers` | `legal-lib.php:332` | — |
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
| `INSERT INTO` | `AuthLog` | `lib.php:385` | — |
| `DELETE FROM` | `AuthLog` | `lib.php:418` | — |
| `DELETE FROM` | `BookingLog` | `lib.php:421` | — |
| `INSERT INTO` | `AuthTickets` | `lib.php:496` | — |
| `UPDATE` | `AuthTickets` | `lib.php:537` | — |
| `UPDATE` | `AuthTickets` | `lib.php:559` | — |
| `UPDATE` | `AuthTickets` | `lib.php:574` | — |
| `DELETE FROM` | `AuthTickets` | `lib.php:580` | — |
| `INSERT INTO` | `AuthSessions` | `lib.php:815` | — |
| `DELETE FROM` | `AuthSessions` | `lib.php:820` | — |
| `DELETE FROM` | `AuthSessions` | `lib.php:846` | — |
| `UPDATE` | `AuthSessions` | `lib.php:850` | — |
| `DELETE FROM` | `AuthSessions` | `lib.php:859` | — |
| `DELETE FROM` | `AuthShare` | `lib.php:943` | — |
| `DELETE FROM` | `AuthShareClub` | `lib.php:944` | — |
| `INSERT INTO` | `AuthClaim` | `lib.php:979` | — |
| `INSERT INTO` | `AuthShare` | `lib.php:1145` | — |
| `INSERT INTO` | `AuthShare` | `lib.php:1232` | — |
| `DELETE FROM` | `AuthClaim` | `lib.php:1238` | — |
| `DELETE FROM` | `AuthClaim` | `lib.php:1242` | — |
| `UPDATE` | `AuthSessions` | `lib.php:1303` | — |
| `UPDATE` | `AuthSessions` | `lib.php:1312` | — |
| `UPDATE` | `AuthUsers` | `lib.php:1426` | — |
| `UPDATE` | `AuthUsers` | `lib.php:1542` | — |
| `UPDATE` | `AuthUsers` | `lib.php:2354` | — |
| `INSERT INTO` | `AuthUsers` | `lib.php:2360` | — |
| `UPDATE` | `BookingArchers` | `login.php:92` | — |
| `INSERT INTO` | `AuthClubLogos` | `logos-lib.php:158` | — |
| `UPDATE` | `AuthClubLogos` | `logos-lib.php:166` | — |
| `UPDATE` | `AuthClubLogos` | `logos-lib.php:177` | — |
| `UPDATE` | `ShopStands` | `shop/lib/catalog-admin.php:169` | — |
| `INSERT INTO` | `ShopStands` | `shop/lib/catalog-admin.php:171` | — |
| `DELETE FROM` | `ShopStaffStands` | `shop/lib/catalog-admin.php:187` | — |
| `DELETE FROM` | `ShopStands` | `shop/lib/catalog-admin.php:188` | — |
| `DELETE FROM` | `ShopVariants` | `shop/lib/catalog-admin.php:201` | — |
| `DELETE FROM` | `ShopStockMoves` | `shop/lib/catalog-admin.php:202` | — |
| `DELETE FROM` | `ShopProducts` | `shop/lib/catalog-admin.php:203` | — |
| `UPDATE` | `ShopProducts` | `shop/lib/catalog-admin.php:369` | — |
| `INSERT INTO` | `ShopProducts` | `shop/lib/catalog-admin.php:372` | — |
| `DELETE FROM` | `ShopStockMoves` | `shop/lib/catalog-admin.php:388` | — |
| `DELETE FROM` | `ShopVariants` | `shop/lib/catalog-admin.php:389` | — |
| `UPDATE` | `ShopVariants` | `shop/lib/catalog-admin.php:396` | — |
| `INSERT INTO` | `ShopVariants` | `shop/lib/catalog-admin.php:399` | — |
| `DELETE FROM` | `ShopStockMoves` | `shop/lib/catalog-admin.php:411` | — |
| `DELETE FROM` | `ShopVariants` | `shop/lib/catalog-admin.php:412` | — |
| `INSERT IGNORE INTO` | `ShopSettings` | `shop/lib/common.php:97` | — |
| `UPDATE` | `ShopSettings` | `shop/lib/common.php:133` | — |
| `UPDATE IGNORE` | `ShopSettings` | `shop/lib/common.php:145` | — |
| `DELETE FROM` | `ShopStaffStands` | `shop/lib/copy.php:119` | — |
| `DELETE FROM` | `ShopStands` | `shop/lib/copy.php:120` | — |
| `UPDATE` | `ShopSettings` | `shop/lib/copy.php:124` | — |
| `INSERT INTO` | `ShopStands` | `shop/lib/copy.php:132` | — |
| `INSERT INTO` | `ShopProducts` | `shop/lib/copy.php:144` | — |
| `INSERT INTO` | `ShopVariants` | `shop/lib/copy.php:157` | — |
| `UPDATE` | `ShopGuests` | `shop/lib/customer.php:46` | — |
| `UPDATE` | `ShopGuests` | `shop/lib/customer.php:78` | — |
| `INSERT INTO` | `ShopGuests` | `shop/lib/customer.php:84` | — |
| `UPDATE` | `ShopGuests` | `shop/lib/customer.php:97` | — |
| `UPDATE` | `ShopInvites` | `shop/lib/invite.php:46` | — |
| `UPDATE` | `ShopInvites` | `shop/lib/invite.php:51` | — |
| `INSERT INTO` | `ShopInvites` | `shop/lib/invite.php:57` | — |
| `UPDATE` | `ShopInvites` | `shop/lib/invite.php:100` | — |
| `UPDATE` | `ShopInvites` | `shop/lib/invite.php:111` | — |
| `INSERT INTO` | `ShopStands` | `shop/lib/legacy.php:118` | — |
| `INSERT INTO` | `ShopProducts` | `shop/lib/legacy.php:134` | — |
| `INSERT INTO` | `ShopVariants` | `shop/lib/legacy.php:145` | — |
| `UPDATE` | `ShopStands` | `shop/lib/legacy.php:155` | — |
| `INSERT INTO` | `ShopOrders` | `shop/lib/legacy.php:165` | — |
| `INSERT INTO` | `ShopOrderLines` | `shop/lib/legacy.php:175` | — |
| `INSERT INTO` | `ShopStockMoves` | `shop/lib/legacy.php:180` | — |
| `UPDATE` | `ShopSettings` | `shop/lib/legacy.php:186` | — |
| `UPDATE` | `ShopStands` | `shop/lib/orders.php:305` | — |
| `INSERT INTO` | `ShopOrders` | `shop/lib/orders.php:312` | — |
| `INSERT INTO` | `ShopOrderLines` | `shop/lib/orders.php:337` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/orders.php:342` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/orders.php:404` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/orders.php:431` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/orders.php:454` | — |
| `UPDATE` | `ShopOrderLines` | `shop/lib/orders.php:469` | — |
| `UPDATE` | `ShopOrderLines` | `shop/lib/orders.php:499` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/orders.php:509` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/pay.php:148` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/pay.php:245` | — |
| `DELETE FROM` | `ShopStaffSessions` | `shop/lib/purge.php:64` | — |
| `DELETE FROM` | `ShopStaffStands` | `shop/lib/purge.php:65` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/purge.php:66` | — |
| `DELETE FROM` | `ShopInvites` | `shop/lib/purge.php:72` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/purge.php:100` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/purge.php:103` | — |
| `DELETE FROM` | `ShopInvites` | `shop/lib/purge.php:107` | — |
| `DELETE FROM` | `ShopPush` | `shop/lib/purge.php:108` | — |
| `UPDATE` | `ShopGuests` | `shop/lib/purge.php:125` | — |
| `DELETE FROM` | `ShopStaff` | `shop/lib/purge.php:145` | — |
| `DELETE FROM` | `ShopStaffLog` | `shop/lib/purge.php:147` | — |
| `DELETE FROM` | `ShopInvites` | `shop/lib/purge.php:149` | — |
| `DELETE FROM` | `ShopGuests` | `shop/lib/purge.php:151` | — |
| `DELETE FROM` | `ShopPush` | `shop/lib/purge.php:153` | — |
| `UPDATE` | `ShopOrders` | `shop/lib/purge.php:155` | — |
| `INSERT IGNORE INTO` | `ShopPushKeys` | `shop/lib/push.php:89` | — |
| `INSERT INTO` | `ShopPush` | `shop/lib/push.php:171` | — |
| `DELETE FROM` | `ShopPush` | `shop/lib/push.php:182` | — |
| `DELETE FROM` | `ShopPush` | `shop/lib/push.php:191` | — |
| `UPDATE` | `ShopPush` | `shop/lib/push.php:247` | — |
| `DELETE FROM` | `ShopPush` | `shop/lib/push.php:249` | — |
| `UPDATE` | `ShopPush` | `shop/lib/push.php:251` | — |
| `DELETE FROM` | `ShopPush` | `shop/lib/push.php:252` | — |
| `INSERT INTO` | `ShopStaffSessions` | `shop/lib/staff-session.php:36` | — |
| `DELETE FROM` | `ShopStaffSessions` | `shop/lib/staff-session.php:42` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff-session.php:45` | — |
| `DELETE FROM` | `ShopStaffSessions` | `shop/lib/staff-session.php:55` | — |
| `DELETE FROM` | `ShopStaffSessions` | `shop/lib/staff-session.php:97` | — |
| `UPDATE` | `ShopStaffSessions` | `shop/lib/staff-session.php:116` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff-session.php:118` | — |
| `INSERT INTO` | `ShopStaffLog` | `shop/lib/staff.php:312` | — |
| `DELETE FROM` | `ShopStaffStands` | `shop/lib/staff.php:359` | — |
| `INSERT INTO` | `ShopStaffStands` | `shop/lib/staff.php:361` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:379` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:404` | — |
| `DELETE FROM` | `ShopStaffStands` | `shop/lib/staff.php:407` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:426` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:441` | — |
| `UPDATE` | `ShopInvites` | `shop/lib/staff.php:444` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:454` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:478` | — |
| `INSERT INTO` | `ShopStaff` | `shop/lib/staff.php:484` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:542` | — |
| `DELETE FROM` | `ShopStaffStands` | `shop/lib/staff.php:543` | — |
| `INSERT INTO` | `ShopStaff` | `shop/lib/staff.php:546` | — |
| `INSERT INTO` | `ShopStaff` | `shop/lib/staff.php:611` | — |
| `UPDATE` | `ShopStaff` | `shop/lib/staff.php:635` | — |
| `DELETE FROM` | `ShopStaffSessions` | `shop/lib/staff.php:639` | — |
| `INSERT INTO` | `ShopStockMoves` | `shop/lib/stock.php:28` | — |
| `UPDATE` | `ShopVariants` | `shop/lib/stock.php:56` | — |
| `UPDATE` | `ShopProducts` | `shop/lib/stock.php:59` | — |
| `UPDATE` | `ShopVariants` | `shop/lib/stock.php:75` | — |
| `UPDATE` | `ShopProducts` | `shop/lib/stock.php:78` | — |
| `UPDATE` | `ShopVariants` | `shop/lib/stock.php:134` | — |
| `UPDATE` | `ShopProducts` | `shop/lib/stock.php:137` | — |
| `UPDATE` | `ShopStockMoves` | `shop/lib/till.php:525` | — |
| `UPDATE` | `ShopStands` | `shop/lib/till.php:538` | — |
| `DELETE FROM` | `ShopGuests` | `shop/public/api/order.php:66` | — |
| `UPDATE` | `ShopOrders` | `shop/public/api/seen.php:16` | — |
| `UPDATE` | `ShopStaff` | `shop/staff/login.php:110` | — |
| `UPDATE` | `ShopStaff` | `shop/staff/login.php:119` | — |
| `UPDATE` | `ShopStaff` | `shop/staff/login.php:120` | — |
| `ALTER TABLE` | `AuthUsage` | `stats-usage.php:128` | — |
| `ALTER TABLE` | `AuthUsageSeen` | `stats-usage.php:132` | — |
| `INSERT INTO` | `AuthUsage` | `stats-usage.php:241` | — |
| `INSERT IGNORE INTO` | `AuthUsageSeen` | `stats-usage.php:247` | — |
| `DELETE FROM` | `AuthUsageSeen` | `stats-usage.php:271` | — |
| `DELETE FROM` | `AuthUsage` | `stats-usage.php:272` | — |
| `UPDATE` | `AuthSessions` | `switch-view.php:25` | — |
| `UPDATE` | `AuthUsers` | `switch-view.php:28` | — |
| `INSERT INTO` | `AuthTrust` | `trust-lib.php:299` | — |
| `DELETE FROM` | `AuthTrust` | `trust-lib.php:314` | — |
| `INSERT INTO` | `AuthTrust` | `trust-lib.php:362` | — |
| `DELETE FROM` | `AuthTrust` | `trust-lib.php:378` | — |
| `DELETE FROM` | `AuthTrustEvents` | `trust-lib.php:398` | — |
| `INSERT INTO` | `AuthTrustEvents` | `trust-lib.php:429` | — |
| `DELETE FROM` | `AuthTrustEvents` | `trust-lib.php:445` | — |
| `DELETE FROM` | `AuthTrust` | `trust-lib.php:448` | — |
| `DELETE FROM` | `AuthTrust` | `trust-lib.php:449` | — |

<!-- END DATABASE WRITES -->
