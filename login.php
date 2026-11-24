<?php
require_once __DIR__ . '/lib.php';
bootstrap_errors();
auth_boot();
if (!auth_enabled()) { header('Location: index.php'); exit; }

$a = $_GET['a'] ?? 'login';
if (!in_array($a, ['login', 'register', 'forgot', 'reset', 'logout'], true)) $a = 'login';
$me = current_user();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* destination après connexion : uniquement une page interne */
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('/^(index|sync)\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next) || strpos($next, 'sync.php') === 0) $next = '';
$dest = $next !== '' ? $next : 'index.php';

if ($a === 'logout') {
    if ($method === 'POST' && csrf_ok()) auth_logout_session();
    header('Location: login.php'); exit;
}
if ($me && in_array($a, ['login', 'register'], true)) { header('Location: ' . $dest); exit; }

$errors = []; $notice = ''; $devLink = null; $old = [];
$first = auth_user_count() === 0;
$legacy = $first && is_file(legacy_db_path());
$mode = (string)trq_config('registration');
$canRegister = auth_registration_open();

if ($method === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Session de formulaire expirée : rechargez la page puis réessayez.';
    } elseif ($a === 'login') {
        $old = ['email' => trim((string)($_POST['email'] ?? ''))];
        [$ok, $err] = auth_login($old['email'], (string)($_POST['password'] ?? ''), !empty($_POST['remember']));
        if ($ok) { header('Location: ' . $dest); exit; }
        $errors[] = $err;
    } elseif ($a === 'register') {
        $old = ['name' => trim((string)($_POST['name'] ?? '')), 'email' => trim((string)($_POST['email'] ?? ''))];
        [$uid, $err] = auth_register($_POST);
        if ($uid) { auth_start_session($uid, true); header('Location: index.php?p=settings&welcome=1'); exit; }
        $errors[] = $err;
    } elseif ($a === 'forgot') {
        $old = ['email' => trim((string)($_POST['email'] ?? ''))];
        $r = auth_request_reset($old['email']);
        if (!$r['ok']) $errors[] = $r['error'];
        else { $notice = "Si un compte correspond à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé (valable 1 heure)."; $devLink = $r['dev_link']; }
    } elseif ($a === 'reset') {
        $err = auth_reset_password((string)($_POST['token'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($err) $errors[] = $err; else { header('Location: login.php?reset_ok=1'); exit; }
    }
}
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$tokenValid = $a === 'reset' && auth_reset_lookup($token) !== null;
if ($a === 'login' && isset($_GET['reset_ok'])) $notice = 'Mot de passe modifié. Vous pouvez vous connecter.';

$titles = ['login' => 'Connexion', 'register' => 'Créer un compte', 'forgot' => 'Mot de passe oublié', 'reset' => 'Nouveau mot de passe'];
$csrf = csrf_token();
$minPw = max(8, (int)trq_config('min_password'));
function f_old(array $o, string $k): string { return htmlspecialchars($o[$k] ?? '', ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="<?= h(trq_lang()) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titles[$a]) ?> · TRQ</title>
<?= brand_head_tags() ?>
<script>
if (localStorage.theme === 'dark' || (!localStorage.theme && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark');
var bcStyle = localStorage.bcStyle || 'mira';
document.write('<link rel="stylesheet" href="assets/vendor/basecoat-' + bcStyle + '.cdn.min.css">');
</script>
<?= brand_css() ?>
<script src="assets/vendor/tailwind-browser.js"></script>
<style type="text/tailwindcss">
  @custom-variant dark (&:is(html.dark *));
  @theme inline {
    --color-background: var(--background); --color-foreground: var(--foreground);
    --color-card: var(--card); --color-primary: var(--primary); --color-primary-foreground: var(--primary-foreground);
    --color-muted: var(--muted); --color-muted-foreground: var(--muted-foreground);
    --color-accent: var(--accent); --color-destructive: var(--destructive); --color-border: var(--border); --color-input: var(--input); --color-ring: var(--ring);
  }
  @layer base { *, ::before, ::after { border-color: var(--border); } }
</style>
<style>
  body { font-family: 'Geist', ui-sans-serif, system-ui, sans-serif; letter-spacing: -0.005em; }
  .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>
</head>
<body class="min-h-screen bg-background text-foreground antialiased">
<div class="grid min-h-screen lg:grid-cols-2">

  <aside class="relative hidden flex-col justify-between overflow-hidden p-10 text-white lg:flex" style="background:#0f3ab2">
    <div class="flex items-center gap-3">
      <img src="assets/logo-192.png" alt="" class="size-10 rounded-xl ring-1 ring-white/25">
      <span class="text-xl font-semibold tracking-tight">TRQ</span>
    </div>
    <div class="relative z-10 space-y-6">
      <img src="assets/wordmark-white.png" alt="TRQ" class="w-56">
      <p class="max-w-md text-2xl font-semibold leading-snug">Analysez votre entraînement. Construisez vos séances. Progressez.</p>
      <ul class="space-y-2 text-sm text-white/85">
        <li>✓ Charge, forme et fraîcheur (PMC), courbes de puissance et modèle CP</li>
        <li>✓ Analyse détaillée de chaque sortie : tours, carte, télémétrie</li>
        <li>✓ Workout Builder à la puissance ou à la fréquence cardiaque</li>
        <li>✓ Synchronisation avec intervals.icu</li>
      </ul>
    </div>
    <svg class="pointer-events-none absolute inset-x-0 bottom-0 h-56 w-full" viewBox="0 0 600 220" preserveAspectRatio="none" aria-hidden="true">
      <g fill="#fff">
        <polygon opacity=".10" points="0,220 0,150 90,110 90,220"/><rect opacity=".14" x="90" y="70" width="80" height="150"/><rect opacity=".07" x="170" y="130" width="50" height="90"/>
        <rect opacity=".14" x="220" y="70" width="80" height="150"/><rect opacity=".07" x="300" y="130" width="50" height="90"/><rect opacity=".14" x="350" y="70" width="80" height="150"/>
        <rect opacity=".07" x="430" y="130" width="50" height="90"/><polygon opacity=".10" points="480,220 480,130 600,170 600,220"/>
      </g>
    </svg>
    <p class="relative z-10 text-xs text-white/60">© <?= date('Y') ?> TRQ</p>
  </aside>

  <main class="flex items-center justify-center p-6">
    <div class="w-full max-w-sm space-y-6">
      <div class="flex items-center gap-3 lg:hidden">
        <img src="assets/logo-192.png" alt="TRQ" class="size-10 rounded-xl"><span class="text-xl font-semibold tracking-tight">TRQ</span>
      </div>

      <div class="space-y-1">
        <h1 class="text-2xl font-bold tracking-tight"><?= h($titles[$a]) ?></h1>
        <p class="text-sm text-muted-foreground"><?php
          echo ['login' => 'Heureux de vous revoir. Connectez-vous pour accéder à vos données.',
                'register' => 'Créez votre espace personnel : vos données restent privées et isolées.',
                'forgot' => 'Saisissez votre adresse e-mail pour recevoir un lien de réinitialisation.',
                'reset' => 'Choisissez un nouveau mot de passe.'][$a]; ?></p>
      </div>

      <?php foreach ($errors as $e): ?>
        <div class="alert" data-variant="destructive"><h2><?= h($e) ?></h2></div>
      <?php endforeach; ?>
      <?php if ($notice): ?><div class="alert"><h2><?= h($notice) ?></h2></div><?php endif; ?>
      <?php if ($devLink): ?>
        <div class="alert"><h2>Mode développement : e-mail non envoyé</h2><section class="break-all text-xs">Lien de réinitialisation : <a class="underline" href="<?= h($devLink) ?>"><?= h($devLink) ?></a></section></div>
      <?php endif; ?>

      <?php if ($a === 'login'): ?>
      <form method="post" action="login.php?a=login" class="form grid gap-4" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="next" value="<?= h($next) ?>">
        <div class="grid gap-2"><label for="email">Adresse e-mail</label><input class="input" type="email" id="email" name="email" value="<?= f_old($old, 'email') ?>" required autofocus autocomplete="username"></div>
        <div class="grid gap-2">
          <div class="flex items-center justify-between"><label for="password">Mot de passe</label><a class="text-xs text-muted-foreground underline" href="login.php?a=forgot">Mot de passe oublié ?</a></div>
          <div class="relative"><input class="input pr-16" type="password" id="password" name="password" required autocomplete="current-password"><button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground" data-toggle="password">Afficher</button></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1" checked> Rester connecté <?= (int)trq_config('session_days') ?> jours</label>
        <button class="btn w-full" type="submit">Se connecter</button>
      </form>
      <?php if ($canRegister): ?><p class="text-center text-sm text-muted-foreground">Pas encore de compte ? <a class="font-medium text-foreground underline" href="login.php?a=register">Créer un compte</a></p><?php endif; ?>

      <?php elseif ($a === 'register'): ?>
        <?php if (!$canRegister): ?>
          <div class="alert"><h2>Les inscriptions sont fermées.</h2><section>Contactez l’administrateur pour obtenir un accès.</section></div>
        <?php else: ?>
        <?php if ($legacy): ?><div class="alert"><h2>Données existantes détectées</h2><section class="text-xs">Ce premier compte (administrateur) reprendra automatiquement vos données actuelles : activités, séances, réglages.</section></div><?php endif; ?>
        <form method="post" action="login.php?a=register" class="form grid gap-4" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <div class="hp" aria-hidden="true"><label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="grid gap-2"><label for="name">Nom</label><input class="input" id="name" name="name" value="<?= f_old($old, 'name') ?>" required maxlength="60" autocomplete="name" autofocus></div>
          <div class="grid gap-2"><label for="email">Adresse e-mail</label><input class="input" type="email" id="email" name="email" value="<?= f_old($old, 'email') ?>" required autocomplete="username"></div>
          <div class="grid gap-2">
            <label for="password">Mot de passe</label>
            <div class="relative"><input class="input pr-16" type="password" id="password" name="password" required minlength="<?= $minPw ?>" autocomplete="new-password"><button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground" data-toggle="password">Afficher</button></div>
            <div class="h-1 overflow-hidden rounded-full bg-muted"><div id="pwBar" class="h-full w-0 transition-all"></div></div>
            <p class="text-xs text-muted-foreground"><?= $minPw ?> caractères minimum. Une phrase de passe est plus sûre qu’un mot compliqué.</p>
          </div>
          <div class="grid gap-2"><label for="password2">Confirmer le mot de passe</label><input class="input" type="password" id="password2" name="password2" required minlength="<?= $minPw ?>" autocomplete="new-password"></div>
          <?php if ($mode === 'invite' && !$first): ?><div class="grid gap-2"><label for="invite">Code d’invitation</label><input class="input" id="invite" name="invite" required autocomplete="off"></div><?php endif; ?>
          <button class="btn w-full" type="submit">Créer mon compte</button>
        </form>
        <?php endif; ?>
        <p class="text-center text-sm text-muted-foreground">Déjà inscrit ? <a class="font-medium text-foreground underline" href="login.php">Se connecter</a></p>

      <?php elseif ($a === 'forgot'): ?>
      <form method="post" action="login.php?a=forgot" class="form grid gap-4">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="grid gap-2"><label for="email">Adresse e-mail</label><input class="input" type="email" id="email" name="email" value="<?= f_old($old, 'email') ?>" required autofocus></div>
        <button class="btn w-full" type="submit">Envoyer le lien</button>
      </form>
      <p class="text-center text-sm text-muted-foreground"><a class="underline" href="login.php">← Retour à la connexion</a></p>

      <?php else: /* reset */ ?>
        <?php if (!$tokenValid && $method !== 'POST'): ?>
          <div class="alert" data-variant="destructive"><h2>Ce lien est invalide ou expiré.</h2></div>
          <p class="text-center text-sm"><a class="underline" href="login.php?a=forgot">Refaire une demande</a></p>
        <?php else: ?>
        <form method="post" action="login.php?a=reset" class="form grid gap-4">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="token" value="<?= h($token) ?>">
          <div class="grid gap-2"><label for="password">Nouveau mot de passe</label>
            <div class="relative"><input class="input pr-16" type="password" id="password" name="password" required minlength="<?= $minPw ?>" autocomplete="new-password" autofocus><button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground" data-toggle="password">Afficher</button></div>
            <div class="h-1 overflow-hidden rounded-full bg-muted"><div id="pwBar" class="h-full w-0 transition-all"></div></div>
            <p class="text-xs text-muted-foreground"><?= $minPw ?> caractères minimum.</p></div>
          <div class="grid gap-2"><label for="password2">Confirmer</label><input class="input" type="password" id="password2" name="password2" required minlength="<?= $minPw ?>" autocomplete="new-password"></div>
          <button class="btn w-full" type="submit">Enregistrer le mot de passe</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<script>
document.querySelectorAll('[data-toggle=password]').forEach(b => b.addEventListener('click', () => {
  const i = b.parentElement.querySelector('input'); const show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.textContent = show ? 'Masquer' : 'Afficher';
}));
const pw = document.getElementById('password'), bar = document.getElementById('pwBar');
if (pw && bar) pw.addEventListener('input', () => {
  const v = pw.value; let s = Math.min(v.length / <?= $minPw + 6 ?>, 1) * 60;
  if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s += 12; if (/\d/.test(v)) s += 12; if (/[^A-Za-z0-9]/.test(v)) s += 16;
  s = Math.min(100, s); bar.style.width = s + '%'; bar.style.background = s < 45 ? '#ef4444' : s < 75 ? '#f59e0b' : '#22c55e';
});
</script>
</body>
</html>
