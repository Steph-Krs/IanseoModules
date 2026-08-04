<?php
/**
 * lib/schema.php — création et migration des tables REP_*.
 *
 * Collation imposée : utf8mb4_unicode_ci. Les tables ianseo peuvent être en
 * utf8mb4_0900_ai_ci ou _as_ci selon le serveur ; toute jointure entre une
 * colonne VARCHAR d'ici et une colonne VARCHAR de ianseo doit porter un
 * COLLATE utf8mb4_unicode_ci côté REP_, sinon MySQL 8 renvoie l'erreur 1267.
 * Voir rep_coll() ci-dessous, utilisé partout où c'est le cas.
 */

if (!defined('REP_SCHEMA_VERSION')) define('REP_SCHEMA_VERSION', 16);

/** Suffixe de collation à coller derrière une colonne REP_ jointe à du ianseo. */
function rep_coll()
{
    return ' COLLATE utf8mb4_unicode_ci ';
}

/**
 * Ajoute une colonne si elle manque. MySQL n'accepte pas ADD COLUMN IF NOT EXISTS
 * avant la 8.0.29 : on interroge information_schema.
 */
/**
 * Retourne true si la colonne vient d'être ajoutée (fausse si elle existait
 * déjà) — sert à gater une migration de DONNÉES qui ne doit s'exécuter qu'une
 * seule fois (voir migration v12) : rep_schema() tourne à chaque nouvelle
 * session, pas seulement à la toute première fois pour toute l'installation.
 */
function rep_colonne($table, $colonne, $definition)
{
    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = " . StrSafe_DB($table) . "
          AND COLUMN_NAME = " . StrSafe_DB($colonne));
    $r = $rs ? safe_fetch($rs) : null;
    if ($r && intval($r->n) === 0) {
        safe_w_sql("ALTER TABLE `$table` ADD COLUMN `$colonne` $definition");
        return true;
    }
    return false;
}

/** Crée les tables si besoin. Idempotent, protégé par un drapeau de session. */
function rep_schema()
{
    $flag = '_rep_schema_v' . REP_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    // Un classement national téléchargé depuis l'extranet FFTA.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_Classements (
        CcId         INT AUTO_INCREMENT PRIMARY KEY,
        CcFfta       INT          NOT NULL,
        CcAnnee      SMALLINT     NOT NULL,
        CcDiscipline VARCHAR(2)   NOT NULL DEFAULT '',
        CcArme       VARCHAR(40)  NOT NULL DEFAULT '',
        CcSexe       VARCHAR(1)   NOT NULL DEFAULT '',
        CcCategorie  VARCHAR(20)  NOT NULL DEFAULT '',
        CcNiveau     VARCHAR(16)  NOT NULL DEFAULT '',
        CcDistance   VARCHAR(24)  NOT NULL DEFAULT '',
        CcLibelle    VARCHAR(120) NOT NULL DEFAULT '',
        CcNbArchers  SMALLINT     NOT NULL DEFAULT 0,
        CcMaj        DATETIME     NOT NULL,
        UNIQUE KEY uq_ffta (CcFfta),
        KEY k_recherche (CcAnnee, CcDiscipline, CcArme, CcCategorie, CcSexe)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration depuis la version 1 : niveau (classification para) et distance
    // (elle sépare le TAE international du TAE national).
    rep_colonne('REP_Classements', 'CcNiveau',   "VARCHAR(16) NOT NULL DEFAULT '' AFTER CcCategorie");
    rep_colonne('REP_Classements', 'CcDistance', "VARCHAR(24) NOT NULL DEFAULT '' AFTER CcNiveau");
    rep_colonne('REP_Config',      'RcSet',      "VARCHAR(40) NOT NULL DEFAULT '' AFTER RcDiscipline");

    // Une ligne de classement = un archer.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_Rangs (
        CrClassement INT          NOT NULL,
        CrRang       SMALLINT     NOT NULL,
        CrLicence    VARCHAR(12)  NOT NULL DEFAULT '',
        CrNom        VARCHAR(80)  NOT NULL DEFAULT '',
        CrCategorie  VARCHAR(16)  NOT NULL DEFAULT '',
        CrClubCode   VARCHAR(12)  NOT NULL DEFAULT '',
        CrClubNom    VARCHAR(80)  NOT NULL DEFAULT '',
        CrMoyenne    SMALLINT     NOT NULL DEFAULT 0,
        CrQuota      SMALLINT     NOT NULL DEFAULT 0,
        PRIMARY KEY (CrClassement, CrRang),
        KEY k_licence (CrLicence)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Un bloc du plan de départ : une épreuve sur une plage cibles × lettres.
    // CbTournament est la colonne qui manque à Qualifications : ici, tout est cloisonné.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_Blocs (
        CbId          INT AUTO_INCREMENT PRIMARY KEY,
        CbTournament  INT       NOT NULL,
        CbSession     TINYINT   NOT NULL DEFAULT 1,
        CbEvent       VARCHAR(10) NOT NULL DEFAULT '',
        CbDivision    VARCHAR(6)  NOT NULL DEFAULT '',
        CbClass       VARCHAR(8)  NOT NULL DEFAULT '',
        CbT1          SMALLINT  NOT NULL DEFAULT 1,
        CbT2          SMALLINT  NOT NULL DEFAULT 1,
        CbL1          TINYINT   NOT NULL DEFAULT 0,
        CbL2          TINYINT   NOT NULL DEFAULT 0,
        CbSource      TINYINT   NOT NULL DEFAULT 0,
        CbParcours    TINYINT   NOT NULL DEFAULT 0,
        CbSensLettres TINYINT   NOT NULL DEFAULT 0,
        CbSensCibles  TINYINT   NOT NULL DEFAULT 0,
        CbDepuis      SMALLINT  NOT NULL DEFAULT 1,
        CbBrassage    TINYINT   NOT NULL DEFAULT 0,
        CbRebalance   TINYINT   NOT NULL DEFAULT 0,
        CbInclureNP   TINYINT   NOT NULL DEFAULT 0,
        CbUpdated     DATETIME  NOT NULL,
        KEY k_tour (CbTournament, CbSession)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration depuis la version 2 : rééquilibrage de la dernière cible d'un bloc.
    rep_colonne('REP_Blocs', 'CbRebalance', "TINYINT NOT NULL DEFAULT 0 AFTER CbBrassage");

    // Migration v5 : les blocs sont désormais rattachés à une ÉPREUVE (Events.EvCode)
    // et non plus à une Division+Classe. On renseigne CbEvent des blocs existants
    // depuis Individuals (l'épreuve qui contient cette Division+Classe).
    rep_colonne('REP_Blocs', 'CbEvent', "VARCHAR(10) NOT NULL DEFAULT '' AFTER CbSession");
    // Migration v6 : « inclure les archers sans épreuve individuelle » par bloc.
    rep_colonne('REP_Blocs', 'CbInclureNP', "TINYINT NOT NULL DEFAULT 0 AFTER CbRebalance");
    safe_w_sql("UPDATE REP_Blocs b
        JOIN Individuals i ON i.IndTournament = b.CbTournament
        JOIN Entries e ON e.EnId = i.IndId AND e.EnTournament = i.IndTournament
             AND e.EnDivision = b.CbDivision COLLATE utf8mb4_unicode_ci
             AND e.EnClass = b.CbClass COLLATE utf8mb4_unicode_ci
        SET b.CbEvent = i.IndEvent
        WHERE b.CbEvent = ''");

    // Saison et discipline retenues pour une compétition (sert au rapprochement).
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_Config (
        RcTournament INT        NOT NULL PRIMARY KEY,
        RcAnnee      SMALLINT   NOT NULL DEFAULT 0,
        RcDiscipline VARCHAR(2) NOT NULL DEFAULT '',
        RcUpdated    DATETIME   NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ordre manuel des clubs, par compétition et par ÉPREUVE (source « Par ordre de
    // club manuel »). OoClub = numéro de club (Countries.CoCode), OoEvent = Events.EvCode.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_OrdreClub (
        OoTournament INT         NOT NULL,
        OoEvent      VARCHAR(10) NOT NULL DEFAULT '',
        OoClub       VARCHAR(12) NOT NULL DEFAULT '',
        OoPos        SMALLINT    NOT NULL DEFAULT 0,
        PRIMARY KEY (OoTournament, OoEvent, OoClub)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migration v5 : passage de (Division,Classe) à Événement. Les anciennes lignes
    // (clé Division+Classe) ne sont plus interprétables → on repart à vide.
    rep_colonne('REP_OrdreClub', 'OoEvent', "VARCHAR(10) NOT NULL DEFAULT '' AFTER OoTournament");
    safe_w_sql("DELETE FROM REP_OrdreClub WHERE OoEvent = ''");

    // Trace de chaque écriture dans Qualifications.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_Journal (
        CjId         INT AUTO_INCREMENT PRIMARY KEY,
        CjTournament INT         NOT NULL,
        CjSession    TINYINT     NOT NULL DEFAULT 0,
        CjQuand      DATETIME    NOT NULL,
        CjQui        VARCHAR(64) NOT NULL DEFAULT '',
        CjAttendu    SMALLINT    NOT NULL DEFAULT 0,
        CjLignes     SMALLINT    NOT NULL DEFAULT 0,
        CjDetail     MEDIUMTEXT,
        KEY k_tour (CjTournament, CjSession)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration v7 — import des arrêtés.
    // État de travail de l'assistant : un JSON par compétition (fichiers importés,
    // lignes consolidées, résolutions de conflit). Écrasé à chaque étape, jamais
    // rejoué en SQL : la souplesse d'un document JSON convient mieux qu'un schéma
    // figé pour des colonnes de fichiers Exalto qui varient d'un arrêté à l'autre.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_ImpEtat (
        IeTournament INT      NOT NULL PRIMARY KEY,
        IeDonnees    LONGTEXT,
        IeUpdated    DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Classement dérivé d'un arrêté (individuel ou équipe) : propre à LA compétition
    // (contrairement à REP_Classements, réutilisable entre compétitions d'un même
    // set/saison — un arrêté ne vaut que pour la compétition qu'il a servi à créer).
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_ArrClassements (
        AcId         INT AUTO_INCREMENT PRIMARY KEY,
        AcTournament INT          NOT NULL,
        AcType       VARCHAR(1)   NOT NULL DEFAULT 'I',
        AcLibelle    VARCHAR(120) NOT NULL DEFAULT '',
        AcMaj        DATETIME     NOT NULL,
        KEY k_tour (AcTournament, AcType)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migration v8 : division et sous-type (EQ/DM), pour retrouver le classement
    // d'équipe d'une épreuve par sa division sans dépendre du libellé — sert aux
    // sources « par club selon l'arrêté équipe/double mixte » du moteur.
    rep_colonne('REP_ArrClassements', 'AcDivision',  "VARCHAR(6) NOT NULL DEFAULT '' AFTER AcType");
    rep_colonne('REP_ArrClassements', 'AcSousType',  "VARCHAR(4) NOT NULL DEFAULT '' AFTER AcDivision");
    // Migration v9 : catégorie et sexe (classements individuels), pour associer
    // AUTOMATIQUEMENT une épreuve à son classement d'arrêté par simple
    // correspondance division+catégorie+sexe — sans ça, la source « classement de
    // l'arrêté » exigeait une association manuelle par épreuve sur la page Import
    // des arrêtés avant de fonctionner, ce que rien ne signalait sur le plan des
    // départs (effectif affiché « sans classement », tri alphabétique silencieux).
    rep_colonne('REP_ArrClassements', 'AcCategorie', "VARCHAR(20) NOT NULL DEFAULT '' AFTER AcSousType");
    rep_colonne('REP_ArrClassements', 'AcSexe',      "VARCHAR(1) NOT NULL DEFAULT '' AFTER AcCategorie");
    // Migration v10 : convention de suffixe de classe (F/H ou M/W, propre au
    // fichier d'où vient ce classement — voir rep_imp_suffixe_classe()). Sans
    // elle, une compétition qui mélange TAE International (F/H) et National
    // (M/W) fusionnait par erreur les deux sous-disciplines dans UN SEUL
    // classement dès qu'elles partageaient division+catégorie+sexe (ex. « CL S1
    // F » existe dans les deux fichiers) — bug réel signalé par l'utilisateur.
    // Sert aussi à désambiguïser l'auto-correspondance épreuve → classement
    // (rep_classement_arrete()) via la discipline TI/TN mémorisée pour l'épreuve.
    rep_colonne('REP_ArrClassements', 'AcConvention', "VARCHAR(2) NOT NULL DEFAULT '' AFTER AcSexe");

    // Une ligne : un archer (ArCle=licence) pour AcType='I', un club (ArCle=code
    // club) pour AcType='E'.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_ArrRangs (
        ArClassement INT         NOT NULL,
        ArRang       SMALLINT    NOT NULL,
        ArCle        VARCHAR(12) NOT NULL DEFAULT '',
        ArNom        VARCHAR(80) NOT NULL DEFAULT '',
        ArClubCode   VARCHAR(12) NOT NULL DEFAULT '',
        ArClubNom    VARCHAR(80) NOT NULL DEFAULT '',
        PRIMARY KEY (ArClassement, ArRang),
        KEY k_cle (ArCle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Association épreuve → classement d'arrêté. Propre à la compétition (contrairement
    // à data/mapping.json, indexé par set et destiné aux classements FFTA réutilisables) :
    // prioritaire sur rep_mapping_actif() quand elle existe.
    safe_w_sql("CREATE TABLE IF NOT EXISTS REP_ArrMapping (
        AmTournament INT         NOT NULL,
        AmEvent      VARCHAR(10) NOT NULL DEFAULT '',
        AmClassement INT         NOT NULL,
        PRIMARY KEY (AmTournament, AmEvent)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration v11 : second niveau de classement par bloc — quand la source
    // principale ne classe pas un archer (absent du classement, ou classement
    // partiel comme l'arrêté qui ne couvre que les sélectionnés individuels),
    // ce second niveau prend le relais avant de retomber sur l'ordre
    // alphabétique. Défaut = 7 (REP_SRC_ALPHA) : comportement identique à
    // avant pour tous les blocs existants (repli alphabétique direct).
    rep_colonne('REP_Blocs', 'CbSource2', "TINYINT NOT NULL DEFAULT 7 AFTER CbSource");

    // Migration v12 : la colonne CbParcours (3 valeurs : 0 par cible, 1 par
    // lettre, 2 serpentin) devient une priorité cible/lettre BOOLÉENNE (1 =
    // cible en premier, 0 = lettre en premier) — le serpentin devient un
    // réglage INDÉPENDANT (CbSerpentin), combinable avec n'importe quelle
    // priorité (demandé par l'utilisateur : la priorité se règle en déplaçant
    // les sous-blocs Cibles/Lettres l'un au-dessus de l'autre dans la colonne
    // « Répartition », le serpentin devient une case dans « Options »).
    // Le remappage de CbParcours (0↔1, 2→0) NE DOIT S'EXÉCUTER QU'UNE SEULE
    // FOIS pour toute l'installation : rep_schema() tourne à chaque nouvelle
    // session, pas seulement à la création de la colonne — le rejouer sur des
    // données déjà migrées inverserait à tort leur priorité. Gaté sur le
    // retour de rep_colonne() (true seulement si CbSerpentin vient d'être
    // créée, donc seulement la toute première fois).
    $colonneSerpentinAjoutee = rep_colonne('REP_Blocs', 'CbSerpentin', "TINYINT NOT NULL DEFAULT 0 AFTER CbParcours");
    if ($colonneSerpentinAjoutee) {
        safe_w_sql("UPDATE REP_Blocs SET CbSerpentin=1 WHERE CbParcours=2");
        safe_w_sql("UPDATE REP_Blocs SET CbParcours=(CASE CbParcours WHEN 0 THEN 1 WHEN 1 THEN 0 WHEN 2 THEN 0 END)");
    }

    // Migration v13 : valeurs par défaut de bloc, propres à la compétition
    // (préremplissent les nouveaux blocs, et peuvent être appliquées d'un coup
    // à tous les blocs existants — demandé par l'utilisateur pour ne pas avoir
    // à régler « hors épr. », sens/priorité, source(+puis) et options bloc par
    // bloc quand ils partagent tous les mêmes réglages). JSON plutôt que des
    // colonnes séparées : ensemble de réglages qui suit celui des blocs
    // eux-mêmes, plus simple à faire évoluer sans nouvelle migration à chaque
    // champ (voir rep_bloc_defaut_lire()/rep_bloc_defaut_ecrire(), lib/mapping.php).
    rep_colonne('REP_Config', 'RcBlocDefaut', "TEXT NULL AFTER RcSet");

    // Migration v14 (réécrite par la v15 ci-dessous, voir son commentaire) : a
    // brièvement ajouté une colonne CrMeilleur, remplacée avant toute publication
    // par CrS1/CrS2/CrS3 — ne rien ajouter ici, la v15 s'en charge (y compris pour
    // une install qui n'aurait jamais eu CrMeilleur).

    // Migration v15 : les 3 scores comptant pour le classement (S1/S2/S3 de
    // l'extranet FFTA, triés décroissant — S3 vaut 0 pour les disciplines qui
    // n'en comptent que 2, ex. Para extérieur), pas seulement le meilleur —
    // demandé par l'utilisateur, les 3 seront utiles à un autre module. Si
    // CrMeilleur existe déjà (installation qui a tourné avec l'éphémère v14
    // ci-dessus), elle est renommée en CrS1 plutôt que dupliquée — même valeur,
    // nom aligné sur la terminologie FFTA (voir rep_ffta_classement(), lib/ffta.php).
    // Rename fait une seule fois (gaté sur la présence de CrMeilleur, jamais
    // recréée depuis) pour rester idempotent d'une session à l'autre.
    $rsRen = safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'REP_Rangs' AND COLUMN_NAME = 'CrMeilleur'");
    $rRen  = $rsRen ? safe_fetch($rsRen) : null;
    if ($rRen && intval($rRen->n) > 0) {
        safe_w_sql("ALTER TABLE REP_Rangs CHANGE COLUMN CrMeilleur CrS1 SMALLINT NOT NULL DEFAULT 0");
    }
    rep_colonne('REP_Rangs', 'CrS1', "SMALLINT NOT NULL DEFAULT 0 AFTER CrMoyenne");   // install neuve : jamais eu CrMeilleur
    rep_colonne('REP_Rangs', 'CrS2', "SMALLINT NOT NULL DEFAULT 0 AFTER CrS1");
    rep_colonne('REP_Rangs', 'CrS3', "SMALLINT NOT NULL DEFAULT 0 AFTER CrS2");

    // Migration v16 : préinscription au Championnat de France (icône « Pré-inscrit »
    // du classement — sans texte, seule la cellule HTML brute la révèle, voir
    // rep_cellules_brutes()/rep_ffta_classement()). CrQuota (déjà en base depuis la
    // toute première version, colonne « Quota » = la place de qualification demandée
    // par l'utilisateur) couvrait déjà la moitié du besoin ; seule la préinscription
    // manquait.
    rep_colonne('REP_Rangs', 'CrPreinscrit', "TINYINT NOT NULL DEFAULT 0 AFTER CrQuota");

    $_SESSION[$flag] = true;
}
