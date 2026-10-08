<?php
/**
 * HTTP client of the FFTA extranet (calendar and TXT results deposit).
 *
 * The client never keeps credentials: they only serve the login() call, and only the extranet
 * session cookie survives, in a 0600 temporary file whose path is kept in the ianseo session.
 *
 * Every request goes through FftaHttp (pacing, pauses, anti-bot detection). To send as few
 * requests as possible, what the extranet pages tell us about the session (roles, search level,
 * disciplines) and the latest answers are kept for a few minutes in the ianseo session.
 */
require_once(__DIR__ . '/FftaHttp.php');

class ExtranetClient
{
    const BASE_PPROD = 'https://pprod-extranet.ffta.fr';
    const BASE_PROD  = 'https://extranet.ffta.fr';

    // Page context (roles, level, disciplines): lets a search skip re-reading the page first.
    const CTX_TTL = 600;

    // Answers (event list, event page, deposit frame): repeated clicks cost no request.
    const CACHE_TTL  = 120;
    const CACHE_KEEP = 6;

    const LIST_PAGE = '/gsportive/resultats-integrationtxt.html';

    private $base;
    private $cookieFile;
    private $http;

    public function __construct(string $cookieFile, string $base = self::BASE_PPROD)
    {
        $this->cookieFile = $cookieFile;
        $this->base       = rtrim($base, '/');
        $this->http       = new FftaHttp($cookieFile);
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * @param array|null $post  POST fields (null = GET)
     * @return array see FftaHttp::request()
     */
    private function request(string $path, ?array $post = null): array
    {
        $url = (strpos($path, 'http') === 0) ? $path : $this->base . $path;

        return $this->http->request($url, $post, ['httpHeaders' => ['Accept-Language: fr-FR,fr;q=0.9']]);
    }

    /** Failure array when the request brought nothing usable (delay, network, block), else null. */
    private function fail(array $r): ?array
    {
        return FftaHttp::failure($r, $this->base);
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    private static function txt(?DOMNode $n): string
    {
        if ($n === null) {
            return '';
        }
        $s = preg_replace('/\s+/u', ' ', $n->textContent);

        return trim(str_replace("\xC2\xA0", ' ', $s));
    }

    /** Preselected value of a <select>, or null when the select is absent. */
    private function selectedOption(string $html, string $name): ?string
    {
        $xp   = $this->dom($html);
        $opts = $xp->query('//select[@name="' . $name . '"]/option');
        if (!$opts->length) {
            return null;
        }
        foreach ($opts as $o) {
            if ($o->hasAttribute('selected')) {
                return $o->getAttribute('value');
            }
        }

        return $opts->item(0)->getAttribute('value');
    }

    /** Is the rendered page the login page? */
    private static function isLoginPage(string $html): bool
    {
        return strpos($html, 'name="login[identifiant]"') !== false;
    }

    /** Message for an unexpected login page: an embedded captcha explains it, if there is one. */
    private function expiredMessage(array $r): array
    {
        if ($r['captcha']) {
            return ['ok' => false, 'blocked' => true, 'msg' => FftaHttp::blockMessage('captcha', $this->base)];
        }

        return ['ok' => false, 'msg' => 'Session extranet expirée — reconnecte-toi.', 'relogin' => true];
    }

    // ── Session cache ────────────────────────────────────────────────────────

    private function ctxKey(): string
    {
        return 'SFA_EXT_' . md5($this->base . '|' . $this->cookieFile);
    }

    /** Fresh page context, or [] when absent or too old. */
    private function ctx(): array
    {
        $c = $_SESSION[$this->ctxKey()]['ctx'] ?? [];

        return (time() - ($c['t'] ?? 0) < self::CTX_TTL) ? $c : [];
    }

    /**
     * Keeps what an extranet page tells about the session. Every page carries the role selector;
     * only the results integration page carries the level and the disciplines.
     */
    private function remember(string $html): void
    {
        $roles = $this->parseRoles($html);
        if (!$roles) {
            return;
        }
        $ctx = $this->ctx();
        $ctx['t']     = time();
        $ctx['roles'] = $roles;
        // Never the previous value: after a role switch it belongs to another role.
        $ctx['pers']  = self::persForRole($roles) ?? $this->selectedOption($html, 'search[Pers]');
        $disc = $this->parseDisciplines($html);
        if ($disc) {
            $ctx['disciplines'] = $disc;
        }
        $_SESSION[$this->ctxKey()]['ctx'] = $ctx;
    }

    private function cacheGet(string $key)
    {
        $c = $_SESSION[$this->ctxKey()]['cache'][$key] ?? null;

        return ($c && time() - $c['t'] < self::CACHE_TTL) ? $c['v'] : null;
    }

    private function cacheSet(string $key, $value): void
    {
        $cache = $_SESSION[$this->ctxKey()]['cache'] ?? [];
        $cache[$key] = ['t' => time(), 'v' => $value];
        uasort($cache, function ($a, $b) {
            return $b['t'] <=> $a['t'];
        });
        $_SESSION[$this->ctxKey()]['cache'] = array_slice($cache, 0, self::CACHE_KEEP, true);
    }

    /** Forgets the cached answers (after a deposit or a role change, they are out of date). */
    public function forgetAnswers(): void
    {
        unset($_SESSION[$this->ctxKey()]['cache']);
    }

    /** Extranet disciplines last read on the integration page: [['value','label'], …]. */
    public function disciplines(): array
    {
        return $this->ctx()['disciplines'] ?? [];
    }

    // ── Step 1: login ────────────────────────────────────────────────────────

    public function login(string $user, string $pass): array
    {
        // Session cookie fetched before posting (browser behaviour)
        $home = $this->request('/');
        if ($f = $this->fail($home)) {
            return $f;
        }

        $r = $this->request('/', [
            'login[identifiant]' => $user,
            'login[idpassword]'  => $pass,
        ]);

        if ($f = $this->fail($r)) {
            return $f;
        }
        if (self::isLoginPage($r['body'])) {
            if ($r['captcha']) {
                return ['ok' => false, 'blocked' => true, 'msg' => FftaHttp::blockMessage('captcha', $this->base)];
            }

            return ['ok' => false, 'msg' => 'Identifiants refusés par l\'extranet.'];
        }

        $this->forgetAnswers();
        $this->remember($r['body']);

        return ['ok' => true, 'roles' => $this->parseRoles($r['body'])];
    }

    /** Role selector (form modDrx): available roles. */
    private function parseRoles(string $html): array
    {
        $xp    = $this->dom($html);
        $roles = [];
        foreach ($xp->query('//select[@name="chxMxDrx"]/option') as $opt) {
            $roles[] = [
                'value'    => $opt->getAttribute('value'),
                'label'    => self::txt($opt),
                'selected' => $opt->hasAttribute('selected'),
            ];
        }

        return $roles;
    }

    /** Discipline filter of the integration page (search[Discipline]), « all » excluded. */
    private function parseDisciplines(string $html): array
    {
        $xp  = $this->dom($html);
        $out = [];
        foreach ($xp->query('//select[@name="search[Discipline]"]/option') as $opt) {
            $v = $opt->getAttribute('value');
            if ($v !== '' && $v !== 'all') {
                $out[] = ['value' => $v, 'label' => self::txt($opt)];
            }
        }

        return $out;
    }

    /**
     * Search level (search[Pers]) matching the label of the active chxMxDrx role, or null when it
     * cannot be told (e.g. the personal information role).
     *
     * Needed because the preselected search[Pers] of the list page STICKS to the account's last
     * search, NOT to the active role: switching role (chxMxDrx) does not update it — checked:
     * after switching from the department role to the federation one, the page still showed the
     * department level, and a search at that wrong level returned nothing. The level is therefore taken from the active role
     * itself, which is reliably known, rather than from that preselection.
     */
    private static function persForRole(array $roles): ?string
    {
        foreach ($roles as $r) {
            if (empty($r['selected'])) {
                continue;
            }
            if (stripos($r['label'], 'informations personnelles') !== false) {
                return null;
            }
            if (preg_match('/F[ée]d[ée]ration/iu', $r['label'])) {
                return 'FED';
            }
            if (preg_match('/R[ée]gional|Ligue/iu', $r['label'])) {
                return 'LIG';
            }
            if (preg_match('/D[ée]partement(al)?/iu', $r['label'])) {
                return 'DEP';
            }
            if (preg_match('/Club/iu', $r['label'])) {
                return 'CLU';
            }

            return null;
        }

        return null;
    }

    /**
     * Is the session carried by the cookie still open? One request: when the extranet answers with
     * its login page, the session is dead.
     */
    public function session(): array
    {
        $r = $this->request(self::LIST_PAGE);

        // Offline, delayed or blocked is NOT an expired session — it must be said distinctly,
        // otherwise the user believes in a credentials problem.
        if ($f = $this->fail($r)) {
            return $f + ['roles' => []];
        }
        if (self::isLoginPage($r['body']) || $r['code'] !== 200) {
            return ['ok' => false, 'offline' => false];
        }

        $this->remember($r['body']);

        return ['ok' => true, 'roles' => $this->parseRoles($r['body'])];
    }

    // ── Step 2: role switch ──────────────────────────────────────────────────

    public function switchRole(string $value): array
    {
        $r = $this->request('/', [
            'chxMxDrx' => $value,
            'modMxDrx' => 'Enregistrer',
        ]);

        if ($f = $this->fail($r)) {
            return $f;
        }
        if (self::isLoginPage($r['body'])) {
            return $this->expiredMessage($r);
        }

        $this->forgetAnswers();   // the events visible depend on the role
        $this->remember($r['body']);

        return ['ok' => true, 'roles' => $this->parseRoles($r['body'])];
    }

    // ── Step 3: event list ───────────────────────────────────────────────────

    /**
     * @param string $dateFrom    dd/mm/yyyy
     * @param string $dateTo      dd/mm/yyyy
     * @param string $discipline  extranet code (T, S, C, 3, B…) or 'all'
     *
     * The level (search[Pers]) comes from the active chxMxDrx role (see persForRole()) — NOT from
     * the page's preselection, which sticks to the account's last search.
     */
    public function listEvents(string $dateFrom, string $dateTo, string $discipline = 'all'): array
    {
        // Without a fresh context, the page is read once: it checks the session and gives the level.
        $ctx = $this->ctx();
        if (empty($ctx['pers'])) {
            $page = $this->request(self::LIST_PAGE);
            if ($f = $this->fail($page)) {
                return $f;
            }
            if (self::isLoginPage($page['body'])) {
                return $this->expiredMessage($page);
            }
            $this->remember($page['body']);
            $ctx = $this->ctx();
        }
        $pers = $ctx['pers'] ?? null;

        $cacheKey = 'list|' . $dateFrom . '|' . $dateTo . '|' . $discipline . '|' . $pers;
        if (($cached = $this->cacheGet($cacheKey)) !== null) {
            return $cached + ['disciplines' => $this->disciplines()];
        }

        $fields = [
            'operation'               => 'search',
            'search[Discipline]'      => $discipline,
            'search[typeChamp]'       => 'all',
            'search[Etat]'            => 'all',
            'search[EprvEtranger]'    => 'N',
            'search[EprvDistinction]' => 'TOUS',
            'search[Date_dbt]'        => $dateFrom,
            'search[Date_fin]'        => $dateTo,
            'StartGen'                => 'Filtrer',
        ];
        if ($pers !== null) {
            $fields['search[Pers]']    = $pers;
            $fields['search[oldPers]'] = '';
        }

        $r = $this->request(self::LIST_PAGE, $fields);

        if ($f = $this->fail($r)) {
            return $f;
        }
        if (self::isLoginPage($r['body'])) {
            return $this->expiredMessage($r);
        }
        $this->remember($r['body']);

        $xp = $this->dom($r['body']);

        // Diagnostic: the count announced by the extranet (« Résultats : N ») is a witness
        // independent of the row parsing — N > 0 with no row recognised means the table layout
        // differs at this level, not a level/role problem.
        $rawTotal = null;
        foreach ($xp->query('//h5[contains(@class,"mxgt")]') as $h5) {
            if (preg_match('/R[ée]sultats\s*:\s*(\d+)/u', self::txt($h5), $m)) {
                $rawTotal = (int) $m[1];
                break;
            }
        }

        $events      = [];
        $skippedCols = 0;
        foreach ($xp->query('//tr[@data-href]') as $tr) {
            if (!preg_match('#epreuve-(\d+)\.html#', $tr->getAttribute('data-href'), $m)) {
                continue;
            }
            $tds = $xp->query('./td', $tr);
            if ($tds->length < 6) {
                $skippedCols++;
                continue;
            }

            $etat  = self::txt($tds->item(0));
            $pills = [];
            foreach ($xp->query('.//span[contains(@class,"pill")]', $tds->item(0)) as $p) {
                $cls = $p->getAttribute('class');
                $pills[self::txt($p)] = strpos($cls, 'green') !== false ? 'ok'
                    : (strpos($cls, 'red') !== false ? 'ko' : 'vide');
            }

            $events[] = [
                'id'           => $m[1],
                'etat'         => $etat,
                'pills'        => $pills,
                'depot'        => !empty($pills),   // row where a deposit is possible
                'dates'        => self::txt($tds->item(1)),
                'nom'          => self::txt($tds->item(2)),
                'lieu'         => self::txt($tds->item(3)),
                'organisateur' => self::txt($tds->item(4)),
                'carac'        => self::txt($tds->item(5)),
            ];
        }

        $res = [
            'ok'     => true,
            'events' => $events,
            'diag'   => [
                'pers'      => $pers,            // level (search[Pers]) actually used
                'raw_total' => $rawTotal,        // « Résultats : N » announced by the extranet
                'parsed'    => count($events),   // rows WE could read
                'skipped'   => $skippedCols,     // rows seen with an unexpected number of columns
            ],
        ];
        $this->cacheSet($cacheKey, $res);

        return $res + ['disciplines' => $this->disciplines()];
    }

    /**
     * Merges the two rows of one « Valide + Para » competition: the extranet exposes one event for
     * the able-bodied results and another for the para ones. The able-bodied row is kept as the
     * main one with the para row's id attached (para_id), so that one competition is offered.
     *
     * The para row is told by its discipline (« Para-… ») at the head of the characteristics;
     * beware, the able-bodied row also contains « Para » through the « Valide + Para » tag.
     */
    public static function groupPara(array $events): array
    {
        $byKey = [];
        foreach ($events as $ev) {
            $key = $ev['nom'] . '|' . $ev['lieu'] . '|' . $ev['organisateur'] . '|' . $ev['dates'];
            $byKey[$key][] = $ev;
        }

        $isPara = function (array $ev): bool {
            return stripos(ltrim($ev['carac']), 'para') === 0;   // discipline first = Para-…
        };

        $out = [];
        foreach ($byKey as $group) {
            if (count($group) < 2) {
                $out[] = $group[0];
                continue;
            }

            $main = $para = null;
            $rest = [];
            foreach ($group as $ev) {
                if ($isPara($ev) && $para === null) {
                    $para = $ev;
                } elseif (!$isPara($ev) && $main === null) {
                    $main = $ev;
                } else {
                    $rest[] = $ev;
                }
            }

            if ($main && $para) {
                $main['para']    = true;
                $main['para_id'] = $para['id'];
                $out[] = $main;
                foreach ($rest as $r) {
                    $out[] = $r;
                }
            } else {
                foreach ($group as $ev) {
                    $out[] = $ev;   // para alone (dedicated championship) or atypical case: unchanged
                }
            }
        }

        return $out;
    }

    // ── Step 4: event page ───────────────────────────────────────────────────

    public function event(string $id): array
    {
        $cacheKey = 'event|' . $id;
        if (($cached = $this->cacheGet($cacheKey)) !== null) {
            return $cached;
        }

        $r = $this->request('/gsportive/resultats-integrationtxt/epreuve-' . rawurlencode($id) . '.html');

        if ($f = $this->fail($r)) {
            return $f;
        }
        if (self::isLoginPage($r['body'])) {
            return $this->expiredMessage($r);
        }

        $xp = $this->dom($r['body']);

        // « Intégrer un fichier TXT » button: its presence allows the deposit
        $btn       = $xp->query('//a[contains(@class,"ajxPopInsertTxt")]')->item(0);
        $canInsert = $btn !== null;
        $vId       = $btn ? $btn->getAttribute('rel') : '';

        // PDF / files already deposited
        $links = [];
        foreach ($xp->query('//a[contains(@href,".pdf") or contains(@href,".txt")]') as $a) {
            $links[] = ['href' => $a->getAttribute('href'), 'label' => self::txt($a)];
        }

        $res = [
            'ok'           => true,
            'id'           => $id,
            'details'      => $this->parseBlock($xp, 'Détails de l\'épreuve'),
            // « Données actuelles » block: label/value list when a deposit exists, a plain
            // sentence otherwise — both are returned, the page chooses.
            'donnees'      => $this->parseBlock($xp, 'Données actuelles'),
            'donnees_text' => $this->blockText($xp, 'Données actuelles'),
            'pdf'          => $this->parseBlock($xp, 'PDF Résultats'),
            'pdf_text'     => $this->blockText($xp, 'PDF Résultats'),
            'links'        => $links,
            'can_insert'   => $canInsert,
            'vid'          => $vId ?: $id,
        ];
        $this->cacheSet($cacheKey, $res);

        return $res;
    }

    /** « label : value » block of an mxg card. */
    private function parseBlock(DOMXPath $xp, string $title): array
    {
        $c = $this->blockNode($xp, $title);
        if (!$c) {
            return [];
        }

        $cells = [];
        foreach ($xp->query('./div', $c) as $div) {
            if (strpos($div->getAttribute('class'), 'cl') !== false) {
                continue;
            }
            $cells[] = self::txt($div);
        }

        $out = [];
        for ($i = 0; $i + 1 < count($cells); $i += 2) {
            $label = rtrim($cells[$i], ' :');
            if ($label !== '') {
                $out[$label] = $cells[$i + 1];
            }
        }

        return $out;
    }

    private function blockText(DOMXPath $xp, string $title): string
    {
        $c = $this->blockNode($xp, $title);

        return $c ? self::txt($c) : '';
    }

    private function blockNode(DOMXPath $xp, string $title): ?DOMNode
    {
        foreach ($xp->query('//h5[contains(@class,"mxgt")]') as $h5) {
            if (mb_strpos(self::txt($h5), $title) !== false) {
                return $xp->query('following-sibling::div[contains(@class,"mxgc")][1]', $h5)->item(0);
            }
        }

        return null;
    }

    // ── Step 5: deposit frame (form returned by the extranet) ───────────────

    public function insertForm(string $vId): array
    {
        $cacheKey = 'insert|' . $vId;
        if (($cached = $this->cacheGet($cacheKey)) !== null) {
            return $cached;
        }

        $r = $this->request('/actions/outils/AjaxInsertTxt.php', ['act' => 'file', 'vId' => $vId]);

        if ($f = $this->fail($r)) {
            return $f;
        }
        if (self::isLoginPage($r['body']) || trim($r['body']) === '') {
            return ['ok' => false, 'msg' => 'Cadre de dépôt non renvoyé (session expirée ?).'];
        }

        $xp    = $this->dom($r['body']);
        $form  = $xp->query('//form[@id="insertTxt"]')->item(0);
        $email = $xp->query('//input[@name="email"]')->item(0);
        $eprv  = $xp->query('//input[@name="EprvId"]')->item(0);
        $desc  = $xp->query('//form[@id="insertTxt"]/div')->item(0);

        $res = [
            'ok'       => true,
            'found'    => $form !== null,
            'email'    => $email ? $email->getAttribute('value') : '',
            'eprv_id'  => $eprv ? $eprv->getAttribute('value') : '',
            'descr'    => $desc ? self::txt($desc) : '',
            'endpoint' => $this->base . '/actions/outils/EprvGetFile.php',
        ];
        $this->cacheSet($cacheKey, $res);

        return $res;
    }

    // ── Step 6: actual deposit of the TXT file ───────────────────────────────

    /**
     * Deposits the results file on the extranet (insertTxt form → EprvGetFile.php).
     * @return array ['ok'=>bool, 'report'=>html, 'msg'=>?, 'relogin'=>?, 'wait'=>?]
     */
    public function deposit(string $vId, string $email, string $txtContent, string $filename = 'resultats.txt'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sfa_dep_');
        file_put_contents($tmp, $txtContent);

        $r = $this->http->request($this->base . '/actions/outils/EprvGetFile.php', [
            'EprvId'  => $vId,
            'email'   => $email,
            'submit'  => 'Ok',
            'txtfile' => new CURLFile($tmp, 'text/plain', $filename),
        ], ['multipart' => true, 'timeout' => 90]);
        @unlink($tmp);

        // Whatever happened, the event state shown before is no longer reliable.
        $this->forgetAnswers();

        if ($f = $this->fail($r)) {
            return $f;
        }
        if (self::isLoginPage($r['body']) || strpos($r['url'], '/login') !== false) {
            return $this->expiredMessage($r);
        }

        $clean = $this->cleanReport($r['body']);

        return ['ok' => true, 'report' => $clean['html'], 'ko' => $clean['ko']];
    }

    /**
     * Cleans the extranet's HTML report: removes scripts, UNFOLDS the KO details (#affKO block,
     * normally hidden and opened by a JS button that does not work outside the extranet), removes
     * that button (#btnaffKO), makes links absolute. Detects whether there are KO lines.
     * @return array ['html'=>string, 'ko'=>bool]
     */
    private function cleanReport(string $html): array
    {
        $xp  = $this->dom($html);
        $doc = $xp->document;

        foreach (iterator_to_array($xp->query('//script')) as $s) {
            $s->parentNode->removeChild($s);
        }
        // « Détail » button (JS toggle) is useless here.
        foreach (iterator_to_array($xp->query('//*[@id="btnaffKO"]')) as $b) {
            $b->parentNode->removeChild($b);
        }

        $ko = false;
        // KO details: normally hidden (display:none), shown here.
        foreach ($xp->query('//*[@id="affKO"]') as $d) {
            $d->setAttribute('style', preg_replace('/display\s*:\s*none;?/i', '', $d->getAttribute('style')));
            if (trim($d->textContent) !== '') {
                $ko = true;
            }
        }

        // Number of KO > 0 in the text, as a fallback.
        $text = $doc->textContent ?? '';
        if (preg_match('/(\d+)\s*(?:ligne[s]?\s*)?KO\b/i', $text, $m) && (int) $m[1] > 0) {
            $ko = true;
        }
        if (preg_match('/\bKO\b\s*[:=]?\s*(\d+)/i', $text, $m) && (int) $m[1] > 0) {
            $ko = true;
        }

        // Rebuilds the fragment (body content).
        $out   = '';
        $bodyN = $doc->getElementsByTagName('body')->item(0);
        if ($bodyN) {
            foreach ($bodyN->childNodes as $c) {
                $out .= $doc->saveHTML($c);
            }
        } else {
            $out = $doc->saveHTML();
        }

        $out = preg_replace('#(href|src)=(["\'])/(?!/)#i', '$1=$2' . $this->base . '/', $out);
        $out = preg_replace('#(href|src)=(["\'])\./#i', '$1=$2' . $this->base . '/', $out);

        return ['html' => trim($out), 'ko' => $ko];
    }
}
