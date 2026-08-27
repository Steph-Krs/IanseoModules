<?php
/**
 * lib/totp.php — TOTP (RFC 6238) pour l'espace licencié, OPTIONNEL.
 *
 * Copie AUTONOME du régime TOTP d'AUTH (aut_totp_*), sans jamais inclure AUTH —
 * exactement le parti pris déjà retenu pour les sessions (« copie du régime AUTH,
 * sans jamais inclure AUTH », voir lib/archer.php). Le secret ne quitte jamais le
 * serveur ; le QR est rendu via l'encodeur QR de TCPDF déjà fourni par ianseo.
 *
 * Colonnes : BK_Archers.BaTotpSecret / BaTotpEnabled / BaTotpLastSlot.
 */

if (function_exists('bk_totp_verify')) return;

function bk_base32_decode($b32)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $b32));
    $bits = '';
    $out = '';
    foreach (str_split($b32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) == 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function bk_totp_new_secret()
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $alphabet[random_int(0, 31)];
    return $s;
}

function bk_totp_code($secretB32, $slot)
{
    $key = bk_base32_decode($secretB32);
    $bin = pack('N', 0) . pack('N', $slot);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $code = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/** Vérifie un code (fenêtre ±1 pas de 30 s). $minSlot = anti-rejeu ; $usedSlot = slot accepté. */
function bk_totp_verify($secretB32, $code, $minSlot, &$usedSlot)
{
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) != 6 || (string) $secretB32 === '') return false;
    $slot = (int) floor(time() / 30);
    foreach (array(0, -1, 1) as $d) {
        $s = $slot + $d;
        if ($s > $minSlot && hash_equals(bk_totp_code($secretB32, $s), $code)) {
            $usedSlot = $s;
            return true;
        }
    }
    return false;
}

/** Diagnostic d'HORLOGE serveur (jamais une acceptation) : écart en secondes ou null. */
function bk_totp_skew($secretB32, $code, $maxSlots = 120)
{
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) != 6 || (string) $secretB32 === '') return null;
    $slot = (int) floor(time() / 30);
    for ($d = -$maxSlots; $d <= $maxSlots; $d++) {
        if (abs($d) <= 1) continue;
        if (hash_equals(bk_totp_code($secretB32, $slot + $d), $code)) return $d * 30;
    }
    return null;
}

function bk_totp_uri($label, $secret)
{
    $issuer = rawurlencode('ianseo licencié');
    return 'otpauth://totp/' . $issuer . ':' . rawurlencode($label)
        . '?secret=' . $secret . '&issuer=' . $issuer . '&digits=6&period=30';
}

/**
 * QR code (SVG inline) via l'encodeur QR de TCPDF fourni par ianseo — aucune
 * dépendance externe, le secret ne sort pas. Retourne '' si indisponible.
 */
function bk_qr_svg($text, $sizePx = 210)
{
    global $CFG;
    $file = $CFG->DOCUMENT_PATH . 'Common/tcpdf/include/barcodes/qrcode.php';
    if (!is_file($file)) return '';
    require_once($file);
    try {
        $qr = new QRcode($text, 'M');
        $arr = $qr->getBarcodeArray();
    } catch (\Throwable $e) {
        return '';
    }
    if (empty($arr['num_rows']) || empty($arr['bcode'])) return '';
    $rows = $arr['num_rows'];
    $cols = $arr['num_cols'];
    $margin = 4;
    $dim = max($rows, $cols) + 2 * $margin;
    $path = '';
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if (!empty($arr['bcode'][$r][$c])) {
                $path .= 'M' . ($c + $margin) . ' ' . ($r + $margin) . 'h1v1h-1z';
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $sizePx . '" height="' . $sizePx . '"'
        . ' viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR code 2FA">'
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/>'
        . '<path fill="#000" d="' . $path . '"/></svg>';
}
