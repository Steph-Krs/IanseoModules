# Inscriptions en ligne

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Ouvre les inscriptions aux archers eux-mêmes : chaque licencié se connecte avec les identifiants
de son espace licencié fédéral, consulte le calendrier des compétitions ouvertes et s'inscrit en
quelques clics. Pensé pour les serveurs ianseo accessibles en ligne.

## Fonctionnalités

### Pour l'archer

- 👤 **Connexion sans nouveau mot de passe** : les identifiants de l'espace licencié fédéral
  sont transmis à la fédération, qui seule les vérifie. **Aucun mot de passe n'est conservé par
  ce site.** Double authentification prise en charge. Le compte est créé automatiquement à la
  première connexion.
- 📅 **Calendrier** des compétitions ouvertes aux inscriptions : filtres (nom, lieu, dates,
  type) et places restantes par départ.
- 📝 **Inscription en quelques clics** : nom, club et date de naissance repris du fichier des
  licences ; armes, catégories, blasons et départs proposés d'après la configuration de la
  compétition.
- 🙋 **Souhaits pris en compte automatiquement** : position sur la cible et « sur la même cible
  que » (parmi les archers de son club déjà inscrits). Le placement se recalcule à chaque
  inscription pour satisfaire le maximum de demandes, sans jamais enfreindre le règlement ni les
  contraintes d'affectation du terrain.
- ➕ **Plusieurs départs possibles** : avec la même arme, seule la première inscription compte
  pour les épreuves, les suivantes sont des tirs supplémentaires ; une arme différente ouvre sa
  propre épreuve.
- 📋 **Mes inscriptions** : consultation, annulation tant que les inscriptions sont ouvertes,
  cible attribuée si l'organisateur l'a autorisé.
- 🖨️ **Feuille de marque individuelle** imprimable, au format réel de l'épreuve (volées,
  flèches, distances et blason lus dans ianseo) — si l'organisateur active l'option.
- 🧾 **Reçu** par archer, ou groupé pour tout un club.

### Pour l'organisateur

Tout se trouve dans le menu **Modules › Inscriptions en ligne**, compétition ouverte.

- ⚙️ **Ouvrir / configurer les inscriptions** : période d'ouverture, restriction aux archers
  d'un département ou d'une région avec **ouverture différée à tous**, tarif, et ce que les
  archers ont le droit de voir.
- 🏹 **Contraintes d'affectation du terrain** : éditeur graphique des possibilités de chaque cible, départ par
  départ. Chaque cible est une **boîte verticale** sur un axe de distances partagé : on y règle
  la distance mini, maxi et par défaut en glissant les poignées. Les blasons autorisés se
  glissent depuis la palette. Sélection multiple au clic-glissé pour traiter une rangée d'un
  coup, copie d'un départ à l'autre, et curseur de taille pour afficher 50 à 70 cibles d'un
  seul coup d'œil. Une cible sans réglage accepte tout. (« Plan du terrain » désigne le plan de
  cibles visuel du module DragDropTarget.)
- 🎯 **Attribution des cibles** : placement automatique respectant les contraintes d'affectation du terrain, avec
  brassage des clubs, plan des cibles, et **contrôle du règlement** — archers d'un même club par
  cible, clubs différents par départ, doublons sur un même départ, archers non placés.
- 🏛️ **Gestionnaires de club** : un licencié désigné peut inscrire les archers de son club (ou
  de son département / sa région). Fonctionne avec un module de comptes comme sans.

## Base de données

Tables créées automatiquement, toutes préfixées `BK_` : `BK_Archers` (comptes licenciés),
`BK_Sessions`, `BK_Log` (journal et limitation des tentatives), `BK_Competitions` (ouverture des
inscriptions), `BK_Registrations` (traçabilité), `BK_ClubManagers`.

Les inscriptions elles-mêmes sont écrites dans les tables **de ianseo** (participants et cibles),
exactement comme une saisie manuelle : elles apparaissent normalement dans tous les écrans et
exports du logiciel.

La configuration du terrain n'est **pas** redemandée : le module lit celle déjà saisie dans
ianseo (départs, nombre de cibles, distances, blasons, rythme de tir).

## Connexion des archers

Les archers utilisent les **identifiants de leur espace licencié fédéral** (l'identifiant peut
être un numéro de licence ou un identifiant nominatif). Un mot de passe oublié se récupère
auprès de la fédération.

Le numéro de licence est ensuite lu sur l'espace licencié lui-même — jamais demandé à l'archer —
afin que chaque compte soit rattaché au bon licencié.

Réglages facultatifs dans `config.local.json` (non versionné) :

```json
{ "sso": { "enabled": true, "base": "https://monespace.ffta.fr", "debug": false } }
```

## Accès

- **Espace licencié** (archers) : `Modules/Custom/AUTH/booking/public/` — accessible sans compte
  organisateur. Communiquez ce lien à vos licenciés ; il est rappelé sur la page d'ouverture
  des inscriptions.
- **Tous les écrans organisateur** : menu **Modules › Inscriptions en ligne**. L'ouverture des
  inscriptions et l'attribution des cibles n'apparaissent que lorsqu'une compétition est
  ouverte ; les gestionnaires de club et la mise à jour sont réservés à l'administrateur.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En résumé : copier le dossier `BOOKING/` et `_shared/`
dans `Modules/Custom/` (ou `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu **Modules › Inscriptions en ligne › Mise à jour**.
