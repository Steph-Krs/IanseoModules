<?php
/**
 * public/register-comp.php — inscription d'un licencié à une compétition.
 *
 * Toutes les règles sont revérifiées ICI, côté serveur, au moment de l'écriture :
 * le calendrier informe, il n'autorise pas. Une page publique ne peut faire
 * confiance à rien de ce que le navigateur renvoie.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/caps.php';
require_once dirname(__DIR__) . '/lib/targets.php';
require_once dirname(__DIR__) . '/lib/documents.php';   // bk_doc_distances
require_once dirname(__DIR__) . '/lib/payment.php';     // moyens de paiement

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? $_POST['t'] ?? 0);
$cfg    = bk_comp_config($tourId);

// Une compétition non ouverte n'est pas inscriptible, même par URL directe.
if (!$tourId || empty($cfg->BcIsOpen)) {
    bk_head('Inscription', 'card');
    echo '<div class="bk-card"><h1>Inscription impossible</h1>'
       . bk_msg('err', "Les inscriptions ne sont pas ouvertes pour cette compétition.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">Retour au calendrier</a></p></div>';
    bk_foot();
    exit;
}

$rs = safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo, ToType FROM Tournament WHERE ToId = $tourId");
$tour = safe_fetch($rs);

// Compétition terminée : inscription impossible même si la fenêtre est restée ouverte.
if ($tour && bk_is_finished($tour->ToWhenTo)) {
    bk_head('Inscription', 'card');
    echo '<div class="bk-card"><h1>Inscription impossible</h1>'
       . bk_msg('err', "Cette compétition est terminée : les inscriptions ne sont plus possibles.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">Retour au calendrier</a></p></div>';
    bk_foot();
    exit;
}

// Identité fédérale de l'archer CONNECTÉ : source de son club (qui borne
// l'inscription de groupe) et de sa majorité (seul un majeur inscrit un tiers).
$selfLue = bk_lookup_licence($archer->BaLicence);
if (!$selfLue) {
    bk_head('Inscription', 'card');
    echo '<div class="bk-card"><h1>Inscription impossible</h1>'
       . bk_msg('err', "Votre licence n'est pas connue de ce serveur. Contactez l'organisateur.")
       . '</div>';
    bk_foot();
    exit;
}

// Inscription de groupe : un licencié MAJEUR peut inscrire un camarade de SON
// club en saisissant son numéro de licence. Le SUJET de l'inscription est par
// défaut l'archer connecté (mode « self ») ; il devient le camarade résolu
// (mode « club ») dès qu'une licence valide de son club est fournie. Tout le
// reste de la page travaille sur $lue (le sujet) et $subjectLicence.
$canGroup   = bk_is_major($selfLue->LueCtrlCode);
$groupMode  = false;
$lue        = $selfLue;
$clubErr    = '';
$reqSubject = bk_clean_licence($_POST['subject'] ?? $_GET['subject'] ?? '');
if ($reqSubject !== '' && $reqSubject !== bk_clean_licence($archer->BaLicence)) {
    if (!$canGroup) {
        $clubErr = "Seul un licencié majeur peut inscrire un autre licencié de son club.";
    } else {
        $mate = bk_lookup_clubmate($reqSubject, $selfLue->LueCountry);
        if ($mate) { $groupMode = true; $lue = $mate; }
        else $clubErr = "Licence inconnue, ou cet archer n'appartient pas à votre club.";
    }
}
$subjectLicence = bk_clean_licence($lue->LueCode);

// Camarades déjà inscrits par cet archer et TOUJOURS dans son club (raccourci de
// l'inscription de groupe). Re-vérifie le club : un archer ayant changé de club
// n'y figure plus.
$mates = $canGroup ? bk_authored_clubmates($archer->BaId, $archer->BaLicence, $selfLue->LueCountry) : array();

$divisions = bk_reg_divisions($tourId);
$division  = (string) ($_POST['division'] ?? $_GET['division'] ?? '');
if ($division === '' || !isset($divisions[$division])) $division = (string) array_key_first($divisions);

$classes = $division !== '' ? bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $division) : array();
$class   = (string) ($_POST['class'] ?? '');
if ($class === '' || !isset($classes[$class])) $class = (string) array_key_first($classes);

$sessions = bk_comp_sessions($tourId);
$sessionOrder = intval($_POST['session'] ?? 0);
$request = trim((string) ($_POST['request'] ?? ''));

// Blasons réellement possibles pour cette catégorie, d'après la configuration
// de la compétition — jamais une liste libre. On récupère aussi les tailles de
// blason (cm) pour les afficher sous la catégorie.
$facesDispo = array();
$faceSizes  = array();
if ($division !== '' && $class !== '') {
    $fi = bk_with_tournament($tourId, function () use ($tourId, $division, $class) {
        $raw = bk_caps_faces_for($tourId, $division, $class);
        $sizes = array();
        foreach ($raw as $f) if (intval($f['cm']) > 0) $sizes[intval($f['cm'])] = true;
        krsort($sizes);
        return array('choices' => bk_caps_face_choices($tourId, $division, $class, $raw ?: null),
                     'sizes' => array_keys($sizes));
    });
    $facesDispo = $fi['choices'];
    $faceSizes  = $fi['sizes'];
}

// Distances applicables à la catégorie (permet de vérifier TAE I / TAE N).
// On ne garde que les distances DISTINCTES (une compétition déclare souvent le
// même mètre en deux blocs D1/D2).
$catDists = ($division !== '' && $class !== '' && !empty($tour->ToType))
    ? bk_doc_distances($tourId, $tour->ToType, $division, $class) : array();
$catMetres = array();
foreach ($catDists as $d) if (intval($d['metres']) > 0) $catMetres[intval($d['metres'])] = true;
krsort($catMetres);
$catMetres = array_keys($catMetres);

// Jauge SPÉCIFIQUE : places restantes PAR DÉPART pour ce PROFIL (arme + catégorie +
// blason choisi), en plus de la jauge globale. 0 ⇒ le départ est complet pour ce profil
// et l'inscription y sera refusée (contrôle d'admission, cohabitation des blasons).
$curFace = 0;
if ($facesDispo) {
    $want = intval($_POST['face'] ?? 0);
    $curFace = ($want && isset($facesDispo[$want])) ? $want : intval(array_key_first($facesDispo));
}
$profileLeft = array();
if ($division !== '' && $class !== '' && $curFace > 0 && function_exists('bk_profile_remaining')) {
    $profileLeft = bk_with_tournament($tourId, function () use ($tourId, $sessions, $division, $class, $curFace) {
        $out = array();
        foreach ($sessions as $s) {
            $r = bk_profile_remaining($tourId, intval($s->SesOrder), $division, $class, $curFace);
            $out[intval($s->SesOrder)] = ($r === null) ? null : intval($r);
        }
        return $out;
    });
}

// Inscriptions déjà prises par cet archer sur cette compétition : pour proposer
// un départ supplémentaire et annoncer l'effet sur la participation aux épreuves.
$dejaMoi = bk_reg_existing($tourId, $subjectLicence);
$dejaSessions = array();
$armesAvecEpreuve = array();
foreach ($dejaMoi as $d) {
    $dejaSessions[intval($d->QuSession)] = true;
    $armesAvecEpreuve[$d->EnDivision] = true;
}

// Tarification : provenance et rang sont fixes pour cette inscription (le club et
// le nombre d'inscriptions déjà prises ne dépendent pas des choix du formulaire) ;
// seuls catégorie et départ font varier le prix, recalculés en direct côté client.
$pricing  = bk_pricing_get($cfg);
$provTier = bk_prov_tier($pricing, $lue->LueCountry);
$nextRank = count($dejaMoi) + 1;
$provDelta = $provTier === 'dept' ? (float) $pricing['prov']['dept']
           : ($provTier === 'region' ? (float) $pricing['prov']['region'] : 0.0);
$rankDelta = 0.0; $rankTh = 0;
foreach ($pricing['rank'] as $th => $d) {
    $th = (int) $th;
    if ($nextRank >= $th && $th > $rankTh) { $rankTh = $th; $rankDelta = (float) $d; }
}
$calc = bk_price_calc($cfg->BcFee, $pricing, $division, $class, $sessionOrder, $provTier, $nextRank);
$showPrice = ((float) $cfg->BcFee > 0) || bk_pricing_is_advanced($pricing);

// Choix de paiement proposés au compétiteur (moyen + quand), s'il y a un tarif.
$payChoices = array(); $payDecl = '';
if ($showPrice) {
    $payChoices = bk_payinfo_choices(bk_payinfo_get($cfg));
    if ($payChoices) {
        $pRow = bk_payment_get($tourId, $subjectLicence);
        if ($pRow && $pRow->PyDeclMethod !== '') $payDecl = $pRow->PyDeclMethod . '|' . $pRow->PyDeclWhen;
    }
}

/** Montant € (français). $signed : préfixe +/− explicite (ajustements). */
function bk_eur($n, $signed = false)
{
    $s = $n < 0 ? '−' : ($signed ? '+' : '');
    return $s . number_format(abs((float) $n), 2, ',', ' ') . ' €';
}

// Camarades de club déjà inscrits, pour la demande « sur la même cible que… ».
$camarades = array();
if ($lue->LueCountry) {
    $rs = safe_r_sql("SELECT e.EnCode, e.EnFirstName, e.EnName, q.QuSession
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        INNER JOIN Countries c ON c.CoId = e.EnCountry
        WHERE e.EnTournament = " . intval($tourId) . " AND e.EnAthlete = 1
          AND c.CoCode = " . StrSafe_DB($lue->LueCountry) . "
          AND e.EnCode <> " . StrSafe_DB($subjectLicence) . "
        ORDER BY e.EnFirstName, e.EnName");
    while ($r = safe_fetch($rs)) $camarades[] = $r;
}

// Lettres disponibles sur ce départ (A, B, C… selon SesAth4Target).
$lettres = array();
foreach ($sessions as $s) {
    if (intval($s->SesOrder) === $sessionOrder || (!$sessionOrder && !$lettres)) {
        for ($i = 0; $i < intval($s->SesAth4Target); $i++) $lettres[] = chr(65 + $i);
    }
}

$err = '';
$geo = bk_comp_archer_blocked($cfg, $lue->LueCountry);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['go'] ?? '') === '1') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — merci de réessayer.';
    } elseif ($class === '' || $division === '') {
        $err = "Choisissez une arme et une catégorie.";
    } elseif ((string) ($_POST['class'] ?? '') !== $class) {
        // La catégorie postée n'est pas dans la liste autorisée : on ne la
        // remplace pas en silence, on le dit (POST forgé ou formulaire périmé).
        $err = "Cette catégorie ne correspond pas à votre âge pour cette arme.";
    } elseif ($groupMode && !$canGroup) {
        // Défense côté écriture : un POST forgé ne doit pas contourner la majorité.
        $err = "Seul un licencié majeur peut inscrire un autre licencié de son club.";
    } else {
        $err = bk_reg_blocked($tourId, $cfg, $subjectLicence, $lue->LueCountry,
            $division, $class, $sessionOrder, $lue);
        if ($err === '') {
            $res = bk_register($tourId, $lue, $division, $class, $sessionOrder, $request, array(
                'role'   => $groupMode ? 'CLUB' : 'SELF',
                'who'    => $archer->BaLicence,   // auteur de l'inscription
                'archer' => $archer->BaId,
            ), array(
                'face'   => intval($_POST['face'] ?? 0),
                'letter' => (string) ($_POST['letter'] ?? ''),
                'with'   => (string) ($_POST['with'] ?? ''),
            ));
            if (!empty($res['ok'])) {
                bk_log('REG_NEW', $subjectLicence);
                // Moyen de paiement souhaité, attribué au SUJET (montant dû de sa
                // fiche) — informe l'organisateur, même pour une inscription de groupe.
                if (!empty($_POST['pay_choice'])) {
                    $pc = explode('|', (string) $_POST['pay_choice'], 2);
                    if (count($pc) === 2) bk_payment_declare($tourId, $subjectLicence, $pc[0], $pc[1]);
                }
                // Placement seulement si l'inscription est validée (auto). En mode
                // validation manuelle, l'affectation attend la validation de l'orga.
                if (!empty($res['validated'])) {
                    bk_replan_session($tourId, $sessionOrder, $cfg);
                }
                bk_redirect('registrations.php?ok=1&t=' . $tourId
                    . ($groupMode ? '&s=' . rawurlencode($subjectLicence) : ''));
            }
            $err = $res['msg'] ?? "L'inscription n'a pas pu être enregistrée.";
        }
    }
}

bk_head('Inscription');
?>

<div class="bk-block" style="margin-bottom:16px">
  <h2><?= bk_e($tour->ToName) ?></h2>
  <p class="bk-meta">
    <span><?= bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) ?></span>
    <?php if ($tour->ToWhere): ?><span><?= bk_e($tour->ToWhere) ?></span><?php endif; ?>
  </p>
</div>

<?php if ($geo !== ''): ?>
  <?= bk_msg('err', $geo) ?>
  <?php bk_foot(); exit; ?>
<?php endif; ?>

<?= $err ? bk_msg('err', $err) : '' ?>
<?= $clubErr ? bk_msg('err', $clubErr) : '' ?>

<div class="bk-block">
  <?php if ($groupMode): ?>
    <h2>Inscription d'un licencié de votre club</h2>
  <?php else: ?>
    <h2>Vos informations</h2>
  <?php endif; ?>
  <dl class="bk-dl">
    <dt>Licence</dt><dd><?= bk_e($lue->LueCode) ?></dd>
    <dt>Nom</dt><dd><?= bk_e($lue->LueFamilyName) ?> <?= bk_e($lue->LueName) ?></dd>
    <dt>Né(e) le</dt><dd><?= bk_e(bk_date_fr($lue->LueCtrlCode)) ?></dd>
    <dt>Club</dt><dd><?= bk_e($lue->LueCoDescr) ?></dd>
  </dl>
  <?php if ($groupMode): ?>
    <p class="bk-hint">Vous inscrivez ce licencié de votre club (vous en êtes l'auteur, licence
       <?= bk_e($archer->BaLicence) ?>). Ces informations proviennent du fichier des licences.</p>
    <p><a class="bk-btn" href="<?= bk_e(bk_public_url('register-comp.php?t=' . $tourId)) ?>">← Revenir à ma propre inscription</a></p>
  <?php else: ?>
    <p class="bk-hint">Ces informations proviennent du fichier des licences et ne sont pas
       modifiables ici.</p>
  <?php endif; ?>

  <?php if ($canGroup): ?>
    <details class="bk-group-switch" <?= ($clubErr && !$groupMode) ? 'open' : '' ?>>
      <summary><?= $groupMode ? 'Inscrire un autre licencié de mon club' : 'Inscrire un licencié de mon club' ?></summary>
      <div class="bk-group-body">
        <?php if ($mates): ?>
          <label for="matesel">Un licencié déjà inscrit par vous</label>
          <select id="matesel" data-base="<?= bk_e(bk_public_url('register-comp.php?t=' . $tourId . '&subject=')) ?>"
                  onchange="if(this.value){location.href=this.getAttribute('data-base')+encodeURIComponent(this.value);}">
            <option value="">— choisir —</option>
            <?php foreach ($mates as $lic => $nom): ?>
              <option value="<?= bk_e($lic) ?>" <?= ($groupMode && $lic === $subjectLicence) ? 'selected' : '' ?>>
                <?= bk_e($nom) ?> (<?= bk_e($lic) ?>)</option>
            <?php endforeach; ?>
          </select>
          <p class="bk-hint" style="margin-bottom:8px">ou saisissez un nouveau numéro de licence :</p>
        <?php else: ?>
          <p class="bk-hint">Saisissez le numéro de licence d'un archer de <b>votre club</b> pour
             l'inscrire à sa place. Une licence inconnue ou d'un autre club est refusée.</p>
        <?php endif; ?>
        <form method="get" action="<?= bk_e(bk_public_url('register-comp.php')) ?>" class="bk-group-form">
          <input type="hidden" name="t" value="<?= intval($tourId) ?>">
          <label for="subjlic">Numéro de licence</label>
          <input type="text" id="subjlic" name="subject" placeholder="Ex. <?= bk_e($archer->BaLicence) ?>"
                 autocomplete="off" required>
          <button type="submit" class="bk-btn bk-btn-primary">Continuer</button>
        </form>
      </div>
    </details>
  <?php endif; ?>
</div>

<?php if (intval($lue->LueStatus) === 9): /* licence sans pratique : pas d'inscription pour ce sujet */ ?>
<div class="bk-block" style="margin-top:14px">
  <h2><?= $groupMode ? bk_e(trim($lue->LueFamilyName . ' ' . $lue->LueName)) : 'Votre inscription' ?></h2>
  <p class="bk-blocked"><?= $groupMode
      ? "Cette licence est « <b>sans pratique</b> » : ce licencié ne peut pas être inscrit à une compétition."
      : "Votre licence est « <b>sans pratique</b> » (dirigeant) : vous ne pouvez pas vous inscrire à une compétition. Vous pouvez en revanche <b>inscrire les licenciés de votre club</b> à l'aide de « Inscrire un licencié de mon club » ci-dessus." ?></p>
</div>
<?php else: ?>
<form method="post" class="bk-block" style="margin-top:14px" id="bkreg">
  <?= bk_csrf_field() ?>
  <input type="hidden" name="t" value="<?= intval($tourId) ?>">
  <input type="hidden" name="go" value="1">
  <?php if ($groupMode): ?><input type="hidden" name="subject" value="<?= bk_e($subjectLicence) ?>"><?php endif; ?>

  <h2><?= $groupMode ? 'Son inscription (' . bk_e(trim($lue->LueFamilyName . ' ' . $lue->LueName)) . ')' : 'Votre inscription' ?></h2>

  <label for="division">Arme</label>
  <select id="division" name="division" onchange="this.form.go.value='0';this.form.submit()">
    <?php foreach ($divisions as $k => $lab): ?>
      <option value="<?= bk_e($k) ?>" <?= $division === $k ? 'selected' : '' ?>><?= bk_e($lab) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="class">Catégorie</label>
  <?php if (!$classes): ?>
    <p class="bk-blocked">Aucune catégorie ne correspond à votre âge pour cette arme.</p>
  <?php else: ?>
    <select id="class" name="class" onchange="this.form.go.value='0';this.form.submit()">
      <?php foreach ($classes as $k => $lab): ?>
        <option value="<?= bk_e($k) ?>" <?= $class === $k ? 'selected' : '' ?>><?= bk_e($lab) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="bk-hint">Déterminée par votre année de naissance et votre sexe.</p>
    <?php if ($catMetres || $faceSizes): ?>
      <p class="bk-catinfo">
        <?php if ($catMetres): ?><b>Distance :</b>
          <?= bk_e(implode(' / ', array_map(function ($m) { return $m . ' m'; }, $catMetres))) ?><?php endif; ?>
        <?php if ($faceSizes): ?><?= $catMetres ? ' &nbsp;—&nbsp; ' : '' ?><b>Blason :</b>
          <?= bk_e(implode(' / ', array_map(function ($cm) { return $cm . ' cm'; }, $faceSizes))) ?><?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (count($facesDispo) > 1): ?>
    <label for="face">Type de blason</label>
    <?php // Recharge le formulaire au changement : la jauge « places pour votre blason »
          // par départ dépend du blason choisi, elle doit être recalculée côté serveur. ?>
    <select id="face" name="face" onchange="this.form.go.value='0';this.form.submit()">
      <?php foreach ($facesDispo as $id => $lab): ?>
        <option value="<?= intval($id) ?>" <?= intval($_POST['face'] ?? 0) === intval($id) ? 'selected' : '' ?>>
          <?= bk_e($lab) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="bk-hint">Plusieurs types de blasons sont possibles pour votre catégorie — la taille, elle,
       dépend de la catégorie choisie ci-dessus. Les places restantes par départ se mettent à jour selon le blason.</p>
  <?php elseif ($facesDispo): $lab = reset($facesDispo); ?>
    <label>Blason</label>
    <p class="bk-fixed"><?= bk_e($lab) ?> <span class="bk-hint">(blason prévu pour votre catégorie)</span></p>
  <?php endif; ?>

  <label for="session">Départ</label>
  <?php $libres = 0; ?>
  <select id="session" name="session" required>
    <option value="">— choisir —</option>
    <?php foreach ($sessions as $s):
      $o = intval($s->SesOrder);
      $left = max(0, intval($s->Places) - intval($s->Pris));
      $pris = isset($dejaSessions[$o]);
      // Jauge spécifique : places pour CE profil. null = pas de contrainte connue.
      $pl = array_key_exists($o, $profileLeft) ? $profileLeft[$o] : null;
      $profFull = ($pl !== null && $pl < 1);
      $dispo = ($left > 0 && !$pris && !$profFull);
      if ($dispo) $libres++; ?>
      <option value="<?= $o ?>" <?= (!$dispo) ? 'disabled' : '' ?>
        <?= $sessionOrder === $o ? 'selected' : '' ?>>
        Départ <?= $o ?><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?>
        <?php $ss = bk_session_start($s); if ($ss !== ''): $sh = substr($ss, 11, 5); ?>
          (<?= bk_e(bk_date_fr($ss)) ?><?= ($sh !== '' && $sh !== '00:00') ? ' à ' . bk_e(str_replace(':', 'h', $sh)) : '' ?>)
        <?php endif; ?>
        — <?php
          if ($pris) echo 'déjà inscrit';
          elseif ($left === 0) echo 'complet';
          elseif ($profFull) echo 'complet pour votre blason';
          else {
            echo $left . ' place' . ($left > 1 ? 's' : '');
            if ($pl !== null) echo ' · ' . intval($pl) . ' pour votre blason';
          }
        ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if (!$libres): ?>
    <p class="bk-blocked">Aucun départ disponible : ils sont complets (y compris pour votre blason), ou vous y êtes déjà inscrit.</p>
  <?php else: ?>
    <p class="bk-hint">« pour votre blason » = places restantes compte tenu de votre catégorie et de votre
       blason (cohabitation des cibles). Un départ complet pour ce blason n'est pas sélectionnable.</p>
  <?php endif; ?>

  <?php if ($dejaMoi): ?>
    <div class="bk-note">
      <b>Tir supplémentaire</b> — <?= $groupMode ? 'ce licencié a' : 'vous avez' ?> déjà
      <?= count($dejaMoi) ?> inscription<?= count($dejaMoi) > 1 ? 's' : '' ?> sur cette compétition.
      <?php if (isset($armesAvecEpreuve[$division])): ?>
        Avec la même arme (<?= bk_e($divisions[$division] ?? $division) ?>), ce tir sera
        <b>hors épreuve</b> : seule votre première inscription compte au classement.
      <?php else: ?>
        Avec une arme différente, ce tir comptera pour sa propre épreuve.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($cfg->BcWishLetter || $cfg->BcWishWith || $cfg->BcWishFree): ?>
  <fieldset class="bk-wishes">
    <legend>Mes souhaits <span class="bk-opt">(facultatif)</span></legend>

    <?php if ($cfg->BcWishLetter): ?>
    <label for="letter">Position sur la cible</label>
    <select id="letter" name="letter">
      <option value="">Peu importe</option>
      <?php foreach ($lettres as $L): ?>
        <option value="<?= bk_e($L) ?>" <?= ($_POST['letter'] ?? '') === $L ? 'selected' : '' ?>><?= bk_e($L) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <?php if ($cfg->BcWishWith): ?>
    <label for="with">Sur la même cible que</label>
    <?php if ($camarades): ?>
      <select id="with" name="with">
        <option value="">Peu importe</option>
        <?php foreach ($camarades as $c): ?>
          <option value="<?= bk_e($c->EnCode) ?>" <?= ($_POST['with'] ?? '') === $c->EnCode ? 'selected' : '' ?>>
            <?= bk_e($c->EnFirstName . ' ' . $c->EnName) ?> (départ <?= intval($c->QuSession) ?>)</option>
        <?php endforeach; ?>
      </select>
      <p class="bk-hint">Uniquement des archers de votre club déjà inscrits. Le souhait n'est
         retenu que s'il reste compatible avec le règlement et le plan du terrain.</p>
    <?php else: ?>
      <p class="bk-fixed bk-hint">Aucun archer de votre club n'est encore inscrit.</p>
      <input type="hidden" name="with" value="">
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($cfg->BcWishFree): ?>
    <label for="request">Autre demande</label>
    <textarea id="request" name="request" rows="2" maxlength="2000"
              placeholder="Précision à transmettre à l'organisateur."><?= bk_e($request) ?></textarea>
    <p class="bk-hint">Ce champ libre est simplement transmis à l'organisateur.</p>
    <?php endif; ?>

    <?php if ($cfg->BcWishLetter || $cfg->BcWishWith): ?>
    <p class="bk-hint">Les souhaits de placement sont pris en compte automatiquement, dans la
       limite du règlement et du plan du terrain.</p>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <?php if ($showPrice): ?>
  <div class="bk-price" id="bk-price">
    <h3>Tarif</h3>
    <table class="bk-price-t"><tbody id="bk-price-lines">
      <?php foreach ($calc['lines'] as $i => $ln): ?>
        <tr><td><?= bk_e($ln['label']) ?></td>
            <td class="bk-price-num"><?= bk_e(bk_eur($ln['amount'], $i > 0)) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <p class="bk-price-tot">Total : <b id="bk-price-total"><?= bk_e(bk_eur($calc['total'])) ?></b></p>
    <p class="bk-hint">Estimation d'après vos choix. Le montant définitif figure sur votre reçu.</p>
  </div>
  <?php endif; ?>

  <?php if ($payChoices): ?>
  <fieldset class="bk-wishes">
    <legend>Paiement <span class="bk-opt">(facultatif)</span></legend>
    <p class="bk-hint">Indiquez comment et quand vous comptez régler — cela informe l'organisateur.
       Le règlement se fait selon ses modalités.</p>
    <label for="pay_choice">Moyen de paiement souhaité</label>
    <select id="pay_choice" name="pay_choice">
      <option value="">— je verrai plus tard —</option>
      <?php foreach ($payChoices as $pc): ?>
        <option value="<?= bk_e($pc['value']) ?>" <?= $payDecl === $pc['value'] ? 'selected' : '' ?>><?= bk_e($pc['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </fieldset>
  <?php endif; ?>

  <button type="submit" class="bk-btn bk-btn-primary" <?= (!$classes || !$libres) ? 'disabled' : '' ?>>
    <?= $groupMode ? 'Confirmer son inscription' : 'Confirmer mon inscription' ?></button>
</form>
<?php endif; /* fin licence sans pratique */ ?>

<?php if ($showPrice): ?>
<script>
var BK_PRICE = <?= json_encode(array(
    'base'      => (float) $cfg->BcFee,
    'cats'      => array_map(function ($c) {
                       return array('label' => $c['label'], 'div' => $c['div'],
                                    'cls' => $c['cls'], 'price' => (float) $c['price']);
                   }, $pricing['categories']),
    'deps'      => (object) $pricing['departures'],
    'prov'      => $provDelta,
    'provLabel' => $provTier === 'dept' ? 'Tarif départemental'
                 : ($provTier === 'region' ? 'Tarif régional' : ''),
    'rank'      => $rankDelta,
    'rankLabel' => $rankTh > 0 ? ($nextRank . 'ᵉ inscription') : '',
), JSON_UNESCAPED_UNICODE) ?>;
(function () {
  var f = document.getElementById('bkreg'); if (!f) return;
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function eur(n, signed){ var s = n < 0 ? '−' : (signed ? '+' : ''); return s + Math.abs(n).toFixed(2).replace('.', ',') + ' €'; }
  function calc() {
    var div = f['division'] ? f['division'].value : '',
        cls = f['class'] ? f['class'].value : '',
        ses = f['session'] ? f['session'].value : '';
    var base = BK_PRICE.base, label = 'Tarif de base';
    for (var i = 0; i < BK_PRICE.cats.length; i++) {
      var c = BK_PRICE.cats[i];
      var okD = !c.div.length || c.div.indexOf(div) >= 0;
      var okC = !c.cls.length || c.cls.indexOf(cls) >= 0;
      if (okD && okC) { base = c.price; label = 'Tarif' + (c.label ? ' ' + c.label : ' catégorie'); break; }
    }
    var lines = [[label, base, false]], total = base;
    if (ses && BK_PRICE.deps[ses] !== undefined) { lines.push(['Départ ' + ses, BK_PRICE.deps[ses], true]); total += BK_PRICE.deps[ses]; }
    if (BK_PRICE.prov) { lines.push([BK_PRICE.provLabel, BK_PRICE.prov, true]); total += BK_PRICE.prov; }
    if (BK_PRICE.rank) { lines.push([BK_PRICE.rankLabel, BK_PRICE.rank, true]); total += BK_PRICE.rank; }
    total = Math.max(0, total);
    var html = '';
    for (var j = 0; j < lines.length; j++) html += '<tr><td>' + esc(lines[j][0]) + '</td><td class="bk-price-num">' + eur(lines[j][1], lines[j][2]) + '</td></tr>';
    var body = document.getElementById('bk-price-lines'); if (body) body.innerHTML = html;
    var tot = document.getElementById('bk-price-total'); if (tot) tot.textContent = eur(total, false);
  }
  ['class', 'session'].forEach(function (n) { if (f[n]) f[n].addEventListener('change', calc); });
  calc();
})();
</script>
<?php endif; ?>
<?php bk_foot(); ?>
