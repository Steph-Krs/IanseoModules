<?php
/**
 * lib/mandate.php — mandat de compétition (document imprimable pour l'organisateur).
 *
 * Le mandat est du HTML imprimable (comme les reçus et feuilles de marque, pas un
 * PDF) : son contenu est REMPLI AUTOMATIQUEMENT depuis la compétition (nom, dates,
 * lieu, départs, catégories, tarifs, moyens de paiement), l'organisateur n'ajoutant
 * que des blocs de texte libres, un choix de template et une couleur. Pas de mise en
 * page libre, pas de traitement de texte — le cadre reste simple et cohérent.
 *
 * Les logos (haut-gauche / haut-droit / bas) sont ceux déjà téléversés dans ianseo
 * (Tournament/ManLogo.php : colonnes ToImgL / ToImgR / ToImgB), servis par
 * Common/TourLogo.php — jamais redemandés au module.
 */

if (defined('BK_MANDATE_LOADED')) return;
define('BK_MANDATE_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/ui.php';

/** Templates disponibles : clé => libellé lisible. */
function bk_mandate_templates()
{
    return array(
        'sobre'   => 'Sobre — titre centré, sections classiques',
        'moderne' => 'Moderne — accent latéral coloré',
        'bandeau' => 'Bandeau — en-tête pleine couleur',
        'encadre' => 'Encadré — page entièrement bordée',
        'ligne'   => 'Épuré — filets fins, titres espacés',
        'compact' => 'Compact — dense, tient sur peu de pages',
    );
}

/**
 * Modèles du VISUEL PARTAGEABLE (share.php), distincts de ceux du mandat mais
 * pilotés par la MÊME couleur (identité commune). clé => libellé. Le rendu réel
 * (canvas) vit dans share.php — pour ajouter/modifier un modèle : ajouter une
 * clé ici ET son cas de dessin dans le switch de share.php.
 */
function bk_share_templates()
{
    return array(
        'bandeau' => 'Bandeau — bande colorée en haut, fond clair',
        'degrade' => 'Dégradé — fond plein dégradé, texte clair',
        'encadre' => 'Encadré — fond clair, large bordure colorée',
        'moitie'  => 'Deux tons — haut coloré, bas clair',
        'epure'   => 'Épuré — fond blanc, filets fins',
    );
}

/** Blocs de texte libres proposés (clé => libellé). Un bloc vide n'est pas affiché. */
function bk_mandate_sections()
{
    return array(
        'intro'    => 'Présentation / mot du club',
        'access'   => 'Accès et parking',
        'lodging'  => 'Hébergement',
        'catering' => 'Restauration / buvette',
        'awards'   => 'Récompenses',
        'contact'  => 'Contact',
        'misc'     => 'Informations complémentaires',
    );
}

/** Sections auto-remplies pouvant être masquées (clé => libellé). */
function bk_mandate_auto_sections()
{
    return array(
        'sessions'   => 'Départs et horaires',
        'categories' => 'Armes et catégories',
        'fees'       => 'Tarifs',
        'payment'    => 'Moyens de paiement',
        'shop'       => 'Boutique',
        'register'   => 'Inscriptions en ligne',
    );
}

/**
 * Configuration du mandat (depuis la colonne BcMandate, JSON), avec valeurs par
 * défaut robustes : une compétition jamais configurée produit un mandat correct.
 */
function bk_mandate_get($cfg)
{
    // Défaut : toutes les sections auto affichées (y compris 'shop' — la clé DOIT
    // venir de bk_mandate_auto_sections(), sinon une section ajoutée après coup
    // ne serait jamais relue depuis le JSON — bug vécu sur la boutique).
    $show = array();
    foreach (bk_mandate_auto_sections() as $k => $_l) $show[$k] = 1;
    $d = array(
        'template'       => 'sobre',
        'share_template' => 'bandeau',                  // modèle du visuel partageable (share.php)
        'color'          => '#0254a8',                  // bleu FFTA par défaut (commun mandat + visuel)
        'logos'          => array('L' => 1, 'R' => 1, 'B' => 1),
        'show'           => $show,
        'blocks'         => array(),                    // clé de section => texte libre
    );
    $raw = is_object($cfg) ? ($cfg->BcMandate ?? null) : (is_array($cfg) ? ($cfg['BcMandate'] ?? null) : null);
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (!empty($j['template']) && array_key_exists($j['template'], bk_mandate_templates())) {
                $d['template'] = $j['template'];
            }
            if (!empty($j['share_template']) && array_key_exists($j['share_template'], bk_share_templates())) {
                $d['share_template'] = $j['share_template'];
            }
            if (!empty($j['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $j['color'])) {
                $d['color'] = strtolower($j['color']);
            }
            foreach (array('L', 'R', 'B') as $k) {
                $d['logos'][$k] = empty($j['logos'][$k]) ? 0 : 1;
            }
            foreach ($d['show'] as $k => $_v) {
                $d['show'][$k] = isset($j['show'][$k]) ? (empty($j['show'][$k]) ? 0 : 1) : 1;
            }
            if (!empty($j['blocks']) && is_array($j['blocks'])) {
                foreach (bk_mandate_sections() as $sk => $_l) {
                    $t = trim((string) ($j['blocks'][$sk] ?? ''));
                    if ($t !== '') $d['blocks'][$sk] = $t;
                }
            }
        }
    }
    return $d;
}

/** Construit la config depuis un POST. Les champs sont validés/bornés ici. */
function bk_mandate_from_post($post)
{
    $tpl = (string) ($post['template'] ?? 'sobre');
    if (!array_key_exists($tpl, bk_mandate_templates())) $tpl = 'sobre';

    $stpl = (string) ($post['share_template'] ?? 'bandeau');
    if (!array_key_exists($stpl, bk_share_templates())) $stpl = 'bandeau';

    $color = strtolower((string) ($post['color'] ?? '#0254a8'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#0254a8';

    $logos = array();
    foreach (array('L', 'R', 'B') as $k) $logos[$k] = empty($post['logo_' . $k]) ? 0 : 1;

    $show = array();
    foreach (bk_mandate_auto_sections() as $k => $_l) $show[$k] = empty($post['show_' . $k]) ? 0 : 1;

    $blocks = array();
    foreach (bk_mandate_sections() as $sk => $_l) {
        $t = trim((string) ($post['block_' . $sk] ?? ''));
        if ($t !== '') $blocks[$sk] = mb_substr($t, 0, 4000);
    }
    return array('template' => $tpl, 'share_template' => $stpl, 'color' => $color,
                 'logos' => $logos, 'show' => $show, 'blocks' => $blocks);
}

/** Enregistre la config du mandat (JSON dans BcMandate). Préserve le reste de la ligne. */
function bk_mandate_save($tourId, $data)
{
    bk_schema();
    $tourId = intval($tourId);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $set  = "BcMandate = " . StrSafe_DB($json);
    safe_w_sql("INSERT INTO BK_Competitions SET BcTournament = $tourId, $set
        ON DUPLICATE KEY UPDATE $set");
}

/**
 * Palette dérivée d'une couleur primaire : teinte claire (fonds), teinte foncée
 * (titres) et couleur de texte lisible SUR la primaire (selon la luminance).
 */
function bk_mandate_palette($hex)
{
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $hex)) $hex = '#0254a8';
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $mix = function ($c, $target, $ratio) { return (int) round($c + ($target - $c) * $ratio); };
    $light = sprintf('#%02x%02x%02x', $mix($r, 255, 0.90), $mix($g, 255, 0.90), $mix($b, 255, 0.90));
    $dark  = sprintf('#%02x%02x%02x', $mix($r, 0, 0.30), $mix($g, 0, 0.30), $mix($b, 0, 0.30));
    $lum   = 0.299 * $r + 0.587 * $g + 0.114 * $b;   // contraste du texte sur la primaire
    return array('primary' => strtolower($hex), 'light' => $light, 'dark' => $dark,
                 'on' => ($lum > 150 ? '#20263d' : '#ffffff'));
}

/**
 * Données auto-remplies du mandat depuis la compétition. Retourne null si la
 * compétition n'existe pas. Ne suppose PAS que les inscriptions soient ouvertes
 * (l'organisateur peut préparer le mandat avant l'ouverture).
 */
function bk_mandate_data($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere, ToComDescr, ToCommitee,
                ToWhenFrom, ToWhenTo, ToType, ToTypeName, ToTypeSubRule,
                LENGTH(ToImgL) AS HasL, LENGTH(ToImgR) AS HasR, LENGTH(ToImgB) AS HasB
        FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return null;

    $cfg  = bk_comp_config($tourId);
    $disc = bk_comp_discipline($t->ToType, $t->ToTypeSubRule, $t->ToTypeName);
    $labels = bk_disc_labels();

    $divisions = array();
    $rs = safe_r_sql("SELECT DivDescription FROM Divisions
        WHERE DivTournament = $tourId AND DivAthlete = 1 ORDER BY DivViewOrder, DivId");
    while ($r = safe_fetch($rs)) $divisions[] = $r->DivDescription;

    $classes = array();
    $rs = safe_r_sql("SELECT ClDescription FROM Classes
        WHERE ClTournament = $tourId AND ClAthlete = 1 ORDER BY ClAgeFrom, ClId");
    while ($r = safe_fetch($rs)) $classes[] = $r->ClDescription;

    $pricing = bk_pricing_get($cfg);

    return array(
        'tour'        => $t,
        'cfg'         => $cfg,
        'disc'        => $disc,
        'discLabel'   => $labels[$disc['key']] ?? $disc['key'],
        'region'      => bk_region_name(substr((string) $t->ToCommitee, 0, 2)),
        'sessions'    => bk_comp_sessions($tourId),
        'divisions'   => $divisions,
        'classes'     => $classes,
        'pay'         => bk_payinfo_get($cfg),
        'fee'         => (float) $cfg->BcFee,
        'feeAdvanced' => bk_pricing_is_advanced($pricing),
        'pricing'     => $pricing,
        'deadline'    => $cfg->BcOpenTo ?? null,
        'shop'        => bk_shop_has_items($tourId) ? bk_shop_items($tourId, true) : array(),
    );
}

/**
 * Le mandat est-il consultable par les archers ? Tri-état sur BcShowMandate :
 *  - NULL  (jamais choisi)  → visible dès qu'un mandat existe (défaut demandé) ;
 *  - 1                       → visible (si un mandat existe) ;
 *  - 0                       → masqué.
 * Jamais visible s'il n'y a pas de mandat (rien à montrer).
 */
function bk_mandate_visible($cfg)
{
    // Niveau 2 (publication simple) : le mandat est auto-rempli depuis les données et
    // toujours publié, même sans configuration explicite (BcMandate peut être vide).
    $lvl = intval(is_object($cfg) ? ($cfg->BcPublishLevel ?? 0) : (is_array($cfg) ? ($cfg['BcPublishLevel'] ?? 0) : 0));
    if ($lvl == 2) return true;

    $raw  = is_object($cfg) ? ($cfg->BcMandate ?? null) : (is_array($cfg) ? ($cfg['BcMandate'] ?? null) : null);
    $has  = trim((string) $raw) !== '';
    $flag = is_object($cfg) ? ($cfg->BcShowMandate ?? null) : (is_array($cfg) ? ($cfg['BcShowMandate'] ?? null) : null);
    if ($flag === null || $flag === '') return $has;
    return $has && intval($flag) === 1;
}

/**
 * Documents officiels ianseo relayables (opt-in de l'organisateur). Chaque entrée :
 * clé => ['label','icon','flag' (colonne BK_Competitions), 'script' (générateur du
 * cœur), 'params' (paramètres GET contrôlés)]. Les scripts sont des points d'entrée
 * PDF du cœur (Prn*.php) qui travaillent sur la session de compétition courante.
 */
function bk_doc_defs()
{
    return array(
        'program' => array(
            'label' => 'Programme', 'icon' => '📋',
            'flag' => 'BcShowProgram', 'has' => 'bk_has_program',
            'script' => 'Scheduler/PrnScheduler.php',
            'params' => array('Finalists' => '1', 'PageBreaks' => ''),
        ),
        'participants' => array(
            'label' => 'Participants par club', 'icon' => '👥',
            'flag' => 'BcShowParticipants', 'has' => 'bk_has_participants',
            'script' => 'Partecipants/PrnCountry.php', 'params' => array(),
        ),
        'participants_target' => array(
            'label' => 'Participants par cible', 'icon' => '🎯',
            'flag' => 'BcShowParticipants', 'has' => 'bk_has_placements',
            'script' => 'Partecipants/PrnSession.php', 'params' => array(),
        ),
        // « Résultats » (case BcShowResults) se décline en plusieurs boutons selon
        // l'avancement de la compétition ; chacun n'apparaît qu'avec sa matière.
        'qualifications' => array(
            'label' => 'Résultats — Qualifications', 'icon' => '🏅',
            'flag' => 'BcShowResults', 'has' => 'bk_has_results',
            'script' => 'Qualification/PrnCompleteAbs.php', 'params' => array(),
        ),
        // 'events' => 'ind'/'team' : le relais injecte la LISTE explicite des épreuves
        // (bk_final_events) au lieu de « . » (toutes) — sans quoi le générateur imprime
        // aussi les catégories SANS duel. On reproduit exactement le select id="IndividualEvents"
        // / "TeamEvents" de Final/PrintOut.php (EvFinalFirstPhase != 0), option « tous » exclue.
        'duels_ind' => array(
            'label' => 'Résultats — Duels individuels', 'icon' => '🏹',
            'flag' => 'BcShowResults', 'has' => 'bk_has_ind_finals',
            'script' => 'Final/Individual/PrnIndividual.php', 'events' => 'ind',
            'params' => array('IncRankings' => '1', 'IncBrackets' => '1',
                              'ShowTargetNo' => '1', 'ShowSchedule' => '1', 'OrisABD' => 'AB'),
        ),
        'duels_team' => array(
            'label' => 'Résultats — Matchs par équipe', 'icon' => '🏆',
            'flag' => 'BcShowResults', 'has' => 'bk_has_team_finals',
            'script' => 'Final/Team/PrnTeam.php', 'events' => 'team',
            'params' => array('IncRankings' => '1', 'IncBrackets' => '1',
                              'ShowTargetNo' => '1', 'ShowSchedule' => '1', 'OrisABD' => 'AB'),
        ),
    );
}

/** Y a-t-il au moins un participant inscrit sur cette compétition ? */
function bk_has_participants($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Entries
        WHERE EnTournament = " . intval($tourId) . " AND EnAthlete = 1 LIMIT 1"));
    return (bool) $r;
}

/** Y a-t-il au moins un résultat de qualification (score saisi) ? */
function bk_has_results($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1
        FROM Qualifications q INNER JOIN Entries e ON e.EnId = q.QuId
        WHERE e.EnTournament = " . intval($tourId) . " AND q.QuScore > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Y a-t-il un programme à montrer ? Même logique que le planificateur du cœur
 * (Common/Lib/Fun_Scheduler.php) : soit un élément SAISI À LA MAIN dans le
 * planificateur (table Scheduler), soit un départ HORODATÉ (DistanceInformation
 * avec une date et une heure de début ou d'échauffement).
 */
function bk_has_program($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Scheduler WHERE SchTournament = $tourId LIMIT 1"));
    if ($r) return true;
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM DistanceInformation
        WHERE DiTournament = $tourId AND DiDay > 0 AND (DiStart > 0 OR DiWarmStart > 0) LIMIT 1"));
    return (bool) $r;
}

/** Au moins un archer placé sur une cible ? (pour la liste des participants PAR CIBLE) */
function bk_has_placements($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1
        FROM Qualifications q INNER JOIN Entries e ON e.EnId = q.QuId
        WHERE e.EnTournament = " . intval($tourId) . " AND q.QuTarget > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Des duels INDIVIDUELS ont-ils VRAIMENT été générés ? La table Finals contient la
 * STRUCTURE des grilles (lignes à FinAthlete=0) dès que les épreuves existent ; un
 * duel réel n'existe que quand un archer y est placé → exiger FinAthlete>0 (bug
 * vécu : la 722 avait 102 lignes Finals toutes vides, le bouton s'affichait à tort).
 */
function bk_has_ind_finals($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Finals
        WHERE FinTournament = " . intval($tourId) . " AND FinAthlete > 0 LIMIT 1"));
    return (bool) $r;
}

/** Des matchs par ÉQUIPE réellement générés ? (TeamFinals avec une équipe placée, TfTeam>0) */
function bk_has_team_finals($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM TeamFinals
        WHERE TfTournament = " . intval($tourId) . " AND TfTeam > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Épreuves (EvCode) proposées par le sélecteur d'impression des duels/matchs du cœur
 * (Final/PrintOut.php : select id="IndividualEvents"/"TeamEvents") — celles qui ont une
 * grille de finale (EvFinalFirstPhase != 0), à l'exclusion de l'option « tous » (« . »).
 * Les passer explicitement au générateur évite d'imprimer les catégories sans duel.
 */
function bk_final_events($tourId, $team)
{
    $tourId = intval($tourId);
    $t = $team ? '1' : '0';
    $out = array();
    $rs = safe_r_sql("SELECT EvCode FROM Events
        WHERE EvTeamEvent = '$t' AND EvTournament = $tourId
          AND EvFinalFirstPhase <> 0 AND EvCodeParent = '' ORDER BY EvProgr");
    while ($r = safe_fetch($rs)) $out[] = $r->EvCode;
    return $out;
}

/**
 * Documents de la compétition consultables par les archers. Retour : liste de
 * ['key','label','icon','url', 'external'?]. Mandat (si visible) + lien ianseo.net
 * (si renseigné) + documents officiels ianseo dont l'organisateur a coché la case.
 */
function bk_docs_list($cfg, $tourId)
{
    $tourId = intval($tourId);
    $out = array();
    if (bk_mandate_visible($cfg)) {
        $out[] = array('key' => 'mandat', 'icon' => '📄', 'label' => 'Mandat de la compétition',
            'url' => bk_public_url('mandate.php?t=' . $tourId));
    }
    foreach (bk_doc_defs() as $key => $d) {
        $flag = $d['flag'];
        $on = is_object($cfg) ? ($cfg->$flag ?? 0) : (is_array($cfg) ? ($cfg[$flag] ?? 0) : 0);
        if (intval($on) !== 1) continue;
        // Le document n'a de sens que s'il y a de la matière (bk_has_* du def) :
        // pas de programme sans horaire, de participants sans inscrit, de résultats
        // sans score, de duels sans grille générée.
        if (!empty($d['has']) && function_exists($d['has']) && !call_user_func($d['has'], $tourId)) continue;
        $out[] = array('key' => $key, 'icon' => $d['icon'], 'label' => $d['label'],
            'url' => bk_public_url('document.php?t=' . $tourId . '&doc=' . $key));
    }
    $url = trim((string) (is_object($cfg) ? ($cfg->BcIanseoUrl ?? '') : ($cfg['BcIanseoUrl'] ?? '')));
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        $out[] = array('key' => 'ianseo', 'icon' => '🔗', 'label' => 'Fiche sur ianseo.net',
            'url' => $url, 'external' => true);
    }
    return $out;
}

/**
 * Relais BORNÉ vers un générateur PDF officiel de ianseo. Le point sensible : les
 * générateurs du cœur exigent une session de compétition + une ACL organisateur.
 * On établit donc, LE TEMPS DE CETTE SEULE REQUÊTE et pour CETTE SEULE compétition :
 *  - la session de compétition (CreateTourSession, comme bk_with_tournament) ;
 *  - un droit de lecture BORNÉ à ce code (AUTH_COMP = [code]), JAMAIS AUTH_ROOT
 *    (voir authCheckACL : le grant vient de aut_code_allowed(code), pas d'un rôle
 *    admin) — donc le contexte élevé ne peut toucher aucune autre compétition.
 * La session ENTIÈRE de l'archer est sauvegardée puis restaurée (finally + filet
 * register_shutdown_function : un Output()/exit du script du cœur ne doit jamais
 * laisser l'archer avec un contexte élevé). L'appelant a DÉJÀ vérifié l'archer, le
 * tourId et la case d'autorisation.
 */
function bk_doc_relay($tourId, $code, $spec)
{
    global $CFG;
    $tourId = intval($tourId);

    $saved = $_SESSION;
    $done  = false;
    register_shutdown_function(function () use ($saved, &$done) {
        if (!$done) $_SESSION = $saved;
    });

    try {
        CreateTourSession($tourId);                 // vide la session, pose TourId/TourCode + réglages
        $_SESSION['AUTH_User']   = '__doc_relay__'; // identité factice non nulle (grant conditionné au code)
        $_SESSION['AUTH_ENABLE'] = 1;
        unset($_SESSION['AUTH_ROOT']);              // JAMAIS admin
        $_SESSION['AUTH_COMP']   = array((string) $code);   // borne le droit à CETTE compétition

        foreach ((array) ($spec['params'] ?? array()) as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; }

        // Duels/matchs : liste explicite des épreuves ayant une grille (jamais « tous »,
        // qui imprimerait aussi les catégories sans duel). Voir bk_final_events / bk_doc_defs.
        if (!empty($spec['events'])) {
            $evs = bk_final_events($tourId, $spec['events'] === 'team');
            if ($evs) { $_GET['Event'] = $evs; $_REQUEST['Event'] = $evs; }
        }

        include $CFG->DOCUMENT_PATH . $spec['script'];   // génère et streame le PDF officiel
    } finally {
        $_SESSION = $saved;
        $done = true;
    }
    exit;
}

/**
 * Rend le document HTML AUTONOME du mandat (doctype → /html) et l'imprime.
 * Mutualisé entre l'aperçu organisateur (admin) et la vue publique (archer) :
 * seul le CONTEXTE change.
 *
 * $ctx :
 *   'logo'    => callable($type, $width) : URL d'un logo (L/R/B) — diffère selon
 *                que l'appelant a une session organisateur (TourLogo.php) ou non
 *                (endpoint public borné) ;
 *   'regUrl'  => URL absolue d'inscription en ligne ;
 *   'shopUrl' => URL absolue de la boutique ;
 *   'toolbar' => HTML de la barre supérieure (boutons), ou '' pour l'omettre.
 */
function bk_mandate_document($data, $m, $ctx)
{
    $pal = bk_mandate_palette($m['color']);
    $t   = $data['tour'];
    $logo = $ctx['logo'];
    $regUrl  = (string) ($ctx['regUrl'] ?? '');
    $shopUrl = (string) ($ctx['shopUrl'] ?? '');
    $toolbar = (string) ($ctx['toolbar'] ?? '');

    $block = function ($key) use ($m) {
        if (empty($m['blocks'][$key])) return '';
        return '<div class="mn-free"><h2>' . bk_e(bk_mandate_sections()[$key])
             . '</h2><div class="mn-text">' . nl2br(bk_e($m['blocks'][$key])) . '</div></div>';
    };

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mandat — <?= bk_e($t->ToName) ?></title>
<style>
:root{
  --pri: <?= $pal['primary'] ?>; --light: <?= $pal['light'] ?>;
  --dark: <?= $pal['dark'] ?>; --on: <?= $pal['on'] ?>;
}
*{ box-sizing:border-box; }
body{ margin:0; background:#e9edf2; color:#20263d;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  font-size:14px; line-height:1.5; overflow-wrap:break-word; word-break:break-word; }
.mn-title h1, .mn-text, ul.mn-list li, .mn-chip, .mn-meta td, .mn-reg, .mn-free { overflow-wrap:break-word; word-break:break-word; }
.mn-reg a, .mn-free a { word-break:break-all; }
.mn-bar{ position:sticky; top:0; display:flex; gap:8px; justify-content:center;
  padding:10px; background:#20263d; z-index:5; }
.mn-bar button, .mn-bar a{ font:inherit; font-size:13px; padding:8px 16px; border-radius:6px;
  border:0; cursor:pointer; text-decoration:none; }
.mn-bar .mn-print{ background:var(--pri); color:var(--on); font-weight:600; }
.mn-bar .mn-close{ background:#4a4f63; color:#fff; }
.mn-page{ max-width:800px; margin:18px auto; background:#fff; padding:0;
  box-shadow:0 2px 14px rgba(0,0,0,.14); }
.mn-inner{ padding:26px 34px 34px; }

/* En-tête */
.mn-head{ display:flex; align-items:center; gap:16px; }
.mn-head img{ max-height:64px; max-width:130px; object-fit:contain; flex:0 0 auto; }
.mn-head .mn-title{ flex:1; text-align:center; }
.mn-title h1{ margin:0; font-size:26px; color:var(--dark); line-height:1.15; }
.mn-title .mn-sub{ margin:6px 0 0; color:#4c4e50; font-size:14px; }
.mn-title .mn-org{ margin:2px 0 0; color:#7d8183; font-size:13px; }

/* Sections */
h2{ font-size:16px; margin:22px 0 8px; color:var(--dark);
  border-bottom:2px solid var(--pri); padding-bottom:4px; }
.mn-meta{ width:100%; border-collapse:collapse; }
.mn-meta th,.mn-meta td{ text-align:left; padding:6px 10px; border-bottom:1px solid #e3e6ea; font-size:14px; }
.mn-meta th{ width:170px; color:var(--dark); font-weight:600; white-space:nowrap; }
ul.mn-list{ margin:6px 0 0; padding-left:18px; }
ul.mn-list li{ margin:3px 0; }
.mn-chips{ display:flex; flex-wrap:wrap; gap:6px; margin:4px 0 0; }
.mn-chip{ background:var(--light); border:1px solid var(--pri); color:var(--dark);
  border-radius:5px; padding:2px 9px; font-size:13px; }
.mn-text{ white-space:normal; }
.mn-free{ margin-top:4px; }
.mn-reg{ background:var(--light); border:1px solid var(--pri); border-radius:8px;
  padding:12px 14px; margin-top:6px; }
.mn-reg a{ color:var(--dark); font-weight:600; word-break:break-all; }
.mn-bottom{ text-align:center; margin-top:26px; padding-top:14px; border-top:1px solid #e3e6ea; }
.mn-bottom img{ max-height:70px; max-width:100%; object-fit:contain; }

/* Template : Moderne — accent latéral */
body.tpl-moderne .mn-inner{ border-left:10px solid var(--pri); }
body.tpl-moderne .mn-head .mn-title{ text-align:left; }
body.tpl-moderne .mn-title h1{ font-size:30px; }

/* Template : Compact — dense */
body.tpl-compact{ font-size:13px; }
body.tpl-compact .mn-inner{ padding:18px 24px 24px; }
body.tpl-compact .mn-title h1{ font-size:22px; }
body.tpl-compact h2{ font-size:14px; margin:14px 0 5px; }
body.tpl-compact .mn-meta th,.tpl-compact .mn-meta td{ padding:3px 8px; }

/* Template : Bandeau — en-tête pleine couleur */
body.tpl-bandeau .mn-inner{ padding-top:0; }
body.tpl-bandeau .mn-head{ background:var(--pri); color:var(--on); margin:0 -34px 18px;
  padding:22px 34px; align-items:center; }
body.tpl-bandeau .mn-title h1{ color:var(--on); }
body.tpl-bandeau .mn-title .mn-sub, body.tpl-bandeau .mn-title .mn-org{ color:var(--on); opacity:.92; }
body.tpl-bandeau .mn-head img{ background:#fff; border-radius:6px; padding:4px; }
body.tpl-bandeau h2{ border-bottom-width:3px; }

/* Template : Encadré — page bordée */
body.tpl-encadre .mn-page{ border:3px solid var(--pri); }
body.tpl-encadre .mn-inner{ padding:24px 30px 30px; }
body.tpl-encadre h2{ border:0; background:var(--light); color:var(--dark);
  padding:6px 12px; border-left:5px solid var(--pri); border-radius:0 4px 4px 0; }

/* Template : Épuré — filets fins, titres espacés */
body.tpl-ligne h2{ border-bottom:1px solid var(--pri); text-transform:uppercase;
  letter-spacing:.12em; font-size:13px; font-weight:700; color:var(--pri); }
body.tpl-ligne .mn-chip{ background:#fff; }
body.tpl-ligne .mn-title h1{ font-weight:600; letter-spacing:.01em; }

/* Mobile : logos réduits pour ne pas écraser le titre central sur peu de largeur */
@media (max-width:600px){
  .mn-head{ gap:10px; }
  .mn-head img{ max-height:44px; max-width:74px; }
  .mn-inner{ padding:18px 16px 24px; }
  .mn-title h1{ font-size:21px; }
  body.tpl-bandeau .mn-head{ margin:0 -16px 16px; padding:16px; }
}

@media print{
  body{ background:#fff; }
  .mn-bar{ display:none; }
  .mn-page{ box-shadow:none; margin:0; max-width:none; }
  .mn-inner{ padding:0 6mm; }
  h2{ break-after:avoid; }
  .mn-free,.mn-reg{ break-inside:avoid; }
}
</style>
</head>
<body class="tpl-<?= bk_e($m['template']) ?>">
<?php if ($toolbar !== ''): ?><div class="mn-bar no-print"><?= $toolbar ?></div><?php endif; ?>

<div class="mn-page"><div class="mn-inner">

  <div class="mn-head">
    <?php if (!empty($m['logos']['L']) && intval($t->HasL) > 0): ?>
      <img src="<?= bk_e($logo('L', 400)) ?>" alt="">
    <?php endif; ?>
    <div class="mn-title">
      <h1><?= bk_e($t->ToName) ?></h1>
      <p class="mn-sub"><?= bk_e($data['discLabel']) ?>
        <?= $t->ToWhere ? ' — ' . bk_e($t->ToWhere) : '' ?>
        <?= ' — ' . bk_e(bk_date_range($t->ToWhenFrom, $t->ToWhenTo)) ?></p>
      <?php if ($t->ToComDescr || $data['region']): ?><p class="mn-org">
        <?= $t->ToComDescr ? 'Organisé par ' . bk_e($t->ToComDescr) : '' ?><?= ($t->ToComDescr && $data['region']) ? ' — ' : '' ?><?= $data['region'] ? bk_e($data['region']) : '' ?></p><?php endif; ?>
    </div>
    <?php if (!empty($m['logos']['R']) && intval($t->HasR) > 0): ?>
      <img src="<?= bk_e($logo('R', 400)) ?>" alt="">
    <?php endif; ?>
  </div>

  <?= $block('intro') ?>

  <?php if (!empty($m['show']['sessions']) && $data['sessions']): ?>
    <h2>Départs et horaires</h2>
    <ul class="mn-list">
      <?php foreach ($data['sessions'] as $s):
        $ss = bk_session_start($s); $hh = $ss !== '' ? substr($ss, 11, 5) : ''; ?>
        <li><b>Départ <?= intval($s->SesOrder) ?></b><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?>
          <?php if ($ss !== ''): ?>— <?= bk_e(bk_date_fr($ss)) ?><?= ($hh !== '' && $hh !== '00:00') ? ' à ' . bk_e(str_replace(':', 'h', $hh)) : '' ?><?php endif; ?>
          — <?= intval($s->Places) ?> places</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!empty($m['show']['categories']) && ($data['divisions'] || $data['classes'])): ?>
    <h2>Armes et catégories</h2>
    <?php if ($data['divisions']): ?>
      <p style="margin:0 0 4px"><b>Armes :</b></p>
      <div class="mn-chips"><?php foreach ($data['divisions'] as $dv): ?><span class="mn-chip"><?= bk_e($dv) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($data['classes']): ?>
      <p style="margin:8px 0 4px"><b>Catégories :</b></p>
      <div class="mn-chips"><?php foreach ($data['classes'] as $cl): ?><span class="mn-chip"><?= bk_e($cl) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($m['show']['fees'])): ?>
    <h2>Tarifs</h2>
    <?php if ($data['fee'] <= 0 && !$data['feeAdvanced']): ?>
      <p style="margin:0">Participation gratuite.</p>
    <?php else: ?>
      <p style="margin:0"><b>Tarif de base :</b> <?= bk_e(number_format($data['fee'], 2, ',', ' ')) ?> €</p>
      <?php if ($data['feeAdvanced']): ?>
        <p class="mn-text" style="margin:4px 0 0; color:#4c4e50">Des ajustements peuvent s'appliquer selon
           la catégorie, le départ, la provenance géographique et le rang d'inscription. Le montant exact
           est calculé automatiquement lors de l'inscription en ligne.</p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($m['show']['payment']) && $data['pay']): ?>
    <h2>Moyens de paiement</h2>
    <ul class="mn-list">
      <?php foreach ($data['pay'] as $pi): ?>
        <li><?= bk_e($pi['label']) ?> <span style="color:#7d8183">(<?= bk_e($pi['whenLabel']) ?>)</span><?= $pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '' ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!empty($m['show']['shop']) && !empty($data['shop']) && $shopUrl !== ''): ?>
    <h2>Boutique</h2>
    <ul class="mn-list">
      <?php foreach ($data['shop'] as $it): ?>
        <li><?= bk_e($it['label']) ?><?= $it['price'] > 0 ? ' — ' . bk_e(number_format($it['price'], 2, ',', ' ')) . ' €' : '' ?><?= $it['description'] !== '' ? ' <span style="color:#7d8183">(' . bk_e($it['description']) . ')</span>' : '' ?>
          <?php if (!empty($it['variants'])):
            $vlabels = array();
            foreach ($it['variants'] as $v) { $vl = trim((string) $v['label']); if ($vl !== '') $vlabels[] = $vl; }
            if ($vlabels): ?>
            <div style="color:#4c4e50; font-size:13px"><?= bk_e($it['option'] !== '' ? $it['option'] : 'Options') ?> :
              <?= bk_e(implode(', ', $vlabels)) ?></div>
          <?php endif; endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="mn-text" style="margin:4px 0 0; color:#4c4e50">Commande en ligne lors de l'inscription :
       <a href="<?= bk_e($shopUrl) ?>" style="color:var(--dark)"><?= bk_e($shopUrl) ?></a></p>
  <?php endif; ?>

  <?php if (!empty($m['show']['register']) && $regUrl !== ''): ?>
    <h2>Inscriptions en ligne</h2>
    <div class="mn-reg">
      <p style="margin:0 0 4px">Inscrivez-vous directement en ligne :</p>
      <p style="margin:0"><a href="<?= bk_e($regUrl) ?>"><?= bk_e($regUrl) ?></a></p>
      <?php if (!empty($data['deadline']) && bk_date_fr($data['deadline']) !== ''): ?>
        <p style="margin:6px 0 0"><b>Clôture des inscriptions :</b> le <?= bk_e(bk_date_fr($data['deadline'])) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?= $block('access') ?>
  <?= $block('lodging') ?>
  <?= $block('catering') ?>
  <?= $block('awards') ?>
  <?= $block('misc') ?>
  <?= $block('contact') ?>

  <?php if (!empty($m['logos']['B']) && intval($t->HasB) > 0): ?>
    <div class="mn-bottom"><img src="<?= bk_e($logo('B', 1000)) ?>" alt=""></div>
  <?php endif; ?>

</div></div>
</body>
</html><?php
}
