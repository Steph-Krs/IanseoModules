# Lecture des feuilles de marque

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion des compétitions de tir à l'arc.

**Photographier une feuille de marque imprimée par ianseo** — avec la caméra de l'ordinateur ou un
téléphone — pour la comparer à ce que contient ianseo, et à terme la saisir quand les tablettes de
marque n'ont pas servi.

> **En développement (0.1).** Cette version retrouve la grille sur la photo, identifie l'archer,
> découpe chaque case et lit les valeurs écrites à la main, en vérifiant les sommes et les cumuls.
> La lecture est encore apprise sur des chiffres manuscrits génériques : elle s'affinera avec de
> vraies feuilles. L'enregistrement dans ianseo vient ensuite.

## Fonctionnalités

- 🧭 **Demandés d'abord, dans cet ordre** : le départ, la distance, le nombre d'archers par
  feuille. Ce dernier ne propose que ce que ianseo peut imprimer pour les départs de la compétition
  (2 côte à côte jusqu'à 2 archers par cible, 3 pour 3, 5 ou 6, 4 sinon), et prend de lui-même celui
  du départ choisi. La lecture s'affiche une fois les trois choisis, retenus pour la compétition
  dans le navigateur : toute la lecture en dépend.
- 📷 **Trois modes avec la caméra, ou une image importée**, dans une seule fenêtre :
  - **Automatique** : la caméra est fixe, orientée vers la table, et seules les feuilles passent
    dessous. Tant qu'aucune grille n'est trouvée, la vue en direct grise tout sauf les zones où
    poser les grilles (celles de la dernière feuille lue entière, sinon les feuilles de la cible
    côte à côte). Dès que
    toutes les grilles de la feuille sont vues entières et assez grandes pour lire l'en-tête et les
    cases, totaux compris, et que la vue est immobile, **la photo se prend d'elle-même** : un éclair
    blanc le signale. La page attend d'abord quelques secondes que le direct ait identifié les
    cartes, dont il lit le mieux les en-têtes. La fenêtre se fige sur la photo, qui est lue comme une
    photo, sans qu'aucun mouvement parasite ne la dérange. Elle se replie pour laisser la page aux
    grilles des archers, et « Revenir au direct » passe à la feuille suivante. Pendant l'attente, la
    vue du moment est comparée à la précédente : une feuille changée, déplacée ou retirée se voit
    aussitôt qu'on la lâche, et rien n'est lu pendant qu'on la manipule. Dès que des grilles sont
    trouvées, seule leur zone compte : un objet qui passe à côté des feuilles ne dérange rien.
    Luminosité et contraste sont égalisés, pour que le réglage automatique de la caméra ne soit pas
    pris pour une autre feuille.
  - **Manuel** : la même vue en direct, avec les zones où poser la feuille, et la photo est prise
    au clic sur le bouton pause, puis lue de même.
  - **Vidéo** : la caméra à la main, pour s'approcher des cases ; seule la vidéo sert à la lecture
    (ci-dessous).
  - **Photo ou fichier image** : l'appareil photo du téléphone, ou une image déposée sur la page ; la
    caméra s'arrête, c'est l'une ou l'autre.
- 🎚️ **Rien à régler avant la première feuille** (modes automatique et manuel) : les places des
  grilles d'une feuille lue entière sont gardées d'elles-mêmes dans le navigateur, pour la
  compétition et la caméra, et deviennent les zones montrées ensuite. En mode automatique, la
  taille des tableaux de la première feuille entière écarte les grilles qui n'en ont pas la taille
  (morceau de tableau, deux tableaux confondus).
- 🪞 **Tourner d'un quart de tour ou mettre en miroir** l'image affichée (les deux boutons des coins
  de la vue ; quatre quarts de tour : comme avant), pour une caméra montée dans un autre sens :
  seulement l'affichage, gardé dans le navigateur, jamais la lecture. La vue se **replie** ou se
  **déplie** par le triangle au milieu de la barre ; repliée, rien n'y est dessiné, et la lecture
  continue. À droite de la barre : prendre la photo (viseur), revenir au direct (caméra), arrêter
  la caméra (caméra barrée, en rouge).
- 📱 **Sur un téléphone ou une tablette**, la page prend la largeur de l'écran et les boutons sont
  plus grands. Seuls restent le bouton **Photo**, qui ouvre l'appareil photo du téléphone (plus
  pratique que la vue en direct), et le triangle, sur une même ligne : ni modes automatique, manuel
  et vidéo, ni boutons de la caméra.
- 🔁 **Relecture** (dans les actions de chaque carte, à droite de « Validée dans ianseo ») : une
  grille posée une ligne trop haut ou trop bas (l'en-tête lu comme des cases, par exemple) est relue
  une ligne plus bas, puis une ligne plus haut ; au clic suivant, ou d'emblée quand la grille n'a pas
  été calée ou l'archer pas reconnu, la grille est **cherchée à nouveau** sur la photo recadrée autour
  de la carte, de plus près, puis relue. Une place restée sans grille, ou une photo où aucune grille
  n'a été trouvée, propose de chercher à nouveau sur toute la photo. La **taille des cases** se règle
  d'une barre (20 à 50 pixels), gardée dans le navigateur.
- 🔍 **Lecture au fil de la vidéo** (mode vidéo) : la feuille est suivie dans la vidéo image par
  image, quoi que fasse la caméra (déplacée, tournée, approchée, la feuille perdue de vue puis
  retrouvée), par des points caractéristiques de l'impression et de l'écriture. Ses grilles sont
  dessinées sur la vidéo, chacune dans sa couleur avec son archer dès qu'il est connu ; le travail
  lent (grilles, en-têtes, cases, contrôles) se fait à côté, sans jamais arrêter la vidéo. Chaque
  case est lue dès que la caméra la montre assez grande et assez nette, et relue quand elle la
  montre mieux ; les lectures d'une case sont mises en commun, les meilleures pesant le plus. Une
  case lue avec certitude se couvre d'un **voile vert** : il reste à approcher la caméra des autres.
  Chaque grille est régulièrement recalée sur ses propres traits imprimés (la ligne d'en-tête et la
  colonne des numéros de volée délimitent le tableau), pour que son contour garde la forme du
  tableau quand la caméra bouge. La **feuille suivante** se présente sans couper la caméra. La vue
  repart de zéro sur la nouvelle feuille dans trois cas :
  - la feuille est perdue plus d'un instant ;
  - l'en-tête d'une feuille identifiée, relu de temps en temps (et aussitôt quand ses cases se
    lisent autrement qu'avant), montre un autre archer ;
  - les grilles se retrouvent ailleurs que les feuilles connues (caméra immobile, feuille changée
    dessous).

  Tant que la caméra bouge, la page demande de la tenir immobile : les cases se lisent à l'arrêt.
  Le nombre d'archers par feuille donné, la page ne garde pas plus de grilles, disposées comme à
  l'impression ; la déformation de l'objectif d'une webcam est mesurée une fois et retenue pour
  cette caméra. Quand cette mesure ne la voit pas et que la feuille immobile montre moins de grilles
  qu'attendu, plusieurs corrections sont essayées en parallèle sur l'image, et celle qui fait
  apparaître toutes les grilles est gardée pour cette caméra. Dès que les feuilles sont identifiées, elles **s'ouvrent d'elles-mêmes** avec leurs
  flèches, à partir d'une image qui les montre entières, et continuent d'être mises à jour tant
  que la caméra tourne.
- 🟥 **Surimpressions**, dans tous les modes, expliquées par une légende repliable :
  - une case **rouge** signale une erreur certaine : une flèche lue avec certitude que la base tient
    autrement (erreur de saisie), ou un cumul ou un total que les flèches, toutes sûres jusque-là,
    ne font pas (erreur de calcul sur la feuille) ;
  - les cases grises imprimées (numéros des flèches et des volées) sont en **violet**, là où la
    grille est repérée : une grille décalée d'une ligne se voit aussitôt ;
  - ce qui est examiné (cases en doute, cases lues, en-tête) est toujours montré en modes
    automatique et manuel, en option en mode vidéo.
- 🔳 **Le QR code ISK** imprimé sur la feuille, quand il y en a un, donne sa cible, son départ et sa
  distance : la page règle d'elle-même départ et distance, et retrouve chaque feuille parmi les
  archers de cette cible.
- 🗂️ **Toute la feuille d'une cible d'un coup** : chaque feuille de marque de la photo (A, B, C… côte
  à côte) est retrouvée et lue à son tour ; une barre les présente avec leur état (sûre, à vérifier,
  à identifier), un clic sur l'une d'elles ou sur la photo l'affiche.
- 📐 **Grille retrouvée et redressée** quelle que soit la façon dont la feuille est tenue :
  inclinée, en perspective, à l'envers ou de côté, la courbure due à l'objectif d'une webcam grand
  angle étant corrigée d'abord. Seules les feuilles de qualification dessinées
  par ianseo sont prises en charge ; le gabarit (nombre de lignes, flèches par ligne, colonne de
  total général ou non) est déduit de la distance de l'archer et vérifié sur la photo.
- 🏷️ **Archer identifié par le code-barres** de la feuille, ou à défaut par **la cible et le nom
  imprimés** : la cible est lue d'abord, puis le nom la vérifie parmi les archers de cette cible. En
  direct, dès qu'une feuille de la cible est identifiée, les autres sont lues parmi les quelques
  archers restants de cette cible, et une feuille posée à côté reçoit l'archer que sa place désigne
  (ianseo imprime les feuilles d'une cible côte à côte dans l'ordre des lettres), sauf si son nom se
  lit autrement ; elle l'indique, à vérifier ; quatre feuilles sont côte à côte, ou deux par deux (A,
  B au-dessus de C, D). Le nombre de volées tirées se voit sur quelques flèches : une volée dont les
  deux premières sont vides n'a pas été saisie, ni les suivantes (vérifié sur les deux premières de la
  volée suivante et les deux dernières de la feuille : six cases disent qu'une feuille n'a pas été
  tirée) ; ses cases ne sont pas lues, en gris. Une case lue avec certitude (deux contrôles de suite)
  garde sa valeur et n'est plus relue, sauf si toute la feuille devient douteuse. Le départ se choisit dans les filtres de la page ; la feuille d'un archer d'un autre départ est
  signalée, avec un bouton pour y passer. Si rien ne se lit assez bien, on saisit la cible. Le nom,
  la cible, la catégorie et la distance s'affichent en grand pour vérifier qu'il s'agit de la bonne
  feuille.
- ✂️ **Chaque case découpée** — flèches, sommes, cumuls, nombres de 10 et de X, ligne Total — et
  présentée à côté des valeurs que ianseo contient déjà (saisies à la tablette ou à la main).
- 🔢 **Valeurs lues et vérifiées** : chaque flèche est lue, chaque nombre écrit parmi les valeurs
  que sa case peut contenir avec le blason de l'archer à cette distance (trois flèches font au plus
  30, et sur un blason de 6 à 10 ni 1 à 5 ni 11 ; un cumul seulement ce que les flèches déjà tirées
  peuvent faire ; un compte pas plus que les flèches qu'il compte), puis les sommes, les cumuls et les
  nombres de 10 et de X écrits par l'archer départagent les lectures douteuses ; un compte de 10 ou
  de X laissé vide pèse peu, les archers l'oubliant souvent, et une case de somme de ligne qui porte
  le total de toute la volée (écrit là à la place, sur une volée tirée en deux lignes) est lue comme
  tel. La **ligne Total** est ce que l'archer signe (points et nombres de 10+X / X de toute la
  carte) : laissée vide par l'archer, elle reste vide et s'affiche en **jaune** pour le signaler ;
  fausse, c'est le total juste qui est retenu, en **rouge** : l'opérateur corrige la feuille papier ;
  lue autrement que ne le donnent les flèches sans que ce soit certain, elle est encadrée d'**orange**,
  à vérifier : jamais prise sans le dire. **Une grille décalée d'une ligne** se voit aux cases que
  le cœur imprime en gris : la ligne d'en-tête au-dessus des flèches, et la colonne des numéros de
  volée, mais pas la case de distance au-dessus d'elle. Cela suffit à dire de quel côté replacer
  les lignes, et la grille est replacée aussitôt. Sur les volées de deux lignes,
  les cases barrées par l'impression le confirment : une lecture encore douteuse est refaite une
  ligne plus loin, et gardée si elle est cohérente. Toutes les grilles d'une feuille sont dans le
  même sens (l'en-tête et le nom en haut, la ligne Total en bas) : une grille trouvée à l'envers
  des autres est réajustée dans leur sens, ou écartée. Une valeur sûre
  s'affiche en vert, une valeur à vérifier en orange, une flèche différente de ianseo est encadrée de
  rouge, et une erreur d'addition de l'archer, reportée sur les cumuls suivants et la ligne Total,
  est signalée sur ces totaux (les flèches font foi). Un clic sur une flèche propose les valeurs du
  blason de l'archer pour la corriger.
- 🧾 **Les feuilles de la cible côte à côte**, dans l'ordre A, B, C, D, une place sans archer
  marquée « --- ». Chacune a un **bouton** coloré :
  - **vert** : toutes les flèches et les trois totaux lus avec certitude, justes, et conformes à la
    base s'il y a déjà des valeurs ;
  - **orange** : des cases lues sans certitude ;
  - **rouge** : une erreur certaine, de calcul de l'archer ou avec la base ;
  - **bleu en mouvement** : lecture en cours.

  Le bouton porte l'**œil** de la lecture (vert : tout est sûr ; orange : tout est lu ; rouge : des
  cases ne sont pas lues) et les **trois totaux**, chacun avec son signe : validé (juste), croix
  (erreur de calcul de l'archer) ou attention (différent de la base). Chaque signe est vert ou rouge
  quand la lecture est sûre, orange quand elle ne l'est pas. Le bouton donne enfin le **nombre
  d'alertes** (erreurs et différences avec la base). Une légende repliable explique tout.
- 🔲 **Les cases** : leur image est soulignée d'orange quand la lecture est incertaine, grisée et
  barrée quand la case n'est pas lue ; une valeur corrigée à la main devient sûre. Les flèches sont
  en vert quand elles sont sûres, ou quand elles sont celles de la base ; une flèche autre que dans
  la base est en rouge, encadrée de rouge. La colonne des volées n'a pas de titre et « Total » s'écrit
  verticalement, pour laisser la largeur aux cases. Chaque nombre écrit (sommes, cumuls, comptes, totaux)
  s'affiche « lu / calculé d'après les flèches », avec en bleu ce que tient la base. Le lu est vert
  s'il est juste, rouge sinon ; le calculé est rouge s'il diffère de la base. Un nombre faux à coup sûr
  ou différent de la base est encadré de **rouge** sur la ligne Total (total, 10+X et X signés : ce qui
  est écrit dans ianseo), d'**orange** ailleurs (somme, cumul, comptes d'une volée : ils ne servent
  qu'au contrôle). Chaque **total signé**
  peut recevoir d'un clic la valeur que font les flèches (« = valeur » : total mal lu, ou erreur de
  l'archer gardée juste), ou 0 s'il est laissé vide (« = 0 » : vide, il vaut 0, sans recomptage,
  comme les boutons « Remove » de la vérification par code-barres ; un seul bouton quand les flèches
  font 0 aussi) ; « annuler » revient à la
  lecture. La correction d'une flèche ne propose que les valeurs du blason de l'archer, de la plus
  forte à la plus faible (X, 10, 9… 1, M selon la discipline), puis « vide ».
  Deux vérifications rapides sur chaque carte : « Valider les n flèches incertaines » les prend
  telles qu'elles sont lues (le plus souvent justes, seulement incertaines ; une flèche autre que
  dans ianseo reste à corriger seule), puis, toutes les flèches sûres, « Valider les totaux
  calculés » met les trois totaux à ce que font les flèches. L'écriture dans ianseo n'est proposée
  qu'ensuite. Ces boutons sont dans l'en-tête coloré de la carte, à droite de l'archer et des totaux,
  et répétés sur la ligne Total (Relecture exceptée).
  Une somme, un cumul ou un compte de volée lu sans certitude est souligné de jaune : ils ne servent
  qu'au contrôle ; les flèches et les totaux signés, eux, d'orange.
- 💾 **Écriture dans ianseo** : « Importer les données » tant que la base ne tient pas toutes
  les flèches de la distance (aucune, ou il en manque : c'est la première lecture complète de la
  feuille), « Valider les données » ensuite, à une lecture suivante (voir Base de données). Une
  valeur lue autrement que dans la base n'est jamais écrite ni validée : l'opérateur saisit la
  bonne. Quand la base n'a qu'une partie des flèches, ses totaux sont montrés sans être comparés.
  Deux cases, comme pour ISK, importent d'elles-mêmes, au choix :
  - toute la feuille, quand toutes ses lectures sont sûres ; une grille imprimée à une place sans
    archer ne la retient pas ;
  - chaque archer, dès que toutes ses valeurs le sont.

  Une écriture refusée par le serveur n'est pas retentée d'elle-même : la carte affiche alors ce que
  tient la base, et seul un clic de l'opérateur la relance. La feuille entièrement écrite, la vue
  revient au direct pour la suivante ; la feuille restée sous la caméra n'est pas reprise tant que la
  vue ne change pas.
- 🎞️ **Capture en série** (outil de développement, voir Accès) : la caméra reste allumée et chaque feuille présentée est photographiée
  dès que sa grille est trouvée et ne bouge plus, avec un bip ; on enchaîne avec la suivante sans
  rien toucher, au même endroit si l'on veut. Sans caméra en direct, « Photo » ouvre l'appareil
  photo du téléphone et « Envoyer des photos » envoie d'un coup celles de la galerie. Les photos
  sont gardées sur le serveur pour la compétition ouverte, téléchargeables en une archive ZIP.

## Confidentialité

Sur la page de lecture, la photo est analysée **dans le navigateur** et n'est jamais envoyée au
serveur : le code-barres, ou la cible et le nom imprimés, y sont lus, face à la liste des archers de
la compétition que la page reçoit du serveur.

Les photos de la capture en série, elles, sont envoyées au serveur : elles sont rangées dans son
dossier temporaire (jamais sous la racine du site web), par compétition, réservées aux utilisateurs
qui peuvent saisir les scores, et **effacées d'elles-mêmes au bout de 7 jours**.

Deux options de développement du module, affichées seulement quand l'administrateur a choisi
« Devenir développeur » (voir Accès), envoient aussi quelque chose au serveur, rangé de la même façon. **L'enregistrement de l'essai** (la vidéo de la caméra et ce que la page en a fait,
effacé au bout de 7 jours) n'est pas coché d'origine. **L'envoi des cases en doute** l'est : une
petite image de chaque case que la lecture n'a pas pu confirmer, des chiffres manuscrits et jamais
un nom, effacée au bout de 60 jours. Les deux se décochent sur la page, et le choix est gardé dans
le navigateur. Les cases en doute s'affichent sur la page **Cases à
vérifier**, quelques dizaines à la fois, la valeur lue déjà saisie, avec pour chacune ce qu'elle
est sur la feuille et le plus grand nombre qu'elle peut contenir (seules les lectures possibles avec
le blason de l'archer sont proposées, un nombre impossible saisi est signalé) : on corrige celles qui sont fausses, on
signale d'un clic (ou par « * », sur le pavé numérique) une case mal cadrée (entre deux cases, hors de la grille), et on valide avec Entrée.
Chaque case s'affiche en grand avec ses voisines, une douzaine à la fois, avec sa cible et sa
distance pour retrouver la feuille. Ce qui y est saisi sert ensuite à l'apprentissage de la lecture.

## Accès

- Lecture : tout utilisateur qui a accès aux qualifications de la compétition ouverte. Capture en
  série, cases à vérifier : le droit de saisir les qualifications. Les entrées de menu apparaissent dans l'onglet
  **Modules** quand une compétition est ouverte.
- La caméra en direct demande une page sécurisée : https, ou l'ordinateur du serveur lui-même. Un
  ianseo local s'utilise en http, et le pare-feu que règle son installateur n'ouvre que les ports
  http. Depuis un autre ordinateur ou un téléphone, avec Chrome : un réglage à faire une fois sur
  l'appareil (« Insecure origins treated as secure », dans `chrome://flags`), que les pages du
  module expliquent avec l'adresse exacte à coller — rien à changer sur le serveur. Sans ce réglage,
  l'appareil photo du téléphone et sa galerie restent utilisables sur les deux pages.
- **Outils de développement** (capture en série, cases à vérifier, options d'essai de la page de
  lecture) : masqués d'origine. L'administrateur les affiche, pour toute l'installation, sur la page
  de mise à jour du module (« Devenir développeur ») ; masqués, ni menu, ni options, et leurs pages
  et points d'accès refusent de servir. Sur un serveur partagé, tous ceux qui peuvent saisir les
  qualifications les voient une fois affichés.
- Mise à jour et désinstallation : administrateur.

## Lecture de l'écriture

Trois petits réseaux de neurones, livrés avec le module (`assets/model/`), lisent les cases dans le
navigateur, sans service extérieur : un pour les flèches, un pour les chiffres un à un, un pour les
nombres lus d'un seul tenant (sommes, cumuls, comptes), qui n'a pas besoin de séparer des chiffres
qui se touchent ou empiètent l'un sur l'autre. Ils ont été entraînés sur la base de chiffres et de
lettres manuscrits **EMNIST** du NIST (*National Institute of Standards and Technology*), puis sur
des photos de vraies feuilles de marque de compétitions passées, étiquetées par les flèches de leur
base, et sur les cases vérifiées à la main sur la page Cases à vérifier (un X écrit « + » ou « 10+ »
est lu comme un X) ; rien de ces photos n'est livré, seulement les poids des réseaux.

Chaque case est découpée avec une **marge** : un trait qui déborde de sa case est lu avec elle, celui
qui déborde de la case voisine est écarté, les lignes de la grille sont retirées. Les chiffres qui se
touchent sont séparés au passage le plus clair, et les nombres sont lus aussi d'un seul tenant, les
deux lectures étant combinées. En mode vidéo seulement, chaque case est en plus **recadrée** sur ses
propres traits avant sa lecture : chaque bord est posé sur le trait le plus proche, dans une marge
autour de la case. Cela donne plus de cases sûres en vidéo, et rien sur une photo. Ces réglages
sont ceux de chaque mode et ne se choisissent plus sur la page. Il reste trois options pour le
développement du module, affichées par « Devenir développeur » :
- montrer ce qui est examiné, en mode vidéo seulement ;
- enregistrer l'essai ;
- envoyer les cases en doute.

Les QR codes sont lus par le lecteur du navigateur quand il en a un, sinon par
[jsQR](https://github.com/cozmo/jsQR) 1.4.0 (`assets/js/vendor/jsQR.js`), sous licence Apache 2.0
(`assets/js/vendor/jsQR-LICENSE.txt`).

Quand les nombres écrits par l'archer s'accordent trop peu avec les flèches lues (photo floue ou trop
petite), ou quand les totaux des volées sont presque tous lus vides (grille décalée d'une ligne), la
lecture est signalée comme peu sûre et toutes les valeurs sont à vérifier : elle n'est jamais
présentée comme sûre ; pas plus que les flèches d'une volée où les contrôles supposent une erreur
d'addition de l'archer. Avant d'en arriver là, deux façons d'écrire des archers sont essayées : le
total de la volée dans la case de somme d'une ligne, et un cumul écrit à cheval sur la case du total
de volée et la sienne (« 1 | 12 » pour 112), une même personne remplissant toute la feuille.
**Avec une webcam** (2 Mpx, 1920 × 1080), posez la feuille **de côté**,
sa longueur dans la largeur de l'image, qu'elle remplisse, bien éclairée : tenue droite, elle n'a
que les 1080 pixels de hauteur de l'image et ses cases sont trop petites. Le code-barres demande une
photo de téléphone ; avec une webcam, ce sont la cible et le nom imprimés qui identifient l'archer.

Un troisième petit réseau lit l'en-tête imprimé (cible, nom, catégorie) ; il a été entraîné sur du
texte tracé dans l'Helvetica qu'utilise le cœur pour imprimer (et en Arial, que les imprimantes
emploient parfois à sa place).

## Base de données

Le module **ne crée aucune table**. Il lit la compétition ouverte : inscriptions, placements et
flèches de qualification, distances, blasons. Il n'écrit qu'à la demande de l'opérateur, ou de
l'importation automatique qu'il a cochée, et seulement dans la table des qualifications du cœur
(`Qualifications`), pour l'archer et la distance de la feuille lue :
- **Importer les données** : tant que la base ne tient pas toutes les flèches de cette distance
  (aucune, ou il en manque) et que la feuille n'est pas validée. Les flèches déjà saisies doivent
  être celles de la lecture, sauf celles que l'opérateur a corrigées ; la lecture complète les
  autres. Le module écrit les flèches et les totaux de la distance, puis les totaux de l'archer. Les
  totaux sont recalculés par le serveur à partir des flèches ; un total signé laissé vide et mis à 0
  par l'opérateur vaut 0.
- **Valider les données** : quand toutes les flèches sont saisies, donc à une lecture suivante pour
  une feuille importée. Elles sont validées comme par la vérification par code-barres du cœur (le bit
  de la distance dans `QuConfirm`), seulement si la lecture est ce qui est saisi. Les cases que
  l'opérateur a corrigées sont écrites d'abord.

Chaque écriture passe par la compétition ouverte (jointure sur `Entries`). Les classements sont
ensuite recalculés par les classes du cœur, comme après un import ISK : catégorie, équipes,
épreuves et leurs barrages.

Un seul réglage, pour toute l'installation, dans la table `Parameters` du cœur : la ligne
`OcrDevTools` (valeur `1`) quand l'administrateur a affiché les outils de développement ; supprimée
quand il les masque.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En bref : copier les dossiers `OCR/` et `_shared/` dans
`Modules/Custom/` (ou lancer `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu **Modules → Lecture des feuilles de marque → Mise à jour du module**, avec une
compétition ouverte.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `Qualifications` | `lib/score.php:143` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/score.php:149` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/score.php:160` | review scope by hand |

### Tables owned by this module

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
