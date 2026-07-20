# Poules — vue commentateur

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Affichage **live** des enjeux d'une phase de poules Round Robin par équipes. Pensé pour le
commentateur qui anime la compétition en direct.

## Fonctionnalités

- 🏅 Classement en temps réel des poules
- 🔭 Fourchette de classement final **mathématiquement atteignable** par équipe (meilleur / pire
  cas), en tenant compte du calendrier réel des matchs restants, des départages et des matchs en
  cours
- 🎯 Statuts par équipe : qualifiée, en course, hors course, menacée / reléguée, 1re de poule
- ⚔️ Mise en avant des matchs décisifs du round en cours, triés par importance
- ⚙️ Réglages côté client (places qualificatives, relégation, rafraîchissement, alternance des
  épreuves) — aucune reconfiguration serveur

## Base de données

**Aucune table créée.** Module 100 % lecture seule : il lit uniquement les tables Round Robin
natives de ianseo.

## Accès

Réservé aux organisateurs (`AclRobin` / `ReadOnly`, comme une vue commentateur). La page de mise
à jour est réservée à l'administrateur. L'entrée n'apparaît que si la compétition ouverte contient
des poules Round Robin par équipes.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `POULES/` et `_shared/` dans
`Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu du module → **Mise à jour**.
