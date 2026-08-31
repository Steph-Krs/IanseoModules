<?php
/**
 * public/registrations.php — « Mes inscriptions » : consultation et annulation.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/targets.php';
require_once dirname(__DIR__) . '/lib/shop.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_mandate_visible

$archer = bk_require_archer();

$err = '';
$ok  = !empty($_GET['ok']) ? 'Votre inscription a bien été enregistrée.' : '';

// Confirmation d'inscription : montant + moyens de paiement de la compétition tout
// juste validée, affichés en évidence (au moment où l'archer les attend).
$okDue = null; $okPay = array(); $okSubject = null;
$okTour = intval($_GET['t'] ?? 0);
if (!empty($_GET['ok']) && $okTour > 0) {
    // Inscription de groupe : ?s=<licence> désigne le camarade inscrit. On ne
    // montre les infos d'un tiers qu'après avoir REVÉRIFIÉ que c'est bien un
    // camarade de club de l'archer connecté (défense contre un ?s= forgé).
    $okSubjectLic = bk_clean_licence($_GET['s'] ?? '');
    if ($okSubjectLic !== '' && $okSubjectLic !== bk_clean_licence($archer->BaLicence)) {
        $selfLue = bk_lookup_licence($archer->BaLicence);
        if ($selfLue) $okSubject = bk_lookup_clubmate($okSubjectLic, $selfLue->LueCountry);
    }
    $okLic = $okSubject ? $okSubjectLic : $archer->BaLicence;
    $okDue = bk_due_total($okTour, $okLic);
    if ($okDue['total'] > 0 && !bk_payment_is_paid($okTour, $okLic)) {
        $okPay = bk_payinfo_get(bk_comp_config($okTour));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — merci de réessayer.';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $enid = intval($_POST['enid'] ?? 0);
        // Retenir la compétition AVANT suppression : ensuite la ligne n'existe plus.
        $rsT = safe_r_sql("SELECT BrTournament FROM BK_Registrations WHERE BrEnId = $enid");
        $rT  = safe_fetch($rsT);
        $res = bk_unregister($enid, $archer->BaId, $archer->BaLicence);
        if (!empty($res['ok'])) {
            bk_log('REG_CANCEL', $archer->BaLicence);
            if ($rT) bk_replan_all(intval($rT->BrTournament), bk_comp_config(intval($rT->BrTournament)));
            $ok = 'Votre inscription a été annulée.';
        } else {
            $err = $res['msg'] ?? "L'annulation a échoué.";
        }
    }
}

$regs = bk_my_registrations($archer->BaLicence);
// Inscriptions que l'archer a faites POUR des camarades de son club (groupe).
$authored = bk_authored_registrations($archer->BaId, $archer->BaLicence);

bk_head('Mes inscriptions');
?>
<?php if ($ok && $okDue && $okDue['total'] > 0): ?>
  <div class="bk-confirm">
    <p><b>Inscription enregistrée<?= $okSubject ? ' pour ' . bk_e(trim($okSubject->LueFamilyName . ' ' . $okSubject->LueName)) : '' ?>.</b>
       Montant à régler : <b><?= bk_e(number_format($okDue['total'], 2, ',', ' ')) ?> €</b>
       <span class="bk-hint">(paiement à valider par l'organisateur).</span></p>
    <?php if ($okPay): ?>
      <p class="bk-confirm-h">Moyens de paiement :</p>
      <ul>
        <?php foreach ($okPay as $pi): ?>
          <li><?= bk_e($pi['label']) ?> <span class="bk-hint">(<?= bk_e($pi['whenLabel']) ?>)</span><?= $pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '' ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php elseif ($ok): ?>
  <?= bk_msg('ok', $ok) ?>
<?php endif; ?>
<?php if (!empty($_GET['ok']) && $okTour > 0): ?>
  <p class="bk-confirm-share"><a class="bk-btn bk-btn-primary" href="<?= bk_e(bk_public_url('share.php?t=' . $okTour)) ?>">📣 Partager ma participation sur les réseaux</a></p>
<?php endif; ?>
<?= $err ? bk_msg('err', $err) : '' ?>

<?php if (!$regs && !$authored): ?>
  <p class="bk-empty">Vous n'avez aucune inscription.
     <a href="<?= bk_e(bk_public_url('calendar.php')) ?>">Voir les compétitions ouvertes</a>.</p>
<?php endif; ?>

<?php if ($authored): ?>
  <div class="bk-tabs" id="bk-tabs" role="tablist">
    <button type="button" class="bk-tab on" data-tab="mine">Mes inscriptions</button>
    <button type="button" class="bk-tab" data-tab="club">Mon club <span class="bk-tab-count"><?= count($authored) ?></span></button>
  </div>
<?php endif; ?>

<div class="bk-tabpanel" data-panel="mine">
<?php if ($regs): ?>
  <p style="margin:0 0 12px">
    <a class="bk-btn" href="<?= bk_e(bk_public_url('calendar-ics.php')) ?>">📅 Ajouter à mon agenda</a>
    <span class="bk-hint">Fichier .ics à importer dans l'agenda de votre téléphone.</span>
  </p>
<?php endif; ?>
<?php if (!$regs && $authored): ?>
  <p class="bk-empty">Vous n'avez aucune inscription personnelle. Vos inscriptions pour d'autres
     licenciés sont dans l'onglet « Mon club ».</p>
<?php endif; ?>

<?php if ($regs):
  // Regroupement par compétition : un bk-item par compétition, un sous-bloc par départ.
  $groups = array();
  foreach ($regs as $r) {
      $t = intval($r->BrTournament);
      if (!isset($groups[$t])) $groups[$t] = array('c' => $r, 'regs' => array());
      $groups[$t]['regs'][] = $r;
  }
  // Disciplines présentes, pour le filtre.
  $labels = bk_disc_labels();
  $discList = array();
  foreach ($groups as $g0) {
      $dd0 = bk_comp_discipline($g0['c']->ToType, $g0['c']->ToTypeSubRule, $g0['c']->ToTypeName);
      $discList[$dd0['key']] = $labels[$dd0['key']] ?? $dd0['key'];
  }
  asort($discList);
?>
  <div class="bk-reg-filters" id="bk-regfilters">
    <div class="bk-rf-status">
      <button type="button" class="bk-rf-btn on" data-past="all">Toutes</button>
      <button type="button" class="bk-rf-btn" data-past="0">À venir</button>
      <button type="button" class="bk-rf-btn" data-past="1">Passées</button>
    </div>
    <?php if (count($discList) > 1): ?>
      <label class="bk-rf-disc">Discipline
        <select id="bk-rf-discsel">
          <option value="all">Toutes</option>
          <?php foreach ($discList as $dk => $dlab): ?><option value="<?= bk_e($dk) ?>"><?= bk_e($dlab) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
  </div>
  <div class="bk-list">
  <?php foreach ($groups as $t => $g): $c = $g['c']; $nb = count($g['regs']);
      $due  = bk_due_total($t, $archer->BaLicence);
      $paid = bk_payment_is_paid($t, $archer->BaLicence);
      $free = $due['total'] <= 0;
      $pay  = (!$free && !$paid) ? bk_payinfo_get(bk_comp_config($t)) : array();
      $ddG  = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
      $declRow = (!$free && !$paid) ? bk_payment_get($t, $archer->BaLicence) : null;
      $declM = $declRow ? (string) $declRow->PyDeclMethod : '';
      $declW = $declRow ? (string) $declRow->PyDeclWhen : '';
      $pastG = bk_is_finished($c->ToWhenTo) ? 1 : 0; ?>
    <article class="bk-item" data-past="<?= $pastG ?>" data-disc="<?= bk_e($ddG['key']) ?>">
      <div class="bk-item-main">
        <h2 class="bk-item-h"><span class="bk-item-ic"><?= bk_disc_icon($ddG['key'], 24) ?></span><?= bk_e($c->ToName) ?></h2>
        <p class="bk-meta">
          <span><?= bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) ?></span>
          <?php if ($c->ToWhere): ?><span><?= bk_e($c->ToWhere) ?></span><?php endif; ?>
          <span class="bk-code"><?= $nb ?> départ<?= $nb > 1 ? 's' : '' ?></span>
        </p>

        <div class="bk-reg-list">
        <?php foreach ($g['regs'] as $r): ?>
          <div class="bk-reg">
            <p class="bk-tags">
              <span class="bk-tag"><?= bk_e($r->DivDescription ?: $r->EnDivision) ?></span>
              <span class="bk-tag"><?= bk_e($r->ClDescription ?: $r->EnClass) ?></span>
              <span class="bk-tag">Départ <?= intval($r->QuSession) ?></span>
              <?php if (isset($r->BrValidated) && intval($r->BrValidated) === 0): ?>
                <span class="bk-tag bk-tag-wait">En attente de validation</span>
              <?php elseif (!empty($r->BcShowAssignment) && intval($r->QuTarget) > 0): ?>
                <span class="bk-tag bk-tag-on">Cible <?= intval($r->QuTarget) ?><?= bk_e($r->QuLetter) ?></span>
              <?php elseif (!empty($r->BcShowAssignment)): ?>
                <span class="bk-tag">Cible non attribuée</span>
              <?php endif; ?>
              <?php if ($r->BrByRole === 'MANAGER'): ?>
                <span class="bk-tag">Inscrit par le club</span>
              <?php elseif ($r->BrByRole === 'CLUB'): ?>
                <span class="bk-tag">Inscrit par un membre de votre club</span>
              <?php elseif ($r->BrByRole === 'IMPORT'): ?>
                <span class="bk-tag">Saisie par l'organisateur</span>
              <?php endif; ?>
            </p>
            <?php if (trim((string) $r->BrRequest) !== ''): ?>
              <p class="bk-org">Demande : <?= bk_e($r->BrRequest) ?></p>
            <?php endif; ?>
            <div class="bk-reg-act">
              <?php if (!empty($r->BcAllowScoresheet)): ?>
                <a class="bk-btn" href="<?= bk_e(bk_public_url('scoresheet-official.php?enid=' . intval($r->BrEnId))) ?>" target="_blank" rel="noopener">Feuille de marque</a>
              <?php endif; ?>
              <?php if (!empty($c->BcIsOpen) && $r->BrByRole !== 'IMPORT'): ?>
                <form method="post" onsubmit="return confirm('Annuler ce départ ?')">
                  <?= bk_csrf_field() ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="enid" value="<?= intval($r->BrEnId) ?>">
                  <button type="submit" class="bk-btn bk-btn-danger">Annuler ce départ</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <?php if ($pay): ?>
        <div class="bk-payinfo">
          <b>Moyens de paiement</b>
          <ul>
            <?php foreach ($pay as $pi):
              $chosen = ($declM !== '' && $pi['m'] === $declM && ($pi['when'] === 'both' || $pi['when'] === $declW)); ?>
              <li class="<?= $chosen ? 'bk-pay-chosen' : '' ?>"><?= bk_e($pi['label']) ?>
                <span class="bk-hint">(<?= bk_e($pi['whenLabel']) ?>)</span><?= $pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '' ?>
                <?php if ($chosen): ?><span class="bk-pay-badge">✓ votre choix<?= $declW ? ' — ' . ($declW === 'before' ? 'avant' : 'sur place') : '' ?></span><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($declM !== '' && !array_filter($pay, function ($pi) use ($declM, $declW) { return $pi['m'] === $declM && ($pi['when'] === 'both' || $pi['when'] === $declW); })): ?>
            <p class="bk-hint">Votre choix : <b><?= bk_e(bk_payment_decl_label($declM, $declW)) ?></b></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="bk-item-act">
        <?php if (!$free): ?>
          <p class="bk-due">Montant : <b><?= bk_e(number_format($due['total'], 2, ',', ' ')) ?> €</b>
            <?php if ($paid): ?><span class="bk-tag bk-tag-on">paiement validé</span>
            <?php else: ?><span class="bk-due-wait">paiement non validé</span><?php endif; ?></p>
        <?php endif; ?>
        <?php if ($free || $paid): ?>
          <p><a class="bk-btn bk-btn-primary" href="<?= bk_e(bk_public_url('receipt.php?comp=' . $t)) ?>">Reçu</a></p>
        <?php endif; ?>
        <?php if (bk_shop_has_items($t)): ?>
          <p><a class="bk-btn" href="<?= bk_e(bk_public_url('shop.php?t=' . $t)) ?>">Boutique</a></p>
        <?php endif; ?>
        <?php if (bk_docs_list($c, $t) || bk_dossard_available($c, $t)): ?>
          <p><a class="bk-btn" href="<?= bk_e(bk_public_url('documents.php?t=' . $t)) ?>">📄 Documents</a></p>
        <?php endif; ?>
        <p><a class="bk-btn" href="<?= bk_e(bk_public_url('share.php?t=' . $t)) ?>">📣 Partager ma participation</a></p>
        <?php if (!empty($c->BcIsOpen)): ?>
          <p><a class="bk-btn" href="<?= bk_e(bk_public_url('register-comp.php?t=' . $t)) ?>">Ajouter une inscription</a></p>
        <?php else: ?>
          <p class="bk-hint">Inscriptions closes : contactez l'organisateur pour toute modification.</p>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="bk-empty" id="bk-reg-none" hidden>Aucune inscription ne correspond à ces filtres.</p>
  <script>
  (function () {
    var f = document.getElementById('bk-regfilters'); if (!f) return;
    var items = document.querySelectorAll('#bk .bk-list .bk-item');
    var none = document.getElementById('bk-reg-none');
    var status = 'all', disc = 'all';
    function apply() {
      var shown = 0;
      Array.prototype.forEach.call(items, function (a) {
        var ok = (status === 'all' || a.getAttribute('data-past') === status)
              && (disc === 'all' || a.getAttribute('data-disc') === disc);
        a.style.display = ok ? '' : 'none'; if (ok) shown++;
      });
      if (none) none.hidden = shown > 0;
    }
    Array.prototype.forEach.call(f.querySelectorAll('.bk-rf-btn'), function (b) {
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(f.querySelectorAll('.bk-rf-btn'), function (x) { x.classList.remove('on'); });
        b.classList.add('on'); status = b.getAttribute('data-past'); apply();
      });
    });
    var sel = document.getElementById('bk-rf-discsel');
    if (sel) sel.addEventListener('change', function () { disc = this.value; apply(); });
  })();
  </script>
<?php endif; ?>
</div><!-- /panel mine -->

<?php if ($authored):
  // Inscriptions faites pour des camarades de club — regroupées par compétition.
  $ag = array();
  foreach ($authored as $r) {
      $t = intval($r->BrTournament);
      if (!isset($ag[$t])) $ag[$t] = array('c' => $r, 'regs' => array());
      $ag[$t]['regs'][] = $r;
  } ?>
  <div class="bk-tabpanel" data-panel="club" hidden>
  <section class="bk-authored">
    <p class="bk-hint" style="margin-top:0">Inscriptions que vous avez enregistrées pour des licenciés
       de votre club. Chaque licencié les retrouve aussi dans son propre espace.</p>
    <div class="bk-authored-list">
    <?php foreach ($ag as $t => $g): $c = $g['c'];
        $ddC = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
        $payC = bk_payinfo_get(bk_comp_config($t));   // moyens acceptés (niveau compétition)
        $anyUnpaid = false; ?>
      <article class="bk-item">
        <div class="bk-item-main">
          <h3 class="bk-item-h"><span class="bk-item-ic"><?= bk_disc_icon($ddC['key'], 22) ?></span><?= bk_e($c->ToName) ?></h3>
          <p class="bk-meta">
            <span><?= bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) ?></span>
            <?php if ($c->ToWhere): ?><span><?= bk_e($c->ToWhere) ?></span><?php endif; ?>
          </p>
          <div class="bk-reg-list">
          <?php foreach ($g['regs'] as $r):
            $dueA  = bk_due_total($t, $r->BrLicence);
            $pyA   = bk_payment_get($t, $r->BrLicence);
            $paidA = $pyA && intval($pyA->PyPaid) === 1;
            $declA = $pyA ? bk_payment_decl_label($pyA->PyDeclMethod ?? '', $pyA->PyDeclWhen ?? '') : '';
            if ($dueA['total'] > 0 && !$paidA) $anyUnpaid = true; ?>
            <div class="bk-reg">
              <p class="bk-tags">
                <span class="bk-tag bk-tag-on"><?= bk_e(trim($r->EnFirstName . ' ' . $r->EnName)) ?></span>
                <span class="bk-tag"><?= bk_e($r->EnCode) ?></span>
                <span class="bk-tag"><?= bk_e($r->DivDescription ?: $r->EnDivision) ?></span>
                <span class="bk-tag"><?= bk_e($r->ClDescription ?: $r->EnClass) ?></span>
                <span class="bk-tag">Départ <?= intval($r->QuSession) ?></span>
                <?php if (isset($r->BrValidated) && intval($r->BrValidated) === 0): ?>
                  <span class="bk-tag bk-tag-wait">En attente de validation</span>
                <?php elseif (!empty($r->BcShowAssignment) && intval($r->QuTarget) > 0): ?>
                  <span class="bk-tag bk-tag-on">Cible <?= intval($r->QuTarget) ?><?= bk_e($r->QuLetter) ?></span>
                <?php endif; ?>
              </p>
              <?php if ($dueA['total'] > 0): ?>
                <p class="bk-org">Montant : <b><?= bk_e(number_format($dueA['total'], 2, ',', ' ')) ?> €</b>
                  <?php if ($paidA): ?><span class="bk-tag bk-tag-on">paiement validé</span>
                  <?php else: ?><span class="bk-due-wait">paiement non validé</span><?php endif; ?>
                  <?php if ($declA): ?>&nbsp;·&nbsp; Choix : <b><?= bk_e($declA) ?></b><?php endif; ?></p>
              <?php endif; ?>
              <?php if (!empty($r->BcAllowScoresheet) || !empty($c->BcIsOpen)): ?>
                <div class="bk-reg-act">
                  <?php if (!empty($r->BcAllowScoresheet)): ?>
                    <a class="bk-btn" href="<?= bk_e(bk_public_url('scoresheet-official.php?enid=' . intval($r->BrEnId))) ?>" target="_blank" rel="noopener">Feuille de marque</a>
                  <?php endif; ?>
                  <?php if (!empty($c->BcIsOpen)): ?>
                    <form method="post" onsubmit="return confirm('Annuler cette inscription ?')">
                      <?= bk_csrf_field() ?>
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="enid" value="<?= intval($r->BrEnId) ?>">
                      <button type="submit" class="bk-btn bk-btn-danger">Annuler</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>

          <?php if ($payC && $anyUnpaid): ?>
          <div class="bk-payinfo">
            <b>Moyens de paiement</b>
            <ul>
              <?php foreach ($payC as $pi): ?>
                <li><?= bk_e($pi['label']) ?> <span class="bk-hint">(<?= bk_e($pi['whenLabel']) ?>)</span><?= $pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '' ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </section>
  </div><!-- /panel club -->

  <script>
  (function () {
    var tabs = document.getElementById('bk-tabs'); if (!tabs) return;
    var panels = document.querySelectorAll('#bk .bk-tabpanel');
    Array.prototype.forEach.call(tabs.querySelectorAll('.bk-tab'), function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-tab');
        Array.prototype.forEach.call(tabs.querySelectorAll('.bk-tab'), function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        Array.prototype.forEach.call(panels, function (p) { p.hidden = (p.getAttribute('data-panel') !== key); });
      });
    });
  })();
  </script>
<?php endif; ?>

<script>
// Cartes de compétition dépliables : repliées par défaut (titre + dates seuls),
// clic sur l'en-tête pour tout afficher. Individuel par compétition.
(function () {
  Array.prototype.forEach.call(document.querySelectorAll('#bk .bk-item'), function (it) {
    var h = it.querySelector('.bk-item-h'); if (!h) return;
    it.classList.add('bk-collapsible');
    var tog = document.createElement('span');
    tog.className = 'bk-item-toggle'; tog.setAttribute('aria-hidden', 'true'); tog.textContent = '⌄';
    h.appendChild(tog);
    function toggle() { it.classList.toggle('bk-open'); }
    h.addEventListener('click', toggle);
    var meta = it.querySelector('.bk-meta');
    if (meta) { meta.style.cursor = 'pointer'; meta.addEventListener('click', toggle); }
  });
})();
</script>
<?php bk_foot(); ?>
