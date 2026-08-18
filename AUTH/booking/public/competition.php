<?php
/**
 * public/competition.php — détail d'une compétition ouverte (depuis le calendrier).
 *
 * Nom, dates, discipline, organisateur, lieu, tarif, et les DÉPARTS avec leur
 * date/heure et le nombre de places restantes. Bouton d'inscription (le flux
 * d'inscription revérifie tout côté serveur : cet écran informe).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_mandate_visible

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? 0);
$embed  = !empty($_GET['embed']);           // fragment (preview) demandé par le calendrier
$c = $tourId ? bk_comp_one($tourId) : null;

if (!$c) {
    if ($embed) { echo bk_msg('err', "Cette compétition n'est pas (ou plus) ouverte aux inscriptions."); exit; }
    bk_head('Compétition', 'card');
    echo '<div class="bk-card"><h1>Compétition indisponible</h1>'
       . bk_msg('err', "Cette compétition n'est pas (ou plus) ouverte aux inscriptions.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">← Retour au calendrier</a></p></div>';
    bk_foot();
    exit;
}

// Club de l'archer (relu dans le fichier des licences) pour l'éligibilité.
$club = $archer->BaClubCode;
$q = safe_r_sql("SELECT LueCountry FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . " ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) $club = $r->LueCountry;

$blocked  = bk_comp_archer_blocked($c, $club);
$sessions = !empty($c->BcShowGauges) ? bk_comp_sessions($tourId) : bk_comp_sessions($tourId);
$dd       = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
$labels   = bk_disc_labels();

$dejaN = 0;
foreach (bk_my_registrations($archer->BaLicence) as $r) if (intval($r->BrTournament) === $tourId) $dejaN++;

/** Date + heure d'un départ (SesDtStart), '—' si absent. */
function bk_comp_dt($v)
{
    $v = trim((string) $v);
    if ($v === '' || strpos($v, '0000') === 0) return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) . ' à ' . date('H\hi', $ts) : '';
}

if (!$embed): bk_head($c->ToName); ?>
<p class="bk-back"><a href="<?= bk_e(bk_public_url('calendar.php')) ?>">← Retour au calendrier</a></p>
<?php endif; ?>

<div class="bk-detail">
  <div class="bk-detail-head">
    <span class="bk-detail-ic"><?= bk_disc_icon($dd['key'], 34) ?><?= $dd['para'] ? bk_disc_icon_para(18) : '' ?></span>
    <div>
      <h1><?= bk_e($c->ToName) ?></h1>
      <p class="bk-detail-sub">
        <span><?= bk_e(($labels[$dd['key']] ?? '') . ($dd['para'] ? ' — Para' : '')) ?></span>
        <span><?= bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) ?></span>
        <?php if ($c->ToWhere): ?><span><?= bk_e($c->ToWhere) ?></span><?php endif; ?>
      </p>
      <?php if ($c->ToComDescr): ?><p class="bk-org">Organisé par <?= bk_e($c->ToComDescr) ?></p><?php endif; ?>
    </div>
  </div>

  <?php if ($blocked): ?>
    <?= bk_msg('err', $blocked . ($c->BcRestrictTo ? ' Ouverture à tous le ' . bk_date_fr($c->BcRestrictTo) . '.' : '')) ?>
  <?php endif; ?>

  <h2>Départs</h2>
  <?php if (!$sessions): ?>
    <p class="bk-hint">Les départs ne sont pas encore configurés pour cette compétition.</p>
  <?php else: ?>
  <ul class="bk-dep-list">
    <?php foreach ($sessions as $s):
        $pl = intval($s->Places); $pr = intval($s->Pris);
        $reste = max(0, $pl - $pr);
        $pc = $pl > 0 ? min(100, round($pr * 100 / $pl)) : 0;
        $dt = bk_comp_dt(bk_session_start($s)); ?>
      <li class="bk-dep<?= $reste === 0 ? ' bk-dep-full' : '' ?>">
        <div class="bk-dep-main">
          <b>Départ <?= intval($s->SesOrder) ?><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?></b>
          <?php if ($dt): ?><span class="bk-dep-dt"><?= bk_e($dt) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($c->BcShowGauges)): ?>
        <div class="bk-dep-gauge">
          <span class="bk-gauge<?= $reste === 0 ? ' bk-gauge-full' : '' ?>"><i style="width:<?= $pc ?>%"></i></span>
          <span class="bk-dep-num"><?= $reste ?> place<?= $reste > 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="bk-detail-act">
    <?php
    $cp = bk_pricing_get($c);
    if (bk_pricing_is_advanced($cp)):
        $min = bk_price_min($c->BcFee, $cp); ?>
      <p class="bk-fee">Tarif : à partir de <?= bk_e(number_format($min, 2, ',', ' ')) ?> €
         <span class="bk-hint">(selon catégorie, départ, provenance…)</span></p>
    <?php elseif ((float) $c->BcFee > 0): ?>
      <p class="bk-fee">Tarif : <?= bk_e(number_format((float) $c->BcFee, 2, ',', ' ')) ?> €</p>
    <?php endif; ?>
    <?php if (bk_is_finished($c->ToWhenTo)): ?>
      <p class="bk-tag">Compétition terminée — les inscriptions sont closes.</p>
      <?php if ($dejaN > 0): ?>
        <a class="bk-btn" href="<?= bk_e(bk_public_url('registrations.php')) ?>">Mes inscriptions</a>
      <?php endif; ?>
    <?php elseif ($blocked): ?>
      <p class="bk-hint">Vous ne pouvez pas encore vous inscrire à cette compétition.</p>
    <?php elseif ($dejaN > 0): ?>
      <p class="bk-tag bk-tag-on">Déjà inscrit<?= $dejaN > 1 ? ' (' . $dejaN . ')' : '' ?></p>
      <a class="bk-btn bk-btn-primary" href="<?= bk_e(bk_public_url('register-comp.php?t=' . $tourId)) ?>">Ajouter une inscription</a>
      <a class="bk-btn" href="<?= bk_e(bk_public_url('registrations.php')) ?>">Mes inscriptions</a>
    <?php else: ?>
      <a class="bk-btn bk-btn-primary" href="<?= bk_e(bk_public_url('register-comp.php?t=' . $tourId)) ?>">Inscriptions</a>
    <?php endif; ?>
    <?php if (bk_docs_list($c, $tourId)): ?>
      <a class="bk-btn" href="<?= bk_e(bk_public_url('documents.php?t=' . $tourId)) ?>">📄 Documents de la compétition</a>
    <?php endif; ?>
  </div>
</div>
<?php if (!$embed) bk_foot(); ?>
