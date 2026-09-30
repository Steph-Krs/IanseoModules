# Envoi automatique

Module pour [I@nseo](https://www.ianseo.net/), le logiciel de gestion des compétitions de tir à
l'arc.

Ouvre et ferme la saisie des scores et **envoie les résultats sur ianseo.net tout seul**, à heures
fixées, sans ordinateur laissé sur la page d'envoi. Pensé pour les compétitions dont les scores
sont saisis en ligne pendant plusieurs jours ou semaines (ISK-NG sur téléphone), où la page d'envoi
restait ouverte sur un ordinateur toute la période — et où un envoi arrêté sans bruit pouvait
passer inaperçu pendant des jours.

## Fonctionnalités

- 🗓️ **Une période** : début et fin (date et heure), **changements d'heure compris** — une période
  qui commence en heure d'hiver et finit en heure d'été garde les heures saisies. Une heure qui
  n'existe pas (02:00–03:00 la nuit du passage à l'heure d'été) est refusée.
- 📱 **Saisie ouverte et fermée** (les sessions ISK-NG de la page du cœur *Gérer les sessions
  verrouillées*) : ouverte au début, fermée à la fin, une seule fois chacune — une session
  verrouillée à la main pendant la période reste verrouillée. À la fin, la saisie est fermée
  **avant** un dernier envoi, pour qu'il contienne tous les scores.
- ⏱️ **Envoi toutes les N minutes** (5 au minimum), réglable sur la page.
- 📋 **Les mêmes listes que la page d'envoi du cœur** : classements de qualification, classements
  par catégorie, éliminatoires, poules, grilles, classements finaux, médailles. Un élément que
  ianseo ne propose pas encore (grilles avant les barrages, médailles avant leur attribution) est
  envoyé dès qu'il le propose — jamais de grille vide.
- 🧪 **Simulation** : tout se déroule pour de vrai sauf l'envoi, qui est imité. Les résultats sont
  construits exactement comme pour un vrai envoi, ce qui donne le coût réel sur le serveur.
- 🚨 **Des échecs qui ne passent pas inaperçus** : état et historique sur la page, nouvelles
  tentatives après 1, 2, 4… minutes, et une **surveillance extérieure** facultative
  (healthchecks.io) qui vous prévient par e-mail ou SMS quand les envois s'arrêtent — y compris
  quand le serveur lui-même est arrêté.
- ▶️ Bouton **Envoyer maintenant**, pour vérifier toute la chaîne à tout moment.

## Fonctionnement

PHP ne s'exécute que lorsqu'une page est demandée : rien dans ianseo ne peut se réveiller seul à
8 h. Le planificateur du serveur (cron) lance donc la tâche du module **chaque minute**. Chaque
passage lit les réglages, fait ce qui est dû — ouvrir la saisie, envoyer, fermer la saisie — et
s'arrête. Presque toujours, rien n'est dû, et le passage coûte deux petites requêtes.

L'envoi lui-même est **le code de ianseo**, inchangé : `Tournament/UploadResults-upload.php`
accepte déjà un appel sans navigateur, avec les codes ianseo.net enregistrés avec la compétition.
Le module prépare les mêmes champs que la page du cœur enverrait, et lance ce code dans un
processus séparé, avec une limite de temps (10 minutes).

Chaque passage repart de zéro : rien n'est conservé d'un passage à l'autre, hors de la base. Un
échec ne coûte que ce passage ; le suivant recommence.

### Quand quelque chose se passe mal

| Situation | Ce qui se passe |
|---|---|
| Perte de la connexion Internet du serveur | Les envois échouent en moins d'une seconde (les codes sont vérifiés avant la construction des résultats) et sont retentés après 1, 2, 4, 8… minutes, puis au rythme normal. **Rien n'est perdu** : chaque envoi contient les résultats complets, donc le premier envoi après le retour de la connexion publie tout. L'ouverture et la fermeture de la saisie n'ont pas besoin d'Internet. Si la surveillance est en place, elle vous prévient une fois le délai de grâce écoulé. |
| ianseo.net injoignable ou qui refuse | Mêmes nouvelles tentatives ; la raison est notée dans l'historique. |
| Redémarrage de la base (mises à jour de nuit) | Ce passage échoue ou est sauté ; la minute suivante reprend. |
| Serveur arrêté à l'heure du début ou de la fin | Rattrapé au premier passage après son retour : ouverture, fermeture, et dernier envoi (retenté pendant 48 heures après la fin). |
| Envoi bloqué | Arrêté au bout de 10 minutes, noté comme échec, tentative suivante programmée. |
| Un envoi de plus d'une minute | Les passages suivants s'effacent (verrou en base) ; jamais deux envois en même temps. |
| La tâche planifiée ne tourne pas | La page l'indique en rouge. Seule la surveillance extérieure peut vous prévenir quand vous ne regardez pas la page. |

### Coût sur le serveur

Mesuré sur un vrai challenge (9 027 archers, 17 épreuves, classements de qualification individuels
et classement par catégorie) : environ **2 secondes** de calcul pour construire les résultats,
**774 Ko** envoyés, 60 Mo de mémoire, soit environ 4,5 secondes par envoi en comptant le lancement
du processus et la vérification des codes (machine de développement). C'est le travail que la page
d'envoi du cœur faisait déjà faire au serveur à chaque envoi automatique ; le module n'en ajoute
aucun, et économise le rechargement de liste que la page faisait après chaque envoi. La durée et la
taille de chaque envoi sont affichées dans l'historique.

## Installation

1. Copier les dossiers `AUTO_SEND/` et `_shared/` dans `Modules/Custom/` (ou lancer `install.sh` /
   `install.ps1`, voir le [README général](../README.md)).
2. Ouvrir une compétition, puis **Modules → Envoi automatique → Réglages et état** : les tables
   sont créées.
3. **Installer la tâche planifiée** (une fois par serveur, voir ci-dessous). La page affiche la
   ligne exacte pour l'installation, et passe au vert quand la tâche tourne.
4. Enregistrer les codes ianseo.net de la compétition par le menu habituel de ianseo, **en cochant
   « mémoriser »** : la tâche envoie sans personne de connecté. La page du module signale leur
   absence.

### La tâche planifiée sous Linux (Debian, Ubuntu)

En administrateur du serveur. Remplacer `/opt/ianseo` par le dossier de votre installation ianseo
(la page du module affiche la ligne avec le bon chemin), et `www-data` par l'utilisateur du serveur
web s'il est différent :

```sh
echo '* * * * * www-data nice -n 10 /usr/bin/php /opt/ianseo/Modules/Custom/AUTO_SEND/cron.php 2>&1 | logger -t ianseo-autosend' | sudo tee /etc/cron.d/ianseo-autosend
```

- `nice -n 10` laisse la priorité au serveur web : les scores saisis sur les téléphones passent
  d'abord.
- La tâche n'écrit rien quand tout va bien ; une erreur part dans le journal système.
- Un fichier de `/etc/cron.d/` ne doit pas avoir de point dans son nom, et cron le lit sans
  redémarrage.

Vérifier qu'elle tourne :

```sh
# La page du module affiche « La tâche planifiée tourne » dans la minute. En ligne de commande :
sudo grep ianseo-autosend /var/log/syslog | tail        # les erreurs seulement (muet si tout va bien)
sudo grep 'AUTO_SEND/cron.php' /var/log/syslog | tail -3 # une ligne par passage, chaque minute
sudo -u www-data php /opt/ianseo/Modules/Custom/AUTO_SEND/cron.php   # un passage à la main : n'affiche rien si tout va bien
php -m | grep -i mysqli                                  # le PHP en ligne de commande a besoin de mysqli, comme le site
```

Tout mettre en pause : décocher **Programmation active** sur la page (recommandé), ou commenter la
ligne :

```sh
sudo sed -i 's/^\*/#*/' /etc/cron.d/ianseo-autosend      # pause
sudo sed -i 's/^#\*/*/' /etc/cron.d/ianseo-autosend      # reprise
sudo rm /etc/cron.d/ianseo-autosend                      # suppression
```

### La tâche planifiée sous Windows (XAMPP)

Dans une invite de commandes lancée en administrateur (adapter les deux chemins ; la page du module
les affiche) :

```bat
schtasks /Create /TN "ianseo AUTO_SEND" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"C:\ianseo\php\php.exe\" \"C:\ianseo\htdocs\Modules\Custom\AUTO_SEND\cron.php\""
schtasks /Query /TN "ianseo AUTO_SEND"
schtasks /Delete /TN "ianseo AUTO_SEND" /F
```

## Surveillance extérieure avec healthchecks.io (facultative)

**Pourquoi de l'extérieur** : un avertissement affiché à l'écran, ou même un e-mail envoyé par le
serveur, ne peut pas signaler que le serveur lui-même est arrêté, ou que son réseau est coupé. Un
« interrupteur d'homme mort » fonctionne à l'envers : le serveur **appelle** le service après
chaque envoi ; si les appels **cessent**, le service donne l'alerte. Rien n'est installé sur le
serveur — le module appelle seulement une adresse.

1. Créer un compte gratuit sur [healthchecks.io](https://healthchecks.io) (une adresse e-mail
   suffit).
2. **Add Check**. Lui donner un nom (par ex. « ianseo — challenge »). Dans **Schedule** : *Period*
   = l'intervalle d'envoi (10 minutes), *Grace time* = 20 minutes — de quoi absorber un redémarrage
   de nuit ou une nouvelle tentative sans fausse alerte.
3. Copier son **adresse de ping** (`https://hc-ping.com/…`) dans **Adresse de surveillance** sur la
   page du module, et enregistrer.
4. Dans **Integrations**, l'e-mail du compte est actif par défaut ; on peut ajouter SMS, Telegram,
   Signal ou des notifications (ntfy).
5. Le contrôle reste gris (« new ») jusqu'au premier appel : rien n'est envoyé en simulation. Une
   fois la vraie période commencée, il passe au vert.
6. **Après la période**, décocher *Programmation active* sur la page **et** mettre le contrôle en
   pause dans healthchecks.io — sinon, les appels ayant cessé, il vous prévient.

Ce que reçoit le service : un appel vide après chaque envoi réussi (et comme signe de vie entre deux
envois), et un appel à `…/fail` avec le code du résultat et le message d'erreur après un échec (par
exemple *ianseo.net injoignable*). Aucune donnée personnelle.

## Accès

- Réglages et état : une compétition ouverte, et le droit de l'envoyer sur ianseo.net (celui que
  demande la page d'envoi du cœur). Ouvrir et fermer la saisie demande en plus le droit de la page
  des sessions ISK-NG du cœur ; sans lui, ces réglages sont en lecture seule.
- La tâche planifiée s'exécute sur le serveur lui-même, sans compte.
- Mise à jour et désinstallation : administrateur.

## Base de données

Deux tables propres au module, créées à l'ouverture de sa page (jamais par la tâche planifiée) :

| Table | Contenu |
|---|---|
| `AutoSendPlans` | une ligne par compétition : la période, l'intervalle, les sessions de saisie, les listes à envoyer, l'adresse de surveillance, et ce que la tâche a fait (ouverture, fermeture, dernier envoi, échecs) |
| `AutoSendRuns` | l'historique : une ligne par envoi, ouverture ou fermeture, avec son résultat, sa durée et sa taille ; gardé 60 jours |

Données ianseo modifiées **par les fonctions du cœur** :

- la liste des sessions ISK-NG verrouillées de la compétition (`ModulesParameters`, module
  `ISK-NG`, paramètre `LockedSessions`), par `getModuleParameter()` / `setModuleParameter()`,
  exactement comme la page du cœur *Gérer les sessions verrouillées* ;
- l'envoi est le code du cœur, lancé sans modification : il met à jour ce qu'il met à jour quand on
  utilise la page d'envoi (le code en ligne de la compétition `Tournament.ToOnlineId`, et les
  indicateurs de menu que ianseo rafraîchit à chaque ouverture de compétition).

## Mise à jour, désinstallation

Depuis ianseo : **Modules → Envoi automatique → Mettre à jour le module**. La désinstallation
supprime les fichiers et, sur demande, les deux tables ; **retirez aussi la tâche planifiée du
serveur**.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

None. This module never writes to a core ianseo table.

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `AutoSendPlans` | `api/state.php:25` | — |
| `INSERT IGNORE INTO` | `AutoSendPlans` | `lib/plan.php:134` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:47` | — |
| `DELETE FROM` | `AutoSendRuns` | `lib/runner.php:62` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:103` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:108` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:127` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:138` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:148` | — |
| `INSERT INTO` | `AutoSendRuns` | `lib/runner.php:314` | — |
| `UPDATE` | `AutoSendPlans` | `lib/runner.php:352` | — |
| `UPDATE` | `AutoSendPlans` | `lib/settings.php:103` | — |

<!-- END DATABASE WRITES -->
