<?php
/**
 * AUTH module — server settings (config.local.json) edited from ianseo.
 *
 * Server admin only (same guard as deploy.php). Every safety rule lives in
 * config-lib.php, on the server side: masked secrets, locked commands/paths/URLs,
 * atomic write with a .bak copy. This page is only a view on top of it.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/config-lib.php');
require_once(dirname(__DIR__) . '/backup-lib.php');
require_once(dirname(__DIR__) . '/health-lib.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

function acf_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function acf_int($v, $min, $max, $def) { $v = trim((string) $v); return ($v === '' || !is_numeric($v)) ? $def : max($min, min($max, intval($v))); }

$msgOk = ''; $msgErr = ''; $testOut = null; $rawPosted = null;
$readErr = '';
$cfg = aut_cfg_read($readErr);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cfg !== null) {
    $action = (string) ($_POST['action'] ?? '');
    $new = null;
    if (!aut_csrf_check()) {
        $msgErr = 'Session expirée : rien n\'a été enregistré, réessayez.';
    } elseif ($action === 'save_backup') {
        $d = aut_backup_defaults();
        $new = $cfg;
        $new['backup'] = array_merge(is_array($cfg['backup'] ?? null) ? $cfg['backup'] : array(), array(
            'enabled'           => !empty($_POST['bk_enabled']),
            'dir'               => trim((string) ($_POST['bk_dir'] ?? $d['dir'])),
            'keep_days'         => acf_int($_POST['bk_keep'] ?? '', 1, 3650, $d['keep_days']),
            'files'             => !empty($_POST['bk_files']),
            'logos'             => !empty($_POST['bk_logos']),
            'required_for_core' => !empty($_POST['bk_required']),
            'remote'            => trim((string) ($_POST['bk_remote'] ?? '')),
            'remote_keep_days'  => acf_int($_POST['bk_rkeep'] ?? '', 1, 3650, $d['remote_keep_days']),
            'live'              => !empty($_POST['bk_live']),
            'live_keep_hours'   => acf_int($_POST['bk_lkeep'] ?? '', 6, 720, $d['live_keep_hours']),
        ));
    } elseif ($action === 'save_maint') {
        $new = $cfg;
        $m = is_array($cfg['maintenance'] ?? null) ? $cfg['maintenance'] : array();
        $m['steps'] = array();
        foreach (array('core', 'modules', 'licences', 'logos') as $s) $m['steps'][$s] = !empty($_POST['st_' . $s]);
        $at = trim((string) ($_POST['nt_at'] ?? ''));
        if ($at !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at)) {
            $msgErr = 'Heure de préavis invalide (format HH:MM).';
            $new = null;
        } else {
            $m['notice'] = array('at' => $at, 'lead_minutes' => acf_int($_POST['nt_lead'] ?? '', 0, 240, 15));
            $new['maintenance'] = $m;
            $new['log_retention_days'] = acf_int($_POST['log_days'] ?? '', 7, 3650, 180);
            $new['stats_enabled'] = !empty($_POST['stats_enabled']);
        }
    } elseif ($action === 'save_raw') {
        $rawPosted = (string) ($_POST['raw'] ?? '');
        $d = json_decode($rawPosted, true);
        if (!is_array($d)) $msgErr = 'JSON invalide : ' . json_last_error_msg() . '. Rien n\'a été enregistré.';
        else $new = $d;
    } elseif ($action === 'test_remote') {
        $remote = aut_backup_config($cfg)['remote'];
        $out = array();
        if ($remote === '') $msgErr = 'Aucune destination en ligne configurée.';
        elseif (aut_backup_remote_test($remote, $out)) { $msgOk = 'Destination en ligne joignable : ' . $remote; $testOut = $out; }
        else { $msgErr = 'Destination en ligne injoignable : ' . $remote; $testOut = $out; }
    }

    if ($new !== null && $msgErr === '') {
        $e = ''; $changed = array();
        if (aut_cfg_save($new, $e, $changed)) {
            if ($changed) {
                aut_log('CONFIG_EDIT', $_SESSION['AUTH_User'] ?? 'local');
                $msgOk = 'Configuration enregistrée (' . implode(', ', $changed) . ').';
            } else {
                $msgOk = 'Aucune modification.';
            }
            $rawPosted = null;
            $cfg = aut_cfg_read($readErr);
        } else {
            $msgErr = $e;
        }
    }
}

$bk = aut_backup_config($cfg ?: array());
$mt = is_array($cfg['maintenance'] ?? null) ? $cfg['maintenance'] : array();
$steps = is_array($mt['steps'] ?? null) ? $mt['steps'] : array();
$notice = is_array($mt['notice'] ?? null) ? $mt['notice'] : array();

$PAGE_TITLE = 'Multi-comptes — Configuration du serveur';
include('Common/Templates/head.php');

echo '<style>
#acf .ok{color:#1a5c1a} #acf .ko{color:#8b1a1a} #acf .warn{color:#8a5a00}
#acf code.cmd{display:block;background:#f4f6f8;border:1px solid #d2d4d6;padding:6px 8px;margin:4px 0;white-space:pre-wrap;word-break:break-all}
#acf td.lbl{width:34%;font-weight:bold}
#acf .hint{font-size:11px;color:#555}
#acf textarea{width:100%;min-height:360px;font-family:monospace;font-size:12px}
#acf input[type=text]{width:95%}
</style>';

echo '<div id="acf"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">Configuration du serveur (config.local.json)</th></tr>';
if ($msgOk)   echo '<tr><td colspan="2" class="Center" style="background:#e8f4e8;color:#1a5c1a;">' . acf_h($msgOk) . '</td></tr>';
if ($msgErr)  echo '<tr><td colspan="2" class="Center" style="background:#fde8e8;color:#8b1a1a;">' . acf_h($msgErr) . '</td></tr>';
if ($readErr) echo '<tr><td colspan="2" class="Center" style="background:#fde8e8;color:#8b1a1a;">' . acf_h($readErr)
    . ' — la page est en lecture seule tant que le fichier n\'est pas réparé en ligne de commande.</td></tr>';
if ($testOut) echo '<tr><td colspan="2"><code class="cmd">' . acf_h(implode("\n", array_slice($testOut, 0, 30))) . '</code></td></tr>';
echo '<tr><td colspan="2" class="hint">Réglages propres à ce serveur. Les mots de passe ne sont jamais affichés, et '
    . 'les commandes, chemins de fichiers et adresses de serveurs ne se modifient qu\'en ligne de commande : '
    . 'une session administrateur volée ne doit pas pouvoir exécuter de commandes sur le serveur ni détourner les '
    . 'identifiants des organisateurs. Chaque enregistrement garde la version précédente '
    . '(<code>config.local.json.bak</code>) et est journalisé.</td></tr>';

/* ---------------- Server health ---------------- */
echo '<tr><th class="Title" colspan="2">État du serveur</th></tr>';
echo '<tr><td colspan="2" class="hint">Contrôles en lecture seule, tirés d\'incidents réels sur un serveur ianseo en '
    . 'production (détails : <code>Modules/Custom/AUTH/SERVEUR.md</code>). Rien n\'est modifié ici : les corrections '
    . 'proposées se font en ligne de commande sur le serveur.</td></tr>';
$acfIcon = array('ok' => '<span class="ok">✔</span>', 'warn' => '<span class="ko">⚠</span>', 'info' => '<span class="warn">ℹ</span>');
foreach (aut_health_checks() as $h) {
    echo '<tr><td class="lbl">' . $acfIcon[$h['level']] . ' ' . acf_h($h['title']) . '</td><td>' . acf_h($h['text']);
    if ($h['list']) {
        echo '<ul style="margin:4px 0 0 18px">';
        foreach ($h['list'] as $x) echo '<li>' . acf_h($x) . '</li>';
        echo '</ul>';
    }
    if ($h['fix'] !== '') echo '<code class="cmd">' . acf_h($h['fix']) . '</code>';
    echo '</td></tr>';
}

/* ---------------- Backup ---------------- */
$fix = '';
$dirPb = aut_backup_dir_problem($bk['dir'], $fix, false);
$dump = aut_backup_mysqldump_bin();
$rcl  = aut_backup_rclone_bin();
$list = aut_backup_list($bk['dir']);

echo '<tr><th class="Title" colspan="2">Sauvegardes (nuit + copies à chaud)</th></tr>';
echo '<tr><td colspan="2" class="hint">La sauvegarde nocturne est prise dans la fenêtre de maintenance, site fermé, juste '
    . 'avant la mise à jour du cœur ianseo — c\'est elle qui permet de revenir en arrière si une migration se passe mal. '
    . 'Les copies à chaud (base seule, site ouvert, quelques secondes sans rien bloquer) limitent la perte à quelques '
    . 'heures de saisie en cas de sinistre ; elles tournent si la tâche <code>/etc/cron.d/ianseo-backup-live</code> est '
    . 'installée (gabarit <code>serveur/cron/ianseo-backup-live</code>).</td></tr>';

echo '<tr><td class="lbl">État</td><td>';
echo $dirPb === '' ? '<span class="ok">✔ Dossier utilisable</span>' : '<span class="ko">✘ ' . acf_h($dirPb) . '</span>';
if ($fix !== '') echo '<br>À lancer une fois sur le serveur :<code class="cmd">' . acf_h($fix) . '</code>';
echo '<br>' . ($dump !== '' ? '<span class="ok">✔ mysqldump disponible</span>' : '<span class="ko">✘ mysqldump introuvable</span>');
echo '<br>' . ($rcl !== '' ? '<span class="ok">✔ rclone disponible</span>' : '<span class="warn">– rclone non installé (copie en ligne impossible)</span>');
if ($dirPb === '') {
    $free = @disk_free_space($bk['dir']);
    if ($free !== false) echo '<br>Espace libre : ' . round($free / 1073741824, 1) . ' Go';
}
if ($list) {
    $acfNight = array_values(array_filter($list, function ($b) { return $b['kind'] !== 'live'; }));
    $acfLive  = array_values(array_filter($list, function ($b) { return $b['kind'] === 'live'; }));
    foreach (array('Dernières sauvegardes nocturnes' => array_slice($acfNight, 0, 4),
                   'Dernières copies à chaud' => array_slice($acfLive, 0, 4)) as $acfT => $acfL) {
        if (!$acfL) continue;
        echo '<br><br><b>' . $acfT . ' :</b><br>';
        foreach ($acfL as $b) echo acf_h(basename($b['file'])) . ' — ' . round($b['size'] / 1048576, 1) . ' Mo<br>';
    }
} elseif ($dirPb === '') {
    echo '<br><br>Aucune sauvegarde pour l\'instant (première à la prochaine nuit).';
}
echo '</td></tr>';

$dis = $cfg === null ? ' disabled' : '';
echo '</table><form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save_backup"><table class="Tabella">';
echo '<tr><td class="lbl">Sauvegarde active</td><td><label><input type="checkbox" name="bk_enabled" value="1"'
    . ($bk['enabled'] ? ' checked' : '') . $dis . '> sauvegarder chaque nuit</label></td></tr>';
echo '<tr><td class="lbl">Dossier des sauvegardes</td><td><input type="text" name="bk_dir" value="' . acf_h($bk['dir']) . '"' . $dis . '>'
    . '<div class="hint">Chemin absolu, <b>hors du site web</b> (refusé sinon : les fichiers y seraient téléchargeables).</div></td></tr>';
echo '<tr><td class="lbl">Conserver (jours)</td><td><input type="number" name="bk_keep" min="1" max="3650" value="' . intval($bk['keep_days']) . '"' . $dis . '>'
    . '<div class="hint">Les plus anciennes sont supprimées ; la dernière sauvegarde est toujours gardée.</div></td></tr>';
echo '<tr><td class="lbl">Inclure les fichiers</td><td><label><input type="checkbox" name="bk_files" value="1"'
    . ($bk['files'] ? ' checked' : '') . $dis . '> archive du site ianseo (code + modules), en plus de la base</label>'
    . '<div class="hint">Pour revenir en arrière après une mise à jour, il faut la base ET le code qui va avec.</div></td></tr>';
echo '<tr><td class="lbl">Inclure les logos des clubs</td><td><label><input type="checkbox" name="bk_logos" value="1"'
    . ($bk['logos'] ? ' checked' : '') . $dis . '> sauvegarder aussi les logos (tables Flags et AUT_ClubLogos)</label>'
    . '<div class="hint">Décoché (conseillé) : copie jusqu\'à 14 fois plus légère et plus rapide. Une restauration laisse '
    . 'les logos en place ; sur un serveur neuf, la synchro des logos les remet pour les compétitions non terminées '
    . '(<code>cron/sync-logos.php --full</code>).</div></td></tr>';
echo '<tr><td class="lbl">Copies à chaud</td><td><label><input type="checkbox" name="bk_live" value="1"'
    . ($bk['live'] ? ' checked' : '') . $dis . '> actives (si la tâche planifiée est installée)</label>, conservées '
    . '<input type="number" name="bk_lkeep" min="6" max="720" style="width:5em" value="' . intval($bk['live_keep_hours']) . '"' . $dis . '> heures'
    . '<div class="hint">Décocher suspend les copies à chaud sans toucher au serveur. Elles sont aussi envoyées en ligne si '
    . 'une copie en ligne est configurée, avec la même durée de conservation.</div></td></tr>';
echo '<tr><td class="lbl">Mise à jour du cœur</td><td><label><input type="checkbox" name="bk_required" value="1"'
    . ($bk['required_for_core'] ? ' checked' : '') . $dis . '> ne pas mettre ianseo à jour si la sauvegarde a échoué (recommandé)</label></td></tr>';
echo '<tr><td class="lbl">Copie en ligne (rclone)</td><td><input type="text" name="bk_remote" placeholder="ex. gdrive-chiffre:ianseo" value="'
    . acf_h($bk['remote']) . '"' . $dis . '><div class="hint">Vide = sauvegarde locale seulement. Format : '
    . '<code>nom:dossier</code>, où <code>nom</code> est une destination déclarée dans rclone (voir ci-dessous).</div></td></tr>';
echo '<tr><td class="lbl">Conserver en ligne (jours)</td><td><input type="number" name="bk_rkeep" min="1" max="3650" value="' . intval($bk['remote_keep_days']) . '"' . $dis . '></td></tr>';
echo '<tr><td colspan="2" class="Center"><button type="submit"' . $dis . '>Enregistrer la sauvegarde</button></td></tr></table></form>';
if ($bk['remote'] !== '') {
    echo '<form method="post" action="" class="Center" style="margin:4px 0">' . aut_csrf_field()
        . '<input type="hidden" name="action" value="test_remote"><button type="submit"' . $dis . '>Tester la destination en ligne</button></form>';
}
echo '<table class="Tabella">';

echo '<tr><td colspan="2" class="hint"><details><summary><b>Configurer une copie sur Google Drive, Dropbox, OneDrive, un NAS…</b></summary>'
    . '<p>La copie en ligne passe par <b>rclone</b>, un outil libre qui sait écrire sur plus de 70 services. '
    . 'L\'autorisation Google ou Dropbox est faite une seule fois, dans rclone : ianseo ne stocke aucun jeton d\'accès.</p>'
    . '<p>⚠️ <b>La base contient les données personnelles des licenciés.</b> Ne l\'envoyez pas en clair chez un '
    . 'hébergeur : déclarez une destination <b>chiffrée</b> (type <code>crypt</code> de rclone) par-dessus votre Drive — '
    . 'les fichiers y sont illisibles sans la phrase secrète, qui reste sur le serveur. Gardez cette phrase ailleurs '
    . 'aussi : sans elle, les sauvegardes en ligne sont irrécupérables.</p>'
    . '<p>Une fois, en ligne de commande sur le serveur :</p>'
    . '<code class="cmd">sudo apt install rclone
sudo install -d -o www-data -g www-data -m 0700 /var/www/.config
sudo -u www-data rclone config</code>'
    . '<p>Dans <code>rclone config</code> : créez d\'abord la destination du service (ex. <code>gdrive</code>, type '
    . '<code>drive</code> ; le serveur n\'ayant pas de navigateur, rclone indique une commande '
    . '<code>rclone authorize "drive"</code> à lancer sur un PC où rclone est installé, puis à recoller). '
    . 'Créez ensuite une destination <code>gdrive-chiffre</code> de type <code>crypt</code> pointant sur '
    . '<code>gdrive:ianseo</code>. Saisissez enfin <code>gdrive-chiffre:</code> ci-dessus et testez.</p>'
    . '</details></td></tr>';

/* ---------------- Nightly window ---------------- */
echo '</table><form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save_maint"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">Nuit de maintenance</th></tr>';
$lbl = array(
    'core'     => array('Mettre à jour le cœur ianseo', false),
    'modules'  => array('Mettre à jour les modules', true),
    'licences' => array('Synchroniser les licences FFTA', true),
    'logos'    => array('Synchroniser les logos de club', true),
);
echo '<tr><td class="lbl">Étapes</td><td>';
foreach ($lbl as $k => $l) {
    $on = array_key_exists($k, $steps) ? !empty($steps[$k]) : $l[1];
    echo '<label><input type="checkbox" name="st_' . $k . '" value="1"' . ($on ? ' checked' : '') . $dis . '> ' . acf_h($l[0]) . '</label><br>';
}
echo '<div class="hint">La sauvegarde se règle dans la section précédente.</div></td></tr>';
echo '<tr><td class="lbl">Préavis affiché aux utilisateurs</td><td>à <input type="text" name="nt_at" style="width:5em" placeholder="03:15" value="'
    . acf_h($notice['at'] ?? '') . '"' . $dis . '>, <input type="number" name="nt_lead" min="0" max="240" style="width:5em" value="'
    . intval($notice['lead_minutes'] ?? 15) . '"' . $dis . '> minutes avant'
    . '<div class="hint">Doit correspondre à l\'heure de la tâche planifiée du serveur (<code>/etc/cron.d/ianseo-nightly</code>). Vide = pas de préavis.</div></td></tr>';
echo '<tr><td class="lbl">Conservation des journaux (jours)</td><td><input type="number" name="log_days" min="7" max="3650" value="'
    . intval($cfg['log_retention_days'] ?? 180) . '"' . $dis . '></td></tr>';
echo '<tr><td class="lbl">Statistiques d\'usage</td><td><label><input type="checkbox" name="stats_enabled" value="1"'
    . ((!array_key_exists('stats_enabled', (array) $cfg) || !empty($cfg['stats_enabled'])) ? ' checked' : '') . $dis . '> mesurer la fréquentation (sans données personnelles)</label></td></tr>';
echo '<tr><td class="lbl">Commandes système</td><td class="hint">';
foreach (array('on', 'off', 'unlock', 'lock') as $k) {
    echo '<code>' . $k . '</code> : ' . (trim((string) ($mt[$k] ?? '')) !== '' ? '<code>' . acf_h($mt[$k]) . '</code>' : '<i>aucune</i>') . '<br>';
}
echo 'Modifiables uniquement en ligne de commande.</td></tr>';
echo '<tr><td class="lbl">Signal de vie (supervision)</td><td class="hint">'
    . (trim((string) ($mt['ping_url'] ?? '')) !== '' ? '<code>' . acf_h($mt['ping_url']) . '</code>' : '<i>aucun</i>')
    . '<br>Adresse appelée à la fin de chaque nuit, et en cas d\'échec d\'une copie (<code>…/fail</code>) — par exemple un '
    . 'contrôle healthchecks.io, qui prévient aussi quand <b>rien</b> n\'arrive (tâche arrêtée, serveur éteint). '
    . 'Clé <code>maintenance.ping_url</code>, modifiable uniquement en ligne de commande.</td></tr>';
echo '<tr><td colspan="2" class="Center"><button type="submit"' . $dis . '>Enregistrer</button></td></tr></table></form><table class="Tabella">';

/* ---------------- Raw editor ---------------- */
echo '<tr><th class="Title" colspan="2">Édition avancée (fichier complet)</th></tr>';
$locked = array();
foreach (aut_cfg_flatten($cfg ?: array()) as $p => $v) if (aut_cfg_is_locked($p, $v)) $locked[] = $p;
echo '<tr><td colspan="2"><details' . ($rawPosted !== null ? ' open' : '') . '><summary>Afficher le JSON</summary>'
    . '<p class="hint">Les mots de passe apparaissent sous la forme <code>' . AUT_CFG_MASK . '</code> : laissez-les tels quels '
    . 'pour ne pas les changer, ou remplacez-les par la nouvelle valeur. '
    . ($locked ? 'Verrouillés (ligne de commande uniquement) : <code>' . acf_h(implode(', ', $locked)) . '</code>.' : '')
    . '</p><form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save_raw">'
    . '<textarea name="raw" spellcheck="false"' . $dis . '>'
    . acf_h($rawPosted !== null ? $rawPosted
        : json_encode(aut_cfg_masked($cfg ?: array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    . '</textarea><div class="Center"><button type="submit"' . $dis
    . ' onclick="return confirm(\'Enregistrer le fichier de configuration complet ?\');">Enregistrer le JSON</button></div></form></details></td></tr>';

echo '</table></div>';
include('Common/Templates/tail.php');
