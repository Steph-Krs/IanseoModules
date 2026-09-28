# Feuilles de marque par club ou archer

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion des compétitions de tir à l'arc.

Imprime les **feuilles de marque de la compétition ouverte, découpées en fichiers** : un PDF par
club, avec tous ses archers, ou un PDF par archer — téléchargés ensemble dans une archive ZIP.
Pensé pour les compétitions où chaque club, ou chaque archer, reçoit ses propres feuilles : un
challenge tiré dans les clubs par exemple, où une « cible » sert seulement à donner à un archer les
feuilles de ses différents niveaux.

L'impression des feuilles de marque de ianseo n'est pas modifiée : le module est une page à part.

## Fonctionnalités

- 📦 **Un fichier par club** (`<code club>.pdf`) **ou un par archer** (`<numéro de licence>.pdf`),
  dans une archive ZIP. L'archive se construit par petites étapes avec une barre de progression :
  une compétition de plusieurs milliers d'archers ne bute jamais sur une limite de temps du serveur.
- 📄 **Aucune page vide**, quel que soit le nombre d'emplacements par cible : une cible de huit
  emplacements qui porte trois archers imprime une page, pas deux. Sur une page imprimée, les
  emplacements libres restent des **grilles vierges**, qu'un club peut toujours utiliser.
- 📱 **QR codes des applications de saisie** (Ianseo ScoreKeeper), cochés par défaut : scanné sur
  une tablette, le code d'une page ouvre sa cible, dans son départ et à sa distance. Dessinés par
  les fonctions du cœur, à la place que leur donne le cœur — entre les deux rangées de feuilles —
  et sur chaque page, puisque la page d'un club ou d'un archer peut circuler seule. Le QR code
  personnel de demande de cible est proposé aussi quand la compétition utilise des appareils
  personnels.
- 🙈 **Numéro de cible masqué** au besoin, pour les compétitions où l'emplacement ne dit rien à
  l'archer.
- 🖼️ **En-tête de page sans son texte** au besoin (titre, organisateur, lieu, dates) : seules les
  images de la compétition restent, quand l'image d'en-tête dit déjà tout.
- 🏷️ **Le PDF d'un club** s'ouvre seul depuis la liste des clubs, pour le réimprimer ou le renvoyer
  sans reconstruire toute l'archive.
- ⚙️ **Les options de l'impression du cœur**, sous ses propres libellés : départs, distances,
  en-tête et pied de page, en-tête et logos de la compétition, drapeaux, informations de l'archer,
  code-barres (avec le module Barcodes ; décoché par défaut, ces feuilles étant saisies sur
  tablette).
- 📊 **Les comptes avant d'imprimer** : archers et feuilles par club et par départ, et un
  avertissement pour quiconque ne serait pas imprimé (archer sans cible, emplacement hors du plan
  du départ, emplacement donné deux fois).

Chaque fichier ne contient que les feuilles de son destinataire : un club ne reçoit jamais les
archers d'un autre club, et chaque feuille garde sur la page la place que lui donne l'impression de
ianseo. Dans le fichier d'un club, les feuilles suivent l'ordre des noms des archers, toutes les
feuilles d'un archer ensemble.

Les feuilles sont dessinées par la classe du cœur (`ScorePDF`), quatre par page comme dans
l'impression du cœur. Les images de la compétition sont intégrées à leur taille d'impression, en
300 dpi, pour que chaque fichier reste léger : environ 100 Ko pour le fichier d'un archer avec
l'en-tête de page, au lieu de 300 Ko.

## Accès

- Tout utilisateur qui peut imprimer les feuilles de marque de la compétition ouverte (lecture sur
  les qualifications, comme pour l'impression du cœur). L'entrée de menu du module apparaît quand
  une compétition est ouverte.
- Mise à jour et désinstallation : administrateur.

## Base de données

Le module **ne crée aucune table** et **n'écrit rien** en base. Il lit la compétition ouverte :
inscriptions, placements de qualification, départs, clubs, distances et blasons.

Les fichiers d'une archive sont construits dans un dossier de travail du répertoire temporaire du
serveur (jamais sous `Modules/Custom/`, que le serveur web peut servir), appartiennent à la session
qui les a demandés, et sont supprimés une fois l'archive téléchargée — ou au bout d'un jour si le
téléchargement n'a pas eu lieu.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En bref : copier les dossiers `SCORECARD_SPLITTER/` et
`_shared/` dans `Modules/Custom/` (ou lancer `install.sh` / `install.ps1`). Mises à jour et
désinstallation depuis ianseo : menu **Modules → Feuilles de marque par club ou archer → Mise à jour
module**, avec une compétition ouverte.

L'archive ZIP demande l'extension `zip` de PHP, présente sur la plupart des installations ; sans
elle, le PDF de chaque club reste disponible depuis la liste.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
