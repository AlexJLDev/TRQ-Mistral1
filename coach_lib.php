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
        code TEXT,
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
    try { auth_db()->exec('ALTER TABLE coach_invites ADD COLUMN code TEXT'); } catch (\Throwable $e) { } // bases créées avant le stockage du code en clair
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

/** Crée (ou remplace) le code d'invitation de l'athlète. Le code est stocké en clair pour pouvoir être réaffiché :
 *  il expiré de toute façon à 24 h et n'est utilisable qu'une fois, et l'athlète doit de surcroît ACCEPTER la demande
 *  (le code seul ne donne aucun accès). Conservation du hash pour compat avec une migration éventuelle. */
function coach_invite_create(int $athleteId): array {
    coach_schema();
    $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans I, O, 1, 0 (ambigus à la lecture)
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $pool[random_int(0, strlen($pool) - 1)];
    $exp = time() + 86400;
    auth_db()->prepare(
        'INSERT INTO coach_invites(athlete_id, code, code_hash, expires_at, created_at) VALUES (?,?,?,?,?)
         ON CONFLICT(athlete_id) DO UPDATE SET code = excluded.code, code_hash = excluded.code_hash, expires_at = excluded.expires_at, created_at = excluded.created_at'
    )->execute([$athleteId, $code, hash('sha256', $code), $exp, time()]);
    return ['code' => $code, 'expires' => $exp];
}

/** Invite en cours d'un athlète : ['code', 'expires_at'] ou null. */
function coach_invite_get(int $athleteId): ?array {
    coach_schema();
    $st = auth_db()->prepare('SELECT code, expires_at FROM coach_invites WHERE athlete_id = ? AND expires_at > ?');
    $st->execute([$athleteId, time()]);
    $r = $st->fetch();
    return $r ? ['code' => (string)$r['code'], 'expires_at' => (int)$r['expires_at']] : null;
}

function coach_invite_revoke(int $athleteId): void {
    coach_schema();
    auth_db()->prepare('DELETE FROM coach_invites WHERE athlete_id = ?')->execute([$athleteId]);
}

/** Résout un code saisi par un coach. Retourne ['athlete_id', 'athlete_name'] ou null. */
function coach_invite_lookup(string $code): ?array {
    coach_schema();
    $code = strtoupper(trim($code));
    if (preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{6}$/', $code) !== 1) return null;
    $st = auth_db()->prepare(
        'SELECT i.athlete_id, i.expires_at, u.name FROM coach_invites i
         JOIN users u ON u.id = i.athlete_id AND u.active = 1
         WHERE i.code = ? OR (i.code IS NULL AND i.code_hash = ?)');
    $st->execute([$code, hash('sha256', $code)]);
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
    coach_schema();
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
    coach_schema();
    $st = auth_db()->prepare(
        'UPDATE coach_links SET status = \'active\', activated_at = ? WHERE id = ? AND athlete_id = ? AND status = \'pending\'');
    $st->execute([time(), $linkId, $athleteId]);
    if ($st->rowCount() < 1) return false;
    coach_snapshot_refresh($athleteId);
    return true;
}

/** L'athlète refuse une demande (la ligne disparaît). */
function coach_reject(int $athleteId, int $linkId): bool {
    coach_schema();
    $st = auth_db()->prepare('DELETE FROM coach_links WHERE id = ? AND athlete_id = ? AND status = \'pending\'');
    $st->execute([$linkId, $athleteId]);
    return $st->rowCount() > 0;
}

/** Révocation — par l'athlète (coachId) ou par le coach lui-même (athleteId). */
function coach_revoke(int $athleteId, int $coachId): bool {
    coach_schema();
    $st = auth_db()->prepare(
        'UPDATE coach_links SET status = \'revoked\', revoked_at = ? WHERE athlete_id = ? AND coach_id = ? AND status = \'active\'');
    $st->execute([time(), $athleteId, $coachId]);
    return $st->rowCount() > 0;
}

/** Garde-fou ACL : lien actif entre ce coach et cet athlète ? */
function coach_is_active(int $coachId, int $athleteId): bool {
    coach_schema();
    $st = auth_db()->prepare('SELECT 1 FROM coach_links WHERE coach_id = ? AND athlete_id = ? AND status = \'active\'');
    $st->execute([$coachId, $athleteId]);
    return (bool)$st->fetch();
}

/** Demandes en attente reçues par l'athlète (avec nom/email du coach). */
function coach_pending(int $athleteId): array {
    coach_schema();
    $st = auth_db()->prepare(
        'SELECT l.id, l.created_at, u.id AS coach_id, u.name, u.email FROM coach_links l
         JOIN users u ON u.id = l.coach_id AND u.active = 1
         WHERE l.athlete_id = ? AND l.status = \'pending\' ORDER BY l.created_at');
    $st->execute([$athleteId]);
    return $st->fetchAll();
}

/** Coachs actifs de l'athlète. */
function coach_coached_by(int $athleteId): array {
    coach_schema();
    $st = auth_db()->prepare(
        'SELECT l.activated_at, u.id AS coach_id, u.name, u.email, u.avatar_ts FROM coach_links l
         JOIN users u ON u.id = l.coach_id AND u.active = 1
         WHERE l.athlete_id = ? AND l.status = \'active\' ORDER BY l.activated_at');
    $st->execute([$athleteId]);
    return $st->fetchAll();
}

/** Athlètes actifs du coach (fiche de liaison). */
function coach_athletes(int $coachId): array {
    coach_schema();
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
    coach_schema();
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
    coach_schema();
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

/* ---------- planification (le coach écrit dans planned_workouts de l'athlète) ---------- */

/** Écrit une notification directement dans la base de l'ATHLÈTE (notif_add écrit dans la base du courant : fausse ici). */
function coach_notify_athlete(int $athleteId, string $type, string $ref, string $title, string $body = '', string $link = '', string $level = 'info'): void {
    $p = coach_open_db($athleteId);
    if (!$p) return;
    try {
        $p->prepare('INSERT INTO notifications(ts, type, ref, level, title, body, link, read_at)
            VALUES (?,?,?,?,?,?,?,NULL)
            ON CONFLICT(type, ref) DO UPDATE SET
                ts = excluded.ts, level = excluded.level, title = excluded.title,
                body = excluded.body, link = excluded.link, read_at = NULL')
            ->execute([time(), $type, $ref, $level, $title, $body, $link]);
    } catch (\Throwable $e) { /* la table notifications n'existe pas encore chez cet athlète : pas grave */ }
}

/** Prochaines séances planifiées de l'athlète (30 par défaut). */
function coach_plan_list(int $athleteId, int $limit = 30): array {
    $p = coach_open_db($athleteId);
    if (!$p) return [];
    try {
        return $p->query("SELECT id, date, sport, title, duration, distance, tss, structure, notes FROM planned_workouts WHERE date >= date('now') ORDER BY date LIMIT " . max(1, min(100, $limit)))->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Ajoute une séance planifiée dans la base de l'ATHLÈTE + notif dédiée (l'athlète la voit
 * dans son calendrier existant, reçoit le rappel J-1/J existant, et cette notif) .
 * $durSec en secondes, $distM en mètres (conventions du calendrier).
 */
function coach_plan_add(int $coachId, int $athleteId, string $date, string $sport, string $title,
                        int $durSec = 0, float $distM = 0.0, ?float $tss = null, string $notes = '',
                        ?string $structureJson = null, ?string $profileJson = null, ?int $workoutId = null,
                        string $mode = 'power'): bool {
    if (!coach_is_active($coachId, $athleteId)) return false;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) return false;
    if (!in_array($sport, ['ride', 'run', 'swim', 'other'], true)) $sport = 'ride';
    /* séance structurée : structure/profile = JSON (copié depuis la bibliothèque du coach, unit='pct' → portable entre athlètes) */
    if ($structureJson !== null && json_decode($structureJson, true) === null) $structureJson = null;
    if ($profileJson !== null && json_decode($profileJson, true) === null) $profileJson = null;
    if ($structureJson !== null && strlen($structureJson) > 200000) return false;
    $tssType = ($structureJson !== null && $mode === 'hr') ? 'hrtss' : 'power';
    $icuText = $structureJson !== null ? coach_plan_text((string)$structureJson, $mode) : '';
    $p = coach_open_db($athleteId);
    if (!$p) return false;
    $cols = 'date, sport, title, duration, distance, tss, notes' . ($structureJson !== null ? ', structure, profile, workout_id, tss_type, icu_text' : '');
    $vals = [$date, $sport, $title, $durSec > 0 ? $durSec : null, $distM > 0 ? $distM : null, $tss, $notes];
    if ($structureJson !== null) $vals = array_merge($vals, [$structureJson, $profileJson, $workoutId, $tssType, $icuText]);
    $st = $p->prepare('INSERT INTO planned_workouts(' . $cols . ') VALUES (' . rtrim(str_repeat('?,', count($vals)), ',') . ')');
    $st->execute($vals);
    $when = date('d/m', strtotime($date));
    $det = [];
    if ($durSec > 0) $det[] = sprintf('%dh%02d', intdiv($durSec, 3600), intdiv($durSec % 3600, 60));
    if ($distM > 0) $det[] = sprintf('%.1f km', $distM / 1000);
    if ($tss !== null && $tss > 0) $det[] = sprintf('%.0f TSS', $tss);
    coach_notify_athlete($athleteId, 'coach_plan', 'coach-plan-' . uniqid(),
        'Ton coach a planifié une séance',
        $title . ' — ' . $when . ($det ? ' · ' . implode(' · ', $det) : ''),
        '?p=calendar', 'info');
    return true;
}

/** Supprime une séance planifiée de l'athlète (le coach doit avoir un lien actif). */
function coach_plan_del(int $coachId, int $athleteId, int $planId): bool {
    if (!coach_is_active($coachId, $athleteId) || $planId <= 0) return false;
    $p = coach_open_db($athleteId);
    if (!$p) return false;
    $st = $p->prepare('DELETE FROM planned_workouts WHERE id = ?');
    $st->execute([$planId]);
    return $st->rowCount() > 0;
}

/** Description texte de la séance (icu_text) générée côté serveur depuis la structure du builder → rend l'export intervals.icu disponible. */
function coach_plan_text(string $structureJson, string $mode): string {
    $st = json_decode($structureJson, true);
    if (!is_array($st) || !$st) return '';
    $u = $mode === 'hr' ? '% LTHR' : '% FTP';
    $dur = function (int $s): string {
        return $s % 60 === 0 ? intdiv($s, 60) . ' min' : rtrim(rtrim(number_format($s / 60, 1, '.', ' '), '0'), '.') . ' min';
    };
    $rng = function (array $s) use ($u): string {
        $lo = (float)($s['lo'] ?? 100); $hi = (float)($s['hi'] ?? $lo);
        return ($hi - $lo < 0.5 ? 'à ' . round($lo) : round($lo) . '–' . round($hi)) . ' ' . $u;
    };
    $lbl = ['warmup' => 'Échauffement', 'work' => 'Effort', 'rest' => 'Récupération', 'cooldown' => 'Retour au calme', 'steady' => 'Palier'];
    $one = function (array $s) use ($lbl, $dur, $rng): string {
        $k = (string)($s['kind'] ?? 'work');
        return ($lbl[$k] ?? ($k === 'work' ? 'Effort' : $k)) . ' ' . $dur((int)($s['dur'] ?? 60)) . ' ' . $rng($s) . (!empty($s['ramp']) ? ' (en rampe)' : '');
    };
    $out = [];
    foreach ($st as $b) {
        if (isset($b['steps']) && is_array($b['steps'])) {
            $reps = max(1, (int)($b['reps'] ?? 1));
            $out[] = $reps . ' × [' . implode(' · ', array_map(fn($c) => $one((array)$c), $b['steps'])) . ']';
        } else $out[] = $one((array)$b);
    }
    return implode("\n", $out);
}

/**
 * Transformer la structure TRQ en TEXTE NATIF intervals.icu (le format "workout text"
 * reconnu par leur API pour créer une séance planifiée structurée affichée avec le graphe) :
 * ligne "- 15m 55% Warmup", répétition "- 3x" suivie des lignes d'étapes, etc.
 * (documenté sur forum.intervals.icu t/63624 : la description doit suivre ce format). */
function coach_icu_native_text(string $structureJson, string $mode): string {
    $st = json_decode($structureJson, true);
    if (!is_array($st) || !$st) return '';
    $dur = function (int $s): string {
        if ($s < 60) return $s . 's';
        return $s % 60 === 0 ? intdiv($s, 60) . 'm' : rtrim(rtrim(number_format($s / 60, 1, '.', ' '), '0'), '.') . 'm';
    };
    $rng = function (array $s): string {
        $lo = (float)($s['lo'] ?? 100); $hi = (float)($s['hi'] ?? $lo);
        return ($hi - $lo < 0.5 ? round($lo) : round($lo) . '-' . round($hi)) . '%';
    };
    $one = function (array $s) use ($dur, $rng): string {
        $k = (string)($s['kind'] ?? 'work');
        $suffix = $k === 'warmup' ? ' Warmup' : ($k === 'cooldown' ? ' Cooldown' : '');
        return '- ' . $dur((int)($s['dur'] ?? 60)) . ' ' . $rng($s) . $suffix . (!empty($s['ramp']) ? ' ramp' : '');
    };
    $out = [];
    foreach ($st as $b) {
        if (isset($b['steps']) && is_array($b['steps'])) {
            $out[] = '- ' . max(1, (int)($b['reps'] ?? 1)) . 'x';
            foreach ($b['steps'] as $c) $out[] = $one((array)$c);
        } else $out[] = $one((array)$b);
    }
    return implode("\n", $out);
}

/* ---------- commentaires du coach sous les séances de l'athlète ---------- */

function coach_comments_schema(PDO $p): void {
    try {
        $p->exec('CREATE TABLE IF NOT EXISTS coach_comments(id INTEGER PRIMARY KEY AUTOINCREMENT, activity_id TEXT, coach_id INTEGER, ts INTEGER, body TEXT)');
    } catch (\Throwable $e) { /* base absente : rien à faire */ }
}

/** Ajoute un commentaire du coach sur une séance de l'ATHLÈTE (+ notif dans sa base). */
function coach_comment_add(int $coachId, int $athleteId, string $activityId, string $body): bool {
    if (!coach_is_active($coachId, $athleteId)) return false;
    $body = trim(mb_substr($body, 0, 2000));
    if ($body === '' || preg_match('/^\d{1,20}$/', $activityId) !== 1) return false;
    $p = coach_open_db($athleteId);
    if (!$p) return false;
    coach_comments_schema($p);
    try {
        $chk = $p->prepare('SELECT COUNT(*) FROM activities WHERE id = ?');
        $chk->execute([$activityId]);
        if (!(int)$chk->fetchColumn()) return false;   // séance introuvable chez l'athlète
    } catch (\Throwable $e) { return false; }
    $p->prepare('INSERT INTO coach_comments(activity_id, coach_id, ts, body) VALUES (?,?,?,?)')
        ->execute([$activityId, $coachId, time(), $body]);
    coach_notify_athlete($athleteId, 'coach_comment', 'coach-comment-' . $activityId . '-' . uniqid(),
        'Ton coach a commenté une séance', mb_substr($body, 0, 160), '?p=activity&id=' . $activityId, 'info');
    return true;
}

/** Commentaires d'une séance de l'athlète (avec nom du coach résolu côté auth). $activityId null = tous (200 max). */
function coach_comment_list(int $athleteId, ?string $activityId = null): array {
    $p = coach_open_db($athleteId);
    if (!$p) return [];
    try {
        $st = $activityId !== null
            ? $p->prepare('SELECT id, activity_id, coach_id, ts, body FROM coach_comments WHERE activity_id = ? ORDER BY ts')
            : $p->prepare('SELECT id, activity_id, coach_id, ts, body FROM coach_comments ORDER BY ts DESC LIMIT 200');
        $st->execute($activityId !== null ? [$activityId] : []);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) {
        try {
            $u = auth_db()->prepare('SELECT name FROM users WHERE id = ?');
            $u->execute([(int)$r['coach_id']]);
            $r['coach_name'] = (string)($u->fetchColumn() ?: 'Coach');
        } catch (\Throwable $e) { $r['coach_name'] = 'Coach'; }
    }
    return $rows;
}

/** Commentaires sur UNE séance — côté ATHLÈTE sur sa propre page (base courante, table créée initialement par le coach). */
function coach_comments_current(string $activityId): array {
    try {
        $st = db()->prepare('SELECT id, coach_id, ts, body FROM coach_comments WHERE activity_id = ? ORDER BY ts');
        $st->execute([$activityId]);
    } catch (\Throwable $e) { return []; }
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        try {
            $u = auth_db()->prepare('SELECT name FROM users WHERE id = ?');
            $u->execute([(int)$r['coach_id']]);
            $r['coach_name'] = (string)($u->fetchColumn() ?: 'Coach');
        } catch (\Throwable $e) { $r['coach_name'] = 'Coach'; }
    }
    return $rows;
}

/** Bibliothèque de séances structurées du coach (sa propre base) — légende + structures pour le select de planification. */
function coach_library(int $coachId, int $limit = 50): array {
    try {
        return db()->query('SELECT id, name, sport, mode, unit, duration, tss, structure, profile FROM workouts ORDER BY updated_at DESC LIMIT ' . max(1, min(200, $limit)))
            ->fetchAll();
    } catch (\Throwable $e) { return []; }
}
