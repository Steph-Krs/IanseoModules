<?php
/**
 * legal-lib.php — legal notice, terms of use, privacy (GDPR) and cookies of the SERVER.
 *
 * A "shared" server may be run by anyone (federation, league, committee, club, private
 * person…). The legal texts must therefore ADAPT to the operator, and the author of the module
 * cannot be held liable for the use made of third-party servers. Hence:
 *  - the operator fills in their information on an administration page (admin/legal.php),
 *    stored in legal.local.json (NOT versioned);
 *  - the module GENERATES complete texts from that information, in the visitor's language
 *    (languages/<code>.php, keys Lg*), which the operator can read again and override;
 *  - a clear DISCLAIMER appears in the texts: the operator of the server alone is responsible.
 *
 * Loaded only by the pages that need it (legal pages, sign-in, acceptance page, terms guard)
 * — not globally, to weigh nothing.
 */

if (defined('AUT_LEGAL_LOADED')) return;
define('AUT_LEGAL_LOADED', true);

require_once __DIR__ . '/lang-lib.php';

/** Legal configuration file (not versioned, specific to each server). */
function aut_legal_file()
{
    return __DIR__ . '/legal.local.json';
}

/** Full legal configuration (with defaults). */
function aut_legal_conf()
{
    static $c = null;
    if ($c !== null) return $c;
    $c = array();
    $f = aut_legal_file();
    if (is_file($f)) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) $c = $j;
    }
    $c += array('version' => '1', 'updated' => '', 'operator' => array(), 'custom' => array());
    if (!is_array($c['operator'])) $c['operator'] = array();
    if (!is_array($c['custom']))   $c['custom']   = array();
    return $c;
}

/** Saves the legal configuration (overwrites). Returns true when written. */
function aut_legal_save($conf)
{
    $conf['updated'] = date('c');
    $ok = @file_put_contents(aut_legal_file(),
        json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $ok !== false;
}

/** Current version of the terms of use (raise it to ask for the acceptance again). */
function aut_legal_version()
{
    $v = trim((string) (aut_legal_conf()['version'] ?? '1'));
    return $v !== '' ? $v : '1';
}

/** Operator fields (key => label + help) for the administration form. */
function aut_legal_fields()
{
    $out = array();
    foreach (array('site_name' => 'SiteName', 'name' => 'Name', 'status' => 'Status', 'siret' => 'Siret',
                   'address' => 'Address', 'email' => 'Email', 'phone' => 'Phone', 'publisher' => 'Publisher',
                   'dpo' => 'Dpo', 'host_name' => 'HostName', 'host_address' => 'HostAddress') as $k => $key) {
        $out[$k] = array(aut_t('LgF' . $key), aut_t('LgF' . $key . 'Help'));
    }
    return $out;
}

/** Values of the operator (every key present, empty by default). */
function aut_legal_operator()
{
    $op = aut_legal_conf()['operator'];
    $out = array();
    foreach (array_keys(aut_legal_fields()) as $k) $out[$k] = trim((string) ($op[$k] ?? ''));
    return $out;
}

/** Has the operator filled in the bare minimum (name + e-mail)? */
function aut_legal_configured()
{
    $op = aut_legal_operator();
    return $op['name'] !== '' && $op['email'] !== '';
}

/** Legal documents: key => [title, URL slug]. */
function aut_legal_docs()
{
    return array(
        'mentions'       => array(aut_t('LgDocMentions'), 'mentions-legales'),
        'cgu'            => array(aut_t('LgDocCgu'), 'cgu'),
        'confidentialite'=> array(aut_t('LgDocPrivacy'), 'confidentialite'),
        'cookies'        => array(aut_t('LgDocCookies'), 'cookies'),
    );
}

/** Slug → document key (for the URLs). */
function aut_legal_doc_by_slug($slug)
{
    foreach (aut_legal_docs() as $k => $d) if ($d[1] === $slug) return $k;
    return array_key_exists($slug, aut_legal_docs()) ? $slug : '';
}

/** Public URL of a legal document. */
function aut_legal_url($doc)
{
    global $CFG;
    $d = aut_legal_docs()[$doc] ?? null;
    $slug = $d ? $d[1] : $doc;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/legal.php?doc=' . rawurlencode($slug);
}

/* ------------------------------------------------------------------ */
/* Rendering of the texts (editable override, otherwise generation)    */
/* ------------------------------------------------------------------ */

function _le($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Value of an operator field for the text (generic fallback when not filled in). */
function _lop($op, $key, $fallback = '')
{
    $v = trim((string) ($op[$key] ?? ''));
    return $v !== '' ? $v : $fallback;
}

/**
 * HTML of a legal document. When the operator typed a custom text (custom[$doc]), it is used
 * as is (turned into paragraphs); otherwise the text is GENERATED from the operator's
 * information.
 */
function aut_legal_render($doc)
{
    $conf = aut_legal_conf();
    $custom = trim((string) ($conf['custom'][$doc] ?? ''));
    if ($custom !== '') return aut_legal_textify($custom);

    $op = aut_legal_operator();
    switch ($doc) {
        case 'mentions':        return aut_legal_gen_mentions($op);
        case 'cgu':             return aut_legal_gen_cgu($op);
        case 'confidentialite': return aut_legal_gen_confid($op);
        case 'cookies':         return aut_legal_gen_cookies($op);
    }
    return '<p>' . _le(aut_t('LgUnknownDoc')) . '</p>';
}

/** Free text → safe HTML (paragraphs / line breaks, everything escaped). */
function aut_legal_textify($txt)
{
    $blocks = preg_split('/\n{2,}/', trim((string) $txt));
    $h = '';
    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') continue;
        $h .= '<p>' . nl2br(_le($b)) . '</p>';
    }
    return $h ?: '<p></p>';
}

/** Shared disclaimer box (the operator is responsible; module provided "as is"). */
function aut_legal_disclaimer_html($op)
{
    return '<div class="lg-note"><p>' . aut_t('LgDisclaimer', _le(_lop($op, 'name', aut_t('LgOperatorFallback')))) . '</p></div>';
}

/** <h2> title then paragraph, both from the language files (texts with simple markup). */
function aut_legal_section($title, $text)
{
    return '<h2>' . _le(aut_t($title)) . '</h2><p>' . $text . '</p>';
}

/** <ul> of the given keys (texts with simple markup). */
function aut_legal_list($keys)
{
    $h = '<ul>';
    foreach ($keys as $k) $h .= '<li>' . aut_t($k) . '</li>';
    return $h . '</ul>';
}

function aut_legal_gen_mentions($op)
{
    $site = _lop($op, 'site_name', aut_t('LgMSiteFallback'));
    $name = _lop($op, 'name', aut_t('LgMNameFallback'));
    $pub  = _lop($op, 'publisher', $name);
    $h  = '<p>' . aut_t('LgMIntro', _le($site)) . '</p>';
    $h .= '<h2>' . _le(aut_t('LgMPublisherH')) . '</h2><ul>';
    $h .= '<li>' . aut_t('LgMOperator', _le($name)) . ($op['status'] !== '' ? ' (' . _le($op['status']) . ')' : '') . '</li>';
    if ($op['siret'] !== '')   $h .= '<li>' . aut_t('LgMId', _le($op['siret'])) . '</li>';
    if ($op['address'] !== '') $h .= '<li>' . aut_t('LgMAddress', _le($op['address'])) . '</li>';
    if ($op['email'] !== '')   $h .= '<li>' . aut_t('LgMContact', '<a href="mailto:' . _le($op['email']) . '">' . _le($op['email']) . '</a>') . '</li>';
    if ($op['phone'] !== '')   $h .= '<li>' . aut_t('LgMPhone', _le($op['phone'])) . '</li>';
    $h .= '<li>' . aut_t('LgMDirector', _le($pub)) . '</li>';
    $h .= '</ul>';
    $h .= '<h2>' . _le(aut_t('LgMHostingH')) . '</h2>';
    if ($op['host_name'] !== '' || $op['host_address'] !== '') {
        $h .= '<p>' . _le(_lop($op, 'host_name', aut_t('LgMHostFallback'))) . ($op['host_address'] !== '' ? ' — ' . _le($op['host_address']) : '') . '</p>';
    } else {
        $h .= '<p>' . _le(aut_t('LgMHostMissing')) . '</p>';
    }
    $h .= aut_legal_section('LgMIpH', aut_t('LgMIp'));
    $h .= aut_legal_disclaimer_html($op);
    return $h;
}

function aut_legal_gen_cgu($op)
{
    $site = _lop($op, 'site_name', aut_t('LgCSiteFallback'));
    $name = _lop($op, 'name', aut_t('LgOperatorFallback'));
    $h  = '<p>' . aut_t('LgCIntro', array('site' => _le($site), 'name' => _le($name))) . '</p>';
    $h .= aut_legal_section('LgC1H', aut_t('LgC1'));
    $h .= aut_legal_section('LgC2H', aut_t('LgC2'));
    $h .= aut_legal_section('LgC3H', aut_t('LgC3'));
    $h .= aut_legal_section('LgC4H', aut_t('LgC4', _le(aut_legal_url('confidentialite'))));
    $h .= aut_legal_section('LgC5H', aut_t('LgC5'));
    $h .= aut_legal_section('LgC6H', aut_t('LgC6'));
    $h .= aut_legal_section('LgC7H', aut_t('LgC7'));
    $h .= aut_legal_section('LgC8H', aut_t('LgC8'));
    $h .= aut_legal_disclaimer_html($op);
    $h .= '<p class="lg-ver">' . _le(aut_t('LgCVersion', aut_legal_version())) . '</p>';
    return $h;
}

function aut_legal_gen_confid($op)
{
    $name = _lop($op, 'name', aut_t('LgOperatorFallback'));
    $dpo  = _lop($op, 'dpo', _lop($op, 'email', ''));
    $h  = '<p>' . aut_t('LgPIntro') . '</p>';
    $h .= aut_legal_section('LgPControllerH', $op['address'] !== ''
        ? aut_t('LgPControllerAddr', array('name' => _le($name), 'address' => _le($op['address'])))
        : aut_t('LgPController', _le($name)));
    $h .= '<h2>' . _le(aut_t('LgPDataH')) . '</h2>'
        . aut_legal_list(array('LgPData1', 'LgPData2', 'LgPData3', 'LgPData4', 'LgPData5'));
    $h .= '<p>' . aut_t('LgPPassword', _le(aut_legal_url('cookies'))) . '</p>';
    $h .= '<h2>' . _le(aut_t('LgPPurposeH')) . '</h2>'
        . aut_legal_list(array('LgPPurpose1', 'LgPPurpose2', 'LgPPurpose3'));
    $h .= aut_legal_section('LgPRecipientsH', aut_t('LgPRecipients'));
    $h .= '<h2>' . _le(aut_t('LgPRetentionH')) . '</h2>'
        . aut_legal_list(array('LgPRetention1', 'LgPRetention2', 'LgPRetention3'));
    $h .= aut_legal_section('LgPRightsH', aut_t('LgPRights', $dpo !== ''
        ? '<a href="mailto:' . _le($dpo) . '">' . _le($dpo) . '</a>'
        : _le(aut_t('LgPRightsOperator'))));
    $h .= '<p>' . aut_t('LgPFederation') . '</p>';
    $h .= aut_legal_disclaimer_html($op);
    return $h;
}

function aut_legal_gen_cookies($op)
{
    $h  = '<p>' . aut_t('LgKIntro') . '</p>';
    $h .= '<h2>' . _le(aut_t('LgKUsedH')) . '</h2>'
        . aut_legal_list(array('LgKSession', 'LgKAudience'));
    $h .= '<p>' . aut_t('LgKExempt') . '</p>';
    $h .= aut_legal_disclaimer_html($op);
    return $h;
}

/* ------------------------------------------------------------------ */
/* Acceptance of the terms of use (time-stamped + versioned)           */
/* ------------------------------------------------------------------ */

/** Schema: acceptance columns on AUT_Users (organisers). Idempotent. */
function aut_legal_ensure_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $q = safe_r_sql("SHOW COLUMNS FROM AUT_Users LIKE 'AuCguVer'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AUT_Users
            ADD COLUMN AuCguVer VARCHAR(16) NOT NULL DEFAULT '' AFTER AuLastLogin,
            ADD COLUMN AuCguAt  DATETIME NULL AFTER AuCguVer");
    }
}

/** Has the organiser (AUT_Users.AuUsername) accepted the current version of the terms? */
function aut_legal_org_ok($username)
{
    aut_legal_ensure_schema();
    $r = safe_fetch(safe_r_sql("SELECT AuCguVer FROM AUT_Users WHERE AuUsername = " . StrSafe_DB($username)));
    return $r && (string) $r->AuCguVer === (string) aut_legal_version();
}

/** Records the acceptance by an organiser (server date/time + version). */
function aut_legal_org_record($username)
{
    aut_legal_ensure_schema();
    safe_w_sql("UPDATE AUT_Users SET AuCguVer = " . StrSafe_DB(aut_legal_version()) . ", AuCguAt = NOW()
        WHERE AuUsername = " . StrSafe_DB($username));
}

/**
 * ARCHER side (BK_Archers): the column is created by the booking schema (bk_schema, v19).
 * The value already loaded on the archer object is read (bk_current_archer does SELECT a.*).
 */
function aut_legal_archer_ok($archer)
{
    return $archer && (string) ($archer->BaCguVer ?? '') === (string) aut_legal_version();
}

/** Records the acceptance by an archer (server date/time + version). */
function aut_legal_archer_record($archerId)
{
    safe_w_sql("UPDATE BK_Archers SET BaCguVer = " . StrSafe_DB(aut_legal_version()) . ", BaCguAt = NOW()
        WHERE BaId = " . intval($archerId));
}
