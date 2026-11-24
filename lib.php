<?php
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/diagnostic.php';
require_once __DIR__ . '/plan_compare.php';
require_once __DIR__ . '/nutrition_lib.php';
require_once __DIR__ . '/progress_lib.php';

const DB_FILE = __DIR__ . '/data/app.sqlite';
const MMP_DURS = [1, 5, 10, 15, 30, 60, 120, 180, 300, 600, 1200, 1800, 3600, 5400, 7200];
const RIDE_TYPES = ['Ride', 'VirtualRide', 'MountainBikeRide', 'GravelRide', 'EBikeRide', 'Handcycle'];
const RUN_TYPES = ['Run', 'TrailRun', 'VirtualRun'];
const SWIM_TYPES = ['Swim'];
/** Colonnes d'une activité SANS le flux binaire (évite de charger des Mo par ligne dans les listes). */
const ACT_COLS = 'id,date,type,sport,name,duration,distance,elev,avg_hr,max_hr,avg_speed,avg_w,np,if_,tss,tss_type,vi,ef,decoupling,kj,cadence,icu_load,mmp,zones,hr_zones,intervals,wko_metrics,laps,synced';

/**
 * Gestion d'erreurs : plus jamais de page blanche.
 * - tout est bufferisé, donc une erreur fatale (mémoire, timeout, exception) remplace la page partielle ;
 * - le détail est affiché à l'écran ET écrit dans data/php-error.log.
 */
function bootstrap_errors(bool $json = false): void {
    @mkdir(__DIR__ . '/data', 0775, true);
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    @ini_set('error_log', __DIR__ . '/data/php-error.log');
    @ini_set('memory_limit', '512M');
    error_reporting(E_ALL);
    ob_start();
    $fail = function (string $msg, string $where) use ($json): void {
        while (ob_get_level() > 0) ob_end_clean();
        @file_put_contents(__DIR__ . '/data/php-error.log', date('c') . " $msg @ $where\n", FILE_APPEND);
        if (!headers_sent()) http_response_code(500);
        if ($json) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg . ' (' . basename(explode(':', $where)[0]) . ':' . (explode(':', $where)[1] ?? '?') . ')']);
            return;
        }
        $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); $w = htmlspecialchars($where, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Erreur · TRQ</title>'
           . '<style>:root{color-scheme:light dark}body{font:15px/1.5 system-ui,sans-serif;max-width:46rem;margin:12vh auto;padding:0 1rem}'
           . '.c{border:1px solid #8884;border-radius:12px;padding:1.5rem}h1{font-size:1.15rem;margin:0 0 .5rem}pre{white-space:pre-wrap;background:#8881;padding:.75rem;border-radius:8px;font-size:13px}</style>'
           . '<div class="c"><h1>⚠️ TRQ a rencontré une erreur</h1><pre>' . $m . "\n\n" . $w . '</pre>'
           . '<p>Détail aussi dans <code>data/php-error.log</code>. <a href="?p=dashboard">Retour au tableau de bord</a></p></div>';
    };
    set_exception_handler(fn(Throwable $e) => $fail(get_class($e) . ': ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine()));
    register_shutdown_function(function () use ($fail): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $fail($e['message'], $e['file'] . ':' . $e['line']);
        }
    });
}

function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function db(): PDO {
    static $pdos = [];
    $path = db_path();                      // une base par utilisateur (voir auth_lib.php)
    if (!isset($pdos[$path])) {
        ensure_data_dir();
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
        
        CREATE TABLE IF NOT EXISTS activities(
            id TEXT PRIMARY KEY, date TEXT, type TEXT, sport TEXT, name TEXT,
            duration INTEGER, distance REAL, elev REAL, avg_hr REAL, max_hr REAL, avg_speed REAL,
            avg_w REAL, np REAL, if_ REAL, tss REAL, tss_type TEXT DEFAULT 'power',
            vi REAL, ef REAL, decoupling REAL, kj REAL, cadence REAL,
            icu_load REAL, mmp TEXT, zones TEXT, hr_zones TEXT, intervals TEXT,
            wko_metrics TEXT, laps TEXT, custom_laps TEXT, streams BLOB, synced INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_act_date ON activities(date);
        CREATE INDEX IF NOT EXISTS idx_act_sport ON activities(sport);

        CREATE TABLE IF NOT EXISTS wellness(
            date TEXT PRIMARY KEY,
            hrv REAL,
            resting_hr REAL,
            sleep_sec INTEGER,
            sleep_score REAL,
            sleep_quality INTEGER,
            weight REAL,
            fatigue INTEGER,
            soreness INTEGER,
            stress INTEGER,
            mood INTEGER,
            readiness REAL,
            comments TEXT,
            synced INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_well_date ON wellness(date);

        CREATE TABLE IF NOT EXISTS planned_workouts(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT,
            sport TEXT,
            title TEXT,
            duration INTEGER,
            distance REAL,
            tss REAL,
            tss_type TEXT DEFAULT 'power',
            notes TEXT,
            completed INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_plan_date ON planned_workouts(date);

        CREATE TABLE IF NOT EXISTS workouts(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, sport TEXT DEFAULT 'ride', mode TEXT DEFAULT 'power', unit TEXT DEFAULT 'pct',
            structure TEXT, profile TEXT, duration INTEGER DEFAULT 0, tss REAL DEFAULT 0, if_ REAL,
            notes TEXT, created_at TEXT, updated_at TEXT
        );

        CREATE TABLE IF NOT EXISTS notifications(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER,
            type TEXT,
            ref TEXT,
            level TEXT DEFAULT 'info',
            title TEXT,
            body TEXT,
            link TEXT,
            read_at INTEGER
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_notif_ref ON notifications(type, ref);
        CREATE INDEX IF NOT EXISTS idx_notif_ts ON notifications(ts);
        ");

        // Assurer la présence des colonnes optionnelles si table préexistante
        $cols = array_column($pdo->query("PRAGMA table_info(activities)")->fetchAll(), 'name');
        if (!in_array('tss_type', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN tss_type TEXT DEFAULT 'power'");
        if (!in_array('hr_zones', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN hr_zones TEXT");
        if (!in_array('intervals', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN intervals TEXT");
        if (!in_array('cadence', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN cadence REAL");
        if (!in_array('wko_metrics', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN wko_metrics TEXT");
        if (!in_array('laps', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN laps TEXT");
        if (!in_array('custom_laps', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN custom_laps TEXT");
        if (!in_array('rpe', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN rpe INTEGER");
        if (!in_array('feel', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN feel TEXT");
        if (!in_array('name_custom', $cols, true)) $pdo->exec("ALTER TABLE activities ADD COLUMN name_custom INTEGER DEFAULT 0");
        $pcols = array_column($pdo->query("PRAGMA table_info(planned_workouts)")->fetchAll(), 'name');
        foreach (['structure' => 'TEXT', 'profile' => 'TEXT', 'workout_id' => 'INTEGER', 'icu_event_id' => 'TEXT', 'icu_text' => 'TEXT', 'activity_id' => 'TEXT', 'cmp_key' => 'TEXT', 'cmp_json' => 'TEXT'] as $c => $t)
            if (!in_array($c, $pcols, true)) $pdo->exec("ALTER TABLE planned_workouts ADD COLUMN $c $t");
        $pdos[$path] = $pdo;
    }
    return $pdos[$path];
}

function secret_key(): string {
    static $k;
    if ($k) return $k;
    ensure_data_dir(); $f = data_dir() . '/app.key';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
    return $k = hex2bin(trim((string)file_get_contents($f)));
}
function enc_secret(string $v): string {
    if ($v === '' || !function_exists('openssl_encrypt')) return $v;
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($v, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $c === false ? $v : 'enc1:' . base64_encode($iv . $tag . $c);
}
function dec_secret(string $v): string {
    if (strncmp($v, 'enc1:', 5) !== 0) return $v;                       // valeur ancienne en clair : lisible, rechiffrée au prochain enregistrement
    $raw = base64_decode(substr($v, 5), true); if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

function cfg(string $k, $default = null) {
    $st = db()->prepare('SELECT v FROM settings WHERE k=?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    if ($k === 'api_key' && is_string($v)) $v = dec_secret($v);
    return ($v === false || $v === '') ? $default : $v;
}

function cfg_set(string $k, string $v): void {
    if ($k === 'api_key') $v = enc_secret($v);
    db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

/* ---------- photo de profil (BLOB dans auth.sqlite, servie par ?p=avatar) ---------- */

/** Infos (mime, horodatage) de la photo de l'utilisateur connecté — null si aucune. */
function avatar_info(): ?array {
    static $r = false;
    if ($r === false) {
        $r = null;
        try {
            if (auth_enabled() && current_uid() > 0) {
                $st = auth_db()->prepare('SELECT avatar_mime, avatar_ts FROM users WHERE id=?');
                $st->execute([current_uid()]);
                $row = $st->fetch();
                if ($row && $row['avatar_ts']) $r = ['mime' => (string)$row['avatar_mime'], 'ts' => (int)$row['avatar_ts']];
            }
        } catch (\Throwable $e) { $r = null; }
    }
    return $r;
}

/** Valide et retaille l'image envoyée (carré 256px si GD dispo), renvoie [bytes, mime] ou lance RuntimeException. */
function avatar_process(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Envoi du fichier impossible (trop gros ou requête interrompue).');
    if ($f['size'] > 4194304) throw new RuntimeException('Image trop lourde (4 Mo max).');
    $bin = @file_get_contents($f['tmp_name']);
    $info = $bin === false ? null : @getimagesizefromstring($bin);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException('Fichier non reconnu comme une image (JPEG, PNG, WebP ou GIF).');
    $mime = $info['mime'];
    if (!function_exists('imagecreatetruecolor') || $mime === 'image/gif') return [$bin, $mime];   // GIF animé ou absent de GD : original conservé
    $src = @imagecreatefromstring($bin);
    if (!$src) return [$bin, $mime];
    $w = imagesx($src); $h = imagesy($src);
    if ($w === 256 && $h === 256) { imagedestroy($src); return [$bin, $mime]; }
    $side = min($w, $h); $sx = (int)floor(($w - $side) / 2); $sy = (int)floor(($h - $side) / 2);
    $dst = imagecreatetruecolor(256, 256);
    if (in_array($mime, ['image/png', 'image/webp'], true)) {
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, 256, 256, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 256, 256, $side, $side);
    imagedestroy($src);
    ob_start();
    if ($mime === 'image/png') imagepng($dst, null, 6);
    elseif ($mime === 'image/webp' && function_exists('imagewebp')) imagewebp($dst, null, 88);
    else { imagejpeg($dst, null, 86); $mime = 'image/jpeg'; }
    imagedestroy($dst);
    $out = (string)ob_get_clean();
    if ($out === '') return [$bin, $info['mime']];
    return [$out, $mime];
}

/* ---------- intervals.icu API ---------- */

/** Handle cURL configuré : bundle de certificats, contournement SSL optionnel (antivirus / proxy qui inspectent le HTTPS). */
function icu_curl(string $url, array $extra = []) {
    $key = cfg('api_key');
    if (!$key) throw new RuntimeException("Clé API intervals.icu manquante (Paramètres).");
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERPWD => 'API_KEY:' . $key,
             CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4] + $extra;
    if (cfg('ssl_verify', '1') === '0') {
        $opts[CURLOPT_SSL_VERIFYPEER] = false; $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    } else {
        $ca = (string)cfg('ca_bundle', '');
        if ($ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;                                   // bundle choisi par l'utilisateur
        elseif (!ini_get('curl.cainfo') && !ini_get('openssl.cafile') && is_file(__DIR__ . '/assets/cacert.pem')) $opts[CURLOPT_CAINFO] = __DIR__ . '/assets/cacert.pem';  // WAMP sans bundle configuré
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    return $ch;
}

/** Transforme une erreur cURL / HTTP en message compréhensible. */
function icu_error($ch, $resp, string $what): string {
    $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
        $msg = curl_error($ch);
        if (in_array($errno, [51, 58, 59, 60, 77, 83], true) || stripos($msg, 'certificate') !== false)
            return "intervals.icu : certificat SSL non reconnu ($msg). Un antivirus ou un proxy qui inspecte le HTTPS en est la cause la plus probable. "
                 . "Solution : Paramètres → « Connexion sécurisée (SSL) » (bundle de certificats ou désactivation de la vérification). ($what)";
        if ($errno === 35) return "intervals.icu : échec de la connexion sécurisée ($msg). Vérifiez votre connexion Internet, un pare-feu ou un antivirus ; voir aussi Paramètres → « Connexion sécurisée (SSL) ». ($what)";
        if ($errno === 6) return "intervals.icu : nom de domaine introuvable ($msg). Votre machine n'arrive pas à résoudre les noms DNS : vérifiez votre connexion Internet, le serveur DNS (essai possible avec 1.1.1.1/8.8.8.8), ou un VPN/proxy d'entreprise qui bloquerait intervals.icu. ($what)";
        if ($errno === 7) return "intervals.icu : connexion refusée ($msg). Un pare-feu, un antivirus ou le réseau bloque la sortie HTTPS. ($what)";
        return "intervals.icu : $msg ($what)";
    }
    if ($code === 401 || $code === 403) return "intervals.icu : clé API refusée (HTTP $code). Vérifiez la clé et l'ID athlète dans Paramètres. ($what)";
    if ($code === 404) return "intervals.icu : ressource introuvable (HTTP 404). Vérifiez l'ID athlète. ($what)";
    return "intervals.icu : HTTP $code " . mb_substr((string)$resp, 0, 200) . " ($what)";
}

function icu_get(string $path, array $params = []) {
    $url = 'https://intervals.icu/api/v1' . $path . ($params ? '?' . http_build_query($params) : '');
    $ch = icu_curl($url, [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code >= 400) throw new RuntimeException(icu_error($ch, $body, "GET $path"));
    return json_decode($body, true);
}

/** Requête JSON (POST/PUT/DELETE) vers intervals.icu. */
function icu_send(string $method, string $path, array $body = [], array $params = []) {
    $url = 'https://intervals.icu/api/v1' . $path . ($params ? '?' . http_build_query($params) : '');
    $ch = icu_curl($url, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code >= 400) throw new RuntimeException(icu_error($ch, $resp, "$method $path"));
    return json_decode((string)$resp, true);
}

function athlete_id(): string {
    /* Tolère les ID copiés depuis l'URL d'intervals.icu (ex : « i27729 ») : on ne garde que les chiffres. */
    return preg_replace('/\D+/', '', (string)cfg('athlete_id', '0')) ?: '0';
}

function sport_of(string $type): string {
    if (in_array($type, RIDE_TYPES, true)) return 'ride';
    if (in_array($type, RUN_TYPES, true)) return 'run';
    if (in_array($type, SWIM_TYPES, true)) return 'swim';
    return 'other';
}

function import_athlete_settings(): void {
    $a = icu_get('/athlete/' . athlete_id());
    foreach (($a['sportSettings'] ?? []) as $s) {
        $types = $s['types'] ?? [];
        if (array_intersect($types, RIDE_TYPES)) {
            if (!empty($s['ftp'])) cfg_set('ftp', (string)$s['ftp']);
            if (!empty($s['lthr'])) cfg_set('lthr', (string)$s['lthr']);
            if (!empty($s['max_hr'])) cfg_set('max_hr', (string)$s['max_hr']);
        }
        if (array_intersect($types, RUN_TYPES) && !empty($s['threshold_pace'])) {
            cfg_set('run_thr_pace', (string)round(1000 / (float)$s['threshold_pace']));
        }
    }
    if (!empty($a['icu_weight'])) cfg_set('weight', (string)$a['icu_weight']);
    if (!empty($a['icu_resting_hr'])) cfg_set('rest_hr', (string)$a['icu_resting_hr']);
}

/* ---------- Synchronisation Wellness ---------- */
function sync_wellness_icu(int $days = 90): int {
    $oldest = date('Y-m-d', strtotime("-$days days"));
    $list = icu_get('/athlete/' . athlete_id() . '/wellness', ['oldest' => $oldest, 'newest' => date('Y-m-d')]);
    if (!is_array($list)) return 0;
    
    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO wellness
        (date, hrv, resting_hr, sleep_sec, sleep_score, sleep_quality, weight, fatigue, soreness, stress, mood, readiness, comments, synced)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ON CONFLICT(date) DO UPDATE SET
            hrv=excluded.hrv, resting_hr=excluded.resting_hr, sleep_sec=excluded.sleep_sec,
            sleep_score=excluded.sleep_score, sleep_quality=excluded.sleep_quality,
            weight=excluded.weight, fatigue=excluded.fatigue, soreness=excluded.soreness,
            stress=excluded.stress, mood=excluded.mood, readiness=excluded.readiness,
            comments=excluded.comments, synced=1');

    $cnt = 0;
    $pdo->beginTransaction();
    foreach ($list as $w) {
        if (empty($w['id'])) continue;
        $d = $w['id'];
        $hrv = !empty($w['hrv']) ? (float)$w['hrv'] : (!empty($w['hrvSDNN']) ? (float)$w['hrvSDNN'] : null);
        $rhr = !empty($w['restingHR']) ? (float)$w['restingHR'] : null;
        $sleepSec = !empty($w['sleepSecs']) ? (int)$w['sleepSecs'] : null;
        $sleepScore = !empty($w['sleepScore']) ? (float)$w['sleepScore'] : null;
        $sleepQual = !empty($w['sleepQuality']) ? (int)$w['sleepQuality'] : null;
        $weight = !empty($w['weight']) ? (float)$w['weight'] : null;
        $fatigue = !empty($w['fatigue']) ? (int)$w['fatigue'] : null;
        $soreness = !empty($w['soreness']) ? (int)$w['soreness'] : null;
        $stress = !empty($w['stress']) ? (int)$w['stress'] : null;
        $mood = !empty($w['mood']) ? (int)$w['mood'] : null;
        $comments = $w['comments'] ?? null;
        
        $readiness = compute_readiness_score($hrv, $rhr, $sleepSec, $sleepScore, $fatigue, $stress);
        $ins->execute([$d, $hrv, $rhr, $sleepSec, $sleepScore, $sleepQual, $weight, $fatigue, $soreness, $stress, $mood, $readiness, $comments]);
        $cnt++;
    }
    $pdo->commit();
    return $cnt;
}

function compute_readiness_score(?float $hrv, ?float $rhr, ?int $sleepSec, ?float $sleepScore, ?int $fatigue, ?int $stress): float {
    $score = 80.0;
    $baseHrv = (float)cfg('base_hrv', 65);
    $baseRhr = (float)cfg('rest_hr', 50);

    if ($hrv !== null && $baseHrv > 0) {
        $diff = ($hrv - $baseHrv) / $baseHrv;
        $score += max(-30, min(15, $diff * 50));
    }
    if ($rhr !== null && $baseRhr > 0) {
        $diffRhr = $rhr - $baseRhr;
        if ($diffRhr > 2) $score -= min(25, ($diffRhr - 2) * 4);
        elseif ($diffRhr < 0) $score += min(5, abs($diffRhr) * 1.5);
    }
    if ($sleepSec !== null) {
        $hours = $sleepSec / 3600;
        if ($hours < 6.5) $score -= (6.5 - $hours) * 8;
        elseif ($hours >= 7.5) $score += 5;
    }
    if ($sleepScore !== null) {
        $score += ($sleepScore - 75) * 0.2;
    }
    if ($fatigue !== null && $fatigue >= 3) $score -= ($fatigue - 2) * 5;
    if ($stress !== null && $stress >= 3) $score -= ($stress - 2) * 4;

    return round(max(10, min(100, $score)), 1);
}

/* ---------- Métriques & Traitement Flux ---------- */
function clean(array $a): array { return array_map(fn($x) => $x === null ? 0.0 : (float)$x, $a); }

function normalized_power(array $p): float {
    $n = count($p);
    if ($n === 0) return 0.0;
    if ($n < 30) return array_sum($p) / $n;
    $win = 0.0; $sum4 = 0.0; $cnt = 0;
    for ($i = 0; $i < $n; $i++) {
        $win += $p[$i];
        if ($i >= 30) $win -= $p[$i - 30];
        if ($i >= 29) { $sum4 += ($win / 30) ** 4; $cnt++; }
    }
    return $cnt > 0 ? ($sum4 / $cnt) ** 0.25 : array_sum($p) / $n;
}

/** Meilleure moyenne glissante pour chaque durée (flux à 1 Hz). */
function best_means(array $x, array $durs = MMP_DURS): array {
    $n = count($x); $S = [0.0];
    foreach ($x as $v) $S[] = end($S) + $v;
    $out = [];
    foreach ($durs as $d) {
        if ($d > $n) continue;
        $best = 0.0;
        for ($i = 0; $i + $d <= $n; $i++) { $m = $S[$i + $d] - $S[$i]; if ($m > $best) $best = $m; }
        $out[$d] = round($best / $d, 2);
    }
    return $out;
}

/** Modèle CP/W' (2 paramètres) : travail = CP*t + W', régression linéaire 3–20 min. */
function cp_model(array $curve): ?array {
    $pts = [];
    foreach ([180, 300, 600, 1200] as $t) {
        if (!empty($curve[$t]) && $curve[$t] > 0) $pts[] = [$t, $curve[$t] * $t];
    }
    if (count($pts) < 3) return null;
    $n = count($pts);
    $sx = array_sum(array_column($pts, 0)); $sy = array_sum(array_column($pts, 1));
    $sxy = 0; $sxx = 0;
    foreach ($pts as [$x, $y]) { $sxy += $x * $y; $sxx += $x * $x; }
    $den = $n * $sxx - $sx * $sx;
    if ($den == 0) return null;
    $cp = ($n * $sxy - $sx * $sy) / $den;
    $wp = ($sy - $cp * $sx) / $n;
    if ($cp <= 0) return null;
    
    $pmax = $curve[5] ?? ($cp * 3.5);
    $eftp = isset($curve[1200]) ? round($curve[1200] * 0.95, 1) : round($cp, 1);
    
    return [
        'cp' => round($cp, 1),
        'wp' => round(max($wp, 0), 1),
        'pmax' => round($pmax, 1),
        'eftp' => $eftp,
    ];
}

function coggan_zones(array $p, float $ftp): array {
    $b = [0.55, 0.75, 0.90, 1.05, 1.20, 1.50]; $z = array_fill(0, 7, 0);
    foreach ($p as $w) {
        $r = $w / max(1.0, $ftp); $i = 0;
        while ($i < 6 && $r > $b[$i]) $i++;
        $z[$i]++;
    }
    return $z;
}

function hr_zones(array $hr, float $lthr): array {
    $b = [0.81, 0.89, 0.93, 0.99]; $z = array_fill(0, 5, 0);
    foreach ($hr as $h) {
        if ($h <= 0) continue;
        $r = $h / max(1.0, $lthr); $i = 0;
        while ($i < 4 && $r > $b[$i]) $i++;
        $z[$i]++;
    }
    return $z;
}

/**
 * Calcule les histogrammes de temps passé (en secondes) par plage de valeurs
 * pour Puissance, Couple (N.m), FC (bpm), Vitesse (km/h) et Cadence (rpm).
 */
function peak_histograms(array $S, float $ftp = 250): array {
    $w = !empty($S['watts']) ? clean($S['watts']) : [];
    $hr = !empty($S['heartrate']) ? clean($S['heartrate']) : [];
    $v = !empty($S['velocity_smooth']) ? array_map(fn($x) => round($x * 3.6, 2), clean($S['velocity_smooth'])) : [];
    $cad = !empty($S['cadence']) ? clean($S['cadence']) : [];
    $grade = !empty($S['grade']) ? array_map(fn($x) => round((float)$x, 1), clean($S['grade'])) : [];

    // Couple (N.m) : flux 'torque' si présent, sinon calculé P / (2 * pi * rpm / 60)
    $torque = [];
    if (!empty($S['torque'])) {
        $torque = clean($S['torque']);
    } elseif ($w && $cad) {
        $n = min(count($w), count($cad));
        for ($i = 0; $i < $n; $i++) {
            if ($w[$i] > 0 && $cad[$i] > 15) {
                $torque[] = round($w[$i] / (2 * M_PI * ($cad[$i] / 60)), 1);
            }
        }
    }

    $binFixed = function (array $vals, array $edges, bool $skipZero = false): array {
        $m = count($edges) - 1;
        $counts = array_fill(0, $m, 0);
        $labels = [];
        for ($i = 0; $i < $m; $i++) {
            $labels[] = [$edges[$i], $edges[$i + 1]];
        }
        foreach ($vals as $val) {
            if ($skipZero && $val <= 0) continue;
            if ($val < $edges[0]) continue;
            $idx = $m - 1;
            for ($i = 0; $i < $m; $i++) {
                if ($val < $edges[$i + 1]) { $idx = $i; break; }
            }
            $counts[$idx]++;
        }
        return ['labels' => $labels, 'sec' => $counts];
    };

    $maxP = $w ? max($w) : 300;
    $pStep = $maxP > 450 ? 30 : 20;
    $pMaxEdge = max(300, (int)(ceil($maxP / $pStep) * $pStep));
    $pEdges = [];
    for ($x = $pStep; $x <= $pMaxEdge; $x += $pStep) $pEdges[] = $x;
    // Découpage fin en bas si besoin : part de 20 par pas de 20
    $pEdges = [];
    for ($x = 20; $x <= $pMaxEdge; $x += 20) $pEdges[] = $x;

    $tEdges = [10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75];
    $hrEdges = [70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200];

    $maxV = $v ? max($v) : 64.8;
    $vTop = max(64.8, ceil($maxV / 7.2) * 7.2);
    $vStep = $vTop / 9;
    $vEdges = [];
    for ($i = 0; $i <= 9; $i++) $vEdges[] = round($i * $vStep, 1);

    $cEdges = [20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130];
    $gEdges = [-12, -8, -5, -3, -1, 1, 3, 5, 8, 12];

    return [
        'power'    => $w ? $binFixed($w, $pEdges, true) : null,
        'torque'   => $torque ? $binFixed($torque, $tEdges, true) : null,
        'hr'       => $hr ? $binFixed($hr, $hrEdges, true) : null,
        'speed'    => $v ? $binFixed($v, $vEdges, false) : null,
        'cadence'  => $cad ? $binFixed($cad, $cEdges, true) : null,
        'grade'    => $grade ? $binFixed($grade, $gEdges, false) : null,
    ];
}

/** W'bal (modèle différentiel de Skiba). */
function wbal_series(array $p, float $cp, float $wp): array {
    $bal = $wp; $out = [];
    foreach ($p as $w) {
        if ($w > $cp) $bal -= ($w - $cp);
        else {
            $tau = 546 * exp(-0.01 * ($cp - $w)) + 316;
            $bal = $wp - ($wp - $bal) * exp(-1 / $tau);
        }
        $out[] = round(max($bal, 0) / 1000, 2);
    }
    return $out;
}

/** Détection automatique des intervalles à partir des flux 1 Hz */
function detect_intervals(array $watts, array $hr = [], array $speed = [], array $cad = [], array $wbal = [], float $ftp = 250): array {
    $n = count($watts);
    if ($n < 45) return [];

    // Lissage 5s pour filtrer le bruit
    $smooth = [];
    $win = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $win += $watts[$i];
        if ($i >= 5) $win -= $watts[$i - 5];
        $smooth[] = $i >= 4 ? $win / 5 : $watts[$i];
    }

    $targetWork = max(0.85 * $ftp, 0.88 * (array_sum($watts) / max(1, $n)));
    $targetRec = 0.72 * $ftp;

    $blocks = [];
    $inWork = false;
    $start = 0;

    for ($i = 0; $i < $n; $i++) {
        $p = $smooth[$i];
        if (!$inWork && $p >= $targetWork) {
            if ($i > $start && count($blocks) > 0) {
                $blocks[] = ['start' => $start, 'end' => $i - 1, 'is_work' => 0];
            }
            $inWork = true;
            $start = $i;
        } elseif ($inWork && $p < $targetRec) {
            $lookahead = min($n - 1, $i + 10);
            $stillLow = true;
            for ($k = $i; $k <= $lookahead; $k++) {
                if ($smooth[$k] >= $targetWork) { $stillLow = false; break; }
            }
            if ($stillLow) {
                if (($i - $start) >= 20) {
                    $blocks[] = ['start' => $start, 'end' => $i - 1, 'is_work' => 1];
                    $start = $i;
                }
                $inWork = false;
            }
        }
    }
    if ($inWork && ($n - $start) >= 20) {
        $blocks[] = ['start' => $start, 'end' => $n - 1, 'is_work' => 1];
    } elseif (!$inWork && ($n - $start) >= 30) {
        $blocks[] = ['start' => $start, 'end' => $n - 1, 'is_work' => 0];
    }

    if (count(array_filter($blocks, fn($b) => $b['is_work'] == 1)) < 2) {
        $blocks = [];
        $lap = 600; // 10 min
        for ($i = 0; $i < $n; $i += $lap) {
            $end = min($n - 1, $i + $lap - 1);
            if ($end - $i >= 60) {
                $blocks[] = ['start' => $i, 'end' => $end, 'is_work' => 1];
            }
        }
    }

    $res = [];
    $idx = 1;
    foreach ($blocks as $b) {
        $len = $b['end'] - $b['start'] + 1;
        if ($len < 15) continue;
        $wSlice = array_slice($watts, $b['start'], $len);
        $hrSlice = $hr ? array_slice($hr, $b['start'], $len) : [];
        $spdSlice = $speed ? array_slice($speed, $b['start'], $len) : [];
        $cadSlice = $cad ? array_slice($cad, $b['start'], $len) : [];
        $wbSlice = $wbal ? array_slice($wbal, $b['start'], $len) : [];

        $avgW = array_sum($wSlice) / $len;
        $npW = normalized_power($wSlice);
        $if_ = round($npW / max(1.0, $ftp), 3);
        $avgHr = $hrSlice ? array_sum(array_filter($hrSlice)) / max(1, count(array_filter($hrSlice))) : 0;
        $maxHr = $hrSlice ? max($hrSlice) : 0;
        $dist = $spdSlice ? array_sum($spdSlice) : 0; // mètres
        $avgSpdKmh = $len > 0 && $dist > 0 ? round(($dist / $len) * 3.6, 1) : ($spdSlice ? round((array_sum($spdSlice)/$len)*3.6, 1) : 0);
        $maxSpdKmh = $spdSlice ? round(max($spdSlice) * 3.6, 1) : 0;
        $avgCad = $cadSlice ? round(array_sum(array_filter($cadSlice)) / max(1, count(array_filter($cadSlice))), 0) : 0;
        $kj = round($avgW * $len / 1000, 1);
        $vi = round($npW / max(1.0, $avgW), 2);
        $ef = $avgHr > 0 ? round($npW / $avgHr, 2) : 0;
        $minWb = $wbSlice ? min($wbSlice) : null;

        $ratio = $avgW / max(1.0, $ftp);
        $zone = 'Z1 Récup';
        if ($ratio > 1.50) $zone = 'Z7 Neuromusc.';
        elseif ($ratio > 1.20) $zone = 'Z6 Anaérobie';
        elseif ($ratio > 1.05) $zone = 'Z5 VO2max';
        elseif ($ratio > 0.90) $zone = 'Z4 Seuil';
        elseif ($ratio > 0.75) $zone = 'Z3 Tempo';
        elseif ($ratio > 0.55) $zone = 'Z2 Endurance';

        $res[] = [
            'id' => $idx++,
            'is_work' => $b['is_work'],
            'start' => $b['start'],
            'end' => $b['end'],
            'duration' => $len,
            'distance' => round($dist, 0),
            'avg_w' => round($avgW, 1),
            'np' => round($npW, 1),
            'if_' => $if_,
            'vi' => $vi,
            'ef' => $ef,
            'avg_hr' => round($avgHr, 0),
            'max_hr' => round($maxHr, 0),
            'avg_speed' => $avgSpdKmh,
            'max_speed' => $maxSpdKmh,
            'cadence' => $avgCad,
            'min_wbal' => $minWb !== null ? round($minWb, 1) : null,
            'kj' => $kj,
            'zone' => $zone,
        ];
    }

    return $res;
}


/* ---------- Tours (laps) : appareil / intervals.icu, détection auto, splits ---------- */

/** Récupère les tours définis par l'appareil ou par intervals.icu (indices dans les flux 1 Hz). */
function fetch_device_laps(string $activityId): array {
    return parse_device_laps(icu_get('/activity/' . $activityId . '/intervals') ?: []);
}
function parse_device_laps(array $r): array {
    $list = $r['icu_intervals'] ?? (isset($r[0]) ? $r : []);
    $defs = [];
    foreach ($list as $iv) {
        $s = $iv['start_index'] ?? null; $e = $iv['end_index'] ?? null;
        if ($s === null || $e === null || (int)$e - (int)$s < 5) continue;
        $defs[] = [
            'start' => (int)$s, 'end' => (int)$e,
            'label' => trim((string)($iv['label'] ?? '')),
            'is_work' => strtoupper((string)($iv['type'] ?? 'WORK')) === 'RECOVERY' ? 0 : 1,
            'source' => 'icu',
        ];
    }
    usort($defs, fn($a, $b) => $a['start'] <=> $b['start']);
    $clean = []; $last = -1;                      // pas de chevauchement
    foreach ($defs as $d) { if ($d['start'] >= $last) { $clean[] = $d; $last = $d['end']; } }
    return count($clean) >= 2 ? $clean : [];      // un « tour » unique n'a aucun intérêt
}

/** Statistiques d'un tour [start, end[ calculées depuis les flux. */
function lap_stats(array $S, int $start, int $end, float $ftp): array {
    $sl = function (string $k) use ($S, $start, $end): array {
        return isset($S[$k]) ? clean(array_slice($S[$k], $start, $end - $start)) : [];
    };
    $w = $sl('watts'); $hr = $sl('heartrate'); $v = $sl('velocity_smooth'); $cad = $sl('cadence');
    $len = $end - $start;
    $alt = $sl('altitude'); $elev = 0.0;                       // D+ : pas de 5 s pour filtrer le bruit du baromètre
    if (count($alt) > 10) { $prevAlt = $alt[0]; for ($i = 5; $i < count($alt); $i += 5) { $dz = $alt[$i] - $prevAlt; if ($dz > 0) $elev += $dz; $prevAlt = $alt[$i]; } }
    $nz = fn(array $x) => array_filter($x, fn($y) => $y > 0);
    $avg = fn(array $x) => $x ? array_sum($x) / count($x) : 0.0;
    $avgW = $avg($w); $npW = count($w) >= 30 ? normalized_power($w) : $avgW;
    $hrNz = $nz($hr); $cadNz = $nz($cad);
    $ratio = $ftp > 0 ? $avgW / $ftp : 0;
    $zone = 'Z1 Récup';
    if ($ratio > 1.50) $zone = 'Z7 Neuromusc.'; elseif ($ratio > 1.20) $zone = 'Z6 Anaérobie';
    elseif ($ratio > 1.05) $zone = 'Z5 VO2max'; elseif ($ratio > 0.90) $zone = 'Z4 Seuil';
    elseif ($ratio > 0.75) $zone = 'Z3 Tempo'; elseif ($ratio > 0.55) $zone = 'Z2 Endurance';
    return [
        'start' => $start, 'end' => $end, 'duration' => $len,
        'distance' => $v ? round(array_sum($v), 0) : 0,
        'avg_w' => round($avgW, 1), 'np' => round($npW, 1),
        'if_' => $ftp > 0 && $avgW > 0 ? round($npW / $ftp, 2) : null,
        'avg_hr' => $hrNz ? round($avg($hrNz), 0) : 0, 'max_hr' => $hrNz ? round(max($hrNz), 0) : 0,
        'cadence' => $cadNz ? round($avg($cadNz), 0) : 0,
        'avg_speed' => $v ? round($avg($v) * 3.6, 2) : 0,
        'kj' => $w ? round(array_sum($w) / 1000, 0) : 0,
        'elev' => round($elev),
        'zone' => $zone,
    ];
}

/** Transforme des définitions [start,end,label,is_work,source] en tours complets. */
function build_laps(array $defs, array $S, float $ftp): array {
    $out = []; $i = 1;
    foreach ($defs as $d) {
        $st = lap_stats($S, (int)$d['start'], (int)$d['end'], $ftp);
        $st['id'] = $i++;
        $st['label'] = $d['label'] ?? '';
        $st['is_work'] = (int)($d['is_work'] ?? 1);
        $st['source'] = $d['source'] ?? 'icu';
        $out[] = $st;
    }
    return $out;
}

/** Splits automatiques tous les $meters mètres (course à pied sans tours). */
function split_defs(array $S, int $meters = 1000): array {
    $v = clean($S['velocity_smooth'] ?? []); $defs = []; $acc = 0.0; $start = 0; $k = 1;
    foreach ($v as $i => $x) {
        $acc += $x;
        if ($acc >= $meters) { $defs[] = ['start' => $start, 'end' => $i + 1, 'label' => $k++ . ' km', 'is_work' => 1, 'source' => 'split']; $start = $i + 1; $acc = 0.0; }
    }
    if (count($v) - $start >= 60 && $acc > $meters * 0.25) $defs[] = ['start' => $start, 'end' => count($v), 'label' => 'reste', 'is_work' => 1, 'source' => 'split'];
    return $defs;
}

/**
 * Tours à afficher : 1) tours appareil / intervals.icu  2) intervalles détectés (puissance)  3) splits 1 km (course).
 * @return array{0: array, 1: string} [tours, source]
 */
function laps_for_view(array $a, array $S, float $ftp): array {
    $custom = !empty($a['custom_laps']) ? (json_decode($a['custom_laps'], true) ?: []) : [];
    if ($custom) return [$custom, 'manual'];       // découpage personnalisé : remplace les tours auto (réversible)
    $dev = !empty($a['laps']) ? (json_decode($a['laps'], true) ?: []) : [];
    if (count($dev) >= 2) return [$dev, 'icu'];
    $auto = !empty($a['intervals']) ? (json_decode($a['intervals'], true) ?: []) : [];
    if (count($auto) >= 2) { foreach ($auto as &$x) { $x['label'] = $x['label'] ?? ''; $x['source'] = 'auto'; } return [$auto, 'auto']; }
    if (($a['sport'] ?? '') === 'run' && !empty($S['velocity_smooth']) && ($a['distance'] ?? 0) >= 2000) {
        $laps = build_laps(split_defs($S), $S, $ftp);
        if (count($laps) >= 2) return [$laps, 'split'];
    }
    return [[], ''];
}

/** Estime le retard de FC (en secondes) par corrélation croisée entre puissance et FC. */
function hr_lag_seconds(array $w, array $hr, int $maxLag = 120): ?int {
    $n = min(count($w), count($hr));
    if ($n < 30) return null;
    $maxLag = min($maxLag, intdiv($n, 3));
    $bestLag = 0;
    $bestCorr = -INF;

    for ($lag = 0; $lag <= $maxLag; $lag++) {
        $m = $n - $lag;
        if ($m < 15) break;
        $sumW = 0.0; $sumH = 0.0;
        for ($i = 0; $i < $m; $i++) {
            $sumW += (float)$w[$i];
            $sumH += (float)$hr[$i + $lag];
        }
        $mw = $sumW / $m;
        $mh = $sumH / $m;
        $num = 0.0; $dw2 = 0.0; $dh2 = 0.0;
        for ($i = 0; $i < $m; $i++) {
            $dw = (float)$w[$i] - $mw;
            $dh = (float)$hr[$i + $lag] - $mh;
            $num += $dw * $dh;
            $dw2 += $dw * $dw;
            $dh2 += $dh * $dh;
        }
        $den = sqrt($dw2 * $dh2);
        if ($den > 0) {
            $corr = $num / $den;
            if ($corr > $bestCorr) {
                $bestCorr = $corr;
                $bestLag = $lag;
            }
        }
    }
    return $bestLag;
}

function analyze(array $a, array $S): array {
    $ftp = (float)cfg('ftp', 250);
    $lthr = (float)cfg('lthr', 170);
    $maxHr = (float)cfg('max_hr', 194);
    $rest = (float)cfg('rest_hr', 50);
    $thrPace = (float)cfg('run_thr_pace', 240); // s/km

    $w = clean($S['watts'] ?? []);
    $hr = clean($S['heartrate'] ?? []);
    $v = clean($S['velocity_smooth'] ?? []);
    $cad = clean($S['cadence'] ?? []);
    $alt = clean($S['altitude'] ?? []);

    $dur = (int)($a['duration'] ?: max(count($w), count($hr), count($v)));
    $hours = $dur / 3600;

    $o = [
        'np' => null, 'if_' => null, 'tss' => null, 'tss_type' => 'power',
        'vi' => null, 'ef' => null, 'decoupling' => null, 'kj' => null,
        'cadence' => null, 'avg_w' => $a['avg_w'] ?? null,
        'mmp' => null, 'zones' => null, 'hr_zones' => null, 'intervals' => null,
        'wko_metrics' => null
    ];

    $hasPower = $w && array_sum($w) > 0;
    $avgHr = $hr ? array_sum(array_filter($hr)) / max(1, count(array_filter($hr))) : (float)($a['avg_hr'] ?? 0);
    $maxHrObs = $hr ? max($hr) : (float)($a['max_hr'] ?? 0);
    if ($cad && count(array_filter($cad)) > 0) {
        $o['cadence'] = round(array_sum(array_filter($cad)) / count(array_filter($cad)), 0);
    }

    if ($hr && $lthr > 0) {
        $o['hr_zones'] = json_encode(hr_zones($hr, $lthr));
    }

    // Calculs avancés WKO5 & TrainingPeaks
    $wko = [];

    // Altimétrie D+ et D-
    $elevGain = 0.0;
    $elevLoss = 0.0;
    $climbTime = 0;
    if ($alt && count($alt) > 1) {
        for ($i = 1; $i < count($alt); $i++) {
            $diff = $alt[$i] - $alt[$i-1];
            if ($diff > 0.15) { $elevGain += $diff; $climbTime++; }
            elseif ($diff < -0.15) { $elevLoss += abs($diff); }
        }
    } else {
        $elevGain = (float)($a['elev'] ?? 0);
    }
    $vam = $climbTime > 60 && $elevGain > 50 ? round(($elevGain / $climbTime) * 3600, 0) : null;

    // Vitesse max & moy
    $maxSpd = $v ? round(max($v) * 3.6, 1) : 0.0;
    $avgSpd = $v ? round((array_sum($v) / count($v)) * 3.6, 1) : ($a['avg_speed'] ? round((float)$a['avg_speed'] * 3.6, 1) : 0.0);

    // Cadence max & tours de pédale
    $cadMax = $cad ? round(max($cad), 0) : 0;
    $totalRevs = $o['cadence'] ? round($o['cadence'] * ($dur / 60)) : 0;

    // Couple moyen (Torque N.m)
    $avgTorque = ($hasPower && $o['cadence'] > 20) ? round(($a['avg_w'] ?? array_sum($w)/count($w)) / (2 * M_PI * ($o['cadence'] / 60)), 1) : null;

    // Métriques W'bal
    $wbal = [];
    $z2Ratio = null;
    $minWbal = null;
    $minWbalPct = null;
    $matchesBurned = 0;
    $timeAboveFtp = 0;
    $workAboveFtp = 0.0;

    if ($hasPower) {
        $avg = array_sum($w) / count($w);
        $np = normalized_power($w);
        $if = $np / max(1.0, $ftp);
        $tss = round($hours * ($np * $if) / max(1.0, $ftp) * 100, 1);

        $mmpArray = best_means($w);
        $cpm = cp_model($mmpArray);
        $cpVal = $cpm['cp'] ?? $ftp;
        $wpVal = $cpm['wp'] ?? 20000;

        $wbal = wbal_series($w, $cpVal, $wpVal);
        if ($wbal) {
            $minWbal = min($wbal);
            $minWbalPct = round(($minWbal / ($wpVal / 1000)) * 100, 0);
        }

        // Travail & temps au-dessus du FTP + allumettes brûlées (matches)
        $inMatch = false;
        $matchLen = 0;
        foreach ($w as $pw) {
            if ($pw > $ftp) {
                $timeAboveFtp++;
                $workAboveFtp += ($pw - $ftp) / 1000;
            }
            if ($pw >= ($ftp * 1.20)) {
                $matchLen++;
                if (!$inMatch && $matchLen >= 15) {
                    $matchesBurned++;
                    $inMatch = true;
                }
            } else {
                $matchLen = 0;
                $inMatch = false;
            }
        }

        $o = array_merge($o, [
            'avg_w' => round($avg, 1),
            'np' => round($np, 1),
            'if_' => round($if, 3),
            'tss' => $tss,
            'tss_type' => 'power',
            'vi' => round($np / max($avg, 1), 3),
            'kj' => round(array_sum($w) / 1000, 0),
            'mmp' => json_encode($mmpArray),
            'zones' => json_encode(coggan_zones($w, $ftp)),
        ]);

        if ($avgHr > 0) $o['ef'] = round($np / $avgHr, 3);
        $half = intdiv(count($w), 2);
        if ($avgHr > 0 && $dur > 1800 && count($hr) >= count($w)) {
            $e = fn($i, $j) => (array_sum(array_slice($w, $i, $j - $i)) / max(1, $j - $i)) /
                max(1, array_sum(array_slice($hr, $i, $j - $i)) / max(1, $j - $i));
            $e1 = $e(0, $half); $e2 = $e($half, count($w));
            if ($e1 > 0) $o['decoupling'] = round(($e1 - $e2) / $e1 * 100, 1);
        }

        $o['hr_lag'] = ($avgHr > 0 && count($hr) >= count($w)) ? hr_lag_seconds($w, $hr, 120) : null;

        // Ratio Puissance / FC moyen en zone Z2 FC (82% - 89% LTHR si LTHR configuré)
        $z2Ratio = null;
        if ($lthr > 0 && count($hr) >= count($w)) {
            $z2Min = 0.82 * $lthr;
            $z2Max = 0.89 * $lthr;
            $z2W = 0.0; $z2H = 0.0; $z2Cnt = 0;
            for ($i = 0; $i < count($w); $i++) {
                if ($w[$i] > 0 && $hr[$i] >= $z2Min && $hr[$i] <= $z2Max) {
                    $z2W += $w[$i];
                    $z2H += $hr[$i];
                    $z2Cnt++;
                }
            }
            if ($z2Cnt >= 10 && $z2H > 0) {
                $z2Ratio = round(($z2W / $z2Cnt) / ($z2H / $z2Cnt), 2);
            }
        }

        // Intervalles avec toutes métriques
        $o['intervals'] = json_encode(detect_intervals($w, $hr, $v, $cad, $wbal, $ftp));

    } elseif ($a['sport'] === 'run') {
        if ($v && $thrPace > 0) {
            $avgV = array_sum($v) / count($v);
            $thrV = 1000 / $thrPace;
            $if = $avgV / max(0.1, $thrV);
            $o['if_'] = round($if, 3);
            $o['tss'] = round($hours * ($if ** 2) * 100, 1);
            $o['tss_type'] = 'rtss';
            $o['mmp'] = json_encode(best_means($v, [60, 300, 600, 1200, 1800, 3600]));
        }
    }

    if ($o['tss'] === null && $avgHr > $rest && $lthr > $rest) {
        $ifh = ($avgHr - $rest) / ($lthr - $rest);
        $o['if_'] = round($ifh, 3);
        $o['tss'] = round($hours * ($ifh ** 2) * 100, 1);
        $o['tss_type'] = 'hrtss';
    }

    if ($o['tss'] === null && !empty($a['icu_load'])) {
        $o['tss'] = (float)$a['icu_load'];
        $o['tss_type'] = 'load';
    }

    // Polarisation d'entraînement (Low Z1-Z2, Mid Z3-Z4, High Z5-Z7)
    $polarization = ['low' => 0, 'mid' => 0, 'high' => 0];
    if (!empty($o['zones'])) {
        $zArr = json_decode($o['zones'], true);
        $totSec = max(1, array_sum($zArr));
        $polarization['low'] = round((($zArr[0] + $zArr[1]) / $totSec) * 100, 1);
        $polarization['mid'] = round((($zArr[2] + $zArr[3]) / $totSec) * 100, 1);
        $polarization['high'] = round((($zArr[4] + $zArr[5] + $zArr[6]) / $totSec) * 100, 1);
    }

    $wko = [
        'pmax_1s' => $w ? round(max($w), 0) : null,
        'pmax_5s' => !empty($mmpArray[5]) ? round($mmpArray[5], 0) : null,
        'pmax_1m' => !empty($mmpArray[60]) ? round($mmpArray[60], 0) : null,
        'pmax_5m' => !empty($mmpArray[300]) ? round($mmpArray[300], 0) : null,
        'pmax_20m' => !empty($mmpArray[1200]) ? round($mmpArray[1200], 0) : null,
        'work_above_ftp' => round($workAboveFtp, 1),
        'time_above_ftp' => $timeAboveFtp,
        'min_wbal' => $minWbal !== null ? round($minWbal, 2) : null,
        'min_wbal_pct' => $minWbalPct,
        'matches_burned' => $matchesBurned,
        'cad_max' => $cadMax,
        'total_revs' => $totalRevs,
        'avg_torque' => $avgTorque,
        'max_speed' => $maxSpd,
        'avg_speed' => $avgSpd,
        'elev_gain' => round($elevGain, 0),
        'elev_loss' => round($elevLoss, 0),
        'vam' => $vam,
        'pct_lthr' => $lthr > 0 ? round(($avgHr / $lthr) * 100, 1) : null,
        'pct_max_hr' => $maxHr > 0 ? round(($avgHr / $maxHr) * 100, 1) : null,
        'polarization' => $polarization,
        'hr_lag' => $o['hr_lag'] ?? null,
        'z2_ratio' => $z2Ratio,
    ];

    $o['wko_metrics'] = json_encode($wko);
    return $o;
}

/** Charge PMC : CTL (42 j), ATL (7 j), TSB, avec décomposition par sport */
function pmc(?string $sport = null): array {
    static $memo = [];
    $key = $sport ?? '__all__';
    if (array_key_exists($key, $memo)) return $memo[$key];

    $pdo = db();
    $sql = 'SELECT substr(date,1,10) d, sport, SUM(COALESCE(tss,0)) t FROM activities GROUP BY d, sport ORDER BY d';
    $rows = $pdo->query($sql)->fetchAll();
    if (!$rows) return $memo[$key] = [];

    $byDate = [];
    foreach ($rows as $r) {
        $d = $r['d'];
        if (!isset($byDate[$d])) $byDate[$d] = ['ride' => 0.0, 'run' => 0.0, 'other' => 0.0, 'total' => 0.0];
        $sp = ($r['sport'] === 'ride' || $r['sport'] === 'run') ? $r['sport'] : 'other';
        $byDate[$d][$sp] += (float)$r['t'];
        $byDate[$d]['total'] += (float)$r['t'];
    }

    $d = new DateTime(array_key_first($byDate));
    $end = new DateTime('today');
    $ctl = $atl = 0.0;
    $res = [];

    for (; $d <= $end; $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $entry = $byDate[$k] ?? ['ride' => 0.0, 'run' => 0.0, 'other' => 0.0, 'total' => 0.0];
        
        $t = $sport ? ($entry[$sport] ?? 0.0) : $entry['total'];
        $tsb = $ctl - $atl;
        $ctl += ($t - $ctl) / 42;
        $atl += ($t - $atl) / 7;

        $res[$k] = [
            'tss' => round($t, 1),
            'ride_tss' => round($entry['ride'], 1),
            'run_tss' => round($entry['run'], 1),
            'other_tss' => round($entry['other'], 1),
            'ctl' => round($ctl, 1),
            'atl' => round($atl, 1),
            'tsb' => round($tsb, 1)
        ];
    }
    return $memo[$key] = $res;
}


/* ---------- GPS / carte ---------- */

/** Normalise le flux latlng d'intervals.icu (data = latitudes, data2 = longitudes, ou paires) en [[lat,lng],...] aligné sur le temps. */
function normalize_latlng(array $stream): array {
    $d = $stream['data'] ?? []; $d2 = $stream['data2'] ?? null; $out = []; $last = null;
    foreach ($d as $i => $v) {
        if (is_array($v)) { $lat = $v[0] ?? null; $lng = $v[1] ?? null; }
        else { $lat = $v; $lng = $d2[$i] ?? null; }
        if ($lat !== null && $lng !== null && (abs((float)$lat) > 0.0001 || abs((float)$lng) > 0.0001)) $last = [round((float)$lat, 5), round((float)$lng, 5)];
        $out[] = $last;                            // report de la dernière position connue (perte de signal)
    }
    $first = null; foreach ($out as $p) { if ($p) { $first = $p; break; } }
    if (!$first) return [];
    foreach ($out as $i => $p) { if ($p) break; $out[$i] = $first; }  // début sans fix GPS
    return $out;
}

/** Trace de démonstration (boucle déterministe) alignée sur n secondes. */
function demo_route(int $n, string $seed): array {
    $h = crc32($seed); $ph = ($h % 628) / 100; $rx = 0.018 + ($h % 7) / 1000; $ry = 0.026 + ($h % 5) / 1000; $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $t = 2 * M_PI * $i / max(1, $n - 1);
        $w = 1 + 0.25 * sin(3 * $t + $ph) + 0.1 * sin(7 * $t);
        $pts[] = [round(45.7640 + $rx * sin($t) * $w, 5), round(4.8357 + $ry * (cos($t) - 1) * $w * 0.8, 5)];
    }
    return $pts;
}

/** Charge utile pour la carte : ≤ 1500 points + métriques alignées (moyennes par bloc). */
function gps_payload(array $S): ?array {
    $ll = $S['latlng'] ?? []; $n = count($ll);
    if ($n < 2) return null;
    $step = max(1, (int)ceil($n / 1500));
    $src = ['watts' => 'watts', 'hr' => 'heartrate', 'speed' => 'velocity_smooth', 'alt' => 'altitude'];
    $clean = []; foreach ($src as $k => $sk) if (!empty($S[$sk])) $clean[$k] = clean($S[$sk]);
    $pts = []; $m = array_fill_keys(array_keys($clean), []);
    for ($i = 0; $i < $n; $i += $step) {
        $pts[] = $ll[$i];
        foreach ($clean as $k => $arr) {
            $c = array_slice($arr, $i, $step);
            $v = $c ? array_sum($c) / count($c) : 0;
            $m[$k][] = round($k === 'speed' ? $v * 3.6 : $v, 1);
        }
    }
    return ['pts' => $pts, 'step' => $step, 'n' => $n] + $m;
}

/** Complète (une seule fois) le GPS d'une activité déjà synchronisée, le stocke, et renvoie la charge utile. */
function gps_for_activity(string $id): ?array {
    $st = db()->prepare('SELECT streams FROM activities WHERE id=?'); $st->execute([$id]);
    $blob = $st->fetchColumn();
    if ($blob === false || $blob === null) throw new RuntimeException('Flux non synchronisés pour cette activité.');
    $S = json_decode(gzuncompress($blob), true) ?: [];
    if (empty($S['latlng']) && empty($S['gps_checked'])) {
        if (strpos($id, 'demo_') === 0) {
            $len = count($S['time'] ?? $S['watts'] ?? $S['heartrate'] ?? $S['velocity_smooth'] ?? []);
            $ll = $len > 1 ? demo_route($len, $id) : [];
        } else {
            $ll = [];
            try {
                foreach (icu_get('/activity/' . $id . '/streams.json', ['types' => 'latlng']) ?: [] as $s) {
                    if (($s['type'] ?? '') === 'latlng') $ll = normalize_latlng($s);
                }
            } catch (Throwable $e) { error_log('gps_for_activity ' . $id . ' : ' . $e->getMessage()); }
        }
        $S['latlng'] = $ll; $S['gps_checked'] = 1;
        db()->prepare('UPDATE activities SET streams=? WHERE id=?')->execute([gzcompress(json_encode($S), 6), $id]);
    }
    return gps_payload($S);
}

/* ---------- Tours personnalisés (sélection sur le graphique) ---------- */
function stream_length(array $S): int {
    $n = 0; foreach (['time', 'watts', 'heartrate', 'velocity_smooth', 'cadence', 'altitude'] as $k) $n = max($n, count($S[$k] ?? []));
    return $n;
}
function load_streams_row(string $id): array {
    $st = db()->prepare('SELECT streams, custom_laps FROM activities WHERE id=?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || $r['streams'] === null) throw new RuntimeException('Flux non synchronisés pour cette activité.');
    return [json_decode(gzuncompress($r['streams']), true) ?: [], $r];
}
function lap_preview(string $id, int $start, int $end): array {
    [$S] = load_streams_row($id); $n = stream_length($S);
    $start = max(0, $start); $end = min($n, $end);
    if ($end - $start < 2) throw new RuntimeException('Sélection trop courte.');
    return lap_stats($S, $start, $end, (float)cfg('ftp', 250));
}
function custom_lap_save(string $id, array $defs, array $S): array {
    usort($defs, fn($a, $b) => $a['start'] <=> $b['start']);
    $laps = $defs ? build_laps($defs, $S, (float)cfg('ftp', 250)) : [];
    db()->prepare('UPDATE activities SET custom_laps=? WHERE id=?')->execute([$laps ? json_encode($laps) : null, $id]);
    return $laps;
}
function custom_lap_add(string $id, int $start, int $end, string $label, int $isWork): array {
    [$S, $r] = load_streams_row($id); $n = stream_length($S);
    $start = max(0, $start); $end = min($n, $end);
    if ($end - $start < 5) throw new RuntimeException('Sélection trop courte (minimum 5 s).');
    $defs = json_decode($r['custom_laps'] ?: '[]', true) ?: [];
    foreach ($defs as $i => $d) {
        if ($start < $d['end'] && $end > $d['start'])
            throw new RuntimeException('La sélection chevauche le tour « ' . (($d['label'] ?? '') !== '' ? $d['label'] : 'Tour ' . ($i + 1)) . ' ». Supprimez-le ou ajustez la sélection.');
    }
    $defs[] = ['start' => $start, 'end' => $end, 'label' => mb_substr(trim($label), 0, 40), 'is_work' => $isWork ? 1 : 0, 'source' => 'manual'];
    return custom_lap_save($id, $defs, $S);
}
function custom_lap_delete(string $id, int $lapId): array {
    [$S, $r] = load_streams_row($id);
    $defs = json_decode($r['custom_laps'] ?: '[]', true) ?: [];
    $defs = array_values(array_filter($defs, fn($d) => (int)($d['id'] ?? 0) !== $lapId));
    return custom_lap_save($id, $defs, $S);
}
function custom_lap_clear(string $id): void {
    db()->prepare('UPDATE activities SET custom_laps=NULL WHERE id=?')->execute([$id]);
}

/* ---------- Entraînements structurés (Workout Builder) ---------- */
function wo_zone_color(float $pct, string $mode): string {
    if ($mode === 'hr') return $pct < 81 ? '#94a3b8' : ($pct < 90 ? '#0ea5e9' : ($pct < 94 ? '#10b981' : ($pct < 100 ? '#f59e0b' : '#ef4444')));
    return $pct > 150 ? '#a855f7' : ($pct > 120 ? '#f43f5e' : ($pct > 105 ? '#f97316' : ($pct > 90 ? '#f59e0b' : ($pct > 75 ? '#10b981' : ($pct > 55 ? '#0ea5e9' : '#94a3b8')))));
}

/** Miniature SVG du profil. $profile = [[durée_s, début_%, fin_%], ...] */
function workout_mini_svg(array $profile, string $mode = 'power', int $w = 150, int $h = 34): string {
    $total = 0; $max = 100.0;
    foreach ($profile as $s) { $total += (float)$s[0]; $max = max($max, (float)$s[1], (float)$s[2]); }
    if ($total <= 0) return '';
    $max *= 1.08; $x = 0.0; $out = '';
    foreach ($profile as $s) {
        $x1 = $x + (float)$s[0] / $total * $w;
        $ya = $h - (float)$s[1] / $max * $h; $yb = $h - (float)$s[2] / $max * $h;
        $col = wo_zone_color(((float)$s[1] + (float)$s[2]) / 2, $mode);
        $out .= sprintf('<polygon points="%.1f,%d %.1f,%.1f %.1f,%.1f %.1f,%d" fill="%s"/>', $x, $h, $x, $ya, $x1, $yb, $x1, $h, $col);
        $x = $x1;
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" preserveAspectRatio="none" aria-hidden="true" style="display:block;border-radius:3px">' . $out . '</svg>';
}

function wo_clean_payload(array $d): array {
    $sport = in_array($d['sport'] ?? '', ['ride', 'run'], true) ? $d['sport'] : 'ride';
    $mode = ($d['mode'] ?? '') === 'hr' ? 'hr' : 'power';
    $structure = is_array($d['structure'] ?? null) ? $d['structure'] : [];
    $profile = is_array($d['profile'] ?? null) ? $d['profile'] : [];
    if (strlen(json_encode($structure)) > 200000 || count($profile) > 2000) throw new RuntimeException('Séance trop volumineuse.');
    return [
        'name' => mb_substr(trim((string)($d['name'] ?? '')) ?: 'Séance', 0, 80),
        'sport' => $sport, 'mode' => $mode, 'unit' => ($d['unit'] ?? '') === 'abs' ? 'abs' : 'pct',
        'structure' => json_encode($structure, JSON_UNESCAPED_UNICODE), 'profile' => json_encode($profile),
        'duration' => max(0, (int)($d['duration'] ?? 0)), 'tss' => round(max(0, (float)($d['tss'] ?? 0)), 1),
        'if_' => isset($d['if']) ? round((float)$d['if'], 3) : null,
        'notes' => mb_substr((string)($d['notes'] ?? ''), 0, 8000),
    ];
}

function workout_save(array $d): int {
    $w = wo_clean_payload($d); $id = (int)($d['id'] ?? 0); $now = date('c');
    if ($id > 0 && db()->query("SELECT COUNT(*) FROM workouts WHERE id=$id")->fetchColumn()) {
        db()->prepare('UPDATE workouts SET name=?, sport=?, mode=?, unit=?, structure=?, profile=?, duration=?, tss=?, if_=?, notes=?, updated_at=? WHERE id=?')
            ->execute([$w['name'], $w['sport'], $w['mode'], $w['unit'], $w['structure'], $w['profile'], $w['duration'], $w['tss'], $w['if_'], $w['notes'], $now, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO workouts (name, sport, mode, unit, structure, profile, duration, tss, if_, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$w['name'], $w['sport'], $w['mode'], $w['unit'], $w['structure'], $w['profile'], $w['duration'], $w['tss'], $w['if_'], $w['notes'], $now, $now]);
    return (int)db()->lastInsertId();
}

/** Envoie (ou met à jour) une séance planifiée dans le calendrier intervals.icu. Lève une exception en cas d'échec. */
function push_planned_to_icu(int $planId): string {
    $st = db()->prepare('SELECT * FROM planned_workouts WHERE id=?'); $st->execute([$planId]); $p = $st->fetch();
    if (!$p) throw new RuntimeException('Séance planifiée introuvable.');
    if (trim((string)($p['icu_text'] ?? '')) === '') throw new RuntimeException("Cette séance n'a pas de description structurée à envoyer (créée hors du builder).");
    $ev = icu_send('POST', '/athlete/' . athlete_id() . '/events', [
        'category' => 'WORKOUT', 'start_date_local' => $p['date'] . 'T00:00:00', 'type' => $p['sport'] === 'run' ? 'Run' : 'Ride',
        'name' => $p['title'], 'description' => (string)$p['icu_text'], 'moving_time' => (int)$p['duration'], 'uid' => 'cyclolab-plan-' . $planId,
    ], ['upsertOnUid' => 'true']);
    $eid = (string)($ev['id'] ?? '');
    db()->prepare('UPDATE planned_workouts SET icu_event_id=? WHERE id=?')->execute([$eid !== '' ? $eid : 'ok', $planId]);
    return $eid;
}

/** Planifie au calendrier ; envoie aussi à intervals.icu si demandé (l'échec de l'envoi n'annule pas la planification). */
function workout_plan(array $d): array {
    $w = wo_clean_payload($d);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['date'] ?? '')) ? $d['date'] : date('Y-m-d');
    db()->prepare('INSERT INTO planned_workouts (date, sport, title, duration, distance, tss, tss_type, notes, structure, profile, workout_id, icu_text) VALUES (?,?,?,?,0,?,?,?,?,?,?,?)')
        ->execute([$date, $w['sport'], $w['name'], $w['duration'], $w['tss'], $w['mode'] === 'hr' ? 'hrtss' : 'power', $w['notes'], $w['structure'], $w['profile'], (int)($d['workout_id'] ?? 0) ?: null, mb_substr((string)($d['text'] ?? ''), 0, 20000)]);
    $planId = (int)db()->lastInsertId();
    $res = ['planned_id' => $planId, 'icu' => null];
    if (!empty($d['push'])) {
        try { $res['icu'] = ['ok' => true, 'id' => push_planned_to_icu($planId)]; }
        catch (Throwable $e) { $res['icu'] = ['ok' => false, 'error' => $e->getMessage()]; }
    }
    return $res;
}

function process_activity(array $row): void {
    $S = [];
    if ($row['streams'] !== null) {
    $S = json_decode(gzuncompress($row['streams']), true) ?: [];
    $blob = $row['streams'];
    // Backfill : les anciens blobs n'ont pas grade/temp
    if (!array_key_exists('grade', $S) || !array_key_exists('temp', $S)) {
        try {
            foreach (icu_get('/activity/' . $row['id'] . '/streams.json', ['types' => 'grade_smooth,temp']) ?: [] as $s) {
                if (!empty($s['data'])) $S[(($s['type'] ?? '') === 'grade_smooth') ? 'grade' : ($s['type'] ?? '')] = $s['data'];
            }
            $blob = gzcompress(json_encode($S), 6);
        } catch (Throwable $e) { }
    }
} else {
    /* Séance sans flux stockés : récupération complète des streams intervals.icu — sans ce bloc,
       $blob et $S restent vides et l'UPDATE écrase la séance avec des valeurs nulles. */
    $blob = null;
    try {
        $types = ['time', 'watts', 'heartrate', 'velocity_smooth', 'cadence', 'altitude', 'distance', 'grade_smooth', 'temp', 'latlng'];
        $raw = null;
        $tries = 0;
        while ($types && $tries++ < 6) {
            try { $raw = icu_get('/activity/' . $row['id'] . '/streams.json', ['types' => implode(',', $types)]); break; }
            catch (Throwable $e) {
                if (!preg_match('/Invalid stream type \[([a-zA-Z_]+)\]/', (string)$e->getMessage(), $mm) || !in_array($mm[1], $types, true)) throw $e;
                $types = array_values(array_diff($types, [$mm[1]]));
            }
        }
        foreach ((array)$raw as $s) {
            if (empty($s['data'])) continue;
            $st = (string)($s['type'] ?? '');
            if ($st === 'latlng') { $ll = normalize_latlng($s); if ($ll) $S['latlng'] = $ll; continue; }
            $S[$st === 'grade_smooth' ? 'grade' : $st] = $s['data'];
        }
        if ($S) { $S['gps_checked'] = 1; $blob = gzcompress(json_encode($S), 6); }
    } catch (Throwable $e) {
        error_log('process_activity streams ' . $row['id'] . ' : ' . $e->getMessage());
        throw $e;
    }
}
    $m = analyze($row, $S);

    // Tours : on interroge intervals.icu une seule fois (laps = NULL), puis on recalcule localement.
    $defs = null;
    if ($S) {
        if (($row['laps'] ?? null) === null) {
            try { $defs = fetch_device_laps((string)$row['id']); } catch (Throwable $e) { $defs = null; }
        } else {
            $defs = json_decode($row['laps'], true) ?: [];
        }
    }
    $lapsJson = $defs === null ? null : json_encode(build_laps($defs, $S, (float)cfg('ftp', 250)));
    $cus = !empty($row['custom_laps']) ? json_decode($row['custom_laps'], true) : null;
    $cusJson = ($cus && $S) ? json_encode(build_laps($cus, $S, (float)cfg('ftp', 250))) : ($row['custom_laps'] ?? null);

    db()->prepare('UPDATE activities SET
        avg_w=?, np=?, if_=?, tss=?, tss_type=?, vi=?, ef=?, decoupling=?, kj=?, cadence=?,
        mmp=?, zones=?, hr_zones=?, intervals=?, wko_metrics=?, laps=?, custom_laps=?, streams=?, synced=1 WHERE id=?')
        ->execute([
            $m['avg_w'], $m['np'], $m['if_'], $m['tss'], $m['tss_type'], $m['vi'], $m['ef'],
            $m['decoupling'], $m['kj'], $m['cadence'], $m['mmp'], $m['zones'], $m['hr_zones'],
            $m['intervals'], $m['wko_metrics'], $lapsJson, $cusJson, $blob, $row['id']
        ]);
}

/* ---------- WKO5 Courbes de puissance multi-saisons ---------- */
function get_seasons_list(): array {
    $pdo = db();
    $years = $pdo->query("SELECT DISTINCT substr(date,1,4) y FROM activities WHERE sport='ride' AND mmp IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    $seasons = [];
    foreach ($years as $y) {
        if ($y) $seasons['year_' . $y] = "Saison $y";
    }
    $seasons['d90'] = '90 derniers jours';
    $seasons['d42'] = '42 derniers jours';
    $seasons['all'] = 'Tout l’historique';
    return $seasons;
}

function get_season_mmp(string $period, string $sport = 'ride'): array {
    $pdo = db();
    $sql = "SELECT mmp FROM activities WHERE sport=? AND mmp IS NOT NULL";
    $params = [$sport];

    if (str_starts_with($period, 'year_')) {
        $year = substr($period, 5);
        $sql .= " AND substr(date,1,4)=?";
        $params[] = $year;
    } elseif ($period === 'd90') {
        $sql .= " AND date >= date('now','-90 days')";
    } elseif ($period === 'd42') {
        $sql .= " AND date >= date('now','-42 days')";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $curve = [];
    foreach ($st as $r) {
        $m = json_decode($r['mmp'], true) ?: [];
        foreach ($m as $d => $v) {
            $d = (int)$d;
            $curve[$d] = max($curve[$d] ?? 0.0, (float)$v);
        }
    }
    ksort($curve);
    $model = cp_model($curve);
    return ['curve' => $curve, 'model' => $model];
}

/* ---------- Wellness Statistics & Corridor ---------- */
function get_wellness_summary(int $days = 60): array {
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM wellness ORDER BY date DESC LIMIT ' . max(1, (int)$days))->fetchAll();
    if (!$rows) return [];

    $rows = array_reverse($rows);
    $hrvVals = [];
    $data = [];

    foreach ($rows as $r) {
        $hrv = $r['hrv'] !== null ? (float)$r['hrv'] : null;
        if ($hrv !== null) $hrvVals[] = $hrv;
        
        $recentHrv = array_slice($hrvVals, -7);
        $avg7 = count($recentHrv) ? array_sum($recentHrv) / count($recentHrv) : null;

        $data[$r['date']] = [
            'date' => $r['date'],
            'hrv' => $hrv,
            'hrv_7d' => $avg7 ? round($avg7, 1) : null,
            'resting_hr' => $r['resting_hr'] !== null ? (float)$r['resting_hr'] : null,
            'sleep_h' => $r['sleep_sec'] ? round($r['sleep_sec'] / 3600, 2) : null,
            'sleep_score' => $r['sleep_score'] !== null ? (float)$r['sleep_score'] : null,
            'weight' => $r['weight'] !== null ? (float)$r['weight'] : null,
            'readiness' => $r['readiness'] !== null ? (float)$r['readiness'] : null,
            'fatigue' => $r['fatigue'],
            'soreness' => $r['soreness'],
            'stress' => $r['stress'],
            'mood' => $r['mood'],
            'comments' => $r['comments'],
        ];
    }

    $baselineHrv = count($hrvVals) >= 7 ? array_sum(array_slice($hrvVals, -45)) / min(45, count($hrvVals)) : 65;
    $sqDiff = 0.0;
    $subset = array_slice($hrvVals, -45);
    foreach ($subset as $val) $sqDiff += ($val - $baselineHrv) ** 2;
    $sd = count($subset) > 1 ? sqrt($sqDiff / (count($subset) - 1)) : 8.0;

    return [
        'history' => $data,
        'baseline' => round($baselineHrv, 1),
        'sd' => round($sd, 1),
        'upper_corridor' => round($baselineHrv + 0.75 * $sd, 1),
        'lower_corridor' => round(max(20, $baselineHrv - 0.75 * $sd), 1),
    ];
}

/* ---------- Générateur de données de démo ---------- */
function seed_demo_data(): void {
    $pdo = db();
    $pdo->beginTransaction();

    cfg_set('ftp', '285');
    cfg_set('lthr', '172');
    cfg_set('max_hr', '194');
    cfg_set('rest_hr', '48');
    cfg_set('weight', '68.5');
    cfg_set('run_thr_pace', '240'); // 4:00/km

    $insWell = $pdo->prepare('INSERT INTO wellness
        (date, hrv, resting_hr, sleep_sec, sleep_score, sleep_quality, weight, fatigue, soreness, stress, mood, readiness, comments, synced)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ON CONFLICT(date) DO UPDATE SET
            hrv=excluded.hrv, resting_hr=excluded.resting_hr, sleep_sec=excluded.sleep_sec,
            sleep_score=excluded.sleep_score, weight=excluded.weight, readiness=excluded.readiness');

    $today = new DateTime('today');
    for ($i = 45; $i >= 0; $i--) {
        $d = clone $today;
        $d->modify("-$i days");
        $dStr = $d->format('Y-m-d');
        
        $cycle = sin($i * 0.4) * 8 + (mt_rand(-5, 5));
        $hrv = round(64 + $cycle, 1);
        $rhr = round(48 - ($cycle * 0.3) + mt_rand(-1, 2), 0);
        $sleepH = round(7.2 + sin($i * 0.3) * 0.8 + (mt_rand(-5, 5) / 10), 1);
        $sleepScore = round(min(98, max(60, 75 + ($sleepH - 7) * 12 + mt_rand(-6, 6))), 0);
        $weight = round(68.5 + (sin($i * 0.1) * 0.6) + (mt_rand(-2, 2) / 10), 1);
        $fatigue = min(5, max(1, (int)round(2.5 - ($cycle * 0.15) + (mt_rand(0, 2)))));
        $stress = min(5, max(1, (int)round(2.0 + mt_rand(0, 2))));
        $readiness = compute_readiness_score($hrv, $rhr, (int)($sleepH * 3600), $sleepScore, $fatigue, $stress);

        $insWell->execute([
            $dStr, $hrv, $rhr, (int)($sleepH * 3600), $sleepScore, 4, $weight,
            $fatigue, 2, $stress, 4, $readiness, "Forme normale",
        ]);
    }

    $insAct = $pdo->prepare('INSERT INTO activities
        (id, date, type, sport, name, duration, distance, elev, avg_hr, max_hr, avg_speed, avg_w, np, if_, tss, tss_type, vi, ef, decoupling, kj, cadence, icu_load, mmp, zones, hr_zones, intervals, wko_metrics, streams, synced)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ON CONFLICT(id) DO UPDATE SET
            avg_w=excluded.avg_w, np=excluded.np, tss=excluded.tss, intervals=excluded.intervals, wko_metrics=excluded.wko_metrics, streams=excluded.streams');

    $workouts = [
        [
            'days_ago' => 1,
            'name' => '5x5min VO2max @ 325W',
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 4500,
            'dist' => 38500,
            'elev' => 320,
            'kind' => 'vo2_intervals',
        ],
        [
            'days_ago' => 3,
            'name' => 'Endurance Fondamentale Z2 & Cadence',
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 7200,
            'dist' => 62000,
            'elev' => 580,
            'kind' => 'endurance_ride',
        ],
        [
            'days_ago' => 5,
            'name' => 'Intervalles Seuil 3x10min @ 280W',
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 5400,
            'dist' => 46000,
            'elev' => 410,
            'kind' => 'threshold_intervals',
        ],
        [
            'days_ago' => 6,
            'name' => 'Course à pied 10 km Seuil & Tempo',
            'type' => 'Run',
            'sport' => 'run',
            'dur' => 2640,
            'dist' => 10200,
            'elev' => 85,
            'kind' => 'tempo_run',
        ],
        [
            'days_ago' => 8,
            'name' => 'Sortie Longue Haute-Montagne & Col',
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 11400,
            'dist' => 92000,
            'elev' => 1650,
            'kind' => 'long_ride',
        ],
        [
            'days_ago' => 10,
            'name' => 'Piste 8x400m VMA Dynamique',
            'type' => 'Run',
            'sport' => 'run',
            'dur' => 3000,
            'dist' => 9500,
            'elev' => 20,
            'kind' => 'track_run',
        ],
        [
            'days_ago' => 12,
            'name' => 'Récupération active Z1 & Vélocité',
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 3600,
            'dist' => 28000,
            'elev' => 120,
            'kind' => 'recovery_ride',
        ],
        [
            'days_ago' => 14,
            'name' => 'Natation Éducatifs & Allure',
            'type' => 'Swim',
            'sport' => 'swim',
            'dur' => 2700,
            'dist' => 2200,
            'elev' => 0,
            'kind' => 'swim',
        ],
    ];

    for ($w = 3; $w <= 8; $w++) {
        $workouts[] = [
            'days_ago' => $w * 7 - 2,
            'name' => "Sortie Club Dimanche (S-$w)",
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 9600,
            'dist' => 84000,
            'elev' => 1100,
            'kind' => 'long_ride',
        ];
        $workouts[] = [
            'days_ago' => $w * 7 - 4,
            'name' => "Intervalles SweetSpot 2x20min",
            'type' => 'Ride',
            'sport' => 'ride',
            'dur' => 4800,
            'dist' => 42000,
            'elev' => 350,
            'kind' => 'threshold_intervals',
        ];
        $workouts[] = [
            'days_ago' => $w * 7 - 5,
            'name' => "Footing Endurance Aérobie 8km",
            'type' => 'Run',
            'sport' => 'run',
            'dur' => 2280,
            'dist' => 8200,
            'elev' => 60,
            'kind' => 'tempo_run',
        ];
    }

    $lastYear = (int)date('Y') - 1;
    $prevSeasonDates = ["$lastYear-04-12", "$lastYear-05-18", "$lastYear-06-25", "$lastYear-08-14", "$lastYear-09-20"];
    foreach ($prevSeasonDates as $idx => $pDate) {
        $wattsMmp = [
            1 => 890, 5 => 840, 10 => 780, 15 => 710, 30 => 560, 60 => 450,
            120 => 380, 180 => 350, 300 => 325, 600 => 300, 1200 => 275, 1800 => 260, 3600 => 245
        ];
        $insAct->execute([
            "demo_prev_$idx", $pDate . 'T09:30:00', 'Ride', 'ride', "Course & Étape Saison $lastYear #$idx",
            7200, 65000, 850, 158, 186, 9.0, 235, 260, 0.91, 145, 'power', 1.10, 1.64, 3.2, 1690, 88, 145,
            json_encode($wattsMmp),
            json_encode([300, 1800, 2400, 1500, 900, 200, 100]),
            json_encode([400, 2200, 2600, 1600, 400]),
            null, null, null,
        ]);
    }

    foreach ($workouts as $idx => $w) {
        $d = clone $today;
        $d->modify("-{$w['days_ago']} days");
        $dStr = $d->format('Y-m-d\TH:i:s');
        $id = "demo_act_" . ($idx + 1);

        $streams = generate_mock_streams($w['kind'], $w['dur']);
        $blob = gzcompress(json_encode($streams), 6);

        $m = analyze([
            'id' => $id, 'date' => $dStr, 'type' => $w['type'], 'sport' => $w['sport'],
            'duration' => $w['dur'], 'avg_w' => null, 'avg_hr' => null, 'icu_load' => null,
            'elev' => $w['elev']
        ], $streams);

        $insAct->execute([
            $id, $dStr, $w['type'], $w['sport'], $w['name'],
            $w['dur'], $w['dist'], $w['elev'],
            $m['avg_hr'] ?? 150, ($m['avg_hr'] ?? 150) + 28,
            round($w['dist'] / max(1, $w['dur']), 2),
            $m['avg_w'], $m['np'], $m['if_'], $m['tss'], $m['tss_type'],
            $m['vi'], $m['ef'], $m['decoupling'], $m['kj'], $m['cadence'],
            $m['tss'], $m['mmp'], $m['zones'], $m['hr_zones'], $m['intervals'],
            $m['wko_metrics'], $blob
        ]);
    }

    $pdo->commit();
}

/** Génération de flux 1 Hz ultra réalistes pour vélo et course */
function generate_mock_streams(string $kind, int $dur): array {
    $watts = [];
    $hr = [];
    $vel = [];
    $cad = [];
    $alt = [];

    $currentAlt = 250.0;
    $currentHr = 110.0;

    for ($t = 0; $t < $dur; $t++) {
        $p = 180.0;
        $c = 88;
        $v = 8.5; // ~30 km/h

        if ($kind === 'vo2_intervals') {
            if ($t < 900) {
                $p = 150 + ($t / 900) * 80;
                $c = 85;
            } elseif ($t < 3300) {
                $cycle = ($t - 900) % 480;
                if ($cycle < 300) {
                    $p = 325 + sin($t * 0.2) * 20 + mt_rand(-15, 15);
                    $c = 94;
                    $v = 11.2;
                } else {
                    $p = 145 + mt_rand(-10, 10);
                    $c = 82;
                    $v = 6.8;
                }
            } else {
                $p = 140 - (($t - 3300) / 1200) * 30;
                $c = 80;
            }
        } elseif ($kind === 'threshold_intervals') {
            if ($t < 600) {
                $p = 160 + ($t / 600) * 70;
            } elseif ($t < 3120) {
                $cycle = ($t - 600) % 840;
                if ($cycle < 600) {
                    $p = 280 + sin($t * 0.1) * 15 + mt_rand(-10, 10);
                    $c = 91;
                    $v = 10.0;
                } else {
                    $p = 140 + mt_rand(-10, 10);
                    $c = 82;
                }
            } else {
                $p = 150;
            }
        } elseif ($kind === 'endurance_ride' || $kind === 'long_ride') {
            $hill = sin($t * 0.003) * 60;
            $p = 195 + $hill + mt_rand(-20, 20);
            $currentAlt += ($hill > 20 ? 0.25 : -0.1);
            $v = 8.5 + ($hill < 0 ? 3.0 : -2.0);
            $c = 88;
        } elseif ($kind === 'tempo_run') {
            $v = 4.16;
            $p = 290 + mt_rand(-15, 15);
            $c = 86;
        } elseif ($kind === 'track_run') {
            $cycle = $t % 240;
            if ($cycle < 90) { $v = 4.88; $p = 340; }
            else { $v = 2.8; $p = 180; }
            $c = 90;
        } else {
            $p = 135 + mt_rand(-10, 10);
            $c = 85;
            $v = 7.0;
        }

        $targetHr = 95 + ($p / 285) * 80;
        $currentHr += ($targetHr - $currentHr) * 0.03 + (mt_rand(-1, 1) * 0.2);

        $watts[] = max(0, round($p, 1));
        $hr[] = round(max(85, min(195, $currentHr)), 0);
        $vel[] = round(max(0, $v), 2);
        $cad[] = round(max(0, $c + mt_rand(-2, 2)), 0);
        $alt[] = round(max(50, $currentAlt), 1);
    }

    return [
        'time' => range(0, $dur - 1),
        'watts' => $watts,
        'heartrate' => $hr,
        'velocity_smooth' => $vel,
        'cadence' => $cad,
        'altitude' => $alt,
    ];
}

function clear_database(): void {
    $pdo = db();
    $pdo->exec("DELETE FROM activities; DELETE FROM wellness; DELETE FROM planned_workouts;");
}

/* ---------- Helpers de formatage ---------- */
function pace(?float $v): string {
    return $v > 0 ? sprintf('%d:%02d', intdiv((int)round(1000 / $v), 60), (int)round(1000 / $v) % 60) : '–';
}
function n($v, int $d = 0): string {
    return $v === null || $v === '' ? '–' : number_format((float)$v, $d, ',', ' ');
}
function fmt_dur(int $s): string {
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return $h > 0 ? sprintf('%dh%02d', $h, $m) : sprintf('%d:%02d', $m, $sec);
}
function fmt_dur_full(int $s): string {
    return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}
function fmt_label(int $s): string {
    return $s < 60 ? "{$s}s" : ($s < 3600 ? intdiv($s, 60) . 'min' : round($s / 3600, 1) . 'h');
}

/* ---------- Notifications in-app ---------- */

/** Ajoute (ou restimule) une notification — dédoublonnée par (type, ref). */
function notif_add(string $type, string $ref, string $title, string $body = '', string $link = '', string $level = 'info'): void {
    db()->prepare('INSERT INTO notifications(ts, type, ref, level, title, body, link, read_at)
        VALUES (?,?,?,?,?,?,?,NULL)
        ON CONFLICT(type, ref) DO UPDATE SET
            ts = excluded.ts, level = excluded.level, title = excluded.title,
            body = excluded.body, link = excluded.link, read_at = NULL')
        ->execute([time(), $type, $ref, $level, $title, $body, $link]);
}

function notif_latest(int $limit = 40): array {
    return db()->query('SELECT id, ts, type, level, title, body, link, read_at FROM notifications ORDER BY ts DESC, id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
}

function notif_unread(): int {
    return (int)db()->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();
}

function notif_read_id(int $id): void {
    db()->prepare('UPDATE notifications SET read_at = ' . time() . ' WHERE id = ? AND read_at IS NULL')->execute([max(0, $id)]);
}

function notif_read_all(): void {
    db()->exec('UPDATE notifications SET read_at = ' . time() . ' WHERE read_at IS NULL');
}

/** Purge des notifications de plus de 6 mois. */
function notif_gc(): void {
    db()->exec('DELETE FROM notifications WHERE ts < ' . (time() - 182 * 86400));
}

/** Libellé humain d'une durée de meilleur effort (5 → 5 s, 300 → 5 min, 1200 → 20 min). */
function notif_dur_label(int $s): string {
    return $s < 60 ? sprintf(t('%d s'), $s) : sprintf(t('%d min'), intdiv($s, 60));
}

/** Génère les notifications d'analyse (alertes, objectifs, records, rappels) — throttlé 30 min via cfg(notif_gen). */
function notif_generate(): void {
    if (time() - (int)cfg('notif_gen', 0) < 1800) return;
    cfg_set('notif_gen', (string)time());
    try { notif_gc(); } catch (Throwable $e) {}

    /* 1. Alertes de surmenage (progress_lib) — refé/Data du jour pour permettre une réévaluation quotidienne */
    try {
        foreach (progress_alerts() as $a) {
            $lvl = ($a['sev'] ?? 'info') === 'bad' ? 'crit' : (($a['sev'] ?? 'info') === 'warn' ? 'warn' : 'info');
            notif_add('alert', 'alert-' . $lvl . '-' . md5((string)$a['title']) . '-' . date('Ymd'), (string)t($a['title']), (string)t($a['msg']), '?p=progress&alert=1', $lvl);
        }
    } catch (Throwable $e) {}

    /* 2. Objectifs hebdomadaires : atteint / en retard */
    try {
        $gs = progress_goal_status();
        if (!empty($gs['goals_set']) && $gs['goal_tss'] > 0) {
            $pct = $gs['total_tss'] / $gs['goal_tss'];
            if ($pct >= 1) {
                notif_add('goal', 'goal-done-' . $gs['week_monday'], t('Objectif de la semaine atteint'),
                    sprintf(t('%s TSS sur %s visés.'), n($gs['total_tss']), n($gs['goal_tss'])), '?p=progress&alert=1', 'good');
            } elseif ((int)date('N') >= 4 && $pct < 0.55) {
                notif_add('goal', 'goal-late-' . $gs['week_monday'], t('Objectif de la semaine en retard'),
                    sprintf(t('%s TSS sur %s visés — prévois une séance pour rester dans la cible.'), n($gs['total_tss']), n($gs['goal_tss'])), '?p=progress&alert=1', 'warn');
            }
        }
    } catch (Throwable $e) {}

    /* 3. Records personnels — compare au snapshot cfg(pr_notif_snap) ; premier passage sans notif */
    try {
        $prs = progress_prs();
        $flat = [];
        foreach (($prs['pw'] ?? []) as $d => $r) if (($r['val'] ?? 0) > 0) $flat["pw-$d"] = $r;
        foreach (($prs['hr'] ?? []) as $d => $r) if (($r['val'] ?? 0) > 0) $flat["hr-$d"] = $r;
        $snap = json_decode((string)cfg('pr_notif_snap', ''), true) ?: [];
        foreach ($flat as $k => $r) {
            $old = $snap[$k] ?? null;
            if (is_array($old) && (float)$r['val'] > (float)($old['val'] ?? 0) + 0.01) {
                $isPw = str_starts_with($k, 'pw-');
                $unit = $isPw ? t('W') : t('bpm');
                notif_add('pr', 'pr-' . $k, t('Nouveau record personnel'),
                    sprintf(t('%s %s sur %s — %s'), n((float)$r['val']), $unit, notif_dur_label((int)substr($k, 3)), $r['name'] ?: t('Séance')),
                    '?p=activity&id=' . urlencode((string)($r['act_id'] ?? '')), 'good');
            }
            $snap[$k] = ['val' => (float)$r['val']];
        }
        if ($flat) cfg_set('pr_notif_snap', json_encode($snap));
    } catch (Throwable $e) {}

    /* 4. Rappel de séance planifiée (aujourd'hui / demain) */
    try {
        $tom = date('Y-m-d', strtotime('+1 day'));
        $today = date('Y-m-d');
        $st = db()->prepare("SELECT id, date, title, ROUND(tss) tss FROM planned_workouts WHERE completed=0 AND substr(date,1,10) IN (?, ?) ORDER BY date");
        $st->execute([$today, $tom]);
        foreach ($st->fetchAll() as $w) {
            $when = substr($w['date'], 0, 10) === $today ? t('aujourd\'hui') : t('demain');
            notif_add('plan', 'plan-' . $w['id'] . '-' . substr($w['date'], 0, 10), t('Séance planifiée'),
                sprintf(t('%s le %s — %s TSS'), $when, date('d/m', strtotime($w['date'])), n((float)$w['tss'])), '?p=calendar', 'info');
        }
    } catch (Throwable $e) {}

    /* 5. Données wellness manquantes (HRV / FC repos jamais synchronisées depuis > 2 jours) */
    try {
        $last = db()->query("SELECT MAX(date) FROM wellness WHERE hrv IS NOT NULL OR resting_hr IS NOT NULL")->fetchColumn();
        if (!$last || strtotime(substr((string)$last, 0, 10)) < strtotime('-2 days')) {
            notif_add('well', 'well-missing-' . date('Y-m-d'), t('Données de récupération manquantes'),
                t('HRV / FC repos non synchronisés depuis plus de 2 jours — vérifie la connexion wellness (intervals.icu).'), '?p=wellness', 'info');
        }
    } catch (Throwable $e) {}
}

/* ---------- i18n : filtre de sortie traduit le HTML des pages (index, login) ---------- */
ob_start('trq_translate_html');
