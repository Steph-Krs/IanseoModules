<?php
/**
 * Set SELEC — déclare le type de compétition « Sélection » et ses sous-règles.
 *
 * Ce fichier est DÉPLOYÉ dans Modules/Sets/SELEC/ par l'auto-réparation du module
 * Custom/SELEC : Modules/Sets/ est réécrit à chaque mise à jour officielle de
 * ianseo, seul Modules/Custom/ survit. La copie de référence est
 * Modules/Custom/SELEC/dist/set/sets.php — c'est elle qu'il faut modifier.
 *
 * ianseo découvre les sets par glob(Modules/Sets/*\/sets.php) et attend que
 * chaque set remplisse $SetType[<CODE>] avec 'descr', 'types' et 'rules'.
 *
 * L'identifiant du type est lu en base plutôt que codé en dur : le module le
 * crée lui-même dans TourTypes, et le numéro retenu peut varier si celui prévu
 * est déjà pris par une version future de ianseo.
 */

$SelecTtId = 0;
$q = safe_r_sql("SELECT TtId FROM TourTypes WHERE TtType='Type_FR_Selection' LIMIT 1");
if ($q && ($r = safe_fetch($q))) $SelecTtId = intval($r->TtId);

if ($SelecTtId) {
    $SetType['SELEC']['descr'] = get_text('Setup-SELEC', 'Install');
    $SetType['SELEC']['noc']   = 'FRA';
    $SetType['SELEC']['types'] = array(
        "$SelecTtId" => isset($TourTypes["$SelecTtId"]) ? $TourTypes["$SelecTtId"] : 'Sélection',
    );
    // Ordre = valeur de la sous-règle moins 1 (ianseo poste l'index + 1).
    $SetType['SELEC']['rules']["$SelecTtId"] = array(
        'SelecTAECL2027E1',
        'SelecTAECL2027E2',
    );
}
unset($SelecTtId, $q, $r);
