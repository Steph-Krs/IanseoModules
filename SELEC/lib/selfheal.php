<?php
/**
 * lib/selfheal.php — déploiement et auto-réparation du set « SELEC ».
 *
 * POURQUOI : ianseo ne découvre les types de compétition que dans
 * Modules/Sets/<CODE>/sets.php, or Modules/Sets/ est intégralement réécrit à
 * chaque mise à jour officielle. Seul Modules/Custom/ survit. Le module garde
 * donc sa copie de référence dans Custom/SELEC/dist/set/ et la redéploie
 * automatiquement — même mécanisme que AUTH pour Modules/Authentication.
 *
 * Ce fichier écrit HORS de Modules/Custom/ :
 *   - Modules/Sets/SELEC/            (créé et maintenu par nous, jamais partagé)
 *   - TourTypes                      (une ligne, la nôtre)
 *   - Common/Languages/<lg>/*.php    (un bloc balisé ajouté en fin de fichier)
 * Rien d'autre n'est touché, et chaque écriture est idempotente.
 *
 * Appelé depuis menu.php, donc sur TOUTES les pages : il doit être quasi gratuit
 * quand tout est en place (un drapeau de session, puis un simple test de fichier).
 */

if (!defined('SELEC_TYPE_NAME'))  define('SELEC_TYPE_NAME', 'Type_FR_Selection');
if (!defined('SELEC_TYPE_ID'))    define('SELEC_TYPE_ID', 90);   // libre, ianseo s'arrête à 50
if (!defined('SELEC_SET_CODE'))   define('SELEC_SET_CODE', 'SELEC');

/** Version du module, sert d'empreinte de déploiement. */
function selec_sh_version()
{
    $j = json_decode((string) @file_get_contents(__DIR__ . '/../version.json'), true);
    return (is_array($j) && !empty($j['version'])) ? $j['version'] : '0';
}

/**
 * Déploie le set si nécessaire. Retourne un compte-rendu :
 * ['ok'=>bool, 'type'=>int, 'faits'=>[], 'erreurs'=>[]].
 * $force rejoue le déploiement même si l'empreinte est à jour.
 */
function selec_selfheal($force = false)
{
    global $CFG;
    $out = array('ok' => true, 'type' => 0, 'faits' => array(), 'erreurs' => array());

    $src = __DIR__ . '/../dist/set';
    $dst = $CFG->DOCUMENT_PATH . 'Modules/Sets/' . SELEC_SET_CODE;
    $stamp = $dst . '/.deployed';
    $ver = selec_sh_version();

    // 1) Le type de compétition doit exister avant tout : c'est lui qui donne
    //    son numéro au fichier de setup.
    $ttId = selec_sh_type();
    if (!$ttId) {
        $out['ok'] = false;
        $out['erreurs'][] = 'Impossible de créer le type de compétition « Sélection » dans TourTypes.';
        return $out;
    }
    $out['type'] = $ttId;

    $setupName = 'Setup_' . $ttId . '_' . SELEC_SET_CODE . '.php';
    $aJour = (!$force && is_file($stamp) && trim((string) @file_get_contents($stamp)) === $ver
        && is_file($dst . '/sets.php') && is_file($dst . '/' . $setupName));
    if ($aJour) return $out;

    // 2) Déploiement des fichiers du set.
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        $out['ok'] = false;
        $out['erreurs'][] = "Impossible de créer $dst (droits d'écriture).";
        return $out;
    }
    // Le fichier de setup porte le numéro du type : on nettoie les anciens noms
    // si le numéro a changé, sinon ianseo verrait deux types pour ce set.
    foreach ((array) glob($dst . '/Setup_*_' . SELEC_SET_CODE . '.php') as $vieux) {
        if (basename($vieux) !== $setupName) @unlink($vieux);
    }

    $copies = array(
        $src . '/sets.php'             => $dst . '/sets.php',
        $src . '/Setup_SELEC.tpl.php'  => $dst . '/' . $setupName,
    );
    foreach ($copies as $de => $vers) {
        if (!is_file($de)) { $out['erreurs'][] = "Source manquante : $de"; $out['ok'] = false; continue; }
        if (!@copy($de, $vers)) { $out['erreurs'][] = "Copie impossible vers $vers"; $out['ok'] = false; }
        else $out['faits'][] = basename($vers);
    }

    // 3) Libellés. Sans eux, ianseo affiche « [[SelecTAECL2027E1]@[fr]@[Install]] »
    //    dans la liste des sous-règles : illisible sur un outil de sélection.
    $out = array_merge_recursive($out, selec_sh_langues());

    if ($out['ok']) @file_put_contents($stamp, $ver);
    return $out;
}

/**
 * Crée (ou retrouve) la ligne TourTypes du type « Sélection ».
 * Retourne son identifiant, 0 en cas d'échec.
 */
function selec_sh_type()
{
    $rs = safe_r_sql("SELECT TtId FROM TourTypes WHERE TtType=" . StrSafe_DB(SELEC_TYPE_NAME) . " LIMIT 1");
    if ($rs && ($r = safe_fetch($rs))) return intval($r->TtId);

    // Numéro souhaité, sinon le premier libre au-dessus : une version future de
    // ianseo pourrait occuper le 90.
    $id = SELEC_TYPE_ID;
    $rs = safe_r_sql("SELECT TtId FROM TourTypes WHERE TtId>=" . intval($id) . " ORDER BY TtId");
    $pris = array();
    while ($rs && ($r = safe_fetch($rs))) $pris[intval($r->TtId)] = true;
    while (isset($pris[$id])) $id++;

    try {
        safe_w_sql("INSERT INTO TourTypes SET
            TtId=" . intval($id) . ",
            TtType=" . StrSafe_DB(SELEC_TYPE_NAME) . ",
            TtDistance=8,
            TtOrderBy=" . intval($id) . ",
            TtWaEquivalent=0");
    } catch (Exception $e) {
        return 0;
    }

    $rs = safe_r_sql("SELECT TtId FROM TourTypes WHERE TtType=" . StrSafe_DB(SELEC_TYPE_NAME) . " LIMIT 1");
    return ($rs && ($r = safe_fetch($rs))) ? intval($r->TtId) : 0;
}

/** Libellés à injecter, par module de langue puis par langue. */
function selec_sh_textes()
{
    return array(
        'Install' => array(
            'fr' => array(
                'Setup-SELEC'      => 'Sélection Équipe de France',
                'SelecTAECL2027E1' => 'TAE Arc Classique 2027 — Épreuve 1',
                'SelecTAECL2027E2' => 'TAE Arc Classique 2027 — Épreuve 2',
            ),
            'en' => array(
                'Setup-SELEC'      => 'French Team Selection',
                'SelecTAECL2027E1' => 'Outdoor Recurve 2027 — Stage 1',
                'SelecTAECL2027E2' => 'Outdoor Recurve 2027 — Stage 2',
            ),
        ),
        'Tournament' => array(
            'fr' => array('Type_FR_Selection' => 'Sélection — séries de 36 flèches'),
            'en' => array('Type_FR_Selection' => 'Selection — 36 arrow series'),
        ),
    );
}

/**
 * Ajoute nos libellés en fin des fichiers de langue de ianseo, dans un bloc
 * balisé. get_text() ne lit qu'UN fichier par (langue, module) : il n'y a pas
 * d'autre point d'entrée que celui-là. Le bloc est réécrit à l'identique tant
 * que rien ne change, et un fichier non modifiable dégrade seulement l'affichage.
 */
function selec_sh_langues()
{
    global $CFG;
    $out = array('ok' => true, 'faits' => array(), 'erreurs' => array());
    $debut = '// === SELEC-LANG BEGIN (module Custom/SELEC — ne pas éditer à la main) ===';
    $fin   = '// === SELEC-LANG END ===';

    foreach (selec_sh_textes() as $module => $parLangue) {
        foreach ($parLangue as $lg => $textes) {
            $f = $CFG->LANGUAGE_PATH . $lg . '/' . $module . '.php';
            if (!is_file($f)) continue;

            $bloc = $debut . "\n";
            foreach ($textes as $k => $v) {
                $bloc .= "\$lang['" . $k . "']='" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $v) . "';\n";
            }
            $bloc .= $fin . "\n";

            $contenu = (string) @file_get_contents($f);
            if ($contenu === '') { $out['erreurs'][] = "Lecture impossible : $f"; $out['ok'] = false; continue; }

            // Bloc déjà présent et identique : rien à faire.
            $pDeb = strpos($contenu, $debut);
            if ($pDeb !== false) {
                $pFin = strpos($contenu, $fin, $pDeb);
                if ($pFin === false) { $out['erreurs'][] = "Bloc SELEC-LANG incomplet dans $f"; $out['ok'] = false; continue; }
                $ancien = substr($contenu, $pDeb, $pFin + strlen($fin) + 1 - $pDeb);
                if ($ancien === $bloc) continue;
                $nouveau = substr($contenu, 0, $pDeb) . $bloc . substr($contenu, $pFin + strlen($fin) + 1);
            } else {
                // Insertion juste avant la balise de fermeture, sinon à la fin.
                $pos = strrpos($contenu, '?>');
                $nouveau = ($pos === false)
                    ? rtrim($contenu, "\r\n") . "\n" . $bloc
                    : substr($contenu, 0, $pos) . $bloc . substr($contenu, $pos);
            }

            if (@file_put_contents($f, $nouveau) === false) {
                $out['erreurs'][] = "Écriture impossible : $f (les libellés resteront techniques)";
                $out['ok'] = false;
            } else {
                $out['faits'][] = "langue $lg/$module";
            }
        }
    }
    return $out;
}

/**
 * Point d'appel léger depuis menu.php : un drapeau de session, puis un simple
 * test d'existence. Ne lève jamais d'exception — menu.php est inclus partout,
 * une erreur fatale ici casserait tout ianseo.
 */
function selec_selfheal_leger()
{
    global $CFG;
    if (!empty($GLOBALS['_selec_sh_fait'])) return;
    $GLOBALS['_selec_sh_fait'] = true;
    try {
        $dst = $CFG->DOCUMENT_PATH . 'Modules/Sets/' . SELEC_SET_CODE;
        $stamp = $dst . '/.deployed';
        if (is_file($stamp) && trim((string) @file_get_contents($stamp)) === selec_sh_version()) return;
        selec_selfheal();
    } catch (Exception $e) {
        // Volontairement silencieux : le module reste utilisable sans le set.
    } catch (Error $e) {
        // idem (PHP 8 : les erreurs fatales récupérables sont des Error)
    }
}
