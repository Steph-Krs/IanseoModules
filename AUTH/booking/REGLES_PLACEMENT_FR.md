# Règles de placement sur les cibles

Version anglaise : [REGLES_PLACEMENT.md](REGLES_PLACEMENT.md). Ce fichier est le texte d'origine.

Règles du placement automatique des inscriptions en ligne (page **Attribution des cibles**,
`bk_assign_session()` de `lib/targets.php`, planificateur `lib/placement.php`). Le placement écrit
`QuTarget`, `QuLetter` et `QuTargetNo` comme les écrans du cœur : le résultat se retrouve tel quel
dans les listes par cible, le plan glisser-déposer et les feuilles de marque d'ianseo.

## L'ordre des archers

Les archers d'un départ sont rangés selon des critères, puis répartis **équitablement** sur les
cibles dans cet ordre. Ordre par défaut :

| Critère | Sens par défaut |
|---|---|
| **Distance** | la plus courte d'abord |
| **Blason** | le plus grand d'abord ; à taille égale, l'unique avant le réduit ou le trispot : 122 › 80 › 80 réduit › 60 › trispot 60 › 40 › trispot 40 |
| **Arme** | arc nu, arc classique, arc à poulies, puis les autres divisions de la compétition dans l'ordre d'ianseo |
| **Âge** | les plus jeunes d'abord (une classe sans tranche d'âge, comme Découverte, vient en dernier) |
| **Sexe** | les femmes d'abord |

Dans une même catégorie, les **clubs alternent** (le plus gros club d'abord), ce qui répartit les
clubs sur les cibles.

Page **Contraintes d'affectation du terrain**, section « Ordre de placement » :

- le **sens** se règle départ par départ : depuis la cible 1 (défaut) ou depuis la dernière cible,
  selon la configuration du terrain ;
- les **réglages avancés** (repliés, facultatifs) valent pour toute la compétition : l'ordre des
  critères, le sens de chacun, l'ordre des armes. Toute division de la compétition y figure, quelle
  que soit la discipline (les libellés sont ceux d'ianseo : en 3D et en Nature, le set FR appelle
  « CL » l'arc nu).

Un changement s'applique au prochain placement (nouvelle inscription, validation, ou
« Réattribuer » sur la page Attribution des cibles).

## La butte en salle

Une butte a deux **moitiés** : **gauche = A et C**, **droite = B et D** (au rythme 3 : gauche A et
C, droite B). Une moitié porte **une seule famille** de blasons :

| Famille | Blasons | Sur la butte |
|---|---|---|
| **80** | blason de 80 | toute la butte, partagé par les archers ; seul sur sa cible |
| **60** | blason unique de 60 | une moitié, partagé par les 2 archers de la moitié |
| **trispot 60** | trispot de 60 | comme un 60, jamais dans la même moitié qu'un blason unique de 60 |
| **40** | blason unique de 40 | deux par moitié, un par archer |
| **trispot** | trispot de 40, classique ou poulies | deux par moitié, un par archer |
| inconnu | blason non reconnu | seul sur sa cible (repli sûr) |

Exemple : un 60 en A peut côtoyer un 40 en B ou en D, **jamais en C**. Un 40 à côté d'un trispot
est possible mais évité (une cible de plus si le terrain l'a), et signalé.

La famille vient du **type de blason d'ianseo** (`TargetFaces.TfT<n>`, `TfW<n>` = diamètre), pas de
son nom. En **salle 25 + 18**, elle est prise à chaque distance (« 60cm/40cm » = 60 à 25 m puis 40
à 18 m) et une cible doit être possible à chaque distance.

**Extérieur** : un blason complet (122, 80) est partagé par les archers de la cible ; le 80 réduit
(5-X, type d'ianseo « poulies extérieur ») se pose **un par archer**, autant que le rythme (4 en
ABCD, 3 en ABC) ; jamais de réduit avec un autre blason
([REGLES_COHABITATION_FR.md](REGLES_COHABITATION_FR.md)). **Parcours et autres disciplines** : pas
de règle de blason.

**Lettres** : une cible qui a moins d'archers que de lettres les place sur les lettres
**extrêmes**, pour laisser de la place entre eux sur le pas de tir (2 archers en ABC : A et C, pas
B ; 2 en ABCD : A et D) — même règle que REPARTITION_EPREUVES. En salle, quand deux familles de
blasons partagent la cible, ce sont les moitiés de butte qui décident. Un vœu de lettre passe
avant.

## Strict (jamais enfreint)

- une seule distance par cible, série par série ;
- les **contraintes du terrain** : plage de distances et **blasons précis** acceptés par chaque
  cible. Une cible réservée aux trispots poulies ne reçoit pas les trispots classiques ; pour
  accepter les deux, on coche les deux. Une cible réservée qui accepte un groupe d'archers lui est
  donnée en priorité ;
- la capacité de la butte et le rythme ;
- un archer déjà placé (à la main, par un autre outil) ne bouge jamais ;
- une inscription en attente de validation n'est pas placée.

## Souple, par ordre d'importance

1. **Tout le monde a une cible.**
2. **Au moins 6 cibles occupées** (2 archers ou plus) par départ, règle du règlement avec les
   3 clubs : tant que les 6 cibles ne sont pas atteintes, on étale ; au-delà, on complète les
   cibles normalement. Jamais une cible d'un seul archer pour atteindre le minimum. Réglage de la
   compétition (6 par défaut, modifiable seulement en DROM-TOM, comme le quota de clubs).
3. **Quota de clubs** par cible.
4. **Règlement B.1.2** : pas 2 archers d'un même club seuls sur une cible (ils se marqueraient) ;
   pas d'archer seul ; pas 4 archers sur la cible d'un archer en fauteuil roulant (champ fauteuil
   d'ianseo).
5. **Pas de 40 avec un trispot** sur une même cible.
6. **L'ordre** ci-dessus, en panachant le moins possible : quand une règle oblige à déplacer un
   archer, il est échangé avec le plus proche dans l'ordre, celui qui ne change que le critère le
   moins important (dans une même catégorie d'abord).
7. **Équilibrage** : 6 archers sur 2 cibles font 3 + 3, pas 4 + 2.
8. Vœu « **sur la même cible que** », puis vœu de **lettre**.

## Contrôle du règlement

La page Attribution des cibles (et l'onglet « État des inscriptions ») vérifie les cibles **telles
qu'elles sont**, déplacements faits par l'organisateur dans ianseo compris : quota de club, nombre
de clubs, cibles occupées, archer seul, 2 archers d'un même club seuls, 4 archers sur la cible d'un
archer en fauteuil, blasons qui ne peuvent pas partager leur cible, doublons, archers non placés.

## Jauge d'inscription

Les places restantes d'un profil (arme, catégorie, blason) se comptent cible par cible, autour des
archers déjà placés, avec les mêmes règles que le placement (moitiés de butte, partage des blasons,
contraintes du terrain, fauteuil). Les cibles vides de même réglage ne sont mesurées qu'une fois :
la jauge reste immédiate sur un départ de 9 999 cibles.

## Méthode

1. Les archers sont triés selon les critères ; les vœux « sur la même cible que » rapprochent les
   demandeurs de la personne demandée.
2. Pour chaque distance, le nombre de cibles est calculé (rythme, fauteuils, quota de club, puis
   6 cibles occupées), puis la suite d'archers est **découpée** en cibles au meilleur coût
   (programmation dynamique) : écart à la taille moyenne, archer seul, deux familles de blasons sur
   une cible, changement de catégorie, quota de club, vœux.
3. Les cibles sont posées sur le terrain dans le sens du départ, en respectant les contraintes.
4. Échanges d'un archer, puis de deux, pour le quota et les paires d'un même club.
5. Les archers restants vont là où ils peuvent ; derniers déplacements pour les règles B.1.2.

## Tests

Chaque règle a son cas dans le banc **`booking/tests/placement.php`** (sans base de données, en
ligne de commande) :

```
php Modules/Custom/AUTH/booking/tests/placement.php        # résumé, et le détail des échecs
php Modules/Custom/AUTH/booking/tests/placement.php -v     # chaque cas, sa règle et son résultat
```

Chaque plan est en plus vérifié contre les invariants qui doivent tenir quelles que soient les
données : archer placé une fois, aucune place prise deux fois, archers déjà placés jamais déplacés,
une distance par cible, une famille par moitié de butte à chaque distance, contraintes du terrain
respectées.
