<?php
/**
 * Drawing the files: the core's ScorePDF class, four scorecards to a page.
 *
 * Every card is drawn by ScorePDF::DrawScoreNew(), the method the core's
 * printout uses, at the place the core gives it: two columns and two rows on a
 * portrait page, positions A to D on the first page of a target, E to H on the
 * second, and so on. The geometry below is the core's, from
 * CreateSessionScorecard() in Common/Lib/ScorecardsLib.php, for its four-to-a-page
 * layout; it is the one part of that function copied here, because the function
 * reads and draws in a single piece and cannot be told to skip anything.
 *
 * Two differences, and they are the point of the module. A page is drawn only
 * when it carries a card of the file's owner, so a target of eight positions
 * holding three archers prints one page, not two. And the target number can be
 * left out, when positions only serve to give each archer their own scorecards.
 *
 * The QR codes of the scoring applications are drawn by the core's own functions,
 * at the place the core gives them: between the two rows, which move apart to
 * make room. The core draws them once per target, on its last page; here every
 * page gets them, since a club's or an archer's page may travel on its own.
 */

/**
 * The core's scorecard document, with its images embedded at print resolution.
 *
 * The competition's images (Tournament → Images) are embedded as they were
 * uploaded, and a logo of 1 300 pixels printed 15 mm high weighs 100 KB or more.
 * The core does not mind, since its printout is one document; here every club,
 * or every archer, gets a document of its own, each carrying its own copy, and
 * the header alone came to 275 KB per file. TCPDF can reduce an image to its
 * printed size at a given resolution — the core asks it to for the club flags —
 * so this asks it to for every image, at the 300 dpi that TCPDF uses by default.
 *
 * A reduced image is encoded again, at the JPEG quality the document sets.
 * IanseoPdf sets 100, at which the reduced banner of a real competition came out
 * heavier than the original; 90 is indistinguishable in print and a third of it.
 * Measured on one archer's file: 295 KB as the core embeds the images, 100 KB here.
 *
 * TCPDF reduces an image again in every document, which cost 0.2 s per file for
 * the header alone — eleven minutes over the archers of a large challenge. So a
 * JPEG is reduced once, by scs_print_image(), and the copy is reused by every
 * document; TCPDF then finds it small enough already and embeds it as it is.
 *
 * The full-page header can also leave out its text (title, organiser, place,
 * dates), for a competition whose header image already says all of it.
 */
class ScorecardSplitterPdf extends ScorePDF {
    /**
     * Draw the images of the full-page header without its text.
     * @var bool
     */
    public $HideHeaderText = false;

    public function __construct($Portrait = true) {
        parent::__construct($Portrait);
        $this->setJPEGQuality(90);
    }

    /**
     * The full-page header, as IanseoPdf::Header() draws it, or its images alone.
     *
     * The images are placed exactly as the core places them; only the lines of
     * text between them are left out. The core's method cannot be asked for that:
     * emptying the fields it prints still leaves the comma between place and dates.
     */
    public function Header() {
        if (!$this->HideHeaderText) {
            parent::Header();
            return;
        }
        $this->SetDefaultColor();
        $size = 15 + (count($this->StaffCategories) > 0 ? 5 : 0);
        if ($this->ToPaths['ToLeft']) {
            $this->Image($this->ToPaths['ToLeft'], IanseoPdf::sideMargin, 5, 0, $size);
        }
        if ($this->ToPaths['ToRight']) {
            $im = getimagesize($this->ToPaths['ToRight']);
            $this->Image($this->ToPaths['ToRight'], ($this->w - IanseoPdf::sideMargin) - ($im[0] * $size / $im[1]), 5, 0, $size);
        }
    }

    public function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '', $align = '', $resize = false,
                          $dpi = 300, $palign = '', $ismask = false, $imgmask = false, $border = 0, $fitbox = false,
                          $hidden = false, $fitonpage = false, $alt = false, $altimgs = array()) {
        return parent::Image(scs_print_image($file, $w, $h, $dpi, $fitbox), $x, $y, $w, $h, $type, $link, $align, true,
            $dpi, $palign, $ismask, $imgmask, $border, $fitbox, $hidden, $fitonpage, $alt, $altimgs);
    }
}

/**
 * A copy of a JPEG image reduced to its printed size, made once and reused.
 *
 * The copy is kept in the module's folder of the temporary directory, named
 * after the original's path, date, size and the size asked for, so a new logo
 * never meets an old copy. Anything that is not a plain JPEG file, or whose
 * printed size is unknown, is returned as it is and left to TCPDF.
 *
 * The copy must never be larger than the size TCPDF works out for the same
 * image, or TCPDF reduces it once more in every document — which is the cost the
 * copy is there to avoid. Hence the pixels rounded down, and the three ways an
 * image can be placed: by one side, the other following; stretched to both
 * sides; or fitted inside both, as the core places club flags.
 *
 * @param mixed $file Path, or TCPDF's "@" followed by the image data.
 * @param float $w Printed width in millimetres, 0 when it follows from the height.
 * @param float $h Printed height in millimetres, 0 when it follows from the width.
 * @param int $dpi Resolution wanted.
 * @param mixed $fitbox TCPDF's fitbox argument: fit inside $w by $h, keeping the proportions.
 * @return mixed The path to embed.
 */
function scs_print_image($file, $w, $h, $dpi, $fitbox = false) {
    static $done = [];
    if (!is_string($file) || $file === '' || $file[0] === '@' || !function_exists('imagecreatefromjpeg')) return $file;
    $key = $file . '|' . $w . '|' . $h . '|' . $dpi . '|' . ($fitbox ? 1 : 0);
    if (isset($done[$key])) return $done[$key];
    $done[$key] = $file;

    $info = @getimagesize($file);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 1 || $info[1] < 1) return $file;

    // Scale of each side at the resolution wanted, from the printed size in millimetres.
    $sx = $w > 0 ? $w / 25.4 * $dpi / $info[0] : 0;
    $sy = $h > 0 ? $h / 25.4 * $dpi / $info[1] : 0;
    if ($sx > 0 && $sy > 0 && $fitbox) {
        $sx = $sy = min($sx, $sy);
    } elseif ($sx <= 0 || $sy <= 0) {
        $sx = $sy = max($sx, $sy);
    }
    // Unknown size, or too little to gain: leave the image alone.
    if ($sx <= 0 || max($sx, $sy) > 0.8) return $file;
    $pw = max(1, (int)floor($info[0] * $sx));
    $ph = max(1, (int)floor($info[1] * $sy));

    $dir  = scs_jobs_root() . DIRECTORY_SEPARATOR . 'images';
    $copy = $dir . DIRECTORY_SEPARATOR . md5($file . '|' . @filemtime($file) . '|' . @filesize($file) . "|{$pw}x{$ph}") . '.jpg';
    if (!is_file($copy)) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return $file;
        $src = @imagecreatefromjpeg($file);
        if (!$src) return $file;
        $dst = imagecreatetruecolor($pw, $ph);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $pw, $ph, $info[0], $info[1]);
        // Written aside, then renamed: a request running at the same time never
        // reads half an image.
        $tmp = $copy . '.' . getmypid() . '.tmp';
        $ok  = @imagejpeg($dst, $tmp, 90) && @rename($tmp, $copy);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            @unlink($tmp);
            return $file;
        }
    } else {
        // In use again: scs_jobs_purge() only removes copies left unused for a day.
        @touch($copy);
    }
    return $done[$key] = $copy;
}

/**
 * A scorecard document set up from the options, as the core sets up its own.
 *
 * @param array $opts Output of scs_options().
 * @return ScorePDF
 */
function scs_new_pdf(array $opts) {
    $pdf = new ScorecardSplitterPdf(true);
    // QR codes first, as in the core: FullHeaderShow() leaves a different bottom
    // margin when they are printed, and they take the place of the sponsors' image.
    if (!empty($opts['qr'])) {
        $pdf->QRCode      = $opts['qr'];
        $pdf->BottomImage = false;
    }
    if (!empty($opts['qrPersonal'])) {
        $pdf->ScoreQrPersonal = true;
        $pdf->BottomImage     = false;
    }
    $pdf->FillWithArrows = false;
    if (!$opts['header']) $pdf->HideHeader();
    if (!$opts['logos'])  $pdf->HideLogo();
    if (!$opts['flags'])  $pdf->HideFlags();
    $pdf->FullHeaderShow($opts['page']);
    $pdf->HideHeaderText = !empty($opts['hideHeaderText']);
    $pdf->PrintBarcode   = $opts['barcode'];
    $pdf->GetArcInfo     = $opts['info'];
    return $pdf;
}

/**
 * Size of a scorecard, the corner of each of the four places of a page, and the
 * place of the QR codes.
 *
 * @param ScorePDF $pdf
 * @return array [w, h, slots => four [x, y], qr => [x, y] or null].
 */
function scs_geometry(ScorePDF $pdf) {
    $m  = $pdf->getSideMargin();
    $w  = ($pdf->GetPageWidth() - $m * 3) / 2;
    $h  = ($pdf->GetPageHeight() - $m * 3) / 2;
    $x2 = $m + $m + $w;
    // The full-page header and footer take their room from the lower row.
    $y2 = $m + $m + $h - ($pdf->PrintFullHeader ? 10 + (empty($pdf->QRCode) ? 5 : 0) : 0);
    $qr = null;
    if ($pdf->QRCode || $pdf->ScoreQrPersonal) {
        // The codes sit between the two rows, which move apart to make room.
        $h  -= 8;
        $y2 += 8;
        // One code alone is centred by the core's drawing function, given x = 0.
        $count = count($pdf->QRCode) + ($pdf->ScoreQrPersonal ? 1 : 0);
        $qr = [
            'x' => (count($pdf->QRCode) > 1 || $pdf->ScoreQrPersonal) ? ($pdf->GetPageWidth() + 5 - 30 * $count) / 2 : 0,
            'y' => ($pdf->GetPageHeight() - 25) / 2 - 0.5,
        ];
    }
    return ['w' => $w, 'h' => $h, 'slots' => [[$m, $m], [$x2, $m], [$m, $y2], [$x2, $y2]], 'qr' => $qr];
}

/**
 * The QR codes of one page: one per scoring application, then the personal one.
 *
 * Each code opens this target, in this session and at this distance, in the
 * scoring application, whatever the archers' positions on the page.
 *
 * @param ScorePDF $pdf
 * @param array $qr Place of the codes, from scs_geometry().
 * @param int $session
 * @param int $target
 * @param int $dist
 */
function scs_draw_qr(ScorePDF $pdf, array $qr, $session, $target, $dist) {
    $k = -1;
    foreach ($pdf->QRCode as $k => $api) {
        require_once 'Api/' . $api . '/DrawQRCode.php';
        $draw = 'DrawQRCode_' . preg_replace('/[^a-z0-9]/i', '_', $api);
        $draw($pdf, $qr['x'] + 30 * $k, $qr['y'], $session, $dist, $target, '', 'Q', false);
    }
    if ($pdf->ScoreQrPersonal) {
        DrawScoreQrPersonal($pdf, $target, $qr['x'] + 30 * ($k + 1), $qr['y']);
    }
}

/**
 * A blank grid for a position of the page that does not belong to the file.
 *
 * Built like the core's empty positions, which come out of its query with every
 * archer's column empty and only the session's layout filled in: the grid has
 * the right number of ends and arrows, and nothing else.
 *
 * @param array $model A card of the same target, for the session's layout.
 * @param int $slot Position index, 0 for A.
 * @param int $target Target number.
 * @return array
 */
function scs_blank_card(array $model, $slot, $target) {
    $blank = array_fill_keys(array_keys($model), null);
    foreach ($model as $k => $v) {
        if (preg_match('/^Num(Ends|Arrows)\d$/', $k)) $blank[$k] = $v;
    }
    $blank['Session']    = $model['Session'];
    $blank['AtTarget']   = $target;
    $blank['tNo']        = $target . chr(65 + $slot);
    // Real characters rather than NULL: with NULL the core queries the database
    // again for every end of the grid.
    $blank['GoldsChars'] = $model['GoldsChars'];
    $blank['XNineChars'] = $model['XNineChars'];
    // DrawScoreNew() remembers the ends and arrows of a card by its category, for
    // the whole request. Blank grids have none, so those of every session would
    // share the layout of the first one drawn — and a club's file mixes sessions.
    // An invisible category of its own per session keeps each grid right.
    $blank['Cat'] = str_repeat("\u{00A0}", max(1, (int)$model['Session']));
    return $blank;
}

/**
 * Does any card of the target have this distance?
 *
 * The core skips a distance that the archer's category does not shoot, which the
 * competition's distance names mark with a dash.
 *
 * @param array $cards
 * @param int $dist
 * @return bool
 */
function scs_has_distance(array $cards, $dist) {
    foreach ($cards as $card) {
        if (($card['D' . $dist] ?? '') !== '-') return true;
    }
    return false;
}

/**
 * Draw the pages of one file.
 *
 * Per target and per distance, as in the core's printout: each block of four
 * positions that holds a card of the file's owner makes one page, the other
 * positions of that page are blank grids, and a block without any makes no page.
 *
 * @param ScorePDF $pdf
 * @param array $targets One file of scs_split().
 * @param array $opts Output of scs_options().
 * @param array $sessions Output of scs_sessions().
 * @return int Number of pages drawn.
 */
function scs_draw(ScorePDF $pdf, array $targets, array $opts, array $sessions) {
    $g     = scs_geometry($pdf);
    $pages = 0;
    foreach ($targets as $t) {
        $cards = $t['cards'];
        ksort($cards);
        $model     = reset($cards);
        $perTarget = $sessions[$t['session']]['perTarget'];

        $blocks = [];
        foreach (array_keys($cards) as $slot) $blocks[intdiv($slot, 4)] = true;
        ksort($blocks);

        foreach ($opts['distances'] as $dist) {
            if ($dist && !scs_has_distance($cards, $dist)) continue;
            foreach (array_keys($blocks) as $block) {
                $pdf->AddPage('P');
                $pages++;
                $last = min(4 * $block + 4, $perTarget);
                for ($slot = 4 * $block; $slot < $last; $slot++) {
                    $card = $cards[$slot] ?? scs_blank_card($model, $slot, $t['target']);
                    if ($opts['hide']) $card['tNo'] = '';
                    [$x, $y] = $g['slots'][$slot % 4];
                    $pdf->DrawScoreNew($x, $y, $g['w'], $g['h'], $dist, $card);
                }
                if ($g['qr']) scs_draw_qr($pdf, $g['qr'], $t['session'], $t['target'], $dist);
            }
        }
    }
    return $pages;
}
