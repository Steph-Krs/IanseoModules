# Serveur ianseo partagé multi-comptes — Installation & sécurité

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
- `unattended-upgrades` activé (MaJ sécurité automatiques).
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

## 5. Apache — vhost durci

```apache
<VirtualHost *:80>
    ServerName ianseo.ffta.fr
    Redirect permanent / https://ianseo.ffta.fr/
</VirtualHost>

<VirtualHost *:443>
    ServerName ianseo.ffta.fr
    DocumentRoot /var/www/ianseo

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/ianseo.ffta.fr/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/ianseo.ffta.fr/privkey.pem
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
Certificat : `certbot --apache -d ianseo.ffta.fr` (renouvellement auto).
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
session.gc_maxlifetime  = 28800
allow_url_include = Off
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
open_basedir = /var/www/ianseo:/tmp
upload_max_filesize = 64M
post_max_size = 64M
```
> Tester après coup : l'export/import ianseo et les impressions PDF doivent
> fonctionner (TCPDF n'a pas besoin des fonctions désactivées).

### 6.2 MySQL
- `bind-address = 127.0.0.1` (jamais exposé).
- Utilisateur dédié `ianseo` limité à la base `ianseo`
  (`GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP ON ianseo.* …`),
  **pas** de `FILE`, `SUPER`, `GRANT`. Mot de passe long généré.
- Pas de phpMyAdmin accessible depuis Internet (voir vhost).
- `Common/config.inc.php` (identifiants DB) : permissions `640 root:www-data`.

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
```bash
# /etc/cron.daily/ianseo-backup
#!/bin/sh
set -e
F=/var/backups/ianseo-$(date +%F).sql.gz.gpg
mysqldump --single-transaction ianseo | gzip \
  | gpg --batch --yes -r backup@ffta.fr -e -o "$F"
find /var/backups -name 'ianseo-*.gpg' -mtime +30 -delete
# copie hors serveur (stockage FFTA) :
rsync -a /var/backups/ backup@stockage.ffta.fr:/backups/ianseo/
```
+ `Common/config.inc.php`, `Modules/Custom/`, `TourData/`.
**Tester une restauration** au moins une fois avant l'ouverture, puis
périodiquement. La clé GPG privée n'est PAS sur le serveur.

### 7.3 Conformité (à traiter avec le DPO FFTA)
- Inscrire le traitement au **registre** (finalité : gestion sportive des
  compétitions ; base légale : intérêt légitime / relation contractuelle).
- **Information des personnes** : mention dans les documents d'inscription ;
  les résultats nominatifs publiés sont un usage sportif standard mais doivent
  figurer dans la mention.
- Durées de conservation alignées sur la purge (§ 7.1).
- **Violation de données** : procédure de notification CNIL sous 72 h —
  prévoir le contact et la marche à suivre AVANT l'incident.
- Sous-traitance hébergeur (OVH…) : vérifier le DPA.

## 8. Installation pas à pas

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
- **Surveiller** : le journal AUT_Log (pics de LOGIN_FAIL), fail2ban
  (`fail2ban-client status ianseo-auth`), l'espace disque, les MaJ ianseo.
- **Mise à jour ianseo** : menu Update (réservé ADMIN). La MaJ remet `htdocs/` à
  zéro et efface `Modules/Authentication/`, mais **un filet auto-répare** : le bloc
  `AUTH-SELFHEAL` de `config.inc.php` (posé à l'activation / à chaque déploiement, et
  préservé aux MaJ) recopie `dist/` → `Modules/Authentication/` dès la 1re requête.
  **Plus de redéploiement manuel après une MaJ** — à condition que le serveur web
  puisse écrire dans `Modules/`. `config.inc.php` et `Modules/Custom/` survivent aux MaJ.
  (Un install existant sans le filet le reçoit au prochain « Déployer ».)
- **Mise à jour du module** : menu Multi-comptes → Mise à jour module, puis
  redéployer si `dist/` a changé.

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
- Configuration (`Modules/Custom/AUTH/config.local.json`) :
  ```json
  { "sso": { "enabled": true, "required_role_regex": "Gestionnaire|Administrateur" } }
  ```
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
  connecté. Sessions à jetons hachés en base (`BK_Sessions`, comme AUTH).
- **Anti-bourrage** : la connexion compétiteur relaie vers la FFTA → **8 échecs /
  15 min par IP ou licence** avant tout appel sortant (`bk_too_many`), pour ne pas
  devenir un relais de brute-force contre la fédération.
- **Données** : comptes licenciés (`BK_Archers`), inscriptions (traçage
  `BK_Registrations` + Entries du cœur), paiements suivis (`BK_Payments`), boutique.
  Mêmes compensations que § 7 (WAF, TLS, journal, sauvegardes, purge).
- **Trace de débogage SSO** identique au § 11 : fichier vide
  `booking/ffta-debug.on` → `booking/ffta-debug.log` (jamais de mot de passe), à
  retirer après usage.

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
2. **`innodb_buffer_pool_size` = 8 Go** : tout le jeu actif tient en RAM.
3. **Cache des pages de résultats publiques** (Apache mod_cache/Varnish, TTL court) : absorbe le
   pic de spectateurs des finales — souvent LE pic.
4. **Workers FPM (50–100)** : ⚠️ le login compétiteur **relaie de façon SYNCHRONE** vers
   `monespace.ffta.fr` (~1–2 s bloquants/login) → une rafale d'inscriptions immobilise des workers.
5. **ModSecurity** : compter **+20–30 % de CPU** (intégré au profil recommandé) ; exclusions ciblées
   sur `/Api/` pour ne pas casser le scoring ISK.
6. **Chemin de montée en charge** si dépassement : séparer **MySQL sur sa propre VM** (web/DB split),
   puis répliques de lecture pour les résultats, puis CDN devant les pages publiques.
