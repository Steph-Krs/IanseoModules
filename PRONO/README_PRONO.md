# PRONO — Pronostics en direct

Un jeu de pronostics sur une compétition ianseo. Les spectateurs choisissent un pseudo
depuis leur téléphone, pronostiquent les qualifications et les éliminatoires, et
**marquent des points** quand ils voient juste.

**On ne mise rien.** Pas de solde de départ, pas de somme à engager : un pronostic se
pose d'un seul geste et se change tant que le match n'a pas commencé. Un pronostic
juste rapporte, un pronostic faux ne coûte rien — on ne perd jamais de points.

Aucune donnée personnelle n'est collectée : un pseudo et un mot de passe, rien d'autre —
pas d'adresse e-mail, pas de numéro de téléphone.

*(Ce module s'appelait PARIS ; il a été renommé PRONO — dossier, tables, vocabulaire —
pour refléter ce que c'est vraiment : des pronostics à points, jamais des paris
d'argent.)*

## Combien rapporte un pronostic

Deux barèmes, au choix dans la console.

**À la difficulté** (par défaut). La valeur d'une issue est indexée sur sa probabilité :
un pronostic à 50/50 vaut les points de base (10 par défaut), un favori évident en vaut
à peine plus, un outsider beaucoup. Voir juste quand personne n'y croyait doit payer.
La valeur est **figée au moment du pronostic** : miser tôt sur un outsider rapporte ce
qu'il valait alors, même si tout le monde suit ensuite. Le gain est plafonné à un
multiple des points de base (25 fois par défaut, réglable dans la console).

**Forfaitaire.** Points fixes par type, indépendants de la difficulté : duel ×1, score
exact ×3, vainqueur d'épreuve et meilleur score de qualification ×2,5. Plus lisible d'un
coup d'œil, moins nerveux.

Dans les deux cas, les pronostics des autres joueurs font bouger la valeur des issues
avant qu'elles ne soient verrouillées : plus une issue est plébiscitée, moins elle
rapporte à ceux qui s'y rallient ensuite.

### Un duel, un seul pronostic

Le vainqueur et le score exact ne font qu'un. Sur la carte d'un duel, les deux noms
sont cliquables : un appui suffit pour désigner un vainqueur, sans toucher au score.

En dessous, un score part de **0-0**, encadré d'un **−** et d'un **+** de chaque côté
— ceux de gauche pilotent l'archer de gauche, ceux de droite l'archer de droite. Dès
qu'un côté atteint le seuil de victoire (6 points de set en individuel, 5 en équipes),
**son nom s'affiche comme choisi** : le score désigne le vainqueur, il n'y a rien à
cliquer en plus.

Un **+** se désactive dès qu'aucun score final valable n'est plus atteignable, et seul
un score **réellement possible en fin de duel** peut être enregistré : 6-2 oui, 7-0 ou
4-4 non. Tant que le compte n'y est pas, l'application le dit au lieu de laisser
valider n'importe quoi.

L'**arc à poulies** se joue au cumul de points, sans sets. Ses duels proposent donc
d'annoncer le **score final du vainqueur**, par tranches de 3 points couvrant la plage
crédible (« Herve Sandra 138-140 »). Deviner un total exact sur 150 points serait
injouable ; une tranche reste à portée, et sa valeur suit sa probabilité réelle —
calculée comme la masse de la loi du total **conditionnée à la victoire**, puisque la
tranche désigne le vainqueur autant que son score.

La **largeur des tranches** se règle dans la console (3 points par défaut, jusqu'à 50).
Plus large, la tranche est plus facile à deviner et rapporte donc moins. Les bornes
sont inscrites dans chaque issue : changer la largeur en cours de compétition **ne
rejuge jamais un pronostic déjà posé** — il reste évalué sur la tranche qui lui avait
été proposée. Les tranches devenues caduques sur lesquelles personne n'a pronostiqué
sont simplement retirées.

Deux conséquences :

- **Moitié moins de pronostics à poser.** Un duel = une carte = un choix.
- **Se contredire devient impossible.** On ne peut plus annoncer la victoire d'un
  archer et lui coller un 0-6 dans la foulée.

Et tenter le score n'est pas tout ou rien : **si le score n'est pas exact mais que le
vainqueur est le bon, tu marques les points du duel**. On n'est jamais puni d'avoir osé.

## Fonctionnalités

### Cinq types de pronostics

| Type | Sur quoi on pronostique | Quand |
|---|---|---|
| **Duel** | qui gagne le match — et, si tu l'oses, le score exact | tableau final |
| **Vainqueur de l'épreuve** | qui soulève le titre | dès la grille tirée |
| **Tiercé de qualification** | qui finit 1er, 2e et 3e — comme aux courses hippiques | dès l'ouverture, avant même la première flèche |
| **Score du 1er qualifié** | - de X / entre / + de X, ancré sur le classement national | dès l'ouverture, si le classement national est disponible |
| **Score du cut** | idem pour le dernier qualifié | dès l'ouverture, si le classement national est disponible |

Le tiercé se joue en un seul geste : nommer les 3 podiums. Les trois noms dans le bon
ordre rapportent le maximum ; les trois bons noms mais dans le désordre rapportent
moins ; un seul nom faux, et le pronostic ne rapporte rien — exactement les trois
issues d'un vrai tiercé.

Les marchés de qualification s'ouvrent dès que la liste des inscrits est connue, pas
seulement une fois les premiers scores rentrés — attendre biaiserait le jeu vers les
spectateurs les plus tardifs et priverait les pronostics les plus précoces de tout
intérêt. Sans classement national (voir plus bas), tous les archers démarrent à égalité
et les cotes se creusent au fil des scores réels ; avec un classement national, des
favoris apparaissent dès l'ouverture.

Les six types valent aussi bien pour les **épreuves individuelles que par équipes** :
équipes de club (3 archers) comme doubles mixtes (2 archers). Le format réglementaire
est respecté — 4 volées de 2 flèches par archer, premier à 5 points de set, barrage
d'une flèche par archer — de sorte que les scores exacts proposés sont ceux du format
équipes (6-0, 6-2, 5-1, 5-3, 5-4…) et non ceux de l'individuel.

Une équipe est identifiée par son club **et** son numéro d'équipe : un club qui aligne
deux équipes dans la même épreuve les voit distinguées.

### Le calcul de la difficulté

La valeur d'une issue n'est pas saisie à la main : elle sort d'un modèle nourri par les
qualifications — et, en amont de la première flèche, par le classement national.

1. **Profil de chaque archer.** ianseo enregistre chaque flèche individuellement, en
   qualification (`QuD<n>Arrowstring`) **comme en match** (`FinArrowstring`). Les deux
   alimentent le profil : un archer qui monte en puissance dans le tableau voit ses
   probabilités bouger aux tours suivants, un archer qui craque aussi. La loi obtenue
   est régularisée vers le profil du plateau — un archer n'ayant tiré que six flèches
   ne se voit pas attribuer une cote délirante.
2. **Avant la première flèche**, quand le module **REPARTITION_EPREUVES** est installé
   et que ses classements nationaux sont téléchargés, le profil de départ d'un archer
   est amorcé par sa force relative dans ce classement plutôt que par la seule moyenne
   neutre du plateau (voir « Classement national » plus bas). Sans ce module, tous les
   archers démarrent à égalité — pas de bug, juste pas encore d'information.
3. **Duel.** Par convolution exacte, on obtient la loi du total d'une volée, puis une
   chaîne de Markov sur les points de set donne d'un seul calcul la probabilité de
   victoire **et** la loi des scores exacts. Arc à poulies : loi du cumul des flèches.
   Aucune simulation Monte-Carlo, donc pas de bruit d'un rafraîchissement à l'autre.
   Pour une **équipe**, la volée n'est pas celle d'un tireur moyen : c'est la somme de
   2 flèches de chaque archer, donc la convolution de leurs lois individuelles. Deux
   pointures accompagnées d'un maillon faible n'ont pas la même dispersion que trois
   archers réguliers de même total — et ça change la cote.
4. **Direct.** À chaque volée saisie, la chaîne repart de l'état de sets courant : la
   cote suit le match.
5. **Tableau final.** La probabilité de gagner l'épreuve se propage dans l'arbre
   binaire du tableau, en forçant les matchs déjà joués à leur résultat réel.
6. **Qualifications.** Loi du score final = score acquis + flèches restantes ;
   intégration sur grille et loi de Poisson-binomiale pour les probabilités de rang.
7. **La foule.** La probabilité finale mélange le modèle et la répartition réelle des
   pronostics. À trois joueurs le modèle décide ; passé une vingtaine de pronostics sur
   un marché, la foule reprend la main.

Le modèle est **vérifiable** : `admin/calibrate.php` rejoue tous les duels déjà en
base, compare l'annoncé au réalisé et affiche le diagramme de fiabilité.

### Classement national (module REPARTITION_EPREUVES, optionnel)

PRONO fonctionne seul, sans aucune dépendance. Mais s'il trouve le module
**REPARTITION_EPREUVES** installé, avec des classements nationaux FFTA téléchargés et
rapprochés de la compétition en cours, il s'en sert pour désigner des favoris **dès
l'ouverture des pronostics de qualification**, avant qu'une seule flèche ne soit tirée.

Le principe reste prudent : on ne réutilise jamais une moyenne nationale telle quelle
(les compétitions ne se tirent pas toutes au même format), seulement la force
*relative* des archers entre eux au sein du classement national, reportée sur l'échelle
de la compétition du jour. Si le module n'est pas installé, ou que la compétition n'a
pas de classement rapproché, rien ne casse : le profil neutre habituel s'applique.

La console signale, à l'activation des pronostics de qualification, si ce module est
installé (avec un rappel d'actualiser les classements) ou non (avec un lien vers son
installation depuis la page de mise à jour de PRONO — même dépôt GitHub).

Le **score du 1er qualifié** et le **score du cut** vont plus loin : leurs 3 issues
(« - de X », « X-Y », « + de Y ») sont directement les scores réels de la catégorie —
X et Y viennent des meilleurs scores individuels du classement national, pas d'une
estimation. Ces deux marchés nécessitent donc REPARTITION_EPREUVES et un rapprochement
à jour ; sans classement national, ils n'apparaissent simplement pas (le tiercé, lui,
reste toujours disponible).

### Deux classements — et des classements de groupe

Le **classement de la compétition** ne compte que les points du jour. Le **classement
de la saison** additionne toutes les compétitions retenues — un onglet bascule de l'un
à l'autre, et affiche le nombre de compétitions jouées par chacun.

Chaque compétition porte une case **« compte pour le classement de la saison »** dans
la console. La décocher retire la compétition du cumul sans toucher à ses pronostics
ni à son classement propre : c'est ce qui permet d'écarter un essai.

Réimporter une compétition ne change rien : les points restent attachés à elle, comme
les pronostics.

En plus du classement général, un joueur peut créer un **groupe** — club, famille,
bande de copains — avec un nom et un mot de passe. Le classement bascule alors sur ce
groupe (compétition et saison) exactement comme le classement général, mais limité à
ses membres.

- **Créer** un groupe demande juste un nom (3-40 caractères) et un mot de passe (4
  caractères minimum) : le créateur en devient le propriétaire et premier membre.
- **Rejoindre** un groupe se fait dans un formulaire dédié : nom + mot de passe, comme
  une connexion. Aucune liste de groupes existants n'est affichée — seul le bon
  couple nom/mot de passe fait entrer, un mauvais renvoie un message générique (même
  principe anti-tâtonnement que la connexion d'un compte).
- Un joueur peut appartenir à **plusieurs groupes** en même temps.
- **Seul le créateur** (ou un administrateur, depuis la console) peut supprimer un
  groupe ; n'importe quel membre peut le quitter à tout moment.
- Si le créateur quitte le groupe ou supprime son compte, la propriété passe
  automatiquement au membre restant ayant le plus de **points de saison** ; si
  personne ne reste, le groupe disparaît avec lui.

### Confidentialité et consultation des pronostics

Chaque joueur choisit, depuis sa fiche de compte, qui peut voir ses pronostics :

- **Public** (par défaut) — visible par tous, depuis le classement général comme
  depuis un classement de groupe.
- **Mes groupes seulement** — visible uniquement par les joueurs qui partagent au
  moins un groupe avec lui.
- **Personne** — ses pronostics restent privés ; seul son score reste visible dans les
  classements.

Depuis n'importe quel classement, un appui sur le pseudo d'un participant affiche ses
pronostics de la compétition en cours, si sa confidentialité le permet — sinon un
message l'indique clairement, sans rien dévoiler.

### Comptes joueurs

Un compte = un **pseudo** et un **mot de passe** (4 caractères minimum), valable pour
**tout le serveur**. Un joueur s'inscrit une fois et retrouve son compte à la
compétition suivante — c'est ce qui rend le classement de saison possible. Le mot de
passe ne sert qu'à une chose : retrouver ses pronostics et ses points depuis un autre
appareil — téléphone déchargé, passage sur la tablette d'un ami.

- **Plusieurs appareils à la fois** : chaque connexion crée son propre jeton, donc se
  connecter sur le téléphone ne déconnecte pas la tablette. Se déconnecter ne ferme
  que l'appareil courant.
- Les mots de passe sont stockés en empreinte **bcrypt** (`password_hash`), jamais en
  clair. Le message d'échec est identique que le pseudo existe ou non, et une pause
  s'ajoute à la lenteur intrinsèque de bcrypt pour décourager le tâtonnement.
- Le jeton de session vit dans un cookie **httpOnly**, inaccessible au JavaScript.
- Depuis la fiche de compte (appui sur le score), un joueur peut changer son mot de
  passe ou se déconnecter.

### Lecture sur un téléphone tenu à la verticale

C'est la contrainte de conception principale : tout doit tenir en portrait.

- **Filtres par famille** de pronostics (Duels, Scores exacts, Vainqueurs,
  Qualifications), avec le nombre de marchés ouverts sur chacune.
- **Sections repliables** par épreuve, dont l'état est mémorisé d'une visite à l'autre.
- Les **scores exacts** — douze issues dont le libellé contient un nom complet — sont
  regroupés par vainqueur : un intertitre « Victoire de … » puis des pastilles ne
  portant que le score et la cote. Rien n'est tronqué.
- Au-delà de quatre issues, un marché passe en colonne unique pour que les noms
  restent entiers ; les libellés reviennent à la ligne au lieu d'être coupés.

### Survie au réimport de la compétition

Réimporter une compétition dans ianseo ne met pas à jour le tournoi existant : elle en
crée un **nouveau** (nouveau `ToId`, nouveaux `EnId`) et supprime l'ancien. Pour qui
réimporte à chaque mise à jour des résultats, cela signifierait perdre tous les
pronostics à chaque fois.

Deux ancres évitent ça :

- la compétition est identifiée par son **code** (`ToCode`, unique et stable dans
  ianseo), pas par son identifiant numérique ;
- les archers le sont par leur **numéro de licence** (`EnCode`), pas par leur `EnId`,
  et les équipes par **numéro de club + numéro d'équipe**.
  Les marchés et les issues sont donc désignés par des clés qui survivent à l'import.

Au premier accès à la console après un réimport, joueurs, points et pronostics sont
rattachés automatiquement au nouvel import, et un message le confirme. La face publique
bascule seule, sans intervention. Un garde-fou refuse la reprise si la nouvelle
compétition a **déjà** des joueurs ou des pronostics : mieux vaut ne rien faire que
d'écraser des pronostics récents.

### Fermer les pronostics sans fermer la porte

Deux niveaux, à ne pas confondre.

**Compétition active** (case à cocher) : c'est ce qui est servi publiquement. La
décocher coupe complètement l'accès — les joueurs ne voient plus rien.

**Prise de pronostics** (bouton) : la fermer arrête les nouveaux pronostics, mais les
joueurs continuent de consulter **leurs pronostics et le classement**. C'est ce qu'on
veut au moment où les matchs commencent, ou pour clore le jeu en fin de journée en
laissant chacun voir son score.

**Fermeture rapide, d'un clic, depuis n'importe où** : une petite barre flottante en
haut à droite de **toutes** les pages de ianseo propose « Fermer les prochains duels ».
Un appui ferme, pour chaque épreuve, la prochaine phase dont l'horaire prévu (le
planning ianseo, saisi via l'écran natif de gestion des horaires) est passé ou tombe
dans l'heure qui vient — et seulement celle-là : si la 1/16 et la 1/8 tirent toutes les
deux dans l'heure mais que la 1/16 n'a pas de résultat, seule la 1/16 se ferme. Sans
planning saisi pour une épreuve, repli sur l'ancien critère : déjà commencée. Le
vainqueur d'épreuve se ferme avec la toute première phase du tableau.

**Épreuve par épreuve, ou phase par phase** (page « Types & grille » de la console) :
un tableau croise les épreuves et les types de pronostics (duel par phase, vainqueur,
tiercé, fourchettes) — chaque case s'ouvre ou se ferme d'un clic, et deux boutons en
tête de colonne permettent de tout fermer ou tout rouvrir d'un coup pour un type donné.
C'est le geste courant en compétition — les résultats n'arrivant qu'au retour des
archers de la cible, on ferme au moment où les tirs commencent, sans toucher aux
autres épreuves ni aux autres phases.

La fermeture globale peut aussi être **programmée** : quatre raccourcis (15 min, 30 min,
1 h, 2 h) ou une heure précise. Les joueurs voient alors un compte à rebours
(« Fermeture des pronostics dans 25 min ») qui devient un bandeau rouge à l'échéance.

L'heure est comparée par **MySQL**, jamais par PHP : ianseo force PHP en UTC alors que
l'organisateur raisonne en heure murale, et la face publique n'a pas le même fuseau.
La console affiche l'horloge du serveur à côté du champ, pour lever toute ambiguïté.

### Intégrité

Le point sensible d'un pronostic en direct n'est pas le modèle, c'est le **décalage de
saisie** : ianseo enregistre une volée 30 à 60 secondes après le terrain. Sans
précaution, quelqu'un dans les gradins peut pronostiquer sur un résultat qu'il connaît
déjà.

- **Un duel se ferme dès la première flèche saisie**, pas à la fin de la volée. ianseo
  n'écrit la chaîne de flèches d'un match qu'au moment où une valeur est réellement
  entrée : c'est le signal le plus précoce disponible, et donc la meilleure parade au
  décalage entre le terrain et le serveur. C'est aussi la limite pour changer d'avis.
- Les marchés de qualification se ferment sur la dernière volée.
- La valeur en points est **figée au moment du pronostic**.
- Un seul pronostic par personne et par marché, jamais dédoublé.
- **Un pronostic sur un duel se change** tant que le match n'a pas commencé : le marché
  se ferme de toute façon à la première volée, donc rien ne s'achète avec l'attente.
- **Un pronostic « au long cours » est définitif** (vainqueur d'épreuve, marchés de
  qualification). Ces marchés restent ouverts pendant que la compétition se joue :
  autoriser le changement reviendrait à attendre l'élimination de son favori pour se
  reporter sur le suivant.
- Les issues **éliminées disparaissent** de la liste au fil des tours. Un archer sorti
  au premier tour n'encombre plus le marché du vainqueur d'épreuve, et surtout il
  n'absorbe plus une part de probabilité qui n'existe pas : les points affichés
  restent justes tour après tour. Si ton pronostic est éliminé, l'application te le
  dit — il sera compté perdu au règlement.
- Le serveur relit toujours la base : un téléphone qui affiche encore un marché fermé
  se voit refuser le pronostic.
- Par défaut, seules les **catégories adultes** sont proposées (U11 à U18 écartées).

## Base de données

Neuf tables, toutes préfixées `PRONO_` (`utf8mb4_unicode_ci`) :

| Table | Rôle |
|---|---|
| `PRONO_Config` | réglages par compétition (ouverture, barème, points de base, marchés actifs, affiche) |
| `PRONO_Users` | joueurs : pseudo, empreinte du mot de passe, niveau de confidentialité — comptes globaux au serveur |
| `PRONO_Scores` | points par (joueur, compétition) — le classement de saison en fait la somme |
| `PRONO_Tokens` | sessions ouvertes, une ligne par appareil connecté |
| `PRONO_Markets` | marchés : type, libellé, statut (`OPEN`/`LOCKED`/`SETTLED`) |
| `PRONO_Selections` | issues d'un marché : probabilité, difficulté, nombre de pronostics |
| `PRONO_Bets` | pronostics : issue(s) choisie(s), **points figés**, statut. Le tiercé porte 3 issues (1er/2e/3e) sur la même ligne. |
| `PRONO_Groups` | groupes de joueurs : nom, mot de passe (empreinte bcrypt), propriétaire |
| `PRONO_GroupMembers` | appartenance aux groupes (un joueur peut en rejoindre plusieurs) |

Aucune écriture dans les tables ianseo : le module est en lecture seule côté sportif
(et en lecture seule, optionnelle, des tables `REP_*` de REPARTITION_EPREUVES s'il est
installé).

## Accès

| Page | Qui |
|---|---|
| `index.php` — console | organisateur (`AclQualification` / écriture) |
| `admin/markets.php` — types & grille | organisateur (`AclQualification` / écriture) |
| `admin/groups.php` — groupes & joueurs | organisateur (`AclQualification` / écriture) |
| `admin/qrcode.php` — affiche à imprimer | organisateur (`AclQualification` / écriture) |
| `screen.php` — écran de salle | `AclQualification` / lecture |
| `admin/calibrate.php` | organisateur (`AclQualification` / écriture) |
| `admin/update.php` | administrateur serveur |
| `public/` — face publique | tout le monde, sans authentification |

## Déploiement : faire jouer des téléphones en 4G

C'est le vrai sujet. Le serveur ianseo est sur un réseau fermé ; les téléphones sont
sur leur data. Un **tunnel sortant Cloudflare** résout ça sans ouvrir un seul port :
la machine ianseo établit une connexion vers Cloudflare, qui publie une URL HTTPS.
Pas d'IP publique, pas de configuration de box.

### 1. Un vhost Apache dédié à la face publique

**On ne publie pas ianseo.** On expose uniquement `public/`, dont le contenu ne charge
jamais `config.php` de ianseo (ni session, ni ACL, ni routage ianseo).

D'abord, activer **deux** modules dans `apache/conf/httpd.conf` — ils sont commentés
par défaut dans XAMPP. `mod_deflate` compresse, mais la directive
`AddOutputFilterByType` qui le déclenche appartient à `mod_filter` : sans les deux,
Apache refuse de démarrer.

```apache
LoadModule deflate_module modules/mod_deflate.so
LoadModule filter_module  modules/mod_filter.so
```

Puis, dans `apache/conf/extra/httpd-vhosts.conf` :

```apache
Listen 8081
<VirtualHost *:8081>
    DocumentRoot "C:/ianseo/htdocs/Modules/Custom/PRONO/public"
    <Directory "C:/ianseo/htdocs/Modules/Custom/PRONO/public">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE application/json text/html application/javascript text/css
    </IfModule>
    ErrorLog  "logs/prono-error.log"
    CustomLog "logs/prono-access.log" common
</VirtualHost>
```

Vérifier la configuration avec `apache\bin\httpd.exe -t` (doit répondre `Syntax OK`)
**avant** de redémarrer Apache — sous XAMPP, Apache tourne généralement depuis le
Control Panel et non en service : le redémarrage se fait par **Stop** puis **Start**.
Contrôler ensuite `http://localhost:8081/`.

### 2. Le tunnel

```powershell
winget install --id Cloudflare.cloudflared
cloudflared tunnel --url http://localhost:8081
```

La commande affiche une URL en `https://xxxx.trycloudflare.com` : c'est l'adresse à
donner aux spectateurs (QR code sur l'écran de salle). Pour une URL stable et un nom
de domaine à soi, créer un tunnel nommé (`cloudflared tunnel create`) et le déclarer
en service Windows.

**Prérequis unique** : un accès internet *sortant* sur la machine ianseo. Le partage
de connexion d'un téléphone suffit largement — le trafic est du texte compressé.

### 3. Ce qui tient la charge

Le moteur écrit un `snapshot.json` pré-calculé ; l'API le sert tel quel, en gzip. Les
recalculs n'ont lieu que quand les données ianseo bougent (empreinte CRC), et un
verrou fichier garantit qu'une seule requête déclenche le calcul. Compter environ
10 ko toutes les 10 secondes par téléphone, largement réduits par la compression.

Aucune tâche planifiée n'est nécessaire : la face publique déclenche le moteur
elle-même. `cron/poll.php` reste disponible pour le diagnostic ou pour imposer un
rythme fixe.

### 4. Durcir l'accès base (optionnel)

Par défaut le module réutilise les identifiants MySQL de ianseo. Pour restreindre ce
que la face publique peut atteindre, créer un utilisateur dédié :

```sql
CREATE USER 'prono_web'@'localhost' IDENTIFIED BY 'un-mot-de-passe-solide';
GRANT SELECT ON ianseo.Finals             TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.Qualifications     TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.Entries            TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.Individuals        TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.Events             TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.Tournament         TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.Countries          TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.DistanceInformation TO 'prono_web'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `ianseo`.`PRONO\_%` TO 'prono_web'@'localhost';
```

Si le classement national (module REPARTITION_EPREUVES) doit rester actif avec un accès
durci, ajouter aussi la lecture de ses tables :

```sql
GRANT SELECT ON ianseo.REP_Rangs       TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.REP_Classements TO 'prono_web'@'localhost';
GRANT SELECT ON ianseo.REP_Config      TO 'prono_web'@'localhost';
```

puis créer `data/db.local.json` :

```json
{ "host": "localhost", "user": "prono_web", "pass": "un-mot-de-passe-solide", "name": "ianseo" }
```

Ce fichier n'est jamais synchronisé depuis GitHub et `data/` est bloqué par `.htaccess`.

## Mise en route

1. Menu **Pronostics → Console** : les tables se créent au premier accès. Régler les
   points de base et le barème, cocher **Compétition active**, enregistrer.
2. Menu **Pronostics → Types & grille** : choisir les épreuves et les types de
   pronostics, puis affiner épreuve par épreuve et phase par phase si besoin.
3. *(Recommandé)* **Calibrer sur l'historique** une fois, si la base contient des
   compétitions passées.
4. Monter le vhost et le tunnel, afficher l'URL sur l'écran de salle.
5. **Préparer l'affiche à imprimer** (`admin/qrcode.php`) : coller l'adresse du tunnel,
   personnaliser le titre et le texte, choisir une affiche A4 ou quatre affichettes
   par page à découper pour les tables. Le PDF reprend l'en-tête et le pied de page de
   compétition de ianseo (logos, nom de l'épreuve, lieu et dates). L'aperçu à l'écran
   permet de scanner le code depuis un téléphone en 4G **avant** d'imprimer — l'adresse
   d'un tunnel rapide change à chaque relance de `cloudflared`.

## Installation, mise à jour, désinstallation

Standard des modules custom : `admin/update.php` (réservé à l'administrateur serveur)
vérifie la version publiée sur GitHub, met à jour les fichiers et resynchronise
`_shared/`. La zone repliée « Désinstaller le module » supprime le dossier et, sur
demande explicite, les tables `PRONO_*`.

Le vhost Apache et le tunnel Cloudflare ayant été créés à la main, ils doivent être
retirés séparément.
