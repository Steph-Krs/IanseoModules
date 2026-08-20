# Multi-comptes

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion de compétitions de tir à
l'arc.

Transforme une installation ianseo hébergée en ligne en **serveur multi-organisateurs**
**avec inscriptions en ligne** : chaque structure dispose de son compte et ne voit / ne modifie
que ses propres compétitions (partage possible pour l'entraide à la saisie), et **les licenciés
s'inscrivent eux-mêmes** aux compétitions ouvertes depuis leur propre espace.

> Ce README volontairement **ne détaille pas** le fonctionnement interne ni les mécanismes de sécurité.

## ⚠️ À savoir avant de déployer — sécurité des comptes utilisés

Il n'existe pas encore de **SSO officiel** (OAuth / OpenID Connect) fourni par la fédération.
La connexion fonctionne donc par **relais de crédentiels** : à chaque login, l'identifiant et le
mot de passe de l'utilisateur **transitent par CE serveur** pour être vérifiés auprès des espaces
en ligne (dirigeant / licencié). Le mot de passe n'est **jamais stocké ni journalisé**, mais il
passe par la mémoire du serveur le temps de la requête.

**Conséquence, tant qu'un vrai SSO n'est pas en place : la sécurité des comptes des utilisateurs
dépend directement de la sécurité ET de la fiabilité de ce serveur (et de son exploitant).** Les
utilisateurs en sont informés sur la page de connexion. C'est pourquoi le déploiement doit suivre
le durcissement décrit dans `SERVEUR.md`, et pourquoi un vrai OIDC reste à demander au prestataire
des espaces en ligne (le module est prêt à basculer le jour venu).

## Fonctionnalités

### Côté organisateur (multi-comptes)
- 👤 Un compte par organisateur ; chaque compte ne voit que ses compétitions
- 🤝 Partage contrôlé d'une compétition (aide à la saisie, visibilité pour la structure de tutelle)
- 🔑 Connexion centralisée, avec repli sur des comptes locaux
- 🛠️ Administration des comptes et journal d'activité

### Côté compétiteur (inscriptions en ligne — sous-module `booking/`)
- 🎯 Espace licencié : calendrier des compétitions ouvertes, inscription en quelques clics
- 🧩 Attribution automatique départ/cible selon les règles fédérales (dont cohabitation des blasons)
- 👥 Inscription groupée d'un camarade de club ; suivi des paiements ; boutique
- 🧾 Mandat, documents et feuilles de marque de la compétition consultables par les archers

## Base de données

Tables internes créées automatiquement : préfixe `AUT_` (comptes organisateurs) et `BK_`
(comptes licenciés, inscriptions, boutique, paiements).

## Accès

- Gestion des comptes et administration : **administrateur du serveur**.
- Chaque organisateur : uniquement ses compétitions (et celles qu'on lui a partagées).
- Chaque licencié : son propre espace d'inscription (connexion par son compte fédéral).

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md) pour le principe commun.

> **Module sensible** : il porte l'authentification du serveur. Son installation, sa mise à jour
> et son retrait doivent être réalisés par l'administrateur.
> Un retrait effectué sans précaution peut rendre le site inaccessible.
