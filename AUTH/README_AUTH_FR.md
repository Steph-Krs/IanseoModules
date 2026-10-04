# Multi-comptes

English version: [README_AUTH.md](README_AUTH.md).

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Transforme une installation ianseo hébergée en ligne en **serveur multi-organisateurs**
**avec inscriptions en ligne** : chaque structure dispose de son compte et ne voit / ne modifie
que ses propres compétitions (partage possible pour l'entraide à la saisie), et **les licenciés
s'inscrivent eux-mêmes** aux compétitions ouvertes depuis leur propre espace.

L'interface suit la langue choisie par ianseo pour le visiteur. Les textes sont dans `languages/`
(format du cœur, l'anglais servant de repli pour toute clé manquante). Langues disponibles : anglais,
français, espagnol, allemand et italien.

> Ce README volontairement **ne détaille pas** le fonctionnement interne ni les mécanismes de sécurité.

## ⚠️ À savoir avant de déployer — sécurité des comptes utilisés

Il n'existe pas encore de **SSO officiel** (OAuth / OpenID Connect) fourni par la fédération.
La connexion fonctionne donc par **relais de crédentiels** : à chaque login, l'identifiant et le
mot de passe de l'utilisateur **transitent par CE serveur** pour être vérifiés auprès des espaces
en ligne (dirigeant / licencié). Le mot de passe n'est **jamais stocké ni journalisé**, mais il
passe par la mémoire du serveur le temps de la requête.

**Conséquence, tant qu'un vrai SSO n'est pas en place : la sécurité des comptes des utilisateurs
dépend directement de la sécurité ET de la fiabilité de ce serveur (et de son exploitant).** Les
utilisateurs en sont informés sur la page de connexion. C'est pourquoi le déploiement doit suivre
le durcissement décrit dans `SERVEUR.md`, et pourquoi un vrai OIDC reste à demander au prestataire
des espaces en ligne (le module est prêt à basculer le jour venu).

## Fonctionnalités

### Côté organisateur (multi-comptes)
- 👤 Un compte par organisateur ; chaque compte ne voit que ses compétitions
- 🤝 Partage contrôlé d'une compétition (aide à la saisie, visibilité pour la structure de tutelle)
- 🔑 Connexion centralisée, avec repli sur des comptes locaux
- 🛠️ Administration des comptes et journal d'activité

### Côté compétiteur (inscriptions en ligne — sous-module `booking/`)
- 🎯 Espace licencié : calendrier des compétitions ouvertes, inscription en quelques clics
- 🧩 Attribution automatique départ/cible selon les règles fédérales (dont cohabitation des blasons)
- ⏳ Liste d'attente quand un départ est complet, depuis le formulaire d'inscription habituel :
  dès qu'une place se libère, le premier archer compatible est inscrit automatiquement et prévenu
  dans son espace
- 👥 Inscription groupée d'un camarade de club ; boutique
- 💶 **Paiements** : un compte par participant (inscrit en ligne ou saisi dans ianseo) — total dû
  d'après les tarifs de la compétition, déjà payé, reste à payer ; historique des encaissements,
  annulations et remboursements ; règlement d'un club en une fois, du montant choisi ; reçus et liste
  en PDF. L'archer voit son compte et imprime son reçu à tout moment, et l'accueil lui rappelle ce
  qu'il doit encore pour une compétition terminée. Aussi pour une compétition **fermée** sur le
  serveur (participants importés dans ianseo) : une case active tarifs, paiements et boutique sans
  ouvrir les inscriptions en ligne
- 🧾 Mandat, documents et feuilles de marque de la compétition consultables par les archers
- 🗳️ Questionnaire de satisfaction après la compétition (moins de 2 minutes, rien d'obligatoire) ;
  l'organisateur en voit les résultats anonymes, en graphiques simples, comparés aux autres compétitions

### Côté administrateur du serveur
- 🌙 Maintenance nocturne automatique : mise à jour d'ianseo et des modules, synchronisations
- 💾 Sauvegarde nocturne de la base et des fichiers, plus une copie de la base toutes les
  6 heures sans rien bloquer, avec copie en ligne chiffrée facultative (Google Drive, Dropbox,
  OneDrive, NAS…) — sans sauvegarde valide, ianseo n'est pas mis à jour
- ♻️ Restauration guidée, et vérification d'une copie sans toucher au site
- 🚨 Alerte à l'administrateur quand la nuit échoue ou ne tourne plus ; signal de vie facultatif
  vers un service de supervision
- 🩺 Page **État du serveur** : signale les réglages qui ralentissent ou bloquent un serveur ianseo
  en ligne (MySQL 8, connexions, mémoire, sessions, taille des imports, départs surdimensionnés…),
  avec la correction à appliquer
- 🕶️ **Anonymiser un licencié** sur toutes les compétitions du serveur (demande d'effacement) :
  inscriptions à venir supprimées (l'organisateur est prévenu d'un remboursement à faire si un
  paiement avait été enregistré) ; ailleurs, licence remplacée par « ANON », nom, prénom, date de naissance
  et photo retirés, résultats sportifs conservés ; compte en ligne supprimé
- ⚙️ Page **Configuration du serveur** : réglages modifiables sans ligne de commande
  (mots de passe jamais affichés ; commandes et chemins réservés à la ligne de commande)

## Base de données

Tables internes créées automatiquement : préfixe `AUT_` (comptes organisateurs) et `BK_`
(comptes licenciés, inscriptions, boutique, paiements).

## Accès

- Gestion des comptes et administration : **administrateur du serveur**.
- Chaque organisateur : uniquement ses compétitions (et celles qu'on lui a partagées).
- Chaque licencié : son propre espace d'inscription (connexion par son compte fédéral).

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md) pour le principe commun.

> **Module sensible** : il porte l'authentification du serveur. Son installation, sa mise à jour
> et son retrait doivent être réalisés par l'administrateur.
> Un retrait effectué sans précaution peut rendre le site inaccessible.

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
