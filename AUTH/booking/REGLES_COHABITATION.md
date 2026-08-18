# Règles de cohabitation des blasons (M7)

Spécification fournie (source FFTA). Base de l'implémentation de la cohabitation
dans `bk_assign_session()` (lib/targets.php). Les cas marqués **à affiner** ne sont pas
tranchés — ne rien inventer, laisser le repli sûr (pas de cohabitation forcée).

## Principe général (toutes disciplines)
- On place les archers de **mêmes catégories ensemble** pour les regrouper (facilité
  d'organisation, pas une règle explicite du règlement).
- Les cohabitations de blasons se font **sur les cibles de transition** d'une catégorie à
  l'autre. Ailleurs, une cible ne porte qu'un seul type de blason.

## TAE (extérieur) — peu importe le rythme
Sur une même cible, l'un de ces cas :
- **1** blason de **60**, de **80 (zones 1-10)** ou de **122** ;
- **2 ou 3** blasons de **80 réduit** (zones 5-10 ou 6-10).

## Tir à 18 m — selon le rythme (nombre d'archers par cible)
Trispot classique ou poulies = **sans distinction**. Monospot = « blason unique ».

### Rythme AB (2 archers / cible)
- 2 trispots de 40 ou 60 (classique/poulies, sans distinction) ;
- 2 monospots de 40 ou 60 ;
- 1 monospot de 40 ou 60 **+** 1 trispot de 40 ou 60 ;
- 1 blason de 80.

### Rythme ABC (3 archers / cible)
- 3 trispots de 40 ;
- **à affiner** : le cas de 3 archers hors trispots n'est pas défini.

### Rythme AB-CD (4 archers / cible)
- 4 trispots de 40 (classique/poulies), peu importe l'emplacement ;
- 4 monospots de 40, peu importe l'emplacement ;
- 2 trispots de 40 en A et C **+** 2 monospots de 40 en B et D (ou inversement) ;
- 1 trispot de 60 pour A et C **+** 2 monospots ou 2 trispots pour B et D (ou inversement) ;
- 2 monospots de 60, peu importe l'emplacement ;
- 1 blason de 80.

## Parcours (Campagne, 3D, Nature) — pelotons
La « cible » est un **peloton**. On groupe 2 archers tirant au même **piquet** ; dans un
peloton de 4 on peut avoir 2 piquets différents. Pas de vraie règle de cohabitation de
piquets — **facilité d'organisation, à affiner**. Les contraintes fermes viennent du
règlement (composition du peloton) :

### Campagne
- Peloton de **4 max, jamais moins de 3**. Si possible, même nombre de tireurs par peloton.
- S'ils sont **3**, emplacements possibles **A, B, C uniquement** (pas D).

### Nature
- Peloton de **3 min à 5 max**.
- Au plus **2 archers du même club** par peloton de 4, ou **3** par peloton de 5.
- Éviter un peloton de 3 jeunes + 1 adulte ; préférer 1 jeune + 3 adultes ou 2 jeunes + 2 adultes.

### 3D
- Peloton de **4 min à 6 max**.
- Au plus **2 archers du même club** par peloton de 4, ou **4** par peloton de 6.
- Même équilibre jeunes/adultes que Nature.

## Beursault
- **Aucune règle** de cohabitation.

## Notes d'implémentation
- Classification d'un blason depuis `TargetFaces.TfName` (jeu FFTA) : diamètre (40/60/80/122)
  + type (monospot « Blason Unique », trispot « Trispot », complet « Blason Complet/Classique »,
  réduit « réduit »/« 5-10 »/« 6-10 », piquet « Piquet <couleur> »). Classique vs poulies :
  ignoré (sans distinction). Repli + surcharge possible via `config.local.json`.
- Le rythme 18 m = nombre de lettres par cible (`Session.SesAth4Target`).
- La cohabitation valide un **ensemble d'occupants** d'une cible : la placer dans l'éligibilité
  de `bk_assign_session()` (même point d'accroche que le quota de club).
- **Modèle budget + coût** (implémenté) : chaque cible a un budget (18 m : 4 ; TAE : 3), chaque
  blason un coût (18 m : 40→1, 60→2, 80→4 ; TAE : réduit→1, plein→3). Valide si Σcoûts ≤ budget et
  nb archers ≤ rythme. Reproduit toutes les combinaisons ci-dessus.
- **Blason partageable** (essentiel) : en TAE, un blason **plein** est tiré par plusieurs archers
  d'une même catégorie sur une seule cible → il ne compte qu'**une fois** dans le budget. À 18 m et
  pour les réduits TAE, chaque archer a son propre blason (un coût par archer).
