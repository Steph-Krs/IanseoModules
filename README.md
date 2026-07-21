# IanseoModules

Modules pour le projet I@nseo :
- https://www.ianseo.net/
- https://www.facebook.com/ianseoarchery

## Modules disponibles

<!-- À TENIR À JOUR : une ligne par module publié, format « [Nom](DOSSIER/README_DOSSIER.md) — résumé ». -->

- [Trophée National des Mixtes](TNM/README_TNM.md) — Gestion d'une compétition par équipes
  mixtes : qualifications, poules en Round Robin (2 tours) puis Big Shoot Off final, impressions
  (feuilles de poules, classements, feuilles de marques) et saisie / vue commentateur des BSO.
- [Guide interactif](GUIDE/README_GUIDE.md) — Panneau d'aide pas à pas affiché sur toutes les
  pages de ianseo : formations multi-étapes, QCM et défis, surbrillance des éléments et suivi de
  progression. Pensé pour les clubs qui débutent sur ianseo.
- [Poules — vue commentateur](POULES/README_POULES.md) — Affichage live des enjeux d'une phase
  de poules Round Robin par équipes : classement en temps réel, fourchette de classement final
  atteignable par équipe, détection des matchs décisifs. Lecture seule.
- [Passerelle extranet FFTA](SYNCHRO_FFTA/README_SYNCHRO_FFTA.md) — Dépôt des résultats (fichier
  TXT) et création d'une compétition ianseo depuis une épreuve de l'extranet FFTA.
- [Export liste](EXPORT_LISTE/README_EXPORT_LISTE.md) — Export CSV des participants au format de
  l'import par liste (10 colonnes), enrichi du N° d'agrément et du nom du club. Lecture seule.

## Installation automatique (Mac / Linux)

Une **seule commande** à copier dans le terminal. Elle télécharge puis lance l'installateur.

```bash
curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash
```

## Installation automatique (Windows)

Équivalent Windows, à coller dans **PowerShell**.

```powershell
irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
```

## Installation manuelle (Windows, Mac, Linux)

Un module ianseo est simplement un **dossier** à placer dans `Modules/Custom/` de votre
installation ianseo. Ce dossier `Custom/` est le **seul que ianseo ne réécrit jamais** lors de
ses mises à jour officielles.

Installer un module revient donc à copier, dans `…/Modules/Custom/` :
- le **dossier du module** (ex. `GUIDE/` ou `TNM/`) ;
- le **dossier commun `_shared/`**, utilisé par tous les modules.

Où se trouve `Modules/Custom/` selon votre système :

| Système | Emplacement typique |
|---|---|
| Windows (XAMPP) | `C:\ianseo\htdocs\Modules\Custom\` |
| Linux | `/opt/ianseo/Modules/Custom/` ou `/var/www/ianseo/Modules/Custom/` |
| Mac (MAMP) | `/Applications/MAMP/htdocs/Modules/Custom/` |

Sans terminal, cette méthode fonctionne partout :

1. Téléchargez le dépôt en ZIP : bouton **Code → Download ZIP** sur
   https://github.com/Steph-Krs/IanseoModules
   (ou directement https://github.com/Steph-Krs/IanseoModules/archive/refs/heads/main.zip).
2. Décompressez l'archive.
3. Copiez le **dossier du module** voulu (ex. `GUIDE`) **et** le dossier **`_shared`** dans
   votre `…/Modules/Custom/` (voir le tableau des emplacements ci-dessus).
4. C'est prêt : ouvrez ianseo, l'entrée du module apparaît dans le menu.

## Mise à jour d'un module

Une fois installé, un module se met à jour **depuis ianseo**, sans ligne de commande : page
d'administration du module → **Vérifier / Mettre à jour** (`admin/update.php`, réservé au
profil **Root**). Le module compare sa version locale (`version.json`) à celle du dépôt et
re-télécharge les fichiers modifiés.

Relancer `install.sh` réinstalle proprement (idempotent : votre `module.json` local, avec son
éventuel token GitHub, est conservé).

## Désinstallation d'un module

La désinstallation se fait **depuis ianseo**, sans ligne de commande, réservée à
l'**administrateur** : page d'administration du module → **Mise à jour** → zone repliée
**« Désinstaller le module »**.

Déroulé et garde-fous :

- Confirmation par **saisie du nom du module**.
- **Pas de sauvegarde par défaut** : les fichiers restent récupérables depuis GitHub (une
  réinstallation les restaure). Un module sensible peut proposer une **sauvegarde ZIP
  téléchargeable**, supprimée du serveur dès le téléchargement.
- Seuls les dossiers contenant un `module.json` sont concernés ; `_shared/` n'est jamais supprimé.
- Si le module crée des tables, une case **décochée par défaut** propose de les supprimer aussi
  (sinon elles sont conservées et une réinstallation retrouve les données).

> Avec le module de comptes **AUTH** actif, cette page — comme la mise à jour et l'installation
> d'autres modules — n'est **visible et accessible qu'à l'administrateur du serveur**.
