<?php
/**
 * SYNCHRO_FFTA — injection de la synchro licenciés sur LookupTableLoad.php.
 *
 * Inclus par menu.php (lui-même chargé sur toutes les pages). Ne s'active que sur
 * LookupTableLoad.php quand le formulaire de téléchargement est affiché : on intercepte
 * la case « FRA » pour faire la synchro authentifiée via licences-sync.php, en streaming.
 *
 * Repris de l'ancien module Import_licence_direct (dont l'endpoint pointait vers un
 * fichier inexistant Modules/Sets/FR/FFTAAjax.php) — corrigé et intégré ici.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'LookupTableLoad.php') {
    return;
}
if (!empty($GLOBALS['_sfa_lic_inject_done'])) {
    return;
}
$GLOBALS['_sfa_lic_inject_done'] = true;

$__sfa_form = (
    empty($_REQUEST['Download'])           &&
    empty($_FILES['UploadedFile']['name']) &&
    empty($_REQUEST['Photo'])              &&
    empty($_REQUEST['Flags'])              &&
    empty($_REQUEST['Rank'])               &&
    empty($_REQUEST['Clubs'])              &&
    empty($_REQUEST['Records'])            &&
    empty($_REQUEST['Check'])
);
if (!$__sfa_form) {
    return;
}

$__sfa_endpoint = $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/licences-sync.php';

ob_implicit_flush(false);   // stoppe le flush implicite pendant la capture
ob_start();

register_shutdown_function(function () use ($__sfa_endpoint) {
    $html = ob_get_clean();
    if ($html === false || $html === '') {
        return;
    }

    $url = addslashes($__sfa_endpoint);

    $injection = <<<INJECT

<!-- ── SYNCHRO_FFTA — synchro licenciés (Espace Dirigeant) ─────────────── -->
<div id="ffta-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);
            z-index:99999;align-items:center;justify-content:center">
  <div style="background:#fff;padding:2em;border-radius:8px;width:360px;
              box-shadow:0 4px 24px rgba(0,0,0,.35)">
    <h3 style="margin-top:0;color:#01367c">Espace Dirigeant FFTA</h3>
    <p style="color:#666;font-size:.9em;margin-bottom:1.2em">
      Identifiants utilisés une seule fois · jamais stockés · jamais loggés
    </p>
    <div style="margin-bottom:.9em">
      <label style="display:block;margin-bottom:3px">Identifiant</label>
      <input type="text" id="ffta-user" autocomplete="off"
             style="width:100%;box-sizing:border-box;padding:8px;
                    border:1px solid #ccc;border-radius:4px">
    </div>
    <div style="margin-bottom:.9em">
      <label style="display:block;margin-bottom:3px">Mot de passe</label>
      <input type="password" id="ffta-pass" autocomplete="new-password"
             style="width:100%;box-sizing:border-box;padding:8px;
                    border:1px solid #ccc;border-radius:4px">
    </div>
    <div style="margin-bottom:1.2em">
      <label style="display:block;margin-bottom:3px">
        Code MFA
        <span id="ffta-mfa-i" title="Cliquer pour en savoir plus"
              style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;
                     border-radius:50%;background:#0254a8;color:#fff;font-size:11px;font-style:italic;
                     cursor:pointer;font-family:serif">i</span>
        <small style="color:#888">— laisser vide si non activée</small>
      </label>
      <input type="text" id="ffta-otp" autocomplete="off"
             maxlength="8" inputmode="numeric" placeholder="6 chiffres"
             style="width:100%;box-sizing:border-box;padding:8px;
                    border:1px solid #ccc;border-radius:4px">
      <div id="ffta-mfa-help" style="display:none;margin-top:6px;font-size:.8em;color:#555;
                  background:#eef4fb;border-left:3px solid #0254a8;border-radius:0 4px 4px 0;padding:6px 8px">
        La double authentification (MFA) ajoute une sécurité à votre compte. Elle s'active dans les
        paramètres de votre <b>Espace Dirigeant</b> (fortement recommandé). Si elle est activée, saisissez
        ici le <b>code à 6 chiffres</b> affiché par votre application d'authentification (Google
        Authenticator, FreeOTP…). Sinon, laissez ce champ vide.
      </div>
    </div>
    <div id="ffta-err" style="color:#cc3333;margin-bottom:.8em;display:none"></div>
    <div style="display:flex;gap:.8em;justify-content:flex-end">
      <button type="button" id="ffta-cancel"
              style="padding:8px 18px;border:1px solid #ccc;
                     background:#f5f5f5;border-radius:4px;cursor:pointer">
        Annuler
      </button>
      <button type="button" id="ffta-confirm"
              style="padding:8px 18px;background:#0254a8;color:#fff;
                     border:none;border-radius:4px;cursor:pointer">
        Synchroniser
      </button>
    </div>
  </div>
</div>

<div id="ffta-result"
     style="display:none;margin:1em 0;padding:1em;border:1px solid #ccc;
            background:#f9f9f9;font-family:monospace;white-space:pre-wrap"></div>

<script>
(function () {
    'use strict';
    var ENDPOINT = '$url';
    var modal    = document.getElementById('ffta-modal');
    var result   = document.getElementById('ffta-result');
    var form     = document.getElementById('FrmDownload');
    var fraChk   = form ? form.querySelector('input[name="Download[FRA]"]') : null;

    if (!fraChk) return; // pas de case FRA sur cette page

    // « i » MFA : déplie/replie l'explication
    var iBtn = document.getElementById('ffta-mfa-i');
    if (iBtn) iBtn.addEventListener('click', function () {
        var h = document.getElementById('ffta-mfa-help');
        h.style.display = (h.style.display === 'block') ? 'none' : 'block';
    });

    form.addEventListener('submit', function (e) {
        if (!fraChk.checked) return; // autre case → comportement normal
        e.preventDefault();
        // Session Espace Dirigeant déjà disponible (AUTH ou précédente) ? → synchro directe,
        // sans redemander les identifiants.
        var body = new URLSearchParams({ffta_action: 'status'});
        fetch(ENDPOINT, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()})
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.logged) { doSync('', '', ''); } else { openModal(); } })
        .catch(function () { openModal(); });
    });

    document.getElementById('ffta-cancel').addEventListener('click', clearClose);
    document.addEventListener('keydown', function (e) {
        if (modal.style.display !== 'none' && e.key === 'Escape') clearClose();
    });

    document.getElementById('ffta-confirm').addEventListener('click', function () {
        var u = document.getElementById('ffta-user').value.trim();
        var p = document.getElementById('ffta-pass').value;
        if (!u || !p) { showErr('Identifiant et mot de passe requis.'); return; }
        doSync(u, p, document.getElementById('ffta-otp').value.trim());
    });

    function openModal() {
        document.getElementById('ffta-user').value = '';
        document.getElementById('ffta-pass').value = '';
        document.getElementById('ffta-otp').value  = '';
        document.getElementById('ffta-err').style.display = 'none';
        result.style.display = 'none';
        modal.style.display  = 'flex';
        setTimeout(function(){ document.getElementById('ffta-user').focus(); }, 50);
    }

    function doSync(username, password, otp) {
        clearClose();
        form.insertAdjacentElement('afterend', result);
        result.style.display = 'block';
        result.innerHTML = '<span style="color:#666"><i>⏳ Connexion à l\'Espace Dirigeant FFTA…</i></span>';

        var body = new URLSearchParams({
            ffta_action   : 'sync',
            ffta_username : username,
            ffta_password : password,
            ffta_otp      : otp
        });
        username = ''; password = ''; otp = '';

        fetch(ENDPOINT, {
            method      : 'POST',
            credentials : 'same-origin',
            headers     : {'Content-Type': 'application/x-www-form-urlencoded'},
            body        : body.toString()
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            result.innerHTML = '';
            var reader  = r.body.getReader();
            var decoder = new TextDecoder();
            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) return;
                    result.innerHTML += decoder.decode(chunk.value, {stream: true});
                    result.scrollTop = result.scrollHeight;
                    return pump();
                });
            }
            return pump();
        })
        .catch(function (err) {
            result.innerHTML += '<b style="color:#cc3333">Erreur : ' + err.message + '</b>';
        });
    }

    function showErr(msg) {
        var e = document.getElementById('ffta-err');
        e.textContent   = msg;
        e.style.display = 'block';
    }

    function clearClose() {
        document.getElementById('ffta-user').value = '';
        document.getElementById('ffta-pass').value = '';
        document.getElementById('ffta-otp').value  = '';
        modal.style.display = 'none';
    }
})();
</script>
INJECT;

    if (strpos($html, '</body>') !== false) {
        echo str_replace('</body>', $injection . '</body>', $html);
    } else {
        echo $html . $injection;
    }
});
