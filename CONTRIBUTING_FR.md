# Contribuer aux modules

*[English version](CONTRIBUTING.md)*

Merci de vouloir améliorer ces modules ! Signalements, idées et corrections sont les bienvenus,
en français comme en anglais (*issues and pull requests in English are welcome too*).

## Signaler un problème ou proposer une idée

Ouvrez une **issue** avant d'écrire du code. Un sujet par issue.

Pour un problème, trois parties suffisent :

- **Constat** : ce qui se passe, sur quel écran, dans quel module et quelle version
  (page « Mise à jour » du module) ;
- **Comportement attendu** : ce qui devrait se passer ;
- **Pour reproduire** : les étapes, sur une compétition d'essai si possible.

Une faille de sécurité ne se signale **pas** dans une issue publique : utilisez
**Security › Report a vulnerability** sur la page du dépôt.

## Proposer une modification (pull request)

- **Une pull request par sujet**, liée à son issue (`Closes #N` dans la description).
- **Pas besoin de modifier `version.json` ni `CHANGELOG.md`.** Le numéro de version est calculé au
  moment de publier, en regroupant ce qui part ensemble ; deux PR qui numérotent chacune une
  version entrent en conflit.
- Dans la description : ce que la PR corrige ou apporte, comment, **les changements de base de
  données** (tables, colonnes) et ce que vous avez testé.
- Messages de commit au format conventionnel, en anglais : `fix(MODULE): …`, `feat(MODULE): …`.

### Comment une contribution est intégrée

Le dépôt est aussi le **canal de mise à jour** : tout ce qui arrive sur la branche `main` est
proposé aussitôt à toutes les installations. Une pull request n'est donc **pas fusionnée
directement**. Elle est reprise dans un commit du module, passée au banc de tests (installation
neuve, toutes les langues, contrôles de conformité aux règles de ianseo), ajustée si besoin, puis
publiée avec sa version.

Votre travail reste crédité : vous êtes **co-auteur du commit** (`Co-authored-by`, qui le fait
apparaître dans vos contributions GitHub) et **cité dans le `CHANGELOG.md`** du module. La pull
request est ensuite fermée avec un message qui renvoie au commit : elle apparaît « Closed » et
non « Merged », c'est normal.

## Règles du code

Ces règles viennent de différents échanges avec l'équipe ianseo ou de pannes réelles. Une PR qui ne
les suit pas peut être reprise, mais elle demandera plus de travail avant publication.

### Où écrire

- Un module est un dossier de `Modules/Custom/` qui contient un `module.json`. **On ne modifie
  jamais un fichier du cœur de ianseo** : une mise à jour officielle remet tout `htdocs/` à zéro,
  sauf `Modules/Custom/`.
- Un module fonctionne **seul** : il ne `require` jamais un autre module et ne suppose pas sa
  présence. Les échanges passent par des conventions (clés de session, tables lues si elles
  existent), avec un repli quand l'autre module est absent.
- Un nouveau fichier distribué s'ajoute au `files[]` de `version.json`, sinon il n'arrive jamais
  chez les utilisateurs.
- Aucune dépendance externe nouvelle sans en discuter d'abord dans une issue.

### Langues

- **Code, noms de variables et commentaires en anglais** : le cœur de ianseo est maintenu par une
  équipe internationale.
- **Aucun texte lu par l'utilisateur dans le code** : tout passe par les fichiers de langue du
  module, dans toutes les langues qu'il propose.
- Sur un texte saisi par un humain (nom, club, description), utilisez les fonctions `mb_*`
  (`mb_substr`, `mb_strlen`…) : `substr()` peut couper un caractère accentué en deux.

### Base de données

- Requêtes par les fonctions du cœur : `safe_r_sql()`, `safe_w_sql()`, `safe_fetch()`, et
  `StrSafe_DB()` pour toute valeur.
- ⚠ **Une erreur SQL arrête la page** (`safe_error()` fait un `exit` qu'aucun `try/catch` ne
  rattrape). Dans `menu.php`, inclus sur toutes les pages de ianseo, elle rend tout le site
  inaccessible. `menu.php` **ne fait que lire**, et toute lecture d'une table du module y est
  protégée (3ᵉ argument `$force` de `safe_r_sql()`).
- Les tables du module se créent **à l'ouverture d'une page du module**, par sa fonction de schéma
  (`CREATE TABLE IF NOT EXISTS`, puis seulement les `ALTER`), jamais depuis `menu.php`. Il n'y a
  aucun script SQL à lancer à la main. Testez sur une **base vide** : une base de développement a
  déjà les tables et cache les erreurs d'ordre.
- Nouvelles tables : préfixe du module, `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, et
  `COLLATE utf8mb4_unicode_ci` côté module dans une jointure sur une colonne texte du cœur.
- Une colonne qui recopie un identifiant du cœur reprend **son type** (`ToId`, `EnId` :
  `INT UNSIGNED`).
- Noms de tables du cœur **avec leur casse exacte** (`Tournament`, `Entries`, `Qualifications`) :
  un serveur Linux distingue les majuscules, contrairement à un poste Windows.
- Pas d'alias de table : les noms de colonnes de ianseo sont uniques (préfixes `En`, `Qu`, `To`…).
  Seule exception, une même table jointe deux fois.
- `Qualifications` est en relation 1:1 avec `Entries` (`INNER JOIN`) et **n'a pas de colonne de
  compétition** : tout `UPDATE` passe par `Entries`, sinon il touche toutes les compétitions.
- Cibles : lisez `QuTarget` et `QuLetter`. `QuTargetNo` n'est plus mis à jour par les écrans de
  ianseo.

### Pages, PDF et réponses

- **Documents papier : classes PDF du cœur** (`Common/pdf/IanseoPdf.php` et ses dérivées), jamais
  une page HTML avec `window.print()`.
- **Classements : classes `Rank` du cœur** (`Obj_RankFactory::create()`), sans recopier leurs
  requêtes.
- Points AJAX : réponse par `JsonOut()`, avec `error` (0 = succès) et `msg`. Chargez une liste en
  un seul appel, pas un appel par ligne.
- Page construite en PHP continu (`echo`), sans alterner balisage et `<?php … ?>` à chaque ligne.
- Un bouton porte du texte traduit, ou une icône fournie par ianseo (`Common/Images/`) ; un
  symbole seul a au moins un `title` traduit.
- Fichiers en UTF-8 **sans BOM** : les trois octets du BOM, envoyés avant le JSON, le rendent
  illisible.
- Accès : `checkFullACL()` en tête de page, `hasFullACL()` pour un contrôle dans la page.

## Licence

En contribuant, vous acceptez que votre contribution soit publiée sous la licence du module
concerné : son fichier `LICENCE.md` s'il en a un, sinon le fichier [`LICENSE`](LICENSE) à la racine
du dépôt.
