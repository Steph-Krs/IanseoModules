# Thème

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion des compétitions de tir à l'arc.

Un **mode sombre** pour ianseo, et **huit couleurs** pour reconnaître d'un coup d'œil sur quelle
installation on travaille : le serveur du club, celui d'un portable, un serveur en ligne…

Le module ne change rien au cœur de ianseo : il redonne une valeur aux variables de couleur que
ianseo déclare déjà (`Common/Styles/colors.css`), comme le fait le mode debug.

## Fonctionnalités

- 🌙 **Trois modes**, au choix depuis le menu **Modules → Thème** : **Automatique** (suit le
  réglage clair ou sombre de l'ordinateur, et change avec lui — c'est le réglage par défaut),
  **Clair** ou **Sombre**.
- 🎨 **Huit couleurs**, chacune en version claire et en version sombre : bleu ianseo, vert sapin,
  turquoise, olive, violet, framboise, bordeaux, ardoise. La page **Modules → Thème → Couleurs…**
  montre un aperçu de chacune.
- 🔍 **Lisibilité mesurée** : les versions claires reprennent la luminosité de chaque couleur du
  bleu de ianseo ; tous les couples texte / fond ont un contraste d'au moins 6,5:1 (le minimum
  recommandé est de 4,5:1).
- 🚦 **Les couleurs d'état gardent leur sens** en mode sombre : vert pour un archer qui peut tirer,
  jaune pour ce qui est incomplet, rouge pour une erreur…
- 🖨️ **Impressions inchangées** : le thème ne s'applique qu'à l'écran, les PDF et les impressions
  restent identiques.
- 🐞 **Mode debug toujours visible** : ses couleurs orange remplacent la couleur choisie, avec une
  version sombre.

Le choix est **propre à chaque navigateur, pour chaque installation** : deux personnes, ou deux
installations ouvertes sur le même ordinateur (même sur `localhost` avec des ports différents),
gardent chacune le leur.

### Pages sans menu

Les fenêtres pop-up (la fiche d'un participant par exemple) et les pages Speaker n'affichent pas de
menu : aucun fichier d'un module n'y est chargé. L'administrateur peut leur donner le thème depuis
la page **Couleurs…** : un bouton ajoute quelques lignes à `Common/DebugOverrides.php`, un fichier
que ianseo inclut sur chaque page et que ses mises à jour ne touchent pas. Ces lignes :

- chargent le thème dans l'en-tête de **toute page dessinée avec les couleurs standard de ianseo**,
  ce qui supprime aussi le bref éclair clair au chargement des autres pages ;
- ne touchent pas les écrans publics (sortie TV, applications de saisie), ni les réponses JSON,
  PDF ou les téléchargements ;
- ne font plus rien une fois le module désinstallé ; le même bouton les retire.

Si le serveur web ne peut pas écrire ce fichier, la page affiche les lignes à y copier à la main.

## Accès

- Choisir un mode et une couleur : tout utilisateur. Le réglage ne concerne que son navigateur.
- Pages sans menu, mise à jour et désinstallation : administrateur.

## Base de données

Le module **ne crée aucune table** et **n'écrit rien** en base. Le choix de chaque utilisateur est
conservé par son navigateur, dans un cookie.

## Installation, mise à jour, désinstallation

Voir le [README général](../README.md). En bref : copier les dossiers `THEME/` et `_shared/` dans
`Modules/Custom/` (ou lancer `install.sh` / `install.ps1`). Mises à jour et désinstallation depuis
ianseo : menu **Modules → Thème → Mise à jour module**.

Si les pages sans menu ont reçu le thème, désactiver l'option avant de désinstaller retire aussi
les lignes de `Common/DebugOverrides.php`. Oubliées, elles restent sans effet.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

None. This module creates no table of its own.

<!-- END DATABASE WRITES -->
