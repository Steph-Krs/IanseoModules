<?php
// ── Menu ianseo — module PRONO ────────────────────────────────────────────────
// Ce fichier est inclus sur TOUTES les pages de ianseo (get_which_menu fait un
// glob sur Modules/Custom/*/menu.php) : rester léger, et ne jamais lever d'erreur
// fatale — elle casserait le site entier.
if (!empty($on) && !empty($_SESSION['TourId']) && (subFeatureAcl($acl, AclQualification, '') >= AclReadOnly)) {

    if (!array_key_exists('_prono_has_ind', $GLOBALS)) {
        $GLOBALS['_prono_has_ind'] = false;
        $_pr_tid = intval($_SESSION['TourId']);
        $_pr_rs  = safe_r_sql("SELECT 1 FROM Events WHERE EvTournament=$_pr_tid LIMIT 1");
        if ($_pr_rs && safe_fetch($_pr_rs)) $GLOBALS['_prono_has_ind'] = true;
        unset($_pr_tid, $_pr_rs);
    }

    if ($GLOBALS['_prono_has_ind']) {
        if (!isset($ret['MODS']['PRONO'])) {
            $ret['MODS']['PRONO'][] = 'Pronostics';
        }
        if (subFeatureAcl($acl, AclQualification, '') >= AclReadWrite) {
            $ret['MODS']['PRONO'][] = 'Console' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/PRONO/index.php';
            $ret['MODS']['PRONO'][] = 'Types & grille' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/PRONO/admin/markets.php';
            $ret['MODS']['PRONO'][] = 'Groupes & joueurs' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/PRONO/admin/groups.php';
        }
        $ret['MODS']['PRONO'][] = 'Écran de salle' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/PRONO/screen.php';

        // Réservé à l'administrateur. Avec AUTH actif, authCheckACL accorde AclRoot à tout
        // organisateur connecté hors compétition → exiger en plus la vue Administrateur
        // serveur (AUTH_ROOT). Sans AUTH, comportement ianseo classique.
        if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
            && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
            $ret['MODS']['PRONO'][] = 'Mise à jour module' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/PRONO/admin/update.php';
        }
    }

    // ── Fermeture rapide, présente sur toutes les pages ───────────────────────
    // Un clic ferme la PROCHAINE phase de chaque épreuve dont l'horaire prévu
    // (FinSchedule) est passé ou tombe dans l'heure — jamais une bascule globale,
    // c'est une action, pas un état : la grille de la console reste le seul endroit
    // où rouvrir une cellule précise. Une seule requête, tolérante à l'absence des
    // tables (3e paramètre = ne pas interrompre) : ce fichier est inclus sur TOUTES
    // les pages de ianseo. headers_sent() garantit qu'une page est déjà en cours de
    // rendu : sans ce test, écrire ici casserait une éventuelle redirection en
    // cours de construction.
    if (empty($GLOBALS['_prono_bar_done'])
        && headers_sent()
        && subFeatureAcl($acl, AclQualification, '') >= AclReadWrite
        && PHP_SAPI !== 'cli') {

        $GLOBALS['_prono_bar_done'] = true;
        $_pr_rs  = safe_r_sql('SELECT 1 FROM PRONO_Config WHERE PaCfOpen = 1 LIMIT 1', false, true);
        $_pr_has = $_pr_rs ? (bool) safe_fetch($_pr_rs) : false;

        if ($_pr_has) {
            $_pr_back  = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES);
            $_pr_url   = htmlspecialchars($CFG->ROOT_DIR . 'Modules/Custom/PRONO/admin/quickclose.php', ENT_QUOTES);
            $_pr_count = isset($_GET['prono_closed']) ? max(0, (int) $_GET['prono_closed']) : null;
            ?>
<style>
#prono-bar{position:fixed;top:4px;right:8px;z-index:99989;background:#01367c;
          color:#fff;font:11px Verdana,Arial,sans-serif;border-radius:14px;padding:4px 8px 4px 12px;
          opacity:.93;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;gap:8px}
#prono-bar form{margin:0}
#prono-bar button{font:inherit;font-weight:700;cursor:pointer;border:0;border-radius:11px;
                 padding:3px 10px;background:#fff;color:#01367c}
#prono-bar .flash{color:#bfe6c0}
</style>
<div id="prono-bar">
  <span data-role="flash"><?php if ($_pr_count !== null): ?>
    <span class="flash"><?= $_pr_count > 0 ? $_pr_count . ' phase(s) fermée(s)' : 'Rien à fermer pour l’instant' ?></span>
  <?php else: ?>Duels<?php endif; ?></span>
  <form method="post" action="<?= $_pr_url ?>">
    <input type="hidden" name="back" value="<?= $_pr_back ?>">
    <button type="submit" title="Ferme la prochaine phase de chaque épreuve dont l'horaire prévu est passé ou dans l'heure">Fermer les prochains duels</button>
  </form>
</div>
<script>
// Convention partagée : on se décale sous les barres déjà posées par d'autres modules.
(function () {
  var me = document.getElementById('prono-bar');
  if (!me) return;
  var top = 4;
  document.querySelectorAll('[id$="-bar"]').forEach(function (b) {
    if (b !== me && b.getBoundingClientRect().width) top += b.offsetHeight + 4;
  });
  me.style.top = top + 'px';

  // Le compte-rendu (?prono_closed=N) ne doit s'afficher qu'une fois : on nettoie
  // l'URL pour qu'un rechargement de la page ne le réaffiche pas indéfiniment.
  if (/[?&]prono_closed=/.test(location.search)) {
    var url = location.href.replace(/([?&])prono_closed=\d+&?/, '$1').replace(/[?&]$/, '');
    history.replaceState(null, '', url);
  }
})();
</script>
            <?php
        }
        unset($_pr_rs, $_pr_has);
    }
}
