<?php
/* Garmin Connect — interconnexion TRQ ⇄ Garmin (Connect Developer Program, accès Starter).

   Programme réouvert le 21 octobre 2026. Accès Starter = OAuth 2.0 + PKCE,
   livraison PUSH uniquement (pas de pull, pas d'historique) :

     1. SANTÉ / ACTIVITÉS (Health + Activity API)  → Garmin POUSSE les données
        vers les URLs de rappel (webhooks) enregistrées dans le portail
        développeur → endpoint TRQ : garmin_webhook.php (ACK + import).
     2. ENTRAÎNEMENTS (Training API)            → TRQ PUSSE les séances
        structurées vers le calendrier Garmin Connect ; la montre les récupère
        à la synchro suivante (POST /workoutportal/workout/v2
        + planification à une date).

   Activation (une seule fois, dans le portail développeur Garmin) :
     - Clé app : renseigner garmin_client_id + garmin_client_secret (carte Garmin
       des Paramètres — chiffrés automatiquement via cfg_set).
     - URL de rappel OAuth : <origine>/oauth_garmin.php
     - URLs de rappel webhooks (tous les endpoints push) : <origine>/garmin_webhook.php?token=<garmin_webhook_token>
       (le token est affiché dans la carte Garmin des Paramètres et généré
       automatiquement à la première ouverture de la carte).

   Clés de configuration (table settings de la base utilisateur) :
     - garmin_client_id        (public)
     - garmin_client_secret    (chiffré AES-256-GCM automatiquement via cfg_set)
     - garmin_access_token     (chiffré)
     - garmin_refresh_token    (chiffré)
     - garmin_token_exp        (expiration du token, timestamp unix)
     - garmin_user_id          (userId Garmin — sert à router les webhooks multi-utilisateurs)
     - garmin_user_display     (identifiant affiché après liaison)
     - garmin_permissions      (JSON : droits accordés par l'utilisateur côté Garmin)
     - garmin_webhook_token    (secret dans l'URL du webhook)
     - garmin_webhook_last     (JSON : dernier webhook reçu — diagnostic)
     - garmin_pushed           (JSON : {id séance planifiée => {gid, date, name, ts}})
     - garmin_last_push        (JSON : dernière requête/réponse Training API — diagnostic)
*/

/* Endpoints Garmin (programme réouvert 21/10/2026 — OAuth 2.0 PKCE) */
const GARMIN_SSO_AUTHORIZE = 'https://connect.garmin.com/oauth2Confirm';
const GARMIN_SSO_TOKEN     = 'https://connectapi.garmin.com/di-oauth2-service/oauth/token';
const GARMIN_API_BASE      = 'https://apis.garmin.com';
const GARMIN_WEBHOOK_ACK   = '{"response": "ACKNOWLEDGED"}';

/**
 * Générateur PKCE : crée le code_verifier et rend la paire.
 * Copies : ① session PHP ② copie durable en base (garmin_pkce, 15 min) —
 * nécessaire car require_login() ferme la session et que le cookie de session
 * peut être perdu/régénéré pendant le passage chez Garmin (le callback arrive
 * alors avec une session neuve et un code_verifier vide → erreur Garmin
 * « code_verifier must be provided »).
 */
function garmin_pkce_pair(): array {
    $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    try { cfg_set('garmin_pkce', json_encode(['v' => $verifier, 't' => time()], JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {}
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['garmin_pkce_verifier'] = $verifier;
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return [$verifier, $challenge];
}
function garmin_pkce_verifier(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $v = (string)($_SESSION['garmin_pkce_verifier'] ?? '');
    if ($v !== '') return $v;
    /* Repli : la copie posée en base au start (valable 15 min). */
    try {
        $rec = json_decode((string)cfg('garmin_pkce', ''), true);
        if (is_array($rec) && !empty($rec['v']) && (time() - (int)($rec['t'] ?? 0)) < 900) return (string)$rec['v'];
    } catch (Throwable $e) {}
    return '';
}

/** URL de rappel OAuth à enregistrer chez Garmin : <origine de l'app>/oauth_garmin.php */
function garmin_redirect_uri(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (string)cfg('trust_proxy', '0') === '1';           // hébergé derrière un proxy (LWS, InfinityFree…)
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host . '/oauth_garmin.php';
}

/** Base d'URL sans le nom de fichier OAuth (pour construire l'URL du webhook). */
function garmin_redirect_uri_url_base(): string {
    $u = garmin_redirect_uri();
    return (string)preg_replace('~/oauth_garmin\.php$~', '', $u);
}

/** URL du webhook push à enregistrer dans le portail Garmin (token inclus). */
function garmin_webhook_uri(): string {
    $tok = trim((string)cfg('garmin_webhook_token', ''));
    if ($tok === '') { $tok = bin2hex(random_bytes(24)); cfg_set('garmin_webhook_token', $tok); }
    return garmin_redirect_uri_url_base() . '/garmin_webhook.php?token=' . $tok;
}

/** Statut de la connexion Garmin — aucun appel réseau, lecture locale des réglages. */
function garmin_status(): array {
    $clientId = trim((string)cfg('garmin_client_id', ''));
    $secret   = trim((string)cfg('garmin_client_secret', ''));
    $token    = trim((string)cfg('garmin_access_token', ''));
    $exp      = (int)cfg('garmin_token_exp', '0');
    return [
        'configured'   => $clientId !== '' && $secret !== '',            // clés développeur en place
        'connected'    => $token !== '' && ($exp === 0 || $exp > time()), // compte lié + token valable
        'client_id'    => $clientId,
        'expires_at'   => $exp,
        'display'      => (string)cfg('garmin_user_display', ''),
        'user_id'      => (string)cfg('garmin_user_id', ''),
        'permissions'  => json_decode((string)cfg('garmin_permissions', '[]'), true) ?: [],
        'webhook_last' => json_decode((string)cfg('garmin_webhook_last', '[]'), true) ?: [],
        'pushed'       => json_decode((string)cfg('garmin_pushed', '{}'), true) ?: [],
    ];
}

/**
 * URL d'autorisation à ouvrir dans le navigateur (start du flux OAuth 2.0 + PKCE).
 * $popup=true → le mode pop-up est transporté via un préfixe du state ('p'…/ 'r'…),
 * ce qui permet au callback de savoir s'il doit fermer la fenêtre (pop-up) ou rediriger.
 */
function garmin_authorize_url(bool $popup = false): string {
    [, $challenge] = garmin_pkce_pair();
    return GARMIN_SSO_AUTHORIZE . '?' . http_build_query([
        'response_type'         => 'code',
        'client_id'             => (string)cfg('garmin_client_id', ''),
        'redirect_uri'          => garmin_redirect_uri(),
        'scope'                 => 'ACTIVITY_EXPORT HEALTH_EXPORT WORKOUT_IMPORT',
        'state'                 => ($popup ? 'p' : 'r') . bin2hex(random_bytes(8)),
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);
}

/** Échange le code d'autorisation contre les tokens (callback oauth_garmin.php). */
function garmin_exchange_code(string $code): array {
    $resp = garmin_http(GARMIN_SSO_TOKEN, [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'code_verifier' => garmin_pkce_verifier(),
        'client_id'     => (string)cfg('garmin_client_id', ''),
        'client_secret' => (string)cfg('garmin_client_secret', ''),
        'redirect_uri'  => garmin_redirect_uri(),
    ]);
    if (empty($resp['access_token'])) throw new RuntimeException('Garmin : réponse OAuth inattendue (' . garmin_http_excerpt($resp) . ').');
    garmin_store_tokens($resp);
    try { cfg_set('garmin_pkce', ''); } catch (Throwable $e) {}   // le code est à usage unique : purge le verifier
    return $resp;
}

function garmin_store_tokens(array $resp): void {
    cfg_set('garmin_access_token', (string)$resp['access_token']);
    cfg_set('garmin_refresh_token', (string)($resp['refresh_token'] ?? cfg('garmin_refresh_token', '')));
    cfg_set('garmin_token_exp', (string)(time() + (int)($resp['expires_in'] ?? 3600) - 60));
}

/** Rafraîchit le token d'accès (stocke le NOUVEAU refresh token délivré à chaque appel — exigence Garmin). */
function garmin_refresh_token(): void {
    $rt = trim((string)cfg('garmin_refresh_token', ''));
    if ($rt === '') throw new RuntimeException('Garmin : pas de refresh token — reconnectez le compte.');
    $resp = garmin_http(GARMIN_SSO_TOKEN, [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $rt,
        'client_id'     => (string)cfg('garmin_client_id', ''),
        'client_secret' => (string)cfg('garmin_client_secret', ''),
    ]);
    if (empty($resp['access_token'])) throw new RuntimeException('Garmin : rafraîchissement du token échoué (' . garmin_http_excerpt($resp) . ').');
    garmin_store_tokens($resp);
}

/** Garantit un token d'accès frais (refresh automatique si proche de l'expiration). */
function garmin_access_token(): string {
    $exp = (int)cfg('garmin_token_exp', '0');
    if ($exp !== 0 && $exp < time() + 60) garmin_refresh_token();
    return (string)cfg('garmin_access_token', '');
}

/** Appel API authentifié (Bearer) — GET / POST JSON. $path commence par « / ». */
function garmin_api(string $method, string $path, ?array $body = null, int $timeout = 25) {
    $tok = garmin_access_token();
    if ($tok === '') throw new RuntimeException('Garmin : compte non connecté.');
    return garmin_http_raw($method, GARMIN_API_BASE . $path, $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null, [
        'Authorization: Bearer ' . $tok,
        'Accept: application/json',
        $body !== null ? 'Content-Type: application/json' : '',
    ], $timeout);
}

/** Vérifie les permissions consenties côté Garmin + récupère le userId. */
function garmin_permissions(): array {
    $j = garmin_api('GET', '/wellness-api/rest/user/permissions');
    $uid = (string)($j['userId'] ?? '');
    if ($uid !== '') cfg_set('garmin_user_id', $uid);
    if (isset($j['username'])) cfg_set('garmin_user_display', (string)$j['username']);
    $perms = $j['permissions'] ?? ($j['permission'] ?? []);
    if (is_array($perms)) cfg_set('garmin_permissions', json_encode(array_values($perms), JSON_UNESCAPED_UNICODE));
    return ['user_id' => $uid, 'permissions' => is_array($perms) ? array_values($perms) : [], 'raw' => $j];
}

/** Sonde Training API : teste les chemins candidats et renvoie path → statut ou erreur.
    Diagnostique l'erreur « RouteFailed » (HTTP 500) : chemin inexistant OU Training API non activée pour l'app dans le portail. */
function garmin_probe_training(): array {
    $out = [];
    foreach ([
        '/training-api/workout',
        '/training-api/workout/v2/1',
    ] as $p) {
        try {
            $r = garmin_api('GET', $p);
            $out[$p] = 'OK ' . mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 120);
        } catch (Throwable $e) {
            $out[$p] = mb_substr($e->getMessage(), 0, 200);
        }
    }
    /* POST de routage totalement inoffensif : id 0 n'existe pas → 404/400 = route OK, RouteFailed = route morte. */
    try {
        garmin_api('POST', '/training-api/workout/0/schedule', ['calendarDate' => date('Y-m-d')]);
        $out['POST /training-api/workout/0/schedule'] = 'OK (inattendu)';
    } catch (Throwable $e) {
        $out['POST /training-api/workout/0/schedule'] = mb_substr($e->getMessage(), 0, 200);
    }
    return $out;
}

/** Déconnexion : efface toutes les clés Garmin de la base de l'utilisateur. */
function garmin_disconnect(): void {
    db()->prepare('DELETE FROM settings WHERE k LIKE ?')->execute(['garmin_%']);
}

/* ================================================================================
   PUSH SÉANCES STRUCTURÉES — Training API (workout-portal)
   ================================================================================ */

/** TRQ sport → énum sport Training API v2 (RUNNING, CYCLING, LAP_SWIMMING). */
function garmin_sport_map(string $sport, string $tssType = 'power'): string {
    return match ((string)($sport === '' ? 'ride' : $sport)) {
        'run'   => 'RUNNING',
        'swim'  => 'LAP_SWIMMING',
        default => 'CYCLING',
    };
}

/**
 * Convertit une structure TRQ ([{kind, dur (s), lo, hi (%), ramp?, reps?, steps?}])
 * en steps au format OFFICIEL Training API v2 (§3.1) :
 * - étapes : {type: WorkoutStep, stepOrder, intensity, durationType: TIME, durationValue}
 * - répétitions : {type: WorkoutRepeatStep, repeatType: REPEAT_UNTIL_STEPS_CMPLT, repeatValue: N, steps: [...]}
 * - cibles puissance : targetType POWER + targetValueType PERCENT (% FTP) + targetValueLow/High ;
 *   mode FC : consigne en description (le % de la doc vise la FC max, pas la LTHR).
 * stepOrder continue à travers tout, y compris l'intérieur des répétitions (exemple officiel).
 */
function garmin_structure_to_steps(array $struct, string $sport, bool $hrMode = false): array {
    $steps = [];
    $order = 0;
    $push = function (array $blk) use (&$steps, &$order, $hrMode) {
        $order++;
        $dur = max(1, (int)round((float)($blk['dur'] ?? 0)));
        $lo = (float)($blk['lo'] ?? 0);
        $hi = (float)($blk['hi'] ?? 0);
        $kind = (string)($blk['kind'] ?? 'work');
        /* Description lisible sur la montre */
        $pct = ($lo > 0 || $hi > 0) ? ($lo === $hi ? "$lo %" : "$lo → $hi %") : '';
        if ($lo > 0 && $hi > 0 && $hi > $lo && (int)round($dur / 30) > 3) $pct .= ' (progressif)';
        $desc = $pct !== '' ? 'Intensité ' . $pct . ' de la cible' : '';
        $s = [
            'type'         => 'WorkoutStep',
            'stepOrder'    => $order,
            'intensity'    => match ($kind) {
                'warmup'   => 'WARMUP',
                'rest'     => 'RECOVERY',
                'cooldown' => 'COOLDOWN',
                default    => 'ACTIVE',
            },
            'durationType' => 'TIME',
            'durationValue' => $dur,
        ];
        if ($desc !== '') $s['description'] = $desc;
        /* Cible % FTP structurée — uniquement le cœur de séance (warmup/cooldown = rampe libre) */
        if (!$hrMode && !in_array($kind, ['warmup', 'cooldown'], true) && $hi > 0) {
            $s['targetType'] = 'POWER';
            $s['targetValueType'] = 'PERCENT';
            $s['targetValueLow'] = ($lo > 0 ? $lo : $hi);
            if ($hi > $lo) $s['targetValueHigh'] = $hi;
        }
        $steps[] = $s;
    };
    foreach ($struct as $blk) {
        if (!empty($blk['reps']) && (int)$blk['reps'] > 1 && !empty($blk['steps']) && is_array($blk['steps'])) {
            $order++;
            $inner = [];
            foreach ($blk['steps'] as $c) {
                $push($c);
                $inner[] = array_pop($steps);
            }
            $steps[] = [
                'type'         => 'WorkoutRepeatStep',
                'stepOrder'    => $order,
                'repeatType'   => 'REPEAT_UNTIL_STEPS_CMPLT',
                'repeatValue'  => (float)(int)$blk['reps'],
                'steps'        => $inner,
            ];
        } else {
            $push($blk);
        }
    }
    return $steps;
}

/** Structure TRQ → payload Training API v2 officiel (§3.2) : nom, sport énum, segments[0].steps. */
function garmin_build_workout(array $plan): array {
    $struct = json_decode((string)($plan['structure'] ?? ''), true) ?: [];
    if (!$struct) throw new RuntimeException('Garmin : séance sans structure — rien à exporter.');
    $sport = garmin_sport_map((string)($plan['sport'] ?? 'ride'));
    $hrMode = (string)($plan['tss_type'] ?? 'power') === 'hrtss';
    return [
        'workoutName' => trim((string)($plan['title'] ?? 'Séance TRQ')),
        'sport'       => $sport,
        'segments'    => [['segmentOrder' => 1, 'sport' => $sport, 'steps' => garmin_structure_to_steps($struct, $sport, $hrMode)]],
    ];
}

/**
 * Pousse la séance planifiée $planId vers Garmin Connect (Training API v2 officielle :
 * création POST /workoutportal/workout/v2, planification POST /training-api/schedule/).
 * Journalise l'erreur éventuelle dans garmin_last_push (diagnostic dans la carte Paramètres).
 */
function garmin_push_workout(int $planId): array {
    $st = db()->prepare('SELECT * FROM planned_workouts WHERE id=?');
    $st->execute([$planId]);
    $plan = $st->fetch();
    if (!$plan) throw new RuntimeException('Garmin : séance planifiée introuvable.');
    $payload = garmin_build_workout(is_array($plan) ? $plan : []);
    $day = substr((string)$plan['date'], 0, 10);
    $gid = '';
    $sid = '';
    $pushed = json_decode((string)cfg('garmin_pushed', '{}'), true) ?: [];
    $prevGid = (string)($pushed[$planId]['gid'] ?? ($pushed[$planId]['gid'] ?? ''));
    $oldSid = (string)($pushed[$planId]['sid'] ?? '');
    try {
        if ($prevGid !== '') {
            /* Déjà envoyée → MISE À JOUR (PUT, doc §3.5) au lieu d'un doublon. */
            $uid = (int)cfg('garmin_user_id', 0);
            $payload['workoutId'] = (int)$prevGid;
            if ($uid > 0) $payload['ownerId'] = $uid;
            try {
                garmin_api('PUT', '/training-api/workout/v2/' . rawurlencode($prevGid), $payload);
                $gid = $prevGid;
            } catch (Throwable $ue) {
                /* 404 : supprimée côté Garmin → recréation simple. */
                if (stripos($ue->getMessage(), '404') === false && stripos($ue->getMessage(), 'Not Found') === false) throw $ue;
                $prevGid = '';
            }
        }
        if ($prevGid === '') {
            $created = garmin_api('POST', '/workoutportal/workout/v2', $payload);
            $gid = (string)($created['workoutId'] ?? ($created['id'] ?? ''));
            $oldSid = '';   /* nouvelle séance → nouvelle planification */
        }
        if ($gid !== '' && $day !== '') {
            if ($oldSid !== '' && $gid === $prevGid) {
                /* Re-planification au jour courant (doc §4.5) */
                try {
                    garmin_api('PUT', '/training-api/schedule/' . rawurlencode($oldSid), ['scheduleId' => (int)$oldSid, 'workoutId' => (int)$gid, 'date' => $day]);
                    $sid = $oldSid;
                } catch (Throwable $se) {
                    $sch = garmin_api('POST', '/training-api/schedule/', ['workoutId' => (int)$gid, 'date' => $day]);
                    $sid = (string)($sch['scheduleId'] ?? '');
                }
            } else {
                $sch = garmin_api('POST', '/training-api/schedule/', ['workoutId' => (int)$gid, 'date' => $day]);
                $sid = (string)($sch['scheduleId'] ?? '');
            }
        }
        $pushed[$planId] = array_filter(['gid' => $gid, 'sid' => $sid, 'date' => $day, 'name' => (string)$plan['title'], 'ts' => time()], fn ($v) => $v !== '' && $v !== null);
        cfg_set('garmin_pushed', json_encode($pushed, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        cfg_set('garmin_last_push', json_encode(['when' => date('c'), 'ok' => false, 'error' => $e->getMessage(), 'plan' => $planId], JSON_UNESCAPED_UNICODE));
        throw $e;
    }
    return ['gid' => $gid, 'date' => $day, 'name' => (string)$plan['title']];
}

/** Re-planifie une séance DÉJÀ envoyée vers une autre date (PUT schedule si sid connu, sinon création — doc §4.3/4.5). No-op si jamais envoyée. */
function garmin_reschedule_workout(int $planId, string $newDate): void {
    $pushed = json_decode((string)cfg('garmin_pushed', '{}'), true) ?: [];
    $info = $pushed[$planId] ?? [];
    $gid = (string)($info['gid'] ?? '');
    if ($gid === '') return;
    $sid = (string)($info['sid'] ?? '');
    if ($sid !== '') {
        try {
            garmin_api('PUT', '/training-api/schedule/' . rawurlencode($sid), ['scheduleId' => (int)$sid, 'workoutId' => (int)$gid, 'date' => $newDate]);
        } catch (Throwable $e) { $sid = ''; }
    }
    if ($sid === '') {
        $sch = garmin_api('POST', '/training-api/schedule/', ['workoutId' => (int)$gid, 'date' => $newDate]);
        $sid = (string)($sch['scheduleId'] ?? '');
    }
    $pushed[$planId] = array_filter(['gid' => $gid, 'sid' => $sid, 'date' => $newDate, 'name' => (string)($info['name'] ?? ''), 'ts' => time()], fn ($v) => $v !== '' && $v !== null);
    cfg_set('garmin_pushed', json_encode($pushed, JSON_UNESCAPED_UNICODE));
}

/** Retire la séance du calendrier + bibliothèque Garmin (supprimée côté TRQ) — erreurs ignorées (best effort). */
function garmin_delete_workout(int $planId): void {
    $pushed = json_decode((string)cfg('garmin_pushed', '{}'), true) ?: [];
    $info = $pushed[$planId] ?? [];
    $sid = (string)($info['sid'] ?? '');
    $gid = (string)($info['gid'] ?? '');
    try { if ($sid !== '') garmin_api('DELETE', '/training-api/schedule/' . rawurlencode($sid)); } catch (Throwable $e) { /* déjà absente */ }
    try { if ($gid !== '') garmin_api('DELETE', '/training-api/workout/v2/' . rawurlencode($gid)); } catch (Throwable $e) { /* déjà absente */ }
    unset($pushed[$planId]);
    cfg_set('garmin_pushed', json_encode($pushed, JSON_UNESCAPED_UNICODE));
}

/* ================================================================================
   WEBHOOKS PUSH (Health + Activity API) — import des données poussées par Garmin
   ================================================================================ */

/** Ouvre une base SQLite à partir de son chemin (réplique minimale de db() — sans session). */
function garmin_open_db(string $path): ?PDO {
    if (!is_file($path)) return null;
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=WAL');
        return $pdo;
    } catch (Throwable $e) { return null; }
}

/** Écrit un réglage directement dans la base passée (sans cfg()/session — webhooks). */
function garmin_cfg_set(PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO settings(k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/** Trouve la base utilisateur possédant ce userId Garmin (multi-utilisateurs). */
function garmin_find_db(string $guserId): ?PDO {
    $paths = glob(data_dir() . '/users/u*.sqlite') ?: [];
    $mono = data_dir() . '/app.sqlite';
    if (is_file($mono)) $paths[] = $mono;
    foreach (array_slice($paths, 0, 200) as $p) {
        $pdo = garmin_open_db($p);
        if (!$pdo) continue;
        try {
            $st = $pdo->prepare("SELECT v FROM settings WHERE k='garmin_user_id'");
            $st->execute();
            if ((string)$st->fetchColumn() === $guserId) return $pdo;
        } catch (Throwable $e) {}
        $pdo = null;
    }
    return null;
}

/** Répartit une liste de résumés push (activities / dailies / sleeps / hrvs…) dans la base. */
function garmin_ingest(PDO $pdo, array $items, string $type, array &$res): void {
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $sid = (string)($it['summaryId'] ?? '');
        if ($sid === '') continue;
        try {
            if ($type === 'activity') garmin_ingest_activity($pdo, $it, $sid, $res);
            else garmin_ingest_wellness($pdo, $it, $type, $res);
        } catch (Throwable $e) { $res['errors'][] = $e->getMessage(); }
    }
}

function garmin_sport_from_type(string $garminType): string {
    $t = strtolower($garminType);
    if (in_array($t, ['running', 'trail_running', 'treadmill_running', 'indoor_running'], true)) return 'run';
    if (in_array($t, ['swimming', 'lap_swimming', 'open_water_swimming'], true)) return 'swim';
    if (in_array($t, ['cycling', 'road_biking', 'mountain_biking', 'indoor_cycling', 'gravel_cycling', 'cyclocross', 'virtual_ride', 'virtual_indoor_cycling', 'bmx', 'commuting'], true)) return 'ride';
    return 'other';
}

function garmin_ingest_activity(PDO $pdo, array $it, string $sid, array &$res): void {
    $start = (int)($it['startTimeInSeconds'] ?? 0);
    if ($start <= 0) return;
    $gsport = (string)($it['activityType'] ?? '');
    /* Anti-doublon : la même sortie est peut-être déjà là, importée d'intervals.icu (plus riche : streams, TSS)
       ou d'un push Garmin précédent → jour + sport + durée proche (±20 % ou ±2 min) = déjà enregistrée, on saute. */
    $gDur = (int)($it['durationInSeconds'] ?? ($it['movingDurationInSeconds'] ?? 0));
    try {
        $chk = $pdo->prepare("SELECT id, duration FROM activities WHERE substr(date,1,10) = ? AND sport = ? AND id <> ?");
        $chk->execute([date('Y-m-d', $start), garmin_sport_from_type($gsport), 'g-' . $sid]);
        foreach ($chk->fetchAll() as $ex) {
            $ed = (int)$ex['duration'];
            $tol = max(120, (int)round(0.2 * max($gDur, $ed)));
            if ($ed > 0 && $gDur > 0 && abs($ed - $gDur) <= $tol) { $res['skipped']++; return; }   // déjà présente → pas d'import
        }
    } catch (Throwable $e) {}
    $fields = [
        'id'        => 'g-' . $sid,
        'date'      => gmdate('Y-m-d\TH:i:s\Z', $start),
        'type'      => $gsport !== '' ? $gsport : 'workout',
        'sport'     => garmin_sport_from_type($gsport),
        'name'      => ((string)($it['activityName'] ?? '') ?: 'Séance Garmin'),
        'duration'  => max(1, (int)($it['durationInSeconds'] ?? ($it['movingDurationInSeconds'] ?? 0))),
        'distance'  => (float)($it['distanceInMeters'] ?? 0),
        'elev'      => (float)($it['elevationGainInMeters'] ?? 0),
        'avg_hr'    => (float)($it['averageHeartRateInBeatsPerMinute'] ?? 0),
        'max_hr'    => (float)($it['maxHeartRateInBeatsPerMinute'] ?? 0),
        'avg_w'     => (float)($it['averageBikePowerInWatts'] ?? ($it['averagePowerInWatts'] ?? 0)),
        'avg_speed' => (float)($it['averageSpeedInMetersPerSecond'] ?? 0),
        'synced'    => 2,   /* 2 = importé depuis Garmin (webhooks push) */
    ];
    $cols = implode(', ', array_keys($fields));
    $ph = implode(',', array_fill(0, count($fields), '?'));
    $sets = [];
    foreach (array_keys($fields) as $k) if ($k !== 'id') $sets[] = "$k=excluded.$k";
    $st = $pdo->prepare("INSERT INTO activities ($cols) VALUES ($ph) ON CONFLICT(id) DO UPDATE SET " . implode(', ', $sets));
    $st->execute(array_values($fields));
    $res['activities']++;
}

/** Met à jour la ligne wellness d'un jour en ne touchant QUE les champs fournis (fusion avec l'existant). */
function garmin_ingest_wellness(PDO $pdo, array $it, string $type, array &$res): void {
    $day = null;
    if (!empty($it['calendarDate'])) $day = substr((string)$it['calendarDate'], 0, 10);
    if ($day === null && !empty($it['sleepStartTimestampInSeconds'])) $day = date('Y-m-d', (int)$it['sleepStartTimestampInSeconds'] + (int)($it['durationInSeconds'] ?? 0));
    if ($day === null && !empty($it['summaryId']) && preg_match('/\d{4}-\d{2}-\d{2}/', (string)$it['summaryId'])) $day = substr((string)$it['summaryId'], 0, 10);
    if ($day === null) return;

    $cur = $pdo->prepare('SELECT hrv, resting_hr, sleep_sec, sleep_score, sleep_quality, weight FROM wellness WHERE date = ?');
    $cur->execute([$day]);
    $row = $cur->fetch() ?: [];

    if ($type === 'daily') {   /* Daily Summary : FC repos, pas, kcal… */
        $rhr = !empty($it['restingHeartRateInBeatsPerMinute']) ? (float)$it['restingHeartRateInBeatsPerMinute'] : (!empty($it['restingHeartRateInBeatsPerMinuteFloat']) ? (float)$it['restingHeartRateInBeatsPerMinuteFloat'] : null);
        if ($rhr !== null) $row['resting_hr'] = $rhr;
    }
    if ($type === 'sleep') {
        $sec = (int)($it['durationInSeconds'] ?? 0) ?: null;
        if ($sec === null) $sec = (int)(($it['awakeSleepDurationInSeconds'] ?? 0) + ($it['deepSleepDurationInSeconds'] ?? 0) + ($it['lightSleepDurationInSeconds'] ?? 0) + ($it['remSleepDurationInSeconds'] ?? 0)) ?: null;
        $score = isset($it['overallSleepScore']['value']) ? (float)$it['overallSleepScore']['value'] : (isset($it['sleepScore']['value']) ? (float)$it['sleepScore']['value'] : null);
        if ($sec) $row['sleep_sec'] = (int)$sec;
        if ($score !== null) $row['sleep_score'] = $score;
    }
    if ($type === 'hrv') {     /* HRV Status : moyenne rMSSD de la nuit */
        $v = $it['hrvSummary']['lastNightAvg'] ?? ($it['lastNightAvg'] ?? null);
        if ($v === null && !empty($it['dailyRmssd']['value'])) $v = (float)$it['dailyRmssd']['value'];
        if ($v !== null) $row['hrv'] = (float)$v;
    }
    if (in_array($type, ['weight', 'usermetrics'], true)) {
        if (!empty($it['weightInGrams'])) $row['weight'] = (float)$it['weightInGrams'] / 1000;
        if (!empty($it['weightInKilograms'])) $row['weight'] = (float)$it['weightInKilograms'];
    }

    $row['date'] = $day;
    $row['synced'] = 1;
    $row['readiness'] = compute_readiness_score(
        isset($row['hrv']) ? (float)$row['hrv'] : null,
        isset($row['resting_hr']) ? (float)$row['resting_hr'] : null,
        isset($row['sleep_sec']) ? (int)$row['sleep_sec'] : null,
        isset($row['sleep_score']) ? (float)$row['sleep_score'] : null,
        null, null);
    $cols = ['date', 'hrv', 'resting_hr', 'sleep_sec', 'sleep_score', 'sleep_quality', 'weight', 'readiness', 'synced'];
    $vals = [];
    foreach ($cols as $c) $vals[] = $row[$c] ?? null;
    $sets = implode(', ', array_map(fn ($c) => "$c = excluded.$c", array_slice($cols, 1)));
    $st = $pdo->prepare('INSERT INTO wellness (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ")
        ON CONFLICT(date) DO UPDATE SET $sets");
    $st->execute($vals);
    $res['wellness']++;
}

/** Trouve la base (mono ou multi-utilisateurs) dont le token webhook correspond — authentification du push Garmin sans session. */
function garmin_db_for_webhook_token(string $tok): ?PDO {
    if ($tok === '') return null;
    $paths = glob(data_dir() . '/users/u*.sqlite') ?: [];
    $mono = data_dir() . '/app.sqlite';
    if (is_file($mono)) $paths[] = $mono;
    foreach (array_slice($paths, 0, 200) as $p) {
        $pdo = garmin_open_db($p);
        if (!$pdo) continue;
        try {
            $st = $pdo->prepare("SELECT v FROM settings WHERE k='garmin_webhook_token'");
            $st->execute();
            if ((string)$st->fetchColumn() === $tok) return $pdo;
        } catch (Throwable $e) {}
        $pdo = null;
    }
    return null;
}

/** Traite le corps brut d'un webhook push Garmin — $pdo = base résolue par token (aucun cfg()/session). */
function garmin_webhook_process(PDO $pdo, string $raw): array {
    $j = json_decode($raw, true);
    if (!is_array($j)) return ['ignored' => 'payload non JSON'];

    /* Regrouper par type selon les clés connues du format push Garmin */
    $lists = [
        'activity'    => $j['activities'] ?? null,
        'daily'       => $j['dailies'] ?? null,
        'sleep'       => $j['sleeps'] ?? null,
        'hrv'         => $j['hrvs'] ?? ($j['hrv'] ?? null),
        'usermetrics' => $j['usermetrics'] ?? null,
    ];
    /* Certains types arrivent en tableau plat avec un champ summaryType */
    if (!array_filter($lists) && isset($j[0]) && is_array($j[0])) {
        $map = ['ACTIVITY_SUMMARY' => 'activity', 'DAILY_SUMMARY' => 'daily', 'SLEEP_SUMMARY' => 'sleep', 'HRV_SUMMARY' => 'hrv', 'USERMETRICS_SUMMARY' => 'usermetrics', 'BODYCOMPOSITION_SUMMARY' => 'usermetrics'];
        foreach ($j as $it) {
            if (!is_array($it)) continue;
            $k = $map[(string)($it['summaryType'] ?? '')] ?? null;
            if ($k === null) continue;
            if (!is_array($lists[$k])) $lists[$k] = [$it]; else $lists[$k][] = $it;
        }
    }
    if (!array_filter($lists)) return ['ignored' => 'aucun type connu (clés : ' . implode(', ', array_slice(array_keys($j), 0, 6)) . ')'];

    $uid = (string)($j['userId'] ?? '');
    /* Routage : base résolue par token, sauf si le payload désigne un autre utilisateur Garmin */
    if ($uid !== '') {
        try { $st = $pdo->prepare("SELECT v FROM settings WHERE k='garmin_user_id'"); $st->execute(); $curUid = (string)$st->fetchColumn(); } catch (Throwable $e) { $curUid = ''; }
        if ($curUid !== $uid) $pdo = garmin_find_db($uid) ?: $pdo;
    }

    $res = ['activities' => 0, 'wellness' => 0, 'errors' => []];
    foreach ($lists as $type => $items) {
        if (!is_array($items)) continue;
        garmin_ingest($pdo, $items, $type, $res);
    }
    try { garmin_cfg_set($pdo, 'garmin_webhook_last', json_encode(['when' => time(), 'res' => $res], JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {}
    return $res;
}

/* ---------------- HTTP ---------------- */
function garmin_http_excerpt(array $resp): string {
    foreach (['error_description', 'error', 'message'] as $k) if (!empty($resp[$k])) return (string)$resp[$k];
    return substr(json_encode($resp, JSON_UNESCAPED_UNICODE) ?: '', 0, 120);
}

/** POST form-urlencoded minimal (OAuth). */
function garmin_http(string $url, array $params): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 0) throw new RuntimeException('Garmin : service injoignable (réseau/proxy).');
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return ['_http' => $code, '_raw' => substr((string)$body, 0, 500)];
    if ($code >= 400) return $j + ['_http' => $code];
    return $j;
}

/** HTTP générique avec token Bearer — renvoie le JSON décodé, lève une exception avec extrait du corps si erreur. */
function garmin_http_raw(string $method, string $url, ?string $jsonBody, array $headers, int $timeout = 25) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_values(array_filter($headers)),
    ];
    if ($jsonBody !== null) $opts[CURLOPT_POSTFIELDS] = $jsonBody;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 0) throw new RuntimeException('Garmin : service injoignable (réseau/proxy).');
    if ($code >= 400) throw new RuntimeException('Garmin (HTTP ' . $code . ') : ' . substr(strip_tags((string)$body), 0, 300));
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : [];
}
