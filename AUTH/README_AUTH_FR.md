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
- 🧾 Invitation, documents et feuilles de marque de la compétition consultables par les archers
- 🗳️ Questionnaire de satisfaction après la compétition (moins de 2 minutes, rien d'obligatoire) ;
  l'organisateur en voit les résultats anonymes, en graphiques simples, comparés aux autres compétitions

### Buvette & boutique — points de vente en ligne
Gestion complète des points de vente (buvette, restauration, boutique) d'une compétition, avec
commande par téléphone des archers et des visiteurs, et caisse mobile pour les bénévoles.

- 🏪 **Configuration et catalogue** : création de points de vente (mode direct ou file d'attente,
  paiement avant ou au retrait), produits avec variantes, stocks, limite par commande et par personne,
  précommandes jusqu'à une date fixée
- 🛒 **Commande** : sélection des produits, panier mémorisé par téléphone, suivi en direct du statut
  (« reçue » → « préparation » → « prête »), estimation du temps d'attente, annulation avant retrait ;
  commande ou précommande **pour un jour et une heure** (le repas de midi commandé le matin n'est pas
  préparé trop tôt)
- 🔔 **Notification sur le téléphone** quand la commande est prête, même page fermée (Android ;
  iPhone une fois la page ajoutée à l'écran d'accueil, iOS 16.4 ou plus ; site en HTTPS)
- 📺 **Écran public** comme en restauration rapide : commandes en attente (avec l'état du paiement),
  en préparation (avec le temps estimé), prêtes ; chaque colonne affiche son nombre de commandes, et
  chaque commande le prénom et l'initiale du nom, ou le pseudo, du client
- 👥 **Clients** : archers connectés à leur espace licencié, ou visiteurs à pseudo (enregistrement
  automatique à la première commande ; accès fermé le lendemain de la compétition, le pseudo reste
  sur les commandes pour savoir lesquelles ont été honorées)
- 🧾 **Caisse des bénévoles** : vente, encaissement, file des commandes classée par heure demandée,
  **temps d'attente propre à chaque commande** (saisi à l'encaissement, ajusté par le préparateur
  par boutons +2 / +5 / −2 / −5 min) ; adresse stable et affiche avec QR code pour revenir à la caisse
- 💰 **Paiements** : espèces, chèque, terminal bancaire, autre moyen défini par l'organisateur ;
  sur-compte pour les licenciés connectés (règlement à la fin de la compétition) ; aucune donnée
  bancaire n'est stockée sur le serveur
- 👔 **Bénévoles** : invitation par code QR avec validation et code de vérification à 4 chiffres
  pour l'organisateur, rôles préréglés (vendeur, préparateur, polyvalent, responsable de stand),
  plafonds de remboursement, droits donnés **par stand** pour une meilleure autonomie, révocation
  immédiate possible ; comptes non licenciés ouverts de la veille à la fin (effacés automatiquement
  le lendemain)
- 📊 **Bilans** : synthèse par stand, par jour, par moyen de paiement, par bénévole ; clôture de
  caisse PDF et exports CSV ; comptes ouverts (« sur compte ») lien avec la page Paiements
- 📋 **Copie** : reproduire les paramètres et le catalogue d'une compétition précédente ; stocks
  conservent la quantité initiale
- 🔐 **Indice de confiance des payeurs** : impayés et retards calculés chaque nuit sur les
  compétitions dont l'organisateur enregistre les paiements ; ne freine que le paiement différé
  (inscription payée plus tard, précommande, sur compte), jamais un paiement immédiat ; modes :
  désactivé, observation (administrateur seul, par défaut), alerte (organisateurs), blocage (crédit
  refusé à un archer ou un club rouge, sauf acceptation par l'organisateur) ; l'archer voit son niveau
- 🔁 **Reprise de la boutique des inscriptions en ligne** : articles, options, stocks et commandes
  passent automatiquement dans la buvette & boutique à la mise à jour, sans changer ce que doit
  chaque archer ; les anciens liens et QR codes mènent à la nouvelle boutique

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
- Avec le module SYNCHRO_FFTA, l'administrateur peut réserver la création des compétitions à
  l'extranet FFTA (menu **Création par l'extranet FFTA**) : « Nouveau » est alors masqué et
  renvoie vers SYNCHRO_FFTA pour tous sauf lui, et l'import n'accepte qu'une compétition déjà
  présente sur le serveur.

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
| `DELETE FROM` | `TournamentInvolved` | `anonymise-lib.php:249` | — |
| `UPDATE` | `Entries` | `anonymise-lib.php:374` | review scope by hand |
| `DELETE FROM` | `Photos` | `anonymise-lib.php:379` | — |
| `DELETE FROM` | `ExtraData` | `anonymise-lib.php:381` | — |
| `UPDATE` | `ExtraData` | `anonymise-lib.php:383` | — |
| `UPDATE` | `TournamentInvolved` | `anonymise-lib.php:388` | review scope by hand |
| `UPDATE` | `Entries` | `booking/lib/adopt.php:224` | review scope by hand |
| `UPDATE` | `IdCards` | `booking/lib/mandate.php:716` | review scope by hand |
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
| `UPDATE` | `BookingCompetitions` | `booking/admin/competition.php:128` | — |
| `INSERT INTO` | `BookingCompetitions` | `booking/admin/competition.php:132` | — |
| `UPDATE` | `BookingCompetitions` | `booking/admin/competition.php:215` | — |
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
| `INSERT INTO` | `BookingCompetitions` | `booking/lib/mandate.php:170` | — |
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
| `INSERT INTO` | `AuthClaim` | `lib.php:989` | — |
| `INSERT INTO` | `AuthShare` | `lib.php:1155` | — |
| `INSERT INTO` | `AuthShare` | `lib.php:1295` | — |
| `DELETE FROM` | `AuthClaim` | `lib.php:1301` | — |
| `DELETE FROM` | `AuthClaim` | `lib.php:1305` | — |
| `UPDATE` | `AuthSessions` | `lib.php:1366` | — |
| `UPDATE` | `AuthSessions` | `lib.php:1375` | — |
| `UPDATE` | `AuthUsers` | `lib.php:1489` | — |
| `UPDATE` | `AuthUsers` | `lib.php:1605` | — |
| `UPDATE` | `AuthUsers` | `lib.php:2418` | — |
| `INSERT INTO` | `AuthUsers` | `lib.php:2424` | — |
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
