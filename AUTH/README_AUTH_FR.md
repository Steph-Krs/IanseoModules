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

Tables internes créées automatiquement : préfixe `Auth` (comptes organisateurs) et `Booking`
(comptes licenciés, inscriptions, boutique, paiements).

Jusqu'à la version 1.1.17, elles s'appelaient `AUT_*` et `BK_*`. Un serveur mis à jour depuis une
telle version bascule de lui-même, à la première page ouverte : chaque table est renommée sur
place (lignes, index et compteurs conservés), et une vue garde l'ancien nom, pour que les fichiers
déployés dans `Modules/Authentication` continuent de fonctionner jusqu'au prochain déploiement.
Si le compte MySQL de ianseo n'a pas le droit `CREATE VIEW`, les vues sont omises : redéployer
aussitôt après la mise à jour. `ianseo-restore` restaure aussi une copie prise avant le
renommage, et la migre avant de rouvrir le site.

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
