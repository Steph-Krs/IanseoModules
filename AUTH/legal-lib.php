<?php
/**
 * legal-lib.php — mentions légales, CGU, confidentialité (RGPD) et cookies du SERVEUR.
 *
 * Un serveur « partagé » peut être exploité par n'importe qui (FFTA, ligue, comité, club,
 * particulier…). Les textes légaux doivent donc s'ADAPTER à l'exploitant, et l'auteur du
 * module ne peut être tenu responsable de l'usage qui est fait des serveurs tiers. D'où :
 *  - l'exploitant renseigne ses informations sur une page d'administration (admin/legal.php),
 *    stockées dans legal.local.json (NON versionné) ;
 *  - le module GÉNÈRE des textes complets et conformes à partir de ces informations, que
 *    l'exploitant peut relire et surcharger ;
 *  - une DÉCHARGE claire figure dans les textes : l'exploitant du serveur est seul responsable.
 *
 * Chargé uniquement par les pages qui en ont besoin (pages légales, connexion, page
 * d'acceptation, garde CGU) — pas globalement, pour ne rien alourdir.
 */

if (defined('AUT_LEGAL_LOADED')) return;
define('AUT_LEGAL_LOADED', true);

/** Fichier de configuration légale (non versionné, propre à chaque serveur). */
function aut_legal_file()
{
    return __DIR__ . '/legal.local.json';
}

/** Configuration légale complète (avec défauts). */
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

/** Enregistre la configuration légale (écrase). Retourne true si écrit. */
function aut_legal_save($conf)
{
    $conf['updated'] = date('c');
    $ok = @file_put_contents(aut_legal_file(),
        json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $ok !== false;
}

/** Version courante des CGU (bumper pour re-demander l'acceptation). */
function aut_legal_version()
{
    $v = trim((string) (aut_legal_conf()['version'] ?? '1'));
    return $v !== '' ? $v : '1';
}

/** Champs de l'exploitant (clé => libellé + aide) pour le formulaire d'administration. */
function aut_legal_fields()
{
    return array(
        'site_name'    => array('Nom du service', 'Ex. « Inscriptions en ligne — CD60 ». Affiché aux utilisateurs.'),
        'name'         => array("Exploitant (nom / raison sociale)", "Qui exploite ce serveur (personne, association, comité, club…)."),
        'status'       => array('Statut juridique', 'particulier / association / société / structure publique'),
        'siret'        => array('SIRET / RNA (si applicable)', 'Numéro d\'identification pour une association ou une société.'),
        'address'      => array('Adresse postale', 'Adresse de l\'exploitant.'),
        'email'        => array('E-mail de contact', 'Adresse à laquelle les utilisateurs peuvent écrire.'),
        'phone'        => array('Téléphone (facultatif)', ''),
        'publisher'    => array('Directeur / directrice de la publication', 'Personne responsable de la publication (souvent le représentant légal).'),
        'dpo'          => array('Contact « données personnelles » (DPO)', 'À qui s\'adressent les demandes RGPD (accès, effacement…). E-mail conseillé.'),
        'host_name'    => array('Hébergeur — nom', 'Ex. OVH, Scaleway, un serveur auto-hébergé…'),
        'host_address' => array('Hébergeur — adresse', ''),
    );
}

/** Valeurs de l'exploitant (toutes les clés présentes, vides par défaut). */
function aut_legal_operator()
{
    $op = aut_legal_conf()['operator'];
    $out = array();
    foreach (array_keys(aut_legal_fields()) as $k) $out[$k] = trim((string) ($op[$k] ?? ''));
    return $out;
}

/** L'exploitant a-t-il renseigné le minimum vital (nom + e-mail) ? */
function aut_legal_configured()
{
    $op = aut_legal_operator();
    return $op['name'] !== '' && $op['email'] !== '';
}

/** Documents légaux : clé => [titre, slug d'URL]. */
function aut_legal_docs()
{
    return array(
        'mentions'       => array('Mentions légales', 'mentions-legales'),
        'cgu'            => array("Conditions générales d'utilisation", 'cgu'),
        'confidentialite'=> array('Politique de confidentialité', 'confidentialite'),
        'cookies'        => array('Cookies', 'cookies'),
    );
}

/** Slug → clé de document (pour les URLs). */
function aut_legal_doc_by_slug($slug)
{
    foreach (aut_legal_docs() as $k => $d) if ($d[1] === $slug) return $k;
    return array_key_exists($slug, aut_legal_docs()) ? $slug : '';
}

/** URL publique d'un document légal. */
function aut_legal_url($doc)
{
    global $CFG;
    $d = aut_legal_docs()[$doc] ?? null;
    $slug = $d ? $d[1] : $doc;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/legal.php?doc=' . rawurlencode($slug);
}

/* ------------------------------------------------------------------ */
/* Rendu des textes (surcharge éditable, sinon génération)             */
/* ------------------------------------------------------------------ */

function _le($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Nom de l'exploitant pour le texte (repli générique si non renseigné). */
function _lop($op, $key, $fallback = '')
{
    $v = trim((string) ($op[$key] ?? ''));
    return $v !== '' ? $v : $fallback;
}

/**
 * Rendu HTML d'un document légal. Si l'exploitant a saisi un texte personnalisé
 * (custom[$doc]), il est utilisé tel quel (converti en paragraphes) ; sinon le texte
 * est GÉNÉRÉ à partir des informations de l'exploitant.
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
    return '<p>Document inconnu.</p>';
}

/** Texte libre → HTML sûr (paragraphes / sauts de ligne, tout échappé). */
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

/** Encart de décharge commun (l'exploitant est responsable ; module fourni « tel quel »). */
function aut_legal_disclaimer_html($op)
{
    $name = _lop($op, 'name', 'l\'exploitant de ce serveur');
    return '<div class="lg-note"><p><b>Responsabilité.</b> Ce service est exploité par '
        . _le($name) . '. Le logiciel qui le fait fonctionner (ianseo et le module d\'inscriptions '
        . 'en ligne) est fourni « en l\'état », sans garantie. <b>Seul l\'exploitant du serveur est '
        . 'responsable</b> de sa mise en œuvre, de son contenu, de la sécurité et du traitement des '
        . 'données réalisés sur ce serveur ; les auteurs des logiciels utilisés ne sauraient être tenus '
        . 'responsables de l\'usage qui en est fait ici.</p></div>';
}

function aut_legal_gen_mentions($op)
{
    $site = _lop($op, 'site_name', 'Ce service en ligne');
    $name = _lop($op, 'name', 'Exploitant non renseigné');
    $pub  = _lop($op, 'publisher', $name);
    $h  = '<p>Conformément à la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l\'économie '
        . 'numérique, les présentes mentions légales sont portées à la connaissance des utilisateurs de '
        . '<b>' . _le($site) . '</b>.</p>';
    $h .= '<h2>Éditeur</h2><ul>';
    $h .= '<li>Exploitant : <b>' . _le($name) . '</b>' . ($op['status'] !== '' ? ' (' . _le($op['status']) . ')' : '') . '</li>';
    if ($op['siret'] !== '')   $h .= '<li>Identification : ' . _le($op['siret']) . '</li>';
    if ($op['address'] !== '') $h .= '<li>Adresse : ' . _le($op['address']) . '</li>';
    if ($op['email'] !== '')   $h .= '<li>Contact : <a href="mailto:' . _le($op['email']) . '">' . _le($op['email']) . '</a></li>';
    if ($op['phone'] !== '')   $h .= '<li>Téléphone : ' . _le($op['phone']) . '</li>';
    $h .= '<li>Directeur / directrice de la publication : ' . _le($pub) . '</li>';
    $h .= '</ul>';
    $h .= '<h2>Hébergement</h2>';
    if ($op['host_name'] !== '' || $op['host_address'] !== '') {
        $h .= '<p>' . _le(_lop($op, 'host_name', 'Hébergeur')) . ($op['host_address'] !== '' ? ' — ' . _le($op['host_address']) : '') . '</p>';
    } else {
        $h .= '<p>Hébergeur non renseigné par l\'exploitant.</p>';
    }
    $h .= '<h2>Propriété intellectuelle</h2>';
    $h .= '<p>Le calcul et la publication des résultats reposent sur le logiciel libre <a href="https://www.ianseo.net" '
        . 'target="_blank" rel="noopener">ianseo</a>. La gestion des comptes, des inscriptions et du calendrier est assurée '
        . 'par un module indépendant. Les marques, logos et contenus des fédérations et clubs restent la propriété de leurs '
        . 'titulaires respectifs.</p>';
    $h .= aut_legal_disclaimer_html($op);
    return $h;
}

function aut_legal_gen_cgu($op)
{
    $site = _lop($op, 'site_name', 'le service');
    $name = _lop($op, 'name', 'l\'exploitant de ce serveur');
    $h  = '<p>Les présentes conditions générales d\'utilisation (ci-après « CGU ») régissent l\'accès et '
        . 'l\'usage de <b>' . _le($site) . '</b> (ci-après « le service »), exploité par ' . _le($name) . '. '
        . 'En utilisant le service, l\'utilisateur accepte les présentes CGU.</p>';
    $h .= '<h2>1. Objet</h2><p>Le service permet aux archers licenciés de s\'inscrire en ligne aux compétitions, '
        . 'de consulter le calendrier et leurs documents, et aux organisateurs de gérer leurs compétitions.</p>';
    $h .= '<h2>2. Accès et comptes</h2><p>La connexion s\'effectue avec les identifiants fédéraux de l\'utilisateur '
        . '(espace licencié ou espace dirigeant de la fédération). <b>Le service ne conserve jamais votre mot de passe</b> : '
        . 'il n\'est utilisé que le temps de vous authentifier auprès de la fédération, puis oublié. L\'utilisateur est '
        . 'responsable de la confidentialité de ses identifiants et des actions effectuées depuis son compte.</p>';
    $h .= '<h2>3. Usage autorisé</h2><p>L\'utilisateur s\'engage à utiliser le service conformément à sa destination, '
        . 'aux règlements sportifs applicables et à la réglementation en vigueur, et à ne pas en perturber le fonctionnement '
        . '(tentatives d\'accès non autorisé, injection, surcharge, etc.).</p>';
    $h .= '<h2>4. Données personnelles</h2><p>Le traitement des données personnelles est décrit dans la '
        . '<a href="' . _le(aut_legal_url('confidentialite')) . '">politique de confidentialité</a>.</p>';
    $h .= '<h2>5. Disponibilité</h2><p>Le service est fourni « en l\'état » et « selon disponibilité ». L\'exploitant '
        . 's\'efforce d\'en assurer le bon fonctionnement mais ne garantit ni l\'absence d\'interruption, ni l\'absence '
        . 'd\'erreur. Il peut suspendre ou faire évoluer le service à tout moment.</p>';
    $h .= '<h2>6. Responsabilité</h2><p>L\'exploitant ne saurait être tenu responsable des dommages résultant de '
        . 'l\'utilisation ou de l\'impossibilité d\'utiliser le service, ni des données saisies par les utilisateurs ou les '
        . 'organisateurs. Les résultats sportifs font foi selon les règlements de la fédération, et non selon le seul '
        . 'affichage du service.</p>';
    $h .= '<h2>7. Modification des CGU</h2><p>Les présentes CGU peuvent être modifiées. La version en vigueur est celle '
        . 'publiée sur le service ; une nouvelle acceptation peut être demandée à l\'utilisateur.</p>';
    $h .= '<h2>8. Droit applicable</h2><p>Les présentes CGU sont soumises au droit français. Tout litige relève des '
        . 'tribunaux compétents, à défaut de résolution amiable.</p>';
    $h .= aut_legal_disclaimer_html($op);
    $h .= '<p class="lg-ver">Version des CGU : ' . _le(aut_legal_version()) . '.</p>';
    return $h;
}

function aut_legal_gen_confid($op)
{
    $name = _lop($op, 'name', 'l\'exploitant de ce serveur');
    $dpo  = _lop($op, 'dpo', _lop($op, 'email', ''));
    $h  = '<p>La présente politique décrit le traitement des données personnelles réalisé dans le cadre du service, '
        . 'conformément au Règlement général sur la protection des données (RGPD) et à la loi « Informatique et Libertés ».</p>';
    $h .= '<h2>Responsable de traitement</h2><p>Le responsable du traitement est <b>' . _le($name) . '</b>'
        . ($op['address'] !== '' ? ', ' . _le($op['address']) : '') . '.</p>';
    $h .= '<h2>Données traitées</h2><ul>'
        . '<li>Identité et licence : numéro de licence, nom, prénom, date de naissance, club, catégorie — <b>fournis par '
        . 'la fédération</b> (fichier des licenciés) et réalignés à chaque connexion.</li>'
        . '<li>Inscriptions et documents : compétitions, départs, cibles, souhaits de placement, paiements déclarés, reçus.</li>'
        . '<li>Compte et connexion : jeton de session (haché), adresse IP et navigateur (journal de sécurité), horodatage '
        . 'des connexions et de l\'acceptation des CGU.</li>'
        . '</ul>';
    $h .= '<p><b>Le mot de passe n\'est jamais conservé ni journalisé</b> : il transite uniquement le temps de vous '
        . 'authentifier auprès de la fédération. Aucun cookie de pistage ou de publicité n\'est utilisé (voir '
        . '<a href="' . _le(aut_legal_url('cookies')) . '">Cookies</a>).</p>';
    $h .= '<h2>Finalités et base légale</h2><ul>'
        . '<li>Gérer les inscriptions et la participation aux compétitions (exécution du service / intérêt légitime).</li>'
        . '<li>Assurer la sécurité du service et prévenir les abus (intérêt légitime, journal de connexion).</li>'
        . '<li>Respecter les obligations liées à l\'organisation des compétitions sportives.</li>'
        . '</ul>';
    $h .= '<h2>Destinataires</h2><p>Les données sont accessibles à l\'exploitant du serveur et aux organisateurs des '
        . 'compétitions concernées, pour les seuls besoins de l\'organisation. Elles ne sont ni vendues, ni cédées à des tiers '
        . 'à des fins commerciales.</p>';
    $h .= '<h2>Durées de conservation</h2><ul>'
        . '<li>Sessions : au plus 7 jours, puis suppression automatique.</li>'
        . '<li>Journal de connexion : quelques mois à des fins de sécurité.</li>'
        . '<li>Inscriptions et données de compétition : le temps nécessaire à l\'organisation et à l\'archivage des résultats.</li>'
        . '</ul>';
    $h .= '<h2>Vos droits</h2><p>Vous disposez d\'un droit d\'accès, de rectification, d\'effacement, de limitation et '
        . 'd\'opposition sur vos données. Pour les exercer, contactez '
        . ($dpo !== '' ? '<a href="mailto:' . _le($dpo) . '">' . _le($dpo) . '</a>' : 'l\'exploitant du serveur')
        . '. Vous pouvez également introduire une réclamation auprès de la CNIL (www.cnil.fr).</p>';
    $h .= '<p>Une partie des données provient de la fédération (fichier des licences) : les demandes relatives à ces '
        . 'informations d\'origine peuvent aussi être adressées à la fédération.</p>';
    $h .= aut_legal_disclaimer_html($op);
    return $h;
}

function aut_legal_gen_cookies($op)
{
    $h  = '<p>Ce service utilise le strict minimum de cookies nécessaires à son fonctionnement. <b>Aucun cookie de '
        . 'pistage, de mesure d\'audience ou de publicité n\'est utilisé</b>, et aucune donnée n\'est partagée avec des tiers '
        . 'à ces fins.</p>';
    $h .= '<h2>Cookies utilisés</h2><ul>'
        . '<li><b>Cookie de session</b> (strictement nécessaire) : il permet de vous garder connecté le temps de votre '
        . 'visite. Il ne contient pas d\'information personnelle exploitable (seulement un identifiant de session) et expire '
        . 'à la fin de la session.</li>'
        . '</ul>';
    $h .= '<p>Les cookies strictement nécessaires ne requièrent pas votre consentement préalable (article 82 de la loi '
        . '« Informatique et Libertés »). Vous pouvez configurer votre navigateur pour les refuser, mais le service ne '
        . 'pourrait alors plus vous maintenir connecté.</p>';
    $h .= aut_legal_disclaimer_html($op);
    return $h;
}

/* ------------------------------------------------------------------ */
/* Acceptation des CGU (horodatée + versionnée)                        */
/* ------------------------------------------------------------------ */

/** Schéma : colonnes d'acceptation sur AUT_Users (organisateurs). Idempotent. */
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

/** L'organisateur (AUT_Users.AuUsername) a-t-il accepté la version courante des CGU ? */
function aut_legal_org_ok($username)
{
    aut_legal_ensure_schema();
    $r = safe_fetch(safe_r_sql("SELECT AuCguVer FROM AUT_Users WHERE AuUsername = " . StrSafe_DB($username)));
    return $r && (string) $r->AuCguVer === (string) aut_legal_version();
}

/** Enregistre l'acceptation par un organisateur (date/heure serveur + version). */
function aut_legal_org_record($username)
{
    aut_legal_ensure_schema();
    safe_w_sql("UPDATE AUT_Users SET AuCguVer = " . StrSafe_DB(aut_legal_version()) . ", AuCguAt = NOW()
        WHERE AuUsername = " . StrSafe_DB($username));
}

/**
 * Côté ARCHER (BK_Archers) : la colonne est créée par le schéma booking (bk_schema, v19).
 * On lit la valeur déjà chargée sur l'objet archer (bk_current_archer fait SELECT a.*).
 */
function aut_legal_archer_ok($archer)
{
    return $archer && (string) ($archer->BaCguVer ?? '') === (string) aut_legal_version();
}

/** Enregistre l'acceptation par un archer (date/heure serveur + version). */
function aut_legal_archer_record($archerId)
{
    safe_w_sql("UPDATE BK_Archers SET BaCguVer = " . StrSafe_DB(aut_legal_version()) . ", BaCguAt = NOW()
        WHERE BaId = " . intval($archerId));
}
