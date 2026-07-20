<?php
// ── Vérification installation ─────────────────────────────────────────────────
// information_schema ne lève jamais d'erreur MySQL 1146, même sans TNM_BsoConfig.
// Cache $GLOBALS pour éviter la requête à chaque include répété dans la même requête.
if (!array_key_exists('_tnm_tables_ok', $GLOBALS)) {
    $GLOBALS['_tnm_tables_ok'] = false;
    $_tnmChk = safe_r_sql("SELECT COUNT(*) n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='TNM_BsoConfig'");
    if ($_tnmChk && ($_tnmRow = safe_fetch($_tnmChk)) && $_tnmRow->n > 0)
        $GLOBALS['_tnm_tables_ok'] = true;
    unset($_tnmChk, $_tnmRow);
}
$_tnm_ok = $GLOBALS['_tnm_tables_ok'];

// ── Menu ianseo ───────────────────────────────────────────────────────────────
// Si les tables n'existent pas : seule la Configuration est accessible.
if (!empty($on) && (subFeatureAcl($acl, AclQualification, '') > AclReadOnly)) {
    if (!isset($ret['MODS']['TNM']))
        $ret['MODS']['TNM'][] = 'Trophée National des Mixtes';

    $ret['MODS']['TNM'][] = 'Configuration' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/config.php';

    // Réservé à l'administrateur. Avec AUTH actif, authCheckACL accorde AclRoot à tout
    // organisateur connecté hors compétition → exiger en plus la vue Administrateur serveur
    // (AUTH_ROOT). Sans AUTH ($_SESSION['AUTH_User'] absent), comportement ianseo classique.
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['TNM'][] = 'Mise à jour module' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/admin/update.php';
    }

    if ($_tnm_ok) {
        $ret['MODS']['TNM'][] = 'Import qualif' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/ImportQualScores.php';
        $ret['MODS']['TNM'][] = 'Impr. feuilles de marques' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/PrintScore.php?team=1';
        $ret['MODS']['TNM'][] = 'Saisie tableau (bye)' . '|' . $CFG->ROOT_DIR . 'Modules/RoundRobin/InsertPoint.php?team=1';
        $ret['MODS']['TNM'][] = 'Scan feuilles de marques' . '|' . $CFG->ROOT_DIR . 'Modules/Barcodes/GetRobinScoreBarCode.php';
        $ret['MODS']['TNM'][] = 'Validation tour' . '|' . $CFG->ROOT_DIR . 'Modules/RoundRobin/AbsRobin.php?team=1';
        $ret['MODS']['TNM'][] = 'Assistant poules' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/PoolsAssist.php';
        $ret['MODS']['TNM'][] = 'Impressions poules & clsmts' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/';
        $ret['MODS']['TNM'][] = 'BSO - validation équipes + pgm' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/config.php';
        $ret['MODS']['TNM'][] = 'BSO - Saisie' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/BsoSaisie.php';
        $ret['MODS']['TNM'][] = 'BSO - Commentateur' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/BsoCommentateur.php';
    }
}

// ── Barre de navigation TNM ───────────────────────────────────────────────────
// Injectée sur toutes les pages ianseo pour les compétitions TNM actives.
// Seulement si les tables existent ET qu'une compétition TNM est configurée.
if ($_tnm_ok && empty($GLOBALS['_tnm_nav_done']) && !empty($_SESSION['TourId'])) {
    $_tnm_tid = intval($_SESSION['TourId']);
    $_tnm_rs  = safe_r_sql("SELECT 1 FROM TNM_BsoConfig WHERE BcTournament=$_tnm_tid LIMIT 1");
    if ($_tnm_rs && safe_fetch($_tnm_rs)) {
        $GLOBALS['_tnm_nav_done'] = true;
        $_tnm_r = $CFG->ROOT_DIR;
        $_tnm_links = [
            ['Feuilles de marques', $_tnm_r . 'Modules/Custom/TNM/PrintScore.php?team=1'],
            ['Scan',                $_tnm_r . 'Modules/Barcodes/GetRobinScoreBarCode.php'],
            ['Validation tour',     $_tnm_r . 'Modules/RoundRobin/AbsRobin.php?team=1'],
            ['Assistant poules',    $_tnm_r . 'Modules/Custom/TNM/PoolsAssist.php'],
            ['Impressions',         $_tnm_r . 'Modules/Custom/TNM/'],
            ['valid. BSO + pgm',    $_tnm_r . 'Modules/Custom/TNM/config.php'],
            ['Commentateur',        $_tnm_r . 'Modules/Custom/TNM/BsoCommentateur.php'],
        ];
        $_tnm_html = '<div id="tnm-nav">';
        foreach ($_tnm_links as $_tnm_l)
            $_tnm_html .= '<a href="' . htmlspecialchars($_tnm_l[1]) . '">' . htmlspecialchars($_tnm_l[0]) . '</a>';
        $_tnm_html .= '</div>';
        echo '<style>
#tnm-nav{clear:both;background:#F90A72;padding:2px 8px;display:flex;gap:2px;flex-wrap:wrap;align-items:center}
#tnm-nav::before{content:"TNM";color:#002B92;font-size:16px;font-weight:bold;padding:0 10px 0 4px;letter-spacing:3px;flex-shrink:0}
#tnm-nav a{color:#fff;font-size:12px;padding:3px 10px;border-radius:3px;text-decoration:none;white-space:nowrap}
#tnm-nav a:hover{background:rgba(255,255,255,.15);color:#dfdfdf}
#tnm-nav a.tnm-cur{background:#002B92;color:#fff;font-weight:bold}
@media print{#tnm-nav{display:none}}
</style><script>(function(){var h=' . json_encode($_tnm_html) . ';
document.addEventListener("DOMContentLoaded",function(){
  if(document.getElementById("tnm-nav"))return;
  var nav=document.getElementById("navigation");
  if(!nav)return;
  var d=document.createElement("div");
  d.innerHTML=h;
  nav.insertAdjacentElement("afterend",d.firstChild);
  var p=location.pathname;
  document.querySelectorAll("#tnm-nav a").forEach(function(a){if(a.pathname===p)a.classList.add("tnm-cur");});
});
})();</script>';
        unset($_tnm_r, $_tnm_links, $_tnm_html, $_tnm_l);
    }
    unset($_tnm_tid, $_tnm_rs);
}
unset($_tnm_ok);
?>
