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
- 👻 **Concurrents fictifs** : des adversaires sans archer derrière, chacun à un niveau : un score
  final de qualification plus ou moins un écart, une moyenne par flèche plus ou moins un écart pour
  les duels en sets, un score de duel plus ou moins un écart pour les duels en points. Un niveau
  au-delà de ce que permettent le blason et les flèches est refusé. Ils participent aux
  classements, aux équipes et aux tableaux, sans cible ni feuille de marque.
- ⏱️ **Page Direct** : tant qu'elle est ouverte, les concurrents simulés tirent. Leurs flèches de
  qualification apparaissent au rythme de l'archer de l'entraînement qui en a tiré le plus, pour que
  le classement bouge pendant l'entraînement ; en duel, ils montrent autant de flèches que leur
  adversaire réel en a saisi, et jouent leurs propres matchs au rythme des vrais matchs de la même
  phase. Les flèches sont écrites par les fonctions du cœur, qui totalisent les matchs et font
  passer les vainqueurs. Le tableau de bord rappelle à l'entraîneur de laisser la page ouverte.
  Chaque écran choisit les épreuves qu'il affiche.
- ⏭️ **Tour suivant sans clic** : dans tous les entraînements, le vainqueur de chaque match décidé
  passe au tour suivant (ianseo le laisse à ses écrans de saisie, ISK-Lite ne le fait jamais), et
  les exempts des tours suivants sont donnés. Une page **Barrages à saisir** dans le menu de
  l'entraîneur liste les matchs qui attendent un barrage : saisir les flèches et, si elles sont
  égales, cocher l'archer le plus proche du centre.
- 🌍 **Entraînement depuis une compétition World Archery**, seconde façon de « Créer un
  entraînement » : trouver une compétition dont World Archery publie les résultats (période, pays,
  discipline, niveau, nom), choisir le jour et cocher les archers de l'entraînement :
  l'entraînement est créé avec les épreuves et les formats de match de la vraie compétition et tous
  ses athlètes et équipes avec leurs vrais résultats ; les archers de l'entraînement s'intercalent
  dans le vrai plateau. Qualification : le vrai score, les 10 et les X de chaque athlète, affichés
  au rythme de l'entraînement. Duels, la réalité d'abord : deux athlètes qui se sont vraiment
  rencontrés tirent ce match ; sinon un athlète tire ses propres flèches de cette phase, ou, au-delà
  du tour qu'il a atteint, celles de celui qui l'a éliminé (et ainsi de suite en remontant le
  tableau). Cible extérieure, salle, campagne et 3D, épreuves individuelles et par équipes.
- 📈 **Suivi des archers** : tous les tirs d'un archer sur le serveur, entraînements et vraies
  compétitions, regroupés par situation (type, format, distances, blason) avec la moyenne, la moyenne
  par flèche, le meilleur et le dernier score et les duels gagnés et perdus ; un graphique de la
  moyenne par flèche dans le temps ; des exports CSV (un archer, un entraînement, tous les
  entraînements). Les résultats des entraînements sont conservés par le module, ils restent une fois
  la compétition supprimée d'ianseo.
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

Neuf tables propres, créées à la première ouverture d'une page du module : `TrainingTemplates`
(formats), `TrainingArchers`, `TrainingGroups`, `TrainingGroupMembers` (archers habituels et leurs
groupes), `TrainingRuns` (les compétitions créées par le module), `TrainingResults` (résultats des
entraînements, conservés pour le suivi), `TrainingGhosts` (concurrents simulés et leurs niveaux),
`TrainingSimDuels` (flèches tirées pour eux en duel), `TrainingWaData` (résultats de la compétition
World Archery d'un entraînement). Créer un entraînement depuis World Archery lit son API publique
(`api.worldarchery.org`) : le serveur a besoin d'internet pour cela, pas ensuite. Les compétitions elles-mêmes sont
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
| `INSERT INTO` | `Countries` | `lib/entries.php:98` | — |
| `INSERT INTO` | `Entries` | `lib/entries.php:138` | — |
| `INSERT INTO` | `Qualifications` | `lib/entries.php:157` | — |
| `UPDATE` | `Entries` | `lib/entries.php:179` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/entries.php:302` | — |
| `UPDATE` | `Session` | `lib/entries.php:385` | review scope by hand |
| `UPDATE` | `Entries` | `lib/entries.php:387` | review scope by hand |
| `UPDATE` | `Session` | `lib/entries.php:404` | review scope by hand |
| `UPDATE` | `Entries` | `lib/entries.php:406` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/entries.php:442` | — |
| `UPDATE` | `Qualifications` | `lib/entries.php:456` | — |
| `UPDATE` | `Qualifications` | `lib/ghosts.php:411` | — |
| `UPDATE` | `Entries` | `lib/teams.php:73` | — |
| `UPDATE` | `Countries` | `lib/teams.php:92` | review scope by hand |
| `INSERT INTO` | `Countries` | `lib/teams.php:98` | — |
| `UPDATE` | `Entries` | `lib/teams.php:104` | review scope by hand |
| `DELETE FROM` | `Countries` | `lib/teams.php:109` | review scope by hand |
| `UPDATE` | `Events` | `lib/teams.php:113` | — |
| `INSERT INTO` | `Tournament` | `lib/wa.php:398` | — |
| `UPDATE` | `Events` | `lib/wa.php:441` | review scope by hand |
| `UPDATE` | `Events` | `lib/wa.php:446` | — |
| `DELETE FROM` | `EventClass` | `lib/wa.php:454` | — |
| `DELETE FROM` | `Events` | `lib/wa.php:455` | — |

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
| `DELETE FROM` | `TrainingGhosts` | `lib/entries.php:194` | — |
| `DELETE FROM` | `TrainingSimDuels` | `lib/entries.php:195` | — |
| `INSERT INTO` | `TrainingGhosts` | `lib/ghosts.php:163` | — |
| `UPDATE` | `TrainingGhosts` | `lib/ghosts.php:179` | — |
| `DELETE FROM` | `TrainingSimDuels` | `lib/ghosts.php:181` | — |
| `UPDATE` | `TrainingGhosts` | `lib/ghosts.php:393` | — |
| `REPLACE INTO` | `TrainingSimDuels` | `lib/live.php:429` | — |
| `DELETE FROM` | `TrainingResults` | `lib/results.php:124` | — |
| `INSERT INTO` | `TrainingResults` | `lib/results.php:131` | — |
| `UPDATE` | `TrainingTemplates` | `lib/template.php:394` | — |
| `INSERT INTO` | `TrainingTemplates` | `lib/template.php:397` | — |
| `UPDATE` | `TrainingTemplates` | `lib/template.php:417` | — |
| `INSERT INTO` | `TrainingRuns` | `lib/template.php:483` | — |
| `UPDATE` | `TrainingTemplates` | `lib/transfer.php:87` | — |
| `INSERT INTO` | `TrainingTemplates` | `lib/transfer.php:90` | — |
| `INSERT INTO` | `TrainingGroups` | `lib/transfer.php:102` | — |
| `INSERT INTO` | `TrainingGhosts` | `lib/wa.php:480` | — |
| `INSERT INTO` | `TrainingRuns` | `lib/wa.php:503` | — |
| `REPLACE INTO` | `TrainingWaData` | `lib/wa.php:505` | — |
| `REPLACE INTO` | `TrainingWaData` | `lib/wa.php:508` | — |
| `UPDATE` | `TrainingRuns` | `live.php:33` | — |
| `UPDATE` | `TrainingTemplates` | `templates.php:41` | — |
| `DELETE FROM` | `TrainingTemplates` | `templates.php:49` | — |

<!-- END DATABASE WRITES -->
