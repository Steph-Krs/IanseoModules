# Files to install **outside** the module

French version: [README_FR.md](README_FR.md).

These templates live in the module to be versioned and updated with it, but they are **meant
for the system**: the module does not install them itself and cannot change them. You copy them
once, by hand.

> None of these files holds a secret. The values specific to your installation are upper-case
> **placeholders** (`YOUR-DOMAIN`) or standard paths (`/var/www/ianseo`). Read them again before
> installing them.

## What goes where

| File of the folder | Destination | Rights |
|---|---|---|
| `bin/ianseo-lock` | `/usr/local/bin/ianseo-lock` | `root:root 0750` |
| `bin/ianseo-unlock` | `/usr/local/bin/ianseo-unlock` | `root:root 0750` |
| `bin/ianseo-maintenance-on` | `/usr/local/bin/ianseo-maintenance-on` | `root:root 0750` |
| `bin/ianseo-maintenance-off` | `/usr/local/bin/ianseo-maintenance-off` | `root:root 0750` |
| `bin/ianseo-restore` | `/usr/local/bin/ianseo-restore` | `root:root 0750` |
| `apache/ianseo.conf` | `/etc/apache2/sites-available/ianseo.conf` | `root:root 0644` |
| `apache/ianseo-ssl.conf` | `/etc/apache2/sites-available/ianseo-ssl.conf` | `root:root 0644` |
| `apache/maintenance.html` | `/var/www/maintenance/index.html` | `root:root 0644` |
| `cron/ianseo-nightly` | `/etc/cron.d/ianseo-nightly` | `root:root 0644` |
| `cron/ianseo-backup-live` | `/etc/cron.d/ianseo-backup-live` | `root:root 0644` |
| `cron/ianseo-waitlist` | `/etc/cron.d/ianseo-waitlist` | `root:root 0644` |
| `sudoers/ianseo-maintenance` | `/etc/sudoers.d/ianseo-maintenance` | `root:root 0440` |
| `logrotate/ianseo` | `/etc/logrotate.d/ianseo` | `root:root 0644` |
| `fail2ban/filter-ianseo-auth.conf` | `/etc/fail2ban/filter.d/ianseo-auth.conf` | `root:root 0644` |
| `fail2ban/jail-ianseo.conf` | `/etc/fail2ban/jail.d/ianseo.conf` | `root:root 0644` |
| `apt/apt-daily.timer.conf` | `/etc/systemd/system/apt-daily.timer.d/ianseo.conf` | `root:root 0644` |
| `apt/apt-daily-upgrade.timer.conf` | `/etc/systemd/system/apt-daily-upgrade.timer.d/ianseo.conf` | `root:root 0644` |
| `apt/51ianseo-auto-reboot` | `/etc/apt/apt.conf.d/51ianseo-auto-reboot` (optional) | `root:root 0644` |
| `mysql/ianseo.cnf` | `/etc/mysql/mariadb.conf.d/99-ianseo.cnf` (MySQL 8: `/etc/mysql/mysql.conf.d/`) | `root:root 0644` |

The maintenance page (`apache/maintenance.html`) is shown in the visitor's browser language
(English, French, Spanish, German, Italian), English otherwise.

## Installation

The full procedure, from a fresh Debian, is in **`../SERVEUR.md` § 8** (French original: `../SERVEUR_FR.md`). The commands
below are only its summary, to run from this folder:

```bash
cd /var/www/ianseo/Modules/Custom/AUTH/serveur

# Operation scripts
sudo install -m 0750 -o root -g root bin/ianseo-lock            /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-unlock          /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-maintenance-on  /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-maintenance-off /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-restore         /usr/local/bin/

# Maintenance page
sudo mkdir -p /var/www/maintenance
sudo install -m 0644 -o root -g root apache/maintenance.html /var/www/maintenance/index.html

# Apache (set YOUR-DOMAIN BEFORE turning it on)
sudo install -m 0644 -o root -g root apache/ianseo.conf     /etc/apache2/sites-available/
sudo install -m 0644 -o root -g root apache/ianseo-ssl.conf /etc/apache2/sites-available/
sudo a2enmod ssl rewrite headers
sudo apache2ctl configtest && sudo systemctl reload apache2

# Minimal rights of the web account (check mandatory)
sudo install -m 0440 -o root -g root sudoers/ianseo-maintenance /etc/sudoers.d/
sudo visudo -c

# Logs + rotation
sudo touch /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log /var/log/ianseo-backup.log
sudo chown www-data:adm /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log /var/log/ianseo-backup.log
sudo chmod 0640        /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log /var/log/ianseo-backup.log
sudo install -m 0644 -o root -g root logrotate/ianseo /etc/logrotate.d/

# System updates at a fixed time, after the maintenance of 03:15 (SERVEUR.md § 4.1)
sudo install -D -m 0644 apt/apt-daily.timer.conf         /etc/systemd/system/apt-daily.timer.d/ianseo.conf
sudo install -D -m 0644 apt/apt-daily-upgrade.timer.conf /etc/systemd/system/apt-daily-upgrade.timer.d/ianseo.conf
sudo systemctl daemon-reload
sudo install -m 0644 -o root -g root apt/51ianseo-auto-reboot /etc/apt/apt.conf.d/   # optional

# Database (SERVEUR.md § 6.3) — MySQL 8: /etc/mysql/mysql.conf.d/ instead
sudo install -m 0644 -o root -g root mysql/ianseo.cnf /etc/mysql/mariadb.conf.d/99-ianseo.cnf
sudo systemctl restart mariadb                       # outside a competition

# fail2ban
sudo install -m 0644 -o root -g root fail2ban/filter-ianseo-auth.conf /etc/fail2ban/filter.d/ianseo-auth.conf
sudo install -m 0644 -o root -g root fail2ban/jail-ianseo.conf        /etc/fail2ban/jail.d/ianseo.conf
sudo systemctl restart fail2ban

# Nightly cron — LAST, after a successful dry run (see SERVEUR.md)
sudo install -m 0644 -o root -g root cron/ianseo-nightly /etc/cron.d/ianseo-nightly
# Live copies of the database, every 6 hours (SERVEUR.md § 9 bis)
sudo install -m 0644 -o root -g root cron/ianseo-backup-live /etc/cron.d/ianseo-backup-live
# Waiting lists of the online registration, every 10 minutes (SERVEUR.md § 11 bis)
sudo install -m 0644 -o root -g root cron/ianseo-waitlist /etc/cron.d/ianseo-waitlist
```

## Checks

```bash
sudo -u www-data test -w /var/www/ianseo/TV/Photos && echo "TV OK"   # required by ianseo itself
sudo -u www-data sudo -n /usr/local/bin/ianseo-maintenance-on        # must pass without a password
curl -s -o /dev/null -w '%{http_code}\n' https://YOUR-DOMAIN/      # must print 503
sudo -u www-data sudo -n /usr/local/bin/ianseo-maintenance-off
sudo fail2ban-client status ianseo-auth
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php --dry-run
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/backup.php --live   # one live copy
sudo ianseo-restore                                  # lists the backups
sudo ianseo-restore --test ianseo-live-…             # restores a copy apart, without touching the site
systemctl list-timers 'apt-daily*'                   # NEXT: 04:00 and 04:30
```
