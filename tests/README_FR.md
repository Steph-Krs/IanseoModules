# Banc de test

Contrôles automatiques des modules, lancés par GitHub sur chaque pull request, chaque envoi,
et une fois par semaine. Ils se lancent aussi sur toute machine équipée de Docker. Rien dans
ce dossier n'est distribué : il ne figure dans aucun `files[]`.

## Ce qui est contrôlé

Le banc prépare ianseo **comme une installation neuve** :

1. le paquet officiel publié sur ianseo.net, vérifié par son empreinte SHA-256 ;
2. installé : `Install/install.sql`, puis les migrations de base du cœur ;
3. amené à la dernière version par le système de mise à jour du cœur lui-même (la page
   « Mettre à jour ianseo »). La version atteinte est affichée en tête de chaque exécution :
   si un contrôle passe au rouge sans changement dans le dépôt, comparez-la à la dernière
   exécution verte.

**Conformité**, pour chaque module du dépôt : `bin/compliance.php` contrôle les règles que
l'équipe ianseo demande aux modules contribués (casse exacte des tables du cœur, écritures
en base, sûreté de `menu.php`, ordre des migrations, commentaires en anglais, textes non
traduits, fonctions de chaîne octet, BOM, `JsonOut()`, pas d'impression par le navigateur,
casse des noms de fichiers…). Les modules écrits avant une règle portent une dette connue,
comptée dans `compliance-baseline.json` par module, contrôle et fichier : seules les erreurs
au-delà font échouer. Quand vous remboursez une partie de la dette, réécrivez la référence
dans le même commit (`php tests/bin/compliance.php <modules> --write-baseline=tests/compliance-baseline.json`).

Puis, pour chaque module décrit dans `modules/<MODULE>.json`, sur chaque serveur de base de
données de la liste (MariaDB 10.11 et 11.4, MySQL 8.0 et 8.4), chacun avec ses réglages par
défaut :

- **Installation neuve** — sur une base construite depuis `install.sql`, jamais sur une copie
  d'une base de développement, la fonction de schéma du module va au bout, crée toutes les
  tables listées dans `tables` de son `version.json` (écrites exactement : les noms de tables
  sont sensibles à la casse sous Linux), et un second passage, qui est une seconde session,
  ne change rien.
- **Scripts** — les scripts de test du module ; code de sortie 0 = réussi.
- **Pages** — chaque page listée pour le module, dans chaque langue qu'il propose, servie
  par un vrai serveur web, sur une base qui porte les tables de tous les modules et une
  compétition fictive créée par le formulaire « Nouvelle compétition » de ianseo lui-même
  (règles françaises, salle 18 m, archers fictifs). `pages` s'ouvrent sans compétition,
  `tour_pages` après ouverture de la compétition fictive, dans une session qui garde ses
  cookies comme un navigateur. Une page échoue si elle ne répond pas 200, renvoie ailleurs
  (les redirections ne sont pas suivies : une page renvoyée vers l'accueil semblait
  bonne), affiche une clé de traduction au lieu du texte (`[[Clé]@…]`), ou lève un
  avertissement, une notice ou une erreur fatale PHP dans un fichier de module. Les
  avertissements du cœur sont comptés, sans faire échouer.
- **Navigateur** — `dom/<MODULE>.js`, pour les pages qui construisent leur contenu en
  JavaScript : la vraie page est chargée avec son script dans jsdom, puis contrôlée (pas de
  `[object Object]`, pas de clé de traduction brute, le contenu est bien construit).

## Le lancer

```
tests/run.sh                  # tous les modules, MariaDB 10.11
tests/run.sh --db mysql-8.0   # mariadb-10.11, mariadb-11.4, mysql-8.0, mysql-8.4
tests/run.sh AUTH TIRAGE      # quelques modules
tests/run.sh --force-core     # retélécharger et remettre à jour le cœur
```

Le cœur est téléchargé et mis à jour au plus une fois par jour (`tests/.cache/`, ignoré par
git). Le dépôt est monté en lecture seule, aucun port n'est ouvert, et le serveur de base de
données garde ses données en mémoire le temps de l'exécution.

## Ajouter un module

Créer `modules/<MODULE>.json` (format détaillé dans `README.md`) :

```json
{
    "pages": ["/Modules/Custom/MODULE/admin/update.php"],
    "tour_pages": ["/Modules/Custom/MODULE/index.php"],
    "languages": ["en", "fr"],
    "schema": { "require": ["lib/schema.php"], "call": ["mod_schema"] },
    "scripts": ["MODULE/tests/run.php"]
}
```

Les données de test sont toujours inventées : aucun nom ni numéro de licence réel dans ce dépôt.
