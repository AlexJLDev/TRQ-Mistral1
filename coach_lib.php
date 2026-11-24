<?php
/**
 * coach_lib.php — Socle du module coach (relation coach ↔ athlète).
 *
 * Principe :
 * - Une table de liaison dans auth.sqlite (coach_links) : c'est une *relation*,
 *   pas une donnée d'entraînement.
 * - Un code d'invitation à 6 caractères généré par l'ATHLÈTE (24 h, un seul
 *   actif, stocké haché) ; le coach l'entre → demande en attente ; l'athlète
 *   accepte ou refuse ; révocation possible à tout moment par l'athlète.
 * - Une fois le lien actif, le partage est COMPLET (séances, forme,
 *   récupération, planification) — décision produit assumée.
 * - coach_snapshots : pré-calcul léger (CTL/ATL/TSB, TSS 7/28 j, dernière
 *   activité, niveau d'alerte) pour les vues d'ensemble du coach — une seule
 *   requête SQL quel que soit le nombre d'athlètes.
 * - coach_open_db() : ouverture PDO à la volée de la base de l'athlète pour
 *   les fiches détaillées (données toujours fraîches, ATTACH inutile).
 *
 * Ce fichier n'affiche rien : fonctions pures, prêtes pour les PR B/C/D/E.
 */

/* ---------- schéma (auth.sqlite) ---------- */

function coach_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    auth_db()->exec("
    CREATE TABLE IF NOT EXISTS coach_links(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        coach_id INTEGER NOT NULL,
        athlete_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',   /* pending | active | revoked */
        created_at INTEGER, activated_at INTEGER, revoked_at INTEGER,
        UNIQUE(coach_id, athlete_id));
    CREATE INDEX IF NOT EXISTS idx_cl_athlete ON coach_links(athlete_id, status);
    CREATE INDEX IF NOT EXISTS idx_cl_coach ON coach_links(coach_id, status);
    CREATE TABLE IF NOT EXISTS coach_invites(
        athlete_id INTEGER PRIMARY KEY,
        code_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        created_at INTEGER);
    CREATE TABLE IF NOT EXISTS coach_snapshots(
        athlete_id INTEGER PRIMARY KEY,
        ctl REAL, atl REAL, tsb REAL,
        tss_7j INTEGER, tss_28j INTEGER,
        last_activity TEXT, last_sync TEXT,
        alert_level INTEGER NOT NULL DEFAULT 0,   /* 0 sain | 1 attention | 2 surmenage | 3 inactif */
        computed_at INTEGER);");
}

/* ---------- rôles ---------- */

function coach_enabled(): bool {
    return auth_enabled();
}

/** Vrai si le compte a le rôle coach (admin = coach de fait pour les futures vues). */
function coach_is_coach(?int $uid = null): bool {
    $u = $uid !== null
        ? auth_db()->query('SELECT role FROM users WHERE id = ' . (int)$uid)->fetch()
        : current_user();
    return (bool)$u && in_array((string)$u['role'], ['coach', 'admin'], true);
}

/** Bascule le rôle du compte entre 'user' et 'coach' (admin intouchable). Retourne le nouveau rôle. */
function coach_role_toggle(int $uid): string {
    $pdo = auth_db();
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$uid]);
    $role = (string)($st->fetchColumn() ?: 'user');
    if ($role === 'admin') return 'admin';
    $new = $role === 'coach' ? 'user' : 'coach';
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$new, $uid]);
    return $new;
}

/* ---------- code d'invitation (généré par l'athlète) ---------- */

/** Crée (ou remplace) le code d'invitation de l'athlète. Retourne le code en clair (jamais stocké tel quel). */
function coach_invite_create(int $athleteId): array {
    $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans I, O, 1, 0 (ambigus à la lecture)
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $pool[random_int(0, strlen($pool) - 1)];
    $exp = time() + 86400;
    auth_db()->prepare(
        'INSERT INTO coach_invites(athlete_id, code_hash, expires_at, created_at) VALUES (?,?,?,?)
         ON CONFLICT(athlete_id) DO UPDATE SET code_hash = excluded.code_hash, expires_at = excluded.expires_at, created_at = excluded.created_at'
    )->execute([$athleteId, hash('sha256', $code), $exp, time()]);
    return ['code' => $code, 'expires' => $exp];
}

/** Invite en cours d'un athlète : ['expires_at' => int] ou null. Ne restitue jamais le code en clair. */
function coach_invite_get(int $athleteId): ?array {
    $st = auth_db()->prepare('SELECT expires_at FROM coach_invites WHERE athlete_id = ? AND expires_at > ?');
    $st->execute([$athleteId, time()]);
    $r = $st->fetch();
    return $r ? ['expires_at' => (int)$r['expires_at']] : null;
}

function coach_invite_revoke(int $athleteId): void {
    auth_db()->prepare('DELETE FROM coach_invites WHERE athlete_id = ?')->execute([$athleteId]);
}

/** Résout un code saisi par un coach. Retourne ['athlete_id', 'athlete_name'] ou null. */
function coach_invite_lookup(string $code): ?array {
    $code = strtoupper(trim($code));
    if (preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{6}$/', $code) !== 1) return null;
    $st = auth_db()->prepare(
        'SELECT i.athlete_id, i.expires_at, u.name FROM coach_invites i
         JOIN users u ON u.id = i.athlete_id AND u.active = 1
         WHERE i.code_hash = ?');
    $st->execute([hash('sha256', $code)]);
    $r = $st->fetch();
    if (!$r || (int)$r['expires_at'] < time()) return null;
    return ['athlete_id' => (int)$r['athlete_id'], 'athlete_name' => (string)$r['name']];
}

/* ---------- liens coach ↔ athlète ---------- */

/** Le coach entre le code de l'athlète → demande en attente. Retourne ['ok', 'msg']. */
function coach_request_via_code(int $coachId, string $code): array {
    $inv = coach_invite_lookup($code);
    if (!$inv) return ['ok' => false, 'msg' => 'Code invalide ou expiré.'];
    if ($inv['athlete_id'] === $coachId) return ['ok' => false, 'msg' => 'C’est ton propre code.'];
    $ok = coach_request($coachId, $inv['athlete_id']);
    return ['ok' => $ok, 'msg' => $ok
        ? 'Demande envoyée à ' . $inv['athlete_name'] . ' — elle sera effective dès qu’il l’accepte.'
        : 'Une demande est déjà en attente pour cet athlète.'];
}

/** Crée (ou réactive) une demande pending d'un coach vers un athlète. */
function coach_request(int $coachId, int $athleteId): bool {
    $pdo = auth_db();
    $st = $pdo->prepare('SELECT id, status FROM coach_links WHERE coach_id = ? AND athlete_id = ?');
    $st->execute([$coachId, $athleteId]);
    $cur = $st->fetch();
    if ($cur && in_array($cur['status'], ['pending', 'active'], true)) return false; // déjà traitée
    if ($cur) {
        $pdo->prepare('UPDATE coach_links SET status = \'pending\', created_at = ?, activated_at = NULL, revoked_at = NULL WHERE id = ?')
            ->execute([time(), $cur['id']]);
    } else {
        $pdo->prepare('INSERT INTO coach_links(coach_id, athlete_id, status, created_at) VALUES (?,?, \'pending\', ?)')
            ->execute([$coachId, $athleteId, time()]);
    }
    coach_invite_revoke($athleteId); // le code a servi : il meurt (usage unique)
    return true;
}

/** L'athlète accepte une demande. Rafraîchit le snapshot. */
function coach_accept(int $athleteId, int $linkId): bool {
    $st = auth_db()->prepare(
        'UPDATE coach_links SET status = \'active\', activated_at = ? WHERE id = ? AND athlete_id = ? AND status = \'pending\'');
    $st->execute([time(), $linkId, $athleteId]);
    if ($st->rowCount() < 1) return false;
    coach_snapshot_refresh($athleteId);
    return true;
}

/** L'athlète refuse une demande (la ligne disparaît). */
function coach_reject(int $athleteId, int $linkId): bool {
    $st = auth_db()->prepare('DELETE FROM coach_links WHERE id = ? AND athlete_id = ? AND status = \'pending\'');
    $st->execute([$linkId, $athleteId]);
    return $st->rowCount() > 0;
}

/** Révocation — par l'athlète (coachId) ou par le coach lui-même (athleteId). */
function coach_revoke(int $athleteId, int $coachId): bool {
    $st = auth_db()->prepare(
        'UPDATE coach_links SET status = \'revoked\', revoked_at = ? WHERE athlete_id = ? AND coach_id = ? AND status = \'active\'');
    $st->execute([time(), $athleteId, $coachId]);
    return $st->rowCount() > 0;
}

/** Garde-fou ACL : lien actif entre ce coach et cet athlète ? */
function coach_is_active(int $coachId, int $athleteId): bool {
    $st = auth_db()->prepare('SELECT 1 FROM coach_links WHERE coach_id = ? AND athlete_id = ? AND status = \'active\'');
    $st->execute([$coachId, $athleteId]);
    return (bool)$st->fetch();
}

/** Demandes en attente reçues par l'athlète (avec nom/email du coach). */
function coach_pending(int $athleteId): array {
    $st = auth_db()->prepare(
        'SELECT l.id, l.created_at, u.id AS coach_id, u.name, u.email FROM coach_links l
         JOIN users u ON u.id = l.coach_id AND u.active = 1
         WHERE l.athlete_id = ? AND l.status = \'pending\' ORDER BY l.created_at');
    $st->execute([$athleteId]);
    return $st->fetchAll();
}

/** Coachs actifs de l'athlète. */
function coach_coached_by(int $athleteId): array {
    $st = auth_db()->prepare(
        'SELECT l.activated_at, u.id AS coach_id, u.name, u.email, u.avatar_ts FROM coach_links l
         JOIN users u ON u.id = l.coach_id AND u.active = 1
         WHERE l.athlete_id = ? AND l.status = \'active\' ORDER BY l.activated_at');
    $st->execute([$athleteId]);
    return $st->fetchAll();
}

/** Athlètes actifs du coach (fiche de liaison). */
function coach_athletes(int $coachId): array {
    $st = auth_db()->prepare(
        'SELECT l.activated_at, u.id AS athlete_id, u.name, u.email, u.avatar_ts FROM coach_links l
         JOIN users u ON u.id = l.athlete_id AND u.active = 1
         WHERE l.coach_id = ? AND l.status = \'active\' ORDER BY u.name');
    $st->execute([$coachId]);
    return $st->fetchAll();
}

/** Athlète révoque-t-il entraîne un nettoyage de son snapshot ? Non : le snapshot reste, il n'est juste plus visible. */

/* ---------- ouverture de la base d'un athlète ---------- */

/** PDO sur la base SQLite de l'athlète (lecture seule dans l'usage). Retourne null si absente. */
function coach_open_db(int $athleteId): ?PDO {
    $path = user_db_path($athleteId);
    if (!is_file($path)) return null;
    try {
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (\Throwable $e) {
        return null;
    }
}

/* ---------- snapshots (pilotage d'ensemble du coach) ---------- */

/**
 * Recalcule et stocke le snapshot de l'athlète (même algorithme PMC que pmc() :
 * CTL 42 j, ATL 7 j). No-op si l'athlète n'a aucun coach actif ou pas de base.
 */
function coach_snapshot_refresh(int $athleteId): void {
    $pdo = auth_db();
    $st = $pdo->prepare('SELECT 1 FROM coach_links WHERE athlete_id = ? AND status = \'active\' LIMIT 1');
    $st->execute([$athleteId]);
    if (!$st->fetch()) return;

    $p = coach_open_db($athleteId);
    if (!$p) return;

    // PMC allégé (total toutes disciplines)
    $ctl = $atl = 0.0;
    $tss7 = $tss28 = 0;
    $lastAct = null;
    try {
        $rows = $p->query('SELECT substr(date,1,10) d, SUM(COALESCE(tss,0)) t FROM activities GROUP BY d ORDER BY d')->fetchAll();
    } catch (\Throwable $e) {
        $rows = [];
    }
    if ($rows) {
        $byDate = [];
        foreach ($rows as $r) { $byDate[$r['d']] = (float)$r['t']; }
        $lastAct = array_key_last($byDate);
        $w7 = date('Y-m-d', strtotime('-6 days'));
        $w28 = date('Y-m-d', strtotime('-27 days'));
        $d = new DateTime(array_key_first($byDate));
        $end = new DateTime('today');
        for (; $d <= $end; $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $t = $byDate[$k] ?? 0.0;
            $ctl += ($t - $ctl) / 42;
            $atl += ($t - $atl) / 7;
            if ($k >= $w7) $tss7 += (int)round($t);
            if ($k >= $w28) $tss28 += (int)round($t);
        }
    }
    $tsb = round($ctl - $atl, 1);

    try {
        $lastWell = $p->query('SELECT MAX(date) FROM wellness')->fetchColumn();
        $rhr = $p->query('SELECT v FROM settings WHERE k = \'last_sync\'')->fetchColumn();
    } catch (\Throwable $e) {
        $lastWell = null; $rhr = null;
    }

    // Niveau d'alerte du jour (prépare la grille du roster)
    $ratio = $ctl > 10 ? $atl / max($ctl, 1) : 0;
    $inactive = $lastAct === null || $lastAct < date('Y-m-d', strtotime('-9 days'));
    if ($inactive)      $alert = 3;
    elseif ($tsb < -30 || $ratio > 1.5) $alert = 2;
    elseif ($tsb < -10 || $ratio > 1.3) $alert = 1;
    else                $alert = 0;

    $pdo->prepare(
        'INSERT INTO coach_snapshots(athlete_id, ctl, atl, tsb, tss_7j, tss_28j, last_activity, last_sync, alert_level, computed_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON CONFLICT(athlete_id) DO UPDATE SET
            ctl = excluded.ctl, atl = excluded.atl, tsb = excluded.tsb,
            tss_7j = excluded.tss_7j, tss_28j = excluded.tss_28j,
            last_activity = excluded.last_activity, last_sync = excluded.last_sync,
            alert_level = excluded.alert_level, computed_at = excluded.computed_at'
    )->execute([
        $athleteId, round($ctl, 1), round($atl, 1), $tsb, $tss7, $tss28,
        $lastAct, $rhr !== false ? $rhr : null, $alert, time(),
    ]);
}

/**
 * Vue roster du coach : UNE seule requête quel que soit le nombre d'athlètes
 * (auth.sqlite only). Les snapshots sont rafraîchis à part (coach_snapshot_refresh).
 */
function coach_roster(int $coachId): array {
    $st = auth_db()->prepare(
        'SELECT u.id AS athlete_id, u.name, u.email, u.avatar_ts,
                s.ctl, s.atl, s.tsb, s.tss_7j, s.tss_28j, s.last_activity, s.last_sync, s.alert_level, s.computed_at
         FROM coach_links l
         JOIN users u ON u.id = l.athlete_id AND u.active = 1
         LEFT JOIN coach_snapshots s ON s.athlete_id = l.athlete_id
         WHERE l.coach_id = ? AND l.status = \'active\'
         ORDER BY COALESCE(s.alert_level, 0) DESC, u.name');
    $st->execute([$coachId]);
    return $st->fetchAll();
}
