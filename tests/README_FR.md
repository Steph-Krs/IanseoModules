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

Puis, pour chaque module décrit dans `modules/<MODULE>.json`, sur chaque serveur de base de
données de la liste (MariaDB 10.11 et 11.4, MySQL 8.0 et 8.4), chacun avec ses réglages par
défaut :

- **Installation neuve** — sur une base construite depuis `install.sql`, jamais sur une copie
  d'une base de développement, la fonction de schéma du module va au bout, crée toutes les
  tables listées dans `tables` de son `version.json` (écrites exactement : les noms de tables
  sont sensibles à la casse sous Linux), et un second passage, qui est une seconde session,
  ne change rien.
- **Scripts** — les scripts de test du module ; code de sortie 0 = réussi.

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
    "schema": { "require": ["lib/schema.php"], "call": ["mod_schema"] },
    "scripts": ["MODULE/tests/run.php"]
}
```

Les données de test sont toujours inventées : aucun nom ni numéro de licence réel dans ce dépôt.
