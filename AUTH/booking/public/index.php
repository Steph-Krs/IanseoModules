<?php
/**
 * public/index.php — tableau de bord du licencié connecté.
 *
 * M1 : identité, club de rattachement, accès aux prochaines briques. Le
 * calendrier des compétitions ouvertes et les inscriptions arrivent en M2/M3.
 */
require_once __DIR__ . '/boot.php';

$archer = bk_require_archer();

// Club courant : relu dans la base licenciés plutôt que recopié du compte —
// un archer peut changer de club entre deux saisons.
$club = $archer->BaClubCode;
$clubName = '';
$q = safe_r_sql("SELECT LueCountry, LueCoDescr FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . "
    ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) {
    $club     = $r->LueCountry;
    $clubName = $r->LueCoDescr;
}

bk_head('Mon espace');
?>
<h1>Bonjour <?= bk_e($archer->BaName) ?></h1>

<div class="bk-grid">
  <section class="bk-block">
    <h2>Ma licence</h2>
    <dl class="bk-dl">
      <dt>Licence</dt><dd><?= bk_e($archer->BaLicence) ?></dd>
      <dt>Nom</dt><dd><?= bk_e($archer->BaFamilyName) ?></dd>
      <dt>Prénom</dt><dd><?= bk_e($archer->BaName) ?></dd>
      <dt>Club</dt><dd><?= bk_e($clubName ?: '—') ?>
        <?= $club ? '<span class="bk-code">' . bk_e($club) . '</span>' : '' ?></dd>
    </dl>
    <p class="bk-actions">
      <a class="bk-btn" href="<?= bk_e(bk_public_url('licence.php')) ?>" target="_blank" rel="noopener">📄 Mon attestation de licence</a>
      <a class="bk-btn" href="<?= bk_e(bk_public_url('security.php')) ?>"><?= !empty($archer->BaTotpEnabled) ? '🔒 Sécurité (2FA active)' : '🔒 Sécurité' ?></a>
    </p>
  </section>

  <section class="bk-block">
    <h2>Mes inscriptions</h2>
    <?php
    require_once dirname(__DIR__) . '/lib/registration.php';
    $mes = bk_my_registrations($archer->BaLicence);
    ?>
    <?php if ($mes): ?>
      <ul class="bk-mini">
        <?php foreach (array_slice($mes, 0, 4) as $r): ?>
          <li><b><?= bk_e($r->ToName) ?></b><br>
            <span class="bk-hint"><?= bk_e(bk_date_range($r->ToWhenFrom, $r->ToWhenTo)) ?>
              — départ <?= intval($r->QuSession) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p><a class="bk-btn" href="<?= bk_e(bk_public_url('registrations.php')) ?>">Toutes mes inscriptions</a></p>
    <?php else: ?>
      <p class="bk-empty">Aucune inscription pour le moment.</p>
    <?php endif; ?>
  </section>

  <section class="bk-block">
    <h2>Compétitions ouvertes</h2>
    <?php
    require_once dirname(__DIR__) . '/lib/competition.php';
    $ouvertes = bk_comp_calendar();
    $n = count($ouvertes);
    ?>
    <?php if ($n): ?>
      <p><?= $n ?> compétition<?= $n > 1 ? 's' : '' ?> ouverte<?= $n > 1 ? 's' : '' ?> aux inscriptions.</p>
      <p><a class="bk-btn bk-btn-primary" href="<?= bk_e(bk_public_url('calendar.php')) ?>">Voir le calendrier</a></p>
    <?php else: ?>
      <p class="bk-empty">Aucune compétition n'est ouverte aux inscriptions pour le moment.</p>
    <?php endif; ?>
  </section>

  <section class="bk-block">
    <h2>Mon suivi</h2>
    <p class="bk-hint">Vos performances au fil des compétitions<?= bk_is_manager() ? ', et la gestion de votre club' : '' ?>.</p>
    <p class="bk-actions">
      <a class="bk-btn" href="<?= bk_e(bk_public_url('stats.php')) ?>">📊 Mes statistiques</a>
      <a class="bk-btn" href="<?= bk_e(bk_public_url('registrations.php')) ?>">🗒️ Mes inscriptions</a>
      <?php if (bk_is_manager()): ?>
        <a class="bk-btn" href="<?= bk_e(bk_public_url('club.php')) ?>">👥 Mon club</a>
      <?php endif; ?>
    </p>
  </section>

  <section class="bk-block" id="bk-news" style="display:none">
    <h2>Actualités</h2>
    <p class="bk-hint" style="margin-top:0">Les dernières nouvelles de la fédération.</p>
    <ul class="bk-news-list" id="bk-news-list"></ul>
    <p><a class="bk-btn" href="https://www.ffta.fr/actualites" target="_blank" rel="noopener">Toutes les actualités ↗</a></p>
  </section>
</div>
<script>
/* Actualités FFTA : chargées en asynchrone (aucun appel réseau au rendu de la page).
   Titres injectés via textContent (jamais de HTML du flux). La section reste masquée
   si le flux est indisponible. */
(function () {
  fetch(<?= json_encode(bk_public_url('news.php')) ?>, { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d || !d.items || !d.items.length) return;
      var ul = document.getElementById('bk-news-list');
      d.items.forEach(function (it) {
        if (!it.link || !/^https?:\/\//i.test(it.link)) return;
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = it.link; a.target = '_blank'; a.rel = 'noopener';
        a.textContent = it.title || it.link;
        li.appendChild(a);
        if (it.date) {
          var s = document.createElement('span');
          s.className = 'bk-news-date';
          s.textContent = it.date;
          li.appendChild(s);
        }
        ul.appendChild(li);
      });
      document.getElementById('bk-news').style.display = '';
    })
    .catch(function () {});
})();
</script>
<?php bk_foot(); ?>
