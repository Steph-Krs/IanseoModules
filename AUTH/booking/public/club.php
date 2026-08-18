<?php
/**
 * public/club.php — inscription des archers de son club par un gestionnaire.
 *
 * Le périmètre est revérifié à chaque écriture (bk_scope_covers) : un
 * gestionnaire ne doit jamais pouvoir inscrire un archer hors de son club,
 * même en forgeant le formulaire.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/club.php';

$archer = bk_require_archer();
$scopes = bk_manager_scopes($archer);

if (!$scopes) {
    bk_head('Inscrire mon club', 'card');
    echo '<div class="bk-card"><h1>Accès réservé</h1>'
       . bk_msg('err', "Votre compte n'est pas déclaré gestionnaire de club. "
            . "Demandez à l'organisateur du serveur de vous accorder ce droit.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url()) . '">Retour à mon espace</a></p></div>';
    bk_foot();
    exit;
}

$tourId = intval($_GET['t'] ?? $_POST['t'] ?? 0);
$comps  = bk_comp_calendar();
$cfg    = $tourId ? bk_comp_config($tourId) : null;
$search = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));

$err = ''; $ok = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['go'] ?? '') === '1') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — merci de réessayer.';
    } elseif (!$tourId || !$cfg || empty($cfg->BcIsOpen)) {
        $err = "Les inscriptions ne sont pas ouvertes pour cette compétition.";
    } else {
        $licence  = bk_clean_licence($_POST['licence'] ?? '');
        $division = (string) ($_POST['division'] ?? '');
        $session  = intval($_POST['session'] ?? 0);
        $request  = trim((string) ($_POST['request'] ?? ''));

        $lue = bk_lookup_licence($licence);
        // La catégorie n'est pas demandée : elle découle de l'âge, du sexe et de
        // l'arme. On prend la plus spécifique que le règlement autorise (la liste
        // est déjà triée ainsi) — impossible de la désaccorder de l'arme choisie.
        $class = '';
        if ($lue) {
            $cl = bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $division);
            $class = (string) array_key_first($cl);
        }

        if (!$lue) {
            $err = "Licence inconnue.";
        } elseif ($class === '') {
            $err = "Aucune catégorie ne correspond à cet archer pour cette arme.";
        } elseif (!bk_scope_covers($scopes, $lue->LueCountry)) {
            // Contrôle décisif : hors périmètre, on refuse quoi qu'il arrive.
            bk_log('CLUB_OUT_OF_SCOPE', $archer->BaLicence);
            $err = "Cet archer n'appartient pas à un club dont vous êtes gestionnaire.";
        } else {
            $err = bk_reg_blocked($tourId, $cfg, $licence, $lue->LueCountry,
                $division, $class, $session, $lue);
            if ($err === '') {
                $res = bk_register($tourId, $lue, $division, $class, $session, $request, array(
                    'role' => 'MANAGER', 'who' => $archer->BaLicence, 'archer' => 0,
                ));
                if (!empty($res['ok'])) {
                    bk_log('REG_CLUB', $archer->BaLicence);
                    $ok = $lue->LueFamilyName . ' ' . $lue->LueName . ' a bien été inscrit(e).';
                } else {
                    $err = $res['msg'] ?? "L'inscription a échoué.";
                }
            }
        }
    }
}

$membres  = bk_club_members($scopes, $search);
$labels   = bk_scope_labels($scopes);
$sessions = $tourId ? bk_comp_sessions($tourId) : array();
$divs     = $tourId ? bk_reg_divisions($tourId) : array();

// Déjà inscrits sur cette compétition (pour ne pas les reproposer).
$deja = array();
if ($tourId) {
    $rs = safe_r_sql("SELECT EnCode FROM Entries WHERE EnTournament = " . intval($tourId));
    while ($r = safe_fetch($rs)) $deja[bk_clean_licence($r->EnCode)] = true;
}

bk_head('Inscrire mon club');
?>
<h1>Inscrire les archers de mon club</h1>
<p class="bk-org">Périmètre : <?= bk_e(implode(', ', $labels) ?: implode(', ', $scopes)) ?></p>

<?= $ok  ? bk_msg('ok',  $ok)  : '' ?>
<?= $err ? bk_msg('err', $err) : '' ?>

<form class="bk-filters" method="get">
  <label>Compétition
    <select name="t" onchange="this.form.submit()">
      <option value="">— choisir —</option>
      <?php foreach ($comps as $c): ?>
        <option value="<?= intval($c->BcTournament) ?>" <?= $tourId === intval($c->BcTournament) ? 'selected' : '' ?>>
          <?= bk_e($c->ToName) ?> (<?= bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) ?>)
        </option>
      <?php endforeach; ?>
    </select></label>
  <label>Rechercher un archer
    <input type="text" name="q" value="<?= bk_e($search) ?>" placeholder="Nom ou licence"></label>
  <button type="submit" class="bk-btn">Filtrer</button>
</form>

<?php if (!$tourId): ?>
  <p class="bk-empty">Choisissez une compétition pour inscrire vos archers.</p>
<?php elseif (empty($cfg->BcIsOpen)): ?>
  <?= bk_msg('err', "Les inscriptions ne sont pas ouvertes pour cette compétition.") ?>
<?php elseif (!$membres): ?>
  <p class="bk-empty">Aucun archer ne correspond dans votre périmètre.</p>
<?php else: ?>
  <div class="bk-list">
  <?php foreach ($membres as $m):
    $inscrit = isset($deja[bk_clean_licence($m->LueCode)]);
    $geo     = bk_comp_archer_blocked($cfg, $m->LueCountry); ?>
    <article class="bk-item<?= ($inscrit || $geo) ? ' bk-item-off' : '' ?>">
      <div class="bk-item-main">
        <h2><?= bk_e($m->LueFamilyName) ?> <?= bk_e($m->LueName) ?></h2>
        <p class="bk-meta">
          <span><?= bk_e($m->LueCode) ?></span>
          <span>né(e) le <?= bk_e(bk_date_fr($m->LueCtrlCode)) ?></span>
          <span><?= bk_e($m->LueCoDescr) ?></span>
        </p>
      </div>
      <div class="bk-item-act">
        <?php if ($inscrit): ?>
          <p class="bk-tag bk-tag-on">Déjà inscrit</p>
        <?php elseif ($geo): ?>
          <p class="bk-blocked"><?= bk_e($geo) ?></p>
        <?php else: ?>
          <form method="post" class="bk-inline">
            <?= bk_csrf_field() ?>
            <input type="hidden" name="t" value="<?= intval($tourId) ?>">
            <input type="hidden" name="q" value="<?= bk_e($search) ?>">
            <input type="hidden" name="go" value="1">
            <input type="hidden" name="licence" value="<?= bk_e($m->LueCode) ?>">
            <select name="division" required>
              <?php foreach ($divs as $k => $lab): ?>
                <option value="<?= bk_e($k) ?>"><?= bk_e($lab) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="session" required>
              <?php foreach ($sessions as $s):
                $left = max(0, intval($s->Places) - intval($s->Pris)); ?>
                <option value="<?= intval($s->SesOrder) ?>" <?= $left === 0 ? 'disabled' : '' ?>>
                  Départ <?= intval($s->SesOrder) ?> (<?= $left ?>)</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="bk-btn bk-btn-primary">Inscrire</button>
          </form>
          <p class="bk-hint">La catégorie est déduite de l'âge, du sexe et de l'arme choisie.</p>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="bk-hint">Seuls les 60 premiers archers sont affichés — affinez la recherche si besoin.</p>
<?php endif; ?>
<?php bk_foot(); ?>
