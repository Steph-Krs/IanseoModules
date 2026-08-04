# Répartition des épreuves

Attribution des cibles **en masse** pour les grandes compétitions. Là où le placement manuel
convient à une compétition de club, un championnat de France demande de placer plusieurs centaines
d'archers selon des règles : ordre du classement national, ordre des clubs, remplissage en
serpentin, restriction à certaines lettres de cible.

Le module dessine un **plan de départ par blocs** — un bloc = une épreuve sur une plage de cibles
et de lettres, avec sa règle — puis écrit le résultat dans ianseo une fois les contrôles passés.

## Fonctionnalités

**Classements nationaux**
Téléchargement des classements publiés par la FFTA (iframe publique de l'extranet, sans
authentification). Matrice armes × catégories d'âge, homme et femme côte à côte, avec la date de
dernière mise à jour et un rafraîchissement par case, par ligne, par colonne ou global. Le
rafraîchissement est toujours **manuel** : un classement est une photo à un instant donné.

**Correspondances**
Table de correspondance entre les épreuves ianseo (`Division` / `Classe`) et les classements FFTA.
Les épreuves de la compétition sans correspondance sont signalées : leurs archers seraient tous
traités comme non classés.

**Plan des départs**
Un plan par départ, cibles en abscisse et lettres en ordonnée, ajustable à la largeur de l'écran ou
à taille réelle. Les blocs se déplacent et s'étirent à la souris par leurs quatre bords et leurs
quatre coins ; le chevauchement est refusé. Le tableau sous le plan reprend les mêmes informations
et les modifie dans les deux sens. Une épreuve peut porter plusieurs blocs, éventuellement sur des
départs différents.

**Règles de placement**
Quatre réglages indépendants par bloc :

| Réglage | Valeurs |
|---|---|
| Source de l'ordre | classement national · classement national par club · par ordre de club manuel |
| Parcours | par cible · par lettre · serpentin |
| Sens des lettres | A→D ou D→A |
| Sens des cibles | croissant ou décroissant |

L'attribution se fait toujours **lettre d'abord, cible ensuite** — les flèches portées par chaque
bloc se lisent dans cet ordre. Les archers absents du classement partent en fin de liste en tri par
classement, et suivent l'ordre normal en tri par club.

Les deux tris **par club** suivent un algorithme de couloirs : chaque lettre est un couloir, un club
occupe **une seule lettre** (tous ses archers y tirent, sur des cibles adjacentes), puis on passe au
club suivant dans le couloir le moins avancé. La différence est l'ordre des clubs : par leur
**meilleur classé national** (« classement national par club ») ou par un **ordre défini à la main**
(« par ordre de club manuel »). Cet ordre manuel se règle sur la page **« Ordre des clubs »** : une
colonne par épreuve, la liste des clubs réordonnable au glisser-déposer, colonnes repliables quand il
y en a beaucoup.

Le **brassage des clubs** se choisit par bloc, à deux niveaux : **Féd.** (au plus 2 archers d'un
même club par cible, la règle fédérale) ou **Mél.** (au plus 1, mélange plus poussé mais non
obligatoire). Le brassage n'échange que les cibles des archers du bloc, sans changer les places du
classement utilisées. La mise en conformité fédérale est aussi proposée en un clic par le bouton
**« Brasser »** du panneau de contrôles.

**Contrôles avant affectation**
Six contrôles, recalculés à chaque modification. Les quatre premiers sont bloquants, les deux
derniers sont des avertissements.

1. Un archer, une seule place — deux blocs d'une même épreuve ne prélèvent pas les mêmes archers
2. Une cible-lettre, un seul archer
3. Un archer ne tire qu'une fois par départ — la personne en cause est nommée
4. Assez de places pour chaque épreuve
5. Aucun archer seul sur une cible — avec report proposé vers la cible précédente
6. Règlement fédéral : pas plus de 2 archers d'un même club sur une même cible — avec brassage proposé

**Import des arrêtés**
Dépose les fichiers d'arrêté FFTA (sélections individuelles et dépôts d'équipes) pour créer en
masse les inscriptions d'une compétition, coachs compris. Le format de chaque fichier est détecté
depuis son en-tête, jamais deviné sur la position des colonnes. Un même licencié apparaissant dans
plusieurs fichiers avec des valeurs différentes reste signalé « en conflit » jusqu'à ce que la
valeur à garder soit choisie (une par une ou toutes en une fois) ; un licencié présent à la fois
comme archer et comme coach donne deux inscriptions distinctes. L'ordre de l'arrêté (individuel et
équipe) devient une source de classement au même titre qu'un classement national FFTA.
L'écriture se choisit entre un fichier à réimporter manuellement dans ianseo, ou un import direct
qui ne modifie ni ne supprime jamais une inscription déjà présente — seules des inscriptions
nouvelles sont ajoutées.

**Aperçu et écriture**
L'aperçu liste l'attribution archer par archer sans rien écrire. L'écriture n'est possible
qu'après les contrôles, et une relecture vérifie que l'état obtenu est bien l'état attendu. Chaque
écriture est tracée.

## Base de données

| Table | Contenu |
|---|---|
| `REP_Classements` | un classement national téléchargé |
| `REP_Rangs` | une ligne de classement = un archer (licence, club, S1/S2/S3, moyenne, quota, préinscription) |
| `REP_Blocs` | le plan de départ tel qu'il est dessiné |
| `REP_Config` | saison et discipline retenues pour la compétition |
| `REP_OrdreClub` | ordre manuel des clubs, par épreuve |
| `REP_Journal` | trace de chaque écriture, avec le détail permettant de revenir en arrière |
| `REP_ImpEtat` | état de travail de l'assistant d'import des arrêtés (fichiers, lignes, résolutions) |
| `REP_ArrClassements` / `REP_ArrRangs` | classements dérivés d'un arrêté, propres à la compétition |
| `REP_ArrMapping` | association épreuve → classement d'arrêté |

Le résultat final est écrit dans les tables natives de ianseo (`Qualifications`) : feuilles de
marque, affichages et autres modules continuent de fonctionner sans savoir que ce module existe.

**Écriture bornée.** `Qualifications` n'a aucune colonne de compétition, et tous ses index sont
globaux : un filtre par départ et par cible s'appliquerait à toute la base. Le module n'écrit donc
jamais que sur des identifiants d'inscription explicites, avec la compétition, l'arme et le
caractère individuel en garde-fous redondants. Un seul fichier du module contient un `UPDATE` sur
cette table.

## Accès

Menu **Répartition des épreuves**, visible dans une compétition ouverte pour les profils disposant
de `AclQualification` en lecture-écriture. La page de mise à jour est réservée à l'administrateur.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md) : installation en une commande, mise à jour depuis ianseo
(`admin/update.php`), désinstallation depuis la même page.

Les cibles déjà attribuées aux archers **restent en place** après une désinstallation : elles
appartiennent à ianseo. Seuls les classements téléchargés et les plans de départ sont perdus si
vous cochez la suppression des tables.
