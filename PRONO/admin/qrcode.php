<?php
/**
 * Affiche à imprimer : QR code d'accès aux pronostics, titre et texte libres.
 * Le PDF reprend l'en-tête et le pied de page de compétition de ianseo
 * (Common/pdf/IanseoPdf.php) — logos, nom de l'épreuve, lieu et dates.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

require_once dirname(__DIR__) . '/lib/engine.php';

$tid  = intval($_SESSION['TourId']);
$root = $CFG->ROOT_DIR . 'Modules/Custom/PRONO/';
$msg  = '';

if (!prono_tables_exist()) prono_install_schema();
prono_migrate();
$cfg = prono_config($tid);

$url   = trim((string) ($_REQUEST['url']   ?? $cfg['PaCfPublicUrl']));
$title = trim((string) ($_REQUEST['title'] ?? $cfg['PaCfPosterTitle']));
$text  = (string) ($_REQUEST['text'] ?? ($cfg['PaCfPosterText'] ?? ''));
$mode  = ($_REQUEST['mode'] ?? 'poster') === 'flyers' ? 'flyers' : 'poster';

if ($title === '') $title = 'Pronostics de la compétition';
if ($text === '')  $text  = "Scanne ce code avec ton téléphone, choisis un pseudo et pronostique "
                          . "les duels et les qualifications.\n\n"
                          . "Rien à miser : tu marques des points quand tu vois juste, et un "
                          . "pronostic raté ne coûte rien. Classement en direct sur l'écran de la salle.";

// Enregistrement des réglages (le PDF les réutilise d'une fois sur l'autre)
if (($_POST['do'] ?? '') === 'save') {
    prono_q('UPDATE PRONO_Config SET PaCfPublicUrl = ?, PaCfPosterTitle = ?, PaCfPosterText = ?,
             PaCfUpdated = NOW() WHERE PaCfTournament = ?', [$url, $title, $text, $tid]);
    $msg = 'Réglages enregistrés.';
}

// ─── Génération du PDF ───────────────────────────────────────────────────────
if (($_REQUEST['pdf'] ?? '') === '1' && $url !== '') {
    require_once('Common/pdf/IanseoPdf.php');
    require_once('Common/tcpdf/tcpdf_barcodes_2d.php');

    $qrStyle = [
        'border'        => false,
        'vpadding'      => 0,
        'hpadding'      => 0,
        'fgcolor'       => [1, 54, 124],     // bleu foncé — contraste largement suffisant
        'bgcolor'       => false,
        'module_width'  => 1,
        'module_height' => 1,
    ];

    $pdf = new IanseoPdf($title);
    $pdf->startPageGroup();
    $pdf->AddPage();

    if ($mode === 'poster') {
        $w   = $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;
        $x0  = IanseoPdf::sideMargin;

        $pdf->ln(6);
        $pdf->SetFont($pdf->FontStd, 'B', 26);
        $pdf->MultiCell($w, 0, $title, 0, 'C');
        $pdf->ln(4);

        $qr = 105;
        $pdf->write2DBarcode($url, 'QRCODE,M', $x0 + ($w - $qr) / 2, $pdf->GetY(), $qr, $qr, $qrStyle);
        $pdf->SetY($pdf->GetY() + $qr + 5);

        $pdf->SetFont($pdf->FontStd, 'B', 12);
        $pdf->MultiCell($w, 0, $url, 0, 'C');
        $pdf->ln(6);

        $pdf->SetFont($pdf->FontStd, '', 13);
        $pdf->MultiCell($w, 0, $text, 0, 'C');
    } else {
        // 4 affichettes par page, à découper — pour poser sur les tables
        $mx = IanseoPdf::sideMargin;
        $top = $pdf->GetY();
        $availW = $pdf->getPageWidth() - 2 * $mx;
        $availH = $pdf->getPageHeight() - $top - IanseoPdf::bottomMargin - 6;
        $cw = $availW / 2;
        $ch = $availH / 2;

        for ($i = 0; $i < 4; $i++) {
            $cx = $mx + ($i % 2) * $cw;
            $cy = $top + intdiv($i, 2) * $ch;

            $pdf->Rect($cx + 2, $cy + 2, $cw - 4, $ch - 4, 'D',
                ['all' => ['width' => 0.2, 'color' => [180, 180, 180], 'dash' => 2]]);

            $pdf->SetXY($cx + 5, $cy + 6);
            $pdf->SetFont($pdf->FontStd, 'B', 13);
            $pdf->MultiCell($cw - 10, 0, $title, 0, 'C');

            $qr = min($cw - 30, $ch - 46);
            $pdf->write2DBarcode($url, 'QRCODE,M', $cx + ($cw - $qr) / 2, $pdf->GetY() + 2, $qr, $qr, $qrStyle);

            $pdf->SetXY($cx + 4, $cy + $ch - 16);
            $pdf->SetFont($pdf->FontStd, '', 8);
            $pdf->MultiCell($cw - 8, 0, $url, 0, 'C');
        }
    }

    $pdf->Output('pronostics-qrcode.pdf', 'I');
    exit;
}

// ─── Aperçu écran ────────────────────────────────────────────────────────────
$svg = '';
if ($url !== '') {
    require_once('Common/tcpdf/tcpdf_barcodes_2d.php');
    $bc  = new TCPDF2DBarcode($url, 'QRCODE,M');
    $svg = $bc->getBarcodeSVGcode(4, 4, '#01367c');
}

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#pqr h1{font-size:20px;color:#01367c;margin:0 0 4px}
#pqr .lead{color:#4c4e50;font-size:13px;margin-bottom:14px;max-width:820px}
#pqr .flash{border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13.5px;
            background:#d2f4cd;border:1px solid #75ae77;color:#04ac0b}
#pqr .cols{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}
#pqr .box{background:#fff;border:1px solid #d2d4d6;border-radius:6px;padding:14px;
          box-shadow:0 1px 3px rgba(0,0,0,.08)}
#pqr .box.form{flex:1 1 420px}
#pqr .box.prev{flex:0 0 300px;text-align:center}
#pqr h2{font-size:14px;color:#01367c;margin:0 0 10px;text-transform:uppercase;
        letter-spacing:.04em;border-bottom:1px solid #d2d4d6;padding-bottom:6px}
#pqr label{display:block;font-size:13px;margin-bottom:11px}
#pqr label span{display:block;color:#4c4e50;font-size:12px;margin-bottom:3px}
#pqr input[type=text],#pqr textarea{width:100%;padding:8px 9px;border:1px solid #d2d4d6;
    border-radius:6px;font:inherit}
#pqr textarea{min-height:110px;resize:vertical}
#pqr .btn{background:#0254a8;color:#fff;border:0;border-radius:6px;padding:9px 16px;
          font-weight:600;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}
#pqr .btn.sec{background:#f7f7f7;color:#20263d;border:1px solid #d2d4d6}
#pqr .qr svg{width:230px;height:230px}
#pqr .hint{font-size:12px;color:#7d8183;margin-top:8px}
#pqr .warn{background:#ffd6db;border:1px solid #bb7575;color:#a80000;border-radius:6px;
           padding:10px 12px;font-size:13px;margin-bottom:12px}
#pqr .radio{display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:8px}
</style>

<div id="pqr">
  <h1>Affiche et QR code</h1>
  <div class="lead">Génère l'affiche que les spectateurs scanneront pour rejoindre les pronostics.
    Le PDF reprend l'en-tête et le pied de page de la compétition.</div>

  <?php if ($msg): ?><div class="flash"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($url === ''): ?>
    <div class="warn">Renseigne d'abord l'adresse publique : c'est celle qu'affiche
      <code>cloudflared</code> au lancement du tunnel (elle change à chaque relance).</div>
  <?php endif; ?>

  <form method="post">
    <div class="cols">
      <div class="box form">
        <h2>Contenu de l'affiche</h2>
        <label><span>Adresse publique (URL du tunnel)</span>
          <input type="text" name="url" value="<?= htmlspecialchars($url) ?>"
                 placeholder="https://xxxx.trycloudflare.com"></label>
        <label><span>Titre</span>
          <input type="text" name="title" maxlength="120" value="<?= htmlspecialchars($title) ?>"></label>
        <label><span>Texte</span>
          <textarea name="text"><?= htmlspecialchars($text) ?></textarea></label>

        <h2 style="margin-top:14px">Mise en page</h2>
        <div class="radio"><input type="radio" name="mode" id="m1" value="poster"
             <?= $mode === 'poster' ? 'checked' : '' ?>>
          <label for="m1" style="margin:0">Affiche A4 — une par page, grand QR code</label></div>
        <div class="radio"><input type="radio" name="mode" id="m2" value="flyers"
             <?= $mode === 'flyers' ? 'checked' : '' ?>>
          <label for="m2" style="margin:0">Affichettes — quatre par page, à découper pour les tables</label></div>

        <p style="margin-bottom:0">
          <button class="btn" type="submit" name="do" value="save">Enregistrer</button>
          <button class="btn" type="submit" name="pdf" value="1" formtarget="_blank">Générer le PDF</button>
          <a class="btn sec" href="<?= $root ?>index.php">Retour à la console</a>
        </p>
      </div>

      <div class="box prev">
        <h2>Aperçu</h2>
        <?php if ($svg): ?>
          <div class="qr"><?= $svg ?></div>
          <div class="hint" style="word-break:break-all"><?= htmlspecialchars($url) ?></div>
          <div class="hint">Scanne-le maintenant depuis ton téléphone en 4G pour valider
            le tunnel avant d'imprimer.</div>
        <?php else: ?>
          <div class="hint">L'aperçu apparaîtra dès que l'adresse sera renseignée.</div>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
