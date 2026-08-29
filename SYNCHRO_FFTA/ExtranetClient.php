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
     * Codes cURL qui traduisent une absence de connexion / un serveur injoignable :
     * 5 proxy introuvable, 6 DNS, 7 connexion refusée, 28 délai dépassé, 35 échec TLS.
     * (valeurs numériques : les noms de constantes varient selon les versions de PHP)
     */
    public static function isOffline(int $errno): bool
    {
        return in_array($errno, [5, 6, 7, 28, 35], true);
    }

    /**
     * Message clair à partir d'une erreur réseau : distingue explicitement l'absence de
     * connexion Internet d'un problème d'identifiants ou d'un calendrier vide.
     */
    public static function netMessage(int $errno, string $err, string $base): string
    {
        if (self::isOffline($errno)) {
            return 'Cette étape nécessite une connexion à Internet, et ' . $base . ' est injoignable. '
                 . 'Vérifiez votre connexion réseau puis réessayez. '
                 . 'Il ne s\'agit ni d\'un problème d\'identifiants, ni d\'un calendrier vide.';
        }

        return 'Erreur réseau : ' . $err;
    }

    /** Erreur réseau d'une requête, en message utilisateur + drapeau hors ligne. */
    private function netFail(array $r): array
    {
        $errno = (int) ($r['errno'] ?? 0);

        return [
            'ok'      => false,
            'msg'     => self::netMessage($errno, (string) ($r['error'] ?? ''), $this->base),
            'offline' => self::isOffline($errno),
        ];
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
            'errno' => curl_errno($ch),
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
            return $this->netFail($home);
        }

        $r = $this->request('/', [
            'login[identifiant]' => $user,
            'login[idpassword]'  => $pass,
        ]);

        if ($r['error']) {
            return $this->netFail($r);
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
     * Niveau (search[Pers]) correspondant au libellé du rôle chxMxDrx actuellement actif,
     * ou null si indéterminable (ex. « Mes informations personnelles »).
     *
     * Nécessaire car le présélectionné de search[Pers] sur la page liste est COLLANT à la
     * dernière recherche du compte, PAS au rôle actif : basculer de rôle (chxMxDrx) ne le
     * met pas à jour tout seul — vérifié : après bascule Département → Fédération, la page
     * continuait d'afficher « Département » comme avant, et la recherche à ce niveau erroné
     * ne renvoyait plus aucun résultat. On déduit donc le niveau du rôle actif lui-même,
     * qu'on connaît de façon fiable, plutôt que de faire confiance à ce présélectionné.
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
     * La session extranet portée par le cookie est-elle encore ouverte ?
     * Une seule requête : si l'extranet nous rend la page de login, elle est morte.
     */
    public function session(): array
    {
        $r = $this->request('/gsportive/resultats-integrationtxt.html');

        // Hors ligne : ce n'est PAS une session expirée — il faut le dire distinctement,
        // sinon l'utilisateur croit à un problème d'identifiants.
        if ($r['error']) {
            return $this->netFail($r) + ['roles' => []];
        }
        if (self::isLoginPage($r['body']) || $r['code'] !== 200) {
            return ['ok' => false, 'offline' => false];
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
            return $this->netFail($r);
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
     * Le niveau (search[Pers]) est déduit du rôle chxMxDrx actuellement actif (voir
     * persForRole()) — PAS du présélectionné de la page, qui reste collé à la dernière
     * recherche du compte et ne suit pas un changement de rôle.
     */
    public function listEvents(string $dateFrom, string $dateTo, string $discipline = 'all'): array
    {
        $page = $this->request('/gsportive/resultats-integrationtxt.html');
        if ($page['error']) {
            return $this->netFail($page);
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

        // Priorité au niveau déduit du rôle actif (fiable) ; repli sur le présélectionné de
        // la page si jamais aucun rôle n'était marqué actif (compte à rôle unique, etc.).
        $pers = self::persForRole($this->parseRoles($page['body']))
            ?? $this->selectedOption($page['body'], 'search[Pers]');
        if ($pers !== null) {
            $fields['search[Pers]']    = $pers;
            $fields['search[oldPers]'] = '';
        }

        $r = $this->request('/gsportive/resultats-integrationtxt.html', $fields);

        if ($r['error']) {
            return $this->netFail($r);
        }
        if (self::isLoginPage($r['body'])) {
            return ['ok' => false, 'msg' => 'Session extranet expirée — reconnecte-toi.'];
        }

        $xp     = $this->dom($r['body']);

        // Diagnostic : le nombre annoncé par l'extranet (« Résultats : N ») sert de témoin
        // indépendant du parsing des lignes — si N > 0 mais qu'aucune ligne n'est reconnue,
        // c'est que la structure du tableau diffère à ce niveau (colonnes en plus/en moins),
        // pas un problème de niveau/rôle.
        $rawTotal = null;
        foreach ($xp->query('//h5[contains(@class,"mxgt")]') as $h5) {
            if (preg_match('/R[ée]sultats\s*:\s*(\d+)/u', self::txt($h5), $m)) {
                $rawTotal = (int) $m[1];
                break;
            }
        }

        $events    = [];
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

        return [
            'ok'     => true,
            'events' => $events,
            'diag'   => [
                'pers'      => $pers,           // niveau (search[Pers]) effectivement utilisé
                'raw_total' => $rawTotal,        // « Résultats : N » annoncé par l'extranet
                'parsed'    => count($events),   // lignes que NOUS avons su reconnaître
                'skipped'   => $skippedCols,      // lignes vues mais avec un nombre de colonnes inattendu
            ],
        ];
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
            return $this->netFail($r);
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
            return $this->netFail($r);
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

    // ── Étape 6 : dépôt réel du fichier TXT ──────────────────────────────────

    /**
     * Dépose le fichier résultats sur l'extranet (formulaire insertTxt → EprvGetFile.php).
     * @return array ['ok'=>bool, 'report'=>html, 'msg'=>?, 'relogin'=>?]
     */
    public function deposit(string $vId, string $email, string $txtContent, string $filename = 'resultats.txt'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sfa_dep_');
        file_put_contents($tmp, $txtContent);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base . '/actions/outils/EprvGetFile.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo/synchro-ffta)',
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'EprvId'  => $vId,
                'email'   => $email,
                'submit'  => 'Ok',
                'txtfile' => new CURLFile($tmp, 'text/plain', $filename),
            ],
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = $errno ? curl_error($ch) : '';
        $effUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        @unlink($tmp);

        if ($err || $body === false) {
            return ['ok' => false, 'msg' => self::netMessage($errno, $err, $this->base),
                    'offline' => self::isOffline($errno)];
        }
        if (self::isLoginPage((string) $body) || strpos($effUrl, '/login') !== false) {
            return ['ok' => false, 'msg' => 'Session extranet expirée — reconnecte-toi.', 'relogin' => true];
        }

        $clean = $this->cleanReport((string) $body);

        return ['ok' => true, 'report' => $clean['html'], 'ko' => $clean['ko']];
    }

    /**
     * Nettoie le rapport HTML de l'extranet : retire les scripts, DÉPLIE le détail des KO
     * (bloc #affKO, normalement masqué et ouvert par un bouton JS inopérant hors extranet),
     * retire ce bouton (#btnaffKO), absolutise les liens. Détecte la présence de KO.
     * @return array ['html'=>string, 'ko'=>bool]
     */
    private function cleanReport(string $html): array
    {
        $xp  = $this->dom($html);
        $doc = $xp->document;

        foreach (iterator_to_array($xp->query('//script')) as $s) {
            $s->parentNode->removeChild($s);
        }
        // Bouton « Détail » (toggle JS) inutile ici → on le retire.
        foreach (iterator_to_array($xp->query('//*[@id="btnaffKO"]')) as $b) {
            $b->parentNode->removeChild($b);
        }

        $ko = false;
        // Détail des KO : normalement caché (display:none), on l'affiche.
        foreach ($xp->query('//*[@id="affKO"]') as $d) {
            $d->setAttribute('style', preg_replace('/display\s*:\s*none;?/i', '', $d->getAttribute('style')));
            if (trim($d->textContent) !== '') {
                $ko = true;
            }
        }

        // Nombre de KO > 0 dans le texte, en secours.
        $text = $doc->textContent ?? '';
        if (preg_match('/(\d+)\s*(?:ligne[s]?\s*)?KO\b/i', $text, $m) && (int) $m[1] > 0) {
            $ko = true;
        }
        if (preg_match('/\bKO\b\s*[:=]?\s*(\d+)/i', $text, $m) && (int) $m[1] > 0) {
            $ko = true;
        }

        // Reconstruit le fragment (contenu du body).
        $out  = '';
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
