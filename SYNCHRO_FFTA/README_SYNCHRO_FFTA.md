# Passerelle extranet FFTA

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Fait le pont entre ianseo et l'**extranet FFTA** : dépôt des résultats et création d'une
compétition depuis une épreuve du calendrier. (La mention FFTA est ici **fonctionnelle** : le
module dialogue réellement avec les services de la FFTA.)

## Fonctionnalités

- 📤 Dépôt des résultats d'une compétition sur l'extranet (fichier TXT), depuis le menu
  **Compétition › Exports**
- 🆕 Création d'une compétition ianseo **depuis une épreuve du calendrier de l'extranet** (dates,
  catégories et paramètres pré-remplis), recherche par période et par discipline (formule comprise),
  épreuves avec duels signalées, **lieu précis pré-rempli** avec son adresse et ses coordonnées GPS
  (modifiable)
- 🕗 Pour chaque départ : heure d'**ouverture du greffe**, **inspection du matériel** pendant
  l'entraînement ou à son heure, **entraînement** compris dans le départ ou à son heure (reportés dans
  le programme de la compétition), **parcours repiqueté ou identique**
  (Campagne, 3D, Nature, à partir du 2ᵉ départ) et **temps de tir international** (TAE), reportés
  dans le commentaire du départ
- 🔐 Réutilise une session extranet déjà ouverte quand elle existe (sinon, formulaire de connexion
  en repli) — un minimum de saisies d'identifiants, pour le dépôt comme pour la création
- 🐢 Ménage les sites FFTA : requêtes espacées, réponses récentes réutilisées, pause affichée avec un
  compte à rebours quand le site demande de ralentir, message explicite quand il refuse l'accès

## Images des compétitions créées

À la création, le module remplit les trois images de la compétition (celles que ianseo affiche sur
les documents et sur les dossards) depuis le dossier `assets/` du module :

| Emplacement ianseo | Fichier | Contenu attendu |
|---|---|---|
| `ToLeft` — logo de gauche | `assets/ToLeft.*` | logo fédéral |
| `ToRight` — logo de droite | `assets/ToRight.*` | logo du club organisateur |
| `ToBottom` — bandeau du bas | `assets/ToBottom.*` | pied de page |

`.jpg`, `.jpeg` et `.png` sont acceptés indifféremment.

Le logo de droite est d'abord cherché **en ligne**, sur l'extranet, d'après le numéro d'agrément de
l'organisateur ; `assets/ToRight.*` ne sert que si cette recherche échoue (club sans logo, serveur
hors ligne…).

### Changer les images

- **Pour tous les serveurs** : remplacer les fichiers `assets/ToLeft.*`, `ToRight.*`, `ToBottom.*`
  sur le dépôt. Ils font partie de la mise à jour du module, donc chaque serveur les reçoit
  automatiquement à la mise à jour suivante.
- **Pour un seul serveur** : déposer un fichier suffixé **`-local`** à côté, par exemple
  `assets/ToRight-local.png` ou `assets/ToBottom-local.jpg`. Il est prioritaire sur l'image par
  défaut et **n'est jamais écrasé ni supprimé par une mise à jour** du module (il ne fait pas
  partie des fichiers livrés). Le retirer suffit à revenir à l'image par défaut.

> Le suffixe `-local` reprend la convention déjà utilisée par les autres modules pour ce qui est
> propre à un serveur et ne doit pas être écrasé (`config.local.json`).

## Base de données

À la création d'une compétition, le module écrit dans les tables de ianseo, comme le ferait la
saisie manuelle : compétition, départs, et lignes du programme (`Scheduler`) pour l'ouverture du
greffe et l'inspection du matériel.

### Table `FftaEvents` — le calendrier fédéral, pour tous les modules

Créée automatiquement à la première ouverture de la page de création. Une ligne par épreuve du
calendrier de l'extranet, mise à jour à chaque recherche ; le lieu s'y ajoute quand l'épreuve est
choisie. Les autres modules peuvent la lire (jamais l'écrire) :

| Colonne | Contenu |
|---|---|
| `FeId` | numéro de l'épreuve sur l'extranet (clé) |
| `FeCode` | code de la compétition ianseo correspondante (`Tournament.ToCode`) : `F` + saison + numéro |
| `FeSeason`, `FeName`, `FeDateFrom`, `FeDateTo` | saison, nom, dates |
| `FeOrgCode`, `FeOrgName` | organisateur (agrément, nom) |
| `FeState` | `A` validée, `R` reportée, `X` annulée |
| `FeDiscipline`, `FeFormat`, `FeChampionship` | discipline, formule, type d'épreuve tels que l'extranet les écrit |
| `FeValidePara`, `FeDistinction` | Valide + Para, distinction |
| `FeDuels` | `1` avec duels, `0` sans (dit par le détail de l'épreuve), **vide (NULL) quand l'extranet n'a rien dit** — à ne jamais lire comme « sans duel » |
| `FeCity` | ville affichée par le calendrier |
| `FeVenueName`, `FeVenueStreet`, `FeVenueZip`, `FeVenueCity`, `FeVenueCountry` | lieu précis (vide tant que l'extranet ne le connaît pas) |
| `FeLatitude`, `FeLongitude` | coordonnées GPS du lieu |
| `FeVenueOwn` | `1` : l'organisateur a remplacé le lieu proposé à la création |
| `FeSeenAt`, `FeDetailAt` | dernière lecture dans une recherche, dernière lecture du détail |

**Coordonnées GPS** : elles ne restent que si l'organisateur a gardé, à la création, le lieu proposé
par l'extranet. S'il l'a modifié, elles sont effacées et ne sont plus jamais réécrites depuis
l'extranet (`FeVenueOwn = 1`). Quand le lieu n'est pas encore défini sur l'extranet (« INCONNU »,
« A DEFINIR »), l'adresse affichée par l'extranet est celle de l'organisateur : elle n'est pas
reprise. Les coordonnées de contact de l'organisateur ne sont jamais enregistrées.

Jointure avec une compétition ianseo : `JOIN FftaEvents ON ToCode = FeCode COLLATE utf8mb4_unicode_ci`
(la collation se pose du côté de la colonne du module).

## Accès

- Dépôt des résultats : depuis une compétition ouverte, droit `Exports`.
- Création depuis l'extranet : hors compétition, là où « Nouveau » est disponible.
- Page de mise à jour : réservée à l'administrateur.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `SYNCHRO_FFTA/` et
`_shared/` dans `Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et
désinstallation depuis ianseo : menu **Modules › Synchro FFTA › Mise à jour**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `Tournament` | `create-finish.php:74` | review scope by hand |
| `UPDATE` | `Tournament` | `create-finish.php:79` | review scope by hand |
| `UPDATE` | `Tournament` | `create-finish.php:107` | review scope by hand |
| `UPDATE` | `IdCards` | `create-finish.php:151` | review scope by hand |
| `INSERT INTO` | `Scheduler` | `create-run.php:61` | — |
| `INSERT INTO` | `Tournament` | `create-run.php:184` | — |
| `DELETE FROM` | `LookUpEntries` | `licences-sync.php:147` | review scope by hand |
| `INSERT INTO` | `LookUpEntries` | `licences-sync.php:165` | — |
| `UPDATE` | `LookUpPaths` | `licences-sync.php:170` | review scope by hand |
| `DELETE FROM` | `LookUpEntries` | `licences-sync.php:222` | review scope by hand |
| `INSERT IGNORE INTO` | `LookUpEntries` | `licences-sync.php:234` | — |
| `INSERT INTO` | `LookUpPaths` | `licences-sync.php:271` | — |
| `UPDATE` | `Entries` | `licences-sync.php:284` | — |
| `UPDATE` | `Entries` | `licences-sync.php:296` | — |
| `UPDATE` | `Entries` | `licences-sync.php:306` | — |

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `INSERT INTO` | `FftaEvents` | `lib/events.php:80` | — |
| `UPDATE` | `FftaEvents` | `lib/events.php:113` | — |
| `UPDATE` | `FftaEvents` | `lib/events.php:163` | — |
| `UPDATE` | `FftaEvents` | `lib/events.php:165` | — |
| `ALTER TABLE` | `FftaEvents` | `lib/schema.php:104` | — |
| `UPDATE` | `FftaEvents` | `lib/schema.php:105` | — |

<!-- END DATABASE WRITES -->
