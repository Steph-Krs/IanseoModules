# Shared multi-account ianseo server — Installation & security

French version: [SERVEUR_FR.md](SERVEUR_FR.md) (the original).

Guide to setting up an online multi-account ianseo server
(module `Modules/Custom/AUTH`), with a stated goal: **the security of
personal data comes before everything else** (licensee data,
shared server exposed on the Internet).

---

## 1. Apache or ianseo? → Both, in depth

| Layer | Role |
|---|---|
| **System** (dedicated VM, firewall, SSH, automatic updates) | reduce the attack surface |
| **Apache** (HTTPS, ModSecurity, fail2ban, blocking) | filter before reaching PHP |
| **ianseo + AUTH module** | accounts, 2FA, club/CD/CR/FFTA partitioning, sharing |
| **Data** (minimisation, purge, encrypted backups) | limit the impact of a compromise |

htdigest alone does not make instances (once it is passed, ianseo shows everything
to everybody). The ianseo core already contains the multi-account hooks
(`$CFG->USERAUTH` + `Modules/Authentication/`); the `Custom/AUTH` module
provides the implementation — no core file modified, a single
installation, a single database.

> ⚠️ **To know before anything else — the sign-in is a CREDENTIAL RELAY, not an SSO/OIDC.**
> Lacking an official SSO (OpenID Connect) provided by the federation, each user's identifier and
> **password** **pass through this server** at sign-in to be
> checked against the online spaces (officers' / licensee). The password is **never
> stored nor logged**, but it goes through the **server's memory** for the time of the request.
> **Consequence: as long as a real SSO is not in place, the security of the users' accounts
> depends directly on the security AND the reliability of this server** (and of its operator). The
> whole hardening guide (§§ 4-6) follows from it, and the users are warned on the sign-in
> page. Ask the provider of the spaces, in time, for a real **OIDC** (the module will be able to switch).

## 2. Threat model — being honest

**ianseo is designed to run locally during a competition**, not as a
multi-tenant web application exposed to the Internet. The core code is
old, with a history of vulnerabilities fixed as they came
(SQL injections, uploads). Putting ianseo online = accepting this risk and
**compensating for it with external layers**. Practical consequences:

1. **Never expose ianseo "bare"**: WAF (ModSecurity) + the module's authentication
   from day one + fail2ban.
2. **Consider every signed-in user as semi-hostile**: a compromised club
   account (phishing) gives access to the core's functions. The module
   partitions the competitions, but the core remains the core → hence the
   minimisation of the data present on the server (§ 7).
3. **Minimise what can be lost**: the server must only hold the data
   needed for current/recent competitions — not the database of the
   80,000 licensees if avoidable (§ 7.1).
4. **Ability to detect and restore**: logs, alerts,
   tested backups. "100 % armoured" does not exist; detecting fast and
   restoring fast does.
5. **ianseo updates as soon as published** (they regularly fix
   flaws) + watching the ianseo announcements.
6. Before the public opening: **external audit/pentest** (the FFTA handles
   the data of 80,000 people, the investment is proportionate), and
   declare the processing to the DPO (§ 7.3).

## 3. What the AUTH module brings (application layer)

- **Officers' space SSO** (§ 11): organisers use their
  dirigeant.ffta.fr credentials, accounts are provisioned automatically with
  the right role (club/CD/CR/FFTA) — no password management on the ianseo side.
- Local accounts (ADMIN…): bcrypt, single-use temporary password,
  forced change at first sign-in, 10+ character policy.
- **TOTP 2FA mandatory for ADMIN accounts** (Google/Microsoft
  Authenticator, FreeOTP…), optional for the others (recommended for CD/CR/FED).
- **Sessions with revocable tokens**: nothing replayable in the PHP session,
  expiry after 12 h of inactivity / 7 days, remote sign-out by an admin,
  automatic revocation on password change/reset.
- Anti-brute-force: 8 failures / 15 min (per IP and per identifier), constant-time
  response (anti identifier enumeration).
- Partitioning by competition (agrément prefix) + opt-in CD/CR/FFTA sharing.
- Anonymous: only the sign-in page (+ empty home page). Everything else
  redirects to the login. **Fail-closed**: if the auth files are missing
  while USERAUTH is on, the site fails with an error, it does not open up.
- Complete log (sign-ins, failures, admin actions) in the DB + optional file
  for fail2ban.
- **Operations**: nightly backup + hot copies of the database, encrypted online copy,
  guided restore (`ianseo-restore`), alerts to the administrator and a "Server state" page
  (§§ 9, 9 bis, 16).

## 4. System hardening (Debian/Ubuntu)

### 4.1 Basics
- **Recommended OS: Debian stable** (currently 12 "Bookworm") — or Ubuntu Server
  LTS. Reasons: long support, reliable security fixes, `unattended-upgrades`,
  and the native Apache + PHP + MariaDB/MySQL + ModSecurity + fail2ban stack (this whole guide
  assumes this apt ecosystem). Avoid Windows/XAMPP in production (larger surface,
  more laborious hardening) — XAMPP remains perfect for a **development** machine.
- **Dedicated VM** for ianseo, nothing else on it (no sharing).
- **Synchronised clock (NTP) — MANDATORY, not optional.** The 2FA of the
  administrator accounts is a TOTP (time-based code, ±30 s window): if the server's
  clock drifts by more than ~30 s from the real time, **no valid code
  gets through** and the admin is locked out — while the FFTA sign-in (password) succeeds,
  which makes the symptom confusing. Session expiries are also computed on the
  server clock. On Debian/Ubuntu, `systemd-timesyncd` (or `chrony`) is active by
  default: check `timedatectl` (`System clock synchronized: yes`). ⚠️ On a
  **Windows/XAMPP** dev machine, the clock is often on "Local CMOS Clock" with no sync and drifts
  by several tens of minutes → enable "Set time automatically", or in an
  **administrator** PowerShell: `w32tm /resync /force` (after `net start w32time`).
  Since v1.0.x, a TOTP code refused although it is correct is diagnosed
  explicitly ("clock off by ~N min") and does not increment the anti-bruteforce
  (event `TOTP_SKEW`).
- Firewall: `ufw default deny incoming ; ufw allow 80,443/tcp ; ufw allow from <FFTA_admin_IP> to any port 22 ; ufw enable`
- SSH: keys only (`PasswordAuthentication no`), no direct root,
  if possible restricted to the FFTA IPs or behind a VPN.
- `unattended-upgrades` enabled (automatic security updates) — **but at a fixed time, at night, and
  outside the maintenance window**. By default, Debian/Ubuntu install around 6 am with
  a random delay of one hour, and the installation restarts the database and Apache on the way: on
  another ianseo server, it happened **three times in the middle of a competition** in six months
  (Saturday mornings). Templates `serveur/apt/`: download at 04:00, installation at **04:30**,
  automatic reboot at 04:45 **only** if an update requires it (optional) — after the
  03:15 maintenance (§ 12), never during it: a database restart in the middle of a backup would
  make it fail, and the core update would be skipped that night. Check:
  `systemctl list-timers 'apt-daily*'`. If you move one of the times, move the other.
- Dedicated application user (www-data), ianseo files as `root:www-data`,
  write access limited to the folders that need it (`TourData/`, `Common/` for
  config.inc.php at activation, `Modules/`).

### 4.2 fail2ban
Default SSH jail + a jail dedicated to ianseo sign-in failures.
Enable the module's log file: create
`Modules/Custom/AUTH/config.local.json`:
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
(the application rate limit blocks at 8; fail2ban bans the IP at network level
beyond that — the two complement each other.)
`touch /var/log/ianseo-auth.log && chown www-data /var/log/ianseo-auth.log`
+ logrotate rotation.

### 4.3 ModSecurity (WAF)
```bash
apt install libapache2-mod-security2
cp /etc/modsecurity/modsecurity.conf-recommended /etc/modsecurity/modsecurity.conf
# SecRuleEngine On
apt install modsecurity-crs   # OWASP Core Rule Set
```
Start in `DetectionOnly` for a week, analyse the false positives (ianseo
posts a lot of HTML/raw values), create the necessary exclusions, then
switch to `On`. It is the main compensation for the "core code" risk.

⚠️ **Before switching to `On`**: `SecRequestBodyLimit` is **12.5 MB** in the file as shipped,
whereas PHP accepts 64 MB (§ 6.1). In blocking mode, importing a heavier competition would be refused
(error 413) with nothing in ianseo to explain it. Align it with PHP in
`/etc/modsecurity/modsecurity.conf`: `SecRequestBodyLimit 67108864`.

### 4.4 Rate limiters (mod_evasive, proxy, application firewall)
This guide does not install any: fail2ban (§ 4.2) and the module's anti-stuffing target **sign-in
failures**, not the request volume. If you add one, it must let through the
**bursts of ISK-NG scoring**: each phone sends one `OPTIONS` and about **8 `POST`**
to `/Api/ISK-NG/index.php` **within the same second**, and all the phones of a club go out
through the same address. Experienced on another ianseo server: mod_evasive set to 5 requests per
second and per address blocked **144 addresses** in 18 months, almost all of them scoring
tablets during competitions. Settings that worked (`/etc/apache2/mods-available/evasive.conf`):

```apache
DOSPageCount      30
DOSSiteCount      150
DOSBlockingPeriod 10
DOSWhitelist      127.0.0.1
DOSWhitelist      192.168.*.*
# and do not leave DOSSystemCommand on the documentation's example
```

A misleading symptom to know about: behind an HTTP authentication (htdigest), the refusal
shows as **401** (password prompt) and not 403 — looking for 403s in the
logs finds nothing.

## 5. Apache — hardened vhost

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
    # modern TLS (Mozilla "intermediate")
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off

    ServerSignature Off

    <Directory /var/www/ianseo>
        Options -Indexes -Includes -ExecCGI
        AllowOverride All
        Require all granted
    </Directory>

    # sensitive files never served
    <FilesMatch "\.(inc\.php|json|md|bak|sql|log)$">
        Require all denied
    </FilesMatch>

    # NO PHP execution in the uploaded-files folders
    <Directory /var/www/ianseo/TourData>
        php_admin_flag engine off
        <FilesMatch "\.ph(p[0-9]?|tml|ar)$"> Require all denied </FilesMatch>
    </Directory>
    <Directory /var/www/ianseo/Images>
        php_admin_flag engine off
    </Directory>

    # installation & server tools: localhost only once in service
    <Location "/Install"> Require ip 127.0.0.1 ::1 </Location>
    <Location "/Update">  Require ip 127.0.0.1 ::1 </Location>
    # server-wide repair/maintenance scripts — the AUTH module already
    # blocks them for non-admins, but we double up at the Apache level:
    <Files "RepairXAMPP.php"> Require ip 127.0.0.1 ::1 </Files>
    <Location "/Modules/Help/RepairTables.php"> Require ip 127.0.0.1 ::1 </Location>
    # (ideally, DELETE RepairXAMPP.php from the server: an XAMPP/Windows script
    #  that restarts MySQL, pointless on Linux production.)
    # if phpMyAdmin is present on the machine: do NOT expose it
    # (delete it, or Require ip 127.0.0.1 + SSH tunnel to use it)

    Header always set Strict-Transport-Security "max-age=31536000"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</VirtualHost>
```
Certificate: `certbot --apache -d YOUR-DOMAIN` (automatic renewal).
With PHP-FPM: replace `php_admin_flag engine off` with an equivalent
`<FilesMatch \.php$> SetHandler none </FilesMatch>`.

**htdigest**: keep it during the WHOLE set-up, then either
remove it (comfort for clubs) — the module+WAF layers take over — or
keep it on `/Update` as an extra belt.

## 6. Hardened PHP & MySQL

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
> Test afterwards: ianseo export/import and PDF printing must
> work (TCPDF does not need the disabled functions).

`session.gc_maxlifetime = 43200`: **12 h**, the inactivity time the module provides for (§ 3).
PHP's default value (1440 s) erases an inactive session after **24 minutes**:
the organiser who went to lunch is signed out and loses the open competition — seen on another
server. On Debian/Ubuntu, a system task (`phpsessionclean`) erases the sessions,
according to this **php.ini** setting: an `ini_set()` in the code would change nothing. Same
for `upload_max_filesize`/`post_max_size`: the two `php.ini` files to edit are those
of Apache (`/etc/php/8.x/apache2/php.ini`) or of PHP-FPM — not the command-line one.
**Multi-account › Server configuration › Server state** shows the values actually
applied to the site.

### 6.2 MySQL
- `bind-address = 127.0.0.1` (never exposed).
- Dedicated `ianseo` user limited to the `ianseo` database
  (`GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP ON ianseo.* …`),
  **no** `FILE`, `SUPER`, `GRANT`. Long generated password.
- No phpMyAdmin reachable from the Internet (see vhost).
- `Common/config.inc.php` (DB credentials): permissions `640 root:www-data`.

### 6.3 MySQL — performance (lessons from a production server)

On another online ianseo server (September 2026), **adding an archer froze the page for 5 to
10 minutes**. The settings below come from it; template: `serveur/mysql/ianseo.cnf`.
**Multi-account › Server configuration › Server state** checks each of them.

- **MariaDB (installed in § 8.0) and MySQL 8 do not behave the same.** To check a target
  number, the core (`createAvailableTargetSQL()`) builds a query with **one `UNION` line per
  place** of the session, which it then filters. MySQL 8.0.22+ copies this filter into each branch
  (`derived_condition_pushdown` optimisation): the time becomes quadratic. Measured on a
  session of 9,999 targets × 8 = 80,000 places: **~10 min** under MySQL 8.0.46, **1.2 s** once
  the optimisation is switched off, 1.5 s under MariaDB. Meanwhile, the PHP session lock blocks
  all the other pages of the same user, and the processor is saturated for everybody.
  **Under MySQL 8 only**:
  ```bash
  sudo mysql -e "SET PERSIST optimizer_switch='derived_condition_pushdown=off';"
  ```
  Speed setting only (identical results, MySQL 5.7 behaviour), kept across
  restarts, reversible (`=on`). **Not in a shared `.cnf` file**: MariaDB does not know
  this option and would refuse to start.
- **Size the sessions to the real need**, never "9,999 targets to be safe": with the same
  setting applied, 80,000 places cost more than a second per check. The module
  flags the sessions of more than 5,000 places (to the organiser in "Online registration",
  to the administrator in "Server state"). Advice to operators: choose the archer's **session**
  BEFORE their target — without a session, the check covers all the sessions at once.
- **Connections**: ianseo opens **two** database connections per page (read + write).
  `max_connections` must therefore be at least **2 × the number of PHP processes** (Apache
  `MaxRequestWorkers` in prefork — 150 on Debian —, or `pm.max_children` in PHP-FPM), plus a
  margin for the crons. The default value (151) only covers 75 processes: beyond that, the
  database refuses the connection and the user lands on an error page.
- **Memory**: `innodb_buffer_pool_size` at least equal to the size of the database (128 MB by
  default) — § 15 for the target of a big server. And `MaxRequestWorkers` sized to the RAM:
  150 Apache processes of about 65 MB each exceeded the 8 GB of the other server.
- **Slow query log** (threshold 2 s): it is what pointed to the query at fault.
  File: `/var/lib/mysql/<server-name>-slow.log`; summary: `sudo mysqldumpslow -s t <file>`.
- **Test under the production engine.** An XAMPP development machine runs MariaDB:
  this problem was invisible there. If the server is under MySQL 8, do at least one trial
  (competition creation, adding archers, scoring) on a MySQL 8.

## 7. Personal data & GDPR

### 7.1 The licensee database on the server — an accepted, compensated choice
FFTA decision: the licensee matching database (`LookUpEntries`) **stays
on the server** so that organisers can add/modify
online registrations, and it is fed by a **cron with a service account**
(§ 12) — no more manual sync by the organisers, no more
licence file wandering on the clubs' PCs (that is a gain too).

Consequence to accept: this table (~80,000 names + dates of birth +
licence no. + club) can be consulted by **any signed-in account** (it is the
ianseo registration search function). A single phished club account
gives access to it. Mandatory compensations:
- officers' space SSO (§ 11): no weak passwords on the clubs' side, the
  accounts follow the life of the FFTA access rights (removal of the Gestionnaire role = no more
  access at the next sign-in);
- WAF + rate limit + monitored log (LOGIN_FAIL peaks, abnormal volumes);
- purge of finished competitions: archive (encrypted .ianseo export) +
  deletion from the server ~3 months after the competition;
- only ask the clubs for the fields needed for registrations.

### 7.2 Backups — encrypted and off the server
Built into the module (§ 9 bis): every night the database and the files, during the day "hot"
copies of the database, and an **encrypted** online copy (rclone `crypt`). Principles, which hold
whatever the tool:
- **from day one** — the other online ianseo server ran for months without any backup;
- **encrypted off the server**, and the secrets that allow them to be read again (`crypt`
  passwords) kept **somewhere other than** the server: it is precisely the day it is lost that
  they are needed;
- **at least one restore test** before opening, then periodically — without touching the
  site: `sudo ianseo-restore --test <copy>` (§ 9 bis).

### 7.3 Compliance (to be handled with the FFTA DPO)
- Enter the processing in the **register** (purpose: sports management of
  competitions; legal basis: legitimate interest / contractual relationship).
- **Information of the persons**: notice in the registration documents;
  published named results are a standard sporting use but must
  appear in the notice.
- Retention periods aligned with the purge (§ 7.1).
- **Right to erasure**: Multi-account › Anonymise a licensee (§ 9).
- **Data breach**: CNIL notification procedure within 72 h —
  plan the contact and the steps to follow BEFORE the incident.
- Hosting subcontractor (OVH…): check the DPA.

## 8. Step-by-step installation

> **Templates provided**: all the files to install **outside** the module
> (operating scripts, Apache vhosts, cron, sudoers, logrotate, fail2ban)
> are versioned in **`Modules/Custom/AUTH/serveur/`**, with their destination
> and permissions — see `serveur/README.md`. Copy them rather than
> retyping them: that is where configuration errors creep in.

### 8.0 From a fresh Debian — complete sequence

All the commands, in order. Replace `YOUR-DOMAIN` with your host
name. Nothing here contains a secret: the credentials are only entered at
step 7, in a `chmod 600` file.

```bash
# ── 1. Base system ────────────────────────────────────────────────────
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Europe/Paris     # otherwise the times of the logs and
                                               # of the crons are confusing
sudo apt install -y apache2 mariadb-server php php-mysql php-gd php-curl \
                    php-mbstring php-zip php-xml unzip curl \
                    cron rsyslog fail2ban certbot python3-certbot-apache \
                    libapache2-mod-security2
sudo systemctl enable --now cron fail2ban      # "cron" is missing on some minimal
                                               # images
sudo mysql_secure_installation

# ── 2. Database ───────────────────────────────────────────────────────
sudo mysql -e "CREATE DATABASE ianseo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'ianseo'@'localhost' IDENTIFIED BY 'STRONG-PASSWORD';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ianseo.* TO 'ianseo'@'localhost'; FLUSH PRIVILEGES;"

# ── 3. ianseo code ────────────────────────────────────────────────────
cd /tmp && curl -LO https://www.ianseo.net/Release/ianseo.zip
sudo mkdir -p /var/www/ianseo && sudo unzip -q ianseo.zip -d /var/www/ianseo
sudo chown -R www-data:www-data /var/www/ianseo        # temporary, to install

# ── 4. Apache + TLS ───────────────────────────────────────────────────
sudo a2enmod ssl rewrite headers
sudo a2dissite 000-default
# (the vhosts are put in place at step 6; certbot needs port 80 open)
sudo certbot --apache -d YOUR-DOMAIN

# ── 5. ianseo: initial installation ───────────────────────────────────
# Open https://YOUR-DOMAIN/Install/ and follow the wizard (database "ianseo",
# user "ianseo"). Create a test competition, check that everything
# works BEFORE adding the multi-account layer.

# ── 6. System files (module templates) ────────────────────────────────
# See serveur/README.md for details; adapt YOUR-DOMAIN in the vhosts.
cd /var/www/ianseo/Modules/Custom/AUTH/serveur   # (after step 7 if the module
                                                 #  is not deployed yet)
sudo install -m 0750 -o root -g root bin/ianseo-*              /usr/local/bin/
sudo mkdir -p /var/www/maintenance
sudo install -m 0644 -o root -g root apache/maintenance.html   /var/www/maintenance/index.html
sudo install -m 0644 -o root -g root apache/ianseo*.conf       /etc/apache2/sites-available/
sudo install -m 0440 -o root -g root sudoers/ianseo-maintenance /etc/sudoers.d/
sudo visudo -c                                   # MUST display "parsed OK"
sudo install -m 0644 -o root -g root logrotate/ianseo          /etc/logrotate.d/
sudo install -m 0644 -o root -g root fail2ban/filter-ianseo-auth.conf /etc/fail2ban/filter.d/ianseo-auth.conf
sudo install -m 0644 -o root -g root fail2ban/jail-ianseo.conf        /etc/fail2ban/jail.d/ianseo.conf
sudo touch /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log /var/log/ianseo-backup.log
sudo chown www-data:adm /var/log/ianseo-*.log && sudo chmod 0640 /var/log/ianseo-*.log
sudo systemctl restart fail2ban
sudo apache2ctl configtest && sudo systemctl reload apache2
# system updates at a fixed time, after the maintenance (§ 4.1)
sudo install -D -m 0644 apt/apt-daily.timer.conf         /etc/systemd/system/apt-daily.timer.d/ianseo.conf
sudo install -D -m 0644 apt/apt-daily-upgrade.timer.conf /etc/systemd/system/apt-daily-upgrade.timer.d/ianseo.conf
sudo install -m 0644 -o root -g root apt/51ianseo-auto-reboot /etc/apt/apt.conf.d/   # optional
sudo systemctl daemon-reload
# database (§ 6.3) — MySQL 8: /etc/mysql/mysql.conf.d/ and the SET PERSIST setting
sudo install -m 0644 -o root -g root mysql/ianseo.cnf /etc/mysql/mariadb.conf.d/99-ianseo.cnf
sudo systemctl restart mariadb

# ── 7. AUTH module ────────────────────────────────────────────────────
# Copy Modules/Custom/AUTH/ and Modules/Custom/_shared/ into /var/www/ianseo/
sudo chown -R www-data:www-data /var/www/ianseo/Modules/Custom
# Local configuration (secrets) — chmod 600, NEVER readable by the web:
sudo -u www-data nano /var/www/ianseo/Modules/Custom/AUTH/config.local.json
sudo chmod 600 /var/www/ianseo/Modules/Custom/AUTH/config.local.json
```

`config.local.json` — template (⚠️ **UTF-8 without BOM**: a BOM would make the
JSON reading fail and **the whole** configuration would be silently ignored):

```json
{
  "log_file": "/var/log/ianseo-auth.log",
  "licsync":  { "username": "SERVICE-ACCOUNT", "password": "…", "otp": "" },
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

(`ping_url`: optional, address of a monitoring check — see § 9, "Alerts".)

```bash
# ── 8. Accounts, deployment, locking ──────────────────────────────────
# a) https://YOUR-DOMAIN/Modules/Custom/AUTH/admin/ → create the ADMIN account
# b) admin/deploy.php → "Deploy the files" then "Turn authentication on"
# c) Sign in again: forced password change, then mandatory 2FA
sudo ianseo-lock                                  # normal state: core read-only
sudo -u www-data test -w /var/www/ianseo/TV/Photos && echo "TV OK"

# ── 9. Dry run of the maintenance, THEN activation of the cron ────────
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php --dry-run
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php --only=none
# (the site must answer 503 during the run, then come back)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-nightly /etc/cron.d/
# hot copies of the database, every 6 hours (§ 9 bis)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-backup-live /etc/cron.d/
# waiting lists of the online registration, every 10 minutes (§ 11 bis)
sudo install -m 0644 -o root -g root \
     /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-waitlist /etc/cron.d/
```

**The next day**, check: `tail -n 40 /var/log/ianseo-maintenance.log`, then
`sudo ianseo-restore --test <the night's backup>` — first restore test.

### 8.1 Recap of the functional steps

1. **ianseo**: official ZIP + `https://server/Install/`, single-user test.
   During this whole phase: Apache htdigest active on the whole site.
2. **Module**: copy `Modules/Custom/AUTH/` and `Modules/Custom/_shared/`.
3. **Accounts**: `…/Modules/Custom/AUTH/admin/` → create the **ADMIN** account
   (temporary password shown once), then CLUB (`1075093`), CD (`1075`), CR (`10`)
   test accounts.
4. **Deployment**: `admin/deploy.php` → "1. Deploy the files" then
   "2. Turn authentication on" (refused as long as there is no active ADMIN).
5. **Immediate test in a private window**: ADMIN login → forced password
   change → **forced 2FA set-up** (authenticator app
   on the phone); login with an officers' space account (SSO) → choice of
   structure → sees only their scope; anonymous → sign-in page everywhere.
6. Configure the SSO and the licence cron (§§ 11-12: `config.local.json`,
   service account, crontab).
7. Hardening §§ 4-6 (fail2ban, ModSecurity, vhost, php.ini, MySQL).
8. Remove/reduce the htdigest, open to the first pilot clubs.

**Competition codes**: the name/code is **free** (each organiser puts
what they want) but must be **unique on the server** — a code already in use
is refused at creation and at import (in the ianseo core, reusing a
code OVERWRITES the existing competition; the module forbids it). Only the owning
club can re-import its own competition (backup
restore). Ownership is recorded automatically at creation;
the admin can assign/correct it via the "Competitions & sharing" page.

## 9. Operations

- **Clubs**: create their competitions, "Competitions & sharing" page to
  open access to CD/CR/FFTA (default: private). Sharing = read + write.
- **Admin**: Users page = account creation, password reset
  (sessions revoked), 2FA reset (lost phone), remote sign-out,
  log of the last 30 events.
- **Monitor**: the AUT_Log log (LOGIN_FAIL peaks), fail2ban
  (`fail2ban-client status ianseo-auth`), disk space, ianseo updates. The admin log
  (Multi-account › Users, an Organisers / Archers tab each with its own
  filterable + paginated log) gives a quick view.
- **Alerts**: a red banner is shown on all the administrator's pages when the
  night's maintenance failed (with the steps at fault), when it **did not run** for
  more than 30 h (scheduled task stopped: no backup any more), or when the online copy or
  a hot copy failed. To be warned **even with the server off**, set `maintenance.ping_url`
  in `config.local.json` on the command line: the address of a
  [healthchecks.io](https://healthchecks.io) check (free), called at the end of each night (and
  `…/fail` on failure). Set the check to "once a day, 2 h grace": the
  service sends an e-mail on failure **and** when nothing arrives.
- **Server state** (Multi-account › Server configuration): read-only checks of the
  pitfalls lived through on a production server — MySQL 8 setting (§ 6.3), database connections and
  memory, slow query log, session length and import size (§ 6.1), ModSecurity
  limit (§ 4.3), OPcache, time of the system updates (§ 4.1), oversized sessions,
  age of the backups — each with its fix command.
- **Anonymise a licensee** (Multi-account › Anonymise a licensee, server administrator): for
  an erasure request. Search by licence, surname or first name, preview of everything that changes,
  confirmation by retyping the licence.
  - **Upcoming competitions** (not finished, and no score yet for this person):
    registrations and official roles **deleted**, the freed place goes to the waiting list. If a
    payment had been recorded, the organiser is told of a **refund to make** (club and
    amount, no name) on their "Payments" page, until they click "Refund
    done" — which writes the refund into the history, where the payments stay under `ANON`. A competition whose
    organiser has locked the participants is anonymised instead (the page says so).
  - **Competitions already drawn**: licence replaced by `ANON`, surname and first name emptied (participants and
    officials), date of birth deleted (the category, recorded at registration, does not
    change), photo, caption and e-mail deleted; the licence also disappears from the online registrations,
    payments, shop and log. Scores, rankings, matches, club and category are
    kept. These results are no longer attached to a licence (an export to the federation would
    send them under `ANON`).
  - Everywhere: online account deleted. Out of reach: the federation's licence file (reloaded every night),
  the data of other modules, the results already published on ianseo.net (the page lists the
  competitions to republish) or sent to the federation, and the backups, until the end of their
  retention period.
- **System logs: 60 days.** By default Apache only keeps 14 days: too short to
  analyse a competition afterwards (the other server could only go back two weeks).
  `sudo sed -i 's/^\s*rotate 14/\trotate 60/' /etc/logrotate.d/apache2`. Same duration for
  the load history if the `sysstat` package is installed (`HISTORY=60` in `/etc/sysstat/sysstat`).
- **Log retention**: `AUT_Log` and `BK_Log` are purged automatically beyond
  **180 days** (at most once a day, via the bootstrap; also runnable from the cron).
  Adjustable: `config.local.json` → `"log_retention_days": <days>` (range 7 to 3650).
  No consequence on the anti-bruteforce (15 min window). Aligns the retention with the
  privacy policy ("log kept for a few months").
- **Usage statistics** (Multi-account › Usage statistics, ADMIN): **aggregated**
  audience measurement (page views per hour/day, unique visitors, main pages, archer
  conversion rate, number of archers registering other archers), in 2 tabs
  Organisers / Archers. **No personal data, no IP**: signed-in users are counted
  by account identity, anonymous visitors of the home page via a **consent-exempt
  audience measurement cookie** (`aud`, first party, ≤ 13 months, CNIL doctrine). Counters
  in `AUT_Usage` (kept 25 months) and `AUT_UsageSeen` (log retention), purged with
  the logs. Can be disabled by `config.local.json` → `"stats_enabled": false`; time zone of the
  buckets adjustable by `"stats_timezone"` (default `Europe/Paris`). The **cookie policy**
  (public page Legal notice & Terms → Cookies) describes this cookie and its exemption.
- **ianseo update**: Update menu (ADMIN only). The update resets `htdocs/` to
  zero and erases `Modules/Authentication/`, but **a safety net self-repairs**: the
  `AUTH-SELFHEAL` block of `config.inc.php` (put in place at activation / at each deployment, and
  preserved across updates) copies `dist/` → `Modules/Authentication/` back from the 1st request.
  **No more manual redeployment after an update** — provided the web server
  can write to `Modules/`. `config.inc.php` and `Modules/Custom/` survive updates.
  (An existing install without the net gets it at the next "Deploy".)
- **Module update**: Multi-account menu → Module update, then
  redeploy if `dist/` changed.

## 9 bis. Backups (night + hot copies)

Every night, in the maintenance window (site closed, hence a consistent copy) and **just before
the core update**, `cron/backup.php` produces:

- `ianseo-db-YYYYMMDD-HHMMSS.sql.gz` — database dump (`mysqldump`);
- `ianseo-files-YYYYMMDD-HHMMSS.tar.gz` — archive of the site (without `TV/Photos`): going back
  needs the database **and** the code that goes with it.

**Without a valid backup, the core is not updated that night** (adjustable:
`backup.required_for_core`). Everything is set in **Multi-account › Server configuration**,
or in `config.local.json`:

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

**Club logos left out of the dumps (`logos: false`, default).** On the test server, the logo cache
weighed 59 % of the database: dump of **57.6 MB in 11.6 s** with them, **4.1 MB in 2.6 s** without.
Their two tables (`Flags`, `AUT_ClubLogos`) stay in the file, but as **structure only,
created only if missing**: restoring on this server leaves the logos in place; on a
new server, the tables are created empty and the logo sync fills them (the night's one,
or right away `sudo -u www-data php …/cron/sync-logos.php --full` — for competitions that are not
finished; finished competitions stay without a logo).

**Hot copies of the database** (`ianseo-live-YYYYMMDD-HHMMSS.sql.gz`), every 6 hours together with
the night: 09:05, 15:05, 21:05. Site open, blocking nothing: `--single-transaction` on
InnoDB tables reads a consistent snapshot without locking any table — a page that saves
an end during the copy does not wait. A few seconds (2.6 s on the test server).
They limit the loss to a few hours of entries instead of a day, are sent online
like the night's, kept 48 h (`live_keep_hours`) and skip themselves during the maintenance
window or a restore. Installation, once:

```bash
sudo touch /var/log/ianseo-backup.log && sudo chown www-data:adm /var/log/ianseo-backup.log
sudo install -m 0644 -o root -g root /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-backup-live /etc/cron.d/
```

(Suspend without touching the server: "Hot copies" box of the configuration page.)

**Once per server** — the default folder is under `/var/backups`, which belongs to root:

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/backups/ianseo
```

The folder is always **outside the web site** (refused otherwise: Apache does not block `.gz` files).
The latest backup of each type is kept even beyond `keep_days`.

Run a backup by hand: `sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/backup.php`

### Online copy (Google Drive, Dropbox, OneDrive, NAS…) — rclone

```bash
sudo apt install rclone
sudo install -d -o www-data -g www-data -m 0700 /var/www/.config
sudo -u www-data rclone config
```

⚠️ **The database contains the licensees' personal data**: **two** destinations are declared
— the storage, then a `crypt` (encrypted) layer on top of it. ianseo only writes to
the second: the host only sees unreadable files.

⚠️ On the welcome screen of `rclone config`, **do not** choose `s) Set configuration password`:
the backup runs at night, with nobody to type this password — it would fail.

**Destination 1 — the storage** (Google Drive example; `dropbox`, `onedrive`… go the same way):

| Question | Answer |
|---|---|
| `n/s/q>` | `n` |
| `name>` | `gdrive` |
| `Storage>` | `drive` |
| `client_id>` / `client_secret>` | Enter (empty) |
| `scope>` | `drive.file` — rclone sees **only the files it created**, nothing else of the Drive (see below for a shared folder) |
| `service_account_file>` | Enter |
| `Edit advanced config?` | `n` |
| `Use web browser to automatically authenticate?` | `n` — the server has no browser |

rclone then displays a `rclone authorize "drive" "…"` command. Run it **on a computer
with a browser** where rclone is installed (Windows: `winget install Rclone.Rclone`), sign in
to the chosen Google account, then paste the token displayed into `config_token>` on the server.
Then: `Shared Drive?` → `n`, `Keep this remote?` → `y`.

**Writing into a folder shared by someone else** (experienced on the other server): `drive.file`
sees neither shared drives nor a folder created by another account. The scope
`drive` is then needed, and the folder is designated by its identifier — the end of its address in the browser,
`https://drive.google.com/drive/folders/<identifier>` — at the `root_folder_id>` question (advanced
options: `Edit advanced config?` → `y`).

**Good to know about Google Drive**: the files belong to the Google account that authorised
rclone and depend on it (account deleted = backups lost) — prefer an account of the
organisation to a personal one. Never delete them from the Drive interface (the names there
are encrypted, you do not know what you are erasing): the module's rotation takes care of it, and deletes
**permanently** (without going through the Drive trash, which would count towards the quota).

**Destination 2 — the encrypted layer**:

| Question | Answer |
|---|---|
| `n/s/q>` | `n` |
| `name>` | `gdrive-encrypted` |
| `Storage>` | `crypt` |
| `remote>` | `gdrive:ianseo` (`ianseo` folder of the Drive, created at the first upload) |
| `filename_encryption>` | `standard` |
| `directory_name_encryption>` | `true` |
| `password` | `g` to generate one (or `y` to type it) |
| `password2` (salt) | `g` |
| `Edit advanced config?` | `n`; `Keep this remote?` → `y`; then `q` |

🔑 **Copy the two passwords displayed into a safe or a password manager, off the server.**
Without them, the online backups are **unrecoverable** — for
instance if the server itself is lost, which is precisely the case where they are needed.

Check from the server, then in ianseo:

```bash
sudo -u www-data rclone mkdir gdrive-encrypted:ianseo   # creates the folder (no effect if it exists)
sudo -u www-data rclone lsf gdrive-encrypted:ianseo     # no error = access ok (empty at first)
```

(Without the `mkdir`, a destination that has never received anything answers
`directory not found`: it is not a failure, the first upload would create the folder.)

**Multi-account › Server configuration** → Online copy: `gdrive-encrypted:` → Save →
**Test the online destination**. A failure of the online copy does not block the update
(the local copy is enough to go back).

**The upload starts after the site has reopened**, in the background: only the dump needs the site
closed. On the first server, 125 MB took 17 min to go (slow uplink) — staying
inside the window, the site would have stayed closed 22 min instead of 5, and the core files
unlocked for as long. The result is appended at the end of the night's log
(`Online copy: ok → …`), after the `Finished in … s` line. Run by hand in a terminal,
the upload is done on the spot.

Size to plan for online: ≈ (size of one night) × `remote_keep_days` + (size of one hot
copy) × 8 — without the logos, the database is small; it is the files archive that dominates.
One upload at a time: if the previous one is still dragging on, the local copy is made anyway and
the upload failure is reported (administrator banner).

### Restoring

Script `serveur/bin/ianseo-restore` (installed in `/usr/local/bin/`, to be run as root):

```bash
sudo ianseo-restore                                   # lists the backups, most recent first
sudo ianseo-restore --test ianseo-db-XXXX.sql.gz      # CHECKS a copy without touching the site
sudo ianseo-restore ianseo-db-XXXX.sql.gz             # restores the database (or an ianseo-files-…: the files)
```

⚠️ Restoring the database brings **all the competitions of the server** back to the time of the copy: everything
entered since is lost, for everybody. The script asks you to type `YES`, prevents the nightly
maintenance and the hot copies from starting, puts the site in maintenance, **backs up
the current state** (you may want to go back to it: it is the copy at the top of the list), restores, puts the
core back to read-only (files) and reopens the site. If the restore itself fails, the site
**stays closed** — a half-restored database must not be served — and the message says what to do.
To go back after a core update that went wrong: the database **and** the
files of the same night (same timestamp), the database first.

**Test a restore** before opening, then from time to time: `--test` restores the copy
into a throwaway database, counts tables, competitions and registrations, displays the most
recent competitions, then deletes the throwaway database. For an online copy, bring it back first
(`DESTINATION` = the value of the "Online copy" field, for example `gdrive-encrypted:ianseo`):
`sudo -u www-data rclone copy DESTINATION/ianseo-db-XXXX.sql.gz /tmp/` then
`sudo ianseo-restore --test /tmp/ianseo-db-XXXX.sql.gz`.

Without the script (for instance on a new machine where the module is not there yet):

```bash
sudo /usr/local/bin/ianseo-maintenance-on
gunzip -c /var/backups/ianseo/ianseo-db-XXXX.sql.gz | sudo mysql ianseo
sudo tar -xzf /var/backups/ianseo/ianseo-files-XXXX.tar.gz -C /var/www   # puts /var/www/ianseo back
sudo /usr/local/bin/ianseo-lock                                          # core read-only
sudo /usr/local/bin/ianseo-maintenance-off
```

## 9 ter. Configuration from ianseo (`admin/config.php`)

`config.local.json` is edited from **Multi-account › Server configuration** (server
administrator only). Rules applied on the server side:

- passwords are **never** displayed (replaced by `••••••••`, meaning "unchanged");
- **locked** — editable only on the command line: the maintenance commands
  (`maintenance.on/off/lock/unlock`), the paths (`*file`, `*dir`, `*path`, `*bin`…) and the
  server addresses (`*base`, `*url`, `*host`, any `https://…` value). A stolen
  administrator session must allow neither running a command on the server, nor writing a
  `.php` into the site (`log_file`), nor diverting the organisers' credentials (`sso.base`).
  The only exception: `backup.dir`, checked (never inside the site);
- atomic write, previous version in `config.local.json.bak`, `CONFIG_EDIT` event
  in the log.

The file must belong to the web server: `sudo chown www-data:www-data …/AUTH/config.local.json && sudo chmod 600 …`

## 10. Emergency procedure

If `USERAUTH` is on but `Modules/Authentication/BlockFunction.php`
is missing, **the whole site is in fatal error** (closed, not open — intended).
**Normally the `AUTH-SELFHEAL` net of `config.inc.php` repairs it alone** from the 1st
request. If it does not (web server without write access to `Modules/`, or
`config.inc.php` reset), from the console:

```bash
# redeploy:
cp /var/www/ianseo/Modules/Custom/AUTH/dist/* /var/www/ianseo/Modules/Authentication/
# OR turn authentication off:
sed -i 's/$CFG->USERAUTH = true;/$CFG->USERAUTH = false;/' /var/www/ianseo/Common/config.inc.php
```
```powershell
# Windows:
Copy-Item C:\ianseo\htdocs\Modules\Custom\AUTH\dist\* C:\ianseo\htdocs\Modules\Authentication\ -Force
```

Reminder: from `localhost` (server console or tunnel
`ssh -L 8080:localhost:80 server`), ianseo remains accessible without an account —
native back door of the core. That is also why SSH access must be
locked down (keys + restricted IPs): **whoever has SSH has ianseo**.
The point to know: the web server (www-data) must own the files for the AUTH self-repair and the local writes (config.local.json, sessions, logs) to work — the chown www-data is currently a manual step (displayed at the end of the installation script)

## 11. FFTA officers' space SSO

Organisers sign in with their **dirigeant.ffta.fr credentials**:
no account creation nor password management on the ianseo server side.

- At sign-in, the server validates the credentials by connecting to
  the officers' space (same flow as the existing FR licence integration), reads
  the **attached structures** (select-structure menu) and deduces the role:
  club (badge = agrément, e.g. `0760171`), CD (`60000` → dept 60), CR,
  Federation. FFTA role required: `Gestionnaire`/`Administrateur` (adjustable).
- **No choice of structure at sign-in**: the person goes straight into
  their **last view** used (or their maximum level), then **switches
  view on the fly** via the bar's selector (club / CD / CR / Fed / Admin).
  The active view determines what they see and the owner of the competitions
  they create. The structures are resynchronised at each sign-in: a
  structure removed on the officers' space disappears at the next login.
- The ianseo account is **provisioned automatically** at the first
  sign-in; an admin can deactivate it at any time (blocks access even
  if the FFTA credentials remain valid).
- **The FFTA password is neither stored nor logged** — it only goes over
  HTTPS to dirigeant.ffta.fr. If the FFTA account has MFA, the
  "MFA code" field of the form is relayed.
- **ADMIN** accounts can be your own officers' space account (SSO) +
  our server's 2FA (enrolment QR code), OR a local account. The admin role
  is always **granted explicitly** (never deduced from the SSO).
  **Keep the local `ianseo` account as a "break-glass"**: if
  dirigeant.ffta.fr is unavailable, it lets you regain control
  (independent of the external service). This server's 2FA only concerns the
  admin accounts (the others are secured by the officers' space).
- Limit to know: it is a **credential relay**, not an OAuth. In
  time, ask the provider of the officers' space for a real OpenID
  Connect client (the module will be able to switch); meanwhile, if the FFTA login
  page changes structure, the SSO stops cleanly (explicit error
  message) and the local accounts keep working.
- **Who can sign in as organiser**: a valid officers' space account is not
  enough — one must hold a **SPORTIF** role ("Gestionnaire Sportif" or
  "Administrateur Sportif") on at least one structure (club, CD, CR, federation).
  "Consultant Club", "Gestionnaire Club", "Administrateur" alone and the other
  roles are refused, with a message naming the roles to ask for.
- Configuration (`Modules/Custom/AUTH/config.local.json`) — default if absent:
  ```json
  { "sso": { "enabled": true,
             "required_role_regex": "(Gestionnaire|Administrateur)\\s+Sportif",
             "required_role_label": "\"Gestionnaire Sportif\" or \"Administrateur Sportif\"" } }
  ```
  To widen (e.g. also admit structure administrators without the
  sports qualifier): add `|Administrateur` to the pattern and adjust the label,
  which is only a display text in the refusal message.
- **If one day there is a problem with the SSO sign-in** (the FFTA changes its
  login/MFA page): turn the trace on by creating the empty file
  `Modules/Custom/AUTH/ffta-debug.on`, reproduce the error, read
  `Modules/Custom/AUTH/ffta-debug.log` (URLs, HTTP codes, page type, field
  names — **never** a password nor a code), then **delete
  `ffta-debug.on`**. Off by default. It is what made it possible to wire the
  two-step MFA (Laravel Fortify); keep this mechanism.

## 11 bis. Competitor space (online registration)

The module now includes the **online registration + shop** sub-module
(`Modules/Custom/AUTH/booking/`): the **licensees themselves** open an account,
consult the calendar of open competitions and register online.

- **Third FFTA space**: the competitor sign-in relays the credentials to
  **`monespace.ffta.fr`** (Espace Licencié) — distinct from `dirigeant.ffta.fr` (§ 11)
  and from `extranet.ffta.fr`. Same **credential relay** technique: the password
  goes over HTTPS, **never stored nor logged**; the licensee account has
  no local password (SSO sentinel). The attached licence is **read from the
  page served after sign-in** (declared by the FFTA), never from a form
  field — refused if uncertain.
- **Default sign-in page = competitor** (the great majority of visitors
  are licensees); the organiser tab remains accessible (`?p=org`).
- **Controlled public surface**: the `booking/public/` pages set `$SKIP_AUTH`
  before `config.php` (native core mechanism) → an anonymous licensee reaches them
  **even when AUTH is on**, with no whitelist to maintain. **Counterpart**:
  these pages have **no core ACL**; each read/write is explicitly guarded
  (`bk_current_archer()` + CSRF on every POST), bounded to the signed-in
  licensee. Sessions with hashed tokens in the database (`BK_Sessions`, like AUTH).
- **Anti-stuffing**: the competitor sign-in relays to the FFTA → **8 failures /
  15 min per IP or licence** before any outgoing call (`bk_too_many`), so as not to
  become a brute-force relay against the federation.
- **Data**: licensee accounts (`BK_Archers`), registrations (trace
  `BK_Registrations` + core Entries), tracked payments (`BK_Payments`), shop.
  Same compensations as § 7 (WAF, TLS, log, backups, purge).
- **SSO debug trace** identical to § 11: empty file
  `booking/ffta-debug.on` → `booking/ffta-debug.log` (never a password), to be
  removed after use.
- **Waiting list**: when a session is full for their profile (bow, category, target face), it
  remains selectable in the registration form, which then puts the archer on the list
  (with their wishes and payment method); as soon as a place is freed, the first compatible one is
  **registered automatically** (all the rules of a registration apply, manual
  validation included) and told on the site. The online registration pages serve the list
  as soon as a place is freed in them; for the places freed in the ianseo screens
  (participant deleted, targets added), a scheduled task runs every 10 minutes:
  ```bash
  sudo install -m 0644 -o root -g root /var/www/ianseo/Modules/Custom/AUTH/serveur/cron/ianseo-waitlist /etc/cron.d/
  ```
  The lists are deleted the day after the competition.

## 12. Licence sync by cron

The licensee database is maintained by the server, not by the organisers:

1. Create a dedicated **service account** on the officers' space (minimal
   rights: access to the ianseo download; **without MFA**, otherwise the cron cannot
   authenticate — failing that, MFA on a trusted IP if available).
2. Fill in `Modules/Custom/AUTH/config.local.json` (chmod **600**,
   owner www-data):
   ```json
   { "licsync": { "username": "svc-ianseo", "password": "…", "otp": "" } }
   ```
3. Crontab:
   ```
   15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-licences.php >> /var/log/ianseo-licsync.log 2>&1
   ```
   (Windows: Task Scheduler → `php.exe …\cron\sync-licences.php`.)
4. The script downloads `parametres_ianseo.ffta`, imports it into
   `LookUpEntries`, updates the registration statuses of the current/upcoming
   competitions, and logs `LICSYNC_OK/FAIL` in the module's log.
   Check the log after the first night.

`config.local.json` is never synchronised by the module updates
(outside the manifest): the secrets stay local to the server.

### All in one: the nightly maintenance window

`cron/maintenance.php` chains all the operations, each after the previous one,
and **alone replaces** the crontab lines of the syncs:

```
15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php >> /var/log/ianseo-maintenance.log 2>&1
```

Sequence: maintenance ON → unlock → **ianseo core update** → **Custom modules
update** → AUTH redeployment → **licence sync** → **logo sync** →
lock → maintenance OFF.

- **The maintenance is always switched off**, even if a step fails, if the script is
  interrupted or if it dies on a fatal error (end-of-script handler put in place
  before any action + SIGINT/SIGTERM/SIGHUP signals). Without it, the server would stay
  stuck on a 503 page. *(A `kill -9` remains out of reach: in that case, run
  `ianseo-maintenance-off` by hand.)*
- Each step is **independent**: the failure of one prevents neither the following ones nor the
  exit from maintenance. Summary at the end of the log, exit code 1 and `MAINT_PARTIAL` in the
  log if something failed.
- The system commands are **configurable**, and an empty command simply
  skips the step (the script is therefore harmless outside a production server):

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

- **`notice` — advance warning to the users.** During the
  `lead_minutes` that precede the time `at`, a permanent banner is shown on
  **all** the pages (organiser space *and* licensee space): "Maintenance
  scheduled at 03:15 (in N minutes) — the server will be unavailable for a few
  minutes. Finish and save your entry." The countdown is updated at
  each page. Nobody ends up in front of a 503 without warning.
  Without the `at` key, **no message** is shown (silent by default).
  ⚠️ `at` must match the real time of the cron: the two cannot be deduced
  from each other. Local time (`timezone`, default `Europe/Paris`) — ianseo
  forcing PHP to UTC, a naive setting would announce the time 2 h off.

  `www-data` must be able to run `on`/`off` without a password (`sudoers`, `NOPASSWD`,
  limited to those two paths).

  **`unlock`/`lock` stay EMPTY**: the unlocking of the core files is done by
  **root, around the script**, in the cron line itself (`serveur/cron/ianseo-nightly`):
  `ianseo-unlock && su www-data -c maintenance.php ; ianseo-lock`. The web account thus
  never receives the right to make ianseo's code writable — giving it `ianseo-unlock`
  through sudo would cancel the protection brought by `ianseo-lock` (a flaw in the site could
  rewrite the core). The `;` guarantees the re-locking even if the maintenance fails.
  *(Real incident of 2026-09-27: `unlock` filled in without `sudo` nor a path → "not found",
  files stayed locked, core update refused. Since then, a failed unlock makes
  the core update skip instead of attempting it for nothing.)*
- Options: `--dry-run` (shows the plan without doing anything — run it first),
  `--core` / `--no-core`, `--only=modules,licences,logos`.

#### ianseo core update, without a browser

`cron/update-core.php` does what `/Update/` does: this page is only an AJAX interface,
the real work lives in `Update/UpdateIanseo.php`, **which carries no access
control** (the ACL is in `index-action.php`). It can therefore be run on the CLI — which
**lifts the blocker** for automation: going through HTTP would have required scripting an
ADMIN sign-in + TOTP code, hence storing the 2FA secret in clear. Database
migrations are applied on the way.

> ⚠️ **Off by default** (`steps.core: false`). It rewrites core files
> and migrates the database **with no way back**. Only turn it on with an automatic backup
> of the database and the files (§ 9 bis — without a valid backup at night, it
> is skipped), and after a `--dry-run` trial. It requires the files to be
> unlocked during the window: that is the role of the cron line run by root.

> ⚠️ **Verified pitfall**: a **UTF-8 BOM** at the head of `config.local.json` (Notepad,
> `Set-Content -Encoding utf8`…) made the JSON reading fail and **the whole**
> configuration was silently ignored — with a misleading "credentials
> missing" message. The BOM is now removed automatically, but it is better to save this
> file as **UTF-8 without BOM**.

### Club logos (flags) — second cron, no service account

Without it, each organiser has to go through "Participants › Load matching table" and tick
**Flags** for THEIR competition, otherwise their printouts (bibs, badges, lists) come out
without a logo. The cron below removes this step for everybody.

```
15 4 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-logos.php >> /var/log/ianseo-logosync.log 2>&1
```

- **No credentials required**: the FFTA logos endpoint is public.
  To be placed **after** the licence sync (it provides the list of clubs).
- Two steps: download into the global cache `AUT_ClubLogos` (~1600 clubs,
  ~7 min, one reused connection), then **local propagation** to all the
  competitions that are not finished (`Flags` table + `TV/Photos/` files). A network
  outage does not prevent the propagation of what is already in the cache.
- Volume: count ~50 MB for the cache, plus the files per competition.
- Useful options: `--propagate-only` (no network), `--full` (download everything again),
  `--limit=N` (debugging). Logs `LOGOSYNC_OK/FAIL` in the module's log.
- Optional settings: `{ "logos": { "enabled": true, "delay_ms": 120,
  "refresh_days": 0, "timeout": 15 } }`. The URL is taken from ianseo
  (`LookUpPaths.LupFlagsPath`) — nothing to enter.
- **Logo changed by a club**: the FFTA endpoint returns neither `Last-Modified` nor `ETag`,
  so no conditional request is possible — you have to download to compare.
  Hence `refresh_days: 0` by default: **everything is downloaded again every night**, but a logo
  is only rewritten if it *really* changed (md5 fingerprint comparison, in the cache then
  on the file put in place). A change thus propagates by itself to the competitions that are not
  finished. ⚠️ Do **not** set `refresh_days: 1` with a daily cron: a club taken
  a few minutes after the start of the previous pass would be judged "fresh" and skipped one
  night out of two. Use `0` (default), or `2` and more if you want to save bandwidth
  while accepting a detection delay.
- The logo is put in place **immediately**, without waiting for the cron, in both cases:
  **online** registration (at confirmation) and **manual** entry by the organiser
  (`Partecipants/PopEdit.php`). In both cases it is a simple local copy from the
  cache — no network access. A club still absent from the cache (brand-new club) is caught up
  at the next pass of the cron, which also sweeps the clubs of the competitions.

## 13. Server-wide operations (update / repair)

Some ianseo operations act on the **whole database**, not on a
single competition:

- **Database update** (`/Update/`, Modules → Update menu): can
  run `ALTER TABLE` migrations. Reserved to the administrator (core guard +
  module guard + Apache localhost). **To be done in a maintenance
  window** (no competition under way): a migration while
  judges are entering scores can lock tables and cause
  errors for everybody. Warn people, back up beforehand.
- **Table repair** (`Modules/Help/RepairTables.php`, `RepairXAMPP.php`):
  `REPAIR`/`OPTIMIZE TABLE` on all the tables, or restart of MySQL.
  The ianseo core **checks NO right** on these pages; the AUTH module now
  blocks them for non-admins (central bootstrap guard), and the
  vhost restricts them to localhost. **Never** run them in the middle of a
  competition (table locking → interruptions).

General rule: these actions are **global and reserved to the admin**, to be
scheduled outside competitions. The module prevents an organiser from
triggering them, but nothing replaces an announced maintenance window.

## 14. Remaining points of attention

- **Public results**: everything is behind the login by default. For the
  public: ianseo.net publication (Competition menu), or targeted whitelist via
  `config.local.json` → `"public_paths"` (e.g. `"/TV/"`). Each opened path
  = extra attack surface: only open the strict minimum.
- **ISK / scoring tablets**: `Api/` is blocked for anonymous users;
  **scoring will mainly be done ON the server** (ISK lite, growing)
  → whitelist `/Api/ISK-NG/` (`config.local.json` → `public_paths`). The API has its
  own pairing codes (handshake/confirmhash), but it becomes an exposed surface:
  **tune ModSecurity** so as not to break the score POSTs (put
  `/Api/` in a targeted exclusion after analysis), and **size accordingly** (online
  scoring is the main load item — see the "Load" line below).
  ⚠️ **ISK pro / live modes FORBIDDEN on an online server**: they trigger on the
  ianseo side a mechanism that **revokes the licence** of the server. When the AUTH module
  is active, only "no ISK" and **ISK-NG lite** are offered (dropdown
  filtered on the competition page **and** on SYNCHRO_FFTA), and any competition
  saved/imported as pro/live is **switched back to lite** when it is
  opened (`aut_isk_enforce`, logged `ISK_DOWNGRADE`).
- **ianseo IP ACLs**: disabled remotely by the module — only the
  accounts count.
- **TV channels/rules**: editing is partitioned (a TV rule is tied to its
  competition, write protected by `TVRTournament` + the competition's ACL).
  Known reservation: the `TV/ChannelSetup.php` page (ianseo core) only displays
  the rules of the user's competitions, **except** for an account with
  no accessible competition at all (empty filter → lists all the rules). A
  minor leak (competition and TV rule codes/names, no scores) that
  mainly concerns a new account. To watch; fixable on the core side if
  annoying (add a `FALSE` condition when `AUTH_COMP` is empty, as the
  home list does).

## 15. Sizing (load)

FFTA 2025 figures: **243,500 scores uploaded**, **2,307 competitions**, **28,868
unique competitors**. Peak: **85 simultaneous competitions** (~9,900 competitors) on a
weekend, regularly **65** (~6,500). To these are added **recreational** events not counted.
Model retained: **scoring mainly on the server** (ISK lite, growing).

**The size of the database is NOT the constraint.** `LookUpEntries` (80,000 licensees) ≈ 10–15 MB;
a competition ≈ 1–3 MB. A whole year fits in **~5–15 GB**; with the purge at ~3 months
(§ 7.1), **~1–3 GB active**. The limiting factor is the **PHP CPU + the MySQL throughput** during peaks.

**Peak estimate** (85 competitions online): ~2,500–3,300 ISK tablets (≈ 1 per target/group),
each posting an end every ~3–5 min + polling for updates → **~150 req/s**
sustained for scoring alone, **+50–150 req/s** of results consultation → **aggregate peak
~200–300 req/s**, rising with the growth of ISK lite.

| Profile | vCPU | RAM | Disk | Covers |
|---|---|---|---|---|
| Minimum | 4 | 8 GB | 80 GB SSD | Registrations + results, scoring mostly local |
| **Recommended (floor here)** | **8** | **16 GB** | **160 GB NVMe** | The peak with **online scoring**, WAF active |
| Growth / recreational | 16 | 32 GB | 160 GB+ | ISK lite margin + recreational events |

**Elastic** cloud advised (OVH/Scaleway/Hetzner): scale up for the big
weekends, come back down afterwards (~€30–60/month for the recommended profile).

Levers (they matter more than the VM size):
1. **PHP-FPM + OPcache** (never mod_php prefork) — ×3–5 on ianseo's PHP throughput. Non-negotiable.
2. **`innodb_buffer_pool_size` = 8 GB**: the whole active set fits in RAM. And **`max_connections`
   ≥ 2 × PHP workers + margin**: ianseo opens two connections per page (§ 6.3) — with 100
   FPM workers, at least 220; the default value (151) would make pages be refused at the peak.
3. **Cache of the public results pages** (Apache mod_cache/Varnish, short TTL): absorbs the
   peak of spectators for the finals — often THE peak.
4. **FPM workers (50–100)**: ⚠️ the competitor login **relays SYNCHRONOUSLY** to
   `monespace.ffta.fr` (~1–2 s blocking/login) → a burst of registrations ties up workers.
5. **ModSecurity**: count **+20–30 % CPU** (built into the recommended profile); targeted exclusions
   on `/Api/` so as not to break ISK scoring.
6. **Scaling path** if exceeded: separate **MySQL on its own VM** (web/DB split),
   then read replicas for the results, then a CDN in front of the public pages.
7. **A single processor is not enough**, even for 3 operators: on the other server (1 vCPU),
   a single slow query (§ 6.3) saturated the processor and slowed everybody down, including
   score entry on phones. And any rate limiter placed in front must let through
   the ISK-NG bursts (§ 4.4).

## 16. Around a competition — operating routine

Drawn from the online-entry weekends of the other server. Commands to run on the server.

**Before**
- Sessions sized to the real need (Server state: "Oversized sessions"); the
  operators choose an archer's **session** before their target.
- ISK-NG entry tried with **several phones on the same wifi** (same public address:
  that is what triggers the rate limiters, § 4.4).
- Night backups and hot copies present (Server state, or `sudo ianseo-restore`),
  no red banner on the administrator's side; valid certificate
  (`sudo certbot certificates`).
- System updates properly scheduled at night (`systemctl list-timers 'apt-daily*'`).

**During**
- Load: `top` (who consumes — `mysqld`, `apache2`/`php-fpm`);
- Running queries, longest first:
  `sudo mysql -e "SELECT ID, TIME, LEFT(INFO, 80) FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' ORDER BY TIME DESC"`;
- Errors: `sudo tail -f /var/log/apache2/error.log`; blocks: `sudo fail2ban-client status ianseo-auth`
  (and, if mod_evasive is installed, `ls -lt /var/log/mod_evasive | head`).

**After**
- Slow queries: `sudo mysqldumpslow -s t /var/lib/mysql/*-slow.log | grep "^Count" | head`;
- Busiest seconds:
  `sudo zcat -f /var/log/apache2/access.log* | awk '{print $4}' | sort | uniq -c | sort -rn | head`;
- Module log: Multi-account › Users (`LOGIN_FAIL` peaks).
