# Passerelle extranet FFTA

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Fait le pont entre ianseo et l'**extranet FFTA** : dépôt des résultats et création d'une
compétition depuis une épreuve du calendrier. (La mention FFTA est ici **fonctionnelle** : le
module dialogue réellement avec les services de la FFTA.)

## Fonctionnalités

- 📤 Dépôt des résultats d'une compétition sur l'extranet (fichier TXT), depuis le menu
  **Compétition › Exports**
- 🆕 Création d'une compétition ianseo **depuis une épreuve de l'extranet** (dates, catégories et
  paramètres pré-remplis), avec un filtre par discipline pour la recherche des épreuves
- 🕗 Pour chaque départ : heures d'**ouverture du greffe** et d'**inspection du matériel**
  (obligatoires, reportées dans le programme de la compétition), **parcours repiqueté ou identique**
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

**Aucune table créée.** Le module s'appuie sur des conventions de session pour dialoguer avec
l'extranet. À la création d'une compétition, il écrit dans les tables de ianseo, comme le ferait la
saisie manuelle : compétition, départs, et lignes du programme (`Scheduler`) pour l'ouverture du
greffe et l'inspection du matériel.

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
| `INSERT INTO` | `Scheduler` | `create-run.php:58` | — |
| `INSERT INTO` | `Tournament` | `create-run.php:157` | — |
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

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
