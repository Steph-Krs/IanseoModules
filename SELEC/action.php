<?php
/**
 * action.php — points d'entrée AJAX du module.
 * Toutes les actions sont protégées par le jeton de session (selec_check_token)
 * et bornées à la compétition ouverte : aucune ne prend d'identifiant de
 * compétition en paramètre.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

selec_check_token();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'ancrer':
        $r = selec_config_ancrer($SELEC_TOUR, (string) ($_POST['mode'] ?? ''));
        if (!$r['ok']) JsonOut(array('ok' => false, 'err' => implode('<br>', $r['erreurs'])));
        JsonOut(array('ok' => true, 'msg' => 'Mode rattaché et figé pour cette compétition.'));
        break;

    case 'categories':
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        $cats = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['cats'] ?? '')))));
        $connues = selec_categories($SELEC_TOUR);
        $cats = array_values(array_intersect($cats, array_keys($connues)));
        $opt = $cfg['options'];
        $opt['categories'] = $cats;
        selec_options_ecrire($SELEC_TOUR, $opt);
        JsonOut(array('ok' => true, 'msg' => count($cats) . ' catégorie(s) traitée(s).'));
        break;

    case 'bind':
        $cat  = (string) ($_POST['cat'] ?? '');
        $step = (string) ($_POST['step'] ?? '');
        $slot = (string) ($_POST['slot'] ?? '');
        $ev   = (string) ($_POST['event'] ?? '');
        $connues = selec_categories($SELEC_TOUR);
        if (!isset($connues[$cat]))          JsonOut(array('ok' => false, 'err' => 'Catégorie inconnue.'));
        if ($ev !== '' && !isset($connues[$ev])) JsonOut(array('ok' => false, 'err' => 'Épreuve inconnue.'));
        selec_bind_ecrire($SELEC_TOUR, $cat, $step, $slot, $ev);
        selec_log($SELEC_TOUR, 'bind', array('step' => $step, 'slot' => $slot, 'event' => $ev), $cat);
        JsonOut(array('ok' => true, 'msg' => $ev === ''
            ? "Rattachement retiré ($step / $slot)."
            : "$step / $slot → $ev."));
        break;

    case 'structure':
        // Génère sessions, distances et épreuves de duels depuis le mode.
        // Rien n'est jamais écrasé : ce qui existe est laissé tel quel.
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg || !$cfg['snapshot']) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        if (IsBlocked(BIT_BLOCK_TOURDATA)) JsonOut(array('ok' => false, 'err' => 'Compétition verrouillée par ianseo.'));

        $cats = selec_categories_actives($SELEC_TOUR, $cfg);
        $quoi = array(
            'sessions' => !empty($_POST['sessions']),
            'epreuves' => !empty($_POST['epreuves']),
            'duels'    => !empty($_POST['duels']),
        );
        if (!$quoi['sessions'] && !$quoi['epreuves'] && !$quoi['duels']) {
            JsonOut(array('ok' => false, 'err' => 'Rien de sélectionné à générer.'));
        }

        // Les duels simulés d'abord : retirer l'épreuve caduque de l'ancien
        // format avant de créer les nouvelles évite de laisser deux épreuves
        // concurrentes rattachées à la même étape.
        $purge = array('supprimees' => 0, 'details' => array());
        if ($quoi['duels'] || $quoi['epreuves']) {
            $purge = selec_structure_purger_duels($SELEC_TOUR, $cfg['snapshot'], $cats);
        }
        $r = selec_structure_appliquer($SELEC_TOUR, $cfg['snapshot'], $cats, $quoi, $cfg['options']);
        foreach ($purge['details'] as $d) array_unshift($r['faits'], $d);

        // Réparation des épreuves déjà créées : blason manquant (sans lui,
        // l'impression « Score du match » sort une page vide) et portée posée à
        // tort sur une consolante.
        $rep = selec_structure_reparer($SELEC_TOUR, $cfg['snapshot'], $cats);
        foreach ($rep['details'] as $d) $r['faits'][] = $d;

        $msg = $quoi['duels'] && !$quoi['sessions'] && !$quoi['epreuves']
            ? $r['epreuves'] . ' épreuve(s) de duels simulés créées, '
              . $purge['supprimees'] . ' ancienne(s) retirée(s).'
            : $r['sessions'] . ' session(s) et ' . $r['epreuves'] . ' épreuve(s) créées.';
        if ($r['faits']) {
            $msg .= '<br>' . implode('<br>', array_map('htmlspecialchars', array_slice($r['faits'], 0, 40)));
            if (count($r['faits']) > 40) $msg .= '<br>… (' . (count($r['faits']) - 40) . ' de plus)';
        }
        if ($r['erreurs']) {
            JsonOut(array('ok' => false, 'err' => implode('<br>', array_map('htmlspecialchars', $r['erreurs']))));
        }
        JsonOut(array('ok' => true, 'msg' => $msg));
        break;

    case 'duels_reglages':
        // Heure du premier tour de chaque tournoi, et première cible de chaque
        // catégorie. Le reste (35 min par tour, enchaînement, répartition des
        // cibles entre tableau principal et consolante) se déduit.
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg || !$cfg['snapshot']) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        $cats = selec_categories_actives($SELEC_TOUR, $cfg);
        $opt = $cfg['options'];
        $duels = array('duree' => max(1, intval($_POST['duree'] ?? 35)),
                       'cibles' => array(), 'horaires' => array());

        foreach ((array) json_decode((string) ($_POST['cibles'] ?? ''), true) as $c => $v) {
            if (in_array((string) $c, $cats, true)) $duels['cibles'][(string) $c] = max(1, intval($v));
        }
        foreach ((array) json_decode((string) ($_POST['horaires'] ?? ''), true) as $s => $v) {
            $d = preg_replace('/[^0-9-]/', '', (string) ($v['date'] ?? ''));
            $h = preg_replace('/[^0-9:]/', '', (string) ($v['heure'] ?? ''));
            if ($d === '' && $h === '') continue;
            $duels['horaires'][(string) $s] = array('date' => $d, 'heure' => $h);
        }
        $opt['duels'] = $duels;
        selec_options_ecrire($SELEC_TOUR, $opt);

        if (!empty($_POST['appliquer'])) {
            if (IsBlocked(BIT_BLOCK_TOURDATA)) JsonOut(array('ok' => false, 'err' => 'Compétition verrouillée par ianseo.'));
            $pl = selec_structure_planning($SELEC_TOUR, $cfg['snapshot'], $cats, $opt);
            if ($pl['erreurs']) {
                JsonOut(array('ok' => false, 'err' => implode('<br>', array_map('htmlspecialchars', $pl['erreurs']))));
            }
            JsonOut(array('ok' => true, 'msg' => $pl['lignes'] . ' affectation(s) écrites.<br>'
                . implode('<br>', array_map('htmlspecialchars', $pl['faits']))));
        }
        JsonOut(array('ok' => true, 'msg' => 'Réglages des duels enregistrés.'));
        break;

    case 'prepa_plan':
    case 'prepa_appliquer':
        // Prépare l'étape qui suit celle demandée : replacement des archers sur
        // le départ à venir, ou validation des qualifications + génération des
        // tableaux. Toujours en deux temps — on regarde, puis on applique.
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg || !$cfg['snapshot']) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        if ($action === 'prepa_appliquer' && IsBlocked(BIT_BLOCK_TOURDATA)) {
            JsonOut(array('ok' => false, 'err' => 'Compétition verrouillée par ianseo.'));
        }

        $stepId = (string) ($_POST['step'] ?? '');
        $base   = (string) ($_POST['base'] ?? '');
        $mode   = $cfg['snapshot'];
        if (!selec_prepa_etape($mode, $stepId)) JsonOut(array('ok' => false, 'err' => 'Étape inconnue.'));

        // Un départ est partagé par toutes les catégories : le classement de
        // chacune est nécessaire, même quand le bouton part d'une seule page.
        $cats = selec_categories_actives($SELEC_TOUR, $cfg);
        $binds = selec_binds_tous($SELEC_TOUR);
        $classements = array();
        foreach ($cats as $c) {
            $classements[$c] = selec_calculer($SELEC_TOUR, $c, $mode,
                isset($binds[$c]) ? $binds[$c] : array());
        }

        // Plages de cibles saisies par l'opérateur, une par catégorie.
        // Vides au premier appel : le module propose alors un enchaînement.
        $plages = array();
        if (!empty($_POST['plages'])) {
            $decode = json_decode((string) $_POST['plages'], true);
            if (is_array($decode)) {
                foreach ($decode as $c => $p) {
                    if (!in_array((string) $c, $cats, true)) continue;
                    $plages[(string) $c] = array(
                        'actif' => !empty($p['actif']),
                        'de'    => preg_replace('/[^0-9A-Za-z]/', '', (string) ($p['de'] ?? '')),
                        'a'     => preg_replace('/[^0-9A-Za-z]/', '', (string) ($p['a'] ?? '')),
                    );
                }
            }
        }

        $plan = selec_prepa_plan($SELEC_TOUR, $mode, $cats, $classements, $stepId, $base, $plages);

        if ($action === 'prepa_plan') {
            JsonOut(array('ok' => true, 'plan' => $plan));
        }

        // Le gel de l'étape terminée fait partie de la préparation : c'est lui
        // qui rend le passage à la suite sans risque.
        $r = selec_prepa_appliquer($SELEC_TOUR, $plan, $mode, $cats, $stepId);
        if (!$r['ok'] || $r['erreurs']) {
            JsonOut(array('ok' => false,
                'err' => implode('<br>', array_map('htmlspecialchars', $r['erreurs']))
                    ?: 'Préparation impossible.'));
        }
        JsonOut(array('ok' => true, 'msg' => implode('<br>', array_map('htmlspecialchars', $r['faits']))));
        break;

    case 'sessions_bascule':
        // Verrouillage ISK-NG d'une étape entière, sans quitter la page.
        // Même ACL que l'écran natif (Api/ISK-NG/Sessions.php) : ouvrir ou
        // fermer la saisie tablette n'est pas du ressort d'un simple lecteur.
        if (!hasFullACL(AclISKServer, 'iskManagement', AclReadWrite)) {
            JsonOut(array('ok' => false, 'err' => 'Droits insuffisants pour verrouiller les sessions.'));
        }
        if (IsBlocked(BIT_BLOCK_TOURDATA)) {
            JsonOut(array('ok' => false, 'err' => 'Compétition verrouillée par ianseo.'));
        }
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg || !$cfg['snapshot']) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        $r = selec_lock_basculer($SELEC_TOUR, $cfg['snapshot'],
            (string) ($_POST['step'] ?? ''), (string) ($_POST['sens'] ?? ''));
        if (!$r['ok']) JsonOut($r);
        $r['msg'] = ($r['sens'] === 'lock' ? 'Saisie verrouillée' : 'Saisie ouverte')
            . ' sur ' . $r['total'] . ' session(s).';
        JsonOut($r);
        break;

    case 'gel_etat':
    case 'gel_geler':
    case 'gel_restaurer':
    case 'gel_degeler':
        // Verrouillage d'une étape tirée, et retour en arrière encadré.
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg || !$cfg['snapshot']) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        $mode = $cfg['snapshot'];
        $stepId = (string) ($_POST['step'] ?? '');
        if (!selec_prepa_etape($mode, $stepId)) JsonOut(array('ok' => false, 'err' => 'Étape inconnue.'));
        $cats = selec_categories_actives($SELEC_TOUR, $cfg);

        if ($action === 'gel_etat') {
            $ec = selec_arch_ecarts($SELEC_TOUR, $mode, $stepId, $cats);
            JsonOut(array('ok' => true, 'ecarts' => $ec));
        }

        if (IsBlocked(BIT_BLOCK_QUAL)) JsonOut(array('ok' => false, 'err' => 'Qualifications verrouillées par ianseo.'));

        if ($action === 'gel_geler') {
            $r = selec_arch_geler($SELEC_TOUR, $mode, $stepId, $cats);
            if (!$r['ok']) JsonOut(array('ok' => false, 'err' => implode('<br>', array_map('htmlspecialchars', $r['erreurs']))));
            JsonOut(array('ok' => true, 'msg' => $r['archers'] . ' archer(s) archivés : scores, 10, X '
                . 'et flèches figés pour l\'étape ' . htmlspecialchars($stepId) . '.'));
        }

        if ($action === 'gel_degeler') {
            $n = selec_arch_degeler($SELEC_TOUR, $stepId);
            JsonOut(array('ok' => true, 'msg' => 'Verrou retiré (' . intval($n) . ' ligne(s) d\'archive '
                . 'supprimées). Les scores en base n\'ont pas été touchés — l\'étape sera de nouveau '
                . 'lue dans ianseo.'));
        }

        // Restauration : on remet en base ce qui avait été figé.
        $avecPlacement = !empty($_POST['placement']);
        $r = selec_arch_restaurer($SELEC_TOUR, $mode, $stepId, $avecPlacement);
        if (!$r['ok']) JsonOut(array('ok' => false, 'err' => implode('<br>', array_map('htmlspecialchars', $r['erreurs']))));
        JsonOut(array('ok' => true, 'msg' => $r['archers'] . ' archer(s) restaurés depuis l\'archive'
            . ($avecPlacement ? ', départs et cibles compris' : ' (scores seuls)') . '.'));
        break;

    case 'selfheal':
        // Redéploiement forcé du set « Sélection » (page de configuration).
        $r = selec_selfheal(true);
        if (!$r['ok']) JsonOut(array('ok' => false, 'err' => implode('<br>', array_map('htmlspecialchars', $r['erreurs']))));
        JsonOut(array('ok' => true, 'msg' => 'Set déployé (type n° ' . intval($r['type']) . ') : '
            . htmlspecialchars(implode(', ', $r['faits'])) . '.'));
        break;

    case 'calculer':
        $cfg = selec_config_lire($SELEC_TOUR);
        if (!$cfg || !$cfg['snapshot']) JsonOut(array('ok' => false, 'err' => 'Compétition non rattachée à un mode.'));
        $cats = selec_categories_actives($SELEC_TOUR, $cfg);
        $binds = selec_binds_tous($SELEC_TOUR);
        $n = 0; $alertes = array();
        foreach ($cats as $code) {
            $ctx = selec_calculer($SELEC_TOUR, $code, $cfg['snapshot'],
                isset($binds[$code]) ? $binds[$code] : array());
            $n += selec_enregistrer($ctx);
            foreach ($ctx['alertes'] as $a) $alertes[] = htmlspecialchars($code . ' : ' . $a);
        }
        $msg = count($cats) . ' catégorie(s) recalculée(s), ' . $n . ' lignes de classement.';
        if ($alertes) {
            $msg .= '<br><b>' . count($alertes) . ' point(s) de vigilance :</b><br>'
                  . implode('<br>', array_slice($alertes, 0, 30));
            if (count($alertes) > 30) $msg .= '<br>… (' . (count($alertes) - 30) . ' de plus)';
        }
        JsonOut(array('ok' => true, 'msg' => $msg, 'alertes' => count($alertes)));
        break;

    case 'barrage':
        // Ordre du tir de barrage pour une étape : « 1 » gagne.
        $cat  = (string) ($_POST['cat'] ?? '');
        $step = (string) ($_POST['step'] ?? '');
        $id   = intval($_POST['entry'] ?? 0);
        $ordre = intval($_POST['ordre'] ?? 0);
        $connues = selec_categories($SELEC_TOUR);
        if (!isset($connues[$cat])) JsonOut(array('ok' => false, 'err' => 'Catégorie inconnue.'));
        // L'archer doit appartenir à la compétition : borne systématique, la table
        // Qualifications n'a pas de colonne de compétition et Entries est la seule
        // à porter EnTournament.
        $rs = safe_r_sql("SELECT EnId FROM Entries WHERE EnId=$id AND EnTournament=$SELEC_TOUR");
        if (!$rs || !safe_fetch($rs)) JsonOut(array('ok' => false, 'err' => 'Archer hors de cette compétition.'));

        if ($ordre > 0) {
            safe_w_sql("INSERT INTO SELEC_Shootoff SET
                SoTournament=$SELEC_TOUR, SoCategory=" . StrSafe_DB($cat) . ",
                SoStep=" . StrSafe_DB($step) . ", SoEntry=$id, SoOrder=$ordre,
                SoNote=" . StrSafe_DB(mb_substr((string) ($_POST['note'] ?? ''), 0, 80)) . ",
                SoDate=" . StrSafe_DB(date('Y-m-d H:i:s')) . "
                ON DUPLICATE KEY UPDATE SoOrder=VALUES(SoOrder), SoNote=VALUES(SoNote), SoDate=VALUES(SoDate)");
        } else {
            safe_w_sql("DELETE FROM SELEC_Shootoff WHERE SoTournament=$SELEC_TOUR
                AND SoCategory=" . StrSafe_DB($cat) . " AND SoStep=" . StrSafe_DB($step) . "
                AND SoEntry=$id");
        }
        selec_log($SELEC_TOUR, 'barrage', array('step' => $step, 'entry' => $id, 'ordre' => $ordre), $cat);
        JsonOut(array('ok' => true, 'msg' => 'Barrage enregistré. Relancez le calcul pour l\'appliquer.'));
        break;

    default:
        JsonOut(array('ok' => false, 'err' => 'Action inconnue.'));
}
