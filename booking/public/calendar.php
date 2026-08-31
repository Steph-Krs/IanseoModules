<?php
/**
 * public/calendar.php — calendrier des compétitions ouvertes, en GRILLE mensuelle.
 *
 * Densité maîtrisée par un filtre d'entrée : discipline (pictogramme) + région
 * (par défaut celle du licencié). La grille ne montre que ce qu'un organisateur
 * a explicitement ouvert (bk_comp_calendar) ; l'éligibilité reste revérifiée à
 * l'inscription (cet écran informe, il n'autorise pas).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';

$archer = bk_require_archer();

// Région du licencié (2 premiers chiffres de l'agrément de son club), relue
// dans le fichier des licences (il a pu changer de club).
$club = $archer->BaClubCode;
$q = safe_r_sql("SELECT LueCountry FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . " ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) $club = $r->LueCountry;
$archerRegion = strtoupper(substr((string) $club, 0, 2));

$facets = bk_comp_facets();
$labels = bk_disc_labels();

// Filtres actifs
$disc   = (string) ($_GET['disc'] ?? '');
if ($disc !== '' && $disc !== 'para' && !isset($facets['disc'][$disc])) $disc = '';

// Région : le choix est mémorisé pour la prochaine visite (cookie). Défaut = région
// du licencié TANT QU'aucun choix n'a été fait ici. Sentinelle 'ALL' = « Toutes »
// (distincte de « pas encore choisi ») pour que « Toutes » soit mémorisable — sinon
// il retombait sur la région du licencié dès qu'une compétition y était ouverte.
$rcookie = 'bk_cal_region';
if (isset($_GET['region'])) {
    $raw = strtoupper(trim((string) $_GET['region']));
    $region = ($raw === 'ALL' || $raw === '') ? '' : substr(preg_replace('/[^0-9A-Za-z]/', '', $raw), 0, 2);
    @setcookie($rcookie, ($region === '' ? 'ALL' : $region), time() + 31536000, $CFG->ROOT_DIR ?: '/');
    $_COOKIE[$rcookie] = $region === '' ? 'ALL' : $region;
} elseif (!empty($_COOKIE[$rcookie])) {
    $raw = strtoupper(substr((string) $_COOKIE[$rcookie], 0, 3));
    $region = ($raw === 'ALL') ? '' : substr($raw, 0, 2);
} else {
    $region = $archerRegion;
    // Premier passage, région sans aucune compétition ouverte → Toutes (pas de vide muet).
    if ($region !== '' && !isset($facets['regions'][$region])) $region = '';
}
$regionParam = $region === '' ? 'ALL' : $region;   // valeur transmise dans les URLs

// Compétitions correspondant aux filtres (toutes dates), triées par date
$comps = bk_comp_calendar(array('disc' => $disc, 'region' => $region));

// Compétitions déjà réservées par cet archer (pastille sur la tuile)
$deja = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) $deja[intval($r->BrTournament)] = true;

// Mois affiché : mois courant, ou le mois de la 1re compétition à venir
$today = date('Y-m-d');
$ym = date('Y-m');
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month'])) {
    $ym = $_GET['month'];
} else {
    foreach ($comps as $c) {
        if (substr($c->ToWhenTo, 0, 10) >= $today) {
            $m = substr($c->ToWhenFrom, 0, 7);
            if ($m > $ym) $ym = $m;
            break;
        }
    }
}
$firstTs     = strtotime($ym . '-01');
$daysInMonth = (int) date('t', $firstTs);
$leading     = ((int) date('N', $firstTs)) - 1;     // 0 = lundi
$moisFr = array(1=>'janvier','février','mars','avril','mai','juin','juillet','août',
    'septembre','octobre','novembre','décembre');

// Répartition des compétitions par jour du mois (comparaison de chaînes AAAA-MM-JJ)
$byDay = array();
foreach ($comps as $c) {
    $from = substr($c->ToWhenFrom, 0, 10);
    $to   = substr($c->ToWhenTo, 0, 10);
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $day = sprintf('%s-%02d', $ym, $d);
        if ($day >= $from && $day <= $to) $byDay[$d][] = $c;
    }
}
$moisAffiche = 0;
foreach ($byDay as $l) $moisAffiche += count($l);   // >0 = au moins une compétition ce mois-ci

// Rendu en GRILLE DE CASES + barres superposées : une compétition multi-jours
// s'étend de sa 1re à sa dernière case. Chaque ligne est complète (lundi→dimanche) :
// les jours des mois voisins sont affichés (semi-transparents) pour compléter la ligne.
// cellDate : date Y-m-d à la position 0-based de la grille (arithmétique de dates,
// robuste au changement d'heure — jamais d'ajout de 86400 s).
$totalCells = $leading + $daysInMonth;
$weeks = (int) ceil($totalCells / 7);
$cellDate = function ($pos) use ($ym, $leading) {
    $off = $pos - $leading;
    return date('Y-m-d', strtotime("$ym-01 " . ($off >= 0 ? '+' : '') . $off . ' days'));
};

/** Conserve les filtres en changeant un paramètre. */
function bk_cal_url($over = array())
{
    global $disc, $regionParam, $ym;
    $p = array('disc' => $disc, 'month' => $ym);
    foreach ($over as $k => $v) if ($k !== 'region') $p[$k] = $v;
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    // La région est TOUJOURS transmise (sentinelle 'ALL' = Toutes) pour être mémorisable.
    $reg = array_key_exists('region', $over) ? (string) $over['region'] : $regionParam;
    $p['region'] = $reg === '' ? 'ALL' : $reg;
    return bk_public_url('calendar.php') . '?' . http_build_query($p);
}

$prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
$nextYm = date('Y-m', strtotime($ym . '-01 +1 month'));

bk_head('Compétitions');
?>
<div class="bk-cal-filters">
  <div class="bk-cal-disc">
    <a class="bk-dchip<?= $disc === '' ? ' on' : '' ?>" href="<?= bk_e(bk_cal_url(array('disc' => ''))) ?>">Toutes</a>
    <?php foreach ($labels as $k => $lab): if (empty($facets['disc'][$k])) continue; ?>
      <a class="bk-dchip<?= $disc === $k ? ' on' : '' ?>" href="<?= bk_e(bk_cal_url(array('disc' => $k))) ?>">
        <?= bk_disc_icon($k, 18) ?><span><?= bk_e($lab) ?></span></a>
    <?php endforeach; ?>
    <?php if (!empty($facets['para'])): ?>
      <a class="bk-dchip<?= $disc === 'para' ? ' on' : '' ?>" href="<?= bk_e(bk_cal_url(array('disc' => 'para'))) ?>">
        <?= bk_disc_icon_para(18) ?><span>Para</span></a>
    <?php endif; ?>
  </div>
  <label class="bk-cal-region">Région
    <select onchange="location.href=this.value">
      <option value="<?= bk_e(bk_cal_url(array('region' => 'ALL'))) ?>" <?= $region === '' ? 'selected' : '' ?>>Toutes</option>
      <?php if ($region !== '' && !isset($facets['regions'][$region])): ?>
        <option value="<?= bk_e(bk_cal_url(array('region' => $region))) ?>" selected>
          <?= bk_e($region . ' — ' . bk_region_name($region)) ?> (0)</option>
      <?php endif; ?>
      <?php foreach ($facets['regions'] as $code => $n): ?>
        <option value="<?= bk_e(bk_cal_url(array('region' => $code))) ?>" <?= $region === $code ? 'selected' : '' ?>>
          <?= bk_e($code . ' — ' . bk_region_name($code)) ?> (<?= $n ?>)</option>
      <?php endforeach; ?>
    </select>
  </label>
</div>

<div class="bk-cal-nav">
  <a class="bk-btn" href="<?= bk_e(bk_cal_url(array('month' => $prevYm))) ?>">← <?= bk_e(ucfirst($moisFr[(int) date('n', strtotime($prevYm . '-01'))])) ?></a>
  <b class="bk-cal-title"><span class="bk-cal-year"><?= bk_e(substr($ym, 0, 4)) ?></span><?= bk_e(ucfirst($moisFr[(int) date('n', $firstTs)])) ?></b>
  <a class="bk-btn" href="<?= bk_e(bk_cal_url(array('month' => $nextYm))) ?>"><?= bk_e(ucfirst($moisFr[(int) date('n', strtotime($nextYm . '-01'))])) ?> →</a>
</div>

<?php if (!$comps): ?>
  <p class="bk-empty">Aucune compétition ne correspond à ces filtres. Élargissez la région ou
     la discipline.</p>
<?php else: ?>
  <?php if (!$moisAffiche): ?>
    <p class="bk-hint">Rien ce mois-ci pour ces filtres — utilisez les flèches pour naviguer.</p>
  <?php endif; ?>
  <div class="bk-cal-month">
    <div class="bk-cal-dows">
      <?php foreach (array('Lun','Mar','Mer','Jeu','Ven','Sam','Dim') as $j): ?>
        <div class="bk-cal-dow"><?= $j ?></div>
      <?php endforeach; ?>
    </div>
    <?php for ($w = 0; $w < $weeks; $w++):
        // Dates des 7 colonnes de la semaine (mois voisins compris).
        $colDate = array();
        for ($col = 1; $col <= 7; $col++) $colDate[$col] = $cellDate($w * 7 + ($col - 1));
        // Segments de compétition présents dans cette semaine (bornés à ses 7 cases).
        $segs = array();
        foreach ($comps as $c) {
            $from = substr($c->ToWhenFrom, 0, 10);
            $to   = substr($c->ToWhenTo, 0, 10);
            $sc = null; $ec = null;
            for ($col = 1; $col <= 7; $col++) {
                $d = $colDate[$col];
                if ($d >= $from && $d <= $to) { if ($sc === null) $sc = $col; $ec = $col; }
            }
            if ($sc === null) continue;
            $segs[] = array('c' => $c, 'sc' => $sc, 'len' => $ec - $sc + 1,
                'contl' => ($from < $colDate[$sc]), 'contr' => ($to > $colDate[$ec]));
        }
        // Pistes (empilage sans chevauchement de colonnes) : détermine la hauteur des cases.
        usort($segs, function ($a, $b) { return $a['sc'] - $b['sc']; });
        $lanes = array();
        foreach ($segs as &$sg) {
            $end = $sg['sc'] + $sg['len'] - 1; $put = null;
            foreach ($lanes as $li => $occ) {
                $ok = true;
                foreach ($occ as $r) if (!($sg['sc'] > $r[1] || $end < $r[0])) { $ok = false; break; }
                if ($ok) { $put = $li; break; }
            }
            if ($put === null) { $put = count($lanes); $lanes[$put] = array(); }
            $lanes[$put][] = array($sg['sc'], $end); $sg['lane'] = $put;
        }
        unset($sg);
        $laneCount = count($lanes); ?>
      <div class="bk-cal-week" style="--lanes:<?= intval($laneCount) ?>">
        <div class="bk-cal-cells">
          <?php for ($col = 1; $col <= 7; $col++):
              $d = $colDate[$col];
              $inMonth = (substr($d, 0, 7) === $ym);
              $we = in_array((int) date('N', strtotime($d)), array(6, 7));
              $cls = ($inMonth ? '' : ' bk-cal-other') . ($d === $today ? ' bk-cal-today' : ($we ? ' bk-cal-we' : '')); ?>
            <div class="bk-cal-cell<?= $cls ?>"><span class="bk-cal-num"><?= (int) substr($d, 8, 2) ?></span></div>
          <?php endfor; ?>
        </div>
        <?php if ($segs): ?>
        <div class="bk-cal-overlay">
          <?php foreach ($segs as $sg): $c = $sg['c'];
              $dd  = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
              $lab = $sg['len'] > 1 ? $c->ToName : ($c->ToWhere ?: $c->ToName); ?>
            <a class="bk-cal-comp bk-cal-bar<?= $sg['len'] === 1 ? ' bk-cal-single' : '' ?><?= isset($deja[intval($c->ToId)]) ? ' bk-cal-in' : '' ?><?= $sg['contl'] ? ' bk-cal-contl' : '' ?><?= $sg['contr'] ? ' bk-cal-contr' : '' ?>"
               style="grid-column: <?= intval($sg['sc']) ?> / span <?= intval($sg['len']) ?>; grid-row: <?= intval($sg['lane']) + 1 ?>"
               href="<?= bk_e(bk_public_url('competition.php?t=' . intval($c->ToId))) ?>"
               title="<?= bk_e($c->ToName . ' — ' . $c->ToWhere) ?>">
              <span class="bk-cal-ic"><?= bk_disc_icon($dd['key'], 16) ?><?= $dd['para'] ? bk_disc_icon_para(12) : '' ?></span>
              <span class="bk-cal-loc"><?= bk_e($lab) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
  <p class="bk-hint">Cliquez une compétition pour voir les départs, les places restantes et vous inscrire.
     <?php if ($region !== '' || $disc !== ''): ?>
       <a href="<?= bk_e(bk_cal_url(array('disc' => '', 'region' => ''))) ?>">Voir toutes les disciplines et régions</a>.
     <?php endif; ?></p>
<?php endif; ?>

<div id="bk-preview" class="bk-modal" hidden>
  <div class="bk-modal-backdrop" data-close></div>
  <div class="bk-modal-sheet" role="dialog" aria-modal="true" aria-label="Détail de la compétition">
    <button type="button" class="bk-modal-x" data-close aria-label="Fermer">×</button>
    <div class="bk-modal-body"></div>
    <div class="bk-modal-hint" aria-hidden="true"><span>défiler pour voir plus ⌄</span></div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('bk-preview');
  if (!modal || !window.fetch) return;                 // repli : les tuiles restent des liens
  var body = modal.querySelector('.bk-modal-body'),
      sheet = modal.querySelector('.bk-modal-sheet'), last = null;
  var LOAD = '<p class="bk-hint">Chargement…</p>';
  // Indice de défilement : montre « défiler pour voir plus » tant qu'il reste du
  // contenu sous la zone visible ; masqué une fois en bas.
  function updateHint() {
    var more = sheet.scrollHeight - sheet.clientHeight - sheet.scrollTop > 8;
    sheet.classList.toggle('bk-can-scroll', more);
  }
  sheet.addEventListener('scroll', updateHint);
  window.addEventListener('resize', updateHint);
  function open()  { last = document.activeElement; body.innerHTML = LOAD; modal.hidden = false;
                     document.body.classList.add('bk-modal-open'); }
  function close() { modal.hidden = true; document.body.classList.remove('bk-modal-open');
                     body.innerHTML = ''; sheet.classList.remove('bk-can-scroll'); if (last && last.focus) last.focus(); }
  modal.addEventListener('click', function (e) { if (e.target.hasAttribute('data-close')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
  Array.prototype.forEach.call(document.querySelectorAll('.bk-cal-comp'), function (a) {
    a.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;   // nouvel onglet : laisser passer
      e.preventDefault();
      var url = a.getAttribute('href'), sep = url.indexOf('?') >= 0 ? '&' : '?';
      open();
      fetch(url + sep + 'embed=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { if (!r.ok) throw 0; return r.text(); })
        .then(function (html) { body.innerHTML = html; sheet.scrollTop = 0; updateHint(); })
        .catch(function () { window.location.href = url; });                 // repli : page complète
    });
  });
})();
</script>
<?php bk_foot(); ?>
