# Guide FFTA

Module interactif pour I@nseo, le système de gestion des compétitions de Tir à l'Arc.
- https://www.ianseo.net/

Ce module affiche un panneau latéral persistant sur toutes les pages de ianseo pour guider les utilisateurs pas à pas dans les procédures de gestion de compétition.

## ✨ Fonctionnalités

- 📖 Formations JSON multi-étapes avec navigation Précédent / Suivant
- 📍 Panneau latéral fixe (bas-droite ou bas-gauche, mémorisé)
- 🎯 Surbrillance d'éléments de page avec overlay et flèche animée
- 💬 Info-bulles contextuelles sous la flèche (par trigger)
- ⚡ **Triggers action** : détection d'événements DOM (click, change, mouseover)
- ✅ **Triggers état** : vérification de conditions serveur (compétition ouverte, règles françaises, duel configuré…)
- 🔀 Étapes multi-pages : chaque trigger peut cibler une page différente
- 📊 Barre de progression + indicateur d'étape
- 💾 Persistance de la progression en base de données (`GUIDE_Progress`)
- 🔄 Synchronisation localStorage ↔ serveur
- ⚠️ Avertissement si reprise dans une compétition différente de celle du démarrage
- ✏️ Éditeur WYSIWYG intégré (admin) avec aperçu temps réel et sauvegarde AJAX

## 📁 Architecture

```
Modules/Custom/GUIDE/
├── menu.php                ← Injection panneau + FAB sur toutes les pages (via glob)
├── index.php               ← Catalogue des formations disponibles
├── guide-api.php           ← API AJAX (formations, progression, vérification conditions)
├── conditions.json         ← Bibliothèque de conditions d'état (usage dev uniquement)
├── admin/
│   ├── index.php           ← Liste des formations (ACL : AclRoot ReadWrite)
│   └── edit.php            ← Éditeur formation + aperçu temps réel
├── content/
│   └── NN-id.json          ← Fichiers de formation (auto-détectés)
└── assets/
    ├── guide.css           ← Panneau, FAB, highlight, bulle hint, indicateur état
    └── guide.js            ← Moteur : navigation, triggers, localStorage, XHR
```

## 🗄️ Base de données

### `GUIDE_Progress` — Progression par formation

| Colonne | Type | Description |
|---------|------|-------------|
| `GpId` | INT PK AUTO | Identifiant interne |
| `GpFormId` | VARCHAR(30) | ID de la formation (clé unique) |
| `GpFormVer` | VARCHAR(20) | Version au démarrage |
| `GpTourId` | INT | ID compétition au démarrage (avertit si changement) |
| `GpStep` | INT | Étape courante (0-based) |
| `GpStatus` | ENUM | `en_cours` / `termine` / `obsolete` |
| `GpValidated` | TEXT | JSON des étapes validées `{"etape-id": true}` |
| `GpUpdatedAt` | DATETIME | Dernière mise à jour |

La table est créée automatiquement à la première requête (`guide_ensure_schema()`).

## 📋 Format JSON d'une formation

```json
{
  "id": "ma-formation",
  "title": "Titre affiché",
  "description": "Description courte",
  "version": "1.0",
  "steps": [
    {
      "id": "etape-id",
      "title": "Titre de l'étape",
      "content": "<p>HTML. Utiliser <code>...</code> et <p class=\"guide-tip\">⚠️ conseil</p>",
      "page": "/Tournament/index.php",
      "triggers": [
        {
          "kind": "action",
          "trigger": "change",
          "selector": "input[name='d_ToCode']",
          "page": "/Tournament/index.php",
          "hint": "Saisissez le code de la compétition ici",
          "required": true
        },
        {
          "kind": "etat",
          "condition": "tournament_open",
          "required": true
        }
      ]
    }
  ]
}
```

### Champs d'une étape

| Champ | Description |
|-------|-------------|
| `id` | Identifiant unique dans la formation (snake-case) |
| `title` | Titre affiché dans le panneau |
| `content` | HTML de l'étape (voir balises CSS custom ci-dessous) |
| `page` | Page par défaut pour les triggers sans `page` propre (`null` = toutes pages) |
| `triggers` | Tableau de triggers action ou état (voir ci-dessous) |

### Triggers action (`"kind": "action"`)

| Champ | Description |
|-------|-------------|
| `trigger` | Événement DOM : `click`, `change`, `mouseover`, ou `null` (manuel) |
| `selector` | Sélecteur CSS de l'élément cible |
| `page` | Page de ce trigger (remplace `step.page`) |
| `hint` | Texte affiché sous la flèche quand cet élément est surligné |
| `required` | `true` = bloque le bouton Suivant jusqu'à déclenchement |

### Triggers état (`"kind": "etat"`)

| Champ | Description |
|-------|-------------|
| `condition` | ID d'une condition dans `conditions.json` |
| `required` | `true` = vérifié au chargement de la page, bloque si non satisfait |

La condition est vérifiée via l'API (`?action=check-condition&cid=...`). Si satisfaite, l'étape avance automatiquement. Sinon, un indicateur s'affiche dans le panneau.

## ⚙️ Conditions d'état (`conditions.json`)

Fichier dev-only, non exposé aux utilisateurs. Chaque condition est évaluée côté serveur.

```json
[
  {
    "id": "tournament_open",
    "label": "Compétition ouverte",
    "checks": [
      { "source": "session", "key": "TourId", "op": "gt", "value": 0 }
    ]
  },
  {
    "id": "has_individual_duel",
    "label": "Compétition avec duel individuel configuré",
    "checks": [
      { "source": "session", "key": "TourId", "op": "gt", "value": 0 },
      {
        "table": "Event", "aggregate": "count",
        "where": [
          { "column": "EvTournament", "source": "session", "key": "TourId" },
          { "column": "EvFinalFirstPhase", "op": "neq", "value": "0" },
          { "column": "EvTeamEvent", "op": "eq", "value": "0" }
        ],
        "op": "gt", "value": 0
      }
    ]
  }
]
```

### Opérateurs disponibles : `eq`, `neq`, `gt`, `gte`, `lt`, `lte`

### Types de check

| Type | Champs | Description |
|------|--------|-------------|
| Session | `source:"session"`, `key`, `op`, `value` | Vérifie `$_SESSION[key]` |
| Valeur colonne | `table`, `column`, `join`, `op`, `value` | Lit une valeur dans une table |
| Agrégat COUNT | `table`, `aggregate:"count"`, `where[]`, `op`, `value` | Compte des lignes selon conditions |

## 🔌 API (`guide-api.php`)

| Méthode | Paramètres | Description |
|---------|-----------|-------------|
| GET | `?f={id}` | JSON complet d'une formation |
| POST | `?action=start` | Démarre / réinitialise la progression |
| POST | `?action=update` | Met à jour l'étape et le statut |
| GET | `?action=progress&f={id}` | Progression pour une formation |
| GET | `?action=progress-all` | Toutes les progressions |
| GET | `?action=check-condition&cid={id}` | Vérifie une condition d'état |

## 🎨 CSS custom dans le contenu

```html
<p class="guide-tip">⚠️ Encadré conseil / avertissement (fond jaune)</p>
<code>texte code inline</code>
<ul><li>...</li></ul>   <!-- Liste à puces -->
<ol><li>...</li></ol>   <!-- Liste numérotée -->
```

## 🌐 API publique JS

```js
GuideStart(formationId)        // Démarre une formation depuis l'étape 0
GuideResume(formationId)       // Reprend depuis l'étape sauvegardée
GuideIsActive()                // bool — formation en cours ?
GuideIsCompleted(formationId)  // bool — formation terminée ?
```

## 👤 Accès

| Fonctionnalité | ACL requise |
|----------------|-------------|
| Voir le panneau + catalogue | Tous les utilisateurs connectés |
| Administration (éditer formations) | `AclRoot` ReadWrite |

## ➕ Ajouter une formation

1. Créer `content/NN-identifiant.json` (NN = numéro d'ordre 01, 02…)
2. Remplir avec le format JSON décrit ci-dessus
3. Le catalogue `index.php` et l'API la détectent automatiquement

## 💾 localStorage

| Clé | Contenu |
|-----|---------|
| `guide_state` | `{ active, formation_id, step_index, trigger_index, gp_id, validated }` |
| `guide_completed` | `[formation_id, ...]` — formations terminées |
| `guide_panel_side` | `"right"` ou `"left"` |
