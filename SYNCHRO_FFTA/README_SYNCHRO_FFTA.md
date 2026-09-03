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
  paramètres pré-remplis)
- 🔐 Réutilise une session extranet déjà ouverte quand elle existe (sinon, formulaire de connexion
  en repli) — un minimum de saisies d'identifiants

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
l'extranet.

## Accès

- Dépôt des résultats : depuis une compétition ouverte, droit `Exports`.
- Création depuis l'extranet : hors compétition, là où « Nouveau » est disponible.
- Page de mise à jour : réservée à l'administrateur.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `SYNCHRO_FFTA/` et
`_shared/` dans `Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et
désinstallation depuis ianseo : menu **Modules › Synchro FFTA › Mise à jour**.
