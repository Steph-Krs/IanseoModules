<?php
/**
 * legal.php — pages légales publiques (mentions légales, CGU, confidentialité, cookies).
 *
 * Page AUTONOME accessible ANONYMEMENT ($SKIP_AUTH avant config.php → le bootstrap
 * organisateur ne tourne pas, pas de redirection vers la connexion). Le contenu est
 * généré depuis les informations de l'exploitant (admin/legal.php → legal.local.json).
 */
$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/legal-lib.php');

$slug = (string) ($_GET['doc'] ?? 'mentions');
$doc  = aut_legal_doc_by_slug($slug);
if ($doc === '') $doc = 'mentions';

$docs = aut_legal_docs();
$title = $docs[$doc][0];
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$root = $CFG->ROOT_DIR;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?></title>
<style>
:root { --blue:#0254a8; --blued:#01367c; }
* { box-sizing:border-box; }
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6; color:#20263d; line-height:1.55; }
.lg-wrap { max-width:820px; margin:0 auto; padding:24px 16px 60px; }
.lg-head { display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; margin:6px 0 4px; }
.lg-head h1 { font-size:24px; color:var(--blued); margin:0; }
.lg-tabs { display:flex; flex-wrap:wrap; gap:6px; margin:14px 0 18px; }
.lg-tabs a { text-decoration:none; font-size:13px; padding:7px 12px; border-radius:20px;
    border:1px solid #c9d4df; background:#fff; color:#334; }
.lg-tabs a.on { background:var(--blue); border-color:var(--blue); color:#fff; font-weight:600; }
.lg-card { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:24px 26px;
    box-shadow:0 2px 10px rgba(0,0,0,.05); overflow-wrap:anywhere; }
.lg-card h2 { font-size:17px; color:var(--blue); margin:22px 0 6px; }
.lg-card h2:first-child { margin-top:0; }
.lg-card p, .lg-card li { font-size:14px; }
.lg-card ul { padding-left:20px; }
.lg-card a { color:var(--blue); }
.lg-note { margin:22px 0 0; padding:12px 14px; background:#fdf7e6; border:1px solid #eadfb8; border-radius:8px; }
.lg-note p { margin:0; font-size:13px; color:#6a5a2a; }
.lg-ver { margin-top:18px; font-size:12px; color:#7d8183; }
.lg-back { display:inline-block; margin:16px 0 0; font-size:13px; color:var(--blue); text-decoration:none; }
.lg-warn { background:#fff; border:1px dashed #d0a; border-radius:8px; padding:10px 14px; margin:0 0 16px;
    font-size:13px; color:#8b1a6a; }
@media print { body { background:#fff; } .lg-tabs, .lg-back { display:none; } .lg-card { border:0; box-shadow:none; padding:0; } }
</style>
</head>
<body>
<div class="lg-wrap">
  <div class="lg-head"><h1><?= $e($title) ?></h1></div>

  <?php if (!aut_legal_configured()): ?>
    <p class="lg-warn">⚠️ L'exploitant de ce serveur n'a pas encore renseigné ses informations légales.
       Les textes ci-dessous sont incomplets tant que cette configuration n'est pas faite.</p>
  <?php endif; ?>

  <nav class="lg-tabs">
    <?php foreach ($docs as $k => $d): ?>
      <a class="<?= $k === $doc ? 'on' : '' ?>" href="<?= $e($root) ?>Modules/Custom/AUTH/legal.php?doc=<?= $e($d[1]) ?>"><?= $e($d[0]) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="lg-card">
    <?= aut_legal_render($doc) ?>
  </div>

  <a class="lg-back" href="<?= $e($root) ?>Modules/Custom/AUTH/login.php">← Retour à la connexion</a>
</div>
</body>
</html>
