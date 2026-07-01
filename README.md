# IanseoModules

Modules pour le projet I@nseo :
- https://www.ianseo.net/
- https://www.facebook.com/ianseoarchery

## Installation — le principe (Windows, Mac, Linux)

Un module ianseo est simplement un **dossier** à placer dans `Modules/Custom/` de votre
installation ianseo. Ce dossier `Custom/` est le **seul que ianseo ne réécrit jamais** lors de
ses mises à jour officielles : tout ce qui se trouve ailleurs est remplacé. Vos modules y sont
donc conservés d'une version de ianseo à l'autre.

Installer un module revient donc à copier, dans `…/Modules/Custom/` :
- le **dossier du module** (ex. `GUIDE/` ou `TNM/`) ;
- le **dossier commun `_shared/`**, utilisé par tous les modules.

Où se trouve `Modules/Custom/` selon votre système :

| Système | Emplacement typique |
|---|---|
| Windows (XAMPP) | `C:\ianseo\htdocs\Modules\Custom\` |
| Linux | `/opt/ianseo/Modules/Custom/` ou `/var/www/ianseo/Modules/Custom/` |
| Mac (MAMP) | `/Applications/MAMP/htdocs/Modules/Custom/` |

## Modules disponibles

- [Trophée National des Mixtes](TNM/README_TNM.md) — Gestion du TNM de la FFTA :
  qualifications, poules en Round Robin (2 tours) puis Big Shoot Off final, impressions
  (feuilles de poules, classements, feuilles de marques) et saisie / vue commentateur des BSO.
- [Guide FFTA](GUIDE/README_GUIDE.md) — Panneau d'aide interactif, pas à pas, affiché sur
  toutes les pages de ianseo : formations multi-étapes, surbrillance des éléments, info-bulles
  et suivi de progression. Pensé pour les clubs débutant sur ianseo.

## Installation manuelle (Windows, Mac, Linux)

Sans terminal, cette méthode fonctionne partout :

1. Téléchargez le dépôt en ZIP : bouton **Code → Download ZIP** sur
   https://github.com/Steph-Krs/IanseoModules
   (ou directement https://github.com/Steph-Krs/IanseoModules/archive/refs/heads/main.zip).
2. Décompressez l'archive.
3. Copiez le **dossier du module** voulu (ex. `GUIDE`) **et** le dossier **`_shared`** dans
   votre `…/Modules/Custom/` (voir le tableau des emplacements ci-dessus).
4. C'est prêt : ouvrez ianseo, l'entrée du module apparaît dans le menu.

## Installation automatique (Mac / Linux)

Une **seule commande** à copier dans le terminal. Elle télécharge puis lance l'installateur,
qui ensuite :

- **affiche la liste des modules disponibles** dans le dépôt ;
- vous demande **le(s)quel(s) installer** (un, plusieurs, ou tous) ;
- **repère le dossier `Modules/Custom/`** sur la machine et vous demande de **confirmer ou de
  préciser son emplacement**.

```bash
curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash
```

Rien d'autre à taper : tout se fait ensuite au clavier, en répondant aux questions.

Variante non interactive (pour automatiser), en précisant le module et, au besoin, le chemin :

```bash
curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash -s -- GUIDE
curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash -s -- all /var/www/html/Modules/Custom
```

Pour inspecter le script avant de l'exécuter (recommandé) :

```bash
curl -fsSLO https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh
less install.sh
bash install.sh
```

## Installation automatique (Windows)

Équivalent Windows, à coller dans **PowerShell**. Même déroulé : liste des modules, choix,
détection puis confirmation du dossier `Modules\Custom\` :

```powershell
irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
```

Variante non interactive (définir les variables avant le pipe) :

```powershell
$IanseoModule='GUIDE'; irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
$IanseoModule='all'; $IanseoDest='C:\ianseo\htdocs\Modules\Custom'; irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
```

Pour inspecter le script avant de l'exécuter (recommandé) :

```powershell
irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 -OutFile install.ps1
notepad install.ps1
Set-ExecutionPolicy -Scope Process Bypass -Force   # autorise la session courante
.\install.ps1
```

## Mise à jour d'un module

Une fois installé, un module se met à jour **depuis ianseo**, sans ligne de commande : page
d'administration du module → **Vérifier / Mettre à jour** (`admin/update.php`, réservé au
profil **Root**). Le module compare sa version locale (`version.json`) à celle du dépôt et
re-télécharge les fichiers modifiés.

Relancer `install.sh` réinstalle proprement (idempotent : votre `module.json` local, avec son
éventuel token GitHub, est conservé).
