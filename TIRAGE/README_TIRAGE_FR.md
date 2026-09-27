# Tirage au sort en direct

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Les écrans d'un **tirage au sort en public** — par exemple l'ordre des équipes d'une division
nationale : un écran pour la salle ou la diffusion, une régie pour enregistrer chaque équipe tirée,
et un écran pour le micro et les commentateurs. Le tirage lui-même se fait à la main, dans la salle ;
le module l'accompagne. **Il n'est lié à aucune compétition** : il s'utilise sans compétition ouverte.

## Fonctionnalités

- 📺 **Écran public** plein écran (vidéoprojecteur, téléviseur, régie vidéo) : boucle d'attente sur
  fond animé, liste en cours de tirage avec révélation de chaque équipe au centre de l'écran,
  récapitulatif final de toutes les listes d'équipes. Couleurs, polices, tailles, marges, image de fond et
  voile réglables.
- 🗓️ **Manches de la saison** : une liste dédiée où rien n'est tiré — chaque manche (lieu, dates)
  apparaît sur sa propre carte, l'une après l'autre, au fil du discours du micro.
- 🎛️ **Régie** : un clic donne à l'équipe la place suivante de sa liste et l'affiche ; recherche au
  clavier (Entrée tire l'équipe quand une seule correspond), retrait d'une place, annulation de la
  dernière, réinitialisation, choix de la scène affichée et des colonnes d'historique, aperçu de
  l'écran public.
- 📋 **Liste prête à coller dans ianseo** : les codes clubs dans l'ordre tiré, à coller dans l'écran
  Setup de la compétition de D1 de la nouvelle saison (refusée tant qu'une équipe n'a pas de code
  club, pour ne décaler personne).
- 🎙️ **Écran des commentateurs** : l'équipe qui vient d'être tirée s'affiche d'elle-même, toute
  autre peut être consultée à l'avance, et un bouton **« Revenir au direct »** ramène à la dernière
  place attribuée. Historique (participations, victoires, podiums, classement
  N-1), note libre, et, si le tirage est relié à la compétition ianseo de la saison précédente :
  classement final, matchs gagnés/perdus, points par flèche, forme, résultats et qualification
  manche par manche. Les chiffres qui sont les trois meilleurs de la catégorie apparaissent en
  **or, argent ou bronze**. Archers de l'équipe avec leur **classement national** (si le module
  [Répartition des épreuves](../REPARTITION_EPREUVES/README_REPARTITION_EPREUVES.md) a téléchargé les
  classements).
- 🔗 **Synchronisation par le serveur** : écran public, régie et tablettes des commentateurs peuvent
  être sur des machines différentes ; rien n'est perdu si un navigateur se ferme.
- 📋 **Préparation** : import des équipes depuis les épreuves par équipes d'une compétition ianseo,
  liaison automatique des équipes à leur club, report en un clic de la saison N-1 dans l'historique,
  copie d'un tirage pour préparer celui de l'année suivante, import d'une sauvegarde de l'ancienne
  page HTML de tirage.

Les classements sont lus par les classes `Rank` du cœur de ianseo : pour la première division, ce
sont les classes du règlement français, les mêmes que pour les impressions.

## Accès

- Liste des tirages, préparation et régie : droit de créer une compétition (`AclRoot`, lecture-écriture).
- Écran public et écran des commentateurs : **lien secret, sans compte ianseo**. Deux liens distincts
  par tirage — seul celui des commentateurs montre les notes. Les liens peuvent être renouvelés.
- Mise à jour et désinstallation : administrateur.

## Base de données

Trois tables propres au module, créées à l'ouverture d'une de ses pages :

| Table | Contenu |
|---|---|
| `DrawShows` | un tirage : titres, compétition N-1, liens des écrans, scène affichée, apparence, image de fond |
| `DrawCategories` | une liste : équipes à tirer (reliée si besoin à une épreuve de la compétition N-1) ou manches |
| `DrawTeams` | une équipe ou une manche : place tirée, code club, dates, historique, note pour les commentateurs |

Le module **lit** les tables de ianseo (compétitions, équipes, poules, composition des équipes) et,
si elles existent, celles des classements nationaux du module Répartition des épreuves. Il
**n'écrit dans aucune table de ianseo**.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `TIRAGE/` et `_shared/` dans
`Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu **Modules → Tirage au sort → Mise à jour module**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `DrawShows` | `api/manage.php:182` | — |
| `UPDATE` | `DrawCategories` | `api/manage.php:213` | — |
| `UPDATE` | `DrawCategories` | `api/manage.php:230` | — |
| `DELETE FROM` | `DrawTeams` | `api/manage.php:238` | — |
| `DELETE FROM` | `DrawCategories` | `api/manage.php:239` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:240` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:283` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:298` | — |
| `DELETE FROM` | `DrawTeams` | `api/manage.php:307` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:364` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:389` | — |
| `UPDATE` | `DrawTeams` | `api/manage.php:398` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:402` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:413` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:421` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:428` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:461` | — |
| `UPDATE` | `DrawShows` | `api/manage.php:469` | — |
| `UPDATE` | `DrawCategories` | `lib/legacy.php:94` | — |
| `UPDATE` | `DrawShows` | `lib/legacy.php:154` | — |
| `UPDATE` | `DrawShows` | `lib/store.php:239` | — |
| `INSERT INTO` | `DrawShows` | `lib/store.php:273` | — |
| `DELETE FROM` | `DrawTeams` | `lib/store.php:286` | — |
| `DELETE FROM` | `DrawCategories` | `lib/store.php:287` | — |
| `DELETE FROM` | `DrawShows` | `lib/store.php:288` | — |
| `INSERT INTO` | `DrawShows` | `lib/store.php:304` | — |
| `INSERT INTO` | `DrawCategories` | `lib/store.php:313` | — |
| `INSERT INTO` | `DrawTeams` | `lib/store.php:316` | — |
| `INSERT INTO` | `DrawCategories` | `lib/store.php:337` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:371` | — |
| `INSERT INTO` | `DrawTeams` | `lib/store.php:413` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:542` | — |
| `UPDATE` | `DrawShows` | `lib/store.php:543` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:555` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:556` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:566` | — |
| `UPDATE` | `DrawTeams` | `lib/store.php:576` | — |

<!-- END DATABASE WRITES -->
