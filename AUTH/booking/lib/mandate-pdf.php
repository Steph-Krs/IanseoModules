<?php
/**
 * lib/mandate-pdf.php — the competition's mandate on paper.
 *
 * ianseo asks that a document meant for paper be produced with the core's PDF classes, not
 * printed from a web page by the browser (which cannot paginate, hold a width or repeat a
 * header). The mandate is therefore drawn here on IanseoPdf: the core header (competition,
 * organiser, place, dates, the logos chosen by the organiser) and footer (page numbers, bottom
 * logo), then the same content as the on-screen document (bk_mandate_document) in the chosen
 * colour. The six templates of the screen are rendered with what a PDF page allows: a coloured
 * band, a frame, a side band, rules or a denser layout.
 *
 * Built like bk_ledger_pdf_build(), which opens the competition session the core class
 * needs when the caller is an archer.
 */

if (defined('BK_MANDATE_PDF_LOADED')) return;
define('BK_MANDATE_PDF_LOADED', true);

require_once __DIR__ . '/mandate.php';
require_once __DIR__ . '/ledger-pdf.php';   // bk_ledger_pdf_send, bk_with_tournament
require_once('Common/pdf/IanseoPdf.php');

/**
 * IanseoPdf with the decoration of the "framed" and "modern" templates, drawn on every page
 * right after the core header.
 */
class BkMandatePdf extends IanseoPdf
{
    public $bkTemplate = 'sobre';
    public $bkPrimary = array(2, 84, 168);
    public $bkFooter = 0;   // height kept by the core footer (page numbers, bottom logo)

    function Header()
    {
        parent::Header();
        // Between the core header and the core footer, never over them.
        $top = $this->tMargin - 1;
        $bottom = $this->getPageHeight() - $this->bkFooter - 1;
        if ($this->bkTemplate === 'encadre') {
            $this->SetDrawColorArray($this->bkPrimary);
            $this->SetLineWidth(0.8);
            $this->Rect(IanseoPdf::sideMargin - 3, $top, $this->getPageWidth() - 2 * IanseoPdf::sideMargin + 6, $bottom - $top);
            $this->SetLineWidth(0.1);
        } elseif ($this->bkTemplate === 'moderne') {
            $this->SetFillColorArray($this->bkPrimary);
            $this->Rect(IanseoPdf::sideMargin - 6, $top, 3, $bottom - $top, 'F');
        }
        $this->SetDefaultColor();
    }
}

/** '#rrggbb' → [r, g, b]. */
function bk_mandate_rgb($hex)
{
    // bytes: a #rrggbb colour is ASCII
    return array(hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
}

/** Text for the PDF fonts: the core fonts have no U+2212 minus nor superscript letters. */
function bk_mandate_pdf_text($s)
{
    return str_replace(array('−', 'ᵉ', '↗'), array('-', 'e', ''), (string) $s);
}

/**
 * Sends the mandate of competition $tourId as a PDF and ends the script. $data: bk_mandate_data(),
 * $m: bk_mandate_get(), $ctx: 'regUrl' and 'shopUrl' as for bk_mandate_document().
 */
function bk_mandate_pdf($tourId, $data, $m, $ctx)
{
    $t = $data['tour'];
    $title = bk_mandate_pdf_text(bk_t('MnTitle', $t->ToName));
    // As bk_ledger_pdf_build(), with the subclass: the core class needs the competition session,
    // opened around the drawing when the caller is an archer.
    bk_money_tour($tourId);
    $make = function () use ($title, $data, $m, $ctx) {
        $pdf = new BkMandatePdf($title, true);
        bk_mandate_pdf_draw($pdf, $data, $m, $ctx);
        return $pdf->Output('', 'S');
    };
    $bytes = intval($_SESSION['TourId'] ?? 0) === intval($tourId) ? $make() : bk_with_tournament(intval($tourId), $make);
    // bytes: an ASCII competition code
    bk_ledger_pdf_send($bytes, 'mandate-' . preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $t->ToCode) . '.pdf');
}

/** Draws the whole mandate on $pdf (a BkMandatePdf, no page added yet). */
function bk_mandate_pdf_draw($pdf, $data, $m, $ctx)
{
    $t = $data['tour'];
    $tourId = intval($t->ToId);
    $tpl = $m['template'];
    $pal = bk_mandate_palette($m['color']);
    $pri = bk_mandate_rgb($pal['primary']);
    $light = bk_mandate_rgb($pal['light']);
    $dark = bk_mandate_rgb($pal['dark']);
    $on = bk_mandate_rgb($pal['on']);
    $grey = array(76, 78, 80);
    $regUrl = (string) ($ctx['regUrl'] ?? '');
    $shopUrl = (string) ($ctx['shopUrl'] ?? '');

    // Logos: those the organiser ticked, among those ianseo has.
    foreach (array('L' => 'ToLeft', 'R' => 'ToRight', 'B' => 'ToBottom') as $k => $path) {
        if (empty($m['logos'][$k]) || intval($t->{'Has' . $k}) <= 0) $pdf->ToPaths[$path] = '';
    }
    $pdf->bkTemplate = $tpl;
    $pdf->bkPrimary = $pri;
    $pdf->bkFooter = $pdf->getBreakMargin();
    if ($tpl === 'encadre' || $tpl === 'moderne') $pdf->SetAutoPageBreak(true, $pdf->bkFooter + 3);   // text off the frame
    $pdf->setPrintFooter(true);
    $pdf->startPageGroup();
    $pdf->AddPage();

    $compact = ($tpl === 'compact');
    $fs = $compact ? 9 : 10;          // body text
    $lh = $compact ? 4.4 : 5;         // line height
    $left = IanseoPdf::sideMargin;
    $W = $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;
    $esc = function ($s) { return htmlspecialchars(bk_mandate_pdf_text($s), ENT_QUOTES, 'UTF-8'); };
    $hex = function ($rgb) { return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]); };

    // Rich text line (bold parts, links) through the HTML writer of the core library.
    $html = function ($markup, $indent = 0) use ($pdf, $left, $W, $fs, $hex, $dark) {
        $pdf->SetFont($pdf->FontStd, '', $fs);
        $pdf->SetTextColor(32, 38, 61);
        $pdf->writeHTMLCell($W - $indent, 0, $left + $indent, $pdf->GetY(),
            '<span style="color:#20263d">' . $markup . '</span>', 0, 1, false, true, 'L', true);
    };
    $bullet = function ($markup) use ($pdf, $left, $fs, $lh, $html, $pri) {
        if ($pdf->GetY() > $pdf->getPageHeight() - 25) $pdf->AddPage();
        $pdf->SetFillColorArray($pri);
        $pdf->Rect($left + 1.5, $pdf->GetY() + $lh / 2 - 0.7, 1.4, 1.4, 'F');
        $html($markup, 5);
        $pdf->Ln($fs > 9 ? 0.6 : 0.3);
    };

    // Section heading, in the template's style. $need: room (mm) kept for what follows it, so a
    // heading never ends a page alone.
    $h2 = function ($label, $need = 45) use ($pdf, $tpl, $left, $W, $compact, $pri, $light, $dark) {
        if ($pdf->GetY() > $pdf->getPageHeight() - $pdf->getBreakMargin() - $need) $pdf->AddPage();
        $pdf->Ln($compact ? 3 : 5);
        $y = $pdf->GetY();
        $label = bk_mandate_pdf_text($label);
        if ($tpl === 'encadre') {
            $pdf->SetFillColorArray($light);
            $pdf->Rect($left, $y, $W, 7, 'F');
            $pdf->SetFillColorArray($pri);
            $pdf->Rect($left, $y, 1.6, 7, 'F');
            $pdf->SetFont($pdf->FontStd, 'B', 12);
            $pdf->SetTextColorArray($dark);
            $pdf->SetXY($left + 4, $y);
            $pdf->Cell($W - 4, 7, $label, 0, 1, 'L');
            $pdf->Ln(1.5);
        } elseif ($tpl === 'ligne') {
            $pdf->SetFont($pdf->FontStd, 'B', 9.5);
            $pdf->SetTextColorArray($pri);
            $pdf->setFontSpacing(0.35);
            $pdf->Cell($W, 6, mb_strtoupper($label, 'UTF-8'), 0, 1, 'L');
            $pdf->setFontSpacing(0);
            $pdf->SetDrawColorArray($pri);
            $pdf->SetLineWidth(0.2);
            $pdf->Line($left, $pdf->GetY(), $left + $W, $pdf->GetY());
            $pdf->Ln(2);
        } else {
            $size = $compact ? 11 : 12.5;
            $pdf->SetFont($pdf->FontStd, 'B', $size);
            $pdf->SetTextColorArray($dark);
            if ($tpl === 'moderne') {
                $pdf->SetFillColorArray($pri);
                $pdf->Rect($left, $y + 1.6, 2.6, 2.6, 'F');
                $pdf->SetX($left + 4.5);
                $pdf->Cell($W - 4.5, 6.5, $label, 0, 1, 'L');
            } else {
                $pdf->Cell($W, 6.5, $label, 0, 1, 'L');
            }
            $pdf->SetDrawColorArray($pri);
            $pdf->SetLineWidth($tpl === 'bandeau' ? 0.9 : 0.5);
            $pdf->Line($left, $pdf->GetY() + 0.3, $left + $W, $pdf->GetY() + 0.3);
            $pdf->Ln($compact ? 1.6 : 2.4);
        }
        $pdf->SetLineWidth(0.1);
        $pdf->SetDefaultColor();
    };

    // Coloured chips flowing over the lines.
    $chips = function ($list) use ($pdf, $left, $W, $fs, $pri, $light, $dark) {
        $pdf->SetFont($pdf->FontStd, '', $fs - 0.5);
        $x = $left; $y = $pdf->GetY(); $h = 6;
        foreach ($list as $label) {
            $label = bk_mandate_pdf_text($label);
            $w = min($W, $pdf->GetStringWidth($label) + 5);
            if ($x + $w > $left + $W) { $x = $left; $y += $h + 1.5; }
            if ($y > $pdf->getPageHeight() - 25) { $pdf->AddPage(); $y = $pdf->GetY(); $x = $left; }
            $pdf->SetFillColorArray($light);
            $pdf->SetDrawColorArray($pri);
            $pdf->SetLineWidth(0.25);
            $pdf->RoundedRect($x, $y, $w, $h, 1.2, '1111', 'DF');
            $pdf->SetTextColorArray($dark);
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $h, $label, 0, 0, 'C');
            $x += $w + 1.8;
        }
        $pdf->SetLineWidth(0.1);
        $pdf->SetDefaultColor();
        $pdf->SetXY($left, $y + $h + 1);
    };

    // Free text block of the organiser.
    $block = function ($key) use ($pdf, $m, $h2, $fs, $lh) {
        if (empty($m['blocks'][$key])) return;
        $h2(bk_mandate_sections()[$key]);
        $pdf->SetFont($pdf->FontStd, '', $fs);
        $pdf->SetTextColor(32, 38, 61);
        $pdf->MultiCell(0, $lh, bk_mandate_pdf_text($m['blocks'][$key]), 0, 'L');
    };

    // Title of the document.
    $sub = $data['discLabel'] . ($t->ToWhere ? ' — ' . $t->ToWhere : '') . ' — ' . bk_date_range($t->ToWhenFrom, $t->ToWhenTo);
    $org = array();
    if ($t->ToComDescr) $org[] = bk_t('OrganisedBy', $t->ToComDescr);
    if ($data['region']) $org[] = $data['region'];
    $titleSize = $compact ? 16 : ($tpl === 'moderne' ? 21 : 19);
    $align = $tpl === 'moderne' ? 'L' : 'C';
    $pdf->Ln(2);
    $y0 = $pdf->GetY();
    if ($tpl === 'bandeau') {
        $pdf->SetFont($pdf->FontStd, 'B', $titleSize);
        $hTitle = $pdf->getNumLines(bk_mandate_pdf_text($t->ToName), $W - 10) * ($titleSize * 0.47);
        $pdf->SetFont($pdf->FontStd, '', 11);
        $hSub = $pdf->getNumLines(bk_mandate_pdf_text($sub), $W - 10) * 5.2 + ($org ? 5 : 0);
        $pdf->SetFillColorArray($pri);
        $pdf->Rect($left, $y0, $W, $hTitle + $hSub + 9, 'F');
        $pdf->SetTextColorArray($on);
        $pdf->SetXY($left + 5, $y0 + 4);
    } else {
        $pdf->SetTextColorArray($dark);
    }
    $pdf->SetFont($pdf->FontStd, 'B', $titleSize);
    $pdf->MultiCell($tpl === 'bandeau' ? $W - 10 : $W, $titleSize * 0.47, bk_mandate_pdf_text($t->ToName), 0, $align, false, 1,
        $tpl === 'bandeau' ? $left + 5 : $left);
    if ($tpl !== 'bandeau') $pdf->SetTextColorArray($grey);
    $pdf->SetFont($pdf->FontStd, '', 11);
    $pdf->MultiCell($tpl === 'bandeau' ? $W - 10 : $W, 5.2, bk_mandate_pdf_text($sub), 0, $align, false, 1,
        $tpl === 'bandeau' ? $left + 5 : $left);
    if ($org) {
        $pdf->SetFont($pdf->FontStd, '', 9.5);
        $pdf->MultiCell($tpl === 'bandeau' ? $W - 10 : $W, 5, bk_mandate_pdf_text(implode(' — ', $org)), 0, $align, false, 1,
            $tpl === 'bandeau' ? $left + 5 : $left);
    }
    if ($tpl === 'bandeau') $pdf->SetY($pdf->GetY() + 4);
    // Itinerary links (navigation applications), clickable in the PDF.
    if (!empty($data['itinerary'])) {
        $links = array();
        foreach (bk_itinerary_links($tourId, true) as $label => $url) {   // web addresses: read on any device
            $links[] = '<a href="' . $esc($url) . '" style="color:' . $hex($dark) . ';text-decoration:none"><b>' . $esc($label) . '</b></a>';
        }
        $pdf->SetFont($pdf->FontStd, '', 9.5);
        $pdf->writeHTMLCell($W, 0, $left, $pdf->GetY() + 1, '<span style="color:#7d8183">' . $esc(bk_t('Itinerary')) . '</span> '
            . implode(' · ', $links), 0, 1, false, true, $align, true);
    }
    $pdf->SetDefaultColor();
    $pdf->Ln(1);
    $block('intro');

    // Form of the competition (rules B.1.1), as on the screen version (lib/mandate.php).
    if (!empty($m['show']['categories']) && ($data['format'] !== '' || $data['events'] || $data['divisions'] || $data['classes'] || $data['faces'])) {
        $h2(bk_t('MnFormat'));
        if ($data['format'] !== '' || $data['events']) {
            $html('<b>' . $esc($data['format']) . '</b>' . ($data['format'] !== '' ? ' — ' : '') . $esc(bk_t($data['duels'] ? 'MnWithDuels' : 'MnNoDuel')));
        }
        foreach (array(0 => 'MnEventsInd', 1 => 'MnEventsTeam') as $team => $key) {
            $list = array();
            foreach ($data['events'] as $ev) {
                if ($ev['team'] === (bool) $team) $list[] = $ev['name'] . ($ev['duels'] ? ' · ' . bk_t('MnDuels') : '');
            }
            if ($list) { $pdf->Ln(1.5); $html('<b>' . $esc(bk_t($key)) . '</b>'); $pdf->Ln(1); $chips($list); }
        }
        if (!$data['events']) {
            if ($data['divisions']) { $pdf->Ln(1.5); $html('<b>' . $esc(bk_t('MnBows')) . '</b>'); $pdf->Ln(1); $chips($data['divisions']); }
            if ($data['classes']) { $pdf->Ln(1.5); $html('<b>' . $esc(bk_t('MnClasses')) . '</b>'); $pdf->Ln(1); $chips($data['classes']); }
        }
        if ($data['faces']) { $pdf->Ln(1.5); $html('<b>' . $esc(bk_t('MnFaces')) . '</b>'); $pdf->Ln(1); $chips($data['faces']); }
    }

    // Full programme: the core's table (already filtered), flowing over the pages.
    if (!empty($m['show']['program']) && $data['program'] !== '') {
        $h2(bk_t('MnProgram'));
        $prog = preg_replace('~<tr([^>]*)><td>~', '<tr$1><td width="20%">', $data['program']);
        $prog = str_replace('</td><td', '</td><td width="80%"', $prog);
        $prog = str_replace('<table', '<table cellpadding="2.5"', $prog);
        $pdf->SetFont($pdf->FontStd, '', $fs - 0.5);
        $pdf->SetTextColor(32, 38, 61);
        $pdf->SetX($left);
        $pdf->writeHTML('<style>th{background-color:' . $hex($light) . ';color:' . $hex($dark) . ';font-weight:bold;}'
            . ' td{border-bottom:0.2px solid #e3e6ea;} .SchTitle{font-weight:bold;color:' . $hex($dark) . ';} .SchSubTitle{font-weight:bold;}</style>'
            . bk_mandate_pdf_text($prog), true, false, true, false, '');
        $pdf->SetDefaultColor();
    }

    // Field staff, as on the screen version.
    if (!empty($m['show']['staff']) && $data['staff']) {
        $h2(bk_t('MnStaff'));
        foreach ($data['staff'] as $st) {
            $bullet('<b>' . $esc($st['role']) . '</b> — ' . $esc(implode(', ', $st['names'])));
        }
    }

    if (!empty($m['show']['sessions']) && $data['sessions']) {
        $h2(bk_t('MnSessions'));
        foreach ($data['sessions'] as $s) {
            $ss = bk_session_start($s);
            $places = intval($s->Places);
            $bullet('<b>' . $esc(bk_t('DepCap', intval($s->SesOrder))) . '</b>' . ($s->SesName ? ' — ' . $esc($s->SesName) : '')
                . ($ss !== '' ? ' — ' . $esc(bk_date_time($ss)) : '')
                . ' — ' . $esc(bk_t($places > 1 ? 'PlacesMany' : 'PlacesOne', $places)));
        }
    }

    if (!empty($m['show']['fees'])) {
        $h2(bk_t('MnFees'));
        if ($data['fee'] <= 0 && !$data['feeAdvanced']) {
            $html($esc(bk_t('MnFree')));
        } else {
            $html('<b>' . $esc(bk_t('MnBaseFee')) . '</b> ' . $esc(bk_eur($data['fee'], false, $tourId)));
            if ($data['feeAdvanced']) $html('<span style="color:#4c4e50">' . $esc(bk_t('MnFeeAdjust')) . '</span>');
        }
    }

    if (!empty($m['show']['payment']) && $data['pay']) {
        $h2(bk_t('PayMeansTitle'));
        foreach ($data['pay'] as $pi) {
            $bullet($esc($pi['label']) . ' <span style="color:#7d8183">(' . $esc($pi['whenLabel']) . ')</span>'
                . ($pi['info'] !== '' ? ' — ' . bk_linkify($pi['info'], $esc) : ''));
        }
    }

    if (!empty($m['show']['shop']) && !empty($data['shop']) && $shopUrl !== '') {
        $h2(bk_t('Shop'));
        foreach ($data['shop'] as $it) {
            $line = $esc($it['label']) . ($it['price'] > 0 ? ' — ' . $esc(bk_eur($it['price'], false, $tourId)) : '')
                . ($it['description'] !== '' ? ' <span style="color:#7d8183">(' . $esc($it['description']) . ')</span>' : '');
            $vlabels = array();
            foreach ($it['variants'] as $v) {
                $vl = trim((string) $v['label']);
                if ($vl !== '') $vlabels[] = $vl;
            }
            if ($vlabels) {
                $line .= '<br><span style="color:#4c4e50">' . $esc(bk_t('LabelColon', $it['option'] !== '' ? $it['option'] : bk_t('MnOptions')))
                    . ' ' . $esc(implode(', ', $vlabels)) . '</span>';
            }
            $bullet($line);
        }
        $pdf->Ln(1);
        $html('<span style="color:#4c4e50">' . $esc(bk_t('MnShopOnline')) . '</span> <a href="' . $esc($shopUrl) . '" style="color:'
            . $hex($dark) . '">' . $esc($shopUrl) . '</a>');
    }

    if (!empty($m['show']['register']) && $regUrl !== '') {
        $h2(bk_t('Brand'), 60);
        $box = $esc(bk_t('MnRegOnline')) . '<br><b><a href="' . $esc($regUrl) . '" style="color:' . $hex($dark) . '">' . $esc($regUrl) . '</a></b>';
        if (!empty($data['deadline']) && bk_date_fr($data['deadline']) !== '') {
            $box .= '<br><b>' . $esc(bk_t('MnDeadline')) . '</b> ' . $esc(bk_t('DateOn', bk_date_fr($data['deadline'])));
        }
        $pdf->SetFillColorArray($light);
        $pdf->SetDrawColorArray($pri);
        $pdf->SetLineWidth(0.3);
        $pad = $pdf->getCellPaddings();
        $pdf->setCellPaddings(4, 3, 4, 3);
        $pdf->SetFont($pdf->FontStd, '', $fs);
        $pdf->writeHTMLCell($W, 0, $left, $pdf->GetY(), '<span style="color:#20263d">' . $box . '</span>', 1, 1, true, true, 'L', true);
        $pdf->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $pad['B']);
        $pdf->SetLineWidth(0.1);
        $pdf->SetDefaultColor();
    }

    foreach (array('access', 'lodging', 'catering', 'awards', 'misc', 'contact') as $k) $block($k);
}
