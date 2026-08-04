# PRONO — état des lieux avant mise en production

Document d'aide à la décision : ce que le module lit, ce qu'il écrit, ce qu'il expose,
et ce qu'il reste à faire avant de l'installer sur un serveur de production.

Établi par relevé exhaustif du code (`grep` sur toutes les requêtes SQL du module),
pas de mémoire.

## 1. Base de données

### Tables ianseo — **lecture seule, sans aucune exception**

| Table | Ce qui est lu | Pourquoi |
|---|---|---|
| `Tournament` | `ToId`, `ToCode`, `ToName`, `ToNumDist`, `ToNumEnds`, `ToMaxDistScore` | identité de la compétition, nombre de flèches prévues |
| `DistanceInformation` | `DiDistance`, `DiEnds`, `DiArrows` (type `'Q'`) | nombre exact de flèches par distance |
| `Events` | `EvCode`, `EvEventName`, `EvTeamEvent`, `EvMatchMode`, `EvFinalFirstPhase`, `EvElimType` | liste des épreuves, format des matchs |
| `Entries` | `EnId`, `EnCode`, `EnFirstName`, `EnName`, `EnClass`, `EnCountry`, `EnAthlete` | identité des archers, licence, catégorie d'âge, participation individuelle réelle |
| `Individuals` | `IndId`, `IndTournament`, `IndEvent` | rattachement archer → épreuve |
| `Qualifications` | `QuScore`, `QuClRank`, `QuIrmType`, `QuD1..8Arrowstring` | profil de tir servant au calcul des probabilités |
| `Finals` | grille d'élimination individuelle | duels, scores, état en direct |
| `TeamFinals` | grille d'élimination par équipes | idem pour les équipes |
| `Teams` | `TeCoId`, `TeSubTeam`, `TeScore`, `TeRank`, `TeIrmType` | équipes et leur score de qualification |
| `TeamComponent` | `TcCoId`, `TcSubTeam`, `TcId` | composition des équipes |
| `Countries` | `CoId`, `CoCode`, `CoName` | noms et numéros de clubs |

**Aucun `INSERT`, `UPDATE`, `DELETE`, `ALTER` ou `CREATE` ne vise une table ianseo.**
Le module ne peut pas altérer un résultat sportif, une inscription ou un classement.

### Tables d'un autre module — lecture seule, optionnelle

Si le module **REPARTITION_EPREUVES** est installé, `REP_Rangs`, `REP_Classements` et
`REP_Config` sont lues (jamais écrites) pour amorcer le profil des archers avant leur
première flèche à partir du classement national FFTA. Aucune dépendance dure : absence
du module, tables manquantes ou droits insuffisants retombent silencieusement sur le
profil neutre habituel (voir `CLAUDE.md`, section « Classement national »).

### Tables du module — lecture et écriture

Sept tables, toutes préfixées `PRONO_` : `PRONO_Config`, `PRONO_Users`, `PRONO_Scores`,
`PRONO_Tokens`, `PRONO_Markets`, `PRONO_Selections`, `PRONO_Bets`.

Elles sont créées automatiquement au premier accès à la console (`CREATE TABLE IF NOT
EXISTS`), et `prono_migrate()` y applique les évolutions de schéma (`ALTER TABLE`) à
chaque chargement de la console. **Ces opérations de structure ne portent que sur les
tables `PRONO_*`.**

### Fichiers

Le module écrit uniquement dans son propre dossier `data/` : snapshots JSON, verrou du
moteur, configuration locale optionnelle. Ce dossier est interdit d'accès web par
`.htaccess`, tout comme `lib/`. Aucun fichier ianseo n'est modifié.

## 2. Ce qui est exposé sur internet

Seul le dossier `public/` est publié, via un vhost Apache dédié (port 8081) tunnelisé.
Il contient deux points d'entrée : la page (`index.php`) et l'API (`api.php`).

**Ce code ne charge jamais `config.php` de ianseo** : pas de session ianseo, pas d'ACL,
pas de routage ianseo, aucune fonction ianseo en mémoire. Il ouvre sa propre connexion
PDO (`lib/db.php`). La surface atteignable depuis internet se limite donc à ces deux
fichiers et aux tables `PRONO_*`.

L'application ianseo elle-même, sur le port 80, **n'est pas publiée** par le tunnel.

## 3. Sécurité applicative

- **Requêtes préparées partout.** Aucune concaténation de valeur dans une requête.
- **Mots de passe** en empreinte bcrypt (`password_hash`), jamais en clair. Message
  d'échec identique que le pseudo existe ou non, plus une pause, pour ne pas
  transformer l'API en annuaire de pseudos.
- **Jetons de session** : valeur aléatoire de 32 octets côté client dans un cookie
  `httpOnly` + `SameSite=Lax` (+ `Secure` en HTTPS), **empreinte SHA-256 en base**.
- **Pages d'administration** : `AclQualification` en écriture pour la console et le
  QR code, `upd_admin_guard()` (AclRoot + vue Administrateur serveur) pour la mise à jour.
- **Transactions** sur la prise de pronostic et le règlement, avec `FOR UPDATE` sur la
  ligne joueur.
- **Aucune dépendance externe** : ni CDN, ni bibliothèque tierce. Le QR code utilise
  tcpdf, déjà présent dans ianseo.

## 4. Données personnelles

Un pseudo choisi librement et une empreinte de mot de passe. **Pas d'adresse e-mail,
pas de nom, pas de numéro de téléphone, pas d'adresse IP conservée.** Les pronostics
sont rattachés au pseudo. L'empreinte du jeton de session est supprimée à la
déconnexion.

Les noms d'archers affichés proviennent de ianseo et sont ceux, déjà publics, des
listes de départ et des résultats.

## 5. À faire avant la production

1. **Créer l'utilisateur MySQL restreint.** Par défaut le module réutilise les
   identifiants de ianseo, qui ont tous les droits. Sur un serveur exposé, c'est le
   point à traiter en priorité : la procédure et le `GRANT` complet sont dans
   `README_PRONO.md`, section « Durcir l'accès base ». Le fichier `data/db.local.json`
   n'est jamais synchronisé depuis GitHub.
2. **Limiter le débit sur `api.php`** si le tunnel est exposé largement : le module ne
   fait que ralentir les tentatives de connexion (bcrypt + pause). Une règle de
   limitation côté Cloudflare est le complément naturel.
3. **Sauvegarder** avant la première installation : la création des tables et les
   migrations sont sûres et testées, mais une sauvegarde reste la bonne pratique.
4. **Vérifier le vhost** : `DocumentRoot` sur `public/` uniquement, jamais sur
   `htdocs/`. C'est ce qui garantit que ianseo n'est pas joignable depuis le tunnel.

## 6. Points d'attention connus

- **La console applique les migrations automatiquement** au chargement. C'est
  volontaire (le module doit survivre à une mise à jour sans intervention), mais cela
  signifie qu'ouvrir la console peut modifier la structure des tables `PRONO_*`.
- **La désinstallation** (`_shared/uninstall.php`, réservée à l'administrateur) peut
  supprimer les tables `PRONO_*` si la case correspondante est cochée. Elle ne touche
  jamais aux tables ianseo.
- **Le moteur recalcule** quand les données ianseo changent : compter quelques
  secondes de CPU par recalcul sur une grosse compétition. Aucune écriture ianseo,
  donc aucun risque de contention sur la saisie des résultats.
