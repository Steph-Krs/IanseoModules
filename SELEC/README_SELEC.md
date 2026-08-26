# Sélection Équipe de France

Calcule et publie les classements d'une **épreuve de sélection nationale** directement dans ianseo,
à la place du tableur qui servait jusqu'ici. Tous les scores restent saisis par ianseo (clavier,
douchette ou ISK-NG) ; le module ne fait que lire ces scores et appliquer le règlement de sélection,
étape par étape, en gardant la trace de chaque valeur intermédiaire.

L'enjeu de ce module est la **justesse** : une sélection en équipe de France est relue par tout le
monde. Le module ne devine jamais un départage, ne compare jamais deux moyennes en virgule
flottante, et signale toute égalité qu'il ne peut pas trancher au lieu de la trancher au hasard.

## Fonctionnalités

- **Un type de compétition « Sélection »** ajouté à ianseo, avec une sous-règle par mode de
  sélection : la compétition se crée comme n'importe quelle autre, avec les bonnes séries,
  divisions, classes et blasons.
- **La structure est générée pour vous** : les départs de qualification (chacun portant ses propres
  séries), les tableaux de duels et leurs consolantes. Vous n'ajustez que ce qui diffère de votre
  organisation.
- **Un règlement = un fichier de configuration.** Un « mode de sélection » décrit la suite des
  journées, des qualifications, des tournois, des poules et des barèmes. Ajouter la sélection Arc à
  Poulies, Para ou Jeunes se fait en écrivant un fichier, pas en modifiant du code.
- **Sept briques de calcul** réutilisables : qualification, matchs simulés, tournoi
  montante/descendante, poule, journée à points cumulés, coupure, classement final.
- **Règlement figé à la compétition.** Le mode est recopié dans la compétition au moment du
  rattachement : une mise à jour du module ne modifiera jamais rétroactivement un classement déjà
  publié.
- **Le module classe, il ne sélectionne pas.** Aucune colonne « retenu », aucune barre de
  qualification : la composition de l'équipe appartient à la DTN. Le module ne produit que des
  scores et des classements.
- **Les familles de points sont nommées** partout — Points de Qualifications, Points Journaliers,
  Points de Tournois, Points de Tournois à 6, Points de Tournois de Poule, Points de Matchs
  Simulés. Jamais de colonne « Points » générique qui laisserait croire à un pool commun.
- **Traçabilité complète.** Chaque ligne conserve ses composantes (score, X, 10, flèches, sets,
  moyenne de set, points de classement, points de performance, somme) et le **critère qui a
  départagé** l'archer de celui qui le précède.
- **Égalités visibles.** Deux archers réellement indiscernables gardent le même rang et les mêmes
  points, et sont signalés comme tels. Quand le règlement prévoit un tir de barrage pour départager
  un rang, le module le réclame au lieu de choisir.
- **Tirs de barrage saisissables** à n'importe quelle étape prévue par le règlement.
- **Statistiques par archer** : moyenne des qualifications, moyenne par volée en duel, nombre de
  victoires, valeur moyenne de flèche sur l'ensemble de l'épreuve.
- **Recalcul intégral et idempotent** : la page de classement recalcule à chaque affichage depuis
  les scores ianseo, elle ne peut donc pas être périmée après une correction.
- **Impressions à la structure de ianseo** : classements et feuilles de marque reprennent la mise
  en page des impressions natives, et l'impression native reste proposée tant qu'une étape est en cours.

## Règlements livrés

| Mode | Contenu |
|---|---|
| `TAE_CL_2027_E1` | TAE Arc Classique 2027, épreuve 1 (Compiègne) : 4 qualifications sur 2 journées, coupure aux 8 premiers, 6 tournois sans élimination, 5 matchs simulés, classement final de l'épreuve. |
| `TAE_CL_2027_E2` | TAE Arc Classique 2027, épreuve 2 : 3 qualifications, coupure conditionnelle aux 6 premiers, tournoi à 6 et tournoi de poule. |

Chaque mode déclare ses **points ouverts** (ce que le règlement ne tranche pas encore) ; ils sont
affichés en clair sur la page de configuration plutôt que résolus en silence.

## Base de données

Six tables, toutes préfixées `SELEC_`. Aucune table ianseo n'est modifiée par le calcul.

| Table | Rôle |
|---|---|
| `SELEC_Config` | Mode rattaché à une compétition + copie figée de son règlement |
| `SELEC_Bind` | Quelle épreuve ianseo porte quel tournoi / quelle poule, par catégorie |
| `SELEC_Results` | Classements calculés, avec valeurs intermédiaires et départage appliqué |
| `SELEC_Archive` | Copie figée d'une étape verrouillée : scores, 10, X, chaînes de flèches, départ et cible |
| `SELEC_Shootoff` | Tirs de barrage saisis |
| `SELEC_Log` | Journal des rattachements, recalculs et saisies |

## Transférer une compétition vers un autre serveur

Une compétition se déplace en **deux fichiers**, et il faut les deux :

1. le fichier **`.ianseo`** de ianseo (Compétition → Export), qui emporte les scores, les
   épreuves, les grilles, les cibles et les horaires ;
2. le fichier **`.selec`** de ce module (menu *Transfert entre serveurs*), qui emporte le
   règlement figé, les rattachements, les classements, **les archives des étapes verrouillées**,
   les tirs de barrage et le journal.

L'export de ianseo ne connaît pas les tables des modules — sa liste est fixe — donc sans le
second fichier tout ce que le module a produit resterait sur le serveur d'origine, à commencer
par les archives avec lesquelles se réimpriment les feuilles de marque.

À l'arrivée : importer d'abord le `.ianseo`, ouvrir la compétition créée, puis charger le
`.selec`. Les archers sont rapprochés par **licence + division + classe**, jamais par identifiant
interne. La page analyse le fichier et liste ce qui ne retombe pas sur exactement un archer
**avant** que vous ne validiez.

## Accès

- **Configuration et classements** : profil disposant de `Qualification` en lecture/écriture.
- **Mise à jour du module** : administrateur du serveur uniquement.

L'entrée de menu n'apparaît que sur les compétitions déjà rattachées à un mode de sélection ; un
administrateur la voit partout, pour pouvoir en rattacher une nouvelle.

## Mise en place d'une compétition

1. **Créer la compétition** dans ianseo. Le module ajoute pour cela un type dédié :
   règle locale **Sélection Équipe de France**, type **Sélection — séries de 36 flèches**,
   puis la sous-règle correspondant à l'épreuve (TAE Arc Classique 2027 — Épreuve 1 ou 2).
   Les divisions, classes, blasons et les 8 séries de 36 flèches sont posés automatiquement.
2. **Configurer les épreuves individuelles** : une épreuve = une catégorie de sélection.
   C'est la seule chose qui dépend vraiment de vous, puisqu'elle dépend des catégories retenues.
3. Dans **Sélection Équipe de France → Configuration**, rattacher le mode et cocher les catégories,
   puis cliquer sur **Générer la structure**. Le module crée alors :
   - un **départ par qualification**, portant ses propres séries (départ 1 → séries 1-2,
     départ 2 → séries 3-4, départ 3 → 5-6, départ 4 → 7-8), en 6 volées de 6 flèches ;
   - pour chaque tournoi et chaque catégorie, le **tableau principal** et sa **consolante**
     liée (places 5-8), avec leurs grilles de matchs ;
   - les rattachements internes du module ;
   - les **cibles et les horaires de tous les duels** : vous ne donnez que l'heure du premier
     tour de chaque tournoi et la première cible de chaque catégorie. Un tour dure 35 minutes et
     le suivant enchaîne immédiatement ; petite finale et finale se tirent au même créneau ; un
     tournoi occupe un bloc de cibles du début à la fin, la consolante reprenant celles que le
     tableau principal libère. Un archer par cible, une cible par archer.

   Rien de ce qui existe déjà n'est modifié, et aucun score n'est touché. Relancer la génération
   ne crée jamais de doublon.
4. **Saisir les scores** normalement dans ianseo (clavier, douchette ou ISK-NG). Entre deux
   qualifications, déplacer les archers d'un départ au suivant avec **Participants → Déplacer une
   session** : seule leur affectation change, les scores déjà tirés restent en place puisque chaque
   départ écrit dans ses propres séries.
5. Les classements se lisent dans **Classements et traçabilité**, recalculés à chaque affichage.

## Lecture des classements

Une page par catégorie, **un onglet par journée**, et chaque étape **repliée par défaut** : on
ouvre celle qu'on regarde. L'onglet actif et les étapes ouvertes sont mémorisés, pour retrouver
sa place après chaque action. Sous chaque tableau, la cascade de départage réellement appliquée.

La colonne **Départage** ne se remplit que pour les archers qui ont vraiment eu besoin d'un
départage — ceux qui étaient à égalité sur le critère principal. Les autres n'ont rien à
expliquer : ils se sont séparés au score, ce qui est le principe même du classement. Et lorsqu'un
départage a servi, **tous** les archers du groupe l'affichent, le premier compris.

## Enchaîner les étapes

Sous le classement de chaque étape :

**Imprimer le classement** — une feuille PDF de l'étape, pour la catégorie affichée ou pour
toutes. Elle ne montre pas que le rang et les points : elle montre les **composantes** (score,
X, 10, sets, moyenne de volée, points de classement et de performance) et **le critère qui a
départagé** chaque archer, avec la cascade appliquée rappelée en bas de page. Un classement de
sélection doit pouvoir être refait à la main depuis la feuille. Les alertes de contrôle restent
à l'écran et **n'entrent pas dans le PDF** : une feuille de classement circule, elle porte le
résultat, pas les notes de travail de l'organisateur.

Sur un **classement de journée**, chaque étape apparaît deux fois : une colonne de détail, puis
les points qui en découlent. Le détail dit ce que l'archer a réalisé, dans les termes de l'étape :

- **qualification** — le score et la place : « 660/1 » ;
- **tournoi, poule** — la place et le niveau de performance : « 1 pl. / 27,4853 ». La place seule
  ne suffirait pas : deux archers sortis au même tour n'ont pas tiré pareil, et c'est justement la
  moyenne de volée qui attribue les Points de Performance.

Le total nomme les étapes additionnées — « Total pts Q1+Q2 », « Total pts T5+T6+MS ».

Sur le **classement final**, une colonne par critère de départage donne sa valeur pour chaque
archer : le classement peut être refait ligne à ligne, sans rien prendre pour argent comptant.

**Un archer écarté à la coupure garde exactement le classement qu'il avait à ce moment-là**,
départage compris — il n'a plus rien tiré, rien ne peut plus faire évoluer son rang. Sur sa ligne,
les journées qu'il n'a pas disputées restent **vides**, ainsi que les critères qui ne servent qu'à
départager ceux qui ont continué (valeur moyenne de flèche, nombre de victoires). Sa meilleure
qualification et le critère qui l'a départagé, eux, restent affichés : ce sont eux qui ont établi
son rang. Une case vide n'est pas un oubli, c'est l'absence de tir — le pied de page le rappelle.

**Feuilles de marque** — l'impression native de ianseo, choisie selon la façon dont l'étape a été
tirée : `Qualification/PrintScore.php` pour un départ, `Final/Individual/PrintScore.php` pour des
duels.

### Les duels simulés

Chaque duel simulé est une **épreuve de duels à part entière**. Huit archers tiennent dans un
tableau de 8, soit quatre duels réels par épreuve — tout le monde tire, personne n'affronte un
adversaire fictif, et aucune série de qualification n'est consommée. Le score est en **cumul** et
non en sets, conformément au règlement qui classe à la somme des scores sans regarder la victoire.
Seul le premier tour de chaque tableau se tire.

Les archers sont appariés **par voisins de classement** — le 1<sup>er</sup> contre le 2<sup>e</sup>,
le 3<sup>e</sup> contre le 4<sup>e</sup>, et ainsi de suite — et **gardent leur place du premier au
dernier duel**. Les cibles suivent le classement : le 1<sup>er</sup> sur la première cible du bloc,
le 2<sup>e</sup> sur la suivante. Faire tourner les adversaires n'apporterait rien, puisque le
classement se fait au total des scores et que la victoire ne compte pas.

La date, l'heure du premier duel et la première cible de chaque catégorie se règlent dans la page
de configuration, dans le même tableau que les tournois. Les duels s'enchaînent ensuite à la durée
indiquée.

**Saisissez les flèches, pas seulement les volées.** Le règlement départage les duels simulés aux
X puis aux 10, or ianseo ne compte ni les uns ni les autres sur un duel : ses compteurs n'existent
que pour les qualifications. Le module les recompte lui-même à partir du détail flèche par flèche
du match — celui qu'enregistre la tablette ISK‑NG. Si ce détail manque ou reste incomplet, le
module vous le dit et le départage restera sans effet pour les archers concernés : il ne les
déclarera pas ex aequo par défaut d'information.

Le bouton **« Créer ou corriger les duels simulés seulement »** de la page de configuration les
crée sans toucher aux départs ni aux tournois. Saisie par ISK‑NG ou par la saisie de duels de
ianseo, feuilles de marque par `Final/Individual/PrintScore.php`, vérification par code-barres,
cibles et horaires attribués automatiquement comme pour les tournois.

**Vérifier les feuilles** — ouvre la page de contrôle par code-barres de ianseo correspondant à
la façon dont l'étape a été tirée : qualifications, duels, ou tours de poule.

**Verrouiller / ouvrir la saisie** — ferme ou rouvre d'un seul clic **toutes** les sessions de
saisie tablette (ISK‑NG) de l'étape, sans quitter le classement. C'est le même verrou que l'écran
natif de ianseo, sur les mêmes sessions : les deux pages restent d'accord. Le bouton n'apparaît
que si l'étape a effectivement des sessions verrouillables — une qualification déjà tirée n'en a
plus, ses archers ayant été déplacés vers le départ suivant.

**Préparer l'étape suivante** — le module regarde ce qui vient après dans le règlement et propose
le geste correspondant. Rien n'est fait sans que vous ayez vu la liste :

- *Vers une qualification* : les archers sont replacés sur le **départ à venir**, dans l'ordre du
  classement de référence — la qualification précédente s'il s'agit de la deuxième du jour, le
  classement provisoire aux points journaliers s'il s'agit de la première d'une journée. Vous
  pouvez changer ce classement avant de valider.

  Vous choisissez **quelles catégories** replacer et, pour chacune, **de quelle cible à quelle
  cible** (numéro + lettre, par exemple « de 3B à 9A »). Le module propose un enchaînement : chaque
  catégorie démarre à la première place libre après la précédente, ce qui permet de **compléter la
  dernière cible des hommes avec les premières femmes** plutôt que de laisser des trous. Vous
  pouvez évidemment imposer un autre départ pour chaque catégorie.

  Le nombre de places par cible est celui du départ (configuration des sessions). Un archer occupe
  une seule place, une place reçoit un seul archer : un chevauchement de plages ou une plage trop
  courte est signalé avant validation, et vérifié une seconde fois juste avant l'écriture.
  **Les scores déjà tirés ne bougent jamais** : seuls le départ, la cible et la lettre changent.

## Verrouiller une étape terminée

Une compétition ianseo ne garde qu'**une ligne par archer** : les huit séries d'une compétition y
tiennent côte à côte. Le module donne à chaque départ ses propres séries, et ISK‑NG ne propose que
celles du départ en cours — la saisie sur tablette écrit donc toujours au bon endroit. Mais l'écran
de saisie manuelle de ianseo, lui, propose **toutes** les séries quel que soit le départ : un
mauvais choix réécrit une qualification déjà tirée, silencieusement.

Sur une sélection, cela ne peut pas rester une affaire de vigilance. Le bouton **« Verrouiller et
préparer »** — ou **« Verrouiller seulement… »** si vous ne voulez pas encore replacer les archers
— archive donc l'étape terminée : score, nombre de 10, nombre de X et **la chaîne de flèches** de
chaque archer, avec son départ et sa cible.

Une fois verrouillée :

- **son classement ne peut plus bouger.** Le module ne relit plus cette étape dans ianseo : même si
  la ligne d'un archer est réécrite par la suite, les points déjà attribués restent ceux qui ont été
  tirés.
- **le classement figé est ce qui reste imprimable**, pas les feuilles de marque : le bouton
  *Feuilles de marque* passe par l'impression native de ianseo, qui montre toujours les scores en
  base — donc ceux du départ en cours.
- **le retour en arrière reste possible.** Le bouton *Verrou et retour en arrière…* compare
  l'archive à ce que contient ianseo aujourd'hui et **liste chaque écart** (archer, série, valeur
  archivée, valeur actuelle) avant que vous ne décidiez. Vous pouvez alors *restaurer* les valeurs
  archivées dans ianseo — départs et cibles compris si vous le souhaitez —, *re-verrouiller* sur les
  valeurs actuelles après une correction assumée, ou *retirer le verrou* (l'archive disparaît, les
  scores ne sont pas touchés).
- *Vers un tournoi* : les qualifications sont **validées** et les **tableaux générés**, têtes de
  série dans l'ordre du classement de référence (le classement de la coupure pour le premier
  tournoi, celui du tournoi précédent pour les suivants). Un ex aequo parmi les têtes de série est
  signalé avant validation, parce qu'il rendrait le tirage arbitraire.

Un tableau qui porte déjà des scores **ne peut pas être régénéré** : le module refuse et dit
pourquoi. C'est la seule opération capable de détruire des résultats.

### Ce qui reste manuel

- La **configuration des tours** d'une poule ou des matchs simulés (l'épreuve est créée et
  rattachée, les tours se génèrent depuis le menu Round Robin de ianseo).
- Le **duel à 3** du tournoi à 6 (épreuve 2) : format qui n'existe pas dans ianseo.

### Une limite de ianseo à connaître

`Qualifications` ne stocke que **8 séries par archer** (colonnes `QuD1` à `QuD8`). Le nombre de
**départs** est libre, celui des **séries** ne l'est pas : douze départs ne donnent pas douze fois
deux séries. Les 4 qualifications de l'épreuve 1 consomment donc les 8 emplacements, et les 5 duels
simulés de J4 se tirent en **épreuve de duels** (mode match d'ISK-NG, 5 volées de 3 flèches), pas en
distances de qualification. Le module refuse toute configuration qui dépasserait 8 séries plutôt que
de produire une compétition silencieusement fausse.

## Vérification

Deux bancs de test sont livrés, à lancer en ligne de commande :

```
php Modules/Custom/SELEC/tests/run.php           # les calculs, sans base
php Modules/Custom/SELEC/tests/integration.php   # le pont avec ianseo, sur la vraie base
```

`run.php` rejoue notamment les **résultats réels de la sélection Europe 2026** (tournois de la
journée 3, égalités comprises) et vérifie que le module retrouve exactement les points attribués à
l'époque. `integration.php` vérifie que le décodage des flèches reproduit les totaux stockés par
ianseo et que le classement de qualification du module est identique à celui de ianseo.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). La désinstallation se fait depuis ianseo : page de mise à
jour du module → **Désinstaller le module**. Une case décochée par défaut propose de supprimer
aussi les tables `SELEC_*` ; les scores, eux, appartiennent à ianseo et ne sont jamais touchés.
