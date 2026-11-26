<?php
require_once __DIR__ . '/lib.php';
bootstrap_errors(true);
/* Sécurité : sync.php n'accepte que des POST — une action déclenchée en GET contournerait le contrôle CSRF (valable uniquement sur POST) et reste exécutable via une simple navigation cross-site. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => t('Méthode non autorisée (POST requis).')]);
    exit;
}
$me = require_login(true);                 // 401 JSON si non connecté ; jeton CSRF exigé (en-tête X-CSRF-Token)
while (ob_get_level() > 0) ob_end_clean(); // jette tout affichage parasite (warning/deprecation émis au chargement de lib.php) qui corromprait la réponse JSON
header('Content-Type: application/json');
set_time_limit(180);

try {
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'list') {
        // 1. Liste des activités depuis intervals.icu (les séances connues sont mises à jour : nom, durée, FC, charge icu…)
        $days = max(7, (int)($_POST['days'] ?? 365));
        $q = ['newest' => date('Y-m-d')];
        if ($days < 3650) $q['oldest'] = date('Y-m-d', strtotime("-$days days"));
        if (!cfg('ftp')) import_athlete_settings();
        
        $list = icu_get('/athlete/' . athlete_id() . '/activities', $q);
        $ins = $pdo->prepare('INSERT INTO activities
            (id,date,type,sport,name,duration,distance,elev,avg_hr,max_hr,avg_speed,avg_w,icu_load)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET
                name=CASE WHEN activities.name_custom=1 THEN activities.name ELSE excluded.name END,
                duration=excluded.duration, distance=excluded.distance, elev=excluded.elev,
                avg_hr=excluded.avg_hr, max_hr=excluded.max_hr, avg_speed=excluded.avg_speed,
                avg_w=excluded.avg_w, icu_load=excluded.icu_load,
                tss=CASE WHEN activities.tss_type=\'load\' THEN excluded.icu_load ELSE activities.tss END');
        $known = [];
        foreach ($pdo->query('SELECT id FROM activities')->fetchAll(PDO::FETCH_COLUMN) as $kid) $known[$kid] = true;
        
        $n = 0; $created = 0;
        $pdo->beginTransaction();
        foreach ($list as $a) {
            if (empty($a['start_date_local']) || empty($a['id'])) continue;
            /* Anti-doublon Garmin : même sortie déjà arrivée par push Garmin (id g-…) →
               on fusionne en renommant l'id Garmin vers l'id ICU ; l'upsert ci-dessous
               complète alors la ligne (streams, TSS, FC…) sans créer de doublon. */
            $icuId = (string)$a['id'];
            if (!isset($known[$icuId])) {
                $gd = (int)($a['moving_time'] ?? $a['elapsed_time'] ?? 0);
                $chk = $pdo->prepare("SELECT id, duration FROM activities WHERE id LIKE 'g-%' AND substr(date,1,10) = substr(?,1,10) AND sport = ?");
                $chk->execute([(string)$a['start_date_local'], sport_of((string)($a['type'] ?? ''))]);
                foreach ($chk->fetchAll() as $ex) {
                    $ed = (int)$ex['duration'];
                    if ($ed > 0 && $gd > 0 && abs($ed - $gd) <= max(120, (int)round(0.2 * max($ed, $gd)))) {
                        $pdo->prepare('UPDATE activities SET id = ? WHERE id = ?')->execute([$icuId, $ex['id']]);
                        $pdo->prepare("UPDATE planned_workouts SET activity_id = ? WHERE activity_id = ?")->execute([$icuId, $ex['id']]);   // répare les liens prévu/réalisé
                        $known[$icuId] = true;   // pas comptée comme nouvelle ; l'upsert mettra à jour les champs
                        break;
                    }
                }
            }
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
            if (!isset($known[(string)$a['id']])) $created++;
        }
        $pdo->commit();
        $rem = (int)$pdo->query('SELECT COUNT(*) FROM activities WHERE synced=0')->fetchColumn();
        if ($created > 0) notif_add('sync', 'sync-' . uniqid(), t('Nouvelles séances synchronisées'),
            sprintf(t('%d nouvelle(s) séance(s) importée(s) depuis intervals.icu.'), $created), '?p=activities', 'good');
        if ($created > 0) { try { coach_snapshot_refresh(current_uid()); } catch (Throwable $e) {} }   // module coach : rafraîchit le snapshot vu par le coach
        echo json_encode(['ok' => true, 'found' => $n, 'created' => $created, 'remaining' => $rem]);

    } elseif ($action === 'process') {
        // 2. Traitement des flux 1 Hz et analyse des activités (par lot de 6)
        $rows = $pdo->query('SELECT * FROM activities WHERE synced=0 ORDER BY date DESC LIMIT 6')->fetchAll();
        foreach ($rows as $r) {
            try { process_activity($r); }
            catch (Throwable $e) { error_log('process_activity: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
        }
        $rem = (int)$pdo->query('SELECT COUNT(*) FROM activities WHERE synced=0')->fetchColumn();
        if ($rem === 0) { try { coach_snapshot_refresh(current_uid()); } catch (Throwable $e) {} }   // module coach : flux terminés, snapshot à jour
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
        $title = trim($_POST['title'] ?? t('Séance planifiée'));
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
        db()->prepare('UPDATE activities SET name=?, name_custom=1 WHERE id=?')->execute([$rname, $rid]);
        echo json_encode(['ok' => true, 'name' => $rname]);

    } elseif ($action === 'activity_feel') {
        $fid = (string)($_POST['id'] ?? '');
        $frpe = isset($_POST['rpe']) && $_POST['rpe'] !== '' ? max(1, min(10, (int)$_POST['rpe'])) : null;
        $ffeel = trim((string)($_POST['feel'] ?? ''));
        if ($fid === '') throw new RuntimeException(t('Activité introuvable.'));
        if ($frpe === null && $ffeel === '') throw new RuntimeException('Renseignez au moins le RPE ou la sensation.');
        db()->prepare('UPDATE activities SET rpe=?, feel=? WHERE id=?')->execute([$frpe, $ffeel !== '' ? $ffeel : null, $fid]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'activity_resync') {
        // Re-force la synchro d'UNE séance : flux + tours remis à zéro puis retraités immédiatement depuis intervals.icu.
        // Les erreurs (DNS, clé API, 404, 429…) remontent à l'UI au lieu d'être avalées, sinon la séance reste vide sans explication.
        $rid = (string)($_POST['id'] ?? '');
        if ($rid === '') throw new RuntimeException(t('Activité introuvable.'));
        db()->prepare('UPDATE activities SET synced=0, streams=NULL, laps=NULL WHERE id=?')->execute([$rid]);
        $st = db()->prepare('SELECT * FROM activities WHERE id=?');
        $st->execute([$rid]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException(t('Activité introuvable.'));
        process_activity($r);
        $chk = db()->prepare('SELECT streams IS NOT NULL AND synced=1 FROM activities WHERE id=?');
        $chk->execute([$rid]);
        if (!$chk->fetchColumn()) throw new RuntimeException(t('Aucun flux récupéré depuis intervals.icu pour cette séance (séance sans données, clé API ou ID athlète invalide). Vérifie les réglages puis réessaie.'));
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
        if (!is_array($d)) throw new RuntimeException(t('Données invalides.'));
        echo json_encode(['ok' => true, 'id' => workout_save($d)]);

    } elseif ($action === 'workout_delete') {
        db()->prepare('DELETE FROM workouts WHERE id=?')->execute([(int)$_POST['id']]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'workout_plan') {
        $d = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($d)) throw new RuntimeException(t('Données invalides.'));
        echo json_encode(['ok' => true] + workout_plan($d));

    } elseif ($action === 'plan_link') {
        $pid = (int)($_POST['plan_id'] ?? 0); $aid = (string)($_POST['activity_id'] ?? '');
        $ok1 = db()->prepare('SELECT COUNT(*) FROM planned_workouts WHERE id=?'); $ok1->execute([$pid]);
        $ok2 = db()->prepare('SELECT COUNT(*) FROM activities WHERE id=?'); $ok2->execute([$aid]);
        if (!$ok1->fetchColumn() || !$ok2->fetchColumn()) throw new RuntimeException(t('Séance ou activité introuvable.'));
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

    } elseif ($action === 'plan_move') {
        /* drag & drop : déplace une séance planifiée vers un autre jour */
        $id = (int)($_POST['id'] ?? 0);
        $d = trim((string)($_POST['date'] ?? ''));
        if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new RuntimeException('Coordonnées de déplacement invalides.');
        $has = db()->prepare('SELECT id, icu_event_id, structure FROM planned_workouts WHERE id=?'); $has->execute([$id]);
        $mv = $has->fetch();
        if (!$mv) throw new RuntimeException('Séance planifiée introuvable.');
        db()->prepare('UPDATE planned_workouts SET date=? WHERE id=?')->execute([$d, $id]);
        /* Séance déjà poussée vers intervals.icu : met l'événement à jour (upsert par uid, pas de doublon). Garmin : pas de re-push (leur API ne met pas à jour — évite les doublons dans le calendrier Garmin). */
        if ((string)($mv['icu_event_id'] ?? '') !== '' && trim((string)$mv['structure']) !== '') {
            try { push_planned_to_icu($id); } catch (Throwable $me) { }
        }
        echo json_encode(['ok' => true]);

    } elseif ($action === 'plan_from_workout') {
        /* drag & drop depuis la banque : planifie une séance structurée sur un jour (copie structure + profile + icu_text) */
        $wid = (int)($_POST['workout_id'] ?? 0);
        $d = trim((string)($_POST['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new RuntimeException('Date invalide.');
        $wq = db()->prepare('SELECT * FROM workouts WHERE id=?'); $wq->execute([$wid]);
        $w = $wq->fetch();
        if (!$w) throw new RuntimeException('Séance structurée introuvable dans la banque.');
        $structure = (string)$w['structure'];
        $mode = ($w['mode'] ?? '') === 'hr' ? 'hr' : 'power';
        $icuText = $structure !== '' ? coach_plan_text($structure, $mode) : '';
        db()->prepare('INSERT INTO planned_workouts (date, sport, title, duration, distance, tss, tss_type, notes, structure, profile, workout_id, icu_text) VALUES (?,?,?,?,0,?,?,?,?,?,?,?)')
            ->execute([$d, $w['sport'], $w['name'], $w['duration'], $w['tss'], $mode === 'hr' ? 'hrtss' : 'power', $w['notes'], $structure, $w['profile'], $wid, mb_substr($icuText, 0, 20000)]);
        $pid = (int)db()->lastInsertId();
        /* Envoi direct vers intervals.icu + Garmin Connect dès la planification (silencieux si non reliés) */
        $push = $structure !== '' ? auto_push_planned($pid) : [];
        echo json_encode(['ok' => true, 'push' => $push]);

    } elseif ($action === 'account_password') {
        if (!auth_enabled()) throw new RuntimeException(t('Authentification désactivée.'));
        if ((string)($_POST['new'] ?? '') !== (string)($_POST['new2'] ?? '')) throw new RuntimeException('Les deux mots de passe ne correspondent pas.');
        if ($err = auth_change_password(current_uid(), (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''))) throw new RuntimeException($err);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'account_delete') {
        if (!auth_enabled()) throw new RuntimeException(t('Authentification désactivée.'));
        $u = auth_db()->prepare('SELECT * FROM users WHERE id=?');
        $u->execute([(int)current_uid()]);
        $u = $u->fetch();
        if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])) throw new RuntimeException('Mot de passe incorrect.');
        if ($u['role'] === 'admin' && (int)auth_db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1 && auth_user_count() > 1)
            throw new RuntimeException("Vous êtes le dernier administrateur : désignez-en un autre avant de supprimer ce compte.");
        auth_delete_user((int)$u['id']); auth_logout_session();
        echo json_encode(['ok' => true]);

    } elseif ($action === 'admin_toggle' || $action === 'admin_delete') {
        if (!auth_enabled() || !is_admin()) throw new RuntimeException(t('Accès réservé aux administrateurs.'));
        $id = (int)($_POST['id'] ?? 0);
        if ($id === current_uid()) throw new RuntimeException('Action impossible sur votre propre compte.');
        if ($action === 'admin_toggle') auth_db()->prepare('UPDATE users SET active = 1 - active WHERE id=?')->execute([$id]);
        else auth_delete_user($id);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'notif_list') {
        echo json_encode(['ok' => true, 'items' => notif_latest(40), 'unread' => notif_unread()]);

    } elseif ($action === 'notif_read') {
        notif_read_id((int)($_POST['id'] ?? 0));
        echo json_encode(['ok' => true, 'unread' => notif_unread()]);

    } elseif ($action === 'notif_read_all') {
        notif_read_all();
        echo json_encode(['ok' => true, 'unread' => notif_unread()]);

    } elseif ($action === 'icu_test') {
        $a = icu_get('/athlete/' . athlete_id());
        echo json_encode(['ok' => true, 'name' => (string)($a['name'] ?? ($a['firstname'] ?? 'athlète')), 'ssl_verify' => cfg('ssl_verify', '1') !== '0']);

    } elseif ($action === 'garmin_test') {
        /* Vérifie les permissions consenties + récupère le userId Garmin */
        if (!garmin_status()['configured']) throw new RuntimeException("Garmin : renseignez d'abord client_id et client_secret dans les Paramètres.");
        $p = garmin_permissions();
        echo json_encode(['ok' => true, 'user_id' => $p['user_id'], 'permissions' => $p['permissions']]);

    } elseif ($action === 'garmin_push') {
        /* Pousse les séances planifiées STRUCTURÉES vers Garmin Connect (Training API v2).
           days = fenêtre (0 = aujourd'hui seulement, défaut 7) ; force = repousser même déjà envoyées. */
        $gdays = max(0, (int)($_POST['days'] ?? 7));
        $gforce = (string)($_POST['force'] ?? '') === '1';
        $gLimit = $gdays > 0 ? date('Y-m-d', strtotime("+$gdays days")) : date('Y-m-d');
        $gRes = [];
        $gPlans = $pdo->prepare("SELECT id, date, title, sport, tss_type FROM planned_workouts WHERE completed=0 AND (activity_id IS NULL OR activity_id='') AND structure IS NOT NULL AND structure <> '' AND substr(date,1,10) <= ? ORDER BY substr(date,1,10), id");
        $gPlans->execute([$gLimit]);
        $gPushed = json_decode((string)cfg('garmin_pushed', '{}'), true) ?: [];
        foreach ($gPlans->fetchAll() as $gp) {
            $gpid = (int)$gp['id'];
            if (!$gforce && isset($gPushed[$gpid]) && ($gPushed[$gpid]['date'] ?? '') === substr((string)$gp['date'], 0, 10)) continue;  // déjà poussée à cette date
            try {
                $r = garmin_push_workout($gpid);
                $gRes[] = ['id' => $gpid, 'name' => $r['name'], 'date' => $r['date'], 'gid' => $r['gid'], 'ok' => true];
            } catch (Throwable $ge) {
                $gRes[] = ['id' => $gpid, 'name' => (string)$gp['title'], 'date' => (string)$gp['date'], 'ok' => false, 'error' => $ge->getMessage()];
            }
        }
        echo json_encode(['ok' => true, 'pushed' => count(array_filter($gRes, fn ($x) => $x['ok'])), 'results' => $gRes,
            'window' => $gdays === 0 ? "aujourd'hui" : "$gdays jours"]);

    } elseif ($action === 'fetch_gps') {
        echo json_encode(['ok' => true, 'gps' => gps_for_activity((string)($_POST['id'] ?? ''))]);

    } elseif ($action === 'seed_demo') {
        seed_demo_data();
        echo json_encode(['ok' => true, 'message' => t('Données de démo générées avec succès !')]);

    } elseif ($action === 'clear_data') {
        if (auth_enabled()) {
            $st = auth_db()->prepare('SELECT pass_hash FROM users WHERE id=?');
            $st->execute([(int)current_uid()]);
            $hash = (string)$st->fetchColumn();
            if ($hash === '' || !password_verify((string)($_POST['password'] ?? ''), $hash))
                throw new RuntimeException(t('Mot de passe requis pour réinitialiser la base.'));
        }
        clear_database();
        echo json_encode(['ok' => true, 'message' => t('Base réinitialisée')]);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('Action inconnue : ') . h($action)]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    try {
        notif_add('sync_err', 'syncerr-' . uniqid(), t('Échec de synchronisation'), $e->getMessage(), '?p=settings', 'crit');
    } catch (Throwable $e2) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
