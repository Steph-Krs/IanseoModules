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
