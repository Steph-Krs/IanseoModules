<?php
/**
 * Module AUTH — menu.php
 * Inclus sur TOUTES les pages par get_which_menu() (Common/Menu.php).
 * Injecte les entrées de menu + la barre utilisateur (connecté/déconnexion).
 */

require_once(__DIR__ . '/lib.php');

// Sous-module fusionné (ex-BOOKING : inscriptions en ligne + boutique). Le glob
// ianseo ne balaie que Custom/*/menu.php → booking/menu.php ne s'auto-charge plus,
// on l'inclut ici (il alimente $ret à partir de $on/$acl, dans ce même contexte).
if (is_file(__DIR__ . '/booking/menu.php')) include(__DIR__ . '/booking/menu.php');

$_aut_on     = !empty($CFG->USERAUTH);
$_aut_logged = $_aut_on && !empty($_SESSION['AUTH_User']);
$_aut_root   = !empty($_SESSION['AUTH_ROOT']);
// Admin du module : compte ADMIN connecté, ou contexte sans auth (console locale / dev)
$_aut_admin  = $_aut_root
    || (empty($_SESSION['AUTH_ENABLE']) && isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite);

/* ---- Menu Modules ---- */
$ret['MODS']['AUTH'][] = 'Multi-comptes';
if ($_aut_logged || $_aut_admin) {
    $ret['MODS']['AUTH'][] = 'Compétitions & partage|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/';
    $ret['MODS']['AUTH'][] = 'Signaler / proposer|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/tickets.php';
}
if ($_aut_admin) {
    $ret['MODS']['AUTH'][] = 'Utilisateurs|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/';
    $ret['MODS']['AUTH'][] = 'Statistiques d’usage|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/stats.php';
    $ret['MODS']['AUTH'][] = 'Tickets|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/tickets.php';
    $ret['MODS']['AUTH'][] = 'Mentions légales & CGU|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/legal.php';
    $ret['MODS']['AUTH'][] = 'Déploiement|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/deploy.php';
    $ret['MODS']['AUTH'][] = 'Mise à jour module|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/update.php';
}

/* ---- Barre utilisateur (une seule fois par page) ---- */
if (!empty($GLOBALS['_aut_bar_done'])) return;
$GLOBALS['_aut_bar_done'] = true;

/* Politique ISK d'un serveur en ligne : ng-pro / ng-live révoquent la licence ianseo
   → on ramène la compétition ouverte à « lite » si un tel mode a été enregistré (import
   ou page cœur). Filet serveur ; l'UI masque aussi le choix (ci-dessous + SYNCHRO_FFTA). */
if ($_aut_on) aut_isk_enforce();

if ($_aut_logged) {
    $_aut_r = $CFG->ROOT_DIR;
    $_aut_views = $_SESSION['AUTH_VIEWS'] ?? array();
    $_aut_curRole  = $_SESSION['AUTH_ROLE'] ?? '';
    $_aut_curScope = $_SESSION['AUTH_SCOPE'] ?? '';
    ?>
<style>
#aut-bar { position:fixed; top:4px; right:8px; z-index:99990; background:#1a4f8b; color:#fff;
    font:11px Verdana,Arial,sans-serif; border-radius:14px; padding:4px 12px; opacity:.92;
    box-shadow:0 1px 4px rgba(0,0,0,.3); }
#aut-bar a { color:#cfe0f5; text-decoration:none; margin-left:10px; }
#aut-bar a:hover { color:#fff; text-decoration:underline; }
#aut-bar select { font:11px Verdana,Arial,sans-serif; margin-left:6px; max-width:230px;
    border-radius:8px; border:0; padding:1px 4px; background:#eef4fb; color:#14396b; }
#aut-warn { position:fixed; top:30px; right:8px; z-index:99990; background:#8b1a1a; color:#fff;
    font:11px Verdana,Arial,sans-serif; border-radius:4px; padding:4px 12px; }
#aut-warn a { color:#ffd7d7; }
/* Organisateur connecté : masquer le bloc de présentation ianseo de l'accueil. */
.WhatIanseoDoes { display:none !important; }
</style>
<div id="aut-bar">👤 <?php echo htmlspecialchars($_SESSION['AUTH_User']); ?>
    <?php if (count($_aut_views) > 1) { /* bascule de vue à la volée */ ?>
    <form method="post" action="<?php echo $_aut_r; ?>Modules/Custom/AUTH/switch-view.php" style="display:inline;">
        <?php echo aut_csrf_field(); ?>
        <select name="view" onchange="if(confirm('Changer de vue ? La compétition ouverte sera fermée si elle n\'est plus accessible.')){this.form.submit();}else{this.selectedIndex=this.dataset.cur;}"
            data-cur="<?php
                $_aut_curIdx = 0;
                foreach ($_aut_views as $_aut_i => $_aut_v) {
                    if (strcasecmp($_aut_v['role'], $_aut_curRole) === 0 && strcasecmp($_aut_v['scope'], $_aut_curScope) === 0) { $_aut_curIdx = $_aut_i; break; }
                }
                echo $_aut_curIdx;
            ?>">
        <?php foreach ($_aut_views as $_aut_i => $_aut_v) {
            $sel = ($_aut_i == $_aut_curIdx) ? ' selected' : '';
            echo '<option value="' . $_aut_i . '"' . $sel . '>' . htmlspecialchars($_aut_v['label']) . '</option>';
        } ?>
        </select>
    </form>
    <?php } else {
        echo ' — ' . htmlspecialchars((aut_roles()[$_aut_curRole] ?? '') . ($_aut_curScope !== '' ? ' ' . $_aut_curScope : ''));
    } ?>
    <a href="<?php echo $_aut_r; ?>Modules/Custom/AUTH/">Partage</a>
    <a href="<?php echo $_aut_r; ?>Modules/Custom/AUTH/tickets.php" title="Signaler un bug / proposer une évolution">Signaler</a>
    <?php if ($_aut_root) { ?><a href="<?php echo $_aut_r; ?>Modules/Custom/AUTH/admin/">Comptes</a><?php } ?>
    <?php if ($_aut_root) { ?><a href="<?php echo $_aut_r; ?>Modules/Authentication/Setup2FA.php">2FA</a><?php } ?>
    <?php if (empty($_SESSION['AUTH_SSO'])) { ?><a href="<?php echo $_aut_r; ?>Modules/Authentication/ChangePassword.php">Mot de passe</a><?php } ?>
    <a href="<?php echo $_aut_r; ?>Modules/Authentication/LogOut.php">Déconnexion</a>
</div>
    <?php
    unset($_aut_r, $_aut_views, $_aut_curRole, $_aut_curScope, $_aut_curIdx, $_aut_i, $_aut_v);
}

/* ---- Message flash (ex. refus d'import) — affiché une fois ---- */
if ($_aut_on) {
    $_aut_flash = aut_flash_get();
    if ($_aut_flash !== '') {
        echo '<div id="aut-flash" style="position:fixed; top:40px; left:50%; transform:translateX(-50%);'
            . ' z-index:99995; max-width:640px; background:#fde8e8; border:2px solid #c0392b; color:#8b1a1a;'
            . ' font:13px Verdana,Arial,sans-serif; border-radius:6px; padding:12px 40px 12px 16px;'
            . ' box-shadow:0 4px 16px rgba(0,0,0,.25);">'
            . $_aut_flash
            . '<span onclick="document.getElementById(\'aut-flash\').remove()" style="position:absolute;'
            . ' top:8px; right:12px; cursor:pointer; font-weight:bold;">✕</span></div>';
    }
    unset($_aut_flash);
}

/* ---- Formulaire compétition : contrôle du code en direct + restauration
       de la saisie après un refus serveur (aut_guard_tournament_save) ---- */
if ($_aut_logged && empty($_SESSION['AUTH_ROOT'])
    && strcasecmp(aut_script_rel(), '/Tournament/index.php') === 0) {
    $_aut_blk = $_SESSION['AUT_SaveBlock'] ?? null;
    unset($_SESSION['AUT_SaveBlock']);
    ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var codeEl = document.querySelector('input[name="d_ToCode"]');
    if (!codeEl) return;
    var curCode = <?php echo json_encode($_SESSION['TourCode'] ?? ''); ?>;
    var checkUrl = <?php echo json_encode($CFG->ROOT_DIR . 'Modules/Custom/AUTH/check-code.php'); ?>;

    var msgEl = document.createElement('div');
    msgEl.style.cssText = 'display:none; margin-top:3px; padding:5px 8px; border-radius:4px;'
        + ' background:#fde8e8; border:1px solid #c0392b; color:#8b1a1a; font:11px Verdana,sans-serif;'
        + ' max-width:420px;';
    codeEl.parentNode.appendChild(msgEl);

    var codeFree = null;   // null = pas encore vérifié
    function showMsg(t) { msgEl.textContent = t; msgEl.style.display = t ? 'block' : 'none'; }
    function checkCode() {
        var v = codeEl.value.trim();
        if (!v || (curCode && v.toLowerCase() === curCode.toLowerCase())) { codeFree = true; showMsg(''); return; }
        fetch(checkUrl + '?code=' + encodeURIComponent(v), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { codeFree = !!j.free; showMsg(j.free ? '' : (j.msg || 'Ce code est déjà utilisé.')); })
            .catch(function () { codeFree = null; showMsg(''); });   // au doute, la garde serveur tranchera
    }
    codeEl.addEventListener('change', checkCode);
    codeEl.addEventListener('keyup', function () { codeFree = null; });
    if (codeEl.form) {
        codeEl.form.addEventListener('submit', function (e) {
            if (codeFree === false) {
                e.preventDefault();
                showMsg(msgEl.textContent || 'Ce code est déjà utilisé — choisissez-en un autre.');
                codeEl.focus(); codeEl.select();
            }
        });
    }
<?php if ($_aut_blk) { ?>
    /* refus serveur : on réinjecte la saisie, l'utilisateur ne change que le code */
    var blkData = <?php echo json_encode($_aut_blk['data']); ?>;
    function applyBlk() {
        for (var k in blkData) {
            var el = document.querySelector('[name="' + k + '"]');
            if (!el) continue;
            if (el.type === 'checkbox') { el.checked = blkData[k] !== '' && blkData[k] !== '0'; }
            else { el.value = blkData[k]; }
        }
    }
    applyBlk();
    ['d_Rule', 'd_ToType'].forEach(function (n) {   // relance les listes dépendantes
        var el = document.querySelector('[name="' + n + '"]');
        if (el) el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    setTimeout(applyBlk, 600);
    showMsg(<?php echo json_encode($_aut_blk['msg']); ?>);
    codeFree = false;
    codeEl.focus(); codeEl.select();
<?php } ?>
});
</script>
    <?php
    unset($_aut_blk);
}

/* ---- Politique ISK : retirer les modes interdits (pro/live) du sélecteur de la page
       compétition. L'enforcement serveur (aut_isk_enforce) reste le filet. ---- */
if ($_aut_on && strcasecmp(aut_script_rel(), '/Tournament/index.php') === 0) {
    ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var blocked = <?php echo json_encode(array_values(aut_isk_blocked_modes())); ?>;
    var sel = document.getElementById('IskSelect');
    if (!sel) return;
    blocked.forEach(function (v) {
        var o = sel.querySelector('option[value="' + v + '"]');
        if (o) o.remove();
    });
    if (blocked.indexOf(sel.value) >= 0) {           // un mode interdit était retenu
        var lite = sel.querySelector('option[value="ng-lite"]');
        sel.value = lite ? 'ng-lite' : '';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
});
</script>
    <?php
}

// alerte admin (compte ADMIN connecté OU navigation localhost) : fichiers
// déployés absents ou différents de dist/ (ex. après une MaJ du module/ianseo)
if ($_aut_on && $_aut_admin) {
    $_aut_st = aut_dist_status();
    if (!$_aut_st['deployed'] || $_aut_st['drift']) {
        echo '<style>#aut-warn { position:fixed; top:30px; right:8px; z-index:99990; background:#8b1a1a;'
            . ' color:#fff; font:11px Verdana,Arial,sans-serif; border-radius:4px; padding:4px 12px; }'
            . ' #aut-warn a { color:#ffd7d7; }</style>'
            . '<div id="aut-warn">⚠ Fichiers d\'authentification à redéployer — '
            . '<a href="' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/deploy.php">Déploiement</a></div>';
    }
    unset($_aut_st);
}
unset($_aut_on, $_aut_logged, $_aut_root, $_aut_admin);
