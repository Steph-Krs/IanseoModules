<?php
/**
 * Groupes de joueurs : classements parallèles (compétition + saison), à l'intérieur
 * d'un cercle informel (club, famille, groupe d'amis) plutôt que tout le plateau.
 *
 * Rejoindre un groupe se fait par nom + mot de passe, sans jamais lister les groupes
 * existants — même principe anti-énumération que le login joueur (public/api.php,
 * case 'login') : message générique + pause fixe, que le nom existe ou non, que le
 * mot de passe soit bon ou pas.
 *
 * Erreurs métier (nom pris, mauvais mot de passe, pas le propriétaire...) signalées
 * par RuntimeException, message déjà présentable au joueur : à charge de l'appelant
 * (public/api.php) de l'attraper et de la transmettre à prono_fail(), comme pour
 * PDOException ailleurs dans ce module.
 */
require_once __DIR__ . '/db.php';

const PRONO_GROUP_NAME_MIN = 3;
const PRONO_GROUP_NAME_MAX = 40;
const PRONO_GROUP_MIN_PASS = 4;   // même seuil que PRONO_MIN_PASS (public/api.php), redéfini ici pour ne pas dépendre de son ordre de chargement

function prono_group_validate_name(string $name): string
{
    $name = trim($name);
    $len  = mb_strlen($name);
    if ($len < PRONO_GROUP_NAME_MIN || $len > PRONO_GROUP_NAME_MAX) {
        throw new RuntimeException(
            'Nom de groupe : ' . PRONO_GROUP_NAME_MIN . ' à ' . PRONO_GROUP_NAME_MAX . ' caractères.');
    }
    return $name;
}

/**
 * Crée un groupe et y inscrit son créateur, seul membre initial et propriétaire.
 * Le nom PEUT être révélé comme déjà pris : c'est la création qui échoue, pas une
 * tentative de deviner un groupe existant (à la différence de prono_group_join()).
 */
function prono_group_create(int $uid, string $name, string $pass): array
{
    $name = prono_group_validate_name($name);
    if (mb_strlen($pass) < PRONO_GROUP_MIN_PASS) {
        throw new RuntimeException('Mot de passe du groupe : ' . PRONO_GROUP_MIN_PASS . ' caractères minimum.');
    }

    $db = prono_db();
    $db->beginTransaction();
    try {
        prono_q('INSERT INTO PRONO_Groups (PgName, PgPass, PgOwner, PgCreated) VALUES (?, ?, ?, NOW())',
            [$name, password_hash($pass, PASSWORD_DEFAULT), $uid]);
        $gid = (int) $db->lastInsertId();
        prono_q('INSERT INTO PRONO_GroupMembers (PgmGroup, PgmUser, PgmJoined) VALUES (?, ?, NOW())', [$gid, $uid]);
        $db->commit();
        return ['id' => $gid, 'name' => $name];
    } catch (PDOException $e) {
        $db->rollBack();
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new RuntimeException('Ce nom de groupe est déjà pris.');
        }
        throw $e;
    }
}

/**
 * Rejoint un groupe existant par nom + mot de passe. Message générique + pause fixe
 * sur tout échec (nom inconnu OU mot de passe faux) : ne jamais laisser deviner quels
 * groupes existent en tâtonnant les noms. Déjà membre → no-op silencieux (formulaire
 * soumis deux fois par erreur, pas une faute).
 */
function prono_group_join(int $uid, string $name, string $pass): array
{
    $name = trim($name);
    $g = prono_one('SELECT PgId, PgName, PgPass FROM PRONO_Groups WHERE PgName = ?', [$name]);

    if (!$g || !password_verify($pass, $g['PgPass'])) {
        usleep(400000);
        throw new RuntimeException('Nom ou mot de passe de groupe incorrect.');
    }

    prono_q('INSERT IGNORE INTO PRONO_GroupMembers (PgmGroup, PgmUser, PgmJoined) VALUES (?, ?, NOW())',
        [(int) $g['PgId'], $uid]);
    return ['id' => (int) $g['PgId'], 'name' => (string) $g['PgName']];
}

/** Quitte un groupe ; en transfère la propriété si on en était le propriétaire. */
function prono_group_leave(int $uid, int $gid): void
{
    $wasOwner = (bool) prono_val('SELECT 1 FROM PRONO_Groups WHERE PgId = ? AND PgOwner = ?', [$gid, $uid]);
    prono_q('DELETE FROM PRONO_GroupMembers WHERE PgmGroup = ? AND PgmUser = ?', [$gid, $uid]);
    if ($wasOwner) prono_group_reassign_owner($gid, $uid);
}

/** Supprime un groupe — réservé à son propriétaire, ou à un administrateur. */
function prono_group_delete(int $gid, int $uid, bool $isAdmin): void
{
    $g = prono_one('SELECT PgOwner FROM PRONO_Groups WHERE PgId = ?', [$gid]);
    if (!$g) return;
    if (!$isAdmin && (int) $g['PgOwner'] !== $uid) {
        throw new RuntimeException('Seul le créateur du groupe (ou un administrateur) peut le supprimer.');
    }

    $db = prono_db();
    $db->beginTransaction();
    try {
        prono_q('DELETE FROM PRONO_GroupMembers WHERE PgmGroup = ?', [$gid]);
        prono_q('DELETE FROM PRONO_Groups WHERE PgId = ?', [$gid]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Transfère la propriété d'un groupe au membre restant ayant le plus de points de
 * SAISON (même somme que prono_group_season(), sans la limite d'affichage) ; sans
 * membre restant, le groupe n'a plus de raison d'être et est supprimé. Appelée une
 * fois la ligne d'appartenance du partant déjà retirée (prono_group_leave(),
 * prono_group_user_removed()) : "les membres restants" l'exclut donc déjà.
 */
function prono_group_reassign_owner(int $gid, int $leavingUid): void
{
    $next = prono_one(
        "SELECT gm.PgmUser AS uid,
                IFNULL((SELECT SUM(s.PaScPoints) FROM PRONO_Scores s
                        INNER JOIN PRONO_Config c ON c.PaCfTournament = s.PaScTournament AND c.PaCfSeason = 1
                        WHERE s.PaScUser = gm.PgmUser), 0) AS pts
         FROM PRONO_GroupMembers gm
         WHERE gm.PgmGroup = ?
         ORDER BY pts DESC, gm.PgmUser ASC
         LIMIT 1", [$gid]);

    if ($next) {
        prono_q('UPDATE PRONO_Groups SET PgOwner = ? WHERE PgId = ?', [(int) $next['uid'], $gid]);
    } else {
        prono_q('DELETE FROM PRONO_Groups WHERE PgId = ?', [$gid]);
    }
}

/**
 * Nettoyage des groupes d'un compte qu'on s'apprête à supprimer (prono_delete_user()) :
 * un compte supprimé « quitte » tous ses groupes, avec le même transfert de propriété
 * que quitter volontairement.
 */
function prono_group_user_removed(int $uid): void
{
    $owned = array_column(prono_all('SELECT PgId FROM PRONO_Groups WHERE PgOwner = ?', [$uid]), 'PgId');
    foreach ($owned as $gid) {
        prono_q('DELETE FROM PRONO_GroupMembers WHERE PgmGroup = ? AND PgmUser = ?', [(int) $gid, $uid]);
        prono_group_reassign_owner((int) $gid, $uid);
    }
    // Groupes rejoints sans les posséder : ce qui reste après le nettoyage ci-dessus.
    prono_q('DELETE FROM PRONO_GroupMembers WHERE PgmUser = ?', [$uid]);
}

/** Groupes d'un joueur : id, nom, propriétaire ou non, nombre de membres. */
function prono_user_groups(int $uid): array
{
    return prono_all(
        "SELECT g.PgId AS id, g.PgName AS name, (g.PgOwner = ?) AS isOwner,
                (SELECT COUNT(*) FROM PRONO_GroupMembers m2 WHERE m2.PgmGroup = g.PgId) AS members
         FROM PRONO_Groups g
         INNER JOIN PRONO_GroupMembers gm ON gm.PgmGroup = g.PgId AND gm.PgmUser = ?
         ORDER BY g.PgName", [$uid, $uid]);
}

/** Tous les groupes du serveur — page d'administration uniquement. */
function prono_all_groups(): array
{
    return prono_all(
        "SELECT g.PgId AS id, g.PgName AS name, g.PgCreated AS created, u.PaUsNick AS owner,
                (SELECT COUNT(*) FROM PRONO_GroupMembers m WHERE m.PgmGroup = g.PgId) AS members
         FROM PRONO_Groups g
         LEFT JOIN PRONO_Users u ON u.PaUsId = g.PgOwner
         ORDER BY g.PgName");
}

/** Classement du groupe pour la compétition en cours — même forme que le classement général. */
function prono_group_board(int $gid, int $tid): array
{
    return prono_all(
        'SELECT u.PaUsNick, s.PaScPoints AS PaUsPoints, s.PaScBets AS PaUsBets, s.PaScWon AS PaUsWon
         FROM PRONO_Scores s
         INNER JOIN PRONO_Users u ON u.PaUsId = s.PaScUser
         INNER JOIN PRONO_GroupMembers gm ON gm.PgmUser = u.PaUsId AND gm.PgmGroup = ?
         WHERE s.PaScTournament = ?
         ORDER BY s.PaScPoints DESC, s.PaScWon DESC, s.PaScBets ASC LIMIT 30', [$gid, $tid]);
}

/** Classement du groupe pour la saison — même forme que le classement général. */
function prono_group_season(int $gid): array
{
    return prono_all(
        'SELECT u.PaUsNick, SUM(s.PaScPoints) AS PaUsPoints, SUM(s.PaScBets) AS PaUsBets,
                SUM(s.PaScWon) AS PaUsWon, COUNT(*) AS PaUsEvents
         FROM PRONO_Scores s
         INNER JOIN PRONO_Users u  ON u.PaUsId = s.PaScUser
         INNER JOIN PRONO_Config c ON c.PaCfTournament = s.PaScTournament AND c.PaCfSeason = 1
         INNER JOIN PRONO_GroupMembers gm ON gm.PgmUser = u.PaUsId AND gm.PgmGroup = ?
         GROUP BY u.PaUsId, u.PaUsNick
         ORDER BY SUM(s.PaScPoints) DESC, SUM(s.PaScWon) DESC LIMIT 30', [$gid]);
}

/**
 * Un joueur peut-il voir les pronostics d'un autre ? Toujours vrai pour soi-même.
 * PUBLIC : tout le monde. PRIVATE : personne d'autre. GROUPS : seulement un membre
 * d'au moins un groupe commun.
 */
function prono_can_view_bets(int $viewerUid, int $targetUid): bool
{
    if ($viewerUid === $targetUid) return true;

    $privacy = (string) prono_val('SELECT PaUsPrivacy FROM PRONO_Users WHERE PaUsId = ?', [$targetUid], 'PUBLIC');
    if ($privacy === 'PUBLIC')  return true;
    if ($privacy === 'PRIVATE') return false;

    return (bool) prono_val(
        'SELECT 1 FROM PRONO_GroupMembers a
         INNER JOIN PRONO_GroupMembers b ON b.PgmGroup = a.PgmGroup AND b.PgmUser = ?
         WHERE a.PgmUser = ? LIMIT 1', [$viewerUid, $targetUid]);
}
