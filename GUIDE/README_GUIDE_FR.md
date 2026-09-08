# Guide interactif

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Il affiche un **panneau latéral persistant** sur toutes les pages de ianseo et accompagne
l'utilisateur pas à pas : il surligne l'élément sur lequel agir et attend que l'action ait
réellement été faite. Écrit pour les clubs qui organisent leurs premières compétitions sur ianseo,
et pour quiconque aborde une partie du logiciel qu'il n'a jamais utilisée.

Les formations sont des fichiers de contenu, pas du code : un organisateur ou une fédération peut
écrire les siennes sans toucher au module.

## Fonctionnalités

- 📖 **Formations multi-étapes** avec navigation Précédent / Suivant et barre de progression.
- 🎯 **Surbrillance dans la page** : un voile et une flèche animée désignent l'élément à utiliser,
  une info-bulle dit quoi en faire. La surbrillance survit au re-rendu partiel d'une page ianseo.
- ⚡ **Étapes réactives** : une étape peut attendre un événement DOM (un clic, une saisie) ou une
  *condition* évaluée côté serveur — compétition ouverte, épreuve configurée, au moins huit
  archers dans une même catégorie. Une étape n'avance que lorsque la chose a vraiment été faite.
- 🧩 **QCM et défis** : au-delà du guide, une formation peut porter un QCM et un défi à réaliser
  sans aide dans le vrai logiciel. Les réussir donne une cible de bronze, d'argent ou d'or.
- 🧭 **Checklists et dépannage** : deux autres types de contenu — une checklist filtrée par les
  réponses à quelques questions, et un arbre de décision qui mène à une solution.
- 💾 **Suivi de progression**, enregistré côté serveur et cloisonné par utilisateur : plusieurs
  organisateurs peuvent partager une installation sans partager leur progression.
- 💡 **Aide contextuelle** : sur n'importe quelle page ianseo, le module peut proposer les
  formations qui en parlent.
- ✏️ **Éditeur intégré** (administrateur) : construction visuelle d'une formation, enregistreur
  qui capture les triggers en cliquant simplement dans le logiciel, import / export, aperçu en
  direct.

## Conditions

Une condition demande à la base ianseo si quelque chose a été accompli. C'est ce qui fait du
module autre chose qu'un diaporama : une étape peut exiger que les cibles soient réellement
attribuées, et un défi peut être jugé sur l'état de la compétition plutôt que sur une réponse à
un QCM.

Les conditions sont définies dans `conditions.json` et se construisent depuis les écrans
d'administration, sans écrire de SQL. Toutes lisent ; aucune n'écrit.

## Internationalisation

**L'interface** suit le mécanisme de langue du cœur : des fichiers `languages/<code>.php`
définissant un tableau `$lang`, l'anglais servant de repli pour toute clé manquante. Anglais,
français, italien, allemand et espagnol sont livrés. Les chaînes dont le lecteur de formation a
besoin côté navigateur lui sont transmises par `menu.php` ; toute clé nommée `Js…` y va
automatiquement, il suffit donc de la nommer ainsi.

**Le contenu des formations** est traduit à l'intérieur du fichier de contenu lui-même : une
formation reste un seul document avec un seul numéro de version. Seul le *texte* d'un champ
devient une table de langues :

```json
"title": "Ma première compétition",
"title": { "fr": "Ma première compétition", "en": "My first competition" }
```

Les deux formes sont valides, et une chaîne simple est réputée écrite dans la langue déclarée par
le champ `lang` du fichier (l'anglais à défaut). La structure autour du texte — étapes, triggers,
sélecteurs, pages — n'est **jamais** dupliquée : la dupliquer laisserait les langues diverger, et
une formation dont la version française désigne un autre élément que l'anglaise est pire qu'une
formation sans version française.

Le lecteur obtient la formation dans la langue de son interface. À défaut, l'anglais ; à défaut,
la langue dans laquelle la formation a été écrite — une formation traduite seulement en italien
est donc quand même servie, jamais affichée vide. Une langue régionale compte au passage comme sa
langue parente : un lecteur en français canadien obtient le texte français avant qu'on envisage
l'anglais.

L'éditeur a un sélecteur de langue : on choisit une langue et les champs affichent celle-ci sans
toucher aux autres. Un champ pas encore traduit s'affiche **vide** plutôt qu'avec le texte
d'origine, pour que ce qui manque se voie d'un coup d'œil.

Tous les écrans sont traduits dans les cinq langues : le catalogue, le panneau, le lecteur de
formation, la liste des contenus, les deux éditeurs, le constructeur de conditions, la page de
mise à jour et l'aide à la rédaction.

Les traductions sont réparties en **sections**, exactement comme le cœur répartit les siennes
entre `Common.php`, `Tournament.php`, `Errors.php`… avec `get_text($key, $module)` qui ne charge
que celle dont il a besoin. Ici les chaînes du module sont `languages/<code>.php`, et la
documentation de rédaction est la section `help`, `languages/help/<code>.php` — même format de
tableau `$lang`, lu par `guide_text($key, $a, 'help')`. Cette séparation est utile : les chaînes
principales sont chargées sur **chaque** page de ianseo, puisque le module y injecte son panneau,
alors que la documentation fait plusieurs kilooctets utiles sur un seul écran.

Un fichier de langue ne contient **que des chaînes**, là encore comme ceux du cœur. Tout le
balisage de la documentation de rédaction — titres, tableaux, listes — vit dans `admin/help.php`,
qui appelle `guide_text()` pour chaque phrase ; seule la mise en valeur en ligne (`<b>`, `<i>`,
`<code>`) reste dans la chaîne, parce qu'elle fait partie de la phrase et la suit à la traduction.
Un traducteur ne touche donc jamais au balisage, et une modification de la mise en page se fait
une fois au lieu de cinq.

Les libellés génériques des pages de mise à jour et de désinstallation viennent de
`_shared/languages/`, et sont donc traduits une fois pour tous les modules.

Toutes les formations livrées avec le module sont écrites dans les cinq langues, QCM et défi
compris. Partout où une formation nomme une entrée de menu, le libellé est repris des fichiers de
langue du cœur plutôt que traduit librement : une formation qui nommerait ce que le lecteur ne
trouve pas serait pire qu'une formation qu'il ne peut pas lire. Les libellés des conditions
portent leurs langues de la même façon.

## Base de données

Trois tables, créées à la première ouverture d'une page du module :

| Table | Contient |
|---|---|
| `GuideProgress` | Où en est chaque utilisateur dans chaque formation, et quelles activités il a réussies |
| `GuideVisits` | Les pages qu'un utilisateur a ouvertes, pour les conditions qui s'en servent |
| `GuidePrefs` | Les préférences par utilisateur, dont l'activation de l'aide contextuelle |

Les formations, checklists et arbres de dépannage sont des fichiers JSON dans `content/`, pas des
lignes en base.

Les versions antérieures du module nommaient ces tables `GUIDE_Progress`, `GUIDE_Visits` et
`GUIDE_Prefs`. Elles sont renommées automatiquement, données conservées, et une vue portant
l'ancien nom est laissée en place pour que tout ce qui l'utilise encore continue de fonctionner.

## Accès

- Voir le panneau et le catalogue : tout utilisateur connecté.
- Administration (créer et éditer les contenus) : administrateur uniquement. Avec un module de
  comptes, la vue Administrateur serveur est exigée en plus — un module de comptes accorde
  `AclRoot` à tout organisateur connecté sur les pages hors compétition, ce seul test laisserait
  donc n'importe quel organisateur modifier les formations.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier les dossiers `GUIDE/` et `_shared/`
dans `Modules/Custom/` (ou lancer `install.sh` / `install.ps1`). Mises à jour et désinstallation
depuis ianseo : menu du module → **Administration** → **Mise à jour**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `GuideProgress` | `admin/update.php:226` | — |
| `UPDATE` | `GuideProgress` | `guide-api.php:135` | — |
| `INSERT INTO` | `GuideProgress` | `guide-api.php:144` | — |
| `UPDATE` | `GuideProgress` | `guide-api.php:181` | — |
| `INSERT IGNORE INTO` | `GuideProgress` | `guide-api.php:351` | — |
| `UPDATE` | `GuideProgress` | `guide-api.php:356` | — |
| `INSERT INTO` | `GuidePrefs` | `lib/guide-lib.inc.php:413` | — |
| `DROP TABLE` | `GuideProgress` | `lib/guide-lib.inc.php:503` | — |
| `ALTER TABLE` | `GuideProgress` | `lib/guide-lib.inc.php:531` | — |
| `UPDATE IGNORE` | `GuideProgress` | `lib/guide-lib.inc.php:564` | — |
| `INSERT IGNORE INTO` | `GuideVisits` | `lib/guide-lib.inc.php:653` | — |

<!-- END DATABASE WRITES -->
