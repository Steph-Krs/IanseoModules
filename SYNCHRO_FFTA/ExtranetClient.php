<?php
/**
 * Client HTTP de l'extranet FFTA (gsportive / intégration TXT).
 *
 * Le client ne conserve jamais les identifiants : ils ne servent qu'à l'appel
 * login() et seul le cookie de session extranet survit, dans un fichier
 * temporaire 0600 dont le chemin est gardé en session ianseo.
 */
class ExtranetClient
{
    const BASE_PPROD = 'https://pprod-extranet.ffta.fr';
    const BASE_PROD  = 'https://extranet.ffta.fr';

    private $base;
    private $cookieFile;

    public function __construct(string $cookieFile, string $base = self::BASE_PPROD)
    {
        $this->cookieFile = $cookieFile;
        $this->base       = rtrim($base, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * @param array|null $post  champs POST (null = GET)
     * @return array ['code'=>int,'url'=>string,'body'=>string,'error'=>string]
     */
    private function request(string $path, ?array $post = null): array
    {
        $url = (strpos($path, 'http') === 0) ? $path : $this->base . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo/integration-txt)',
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept-Language: fr-FR,fr;q=0.9'],
        ]);

        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body  = curl_exec($ch);
        $res   = [
            'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'url'   => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'body'  => $body === false ? '' : $body,
            'error' => curl_errno($ch) ? curl_error($ch) : '',
        ];
        curl_close($ch);

        return $res;
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

    /** Valeur présélectionnée d'un <select>, ou null s'il est absent. */
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

    /** La page rendue est-elle la page de login ? */
    private static function isLoginPage(string $html): bool
    {
        return strpos($html, 'name="login[identifiant]"') !== false;
    }

    // ── Étape 1 : connexion ──────────────────────────────────────────────────

    public function login(string $user, string $pass): array
    {
        // Récupération du cookie de session avant de poster (comportement navigateur)
        $home = $this->request('/');
        if ($home['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $home['error']];
        }

        $r = $this->request('/', [
            'login[identifiant]' => $user,
            'login[idpassword]'  => $pass,
        ]);

        if ($r['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $r['error']];
        }
        if (self::isLoginPage($r['body'])) {
            return ['ok' => false, 'msg' => 'Identifiants refusés par l\'extranet.'];
        }

        return ['ok' => true, 'roles' => $this->parseRoles($r['body'])];
    }

    /** Sélecteur de rôle (form modDrx) : liste des rôles disponibles. */
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

    /**
     * La session extranet portée par le cookie est-elle encore ouverte ?
     * Une seule requête : si l'extranet nous rend la page de login, elle est morte.
     */
    public function session(): array
    {
        $r = $this->request('/gsportive/resultats-integrationtxt.html');

        if ($r['error'] || self::isLoginPage($r['body']) || $r['code'] !== 200) {
            return ['ok' => false];
        }

        return ['ok' => true, 'roles' => $this->parseRoles($r['body'])];
    }

    // ── Étape 2 : bascule de rôle ────────────────────────────────────────────

    public function switchRole(string $value): array
    {
        $r = $this->request('/', [
            'chxMxDrx' => $value,
            'modMxDrx' => 'Enregistrer',
        ]);

        if ($r['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $r['error']];
        }
        if (self::isLoginPage($r['body'])) {
            return ['ok' => false, 'msg' => 'Session extranet expirée.'];
        }

        return ['ok' => true, 'roles' => $this->parseRoles($r['body'])];
    }

    // ── Étape 3 : liste des épreuves ─────────────────────────────────────────

    /**
     * @param string $dateFrom    jj/mm/aaaa
     * @param string $dateTo      jj/mm/aaaa
     * @param string $discipline  code extranet (T, S, C, 3, B…) ou 'all'
     *
     * Le niveau (search[Pers]) n'est pas choisi ici : on reprend celui que l'extranet
     * présélectionne selon les droits du compte.
     */
    public function listEvents(string $dateFrom, string $dateTo, string $discipline = 'all'): array
    {
        $page = $this->request('/gsportive/resultats-integrationtxt.html');
        if ($page['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $page['error']];
        }
        if (self::isLoginPage($page['body'])) {
            return ['ok' => false, 'msg' => 'Session extranet expirée — reconnecte-toi.'];
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

        $pers = $this->selectedOption($page['body'], 'search[Pers]');
        if ($pers !== null) {
            $fields['search[Pers]']    = $pers;
            $fields['search[oldPers]'] = '';
        }

        $r = $this->request('/gsportive/resultats-integrationtxt.html', $fields);

        if ($r['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $r['error']];
        }
        if (self::isLoginPage($r['body'])) {
            return ['ok' => false, 'msg' => 'Session extranet expirée — reconnecte-toi.'];
        }

        $xp     = $this->dom($r['body']);
        $events = [];
        foreach ($xp->query('//tr[@data-href]') as $tr) {
            if (!preg_match('#epreuve-(\d+)\.html#', $tr->getAttribute('data-href'), $m)) {
                continue;
            }
            $tds = $xp->query('./td', $tr);
            if ($tds->length < 6) {
                continue;
            }

            $etat = self::txt($tds->item(0));
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
                'depot'        => !empty($pills),   // ligne où un dépôt est possible
                'dates'        => self::txt($tds->item(1)),
                'nom'          => self::txt($tds->item(2)),
                'lieu'         => self::txt($tds->item(3)),
                'organisateur' => self::txt($tds->item(4)),
                'carac'        => self::txt($tds->item(5)),
            ];
        }

        return ['ok' => true, 'events' => $events];
    }

    /**
     * Regroupe les deux lignes d'une même compétition « Valide + Para » : l'extranet
     * expose une épreuve pour les résultats des valides et une autre pour les para.
     * On garde la ligne valides comme principale et on rattache l'id de la ligne para
     * (para_id), pour ne présenter qu'une compétition à créer / une entrée à déposer.
     *
     * La ligne para se reconnaît à sa discipline (« Para-… ») en tête des caractéristiques ;
     * attention, la ligne valides contient aussi le mot « Para » via le tag « Valide + Para ».
     */
    public static function groupPara(array $events): array
    {
        $byKey = [];
        foreach ($events as $ev) {
            $key = $ev['nom'] . '|' . $ev['lieu'] . '|' . $ev['organisateur'] . '|' . $ev['dates'];
            $byKey[$key][] = $ev;
        }

        $isPara = function (array $ev): bool {
            return stripos(ltrim($ev['carac']), 'para') === 0;   // discipline en tête = Para-…
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
                    $out[] = $ev;   // para seul (championnat dédié) ou cas atypique : inchangé
                }
            }
        }

        return $out;
    }

    // ── Étape 4 : page d'une épreuve ─────────────────────────────────────────

    public function event(string $id): array
    {
        $r = $this->request('/gsportive/resultats-integrationtxt/epreuve-' . rawurlencode($id) . '.html');

        if ($r['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $r['error']];
        }
        if (self::isLoginPage($r['body'])) {
            return ['ok' => false, 'msg' => 'Session extranet expirée — reconnecte-toi.'];
        }

        $xp = $this->dom($r['body']);

        // Bouton « Intégrer un fichier TXT » : sa présence conditionne le dépôt
        $btn      = $xp->query('//a[contains(@class,"ajxPopInsertTxt")]')->item(0);
        $canInsert = $btn !== null;
        $vId       = $btn ? $btn->getAttribute('rel') : '';

        // Liens PDF / fichiers déjà déposés
        $links = [];
        foreach ($xp->query('//a[contains(@href,".pdf") or contains(@href,".txt")]') as $a) {
            $links[] = ['href' => $a->getAttribute('href'), 'label' => self::txt($a)];
        }

        return [
            'ok'           => true,
            'id'           => $id,
            'details'      => $this->parseBlock($xp, 'Détails de l\'épreuve'),
            // Bloc « Données actuelles » : liste libellé/valeur quand un dépôt existe,
            // simple phrase sinon — on renvoie les deux, l'affichage choisit.
            'donnees'      => $this->parseBlock($xp, 'Données actuelles'),
            'donnees_text' => $this->blockText($xp, 'Données actuelles'),
            'pdf'          => $this->parseBlock($xp, 'PDF Résultats'),
            'pdf_text'     => $this->blockText($xp, 'PDF Résultats'),
            'links'        => $links,
            'can_insert'   => $canInsert,
            'vid'          => $vId ?: $id,
        ];
    }

    /** Bloc « libellé : valeur » d'une carte mxg. */
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

    // ── Étape 5 : cadre de dépôt (formulaire renvoyé par l'extranet) ─────────

    public function insertForm(string $vId): array
    {
        $r = $this->request('/actions/outils/AjaxInsertTxt.php', ['act' => 'file', 'vId' => $vId]);

        if ($r['error']) {
            return ['ok' => false, 'msg' => 'Erreur réseau : ' . $r['error']];
        }
        if (self::isLoginPage($r['body']) || trim($r['body']) === '') {
            return ['ok' => false, 'msg' => 'Cadre de dépôt non renvoyé (session expirée ?).'];
        }

        $xp    = $this->dom($r['body']);
        $form  = $xp->query('//form[@id="insertTxt"]')->item(0);
        $email = $xp->query('//input[@name="email"]')->item(0);
        $eprv  = $xp->query('//input[@name="EprvId"]')->item(0);
        $desc  = $xp->query('//form[@id="insertTxt"]/div')->item(0);

        return [
            'ok'       => true,
            'found'    => $form !== null,
            'email'    => $email ? $email->getAttribute('value') : '',
            'eprv_id'  => $eprv ? $eprv->getAttribute('value') : '',
            'descr'    => $desc ? self::txt($desc) : '',
            'endpoint' => $this->base . '/actions/outils/EprvGetFile.php',
        ];
    }
}
