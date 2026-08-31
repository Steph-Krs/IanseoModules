# Fichiers à installer **hors** du module

Ces gabarits vivent dans le module pour être versionnés et mis à jour avec lui,
mais ils sont **destinés au système** : le module ne les installe pas lui-même
et ne peut pas les modifier. Vous les copiez une fois, à la main.

> Aucun de ces fichiers ne contient de secret. Les valeurs propres à votre
> installation sont des **placeholders** en majuscules (`VOTRE-DOMAINE`) ou des
> chemins standard (`/var/www/ianseo`). Relisez-les avant de les installer.

## Où va quoi

| Fichier du dossier | Destination | Droits |
|---|---|---|
| `bin/ianseo-lock` | `/usr/local/bin/ianseo-lock` | `root:root 0750` |
| `bin/ianseo-unlock` | `/usr/local/bin/ianseo-unlock` | `root:root 0750` |
| `bin/ianseo-maintenance-on` | `/usr/local/bin/ianseo-maintenance-on` | `root:root 0750` |
| `bin/ianseo-maintenance-off` | `/usr/local/bin/ianseo-maintenance-off` | `root:root 0750` |
| `apache/ianseo.conf` | `/etc/apache2/sites-available/ianseo.conf` | `root:root 0644` |
| `apache/ianseo-ssl.conf` | `/etc/apache2/sites-available/ianseo-ssl.conf` | `root:root 0644` |
| `apache/maintenance.html` | `/var/www/maintenance/index.html` | `root:root 0644` |
| `cron/ianseo-nightly` | `/etc/cron.d/ianseo-nightly` | `root:root 0644` |
| `sudoers/ianseo-maintenance` | `/etc/sudoers.d/ianseo-maintenance` | `root:root 0440` |
| `logrotate/ianseo` | `/etc/logrotate.d/ianseo` | `root:root 0644` |
| `fail2ban/filter-ianseo-auth.conf` | `/etc/fail2ban/filter.d/ianseo-auth.conf` | `root:root 0644` |
| `fail2ban/jail-ianseo.conf` | `/etc/fail2ban/jail.d/ianseo.conf` | `root:root 0644` |

## Installation

La procédure complète, depuis une Debian neuve, est dans **`../SERVEUR.md` § 8**.
Les commandes ci-dessous n'en sont que le résumé, à exécuter depuis ce dossier :

```bash
cd /var/www/ianseo/Modules/Custom/AUTH/serveur

# Scripts d'exploitation
sudo install -m 0750 -o root -g root bin/ianseo-lock            /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-unlock          /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-maintenance-on  /usr/local/bin/
sudo install -m 0750 -o root -g root bin/ianseo-maintenance-off /usr/local/bin/

# Page de maintenance
sudo mkdir -p /var/www/maintenance
sudo install -m 0644 -o root -g root apache/maintenance.html /var/www/maintenance/index.html

# Apache (adapter VOTRE-DOMAINE AVANT d'activer)
sudo install -m 0644 -o root -g root apache/ianseo.conf     /etc/apache2/sites-available/
sudo install -m 0644 -o root -g root apache/ianseo-ssl.conf /etc/apache2/sites-available/
sudo a2enmod ssl rewrite headers
sudo apache2ctl configtest && sudo systemctl reload apache2

# Droits minimaux du compte web (vérification impérative)
sudo install -m 0440 -o root -g root sudoers/ianseo-maintenance /etc/sudoers.d/
sudo visudo -c

# Journaux + rotation
sudo touch /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log
sudo chown www-data:adm /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log
sudo chmod 0640        /var/log/ianseo-maintenance.log /var/log/ianseo-auth.log
sudo install -m 0644 -o root -g root logrotate/ianseo /etc/logrotate.d/

# fail2ban
sudo install -m 0644 -o root -g root fail2ban/filter-ianseo-auth.conf /etc/fail2ban/filter.d/ianseo-auth.conf
sudo install -m 0644 -o root -g root fail2ban/jail-ianseo.conf        /etc/fail2ban/jail.d/ianseo.conf
sudo systemctl restart fail2ban

# Cron nocturne — EN DERNIER, après un essai à blanc réussi (voir SERVEUR.md)
sudo install -m 0644 -o root -g root cron/ianseo-nightly /etc/cron.d/ianseo-nightly
```

## Vérifications

```bash
sudo -u www-data test -w /var/www/ianseo/TV/Photos && echo "TV OK"   # requis par ianseo lui-même
sudo -u www-data sudo -n /usr/local/bin/ianseo-maintenance-on        # doit passer sans mot de passe
curl -s -o /dev/null -w '%{http_code}\n' https://VOTRE-DOMAINE/      # doit afficher 503
sudo -u www-data sudo -n /usr/local/bin/ianseo-maintenance-off
sudo fail2ban-client status ianseo-auth
sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php --dry-run
```
