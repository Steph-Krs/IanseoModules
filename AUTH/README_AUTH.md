# Multi-comptes

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Transforme une installation ianseo hébergée en ligne en **serveur multi-organisateurs**
**avec inscriptions en ligne** : chaque structure dispose de son compte et ne voit / ne modifie
que ses propres compétitions (partage possible pour l'entraide à la saisie), et **les licenciés
s'inscrivent eux-mêmes** aux compétitions ouvertes depuis leur propre espace.

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
| `UPDATE` | `Entries` | `booking/lib/adopt.php:225` | review scope by hand |
| `UPDATE` | `IdCards` | `booking/lib/mandate.php:592` | review scope by hand |
| `INSERT INTO` | `Countries` | `booking/lib/registration.php:305` | — |
| `UPDATE` | `Countries` | `booking/lib/registration.php:309` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/registration.php:366` | review scope by hand |
| `INSERT INTO` | `Entries` | `booking/lib/registration.php:492` | — |
| `UPDATE` | `Entries` | `booking/lib/registration.php:502` | review scope by hand |
| `INSERT INTO` | `Qualifications` | `booking/lib/registration.php:507` | — |
| `UPDATE` | `Qualifications` | `booking/lib/registration.php:508` | UNBOUNDED — must join Entries; review scope by hand |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:299` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:438` | — |
| `UPDATE` | `Qualifications` | `booking/lib/targets.php:530` | — |
| `DELETE FROM` | `LookUpEntries` | `cron/sync-licences.php:130` | review scope by hand |
| `INSERT INTO` | `LookUpEntries` | `cron/sync-licences.php:147` | — |
| `UPDATE` | `LookUpPaths` | `cron/sync-licences.php:151` | review scope by hand |
| `DELETE FROM` | `LookUpEntries` | `cron/sync-licences.php:178` | review scope by hand |
| `INSERT IGNORE INTO` | `LookUpEntries` | `cron/sync-licences.php:189` | — |
| `INSERT INTO` | `LookUpPaths` | `cron/sync-licences.php:225` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:235` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:247` | — |
| `UPDATE` | `Entries` | `cron/sync-licences.php:257` | — |
| `INSERT INTO` | `Flags` | `logos-lib.php:264` | — |
| `INSERT INTO` | `Flags` | `logos-lib.php:310` | — |

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `INSERT INTO` | `AUT_Users` | `admin/index.php:101` | — |
| `UPDATE` | `AUT_Users` | `admin/index.php:124` | — |
| `UPDATE` | `AUT_Users` | `admin/index.php:141` | — |
| `UPDATE` | `AUT_Users` | `admin/index.php:152` | — |
| `DELETE FROM` | `AUT_Users` | `admin/index.php:176` | — |
| `UPDATE` | `BK_Archers` | `admin/index.php:192` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:193` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:197` | — |
| `UPDATE` | `BK_Archers` | `admin/index.php:201` | — |
| `UPDATE` | `BK_Archers` | `admin/index.php:207` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:208` | — |
| `DELETE FROM` | `BK_Sessions` | `admin/index.php:212` | — |
| `DELETE FROM` | `BK_Archers` | `admin/index.php:213` | — |
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
| `UPDATE` | `BK_Competitions` | `booking/admin/competition.php:122` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/admin/competition.php:126` | — |
| `UPDATE` | `BK_Competitions` | `booking/admin/competition.php:202` | — |
| `UPDATE` | `BK_Competitions` | `booking/admin/mandate.php:38` | — |
| `INSERT INTO` | `BK_ReimportConflicts` | `booking/lib/adopt.php:106` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:174` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:178` | — |
| `UPDATE` | `BK_ReimportConflicts` | `booking/lib/adopt.php:189` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:244` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:278` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:299` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/adopt.php:317` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/adopt.php:409` | — |
| `UPDATE` | `BK_TargetCaps` | `booking/lib/adopt.php:410` | — |
| `UPDATE` | `BK_ShopItems` | `booking/lib/adopt.php:411` | — |
| `UPDATE` | `BK_ShopOrders` | `booking/lib/adopt.php:412` | — |
| `UPDATE` | `BK_Payments` | `booking/lib/adopt.php:413` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:414` | — |
| `UPDATE` | `BK_Surveys` | `booking/lib/adopt.php:415` | — |
| `UPDATE` | `BK_SurveyVoters` | `booking/lib/adopt.php:416` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/adopt.php:419` | — |
| `UPDATE` | `BK_Refunds` | `booking/lib/adopt.php:420` | — |
| `UPDATE` | `BK_Ledger` | `booking/lib/adopt.php:423` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/adopt.php:485` | — |
| `INSERT INTO` | `BK_Registrations` | `booking/lib/adopt.php:539` | — |
| `INSERT INTO` | `BK_Log` | `booking/lib/archer.php:37` | — |
| `UPDATE` | `BK_Archers` | `booking/lib/archer.php:149` | — |
| `INSERT INTO` | `BK_Archers` | `booking/lib/archer.php:153` | — |
| `INSERT INTO` | `BK_Sessions` | `booking/lib/archer.php:166` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:170` | — |
| `UPDATE` | `BK_Archers` | `booking/lib/archer.php:172` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:185` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:245` | — |
| `UPDATE` | `BK_Sessions` | `booking/lib/archer.php:250` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/lib/archer.php:262` | — |
| `DELETE FROM` | `BK_TargetCaps` | `booking/lib/caps.php:212` | — |
| `INSERT INTO` | `BK_TargetCaps` | `booking/lib/caps.php:217` | — |
| `DELETE FROM` | `BK_TargetCaps` | `booking/lib/caps.php:225` | — |
| `INSERT IGNORE INTO` | `BK_Competitions` | `booking/lib/competition.php:229` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:238` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/competition.php:274` | — |
| `DELETE FROM` | `BK_ShopItems` | `booking/lib/competition.php:276` | — |
| `INSERT INTO` | `BK_ShopItems` | `booking/lib/competition.php:278` | — |
| `INSERT INTO` | `BK_ShopVariants` | `booking/lib/competition.php:284` | — |
| `DELETE FROM` | `BK_TargetCaps` | `booking/lib/competition.php:310` | — |
| `INSERT INTO` | `BK_TargetCaps` | `booking/lib/competition.php:318` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/competition.php:416` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:445` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/competition.php:468` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:471` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:476` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:513` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/competition.php:532` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:541` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:545` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:549` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:551` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/competition.php:553` | — |
| `UPDATE` | `BK_Archers` | `booking/lib/ffta.php:118` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/geo.php:91` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/geo.php:95` | — |
| `INSERT INTO` | `BK_Competitions` | `booking/lib/mandate.php:166` | — |
| `INSERT INTO` | `BK_Payments` | `booking/lib/payment.php:104` | — |
| `UPDATE` | `BK_Payments` | `booking/lib/payment.php:238` | — |
| `INSERT IGNORE INTO` | `BK_Competitions` | `booking/lib/payment.php:388` | — |
| `INSERT INTO` | `BK_Ledger` | `booking/lib/payment.php:392` | — |
| `INSERT INTO` | `BK_Ledger` | `booking/lib/payment.php:418` | — |
| `UPDATE` | `BK_Ledger` | `booking/lib/payment.php:429` | — |
| `INSERT INTO` | `BK_Refunds` | `booking/lib/payment.php:491` | — |
| `UPDATE` | `BK_Refunds` | `booking/lib/payment.php:519` | — |
| `INSERT INTO` | `BK_Registrations` | `booking/lib/registration.php:548` | — |
| `DELETE FROM` | `BK_Registrations` | `booking/lib/registration.php:634` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/schema.php:198` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/schema.php:210` | — |
| `ALTER TABLE` | `BK_Competitions` | `booking/lib/schema.php:214` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/schema.php:269` | — |
| `UPDATE` | `BK_TargetCaps` | `booking/lib/schema.php:307` | — |
| `INSERT IGNORE INTO` | `BK_SurveyVoters` | `booking/lib/schema.php:452` | — |
| `UPDATE` | `BK_Competitions` | `booking/lib/schema.php:541` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/shop.php:163` | — |
| `INSERT INTO` | `BK_ShopOrders` | `booking/lib/shop.php:166` | — |
| `UPDATE` | `BK_ShopItems` | `booking/lib/shop.php:232` | — |
| `INSERT INTO` | `BK_ShopItems` | `booking/lib/shop.php:235` | — |
| `DELETE FROM` | `BK_ShopVariants` | `booking/lib/shop.php:244` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/shop.php:245` | — |
| `DELETE FROM` | `BK_ShopItems` | `booking/lib/shop.php:246` | — |
| `UPDATE` | `BK_ShopVariants` | `booking/lib/shop.php:258` | — |
| `INSERT INTO` | `BK_ShopVariants` | `booking/lib/shop.php:261` | — |
| `DELETE FROM` | `BK_ShopOrders` | `booking/lib/shop.php:269` | — |
| `DELETE FROM` | `BK_ShopVariants` | `booking/lib/shop.php:270` | — |
| `DELETE FROM` | `BK_Surveys` | `booking/lib/survey.php:188` | — |
| `DELETE FROM` | `BK_SurveyVoters` | `booking/lib/survey.php:189` | — |
| `INSERT INTO` | `BK_Surveys` | `booking/lib/survey.php:196` | — |
| `INSERT IGNORE INTO` | `BK_SurveyVoters` | `booking/lib/survey.php:199` | — |
| `UPDATE` | `BK_Surveys` | `booking/lib/survey.php:278` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/targets.php:506` | — |
| `UPDATE` | `BK_Registrations` | `booking/lib/targets.php:521` | — |
| `INSERT INTO` | `BK_Waitlist` | `booking/lib/waitlist.php:104` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:126` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:205` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:293` | — |
| `DELETE FROM` | `BK_Waitlist` | `booking/lib/waitlist.php:300` | — |
| `DELETE FROM` | `BK_Waitlist` | `booking/lib/waitlist.php:321` | — |
| `UPDATE` | `BK_Waitlist` | `booking/lib/waitlist.php:354` | — |
| `UPDATE` | `BK_Archers` | `booking/public/licence.php:27` | — |
| `UPDATE` | `BK_Archers` | `booking/public/security.php:35` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/public/security.php:36` | — |
| `UPDATE` | `BK_Archers` | `booking/public/security.php:55` | — |
| `DELETE FROM` | `BK_Sessions` | `booking/public/security.php:57` | — |
| `INSERT INTO` | `AUT_Share` | `index.php:42` | — |
| `DELETE FROM` | `AUT_ShareClub` | `index.php:52` | — |
| `INSERT IGNORE INTO` | `AUT_ShareClub` | `index.php:54` | — |
| `UPDATE` | `AUT_Share` | `index.php:63` | — |
| `ALTER TABLE` | `AUT_Users` | `legal-lib.php:334` | — |
| `UPDATE` | `AUT_Users` | `legal-lib.php:352` | — |
| `UPDATE` | `BK_Archers` | `legal-lib.php:368` | — |
| `ALTER TABLE` | `AUT_Users` | `lib.php:179` | — |
| `ALTER TABLE` | `AUT_Users` | `lib.php:187` | — |
| `ALTER TABLE` | `AUT_Share` | `lib.php:208` | — |
| `ALTER TABLE` | `AUT_Share` | `lib.php:215` | — |
| `UPDATE` | `AUT_Share` | `lib.php:217` | — |
| `ALTER TABLE` | `AUT_Claim` | `lib.php:242` | — |
| `ALTER TABLE` | `AUT_Sessions` | `lib.php:273` | — |
| `ALTER TABLE` | `AUT_Sessions` | `lib.php:282` | — |
| `ALTER TABLE` | `AUT_Tickets` | `lib.php:313` | — |
| `ALTER TABLE` | `AUT_Tickets` | `lib.php:323` | — |
| `INSERT INTO` | `AUT_Log` | `lib.php:343` | — |
| `DELETE FROM` | `AUT_Log` | `lib.php:376` | — |
| `DELETE FROM` | `BK_Log` | `lib.php:379` | — |
| `INSERT INTO` | `AUT_Tickets` | `lib.php:451` | — |
| `UPDATE` | `AUT_Tickets` | `lib.php:492` | — |
| `UPDATE` | `AUT_Tickets` | `lib.php:514` | — |
| `UPDATE` | `AUT_Tickets` | `lib.php:529` | — |
| `DELETE FROM` | `AUT_Tickets` | `lib.php:535` | — |
| `INSERT INTO` | `AUT_Sessions` | `lib.php:765` | — |
| `DELETE FROM` | `AUT_Sessions` | `lib.php:770` | — |
| `DELETE FROM` | `AUT_Sessions` | `lib.php:795` | — |
| `UPDATE` | `AUT_Sessions` | `lib.php:799` | — |
| `DELETE FROM` | `AUT_Sessions` | `lib.php:808` | — |
| `DELETE FROM` | `AUT_Share` | `lib.php:892` | — |
| `DELETE FROM` | `AUT_ShareClub` | `lib.php:893` | — |
| `INSERT INTO` | `AUT_Claim` | `lib.php:930` | — |
| `INSERT INTO` | `AUT_Share` | `lib.php:1100` | — |
| `INSERT INTO` | `AUT_Share` | `lib.php:1188` | — |
| `DELETE FROM` | `AUT_Claim` | `lib.php:1194` | — |
| `DELETE FROM` | `AUT_Claim` | `lib.php:1198` | — |
| `UPDATE` | `AUT_Sessions` | `lib.php:1259` | — |
| `UPDATE` | `AUT_Sessions` | `lib.php:1268` | — |
| `UPDATE` | `AUT_Users` | `lib.php:1382` | — |
| `UPDATE` | `AUT_Users` | `lib.php:1497` | — |
| `UPDATE` | `AUT_Users` | `lib.php:2326` | — |
| `INSERT INTO` | `AUT_Users` | `lib.php:2334` | — |
| `UPDATE` | `BK_Archers` | `login.php:93` | — |
| `INSERT INTO` | `AUT_ClubLogos` | `logos-lib.php:155` | — |
| `UPDATE` | `AUT_ClubLogos` | `logos-lib.php:163` | — |
| `UPDATE` | `AUT_ClubLogos` | `logos-lib.php:174` | — |
| `ALTER TABLE` | `AUT_Usage` | `stats-usage.php:128` | — |
| `ALTER TABLE` | `AUT_UsageSeen` | `stats-usage.php:132` | — |
| `INSERT INTO` | `AUT_Usage` | `stats-usage.php:238` | — |
| `INSERT IGNORE INTO` | `AUT_UsageSeen` | `stats-usage.php:244` | — |
| `DELETE FROM` | `AUT_UsageSeen` | `stats-usage.php:269` | — |
| `DELETE FROM` | `AUT_Usage` | `stats-usage.php:270` | — |
| `UPDATE` | `AUT_Sessions` | `switch-view.php:25` | — |
| `UPDATE` | `AUT_Users` | `switch-view.php:28` | — |

<!-- END DATABASE WRITES -->
