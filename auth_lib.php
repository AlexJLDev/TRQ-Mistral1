<?php
/**
 * TRQ – authentification multi-utilisateurs.
 * - data/auth.sqlite : comptes, tentatives de connexion, jetons de réinitialisation
 * - data/users/u{id}.sqlite : TOUTES les données d'un utilisateur (isolation complète)
 */

function trq_config(?string $k = null) {
    static $c;
    if ($c === null) {
        $c = ['auth' => true, 'registration' => 'open', 'invite_code' => '', 'base_url' => '', 'mail_from' => '', 'dev_mail' => null,
              'trust_proxy' => false, 'min_password' => 10, 'session_days' => 30];
        foreach ([__DIR__ . '/config.php', __DIR__ . '/config.local.php'] as $f) {
            if (is_file($f)) { $x = require $f; if (is_array($x)) $c = array_replace($c, $x); }
        }
    }
    return $k === null ? $c : ($c[$k] ?? null);
}
function auth_enabled(): bool { return (bool)trq_config('auth'); }

/* ---------- dossier de données (jamais accessible par le web) ---------- */
function data_dir(): string { return __DIR__ . '/data'; }
function ensure_data_dir(): void {
    $d = data_dir();
    foreach ([$d, $d . '/users', $d . '/sessions'] as $x) if (!is_dir($x)) @mkdir($x, 0775, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess',
        "# TRQ : aucun accès web aux données\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
}

function auth_db(): PDO {
    static $p;
    if (!$p) {
        ensure_data_dir();
        $p = new PDO('sqlite:' . data_dir() . '/auth.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5,
        ]);
        $p->exec("
        CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT, pass_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user', active INTEGER NOT NULL DEFAULT 1, created_at TEXT, last_login TEXT);
        CREATE TABLE IF NOT EXISTS auth_events(id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, ip TEXT, email TEXT, at INTEGER);
        CREATE INDEX IF NOT EXISTS idx_auth_events ON auth_events(kind, at);
        CREATE TABLE IF NOT EXISTS password_resets(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, expires_at INTEGER, used INTEGER DEFAULT 0);");
    }
    return $p;
}

function db_path(): string {
    if (!auth_enabled()) return data_dir() . '/app.sqlite';                 // mode local mono-utilisateur
    $uid = current_uid();
    if ($uid <= 0) throw new RuntimeException('Aucun utilisateur connecté.');
    return user_db_path($uid);
}

/* ---------- requête / session ---------- */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return (bool)trq_config('trust_proxy') && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function client_ip(): string {
    if (trq_config('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function is_local_host(): bool {
    $h = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
    return in_array($h, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || preg_match('/\.(test|local|localhost)$/', $h) === 1;
}
function base_url(): string {
    if (trq_config('base_url')) return rtrim((string)trq_config('base_url'), '/');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
function cookie_path(): string { $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'); return $d === '' ? '/' : $d . '/'; }

function auth_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ensure_data_dir();
    @ini_set('session.use_strict_mode', '1'); @ini_set('session.use_only_cookies', '1');
    @ini_set('session.gc_maxlifetime', (string)((int)trq_config('session_days') * 86400));
    @ini_set('session.gc_probability', '1'); @ini_set('session.gc_divisor', '100');
    if (is_writable(data_dir() . '/sessions')) session_save_path(data_dir() . '/sessions');   // durée de vie indépendante du reste du serveur
    session_name('trq_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!empty($_SESSION['uid']) && empty($_SESSION['remember']) && time() - (int)($_SESSION['seen'] ?? 0) > 8 * 3600) { $_SESSION = []; session_regenerate_id(true); }
    if (time() - (int)($_SESSION['seen'] ?? 0) > 60) $_SESSION['seen'] = time();
    if (!headers_sent()) {
        header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    }
}

/* ---------- utilisateur courant ---------- */
function auth_force_user(int $id): void { $GLOBALS['__trq_force_uid'] = $id; }   // tests / scripts CLI
function current_user(): ?array {
    static $cache = [];
    if (!auth_enabled()) return ['id' => 0, 'email' => 'local', 'name' => 'Local', 'role' => 'admin', 'active' => 1];
    $uid = (int)($GLOBALS['__trq_force_uid'] ?? ($_SESSION['uid'] ?? 0));
    if ($uid <= 0) return null;
    if (!array_key_exists($uid, $cache)) { $st = auth_db()->prepare('SELECT * FROM users WHERE id=? AND active=1'); $st->execute([$uid]); $cache[$uid] = $st->fetch() ?: null; }
    return $cache[$uid];
}
function current_uid(): int { $u = current_user(); return $u ? (int)$u['id'] : 0; }
function is_admin(): bool { $u = current_user(); return $u && $u['role'] === 'admin'; }

/** Protège une page ou un endpoint : redirige (ou répond 401 en JSON) si non connecté, impose le jeton CSRF sur les POST. */
function require_login(bool $json = false): array {
    auth_boot();
    $u = current_user();
    if (!$u) {
        if (!empty($_SESSION['uid'])) { $_SESSION = []; }
        if ($json) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'login' => true, 'error' => 'Session expirée : reconnectez-vous.']); exit; }
        $next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '') : '';
        header('Location: login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : '')); exit;
    }
    csrf_token();                                                   // créé avant la fermeture de la session
    if (auth_enabled() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_ok()) {
        http_response_code(403);
        if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Jeton de sécurité invalide ou expiré : rechargez la page.']); }
        else echo 'Jeton de sécurité invalide ou expiré : rechargez la page puis réessayez.';
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // évite de bloquer les requêtes parallèles (verrou de session)
    return $u;
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function csrf_ok(): bool {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $t);
}
/** Ajoute le jeton à tous les <form method="post"> de la page (callback d'ob_start). */
function csrf_inject_cb(string $html): string {
    if (!auth_enabled() || empty($_SESSION['csrf'])) return $html;
    $tok = htmlspecialchars((string)$_SESSION['csrf'], ENT_QUOTES);
    return preg_replace('/(<form\b[^>]*\bmethod=["\']post["\'][^>]*>)/i', '$1<input type="hidden" name="csrf" value="' . $tok . '">', $html);
}

/* ---------- limitation des tentatives ---------- */
function auth_event(string $kind, string $email = ''): void {
    auth_db()->prepare('INSERT INTO auth_events (kind, ip, email, at) VALUES (?,?,?,?)')->execute([$kind, client_ip(), strtolower($email), time()]);
    if (random_int(1, 50) === 1) auth_db()->prepare('DELETE FROM auth_events WHERE at < ?')->execute([time() - 86400]);
}
function auth_count(string $kind, int $window, bool $byIp = true, string $email = ''): int {
    $sql = 'SELECT COUNT(*) FROM auth_events WHERE kind=? AND at > ?'; $args = [$kind, time() - $window];
    if ($byIp) { $sql .= ' AND ip=?'; $args[] = client_ip(); }
    if ($email !== '') { $sql .= ' AND email=?'; $args[] = strtolower($email); }
    $st = auth_db()->prepare($sql); $st->execute($args); return (int)$st->fetchColumn();
}

/* ---------- mots de passe ---------- */
function pw_hash(string $pw): string { return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT); }
function pw_error(string $pw, string $email = ''): ?string {
    $min = max(8, (int)trq_config('min_password'));
    if (mb_strlen($pw) < $min) return "Le mot de passe doit contenir au moins $min caractères.";
    if (mb_strlen($pw) > 200) return 'Mot de passe trop long (200 caractères maximum).';
    $low = mb_strtolower($pw);
    if ($email !== '' && ($low === mb_strtolower($email) || $low === mb_strtolower(explode('@', $email)[0]))) return 'Le mot de passe ne doit pas être votre adresse e-mail.';
    $common = ['password', 'password1', 'motdepasse', 'azertyuiop', 'qwertyuiop', '1234567890', '123456789012', 'abcdefghij', 'iloveyou123', 'changeme123'];
    if (in_array($low, $common, true) || preg_match('/^(.)\1+$/u', $pw)) return 'Ce mot de passe est trop courant. Choisissez-en un plus original.';
    return null;
}

/* ---------- connexion / déconnexion ---------- */
function auth_login(string $email, string $pw, bool $remember): array {
    $email = strtolower(trim($email));
    if (auth_count('login_fail', 900, true) >= 20 || ($email !== '' && auth_count('login_fail', 900, true, $email) >= 5))
        return [false, 'Trop de tentatives. Réessayez dans 15 minutes.'];
    $st = auth_db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]); $u = $st->fetch();
    $hash = $u['pass_hash'] ?? '$2y$10$usesomesillystringforsaltuoUGrrAHxOLoJ/xkHg7dKfGKqIvC';   // temps constant même si le compte n'existe pas
    $ok = password_verify($pw, $hash) && $u && (int)$u['active'] === 1;
    if (!$ok) { auth_event('login_fail', $email); return [false, 'Adresse e-mail ou mot de passe incorrect.']; }
    if (password_needs_rehash($u['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT))
        auth_db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([pw_hash($pw), $u['id']]);
    auth_db()->prepare('UPDATE users SET last_login=? WHERE id=?')->execute([date('c'), $u['id']]);
    auth_db()->prepare("DELETE FROM auth_events WHERE kind='login_fail' AND email=?")->execute([$email]);
    auth_start_session((int)$u['id'], $remember);
    return [true, null];
}
function auth_start_session(int $uid, bool $remember): void {
    auth_boot();
    session_regenerate_id(true);
    $_SESSION = ['uid' => $uid, 'remember' => $remember ? 1 : 0, 'seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
    if ($remember && !headers_sent())
        setcookie(session_name(), session_id(), ['expires' => time() + (int)trq_config('session_days') * 86400, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function auth_logout_session(): void {
    auth_boot();
    $_SESSION = [];
    if (!headers_sent()) setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/* ---------- inscription ---------- */
function auth_user_count(): int { return (int)auth_db()->query('SELECT COUNT(*) FROM users')->fetchColumn(); }
function legacy_db_path(): string { return data_dir() . '/app.sqlite'; }
function user_db_path(int $uid): string { return data_dir() . '/users/u' . $uid . '.sqlite'; }
function auth_registration_open(): bool { return auth_user_count() === 0 || trq_config('registration') !== 'closed'; }

/** Le premier compte reprend la base mono-utilisateur existante (données d'avant l'authentification). */
function auth_adopt_legacy(int $uid): bool {
    $src = legacy_db_path(); $dst = user_db_path($uid);
    if (!is_file($src) || is_file($dst)) return false;
    $ok = @rename($src, $dst);
    foreach (['-wal', '-shm'] as $ext) if (is_file($src . $ext)) @rename($src . $ext, $dst . $ext);
    return $ok;
}

function auth_register(array $in): array {
    $name = trim((string)($in['name'] ?? '')); $email = strtolower(trim((string)($in['email'] ?? ''))); $pw = (string)($in['password'] ?? '');
    $first = auth_user_count() === 0;
    if (!empty($in['website'])) return [null, 'Inscription refusée.'];                                      // champ piège anti-robots
    if (!$first && trq_config('registration') === 'closed') return [null, "Les inscriptions sont fermées."];
    if (!$first && trq_config('registration') === 'invite' && !hash_equals((string)trq_config('invite_code'), trim((string)($in['invite'] ?? ''))))
        return [null, "Code d'invitation invalide."];
    if (auth_count('register', 3600, true) >= 5) return [null, 'Trop d’inscriptions depuis cette adresse. Réessayez plus tard.'];
    if ($name === '' || mb_strlen($name) > 60) return [null, 'Indiquez votre nom (60 caractères maximum).'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return [null, 'Adresse e-mail invalide.'];
    if ($pw !== (string)($in['password2'] ?? '')) return [null, 'Les deux mots de passe ne correspondent pas.'];
    if ($e = pw_error($pw, $email)) return [null, $e];
    $pdo = auth_db();
    try {
        $pdo->beginTransaction();
        $role = auth_user_count() === 0 ? 'admin' : 'user';
        $pdo->prepare('INSERT INTO users (email, name, pass_hash, role, active, created_at) VALUES (?,?,?,?,1,?)')->execute([$email, $name, pw_hash($pw), $role, date('c')]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'Un compte existe déjà avec cette adresse e-mail.'];
    }
    auth_event('register');
    if ($role === 'admin') auth_adopt_legacy($uid);
    return [$uid, null];
}

/* ---------- réinitialisation du mot de passe ---------- */
function send_mail(string $to, string $subject, string $body): bool {
    $from = trq_config('mail_from') ?: 'no-reply@' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "From: TRQ <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: TRQ");
    /* Sécurité : le body contient des liens de réinitialisation valides 1 h — ne le loguer qu'en local (en prod, on garde la seule ligne d'état). */
    @file_put_contents(data_dir() . '/mail.log', date('c') . " to=$to ok=" . ($ok ? 1 : 0) . " subject=$subject\n" . (is_local_host() ? $body . "\n" : '') . "----\n", FILE_APPEND);
    return $ok;
}
function dev_mail_enabled(): bool { $d = trq_config('dev_mail'); return $d === null ? is_local_host() : (bool)$d; }

function auth_request_reset(string $email): array {
    $email = strtolower(trim($email)); $link = null;
    if (auth_count('reset', 3600, true) >= 5) return ['ok' => false, 'error' => 'Trop de demandes. Réessayez plus tard.'];
    auth_event('reset', $email);
    $st = auth_db()->prepare('SELECT * FROM users WHERE email=? AND active=1'); $st->execute([$email]); $u = $st->fetch();
    if ($u) {
        $token = bin2hex(random_bytes(32));
        auth_db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)')->execute([$u['id'], hash('sha256', $token), time() + 3600]);
        $link = base_url() . '/login.php?a=reset&token=' . $token;
        send_mail($email, 'TRQ – Réinitialisation de votre mot de passe',
            "Bonjour " . $u['name'] . ",\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure) :\n$link\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\n— TRQ\n");
    }
    return ['ok' => true, 'dev_link' => (dev_mail_enabled() && $link) ? $link : null];
}
function auth_reset_lookup(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = auth_db()->prepare('SELECT * FROM password_resets WHERE token_hash=? AND used=0 AND expires_at > ?'); $st->execute([hash('sha256', $token), time()]);
    return $st->fetch() ?: null;
}
function auth_reset_password(string $token, string $pw, string $pw2): ?string {
    $r = auth_reset_lookup($token); if (!$r) return 'Ce lien est invalide ou expiré. Refaites une demande.';
    $u = auth_db()->query('SELECT * FROM users WHERE id=' . (int)$r['user_id'])->fetch(); if (!$u) return 'Compte introuvable.';
    if ($pw !== $pw2) return 'Les deux mots de passe ne correspondent pas.';
    if ($e = pw_error($pw, $u['email'])) return $e;
    auth_db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([pw_hash($pw), $u['id']]);
    auth_db()->prepare('UPDATE password_resets SET used=1 WHERE user_id=?')->execute([$u['id']]);
    return null;
}

/* ---------- compte ---------- */
function auth_change_password(int $uid, string $current, string $new): ?string {
    $u = auth_db()->query('SELECT * FROM users WHERE id=' . $uid)->fetch();
    if (!$u || !password_verify($current, $u['pass_hash'])) return 'Mot de passe actuel incorrect.';
    if ($e = pw_error($new, $u['email'])) return $e;
    auth_db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([pw_hash($new), $uid]);
    return null;
}
function auth_delete_user(int $uid): void {
    foreach (['', '-wal', '-shm'] as $ext) { $f = user_db_path($uid) . $ext; if (is_file($f)) @unlink($f); }
    auth_db()->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$uid]);
    auth_db()->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
}
function auth_users_list(): array {
    $rows = auth_db()->query('SELECT id, email, name, role, active, created_at, last_login FROM users ORDER BY id')->fetchAll();
    foreach ($rows as &$r) { $f = user_db_path((int)$r['id']); $r['db_kb'] = is_file($f) ? (int)round(filesize($f) / 1024) : 0; }
    return $rows;
}

/* ---------- identité visuelle ---------- */
function brand_head_tags(): string {
    return '<meta name="theme-color" content="#0f3ab2">'
        . '<link rel="icon" href="assets/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">'
        . '<link rel="apple-touch-icon" href="assets/logo-180.png"><link rel="manifest" href="manifest.webmanifest">';
}
function brand_css(): string {
    return '<style>:root{--primary:#0f3ab2;--primary-foreground:#fff;--ring:#4a6fdb}.dark{--primary:#3d62e0;--primary-foreground:#fff;--ring:#6b8cf0}</style>';
}
