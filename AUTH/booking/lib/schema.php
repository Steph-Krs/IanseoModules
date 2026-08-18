<?php
/**
 * lib/schema.php — création et migration des tables BK_*.
 *
 * Collation imposée : utf8mb4_unicode_ci. Les tables ianseo peuvent être en
 * utf8mb4_0900_ai_ci ou _as_ci selon le serveur ; toute jointure entre une
 * colonne VARCHAR d'ici et une colonne VARCHAR de ianseo doit porter un
 * COLLATE utf8mb4_unicode_ci côté BK_, sinon MySQL 8 renvoie l'erreur 1267.
 *
 * Règle de migration (leçon REPARTITION_EPREUVES) : un bk_colonne($table, …)
 * doit TOUJOURS être placé APRÈS le CREATE TABLE IF NOT EXISTS de $table, même
 * si la table semble « sûrement déjà là » — sinon l'ALTER échoue sur une
 * installation neuve et interrompt toute la fonction (safe_w_sql lève).
 */

if (!defined('BK_SCHEMA_VERSION')) define('BK_SCHEMA_VERSION', 16);

/** Suffixe de collation à coller derrière une colonne BK_ jointe à du ianseo. */
function bk_coll()
{
    return ' COLLATE utf8mb4_unicode_ci ';
}

/** Ajoute une colonne si elle manque (MySQL < 8.0.29 n'a pas ADD COLUMN IF NOT EXISTS). */
function bk_colonne($table, $colonne, $definition)
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
function bk_schema()
{
    $flag = '_bk_schema_v' . BK_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    // Comptes licenciés. BaPassword vide = compte SSO (sentinelle réservée au
    // futur relais monespace.ffta.fr, même convention que AUT_Users.AuPassword).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_Archers (
        BaId         INT AUTO_INCREMENT PRIMARY KEY,
        BaLicence    VARCHAR(25)  NOT NULL,
        BaPassword   VARCHAR(255) NOT NULL DEFAULT '',
        BaEmail      VARCHAR(128) NOT NULL DEFAULT '',
        BaFamilyName VARCHAR(60)  NOT NULL DEFAULT '',
        BaName       VARCHAR(30)  NOT NULL DEFAULT '',
        BaClubCode   VARCHAR(10)  NOT NULL DEFAULT '',
        BaActive     TINYINT      NOT NULL DEFAULT 1,
        BaLastLogin  DATETIME     NULL,
        BaCreated    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY BaLicenceIdx (BaLicence)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sessions à jetons : seul le HACHÉ est stocké (un dump de session PHP ne
    // donne aucun secret réutilisable). Même principe que AUT_Sessions.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_Sessions (
        BsId        INT AUTO_INCREMENT PRIMARY KEY,
        BsArcher    INT      NOT NULL,
        BsTokenHash CHAR(64) NOT NULL,
        BsCreated   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BsLastSeen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BsIP        VARCHAR(45)  NOT NULL DEFAULT '',
        BsUA        VARCHAR(160) NOT NULL DEFAULT '',
        UNIQUE KEY BsTokenIdx (BsTokenHash),
        KEY BsArcherIdx (BsArcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Journal : sert aussi d'anti-brute-force et d'anti-énumération (la
    // création de compte interroge la base licenciés — à protéger autant que
    // la connexion elle-même).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_Log (
        BlId    INT AUTO_INCREMENT PRIMARY KEY,
        BlWhen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        BlUser  VARCHAR(64) NOT NULL DEFAULT '',
        BlIP    VARCHAR(45) NOT NULL DEFAULT '',
        BlEvent VARCHAR(32) NOT NULL,
        KEY BlWhenIdx (BlWhen),
        KEY BlUserIdx (BlUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ouverture des inscriptions par compétition. La config TERRAIN (cibles,
    // départs, distances, blasons, rythme) n'est PAS dupliquée ici : elle est
    // lue dans Session / DistanceInformation / TargetFaces / TournamentDistances.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_Competitions (
        BcTournament   INT NOT NULL PRIMARY KEY,
        BcOpen         TINYINT  NOT NULL DEFAULT 0,
        BcOpenFrom     DATETIME NULL,
        BcOpenTo       DATETIME NULL,
        BcRestrictKind VARCHAR(4)  NOT NULL DEFAULT '',
        BcRestrictCode VARCHAR(16) NOT NULL DEFAULT '',
        BcRestrictTo   DATETIME NULL,
        BcMaxPerClubPerTarget TINYINT NOT NULL DEFAULT 2,
        BcMinClubsPerSession  TINYINT NOT NULL DEFAULT 3,
        BcShowAssignment TINYINT NOT NULL DEFAULT 0,
        BcShowGauges     TINYINT NOT NULL DEFAULT 1,
        BcAllowScoresheet TINYINT NOT NULL DEFAULT 0,
        BcFee          DECIMAL(6,2) NOT NULL DEFAULT 0,
        BcUpdated      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Souhaits proposés à l'archer au moment de l'inscription (configurable par
    // l'organisateur). Par défaut : seulement la position sur la cible ; le
    // « sur la même cible que » et le champ libre sont désactivés par défaut.
    bk_colonne('BK_Competitions', 'BcWishLetter', "TINYINT NOT NULL DEFAULT 1 AFTER BcAllowScoresheet");
    bk_colonne('BK_Competitions', 'BcWishWith',   "TINYINT NOT NULL DEFAULT 0 AFTER BcWishLetter");
    bk_colonne('BK_Competitions', 'BcWishFree',   "TINYINT NOT NULL DEFAULT 0 AFTER BcWishWith");

    // v4 : tarification avancée (JSON). Vide = tarif plat BcFee (comportement
    // d'origine). Structure : categories[] (prix fixe par arme/classe), departures{}
    // (Δ par départ), prov{} (Δ local dept/ligue), rank{} (Δ dégressif multi-inscriptions).
    bk_colonne('BK_Competitions', 'BcPricing', "LONGTEXT NULL AFTER BcFee");

    // v5 : boutique (buvette généralisée : souvenirs, hébergement, accès…). Date
    // limite propre à la boutique ; vide = suit l'ouverture des inscriptions.
    bk_colonne('BK_Competitions', 'BcShopUntil', "DATETIME NULL AFTER BcPricing");

    // v6 : exclure une compétition des statistiques compétiteur (compétition de
    // test / non officielle). Défaut 0 = visible dans les stats.
    bk_colonne('BK_Competitions', 'BcExcludeStats', "TINYINT NOT NULL DEFAULT 0 AFTER BcShopUntil");

    // v8 : moyens de paiement proposés par l'organisateur (JSON). Vide = aucun.
    // Chaque entrée : {m: moyen, when: before/onsite/both, info: texte}.
    bk_colonne('BK_Competitions', 'BcPayInfo', "LONGTEXT NULL AFTER BcExcludeStats");

    // v10 : validation manuelle des inscriptions. 0 = automatique (comme avant) ;
    // 1 = chaque inscription doit être validée par l'organisateur avant placement.
    bk_colonne('BK_Competitions', 'BcManualValidation', "TINYINT NOT NULL DEFAULT 0 AFTER BcPayInfo");

    // v11 : mandat de compétition (JSON) — template, couleur, logos affichés et
    // blocs de texte libres. Vide = jamais configuré (le générateur propose alors
    // ses valeurs par défaut).
    bk_colonne('BK_Competitions', 'BcMandate', "LONGTEXT NULL AFTER BcManualValidation");

    // v12 : visibilité du mandat par les archers. Tri-état (NULL = jamais choisi
    // → visible dès qu'un mandat existe ; 1 = visible ; 0 = masqué). Voir
    // bk_mandate_visible().
    bk_colonne('BK_Competitions', 'BcShowMandate', "TINYINT NULL AFTER BcMandate");

    // v13 : documents de la compétition consultables par les archers. Lien vers la
    // fiche publique ianseo.net (URL saisie par l'organisateur ; vide = pas de lien).
    bk_colonne('BK_Competitions', 'BcIanseoUrl', "VARCHAR(255) NULL AFTER BcShowMandate");

    // v14 : documents officiels ianseo proposés aux archers (opt-in par l'organisateur,
    // servis via un relais borné qui régénère le PDF officiel). 0 = masqué (défaut).
    bk_colonne('BK_Competitions', 'BcShowProgram',      "TINYINT NOT NULL DEFAULT 0 AFTER BcIanseoUrl");
    bk_colonne('BK_Competitions', 'BcShowParticipants', "TINYINT NOT NULL DEFAULT 0 AFTER BcShowProgram");
    bk_colonne('BK_Competitions', 'BcShowResults',      "TINYINT NOT NULL DEFAULT 0 AFTER BcShowParticipants");

    // v15 : niveau de publication (barre à 3 niveaux — refonte ergonomique).
    // 1 = aucune publication (privé orga) ; 2 = publication simple (tout auto) ;
    // 3 = avancé (tout réglable). Défaut 1 (rien publié sans action explicite).
    // BcAdvancedBackup : snapshot JSON des réglages avancés, conservé quand la
    // compétition est en niveau 2, restauré au retour en niveau 3 (« conserver mais
    // masquer » les réglages avancés). Les colonnes restent la config EFFECTIVE.
    bk_colonne('BK_Competitions', 'BcPublishLevel',  "TINYINT NOT NULL DEFAULT 1 AFTER BcShowResults");
    bk_colonne('BK_Competitions', 'BcAdvancedBackup', "LONGTEXT NULL AFTER BcPublishLevel");
    // Migration : les compétitions déjà OUVERTES avant la barre à 3 niveaux avaient été
    // configurées à la main → niveau 3 (avancé). Idempotent (plus aucune ligne ne
    // correspond une fois migrée : le niveau 2 met BcPublishLevel=2, le niveau 1 BcOpen=0).
    safe_w_sql("UPDATE BK_Competitions SET BcPublishLevel = 3 WHERE BcOpen = 1 AND BcPublishLevel = 1");

    // v16 : ancre stable de réimport. ianseo réimporte une compétition existante
    // (même ToCode) en SUPPRIMANT l'ancien tournoi et en créant un nouveau avec un
    // ToId DIFFÉRENT (Common/Fun_TourDelete.php) → toutes les tables BK_ (liées au
    // ToId) deviennent orphelines et les inscriptions en ligne disparaissent. On
    // mémorise donc le ToCode (stable d'une version à l'autre, comme PRONO_Config
    // .PaCfTourCode) pour reconnecter les données à la nouvelle compétition. Voir
    // lib/adopt.php. Renseigné tant que la compétition est vivante ; l'orphelin d'un
    // réimport déjà survenu AVANT v16 (Tournament d'origine supprimé) n'a pas d'ancre
    // et reste manuel — sans conséquence, la logique est prospective.
    $newCode = bk_colonne('BK_Competitions', 'BcCode', "VARCHAR(20) NOT NULL DEFAULT '' AFTER BcTournament");
    safe_w_sql("UPDATE BK_Competitions o
        INNER JOIN Tournament t ON t.ToId = o.BcTournament
        SET o.BcCode = t.ToCode
        WHERE o.BcCode = ''");
    if ($newCode) safe_w_sql("ALTER TABLE BK_Competitions ADD KEY BcCodeIdx (BcCode)");

    // Une inscription = une ligne Entries de ianseo + cette ligne de traçage
    // (qui a inscrit, quand, avec quelles demandes spéciales). Entries n'a
    // aucune notion d'auteur d'inscription.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_Registrations (
        BrId         INT AUTO_INCREMENT PRIMARY KEY,
        BrEnId       INT NOT NULL,
        BrTournament INT NOT NULL,
        BrArcher     INT NOT NULL DEFAULT 0,
        BrLicence    VARCHAR(25) NOT NULL DEFAULT '',
        BrByRole     VARCHAR(8)  NOT NULL DEFAULT 'SELF',
        BrBy         VARCHAR(64) NOT NULL DEFAULT '',
        BrRequest    TEXT NULL,
        BrCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY BrEnIdx (BrEnId),
        KEY BrTourIdx (BrTournament),
        KEY BrArcherIdx (BrArcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v3 : demandes STRUCTURÉES, exploitables par le placement automatique
    // (BrRequest reste le commentaire libre, lu par l'organisateur seulement).
    bk_colonne('BK_Registrations', 'BrWantLetter', "VARCHAR(2)  NOT NULL DEFAULT '' AFTER BrRequest");
    bk_colonne('BK_Registrations', 'BrWantWith',   "VARCHAR(25) NOT NULL DEFAULT '' AFTER BrWantLetter");

    // v10 : validation manuelle. BrValidated=1 par défaut (auto, comme avant) ;
    // en mode manuel la nouvelle inscription arrive à 0 et n'est PAS placée tant
    // que l'organisateur ne l'a pas validée.
    bk_colonne('BK_Registrations', 'BrValidated', "TINYINT NOT NULL DEFAULT 1 AFTER BrWantWith");

    // v16 : instantané de l'inscription, pour la RÉ-INJECTER dans une nouvelle version
    // de la compétition après un réimport (l'Entry d'origine est supprimée puis
    // recréée avec un autre EnId — voir lib/adopt.php). Renseigné à l'inscription
    // (bk_register) ; rétro-rempli ici depuis les Entries/Qualifications encore
    // présentes pour les inscriptions déjà en base.
    $newSnap = bk_colonne('BK_Registrations', 'BrDivision', "VARCHAR(8) NOT NULL DEFAULT '' AFTER BrValidated");
    bk_colonne('BK_Registrations', 'BrClass',   "VARCHAR(8) NOT NULL DEFAULT '' AFTER BrDivision");
    bk_colonne('BK_Registrations', 'BrSession', "SMALLINT NOT NULL DEFAULT 0 AFTER BrClass");
    bk_colonne('BK_Registrations', 'BrFace',    "INT NOT NULL DEFAULT 0 AFTER BrSession");
    if ($newSnap) {
        safe_w_sql("UPDATE BK_Registrations r
            INNER JOIN Entries e ON e.EnId = r.BrEnId
            INNER JOIN Qualifications q ON q.QuId = e.EnId
            SET r.BrDivision = e.EnDivision,
                r.BrClass    = e.EnClass,
                r.BrFace     = e.EnTargetFace,
                r.BrSession  = q.QuSession
            WHERE r.BrDivision = ''");
    }

    // Possibilités techniques du terrain, cible par cible et départ par départ.
    // Une cible SANS ligne ici n'est pas contrainte (tout est permis) : c'est le
    // comportement par défaut, qui laisse une compétition non configurée se
    // comporter exactement comme avant l'existence de cette table.
    // BtFaces : liste de TargetFaces.TfId séparés par des virgules. Vide = tout.
    // Distances : une PLAGE par cible (min..max) plus une distance par défaut,
    // en mètres — une cible se déplace entre deux bornes physiques sur le
    // terrain. 0 = non renseigné, donc aucune contrainte.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_TargetCaps (
        BtTournament INT NOT NULL,
        BtSession    SMALLINT NOT NULL,
        BtTarget     SMALLINT NOT NULL,
        BtDistances  VARCHAR(120) NOT NULL DEFAULT '',
        BtFaces      VARCHAR(120) NOT NULL DEFAULT '',
        BtUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (BtTournament, BtSession, BtTarget)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v3 : la plage remplace la liste de distances (BtDistances devient inutilisée
    // mais reste en place — la supprimer perdrait la configuration d'une
    // installation qui n'aurait pas encore rejoué la migration de données).
    bk_colonne('BK_TargetCaps', 'BtDistDef', "SMALLINT NOT NULL DEFAULT 0 AFTER BtDistances");
    bk_colonne('BK_TargetCaps', 'BtDistMin', "SMALLINT NOT NULL DEFAULT 0 AFTER BtDistDef");
    $bkNewMax = bk_colonne('BK_TargetCaps', 'BtDistMax', "SMALLINT NOT NULL DEFAULT 0 AFTER BtDistMin");
    if ($bkNewMax) {
        // Reprise des anciennes listes : la plage devient [min, max] des valeurs
        // déclarées, la valeur par défaut la plus petite. Gaté sur la création
        // de la colonne → ne s'exécute qu'une fois pour toute l'installation.
        safe_w_sql("UPDATE BK_TargetCaps SET
            BtDistMin = CAST(SUBSTRING_INDEX(BtDistances, ',', 1) AS UNSIGNED),
            BtDistMax = CAST(SUBSTRING_INDEX(BtDistances, ',', -1) AS UNSIGNED),
            BtDistDef = CAST(SUBSTRING_INDEX(BtDistances, ',', 1) AS UNSIGNED)
            WHERE BtDistances <> ''");
    }

    // Repli autonome quand AUTH est absent : qui peut inscrire pour quel club.
    // Avec AUTH, le périmètre vient de la session (AUTH_ROLE/AUTH_SCOPE).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_ClubManagers (
        BmId      INT AUTO_INCREMENT PRIMARY KEY,
        BmArcher  INT NOT NULL,
        BmClub    VARCHAR(16) NOT NULL,
        BmCreated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY BmPairIdx (BmArcher, BmClub)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v5 — Boutique. Articles regroupés en sections libres (Buvette, Souvenirs…).
    // SiOptionName vide = article simple ; sinon variantes dans BK_ShopVariants
    // (stock propre à chaque variante). Stock 0 = illimité ; SiMaxPerPerson 0 = illimité.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_ShopItems (
        SiId           INT AUTO_INCREMENT PRIMARY KEY,
        SiTournament   INT NOT NULL,
        SiSection      VARCHAR(60)  NOT NULL DEFAULT '',
        SiLabel        VARCHAR(120) NOT NULL DEFAULT '',
        SiDescription  VARCHAR(255) NOT NULL DEFAULT '',
        SiPrice        DECIMAL(7,2) NOT NULL DEFAULT 0,
        SiStock        INT NOT NULL DEFAULT 0,
        SiMaxPerPerson INT NOT NULL DEFAULT 0,
        SiOptionName   VARCHAR(40)  NOT NULL DEFAULT '',
        SiOrder        SMALLINT NOT NULL DEFAULT 0,
        SiActive       TINYINT  NOT NULL DEFAULT 1,
        SiUpdated      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY SiTourIdx (SiTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Variantes d'un article (taille, menu…), chacune avec son stock. SvStock 0 = illimité.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_ShopVariants (
        SvId    INT AUTO_INCREMENT PRIMARY KEY,
        SvItem  INT NOT NULL,
        SvLabel VARCHAR(80) NOT NULL DEFAULT '',
        SvStock INT NOT NULL DEFAULT 0,
        SvOrder SMALLINT NOT NULL DEFAULT 0,
        KEY SvItemIdx (SvItem)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Commandes de la boutique : quantité par (compétition, licence, article, variante).
    // SoVariant = 0 pour un article sans variante. Éditables tant que la boutique est ouverte.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_ShopOrders (
        SoId         INT AUTO_INCREMENT PRIMARY KEY,
        SoTournament INT NOT NULL,
        SoLicence    VARCHAR(25) NOT NULL DEFAULT '',
        SoItem       INT NOT NULL,
        SoVariant    INT NOT NULL DEFAULT 0,
        SoQty        INT NOT NULL DEFAULT 0,
        SoUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY SoUnique (SoTournament, SoLicence, SoItem, SoVariant),
        KEY SoTourIdx (SoTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v7 — Suivi de paiement (organisateur) : une ligne par (compétition, licence).
    // Tant que PyPaid=0, le reçu n'est pas disponible côté compétiteur ; le montant
    // dû reste affiché comme non encaissé. Pas de facture (éléments légaux absents).
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_Payments (
        PyId         INT AUTO_INCREMENT PRIMARY KEY,
        PyTournament INT NOT NULL,
        PyLicence    VARCHAR(25) NOT NULL DEFAULT '',
        PyPaid       TINYINT NOT NULL DEFAULT 0,
        PyMethod     VARCHAR(16) NOT NULL DEFAULT '',
        PyPaidAt     DATETIME NULL,
        PyBy         VARCHAR(64) NOT NULL DEFAULT '',
        PyUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY PyUnique (PyTournament, PyLicence),
        KEY PyTourIdx (PyTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v9 : déclaration du compétiteur à l'inscription — moyen souhaité + quand
    // (before/onsite). Informe l'organisateur ; distinct du PyMethod qu'il valide.
    bk_colonne('BK_Payments', 'PyDeclMethod', "VARCHAR(16) NOT NULL DEFAULT '' AFTER PyMethod");
    bk_colonne('BK_Payments', 'PyDeclWhen', "VARCHAR(8) NOT NULL DEFAULT '' AFTER PyDeclMethod");

    // v16 : incohérences détectées lors d'un réimport de compétition, à trancher par
    // l'organisateur sur une page dédiée (lib/adopt.php les enregistre, admin/reimport.php
    // les résout). RcKind :
    //   'category'  même licence + même départ, mais catégorie (arme/classe) DIFFÉRENTE
    //               entre l'inscription booking et l'import → on garde l'import par défaut,
    //               l'orga confirme ;
    //   'reinject'  inscription booking absente de l'import, ré-injection impossible
    //               (licence inconnue du fichier fédéral, départ disparu…) → à traiter ;
    //   'imported'  participant présent dans l'import mais inconnu de booking (saisi hors
    //               module) → rendu visible dans son espace, SANS info de paiement.
    // RcBooking / RcImport : instantané JSON de chaque version pour l'affichage.
    safe_w_sql("CREATE TABLE IF NOT EXISTS BK_ReimportConflicts (
        RcId         INT AUTO_INCREMENT PRIMARY KEY,
        RcTournament INT NOT NULL,
        RcCode       VARCHAR(20) NOT NULL DEFAULT '',
        RcLicence    VARCHAR(25) NOT NULL DEFAULT '',
        RcName       VARCHAR(120) NOT NULL DEFAULT '',
        RcKind       VARCHAR(16) NOT NULL DEFAULT '',
        RcEnId       INT NOT NULL DEFAULT 0,
        RcBooking    LONGTEXT NULL,
        RcImport     LONGTEXT NULL,
        RcResolved   TINYINT NOT NULL DEFAULT 0,
        RcCreated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY RcTourIdx (RcTournament),
        KEY RcOpenIdx (RcTournament, RcResolved)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION[$flag] = true;
}
