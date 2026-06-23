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
