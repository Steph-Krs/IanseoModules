<?php
/**
 * inc/form-mode.php — sélecteur du mode de sélection.
 * Inclus deux fois par index.php (première association, puis ré-ancrage) :
 * l'identifiant du formulaire reste unique car les deux blocs ne coexistent
 * jamais dans le même état de page.
 */
if (!isset($catalogue)) return;
?>
<form id="f-mode">
  <label>Mode&nbsp;:
    <select name="mode">
      <?php foreach ($catalogue as $id => $m): ?>
        <option value="<?= htmlspecialchars($id) ?>"
          <?= (isset($cfg['mode']) && $cfg['mode'] === $id) ? 'selected' : '' ?>>
          <?= htmlspecialchars($m['libelle']) ?> (v<?= htmlspecialchars($m['version']) ?>,
          <?= intval($m['etapes']) ?> étapes)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn primaire" type="submit">Rattacher cette compétition</button>
</form>
<?php if (!$catalogue): ?>
  <div class="alerte">Aucun mode de sélection n'est livré dans <code>modes/</code>.</div>
<?php endif; ?>
