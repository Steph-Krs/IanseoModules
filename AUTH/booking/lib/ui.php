<?php
/**
 * lib/ui.php — coquille HTML de l'espace licencié (face publique).
 *
 * Cet espace ne passe PAS par Common/Templates/head.php : ce gabarit rend le
 * menu organisateur et suppose une session de compétition ouverte. On rend donc
 * une page autonome, aux couleurs de la charte (voir CHARTE_GRAPHIQUE.md).
 * CSS à portée limitée par #bk (aucun style partagé entre modules).
 */

if (defined('BK_UI_LOADED')) return;
define('BK_UI_LOADED', true);

function bk_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function bk_public_url($page = '')
{
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/' . $page;
}

/**
 * En-tête de page. $layout :
 *  - 'card'  : carte centrée (connexion, création de compte)
 *  - 'page'  : pleine largeur avec barre de navigation (espace connecté)
 */
/** Icône de navigation (SVG inline, stroke = couleur courante). Repli mobile : le
 *  libellé texte est masqué et seule l'icône reste, pour tenir la largeur. */
function bk_nav_icon($name)
{
    static $p = array(
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 9.5V20h14V9.5"/>',
        'cal'  => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        'club' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c0-3 2.7-5 5.5-5s5.5 2 5.5 5"/><path d="M16.5 5.6a3.2 3.2 0 010 5.6M17 14.6c2.3.5 3.5 2.2 3.5 4.4"/>',
        'out'  => '<path d="M15 4.5h4.5v15H15"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h9"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 3.5L16 11H5"/>',
        'stat' => '<path d="M4 20V4M4 20h16"/><path d="M8 20v-5M13 20v-9M18 20v-3"/>',
    );
    $inner = $p[$name] ?? '';
    return '<svg class="bk-nav-ic" width="17" height="17" viewBox="0 0 24 24" fill="none"'
         . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true">' . $inner . '</svg>';
}

function bk_head($title, $layout = 'page')
{
    $archer = function_exists('bk_current_archer') ? bk_current_archer() : null;
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= bk_e($title) ?> — Inscriptions</title>
<link rel="stylesheet" href="<?= bk_e(bk_public_url('assets/bk.css')) ?>?v=<?= bk_e(bk_version()) ?>">
</head>
<body>
<div id="bk" class="bk-<?= bk_e($layout) ?>">
<?php if ($layout === 'page'): ?>
  <header class="bk-top">
    <a class="bk-brand" href="<?= bk_e(bk_public_url()) ?>">Inscriptions en ligne</a>
    <?php if ($archer): ?>
      <nav class="bk-nav">
        <a href="<?= bk_e(bk_public_url()) ?>" title="Mon espace"><?= bk_nav_icon('home') ?><span class="bk-nav-lab">Mon espace</span></a>
        <a href="<?= bk_e(bk_public_url('calendar.php')) ?>" title="Calendrier"><?= bk_nav_icon('cal') ?><span class="bk-nav-lab">Calendrier</span></a>
        <a href="<?= bk_e(bk_public_url('registrations.php')) ?>" title="Mes inscriptions"><?= bk_nav_icon('list') ?><span class="bk-nav-lab">Mes inscriptions</span></a>
        <a href="<?= bk_e(bk_public_url('stats.php')) ?>" title="Mes statistiques"><?= bk_nav_icon('stat') ?><span class="bk-nav-lab">Statistiques</span></a>
        <?php if (bk_is_manager()): ?>
          <a href="<?= bk_e(bk_public_url('club.php')) ?>" title="Mon club"><?= bk_nav_icon('club') ?><span class="bk-nav-lab">Mon club</span></a>
        <?php endif; ?>
        <a href="<?= bk_e(bk_public_url('tickets.php')) ?>" title="Signaler un bug / proposer une évolution"><?= bk_nav_icon('flag') ?><span class="bk-nav-lab">Signaler</span></a>
        <span class="bk-who"><?= bk_e($archer->BaName . ' ' . $archer->BaFamilyName) ?></span>
        <a class="bk-out" href="<?= bk_e(bk_public_url('logout.php')) ?>" title="Déconnexion"><?= bk_nav_icon('out') ?><span class="bk-nav-lab">Déconnexion</span></a>
      </nav>
    <?php endif; ?>
  </header>
<?php endif; ?>
  <main class="bk-main">
<?php
}

function bk_foot()
{
    ?>
  </main>
</div>
</body>
</html>
<?php
}

/** Version du module — casse le cache navigateur des assets après une MaJ. */
function bk_version()
{
    static $v = null;
    if ($v === null) {
        $j = json_decode((string) @file_get_contents(__DIR__ . '/../version.json'), true);
        $v = (is_array($j) && !empty($j['version'])) ? $j['version'] : '0';
    }
    return $v;
}

/**
 * L'archer connecté est-il gestionnaire d'un club ? Sert à décider l'affichage
 * de l'entrée de menu. lib/club.php n'est chargé que si présent : la coquille
 * doit rester utilisable sur une page qui ne l'inclut pas.
 */
function bk_is_manager()
{
    static $r = null;
    if ($r !== null) return $r;
    $r = false;
    $a = function_exists('bk_current_archer') ? bk_current_archer() : null;
    if ($a) {
        if (!function_exists('bk_manager_scopes')) {
            $f = __DIR__ . '/club.php';
            if (is_file($f)) require_once $f;
        }
        if (function_exists('bk_manager_scopes')) $r = (bool) bk_manager_scopes($a);
    }
    return $r;
}

/** Date au format français court (2026-08-15 → 15/08/2026). */
function bk_date_fr($d)
{
    $d = substr(trim((string) $d), 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return '';
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/** « le 15/08/2026 » ou « du 13/08/2026 au 16/08/2026 ». */
function bk_date_range($from, $to)
{
    $f = bk_date_fr($from);
    $t = bk_date_fr($to);
    if ($f === '' && $t === '') return '';
    if ($f === '' || $f === $t) return 'le ' . ($f ?: $t);
    if ($t === '') return 'le ' . $f;
    return 'du ' . $f . ' au ' . $t;
}

function bk_msg($type, $text)
{
    return '<div class="bk-msg bk-msg-' . bk_e($type) . '">' . bk_e($text) . '</div>';
}

/** Redirige puis termine le script (jamais de sortie avant un header). */
function bk_redirect($page)
{
    header('Location: ' . bk_public_url($page));
    exit;
}

/** Exige un licencié connecté ; redirige vers la connexion sinon. */
function bk_require_archer()
{
    $a = bk_current_archer();
    if (!$a) bk_redirect('login.php');
    return $a;
}
