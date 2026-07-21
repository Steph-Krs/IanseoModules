# Trophée National des Mixtes

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Gère une compétition par équipes mixtes sur une base **Qualifications → 2 tours de poules en
Round Robin → Big Shoot Off (BSO) final**.

## Fonctionnalités

- 📥 Aide à l'import des résultats de qualification
- ✅ Validation des places avec vue de la composition des poules
- 🧠 Aide à l'attribution des poules
  - Tour 1 : séparation des clubs d'un même département
  - Tour 2 : séparation des clubs issus des mêmes poules du Tour 1
  - Tirages aléatoires affichés pour les départages
- 🖨️ Impression des feuilles de match (avec bye), des tableaux de poules (avec/sans résultat,
  suivi du classement — 1 page par poule, adapté aux poules de 4), et des classements provisoires
  et définitifs (1 page par épreuve et par tour)
- ❌ Gestion des DNS/DNF + édition manuelle des valeurs de poule
- ⚖️ Big Shoot Off : configuration par épreuve, horaires, saisie des scores, vue commentateur

## Base de données

Deux tables créées automatiquement au premier accès : `TNM_BsoConfig` (configuration BSO par
épreuve) et `TNM_BsoVolee` (scores BSO par volée).

## Accès

Réservé aux organisateurs (`AclQualification` / `AclRobin`). Les actions d'écriture exigent le
niveau `ReadWrite`. La page de mise à jour est réservée à l'administrateur.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `TNM/` et `_shared/` dans
`Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu du module → **Mise à jour**.
