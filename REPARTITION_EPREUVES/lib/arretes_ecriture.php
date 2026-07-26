<?php
/**
 * lib/arretes_ecriture.php — écriture des lignes consolidées de l'arrêté vers
 * ianseo (Entries), en réutilisant l'import natif « par liste »
 * (Partecipants/ListLoad.php) plutôt que de dupliquer sa logique d'insertion.
 *
 * Sécurité — ce que ListLoad.php fait et ne fait PAS (vérifié en lisant tout le
 * fichier avant d'écrire ce module) :
 *  - une ligne texte devient toujours une INSERTION si aucune entrée existante
 *    ne correspond ; la mise à jour d'une entrée déjà présente n'a lieu QUE si
 *    `OverwritePreviousArchers` est transmis, et l'appariement s'y fait par
 *    licence SEULE (EnCode), pas licence+division+classe — or la licence n'est
 *    justement pas une clé d'inscription (un archer peut être inscrit plusieurs
 *    fois). On ne transmet donc JAMAIS ce paramètre : chaque import n'insère que
 *    des inscriptions nouvelles, jamais ne modifie une inscription existante.
 *  - la suppression des inscriptions absentes du texte importé n'a lieu QUE si
 *    `DeletePreviousArchers` est transmis (case décochée par défaut dans l'écran
 *    natif). On ne le transmet JAMAIS non plus : aucune suppression possible.
 *  - la division/classe « OF »/« COA » (coach) fait passer `EnAthlete` à 0
 *    automatiquement (recalcul ianseo via Divisions.DivAthlete/Classes.ClAthlete
 *    après import) — À CONDITION que ces division/classe existent déjà pour LA
 *    compétition visée (elles sont définies par compétition, pas globalement) :
 *    rep_imp_of_coa_ok() vérifie cette précondition avant tout import de coach.
 * En conséquence, notre propre vérification d'existant (rep_imp_deja_present)
 * est ce qui protège contre les doublons — pas un paramètre de ListLoad.
 */

if (!function_exists('rep_coll')) require_once __DIR__ . '/schema.php';

/** Une inscription (licence+division+classe) existe-t-elle déjà dans la compétition ? */
function rep_imp_deja_present($tourId, $licence, $division, $class)
{
    $rs = safe_r_sql("SELECT EnId FROM Entries
        WHERE EnTournament=" . intval($tourId) . "
          AND EnCode " . rep_coll() . " = " . StrSafe_DB($licence) . "
          AND EnDivision=" . StrSafe_DB($division) . "
          AND EnClass=" . StrSafe_DB($class) . " LIMIT 1");
    return ($rs && safe_fetch($rs)) ? true : false;
}

/** La compétition a-t-elle Division « OF » et Classe « COA » configurées (coachs) ? */
function rep_imp_of_coa_ok($tourId)
{
    $tourId = intval($tourId);
    $rs1 = safe_r_sql("SELECT COUNT(*) AS n FROM Divisions WHERE DivTournament=$tourId AND DivId='OF'");
    $r1 = $rs1 ? safe_fetch($rs1) : null;
    $rs2 = safe_r_sql("SELECT COUNT(*) AS n FROM Classes WHERE ClTournament=$tourId AND ClId='COA'");
    $r2 = $rs2 ? safe_fetch($rs2) : null;
    return ($r1 && intval($r1->n) > 0) && ($r2 && intval($r2->n) > 0);
}

/**
 * Prépare les lignes prêtes à importer à partir de l'état consolidé : filtre
 * les lignes incomplètes, en conflit non résolu, déjà présentes dans ianseo, ou
 * (pour les coachs) bloquées par l'absence de OF/COA. Ne fait AUCUNE écriture.
 * Retourne ['pretes'=>[...], 'ignorees'=>[['ligne'=>..,'motif'=>..], ...]].
 */
function rep_imp_preparer_ecriture($tourId)
{
    $r = rep_imp_consolider($tourId);
    $ofCoaOk = rep_imp_of_coa_ok($tourId);

    $pretes = []; $ignorees = [];
    foreach ($r['consolide'] as $l) {
        if ($l['licence'] === '') continue;
        if ($l['incomplet']) { $ignorees[] = ['ligne' => $l, 'motif' => 'division/classe incomplète']; continue; }
        if ($l['conflit'])   { $ignorees[] = ['ligne' => $l, 'motif' => 'conflit non résolu']; continue; }
        if ($l['role'] === 'coach' && !$ofCoaOk) {
            $ignorees[] = ['ligne' => $l, 'motif' => "compétition sans division « OF » / classe « COA »"];
            continue;
        }
        if (rep_imp_deja_present($tourId, $l['licence'], $l['division'], $l['class'])) {
            $ignorees[] = ['ligne' => $l, 'motif' => 'déjà inscrit dans cette compétition'];
            continue;
        }
        $pretes[] = $l;
    }
    return ['pretes' => $pretes, 'ignorees' => $ignorees];
}

/**
 * Les 5 drapeaux de qualification/finale au sens exact de ListLoad.php — précisés
 * par l'utilisateur après une première version erronée :
 *   6) IndQual        : 1 pour TOUT archer (tout le monde tire une qualification,
 *                       même un archer qui ne compte que pour son équipe)
 *   7) TeamQual       : 1 seulement pour un participant aux épreuves par équipe
 *   8) IndFinEvent    : 1 seulement pour un participant à l'épreuve individuelle
 *                       réelle (ni les équipiers seuls, ni les étrangers HORS_F)
 *   9) TeamFinEvent   : 1 seulement pour un participant aux épreuves par équipe
 *                       (même condition que 7)
 *   10) MixedTeamFinEvent : 1 seulement pour un participant au double mixte
 * Un coach (role='coach') n'a aucun de ces drapeaux : il ne tire jamais.
 */
function rep_imp_drapeaux($l)
{
    $estArcher = ($l['role'] === 'archer');
    return [
        'indQual'     => $estArcher ? 1 : 0,
        'teamQual'    => $l['equipe'] ? 1 : 0,
        'indFin'      => ($estArcher && $l['indiv']) ? 1 : 0,
        'teamFin'     => $l['equipe'] ? 1 : 0,
        'mixTeamFin'  => $l['doublemixte'] ? 1 : 0,
    ];
}

/**
 * Une ligne au format texte natif ianseo (Partecipants/ListLoad.php), pour le
 * mode d'import DIRECT uniquement (jamais téléchargée telle quelle — voir
 * rep_imp_generer_csv() pour le fichier destiné à l'utilisateur).
 *
 * Nom/prénom sont volontairement laissés VIDES : ListLoad.php les réassigne
 * intégralement (et inconditionnellement) depuis la base fédérale LookUpEntries
 * dès qu'elle trouve la licence — nos valeurs y seraient de toute façon ignorées.
 * Le sexe, lui, EST transmis : un champ vide y est lu comme "féminin" par défaut
 * (`$Sex2Save = (...) ? "1" : "0"` — une chaîne vide satisfait la condition), ce
 * qui inverserait le sexe de tout archer non trouvé dans LookUpEntries (compétition
 * sans code fédéral `ToIocCode`, ou licence absente de cette base) : on transmet
 * donc toujours notre valeur résolue plutôt que de compter sur ce filet.
 * Codes/nom de club en positions 14/15 (Country/Nation) : jamais en 11/12, qui sont
 * les positions FIXES de FamilyName/Name dans ListLoad.php — les décaler y ferait
 * lire un code de club comme un nom d'archer.
 */
function rep_imp_champs_directs($l)
{
    $d = rep_imp_drapeaux($l);
    return [
        $l['licence'],
        0,                       // Départ : posé plus tard par le plan des départs
        $l['division'],
        $l['class'],
        '',                      // Cible : idem
        $d['indQual'], $d['teamQual'], $d['indFin'], $d['teamFin'], $d['mixTeamFin'],
        '',                      // FamilyName : laissé à LookUpEntries
        '',                      // Name : idem
        $l['sexe'] === 'F' ? 1 : 0,
        $l['clubcode'],          // Country (position réelle 14)
        $l['clubnom'],           // Nation (position réelle 15)
    ];
}

/** Texte complet transmis à ListLoad.php (une ligne par inscription). */
function rep_imp_generer_texte_direct($lignes)
{
    $out = [];
    foreach ($lignes as $l) {
        $out[] = implode(';', array_map(function ($v) {
            return str_replace([';', "\t", "\n", "\r"], ' ', (string) $v);
        }, rep_imp_champs_directs($l)));
    }
    return implode("\n", $out) . "\n";
}

/**
 * Fichier destiné à l'utilisateur (bouton « Télécharger »), au même format que
 * `Modules/Custom/EXPORT_LISTE` : les 10 colonnes du format d'import natif, plus
 * code club et nom de club en colonnes 11/12 — ajoutées pour la relecture, PAS
 * pour être réimportées telles quelles (à retirer avant réimport dans ianseo,
 * comme documenté par EXPORT_LISTE). $avecEntete ajoute une ligne d'en-tête,
 * $avecBom un marqueur UTF-8 pour Excel — ni l'un ni l'autre compatibles avec un
 * réimport direct.
 */
function rep_imp_generer_csv($lignes, $avecEntete = false, $avecBom = false)
{
    $sep = ';';
    $nettoie = function ($v) use ($sep) { return str_replace([$sep, "\t", "\r", "\n"], ' ', (string) $v); };

    $out = $avecBom ? "\xEF\xBB\xBF" : '';
    if ($avecEntete) {
        $out .= implode($sep, ['Licence', 'Depart', 'Division', 'Classe', 'Cible',
            'QualifInd', 'QualifEquipe', 'FinaleInd', 'FinaleEquipe', 'DoubleMixte',
            'NoAgrement', 'Club']) . "\r\n";
    }
    foreach ($lignes as $l) {
        $d = rep_imp_drapeaux($l);
        $out .= implode($sep, [
            $nettoie($l['licence']), '', $nettoie($l['division']), $nettoie($l['class']), '',
            $d['indQual'], $d['teamQual'], $d['indFin'], $d['teamFin'], $d['mixTeamFin'],
            $nettoie($l['clubcode']), $nettoie($l['clubnom']),
        ]) . "\r\n";
    }
    return $out;
}

/**
 * État réel (classe, sexe) des inscriptions Entries pour une liste de couples
 * (licence, division) — jamais la classe dans la clé de recherche elle-même
 * (voir rep_imp_ecrire_direct()). Retourne [ "LICENCE|DIVISION" => ['class','sexe'] ].
 */
function rep_imp_entries_reelles($tourId, $couples)
{
    $out = [];
    foreach (array_chunk($couples, 200) as $paquet) {
        $rs = safe_r_sql("SELECT EnCode, EnDivision, EnClass, EnSex FROM Entries
            WHERE EnTournament=" . intval($tourId) . "
              AND (EnCode " . rep_coll() . ", EnDivision) IN (" . implode(',', $paquet) . ")");
        while ($rs && $r = safe_fetch($rs)) {
            $cle = mb_strtoupper($r->EnCode, 'UTF-8') . '|' . mb_strtoupper($r->EnDivision, 'UTF-8');
            $out[$cle] = ['class' => trim((string) $r->EnClass), 'sexe' => (intval($r->EnSex) === 1 ? 'F' : 'H')];
        }
    }
    return $out;
}

/**
 * Mode direct : appelle Partecipants/ListLoad.php en interne avec les lignes
 * prêtes, sans jamais transmettre OverwritePreviousArchers ni
 * DeletePreviousArchers (voir l'en-tête du fichier). Nécessite que
 * l'utilisateur courant tienne aussi l'ACL AclParticipants — vérifié par
 * l'appelant (rep_imp_direct_possible()) avant de proposer ce mode.
 *
 * Vérification par (licence, division) — PAS licence+division+classe : quand
 * ListLoad.php trouve la licence dans la base fédérale (LookUpEntries) et que
 * celle-ci porte une date de naissance, il recalcule la classe d'âge réelle de
 * l'archer et écrase silencieusement la nôtre si elle ne correspond pas (la
 * division — l'arme — n'est jamais recalculée ainsi). Vérifier la classe exacte
 * ferait donc apparaître à tort des inscriptions bien créées comme « manquantes »
 * — bug réel signalé par l'utilisateur (848 attendues, 818 « trouvées », alors que
 * les 848 étaient bien inscrites).
 *
 * Retourne ['ok','err','avant','apres','manquantes','modifications'] :
 *  - 'manquantes' : lignes dont la (licence, division) reste introuvable après
 *    l'import — de vraies anomalies (ex. refusées par ListLoad faute de classe
 *    valide pour l'âge de l'archer dans LookUpEntries).
 *  - 'modifications' : lignes bien inscrites mais dont la classe et/ou le sexe
 *    stockés diffèrent de ce qu'on a soumis — informationnel, jamais bloquant.
 *    C'est ici qu'apparaît par exemple un sexe recalculé parce que le mauvais
 *    suffixe de classe (F/H au lieu de M/W, ou l'inverse) a été soumis : la
 *    classe soumise n'existait pas pour cette division, LookUpEntries a fourni
 *    sa propre classe ET son propre sexe à la place (cas réel signalé par
 *    l'utilisateur : la vérification par classe exacte échouait justement à
 *    cause de ce mélange F/H ↔ M/W, ce qui a permis de le détecter).
 */
function rep_imp_ecrire_direct($tourId, $lignes)
{
    if (!$lignes) {
        return ['ok' => false, 'err' => 'Aucune ligne à importer.', 'avant' => 0, 'apres' => 0,
                'manquantes' => [], 'modifications' => []];
    }

    $couples = [];
    $parCle  = [];
    foreach ($lignes as $l) {
        $couples[] = '(' . StrSafe_DB($l['licence']) . ', ' . StrSafe_DB($l['division']) . ')';
        $parCle[mb_strtoupper($l['licence'], 'UTF-8') . '|' . mb_strtoupper($l['division'], 'UTF-8')] = $l;
    }

    $avantDetail = rep_imp_entries_reelles($tourId, $couples);

    $texte = rep_imp_generer_texte_direct($lignes);

    // Isole l'appel : ListLoad.php termine par un rendu de page complet (head/tail),
    // qu'on capture et ignore plutôt que de le laisser fuiter dans notre JSON.
    $_REQUEST['TextList'] = '1';
    $_REQUEST['txtList']  = $texte;
    unset($_REQUEST['OverwritePreviousArchers'], $_REQUEST['DeletePreviousArchers'], $_FILES['UploadedFile']);

    ob_start();
    try {
        require HTDOCS . '/Partecipants/ListLoad.php';
    } catch (\Throwable $e) {
        ob_end_clean();
        return ['ok' => false, 'err' => 'Échec de l\'import natif : ' . $e->getMessage(),
                'avant' => count($avantDetail), 'apres' => count($avantDetail),
                'manquantes' => [], 'modifications' => []];
    }
    ob_end_clean();

    $apresDetail = rep_imp_entries_reelles($tourId, $couples);

    $manquantes = [];
    $modifications = [];
    foreach ($parCle as $cle => $l) {
        if (!isset($apresDetail[$cle])) {
            $manquantes[] = ['licence' => $l['licence'], 'nom' => trim($l['nom'] . ' ' . $l['prenom']),
                             'role' => $l['role'], 'division' => $l['division'], 'class' => $l['class']];
            continue;
        }
        $reel = $apresDetail[$cle];
        if ($reel['class'] !== $l['class'] || $reel['sexe'] !== $l['sexe']) {
            $modifications[] = [
                'licence' => $l['licence'], 'nom' => trim($l['nom'] . ' ' . $l['prenom']), 'role' => $l['role'],
                'division' => $l['division'],
                'class_soumise' => $l['class'], 'class_ianseo' => $reel['class'],
                'sexe_soumis' => $l['sexe'], 'sexe_ianseo' => $reel['sexe'],
            ];
        }
    }

    $ok = empty($manquantes);
    return [
        'ok'  => $ok,
        'err' => $ok ? '' : count($manquantes) . " inscription(s) introuvable(s) après l'import — voir le détail ci-dessous.",
        'avant' => count($avantDetail), 'apres' => count($apresDetail),
        'manquantes' => $manquantes,
        'modifications' => $modifications,
    ];
}

/** Le compte courant tient-il l'ACL requise par ListLoad.php (Participants, avancé) ? */
function rep_imp_direct_possible()
{
    return function_exists('hasFullACL') && hasFullACL(AclParticipants, 'pAdvancedEntries', AclReadWrite);
}

/**
 * Applique la propagation double mixte par club (rep_imp_dm_clubs()) : passe
 * EnTeamMixEvent à 1 pour EXACTEMENT les EnId fournis — jamais une portée plus
 * large redéduite ici, la liste vient de l'aperçu affiché et cochée par
 * l'utilisateur. Bornée à la compétition (EnTournament), comme toute écriture
 * de ce module. Ne fait que POSER le drapeau (jamais l'ôter) : cette action
 * n'a aucune connaissance de qui l'avait légitimement à 0 pour une autre
 * raison. Retourne le nombre de lignes qui portent bien le drapeau après coup
 * (vérification par relecture, pas la simple confiance dans l'UPDATE).
 */
function rep_imp_dm_appliquer($tourId, $enIds)
{
    $tourId = intval($tourId);
    $enIds = array_values(array_unique(array_filter(array_map('intval', (array) $enIds), function ($v) {
        return $v > 0;
    })));
    if (!$enIds) return 0;

    $in = implode(',', $enIds);
    safe_w_sql("UPDATE Entries SET EnTeamMixEvent=1 WHERE EnTournament=$tourId AND EnId IN ($in)");

    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Entries
        WHERE EnTournament=$tourId AND EnId IN ($in) AND EnTeamMixEvent=1");
    $r = $rs ? safe_fetch($rs) : null;
    return $r ? intval($r->n) : 0;
}
