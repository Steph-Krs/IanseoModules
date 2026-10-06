# Serveur ianseo partagé multi-comptes — Installation & sécurité

Version anglaise : [SERVEUR.md](SERVEUR.md). Ce fichier est le texte d'origine.

Guide de mise en place d'un serveur ianseo en ligne multi-comptes
(module `Modules/Custom/AUTH`), avec un objectif affiché : **la sécurité des
données personnelles prime sur tout le reste** (données de licenciés,
serveur partagé exposé sur Internet).

---

## 1. Apache ou ianseo ? → Les deux, en profondeur

| Couche | Rôle |
|---|---|
| **Système** (VM dédiée, firewall, SSH, MaJ auto) | réduire la surface d'attaque |
| **Apache** (HTTPS, ModSecurity, fail2ban, blocages) | filtrer avant d'atteindre PHP |
| **ianseo + module AUTH** | comptes, 2FA, cloisonnement club/CD/CR/FFTA, partage |
| **Données** (minimisation, purge, sauvegardes chiffrées) | limiter l'impact d'une compromission |

Le htdigest seul ne fait pas d'instances (une fois franchi, ianseo montre tout
à tout le monde). Le cœur ianseo contient déjà les hooks multi-comptes
(`$CFG->USERAUTH` + `Modules/Authentication/`) ; le module `Custom/AUTH`
fournit l'implémentation — aucun fichier du cœur modifié, une seule
installation, une seule base.

> ⚠️ **À savoir avant tout — la connexion est un RELAIS DE CRÉDENTIELS, pas un SSO/OIDC.**
> Faute d'un SSO officiel (OpenID Connect) fourni par la fédération, l'identifiant et le
> **mot de passe** de chaque utilisateur **transitent par ce serveur** à la connexion pour être
> vérifiés auprès des espaces en ligne (dirigeant / licencié). Le mot de passe n'est **jamais
> stocké ni journalisé**, mais il passe par la **mémoire du serveur** le temps de la requête.
> **Conséquence : tant qu'un vrai SSO n'est pas en place, la sécurité des comptes des utilisateurs
> dépend directement de la sécurité ET de la fiabilité de ce serveur** (et de son exploitant). Tout
> ce guide de durcissement (§§ 4-6) en découle, et les utilisateurs en sont avertis sur la page de
> connexion. Demander à terme un vrai **OIDC** au prestataire des espaces (le module pourra basculer).

## 2. Modèle de menace — être honnête

**ianseo est conçu pour tourner en local pendant une compétition**, pas comme
application web multi-locataires exposée à Internet. Le code du cœur est
ancien, avec un historique de vulnérabilités corrigées au fil de l'eau
(injections SQL, uploads). Mettre ianseo en ligne = accepter ce risque et le
**compenser par des couches externes**. Conséquences pratiques :

1. **Ne jamais exposer ianseo « nu »** : WAF (ModSecurity) + authentification
   du module dès le premier jour + fail2ban.
2. **Considérer tout utilisateur connecté comme semi-hostile** : un compte
   club compromis (phishing) donne accès aux fonctions du cœur. Le module
   cloisonne les compétitions, mais le cœur reste le cœur → d'où la
   minimisation des données présentes sur le serveur (§ 7).
3. **Minimiser ce qui est perdable** : le serveur ne doit contenir que les
   données nécessaires aux compétitions en cours/récentes — pas la base des
   80 000 licenciés si évitable (§ 7.1).
4. **Capacité de détection et de restauration** : journaux, alertes,
   sauvegardes testées. « Blindé à 100 % » n'existe pas ; détecter vite et
   restaurer vite, si.
5. **Mises à jour ianseo dès publication** (elles corrigent régulièrement des
   failles) + veille sur les annonces ianseo.
6. Avant l'ouverture publique : **audit/pentest externe** (la FFTA manipule
   des données de 80 000 personnes, l'investissement est proportionné), et
   déclarer le traitement au DPO (§ 7.3).

## 3. Ce que le module AUTH apporte (couche applicative)

- **SSO Espace Dirigeant** (§ 11) : les organisateurs utilisent leurs
  identifiants dirigeant.ffta.fr, comptes provisionnés automatiquement avec
  le bon rôle (club/CD/CR/FFTA) — aucune gestion de mots de passe côté ianseo.
- Comptes locaux (ADMIN…) : bcrypt, mot de passe temporaire à usage unique,
  changement forcé à la première connexion, politique 10+ caractères.
- **2FA TOTP obligatoire pour les comptes ADMIN** (Google/Microsoft
  Authenticator, FreeOTP…), optionnelle pour les autres (recommandée CD/CR/FED).
- **Sessions à jetons révocables** : rien de rejouable dans la session PHP,
  expiration 12 h d'inactivité / 7 jours, déconnexion à distance par un admin,
  révocation automatique au changement/RàZ de mot de passe.
- Anti-brute-force : 8 échecs / 15 min (par IP et par identifiant), réponse en
  temps constant (anti-énumération d'identifiants).
- Cloisonnement par compétition (préfixe agrément) + partage opt-in CD/CR/FFTA.
- Anonyme : uniquement la page de connexion (+ accueil vide). Tout le reste
  redirige vers le login. **Fail-closed** : si les fichiers d'auth manquent
  alors que USERAUTH est actif, le site tombe en erreur, il ne s'ouvre pas.
- Journal complet (connexions, échecs, actions admin) en DB + fichier optionnel
  pour fail2ban.
- **Exploitation** : sauvegarde nocturne + copies à chaud de la base, copie en ligne chiffrée,
  restauration guidée (`ianseo-restore`), alertes à l'administrateur et page « État du serveur »
  (§§ 9, 9 bis, 16).

## 4. Durcissement système (Debian/Ubuntu)

### 4.1 Base
- **OS recommandé : Debian stable** (actuellement 12 « Bookworm ») — ou Ubuntu Server
  LTS. Raisons : support long, correctifs de sécurité fiables, `unattended-upgrades`,
  et pile Apache + PHP + MariaDB/MySQL + ModSecurity + fail2ban native (tout ce guide
  suppose cet écosystème apt). Éviter Windows/XAMPP en production (surface plus large,
  durcissement plus laborieux) — XAMPP reste parfait pour un poste de **développement**.
- **VM dédiée** à ianseo, rien d'autre dessus (pas de mutualisation).
- **Horloge synchronisée (NTP) — OBLIGATOIRE, pas optionnel.** La 2FA des comptes
  administrateur est un TOTP (code basé sur le temps, fenêtre ±30 s) : si l'horloge du
  serveur dérive de plus de ~30 s par rapport à l'heure réelle, **aucun code valide ne
  passe** et l'admin est verrouillé — la connexion FFTA (mot de passe) réussit pourtant,
  ce qui rend le symptôme déroutant. Les expirations de session sont aussi calculées sur
  l'horloge serveur. Sous Debian/Ubuntu, `systemd-timesyncd` (ou `chrony`) est actif par
  défaut : vérifier `timedatectl` (`System clock synchronized: yes`). ⚠️ En dev
  **Windows/XAMPP**, l'horloge est souvent sur « Local CMOS Clock » sans synchro et dérive
  de plusieurs dizaines de minutes → activer « Régler l'heure automatiquement », ou en
  PowerShell **administrateur** : `w32tm /resync /force` (après `net start w32time`).
  Depuis la v1.0.x, un code TOTP refusé alors qu'il est correct est diagnostiqué
  explicitement (« horloge décalée de ~N min ») et n'incrémente pas l'anti-bruteforce
  (événement `TOTP_SKEW`).
- Firewall : `ufw default deny incoming ; ufw allow 80,443/tcp ; ufw allow from <IP_admin_FFTA> to any port 22 ; ufw enable`
- SSH : clés uniquement (`PasswordAuthentication no`), pas de root direct,
  si possible restreint aux IP FFTA ou derrière VPN.
- `unattended-upgrades` activé (MaJ sécurité automatiques) — **mais à heure fixe, la nuit, et
  en dehors de la fenêtre de maintenance**. Par défaut, Debian/Ubuntu installent vers 6 h avec
  un délai aléatoire d'une heure, et l'installation redémarre la base et Apache au passage : sur
  un autre serveur ianseo, c'est tombé **trois fois en pleine compétition** en un semestre
  (samedis matin). Gabarits `serveur/apt/` : téléchargement à 04:00, installation à **04:30**,
  redémarrage automatique à 04:45 **seulement** si une mise à jour l'exige (facultatif) — après la
  maintenance de 03:15 (§ 12), jamais pendant : un redémarrage de la base en pleine sauvegarde la
  ferait échouer, et la mise à jour du cœur serait sautée cette nuit-là. Vérifier :
  `systemctl list-timers 'apt-daily*'`. Si vous déplacez l'un des horaires, déplacez l'autre.
- Utilisateur applicatif dédié (www-data), fichiers ianseo en `root:www-data`,
  écriture limitée aux dossiers qui en ont besoin (`TourData/`, `Common/` pour
  config.inc.php lors de l'activation, `Modules/`).

### 4.2 fail2ban
Jail SSH par défaut + jail dédiée aux échecs de connexion ianseo.
Activer le fichier journal du module : créer
`Modules/Custom/AUTH/config.local.json` :
```json
{ "log_file": "/var/log/ianseo-auth.log" }
```
```ini
# /etc/fail2ban/filter.d/ianseo-auth.conf
[Definition]
failregex = ^.* ianseo-auth <HOST> \S* (LOGIN_FAIL|TOTP_FAIL|LOGIN_BLOCK)$

# /etc/fail2ban/jail.d/ianseo.conf
[ianseo-auth]
enabled  = true
port     = http,https
filter   = ianseo-auth
logpath  = /var/log/ianseo-auth.log
maxretry = 10
findtime = 15m
bantime  = 1h
```
(le rate-limit applicatif bloque à 8 ; fail2ban bannit l'IP au niveau réseau
au-delà — les deux se complètent.)
`touch /var/log/ianseo-auth.log && chown www-data /var/log/ianseo-auth.log`
+ rotation logrotate.

### 4.3 ModSecurity (WAF)
```bash
apt install libapache2-mod-security2
cp /etc/modsecurity/modsecurity.conf-recommended /etc/modsecurity/modsecurity.conf
# SecRuleEngine On
apt install modsecurity-crs   # OWASP Core Rule Set
```
Commencer en `DetectionOnly` une semaine, analyser les faux positifs (ianseo
poste beaucoup de HTML/valeurs brutes), créer les exclusions nécessaires, puis
passer `On`. C'est la principale compensation du risque « code du cœur ».

⚠️ **Avant de passer `On`** : `SecRequestBodyLimit` vaut **12,5 Mo** dans le fichier livré, alors
que PHP accepte 64 Mo (§ 6.1). En blocage, l'import d'une compétition plus lourde serait refusé
(erreur 413) sans que rien dans ianseo ne l'explique. L'aligner sur PHP dans
`/etc/modsecurity/modsecurity.conf` : `SecRequestBodyLimit 67108864`.

### 4.4 Limiteurs de débit (mod_evasive, proxy, pare-feu applicatif)
Ce guide n'en installe pas : fail2ban (§ 4.2) et l'anti-bourrage du module ciblent les **échecs
de connexion**, pas le volume de requêtes. Si vous en ajoutez un, il doit laisser passer les
**rafales de la saisie ISK-NG** : chaque téléphone envoie un `OPTIONS` et environ **8 `POST`**
vers `/Api/ISK-NG/index.php` **dans la même seconde**, et tous les téléphones d'un club sortent
par la même adresse. Vécu sur un autre serveur ianseo : mod_evasive réglé à 5 requêtes par
seconde et par adresse a bloqué **144 adresses** en 18 mois, presque toutes des tablettes de
marque pendant des compétitions. Réglages qui ont fonctionné (`/etc/apache2/mods-available/evasive.conf`) :

```apache
DOSPageCount      30
DOSSiteCount      150
DOSBlockingPeriod 10
DOSWhitelist      127.0.0.1
DOSWhitelist      192.168.*.*
# et ne pas laisser DOSSystemCommand sur l'exemple de la documentation
```

Symptôme trompeur à connaître : derrière une authentification HTTP (htdigest), le refus
s'affiche en **401** (demande de mot de passe) et non en 403 — chercher les 403 dans les
journaux ne trouve rien.

## 5. Apache — vhost durci

```apache
<VirtualHost *:80>
    ServerName YOUR-DOMAIN
    Redirect permanent / https://YOUR-DOMAIN/
</VirtualHost>

<VirtualHost *:443>
    ServerName YOUR-DOMAIN
    DocumentRoot /var/www/ianseo

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/YOUR-DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/YOUR-DOMAIN/privkey.pem
    # TLS moderne (Mozilla "intermediate")
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off

    ServerSignature Off

    <Directory /var/www/ianseo>
        Options -Indexes -Includes -ExecCGI
        AllowOverride All
        Require all granted
    </Directory>

    # fichiers sensibles jamais servis
    <FilesMatch "\.(inc\.php|json|md|bak|sql|log)$">
        Require all denied
    </FilesMatch>

    # AUCUNE exécution PHP dans les dossiers de fichiers uploadés
    <Directory /var/www/ianseo/TourData>
        php_admin_flag engine off
        <FilesMatch "\.ph(p[0-9]?|tml|ar)$"> Require all denied </FilesMatch>
    </Directory>
    <Directory /var/www/ianseo/Images>
        php_admin_flag engine off
    </Directory>

    # installation & outils serveur : localhost uniquement après mise en service
    <Location "/Install"> Require ip 127.0.0.1 ::1 </Location>
    <Location "/Update">  Require ip 127.0.0.1 ::1 </Location>
    # scripts de réparation/maintenance à l'échelle du serveur — le module AUTH
    # les bloque déjà pour les non-admins, mais on double au niveau Apache :
    <Files "RepairXAMPP.php"> Require ip 127.0.0.1 ::1 </Files>
    <Location "/Modules/Help/RepairTables.php"> Require ip 127.0.0.1 ::1 </Location>
    # (idéalement, SUPPRIMER RepairXAMPP.php du serveur : script XAMPP/Windows
    #  qui redémarre MySQL, sans objet en production Linux.)
    # si phpMyAdmin est présent sur la machine : NE PAS l'exposer
    # (le supprimer, ou Require ip 127.0.0.1 + tunnel SSH pour l'utiliser)

    Header always set Strict-Transport-Security "max-age=31536000"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</VirtualHost>
```
Certificat : `certbot --apache -d YOUR-DOMAIN` (renouvellement auto).
Si PHP-FPM : remplacer `php_admin_flag engine off` par un
`<FilesMatch \.php$> SetHandler none </FilesMatch>` équivalent.

**htdigest** : le garder pendant TOUTE la mise en place, puis au choix le
retirer (confort clubs) — les couches module+WAF prennent le relais — ou le
conserver sur `/Update` en ceinture supplémentaire.

## 6. PHP & MySQL durcis

### 6.1 php.ini
```ini
expose_php = Off
display_errors = Off
log_errors = On
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
session.gc_maxlifetime  = 43200
allow_url_include = Off
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
open_basedir = /var/www/ianseo:/tmp
upload_max_filesize = 64M
post_max_size = 64M
```
> Tester après coup : l'export/import ianseo et les impressions PDF doivent
> fonctionner (TCPDF n'a pas besoin des fonctions désactivées).

`session.gc_maxlifetime = 43200` : **12 h**, la durée d'inactivité que prévoit le module (§ 3).
La valeur par défaut de PHP (1440 s) efface une session inactive au bout de **24 minutes** :
l'organisateur parti déjeuner est déconnecté et perd la compétition ouverte — vu sur un autre
serveur. Sous Debian/Ubuntu, c'est une tâche système (`phpsessionclean`) qui efface les sessions,
d'après ce réglage **du php.ini** : un `ini_set()` dans le code n'y changerait rien. Même chose
pour `upload_max_filesize`/`post_max_size` : les deux fichiers `php.ini` à modifier sont ceux
d'Apache (`/etc/php/8.x/apache2/php.ini`) ou de PHP-FPM — pas celui de la ligne de commande.
**Multi-comptes › Configuration du serveur › État du serveur** affiche les valeurs réellement
appliquées au site.

### 6.2 MySQL
- `bind-address = 127.0.0.1` (jamais exposé).
- Utilisateur dédié `ianseo` limité à la base `ianseo`
  (`GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP ON ianseo.* …`),
  **pas** de `FILE`, `SUPER`, `GRANT`. Mot de passe long généré.
  Ajouter `CREATE VIEW, SHOW VIEW` pour la mise à jour vers 1.2.0 : les tables alors renommées
  gardent leur ancien nom sous forme de vue, pour les fichiers déployés jusqu'au prochain
  déploiement (README_AUTH_FR.md › Base de données). Sans ces droits, les vues sont omises :
  redéployer aussitôt après cette mise à jour.
- Pas de phpMyAdmin accessible depuis Internet (voir vhost).
- `Common/config.inc.php` (identifiants DB) : permissions `640 root:www-data`.

### 6.3 MySQL — performances (leçons d'un serveur en production)

Sur un autre serveur ianseo en ligne (septembre 2026), **ajouter un archer bloquait la page 5 à
10 minutes**. Les réglages ci-dessous en sont tirés ; gabarit : `serveur/mysql/ianseo.cnf`.
**Multi-comptes › Configuration du serveur › État du serveur** contrôle chacun d'eux.

- **MariaDB (installé au § 8.0) et MySQL 8 ne se comportent pas pareil.** Pour vérifier un numéro
  de cible, le cœur (`createAvailableTargetSQL()`) fabrique une requête d'**une ligne `UNION` par
  place** du départ, qu'il filtre ensuite. MySQL 8.0.22+ recopie ce filtre dans chaque branche
  (optimisation `derived_condition_pushdown`) : le temps devient quadratique. Mesuré sur un
  départ de 9 999 cibles × 8 = 80 000 places : **~10 min** sous MySQL 8.0.46, **1,2 s** une fois
  l'optimisation coupée, 1,5 s sous MariaDB. Pendant ce temps, le verrou de session PHP bloque
  toutes les autres pages du même utilisateur, et le processeur est saturé pour tout le monde.
  **Sous MySQL 8 seulement** :
  ```bash
  sudo mysql -e "SET PERSIST optimizer_switch='derived_condition_pushdown=off';"
  ```
  Réglage de vitesse uniquement (résultats identiques, comportement de MySQL 5.7), conservé au
  redémarrage, réversible (`=on`). **Pas dans un fichier `.cnf` commun** : MariaDB ne connaît pas
  cette option et refuserait de démarrer.
- **Dimensionner les départs au besoin réel**, jamais « 9 999 cibles par sécurité » : même
  réglage appliqué, 80 000 places coûtent plus d'une seconde par vérification. Le module
  signale les départs de plus de 5 000 places (à l'organisateur dans « Inscriptions en ligne »,
  à l'administrateur dans « État du serveur »). Conseil aux opérateurs : choisir le **départ**
  de l'archer AVANT sa cible — sans départ, la vérification porte sur tous les départs à la fois.
- **Connexions** : ianseo ouvre **deux** connexions à la base par page (lecture + écriture).
  `max_connections` doit donc valoir au moins **2 × le nombre de processus PHP** (Apache
  `MaxRequestWorkers` en prefork — 150 sous Debian —, ou `pm.max_children` en PHP-FPM), plus une
  marge pour les crons. La valeur par défaut (151) ne couvre que 75 processus : au-delà, la
  base refuse la connexion et l'utilisateur tombe sur une page d'erreur.
- **Mémoire** : `innodb_buffer_pool_size` au moins égal à la taille de la base (128 Mo par
  défaut) — § 15 pour la cible d'un gros serveur. Et `MaxRequestWorkers` à la mesure de la RAM :
  150 processus Apache d'environ 65 Mo chacun dépassaient les 8 Go de l'autre serveur.
- **Journal des requêtes lentes** (seuil 2 s) : c'est lui qui a désigné la requête en cause.
  Fichier : `/var/lib/mysql/<nom-du-serveur>-slow.log` ; résumé : `sudo mysqldumpslow -s t <fichier>`.
- **Tester sous le moteur de production.** Un poste de développement XAMPP tourne sous MariaDB :
  ce problème y était invisible. Si le serveur est sous MySQL 8, faire au moins un essai
  (création de compétition, ajout d'archers, saisie) sur un MySQL 8.

## 7. Données personnelles & RGPD

### 7.1 La base licenciés sur le serveur — choix assumé, compensé
Décision FFTA : la base de rapprochement licenciés (`LookUpEntries`) **reste
sur le serveur** pour que les organisateurs puissent ajouter/modifier des
inscriptions en ligne, et elle est alimentée par un **cron avec compte de
service** (§ 12) — plus aucune synchro manuelle par les organisateurs, plus
de fichier licences qui se promène sur les PC des clubs (c'est aussi un gain).

Conséquence à assumer : cette table (~80 000 noms + dates de naissance +
n° licence + club) est consultable par **tout compte connecté** (c'est la
fonction de recherche d'inscription d'ianseo). Un seul compte club hameçonné
y donne accès. Compensations obligatoires :
- SSO espace dirigeant (§ 11) : pas de mots de passe faibles côté clubs, les
  comptes suivent la vie des accès FFTA (retrait du rôle Gestionnaire = plus
  d'accès au prochain login) ;
- WAF + rate-limit + journal surveillé (pics de LOGIN_FAIL, volumes anormaux) ;
- purge des compétitions terminées : archive (export .ianseo chiffré) +
  suppression du serveur ~3 mois après la compétition ;
- ne demander aux clubs que les champs nécessaires aux inscriptions.

### 7.2 Sauvegardes — chiffrées et hors serveur
Intégrées au module (§ 9 bis) : chaque nuit la base et les fichiers, dans la journée des copies
« à chaud » de la base, et une copie en ligne **chiffrée** (rclone `crypt`). Principes, qui valent
quel que soit l'outil :
- **dès le premier jour** — l'autre serveur ianseo en ligne a tourné des mois sans aucune sauvegarde ;
- **chiffrées hors du serveur**, et les secrets qui permettent de les relire (mots de passe
  `crypt`) rangés **ailleurs** que sur le serveur : c'est justement le jour où il est perdu qu'on
  en a besoin ;
- **au moins un test de restauration** avant l'ouverture, puis périodiquement — sans toucher au
  site : `sudo ianseo-restore --test <copie>` (§ 9 bis).

### 7.3 Conformité (à traiter avec le DPO FFTA)
- Inscrire le traitement au **registre** (finalité : gestion sportive des
  compétitions ; base légale : intérêt légitime / relation contractuelle).
- **Information des personnes** : mention dans les documents d'inscription ;
  les résultats nominatifs publiés sont un usage sportif standard mais doivent
  figurer dans la mention.
- Durées de conservation alignées sur la purge (§ 7.1).
- **Droit à l'effacement** : Multi-comptes › Anonymiser un licencié (§ 9).
- **Violation de données** : procédure de notification CNIL sous 72 h —
  prévoir le contact et la marche à suivre AVANT l'incident.
- Sous-traitance hébergeur (OVH…) : vérifier le DPA.

## 8. Installation pas à pas

> **Gabarits fournis** : tous les fichiers à installer **hors** du module
> (scripts d'exploitation, vhosts Apache, cron, sudoers, logrotate, fail2ban)
> sont versionnés dans **`Modules/Custom/AUTH/serveur/`**, avec leur destination
> et leurs droits — voir `serveur/README.md`. Recopiez-les plutôt que de les
> retaper : c'est là que se glissent les erreurs de configuration.

### 8.0 Depuis une Debian neuve — séquence complète

Toutes les commandes, dans l'ordre. Remplacez `YOUR-DOMAIN` par votre nom
d'hôte. Rien ici ne contient de secret : les identifiants ne sont saisis qu'à
l'étape 7, dans un fichier en `chmod 600`.

```bash
# ── 1. Système de base ────────────────────────────────────────────────
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Europe/Paris     # sinon les horaires des logs et
                                               # des crons prêtent à confusion
sudo apt install -y apache2 mariadb-server php php-mysql php-gd php-curl \
                    php-mbstring php-zip php-xml unzip curl \
                    cron rsyslog fail2ban certbot python3-certbot-apache \
                    libapache2-mod-security2
sudo systemctl enable --now cron fail2ban      # « cron » manque sur certaines
                                               # images minimales
sudo mysql_secure_installation

# ── 2. Base de données ────────────────────────────────────────────────
sudo mysql -e "CREATE DATABASE ianseo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'ianseo'@'localhost' IDENTIFIED BY 'MOT-DE-PASSE-FORT';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ianseo.* TO 'ianseo'@'localhost'; FLUSH PRIVILEGES;"

# ── 3. Code ianseo ────────────────────────────────────────────────────
cd /tmp && curl -LO https://www.ianseo.net/Release/ianseo.zip
sudo mkdir -p /var/www/ianseo && sudo unzip -q ianseo.zip -d /var/www/ianseo
sudo chown -R www-data:www-data /var/www/ianseo        # temporaire, pour installer

# ── 4. Apache + TLS ───────────────────────────────────────────────────
sudo a2enmod ssl rewrite headers
sudo a2dissite 000-default
# (les vhosts sont posés à l'étape 6 ; certbot a besoin du port 80 ouvert)
sudo certbot --apache -d YOUR-DOMAIN

# ── 5. ianseo : installation initiale ─────────────────────────────────
# Ouvrir https://YOUR-DOMAIN/Install/ et suivre l'assistant (base « ianseo »,
# utilisateur « ianseo »). Créer une compétition de test, vérifier que tout
# fonctionne AVANT d'ajouter la couche multi-comptes.

# ── 6. Fichiers système (gabarits du module) ──────────────────────────
# Voir serveur/README.md pour le détail ; adapter YOUR-DOMAIN dans les vhosts.
cd /var/www/ianseo/Modules/Custom/AUTH/serveur   # (après l'étape 7 si le module
                                                 #  n'est pas encore déployé)
sudo install -m 0750 -o root -g root bin/ianseo-*              /usr/local/bin/
sudo mkdir -p /var/www/maintenance
sudo install -m 0644 -o root -g root apache/maintenance.html   /var/www/maintenance/index.html
sudo install -m 0644 -o root -g root apache/ianseo*.conf       /etc/apache2/sites-available/
sudo install -m 0440 -o root -g root sudoers/ianseo-maintenance /etc/sudoers.d/
sudo visudo -c                                   # DOIT afficher « parsed OK »
sudo install -m 0644 -o root -g root logrotate/ianseo          /etc/logrotate.d/
sudo install -m 0644 -o root -g root fail2ban/filter-ianseo-auth.conf /etc/fail2ban/filter.d/ianseo-auth.conf
sudo install -m 0644 -o root -g root fail2ban/jail-ianseo.conf        /etc/fail2ban/jail.d/ianseo.conf
sudo touch /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log /var/log/ianseo-backup.log
sudo chown www-data:adm /var/log/ianseo-*.log && sudo chmod 0640 /var/log/ianseo-*.log
sudo systemctl restart fail2ban
sudo apache2ctl configtest && sudo systemctl reload apache2
# mises à jour du système à heure fixe, après la maintenance (§ 4.1)
sudo install -D -m 0644 apt/apt-daily.timer.conf         /etc/systemd/system/apt-daily.timer.d/ianseo.conf
sudo install -D -m 0644 apt/apt-daily-upgrade.timer.conf /etc/systemd/system/apt-daily-upgrade.timer.d/ianseo.conf
sudo install -m 0644 -o root -g root apt/51ianseo-auto-reboot /etc/apt/apt.conf.d/   # facultatif
sudo systemctl daemon-reload
# base de données (§ 6.3) — MySQL 8 : /etc/mysql/mysql.conf.d/ et le réglage SET PERSIST
sudo install -m 0644 -o root -g root mysql/ianseo.cnf /etc/mysql/mariadb.conf.d/99-ianseo.cnf
sudo systemctl restart mariadb

# ── 7. Module AUTH ────────────────────────────────────────────────────
# Copier Modules/Custom/AUTH/ et Modules/Custom/_shared/ dans /var/www/ianseo/
sudo chown -R www-data:www-data /var/www/ianseo/Modules/Custom
# Configuration locale (secrets) — chmod 600, JAMAIS lisible par le web :
sudo -u www-data nano /var/www/ianseo/Modules/Custom/AUTH/config.local.json
sudo chmod 600 /var/www/ianseo/Modules/Custom/AUTH/config.local.json
```

`config.local.json` — modèle (⚠️ **UTF-8 sans BOM** : un BOM ferait échouer la
lecture JSON et **toute** la configuration serait ignorée en silence) :

```json
{
  "log_file": "/var/log/ianseo-auth.log",
  "licsync":  { "username": "COMPTE-DE-SERVICE", "password": "…", "otp": "" },
  "maintenance": {
    "on":     "sudo /usr/local/bin/ianseo-maintenance-on",
    "off":    "sudo /usr/local/bin/ianseo-maintenance-off",
    "unlock": "",
    "lock":   "",
    "steps":  { "core": false, "modules": true, "licences": true, "logos": true },
    "notice": { "at": "03:15", "lead_minutes": 15 },
    "ping_url": ""
  }
}
```

(`ping_url` : facultatif, adresse d'un contrôle de supervision — voir § 9, « Alertes ».)

```bash
# ── 8. Comptes, déploiement, verrouillage ─────────────────────────────
# a) https://YOUR-DOMAIN/Modules/Custom/AUTH/admin/ → créer le compte ADMIN
# b) admin/deploy.php → « Déployer les fichiers » puis « Activer l'authentification »
# c) Se reconnecter : changement de mot de passe forcé, puis 2FA obligatoire
sudo ianseo-lock                                  # état normal : cœur en lecture seule
sudo -u www-data test -w /var/www/ianseo/TV/Photos && echo "TV OK"

# ── 9. Essai à blanc de la maintenance, PUIS activation du cron ───────
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php --dry-run
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php --only=none
# (le site doit répondre 503 pendant l'exécution, puis revenir)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-nightly /etc/cron.d/
# copies à chaud de la base, toutes les 6 heures (§ 9 bis)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-backup-live /etc/cron.d/
# listes d'attente des inscriptions en ligne, toutes les 10 minutes (§ 11 bis)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-waitlist /etc/cron.d/
# indice de confiance des payeurs, une fois par nuit à 05:15 (après la fenêtre de maintenance)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-trust /etc/cron.d/
```

**Le lendemain**, vérifier : `tail -n 40 /var/log/ianseo-maintenance.log`, puis
`sudo ianseo-restore --test <la sauvegarde de la nuit>` — premier test de restauration.

### 8.1 Rappel des étapes fonctionnelles

1. **ianseo** : ZIP officiel + `https://serveur/Install/`, test mono-utilisateur.
   Pendant toute cette phase : htdigest Apache actif sur tout le site.
2. **Module** : copier `Modules/Custom/AUTH/` et `Modules/Custom/_shared/`.
3. **Comptes** : `…/Modules/Custom/AUTH/admin/` → créer le compte **ADMIN**
   (mot de passe temporaire affiché une fois), puis des comptes de test
   CLUB (`1075093`), CD (`1075`), CR (`10`).
4. **Déploiement** : `admin/deploy.php` → « 1. Déployer les fichiers » puis
   « 2. Activer l'authentification » (refusé tant qu'aucun ADMIN actif).
5. **Test immédiat en navigation privée** : login ADMIN → changement de mot de
   passe forcé → **configuration 2FA forcée** (application d'authentification
   sur le téléphone) ; login avec un compte espace dirigeant (SSO) → choix de
   structure → ne voit que son périmètre ; anonyme → page de connexion partout.
6. Configurer le SSO et le cron licences (§§ 11-12 : `config.local.json`,
   compte de service, crontab).
7. Durcissement §§ 4-6 (fail2ban, ModSecurity, vhost, php.ini, MySQL).
8. Retirer/réduire le htdigest, ouvrir aux premiers clubs pilotes.

**Codes de compétition** : le nom/code est **libre** (chaque organisateur met
ce qu'il veut) mais doit être **unique sur le serveur** — un code déjà utilisé
est refusé à la création et à l'import (dans le cœur ianseo, réutiliser un
code ÉCRASE la compétition existante ; le module l'interdit). Seul le club
propriétaire peut ré-importer sa propre compétition (restauration de
sauvegarde). La propriété est enregistrée automatiquement à la création ;
l'admin peut l'attribuer/corriger via la page « Compétitions & partage ».

## 9. Exploitation

- **Clubs** : créent leurs compétitions, page « Compétitions & partage » pour
  ouvrir l'accès CD/CR/FFTA (défaut : privé). Partage = lecture + écriture.
- **Admin** : page Utilisateurs = création de comptes, RàZ mot de passe
  (sessions révoquées), RàZ 2FA (perte de téléphone), déconnexion à distance,
  journal des 30 derniers événements.
- **Surveiller** : le journal AuthLog (pics de LOGIN_FAIL), fail2ban
  (`fail2ban-client status ianseo-auth`), l'espace disque, les MaJ ianseo. Le journal admin
  (Multi-comptes › Utilisateurs, un onglet Organisateurs / Archers avec chacun son journal
  filtrable + paginé) donne une vue rapide.
- **Alertes** : un bandeau rouge s'affiche sur toutes les pages de l'administrateur quand la
  maintenance de la nuit a échoué (avec les étapes en cause), quand elle **n'a pas tourné** depuis
  plus de 30 h (tâche planifiée arrêtée : plus aucune sauvegarde), ou quand la copie en ligne ou
  une copie à chaud a échoué. Pour être prévenu **même serveur éteint**, renseigner en ligne de
  commande `maintenance.ping_url` dans `config.local.json` : l'adresse d'un contrôle
  [healthchecks.io](https://healthchecks.io) (gratuit), appelée à la fin de chaque nuit (et
  `…/fail` en cas d'échec). Régler le contrôle sur « une fois par jour, tolérance 2 h » : le
  service envoie un e-mail en cas d'échec **et** quand rien n'arrive.
- **État du serveur** (Multi-comptes › Configuration du serveur) : contrôles en lecture seule des
  pièges vécus sur un serveur en production — réglage MySQL 8 (§ 6.3), connexions et mémoire de la
  base, journal des requêtes lentes, durée des sessions et taille des imports (§ 6.1), limite
  ModSecurity (§ 4.3), OPcache, heure des mises à jour du système (§ 4.1), départs surdimensionnés,
  âge des sauvegardes — chacun avec la commande de correction.
- **Anonymiser un licencié** (Multi-comptes › Anonymiser un licencié, administrateur serveur) : pour
  une demande d'effacement. Recherche par licence, nom ou prénom, aperçu de tout ce qui change,
  confirmation en retapant la licence.
  - **Compétitions à venir** (non terminées, et pas encore de score pour cette personne) :
    inscriptions et rôles d'officiel **supprimés**, la place libérée va à la liste d'attente. Si un
    paiement avait été enregistré, l'organisateur est prévenu d'un **remboursement à faire** (club et
    montant, sans nom) sur sa page « Paiements », jusqu'à ce qu'il clique « Remboursement
    effectué » — ce qui inscrit le remboursement dans l'historique, où les paiements restent sous `ANON`. Une compétition dont
    l'organisateur a verrouillé les participants est anonymisée à la place (la page le signale).
  - **Compétitions tirées** : licence remplacée par `ANON`, nom et prénom vidés (participants et
    officiels), date de naissance supprimée (la catégorie, enregistrée à l'inscription, ne change
    pas), photo, légende et e-mail supprimés ; la licence disparaît aussi des inscriptions en ligne,
    des paiements, de la boutique et du journal. Scores, classements, matchs, club et catégorie sont
    conservés. Ces résultats ne sont plus rattachés à une licence (un export vers la fédération les
    enverrait sous `ANON`).
  - Partout : compte en ligne supprimé. Hors de portée : le fichier fédéral des licences (rechargé chaque nuit),
  les données des autres modules, les résultats déjà publiés sur ianseo.net (la page liste les
  compétitions à republier) ou transmis à la fédération, et les sauvegardes, jusqu'à la fin de leur
  durée de conservation.
- **Journaux système : 60 jours.** Par défaut Apache ne garde que 14 jours : trop court pour
  analyser une compétition après coup (l'autre serveur n'a pu remonter qu'à deux semaines).
  `sudo sed -i 's/^\s*rotate 14/\trotate 60/' /etc/logrotate.d/apache2`. Même durée pour
  l'historique de charge si le paquet `sysstat` est installé (`HISTORY=60` dans `/etc/sysstat/sysstat`).
- **Rétention des journaux** : `AuthLog` et `BookingLog` sont purgés automatiquement au-delà de
  **180 jours** (au plus une fois par jour, via le bootstrap ; aussi jouable dans le cron).
  Durée modulable : `config.local.json` → `"log_retention_days": <jours>` (borne 7 à 3650).
  Sans conséquence sur l'anti-bruteforce (fenêtre 15 min). Aligne la conservation sur la
  politique de confidentialité (« journal conservé quelques mois »).
- **Statistiques d'usage** (Multi-comptes › Statistiques d'usage, ADMIN) : mesure d'audience
  **agrégée** (pages vues par heure/jour, visiteurs uniques, pages principales, taux de
  conversion des archers, nb d'archers inscrivant d'autres archers), en 2 onglets
  Organisateurs / Archers. **Aucune donnée personnelle, aucune IP** : les connectés sont comptés
  par identité de compte, les visiteurs anonymes de l'accueil via un **cookie de mesure
  d'audience exempté de consentement** (`aud`, 1re partie, ≤ 13 mois, doctrine CNIL). Compteurs
  dans `AuthUsage` (conservés 25 mois) et `AuthUsageSeen` (rétention des journaux), purgés avec
  les journaux. Désactivable par `config.local.json` → `"stats_enabled": false` ; fuseau des
  seaux réglable par `"stats_timezone"` (défaut `Europe/Paris`). La **politique cookies**
  (page publique Mentions légales & CGU → Cookies) décrit ce cookie et son exemption.
- **Mise à jour ianseo** : menu Update (réservé ADMIN). La MaJ remet `htdocs/` à
  zéro et efface `Modules/Authentication/`, mais **un filet auto-répare** : le bloc
  `AUTH-SELFHEAL` de `config.inc.php` (posé à l'activation / à chaque déploiement, et
  préservé aux MaJ) recopie `dist/` → `Modules/Authentication/` dès la 1re requête.
  **Plus de redéploiement manuel après une MaJ** — à condition que le serveur web
  puisse écrire dans `Modules/`. `config.inc.php` et `Modules/Custom/` survivent aux MaJ.
  (Un install existant sans le filet le reçoit au prochain « Déployer ».)
- **Mise à jour du module** : menu Multi-comptes → Mise à jour module, puis
  redéployer si `dist/` a changé.

## 9 bis. Sauvegardes (nuit + copies à chaud)

Chaque nuit, dans la fenêtre de maintenance (site fermé, donc copie cohérente) et **juste avant
la mise à jour du cœur**, `cron/backup.php` produit :

- `ianseo-db-AAAAMMJJ-HHMMSS.sql.gz` — dump de la base (`mysqldump`) ;
- `ianseo-files-AAAAMMJJ-HHMMSS.tar.gz` — archive du site (sans `TV/Photos`) : revenir en
  arrière demande la base **et** le code qui va avec.

**Sans sauvegarde valide, le cœur n'est pas mis à jour cette nuit-là** (réglable :
`backup.required_for_core`). Tout se règle dans **Multi-comptes › Configuration du serveur**,
ou dans `config.local.json` :

```json
"backup": {
  "enabled": true,
  "dir": "/var/backups/ianseo",
  "keep_days": 14,
  "files": true,
  "logos": false,
  "required_for_core": true,
  "remote": "",
  "remote_keep_days": 30,
  "live": true,
  "live_keep_hours": 48
}
```

**Logos des clubs hors des dumps (`logos: false`, défaut).** Sur le serveur de test, le cache
des logos pesait 59 % de la base : dump de **57,6 Mo en 11,6 s** avec, **4,1 Mo en 2,6 s** sans.
Leurs deux tables (`Flags`, `AuthClubLogos`) restent dans le fichier, mais en **structure seule,
créée seulement si elle manque** : restaurer sur ce serveur laisse les logos en place ; sur un
serveur neuf, les tables sont créées vides et la synchro des logos les remplit (celle de la nuit,
ou tout de suite `sudo -u www-data php …/cron/sync-logos.php --full` — pour les compétitions non
terminées ; les compétitions terminées restent sans logo).

**Copies à chaud de la base** (`ianseo-live-AAAAMMJJ-HHMMSS.sql.gz`), toutes les 6 heures avec
la nuit : 09:05, 15:05, 21:05. Site ouvert, sans rien bloquer : `--single-transaction` sur des
tables InnoDB lit un instantané cohérent sans verrouiller aucune table — une page qui enregistre
une volée pendant la copie n'attend pas. Quelques secondes (2,6 s sur le serveur de test).
Elles limitent la perte à quelques heures de saisie au lieu d'une journée, sont envoyées en ligne
comme la nuit, gardées 48 h (`live_keep_hours`) et se sautent d'elles-mêmes pendant la fenêtre de
maintenance ou une restauration. Installation, une fois :

```bash
sudo touch /var/log/ianseo-backup.log && sudo chown www-data:adm /var/log/ianseo-backup.log
sudo install -m 0644 -o root -g root /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-backup-live /etc/cron.d/
```

(Suspendre sans toucher au serveur : case « Copies à chaud » de la page de configuration.)

**Une fois par serveur** — le dossier par défaut est sous `/var/backups`, qui appartient à root :

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/backups/ianseo
```

Le dossier est toujours **hors du site web** (refusé sinon : Apache ne bloque pas les `.gz`).
La dernière sauvegarde de chaque type est gardée même au-delà de `keep_days`.

Lancer une sauvegarde à la main : `sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/backup.php`

### Copie en ligne (Google Drive, Dropbox, OneDrive, NAS…) — rclone

```bash
sudo apt install rclone
sudo install -d -o www-data -g www-data -m 0700 /var/www/.config
sudo -u www-data rclone config
```

⚠️ **La base contient les données personnelles des licenciés** : on déclare **deux**
destinations — le stockage, puis une couche `crypt` (chiffrée) par-dessus. ianseo n'écrit que
dans la seconde : l'hébergeur ne voit que des fichiers illisibles.

⚠️ À l'écran d'accueil de `rclone config`, **ne pas** choisir `s) Set configuration password` :
la sauvegarde tourne la nuit, sans personne pour saisir ce mot de passe — elle échouerait.

**Destination 1 — le stockage** (exemple Google Drive ; `dropbox`, `onedrive`… se déroulent pareil) :

| Question | Réponse |
|---|---|
| `n/s/q>` | `n` |
| `name>` | `gdrive` |
| `Storage>` | `drive` |
| `client_id>` / `client_secret>` | Entrée (vides) |
| `scope>` | `drive.file` — rclone ne voit **que les fichiers qu'il a créés**, rien d'autre du Drive (voir ci-dessous pour un dossier partagé) |
| `service_account_file>` | Entrée |
| `Edit advanced config?` | `n` |
| `Use web browser to automatically authenticate?` | `n` — le serveur n'a pas de navigateur |

rclone affiche alors une commande `rclone authorize "drive" "…"`. La lancer **sur un ordinateur
avec navigateur** où rclone est installé (Windows : `winget install Rclone.Rclone`), se connecter
au compte Google choisi, puis recopier le jeton affiché dans `config_token>` sur le serveur.
Ensuite : `Shared Drive?` → `n`, `Keep this remote?` → `y`.

**Déposer dans un dossier partagé par quelqu'un d'autre** (vécu sur l'autre serveur) : `drive.file`
ne voit ni les drives partagés ni un dossier créé par un autre compte. Il faut alors le scope
`drive`, et désigner le dossier par son identifiant — la fin de son adresse dans le navigateur,
`https://drive.google.com/drive/folders/<identifiant>` — à la question `root_folder_id>` (options
avancées : `Edit advanced config?` → `y`).

**Bon à savoir sur Google Drive** : les fichiers appartiennent au compte Google qui a autorisé
rclone et en dépendent (compte supprimé = sauvegardes perdues) — préférer un compte de la
structure à un compte personnel. Ne jamais les supprimer depuis l'interface de Drive (les noms y
sont chiffrés, on ne sait pas ce qu'on efface) : la rotation du module s'en charge, et supprime
**définitivement** (sans passer par la corbeille de Drive, qui compterait dans le quota).

**Destination 2 — la couche chiffrée** :

| Question | Réponse |
|---|---|
| `n/s/q>` | `n` |
| `name>` | `gdrive-chiffre` |
| `Storage>` | `crypt` |
| `remote>` | `gdrive:ianseo` (dossier `ianseo` du Drive, créé au premier envoi) |
| `filename_encryption>` | `standard` |
| `directory_name_encryption>` | `true` |
| `password` | `g` pour en générer un (ou `y` pour le saisir) |
| `password2` (sel) | `g` |
| `Edit advanced config?` | `n` ; `Keep this remote?` → `y` ; puis `q` |

🔑 **Recopier les deux mots de passe affichés dans un coffre-fort ou un gestionnaire de mots de
passe, hors du serveur.** Sans eux, les sauvegardes en ligne sont **irrécupérables** — par
exemple si le serveur lui-même est perdu, qui est justement le cas où l'on en a besoin.

Vérifier depuis le serveur, puis dans ianseo :

```bash
sudo -u www-data rclone mkdir gdrive-chiffre:ianseo   # crée le dossier (sans effet s'il existe)
sudo -u www-data rclone lsf gdrive-chiffre:ianseo     # aucune erreur = accès ok (vide au début)
```

(Sans le `mkdir`, une destination qui n'a encore jamais rien reçu répond
`directory not found` : ce n'est pas une panne, le premier envoi créerait le dossier.)

**Multi-comptes › Configuration du serveur** → Copie en ligne : `gdrive-chiffre:` → Enregistrer →
**Tester la destination en ligne**. Un échec de la copie en ligne ne bloque pas la mise à jour
(la copie locale suffit pour revenir en arrière).

**L'envoi part après la réouverture du site**, en arrière-plan : seul le dump a besoin du site
fermé. Sur le premier serveur, 125 Mo ont mis 17 min à partir (liaison montante lente) — en
restant dans la fenêtre, le site serait resté fermé 22 min au lieu de 5, et les fichiers du cœur
déverrouillés d'autant. Le résultat s'ajoute à la fin du journal de la nuit
(`Copie en ligne : ok → …`), après la ligne `Terminé en … s`. Lancé à la main dans un terminal,
l'envoi se fait sur place.

Taille à prévoir en ligne : ≈ (taille d'une nuit) × `remote_keep_days` + (taille d'une copie à
chaud) × 8 — sans les logos, la base compte peu ; c'est l'archive des fichiers qui domine.
Un seul envoi à la fois : si le précédent traîne encore, la copie locale est faite quand même et
l'échec de l'envoi est signalé (bandeau administrateur).

### Restaurer

Script `serveur/bin/ianseo-restore` (installé dans `/usr/local/bin/`, à lancer en root) :

```bash
sudo ianseo-restore                                   # liste les sauvegardes, les plus récentes d'abord
sudo ianseo-restore --test ianseo-db-XXXX.sql.gz      # VÉRIFIE une copie sans toucher au site
sudo ianseo-restore ianseo-db-XXXX.sql.gz             # restaure la base (ou un ianseo-files-… : les fichiers)
```

⚠️ Restaurer la base ramène **toutes les compétitions du serveur** au moment de la copie : tout
ce qui a été saisi depuis est perdu, pour tout le monde. Le script demande de taper `YES`, empêche
la maintenance nocturne et les copies à chaud de démarrer, met le site en maintenance, **sauvegarde
l'état actuel** (on peut vouloir y revenir : c'est la copie en tête de liste), restaure, remet le
cœur en lecture seule (fichiers) et rouvre le site. Si la restauration elle-même échoue, le site
**reste fermé** — une base à moitié restaurée ne doit pas être servie — et le message dit quoi faire.
Pour revenir en arrière après une mise à jour du cœur qui a mal tourné : la base **et** les
fichiers de la même nuit (même horodatage), la base d'abord. Une copie prise avant AUTH 1.2.0
(tables `AUT_*` et `BK_*`) est restaurée telle quelle, puis amenée aux noms de tables actuels
par `cron/schema.php` avant la réouverture du site.

**Tester une restauration** avant l'ouverture, puis de temps en temps : `--test` restaure la copie
dans une base jetable, compte tables, compétitions et inscriptions, affiche les compétitions les
plus récentes, puis supprime la base jetable. Pour une copie en ligne, la rapatrier d'abord
(`DESTINATION` = la valeur du champ « Copie en ligne », par exemple `gdrive-chiffre:ianseo`) :
`sudo -u www-data rclone copy DESTINATION/ianseo-db-XXXX.sql.gz /tmp/` puis
`sudo ianseo-restore --test /tmp/ianseo-db-XXXX.sql.gz`.

Sans le script (par exemple sur une machine neuve où le module n'est pas encore là) :

```bash
sudo /usr/local/bin/ianseo-maintenance-on
gunzip -c /var/backups/ianseo/ianseo-db-XXXX.sql.gz | sudo mysql ianseo
sudo tar -xzf /var/backups/ianseo/ianseo-files-XXXX.tar.gz -C /var/www   # remet /var/www/ianseo
sudo /usr/local/bin/ianseo-lock                                          # cœur en lecture seule
sudo /usr/local/bin/ianseo-maintenance-off
```

## 9 ter. Configuration depuis ianseo (`admin/config.php`)

`config.local.json` se modifie depuis **Multi-comptes › Configuration du serveur** (administrateur
serveur uniquement). Règles appliquées côté serveur :

- les mots de passe ne sont **jamais** affichés (remplacés par `••••••••`, qui veut dire « inchangé ») ;
- **verrouillés** — modifiables seulement en ligne de commande : les commandes de maintenance
  (`maintenance.on/off/lock/unlock`), les chemins (`*file`, `*dir`, `*path`, `*bin`…) et les
  adresses de serveurs (`*base`, `*url`, `*host`, toute valeur `https://…`). Une session
  administrateur volée ne doit permettre ni d'exécuter une commande sur le serveur, ni d'écrire un
  `.php` dans le site (`log_file`), ni de détourner les identifiants des organisateurs (`sso.base`).
  Seule exception : `backup.dir`, contrôlé (jamais dans le site) ;
- écriture atomique, version précédente dans `config.local.json.bak`, événement `CONFIG_EDIT`
  dans le journal.

Le fichier doit appartenir au serveur web : `sudo chown www-data:www-data …/AUTH/config.local.json && sudo chmod 600 …`

## 10. Procédure de secours

Si `USERAUTH` est actif mais `Modules/Authentication/BlockFunction.php`
manque, **tout le site est en erreur fatale** (fermé, pas ouvert — voulu).
**Normalement le filet `AUTH-SELFHEAL` de `config.inc.php` répare seul** dès la 1re
requête. S'il ne le fait pas (serveur web sans droit d'écriture sur `Modules/`, ou
`config.inc.php` réinitialisé), depuis la console :

```bash
# redéployer :
cp /var/www/ianseo/Modules/Custom/AUTH/dist/* /var/www/ianseo/Modules/Authentication/
# OU désactiver l'authentification :
sed -i 's/$CFG->USERAUTH = true;/$CFG->USERAUTH = false;/' /var/www/ianseo/Common/config.inc.php
```
```powershell
# Windows :
Copy-Item C:\ianseo\htdocs\Modules\Custom\AUTH\dist\* C:\ianseo\htdocs\Modules\Authentication\ -Force
```

Rappel : depuis `localhost` (console serveur ou tunnel
`ssh -L 8080:localhost:80 serveur`), ianseo reste accessible sans compte —
porte de secours native du cœur. C'est aussi pour ça que l'accès SSH doit être
verrouillé (clés + IP restreintes) : **qui a le SSH a ianseo**.
Le point à connaître : le serveur web (www-data) doit posséder les fichiers pour que l'auto-réparation AUTH et les écritures locales (config.local.json, sessions, logs) fonctionnent — le chown www-data est aujourd'hui une étape manuelle (affichée en fin de script d'installation)

## 11. SSO Espace Dirigeant FFTA

Les organisateurs se connectent avec leurs **identifiants dirigeant.ffta.fr** :
pas de création de comptes ni de gestion de mots de passe côté serveur ianseo.

- À la connexion, le serveur valide les identifiants en se connectant à
  l'espace dirigeant (même flux que l'intégration licences FR existante), lit
  les **structures rattachées** (menu select-structure) et en déduit le rôle :
  club (badge = agrément, ex. `0760171`), CD (`60000` → dept 60), CR,
  Fédération. Rôle FFTA requis : `Gestionnaire`/`Administrateur` (réglable).
- **Pas de choix de structure à la connexion** : la personne entre directement
  dans sa **dernière vue** utilisée (ou son niveau maximum), puis **bascule de
  vue à la volée** via le sélecteur de la barre (club / CD / CR / Fédé / Admin).
  La vue active détermine ce qu'elle voit et le propriétaire des compétitions
  qu'elle crée. Les structures sont resynchronisées à chaque connexion : une
  structure retirée sur l'espace dirigeant disparaît au login suivant.
- Le compte ianseo est **provisionné automatiquement** à la première
  connexion ; un admin peut le désactiver à tout moment (bloque l'accès même
  si les identifiants FFTA restent valides).
- **Le mot de passe FFTA n'est ni stocké ni journalisé** — il transite en
  HTTPS vers dirigeant.ffta.fr uniquement. Si le compte FFTA a une MFA, le
  champ « Code MFA » du formulaire est relayé.
- Les comptes **ADMIN** peuvent être votre propre compte dirigeant (SSO) +
  2FA de notre serveur (QR code d'enrôlement), OU un compte local. Le rôle
  admin est toujours **octroyé explicitement** (jamais déduit du SSO).
  **Gardez le compte local `ianseo` en secours (« break-glass »)** : si
  dirigeant.ffta.fr est indisponible, il permet de reprendre la main
  (indépendant du service externe). La 2FA de ce serveur ne concerne que les
  comptes admin (les autres sont sécurisés par l'Espace Dirigeant).
- Limite à connaître : c'est un **relais de crédentiels**, pas un OAuth. À
  terme, demander au prestataire de l'espace dirigeant un vrai client
  OpenID Connect (le module pourra basculer) ; en attendant, si la page de
  login FFTA change de structure, le SSO s'arrête proprement (message
  d'erreur explicite) et les comptes locaux continuent de fonctionner.
- **Qui peut se connecter comme organisateur** : un compte espace dirigeant valide ne
  suffit pas — il faut porter un rôle **SPORTIF** (« Gestionnaire Sportif » ou
  « Administrateur Sportif ») sur au moins une structure (club, CD, CR, fédération).
  « Consultant Club », « Gestionnaire Club », « Administrateur » seul et les autres
  rôles sont refusés, avec un message nommant les rôles à demander.
- Configuration (`Modules/Custom/AUTH/config.local.json`) — défaut si absent :
  ```json
  { "sso": { "enabled": true,
             "required_role_regex": "(Gestionnaire|Administrateur)\\s+Sportif",
             "required_role_label": "« Gestionnaire Sportif » ou « Administrateur Sportif »" } }
  ```
  Pour élargir (p. ex. admettre aussi les administrateurs de structure sans
  qualificatif sportif) : ajouter `|Administrateur` au motif et ajuster le label,
  qui n'est qu'un texte d'affichage dans le message de refus.
- **En cas de souci de connexion SSO un jour** (la FFTA change sa page de
  login/MFA) : activer la trace en créant le fichier vide
  `Modules/Custom/AUTH/ffta-debug.on`, reproduire l'erreur, lire
  `Modules/Custom/AUTH/ffta-debug.log` (URLs, codes HTTP, type de page, noms de
  champs — **jamais** de mot de passe ni de code), puis **supprimer
  `ffta-debug.on`**. Désactivé par défaut. C'est ce qui a permis de câbler la
  MFA à deux étapes (Laravel Fortify) ; garder ce mécanisme.

## 11 bis. Espace compétiteur (inscriptions en ligne)

Le module inclut désormais le sous-module **inscriptions en ligne + boutique**
(`Modules/Custom/AUTH/booking/`) : les **licenciés eux-mêmes** ouvrent un compte,
consultent le calendrier des compétitions ouvertes et s'inscrivent en ligne.

- **Troisième espace FFTA** : la connexion compétiteur relaie les identifiants vers
  **`monespace.ffta.fr`** (Espace Licencié) — distinct de `dirigeant.ffta.fr` (§ 11)
  et de `extranet.ffta.fr`. Même technique de **relais de crédentiels** : le mot de
  passe transite en HTTPS, **jamais stocké ni journalisé** ; le compte licencié n'a
  pas de mot de passe local (sentinelle SSO). La licence rattachée est **lue sur la
  page servie après connexion** (déclarée par la FFTA), jamais depuis un champ de
  formulaire — refus si elle est incertaine.
- **Page de connexion par défaut = compétiteur** (la grande majorité des visiteurs
  sont des licenciés) ; l'onglet organisateur reste accessible (`?p=org`).
- **Surface publique maîtrisée** : les pages `booking/public/` posent `$SKIP_AUTH`
  avant `config.php` (mécanisme natif du cœur) → un licencié anonyme les atteint
  **même quand AUTH est actif**, sans liste blanche à maintenir. **Contrepartie** :
  ces pages n'ont **aucune ACL du cœur** ; chaque lecture/écriture est gardée
  explicitement (`bk_current_archer()` + CSRF sur tout POST), bornée au licencié
  connecté. Sessions à jetons hachés en base (`BookingSessions`, comme AUTH).
- **Anti-bourrage** : la connexion compétiteur relaie vers la FFTA → **8 échecs /
  15 min par IP ou licence** avant tout appel sortant (`bk_too_many`), pour ne pas
  devenir un relais de brute-force contre la fédération.
- **Données** : comptes licenciés (`BookingArchers`), inscriptions (traçage
  `BookingRegistrations` + Entries du cœur), paiements suivis (`BookingPayments`), boutique.
  Mêmes compensations que § 7 (WAF, TLS, journal, sauvegardes, purge).
- **Trace de débogage SSO** identique au § 11 : fichier vide
  `booking/ffta-debug.on` → `booking/ffta-debug.log` (jamais de mot de passe), à
  retirer après usage.
- **Liste d'attente** : quand un départ est complet pour son profil (arme, catégorie, blason), il
  reste sélectionnable dans le formulaire d'inscription, qui inscrit alors l'archer sur la liste
  (avec ses souhaits et son moyen de paiement) ; dès qu'une place se libère, le premier compatible est
  **inscrit automatiquement** (toutes les règles d'une inscription s'appliquent, validation
  manuelle comprise) et prévenu sur le site. Les pages d'inscription en ligne servent la liste
  dès qu'une place se libère chez elles ; pour les places libérées dans les écrans de ianseo
  (participant supprimé, cibles ajoutées), une tâche planifiée passe toutes les 10 minutes :
  ```bash
  sudo install -m 0644 -o root -g root /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-waitlist /etc/cron.d/
  ```
  Les listes sont supprimées au lendemain de la compétition.

## 12. Synchro licences par cron

La base licenciés est maintenue par le serveur, pas par les organisateurs :

1. Créer un **compte de service** dédié sur l'espace dirigeant (droits
   minimaux : accès au téléchargement ianseo ; **sans MFA**, sinon le cron ne
   peut pas s'authentifier — à défaut, MFA sur IP de confiance si disponible).
2. Renseigner `Modules/Custom/AUTH/config.local.json` (chmod **600**,
   propriétaire www-data) :
   ```json
   { "licsync": { "username": "svc-ianseo", "password": "…", "otp": "" } }
   ```
3. Crontab :
   ```
   15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-licences.php >> /var/log/ianseo-licsync.log 2>&1
   ```
   (Windows : Planificateur de tâches → `php.exe …\cron\sync-licences.php`.)
4. Le script télécharge `parametres_ianseo.ffta`, importe dans
   `LookUpEntries`, met à jour les statuts d'inscription des compétitions en
   cours/à venir, et trace `LICSYNC_OK/FAIL` dans le journal du module.
   Vérifier le log après la première nuit.

`config.local.json` n'est jamais synchronisé par les mises à jour du module
(hors manifeste) : les secrets restent locaux au serveur.

### Tout en un : la fenêtre de maintenance nocturne

`cron/maintenance.php` enchaîne toutes les opérations, chacune après la précédente,
et **remplace à lui seul** les lignes crontab des synchros :

```
15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php >> /var/log/ianseo-maintenance.log 2>&1
```

Déroulé : maintenance ON → déverrouillage → **MàJ du cœur ianseo** → **MàJ des modules
Custom** → redéploiement AUTH → **synchro licences** → **synchro logos** →
verrouillage → maintenance OFF.

- **La maintenance est toujours coupée**, même si une étape échoue, si le script est
  interrompu ou s'il meurt sur une erreur fatale (gestionnaire de fin de script posé
  avant toute action + signaux SIGINT/SIGTERM/SIGHUP). Sans cela, le serveur resterait
  bloqué en page 503. *(Un `kill -9` reste hors de portée : dans ce cas, lancer
  `ianseo-maintenance-off` à la main.)*
- Chaque étape est **indépendante** : l'échec de l'une n'empêche ni les suivantes ni la
  sortie de maintenance. Bilan en fin de log, code de sortie 1 et `MAINT_PARTIAL` au
  journal si quelque chose a échoué.
- Les commandes système sont **configurables**, et une commande vide fait simplement
  sauter l'étape (le script est donc inoffensif hors serveur de production) :

```json
{ "maintenance": {
    "on":     "sudo /usr/local/bin/ianseo-maintenance-on",
    "off":    "sudo /usr/local/bin/ianseo-maintenance-off",
    "unlock": "",
    "lock":   "",
    "steps":  { "core": false, "modules": true, "licences": true, "logos": true },
    "notice": { "at": "03:15", "lead_minutes": 15 }
} }
```

- **`notice` — avertissement préalable aux utilisateurs.** Pendant les
  `lead_minutes` qui précèdent l'heure `at`, un bandeau permanent s'affiche sur
  **toutes** les pages (espace organisateur *et* espace licencié) : « Maintenance
  programmée à 03:15 (dans N minutes) — le serveur sera indisponible quelques
  minutes. Terminez et enregistrez votre saisie. » Le décompte se met à jour à
  chaque page. Personne ne se retrouve devant une 503 sans prévenir.
  Sans la clé `at`, **aucun message** n'est affiché (silencieux par défaut).
  ⚠️ `at` doit correspondre à l'heure réelle du cron : les deux ne se déduisent
  pas l'un de l'autre. Heure locale (`timezone`, défaut `Europe/Paris`) — ianseo
  forçant PHP en UTC, un réglage naïf annoncerait l'horaire avec 2 h d'écart.

  `www-data` doit pouvoir lancer `on`/`off` sans mot de passe (`sudoers`, `NOPASSWD`,
  limité à ces deux chemins).

  **`unlock`/`lock` restent VIDES** : le déverrouillage des fichiers du cœur est fait par
  **root, autour du script**, dans la ligne cron elle-même (`serveur/cron/ianseo-nightly`) :
  `ianseo-unlock && su www-data -c maintenance.php ; ianseo-lock`. Le compte web ne reçoit
  ainsi jamais le droit de rendre le code d'ianseo modifiable — lui donner `ianseo-unlock`
  en sudo annulerait la protection apportée par `ianseo-lock` (une faille du site pourrait
  réécrire le cœur). Le `;` garantit le reverrouillage même si la maintenance échoue.
  *(Incident réel du 2026-09-27 : `unlock` renseigné sans `sudo` ni chemin → « not found »,
  fichiers restés verrouillés, MàJ du cœur refusée. Depuis, un déverrouillage en échec fait
  sauter la MàJ du cœur au lieu de la tenter pour rien.)*
- Options : `--dry-run` (affiche le plan sans rien faire — à lancer en premier),
  `--core` / `--no-core`, `--only=modules,licences,logos`.

#### Mise à jour du cœur ianseo, sans navigateur

`cron/update-core.php` fait ce que fait `/Update/` : cette page n'est qu'une interface
AJAX, le travail réel vit dans `Update/UpdateIanseo.php`, **qui ne porte aucun contrôle
d'accès** (l'ACL est dans `index-action.php`). Il est donc exécutable en CLI — ce qui
**lève le blocage** de l'automatisation : passer par HTTP aurait imposé de scripter une
connexion ADMIN + code TOTP, donc de stocker le secret 2FA en clair. Les migrations de
base sont appliquées au passage.

> ⚠️ **Désactivée par défaut** (`steps.core: false`). Elle réécrit des fichiers du cœur
> et migre la base **sans retour arrière possible**. Ne l'activer qu'avec une sauvegarde
> automatique de la base et des fichiers (§ 9 bis — sans sauvegarde valide la nuit, elle
> est sautée), et après un essai en `--dry-run`. Elle exige que les fichiers soient
> déverrouillés pendant la fenêtre : c'est le rôle de la ligne cron lancée par root.

> ⚠️ **Piège vérifié** : un **BOM UTF-8** en tête de `config.local.json` (Bloc-notes,
> `Set-Content -Encoding utf8`…) faisait échouer la lecture JSON et **toute** la
> configuration était ignorée en silence — avec un message trompeur « identifiants
> absents ». Le BOM est désormais retiré automatiquement, mais mieux vaut enregistrer ce
> fichier en **UTF-8 sans BOM**.

### Logos de club (drapeaux) — second cron, sans compte de service

Sans cela, chaque organisateur doit passer par « Participants › Charger table de
correspondance » et cocher **Drapeaux** pour SA compétition, sinon ses impressions
(dossards, badges, listes) sortent sans logo. Le cron ci-dessous supprime cette
étape pour tout le monde.

```
15 4 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-logos.php >> /var/log/ianseo-logosync.log 2>&1
```

- **Aucun identifiant requis** : l'endpoint FFTA des logos est public.
  À placer **après** la synchro des licences (elle fournit la liste des clubs).
- Deux étapes : téléchargement dans le cache global `AuthClubLogos` (~1600 clubs,
  ~7 min, une connexion réutilisée), puis **propagation locale** vers toutes les
  compétitions non terminées (table `Flags` + fichiers `TV/Photos/`). Une panne
  réseau n'empêche pas la propagation de ce qui est déjà en cache.
- Volume : compter ~50 Mo pour le cache, plus les fichiers par compétition.
- Options utiles : `--propagate-only` (aucun réseau), `--full` (tout retélécharger),
  `--limit=N` (mise au point). Trace `LOGOSYNC_OK/FAIL` dans le journal du module.
- Réglages facultatifs : `{ "logos": { "enabled": true, "delay_ms": 120,
  "refresh_days": 0, "timeout": 15 } }`. L'URL est reprise de ianseo
  (`LookUpPaths.LupFlagsPath`) — rien à saisir.
- **Logo modifié par un club** : l'endpoint FFTA ne renvoie ni `Last-Modified` ni `ETag`,
  donc aucune requête conditionnelle n'est possible — il faut télécharger pour comparer.
  D'où `refresh_days: 0` par défaut : **tout est retéléchargé chaque nuit**, mais un logo
  n'est réécrit que s'il a *vraiment* changé (comparaison d'empreinte md5, en cache puis
  sur le fichier posé). Un changement se répercute donc tout seul sur les compétitions non
  terminées. ⚠️ Ne **pas** régler `refresh_days: 1` avec un cron quotidien : un club repris
  quelques minutes après le début de la passe précédente serait jugé « frais » et sauté une
  nuit sur deux. Utiliser `0` (défaut), ou `2` et plus si l'on veut économiser la bande
  passante en acceptant un délai de détection.
- Le logo est posé **immédiatement**, sans attendre le cron, dans les deux cas :
  inscription **en ligne** (à la confirmation) et saisie **manuelle** par l'organisateur
  (`Partecipants/PopEdit.php`). Dans les deux cas c'est une simple copie locale depuis le
  cache — aucun accès réseau. Un club encore absent du cache (club tout neuf) est rattrapé
  au passage suivant du cron, qui balaie aussi les clubs des compétitions.

## 13. Opérations à l'échelle du serveur (mise à jour / réparation)

Certaines opérations d'ianseo agissent sur **toute la base**, pas sur une
seule compétition :

- **Mise à jour de la base** (`/Update/`, menu Modules → Update) : peut
  exécuter des migrations `ALTER TABLE`. Réservée à l'administrateur (garde du
  cœur + garde du module + Apache localhost). **À faire dans une fenêtre de
  maintenance** (aucune compétition en cours) : une migration pendant que des
  arbitres saisissent des scores peut verrouiller des tables et provoquer des
  erreurs pour tout le monde. Prévenir, sauvegarder avant.
- **Réparation des tables** (`Modules/Help/RepairTables.php`, `RepairXAMPP.php`) :
  `REPAIR`/`OPTIMIZE TABLE` sur toutes les tables, ou redémarrage de MySQL.
  Le cœur ianseo **ne vérifie AUCUN droit** sur ces pages ; le module AUTH les
  bloque désormais pour les non-admins (garde centrale du bootstrap), et le
  vhost les restreint à localhost. Ne les lancer **jamais** en pleine
  compétition (verrouillage de tables → interruptions).

Règle générale : ces actions sont **globales et réservées à l'admin**, à
programmer hors compétition. Le module empêche un organisateur de les
déclencher, mais rien ne remplace une fenêtre de maintenance annoncée.

## 14. Points d'attention restants

- **Résultats publics** : tout est derrière le login par défaut. Pour le
  public : publication ianseo.net (menu Compétition), ou whitelist ciblée via
  `config.local.json` → `"public_paths"` (ex. `"/TV/"`). Chaque chemin ouvert
  = surface d'attaque en plus : n'ouvrir que le strict nécessaire.
- **ISK / tablettes de marque** : `Api/` est bloqué pour les anonymes ;
  **le scoring se fera principalement SUR le serveur** (ISK lite, en croissance)
  → whitelister `/Api/ISK-NG/` (`config.local.json` → `public_paths`). L'API a ses
  propres codes d'appairage (handshake/confirmhash), mais elle devient une surface
  exposée : **régler ModSecurity** pour ne pas casser les POST de scores (mettre
  `/Api/` en exclusion ciblée après analyse), et **dimensionner en conséquence** (le
  scoring en ligne est le principal poste de charge — voir la ligne « Charge » ci-dessous).
  ⚠️ **Modes ISK pro / live INTERDITS sur un serveur en ligne** : ils déclenchent
  côté ianseo un mécanisme qui **révoque la licence** du serveur. Quand le module
  AUTH est actif, seuls « aucun ISK » et **ISK-NG lite** sont proposés (menu
  déroulant filtré sur la page compétition **et** sur SYNCHRO_FFTA), et toute
  compétition enregistrée/importée en pro/live est **rebasculée en lite** à son
  ouverture (`aut_isk_enforce`, journalisé `ISK_DOWNGRADE`).
- **ACL par IP ianseo** : désactivées en remote par le module — seuls les
  comptes font foi.
- **Canaux/règles TV** : l'édition est cloisonnée (une règle TV est liée à sa
  compétition, écriture protégée par `TVRTournament` + ACL de la compétition).
  Réserve connue : la page `TV/ChannelSetup.php` (cœur ianseo) n'affiche que
  les règles des compétitions de l'utilisateur, **sauf** pour un compte sans
  aucune compétition accessible (filtre vide → liste toutes les règles). Fuite
  mineure (codes/noms de compétitions et de règles TV, pas de scores) qui
  concerne surtout un compte neuf. À surveiller ; corrigible côté cœur si
  gênant (ajouter une condition `FALSE` quand `AUTH_COMP` est vide, comme le
  fait la liste d'accueil).
## 15. Dimensionnement (charge)

Chiffres FFTA 2025 : **243 500 scores remontés**, **2 307 compétitions**, **28 868
compétiteurs uniques**. Pic : **85 compétitions simultanées** (~9 900 compétiteurs) un
week-end, régulièrement **65** (~6 500). S'y ajoutent des événements **loisir** non comptés.
Modèle retenu : **scoring principalement sur le serveur** (ISK lite, en croissance).

**La taille de la base n'est PAS la contrainte.** `LookUpEntries` (80 000 licenciés) ≈ 10–15 Mo ;
une compétition ≈ 1–3 Mo. Une année entière tient dans **~5–15 Go** ; avec la purge à ~3 mois
(§ 7.1), **~1–3 Go actifs**. Le facteur limitant est le **CPU PHP + le débit MySQL** pendant les pics.

**Estimation du pic** (85 compét. en ligne) : ~2 500–3 300 tablettes ISK (≈ 1 par cible/peloton),
chacune postant une volée toutes les ~3–5 min + interrogeant les mises à jour → **~150 req/s**
soutenus rien que pour le scoring, **+50–150 req/s** de consultation de résultats → **pic agrégé
~200–300 req/s**, en hausse avec l'essor d'ISK lite.

| Profil | vCPU | RAM | Disque | Couvre |
|---|---|---|---|---|
| Minimum | 4 | 8 Go | 80 Go SSD | Inscriptions + résultats, scoring surtout local |
| **Recommandé (plancher ici)** | **8** | **16 Go** | **160 Go NVMe** | Le pic avec **scoring en ligne**, WAF actif |
| Croissance / loisir | 16 | 32 Go | 160 Go+ | Marge ISK lite + événements loisir |

Cloud **élastique** conseillé (OVH/Scaleway/Hetzner) : redimensionner à la hausse pour les grands
week-ends, revenir ensuite (~30–60 €/mois pour le profil recommandé).

Leviers (comptent plus que la taille de VM) :
1. **PHP-FPM + OPcache** (jamais mod_php prefork) — ×3–5 sur le débit PHP d'ianseo. Non négociable.
2. **`innodb_buffer_pool_size` = 8 Go** : tout le jeu actif tient en RAM. Et **`max_connections`
   ≥ 2 × workers PHP + marge** : ianseo ouvre deux connexions par page (§ 6.3) — avec 100
   workers FPM, 220 au moins ; la valeur par défaut (151) ferait refuser des pages au pic.
3. **Cache des pages de résultats publiques** (Apache mod_cache/Varnish, TTL court) : absorbe le
   pic de spectateurs des finales — souvent LE pic.
4. **Workers FPM (50–100)** : ⚠️ le login compétiteur **relaie de façon SYNCHRONE** vers
   `monespace.ffta.fr` (~1–2 s bloquants/login) → une rafale d'inscriptions immobilise des workers.
5. **ModSecurity** : compter **+20–30 % de CPU** (intégré au profil recommandé) ; exclusions ciblées
   sur `/Api/` pour ne pas casser le scoring ISK.
6. **Chemin de montée en charge** si dépassement : séparer **MySQL sur sa propre VM** (web/DB split),
   puis répliques de lecture pour les résultats, puis CDN devant les pages publiques.
7. **Un seul processeur ne suffit pas**, même pour 3 opérateurs : sur l'autre serveur (1 vCPU),
   une seule requête lente (§ 6.3) saturait le processeur et ralentissait tout le monde, y compris
   la saisie des scores sur téléphone. Et tout limiteur de débit placé devant doit laisser passer
   les rafales d'ISK-NG (§ 4.4).

## 16. Autour d'une compétition — routine d'exploitation

Tirée des week-ends de saisie en ligne de l'autre serveur. Commandes à lancer sur le serveur.

**Avant**
- Départs dimensionnés au besoin réel (État du serveur : « Départs surdimensionnés ») ; les
  opérateurs choisissent le **départ** d'un archer avant sa cible.
- Saisie ISK-NG essayée avec **plusieurs téléphones sur le même wifi** (même adresse publique :
  c'est ce qui déclenche les limiteurs de débit, § 4.4).
- Sauvegardes de la nuit et copies à chaud présentes (État du serveur, ou `sudo ianseo-restore`),
  aucun bandeau rouge chez l'administrateur ; certificat valide
  (`sudo certbot certificates`).
- Mises à jour du système bien programmées la nuit (`systemctl list-timers 'apt-daily*'`).

**Pendant**
- Charge : `top` (qui consomme — `mysqld`, `apache2`/`php-fpm`) ;
- Requêtes en cours, les plus longues d'abord :
  `sudo mysql -e "SELECT ID, TIME, LEFT(INFO, 80) FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' ORDER BY TIME DESC"` ;
- Erreurs : `sudo tail -f /var/log/apache2/error.log` ; blocages : `sudo fail2ban-client status ianseo-auth`
  (et, si mod_evasive est installé, `ls -lt /var/log/mod_evasive | head`).

**Après**
- Requêtes lentes : `sudo mysqldumpslow -s t /var/lib/mysql/*-slow.log | grep "^Count" | head` ;
- Secondes les plus chargées :
  `sudo zcat -f /var/log/apache2/access.log* | awk '{print $4}' | sort | uniq -c | sort -rn | head` ;
- Journal du module : Multi-comptes › Utilisateurs (pics de `LOGIN_FAIL`).
