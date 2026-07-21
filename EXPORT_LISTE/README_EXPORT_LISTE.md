# Export liste — export des participants au format d'import

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Exporte la liste des participants de la compétition ouverte dans un **CSV** dont les **10 premières
colonnes** reprennent exactement le format attendu par l'**import par liste** (Participants →
Chargement de liste), suivies de deux colonnes : **N° d'agrément** (code club) et **nom du club**.

## Fonctionnalités

- 📄 Fichier CSV (séparateur `;`) directement réimportable dans ianseo (10 premières colonnes)
- 🏹 Colonnes : licence, départ, division, classe, cible, 4 participations + double mixte
- 🏛️ Deux colonnes ajoutées en fin : **N° d'agrément** et **nom du club**
- 🎯 Filtre par départ (session) ou export de tous les départs
- ⚙️ Options : ligne d'en-tête facultative, marqueur UTF-8 (BOM) pour un affichage propre des
  accents dans Excel

## Base de données

**Aucune table créée.** Module 100 % lecture seule : il lit les tables natives `Entries`,
`Qualifications` et `Countries`.

## Accès

Réservé aux organisateurs (`AclParticipants` / `pEntries` / `ReadOnly`). La page de mise à jour est
réservée à l'administrateur. L'entrée n'apparaît que si une compétition est ouverte.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `EXPORT_LISTE/` et `_shared/`
dans `Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu du module → **Mise à jour**.
