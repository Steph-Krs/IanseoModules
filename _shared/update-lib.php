<?php
// Bibliothèque partagée de mise à jour pour les modules Custom ianseo.
// Usage dans un module : require_once dirname(__DIR__) . '/_shared/update-lib.php';

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}

// Charge module.json depuis $module_dir.
// Retourne array avec clés : github_url, github_branch, github_path, github_token.
function upd_load_config($module_dir) {
    $defaults = ['github_url' => '', 'github_branch' => 'main', 'github_path' => '', 'github_token' => ''];
    $f = $module_dir . '/module.json';
    if (!is_file($f)) return $defaults;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? array_merge($defaults, $d) : $defaults;
}

// Extrait ['owner', 'repo'] depuis une URL GitHub. Retourne null si invalide.
function upd_parse_repo($url) {
    if (preg_match('|github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?(?:/.*)?$|i', $url, $m)) {
        return ['owner' => $m[1], 'repo' => rtrim($m[2], '/')];
    }
    return null;
}

// Retourne le préfixe distant (ex: "GUIDE/") depuis cfg['github_path'].
function upd_remote_prefix($cfg) {
    $p = trim($cfg['github_path'] ?? '', '/');
    return $p !== '' ? "$p/" : '';
}

// Construit l'URL raw.githubusercontent.com de base pour le module (sans slash final).
function upd_raw_base($cfg) {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) return '';
    $branch = $cfg['github_branch'] ?: 'main';
    $prefix = rtrim(upd_remote_prefix($cfg), '/');
    return "https://raw.githubusercontent.com/{$repo['owner']}/{$repo['repo']}/$branch"
         . ($prefix ? "/$prefix" : '');
}

// Requête API GitHub JSON. Retourne array décodé, ou ['_error' => message].
function upd_gh_fetch($url, $token = '') {
    $headers = "User-Agent: ianseo-custom-module/1.0\r\n";
    if ($token) $headers .= "Authorization: token $token\r\n";
    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return ['_error' => 'Impossible de joindre GitHub (vérifiez la connexion)'];
    $data = json_decode($res, true);
    if (isset($data['message'])) return ['_error' => $data['message']];
    return is_array($data) ? $data : ['_error' => 'Réponse JSON invalide'];
}

// Télécharge un fichier brut. Retourne le contenu (string) ou false.
function upd_gh_raw($url, $token = '') {
    $headers = "User-Agent: ianseo-custom-module/1.0\r\n";
    if ($token) $headers .= "Authorization: token $token\r\n";
    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx);
}

// Lit version.json distant. Retourne array (avec au moins 'version' et 'files'), ou ['_error' => ...].
function upd_remote_version($cfg) {
    $base = upd_raw_base($cfg);
    if (!$base) return ['_error' => 'URL GitHub invalide dans module.json'];
    $raw = upd_gh_raw("$base/version.json", $cfg['github_token'] ?? '');
    if ($raw === false || $raw === '') return ['_error' => 'Impossible de lire version.json distant'];
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['version'])) return ['_error' => 'version.json distant invalide ou manquant'];
    return $data;
}

// Lit version.json local. Retourne array ou null si absent/invalide.
function upd_local_version($module_dir) {
    $f = $module_dir . '/version.json';
    if (!is_file($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

// Compare versions locale et distante. Retourne 'update' si remote > local, sinon 'ok'.
function upd_compare($local_ver, $remote_ver) {
    return version_compare($remote_ver, $local_ver, '>') ? 'update' : 'ok';
}

// Synchronise les fichiers listés dans $files depuis le dépôt vers $module_dir.
// $files : tableau de chemins relatifs au dossier du module (ex: ['assets/guide.js', 'version.json']).
// Retourne ['ok' => N, 'fail' => [fichiers en erreur]].
function upd_sync_files($cfg, $module_dir, $files) {
    $base  = upd_raw_base($cfg);
    $token = $cfg['github_token'] ?? '';
    $ok = 0;
    $fail = [];
    foreach ($files as $rel) {
        $content = upd_gh_raw("$base/$rel", $token);
        if ($content !== false && strlen($content) > 0) {
            $dest = $module_dir . '/' . $rel;
            $dir  = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dest, $content);
            $ok++;
        } else {
            $fail[] = $rel;
        }
    }
    return ['ok' => $ok, 'fail' => $fail];
}

/* ===================== Désinstallation ===================== */

// Dossier Modules/Custom (ce fichier vit dans Custom/_shared/).
function upd_custom_dir() {
    return dirname(__DIR__);
}

// Identifiant de l'utilisateur connecté, '' sans module de comptes.
// Convention du cœur ianseo (USERAUTH), posée par ex. par Modules/Custom/AUTH.
function upd_current_user() {
    return substr(trim((string)($_SESSION['AUTH_User'] ?? '')), 0, 64);
}

// Droit d'administrer les modules. AclRoot ne suffit PAS : avec un module de
// comptes, authCheckACL accorde AclReadWrite à tout organisateur connecté sur
// les pages hors compétition → on exige en plus la vue Administrateur serveur.
function upd_is_admin() {
    if (upd_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) return false;
    return hasFullACL(AclRoot, '', AclReadWrite);
}

// Garde des pages d'administration : avorte (noAccess) si non autorisé.
function upd_admin_guard() {
    global $CFG;
    checkFullACL(AclRoot, '', AclReadWrite);
    if (upd_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) {
        CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
        die();
    }
}

// Modules gérés = dossiers de Custom/ contenant un module.json.
function upd_list_modules() {
    $out = [];
    foreach (glob(upd_custom_dir() . '/*', GLOB_ONLYDIR) as $d) {
        $n = basename($d);
        if ($n === '' || $n[0] === '_' || $n[0] === '.') continue;
        if (is_file($d . '/module.json')) $out[] = $n;
    }
    sort($out);
    return $out;
}

// Valide un nom de module reçu en paramètre. Retourne son chemin, ou null.
// basename() + liste blanche de caractères neutralisent toute traversée de
// chemin ; l'exigence d'un module.json empêche de viser un dossier ianseo.
function upd_valid_module($name) {
    $name = (string)$name;
    if (strpos($name, "\0") !== false) return null;  // basename() tronque sur l'octet nul
    $name = basename(trim($name));
    if ($name === '' || $name[0] === '_' || $name[0] === '.') return null;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) return null;
    $dir = upd_custom_dir() . '/' . $name;
    return (is_dir($dir) && is_file($dir . '/module.json')) ? $dir : null;
}

// Tables déclarées par le module dans version.json ("tables": [...]).
// version.json vient du dépôt distant : les noms sont filtrés avant tout SQL.
function upd_module_tables($module_dir) {
    $v = upd_local_version($module_dir);
    $list = (is_array($v) && !empty($v['tables']) && is_array($v['tables'])) ? $v['tables'] : [];
    $out = [];
    foreach ($list as $t) {
        if (is_string($t) && preg_match('/^[A-Za-z0-9_]+$/', $t)) $out[] = $t;
    }
    return $out;
}

// Avertissement de désinstallation déclaré par le module dans version.json
// ("uninstall_warning"). Sert aux modules dont la suppression a des effets hors
// de leur dossier (fichiers déployés ailleurs, dépendances du cœur…).
function upd_uninstall_warning($module_dir) {
    $v = upd_local_version($module_dir);
    $w = (is_array($v) && !empty($v['uninstall_warning'])) ? $v['uninstall_warning'] : '';
    return is_string($w) ? $w : '';
}

// Archive le module dans le dossier temporaire système (hors racine web :
// une sauvegarde déposée dans Custom/ serait téléchargeable par n'importe qui).
// Retourne ['file' => chemin] ou ['_error' => message].
function upd_backup_module($module_dir, $name) {
    if (!class_exists('ZipArchive')) {
        return ['_error' => 'extension ZIP indisponible sur ce serveur'];
    }
    $base = realpath($module_dir);
    if ($base === false) return ['_error' => 'dossier du module introuvable'];

    $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
          . 'ianseo-' . $name . '-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['_error' => 'impossible de créer ' . $file];
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = $name . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if ($f->isDir()) $zip->addEmptyDir($rel);
        else            $zip->addFile($f->getPathname(), $rel);
    }
    if (!$zip->close()) return ['_error' => 'écriture de l\'archive interrompue'];
    return ['file' => $file];
}

// Suppression récursive, confinée à Modules/Custom/.
// Le test de confinement est la garantie principale : même avec un chemin
// forgé, rien hors de Custom/ (ni Custom/ lui-même) ne peut être supprimé.
function upd_rrmdir($dir) {
    $base = realpath(upd_custom_dir());
    $real = realpath($dir);
    if ($base === false || $real === false) return false;
    if ($real === $base || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return false;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) { if (!rmdir($f->getPathname()))  return false; }
        else             { if (!unlink($f->getPathname())) return false; }
    }
    return rmdir($real);
}

// Supprime les tables déclarées. Retourne la liste effectivement traitée.
function upd_drop_tables($tables) {
    $done = [];
    foreach ($tables as $t) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;  // double garde avant SQL
        safe_w_sql("DROP TABLE IF EXISTS `$t`");
        $done[] = $t;
    }
    return $done;
}
