# Guide interactif

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Affiche un **panneau d'aide latéral persistant** sur toutes les pages de ianseo pour accompagner
les utilisateurs pas à pas dans les procédures de gestion de compétition. Pensé pour les clubs
qui débutent sur ianseo.

## Fonctionnalités

- 📖 Formations multi-étapes avec navigation Précédent / Suivant et barre de progression
- 🎯 Surbrillance des éléments de la page (overlay + flèche animée) et info-bulles contextuelles
- ⚡ Étapes réactives : détection d'événements (clic, saisie…) et vérification de conditions côté
  serveur (compétition ouverte, épreuve configurée…)
- 🧩 QCM et défis vérifiés en conditions réelles, distinctions (bronze / argent / or)
- 🧭 Checklists filtrées et FAQ interactive (arbre de décision)
- 💾 Suivi de progression enregistré et synchronisé, cloisonné par utilisateur
- ✏️ Éditeur intégré (admin) : création visuelle de formations, enregistreur de clics,
  import / export, aperçu en direct

## Base de données

Trois tables créées automatiquement au premier accès : `GUIDE_Progress` (progression),
`GUIDE_Visits` (pages visitées, pour les conditions) et `GUIDE_Prefs` (préférences par compte).
Les formations elles-mêmes sont des fichiers JSON (`content/`).

## Accès

- Voir le panneau et le catalogue : tous les utilisateurs connectés.
- Administration (créer / éditer les formations) : administrateur.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `GUIDE/` et `_shared/` dans
`Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu du module → **Administration** puis **Mise à jour**.
