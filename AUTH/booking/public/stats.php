<?php
/**
 * booking/public/stats.php — statistiques du compétiteur.
 *
 * Toutes les compétitions de CE serveur où l'archer (sa licence) a participé,
 * SAUF celles en « Aucune publication » (niveau 1 — privées à l'organisateur) :
 * si une compétition n'est pas publiée au calendrier, ses scores n'ont pas à
 * figurer dans les statistiques. Données non officielles : avertissement en tête.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';

$archer = bk_require_archer();
$labels = bk_disc_labels();

$rs = safe_r_sql("SELECT t.ToId, t.ToName, t.ToWhere, t.ToWhenFrom, t.ToWhenTo,
            t.ToType, t.ToTypeName, t.ToTypeSubRule,
            e.EnDivision, e.EnClass, d.DivDescription, cl.ClDescription,
            q.QuScore, q.QuClRank
    FROM Entries e
    INNER JOIN Tournament t ON t.ToId = e.EnTournament
    LEFT  JOIN Qualifications q ON q.QuId = e.EnId
    LEFT  JOIN Divisions d  ON d.DivTournament = t.ToId AND d.DivId = e.EnDivision
    LEFT  JOIN Classes cl   ON cl.ClTournament = t.ToId AND cl.ClId = e.EnClass
    LEFT  JOIN BK_Competitions o ON o.BcTournament = t.ToId
    WHERE e.EnCode = " . StrSafe_DB($archer->BaLicence) . " AND e.EnAthlete = 1
      AND COALESCE(o.BcPublishLevel, 2) <> 1
    ORDER BY t.ToWhenFrom DESC, t.ToId DESC");

$rows = array();
$comps = array();          // ToId distincts
$best = null;
$today = date('Y-m-d');    // comparaison de chaînes AAAA-MM-JJ (sûr vis-à-vis du fuseau)
while ($r = safe_fetch($rs)) {
    // Les statistiques ne concernent que des compétitions EN COURS ou PASSÉES :
    // on écarte celles pas encore déroulées (date de début postérieure à aujourd'hui).
    if (substr((string) $r->ToWhenFrom, 0, 10) > $today) continue;
    $rows[] = $r;
    $comps[intval($r->ToId)] = true;
    $sc = intval($r->QuScore);
    if ($sc > 0 && ($best === null || $sc > $best)) $best = $sc;
}

bk_head('Mes statistiques');
?>
<style>
#bk .bk-stat-note { margin:0 0 16px; padding:11px 14px; font-size:13px; border-radius:8px;
    background:#fdf0e6; border:1px solid #cb8137; color:#8a5a26; }
#bk .bk-stat-sum { display:flex; flex-wrap:wrap; gap:12px; margin:0 0 16px; }
#bk .bk-stat-card { flex:1 1 140px; background:#fff; border:1px solid #d2d4d6; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:12px 14px; text-align:center; }
#bk .bk-stat-n { font-size:24px; font-weight:700; color:#01367c; }
#bk .bk-stat-l { font-size:12px; color:#7d8183; margin-top:2px; }
#bk .bk-stat-list { display:flex; flex-direction:column; gap:8px; }
#bk .bk-stat-row { display:flex; flex-wrap:wrap; gap:6px 14px; align-items:baseline;
    background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:10px 14px; }
#bk .bk-stat-ic { color:#0254a8; display:inline-flex; align-items:center; flex:0 0 auto; }
#bk .bk-stat-main { flex:1 1 240px; min-width:0; }
#bk .bk-stat-name { font-weight:600; color:#20263d; }
#bk .bk-stat-meta { font-size:12px; color:#7d8183; margin-top:2px; }
#bk .bk-stat-cat { display:inline-block; padding:2px 8px; border-radius:5px; font-size:12px;
    background:#f0f4ff; border:1px solid #a7d6ff; color:#01367c; }
#bk .bk-stat-res { flex:0 0 auto; text-align:right; }
#bk .bk-stat-score { font-size:18px; font-weight:700; color:#01367c; }
#bk .bk-stat-rank { font-size:12px; color:#7d8183; }
</style>


<div class="bk-stat-note">
  <b>⚠ Données non officielles.</b> Ces informations proviennent uniquement de ce serveur.
  Tous les scores ne remontent pas à la FFTA, et des compétitions de test peuvent exister —
  celles signalées comme telles par l'organisateur ne sont pas comptées ici. Pour vos résultats
  officiels, référez-vous à la FFTA.
</div>

<?php if (!$rows): ?>
  <p class="bk-empty">Aucune participation enregistrée sur ce serveur pour votre licence.
     <a href="<?= bk_e(bk_public_url('calendar.php')) ?>">Voir les compétitions ouvertes</a>.</p>
<?php else: ?>
  <div class="bk-stat-sum">
    <div class="bk-stat-card"><div class="bk-stat-n"><?= count($comps) ?></div>
      <div class="bk-stat-l">compétition<?= count($comps) > 1 ? 's' : '' ?></div></div>
    <div class="bk-stat-card"><div class="bk-stat-n"><?= count($rows) ?></div>
      <div class="bk-stat-l">participation<?= count($rows) > 1 ? 's' : '' ?></div></div>
    <div class="bk-stat-card"><div class="bk-stat-n"><?= $best !== null ? intval($best) : '—' ?></div>
      <div class="bk-stat-l">meilleur score</div></div>
  </div>

  <div class="bk-stat-list">
    <?php foreach ($rows as $r):
        $dd = bk_comp_discipline($r->ToType, $r->ToTypeSubRule, $r->ToTypeName);
        $disc = ($labels[$dd['key']] ?? '') . ($dd['para'] ? ' — Para' : '');
        $cat = trim(($r->DivDescription ?: $r->EnDivision) . ' / ' . ($r->ClDescription ?: $r->EnClass), ' /');
        $sc = intval($r->QuScore); $rk = intval($r->QuClRank); ?>
      <div class="bk-stat-row">
        <span class="bk-stat-ic"><?= bk_disc_icon($dd['key'], 22) ?><?= $dd['para'] ? bk_disc_icon_para(14) : '' ?></span>
        <div class="bk-stat-main">
          <div class="bk-stat-name"><?= bk_e($r->ToName) ?></div>
          <div class="bk-stat-meta">
            <?= bk_e(bk_date_range($r->ToWhenFrom, $r->ToWhenTo)) ?>
            <?php if ($disc !== ''): ?>· <?= bk_e($disc) ?><?php endif; ?>
            <?php if ($r->ToWhere): ?>· <?= bk_e($r->ToWhere) ?><?php endif; ?>
          </div>
          <?php if ($cat !== ''): ?><span class="bk-stat-cat"><?= bk_e($cat) ?></span><?php endif; ?>
        </div>
        <div class="bk-stat-res">
          <div class="bk-stat-score"><?= $sc > 0 ? $sc : '—' ?></div>
          <div class="bk-stat-rank"><?= $rk > 0 ? 'classé ' . $rk . '<sup>e</sup>' : 'non classé' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="bk-hint" style="margin-top:12px">Score et classement de qualification, lus sur ce serveur.
     Une compétition non encore tirée ou non saisie apparaît sans score.</p>
<?php endif; ?>
<?php bk_foot(); ?>
