<?php
require_once __DIR__ . '/lib.php';
bootstrap_errors(true);
/* Sécurité : sync.php n'accepte que des POST — une action déclenchée en GET contournerait le contrôle CSRF (valable uniquement sur POST) et reste exécutable via une simple navigation cross-site. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée (POST requis).']);
    exit;
}
$me = require_login(true);                 // 401 JSON si non connecté ; jeton CSRF exigé (en-tête X-CSRF-Token)
header('Content-Type: application/json');
set_time_limit(180);

try {
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'list') {
        // 1. Liste des activités depuis intervals.icu
        $days = max(7, (int)($_POST['days'] ?? 365));
        $oldest = date('Y-m-d', strtotime("-$days days"));
        if (!cfg('ftp')) import_athlete_settings();
        
        $list = icu_get('/athlete/' . athlete_id() . '/activities', ['oldest' => $oldest, 'newest' => date('Y-m-d')]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO activities
            (id,date,type,sport,name,duration,distance,elev,avg_hr,max_hr,avg_speed,avg_w,icu_load)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        
        $n = 0;
        $pdo->beginTransaction();
        foreach ($list as $a) {
            if (empty($a['start_date_local']) || empty($a['id'])) continue;
            $ins->execute([
                (string)$a['id'],
                $a['start_date_local'],
                $a['type'] ?? '',
                sport_of($a['type'] ?? ''),
                $a['name'] ?? '',
                $a['moving_time'] ?? $a['elapsed_time'] ?? 0,
                $a['distance'] ?? 0,
                $a['total_elevation_gain'] ?? 0,
                $a['average_heartrate'] ?? null,
                $a['max_heartrate'] ?? null,
                $a['average_speed'] ?? null,
                $a['icu_average_watts'] ?? null,
                $a['icu_training_load'] ?? null
            ]);
            $n++;
        }
        $pdo->commit();
        $rem = (int)$pdo->query('SELECT COUNT(*) FROM activities WHERE synced=0')->fetchColumn();
        echo json_encode(['ok' => true, 'found' => $n, 'remaining' => $rem]);

    } elseif ($action === 'process') {
        // 2. Traitement des flux 1 Hz et analyse des activités (par lot de 6)
        $rows = $pdo->query('SELECT * FROM activities WHERE synced=0 ORDER BY date DESC LIMIT 6')->fetchAll();
        foreach ($rows as $r) {
            try { process_activity($r); }
            catch (Throwable $e) { error_log('process_activity: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
        }
        $rem = (int)$pdo->query('SELECT COUNT(*) FROM activities WHERE synced=0')->fetchColumn();
        echo json_encode(['ok' => true, 'remaining' => $rem]);

    } elseif ($action === 'recompute') {
        // Recalcul local après changement de FTP / seuils physiologiques
        $pdo->exec('UPDATE activities SET synced=0');
        $total = (int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
        echo json_encode(['ok' => true, 'remaining' => $total]);

    } elseif ($action === 'import_settings') {
        import_athlete_settings();
        echo json_encode(['ok' => true]);

    } elseif ($action === 'sync_wellness') {
        $days = max(14, (int)($_POST['days'] ?? 90));
        $count = sync_wellness_icu($days);
        echo json_encode(['ok' => true, 'synced' => $count]);

    } elseif ($action === 'save_wellness') {
        // Enregistrement manuel d'une entrée Wellness
        $d = trim($_POST['date'] ?? date('Y-m-d'));
        $hrv = !empty($_POST['hrv']) ? (float)$_POST['hrv'] : null;
        $rhr = !empty($_POST['resting_hr']) ? (float)$_POST['resting_hr'] : null;
        $sleepH = !empty($_POST['sleep_h']) ? (float)$_POST['sleep_h'] : null;
        $sleepScore = !empty($_POST['sleep_score']) ? (float)$_POST['sleep_score'] : null;
        $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
        $fatigue = !empty($_POST['fatigue']) ? (int)$_POST['fatigue'] : null;
        $stress = !empty($_POST['stress']) ? (int)$_POST['stress'] : null;
        $soreness = !empty($_POST['soreness']) ? (int)$_POST['soreness'] : null;
        $mood = !empty($_POST['mood']) ? (int)$_POST['mood'] : null;
        $comments = trim($_POST['comments'] ?? '');

        $sleepSec = $sleepH !== null ? (int)round($sleepH * 3600) : null;
        $readiness = compute_readiness_score($hrv, $rhr, $sleepSec, $sleepScore, $fatigue, $stress);

        $ins = $pdo->prepare('INSERT INTO wellness
            (date, hrv, resting_hr, sleep_sec, sleep_score, sleep_quality, weight, fatigue, soreness, stress, mood, readiness, comments, synced)
            VALUES (?,?,?,?,?,4,?,?,?,?,?,?,?,1)
            ON CONFLICT(date) DO UPDATE SET
                hrv=excluded.hrv, resting_hr=excluded.resting_hr, sleep_sec=excluded.sleep_sec,
                sleep_score=excluded.sleep_score, weight=excluded.weight, fatigue=excluded.fatigue,
                soreness=excluded.soreness, stress=excluded.stress, mood=excluded.mood,
                readiness=excluded.readiness, comments=excluded.comments');

        $ins->execute([$d, $hrv, $rhr, $sleepSec, $sleepScore, $weight, $fatigue, $soreness, $stress, $mood, $readiness, $comments]);
        echo json_encode(['ok' => true, 'date' => $d, 'readiness' => $readiness]);

    } elseif ($action === 'save_workout') {
        // Ajout d'une séance manuelle / planifiée
        $d = trim($_POST['date'] ?? date('Y-m-d'));
        $sport = trim($_POST['sport'] ?? 'ride');
        $title = trim($_POST['title'] ?? 'Séance planifiée');
        $dur = (int)($_POST['duration_min'] ?? 60) * 60;
        $dist = (float)($_POST['distance_km'] ?? 0) * 1000;
        $tss = !empty($_POST['tss']) ? (float)$_POST['tss'] : 50.0;
        $notes = trim($_POST['notes'] ?? '');

        $ins = $pdo->prepare('INSERT INTO planned_workouts (date, sport, title, duration, distance, tss, notes) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([$d, $sport, $title, $dur, $dist, $tss, $notes]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'lap_preview') {
        echo json_encode(['ok' => true, 'stats' => lap_preview((string)$_POST['id'], (int)$_POST['start'], (int)$_POST['end'])]);

    } elseif ($action === 'lap_add') {
        $laps = custom_lap_add((string)$_POST['id'], (int)$_POST['start'], (int)$_POST['end'], (string)($_POST['label'] ?? ''), (int)($_POST['is_work'] ?? 1));
        echo json_encode(['ok' => true, 'count' => count($laps)]);

    } elseif ($action === 'activity_rename') {
        $rid = (string)($_POST['id'] ?? '');
        $rname = trim((string)($_POST['name'] ?? ''));
        if ($rid === '' || $rname === '' || mb_strlen($rname) > 120) throw new RuntimeException('Nom invalide.');
        db()->prepare('UPDATE activities SET name=? WHERE id=?')->execute([$rname, $rid]);
        echo json_encode(['ok' => true, 'name' => $rname]);

    } elseif ($action === 'activity_feel') {
        $fid = (string)($_POST['id'] ?? '');
        $frpe = isset($_POST['rpe']) && $_POST['rpe'] !== '' ? max(1, min(10, (int)$_POST['rpe'])) : null;
        $ffeel = trim((string)($_POST['feel'] ?? ''));
        if ($fid === '') throw new RuntimeException('Activité introuvable.');
        if ($frpe === null && $ffeel === '') throw new RuntimeException('Renseignez au moins le RPE ou la sensation.');
        db()->prepare('UPDATE activities SET rpe=?, feel=? WHERE id=?')->execute([$frpe, $ffeel !== '' ? $ffeel : null, $fid]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'activity_resync') {
        // Re-force la synchro d'UNE séance : flux + tours remis à zéro puis retraités immédiatement depuis intervals.icu.
        // Les erreurs (DNS, clé API, 404, 429…) remontent à l'UI au lieu d'être avalées, sinon la séance reste vide sans explication.
        $rid = (string)($_POST['id'] ?? '');
        if ($rid === '') throw new RuntimeException('Activité introuvable.');
        db()->prepare('UPDATE activities SET synced=0, streams=NULL, laps=NULL WHERE id=?')->execute([$rid]);
        $st = db()->prepare('SELECT * FROM activities WHERE id=?');
        $st->execute([$rid]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Activité introuvable.');
        process_activity($r);
        $chk = db()->prepare('SELECT streams IS NOT NULL AND synced=1 FROM activities WHERE id=?');
        $chk->execute([$rid]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Aucun flux récupéré depuis intervals.icu pour cette séance (séance sans données, clé API ou ID athlète invalide). Vérifie les réglages puis réessaie.');
        echo json_encode(['ok' => true]);

    } elseif ($action === 'week_goals_set') {
        $dec = json_decode((string)($_POST['goals'] ?? ''), true);
        if (!is_array($dec)) throw new RuntimeException('Objectifs invalides.');
        $out = [];
        foreach (['ride', 'run', 'other'] as $sp) {
            $g = is_array($dec[$sp] ?? null) ? $dec[$sp] : [];
            $out[$sp] = [
                'tss' => max(0, min(3000, (int)round((float)($g['tss'] ?? 0)))),
                'time' => max(0, min(100800, (int)round((float)($g['time'] ?? 0)))),
            ];
        }
        cfg_set('week_goals', json_encode($out));
        echo json_encode(['ok' => true, 'goals' => $out]);

    } elseif ($action === 'lap_delete') {
        $laps = custom_lap_delete((string)$_POST['id'], (int)$_POST['lap']);
        echo json_encode(['ok' => true, 'count' => count($laps)]);

    } elseif ($action === 'lap_clear') {
        custom_lap_clear((string)$_POST['id']);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'workout_save') {
        $d = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($d)) throw new RuntimeException('Données invalides.');
        echo json_encode(['ok' => true, 'id' => workout_save($d)]);

    } elseif ($action === 'workout_delete') {
        db()->prepare('DELETE FROM workouts WHERE id=?')->execute([(int)$_POST['id']]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'workout_plan') {
        $d = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($d)) throw new RuntimeException('Données invalides.');
        echo json_encode(['ok' => true] + workout_plan($d));

    } elseif ($action === 'plan_link') {
        $pid = (int)($_POST['plan_id'] ?? 0); $aid = (string)($_POST['activity_id'] ?? '');
        $ok1 = db()->prepare('SELECT COUNT(*) FROM planned_workouts WHERE id=?'); $ok1->execute([$pid]);
        $ok2 = db()->prepare('SELECT COUNT(*) FROM activities WHERE id=?'); $ok2->execute([$aid]);
        if (!$ok1->fetchColumn() || !$ok2->fetchColumn()) throw new RuntimeException('Séance ou activité introuvable.');
        db()->prepare("UPDATE planned_workouts SET activity_id=NULL, cmp_key=NULL, cmp_json=NULL WHERE activity_id=? AND id<>?")->execute([$aid, $pid]);   // une sortie = une séance
        db()->prepare('UPDATE planned_workouts SET activity_id=?, cmp_key=NULL, cmp_json=NULL WHERE id=?')->execute([$aid, $pid]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'plan_unlink') {
        db()->prepare('UPDATE planned_workouts SET activity_id=NULL, cmp_key=NULL, cmp_json=NULL WHERE id=?')->execute([(int)($_POST['plan_id'] ?? 0)]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'plan_push') {
        echo json_encode(['ok' => true, 'id' => push_planned_to_icu((int)$_POST['id'])]);

    } elseif ($action === 'plan_delete') {
        db()->prepare('DELETE FROM planned_workouts WHERE id=?')->execute([(int)$_POST['id']]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'account_password') {
        if (!auth_enabled()) throw new RuntimeException('Authentification désactivée.');
        if ((string)($_POST['new'] ?? '') !== (string)($_POST['new2'] ?? '')) throw new RuntimeException('Les deux mots de passe ne correspondent pas.');
        if ($err = auth_change_password(current_uid(), (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''))) throw new RuntimeException($err);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'account_delete') {
        if (!auth_enabled()) throw new RuntimeException('Authentification désactivée.');
        $u = auth_db()->prepare('SELECT * FROM users WHERE id=?');
        $u->execute([(int)current_uid()]);
        $u = $u->fetch();
        if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])) throw new RuntimeException('Mot de passe incorrect.');
        if ($u['role'] === 'admin' && (int)auth_db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1 && auth_user_count() > 1)
            throw new RuntimeException("Vous êtes le dernier administrateur : désignez-en un autre avant de supprimer ce compte.");
        auth_delete_user((int)$u['id']); auth_logout_session();
        echo json_encode(['ok' => true]);

    } elseif ($action === 'admin_toggle' || $action === 'admin_delete') {
        if (!auth_enabled() || !is_admin()) throw new RuntimeException('Accès réservé aux administrateurs.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id === current_uid()) throw new RuntimeException('Action impossible sur votre propre compte.');
        if ($action === 'admin_toggle') auth_db()->prepare('UPDATE users SET active = 1 - active WHERE id=?')->execute([$id]);
        else auth_delete_user($id);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'icu_test') {
        $a = icu_get('/athlete/' . athlete_id());
        echo json_encode(['ok' => true, 'name' => (string)($a['name'] ?? ($a['firstname'] ?? 'athlète')), 'ssl_verify' => cfg('ssl_verify', '1') !== '0']);

    } elseif ($action === 'fetch_gps') {
        echo json_encode(['ok' => true, 'gps' => gps_for_activity((string)($_POST['id'] ?? ''))]);

    } elseif ($action === 'seed_demo') {
        seed_demo_data();
        echo json_encode(['ok' => true, 'message' => 'Données de démo générées avec succès !']);

    } elseif ($action === 'clear_data') {
        if (auth_enabled()) {
            $st = auth_db()->prepare('SELECT pass_hash FROM users WHERE id=?');
            $st->execute([(int)current_uid()]);
            $hash = (string)$st->fetchColumn();
            if ($hash === '' || !password_verify((string)($_POST['password'] ?? ''), $hash))
                throw new RuntimeException('Mot de passe requis pour réinitialiser la base.');
        }
        clear_database();
        echo json_encode(['ok' => true, 'message' => 'Base réinitialisée']);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Action inconnue : ' . h($action)]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
