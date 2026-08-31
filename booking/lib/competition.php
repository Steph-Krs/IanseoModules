<?php
/**
 * lib/competition.php — ouverture des inscriptions par compétition.
 *
 * La configuration TERRAIN n'est pas ici : elle est déjà saisie dans ianseo
 * (Session, DistanceInformation, TargetFaces, TournamentDistances) et lue telle
 * quelle. BK_Competitions ne porte que ce qui relève des inscriptions en ligne.
 *
 * ⚠️ Dates : toutes les comparaisons se font côté SQL (NOW()), jamais avec
 * time() PHP. ianseo force PHP en UTC (config.php) et change le time_zone MySQL
 * par compétition : MySQL est la seule horloge commune aux deux faces.
 */

if (defined('BK_COMP_LOADED')) return;
define('BK_COMP_LOADED', true);

require_once __DIR__ . '/schema.php';

/** Périmètres de restriction géographique proposés. */
function bk_restrict_kinds()
{
    return array(
        ''   => 'Aucune restriction',
        'CD' => 'Département',
        'CR' => 'Région (ligue)',
    );
}

/**
 * Motif LIKE des agréments de club couverts par une restriction.
 * Agrément FFTA = LLDDCCC (ligue 2 + département 2 + club 3), même convention
 * que le module AUTH — réimplémentée ici pour rester autonome.
 */
function bk_scope_like($kind, $code)
{
    $code = trim((string) $code);
    if ($code === '') return '%';
    if (preg_match('/[_%]/', $code)) return $code;      // motif expert tel quel
    if ($kind === 'CD') return '__' . $code . '%';      // département en position 3-4
    return $code . '%';                                 // région : préfixe
}

/** Contrôle de saisie d'un périmètre. Chaîne vide = valide. */
function bk_scope_error($kind, $code)
{
    if ($kind === '') return '';
    $code = trim((string) $code);
    if ($code === '') return 'Indiquez le code du périmètre.';
    if (preg_match('/^[0-9A-Za-z_%]{2,12}$/', $code) && preg_match('/[_%]/', $code)) return '';
    if (!preg_match('/^[0-9A-Za-z]{2,10}$/', $code)) {
        return 'Le code doit contenir 2 à 10 caractères alphanumériques (ou un motif avec % / _).';
    }
    if ($kind === 'CD' && strlen($code) != 2) return 'Département : 2 chiffres attendus (ex. 60 pour l\'Oise).';
    if ($kind === 'CR' && strlen($code) != 2) return 'Région : 2 chiffres attendus (ex. 07).';
    return '';
}

/**
 * Fragment SQL des colonnes calculées par MySQL. `o` = alias de BK_Competitions.
 *  BcIsOpen  : les inscriptions sont ouvertes en ce moment
 *  BcAllOpen : la restriction géographique est levée (pas de restriction, ou
 *              date d'ouverture à tous atteinte)
 */
function bk_comp_calc_sql($a = 'o')
{
    return "($a.BcOpen = 1
              AND ($a.BcOpenFrom IS NULL OR $a.BcOpenFrom <= NOW())
              AND ($a.BcOpenTo   IS NULL OR $a.BcOpenTo   >= NOW())) AS BcIsOpen,
            ($a.BcRestrictKind = ''
              OR ($a.BcRestrictTo IS NOT NULL AND $a.BcRestrictTo <= NOW())) AS BcAllOpen";
}

/** Valeurs par défaut d'une compétition jamais configurée. */
function bk_comp_defaults($tourId)
{
    return (object) array(
        'BcTournament' => intval($tourId),
        'BcOpen' => 0, 'BcOpenFrom' => null, 'BcOpenTo' => null,
        'BcRestrictKind' => '', 'BcRestrictCode' => '', 'BcRestrictTo' => null,
        'BcMaxPerClubPerTarget' => 2, 'BcMinClubsPerSession' => 3,
        'BcShowAssignment' => 0, 'BcShowGauges' => 1, 'BcAllowScoresheet' => 0,
        'BcWishLetter' => 1, 'BcWishWith' => 0, 'BcWishFree' => 0,
        'BcFee' => '0.00', 'BcPricing' => null, 'BcShopUntil' => null, 'BcExcludeStats' => 0,
        'BcPayInfo' => null, 'BcManualValidation' => 0, 'BcMandate' => null, 'BcShowMandate' => null,
        'BcIanseoUrl' => null, 'BcShowProgram' => 0, 'BcShowParticipants' => 0, 'BcShowResults' => 0,
        'BcShowDossard' => 0,
        'BcPublishLevel' => 1, 'BcAdvancedBackup' => null,
        'BcIsOpen' => 0, 'BcAllOpen' => 1,
    );
}

/**
 * Colonnes de configuration « avancée » d'une compétition — celles que le niveau 2
 * (publication simple) impose automatiquement, et que l'on SAUVEGARDE (snapshot)
 * pour pouvoir les restaurer au retour en niveau 3 (« conserver mais masquer »).
 */
function bk_comp_advanced_cols()
{
    // BcFee (tarif de base) est VOLONTAIREMENT absent : c'est un réglage effectif stable,
    // saisissable aussi en niveau 2 (« À finaliser »), qui ne doit donc pas être remis à sa
    // valeur d'avant par la restauration du snapshot au retour en niveau 3. Seule la
    // tarification AVANCÉE (BcPricing) est mise en veille par le niveau 2 et restaurée ensuite.
    return array('BcOpen', 'BcOpenFrom', 'BcOpenTo', 'BcRestrictKind', 'BcRestrictCode', 'BcRestrictTo',
        'BcMaxPerClubPerTarget', 'BcMinClubsPerSession', 'BcShowAssignment', 'BcShowGauges', 'BcAllowScoresheet',
        'BcWishLetter', 'BcWishWith', 'BcWishFree', 'BcPricing', 'BcManualValidation',
        'BcShowMandate', 'BcMandate', 'BcIanseoUrl', 'BcShowProgram', 'BcShowParticipants', 'BcShowResults',
        'BcShowDossard', 'BcPayInfo', 'BcShopUntil');
}

/** Configuration d'une compétition (défauts si jamais enregistrée). */
function bk_comp_config($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $q = safe_r_sql("SELECT o.*, " . bk_comp_calc_sql('o') . "
        FROM BK_Competitions o WHERE o.BcTournament = $tourId");
    $r = safe_fetch($q);
    return $r ?: bk_comp_defaults($tourId);
}

/* ------------------------------------------------------------------ */
/* « Copier depuis… » — reprendre la configuration d'une autre         */
/* compétition (gain de temps sur les paramétrages complexes)          */
/* ------------------------------------------------------------------ */

/**
 * Fragment SQL bornant les compétitions ACCESSIBLES à l'organisateur courant
 * (sur l'alias Tournament $alias). Lit la convention de session d'AUTH (aucune
 * dépendance dure) : hors AUTH ou administrateur serveur → tout ; sinon la liste
 * AUTH_COMP (codes exacts ou motifs LIKE). Repli sûr : aucun accès.
 */
function bk_copy_access_where($alias = 't')
{
    if (empty($_SESSION['AUTH_ENABLE'])) return '1=1';     // localhost / mono-organisateur
    if (!empty($_SESSION['AUTH_ROOT']))  return '1=1';     // admin serveur (UI = champ libre)
    $comp = $_SESSION['AUTH_COMP'] ?? array();
    if (!is_array($comp) || !$comp) return '1=0';
    $ors = array();
    foreach ($comp as $c) {
        $c = (string) $c;
        if ($c === '') continue;
        $ors[] = (strpos($c, '%') !== false || strpos($c, '_') !== false)
            ? "$alias.ToCode LIKE " . StrSafe_DB($c)
            : "$alias.ToCode = " . StrSafe_DB($c);
    }
    return $ors ? '(' . implode(' OR ', $ors) . ')' : '1=0';
}

/** L'organisateur courant est-il administrateur serveur ? (source « Copier » = champ libre). */
function bk_copy_is_admin()
{
    return !empty($_SESSION['AUTH_ENABLE']) && !empty($_SESSION['AUTH_ROOT']);
}

/** Compétitions accessibles ayant une config booking, hors la compétition courante. */
function bk_copy_sources($currentTour)
{
    bk_schema();
    $currentTour = intval($currentTour);
    $rs = safe_r_sql("SELECT t.ToId, t.ToCode, t.ToName, t.ToWhenFrom
        FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE t.ToId <> $currentTour AND " . bk_copy_access_where('t') . "
        ORDER BY t.ToWhenFrom DESC, t.ToName LIMIT 500");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Résout une saisie libre d'admin (code de compétition, ou identifiant numérique)
 * vers un ToId accessible ayant une config booking. Retourne l'id, ou 0.
 */
function bk_copy_resolve($input, $currentTour)
{
    $input = trim((string) $input);
    if ($input === '') return 0;
    $currentTour = intval($currentTour);
    $where = bk_copy_access_where('t');
    if (ctype_digit($input)) {
        $r = safe_fetch(safe_r_sql("SELECT t.ToId FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
            WHERE t.ToId = " . intval($input) . " AND t.ToId <> $currentTour AND $where"));
        if ($r) return intval($r->ToId);
    }
    $r = safe_fetch(safe_r_sql("SELECT t.ToId FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE t.ToCode = " . StrSafe_DB($input) . " AND t.ToId <> $currentTour AND $where
        ORDER BY t.ToWhenFrom DESC LIMIT 1"));
    return $r ? intval($r->ToId) : 0;
}

/**
 * Copie la configuration booking de $srcTour vers $destTour. REMPLACE les réglages
 * (niveau de publication, inscriptions, visibilité, tarif, mandat, boutique, contraintes
 * du terrain). Les DATES sont conservées en DÉCALAGE par rapport à la date de début
 * (même « ratio » temporel). Non copiés : identité (BcCode), géocodage (par lieu), et le
 * lien ianseo.net (propre à chaque compétition). Les LOGOS ne sont pas copiés — seules les
 * cases « quels logos » du mandat le sont. Retourne true si copié.
 */
function bk_comp_copy_from($destTour, $srcTour)
{
    bk_schema();
    $destTour = intval($destTour); $srcTour = intval($srcTour);
    if ($destTour <= 0 || $srcTour <= 0 || $destTour === $srcTour) return false;

    $src = safe_fetch(safe_r_sql("SELECT o.BcTournament, t.ToWhenFrom AS SrcStart
        FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament WHERE o.BcTournament = $srcTour"));
    if (!$src) return false;                                   // la source n'a pas de config booking
    $dst = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = $destTour"));
    if (!$dst) return false;

    safe_w_sql("INSERT IGNORE INTO BK_Competitions (BcTournament) VALUES ($destTour)");

    $ss = StrSafe_DB($src->SrcStart);
    $ds = StrSafe_DB($dst->ToWhenFrom);
    // Décalage identique par rapport à la date de début (ex. « ouvre 30 j avant, clôt 2 j avant »).
    $remap = function ($col) use ($ss, $ds) {
        return "IF(s.$col IS NULL, NULL, DATE_ADD($ds, INTERVAL TIMESTAMPDIFF(SECOND, $ss, s.$col) SECOND))";
    };

    safe_w_sql("UPDATE BK_Competitions d INNER JOIN BK_Competitions s ON s.BcTournament = $srcTour SET
        d.BcOpen = s.BcOpen, d.BcPublishLevel = s.BcPublishLevel, d.BcAdvancedBackup = s.BcAdvancedBackup,
        d.BcOpenFrom = " . $remap('BcOpenFrom') . ", d.BcOpenTo = " . $remap('BcOpenTo') . ",
        d.BcRestrictKind = s.BcRestrictKind, d.BcRestrictCode = s.BcRestrictCode,
        d.BcRestrictTo = " . $remap('BcRestrictTo') . ",
        d.BcMaxPerClubPerTarget = s.BcMaxPerClubPerTarget, d.BcMinClubsPerSession = s.BcMinClubsPerSession,
        d.BcShowAssignment = s.BcShowAssignment, d.BcShowGauges = s.BcShowGauges, d.BcAllowScoresheet = s.BcAllowScoresheet,
        d.BcFee = s.BcFee, d.BcWishLetter = s.BcWishLetter, d.BcWishWith = s.BcWishWith, d.BcWishFree = s.BcWishFree,
        d.BcPricing = s.BcPricing, d.BcShopUntil = " . $remap('BcShopUntil') . ", d.BcExcludeStats = s.BcExcludeStats,
        d.BcPayInfo = s.BcPayInfo, d.BcManualValidation = s.BcManualValidation, d.BcMandate = s.BcMandate,
        d.BcShowMandate = s.BcShowMandate, d.BcShowProgram = s.BcShowProgram,
        d.BcShowParticipants = s.BcShowParticipants, d.BcShowResults = s.BcShowResults,
        d.BcShowDossard = s.BcShowDossard
        WHERE d.BcTournament = $destTour");

    bk_comp_copy_shop($destTour, $srcTour);
    bk_comp_copy_caps($destTour, $srcTour);
    return true;
}

/** Copie la boutique (articles + variantes) ; REMPLACE celle de la destination (commandes effacées). */
function bk_comp_copy_shop($destTour, $srcTour)
{
    $destTour = intval($destTour); $srcTour = intval($srcTour);
    // Collecte de la source d'abord (pas d'itération imbriquée sur des result sets vivants).
    $items = array();
    $rs = safe_r_sql("SELECT * FROM BK_ShopItems WHERE SiTournament = $srcTour ORDER BY SiId");
    while ($r = safe_fetch($rs)) $items[] = $r;
    $variants = array();
    foreach ($items as $it) {
        $variants[(string) $it->SiId] = array();
        $rv = safe_r_sql("SELECT * FROM BK_ShopVariants WHERE SvItem = " . intval($it->SiId) . " ORDER BY SvId");
        while ($v = safe_fetch($rv)) $variants[(string) $it->SiId][] = $v;
    }
    // Boutique de destination remise à zéro (copie = boutique fraîche).
    safe_w_sql("DELETE FROM BK_ShopOrders WHERE SoTournament = $destTour");
    safe_w_sql("DELETE v FROM BK_ShopVariants v INNER JOIN BK_ShopItems i ON i.SiId = v.SvItem WHERE i.SiTournament = $destTour");
    safe_w_sql("DELETE FROM BK_ShopItems WHERE SiTournament = $destTour");
    foreach ($items as $it) {
        safe_w_sql("INSERT INTO BK_ShopItems (SiTournament, SiSection, SiLabel, SiDescription, SiPrice, SiStock, SiMaxPerPerson, SiOptionName, SiOrder, SiActive)
            VALUES ($destTour, " . StrSafe_DB($it->SiSection) . ", " . StrSafe_DB($it->SiLabel) . ", "
            . StrSafe_DB($it->SiDescription) . ", " . StrSafe_DB($it->SiPrice) . ", " . intval($it->SiStock) . ", "
            . intval($it->SiMaxPerPerson) . ", " . StrSafe_DB($it->SiOptionName) . ", " . intval($it->SiOrder) . ", " . intval($it->SiActive) . ")");
        $newId = intval(safe_w_last_id());
        foreach ($variants[(string) $it->SiId] as $v) {
            safe_w_sql("INSERT INTO BK_ShopVariants (SvItem, SvLabel, SvStock, SvOrder)
                VALUES ($newId, " . StrSafe_DB($v->SvLabel) . ", " . intval($v->SvStock) . ", " . intval($v->SvOrder) . ")");
        }
    }
    return count($items);
}

/**
 * Copie les contraintes d'affectation du terrain. Distances = mètres (portables). Les
 * BLASONS sont re-mappés PAR NOM (TfName) : les TfId ne sont pas fiables d'une compétition
 * à l'autre. Un blason absent de la destination est ignoré. REMPLACE les contraintes existantes.
 */
function bk_comp_copy_caps($destTour, $srcTour)
{
    $destTour = intval($destTour); $srcTour = intval($srcTour);
    $srcName = array();                          // TfId (src) => TfName
    $rs = safe_r_sql("SELECT TfId, TfName FROM TargetFaces WHERE TfTournament = $srcTour");
    while ($r = safe_fetch($rs)) $srcName[(string) $r->TfId] = trim((string) $r->TfName);
    $destByName = array();                        // TfName => TfId (dest)
    $rs = safe_r_sql("SELECT TfId, TfName FROM TargetFaces WHERE TfTournament = $destTour");
    while ($r = safe_fetch($rs)) { $n = trim((string) $r->TfName); if ($n !== '' && !isset($destByName[$n])) $destByName[$n] = (string) $r->TfId; }

    $caps = array();
    $rs = safe_r_sql("SELECT * FROM BK_TargetCaps WHERE BtTournament = $srcTour");
    while ($r = safe_fetch($rs)) $caps[] = $r;

    safe_w_sql("DELETE FROM BK_TargetCaps WHERE BtTournament = $destTour");
    foreach ($caps as $c) {
        $ids = array();
        foreach (array_filter(explode(',', (string) $c->BtFaces), 'strlen') as $fid) {
            $name = $srcName[(string) intval($fid)] ?? '';
            if ($name !== '' && isset($destByName[$name])) $ids[] = $destByName[$name];
        }
        $faces = implode(',', array_values(array_unique($ids)));
        safe_w_sql("INSERT INTO BK_TargetCaps (BtTournament, BtSession, BtTarget, BtDistances, BtDistDef, BtDistMin, BtDistMax, BtFaces)
            VALUES ($destTour, " . intval($c->BtSession) . ", " . intval($c->BtTarget) . ", "
            . StrSafe_DB($c->BtDistances) . ", " . intval($c->BtDistDef) . ", "
            . intval($c->BtDistMin) . ", " . intval($c->BtDistMax) . ", " . StrSafe_DB($faces) . ")");
    }
    return count($caps);
}

/**
 * Une compétition est-elle terminée ? (date de fin < aujourd'hui). Comparaison de
 * chaînes AAAA-MM-JJ, robuste au fuseau — même approche que le calendrier/les stats.
 * Une compétition finie n'est plus inscriptible, même si la fenêtre d'inscription
 * a été laissée ouverte au-delà (erreur/manipulation de l'organisateur).
 */
function bk_is_finished($toWhenTo)
{
    $d = substr((string) $toWhenTo, 0, 10);
    return $d !== '' && strpos($d, '0000') !== 0 && $d < date('Y-m-d');
}

/** Idem à partir d'un identifiant de compétition (lit ToWhenTo). */
function bk_comp_finished($tourId)
{
    $rs = safe_r_sql("SELECT ToWhenTo FROM Tournament WHERE ToId = " . intval($tourId));
    $r = safe_fetch($rs);
    return $r ? bk_is_finished($r->ToWhenTo) : false;
}

/** Enregistre la configuration. $in : valeurs déjà validées par l'appelant. */
function bk_comp_save($tourId, $in)
{
    bk_schema();
    $tourId = intval($tourId);

    $dt = function ($v) {
        $v = trim((string) $v);
        return $v === '' ? 'NULL' : StrSafe_DB(str_replace('T', ' ', $v));
    };

    $set = "BcOpen = "        . (empty($in['open']) ? 0 : 1)
        . ", BcOpenFrom = "   . $dt($in['from'] ?? '')
        . ", BcOpenTo = "     . $dt($in['to'] ?? '')
        . ", BcRestrictKind = " . StrSafe_DB((string) ($in['kind'] ?? ''))
        . ", BcRestrictCode = " . StrSafe_DB(trim((string) ($in['code'] ?? '')))
        . ", BcRestrictTo = " . $dt($in['restrict_to'] ?? '')
        . ", BcMaxPerClubPerTarget = " . max(1, min(20, intval($in['max_club'] ?? 2)))
        . ", BcMinClubsPerSession = "  . max(1, min(50, intval($in['min_clubs'] ?? 3)))
        . ", BcShowAssignment = "  . (empty($in['show_assign']) ? 0 : 1)
        . ", BcShowGauges = "      . (empty($in['show_gauges']) ? 0 : 1)
        . ", BcAllowScoresheet = " . (empty($in['scoresheet']) ? 0 : 1)
        . ", BcWishLetter = " . (empty($in['wish_letter']) ? 0 : 1)
        . ", BcWishWith = "   . (empty($in['wish_with'])   ? 0 : 1)
        . ", BcWishFree = "   . (empty($in['wish_free'])   ? 0 : 1)
        . ", BcExcludeStats = " . (empty($in['exclude_stats']) ? 0 : 1)
        . ", BcManualValidation = " . (empty($in['manual_validation']) ? 0 : 1)
        . ", BcFee = " . StrSafe_DB(number_format((float) str_replace(',', '.', (string) ($in['fee'] ?? 0)), 2, '.', ''));

    // Visibilité du mandat : n'écrire une valeur explicite (0/1) que si l'appelant
    // a présenté la case (tri-état sinon — NULL = « pas encore choisi »).
    if (array_key_exists('show_mandate', $in)) {
        $set .= ", BcShowMandate = " . (empty($in['show_mandate']) ? 0 : 1);
    }
    // Lien ianseo.net : URL http(s) seulement, sinon vidé.
    if (array_key_exists('ianseo_url', $in)) {
        $u = trim((string) $in['ianseo_url']);
        if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = '';
        $set .= ", BcIanseoUrl = " . ($u === '' ? 'NULL' : StrSafe_DB(mb_substr($u, 0, 255)));
    }
    // Documents officiels ianseo proposés aux archers (opt-in), écrits seulement
    // si l'appelant a présenté les cases (competition.php).
    if (array_key_exists('docs_present', $in)) {
        $set .= ", BcShowProgram = "      . (empty($in['show_program']) ? 0 : 1)
              . ", BcShowParticipants = " . (empty($in['show_participants']) ? 0 : 1)
              . ", BcShowResults = "      . (empty($in['show_results']) ? 0 : 1)
              . ", BcShowDossard = "      . (empty($in['show_dossard']) ? 0 : 1);
    }

    // Tarification avancée : JSON déjà normalisé par l'appelant, ou NULL (tarif plat).
    if (array_key_exists('pricing', $in)) {
        $json = trim((string) $in['pricing']);
        $set .= ", BcPricing = " . ($json === '' ? 'NULL' : StrSafe_DB($json));
    }
    // Moyens de paiement : JSON déjà construit par l'appelant, ou NULL (aucun).
    if (array_key_exists('payinfo', $in)) {
        $json = trim((string) $in['payinfo']);
        $set .= ", BcPayInfo = " . ($json === '' ? 'NULL' : StrSafe_DB($json));
    }

    safe_w_sql("INSERT INTO BK_Competitions SET BcTournament = $tourId, $set
        ON DUPLICATE KEY UPDATE $set");
}

/* ------------------------------------------------------------------ */
/* Niveau de publication (barre à 3 niveaux)                           */
/* ------------------------------------------------------------------ */

/** Snapshot des colonnes avancées d'une config (pour BcAdvancedBackup). */
function bk_comp_snapshot($cfg)
{
    $out = array();
    foreach (bk_comp_advanced_cols() as $c) {
        $out[$c] = is_object($cfg) ? ($cfg->$c ?? null) : ($cfg[$c] ?? null);
    }
    return $out;
}

/** Restaure les colonnes avancées depuis un snapshot. */
function bk_comp_restore($tourId, $snap)
{
    if (!is_array($snap)) return;
    $parts = array();
    foreach (bk_comp_advanced_cols() as $c) {
        if (!array_key_exists($c, $snap)) continue;
        $v = $snap[$c];
        $parts[] = "$c = " . ($v === null ? 'NULL' : StrSafe_DB((string) $v));
    }
    if ($parts) {
        safe_w_sql("UPDATE BK_Competitions SET " . implode(', ', $parts)
            . " WHERE BcTournament = " . intval($tourId));
    }
}

/**
 * Applique les valeurs AUTO du niveau 2 (publication simple) : ouvert de maintenant
 * à la date de fin (incluse), aucune restriction géo, validation auto, tarif de base
 * seul, tout visible et tous les documents. Les règles de placement prennent les
 * valeurs FFTA (2 archers/club/cible, 3 clubs/départ). Le mandat est rendu visible
 * (auto-rempli depuis les données — voir bk_mandate_visible, court-circuit niveau 2).
 */
function bk_comp_apply_auto($tourId)
{
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToWhenTo FROM Tournament WHERE ToId = $tourId"));
    $end = ($t && substr((string) $t->ToWhenTo, 0, 4) > '0000')
        ? substr((string) $t->ToWhenTo, 0, 10) . ' 23:59:59' : '';
    $now = date('Y-m-d H:i:s');

    $set = "BcOpen = 1"
        . ", BcOpenFrom = " . StrSafe_DB($now)
        . ", BcOpenTo = " . ($end === '' ? 'NULL' : StrSafe_DB($end))
        . ", BcRestrictKind = '', BcRestrictCode = '', BcRestrictTo = NULL"
        . ", BcManualValidation = 0"
        . ", BcMaxPerClubPerTarget = 2, BcMinClubsPerSession = 3"
        . ", BcShowGauges = 1, BcShowAssignment = 1, BcAllowScoresheet = 1"
        . ", BcShowMandate = 1, BcShowProgram = 1, BcShowParticipants = 1, BcShowResults = 1, BcShowDossard = 1"
        . ", BcWishLetter = 1, BcWishWith = 0, BcWishFree = 0"
        . ", BcPricing = NULL, BcExcludeStats = 0";
    safe_w_sql("UPDATE BK_Competitions SET $set WHERE BcTournament = $tourId");
}

/**
 * Change le niveau de publication et applique la transition :
 *  - 1 : privé (BcOpen=0, plus rien côté archer) ;
 *  - 2 : simple → snapshot des réglages avancés (si pas déjà fait) puis valeurs AUTO ;
 *  - 3 : avancé → restaure le snapshot (s'il existe) et le vide (les réglages avancés
 *        du formulaire sont ensuite écrits par bk_comp_save).
 * Les colonnes restent la config EFFECTIVE lue partout ; BcAdvancedBackup conserve
 * les réglages avancés tant qu'on est en niveau 2.
 */
function bk_comp_set_level($tourId, $level)
{
    bk_schema();
    $tourId = intval($tourId);
    $level  = in_array(intval($level), array(1, 2, 3), true) ? intval($level) : 1;

    // Garantir l'existence de la ligne (sans rien modifier si elle existe déjà).
    safe_w_sql("INSERT INTO BK_Competitions (BcTournament) VALUES ($tourId)
        ON DUPLICATE KEY UPDATE BcTournament = BcTournament");

    $cur = bk_comp_config($tourId);
    $hasBackup = trim((string) ($cur->BcAdvancedBackup ?? '')) !== '';

    if ($level == 2) {
        if (!$hasBackup) {
            $snap = json_encode(bk_comp_snapshot($cur), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            safe_w_sql("UPDATE BK_Competitions SET BcAdvancedBackup = " . StrSafe_DB($snap)
                . " WHERE BcTournament = $tourId");
        }
        bk_comp_apply_auto($tourId);
        safe_w_sql("UPDATE BK_Competitions SET BcPublishLevel = 2 WHERE BcTournament = $tourId");
    } elseif ($level == 3) {
        if ($hasBackup) {
            bk_comp_restore($tourId, json_decode($cur->BcAdvancedBackup, true));
            safe_w_sql("UPDATE BK_Competitions SET BcAdvancedBackup = NULL WHERE BcTournament = $tourId");
        }
        safe_w_sql("UPDATE BK_Competitions SET BcPublishLevel = 3 WHERE BcTournament = $tourId");
    } else {
        safe_w_sql("UPDATE BK_Competitions SET BcOpen = 0, BcPublishLevel = 1 WHERE BcTournament = $tourId");
    }
}

/**
 * Un archer de ce club peut-il s'inscrire ? Retourne '' si oui, sinon le motif
 * du refus (message affichable).
 *
 * $cfg doit porter les colonnes calculées (BcAllOpen), donc venir de
 * bk_comp_config() ou bk_comp_calendar().
 */
function bk_comp_archer_blocked($cfg, $clubCode)
{
    if (!empty($cfg->BcAllOpen)) return '';

    $kind = (string) $cfg->BcRestrictKind;
    $code = strtoupper(trim((string) $cfg->BcRestrictCode));
    $club = strtoupper(trim((string) $clubCode));
    if ($code === '') return '';          // restriction incomplète = pas de restriction

    if (strpbrk($code, '%_') !== false) {
        // Motif expert (DOM-TOM, découpages atypiques) : LIKE → expression régulière.
        $re = '';
        foreach (str_split($code) as $ch) {
            if ($ch === '%')      $re .= '.*';
            elseif ($ch === '_')  $re .= '.';
            else                  $re .= preg_quote($ch, '/');
        }
        $ok = (bool) preg_match('/^' . $re . '$/', $club);
    } elseif ($kind === 'CD') {
        // Agrément LLDDCCC : le département occupe les positions 3-4.
        $ok = (substr($club, 2, strlen($code)) === $code);
    } else {
        // Région (ligue) : préfixe de l'agrément.
        $ok = (strncmp($club, $code, strlen($code)) === 0);
    }
    if ($ok) return '';

    $label = ($kind === 'CD') ? 'du département ' : 'de la région ';
    return 'Ces inscriptions sont réservées aux archers ' . $label . $code . '.';
}

/**
 * Compétitions visibles dans le calendrier public.
 *
 * Ne renvoie que celles PUBLIÉES au calendrier (BcOpen=1) — un organisateur qui
 * n'a rien publié n'y figure jamais. En revanche on montre les compétitions
 * PASSÉES comme à venir : c'est un vrai calendrier. L'inscription, elle, reste
 * gardée à part (fenêtre d'ouverture BcIsOpen + compétition terminée + géo/tarif).
 *
 * $filters : ['q' => texte, 'from' => date, 'to' => date, 'type' => ToTypeName]
 */
function bk_comp_calendar($filters = array())
{
    bk_schema();
    $w = array('o.BcOpen = 1');

    if (!empty($filters['q'])) {
        $q = StrSafe_DB('%' . trim($filters['q']) . '%');
        $w[] = "(t.ToName LIKE $q OR t.ToWhere LIKE $q OR t.ToComDescr LIKE $q)";
    }
    if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
        $w[] = "t.ToWhenTo >= " . StrSafe_DB($filters['from']);
    }
    if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
        $w[] = "t.ToWhenFrom <= " . StrSafe_DB($filters['to']);
    }
    if (!empty($filters['type'])) {
        $w[] = "t.ToTypeName = " . StrSafe_DB($filters['type']);
    }
    if (!empty($filters['disc'])) {
        if ($filters['disc'] === 'para') {
            $w[] = "t.ToTypeSubRule LIKE '%Para%'";
        } else {
            $types = bk_disc_types($filters['disc']);
            $w[] = $types ? "t.ToType IN (" . implode(',', array_map('intval', $types)) . ")" : "1=0";
        }
    }
    if (!empty($filters['region']) && preg_match('/^[0-9A-Za-z]{2}$/', (string) $filters['region'])) {
        $w[] = "LEFT(t.ToCommitee, 2) = " . StrSafe_DB($filters['region']);
    }

    $rs = safe_r_sql("SELECT t.ToId, t.ToCode, t.ToName, t.ToWhere, t.ToComDescr, t.ToCommitee,
                t.ToWhenFrom, t.ToWhenTo, t.ToTypeName, t.ToType, t.ToTypeSubRule, t.ToNumSession,
                o.*, " . bk_comp_calc_sql('o') . "
        FROM BK_Competitions o
        INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE " . implode(' AND ', $w) . "
        ORDER BY t.ToWhenFrom ASC, t.ToName ASC");

    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Types de compétition présents dans le calendrier (pour le filtre). */
function bk_comp_types()
{
    bk_schema();
    $rs = safe_r_sql("SELECT DISTINCT t.ToTypeName
        FROM BK_Competitions o
        INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE o.BcOpen = 1 AND t.ToTypeName <> ''
        ORDER BY t.ToTypeName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r->ToTypeName;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Disciplines (déduites de ToType) & région (préfixe de ToCommitee)   */
/* ------------------------------------------------------------------ */

/** Nom de la ligue (région) d'après son code à 2 chiffres. Code seul si inconnu. */
function bk_region_name($code)
{
    $m = array(
        '01' => 'Auvergne-Rhône-Alpes', '02' => 'Bourgogne-Franche-Comté', '03' => 'Bretagne',
        '04' => 'Centre-Val de Loire', '05' => 'Corse', '06' => 'Grand Est',
        '07' => 'Hauts-de-France', '08' => 'Île-de-France', '09' => 'Normandie',
        '10' => 'Nouvelle-Aquitaine', '11' => 'Occitanie', '12' => 'Pays de la Loire',
        '13' => "Provence-Alpes-Côte d'Azur", '35' => 'La Réunion', '36' => 'Guyane',
        '37' => 'Guadeloupe', '38' => 'Nouvelle-Calédonie', '39' => 'Martinique',
    );
    return $m[$code] ?? ('Région ' . $code);
}

/**
 * La compétition est-elle DROM-TOM ? (d'après l'agrément de l'organisateur, ToCommitee).
 * Les ligues métropolitaines vont de 01 à 13 (Corse = 05) ; les DROM-TOM sont ≥ 30
 * (35 Réunion, 36 Guyane, 37 Guadeloupe, 38 Nouvelle-Calédonie, 39 Martinique).
 * Seules ces compétitions échappent aux règles FFTA de placement (peu de clubs).
 */
function bk_is_dromtom($committee)
{
    $ll = substr(preg_replace('/\D/', '', (string) $committee), 0, 2);
    return $ll !== '' && intval($ll) >= 30;
}

/**
 * Libellés des disciplines (clé interne → nom affiché). Dénominations FFTA
 * officielles — ne pas raccourcir (utilisées au calendrier, dans « Mes
 * inscriptions » et sur le mandat).
 */
function bk_disc_labels()
{
    return array(
        'ext'       => 'Tir à l\'Arc Extérieur',
        'salle'     => 'Tir à 18m',
        'campagne'  => 'Tir en Campagne',
        'nature'    => 'Parcours Nature',
        '3d'        => 'Tir sur cibles 3D',
        'run'       => 'Run Archery',
        'beursault' => 'Beursault',
    );
}

/** Types ianseo (ToType) couverts par une discipline — pour filtrer le calendrier. */
function bk_disc_types($key)
{
    $m = array(
        'ext'       => array(1, 2, 3, 4, 5),      // extérieur / FITA / 70m
        'salle'     => array(6),                  // Indoor 18/25
        'campagne'  => array(7, 9),               // Field — ToType 9 = « Type_HF 12+12 » (Hunter-Field = Campagne)
        'nature'    => array(),                   // (par libellé, voir bk_comp_discipline)
        '3d'        => array(11),
        'run'       => array(48),                 // Run Archery (TourType 48 dans ianseo)
        'beursault' => array(50),
    );
    return $m[$key] ?? array();
}

/**
 * Discipline d'une compétition d'après ToType (+ repli sur le libellé), et si
 * c'est une épreuve Para (ToTypeSubRule). Retourne ['key','para'].
 */
function bk_comp_discipline($type, $subrule = '', $typeName = '')
{
    $type = intval($type);
    // ⚠️ ToType 9 = « Type_HF 12+12 » (Hunter-Field) = CAMPAGNE, pas extérieur : toutes
    // les compétitions Campagne portent ce type (vérifié en base). Le classer en 'ext'
    // affichait un picto TAE au calendrier ET appliquait à tort la cohabitation de blasons
    // TAE à un parcours (qui n'a pas de blason mais des couleurs de piquet).
    $byType = array(
        1 => 'ext', 2 => 'ext', 3 => 'ext', 4 => 'ext', 5 => 'ext',
        6 => 'salle', 7 => 'campagne', 9 => 'campagne', 11 => '3d', 48 => 'run', 50 => 'beursault',
    );
    $key = $byType[$type] ?? '';
    if ($key === '') {
        $n = strtolower($typeName . ' ' . $subrule);
        if     (strpos($n, 'beursault') !== false) $key = 'beursault';
        elseif (strpos($n, '3d') !== false)        $key = '3d';
        elseif (strpos($n, 'nature') !== false)    $key = 'nature';
        elseif (strpos($n, 'run') !== false)       $key = 'run';
        elseif (strpos($n, 'field') !== false || strpos($n, 'campagne') !== false) $key = 'campagne';
        elseif (strpos($n, 'indoor') !== false || strpos($n, 'salle') !== false)   $key = 'salle';
        else   $key = 'ext';
    }
    return array('key' => $key, 'para' => (bool) preg_match('/para/i', (string) $subrule));
}

/**
 * Disciplines et régions RÉELLEMENT présentes dans le calendrier ouvert
 * (pour ne proposer que des filtres utiles). Retour :
 *   ['disc' => [key => n], 'para' => bool, 'regions' => [code => n]]
 */
function bk_comp_facets()
{
    bk_schema();
    $rs = safe_r_sql("SELECT t.ToType, t.ToTypeName, t.ToTypeSubRule, t.ToCommitee
        FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE o.BcOpen = 1");
    $disc = array(); $para = false; $regions = array();
    while ($r = safe_fetch($rs)) {
        $d = bk_comp_discipline($r->ToType, $r->ToTypeSubRule, $r->ToTypeName);
        $disc[$d['key']] = ($disc[$d['key']] ?? 0) + 1;
        if ($d['para']) $para = true;
        $code = strtoupper(substr((string) $r->ToCommitee, 0, 2));
        if (preg_match('/^[0-9]{2}$/', $code)) $regions[$code] = ($regions[$code] ?? 0) + 1;
    }
    ksort($regions);
    return array('disc' => $disc, 'para' => $para, 'regions' => $regions);
}

/** Une compétition ouverte, pour la page de détail (null si absente/fermée). */
function bk_comp_one($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT t.ToId, t.ToCode, t.ToName, t.ToWhere, t.ToComDescr, t.ToCommitee,
                t.ToWhenFrom, t.ToWhenTo, t.ToTypeName, t.ToType, t.ToTypeSubRule, t.ToNumSession,
                o.*, " . bk_comp_calc_sql('o') . "
        FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE o.BcTournament = $tourId AND o.BcOpen = 1");
    return safe_fetch($rs) ?: null;
}

/**
 * Correspondance discipline → n° de blason ianseo (Common/Images/Targets/{id}.svg,
 * les mêmes visuels que ceux utilisés par ISK-ng et PlanQualifs). Les images
 * étant fournies par le cœur ianseo, elles sont toujours présentes.
 *
 * ⚠️ Table à AJUSTER : identifiés de façon sûre 1 = blason couleur 10 zones (WA),
 * 20 = blason bleu/blanc (parcours), 8 = animal, 27 = Beursault. Salle / Nature /
 * Run restent des choix par défaut à confirmer. Surchargeable sans toucher au code
 * via config.local.json → "disc_face": {"salle": 12, ...}.
 */
function bk_disc_face_id($key)
{
    static $map = null;
    if (is_null($map)) {
        $map = array(
            'ext'       => 1,    // blason couleur 10 zones (World Archery) — TAE
            'salle'     => 2,    // blason 18 m
            'campagne'  => 6,    // parcours campagne
            'nature'    => 12,   // animal — encadré (voir bk_disc_icon) pour se distinguer du 3D
            '3d'        => 8,    // animal
            'run'       => 19,   // run archery
            'beursault' => 27,   // cible Beursault
        );
        $ov = function_exists('bk_local_config') ? (bk_local_config()['disc_face'] ?? array()) : array();
        foreach ($ov as $k => $v) {
            if (is_numeric($v)) $map[$k] = intval($v);
        }
    }
    return $map[$key] ?? 0;   // 0.svg = blason « inconnu » (repli)
}

/**
 * Pictogramme « piquet » COLORABLE, pour le PLAN DU TERRAIN des parcours (Campagne,
 * 3D, Nature) : ces disciplines n'ont pas de blason qui change selon la catégorie mais
 * des COULEURS DE PIQUET (rouge, bleu, blanc, rose). Brique réutilisable — passer la
 * couleur réglementaire du piquet. (Le CALENDRIER, lui, garde les blasons du cœur.)
 */
function bk_piquet_svg($color = '#0254a8', $size = 22)
{
    $s = intval($size);
    $c = htmlspecialchars($color, ENT_QUOTES);
    // Piquet à sommet arrondi et base pointue plantée dans le sol, + reflet.
    return '<svg class="bk-piquet" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" '
         . 'role="img" aria-hidden="true">'
         . '<ellipse cx="12" cy="20.2" rx="6.4" ry="1.8" fill="#d9d2c4"/>'
         . '<path d="M9 5.6a3 3 0 0 1 6 0v10.6l-3 4-3-4z" fill="' . $c . '" stroke="rgba(0,0,0,.28)" stroke-width=".6"/>'
         . '<path d="M10.3 6.4a1.3 1.3 0 0 1 1.2 0v9.2" stroke="rgba(255,255,255,.55)" stroke-width="1" fill="none" stroke-linecap="round"/>'
         . '</svg>';
}

/** Couleur para FFTA (contour des pastilles para). */
function bk_color_para() { return '#A0006D'; }

/**
 * Couleur FFTA d'une discipline (charte : voir CHARTE_GRAPHIQUE.md). $official=false
 * (compétition non officielle) → anthracite. Le para est un CONTOUR ajouté par-dessus
 * (bk_color_para), pas une couleur de remplissage.
 */
function bk_disc_color($key, $official = true)
{
    if (!$official) return '#37414a';                     // anthracite — non officielle
    switch ($key) {
        case 'ext': case 'salle':                 return '#3E62FF';   // cibles (TAE + 18 m)
        case 'campagne': case '3d': case 'nature': return '#157A32';   // parcours (Campagne/3D/Nature)
        case 'beursault':                          return '#D04A0B';   // traditionnel (Beursault)
        case 'run':                                return '#0F857C';   // run archery
        default:                                   return '#37414a';   // inconnu → anthracite
    }
}

/** Couleur (hex) d'un piquet d'après son nom (« Piquet Rouge/Bleu/Blanc/Rose »). */
function bk_peg_color($name)
{
    $n = mb_strtolower((string) $name, 'UTF-8');
    if (strpos($n, 'roug') !== false)  return '#d0342c';   // rouge
    if (strpos($n, 'bleu') !== false)  return '#2b6cb0';   // bleu
    if (strpos($n, 'blanc') !== false) return '#c9ced6';   // blanc (gris clair pour rester visible)
    if (strpos($n, 'ros') !== false)   return '#e5679a';   // rose
    if (strpos($n, 'noir') !== false)  return '#333a44';
    if (strpos($n, 'jaune') !== false) return '#e8c33a';
    return '#7a8b3a';                                       // repli (olive)
}

/** Pictogramme d'une discipline au calendrier : image de blason ianseo (ratio préservé). */
function bk_disc_icon($key, $size = 22)
{
    global $CFG;
    $src = $CFG->ROOT_DIR . 'Common/Images/Targets/' . bk_disc_face_id($key) . '.svg';
    $img = '<img class="bk-disc-img" src="' . htmlspecialchars($src, ENT_QUOTES)
         . '" width="' . intval($size) . '" height="' . intval($size) . '" alt="" loading="lazy">';
    // Nature et 3D partagent des blasons animaux : un cadre rectangulaire marque le Nature.
    if ($key === 'nature') return '<span class="bk-disc-frame">' . $img . '</span>';
    return $img;
}

/** Pictogramme « Para » (fauteuil), superposable en petit sur une tuile. */
function bk_disc_icon_para($size = 16)
{
    return '<svg width="' . intval($size) . '" height="' . intval($size) . '" viewBox="0 0 24 24" '
        . 'fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">'
        . '<circle cx="9" cy="17.5" r="4"/><path d="M9 6.5v6.5h6l2.2 4.5"/>'
        . '<circle cx="10" cy="4" r="1.6" fill="currentColor" stroke="none"/></svg>';
}

/**
 * Capacité d'une compétition, départ par départ, lue dans la configuration
 * ianseo (Session) et l'occupation réelle (Qualifications).
 *
 * ⚠️ Qualifications n'a AUCUNE colonne de compétition : le comptage DOIT passer
 * par une jointure sur Entries (EnTournament), sinon il agrège les archers de
 * toutes les compétitions de la base.
 */
function bk_comp_sessions($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT s.SesOrder, s.SesName, s.SesTar4Session, s.SesAth4Target,
                s.SesFirstTarget, s.SesDtStart,
                (SELECT CONCAT(di.DiDay, ' ', di.DiStart) FROM DistanceInformation di
                   WHERE di.DiTournament = $tourId AND di.DiSession = s.SesOrder
                     AND di.DiType = 'Q' AND di.DiDistance = 1 LIMIT 1) AS SesStart,
                (s.SesTar4Session * s.SesAth4Target) AS Places,
                (SELECT COUNT(*)
                   FROM Qualifications q
                   INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
                  WHERE q.QuSession = s.SesOrder) AS Pris
        FROM Session s
        WHERE s.SesTournament = $tourId AND s.SesType = 'Q'
        ORDER BY s.SesOrder");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Date/heure de début d'un départ : l'horaire de la distance 1 (DistanceInformation,
 * saisi dans ManSessions_kiss.php) en priorité, sinon SesDtStart. '' si non renseigné.
 * Retour : chaîne 'AAAA-MM-JJ HH:MM:SS' ou ''.
 */
function bk_session_start($s)
{
    foreach (array($s->SesStart ?? '', $s->SesDtStart ?? '') as $v) {
        $v = trim((string) $v);
        if ($v !== '' && substr($v, 0, 10) !== '0000-00-00') return $v;
    }
    return '';
}

/**
 * Format et durée d'un départ, lus dans DistanceInformation. La durée est la valeur
 * RÉELLE saisie par l'organisateur (DistanceInformation.DiDuration, en minutes, portée
 * par la distance 1 mais représentant tout le départ — voir Scheduler / ManSessions).
 * JAMAIS estimée : 'min'=0 si elle n'est pas renseignée. 'fmt' = volées×flèches (info).
 * Retour : ['ends'=>int, 'fmt'=>'10×3 + 10×3', 'min'=>int].
 */
function bk_session_format($tourId, $sessionOrder)
{
    $rs = safe_r_sql("SELECT DiDistance, DiEnds, DiArrows, DiDuration FROM DistanceInformation
        WHERE DiTournament = " . intval($tourId) . " AND DiSession = " . intval($sessionOrder) . "
          AND DiType = 'Q' ORDER BY DiDistance");
    $ends = 0; $fmt = array(); $min = 0;
    while ($r = safe_fetch($rs)) {
        $e = intval($r->DiEnds); $a = intval($r->DiArrows);
        if ($e > 0 && $a > 0) { $ends += $e; $fmt[] = $e . '×' . $a; }
        if (intval($r->DiDistance) === 1) $min = intval($r->DiDuration);   // durée globale du départ
    }
    return array('ends' => $ends, 'fmt' => implode(' + ', $fmt), 'min' => max(0, $min));
}

/** Durée en 'Xh', 'XhYY' ou 'Z min' (français). '' si 0. */
function bk_dur_hm($min)
{
    $min = intval($min);
    if ($min <= 0) return '';
    $h = intdiv($min, 60); $m = $min % 60;
    if ($h && $m) return $h . 'h' . str_pad($m, 2, '0', STR_PAD_LEFT);
    if ($h) return $h . 'h';
    return $m . ' min';
}
