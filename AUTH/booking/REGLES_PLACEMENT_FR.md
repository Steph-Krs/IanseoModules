# Règles de placement sur les cibles

Version anglaise : [REGLES_PLACEMENT.md](REGLES_PLACEMENT.md). Ce fichier est le texte d'origine.

Règles du placement automatique des inscriptions en ligne (page **Attribution des cibles**,
`bk_assign_session()` de `lib/targets.php`, planificateur `lib/placement.php`). Arrêtées avec les
organisateurs le 12 novembre 2026. Elles remplacent le regroupement par catégorie : **on place par
blason**, et des catégories différentes partagent une cible quand leurs archers tirent à la même
distance sur des blasons compatibles.

Le placement écrit `QuTarget`, `QuLetter` et `QuTargetNo` comme les écrans du cœur : le résultat se
retrouve tel quel dans les listes par cible, le plan glisser-déposer et les feuilles de marque d'ianseo.

## La butte en salle

Une butte a deux **moitiés** : **gauche = A et C**, **droite = B et D** (au rythme 3 : gauche A et
C, droite B ; au rythme 2 : A et B). Une moitié porte **une seule famille** de blasons :

| Famille | Blasons | Sur la butte |
|---|---|---|
| **80** | blason de 80 (Découverte, 25 m jeunes…) | toute la butte, partagé par les 4 archers ; seul sur sa cible |
| **60** | blason unique de 60 | une moitié, partagé par les 2 archers de la moitié |
| **trispot 60** | trispot de 60 (jeunes) | comme un 60, mais jamais dans la même moitié qu'un blason unique de 60 (même cible permise) |
| **40** | blason unique de 40 | deux par moitié, un par archer |
| **trispot** | trispot de 40, classique ou poulies sans distinction | deux par moitié, un par archer |
| inconnu | blason non reconnu | seul sur sa cible (repli sûr) |

La famille vient du **type de blason d'ianseo** (`TargetFaces.TfT<n>`, `TfW<n>` = diamètre), pas de
son nom : un même blason enregistré sous deux noms est reconnu identique.

Mélanges entre les deux moitiés d'une cible :

|  | 40 | trispot | 60 / trispot 60 |
|---|---|---|---|
| **40** | oui | **à éviter** (dernier recours, signalé) | oui |
| **trispot** | à éviter | oui | oui |
| **60 / trispot 60** | oui | oui | oui |

**Salle 25 + 18** : la famille est prise **à chaque distance** (« Blason Unique 60cm/40cm » = 60 à
25 m puis 40 à 18 m). Une cible doit être possible à chaque distance ; un mélange 40 / trispot à
l'une des distances compte comme un mélange.

**Extérieur** : règles de [REGLES_COHABITATION_FR.md](REGLES_COHABITATION_FR.md) inchangées (un
blason complet partagé, ou jusqu'à 3 blasons de 80 réduit).

## Strict (jamais enfreint)

- une seule distance par cible, **série par série** ;
- le réglage du terrain (Contraintes d'affectation : distances, blasons acceptés) ;
- la capacité de la butte et le rythme ;
- un archer déjà placé (à la main, par un autre outil) ne bouge jamais ; il impose sa famille à sa
  moitié. Les places libres de sa cible servent si besoin, sans obligation (A peut rester libre) ;
- une inscription en attente de validation n'est pas placée.

## Souple, par ordre d'importance

On renonce d'abord au bas de la liste.

1. **Tout le monde a une cible.**
1. bis **Au moins 6 cibles remplies** (2 archers ou plus) par départ, règle du règlement avec les
   3 clubs différents. Même si les inscrits tiennent sur 5 cibles, ils sont ventilés sur 6 ; jamais
   une cible d'un seul archer pour atteindre le minimum. Réglage de la compétition (6 par défaut,
   modifiable seulement en DROM-TOM, comme le quota de clubs), vérifié aussi par le contrôle du
   règlement.
2. **Quota de clubs** par cible (réglage de la compétition).
2. bis **Contrôles du greffe** (Règlements sportifs et arbitrage, B.1.2), dans cet ordre : pas
   2 archers d'un même club seuls sur une cible (ils se marqueraient, même raison que la
   double-marque en rythme AB, B.5.1.8) ; pas d'archer seul sur une cible ; pas 4 archers sur une
   cible où tire un archer en fauteuil roulant (champ fauteuil d'ianseo). Le contrôle du règlement
   de la page signale aussi les archers seuls et les paires d'un même club, par exemple après des
   forfaits.
3. **Pas de 40 avec un trispot** sur une même cible.
4. **Nombre minimal de cibles.**
5. **Ordre des cibles** : les distances de la plus courte à la plus longue, chacune sur des cibles
   qui se suivent ; dans une distance, les familles dans l'ordre **80, 60, 40, trispots** (une cible
   mixte se place avec sa famille partenaire).
6. **Équilibrage** dans une famille : 6 archers sur 2 cibles font 3 + 3, pas 4 + 2.
7. Vœu « **sur la même cible que** ».
8. Vœu de **lettre**.

Un vœu non tenu est compté dans le bilan, sans être une erreur : ce sont des préférences. Le bilan
de la page signale aussi les archers au-delà du quota de club et les cibles qui mêlent 40 et trispot.

## Jauge d'inscription

Les places restantes d'un profil (arc, catégorie, blason) sont mesurées **avec le placement
lui-même** : autant d'archers de ce profil que de places libres sont planifiés autour des archers
déjà placés, et ceux que le plan accepte forment la jauge. Jauge et placement ne peuvent donc pas se
contredire.

## Limites connues

- Le planificateur construit quelques plans candidats et garde le meilleur selon les priorités
  ci-dessus ; ce n'est pas une recherche exhaustive. Exemple : un vœu entre un 40 et un 60 n'est tenu
  que si un 60 complète déjà une cible de 40 ; il ne crée pas une cible de plus pour le tenir.
- En extérieur, une cible ne porte qu'un seul blason (les blasons réduits de tailles différentes ne
  sont pas mêlés).

## Tests

Chaque règle a son cas dans le banc autonome **`booking/tests/placement.php`** (sans base de
données, en ligne de commande) :

```
php Modules/Custom/AUTH/booking/tests/placement.php        # résumé, et le détail des échecs
php Modules/Custom/AUTH/booking/tests/placement.php -v     # chaque cas, sa règle et son résultat
```

En plus du résultat attendu de chaque cas, chaque plan est vérifié contre les invariants qui
doivent tenir quelles que soient les données (archer placé une fois, aucune place prise deux fois,
archers déjà placés jamais déplacés, une distance par cible, une famille par moitié de butte à
chaque distance, réglage du terrain respecté).
