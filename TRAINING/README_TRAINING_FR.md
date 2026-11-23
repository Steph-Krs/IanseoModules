# Entraînement

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion des compétitions de tir à l'arc.

**Des entraînements en quelques clics**, pour les clubs et les centres d'entraînement qui mettent
souvent les mêmes archers en situation de compétition. Une personne qui connaît ianseo prépare
quelques **formats de référence**, même complexes ; ensuite un entraîneur sans compétence
particulière sur ianseo crée un entraînement en choisissant un format, une date et les archers,
imprime les feuilles de marque, saisit les scores et passe d'une phase à l'autre depuis une seule
page.

Un entraînement est une **vraie compétition ianseo** : résultats, tableaux et impressions sont ceux
du cœur, et les archers ont l'impression d'une vraie compétition.

## Fonctionnalités

- 📋 **Formats de référence** : la compétition ouverte est enregistrée comme format — divisions et
  classes, épreuves, blasons, distances, images, paramètres de la compétition et programme, sans
  ses archers, équipes, scores ni ses codes ianseo.net. La compétition d'origine peut ensuite être
  supprimée. Un format enregistré avant une mise à jour d'ianseo est remis à niveau quand il sert.
- 🗓️ **Création** : un format, une date et une heure de départ. Tous les horaires du programme
  (départs, entraînement, matchs, jours suivants) suivent l'heure de départ en **gardant les écarts
  du format**. Le code de la compétition est la date suivie d'un numéro : `AAMMJJXX` (`26120501`).
- 🏹 **Archers habituels** : une liste commune à l'installation, remplie à la main, depuis le fichier
  des licences déjà chargé dans ianseo, ou depuis les archers d'une compétition ouverte ; des
  **groupes** cochent plusieurs archers d'un coup. La division est l'arc classique sauf si une autre
  est enregistrée, et la classe est celle que permet la date de naissance : recalculée pour la date
  de chaque entraînement, avec la règle française qui compte une compétition de septembre à
  décembre dans la saison suivante (les bornes d'âge des classes de l'entraînement suivent la saison
  de sa date, quelle que soit celle de la compétition d'origine du format). La catégorie de chaque
  archer reste modifiable à la main, parmi celles que le format autorise.
- 👥 **Équipes et équipes mixtes composées par l'entraîneur** : « Équipe 1, Équipe 2… » avec les
  archers choisis et un nom libre, autant que nécessaire ; les équipes mixtes se composent à part,
  un archer peut donc être dans une équipe et dans une équipe mixte différente. Elles se modifient à
  tout moment — avant, pendant ou après les qualifications.
- 🎯 **Cibles** : placement automatique dans l'ordre du module AUTH — distance, blason (le plus grand
  d'abord), arme (arc nu, classique, poulies), âge, sexe — avec les seules règles physiques : une
  distance par cible, en salle une famille de blasons par demi-butte (A C / B D) et le 80 cm seul,
  en extérieur une famille par cible (réduits ensemble, blason plein partagé). Les archers identiques
  sont répartis régulièrement sur les cibles, et sur chaque cible entre les lettres. Pas de règle de
  club : c'est un entraînement. Déplacements à la main sur le plan des cibles visuel du cœur. Le
  nombre d'archers par cible se change d'un bouton (A, AB, ABC, AB-CD), et le nombre de cibles
  s'ajuste d'un autre à ce dont les archers ont besoin ; les cibles ne sont jamais ajoutées toutes
  seules — quand il en manque, le tableau de bord dit combien il en faudrait.
- 📱 **ISK-Lite par défaut** : chaque entraînement est configuré pour la saisie avec l'application
  ISK gratuite ; les feuilles de marque portent ses QR codes et les scores arrivent seuls dans
  ianseo. L'adresse du serveur donnée aux téléphones se règle une fois, sur la page des formats —
  elle n'apparaît jamais aux entraîneurs, qui ont seulement la page du cœur qui verrouille les
  modifications des archers par départ.
- 🥇 **Classement et duels** : une liste par épreuve avec duels, dans l'ordre des qualifications, les
  ex-aequo côte à côte ; l'entraîneur fait glisser les noms dans l'ordre du tableau et le remplit.
  Les tableaux sont remplis par les fonctions du cœur (classes de classement, grilles du règlement,
  exempts).
- ⚔️ **Duels seuls** : un entraînement peut simuler ses qualifications — chaque archer reçoit un
  point, et l'ordre des tableaux est entièrement au choix de l'entraîneur.
- 🧭 **Tableau de bord de l'entraîneur** : archers, cibles, feuilles de marque, scores, duels,
  résultats, dans l'ordre où ils servent, chacun un bouton ou un lien vers l'écran du cœur qui le
  fait.
- 🔁 **Import / export** : formats, archers habituels et groupes dans un seul fichier, pour
  configurer de la même façon une autre installation — un autre centre d'entraînement. À l'import,
  un archer déjà présent (même licence, sinon même nom et même date de naissance) est mis à jour,
  les groupes sont reconnus par leur nom, et un format enregistré par un ianseo plus récent est
  refusé.
- 🧹 **Menu simplifié** : sur un entraînement créé depuis un format qui a cette option, le menu
  d'ianseo ne garde que la compétition et les écrans de l'entraînement. Le choix appartient à chaque
  **navigateur** : la personne qui prépare les formats passe son navigateur en menu complet, les
  entraîneurs gardent le menu simplifié.

Non pris en charge par le module (écrans du cœur) : épreuves avec éliminatoires, poules ou
round robin.

## Accès

- Formats, archers habituels, création d'un entraînement : le droit de créer une compétition (sur
  une installation locale, tout le monde).
- Tableau de bord, archers d'un entraînement, équipes, classement et duels : le droit de saisir les
  scores des qualifications dans la compétition ouverte.
- Mise à jour et désinstallation : administrateur.

## Base de données

Cinq tables propres, créées à la première ouverture d'une page du module : `TrainingTemplates`
(formats), `TrainingArchers`, `TrainingGroups`, `TrainingGroupMembers` (archers habituels et leurs
groupes), `TrainingRuns` (les compétitions créées par le module). Les compétitions elles-mêmes sont
des compétitions ianseo ordinaires, écrites par l'import du cœur. L'adresse du serveur pour
ISK-Lite est une ligne de la table `Parameters` du cœur (`TrnIskUrl`).

## Installation, mise à jour, désinstallation

Voir le [README principal](../README_FR.md). En bref : copier les dossiers `TRAINING/` et `_shared/`
dans `Modules/Custom/` (ou lancer `install.sh` / `install.ps1`). Mises à jour et désinstallation
depuis ianseo : menu **Modules → Entraînement → Mise à jour module**. La désinstallation laisse dans
ianseo les entraînements déjà créés, comme des compétitions ordinaires.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

| Statement | Table | Location | Notes |
|---|---|---|---|
| `DELETE FROM` | `Finals` | `lib/duels.php:147` | review scope by hand |
| `UPDATE` | `Finals` | `lib/duels.php:162` | review scope by hand |
| `UPDATE` | `Events` | `lib/duels.php:169` | — |
| `DELETE FROM` | `TeamFinals` | `lib/duels.php:203` | review scope by hand |
| `DELETE FROM` | `TeamFinComponent` | `lib/duels.php:204` | review scope by hand |
| `UPDATE` | `TeamFinals` | `lib/duels.php:219` | review scope by hand |
| `INSERT INTO` | `TeamFinComponent` | `lib/duels.php:226` | — |
| `UPDATE` | `Events` | `lib/duels.php:234` | — |
| `INSERT INTO` | `Countries` | `lib/entries.php:93` | — |
| `INSERT INTO` | `Entries` | `lib/entries.php:133` | — |
| `INSERT INTO` | `Qualifications` | `lib/entries.php:152` | — |
| `UPDATE` | `Entries` | `lib/entries.php:174` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/entries.php:258` | — |
| `UPDATE` | `Session` | `lib/entries.php:341` | review scope by hand |
| `UPDATE` | `Entries` | `lib/entries.php:343` | review scope by hand |
| `UPDATE` | `Session` | `lib/entries.php:360` | review scope by hand |
| `UPDATE` | `Entries` | `lib/entries.php:362` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/entries.php:398` | — |
| `UPDATE` | `Qualifications` | `lib/entries.php:412` | — |
| `UPDATE` | `Entries` | `lib/teams.php:73` | — |
| `UPDATE` | `Countries` | `lib/teams.php:92` | review scope by hand |
| `INSERT INTO` | `Countries` | `lib/teams.php:98` | — |
| `UPDATE` | `Entries` | `lib/teams.php:104` | review scope by hand |
| `DELETE FROM` | `Countries` | `lib/teams.php:109` | review scope by hand |
| `UPDATE` | `Events` | `lib/teams.php:113` | — |

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `TrainingArchers` | `lib/archers.php:70` | — |
| `INSERT INTO` | `TrainingArchers` | `lib/archers.php:73` | — |
| `DELETE FROM` | `TrainingGroupMembers` | `lib/archers.php:84` | — |
| `DELETE FROM` | `TrainingArchers` | `lib/archers.php:85` | — |
| `DELETE FROM` | `TrainingGroupMembers` | `lib/archers.php:96` | — |
| `INSERT IGNORE INTO` | `TrainingGroupMembers` | `lib/archers.php:99` | — |
| `UPDATE` | `TrainingGroups` | `lib/archers.php:126` | — |
| `INSERT INTO` | `TrainingGroups` | `lib/archers.php:128` | — |
| `DELETE FROM` | `TrainingGroupMembers` | `lib/archers.php:139` | — |
| `DELETE FROM` | `TrainingGroups` | `lib/archers.php:140` | — |
| `UPDATE` | `TrainingTemplates` | `lib/template.php:394` | — |
| `INSERT INTO` | `TrainingTemplates` | `lib/template.php:397` | — |
| `UPDATE` | `TrainingTemplates` | `lib/template.php:417` | — |
| `INSERT INTO` | `TrainingRuns` | `lib/template.php:483` | — |
| `UPDATE` | `TrainingTemplates` | `lib/transfer.php:87` | — |
| `INSERT INTO` | `TrainingTemplates` | `lib/transfer.php:90` | — |
| `INSERT INTO` | `TrainingGroups` | `lib/transfer.php:102` | — |
| `UPDATE` | `TrainingTemplates` | `templates.php:41` | — |
| `DELETE FROM` | `TrainingTemplates` | `templates.php:49` | — |

<!-- END DATABASE WRITES -->
