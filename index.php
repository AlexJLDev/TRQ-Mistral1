<?php
require_once __DIR__ . '/lib.php';
bootstrap_errors();
$me = require_login();                       // redirige vers login.php si non connecté ; jeton CSRF exigé sur les POST
ob_start('csrf_inject_cb');                  // ajoute le jeton CSRF à tous les <form method="post">

$p = $_GET['p'] ?? 'dashboard';
$pdo = db();

/* ---------- Paramètres POST ---------- */
if ($p === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['api_key', 'athlete_id', 'ftp', 'lthr', 'max_hr', 'rest_hr', 'base_hrv', 'weight', 'body_fat'] as $k) {
        cfg_set($k, trim($_POST[$k] ?? ''));
    }
    /* SSL : champ présent uniquement dans le POST quand le bloc SSL est affiché (administrateur) */
    if (array_key_exists('ca_bundle', $_POST)) {
        cfg_set('ssl_verify', !empty($_POST['ssl_noverify']) ? '0' : '1');
        cfg_set('ca_bundle', trim($_POST['ca_bundle'] ?? ''));
    }
    if (preg_match('/^(\d+):(\d{2})$/', trim($_POST['run_thr_pace'] ?? ''), $m)) {
        cfg_set('run_thr_pace', (string)($m[1] * 60 + $m[2]));
    }
    header('Location: ?p=settings&saved=1');
    exit;
}

/* ---------- Post Wellness rapide ---------- */
if ($p === 'wellness' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
    header('Location: ?p=wellness&saved=1');
    exit;
}

/* ---------- Post Workout rapide ---------- */
if ($p === 'calendar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = trim($_POST['date'] ?? date('Y-m-d'));
    $sport = trim($_POST['sport'] ?? 'ride');
    $title = trim($_POST['title'] ?? 'Séance planifiée');
    $dur = (int)($_POST['duration_min'] ?? 60) * 60;
    $dist = (float)($_POST['distance_km'] ?? 0) * 1000;
    $tss = !empty($_POST['tss']) ? (float)$_POST['tss'] : 50.0;
    $notes = trim($_POST['notes'] ?? '');

    $ins = $pdo->prepare('INSERT INTO planned_workouts (date, sport, title, duration, distance, tss, notes) VALUES (?,?,?,?,?,?,?)');
    $ins->execute([$d, $sport, $title, $dur, $dist, $tss, $notes]);
    header("Location: ?p=calendar&saved=1&m=" . substr($d, 0, 7));
    exit;
}

/* ---------- Helpers d'affichage & Basecoat UI ---------- */
function stat_card(string $label, string $value, string $sub = '', string $iconSvg = ''): void {
    echo '<div class="card p-3 md:p-4 transition-all hover:border-foreground/30 min-w-0">';
    echo '<div class="flex items-center justify-between gap-1">';
    echo '<span class="text-[11px] md:text-xs font-medium text-muted-foreground uppercase tracking-wider truncate">' . h($label) . '</span>';
    if ($iconSvg) echo '<span class="text-muted-foreground/70 shrink-0">' . $iconSvg . '</span>';
    echo '</div>';
    echo '<div class="mt-1.5 md:mt-2 flex items-baseline gap-2">';
    echo '<span class="text-xl md:text-2xl font-bold tracking-tight tabular-nums">' . $value . '</span>';
    echo '</div>';
    if ($sub) echo '<div class="mt-1 text-[11px] md:text-xs text-muted-foreground">' . $sub . '</div>';
    echo '</div>';
}

function tss_badge(?float $tss, ?string $type = 'power'): string {
    if ($tss === null) return '–';
    $label = n($tss);
    $sub = match($type) {
        'rtss' => 'rTSS',
        'hrtss' => 'hrTSS',
        'load' => 'Load',
        default => 'TSS',
    };
    $variant = match($type) {
        'rtss' => 'data-variant="secondary"',
        'hrtss' => 'data-variant="outline"',
        default => '',
    };
    return '<span class="badge" ' . $variant . ' title="' . h($sub) . '">' . $label . ' <span class="text-[10px] opacity-75 font-normal ml-0.5">' . $sub . '</span></span>';
}

function sport_badge(string $sport, string $type = ''): string {
    return match($sport) {
        'ride' => '<span class="badge bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/25 gap-1">🚴 ' . h($type ?: 'Vélo') . '</span>',
        'run' => '<span class="badge bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/25 gap-1">🏃 ' . h($type ?: 'Course') . '</span>',
        'swim' => '<span class="badge bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 border border-cyan-500/25 gap-1">🏊 ' . h($type ?: 'Natation') . '</span>',
        default => '<span class="badge" data-variant="secondary">⚡ ' . h($type ?: 'Autre') . '</span>',
    };
}

function alert_sev(string $sev): array {
    [$box, $dot] = match($sev) {
        'bad' => ['border-red-500/30 bg-red-500/5', 'bg-red-500'],
        'warn' => ['border-amber-500/30 bg-amber-500/5', 'bg-amber-500'],
        default => ['border-sky-500/30 bg-sky-500/5', 'bg-sky-500'],
    };
    return ['box' => $box, 'dot' => '<span class="inline-block h-2 w-2 rounded-full ' . $dot . '"></span>'];
}

function render_goals_grid(array $gs): void {
    $gbar = function (float $cur, float $goal, string $color): string {
        $pct = $goal > 0 ? min(100, round($cur / $goal * 100)) : 0;
        return '<div class="h-1.5 w-full rounded-full bg-muted overflow-hidden"><div class="h-full rounded-full ' . $color . '" style="width:' . $pct . '%"></div></div>';
    };
    $gcol = ['ride' => 'bg-sky-500', 'run' => 'bg-emerald-500', 'other' => 'bg-purple-500'];
    foreach ($gs['sports'] as $sp => $s) {
        $ico = $sp === 'ride' ? '🚴' : ($sp === 'run' ? '🏃' : '⚡');
        echo '<div class="rounded-lg border p-3 space-y-1.5 min-w-0">';
        echo '<p class="text-sm font-medium">' . $ico . ' ' . h($s['label']) . '</p>';
        if ($s['goal_tss'] > 0) {
            echo '<p class="text-xs text-muted-foreground tabular-nums">TSS : <span class="font-medium text-foreground">' . n($s['tss']) . '</span> / ' . n($s['goal_tss']) . '</p>';
            echo $gbar($s['tss'], $s['goal_tss'], $gcol[$sp]);
        }
        if ($s['goal_time'] > 0) {
            echo '<p class="text-xs text-muted-foreground tabular-nums">Temps : <span class="font-medium text-foreground">' . fmt_dur($s['time']) . '</span> / ' . n($s['goal_time'] / 3600, 1) . ' h</p>';
            echo $gbar($s['time'], $s['goal_time'], $gcol[$sp]);
        }
        if ($s['goal_tss'] <= 0 && $s['goal_time'] <= 0) echo '<p class="text-xs text-muted-foreground">Pas d\'objectif pour ce sport</p>';
        echo '</div>';
    }
    echo '<div class="rounded-lg border p-3 space-y-1.5 bg-muted/30 min-w-0">';
    echo '<p class="text-sm font-medium">📊 Total</p>';
    echo '<p class="text-xs text-muted-foreground tabular-nums">TSS : <span class="font-medium text-foreground">' . n($gs['total_tss']) . '</span> / ' . n($gs['goal_tss']) . '</p>';
    echo $gbar($gs['total_tss'], $gs['goal_tss'], 'bg-emerald-500');
    echo '<p class="text-xs text-muted-foreground tabular-nums">Temps : <span class="font-medium text-foreground">' . fmt_dur($gs['total_time']) . '</span> / ' . n($gs['goal_time'] / 3600, 1) . ' h</p>';
    echo $gbar($gs['total_time'], $gs['goal_time'], 'bg-emerald-500');
    echo '</div>';
}

function readiness_badge(?float $score): string {
    if ($score === null) return '<span class="badge" data-variant="outline">Non renseigné</span>';
    if ($score >= 80) return '<span class="badge bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">Optimal (' . round($score) . ')</span>';
    if ($score >= 65) return '<span class="badge bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/30">Bon (' . round($score) . ')</span>';
    if ($score >= 50) return '<span class="badge bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30">Modéré (' . round($score) . ')</span>';
    return '<span class="badge" data-variant="destructive">Récup. requise (' . round($score) . ')</span>';
}

function head(string $title, string $active): void {
    $nav = [
        'dashboard' => ['Tableau de bord', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
        'activities' => ['Activités', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'],
        'calendar' => ['Calendrier', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>'],
        'power' => ['Courbes WKO5', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>'],
        'workouts' => ['Entraînements', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h.01M3 12h.01M3 17h.01"/><path d="M8 7h13M8 12h13M8 17h13"/></svg>'],
        'admin' => ['Administration', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
        'wellness' => ['Wellness & Santé', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>'],
        'nutrition' => ['Nutrition', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>'],
        'progress' => ['Progression', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>'],
        'zones' => ['Zones', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'],
        'settings' => ['Paramètres', '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],
    ];
    ?>
<!doctype html>
<html lang="fr" class="antialiased">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · TRQ</title>
<?= brand_head_tags() ?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<script>
if (localStorage.theme === 'dark' || (!localStorage.theme && matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
}
</script>
<!-- Assets 100 % locaux (assets/vendor) : aucune dépendance CDN, donc plus de page blanche si le réseau est lent ou bloqué -->
<script>
var bcStyle = localStorage.bcStyle || 'mira';
document.write('<link rel="stylesheet" href="assets/vendor/basecoat-' + bcStyle + '.cdn.min.css">');
</script>
<?= brand_css() ?>
<script>
window.__CSRF = document.querySelector('meta[name=csrf-token]').content;
(function () {                       // jeton CSRF sur tous les fetch() en POST + barre de chargement pendant les requêtes
  const f = window.fetch;
  let pending = 0;
  const bar = () => document.getElementById('topLoader');
  window.__trqTop = function (on) { const b = bar(); if (b) b.classList.toggle('trq-on', !!on); };
  window.fetch = function (u, o) {
    o = o || {};
    if (String(o.method || 'GET').toUpperCase() === 'POST') { const h = new Headers(o.headers || {}); h.set('X-CSRF-Token', window.__CSRF); o.headers = h; }
    pending++; window.__trqTop(true);
    return f.call(this, u, o).finally(() => { pending = Math.max(0, pending - 1); if (!pending) window.__trqTop(false); });
  };
})();
(function () {                       // barre de chargement sur navigations et formulaires
  const show = () => window.__trqTop && window.__trqTop(true);
  document.addEventListener('click', function (e) {
    const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#'
        || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.protocol === 'javascript:') return;
    if (a.host && a.host !== location.host) return;
    show();
  }, true);
  document.addEventListener('submit', show, true);
  document.addEventListener('pjax:send', show);
  window.addEventListener('pageshow', function () { window.__trqTop && window.__trqTop(false); });
})();
</script>
<script src="assets/vendor/tailwind-browser.js"></script>
<style type="text/tailwindcss">
  @custom-variant dark (&:is(html.dark *));
  @theme inline {
    --color-background: var(--background);       --color-foreground: var(--foreground);
    --color-card: var(--card);                   --color-card-foreground: var(--card-foreground);
    --color-popover: var(--popover);             --color-popover-foreground: var(--popover-foreground);
    --color-primary: var(--primary);             --color-primary-foreground: var(--primary-foreground);
    --color-secondary: var(--secondary);         --color-secondary-foreground: var(--secondary-foreground);
    --color-muted: var(--muted);                 --color-muted-foreground: var(--muted-foreground);
    --color-accent: var(--accent);               --color-accent-foreground: var(--accent-foreground);
    --color-destructive: var(--destructive);     --color-border: var(--border);
    --color-input: var(--input);                 --color-ring: var(--ring);
  }
  @layer base { *, ::before, ::after { border-color: var(--border); } }
</style>
<script src="assets/vendor/basecoat-all.min.js" defer></script>
<link rel="stylesheet" href="assets/vendor/leaflet.css">
<script src="assets/vendor/leaflet.js"></script>
<script src="assets/vendor/chart.umd.js"></script>
<script>
// Filet de sécurité : toute erreur JavaScript s'affiche au lieu de laisser un écran vide
window.addEventListener('error', function (e) {
  var d = document.getElementById('jsErr') || document.body.appendChild(Object.assign(document.createElement('div'), {id: 'jsErr'}));
  d.style.cssText = 'position:fixed;right:12px;bottom:12px;max-width:min(32rem,90vw);z-index:99999;padding:10px 14px;border-radius:10px;background:#7f1d1d;color:#fff;font:12px/1.4 system-ui';
  d.textContent = 'Erreur JavaScript : ' + e.message + (e.filename ? ' (' + e.filename.split('/').pop() + ':' + e.lineno + ')' : '');
});
</script>
<style>
/* Header 100% opaque (évite toute transparence gênante) */
.app-header {
  background-color: #ffffff !important;
  border-bottom: 1px solid #e4e4e7;
}
html.dark .app-header {
  background-color: #09090b !important;
  border-bottom: 1px solid #27272a;
}

/* Basecoat Dialog & Modal Perfection */
dialog.dialog {
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 4px);
  background: var(--card);
  color: var(--card-foreground);
  padding: 1.5rem;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
  margin: auto;
  max-width: 90vw;
  position: fixed;
  inset: 0;
  z-index: 50;
}
dialog.dialog::backdrop {
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(6px);
}
dialog.dialog:not([open]) {
  display: none;
}
@keyframes lapflash { from { background: color-mix(in oklab, var(--primary) 25%, transparent); } to { background: transparent; } }
tr.lap-flash { animation: lapflash 1.6s ease-out; }
.wo-flash { animation: lapflash 1.4s ease-out; }
.wo-step { scroll-margin-top: 6rem; }
/* Leaflet cohabite avec le preflight Tailwind (img { max-width:100% } casse les tuiles) */
.leaflet-container img { max-width: none !important; max-height: none !important; }
.leaflet-container { font: inherit; background: var(--muted); }
.leaflet-container a { color: inherit; }
.map-dark .leaflet-tile-pane { filter: invert(1) hue-rotate(180deg) brightness(.92) contrast(.88) saturate(.75); }
.dark .leaflet-bar a { background: var(--card); color: var(--foreground); border-color: var(--border); }
.dark .leaflet-bar a:hover { background: var(--accent); }
.dark .leaflet-control-attribution { background: color-mix(in oklab, var(--background) 75%, transparent); color: var(--muted-foreground); }
.table-container { border: 1px solid var(--border); border-radius: var(--radius); overflow-x: auto; }

/* Barre de chargement top (navigations + requêtes) */
#topLoader { position: fixed; top: 0; left: 0; right: 0; height: 2px; z-index: 99998; background: transparent; opacity: 0; pointer-events: none; transition: opacity .15s; }
#topLoader::before { content: ''; position: absolute; top: 0; bottom: 0; left: -40%; width: 40%; background: var(--primary); border-radius: 999px; animation: trqTopRun 1.1s ease-in-out infinite; }
#topLoader.trq-on { opacity: 1; }
@keyframes trqTopRun { 0% { left: -40%; } 100% { left: 100%; } }

/* Écran de démarrage (skeleton) — masque le flash de rendu tant que Tailwind JIT n'a pas fini */
#bootSkeleton { position: fixed; inset: 0; z-index: 99997; background: var(--background); overflow: hidden; transition: opacity .25s ease; }
#bootSkeleton.trq-done { opacity: 0; pointer-events: none; }
#bootSkeleton .skeleton { background: var(--muted); }
.trq-pulse { animation: trqPulse 1.6s ease-in-out infinite; }
@keyframes trqPulse { 0%, 100% { opacity: 1; } 50% { opacity: .55; } }

/* Navigation mobile : dock flottant en bas (la sidebar reste réservée au desktop) */
#mobileDock, #mobileSheet { display: none; }
@media (max-width: 767.98px) {
  #sidebar, #sidebarToggle { display: none !important; }
  main { padding-bottom: calc(5.5rem + env(safe-area-inset-bottom)); }
  #mobileDock {
    display: flex; align-items: center; gap: .25rem;
    position: fixed; left: 50%; transform: translateX(-50%);
    bottom: calc(.75rem + env(safe-area-inset-bottom));
    z-index: 40; padding: .4rem .55rem;
    background: var(--card); border: 1px solid var(--border); border-radius: 999px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, .16);
  }
  .dock-btn {
    display: flex; align-items: center; justify-content: center;
    width: 2.6rem; height: 2.6rem; border-radius: 999px;
    color: var(--foreground); transition: background .15s;
  }
  .dock-btn:hover { background: var(--muted); }
  .dock-btn.is-active { background: var(--primary); color: var(--primary-foreground); }
  .dock-btn.is-active svg { stroke: currentColor; }
  #mobileSheet.trq-open { display: block; position: fixed; inset: 0; z-index: 60; }
  #mobileSheet.trq-open > div:last-child { animation: trqSheetUp .18s ease-out; }
  @keyframes trqSheetUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
}
</style>
</head>
<body>
<div id="topLoader" aria-hidden="true"></div>
<div id="bootSkeleton" aria-hidden="true">
  <div class="mx-auto w-full max-w-6xl px-4 py-6 space-y-4">
    <div class="flex items-center gap-3">
      <div class="skeleton trq-pulse size-9 rounded-lg"></div>
      <div class="skeleton trq-pulse h-4 w-36 rounded"></div>
      <div class="skeleton trq-pulse h-4 w-20 rounded"></div>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="skeleton trq-pulse h-24 rounded-lg"></div>
      <div class="skeleton trq-pulse h-24 rounded-lg"></div>
      <div class="skeleton trq-pulse h-24 rounded-lg"></div>
      <div class="skeleton trq-pulse h-24 rounded-lg"></div>
    </div>
    <div class="skeleton trq-pulse h-64 rounded-lg"></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div class="skeleton trq-pulse h-40 rounded-lg"></div>
      <div class="skeleton trq-pulse h-40 rounded-lg"></div>
    </div>
  </div>
</div>
<script>
(function () {
  const sk = document.getElementById('bootSkeleton');
  let finished = false;
  const done = () => { if (finished || !sk) return; finished = true; sk.classList.add('trq-done'); setTimeout(() => sk.remove(), 350); };
  window.addEventListener('load', done);
  window.addEventListener('pageshow', function (e) { if (e.persisted) done(); });
  setTimeout(done, 2500);                      // filet de sécurité si 'load' traîne (tuile hors ligne, etc.)
})();
</script>
<?php
$groups = [
    'Analyse'     => ['dashboard', 'activities', 'calendar'],
    'Performance' => ['power', 'workouts', 'zones', 'progress'],
    'Santé'       => ['wellness', 'nutrition'],
    'Compte'      => array_merge(['settings'], (auth_enabled() && is_admin()) ? ['admin'] : []),
];
?>
<aside id="sidebar" class="sidebar" data-side="left">
  <nav aria-label="Navigation principale">
    <header>
      <a href="?p=dashboard" class="flex items-center gap-3">
        <img src="assets/logo-192.png" alt="TRQ" class="size-8 rounded-lg">
        <span class="grid text-left leading-tight"><span class="font-semibold tracking-tight">TRQ</span><span class="text-xs text-muted-foreground">v0.3 · intervals.icu</span></span>
      </a>
    </header>
    <section class="scrollbar-sm">
      <?php $gi = 0; foreach ($groups as $label => $keys): $gi++; ?>
      <div role="group" aria-labelledby="grp-<?= $gi ?>">
        <h3 id="grp-<?= $gi ?>"><?= h($label) ?></h3>
        <ul>
          <?php foreach ($keys as $k): [$l, $ico] = $nav[$k]; ?>
          <li><a href="?p=<?= $k ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= $ico ?><span><?= $l ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </section>
    <footer>
      <?php $cu = current_user(); $ini = mb_strtoupper(mb_substr(trim((string)($cu['name'] ?? '?')) ?: '?', 0, 1)); ?>
      <div class="flex items-center gap-2 px-2 py-1">
        <a href="?p=settings" class="flex min-w-0 flex-1 items-center gap-2 -m-1 rounded-md p-1 transition hover:bg-muted" title="Mes paramètres">
          <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"><?= h($ini) ?></span>
          <div class="min-w-0 flex-1 leading-tight">
            <p class="truncate text-sm font-medium"><?= h($cu['name'] ?? '') ?></p>
            <p class="truncate text-[11px] text-muted-foreground">FTP <?= h(cfg('ftp', '–')) ?> W<?= cfg('weight') ? ' · ' . h(cfg('weight')) . ' kg' : '' ?></p>
          </div>
        </a>
        <?php if (auth_enabled()): ?>
          <form method="post" action="login.php?a=logout">
            <button type="submit" class="btn" data-variant="ghost" data-size="icon-sm" title="Se déconnecter" aria-label="Se déconnecter">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </footer>
  </nav>
</aside>

<?php $dockKeys = ['dashboard', 'activities', 'calendar', 'progress']; ?>
<nav id="mobileDock" aria-label="Navigation mobile">
  <a href="?p=settings" class="dock-btn" title="Mes paramètres">
    <span class="flex size-8 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"><?= h($ini) ?></span>
  </a>
  <?php foreach ($dockKeys as $k): [$l, $ico] = $nav[$k]; ?>
  <a href="?p=<?= $k ?>" class="dock-btn<?= $active === $k ? ' is-active' : '' ?>" aria-label="<?= h($l) ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= $ico ?></a>
  <?php endforeach; ?>
  <button type="button" class="dock-btn" aria-label="Toutes les pages" onclick="trqSheet(true)">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
  </button>
</nav>

<div id="mobileSheet" role="dialog" aria-modal="true" aria-label="Toutes les pages">
  <div class="absolute inset-0 bg-black/50" onclick="trqSheet(false)"></div>
  <div class="absolute inset-x-0 bottom-0 max-h-[78vh] overflow-y-auto rounded-t-2xl border bg-card p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-xl">
    <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-muted-foreground/30" data-sheet-close onclick="trqSheet(false)"></div>
    <?php foreach ($groups as $label => $keys): ?>
    <div class="mb-4 last:mb-0">
      <h3 class="mb-1.5 px-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><?= h($label) ?></h3>
      <ul class="space-y-0.5">
        <?php foreach ($keys as $k): [$l, $ico] = $nav[$k]; ?>
        <li><a href="?p=<?= $k ?>" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium <?= $active === $k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted' ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= $ico ?><span><?= $l ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
    <?php if (auth_enabled()): ?>
    <form method="post" action="login.php?a=logout" class="mt-4 border-t pt-3">
      <button type="submit" class="btn w-full" data-variant="outline">Se déconnecter</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<script>
function trqSheet(open) {
  document.getElementById('mobileSheet').classList.toggle('trq-open', !!open);
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') trqSheet(false); });
</script>

<main>
  <header class="sticky top-0 z-20 flex h-14 items-center gap-2 border-b bg-background px-4">
    <button type="button" id="sidebarToggle" class="btn" data-variant="ghost" data-size="icon-sm" aria-label="Afficher ou masquer le menu" onclick="document.getElementById('sidebar').toggle()">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/></svg>
    </button>
    <span class="hidden text-sm text-muted-foreground sm:inline">TRQ</span>
    <span class="hidden text-sm text-muted-foreground sm:inline">/</span>
    <span class="truncate text-sm font-medium"><?= h($title) ?></span>
    <span class="flex-1"></span>
    <button class="btn gap-2" data-variant="outline" data-size="sm" id="syncBtn">
      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
      <span class="hidden sm:inline">Synchroniser</span>
    </button>
    <button class="btn" data-variant="ghost" data-size="icon-sm" id="themeBtn" aria-label="Basculer thème">
      <svg class="w-4 h-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
      <svg class="w-4 h-4 hidden dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2m-7.07-14.07 1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41"/></svg>
    </button>
  </header>

  <div id="syncBar" class="hidden border-b bg-muted/50">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-2 text-xs">
      <div class="flex items-center gap-2">
        <span class="inline-block size-2 animate-ping rounded-full bg-primary"></span>
        <span id="syncMsg" class="font-medium">Synchronisation en cours…</span>
      </div>
      <span class="text-[11px] text-muted-foreground" id="syncCount"></span>
    </div>
  </div>

  <div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
<?php }

function foot(): void { ?>
  </div>
</main>

<!-- Modales Universelles (Positionnées à la racine du document) -->

<!-- Modal Enregistrer Wellness -->
<dialog class="dialog" id="modal-wellness"
        onpointerdown="this.dataset.backdropPress = event.target === this"
        onclick="if (event.target === this && this.dataset.backdropPress === 'true') this.close()">
  <div class="sm:max-w-md w-full">
    <header class="flex items-center justify-between pb-3 border-b mb-4">
      <div>
        <h3 class="text-base font-semibold">Enregistrer le Wellness du jour</h3>
        <p class="text-xs text-muted-foreground">Mesure matinale de santé & récupération</p>
      </div>
      <button type="button" class="btn" data-variant="ghost" data-size="icon" onclick="this.closest('dialog').close()">✕</button>
    </header>
    <section>
      <form method="post" action="?p=wellness" class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <div class="field">
            <label class="text-xs font-medium">Date</label>
            <input class="input text-sm" type="date" name="date" id="well-date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="field">
            <label class="text-xs font-medium">Poids (kg)</label>
            <input class="input text-sm" type="number" step="0.1" name="weight" placeholder="68.5" value="<?= cfg('weight') ?>">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="field">
            <label class="text-xs font-medium">HRV rMSSD (ms)</label>
            <input class="input text-sm" type="number" step="0.1" name="hrv" placeholder="65">
          </div>
          <div class="field">
            <label class="text-xs font-medium">FC au repos (bpm)</label>
            <input class="input text-sm" type="number" name="resting_hr" placeholder="48">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="field">
            <label class="text-xs font-medium">Sommeil (heures)</label>
            <input class="input text-sm" type="number" step="0.1" name="sleep_h" placeholder="7.5">
          </div>
          <div class="field">
            <label class="text-xs font-medium">Score Sommeil (0-100)</label>
            <input class="input text-sm" type="number" name="sleep_score" placeholder="85">
          </div>
        </div>

        <div class="grid grid-cols-3 gap-2">
          <div class="field">
            <label class="text-xs font-medium">Fatigue (1-5)</label>
            <select class="select text-xs" name="fatigue">
              <option value="1">1 (Frais)</option>
              <option value="2" selected>2 (Normal)</option>
              <option value="3">3 (Modérée)</option>
              <option value="4">4 (Élevée)</option>
              <option value="5">5 (Épuisé)</option>
            </select>
          </div>
          <div class="field">
            <label class="text-xs font-medium">Stress (1-5)</label>
            <select class="select text-xs" name="stress">
              <option value="1">1 (Zen)</option>
              <option value="2" selected>2 (Faible)</option>
              <option value="3">3 (Moyen)</option>
              <option value="4">4 (Élevé)</option>
            </select>
          </div>
          <div class="field">
            <label class="text-xs font-medium">Courbatures</label>
            <select class="select text-xs" name="soreness">
              <option value="1" selected>1 (Aucune)</option>
              <option value="2">2 (Légères)</option>
              <option value="3">3 (Moyennes)</option>
              <option value="4">4 (Fortes)</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="text-xs font-medium">Sensations / Notes</label>
          <input class="input text-sm" type="text" name="comments" placeholder="Bonne nuit, jambes légères…">
        </div>

        <footer class="flex items-center justify-end gap-2 pt-2 border-t">
          <button class="btn" data-variant="outline" type="button" onclick="this.closest('dialog').close()">Annuler</button>
          <button class="btn" type="submit">Enregistrer</button>
        </footer>
      </form>
    </section>
  </div>
</dialog>

<!-- Modal Planifier séance / Ajouter Workout -->
<dialog class="dialog" id="modal-workout"
        onpointerdown="this.dataset.backdropPress = event.target === this"
        onclick="if (event.target === this && this.dataset.backdropPress === 'true') this.close()">
  <div class="sm:max-w-md w-full">
    <header class="flex items-center justify-between pb-3 border-b mb-4">
      <div>
        <h3 class="text-base font-semibold">Planifier ou consigner une séance</h3>
        <p class="text-xs text-muted-foreground">Calendrier d'entraînement style TrainingPeaks</p>
      </div>
      <button type="button" class="btn" data-variant="ghost" data-size="icon" onclick="this.closest('dialog').close()">✕</button>
    </header>
    <section>
      <form method="post" action="?p=calendar" class="space-y-4">
        <div class="field">
          <label class="text-xs font-medium">Titre de la séance</label>
          <input class="input text-sm" type="text" name="title" placeholder="Ex: Intervalles VO2max 5x4min" required>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="field">
            <label class="text-xs font-medium">Date</label>
            <input class="input text-sm" type="date" name="date" id="plan-date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="field">
            <label class="text-xs font-medium">Discipline</label>
            <select class="select text-sm" name="sport">
              <option value="ride">🚴 Vélo</option>
              <option value="run">🏃 Course à pied</option>
              <option value="swim">🏊 Natation</option>
              <option value="other">⚡ Renforcement / Autre</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div class="field">
            <label class="text-xs font-medium">Durée (min)</label>
            <input class="input text-sm" type="number" name="duration_min" value="60" required>
          </div>
          <div class="field">
            <label class="text-xs font-medium">Distance (km)</label>
            <input class="input text-sm" type="number" step="0.1" name="distance_km" placeholder="30">
          </div>
          <div class="field">
            <label class="text-xs font-medium">TSS prévu</label>
            <input class="input text-sm" type="number" step="1" name="tss" value="60">
          </div>
        </div>

        <div class="field">
          <label class="text-xs font-medium">Description / Notes & Consignes</label>
          <textarea class="textarea text-sm" name="notes" rows="2" placeholder="Cibles de puissance, fractionné, RPE…"></textarea>
        </div>

        <footer class="flex items-center justify-end gap-2 pt-2 border-t">
          <button class="btn" data-variant="outline" type="button" onclick="this.closest('dialog').close()">Annuler</button>
          <button class="btn" type="submit">Ajouter au calendrier</button>
        </footer>
      </form>
    </section>
  </div>
</dialog>

<script>
function openWorkoutModal(dateStr = '') {
    if (dateStr) {
        const inp = document.getElementById('plan-date');
        if (inp) inp.value = dateStr;
    }
    const d = document.getElementById('modal-workout');
    if (d) d.showModal();
}

function openWellnessModal(dateStr = '') {
    if (dateStr) {
        const inp = document.getElementById('well-date');
        if (inp) inp.value = dateStr;
    }
    const d = document.getElementById('modal-wellness');
    if (d) d.showModal();
}

const styleSel = document.getElementById('styleSel');
if (styleSel) {
    styleSel.value = localStorage.bcStyle || 'mira';
    styleSel.onchange = e => { localStorage.bcStyle = e.target.value; location.reload(); };
}
document.getElementById('themeBtn').onclick = () => {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.theme = isDark ? 'dark' : 'light';
    location.reload();
};

async function pushPlanned(id) {
  try { await call('plan_push', {id}); location.reload(); } catch (e) { alert(e.message); }
}
async function deletePlanned(id) {
  if (!confirm('Supprimer cette séance planifiée ?')) return;
  try { await call('plan_delete', {id}); location.reload(); } catch (e) { alert(e.message); }
}
async function call(action, extra = {}) {
  const r = await fetch('sync.php', {method: 'POST', body: new URLSearchParams({action, ...extra})});
  let j; try { j = await r.json(); } catch (e) { throw new Error('Réponse invalide du serveur (HTTP ' + r.status + ').'); }
  if (j.login) { location.href = 'login.php'; throw new Error(j.error); }
  if (!j.ok) throw new Error(j.error);
  return j;
}

async function runSync(days = 365, recompute = false) {
    const bar = document.getElementById('syncBar');
    const msg = document.getElementById('syncMsg');
    const count = document.getElementById('syncCount');
    bar.classList.remove('hidden');

    try {
        let total, j;
        if (recompute) {
            msg.textContent = 'Réinitialisation des calculs locaux…';
            j = await call('recompute');
        } else {
            msg.textContent = 'Récupération des séances (intervals.icu)…';
            j = await call('list', {days});
            try { await call('sync_wellness', {days: 90}); } catch(e){}
        }
        total = j.remaining || 1;
        while (j.remaining > 0) {
            msg.textContent = `Traitement des flux & analyse : ${total - j.remaining} / ${total}`;
            count.textContent = Math.round(((total - j.remaining) / total) * 100) + '%';
            j = await call('process');
        }
        msg.textContent = 'Synchronisation terminée avec succès !';
        setTimeout(() => location.reload(), 600);
    } catch (e) {
        msg.textContent = 'Erreur : ' + e.message;
    }
}
document.getElementById('syncBtn').onclick = () => runSync(365);

// Configuration palette moderne Chart.js
const isDarkTheme = document.documentElement.classList.contains('dark');
Chart.defaults.color = isDarkTheme ? '#94a3b8' : '#64748b';
Chart.defaults.borderColor = isDarkTheme ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
Chart.defaults.font.family = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
</script>
</body>
</html>
<?php }

function empty_state(): void { ?>
    <div class="card p-8 text-center max-w-xl mx-auto my-12 border-dashed">
      <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-primary/10 text-primary mb-4 text-2xl">
        ⚡
      </div>
      <h3 class="text-xl font-bold tracking-tight">Aucune activité enregistrée</h3>
      <p class="text-sm text-muted-foreground mt-2 mb-6">
        Vous pouvez synchroniser votre compte intervals.icu ou générer un jeu de données de démonstration complet pour tester immédiatement toutes les fonctionnalités (intervalles, WKO5, wellness, calendrier TrainingPeaks).
      </p>
      <div class="flex items-center justify-center gap-3 flex-wrap">
        <button class="btn" onclick="call('seed_demo').then(()=>location.reload())">
          ✨ Générer données de démo
        </button>
        <a href="?p=settings" class="btn" data-variant="outline">
          ⚙️ Configurer clé API
        </a>
      </div>
    </div>
<?php }

$ftp = (float)cfg('ftp', 250);
$weight = (float)cfg('weight', 0);
$count = (int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();

/* ========================================================
   TABLEAU DE BORD (DASHBOARD)
   ======================================================== */
if ($p === 'dashboard') {
    head('Tableau de bord', 'dashboard');
    if (!$count) { empty_state(); foot(); exit; }

    $sport = in_array($_GET['sport'] ?? '', ['ride', 'run'], true) ? $_GET['sport'] : null;
    $pm = pmc($sport);
    $cur = end($pm) ?: ['ctl' => 0, 'atl' => 0, 'tsb' => 0, 'tss' => 0];
    $keys = array_keys($pm);
    $prev = count($keys) >= 8 ? $pm[$keys[count($keys) - 8]] : $cur;
    $slice = array_slice($pm, -180, null, true);

    $since = date('Y-m-d', strtotime('-7 days'));
    $w7 = $pdo->prepare('SELECT SUM(duration) d, SUM(tss) t, SUM(distance) km FROM activities WHERE date>=?' . ($sport ? ' AND sport=?' : ''));
    $w7->execute($sport ? [$since, $sport] : [$since]);
    $w7 = $w7->fetch();

    $tsb = $cur['tsb'];
    $state = match(true) {
        $tsb > 25 => ['Désentraînement', 'data-variant="outline"'],
        $tsb > 5 => ['Frais (Pic de forme)', 'class="badge bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30"'],
        $tsb > -10 => ['Zone Neutre', 'data-variant="secondary"'],
        $tsb > -30 => ['Zone Optimale (Charge)', 'class="badge bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/30"'],
        default => ['Risque Surtension', 'data-variant="destructive"'],
    };

    $wk = $pdo->query("SELECT strftime('%Y-W%W', date) w, sport, SUM(tss) t FROM activities WHERE date >= date('now','-84 days') GROUP BY w, sport ORDER BY w")->fetchAll();
    $weeks = [];
    foreach ($wk as $r) {
        $weeks[$r['w']][$r['sport']] = round((float)$r['t']);
    }

    $zt = array_fill(0, 7, 0);
    foreach ($pdo->query("SELECT zones FROM activities WHERE sport='ride' AND zones IS NOT NULL AND date >= date('now','-42 days')") as $r) {
        foreach (json_decode($r['zones'], true) as $i => $s) $zt[$i] += $s;
    }

    $best = [];
    foreach ($pdo->query("SELECT mmp FROM activities WHERE sport='ride' AND mmp IS NOT NULL AND date >= date('now','-90 days')") as $r) {
        foreach (json_decode($r['mmp'], true) as $d => $v) $best[$d] = max($best[$d] ?? 0, (float)$v);
    }

    $todayWell = $pdo->query("SELECT * FROM wellness ORDER BY date DESC LIMIT 1")->fetch();
    $recentActs = $pdo->query("SELECT " . ACT_COLS . " FROM activities ORDER BY date DESC LIMIT 5")->fetchAll();
    ?>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Tableau de bord de performance</h1>
        <p class="text-sm text-muted-foreground">Charge d'entraînement PMC, fatigue, fraîcheur et profil WKO5</p>
      </div>
      <div class="flex items-center gap-1.5 bg-muted p-1 rounded-lg">
        <?php foreach ([null => 'Tous', 'ride' => 'Vélo', 'run' => 'Course'] as $k => $l): ?>
          <a href="?p=dashboard<?= $k ? "&sport=$k" : '' ?>" class="btn text-xs <?= $sport === $k ? 'bg-background shadow-xs font-semibold' : '' ?>" data-variant="ghost" data-size="sm">
            <?= $l ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
      <?php
      stat_card('Forme (CTL)', n($cur['ctl'], 1), 'Rampe 7j : ' . sprintf('%+.1f', $cur['ctl'] - $prev['ctl']));
      stat_card('Fatigue (ATL)', n($cur['atl'], 1), 'Moyenne 7 jours');
      stat_card('Fraîcheur (TSB)', n($tsb, 1), '<span ' . $state[1] . '>' . $state[0] . '</span>');
      stat_card('7 derniers jours', n($w7['t']) . ' TSS', fmt_dur((int)$w7['d']) . ' · ' . n($w7['km'] / 1000) . ' km');
      stat_card('FTP réglé', n($ftp) . ' W', $weight ? n($ftp / $weight, 2) . ' W/kg' : 'Poids non défini');
      if ($todayWell) {
          stat_card('Wellness & HRV', (!empty($todayWell['hrv']) ? round($todayWell['hrv']) . ' ms' : '–'), readiness_badge($todayWell['readiness'] ?? null));
      } else {
          stat_card('Wellness', '–', '<button onclick="openWellnessModal()" class="underline text-xs">Enregistrer</button>');
      }
      ?>
    </div>

    <?php $gs = progress_goal_status(); ?>
    <?php if ($gs['goals_set']): ?>
    <div class="card" id="goalsCard">
      <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
          <h3 class="text-base font-semibold">Objectifs de la semaine</h3>
          <p class="text-xs text-muted-foreground">Réalisation depuis lundi — cibles modifiables dans <a class="hover:underline" href="?p=progress">Progression</a></p>
        </div>
        <span class="badge bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30"><?= n($gs['total_tss']) ?> / <?= n($gs['goal_tss']) ?> TSS</span>
      </header>
      <section class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
        <?php render_goals_grid($gs); ?>
      </section>
    </div>
    <?php endif; ?>

    <!-- PMC Chart -->
    <div class="card" id="pmcCard">
      <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
          <h3 class="text-base font-semibold">PMC Coggan — TSS</h3>
          <p class="text-xs text-muted-foreground">Forme (CTL 42 j), fatigue (ATL 7 j), charge quotidienne, et fraîcheur (TSB) par zones de Coggan</p>
        </div>
        <div class="flex items-center gap-1" id="pmcRange" role="group" aria-label="Période">
          <button type="button" class="btn" data-size="sm" data-variant="outline" data-days="90">3 mois</button>
          <button type="button" class="btn" data-size="sm" data-variant="outline" data-days="180">6 mois</button>
          <button type="button" class="btn" data-size="sm" data-variant="outline" data-days="365">1 an</button>
          <button type="button" class="btn" data-size="sm" data-variant="outline" data-days="0">Tout</button>
        </div>
      </header>
      <section class="p-4">
        <div class="h-72 w-full"><canvas id="pmc"></canvas></div>
        <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted-foreground" id="pmcLegend">
          <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 rounded" style="background:var(--pmc-ctl)"></span> CTL (forme)</span>
          <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 rounded" style="background:var(--pmc-atl)"></span> ATL (fatigue)</span>
          <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm" style="background:var(--pmc-bar)"></span> Charge</span>
        </div>
        <div class="mt-3 h-44 w-full border-t pt-3"><canvas id="tsb"></canvas></div>
      </section>
    </div>

    <?php $dashAlerts = progress_alerts(); if ($dashAlerts): ?>
    <div class="card" id="alertsCard">
      <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
          <h3 class="text-base font-semibold">Alertes &amp; signaux de surmenage</h3>
          <p class="text-xs text-muted-foreground">Charge, fraîcheur, ressenti et restitution — détails et réglages dans <a class="hover:underline" href="?p=progress">Progression</a></p>
        </div>
        <a href="?p=progress" class="btn" data-size="sm" data-variant="outline">Voir ma progression</a>
      </header>
      <section class="p-4 grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
        <?php foreach ($dashAlerts as $al): $sev = alert_sev($al['sev']); ?>
          <div class="rounded-lg border p-3 <?= $sev['box'] ?>">
            <div class="flex items-center gap-2 text-sm font-medium mb-1"><?= $sev['dot'] ?><?= h($al['title']) ?></div>
            <p class="text-xs text-muted-foreground"><?= h($al['msg']) ?></p>
          </div>
        <?php endforeach; ?>
      </section>
    </div>
    <?php endif; ?>

    <?php $heat = progress_heatmap(); $heatLvl = function (float $tss): string {
        if ($tss <= 0) return 'bg-muted';
        if ($tss < 100) return 'bg-emerald-500/25';
        if ($tss < 200) return 'bg-emerald-500/45';
        if ($tss < 350) return 'bg-emerald-500/70';
        return 'bg-emerald-500';
    }; ?>
    <div class="card" id="heatCard">
      <header class="p-4 border-b">
        <h3 class="text-base font-semibold">Assiduité — 12 derniers mois</h3>
        <p class="text-xs text-muted-foreground">Une case par jour, intensité selon le TSS (tous sports) · série en cours : <?= (int)$heat['streak'] ?> jour(s) · record : <?= (int)$heat['longest'] ?> jours</p>
      </header>
      <section class="p-4 space-y-3">
        <div class="overflow-x-auto pb-1">
          <div class="flex gap-[3px] w-max mx-auto sm:mx-0">
            <?php foreach (array_chunk($heat['cells'], 7, true) as $chunk): ?>
              <div class="flex flex-col gap-[3px]">
                <?php foreach ($chunk as $k => $c): ?>
                  <span class="inline-block h-3 w-3 rounded-[3px] <?= $heatLvl($c['tss']) ?>" title="<?= h(date('d/m', strtotime($k)) . ' — ' . ($c['n'] ? $c['n'] . ' séance(s), ' . round($c['tss']) . ' TSS' : 'repos')) ?>"></span>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted-foreground">
          <span><?= n($heat['sessions']) ?> séances</span>
          <span><?= n($heat['active_days']) ?> jours actifs</span>
          <span><?= n($heat['tss']) ?> TSS cumulés</span>
          <span class="inline-flex items-center gap-1.5 ml-auto">Moins
            <span class="inline-block h-3 w-3 rounded-[3px] bg-muted"></span>
            <span class="inline-block h-3 w-3 rounded-[3px] bg-emerald-500/25"></span>
            <span class="inline-block h-3 w-3 rounded-[3px] bg-emerald-500/45"></span>
            <span class="inline-block h-3 w-3 rounded-[3px] bg-emerald-500/70"></span>
            <span class="inline-block h-3 w-3 rounded-[3px] bg-emerald-500"></span>
          Plus</span>
        </div>
      </section>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">TSS hebdomadaire différencié</h3>
          <p class="text-xs text-muted-foreground">Répartition de la charge par sport (12 dernières semaines)</p>
        </header>
        <section class="p-4">
          <div class="h-64"><canvas id="weekly"></canvas></div>
        </section>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Temps par zone de puissance (Vélo)</h3>
          <p class="text-xs text-muted-foreground">Distribution aérobie / anaérobie Coggan (42 derniers jours)</p>
        </header>
        <section class="p-4">
          <div class="h-64"><canvas id="zones"></canvas></div>
        </section>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
      <div class="card lg:col-span-1">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Profil de puissance (90j)</h3>
          <p class="text-xs text-muted-foreground">Records de puissance MMP par durée</p>
        </header>
        <section class="p-0">
          <div class="table-container border-0 rounded-none">
            <table class="table">
              <thead>
                <tr><th>Durée</th><th>Watts</th><th>W/kg</th></tr>
              </thead>
              <tbody>
                <?php foreach ([5 => 'Pmax 5s', 60 => '1 min', 300 => '5 min VO2', 1200 => '20 min', 3600 => '1 heure'] as $d => $lbl): ?>
                  <tr>
                    <td class="font-medium text-xs"><?= $lbl ?></td>
                    <td class="tabular-nums font-semibold"><?= isset($best[$d]) ? n($best[$d]) . ' W' : '–' ?></td>
                    <td class="tabular-nums text-muted-foreground"><?= isset($best[$d]) && $weight ? n($best[$d] / $weight, 2) : '–' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="card lg:col-span-2">
        <header class="p-4 border-b flex items-center justify-between">
          <div>
            <h3 class="text-base font-semibold">Séances récentes</h3>
            <p class="text-xs text-muted-foreground">Dernières activités analysées</p>
          </div>
          <a href="?p=activities" class="text-xs text-primary font-medium hover:underline">Voir tout →</a>
        </header>
        <section class="p-0">
          <div class="table-container border-0 rounded-none">
            <table class="table">
              <thead>
                <tr><th>Date</th><th>Séance</th><th>Durée</th><th>TSS</th><th>NP / Allure</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentActs as $r): ?>
                  <tr>
                    <td class="text-xs whitespace-nowrap text-muted-foreground"><?= h(substr($r['date'], 0, 10)) ?></td>
                    <td>
                      <a href="?p=activity&id=<?= h($r['id']) ?>" class="font-medium hover:underline">
                        <?= h($r['name'] ?: $r['type']) ?>
                      </a>
                      <div class="mt-0.5"><?= sport_badge($r['sport'], $r['type']) ?></div>
                    </td>
                    <td class="text-xs tabular-nums"><?= fmt_dur((int)$r['duration']) ?></td>
                    <td><?= tss_badge($r['tss'] !== null ? (float)$r['tss'] : null, $r['tss_type'] ?? 'power') ?></td>
                    <td class="text-xs tabular-nums font-medium">
                      <?= $r['sport'] === 'run' ? pace($r['avg_speed'] ? (float)$r['avg_speed'] : null) . ' /km' : ($r['np'] ? n($r['np']) . ' W' : '–') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>

    <script>
    // ===== PMC Coggan : CTL / ATL / charge + panneau TSB à zones =====
    const PM_ALL = <?= json_encode($pm) ?>;
    const PM_KEYS = Object.keys(PM_ALL);
    (function () {
        const card = document.getElementById('pmcCard');
        if (!card) return;
        if (!PM_KEYS.length) { card.querySelector('section').innerHTML = '<p class="py-12 text-center text-sm text-muted-foreground">Pas encore de données de charge.</p>'; return; }
        const dark = document.documentElement.classList.contains('dark');
        const C = dark
            ? {ctl: '#38bdf8', atl: '#fb7340', bar: 'rgba(56,120,170,.40)', grid: 'rgba(148,163,184,.13)', txt: '#7b8aa0'}
            : {ctl: '#0284c7', atl: '#ea580c', bar: 'rgba(2,132,199,.25)', grid: 'rgba(100,116,139,.20)', txt: '#64748b'};
        card.style.setProperty('--pmc-ctl', C.ctl); card.style.setProperty('--pmc-atl', C.atl); card.style.setProperty('--pmc-bar', C.bar);
        const ZONES = [   // seuils TSB de Coggan
            {label: 'Transition', from: 25, to: Infinity, color: dark ? '#38bdf8' : '#0284c7'},
            {label: 'Frais', from: 5, to: 25, color: dark ? '#4ade80' : '#16a34a'},
            {label: 'Zone grise', from: -10, to: 5, color: dark ? '#94a3b8' : '#64748b'},
            {label: 'Optimal', from: -30, to: -10, color: dark ? '#f59e0b' : '#d97706'},
            {label: 'Risque élevé', from: -Infinity, to: -30, color: dark ? '#ef4444' : '#dc2626'},
        ];
        const zoneOf = v => ZONES.find(z => v >= z.from && v < z.to) || ZONES[2];
        const fmtDate = k => new Date(k + 'T12:00:00').toLocaleDateString('fr-FR', {weekday: 'short', day: 'numeric', month: 'long', year: 'numeric'});
        const AXIS_W = 44;
        let days = parseInt(localStorage.pmcRange ?? '365'); if (![0, 90, 180, 365].includes(days)) days = 365;
        let top = null, bot = null;

        const bandsPlugin = {   // bandes colorées des zones + libellés à droite
            id: 'tsbBands',
            beforeDatasetsDraw(chart) {
                const {ctx, chartArea: a, scales: {y}} = chart; ctx.save();
                ZONES.forEach(z => {
                    const y1 = y.getPixelForValue(Math.min(z.to, y.max)), y2 = y.getPixelForValue(Math.max(z.from, y.min));
                    if (!(y2 > y1)) return;
                    ctx.fillStyle = z.color + (dark ? '1f' : '1a'); ctx.fillRect(a.left, y1, a.right - a.left, y2 - y1);
                    ctx.font = '600 10px Geist, ui-sans-serif, system-ui, sans-serif'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
                    ctx.lineJoin = 'round'; ctx.lineWidth = 3; ctx.strokeStyle = dark ? 'rgba(17,17,19,.9)' : 'rgba(255,255,255,.92)'; ctx.strokeText(z.label, a.right - 6, (y1 + y2) / 2);   // halo de lisibilité
                    ctx.fillStyle = z.color; ctx.fillText(z.label, a.right - 6, (y1 + y2) / 2);
                });
                ctx.restore();
            }
        };
        const crossPlugin = {   // repère vertical au survol (les deux panneaux)
            id: 'pmcCross',
            afterDatasetsDraw(chart) {
                const act = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
                if (!act.length) return;
                const x = act[0].element.x, a = chart.chartArea, ctx = chart.ctx;
                ctx.save(); ctx.strokeStyle = dark ? 'rgba(226,232,240,.35)' : 'rgba(15,23,42,.30)'; ctx.setLineDash([3, 3]); ctx.beginPath(); ctx.moveTo(x, a.top); ctx.lineTo(x, a.bottom); ctx.stroke(); ctx.restore();
            }
        };
        const syncTo = (dst) => (evt, els) => {
            if (!dst) return;
            if (!els.length) { dst.setActiveElements([]); dst.tooltip.setActiveElements([], {x: 0, y: 0}); dst.update('none'); return; }
            const i = els[0].index, pt = dst.getDatasetMeta(dst === bot ? 0 : 1).data[i]; if (!pt) return;
            const act = [{datasetIndex: dst === bot ? 0 : 1, index: i}];
            dst.setActiveElements(act); dst.tooltip.setActiveElements(act, {x: pt.x, y: pt.y}); dst.update('none');
        };

        function build() {
            if (top) top.destroy(); if (bot) bot.destroy();
            const keys = days ? PM_KEYS.slice(-days) : PM_KEYS, n = keys.length;
            const monthStep = n <= 220 ? 1 : n <= 420 ? 2 : n <= 800 ? 3 : 6;
            const ticks = new Map(); let first = true;
            keys.forEach((k, i) => {
                const d = new Date(k + 'T12:00:00');
                if (d.getDate() === 1 && d.getMonth() % monthStep === 0) {
                    let t = d.toLocaleDateString('fr-FR', {month: 'short'}).toUpperCase();
                    if (first || d.getMonth() === 0) t += ' ' + d.getFullYear();
                    ticks.set(i, t); first = false;
                }
            });
            const tsb = keys.map(k => PM_ALL[k].tsb);
            const lo = Math.min(-40, Math.floor(Math.min(...tsb) - 5)), hi = Math.max(35, Math.ceil(Math.max(...tsb) + 5));
            const common = {
                maintainAspectRatio: false, animation: false, layout: {padding: {right: 6}},
                interaction: {mode: 'index', intersect: false},
            };
            const xAxis = show => ({grid: {display: false}, border: {display: false},
                ticks: {display: show, autoSkip: false, maxRotation: 0, color: C.txt, font: {size: 10, weight: '600'}, callback: (v, i) => ticks.get(i) || ''}});

            top = new Chart('pmc', {
                type: 'bar',
                data: {labels: keys, datasets: [
                    {type: 'line', label: 'CTL (forme)', data: keys.map(k => PM_ALL[k].ctl), borderColor: C.ctl, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 4, cubicInterpolationMode: 'monotone', order: 1},
                    {type: 'line', label: 'ATL (fatigue)', data: keys.map(k => PM_ALL[k].atl), borderColor: C.atl, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 4, cubicInterpolationMode: 'monotone', order: 2},
                    {type: 'bar', label: 'Charge (TSS)', data: keys.map(k => PM_ALL[k].tss), backgroundColor: C.bar, borderWidth: 0, borderRadius: 1, categoryPercentage: 0.7, barPercentage: 1, order: 3},
                ]},
                options: {...common,
                    onHover: (e, els) => syncTo(bot)(e, els),
                    scales: {
                        x: xAxis(true),
                        y: {min: 0, grid: {color: C.grid}, border: {display: false}, ticks: {color: C.txt, font: {size: 10}, maxTicksLimit: 6}, afterFit: a => { a.width = AXIS_W; }},
                    },
                    plugins: {legend: {display: false}, tooltip: {callbacks: {
                        title: it => fmtDate(keys[it[0].dataIndex]),
                        label: c => ` ${c.dataset.label} : ${Math.round(c.parsed.y * 10) / 10}`,
                        footer: it => { const t = PM_ALL[keys[it[0].dataIndex]].tsb; return `TSB : ${t.toFixed(1)} · ${zoneOf(t).label}`; },
                    }}},
                },
                plugins: [crossPlugin],
            });
            bot = new Chart('tsb', {
                type: 'line',
                data: {labels: keys, datasets: [{label: 'TSB (fraîcheur)', data: tsb, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 4, cubicInterpolationMode: 'monotone',
                    borderColor: C.txt, segment: {borderColor: ctx => zoneOf((ctx.p0.parsed.y + ctx.p1.parsed.y) / 2).color},
                    pointHoverBackgroundColor: ctx => zoneOf(tsb[ctx.dataIndex] ?? 0).color}]},
                options: {...common,
                    onHover: (e, els) => syncTo(top)(e, els),
                    scales: {
                        x: xAxis(false),
                        y: {min: lo, max: hi, grid: {display: false}, border: {display: false}, ticks: {display: false}, afterFit: a => { a.width = AXIS_W; }},
                    },
                    plugins: {legend: {display: false}, tooltip: {callbacks: {
                        title: it => fmtDate(keys[it[0].dataIndex]),
                        label: c => { const z = zoneOf(c.parsed.y); return ` TSB : ${c.parsed.y.toFixed(1)} · ${z.label}`; },
                    }}},
                },
                plugins: [bandsPlugin, crossPlugin],
            });
            document.querySelectorAll('#pmcRange button').forEach(b => { if (+b.dataset.days === days) b.removeAttribute('data-variant'); else b.dataset.variant = 'outline'; });
        }
        document.querySelectorAll('#pmcRange button').forEach(b => b.onclick = () => { days = +b.dataset.days; try { localStorage.pmcRange = days; } catch (e) {} build(); });
        build();
    })();

    const wk = <?= json_encode($weeks) ?>;
    const W = Object.keys(wk);
    new Chart('weekly', {
        type: 'bar',
        data: {
            labels: W,
            datasets: [
                { label: 'Vélo (TSS)', data: W.map(k => wk[k].ride || 0), backgroundColor: '#0284c7', borderRadius: 4 },
                { label: 'Course (rTSS)', data: W.map(k => wk[k].run || 0), backgroundColor: '#10b981', borderRadius: 4 },
                { label: 'Autre (hrTSS)', data: W.map(k => wk[k].other || 0), backgroundColor: '#8b5cf6', borderRadius: 4 }
            ]
        },
        options: {
            maintainAspectRatio: false,
            scales: { x: { stacked: true }, y: { stacked: true, title: { display: true, text: 'TSS total' } } }
        }
    });

    const zt = <?= json_encode($zt) ?>;
    new Chart('zones', {
        type: 'bar',
        data: {
            labels: ['Z1 Récup', 'Z2 Endurance', 'Z3 Tempo', 'Z4 Seuil', 'Z5 VO2max', 'Z6 Anaérobie', 'Z7 Neuromusc.'],
            datasets: [{
                data: zt.map(s => +(s / 3600).toFixed(1)),
                backgroundColor: ['#94a3b8', '#0ea5e9', '#10b981', '#f59e0b', '#f97316', '#f43f5e', '#a855f7'],
                borderRadius: 5
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { title: { display: true, text: 'Heures' } } }
        }
    });
    </script>
    <?php foot(); exit;
}

/* ========================================================
   ACTIVITÉS (ACTIVITIES LIST)
   ======================================================== */
if ($p === 'activities') {
    head('Activités', 'activities');
    if (!$count) { empty_state(); foot(); exit; }

    $sport = in_array($_GET['sport'] ?? '', ['ride', 'run', 'swim', 'other'], true) ? $_GET['sport'] : null;
    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;

    $where = [];
    $params = [];
    if ($sport) {
        $where[] = 'sport = ?';
        $params[] = $sport;
    }
    if ($search) {
        $where[] = '(name LIKE ? OR type LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $pdo->prepare("SELECT " . ACT_COLS . " FROM activities $whereSql ORDER BY date DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = $st->fetchAll();
    ?>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Activités</h1>
        <p class="text-sm text-muted-foreground">Historique de vos entraînements et métriques clés</p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <form method="get" class="flex items-center gap-2">
          <input type="hidden" name="p" value="activities">
          <?php if ($sport): ?><input type="hidden" name="sport" value="<?= h($sport) ?>"><?php endif; ?>
          <input class="input text-sm h-8" type="search" name="q" placeholder="Rechercher une séance…" value="<?= h($search) ?>">
        </form>

        <div class="flex items-center gap-1 bg-muted p-1 rounded-lg">
          <?php foreach ([null => 'Toutes', 'ride' => 'Vélo', 'run' => 'Course', 'swim' => 'Natation'] as $k => $l): ?>
            <a href="?p=activities<?= $k ? "&sport=$k" : '' ?>" class="btn text-xs <?= $sport === $k ? 'bg-background shadow-xs font-semibold' : '' ?>" data-variant="ghost" data-size="sm">
              <?= $l ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card p-0">
      <div class="table-container border-0 rounded-none">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Activité</th>
              <th>Durée</th>
              <th>Dist.</th>
              <th>D+</th>
              <th>NP / Vitesse</th>
              <th>IF</th>
              <th>Charge</th>
              <th>VI</th>
              <th>EF</th>
              <th>Découpl.</th>
              <th>kJ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="whitespace-nowrap text-xs text-muted-foreground"><?= h(substr($r['date'], 0, 10)) ?></td>
                <td>
                  <a href="?p=activity&id=<?= h($r['id']) ?>" class="font-medium hover:underline text-sm">
                    <?= h($r['name'] ?: $r['type']) ?>
                  </a>
                  <div class="mt-0.5"><?= sport_badge($r['sport'], $r['type']) ?></div>
                </td>
                <td class="tabular-nums text-xs"><?= fmt_dur((int)$r['duration']) ?></td>
                <td class="tabular-nums text-xs"><?= n($r['distance'] / 1000, 1) ?> km</td>
                <td class="tabular-nums text-xs"><?= n($r['elev']) ?> m</td>
                <td class="tabular-nums font-semibold text-xs">
                  <?= $r['sport'] === 'run' ? pace($r['avg_speed'] ? (float)$r['avg_speed'] : null) . ' /km' : ($r['np'] ? n($r['np']) . ' W' : '–') ?>
                </td>
                <td class="tabular-nums text-xs"><?= n($r['if_'], 2) ?></td>
                <td><?= tss_badge($r['tss'] !== null ? (float)$r['tss'] : null, $r['tss_type'] ?? 'power') ?></td>
                <td class="tabular-nums text-xs"><?= n($r['vi'], 2) ?></td>
                <td class="tabular-nums text-xs"><?= n($r['ef'], 2) ?></td>
                <td class="tabular-nums text-xs">
                  <?= $r['decoupling'] !== null ? (abs((float)$r['decoupling']) > 5 ? '<span class="text-amber-500 font-medium">' . n($r['decoupling'], 1) . ' %</span>' : n($r['decoupling'], 1) . ' %') : '–' ?>
                </td>
                <td class="tabular-nums text-xs"><?= n($r['kj']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="flex items-center justify-between">
      <div class="text-xs text-muted-foreground">Affichage page <?= $page ?></div>
      <div class="flex items-center gap-2">
        <?php if ($page > 1): ?>
          <a class="btn" data-variant="outline" data-size="sm" href="?p=activities&page=<?= $page - 1 ?><?= $sport ? "&sport=$sport" : '' ?><?= $search ? "&q=" . urlencode($search) : '' ?>">← Précédent</a>
        <?php endif; ?>
        <?php if (count($rows) === $per): ?>
          <a class="btn" data-variant="outline" data-size="sm" href="?p=activities&page=<?= $page + 1 ?><?= $sport ? "&sport=$sport" : '' ?><?= $search ? "&q=" . urlencode($search) : '' ?>">Suivant →</a>
        <?php endif; ?>
      </div>
    </div>
    <?php foot(); exit;
}

/* ========================================================
   DÉTAIL ACTIVITÉ & ANALYSE COMPLETE WKO5 / TRAININGPEAKS
   ======================================================== */
if ($p === 'activity') {
    $st = $pdo->prepare('SELECT * FROM activities WHERE id=?');
    $st->execute([$_GET['id'] ?? '']);
    $a = $st->fetch();

    head($a['name'] ?? 'Activité', 'activities');
    if (!$a) {
        echo '<div class="alert" data-variant="destructive"><h3>Activité introuvable</h3></div>';
        foot(); exit;
    }

    $FEEL_LB = ['top' => 'Au top 💪', 'bien' => 'Bien 🙂', 'normal' => 'Normal 😐', 'dur' => 'Dur 😕', 'epuise' => 'Épuisé 🥵'];
    $actRpe = ($a['rpe'] ?? null) !== null && $a['rpe'] !== '' ? (int)$a['rpe'] : null;
    $actFeel = ($a['feel'] ?? null) ?: null;
    $askFeel = $actRpe === null || $actFeel === null;

    $S = $a['streams'] ? (json_decode(gzuncompress($a['streams']), true) ?: []) : [];
    $isRun = $a['sport'] === 'run';
    $wko = !empty($a['wko_metrics']) ? (json_decode($a['wko_metrics'], true) ?: []) : [];

    // Helper sécurisé pour éviter tout Undefined array key en PHP 8.1+
    $wval = function(string $k, string $unit = '', string $default = '–') use ($wko) {
        if (!isset($wko[$k]) || $wko[$k] === null || $wko[$k] === '') return $default;
        return $wko[$k] . ($unit ? " $unit" : '');
    };

    // Un seul pas d'échantillonnage pour toutes les séries (le temps du graphique = indice × pas)
    $nStream = stream_length($S);
    $step = max(1, (int)ceil($nStream / 800));
    $ds = function (array $x) use ($step): array {
        $x = clean($x);
        if ($step === 1) return $x;
        $o = [];
        for ($i = 0, $n = count($x); $i < $n; $i += $step) {
            $c = array_slice($x, $i, $step);
            $o[] = round(array_sum($c) / count($c), 1);
        }
        return $o;
    };
    $rawWatts = clean($S['watts'] ?? []);

    $smooth30 = [];
    if ($rawWatts) {
        $w30 = 0.0;
        for ($i = 0; $i < count($rawWatts); $i++) {
            $w30 += $rawWatts[$i];
            if ($i >= 30) $w30 -= $rawWatts[$i - 30];
            $smooth30[] = $i >= 29 ? round($w30 / 30, 1) : $rawWatts[$i];
        }
    }

    $powerSeries = $rawWatts ? $ds($rawWatts) : [];
    $power30Series = $smooth30 ? $ds($smooth30) : [];
    $hrSeries = !empty($S['heartrate']) ? $ds($S['heartrate']) : [];
    $cadSeries = !empty($S['cadence']) ? $ds($S['cadence']) : [];
    $spdSeries = !empty($S['velocity_smooth']) ? array_map(fn($v) => round($v * 3.6, 1), $ds($S['velocity_smooth'])) : [];
    $altSeries = !empty($S['altitude']) ? $ds($S['altitude']) : [];
    $gradeSeries = !empty($S['grade']) ? $ds($S['grade']) : [];
    $tempSeries  = !empty($S['temp']) ? $ds($S['temp']) : [];
    
    $wb = null;
    $cpm = null;
    if ($rawWatts) {
        $mm = $pdo->query("SELECT mmp FROM activities WHERE sport='ride' AND mmp IS NOT NULL AND date >= date('now','-90 days')");
        $curve = [];
        foreach ($mm as $r) foreach (json_decode($r['mmp'], true) as $d => $v) $curve[$d] = max($curve[$d] ?? 0, (float)$v);
        if ($cpm = cp_model($curve)) {
            $wb = $ds(array_map('floatval', wbal_series($rawWatts, $cpm['cp'], $cpm['wp'])));
        }
    }

    $zones = $a['zones'] ? json_decode($a['zones'], true) : null;
    $hrZones = $a['hr_zones'] ? json_decode($a['hr_zones'], true) : null;
    $intervals = $a['intervals'] ? (json_decode($a['intervals'], true) ?: []) : [];
    [$laps, $lapSource] = laps_for_view($a, $S, (float)$ftp);
    // Diagnostic automatique (règles) : ne doit jamais empêcher l'affichage de la page
    try { $diag = activity_diagnostic($a, $S, $laps, $lapSource, (float)$ftp, (float)cfg('lthr', 0)); }
    catch (Throwable $e) { $diag = null; error_log('diagnostic: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
    // Prévu vs réalisé : séance planifiée du jour (ou liée à la main) et pourcentage de respect
    $planPair = null; $planCmp = null; $planCand = [];
    try {
        $planPair = plan_pair_for_activity($a);
        if ($planPair) { $planCmp = pc_compare($planPair, $a, $S, (float)$ftp, (float)cfg('lthr', 0)); plan_cmp_store($planPair, $a, $planCmp); }
        else $planCand = plan_candidates($a);
    } catch (Throwable $e) { error_log('plan_compare: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
    // Carte : trace déjà stockée, sinon récupération à la volée (une seule fois) via sync.php?action=fetch_gps
    $gpsPayload = !empty($S['latlng']) ? gps_payload($S) : null;
    $showMap = $gpsPayload !== null || (!empty($S) && empty($S['gps_checked']));
    $lapLabel = ['manual' => 'tours personnalisés', 'icu' => 'tours (appareil / intervals.icu)', 'auto' => 'intervalles détectés', 'split' => 'splits de 1 km'][$lapSource] ?? 'tours';
    $lapLabelN = count($laps) . ' ' . ($lapSource === 'manual' && count($laps) === 1 ? 'tour personnalisé' : $lapLabel);

    $seasonBestMmp = [];
    $sBestQuery = $pdo->prepare("SELECT mmp FROM activities WHERE sport = ? AND mmp IS NOT NULL AND date >= date('now','-90 days')");
    $sBestQuery->execute([$a['sport']]);
    foreach ($sBestQuery as $r) {
        foreach (json_decode($r['mmp'], true) ?: [] as $d => $v) {
            $seasonBestMmp[(int)$d] = max($seasonBestMmp[(int)$d] ?? 0, (float)$v);
        }
    }
    $workoutMmp = $a['mmp'] ? json_decode($a['mmp'], true) : [];
    ?>
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <a class="text-xs text-muted-foreground hover:underline flex items-center gap-1" href="?p=activities">
          ← Retour aux activités
        </a>
        <div class="flex items-center gap-2">
          <button type="button" class="btn" data-variant="outline" data-size="sm" title="RPE & ressenti de la séance" onclick="document.getElementById('modal-feel').showModal()">
            <span id="feelChipText"><span class="font-semibold">RPE</span> <?= $actRpe !== null ? $actRpe . '/10' : '—' ?><?= $actFeel !== null && isset($FEEL_LB[$actFeel]) ? ' · ' . h($FEEL_LB[$actFeel]) : '' ?></span>
          </button>
          <?= tss_badge($a['tss'] !== null ? (float)$a['tss'] : null, $a['tss_type'] ?? 'power') ?>
          <?= sport_badge($a['sport'], $a['type']) ?>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b pb-4">
        <div>
          <div class="flex items-center gap-1.5">
            <h1 class="text-2xl font-bold tracking-tight" id="actName"><?= h($a['name'] ?: $a['type']) ?></h1>
            <button type="button" class="btn" data-variant="ghost" data-size="icon-sm" aria-label="Renommer la séance" title="Renommer la séance" onclick="actEditName()">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg>
            </button>
          </div>
          <p class="text-xs text-muted-foreground mt-1">
            <?= h($a['date']) ?> · Analysée le <?= date('Y-m-d') ?>
          </p>
          <div id="actNameEdit" class="hidden mt-1.5 flex items-center gap-2">
            <input id="actNameInput" class="input h-8 w-64 text-sm" maxlength="120" value="<?= h($a['name']) ?>" placeholder="Nom de la séance"
                   onkeydown="if (event.key === 'Enter') { event.preventDefault(); actSaveName(); } else if (event.key === 'Escape') { actCancelName(); }">
            <button type="button" class="btn" data-size="sm" onclick="actSaveName()">Enregistrer</button>
            <button type="button" class="btn" data-variant="outline" data-size="sm" onclick="actCancelName()">Annuler</button>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn" data-variant="outline" data-size="sm" onclick="window.print()">Imprimer rapport</button>
        </div>
      </div>

      <script>
      function actEditName() {
        const inp = document.getElementById('actNameInput');
        inp.value = document.getElementById('actName').textContent;
        document.getElementById('actName').classList.add('hidden');
        document.getElementById('actNameEdit').classList.remove('hidden');
        inp.focus(); inp.select();
      }
      function actCancelName() {
        document.getElementById('actName').classList.remove('hidden');
        document.getElementById('actNameEdit').classList.add('hidden');
      }
      async function actSaveName() {
        const inp = document.getElementById('actNameInput');
        const name = inp.value.trim();
        if (!name) return;
        try {
          const r = await call('activity_rename', {id: <?= json_encode($a['id']) ?>, name});
          document.getElementById('actName').textContent = (r && r.name) || name;
          actCancelName();
        } catch (e) { alert(e.message); }
      }
      </script>

      <dialog class="dialog" id="modal-feel"
              onpointerdown="this.dataset.backdropPress = event.target === this"
              onclick="if (event.target === this && this.dataset.backdropPress === 'true') this.close()">
        <div class="sm:max-w-md w-full">
          <header class="flex items-center justify-between pb-3 border-b mb-4">
            <div>
              <h3 class="text-base font-semibold">RPE & ressenti de la séance</h3>
              <p class="text-xs text-muted-foreground">Charge perçue et sensation — complètent la charge objective (TSS)</p>
            </div>
            <button type="button" class="btn" data-variant="ghost" data-size="icon" onclick="this.closest('dialog').close()">✕</button>
          </header>
          <section class="space-y-4">
            <div class="field">
              <label class="text-xs font-medium">RPE — effort perçu (1 à 10)</label>
              <div class="flex items-center gap-3 mt-1">
                <input type="range" min="1" max="10" step="1" id="feelRpe" value="<?= $actRpe ?? 5 ?>" class="w-full accent-primary" oninput="document.getElementById('feelRpeVal').textContent = this.value + '/10';">
                <span class="text-sm font-semibold tabular-nums w-12 text-right" id="feelRpeVal"><?= $actRpe !== null ? $actRpe . '/10' : '5/10' ?></span>
              </div>
              <p class="mt-1 text-[11px] text-muted-foreground">1 = très très facile · 5 = soutenu · 7 = dur · 10 = maximal</p>
            </div>
            <div class="field">
              <label class="text-xs font-medium">Sensation générale</label>
              <div class="flex flex-wrap gap-1.5 mt-1" id="feelOptions">
                <?php foreach ($FEEL_LB as $k => $v): ?>
                <button type="button" class="btn" data-size="sm" data-k="<?= h($k) ?>" <?= $actFeel === $k ? '' : 'data-variant="outline"' ?> onclick="actPickFeel(this)"><?= h($v) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
          <footer class="flex items-center justify-between gap-2 pt-3 border-t mt-4">
            <span class="text-[11px] text-muted-foreground">Modifiable à tout moment via le badge en haut.</span>
            <div class="flex items-center gap-2">
              <button type="button" class="btn" data-variant="ghost" data-size="sm" onclick="this.closest('dialog').close()">Plus tard</button>
              <button type="button" class="btn" data-size="sm" onclick="actSaveFeel()">Enregistrer</button>
            </div>
          </footer>
        </div>
      </dialog>
      <script>
      const FEEL_LB = <?= json_encode($FEEL_LB, JSON_UNESCAPED_UNICODE) ?>;
      let actFeelSel = <?= json_encode($actFeel ?? 'bien') ?>;
      function actPickFeel(btn) {
        actFeelSel = btn.dataset.k;
        document.querySelectorAll('#feelOptions button').forEach(b => { if (b === btn) b.removeAttribute('data-variant'); else b.setAttribute('data-variant', 'outline'); });
      }
      async function actSaveFeel() {
        const rpe = document.getElementById('feelRpe').value;
        try {
          await call('activity_feel', {id: <?= json_encode($a['id']) ?>, rpe, feel: actFeelSel});
          document.getElementById('feelChipText').innerHTML = '<span class="font-semibold">RPE</span> ' + rpe + '/10' + (actFeelSel && FEEL_LB[actFeelSel] ? ' · ' + FEEL_LB[actFeelSel] : '');
          document.getElementById('modal-feel').close();
        } catch (e) { alert(e.message); }
      }
      <?php if ($askFeel): ?>
      document.getElementById('modal-feel').showModal();
      <?php endif; ?>
      </script>

      <!-- Métriques phares WKO5 & TrainingPeaks -->
      <?php
      $icoDur = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
      $icoDist = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M12.5 19h4.5a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h3"/></svg>';
      $icoLoad = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>';
      $icoPwr = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';
      $icoHr = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/></svg>';
      $icoEnr = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>';
      ?>
      <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 md:gap-4">
        <?php
        stat_card('Durée', fmt_dur_full((int)$a['duration']), '', '<span class="inline-flex items-center justify-center size-6 rounded-md bg-sky-500/10 text-sky-600 dark:text-sky-400 shrink-0">' . $icoDur . '</span>');
        stat_card('Distance', n($a['distance'] / 1000, 1) . ' km', $isRun ? pace((float)$a['avg_speed']) . ' /km' : n($a['avg_speed'] * 3.6, 1) . ' km/h', '<span class="inline-flex items-center justify-center size-6 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 shrink-0">' . $icoDist . '</span>');
        stat_card('Charge & IF', n($a['tss']) . ' TSS', 'IF : ' . n($a['if_'], 2), '<span class="inline-flex items-center justify-center size-6 rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400 shrink-0">' . $icoLoad . '</span>');
        stat_card($isRun ? 'Puissance moy.' : 'Normalized Power', n($a['np'] ?: $a['avg_w']) . ' W', 'Moyenne : ' . n($a['avg_w']) . ' W · VI ' . n($a['vi'], 2), '<span class="inline-flex items-center justify-center size-6 rounded-md bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 shrink-0">' . $icoPwr . '</span>');
        stat_card('FC Moy. / Max', ($a['avg_hr'] ? round($a['avg_hr']) . ' bpm' : '–'), $a['max_hr'] ? 'Max : ' . round($a['max_hr']) . ' bpm' : '', '<span class="inline-flex items-center justify-center size-6 rounded-md bg-rose-500/10 text-rose-600 dark:text-rose-400 shrink-0">' . $icoHr . '</span>');
        stat_card('Énergie & D+', n($a['elev']) . ' m D+', n($a['kj']) . ' kJ (' . n($a['kj'] ? $a['kj'] * 1.05 : 0) . ' kcal)', '<span class="inline-flex items-center justify-center size-6 rounded-md bg-orange-500/10 text-orange-600 dark:text-orange-400 shrink-0">' . $icoEnr . '</span>');
        ?>
      </div>







      
      <nav id="actAnchorBar" aria-label="Sommaire de la séance" class="sticky top-14 z-30 flex items-center gap-1 overflow-x-auto rounded-lg border bg-background/95 px-1.5 py-1.5 shadow-xs backdrop-blur [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <a href="#sec-synthese" class="act-anchor shrink-0 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">📋 Synthèse</a>
        <a href="#sec-parcours" class="act-anchor shrink-0 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">🗺️ Parcours</a>
        <a href="#sec-telemetrie" class="act-anchor shrink-0 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">📈 Télémétrie</a>
        <?php if (!empty($laps)): ?><a href="#sec-intervalles" class="act-anchor shrink-0 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">🔁 Intervalles</a><?php endif; ?>
        <a href="#sec-analyses" class="act-anchor shrink-0 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">🔬 Analyses avancées</a>
        <a href="#sec-distributions" class="act-anchor shrink-0 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">📊 Distributions</a>
      </nav>

      <div id="sec-synthese" class="space-y-6 scroll-mt-24">

      <!-- Nutrition (statement UCI 2026) -->
      <?php $nutPlan = nutrition_session_plan($a, nutrition_weight(), (float)$ftp); $nob = $nutPlan['onbike']; $nutPost = $nutPlan['post']; ?>
      <div class="card" id="nutritionCard">
        <header class="p-4 border-b flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 class="text-base font-semibold">Nutrition de la séance</h3>
            <p class="text-xs text-muted-foreground">Cibles calculées depuis la durée, l'intensité (IF) et l'énergie de la sortie · <?= h(NUTRITION_REF_SHORT) ?></p>
          </div>
          <a class="btn" data-variant="outline" data-size="sm" href="?p=nutrition">Module nutrition →</a>
        </header>
        <section class="p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Dépense estimée</p>
            <p class="mt-1 text-xl font-bold tabular-nums"><?= n($nutPlan['kcal']) ?> kcal</p>
            <p class="text-xs text-muted-foreground tabular-nums"><?= n($a['kj']) ?> kJ mécaniques · durée <?= fmt_dur_full((int)$a['duration']) ?></p>
          </div>
          <div>
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Glucides pendant</p>
            <p class="mt-1 text-xl font-bold tabular-nums"><?= $nob['target'] > 0 ? n($nob['target']) . ' g/h' : h(ucfirst($nob['mode'])) ?></p>
            <p class="text-xs text-muted-foreground"><?php if ($nob['total_g'] > 0): ?>≈ <?= $nob['total_g'] ?> g au total<?php elseif ($nob['mode'] === 'rincage'): ?>Rincage buccal suffisant<?php else: ?>Aucun glucide nécessaire<?php endif; ?></p>
          </div>
          <div>
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Hydratation</p>
            <p class="mt-1 text-xl font-bold tabular-nums"><?= $nutPlan['hydration']['ml_h'] > 0 ? n($nutPlan['hydration']['ml_h']) . ' ml/h' : '–' ?></p>
            <p class="text-xs text-muted-foreground tabular-nums"><?= $nutPlan['hydration']['ml_total'] > 0 ? '≈ ' . n($nutPlan['hydration']['ml_total']) . ' ml au total · pertes < 2 % du poids' : 'Pesez-vous avant/après pour calibrer' ?></p>
          </div>
          <div>
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Récupération (4 h)</p>
            <p class="mt-1 text-xl font-bold tabular-nums"><?= $nutPost['cho_g'] !== null ? $nutPost['cho_g'] . ' g CHO' : '–' ?></p>
            <p class="text-xs text-muted-foreground"><?= $nutPost['pro_g'] !== null ? '+ ' . $nutPost['pro_g'] . ' g protéines · ' . n($nutPost['cho_g_kg_h'], 1) . ' g/kg/h' : 'Renseignez votre poids' ?></p>
          </div>
        </section>
        <footer class="px-4 pb-3 text-[11px] text-muted-foreground"><?= h($nob['note']) ?> · Fourchettes de référence, pas un conseil diététique personnalisé. <a class="hover:underline" href="<?= h(NUTRITION_REF_DOI) ?>" target="_blank" rel="noopener">Source (DOI)</a></footer>
      </div>

      <!-- Diagnostic automatique -->
      <?php if (!empty($diag)): $dgColor = ['good' => '#10b981', 'info' => 'var(--primary)', 'warn' => '#f59e0b'][$diag['main']['level']] ?? 'var(--primary)'; ?>
        <div class="card" id="diagCard">
          <section class="p-5">
            <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
              <span class="inline-block size-2 rounded-full" style="background:<?= $dgColor ?>"></span> Diagnostic automatique
            </div>
            <p class="mt-1.5 text-base font-medium leading-snug"><?= h($diag['main']['text']) ?></p>
            <?php if (!empty($diag['extra'])): ?>
              <details class="mt-3 group">
                <summary class="cursor-pointer select-none text-xs text-muted-foreground hover:text-foreground"><?= count($diag['extra']) ?> autre<?= count($diag['extra']) > 1 ? 's' : '' ?> observation<?= count($diag['extra']) > 1 ? 's' : '' ?></summary>
                <ul class="mt-2 space-y-2 text-sm">
                  <?php foreach ($diag['extra'] as $ex): $c = ['good' => '#10b981', 'info' => 'var(--primary)', 'warn' => '#f59e0b'][$ex['level']] ?? 'var(--primary)'; ?>
                    <li class="flex gap-2"><span class="mt-1.5 inline-block size-1.5 shrink-0 rounded-full" style="background:<?= $c ?>"></span><span><?= h($ex['text']) ?></span></li>
                  <?php endforeach; ?>
                </ul>
                <p class="mt-3 text-[11px] text-muted-foreground">Analyse automatique indicative, calculée à partir de vos seuils (FTP, LTHR) et des données de la sortie.</p>
              </details>
            <?php endif; ?>
          </section>
        </div>
      <?php endif; ?>

      <!-- Prévu vs réalisé -->
      <?php $lvCol = ['good' => '#10b981', 'warn' => '#f59e0b', 'bad' => '#ef4444', 'info' => 'var(--primary)']; ?>
      <?php if (!empty($planCmp)): $pc = $planCmp; $pp = $planPair; $lc = $lvCol[$pc['level']] ?? 'var(--primary)';
            $pctOf = fn($v) => $v === null ? '–' : $v . '%'; $barCol = fn($v) => $v === null ? 'var(--muted)' : ($v >= 90 ? '#10b981' : ($v >= 75 ? '#84cc16' : ($v >= 60 ? '#f59e0b' : '#ef4444'))); ?>
        <div class="card" id="planCard">
          <header class="p-4 border-b flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-xs font-medium text-muted-foreground">Séance planifiée du <?= h(date('d/m/Y', strtotime($pp['date']))) ?></p>
              <h3 class="truncate text-base font-semibold"><?php if (!empty($pp['structure'])): ?><a class="hover:underline" href="?p=workouts&plan=<?= (int)$pp['id'] ?>" title="Ouvrir dans le builder">🧩 <?= h($pp['title']) ?></a><?php else: ?>📝 <?= h($pp['title']) ?><?php endif; ?></h3>
              <p class="text-xs text-muted-foreground tabular-nums">Prévu : <?= fmt_dur((int)$pc['dur']['planned']) ?> · <?= round($pc['tss']['planned']) ?> TSS · cibles en <?= $pc['mode'] === 'hr' ? 'fréquence cardiaque' : 'puissance' ?></p>
            </div>
            <div class="flex items-center gap-3">
              <div class="text-right"><p class="text-3xl font-bold leading-none tabular-nums" style="color:<?= $lc ?>"><?= (int)$pc['score'] ?>%</p><p class="mt-1 text-xs text-muted-foreground"><?= h($pc['verdict']) ?></p></div>
              <?php if (!empty($pp['activity_id'])): ?><button type="button" class="btn" data-variant="ghost" data-size="sm" onclick="planUnlink(<?= (int)$pp['id'] ?>)" title="Dissocier cette sortie de la séance planifiée">Délier</button><?php endif; ?>
            </div>
          </header>
          <section class="space-y-4 p-4">
            <?php if ($pc['summary']): ?><p class="text-sm font-medium"><?= h($pc['summary']) ?></p><?php endif; ?>
            <?php if ($pc['note']): ?><p class="text-xs text-muted-foreground"><?= h($pc['note']) ?></p><?php endif; ?>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <?php foreach ([['Respect des cibles', $pc['parts']['structure'], $pc['parts']['structure'] === null ? 'non évalué' : 'étape par étape'],
                              ['Durée', $pc['parts']['duration'], fmt_dur((int)$pc['dur']['planned']) . ' → ' . fmt_dur((int)$pc['dur']['actual'])],
                              ['Charge (TSS)', $pc['parts']['load'], round($pc['tss']['planned']) . ' → ' . round($pc['tss']['actual'])]] as [$lbl, $val, $sub]): ?>
                <div class="rounded-lg border p-3">
                  <p class="text-[10px] uppercase tracking-wide text-muted-foreground"><?= $lbl ?></p>
                  <p class="text-xl font-semibold tabular-nums"><?= $pctOf($val) ?></p>
                  <div class="my-1 h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full" style="width:<?= (int)($val ?? 0) ?>%;background:<?= $barCol($val) ?>"></div></div>
                  <p class="text-[11px] text-muted-foreground tabular-nums"><?= h($sub) ?></p>
                </div>
              <?php endforeach; ?>
              <div class="rounded-lg border p-3">
                <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Départ</p>
                <p class="text-xl font-semibold tabular-nums"><?= $pc['chart'] ? ($pc['offset'] >= 0 ? '+' : '−') . fmt_dur(abs((int)$pc['offset'])) : '–' ?></p>
                <p class="mt-1 text-[11px] text-muted-foreground"><?= $pc['mode'] === 'hr' ? 'décalage détecté (latence FC incluse)' : 'décalage détecté avec le plan' ?></p>
              </div>
            </div>
            <?php if ($pc['chart']): ?>
              <div>
                <div class="h-64 w-full"><canvas id="planChart"></canvas></div>
                <div class="mt-1 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted-foreground">
                  <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm" style="background:color-mix(in oklab, var(--primary) 35%, transparent);border:1px dashed var(--primary)"></span> Prévu (cible)</span>
                  <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 rounded" style="background:#ea580c"></span> Réalisé (<?= h($pc['unit']) ?>, lissé)</span>
                  <span class="ml-auto hidden items-center gap-1.5 sm:inline-flex">Respect par étape :
                    <?php foreach ([['#10b981', '≥ 90%'], ['#84cc16', '75–90%'], ['#f59e0b', '50–75%'], ['#ef4444', '< 50%']] as [$c, $t]): ?><span class="inline-flex items-center gap-1"><span class="inline-block size-2 rounded-sm" style="background:<?= $c ?>"></span><?= $t ?></span><?php endforeach; ?></span>
                </div>
                <div class="relative mt-2 h-3" style="margin-left:44px;margin-right:6px" aria-label="Respect par étape">
                  <?php foreach ($pc['steps'] as $stp): $sc = $stp['score']; ?>
                    <span class="absolute top-0 h-full rounded-[2px]" title="<?= h($stp['label'] . ($stp['rep'] ? ' (' . $stp['rep'] . ')' : '') . ' : ' . round($sc * 100) . '% de respect') ?>"
                          style="left:<?= round(($pc['chart']['off'] + $stp['start']) / $pc['chart']['n'] * 100, 3) ?>%;width:calc(<?= round($stp['dur'] / $pc['chart']['n'] * 100, 3) ?>% - 1px);background:<?= $sc >= 0.9 ? '#10b981' : ($sc >= 0.75 ? '#84cc16' : ($sc >= 0.5 ? '#f59e0b' : '#ef4444')) ?>"></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($pc['steps']): ?>
              <details>
                <summary class="cursor-pointer select-none text-xs text-muted-foreground hover:text-foreground">Détail par étape (<?= count($pc['steps']) ?>)</summary>
                <div class="mt-2 overflow-x-auto"><table class="table text-xs"><thead><tr><th>Étape</th><th>Début</th><th>Durée</th><th>Cible</th><th>Réalisé</th><th>Écart</th><th class="w-32">Respect</th></tr></thead><tbody>
                  <?php foreach ($pc['steps'] as $stp): $sp = (int)round($stp['score'] * 100); ?>
                    <tr><td class="whitespace-nowrap font-medium"><?= h($stp['label']) ?><?= $stp['rep'] ? ' <span class="text-muted-foreground">(' . h($stp['rep']) . ')</span>' : '' ?></td>
                      <td class="tabular-nums"><?= fmt_dur((int)$stp['start']) ?></td><td class="tabular-nums"><?= fmt_dur((int)$stp['dur']) ?></td>
                      <td class="whitespace-nowrap tabular-nums"><?= h($stp['target']) ?></td>
                      <td class="tabular-nums"><?= $stp['actual'] === null ? '–' : (int)$stp['actual'] . ' ' . h($pc['unit']) ?></td>
                      <td class="tabular-nums <?= abs($stp['dev_pct']) >= 5 ? ($stp['dev_pct'] < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') : 'text-muted-foreground' ?>"><?= $stp['dev_pct'] == 0 ? 'dans la cible' : ($stp['dev_pct'] > 0 ? '+' : '−') . abs($stp['dev_pct']) . '%' ?></td>
                      <td><div class="flex items-center gap-2"><div class="h-1.5 w-20 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full" style="width:<?= $sp ?>%;background:<?= $barCol($sp) ?>"></div></div><span class="w-8 tabular-nums"><?= $sp ?>%</span></div></td></tr>
                  <?php endforeach; ?></tbody></table></div>
              </details>
            <?php endif; ?>
            <details>
              <summary class="cursor-pointer select-none text-xs text-muted-foreground hover:text-foreground">Comment est calculé le pourcentage de respect ?</summary>
              <div class="mt-2 space-y-1 text-xs text-muted-foreground">
                <p><b>60 % cibles</b> : pour chaque étape, la moyenne réelle (fenêtre centrale, sans les transitions) est comparée à la plage cible. Dans la cible (±3 %) = 100 % ; à 20 % d’écart ou plus = 0 %. Les efforts comptent double par rapport aux récupérations et échauffements ; récupérer plus doucement n’est pas pénalisé.</p>
                <p><b>20 % durée</b> : 100 % entre 95 % et 110 % de la durée prévue. <b>20 % charge</b> : 100 % si le TSS réalisé est à ±10 % du TSS prévu.</p>
                <p>La séance réalisée est décalée dans le temps (jusqu’à −1 min / +10 min) pour retrouver le meilleur alignement avec le plan. Sans structure ou sans données de puissance / FC, seules la durée et la charge sont comparées.</p>
              </div>
            </details>
          </section>
        </div>
      <?php elseif (!empty($planCand)): ?>
        <div class="card" id="planLinkCard">
          <section class="space-y-2 p-4">
            <p class="text-sm font-medium">Une séance planifiée correspond-elle à cette sortie ?</p>
            <?php foreach ($planCand as $c2): ?>
              <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2 text-sm">
                <span class="min-w-0 truncate"><?= !empty($c2['structure']) ? '🧩' : '📝' ?> <b><?= h($c2['title']) ?></b> <span class="text-xs text-muted-foreground tabular-nums">· <?= h(date('d/m', strtotime($c2['date']))) ?> · <?= fmt_dur((int)$c2['duration']) ?> · <?= round($c2['tss']) ?> TSS</span></span>
                <button type="button" class="btn" data-size="sm" data-variant="outline" onclick='planLink(<?= (int)$c2['id'] ?>, <?= json_encode((string)$a['id']) ?>)'>Associer à cette sortie</button>
              </div>
            <?php endforeach; ?>
          </section>
        </div>
      <?php endif; ?>
      <script>
      async function planLink(planId, actId) { try { await call('plan_link', {plan_id: planId, activity_id: actId}); location.reload(); } catch (e) { alert(e.message); } }
      async function planUnlink(planId) { try { await call('plan_unlink', {plan_id: planId}); location.reload(); } catch (e) { alert(e.message); } }
      </script>

      </div>

      <div id="sec-parcours" class="space-y-6 scroll-mt-24">

      <!-- Aperçu du parcours (carte) -->
      <?php if ($showMap): ?>
        <div class="card" id="mapCard">
          <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold">Parcours</h3>
              <p class="text-xs text-muted-foreground">Trace colorée selon la métrique · survolez un tour pour le repérer sur la carte</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              <div class="flex items-center gap-1 flex-wrap" id="mapMetricTabs"></div>
              <select id="mapTiles" class="select h-8 w-48 text-sm" aria-label="Fond de carte">
                <option value="osm">Plan · OpenStreetMap</option>
                <option value="cycle">Vélo · CyclOSM</option>
                <option value="topo">Relief · OpenTopoMap</option>
                <option value="sat">Satellite · Esri</option>
              </select>
            </div>
          </header>
          <section class="p-0">
            <div class="relative isolate">
              <div id="mapBox" class="h-[380px] w-full rounded-b-xl"></div>
              <div id="mapMsg" class="absolute inset-0 z-[500] flex items-center justify-center text-sm text-muted-foreground">Chargement de la carte…</div>
              <div id="mapLegend" class="absolute bottom-6 left-2 z-[500] rounded-md border bg-background/90 px-2 py-1 text-[11px] shadow" style="display:none"></div>
            </div>
          </section>
        </div>
      <?php endif; ?>
      </div>

      <div id="sec-telemetrie" class="space-y-6 scroll-mt-24">

      <!-- Graphique Multi-Flux Télémétrique Moderne avec sélecteur de flux & Switcher Empilé / Unique -->
      <div class="card">
        <header class="p-4 border-b flex flex-col lg:flex-row lg:items-center justify-between gap-3">
          <div>
            <h3 class="text-base font-semibold">Télémétrie 1 Hz & Profil de l'effort (TrainingPeaks / WKO5)</h3>
            <p class="text-xs text-muted-foreground">Graphique unique ou empilé · glissez sur un graphique pour sélectionner une portion et créer un tour</p>
          </div>

          <div class="flex items-center gap-3 flex-wrap">
            <!-- Sélecteur de mode d'affichage -->
            <div class="inline-flex items-center p-0.5 rounded-lg bg-muted text-xs">
              <button type="button" id="btn-layout-overlay" class="px-2.5 py-1 rounded-md font-semibold bg-background shadow-xs transition" onclick="switchTelemetryLayout('overlay')">
                📊 Graphique unique
              </button>
              <button type="button" id="btn-layout-stacked" class="px-2.5 py-1 rounded-md font-medium text-muted-foreground hover:text-foreground transition" onclick="switchTelemetryLayout('stacked')">
                📑 Empilé par métrique
              </button>
            </div>

            <!-- Filtres pour mode superposé -->
            <div class="flex items-center gap-1.5 flex-wrap text-xs" id="streamFilters">
              <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-cyan-600 dark:text-cyan-400 font-semibold border border-cyan-500/20 text-[11px]">
                <input type="checkbox" id="chk-power" checked onchange="toggleDataset(0, this.checked)"> Watts
              </label>
              <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-indigo-500 font-semibold border border-indigo-500/20 text-[11px]">
                <input type="checkbox" id="chk-power30" checked onchange="toggleDataset(1, this.checked)"> 30s
              </label>
              <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-rose-500 font-semibold border border-rose-500/20 text-[11px]">
                <input type="checkbox" id="chk-hr" checked onchange="toggleDataset(2, this.checked)"> FC
              </label>
              <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-emerald-500 font-semibold border border-emerald-500/20 text-[11px]">
                <input type="checkbox" id="chk-spd" checked onchange="toggleDataset(3, this.checked)"> Vitesse
              </label>
              <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-amber-500 font-semibold border border-amber-500/20 text-[11px]">
                <input type="checkbox" id="chk-cad" checked onchange="toggleDataset(4, this.checked)"> Cadence
              </label>
              <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-slate-400 font-semibold border border-slate-500/20 text-[11px]">
                <input type="checkbox" id="chk-alt" checked onchange="toggleDataset(5, this.checked)"> Alt.
              </label>
              <?php if (!empty($gradeSeries)): ?>
                <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-teal-500 font-semibold border border-teal-500/20 text-[11px]">
                  <input type="checkbox" id="chk-grade" checked onchange="toggleDataset(<?= $wb ? 7 : 6 ?>, this.checked)"> Pente
                </label>
              <?php endif; ?>
              <?php if (!empty($tempSeries)): ?>
                <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-orange-500 font-semibold border border-orange-500/20 text-[11px]">
                  <input type="checkbox" id="chk-temp" checked onchange="toggleDataset(<?= ($wb ? 7 : 6) + (!empty($gradeSeries) ? 1 : 0) ?>, this.checked)"> Temp.
                </label>
              <?php endif; ?>
              <?php if ($wb): ?>
                <label class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted/60 cursor-pointer text-purple-500 font-semibold border border-purple-500/20 text-[11px]">
                  <input type="checkbox" id="chk-wb" checked onchange="toggleDataset(6, this.checked)"> W'bal
                </label>
              <?php endif; ?>
            </div>
          </div>
        </header>

        <section class="p-4">
          <div class="flex items-center justify-between gap-2 mb-2 text-[11px] text-muted-foreground">
            <span>Molette : zoom · Double-clic : dézoomer · Glisser : sélection d'un tour</span>
            <button type="button" id="zoomReset" class="hidden shrink-0 px-2 py-0.5 rounded-md border hover:bg-muted" onclick="zoomReset()">Réinitialiser le zoom</button>
          </div>

          <!-- 1. Conteneur Graphique Unique (Superposé) -->
          <div id="container-overlay" class="w-full">
            <div class="h-96 w-full"><canvas id="fullTelemetry"></canvas></div>
          </div>

          <!-- 2. Conteneur Graphiques Empilés par Type de Donnée (Panels Synchronisés WKO5) -->
          <div id="container-stacked" class="space-y-4 hidden w-full">
            <?php if (!empty($powerSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-cyan-600 dark:text-cyan-400 flex items-center gap-1.5">
                    ⚡ Puissance instantanée & 30s lissée (Watts)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    Moy: <strong><?= n($a['avg_w']) ?> W</strong> · NP: <strong><?= n($a['np']) ?> W</strong> · Pmax: <strong><?= $wval('pmax_1s', 'W') ?></strong> · Seuil FTP: <strong><?= n($ftp) ?> W</strong>
                  </span>
                </div>
                <div class="h-44 w-full"><canvas id="stkPower"></canvas></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($hrSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-rose-500 flex items-center gap-1.5">
                    💓 Fréquence Cardiaque (bpm)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    Moy: <strong><?= round($a['avg_hr']) ?> bpm</strong> · Max: <strong><?= round($a['max_hr']) ?> bpm</strong> · LTHR: <strong><?= n(cfg('lthr', 172)) ?> bpm</strong>
                  </span>
                </div>
                <div class="h-36 w-full"><canvas id="stkHr"></canvas></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($spdSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-emerald-500 flex items-center gap-1.5">
                    🚀 Vitesse (km/h)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    Moy: <strong><?= $wval('avg_speed') ?> km/h</strong> · Max: <strong><?= $wval('max_speed', 'km/h') ?></strong>
                  </span>
                </div>
                <div class="h-32 w-full"><canvas id="stkSpd"></canvas></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($cadSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-amber-500 flex items-center gap-1.5">
                    🔄 Cadence de pédalage (rpm)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    Moy: <strong><?= round($a['cadence'] ?? 0) ?> rpm</strong> · Max: <strong><?= $wval('cad_max', 'rpm') ?></strong> · Couple: <strong><?= $wval('avg_torque', 'N.m') ?></strong>
                  </span>
                </div>
                <div class="h-32 w-full"><canvas id="stkCad"></canvas></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($altSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-slate-400 flex items-center gap-1.5">
                    ⛰️ Altitude & Profil de dénivelé (m)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    D+ total: <strong><?= n($a['elev']) ?> m</strong>
                  </span>
                </div>
                <div class="h-32 w-full"><canvas id="stkAlt"></canvas></div>
              </div>
            <?php endif; ?>
            <?php if (!empty($gradeSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-teal-500 flex items-center gap-1.5">
                    📐 Pente instantanée (%)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    Max: <strong><?= number_format(max($gradeSeries), 1, ',', ' ') ?> %</strong> · Min: <strong><?= number_format(min($gradeSeries), 1, ',', ' ') ?> %</strong>
                  </span>
                </div>
                <div class="h-32 w-full"><canvas id="stkGrade"></canvas></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($tempSeries)): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-orange-500 flex items-center gap-1.5">
                    🌡️ Température extérieure (°C)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    Moy: <strong><?= number_format(array_sum($tempSeries) / count($tempSeries), 1, ',', ' ') ?> °C</strong> · Min/Max: <strong><?= min($tempSeries) ?> / <?= max($tempSeries) ?> °C</strong>
                  </span>
                </div>
                <div class="h-32 w-full"><canvas id="stkTemp"></canvas></div>
              </div>
            <?php endif; ?>

            <?php if ($wb): ?>
              <div class="border rounded-lg p-3 bg-muted/10">
                <div class="flex items-center justify-between mb-1.5 text-xs">
                  <span class="font-bold text-purple-500 flex items-center gap-1.5">
                    🔋 Batterie anaérobie W'bal (kJ)
                  </span>
                  <span class="tabular-nums text-muted-foreground text-[11px]">
                    W'bal min: <strong><?= $wval('min_wbal', 'kJ') ?> (<?= $wval('min_wbal_pct', '%') ?>)</strong> · W' initial: <strong><?= $cpm ? round($cpm['wp']/1000, 1) . ' kJ' : '20.0 kJ' ?></strong>
                  </span>
                </div>
                <div class="h-32 w-full"><canvas id="stkWb"></canvas></div>
              </div>
            <?php endif; ?>
          </div>
        
          <!-- Sélection : stats + création de tour -->
          <div id="selPanel" class="hidden mt-4 space-y-3 rounded-lg border bg-muted/40 p-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
              <span class="font-semibold tabular-nums" id="selTime"></span>
              <span class="flex flex-wrap items-center gap-1.5 text-xs" id="selStats"></span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <input id="selLabel" class="input h-8 w-56 text-sm" maxlength="40" placeholder="Nom du tour (optionnel)">
              <label class="inline-flex items-center gap-1.5 text-xs"><input type="checkbox" id="selWork" checked> Effort <span class="text-muted-foreground">(décoché = récupération)</span></label>
              <button type="button" class="btn" data-size="sm" onclick="selCreate()">＋ Créer un tour</button>
              <button type="button" class="btn" data-size="sm" data-variant="ghost" onclick="selClear()">Effacer la sélection (Échap)</button>
            </div>
            <p class="text-xs text-destructive" id="selMsg"></p>
            <p class="text-[11px] text-muted-foreground">Vos tours remplacent l'affichage des tours automatiques ; on peut les supprimer un par un ou revenir aux tours automatiques à tout moment.</p>
          </div>
        </section>
      </div>

      </div>

      <?php if (!empty($laps)): ?>
      <div id="sec-intervalles" class="space-y-6 scroll-mt-24">

      <!-- Graphique par tour (laps appareil / intervals.icu, intervalles détectés, ou splits 1 km en course) -->
      <?php if (!empty($laps)): ?>
        <div class="card" id="lapCard">
          <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold">Graphique par tour</h3>
              <p class="text-xs text-muted-foreground"><?= h($lapLabelN) ?> · largeur = durée · survolez une barre, cliquez pour voir la ligne du tableau</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              <?php if ($lapSource === 'manual'): ?><button type="button" class="btn" data-size="sm" data-variant="outline" onclick="clearManualLaps()">↺ Tours automatiques</button><?php endif; ?>
              <div class="flex items-center gap-1 flex-wrap" id="lapMetricTabs"></div>
            </div>
          </header>
          <section class="p-4">
            <div id="lapChartBox" class="relative w-full">
              <div id="lapTip" class="pointer-events-none absolute z-10 hidden rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md" style="display:none"></div>
            </div>
          </section>
        </div>
      <?php endif; ?>

      <!-- Analyse des intervalles & Laps (Tableau) -->
      <?php if (!empty($laps)): ?>
        <div class="card">
          <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
              <h3 class="text-base font-semibold">Tableau détaillé des tours & intervalles (WKO5 Interval Engine)</h3>
              <p class="text-xs text-muted-foreground">Statistiques par bloc : puissance normalisée, intensité, réserve W'bal et allures</p>
            </div>
            <div class="flex items-center gap-2">
              <span class="badge" data-variant="secondary"><?= h($lapLabelN) ?></span>
            </div>
          </header>
          <section class="p-0">
            <div class="table-container border-0 rounded-none">
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Durée</th>
                    <th>Dist.</th>
                    <th>Puissance</th>
                    <th>NP</th>
                    <th>IF</th>
                    <th>Zone</th>
                    <th>Vitesse</th>
                    <th>FC Moy.</th>
                    <th>FC Max</th>
                    <th>Cadence</th>
                    <th>W'bal min</th>
                    <th>EF</th>
                    <th>VI</th>
                    <th>kJ</th>
                    <?php if ($lapSource === 'manual'): ?><th></th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($laps as $iv): ?>
                    <tr data-lap="<?= (int)($iv['id'] ?? 0) ?>" class="<?= !empty($iv['is_work']) ? 'bg-primary/5 font-medium' : '' ?>">
                      <td class="font-bold text-xs"><?= $iv['id'] ?? 1 ?></td>
                      <td>
                        <span class="badge <?= !empty($iv['is_work']) ? 'bg-primary/15 text-primary font-semibold' : '' ?>" data-variant="<?= !empty($iv['is_work']) ? 'outline' : 'secondary' ?>"><?= !empty($iv['label']) ? h($iv['label']) : (!empty($iv['is_work']) ? 'Effort' : 'Récup') ?></span>
                      </td>
                      <td class="tabular-nums text-xs font-semibold"><?= fmt_dur($iv['duration'] ?? 0) ?></td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['distance']) ? n($iv['distance'] / 1000, 2) . ' km' : '–' ?></td>
                      <td class="tabular-nums font-bold text-xs text-cyan-600 dark:text-cyan-400"><?= !empty($iv['avg_w']) ? n($iv['avg_w']) . ' W' : '–' ?></td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['np']) ? n($iv['np']) . ' W' : '–' ?></td>
                      <td class="tabular-nums text-xs font-medium"><?= !empty($iv['if_']) ? n($iv['if_'], 2) : '–' ?></td>
                      <td><span class="badge" data-variant="outline"><?= h($iv['zone'] ?? 'Z1') ?></span></td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['avg_speed']) ? n($iv['avg_speed'], 1) . ' km/h' : '–' ?></td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['avg_hr']) ? round($iv['avg_hr']) : '–' ?> bpm</td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['max_hr']) ? round($iv['max_hr']) : '–' ?> bpm</td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['cadence']) ? round($iv['cadence']) : '–' ?> rpm</td>
                      <td class="tabular-nums text-xs text-purple-500 font-medium"><?= isset($iv['min_wbal']) ? $iv['min_wbal'] . ' kJ' : '–' ?></td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['ef']) ? n($iv['ef'], 2) : '–' ?></td>
                      <td class="tabular-nums text-xs"><?= !empty($iv['vi']) ? n($iv['vi'], 2) : '–' ?></td>
                      <td class="tabular-nums text-xs"><?= n($iv['kj'] ?? 0) ?></td>
                      <?php if ($lapSource === 'manual'): ?><td><button type="button" class="btn" data-size="icon-sm" data-variant="ghost" title="Supprimer ce tour" aria-label="Supprimer ce tour" onclick="deleteLap(<?= (int)$iv['id'] ?>)">🗑</button></td><?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      <?php endif; ?>

      </div>
      <?php endif; ?>

      <div id="sec-analyses" class="space-y-6 scroll-mt-24">

      <!-- Grille Télémétrique Approfondie WKO5 (Protégée contre tout Undefined array key) -->
      <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Bloc Puissance WKO5 -->
        <div class="card p-4 space-y-3">
          <div class="text-xs font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-400 flex items-center justify-between">
            <span>⚡ Puissance & Anaérobie</span>
            <span>WKO5</span>
          </div>
          <div class="space-y-2 text-xs">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Pmax (1s / 5s)</span>
              <span class="font-bold tabular-nums"><?= $wval('pmax_1s', 'W') ?> / <?= $wval('pmax_5s', 'W') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Pmax 1 min / 5 min</span>
              <span class="font-bold tabular-nums"><?= $wval('pmax_1m', 'W') ?> / <?= $wval('pmax_5m', 'W') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Travail au-dessus FTP</span>
              <span class="font-bold tabular-nums text-amber-500"><?= $wval('work_above_ftp', 'kJ') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Temps au-dessus FTP</span>
              <span class="font-bold tabular-nums"><?= !empty($wko['time_above_ftp']) ? fmt_dur((int)$wko['time_above_ftp']) : '–' ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Allumettes brûlées (>120%)</span>
              <span class="font-bold tabular-nums"><?= $wko['matches_burned'] ?? 0 ?> allumette(s)</span>
            </div>
          </div>
        </div>

        <!-- Bloc W'bal Batterie -->
        <div class="card p-4 space-y-3">
          <div class="text-xs font-bold uppercase tracking-wider text-purple-500 flex items-center justify-between">
            <span>🔋 Réserve W'bal</span>
            <span>Skiba</span>
          </div>
          <div class="space-y-2 text-xs">
            <div class="flex justify-between">
              <span class="text-muted-foreground">W'bal min atteint</span>
              <span class="font-bold tabular-nums text-purple-600 dark:text-purple-400">
                <?= $wval('min_wbal', 'kJ') ?>
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Batterie restante minimale</span>
              <span class="font-bold tabular-nums">
                <?= !empty($wko['min_wbal_pct']) ? ($wko['min_wbal_pct'] < 20 ? '<span class="text-red-500 font-bold">' . $wko['min_wbal_pct'] . '%</span>' : $wko['min_wbal_pct'] . '%') : '–' ?>
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Capacité W' initiale</span>
              <span class="font-bold tabular-nums"><?= $cpm ? round($cpm['wp'] / 1000, 1) . ' kJ' : '20.0 kJ' ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Puissance Critique (CP)</span>
              <span class="font-bold tabular-nums"><?= $cpm ? round($cpm['cp']) . ' W' : n($ftp) . ' W' ?></span>
            </div>
          </div>
        </div>

        <!-- Bloc Pédalage & Vitesse -->
        <div class="card p-4 space-y-3">
          <div class="text-xs font-bold uppercase tracking-wider text-emerald-500 flex items-center justify-between">
            <span>🚀 Vitesse & Pédalage</span>
            <span>Télémétrie</span>
          </div>
          <div class="space-y-2 text-xs">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Vitesse Moy. / Max</span>
              <span class="font-bold tabular-nums"><?= $wval('avg_speed') ?> / <?= $wval('max_speed', 'km/h') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Cadence Moy. / Max</span>
              <span class="font-bold tabular-nums"><?= $a['cadence'] ? round($a['cadence']) : '–' ?> / <?= $wval('cad_max', 'rpm') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Tours de pédale totaux</span>
              <span class="font-bold tabular-nums"><?= !empty($wko['total_revs']) ? n($wko['total_revs']) : '–' ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Couple moyen (Torque)</span>
              <span class="font-bold tabular-nums"><?= $wval('avg_torque', 'N.m') ?></span>
            </div>
          </div>
        </div>

        <!-- Bloc Cardio & Découplage -->
        <div class="card p-4 space-y-3">
          <div class="text-xs font-bold uppercase tracking-wider text-rose-500 flex items-center justify-between">
            <span>💓 Cardio & Efficacité</span>
            <span>Physiologie</span>
          </div>
          <div class="space-y-2 text-xs">
            <div class="flex justify-between">
              <span class="text-muted-foreground">% LTHR</span>
              <span class="font-bold tabular-nums"><?= $wval('pct_lthr', '%') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">% FC Max</span>
              <span class="font-bold tabular-nums"><?= $wval('pct_max_hr', '%') ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Facteur d'Efficacité (EF)</span>
              <span class="font-bold tabular-nums"><?= n($a['ef'], 2) ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Découplage Pw:HR (Drift)</span>
              <span class="font-bold tabular-nums"><?= $a['decoupling'] !== null ? n($a['decoupling'], 1) . ' %' : '–' ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Graphiques Puissance/FC & Dérive Puissance-FC -->
      <?php if (!empty($powerSeries) && !empty($hrSeries)):
          $pfcRatio = !empty($a['avg_w']) && !empty($a['avg_hr']) && (float)$a['avg_hr'] > 0
              ? number_format((float)$a['avg_w'] / (float)$a['avg_hr'], 2, '.', '')
              : null;
          $pfcDecoupling = $a['decoupling'] !== null ? number_format((float)$a['decoupling'], 1, '.', '') : null;
          $pfcLag = $wko['hr_lag'] ?? null;
          $pfcZ2 = $wko['z2_ratio'] ?? null;
      ?>
      <div class="grid lg:grid-cols-2 gap-6" id="pfcAnalyticsSection">
        <!-- 1. Puissance / FC dans le temps -->
        <div class="card">
          <header class="p-4 border-b">
            <h3 class="text-base font-semibold">Puissance/FC</h3>
            <div class="mt-1.5 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
              <?php if ($pfcRatio !== null): ?>
                <span>Rapport <strong class="text-foreground tabular-nums"><?= h($pfcRatio) ?></strong></span>
              <?php endif; ?>
              <?php if ($pfcDecoupling !== null): ?>
                <span>Découplage <strong class="text-foreground tabular-nums"><?= h($pfcDecoupling) ?>%</strong></span>
              <?php endif; ?>
              <?php if ($pfcLag !== null): ?>
                <span>Retard de FC <strong class="text-foreground tabular-nums"><?= (int)$pfcLag ?>s</strong></span>
              <?php endif; ?>
            </div>
            <?php if ($pfcZ2 !== null): ?>
              <div class="mt-1 text-center text-xs text-muted-foreground">
                Z2 <strong class="text-foreground tabular-nums"><?= h($pfcZ2) ?></strong>
              </div>
            <?php endif; ?>
          </header>
          <section class="p-4">
            <div class="h-64"><canvas id="chartPowerHrTime"></canvas></div>
          </section>
        </div>

        <!-- 2. Dérive puissance-FC (Premier vs Deuxième Moitié) -->
        <div class="card">
          <header class="p-4 border-b text-center">
            <h3 class="text-base font-semibold text-left">Dérive puissance-FC</h3>
            <p class="mt-1 text-xs font-semibold">
              <span style="color:#a855f7;">Premier</span>
              <span class="text-muted-foreground"> vs. </span>
              <span style="color:#ef4444;">Deuxième Moitié</span>
            </p>
          </header>
          <section class="p-4">
            <div class="h-64"><canvas id="chartPowerHrDrift"></canvas></div>
          </section>
        </div>
      </div>

      <script>
      (function () {
      const gradeData = <?= json_encode($gradeSeries) ?>;
    const tempData = <?= json_encode($tempSeries) ?>;
        const pArr = <?= json_encode($powerSeries) ?>;
        const hArr = <?= json_encode($hrSeries) ?>;
        const dtSec = <?= (int)$step ?>;
        const n = Math.min(pArr.length, hArr.length);
        if (n < 6) return;

        const pairs = [];
        for (let i = 0; i < n; i++) {
          const w = Number(pArr[i]);
          const hr = Number(hArr[i]);
          if (w > 0 && hr > 30) {
            pairs.push({ min: Number(((i * dtSec) / 60).toFixed(1)), w, hr, ratio: Number((w / hr).toFixed(2)) });
          }
        }
        if (pairs.length < 6) return;

        const bucketStep = Math.max(1, Math.ceil(pairs.length / 90));
        const sampled = [];
        for (let i = 0; i < pairs.length; i += bucketStep) {
          const chunk = pairs.slice(i, i + bucketStep);
          const avgMin = chunk.reduce((s, x) => s + x.min, 0) / chunk.length;
          const avgW = chunk.reduce((s, x) => s + x.w, 0) / chunk.length;
          const avgHr = chunk.reduce((s, x) => s + x.hr, 0) / chunk.length;
          const avgRatio = avgW / avgHr;
          sampled.push({
            min: Number(avgMin.toFixed(1)),
            w: Number(avgW.toFixed(0)),
            hr: Number(avgHr.toFixed(0)),
            ratio: Number(avgRatio.toFixed(2))
          });
        }

        const midIdx = Math.floor(sampled.length / 2);
        const firstHalf = sampled.slice(0, midIdx);
        const secondHalf = sampled.slice(midIdx);

        function linReg(pts) {
          if (pts.length < 2) return [];
          let sx = 0, sy = 0, sxy = 0, sxx = 0;
          for (const p of pts) {
            sx += p.x; sy += p.y; sxy += p.x * p.y; sxx += p.x * p.x;
          }
          const den = pts.length * sxx - sx * sx;
          if (den === 0) return [];
          const m = (pts.length * sxy - sx * sy) / den;
          const b = (sy - m * sx) / pts.length;
          const xs = pts.map(p => p.x);
          const minX = Math.min(...xs), maxX = Math.max(...xs);
          return [{ x: minX, y: m * minX + b }, { x: maxX, y: m * maxX + b }];
        }

        const purpleStyle = {
          backgroundColor: 'rgba(168, 85, 247, 0.22)',
          borderColor: '#a855f7',
          borderWidth: 1.5,
          pointRadius: 3.5
        };
        const redStyle = {
          backgroundColor: 'rgba(239, 68, 68, 0.22)',
          borderColor: '#ef4444',
          borderWidth: 1.5,
          pointRadius: 3.5
        };

        const r1 = firstHalf.map(p => ({ x: p.min, y: p.ratio }));
        const r2 = secondHalf.map(p => ({ x: p.min, y: p.ratio }));
        const trendAll = linReg([...r1, ...r2]);

        const elTime = document.getElementById('chartPowerHrTime');
        if (elTime) {
          new Chart(elTime, {
            type: 'scatter',
            data: {
              datasets: [
                { label: '1re moitié', data: r1, ...purpleStyle },
                { label: '2e moitié', data: r2, ...redStyle },
                ...(trendAll.length ? [{
                  type: 'line',
                  label: 'Tendance',
                  data: trendAll,
                  borderColor: '#2563eb',
                  borderWidth: 2,
                  borderDash: [4, 4],
                  pointRadius: 0,
                  fill: false
                }] : [])
              ]
            },
            options: {
              maintainAspectRatio: false,
              plugins: { legend: { display: false } },
              scales: {
                x: {
                  type: 'linear',
                  ticks: { callback: v => `${Math.round(v)}m`, maxTicksLimit: 8 }
                },
                y: {
                  position: 'right',
                  ticks: { callback: v => Number(v).toFixed(1).replace('.', ',') }
                }
              }
            }
          });
        }

        const d1 = firstHalf.map(p => ({ x: p.w, y: p.hr }));
        const d2 = secondHalf.map(p => ({ x: p.w, y: p.hr }));
        const line1 = linReg(d1);
        const line2 = linReg(d2);

        const elDrift = document.getElementById('chartPowerHrDrift');
        if (elDrift) {
          new Chart(elDrift, {
            type: 'scatter',
            data: {
              datasets: [
                { label: 'Premier', data: d1, ...purpleStyle },
                { label: 'Deuxième Moitié', data: d2, ...redStyle },
                ...(line1.length ? [{
                  type: 'line',
                  label: 'Premier (tendance)',
                  data: line1,
                  borderColor: '#a855f7',
                  borderWidth: 2,
                  borderDash: [4, 4],
                  pointRadius: 0,
                  fill: false
                }] : []),
                ...(line2.length ? [{
                  type: 'line',
                  label: 'Deuxième Moitié (tendance)',
                  data: line2,
                  borderColor: '#ef4444',
                  borderWidth: 2,
                  borderDash: [4, 4],
                  pointRadius: 0,
                  fill: false
                }] : [])
              ]
            },
            options: {
              maintainAspectRatio: false,
              plugins: { legend: { display: false } },
              scales: {
                x: {
                  type: 'linear',
                  ticks: { callback: v => `${Math.round(v)}w`, maxTicksLimit: 8 }
                },
                y: {
                  position: 'right'
                }
              }
            }
          });
        }
      })();
      </script>
      <?php endif; ?>
      </div>

      <div id="sec-distributions" class="space-y-6 scroll-mt-24">

      <!-- Graphique PDC de la séance vs Meilleure PDC 90j de la saison -->
      <div class="grid lg:grid-cols-2 gap-6">
        <div class="card">
          <header class="p-4 border-b">
            <h3 class="text-base font-semibold">MMP de la séance vs Record 90 jours (WKO5)</h3>
            <p class="text-xs text-muted-foreground">Comparaison de vos meilleurs efforts de la séance face à votre enveloppe de saison</p>
          </header>
          <section class="p-4">
            <div class="h-64"><canvas id="mmpCompare"></canvas></div>
          </section>
        </div>

        <!-- Zones de puissance & Polarisation -->
        <div class="card">
          <header class="p-4 border-b flex items-center justify-between">
            <div>
              <h3 class="text-base font-semibold">Distribution temporelle des zones (Coggan)</h3>
              <p class="text-xs text-muted-foreground">Temps passé dans chaque zone d'entraînement</p>
            </div>
            <?php if (!empty($wko['polarization']['low'])): ?>
              <span class="text-[11px] text-muted-foreground">
                Basse: <?= $wko['polarization']['low'] ?>% · Moy: <?= $wko['polarization']['mid'] ?>% · Haute: <?= $wko['polarization']['high'] ?>%
              </span>
            <?php endif; ?>
          </header>
          <section class="p-4">
            <div class="h-64"><canvas id="zonesChart"></canvas></div>
          </section>
        </div>
      </div>

<!-- Histogrammes de distribution en pic par type de donnée -->
      <?php $peaks = !empty($S) ? peak_histograms($S, (float)$ftp) : []; ?>
      <?php if (array_filter($peaks)): ?>
      <div class="card" id="peakHistCard">
        <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h3 class="text-base font-semibold">Distribution en pic par type de données</h3>
            <p class="text-xs text-muted-foreground">Temps passé (MM:SS) dans chaque plage de valeurs au cours de la séance</p>
          </div>
          <span class="badge" data-variant="secondary">Histogrammes 1 Hz</span>
        </header>
        <section class="p-4">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $peakMeta = [
                'power'   => ['title' => 'Puissance', 'color' => '#8b5cf6', 'unit' => 'W',    'dec' => 0],
                'torque'  => ['title' => 'Couple',    'color' => '#c026d3', 'unit' => 'N.m',  'dec' => 0],
                'hr'      => ['title' => 'FC',        'color' => '#be3a68', 'unit' => 'bpm',  'dec' => 0],
                'speed'   => ['title' => 'Vitesse',   'color' => '#4db6ac', 'unit' => 'km/h', 'dec' => 1],
                'cadence' => ['title' => 'Cadence',   'color' => '#c8963e', 'unit' => 'rpm',  'dec' => 0],
                'grade'   => ['title' => 'Pente',     'color' => '#14b8a6', 'unit' => '%',    'dec' => 1],
            ];
            foreach ($peakMeta as $key => $meta):
                if (empty($peaks[$key])) continue;
            ?>
              <div class="rounded-lg border bg-muted/15 p-3">
                <div class="text-center text-xs font-semibold mb-2"><?= h($meta['title']) ?></div>
                <div class="h-52 w-full"><canvas id="peakHist_<?= $key ?>"></canvas></div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <script>
      (function () {
        const PEAKS = <?= json_encode($peaks) ?>;
        const META = <?= json_encode($peakMeta) ?>;
        const dark = document.documentElement.classList.contains('dark');
        const gridCol = dark ? 'rgba(148,163,184,0.14)' : 'rgba(100,116,139,0.18)';
        const txtCol = dark ? '#9ca3af' : '#64748b';

        const fmtMmSs = sec => {
          sec = Math.max(0, Math.round(sec));
          const h = Math.floor(sec / 3600);
          const m = Math.floor((sec % 3600) / 60);
          const s = sec % 60;
          if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
          return `${m}:${String(s).padStart(2, '0')}`;
        };

        Object.keys(META).forEach(key => {
          const d = PEAKS[key];
          const m = META[key];
          const el = document.getElementById('peakHist_' + key);
          if (!d || !el) return;

          const fmtVal = v => m.dec > 0 ? Number(v).toFixed(m.dec) : String(Math.round(v));
          const xLabels = d.labels.map(pair => fmtVal(pair[0]));
          const ranges = d.labels.map(pair => `${fmtVal(pair[0])} – ${fmtVal(pair[1])} ${m.unit}`);

          new Chart(el, {
            type: 'bar',
            data: {
              labels: xLabels,
              datasets: [{
                label: m.title,
                data: d.sec,
                backgroundColor: m.color,
                borderWidth: 0,
                borderRadius: 1,
                barPercentage: 0.92,
                categoryPercentage: 0.94
              }]
            },
            options: {
              maintainAspectRatio: false,
              animation: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    title: items => items[0] ? `${m.title} : ${ranges[items[0].dataIndex]}` : '',
                    label: ctx => ` Durée : ${fmtMmSs(ctx.parsed.y)} (${ctx.parsed.y}s)`
                  }
                }
              },
              scales: {
                x: {
                  grid: { display: false },
                  ticks: { color: txtCol, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 11 }
                },
                y: {
                  beginAtZero: true,
                  grid: { color: gridCol },
                  ticks: {
                    color: txtCol,
                    font: { size: 10 },
                    maxTicksLimit: 6,
                    callback: v => fmtMmSs(v)
                  }
                }
              }
            }
          });
        });
      })();
      </script>
      <?php endif; ?>
      </div>

    </div>

    <script>
    const powerData = <?= json_encode($powerSeries) ?>;
    const power30Data = <?= json_encode($power30Series) ?>;
    const hrData = <?= json_encode($hrSeries) ?>;
    const spdData = <?= json_encode($spdSeries) ?>;
    const cadData = <?= json_encode($cadSeries) ?>;
    const altData = <?= json_encode($altSeries) ?>;
    const wbData = <?= json_encode($wb ?: []) ?>;
    const gradeData = <?= json_encode($gradeSeries ?? []) ?>;
    const tempData = <?= json_encode($tempSeries ?? []) ?>;
    const step = <?= $step ?>;
    const ftpValue = <?= (float)$ftp ?>;

    const baseSeries = [powerData, hrData, spdData, altData, cadData].find(s => s.length) || [];
    const labels = baseSeries.map((_, i) => (i * step / 60).toFixed(0) + ' min');

    const datasets = [
        { label: 'Puissance (W)', data: powerData, borderColor: '#06b6d4', borderWidth: 1.2, pointRadius: 0, yAxisID: 'y' },
        { label: 'Puissance 30s', data: power30Data, borderColor: '#6366f1', borderWidth: 2, pointRadius: 0, yAxisID: 'y' },
        { label: 'FC (bpm)', data: hrData, borderColor: '#f43f5e', borderWidth: 1.5, pointRadius: 0, yAxisID: 'y1' },
        { label: 'Vitesse (km/h)', data: spdData, borderColor: '#10b981', borderWidth: 1.5, pointRadius: 0, yAxisID: 'y2' },
        { label: 'Cadence (rpm)', data: cadData, borderColor: '#f59e0b', borderWidth: 1.2, pointRadius: 0, yAxisID: 'y3' },
        { label: 'Altitude (m)', data: altData, borderColor: '#64748b', borderWidth: 1, fill: true, backgroundColor: 'rgba(100, 116, 139, 0.15)', pointRadius: 0, yAxisID: 'y4' },
        ...(wbData.length ? [{ label: "W'bal (kJ)", data: wbData, borderColor: '#a855f7', borderWidth: 2, pointRadius: 0, yAxisID: 'y5' }] : []),
        ...(gradeData.length ? [{ label: 'Pente (%)', data: gradeData, borderColor: '#14b8a6', borderWidth: 1.3, pointRadius: 0, yAxisID: 'y6' }] : []),
        ...(tempData.length ? [{ label: 'Température (°C)', data: tempData, borderColor: '#f97316', borderWidth: 1.3, pointRadius: 0, yAxisID: 'y7' }] : []),
    ];

    // =============================================================
    // Sélection d'une portion (glisser) -> stats exactes -> création d'un tour
    // =============================================================
    const ACTIVITY_ID = <?= json_encode($a['id']) ?>;
    const SEL_IS_RUN = <?= $isRun ? 'true' : 'false' ?>;
    let SEL = null;                       // {a, b} en secondes (flux 1 Hz)
    const selCharts = [];
    const selHms = s => { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return h ? `${h}:${String(m).padStart(2, '0')}:${String(x).padStart(2, '0')}` : `${m}:${String(x).padStart(2, '0')}`; };
    const selPace = kmh => { if (!(kmh > 0)) return '–'; const s = Math.round(3600 / kmh); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') + '/km'; };

    Chart.register({
        id: 'selOverlay',
        afterDatasetsDraw(chart) {
            if (!SEL || !chart.$sel) return;
            const x = chart.scales.x, area = chart.chartArea, ctx = chart.ctx;
            const x1 = x.getPixelForValue(SEL.a / step), x2 = x.getPixelForValue(SEL.b / step);
            ctx.save();
            ctx.fillStyle = 'rgba(59,130,246,.18)'; ctx.fillRect(x1, area.top, x2 - x1, area.bottom - area.top);
            ctx.strokeStyle = 'rgba(59,130,246,.95)'; ctx.lineWidth = 1.5; ctx.beginPath();
            ctx.moveTo(x1, area.top); ctx.lineTo(x1, area.bottom); ctx.moveTo(x2, area.top); ctx.lineTo(x2, area.bottom); ctx.stroke();
            ctx.restore();
        }
    });

    // =============================================================
    // Graphes liés : survol synchronisé (index temporel commun) + zoom molette
    // =============================================================
    const linkedCharts = [];
    let syncHover = false;
    const zoomState = { a: null, b: null };       // bornes d'index (null = vue complète)

    Chart.register({
        id: 'crosshairLine',
        afterDatasetsDraw(chart) {
            const i = chart.$hoverIndex;
            if (i == null || !chart.$link) return;
            const x = chart.scales.x, area = chart.chartArea, ctx = chart.ctx;
            const px = x.getPixelForValue(i);
            if (!isFinite(px) || px < area.left || px > area.right) return;
            ctx.save(); ctx.strokeStyle = 'rgba(148,163,184,.65)'; ctx.lineWidth = 1; ctx.setLineDash([4, 3]);
            ctx.beginPath(); ctx.moveTo(px, area.top); ctx.lineTo(px, area.bottom); ctx.stroke(); ctx.restore();
        }
    });
    Chart.register({
        id: 'idxLink',
        afterEvent(chart, args) {
            if (syncHover || !chart.$link || chart.canvas.offsetParent === null) return;
            const ev = args.event;
            if (ev.type !== 'mousemove' && ev.type !== 'mouseout') return;
            if (ev.type === 'mousemove' && !args.inChartArea) return;
            const els = ev.type === 'mouseout' ? [] : chart.getElementsAtEventForMode(ev, 'nearest', { intersect: false, axis: 'x' }, true);
            const idx = els.length ? els[0].index : null;
            syncHover = true;
            linkedCharts.forEach(c => { if (c.canvas.offsetParent !== null) setLinkHover(c, idx); });
            syncHover = false;
        }
    });

    function setLinkHover(chart, idx) {
        chart.$hoverIndex = idx;
        let di = -1;
        if (idx != null) di = chart.data.datasets.findIndex(ds => !ds.hidden && ds.data && ds.data[idx] != null);
        const els = di >= 0 ? [{ datasetIndex: di, index: idx }] : [];
        const pos = { x: chart.chartArea.left, y: chart.chartArea.top };
        chart.setActiveElements(els, pos);
        chart.tooltip.setActiveElements(els, pos);
        chart.update('none');
    }

    function applyZoom() {
        linkedCharts.forEach(c => { c.options.scales.x.min = zoomState.a; c.options.scales.x.max = zoomState.b; c.update('none'); });
        const b = document.getElementById('zoomReset');
        if (b) b.classList.toggle('hidden', zoomState.a == null);
    }
    function zoomAt(chart, ev, factor) {
        const n = labels.length;
        if (!n) return;
        const r = chart.canvas.getBoundingClientRect();
        const raw = chart.scales.x.getValueForPixel(ev.clientX - r.left);
        const cur = Math.max(0, Math.min(n - 1, raw));
        const a0 = zoomState.a ?? 0, b0 = zoomState.b ?? n - 1;
        let a = cur - (cur - a0) * factor, b = cur + (b0 - cur) * factor;
        const MINW = Math.min(9, n - 1);
        if (b - a < MINW) { const m = (a + b) / 2; a = m - MINW / 2; b = m + MINW / 2; }
        a = Math.max(0, Math.round(a)); b = Math.min(n - 1, Math.round(b));
        if (b - a < 1) return;
        zoomState.a = a; zoomState.b = b; applyZoom();
    }
    function zoomReset() {
        if (zoomState.a == null) return;
        zoomState.a = zoomState.b = null; applyZoom();
    }
    function attachZoom(chart) {
        chart.canvas.addEventListener('wheel', ev => {
            if (ev.deltaY > 0 && zoomState.a == null) return;      // déjà dézoomé à fond : laisser défiler la page
            ev.preventDefault();
            zoomAt(chart, ev, ev.deltaY > 0 ? 1.15 : 1 / 1.15);
        }, { passive: false });
        chart.canvas.addEventListener('dblclick', zoomReset);
    }

    function selRedraw() { selCharts.forEach(c => c.draw()); }
    function selClear() {
        SEL = null;
        document.getElementById('selPanel').classList.add('hidden');
        selRedraw();
        if (window.mapHighlightLap) window.mapHighlightLap(null);
    }
    async function selPreview() {
        const panel = document.getElementById('selPanel'), stats = document.getElementById('selStats'), msg = document.getElementById('selMsg');
        panel.classList.remove('hidden'); msg.textContent = '';
        document.getElementById('selTime').textContent = `${selHms(SEL.a)} → ${selHms(SEL.b)} · ${selHms(SEL.b - SEL.a)}`;
        stats.innerHTML = '<span class="text-muted-foreground">Calcul…</span>';
        const wanted = JSON.stringify(SEL);
        try {
            const j = await call('lap_preview', {id: ACTIVITY_ID, start: SEL.a, end: SEL.b});
            if (!SEL || JSON.stringify(SEL) !== wanted) return;          // la sélection a changé entre-temps
            const s = j.stats, chip = t => `<span class="badge" data-variant="secondary">${t}</span>`, out = [];
            if (s.avg_w > 0) out.push(chip(`<b>${Math.round(s.avg_w)} W</b>${ftpValue ? ' · ' + Math.round(s.avg_w / ftpValue * 100) + ' % FTP' : ''}`), chip(`NP ${Math.round(s.np)} W`), chip(s.zone));
            if (s.avg_hr > 0) out.push(chip(`FC ${Math.round(s.avg_hr)} <span class="opacity-60">(max ${Math.round(s.max_hr)})</span> bpm`));
            if (s.cadence > 0) out.push(chip(`${Math.round(s.cadence)} rpm`));
            if (s.avg_speed > 0) out.push(chip(SEL_IS_RUN ? selPace(s.avg_speed) : s.avg_speed.toFixed(1) + ' km/h'));
            if (s.distance > 0) out.push(chip((s.distance / 1000).toFixed(2) + ' km'));
            if (s.elev > 0) out.push(chip('D+ ' + s.elev + ' m'));
            if (s.kj > 0) out.push(chip(s.kj + ' kJ'));
            stats.innerHTML = out.join('');
        } catch (e) { stats.innerHTML = ''; msg.textContent = e.message; }
    }
    function attachSelection(chart) {
        chart.$sel = true; selCharts.push(chart);
        chart.$link = true; linkedCharts.push(chart);
        attachZoom(chart);
        const cv = chart.canvas; cv.style.touchAction = 'pan-y'; cv.style.cursor = 'crosshair';
        let drag = null;
        const idx = ev => { const r = cv.getBoundingClientRect(); const v = chart.scales.x.getValueForPixel(ev.clientX - r.left); return Math.max(0, Math.min(labels.length - 1, Math.round(v))); };
        cv.addEventListener('pointerdown', ev => { if (ev.button !== 0) return; drag = idx(ev); SEL = {a: drag * step, b: drag * step}; cv.setPointerCapture(ev.pointerId); });
        cv.addEventListener('pointermove', ev => { if (drag === null) return; const b = idx(ev); SEL = {a: Math.min(drag, b) * step, b: Math.max(drag, b) * step}; selRedraw(); });
        const end = () => {
            if (drag === null) return; drag = null;
            if (!SEL || SEL.b - SEL.a < 5) { selClear(); return; }       // simple clic = désélection
            if (window.mapHighlightLap) window.mapHighlightLap(SEL.a, SEL.b);
            selPreview();
        };
        cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
    }
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && SEL) selClear(); });

    async function selCreate() {
        const msg = document.getElementById('selMsg'); msg.textContent = '';
        try {
            await call('lap_add', {id: ACTIVITY_ID, start: SEL.a, end: SEL.b, label: document.getElementById('selLabel').value.trim(), is_work: document.getElementById('selWork').checked ? 1 : 0});
            location.hash = 'lapCard'; location.reload();
        } catch (e) { msg.textContent = e.message; }
    }
    async function deleteLap(n) {
        try { await call('lap_delete', {id: ACTIVITY_ID, lap: n}); location.hash = 'lapCard'; location.reload(); } catch (e) { alert(e.message); }
    }
    async function clearManualLaps() {
        if (!confirm('Supprimer vos tours personnalisés et revenir aux tours automatiques ?')) return;
        try { await call('lap_clear', {id: ACTIVITY_ID}); location.hash = 'lapCard'; location.reload(); } catch (e) { alert(e.message); }
    }

    const chartInstance = new Chart('fullTelemetry', {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { maxTicksLimit: 12 } },
                y: { title: { display: true, text: 'Watts' }, position: 'left' },
                y1: { title: { display: true, text: 'FC (bpm)' }, position: 'right', grid: { drawOnChartArea: false } },
                y2: { display: false },
                y3: { display: false },
                y4: { display: false },
                y5: { display: false },
                y6: { display: false },
                y7: { display: false },
                
            }
        }
    });

    attachSelection(chartInstance);

    function toggleDataset(index, visible) {
        if (chartInstance && chartInstance.data.datasets[index]) {
            chartInstance.data.datasets[index].hidden = !visible;
            chartInstance.update();
        }
    }

    // -------------------------------------------------------------
    // Gestion du Switcher Layout : Graphique Unique vs Empilé
    // -------------------------------------------------------------
    let stackedCharts = [];
    let stackedInitialized = false;

    function initStackedCharts() {
        if (stackedInitialized) return;
        stackedInitialized = true;

        const commonOptions = {
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => items[0] ? 'Temps : ' + items[0].label : ''
                    }
                }
            }
        };

       

        // 1. Stacked Power
        const elPwr = document.getElementById('stkPower');
        if (elPwr && powerData.length) {
            const pwrDatasets = [
                {
                    label: 'Puissance (W)',
                    data: powerData,
                    borderColor: '#06b6d4',
                    borderWidth: 1.2,
                    pointRadius: 0
                },
                {
                    label: '30s lissée (W)',
                    data: power30Data,
                    borderColor: '#6366f1',
                    borderWidth: 2,
                    pointRadius: 0
                }
            ];
            if (ftpValue > 0) {
                pwrDatasets.push({
                    label: 'Seuil FTP (' + ftpValue + ' W)',
                    data: labels.map(() => ftpValue),
                    borderColor: 'rgba(239, 68, 68, 0.65)',
                    borderDash: [5, 5],
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: false
                });
            }
            stackedCharts.push(new Chart(elPwr, {
                type: 'line',
                data: { labels: labels, datasets: pwrDatasets },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: false },
                        y: { title: { display: true, text: 'Watts' }, min: 0 }
                    }
                }
            }));
        }

        // 2. Stacked Heart Rate
        const elHr = document.getElementById('stkHr');
        if (elHr && hrData.length) {
            const lthrVal = <?= (float)cfg('lthr', 172) ?>;
            const hrDatasets = [
                {
                    label: 'FC (bpm)',
                    data: hrData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.08)',
                    fill: true,
                    borderWidth: 1.8,
                    pointRadius: 0
                }
            ];
            if (lthrVal > 0) {
                hrDatasets.push({
                    label: 'LTHR (' + lthrVal + ' bpm)',
                    data: labels.map(() => lthrVal),
                    borderColor: 'rgba(249, 115, 22, 0.7)',
                    borderDash: [4, 4],
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: false
                });
            }
            stackedCharts.push(new Chart(elHr, {
                type: 'line',
                data: { labels: labels, datasets: hrDatasets },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: false },
                        y: { title: { display: true, text: 'bpm' }, min: 60 }
                    }
                }
            }));
        }

        // 3. Stacked Speed
        const elSpd = document.getElementById('stkSpd');
        if (elSpd && spdData.length) {
            stackedCharts.push(new Chart(elSpd, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Vitesse (km/h)',
                        data: spdData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        fill: true,
                        borderWidth: 1.6,
                        pointRadius: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: false },
                        y: { title: { display: true, text: 'km/h' }, min: 0 }
                    }
                }
            }));
        }

        // 4. Stacked Cadence
        const elCad = document.getElementById('stkCad');
        if (elCad && cadData.length) {
            stackedCharts.push(new Chart(elCad, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Cadence (rpm)',
                        data: cadData,
                        borderColor: '#f59e0b',
                        borderWidth: 1.4,
                        pointRadius: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: false },
                        y: { title: { display: true, text: 'rpm' }, min: 30 }
                    }
                }
            }));
        }

        // 5. Stacked Altitude
        const elAlt = document.getElementById('stkAlt');
        if (elAlt && altData.length) {
            stackedCharts.push(new Chart(elAlt, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Altitude (m)',
                        data: altData,
                        borderColor: '#64748b',
                        backgroundColor: 'rgba(100, 116, 139, 0.2)',
                        fill: true,
                        borderWidth: 1.2,
                        pointRadius: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: wbData.length === 0, ticks: { maxTicksLimit: 12 } },
                        y: { title: { display: true, text: 'm' } }
                    }
                }
            }));
        }

        // 6. Stacked W'bal
        const elWb = document.getElementById('stkWb');
        if (elWb && wbData.length) {
            stackedCharts.push(new Chart(elWb, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: "W'bal (kJ)",
                            data: wbData,
                            borderColor: '#a855f7',
                            backgroundColor: 'rgba(168, 85, 247, 0.1)',
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 0
                        },
                        {
                            label: 'Épuisement (0 kJ)',
                            data: labels.map(() => 0),
                            borderColor: 'rgba(239, 68, 68, 0.5)',
                            borderDash: [3, 3],
                            borderWidth: 1.2,
                            pointRadius: 0,
                            fill: false
                        }
                    ]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: true, ticks: { maxTicksLimit: 12 }, title: { display: true, text: 'Temps écoulé' } },
                        y: { title: { display: true, text: 'kJ' } }
                    }
                }
            }));
        }

         // 7. Stacked Grade
        const elGrade = document.getElementById('stkGrade');
        if (elGrade && gradeData.length) {
            stackedCharts.push(new Chart(elGrade, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Pente (%)',
                        data: gradeData,
                        borderColor: '#14b8a6',
                        backgroundColor: 'rgba(20, 184, 166, 0.12)',
                        fill: true,
                        borderWidth: 1.3,
                        pointRadius: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: false },
                        y: { title: { display: true, text: '%' } }
                    }
                }
            }));
        }

        // 8. Stacked Temperature
        const elTemp = document.getElementById('stkTemp');
        if (elTemp && tempData.length) {
            stackedCharts.push(new Chart(elTemp, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Température (°C)',
                        data: tempData,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.08)',
                        fill: true,
                        borderWidth: 1.4,
                        pointRadius: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        x: { display: wbData.length === 0 && !gradeData.length, ticks: { maxTicksLimit: 12 } },
                        y: { title: { display: true, text: '°C' } }
                    }
                }
            }));
        }
        stackedCharts.forEach(attachSelection);
        applyZoom();
    }

    function switchTelemetryLayout(mode) {
        const overlay = document.getElementById('container-overlay');
        const stacked = document.getElementById('container-stacked');
        const filters = document.getElementById('streamFilters');
        const btnOverlay = document.getElementById('btn-layout-overlay');
        const btnStacked = document.getElementById('btn-layout-stacked');

        if (mode === 'stacked') {
            overlay.classList.add('hidden');
            stacked.classList.remove('hidden');
            if (filters) filters.classList.add('hidden');

            btnOverlay.className = 'px-2.5 py-1 rounded-md font-medium text-muted-foreground hover:text-foreground transition';
            btnStacked.className = 'px-2.5 py-1 rounded-md font-semibold bg-background shadow-xs text-foreground transition';

            if (!stackedInitialized) {
                initStackedCharts();
            } else {
                stackedCharts.forEach(c => {
                    c.resize();
                    c.update();
                });
            }
            try { localStorage.setItem('velo_telemetry_layout', 'stacked'); } catch(e) {}
        } else {
            stacked.classList.add('hidden');
            overlay.classList.remove('hidden');
            if (filters) filters.classList.remove('hidden');

            btnOverlay.className = 'px-2.5 py-1 rounded-md font-semibold bg-background shadow-xs text-foreground transition';
            btnStacked.className = 'px-2.5 py-1 rounded-md font-medium text-muted-foreground hover:text-foreground transition';

            chartInstance.resize();
            chartInstance.update();
            try { localStorage.setItem('velo_telemetry_layout', 'overlay'); } catch(e) {}
        }
    }

    // Restaurer le choix de l'utilisateur s'il avait choisi 'stacked'
    try {
        if (localStorage.getItem('velo_telemetry_layout') === 'stacked') {
            switchTelemetryLayout('stacked');
        }
    } catch(e) {}

    <?php if (!empty($planCmp) && !empty($planCmp['chart'])): ?>
    // ===== Prévu vs réalisé : bande cible + courbe réalisée (axe du temps de la sortie) =====
    (function () {
        const C = <?= json_encode($planCmp['chart']) ?>, UNIT = <?= json_encode($planCmp['unit']) ?>;
        const cv = document.getElementById('planChart'); if (!cv) return;
        const dark = document.documentElement.classList.contains('dark');
        const col = {plan: dark ? '#7da2ff' : '#0f3ab2', actual: dark ? '#fb923c' : '#ea580c', grid: dark ? 'rgba(148,163,184,.14)' : 'rgba(100,116,139,.20)', txt: dark ? '#7b8aa0' : '#64748b'};
        const labels = C.planned.map((_, i) => { const s = i * C.step, h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? `${h}h${String(m).padStart(2, '0')}` : `${m} min`; });
        const all = [].concat(C.actual, C.planned).filter(v => v != null), top = Math.max(...all), low = Math.min(...all);
        new Chart(cv, {
            type: 'line',
            data: {labels, datasets: [
                {label: 'Cible max', data: C.hi, borderWidth: 0, pointRadius: 0, fill: '+1', backgroundColor: col.plan + '38', order: 3},
                {label: 'Cible min', data: C.lo, borderWidth: 0, pointRadius: 0, fill: false, order: 3},
                {label: 'Prévu', data: C.planned, borderColor: col.plan, borderWidth: 1.5, borderDash: [5, 4], pointRadius: 0, fill: false, order: 2},
                {label: 'Réalisé', data: C.actual, borderColor: col.actual, borderWidth: 2.2, pointRadius: 0, pointHoverRadius: 3, cubicInterpolationMode: 'monotone', fill: false, order: 1},
            ]},
            options: {
                maintainAspectRatio: false, animation: false, layout: {padding: {right: 6}}, interaction: {mode: 'index', intersect: false}, spanGaps: false,
                scales: {
                    x: {grid: {display: false}, border: {display: false}, ticks: {color: col.txt, maxTicksLimit: 10, maxRotation: 0, font: {size: 10}}},
                    y: {min: UNIT === 'bpm' ? Math.max(0, Math.floor(low * 0.85 / 10) * 10) : 0, suggestedMax: top * 1.08, grid: {color: col.grid}, border: {display: false}, ticks: {color: col.txt, font: {size: 10}, maxTicksLimit: 6}, afterFit: a => { a.width = 44; }},
                },
                plugins: {legend: {display: false}, tooltip: {filter: i => i.datasetIndex >= 2, callbacks: {label: c => ` ${c.dataset.label} : ${c.parsed.y} ${UNIT}`}}},
            },
        });
    })();
    <?php endif; ?>

    <?php if ($showMap): ?>
// ===== Carte du parcours (Leaflet + fonds sans clé), couleur selon la métrique, survol d'un tour = surbrillance =====
(function () {
  const card = document.getElementById('mapCard');
  if (!card) return;
  const box = document.getElementById('mapBox'), msg = document.getElementById('mapMsg'),
        tabs = document.getElementById('mapMetricTabs'), legend = document.getElementById('mapLegend');
  const ACT_ID = <?= json_encode($a['id']) ?>, MAP_IS_RUN = <?= $isRun ? 'true' : 'false' ?>;
  const initial = <?= $gpsPayload ? json_encode($gpsPayload) : 'null' ?>;
  const dark = document.documentElement.classList.contains('dark');
  const LABELS = {track: 'Trace', watts: 'Puissance', hr: 'FC', speed: 'Vitesse', alt: 'Altitude'};
  const UNITS = {watts: 'W', hr: 'bpm', speed: 'km/h', alt: 'm'};
  let map, layers, hl, G, cur, avail, tileLayer, tileKey;
  // Fonds de carte SANS clé API. Usage personnel / léger : respectez les conditions de chaque service de tuiles.
  const OSM = '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
  const TILES = {
    osm:   {url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', tint: true,  opt: {maxZoom: 19, attribution: OSM}},
    cycle: {url: 'https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png', tint: true, opt: {subdomains: 'abc', maxZoom: 20, attribution: '<a href="https://www.cyclosm.org">CyclOSM</a> | ' + OSM}},
    topo:  {url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', tint: true, opt: {subdomains: 'abc', maxZoom: 17, attribution: OSM + ', SRTM | <a href="https://opentopomap.org">OpenTopoMap</a> (CC-BY-SA)'}},
    sat:   {url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', tint: false, opt: {maxZoom: 19, attribution: 'Tiles © Esri — Esri, Maxar, Earthstar Geographics'}},
  };
  function setTiles(k) {
    tileKey = k;
    if (tileLayer) tileLayer.remove();
    tileLayer = L.tileLayer(TILES[k].url, TILES[k].opt).addTo(map);
    tileLayer.bringToBack();
    box.classList.toggle('map-dark', dark && TILES[k].tint);   // mode sombre : inversion douce des tuiles claires
  }

  const stops = [[59, 130, 246], [16, 185, 129], [245, 158, 11], [239, 68, 68]];
  const grad = t => { const x = Math.min(.999, Math.max(0, t)) * 3, i = Math.floor(x), f = x - i, a = stops[i], b = stops[i + 1];
    return 'rgb(' + a.map((v, k) => Math.round(v + (b[k] - v) * f)).join(',') + ')'; };
  const zoneColor = r => r > 1.5 ? '#a855f7' : r > 1.2 ? '#f43f5e' : r > 1.05 ? '#f97316' : r > 0.9 ? '#f59e0b' : r > 0.75 ? '#10b981' : r > 0.55 ? '#0ea5e9' : '#94a3b8';
  const fmtMetric = (k, v) => k === 'speed' && MAP_IS_RUN ? (v > 0 ? Math.floor(3600 / v / 60) + ':' + String(Math.round(3600 / v) % 60).padStart(2, '0') + '/km' : '–') : Math.round(v) + ' ' + UNITS[k];

  function drawTabs() {
    tabs.innerHTML = avail.map(k => `<button type="button" class="btn" data-size="sm" ${k === cur ? '' : 'data-variant="outline"'} data-k="${k}">${LABELS[k]}</button>`).join('');
    tabs.querySelectorAll('button').forEach(b => b.onclick = () => { cur = b.dataset.k; drawTabs(); draw(); });
  }
  function draw() {
    if (layers) layers.clearLayers(); else layers = L.layerGroup().addTo(map);
    const P = G.pts;
    L.polyline(P, {color: dark ? '#000' : '#fff', weight: 8, opacity: .55, lineJoin: 'round'}).addTo(layers);
    let lg = '';
    if (cur === 'track') {
      L.polyline(P, {color: '#3b82f6', weight: 4, lineJoin: 'round'}).addTo(layers);
    } else {
      const v = G[cur], s = v.filter(x => x > 0).sort((a, b) => a - b);
      const lo = s[Math.floor(s.length * .05)] || 0, hi = s[Math.floor(s.length * .95)] || 1;
      const colAt = i => cur === 'watts' ? zoneColor((v[i] || 0) / (ftpValue || 1)) : grad(Math.round(((v[i] - lo) / ((hi - lo) || 1)) * 12) / 12);
      let runCol = colAt(1), run = [P[0], P[1]];
      for (let i = 2; i < P.length; i++) {
        const c = colAt(i);
        if (c === runCol) run.push(P[i]);
        else { L.polyline(run, {color: runCol, weight: 4, lineJoin: 'round'}).addTo(layers); runCol = c; run = [P[i - 1], P[i]]; }
      }
      L.polyline(run, {color: runCol, weight: 4, lineJoin: 'round'}).addTo(layers);
      lg = cur === 'watts'
        ? '<div class="flex items-center gap-1">' + ['Z1', 'Z2', 'Z3', 'Z4', 'Z5', 'Z6', 'Z7'].map((z, i) => `<span style="background:${zoneColor([0.4, 0.65, 0.85, 1, 1.12, 1.35, 1.7][i])}" class="rounded px-1 text-[10px] font-semibold text-white">${z}</span>`).join('') + '</div>'
        : `<div class="flex items-center gap-2"><span>${fmtMetric(cur, lo)}</span><span class="h-2 w-24 rounded" style="background:linear-gradient(90deg,${grad(0)},${grad(.33)},${grad(.66)},${grad(1)})"></span><span>${fmtMetric(cur, hi)}</span></div>`;
    }
    legend.innerHTML = lg; legend.style.display = lg ? 'block' : 'none';
    L.circleMarker(P[0], {radius: 7, color: '#fff', weight: 2, fillColor: '#22c55e', fillOpacity: 1}).bindTooltip('Départ').addTo(layers);
    L.circleMarker(P[P.length - 1], {radius: 7, color: '#fff', weight: 2, fillColor: '#ef4444', fillOpacity: 1}).bindTooltip('Arrivée').addTo(layers);
  }
  function init(g) {
    if (!g || !g.pts || g.pts.length < 2) { card.remove(); return; }
    G = g; msg.style.display = 'none';
    avail = ['track'].concat(['watts', 'hr', 'speed', 'alt'].filter(k => g[k] && g[k].some(x => x > 0)));
    cur = MAP_IS_RUN && avail.includes('speed') ? 'speed' : avail.includes('watts') ? 'watts' : avail.includes('speed') ? 'speed' : 'track';
    map = L.map(box, {scrollWheelZoom: false});
    window.trqMap = map;
    setTiles(localStorage.mapTiles && TILES[localStorage.mapTiles] ? localStorage.mapTiles : 'osm');
    const sel = document.getElementById('mapTiles');
    sel.value = tileKey; sel.onchange = e => { localStorage.mapTiles = e.target.value; setTiles(e.target.value); };
    map.fitBounds(L.latLngBounds(g.pts), {padding: [30, 30]});
    window.trqMapFit = () => map.fitBounds(L.latLngBounds(G.pts), { padding: [30, 30] });
    map.on('focus', () => map.scrollWheelZoom.enable()); map.on('blur', () => map.scrollWheelZoom.disable());
    drawTabs(); draw();
    setTimeout(() => map.invalidateSize(), 250);
  }
  window.mapHighlightLap = function (s, e) {
    if (!map || !G) return;
    if (hl) { hl.remove(); hl = null; }
    if (s == null) return;
    const a = Math.floor(s / G.step), b = Math.max(a + 1, Math.ceil(e / G.step)), seg = G.pts.slice(a, b + 1);
    if (seg.length < 2) return;
    hl = L.layerGroup([L.polyline(seg, {color: '#000', weight: 11, opacity: .6}), L.polyline(seg, {color: '#fbbf24', weight: 6})]).addTo(map);
  };
  if (initial) init(initial);
  else fetch('sync.php', {method: 'POST', body: new URLSearchParams({action: 'fetch_gps', id: ACT_ID})})
    .then(r => r.json())
    .then(j => { if (j.ok) init(j.gps); else msg.textContent = 'Carte indisponible : ' + j.error; })
    .catch(e => msg.textContent = 'Carte indisponible : ' + e.message);
})();
    <?php endif; ?>

    <?php if (!empty($laps)): ?>
// ===== Graphique par tour : largeur = durée, survol = détail, clic = ligne du tableau =====
const LAPS = <?= json_encode(array_values($laps)) ?>;
const IS_RUN_PAGE = <?= $isRun ? 'true' : 'false' ?>;
(function () {
  const box = document.getElementById('lapChartBox');
  if (!box || !LAPS.length) return;
  const tip = document.getElementById('lapTip'), tabs = document.getElementById('lapMetricTabs');
  const pace = kmh => { if (!(kmh > 0)) return '–'; const s = Math.round(3600 / kmh); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') + '/km'; };
  const hms = s => { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return h ? `${h}:${String(m).padStart(2, '0')}:${String(x).padStart(2, '0')}` : `${m}:${String(x).padStart(2, '0')}`; };
  const M = {
    power: {label: 'Puissance', unit: 'W', get: l => l.avg_w, fmt: v => Math.round(v) + ' W'},
    hr:    {label: 'FC', unit: 'bpm', get: l => l.avg_hr, fmt: v => Math.round(v) + ' bpm'},
    cad:   {label: 'Cadence', unit: 'rpm', get: l => l.cadence, fmt: v => Math.round(v) + ' rpm'},
    speed: {label: IS_RUN_PAGE ? 'Allure' : 'Vitesse', unit: 'km/h', get: l => l.avg_speed, fmt: v => IS_RUN_PAGE ? pace(v) : v.toFixed(1) + ' km/h'},
  };
  const avail = Object.keys(M).filter(k => LAPS.some(l => M[k].get(l) > 0));
  if (!avail.length) { box.closest('.card').remove(); return; }
  let cur = IS_RUN_PAGE && avail.includes('speed') ? 'speed' : (avail.includes('power') ? 'power' : avail[0]);
  const zoneColor = r => r > 1.5 ? '#a855f7' : r > 1.2 ? '#f43f5e' : r > 1.05 ? '#f97316' : r > 0.9 ? '#f59e0b' : r > 0.75 ? '#10b981' : r > 0.55 ? '#0ea5e9' : '#94a3b8';
  let cum = 0;
  LAPS.forEach(l => { l._s = l.start != null ? l.start : cum; l._e = l.end != null ? l.end : l._s + l.duration; cum = l._e; });
  const total = Math.max(1, <?= (int)$nStream ?>, ...LAPS.map(l => l._e));

  function drawTabs() {
    tabs.innerHTML = avail.map(k => `<button type="button" class="btn" data-size="sm" ${k === cur ? '' : 'data-variant="outline"'} data-k="${k}">${M[k].label}</button>`).join('');
    tabs.querySelectorAll('button').forEach(b => b.onclick = () => { cur = b.dataset.k; drawTabs(); render(); });
  }
  function render() {
    const W = Math.max(360, box.clientWidth), H = 320, m = {l: 54, r: 54, t: 16, b: 34};
    const iw = W - m.l - m.r, ih = H - m.t - m.b, sx = iw / total;
    const vals = LAPS.map(l => M[cur].get(l) || 0), vmax = (Math.max(...vals) * 1.12) || 1;
    const y = v => m.t + ih - (v / vmax) * ih;
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Graphique par tour" style="display:block;width:100%;height:auto">`;
    for (let i = 0; i <= 4; i++) {
      const v = vmax * i / 4, yy = y(v);
      s += `<line x1="${m.l}" x2="${W - m.r}" y1="${yy}" y2="${yy}" stroke="var(--border)"/>`
         + `<text x="${m.l - 8}" y="${yy + 4}" text-anchor="end" font-size="11" style="fill:var(--muted-foreground)">${Math.round(v)}</text>`;
    }
    s += `<text x="${m.l - 8}" y="${m.t - 4}" text-anchor="end" font-size="10" style="fill:var(--muted-foreground)">${M[cur].unit}</text>`;
    const step = [30, 60, 120, 300, 600, 900, 1800, 3600, 7200].find(t => total / t <= 10) || 7200;
    for (let t = 0; t <= total; t += step)
      s += `<text x="${m.l + t * sx}" y="${H - 10}" text-anchor="middle" font-size="11" style="fill:var(--muted-foreground)">${hms(t)}</text>`;
    let x = m.l; const xs = [];
    LAPS.forEach((l, i) => {
      const x = m.l + l._s * sx, w = Math.max(1, (l._e - l._s) * sx - 1.5), v = vals[i], yy = y(v), h = Math.max(0, m.t + ih - yy);
      const col = cur === 'power' ? zoneColor((l.avg_w || 0) / (ftpValue || 1)) : cur === 'hr' ? '#ef4444' : cur === 'cad' ? '#06b6d4' : '#3b82f6';
      s += `<g class="lap" data-i="${i}" style="cursor:pointer"><rect x="${x}" y="${yy}" width="${w}" height="${h}" rx="3" fill="${col}" opacity="${l.is_work ? 0.95 : 0.5}"/>`;
      if (w > 26) s += `<text x="${x + w / 2}" y="${m.t + ih - 6}" text-anchor="middle" font-size="11" font-weight="600" fill="#fff" style="pointer-events:none">${l.id}</text>`;
      if (w > 64 && h > 52) s += `<text x="${x + w / 2}" y="${m.t + ih - 24}" text-anchor="middle" font-size="11" fill="#fff" style="pointer-events:none">${M[cur].fmt(v)}</text>`;
      s += '</g>'; xs.push(x + w / 2);
    });
    if (cur === 'power' && ftpValue > 0 && ftpValue < vmax)
      s += `<line x1="${m.l}" x2="${W - m.r}" y1="${y(ftpValue)}" y2="${y(ftpValue)}" stroke="#ef4444" stroke-dasharray="5 4" opacity=".8"/>`
         + `<text x="${W - m.r + 4}" y="${y(ftpValue) + 4}" font-size="11" fill="#ef4444">FTP</text>`;
    if (cur !== 'hr' && avail.includes('hr')) {
      const hv = LAPS.map(l => l.avg_hr || 0), pos = hv.filter(v => v > 0);
      const lo = Math.min(...pos) - 6, hi = Math.max(...pos) + 6, yh = v => m.t + ih - ((v - lo) / (hi - lo)) * ih;
      const pts = hv.map((v, i) => v > 0 ? [xs[i], yh(v)] : null).filter(Boolean);
      s += `<polyline fill="none" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="4 3" opacity=".75" points="${pts.map(p => p.join(',')).join(' ')}"/>`
         + pts.map(p => `<circle cx="${p[0]}" cy="${p[1]}" r="3.5" fill="#ef4444" style="pointer-events:none"/>`).join('');
      for (let i = 0; i <= 2; i++) { const v = lo + (hi - lo) * i / 2; s += `<text x="${W - m.r + 8}" y="${yh(v) + 4}" font-size="11" fill="#ef4444">${Math.round(v)}</text>`; }
    }
    box.innerHTML = s + '</svg>';
    box.querySelectorAll('.lap').forEach(g => {
      const l = LAPS[+g.dataset.i];
      g.onmousemove = e => {
        const r = box.getBoundingClientRect();
        const lines = [`<div class="font-semibold">${l.label || ('Tour ' + l.id)}${l.is_work ? '' : ' · récup'}</div>`,
          `<div>${hms(l.duration)}${l.distance ? ' · ' + (l.distance / 1000).toFixed(2) + ' km' : ''}</div>`];
        if (l.avg_w > 0) lines.push(`<div>${Math.round(l.avg_w)} W${ftpValue ? ' (' + Math.round(l.avg_w / ftpValue * 100) + ' % FTP)' : ''} · NP ${Math.round(l.np)} W</div>`);
        if (l.avg_hr > 0) lines.push(`<div>FC ${Math.round(l.avg_hr)} bpm (max ${Math.round(l.max_hr)})</div>`);
        if (l.cadence > 0) lines.push(`<div>Cadence ${Math.round(l.cadence)} rpm</div>`);
        if (l.avg_speed > 0) lines.push(`<div>${IS_RUN_PAGE ? pace(l.avg_speed) : l.avg_speed.toFixed(1) + ' km/h'}</div>`);
        tip.innerHTML = lines.join('');
        tip.style.display = 'block';
        tip.style.left = Math.min(e.clientX - r.left + 14, r.width - tip.offsetWidth - 4) + 'px';
        tip.style.top = Math.max(0, e.clientY - r.top - tip.offsetHeight - 12) + 'px';
      };
      g.addEventListener('mouseenter', () => window.mapHighlightLap && window.mapHighlightLap(l.start, l.end));
      g.onmouseleave = () => { tip.style.display = 'none'; window.mapHighlightLap && window.mapHighlightLap(null); };
      g.onclick = () => {
        const tr = document.querySelector(`tr[data-lap="${l.id}"]`);
        if (tr) { tr.scrollIntoView({block: 'center', behavior: 'smooth'}); tr.classList.remove('lap-flash'); void tr.offsetWidth; tr.classList.add('lap-flash'); }
      };
    });
  }
  drawTabs(); render();
  let raf; new ResizeObserver(() => { cancelAnimationFrame(raf); raf = requestAnimationFrame(render); }).observe(box);
})();
    <?php endif; ?>

    // Graphe MMP Séance vs 90 jours
    const wMmp = <?= json_encode($workoutMmp) ?>;
    const sMmp = <?= json_encode($seasonBestMmp) ?>;
    const mmpKeys = Array.from(new Set([...Object.keys(wMmp), ...Object.keys(sMmp)])).map(Number).sort((a,b)=>a-b);
    const lblFmt = s => s < 60 ? s + 's' : s < 3600 ? Math.round(s / 60) + 'min' : (s / 3600).toFixed(1) + 'h';

    new Chart('mmpCompare', {
        type: 'line',
        data: {
            labels: mmpKeys.map(lblFmt),
            datasets: [
                {
                    label: 'Cette séance',
                    data: mmpKeys.map(d => wMmp[d] || null),
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.12)',
                    fill: true,
                    tension: 0.25,
                    borderWidth: 2.2,
                    pointRadius: 2
                },
                {
                    label: 'Record 90 jours (Saison)',
                    data: mmpKeys.map(d => sMmp[d] || null),
                    borderColor: '#f43f5e',
                    borderDash: [4, 4],
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: 2
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: { y: { title: { display: true, text: 'Watts' } } }
        }
    });

    // Graphe Zones
    <?php if ($zones): ?>
    new Chart('zonesChart', {
        type: 'bar',
        data: {
            labels: ['Z1 Récup', 'Z2 Endur.', 'Z3 Tempo', 'Z4 Seuil', 'Z5 VO2', 'Z6 Anaér.', 'Z7 Neuro.'],
            datasets: [{
                data: <?= json_encode(array_map(fn($s) => round($s / 60, 1), $zones)) ?>,
                backgroundColor: ['#94a3b8', '#0ea5e9', '#10b981', '#f59e0b', '#f97316', '#f43f5e', '#a855f7'],
                borderRadius: 5
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { title: { display: true, text: 'Minutes' } } }
        }
    });
    <?php endif; ?>
    </script>
    <script>
    (function () {
      const bar = document.getElementById('actAnchorBar');
      if (!bar) return;
      const links = [...bar.querySelectorAll('a')];
      bar.addEventListener('click', ev => {
        const a = ev.target.closest('a');
        if (!a) return;
        const el = document.querySelector(a.getAttribute('href'));
        if (!el) return;
        ev.preventDefault();
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', a.getAttribute('href'));
      });
      const secs = links.map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
      const mark = () => {
        let cur = secs[0];
        for (const s of secs) { if (s.getBoundingClientRect().top <= 110) cur = s; }
        links.forEach(a => {
          const on = cur && a.getAttribute('href') === '#' + cur.id;
          a.classList.toggle('bg-muted', !!on);
          a.classList.toggle('text-foreground', !!on);
        });
      };
      addEventListener('scroll', mark, { passive: true });
      mark();
    })();
    </script>
    <?php foot(); exit;
}

/* ========================================================
   COURBES DE PUISSANCE WKO5 MULTI-SAISONS (PDC)
   ======================================================== */
if ($p === 'power') {
    head('Courbes de puissance WKO5', 'power');
    $sport = ($_GET['sport'] ?? 'ride') === 'run' ? 'run' : 'ride';
    $isRun = $sport === 'run';

    $availableSeasons = get_seasons_list();
    $s1 = $_GET['s1'] ?? array_key_first($availableSeasons) ?? 'd90';
    $s2 = $_GET['s2'] ?? (count($availableSeasons) > 1 ? array_keys($availableSeasons)[1] : 'all');

    $data1 = get_season_mmp($s1, $sport);
    $data2 = get_season_mmp($s2, $sport);

    $c1 = $data1['curve'];
    $m1 = $data1['model'];
    $c2 = $data2['curve'];
    $m2 = $data2['model'];

    $durations = [1, 5, 10, 15, 30, 60, 120, 180, 300, 600, 1200, 1800, 3600, 5400];
    ?>
    <div class="space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold tracking-tight">Courbes de puissance WKO5 & Comparaison</h1>
          <p class="text-sm text-muted-foreground">Power Duration Curve (PDC), modèle Critical Power (CP/W') et comparaison inter-saisons</p>
        </div>
        <div class="flex items-center gap-1 bg-muted p-1 rounded-lg">
          <?php foreach (['ride' => 'Vélo (Watts)', 'run' => 'Course (Vitesse)'] as $k => $l): ?>
            <a href="?p=power&sport=<?= $k ?>&s1=<?= $s1 ?>&s2=<?= $s2 ?>" class="btn text-xs <?= $sport === $k ? 'bg-background shadow-xs font-semibold' : '' ?>" data-variant="ghost" data-size="sm">
              <?= $l ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card p-4">
        <form method="get" class="flex flex-col sm:flex-row items-center gap-4">
          <input type="hidden" name="p" value="power">
          <input type="hidden" name="sport" value="<?= h($sport) ?>">

          <div class="flex items-center gap-2 w-full sm:w-auto">
            <span class="text-xs font-semibold text-cyan-500 whitespace-nowrap">● Période A :</span>
            <select class="select text-sm h-9" name="s1" onchange="this.form.submit()">
              <?php foreach ($availableSeasons as $k => $l): ?>
                <option value="<?= $k ?>" <?= $s1 === $k ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="text-xs text-muted-foreground font-bold">VS</div>

          <div class="flex items-center gap-2 w-full sm:w-auto">
            <span class="text-xs font-semibold text-rose-500 whitespace-nowrap">● Période B :</span>
            <select class="select text-sm h-9" name="s2" onchange="this.form.submit()">
              <?php foreach ($availableSeasons as $k => $l): ?>
                <option value="<?= $k ?>" <?= $s2 === $k ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button class="btn" data-variant="outline" data-size="sm" type="submit">Actualiser la comparaison</button>
        </form>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php
        if ($m1 && !$isRun) {
            $diffCp = $m2 ? $m1['cp'] - $m2['cp'] : 0;
            stat_card('Puissance Critique (CP)', n($m1['cp']) . ' W', $m2 ? 'Diff. : <span class="' . ($diffCp >= 0 ? 'text-emerald-500 font-bold' : 'text-red-500 font-bold') . '">' . sprintf('%+d W', $diffCp) . '</span>' : 'Modèle WKO5');
            stat_card("W' (Capacité anaérobie)", n($m1['wp'] / 1000, 1) . ' kJ', $m2 ? 'Pér. B : ' . n($m2['wp'] / 1000, 1) . ' kJ' : '');
            stat_card('eFTP estimé', n($m1['eftp']) . ' W', $weight ? n($m1['eftp'] / $weight, 2) . ' W/kg' : '');
            stat_card('Pmax (Sprint 5s)', n($m1['pmax']) . ' W', $m2 ? 'Pér. B : ' . n($m2['pmax']) . ' W' : '');
        } else {
            stat_card('Modèle CP', '–', 'Données insuffisantes');
            stat_card("W'", '–', '');
            stat_card('eFTP', '–', '');
            stat_card('Pmax', '–', '');
        }
        ?>
      </div>

      <div class="card">
        <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h3 class="text-base font-semibold">Superposition des courbes Power Duration Curve</h3>
            <p class="text-xs text-muted-foreground"><?= $availableSeasons[$s1] ?? $s1 ?> (Cyan) vs <?= $availableSeasons[$s2] ?? $s2 ?> (Rose)</p>
          </div>
        </header>
        <section class="p-4">
          <div class="h-96"><canvas id="pdc"></canvas></div>
        </section>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Tableau comparatif détaillé & Gains / Pertes</h3>
        </header>
        <section class="p-0">
          <div class="table-container border-0 rounded-none">
            <table class="table">
              <thead>
                <tr>
                  <th>Durée</th>
                  <th><?= $availableSeasons[$s1] ?? 'Période A' ?> (Watts)</th>
                  <?= $weight ? '<th>W/kg</th>' : '' ?>
                  <th><?= $availableSeasons[$s2] ?? 'Période B' ?> (Watts)</th>
                  <th>Delta (W)</th>
                  <th>Évolution (%)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($durations as $d):
                    $v1 = $c1[$d] ?? null;
                    $v2 = $c2[$d] ?? null;
                    if (!$v1 && !$v2) continue;
                    $diff = ($v1 !== null && $v2 !== null) ? ($v1 - $v2) : null;
                    $pct = ($diff !== null && $v2 > 0) ? ($diff / $v2) * 100 : null;
                ?>
                  <tr>
                    <td class="font-medium text-xs"><?= fmt_label($d) ?></td>
                    <td class="tabular-nums font-bold text-cyan-600 dark:text-cyan-400">
                      <?= $v1 ? ($isRun ? pace((float)$v1) . ' /km' : n($v1) . ' W') : '–' ?>
                    </td>
                    <?php if ($weight): ?>
                      <td class="tabular-nums text-xs text-muted-foreground"><?= $v1 ? n($v1 / $weight, 2) : '–' ?></td>
                    <?php endif; ?>
                    <td class="tabular-nums font-medium text-rose-600 dark:text-rose-400">
                      <?= $v2 ? ($isRun ? pace((float)$v2) . ' /km' : n($v2) . ' W') : '–' ?>
                    </td>
                    <td class="tabular-nums font-semibold text-xs">
                      <?php if ($diff !== null): ?>
                        <span class="<?= $diff >= 0 ? 'text-emerald-500' : 'text-red-500' ?>">
                          <?= sprintf('%+d W', round($diff)) ?>
                        </span>
                      <?php else: ?>–<?php endif; ?>
                    </td>
                    <td>
                      <?php if ($pct !== null): ?>
                        <span class="badge <?= $pct >= 0 ? 'bg-emerald-500/15 text-emerald-600 border border-emerald-500/30' : 'bg-red-500/15 text-red-600 border border-red-500/30' ?>">
                          <?= sprintf('%+.1f %%', $pct) ?>
                        </span>
                      <?php else: ?>–<?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>

    <script>
    const c1 = <?= json_encode($c1) ?>;
    const c2 = <?= json_encode($c2) ?>;
    const m1 = <?= json_encode($m1) ?>;
    const m2 = <?= json_encode($m2) ?>;
    const isRun = <?= json_encode($isRun) ?>;

    const allKeys = Array.from(new Set([...Object.keys(c1), ...Object.keys(c2)])).map(Number).sort((a,b)=>a-b);
    const lbl = s => s < 60 ? s + 's' : s < 3600 ? Math.round(s / 60) + 'min' : (s / 3600).toFixed(1) + 'h';

    new Chart('pdc', {
        type: 'line',
        data: {
            labels: allKeys.map(lbl),
            datasets: [
                {
                    label: '<?= addslashes($availableSeasons[$s1] ?? 'Période A') ?>',
                    data: allKeys.map(d => c1[d] || null),
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.12)',
                    fill: true,
                    tension: 0.25,
                    borderWidth: 2.5,
                    pointRadius: 3
                },
                {
                    label: '<?= addslashes($availableSeasons[$s2] ?? 'Période B') ?>',
                    data: allKeys.map(d => c2[d] || null),
                    borderColor: '#f43f5e',
                    tension: 0.25,
                    borderWidth: 2,
                    borderDash: [5, 4],
                    pointRadius: 2
                },
                ...(m1 ? [{
                    label: 'Modèle CP (Pér. A)',
                    data: allKeys.map(d => d >= 120 ? +(m1.cp + m1.wp / d).toFixed(1) : null),
                    borderColor: '#38bdf8',
                    borderDash: [2, 2],
                    pointRadius: 0
                }] : [])
            ]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { title: { display: true, text: isRun ? 'Vitesse (km/h)' : 'Puissance (Watts)' } }
            }
        }
    });
    </script>
    <?php foot(); exit;
}

/* ========================================================
   WELLNESS & SANTÉ (HRV, FC REPOS, SOMMEIL)
   ======================================================== */
if ($p === 'wellness') {
    head('Wellness & Récupération', 'wellness');
    $summary = get_wellness_summary(60);
    $history = $summary['history'] ?? [];
    $todayRow = end($history) ?: null;
    ?>
    <div class="space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold tracking-tight">Wellness, Récupération & Sommeil</h1>
          <p class="text-sm text-muted-foreground">Suivi de la Variabilité de Fréquence Cardiaque (HRV rMSSD), Fréquence Cardiaque de repos et score de readiness</p>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn" onclick="openWellnessModal()">
            ＋ Enregistrer une mesure
          </button>
          <button class="btn" data-variant="outline" onclick="call('sync_wellness').then(()=>location.reload()).catch(e=>alert(e.message))">
            Synchroniser intervals.icu
          </button>
        </div>
      </div>

      <?php if (isset($_GET['saved'])): ?>
        <div class="alert"><h3>Mesure enregistrée avec succès</h3></div>
      <?php endif; ?>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php
        stat_card('Score de Readiness', !empty($todayRow['readiness']) ? round($todayRow['readiness']) . ' / 100' : '–', readiness_badge($todayRow['readiness'] ?? null));
        stat_card('HRV rMSSD', !empty($todayRow['hrv']) ? round($todayRow['hrv'], 1) . ' ms' : '–', 'Baseline 45j : ' . ($summary['baseline'] ?? '–') . ' ms');
        stat_card('FC au repos', !empty($todayRow['resting_hr']) ? round($todayRow['resting_hr']) . ' bpm' : '–', 'Normal : ' . cfg('rest_hr', '48') . ' bpm');
        stat_card('Sommeil', !empty($todayRow['sleep_h']) ? n($todayRow['sleep_h'], 1) . ' h' : '–', !empty($todayRow['sleep_score']) ? 'Score : ' . round($todayRow['sleep_score']) . '/100' : '');
        ?>
      </div>

      <div class="card">
        <header class="p-4 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h3 class="text-base font-semibold">Tendance HRV rMSSD & Couloir de normalité</h3>
            <p class="text-xs text-muted-foreground">Détection du surmenage parasympathique et de la fatigue nerveuse</p>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-cyan-500"></span> HRV du jour</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-500"></span> Moyenne 7j</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-2 bg-muted border border-dashed rounded"></span> Couloir normal</span>
          </div>
        </header>
        <section class="p-4">
          <div class="h-80"><canvas id="hrvChart"></canvas></div>
        </section>
      </div>

      <div class="grid lg:grid-cols-2 gap-6">
        <div class="card">
          <header class="p-4 border-b">
            <h3 class="text-base font-semibold">Fréquence cardiaque au repos (bpm)</h3>
            <p class="text-xs text-muted-foreground">Une élévation persistante signale fatigue ou infection</p>
          </header>
          <section class="p-4">
            <div class="h-64"><canvas id="rhrChart"></canvas></div>
          </section>
        </div>

        <div class="card">
          <header class="p-4 border-b">
            <h3 class="text-base font-semibold">Durée & Qualité du sommeil</h3>
            <p class="text-xs text-muted-foreground">Heures de sommeil et score de récupération nocturne</p>
          </header>
          <section class="p-4">
            <div class="h-64"><canvas id="sleepChart"></canvas></div>
          </section>
        </div>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Historique des entrées de santé</h3>
        </header>
        <section class="p-0">
          <div class="table-container border-0 rounded-none">
            <table class="table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Readiness</th>
                  <th>HRV</th>
                  <th>FC Repos</th>
                  <th>Sommeil</th>
                  <th>Poids</th>
                  <th>Fatigue</th>
                  <th>Stress</th>
                  <th>Courbatures</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_reverse($history) as $r): ?>
                  <tr>
                    <td class="font-medium text-xs whitespace-nowrap"><?= h($r['date']) ?></td>
                    <td><?= readiness_badge($r['readiness']) ?></td>
                    <td class="tabular-nums font-semibold text-xs"><?= !empty($r['hrv']) ? round($r['hrv'], 1) . ' ms' : '–' ?></td>
                    <td class="tabular-nums text-xs"><?= !empty($r['resting_hr']) ? round($r['resting_hr']) . ' bpm' : '–' ?></td>
                    <td class="tabular-nums text-xs"><?= !empty($r['sleep_h']) ? n($r['sleep_h'], 1) . ' h' : '–' ?></td>
                    <td class="tabular-nums text-xs"><?= !empty($r['weight']) ? n($r['weight'], 1) . ' kg' : '–' ?></td>
                    <td class="tabular-nums text-xs"><?= $r['fatigue'] ?? '–' ?> / 5</td>
                    <td class="tabular-nums text-xs"><?= $r['stress'] ?? '–' ?> / 5</td>
                    <td class="tabular-nums text-xs"><?= $r['soreness'] ?? '–' ?> / 5</td>
                    <td class="text-xs text-muted-foreground max-w-xs truncate"><?= h($r['comments'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>

    <script>
    const hist = <?= json_encode(array_values($history)) ?>;
    const D = hist.map(r => r.date);
    const upper = <?= json_encode($summary['upper_corridor'] ?? 75) ?>;
    const lower = <?= json_encode($summary['lower_corridor'] ?? 55) ?>;

    new Chart('hrvChart', {
        type: 'line',
        data: {
            labels: D,
            datasets: [
                {
                    label: 'HRV rMSSD',
                    data: hist.map(r => r.hrv),
                    borderColor: '#06b6d4',
                    pointRadius: 3,
                    backgroundColor: '#06b6d4',
                    borderWidth: 1.5,
                    tension: 0.2
                },
                {
                    label: 'Moyenne 7j',
                    data: hist.map(r => r.hrv_7d),
                    borderColor: '#10b981',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.25
                },
                {
                    label: 'Limite Haute (Normal)',
                    data: D.map(() => upper),
                    borderColor: 'rgba(148, 163, 184, 0.4)',
                    borderDash: [4, 4],
                    pointRadius: 0
                },
                {
                    label: 'Limite Basse (Normal)',
                    data: D.map(() => lower),
                    borderColor: 'rgba(148, 163, 184, 0.4)',
                    borderDash: [4, 4],
                    pointRadius: 0,
                    fill: '-1',
                    backgroundColor: 'rgba(148, 163, 184, 0.08)'
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: { x: { ticks: { maxTicksLimit: 10 } }, y: { title: { display: true, text: 'rMSSD (ms)' } } }
        }
    });

    new Chart('rhrChart', {
        type: 'line',
        data: {
            labels: D,
            datasets: [{
                label: 'FC repos (bpm)',
                data: hist.map(r => r.resting_hr),
                borderColor: '#f43f5e',
                backgroundColor: 'rgba(244, 63, 94, 0.12)',
                fill: true,
                tension: 0.2,
                pointRadius: 2.5
            }]
        },
        options: {
            maintainAspectRatio: false,
            scales: { x: { ticks: { maxTicksLimit: 8 } }, y: { title: { display: true, text: 'bpm' } } }
        }
    });

    new Chart('sleepChart', {
        type: 'bar',
        data: {
            labels: D,
            datasets: [
                {
                    type: 'line',
                    label: 'Score Sommeil',
                    data: hist.map(r => r.sleep_score),
                    borderColor: '#a855f7',
                    borderWidth: 2,
                    pointRadius: 0,
                    yAxisID: 'y1'
                },
                {
                    label: 'Heures de sommeil',
                    data: hist.map(r => r.sleep_h),
                    backgroundColor: '#0ea5e9',
                    borderRadius: 4
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: { ticks: { maxTicksLimit: 8 } },
                y: { title: { display: true, text: 'Heures' }, min: 4, max: 11 },
                y1: { position: 'right', grid: { drawOnChartArea: false }, min: 40, max: 100 }
            }
        }
    });
    </script>
    <?php foot(); exit;
}

/* ========================================================
   CALENDRIER D'ENTRAÎNEMENT STYLE TRAININGPEAKS
   ======================================================== */
if ($p === 'calendar') {
    head('Calendrier d’entraînement', 'calendar');

    $today = date('Y-m-d');
    $mParam = $_GET['m'] ?? date('Y-m');
    $firstDayOfMonth = new DateTime($mParam . '-01');
    $yearMonth = $firstDayOfMonth->format('Y-m');
    $prevM = (clone $firstDayOfMonth)->modify('-1 month')->format('Y-m');
    $nextM = (clone $firstDayOfMonth)->modify('+1 month')->format('Y-m');

    $monthStart = clone $firstDayOfMonth;
    if ((int)$monthStart->format('N') > 1) $monthStart->modify('-' . ((int)$monthStart->format('N') - 1) . ' days');
    $monthEnd = (clone $firstDayOfMonth)->modify('last day of this month');
    if ((int)$monthEnd->format('N') < 7) $monthEnd->modify('+' . (7 - (int)$monthEnd->format('N')) . ' days');

    $weekStart = (new DateTime('today'))->modify('monday this week');
    $weekEnd = (clone $weekStart)->modify('+6 days');
    $prevW = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
    $nextW = (clone $weekStart)->modify('+7 days')->format('Y-m-d');

    $calMoisFr = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $calJoursFr = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    $loadRange = function (string $from, string $to) use ($pdo): array {
        $byDay = [];
        $st = $pdo->prepare('SELECT ' . ACT_COLS . ' FROM activities WHERE date >= ? AND date <= ? ORDER BY date ASC');
        $st->execute([$from . 'T00:00:00', $to . 'T23:59:59']);
        foreach ($st->fetchAll() as $r) $byDay[substr($r['date'], 0, 10)]['acts'][] = $r;
        $st = $pdo->prepare('SELECT * FROM planned_workouts WHERE date >= ? AND date <= ?');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) $byDay[$r['date']]['planned'][] = $r;
        $st = $pdo->prepare('SELECT * FROM wellness WHERE date >= ? AND date <= ?');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) $byDay[$r['date']]['well'] = $r;
        return $byDay;
    };
    $byDayM = $loadRange($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
    $byDayW = $loadRange($weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));

    $cmpByPlan = []; $cmpByAct = []; $manualRows = []; $reserved = plan_reserved_ids();
    foreach ($pdo->query("SELECT activity_id FROM planned_workouts WHERE activity_id IS NOT NULL AND activity_id <> ''")->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $ms = $pdo->prepare('SELECT ' . ACT_COLS . ' FROM activities WHERE id=?'); $ms->execute([$mid]); if ($mr = $ms->fetch()) $manualRows[$mid] = $mr;
    }
    foreach ([$byDayM, $byDayW] as $byDay) {
        foreach ($byDay as $dd) {
            if (empty($dd['planned'])) continue;
            $pairs = plan_pair_day($dd['planned'], $dd['acts'] ?? [], $manualRows, $reserved);
            foreach ($dd['planned'] as $plRow) {
                if (!isset($pairs[$plRow['id']]) || isset($cmpByPlan[$plRow['id']])) continue;
                $pAct = $pairs[$plRow['id']];
                $cmpBudget = 10;
                $sum = plan_cmp_summary($plRow, $pAct, $cmpBudget);
                $cmpByPlan[$plRow['id']] = ['act' => $pAct, 'sum' => $sum];
                if ($sum) $cmpByAct[$pAct['id']] = ['score' => $sum['score'], 'level' => $sum['level']];
            }
        }
    }

    $lvlChip = fn(?array $sm) => $sm ? ['good' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300', 'warn' => 'bg-amber-500/15 text-amber-700 dark:text-amber-300', 'bad' => 'bg-red-500/15 text-red-700 dark:text-red-300', 'info' => 'bg-muted text-muted-foreground'][$sm['level']] ?? 'bg-muted text-muted-foreground' : 'bg-muted text-muted-foreground';

    $renderChip = function (array $a, bool $big) use ($cmpByAct, $lvlChip): string {
        $color = match ($a['sport']) {
            'ride' => 'border-l-sky-500 bg-sky-500/10 hover:bg-sky-500/15 dark:text-sky-100 text-sky-950',
            'run' => 'border-l-emerald-500 bg-emerald-500/10 hover:bg-emerald-500/15 dark:text-emerald-100 text-emerald-950',
            'swim' => 'border-l-cyan-500 bg-cyan-500/10 hover:bg-cyan-500/15 dark:text-cyan-100 text-cyan-950',
            default => 'border-l-purple-500 bg-purple-500/10 hover:bg-purple-500/15 dark:text-purple-100 text-purple-950',
        };
        $ico = match ($a['sport']) {
            'ride' => '🚴', 'run' => '🏃', 'swim' => '🏊',
            default => '⚡',
        };
        $titleSize = $big ? 'text-sm' : 'text-xs';
        $out = '<a href="?p=activity&id=' . h($a['id']) . '" class="block p-2 rounded-lg border-l-[3px] ' . $color . ' transition shadow-2xs">';
        $out .= '<div class="flex items-center justify-between font-semibold leading-snug ' . $titleSize . '"><span class="truncate">' . $ico . ' ' . h($a['name'] ?: $a['type']) . '</span>'
              . '<span class="shrink-0 rounded-full bg-background/70 px-1.5 py-0.5 text-[11px] font-bold tabular-nums">' . round((float)($a['tss'] ?? 0)) . '</span></div>';
        $met = '<span>⏱ ' . fmt_dur((int)$a['duration']) . '</span>';
        if (!empty($a['distance'])) $met .= '<span>📏 ' . round((float)$a['distance'] / 1000, 1) . ' km</span>';
        if (!empty($a['elev'])) $met .= '<span>⛰ ' . round((float)$a['elev']) . ' m</span>';
        $out .= '<div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 opacity-90 tabular-nums ' . ($big ? 'text-sm' : 'text-[12px]') . '">' . $met . '</div>';
        $extra = $a['sport'] === 'run'
            ? 'allure ' . pace($a['avg_speed'] ? (float)$a['avg_speed'] : null) . '/km'
            : (!empty($a['np']) ? round((float)$a['np']) . ' W NP' : (!empty($a['avg_w']) ? round((float)$a['avg_w']) . ' W' : ''));
        if (!empty($a['avg_hr'])) $extra .= ' · ' . round((float)$a['avg_hr']) . ' bpm';
        if ($extra !== '') $out .= '<div class="text-[11px] opacity-75 tabular-nums mt-0.5">' . h($extra) . '</div>';
        if (isset($cmpByAct[$a['id']])):
            $ca = $cmpByAct[$a['id']];
            $out .= '<div class="mt-1 inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-bold ' . $lvlChip(['level' => $ca['level']]) . '">🎯 ' . (int)$ca['score'] . ' % du plan</div>';
        endif;
        return $out . '</a>';
    };

    $renderPlan = function (array $pl, bool $big) use ($cmpByPlan, $lvlChip): string {
        $hasStruct = !empty($pl['profile']);
        $out = '<div class="group/pl relative p-2 rounded-lg border border-dashed border-muted-foreground/40 bg-muted/20 ' . ($big ? 'text-sm' : 'text-xs') . ' leading-tight">';
        if (!empty($pl['icu_text']) && cfg('api_key')) {
            $sent = !empty($pl['icu_event_id']);
            $out .= '<button type="button" class="absolute right-6 top-1 flex size-5 items-center justify-center rounded text-xs hover:bg-muted ' . ($sent ? 'text-emerald-600' : 'hidden text-muted-foreground group-hover/pl:flex') . '" onclick="pushPlanned(' . (int)$pl['id'] . ')" title="' . ($sent ? 'Envoyée à intervals.icu (cliquer pour mettre à jour)' : 'Envoyer à intervals.icu') . '" aria-label="Envoyer à intervals.icu">' . ($sent ? '✓' : '↗') . '</button>';
        }
        $out .= '<button type="button" class="absolute right-1 top-1 hidden size-5 items-center justify-center rounded text-muted-foreground hover:bg-muted group-hover/pl:flex" onclick="deletePlanned(' . (int)$pl['id'] . ')" title="Supprimer la séance planifiée" aria-label="Supprimer">✕</button>';
        if ($hasStruct) {
            $out .= '<a href="?p=workouts&plan=' . (int)$pl['id'] . '" class="block truncate pr-8 font-semibold hover:underline" title="Ouvrir dans le builder">🧩 ' . h($pl['title']) . '</a>'
                  . '<div class="mt-1">' . workout_mini_svg(json_decode($pl['profile'], true) ?: [], ($pl['tss_type'] ?? '') === 'hrtss' ? 'hr' : 'power', 160, 26) . '</div>';
        } else {
            $out .= '<div class="truncate pr-8 font-semibold text-muted-foreground">📝 ' . h($pl['title']) . '</div>';
        }
        $out .= '<div class="mt-1 flex items-center justify-between text-muted-foreground tabular-nums ' . ($big ? 'text-sm' : 'text-xs') . '"><span>⏱ ' . fmt_dur((int)$pl['duration']) . '</span><span class="font-bold">' . round((float)$pl['tss']) . ' TSS</span></div>';
        $cp = $cmpByPlan[$pl['id']] ?? null;
        if ($cp) {
            $sm = $cp['sum'];
            $out .= '<a href="?p=activity&id=' . h($cp['act']['id']) . '" class="mt-1.5 flex items-center justify-between rounded px-1.5 py-1 font-semibold ' . $lvlChip($sm) . '" title="' . h('Réalisée : ' . ($cp['act']['name'] ?: $cp['act']['type'])) . '"><span>✓ Réalisée</span><span class="tabular-nums">' . ($sm ? (int)$sm['score'] . ' %' : '…') . '</span></a>';
        } elseif ($pl['date'] < date('Y-m-d')) {
            $out .= '<div class="mt-1.5 rounded bg-red-500/10 px-1.5 py-1 font-medium text-red-600 dark:text-red-400">✗ Non réalisée</div>';
        }
        return $out . '</div>';
    };

    $renderWell = function (array $w): string {
        $cell = function (string $ico, $v, string $unit = '') {
            return '<span class="inline-flex items-center gap-1 rounded-md bg-muted/60 border border-border/60 px-1.5 py-0.5 tabular-nums text-[11px]"><span aria-hidden="true">' . $ico . '</span>' . ($v !== null && $v !== '' ? $v . $unit : '–') . '</span>';
        };
        return '<div class="mb-2 flex flex-wrap items-center gap-1">'
            . $cell('💤', !empty($w['sleep_sec']) ? round((float)$w['sleep_sec'] / 3600, 1) : null, 'h')
            . $cell('❤️', !empty($w['hrv']) ? round((float)$w['hrv']) : null, ' ms')
            . $cell('🫀', !empty($w['resting_hr']) ? round((float)$w['resting_hr']) : null, ' bpm')
            . $cell('⚖️', !empty($w['weight']) ? round((float)$w['weight'], 1) : null, ' kg')
            . '</div>';
    };

    $dayTssOf = function (array $dayData): float {
        $t = 0.0;
        foreach ($dayData['acts'] ?? [] as $ac) $t += (float)($ac['tss'] ?? 0);
        foreach ($dayData['planned'] ?? [] as $pl) if (empty($dayData['acts'])) $t += (float)($pl['tss'] ?? 0);
        return $t;
    };
    ?>
    <div class="space-y-4">
      <!-- Barre de navigation : Mois (desktop) / Semaine (mobile), commutée par le responsive -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-muted/30 p-3 rounded-xl border">
        <div class="max-md:contents">
          <div class="hidden md:flex items-center gap-2">
            <a class="btn" data-variant="outline" data-size="sm" aria-label="Mois précédent" href="?p=calendar&m=<?= $prevM ?>">←</a>
            <a class="btn" data-variant="outline" data-size="sm" href="?p=calendar">Aujourd’hui</a>
            <a class="btn" data-variant="outline" data-size="sm" aria-label="Mois suivant" href="?p=calendar&m=<?= $nextM ?>">→</a>
            <span class="text-lg font-bold ml-1 capitalize"><?= h($calMoisFr[(int)$firstDayOfMonth->format('n')] . ' ' . $firstDayOfMonth->format('Y')) ?></span>
          </div>
          <div class="flex md:hidden items-center gap-2">
            <a class="btn" data-variant="outline" data-size="sm" aria-label="Semaine précédente" href="?p=calendar&d=<?= $prevW ?>">←</a>
            <a class="btn" data-variant="outline" data-size="sm" href="?p=calendar">Aujourd’hui</a>
            <a class="btn" data-variant="outline" data-size="sm" aria-label="Semaine suivante" href="?p=calendar&d=<?= $nextW ?>">→</a>
            <span class="text-base font-bold ml-1"><?= h($weekStart->format('j') . ' ' . $calMoisFr[(int)$weekStart->format('n')] . ' – ' . $weekEnd->format('j') . ' ' . $calMoisFr[(int)$weekEnd->format('n')]) ?></span>
            <span class="text-xs font-semibold text-muted-foreground">· sem. <?= h($weekStart->format('W')) ?></span>
          </div>
        </div>
        <button class="btn" data-size="sm" onclick="openWorkoutModal()">＋ Planifier</button>
      </div>

      <?php /* ===================== VUE SEMAINE (mobile) ===================== */ ?>
      <div class="md:hidden space-y-3">
        <?php
        $cursor = clone $weekStart;
        while ($cursor <= $weekEnd):
            $cDate = $cursor->format('Y-m-d');
            $dayData = $byDayW[$cDate] ?? [];
            $isToday = $cDate === $today;
            $dayTss = $dayTssOf($dayData);
        ?>
          <div class="rounded-xl border <?= $isToday ? 'ring-2 ring-primary/70 bg-primary/5 border-primary/30' : 'bg-background' ?> shadow-xs">
            <header class="flex items-center justify-between gap-2 px-3.5 pt-3 pb-2 border-b border-border/50">
              <div class="flex items-center gap-2.5 min-w-0">
                <span class="w-11 shrink-0 text-sm font-bold uppercase tracking-wide text-muted-foreground"><?= h(mb_substr($calJoursFr[(int)$cursor->format('N')], 0, 3)) ?></span>
                <span class="grid place-items-center size-9 shrink-0 rounded-full text-lg font-bold <?= $isToday ? 'bg-primary text-primary-foreground' : 'text-foreground' ?>"><?= (int)$cursor->format('j') ?></span>
                <?php if ($isToday): ?><span class="badge">Aujourd’hui</span><?php endif; ?>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <?php if ($dayTss > 0): ?><span class="rounded-full bg-muted px-2.5 py-1 text-xs font-bold tabular-nums"><?= round($dayTss) ?> TSS</span><?php endif; ?>
                <button type="button" onclick="openWorkoutModal('<?= $cDate ?>')" class="btn" data-variant="ghost" data-size="sm" aria-label="Ajouter une séance le <?= $cDate ?>" title="Ajouter une séance">＋</button>
              </div>
            </header>
            <section class="p-3 space-y-2">
              <?php if (!empty($dayData['well'])) echo $renderWell($dayData['well']); ?>
              <?php if (empty($dayData['acts']) && empty($dayData['planned'])): ?>
                <p class="py-3 text-center text-sm text-muted-foreground">Aucune séance</p>
              <?php endif; ?>
              <?php foreach ($dayData['planned'] ?? [] as $pl) echo $renderPlan($pl, true); ?>
              <?php foreach ($dayData['acts'] ?? [] as $ac) echo $renderChip($ac, true); ?>
            </section>
          </div>
        <?php $cursor->modify('+1 day'); endwhile; ?>

        <div class="rounded-xl border bg-muted/40 p-4">
          <div class="flex items-center justify-between border-b pb-2 mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Semaine <?= h($weekStart->format('W')) ?></span>
            <span class="text-xl font-black text-primary tabular-nums"><?= round(array_sum(array_map($dayTssOf, $byDayW))) ?> TSS</span>
          </div>
          <?php
          $wt = ['dur' => 0, 'ride' => 0.0, 'rideT' => 0, 'run' => 0.0, 'runT' => 0, 'swim' => 0.0];
          foreach ($byDayW as $dd) foreach ($dd['acts'] ?? [] as $ac) {
              $wt['dur'] += (int)($ac['duration'] ?? 0);
              if ($ac['sport'] === 'ride') { $wt['ride'] += (float)($ac['distance'] ?? 0); $wt['rideT'] += (int)($ac['duration'] ?? 0); }
              elseif ($ac['sport'] === 'run') { $wt['run'] += (float)($ac['distance'] ?? 0); $wt['runT'] += (int)($ac['duration'] ?? 0); }
              elseif ($ac['sport'] === 'swim') $wt['swim'] += (float)($ac['distance'] ?? 0);
          }
          ?>
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div><p class="text-xs text-muted-foreground">Volume total</p><p class="font-bold tabular-nums"><?= fmt_dur($wt['dur']) ?></p></div>
            <?php if ($wt['ride'] > 0): ?><div class="text-sky-600 dark:text-sky-400"><p class="text-xs opacity-80">🚴 Vélo</p><p class="font-semibold tabular-nums"><?= round($wt['ride'] / 1000, 1) ?> km (<?= fmt_dur($wt['rideT']) ?>)</p></div><?php endif; ?>
            <?php if ($wt['run'] > 0): ?><div class="text-emerald-600 dark:text-emerald-400"><p class="text-xs opacity-80">🏃 Course</p><p class="font-semibold tabular-nums"><?= round($wt['run'] / 1000, 1) ?> km (<?= fmt_dur($wt['runT']) ?>)</p></div><?php endif; ?>
            <?php if ($wt['swim'] > 0): ?><div class="text-cyan-600 dark:text-cyan-400"><p class="text-xs opacity-80">🏊 Natation</p><p class="font-semibold tabular-nums"><?= round($wt['swim']) ?> m</p></div><?php endif; ?>
          </div>
        </div>
      </div>

      <?php /* ===================== VUE MOIS (desktop) ===================== */ ?>
      <div class="hidden md:block">
        <div class="border rounded-xl overflow-hidden bg-background shadow-xs">
          <div class="grid grid-cols-8 border-b bg-muted/60 text-center text-xs font-bold uppercase tracking-wide divide-x divide-border">
            <div class="py-3">Lun</div><div class="py-3">Mar</div><div class="py-3">Mer</div><div class="py-3">Jeu</div><div class="py-3">Ven</div><div class="py-3">Sam</div><div class="py-3">Dim</div>
            <div class="py-3 bg-muted/90 text-primary text-[11px]">Résumé semaine</div>
          </div>

          <?php
          $cursor = clone $monthStart;
          while ($cursor <= $monthEnd):
              $wActs = [];
              for ($i = 0; $i < 7; $i++) {
                  $wActs[] = ['date' => $cursor->format('Y-m-d'), 'in_month' => $cursor->format('Y-m') === $yearMonth, 'is_today' => $cursor->format('Y-m-d') === $today, 'day_num' => (int)$cursor->format('j'), 'data' => $byDayM[$cursor->format('Y-m-d')] ?? []];
                  $cursor->modify('+1 day');
              }
              $weekTss = 0.0; $weekDuration = 0; $weekDistRide = 0.0; $weekDistRun = 0.0; $weekDistSwim = 0.0; $weekTimeRide = 0; $weekTimeRun = 0;
              foreach ($wActs as $wd) foreach ($wd['data']['acts'] ?? [] as $ac) {
                  $weekTss += (float)($ac['tss'] ?? 0); $weekDuration += (int)($ac['duration'] ?? 0);
                  if ($ac['sport'] === 'ride') { $weekDistRide += (float)($ac['distance'] ?? 0); $weekTimeRide += (int)$ac['duration']; }
                  elseif ($ac['sport'] === 'run') { $weekDistRun += (float)($ac['distance'] ?? 0); $weekTimeRun += (int)$ac['duration']; }
                  elseif ($ac['sport'] === 'swim') $weekDistSwim += (float)($ac['distance'] ?? 0);
              }
              $weekNum = $wActs[0]['date'] ? date('W', strtotime($wActs[0]['date'])) : '';
          ?>
          <div class="grid grid-cols-8 divide-x divide-border border-b last:border-b-0">
            <?php foreach ($wActs as $wd):
                $dayData = $wd['data'];
            ?>
              <div class="p-2.5 flex flex-col justify-between min-h-[180px] <?= $wd['is_today'] ? 'bg-primary/5 ring-1 ring-primary/60 ring-inset' : ($wd['in_month'] ? 'hover:bg-muted/15' : 'bg-muted/20 opacity-50') ?> transition relative group">
                <div class="flex items-center justify-between mb-2">
                  <span class="<?= $wd['is_today'] ? 'grid place-items-center size-7 rounded-full bg-primary text-primary-foreground' : '' ?> text-sm font-bold tabular-nums"><?= $wd['day_num'] ?></span>
                  <div class="flex items-center gap-1.5">
                    <?php if (($dt = $dayTssOf($dayData)) > 0): ?><span class="text-[11px] font-bold px-1.5 py-0.5 rounded-full bg-muted text-muted-foreground tabular-nums"><?= round($dt) ?></span><?php endif; ?>
                    <button type="button" onclick="openWorkoutModal('<?= $wd['date'] ?>')" class="size-5 rounded hover:bg-muted text-muted-foreground flex items-center justify-center text-sm opacity-60 group-hover:opacity-100 transition" title="Ajouter une séance">+</button>
                  </div>
                </div>

                <?php if (!empty($dayData['well'])) echo $renderWell($dayData['well']); ?>

                <div class="flex-1 space-y-2 overflow-y-auto max-h-[320px]">
                  <?php foreach ($dayData['planned'] ?? [] as $pl) echo $renderPlan($pl, false); ?>
                  <?php foreach ($dayData['acts'] ?? [] as $ac) echo $renderChip($ac, false); ?>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="p-3.5 bg-muted/40 flex flex-col justify-between border-l border-border">
              <div>
                <div class="flex items-center justify-between border-b pb-1.5 mb-2.5">
                  <span class="text-[11px] font-bold text-muted-foreground uppercase tracking-wider">Sem. <?= $weekNum ?></span>
                  <span class="text-base font-black text-primary tabular-nums"><?= round($weekTss) ?></span>
                </div>
                <div class="space-y-2 text-xs">
                  <div class="flex items-center justify-between gap-1"><span class="text-muted-foreground">Volume</span><span class="font-bold tabular-nums"><?= fmt_dur($weekDuration) ?></span></div>
                  <?php if ($weekDistRide > 0): ?><div class="flex items-center justify-between gap-1 text-sky-600 dark:text-sky-400"><span>🚴</span><span class="font-semibold tabular-nums"><?= round($weekDistRide / 1000, 1) ?> km</span></div><?php endif; ?>
                  <?php if ($weekDistRun > 0): ?><div class="flex items-center justify-between gap-1 text-emerald-600 dark:text-emerald-400"><span>🏃</span><span class="font-semibold tabular-nums"><?= round($weekDistRun / 1000, 1) ?> km</span></div><?php endif; ?>
                  <?php if ($weekDistSwim > 0): ?><div class="flex items-center justify-between gap-1 text-cyan-600 dark:text-cyan-400"><span>🏊</span><span class="font-semibold tabular-nums"><?= round($weekDistSwim) ?> m</span></div><?php endif; ?>
                </div>
              </div>
              <div class="mt-3 pt-2.5 border-t border-border/60">
                <div class="text-[11px] text-muted-foreground flex justify-between"><span>Charge</span><span class="font-medium"><?= $weekTss > 400 ? 'Élevée' : ($weekTss > 250 ? 'Modérée' : 'Récup') ?></span></div>
                <div class="w-full bg-muted rounded-full h-2 mt-1.5 overflow-hidden"><div class="bg-primary h-2 rounded-full" style="width: <?= min(100, round(($weekTss / 600) * 100)) ?>%"></div></div>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
    <?php foot(); exit;
}

/* ========================================================
   ZONES PHYSIOLOGIQUES (POWER & HR)
   ======================================================== */
/* ================= ENTRAÎNEMENTS (Workout Builder) ================= */
if ($p === 'workouts') {
    head('Entraînements', 'workouts');
    $woId = (int)($_GET['id'] ?? 0); $planId = (int)($_GET['plan'] ?? 0);
    $woData = null; $planDate = null; $notice = '';
    if ($woId) {
        $st = $pdo->prepare('SELECT * FROM workouts WHERE id=?'); $st->execute([$woId]); $r = $st->fetch();
        if ($r) $woData = ['id' => (int)$r['id'], 'name' => $r['name'], 'sport' => $r['sport'], 'mode' => $r['mode'], 'unit' => $r['unit'], 'notes' => $r['notes'], 'structure' => json_decode($r['structure'] ?: '[]', true) ?: []];
        else $notice = 'Séance introuvable.';
    } elseif ($planId) {
        $st = $pdo->prepare('SELECT * FROM planned_workouts WHERE id=?'); $st->execute([$planId]); $r = $st->fetch();
        if ($r && !empty($r['structure'])) {
            $woData = ['id' => null, 'name' => $r['title'], 'sport' => $r['sport'] === 'run' ? 'run' : 'ride', 'mode' => ($r['tss_type'] ?? '') === 'hrtss' ? 'hr' : 'power', 'unit' => 'pct', 'notes' => $r['notes'], 'structure' => json_decode($r['structure'], true) ?: []];
            $planDate = $r['date']; $notice = 'Copie de la séance planifiée du ' . $r['date'] . ' : modifiez-la puis enregistrez-la ou planifiez-la à nouveau.';
        } else $notice = 'Cette séance planifiée n’a pas de structure (créée depuis le calendrier).';
    }
    $lib = $pdo->query('SELECT id, name, sport, mode, profile, duration, tss, if_ FROM workouts ORDER BY updated_at DESC')->fetchAll();
    $woCfg = ['ftp' => (float)cfg('ftp', 250), 'hasFtp' => (bool)cfg('ftp'), 'lthr' => (float)cfg('lthr', 170), 'hasLthr' => (bool)cfg('lthr'),
              'rest' => (float)cfg('rest_hr', 55), 'today' => date('Y-m-d'), 'planDate' => $planDate, 'workout' => $woData];
    ?>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Entraînements</h1>
        <p class="text-sm text-muted-foreground">Construisez une séance structurée à la puissance ou à la fréquence cardiaque, puis planifiez-la ou exportez-la.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <select id="woTemplates" class="select h-8 w-56 text-sm" aria-label="Modèles"></select>
        <button type="button" id="woNew" class="btn" data-variant="outline" data-size="sm">Nouveau</button>
        <button type="button" id="woSave" class="btn" data-size="sm">💾 Enregistrer</button>
        <button type="button" id="woPlanBtn" class="btn" data-variant="outline" data-size="sm">📅 Planifier</button>
        <button type="button" id="woExportBtn" class="btn" data-variant="outline" data-size="sm">⬇ Exporter</button>
      </div>
    </div>

    <?php if (isset($_GET['saved'])): ?><div class="alert"><h2>Séance enregistrée dans la bibliothèque</h2></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert"><h2><?= h($notice) ?></h2></div><?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-5">
      <div class="space-y-4 lg:col-span-2">
        <div class="card">
          <header class="p-4 border-b"><h3 class="text-base font-semibold">Séance</h3></header>
          <section class="space-y-3 p-4">
            <div class="grid grid-cols-3 gap-3">
              <div class="col-span-2"><label class="text-xs font-medium">Nom</label><input id="woName" class="input text-sm" maxlength="80" placeholder="Ex : Sweet Spot 3×15"></div>
              <div><label class="text-xs font-medium">Discipline</label><select id="woSport" class="select text-sm"><option value="ride">🚴 Vélo</option><option value="run">🏃 Course</option></select></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
              <div class="flex items-center gap-1" role="group" aria-label="Mode de cible">
                <button type="button" class="btn" data-size="sm" data-mode="power">⚡ Puissance</button>
                <button type="button" class="btn" data-size="sm" data-mode="hr" data-variant="outline">❤️ Fréquence cardiaque</button>
              </div>
              <div class="flex items-center gap-1" role="group" aria-label="Unité">
                <button type="button" class="btn" data-size="sm" data-unit="pct">% FTP</button>
                <button type="button" class="btn" data-size="sm" data-unit="abs" data-variant="outline">Watts</button>
              </div>
            </div>
            <p class="text-xs text-muted-foreground" id="woModeHint"></p>
            <div><label class="text-xs font-medium">Description / consignes (optionnel)</label><textarea id="woNotes" class="textarea text-sm" rows="2" maxlength="2000" placeholder="Objectif de la séance, cadence, ressenti…"></textarea></div>
          </section>
        </div>
        <div class="card">
          <header class="p-4 border-b flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-base font-semibold">Étapes</h3>
            <div class="flex flex-wrap gap-1">
              <button type="button" class="btn" data-size="sm" data-variant="outline" data-add="warmup">＋ Échauffement</button>
              <button type="button" class="btn" data-size="sm" data-variant="outline" data-add="work">＋ Effort</button>
              <button type="button" class="btn" data-size="sm" data-variant="outline" data-add="rest">＋ Récup</button>
              <button type="button" class="btn" data-size="sm" data-variant="outline" data-add="steady">＋ Palier</button>
              <button type="button" class="btn" data-size="sm" data-variant="outline" data-add="cooldown">＋ Retour au calme</button>
              <button type="button" class="btn" data-size="sm" data-add="repeat">🔁 Bloc répété</button>
            </div>
          </header>
          <section class="p-4"><div id="woSteps" class="space-y-2"></div></section>
        </div>
      </div>

      <div class="lg:col-span-3">
        <div class="card lg:sticky lg:top-20">
          <header class="p-4 border-b"><h3 class="text-base font-semibold">Profil de la séance</h3><p class="text-xs text-muted-foreground">Cliquez sur une barre pour retrouver son étape. Les plages (min–max) apparaissent en bande plus claire.</p></header>
          <section class="space-y-4 p-4">
            <p id="woWarn" class="text-xs text-amber-600 dark:text-amber-400"></p>
            <div id="woTiles" class="grid grid-cols-2 gap-3 sm:grid-cols-4"></div>
            <div id="woChart" class="relative w-full">
              <div id="woTip" class="pointer-events-none absolute z-10 rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md" style="display:none"></div>
            </div>
            <div id="woZones"></div>
          </section>
        </div>
      </div>
    </div>

    <div class="space-y-3">
      <h2 class="text-lg font-semibold">Bibliothèque <span class="text-sm font-normal text-muted-foreground">(<?= count($lib) ?>)</span></h2>
      <?php if (!$lib): ?>
        <p class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">Aucune séance enregistrée pour l’instant.</p>
      <?php else: ?>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($lib as $w): ?>
          <div class="card p-4 space-y-2 <?= (int)$w['id'] === $woId ? 'ring-2 ring-primary' : '' ?>">
            <div class="flex items-start justify-between gap-2">
              <a href="?p=workouts&id=<?= (int)$w['id'] ?>" class="font-semibold leading-tight hover:underline"><?= $w['sport'] === 'run' ? '🏃' : '🚴' ?> <?= h($w['name']) ?></a>
              <span class="badge" data-variant="secondary"><?= $w['mode'] === 'hr' ? '❤️ FC' : '⚡ Watts' ?></span>
            </div>
            <?= workout_mini_svg(json_decode($w['profile'] ?: '[]', true) ?: [], $w['mode'], 240, 44) ?>
            <div class="flex items-center justify-between text-xs text-muted-foreground tabular-nums">
              <span>⏱ <?= fmt_dur((int)$w['duration']) ?></span><span><?= round((float)$w['tss']) ?> TSS</span><span>IF <?= $w['if_'] !== null ? number_format((float)$w['if_'], 2) : '–' ?></span>
            </div>
            <div class="flex gap-1 pt-1">
              <a class="btn" data-size="sm" data-variant="outline" href="?p=workouts&id=<?= (int)$w['id'] ?>">Ouvrir</a>
              <a class="btn" data-size="sm" data-variant="outline" href="?p=workouts&id=<?= (int)$w['id'] ?>#plan">📅 Planifier</a>
              <span class="flex-1"></span>
              <button type="button" class="btn" data-size="icon-sm" data-variant="ghost" title="Supprimer" aria-label="Supprimer" onclick="deleteWorkout(<?= (int)$w['id'] ?>)">🗑</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <dialog class="dialog" id="woExportDlg"
            onpointerdown="this.dataset.backdropPress = event.target === this"
            onclick="if (event.target === this && this.dataset.backdropPress === 'true') this.close()">
      <div class="sm:max-w-xl w-full">
        <header class="flex items-center justify-between pb-3 border-b mb-4">
          <div><h3 class="text-base font-semibold">Exporter la séance</h3><p class="text-xs text-muted-foreground">Collez le texte dans la description d’un entraînement intervals.icu, ou téléchargez un fichier.</p></div>
          <button type="button" class="btn" data-variant="ghost" data-size="icon" onclick="this.closest('dialog').close()">✕</button>
        </header>
        <section class="space-y-3">
          <textarea id="woText" class="textarea font-mono text-xs" rows="12" readonly></textarea>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="btn" data-size="sm" id="woCopy">Copier le texte</button>
            <button type="button" class="btn" data-size="sm" data-variant="outline" id="woDlTxt">⬇ .txt</button>
            <button type="button" class="btn" data-size="sm" data-variant="outline" id="woDlZwo">⬇ .zwo (Zwift)</button>
            <button type="button" class="btn" data-size="sm" data-variant="outline" id="woDlMrc">⬇ .mrc (% FTP)</button>
            <button type="button" class="btn" data-size="sm" data-variant="outline" id="woDlErg">⬇ .erg (watts)</button>
          </div>
        </section>
      </div>
    </dialog>

    <dialog class="dialog" id="woPlanDlg"
            onpointerdown="this.dataset.backdropPress = event.target === this"
            onclick="if (event.target === this && this.dataset.backdropPress === 'true') this.close()">
      <div class="sm:max-w-sm w-full">
        <header class="flex items-center justify-between pb-3 border-b mb-4">
          <h3 class="text-base font-semibold">Planifier au calendrier</h3>
          <button type="button" class="btn" data-variant="ghost" data-size="icon" onclick="this.closest('dialog').close()">✕</button>
        </header>
        <section class="space-y-4">
          <div><label class="text-xs font-medium">Date</label><input type="date" id="woPlanDate" class="input text-sm"></div>
          <label class="flex items-start gap-2 text-sm"><input type="checkbox" id="woPush" class="mt-1" <?= cfg('api_key') ? 'checked' : 'disabled' ?>>
            <span>Envoyer aussi à intervals.icu<br><span class="text-xs text-muted-foreground"><?= cfg('api_key') ? 'La séance sera créée dans votre calendrier intervals.icu (mise à jour si elle existe déjà).' : 'Clé API non configurée (Paramètres).' ?></span></span></label>
          <p class="text-xs text-destructive" id="woPlanMsg"></p>
          <button type="button" class="btn w-full" id="woPlanGo">Planifier</button>
        </section>
      </div>
    </dialog>
    <div id="woToast" style="display:none"></div>

    <script>window.WO_CFG = <?= json_encode($woCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <script src="assets/workout-builder.js"></script>
    <?php foot(); exit;
}

/* ================= ADMINISTRATION (comptes) ================= */
if ($p === 'admin') {
    head('Administration', 'admin');
    if (!auth_enabled() || !is_admin()) { echo '<div class="alert" data-variant="destructive"><h2>Accès réservé aux administrateurs.</h2></div>'; foot(); exit; }
    $users = auth_users_list(); $regMode = (string)trq_config('registration');
    ?>
    <div class="space-y-1">
      <h1 class="text-2xl font-bold tracking-tight">Administration</h1>
      <p class="text-sm text-muted-foreground"><?= count($users) ?> compte(s) · inscriptions : <b><?= ['open' => 'ouvertes', 'invite' => 'sur invitation', 'closed' => 'fermées'][$regMode] ?? h($regMode) ?></b> (modifiable dans <code>config.local.php</code>)</p>
    </div>
    <div class="card"><section class="overflow-x-auto p-0">
      <table class="table"><thead><tr><th>#</th><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Créé le</th><th>Dernière connexion</th><th>Données</th><th>Statut</th><th></th></tr></thead><tbody>
      <?php foreach ($users as $u): $self = (int)$u['id'] === current_uid(); ?>
        <tr>
          <td><?= (int)$u['id'] ?></td><td class="font-medium"><?= h($u['name']) ?><?= $self ? ' <span class="badge" data-variant="secondary">vous</span>' : '' ?></td><td><?= h($u['email']) ?></td>
          <td><span class="badge" data-variant="<?= $u['role'] === 'admin' ? 'default' : 'outline' ?>"><?= $u['role'] === 'admin' ? 'admin' : 'utilisateur' ?></span></td>
          <td class="whitespace-nowrap text-xs"><?= h(substr((string)$u['created_at'], 0, 10)) ?></td>
          <td class="whitespace-nowrap text-xs"><?= $u['last_login'] ? h(substr((string)$u['last_login'], 0, 16)) : '–' ?></td>
          <td class="text-xs tabular-nums"><?= (int)$u['db_kb'] ?> Ko</td>
          <td><span class="badge" data-variant="<?= $u['active'] ? 'secondary' : 'destructive' ?>"><?= $u['active'] ? 'actif' : 'désactivé' ?></span></td>
          <td class="whitespace-nowrap text-right">
            <?php if (!$self): ?>
              <button type="button" class="btn" data-size="sm" data-variant="outline" onclick="adminAct('admin_toggle', <?= (int)$u['id'] ?>)"><?= $u['active'] ? 'Désactiver' : 'Réactiver' ?></button>
              <button type="button" class="btn" data-size="icon-sm" data-variant="ghost" title="Supprimer le compte et ses données" aria-label="Supprimer" onclick="if (confirm('Supprimer ce compte et TOUTES ses données ?')) adminAct('admin_delete', <?= (int)$u['id'] ?>)">🗑</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?></tbody></table>
    </section></div>
    <script>
    async function adminAct(action, id) { try { await call(action, {id}); location.reload(); } catch (e) { alert(e.message); } }
    </script>
    <?php foot(); exit;
}

if ($p === 'zones') {
    head('Zones d’entraînement', 'zones');
    $lthr = (float)cfg('lthr', 0);
    $runThrPace = (int)cfg('run_thr_pace', 0);

    $coggan = [
        ['Z1 Récupération active', 0, 0.55, 'Récupération post-séance intense, amélioration du retour veineux', '#94a3b8'],
        ['Z2 Endurance fondamentale', 0.56, 0.75, 'Capacité aérobie, oxydation des lipides, endurance de base', '#0ea5e9'],
        ['Z3 Tempo / Allure course', 0.76, 0.90, 'Rythme soutenu aérobie, développement de l’endurance de force', '#10b981'],
        ['Z4 Seuil lactique (FTP)', 0.91, 1.05, 'Amélioration de la puissance au seuil et clairance des lactates', '#f59e0b'],
        ['Z5 VO2max / Puissance aérobie', 1.06, 1.20, 'Capacité cardio-vasculaire maximale et consommation d’O2', '#f97316'],
        ['Z6 Capacité anaérobie', 1.21, 1.50, 'Efforts courts violents (30s à 2min), tolérance lactique', '#f43f5e'],
        ['Z7 Puissance neuromusculaire', 1.51, null, 'Sprints explosifs maximaux (< 20s), recrutement moteur', '#a855f7'],
    ];

    $hz = [
        ['Z1 Récupération', 0, 0.81],
        ['Z2 Aérobie / Endurance', 0.82, 0.89],
        ['Z3 Tempo', 0.90, 0.93],
        ['Z4 Seuil (Seuil LTHR)', 0.94, 0.99],
        ['Z5 Anaérobie / Max', 1.00, null],
    ];
    ?>
    <div class="space-y-6">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Zones d'entraînement physiologiques</h1>
        <p class="text-sm text-muted-foreground">Calibration des zones Coggan pour le vélo et seuil LTHR pour la fréquence cardiaque</p>
      </div>

      <div class="grid lg:grid-cols-2 gap-6">
        <div class="card">
          <header class="p-4 border-b flex items-center justify-between">
            <div>
              <h3 class="text-base font-semibold">Zones de Puissance (Modèle Coggan)</h3>
              <p class="text-xs text-muted-foreground">Basé sur votre FTP de <strong><?= n($ftp) ?> W</strong></p>
            </div>
            <a href="?p=settings" class="text-xs text-primary hover:underline">Modifier FTP →</a>
          </header>
          <section class="p-0">
            <div class="table-container border-0 rounded-none">
              <table class="table">
                <thead>
                  <tr><th>Zone</th><th>Plage Watts</th><th>Description physiologique</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($coggan as [$l, $a, $b, $desc, $col]): ?>
                    <tr>
                      <td class="font-semibold text-xs whitespace-nowrap">
                        <span class="inline-block w-2.5 h-2.5 rounded-full mr-1.5" style="background-color: <?= $col ?>"></span>
                        <?= $l ?>
                      </td>
                      <td class="tabular-nums font-bold text-xs whitespace-nowrap">
                        <?= $b === null ? '> ' . round($ftp * $a) . ' W' : round($ftp * $a) . ' – ' . round($ftp * $b) . ' W' ?>
                      </td>
                      <td class="text-xs text-muted-foreground"><?= $desc ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div class="space-y-6">
          <div class="card">
            <header class="p-4 border-b flex items-center justify-between">
              <div>
                <h3 class="text-base font-semibold">Zones Fréquence Cardiaque (Friel LTHR)</h3>
                <p class="text-xs text-muted-foreground">Basé sur votre LTHR de <strong><?= $lthr ? n($lthr) . ' bpm' : 'Non configuré' ?></strong></p>
              </div>
              <a href="?p=settings" class="text-xs text-primary hover:underline">Modifier LTHR →</a>
            </header>
            <section class="p-0">
              <?php if ($lthr > 0): ?>
                <div class="table-container border-0 rounded-none">
                  <table class="table">
                    <thead><tr><th>Zone</th><th>Plage FC</th><th>% LTHR</th></tr></thead>
                    <tbody>
                      <?php foreach ($hz as [$l, $a, $b]): ?>
                        <tr>
                          <td class="font-medium text-xs"><?= $l ?></td>
                          <td class="tabular-nums font-bold text-xs">
                            <?= $b === null ? '> ' . round($lthr * $a) . ' bpm' : round($lthr * $a) . ' – ' . round($lthr * $b) . ' bpm' ?>
                          </td>
                          <td class="tabular-nums text-xs text-muted-foreground">
                            <?= $b === null ? '> ' . round($a * 100) . '%' : round($a * 100) . ' – ' . round($b * 100) . '%' ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="p-4 text-xs text-muted-foreground">Renseignez votre seuil de fréquence cardiaque (LTHR) dans les paramètres.</div>
              <?php endif; ?>
            </section>
          </div>

          <div class="card">
            <header class="p-4 border-b">
              <h3 class="text-base font-semibold">Allure Seuil Course à pied (rTSS)</h3>
              <p class="text-xs text-muted-foreground">Référence pour le calcul de la charge en course</p>
            </header>
            <section class="p-4 text-sm">
              <div class="flex items-center justify-between">
                <span>Allure seuil actuelle :</span>
                <span class="font-bold tabular-nums text-primary">
                  <?= $runThrPace ? sprintf('%d:%02d /km', intdiv($runThrPace, 60), $runThrPace % 60) : 'Non configurée' ?>
                </span>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
    <?php foot(); exit;
}

/* ========================================================
   PROGRESSION (RAPPORT HEBDO / EFFICIENCE / RECORDS)
   ======================================================== */
if ($p === 'progress') {
    head('Progression', 'progress');
    if (!$count) { empty_state(); foot(); exit; }

    $wks = array_values(progress_weeks(10));
    $curW = end($wks);
    $prevW = count($wks) > 1 ? $wks[count($wks) - 2] : null;
    $eff = progress_efficiency();
    $prs = progress_prs();
    $alerts = progress_alerts();
    $rpeAvg = $curW['rpe_n'] ? round($curW['rpe_sum'] / $curW['rpe_n'], 1) : null;
    $dsub = function (float $cur, ?float $prev, int $dec = 0, string $unit = ''): string {
        if ($prev === null) return 'pas de semaine précédente';
        $d = $cur - $prev;
        return sprintf('%s%s%s vs semaine précédente', $d > 0 ? '+' : ($d < 0 ? '−' : '='), n(abs($d), $dec), $unit);
    };
    $dfmt = fn(?string $d): string => $d ? date('d/m/y', strtotime($d)) : '–';
    $feelLb = ['top' => 'Au top 💪', 'bien' => 'Bien 🙂', 'normal' => 'Normal 😐', 'dur' => 'Dur 😕', 'epuise' => 'Épuisé 🥵'];
    $gs = progress_goal_status();
    ?>
    <div class="space-y-6">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Progression</h1>
        <p class="text-sm text-muted-foreground">Rapport hebdomadaire, efficience (TSS/RPE), records personnels et signaux de surmenage</p>
      </div>

      <!-- Rapport de la semaine -->
      <div class="card" id="weekCard">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Rapport de la semaine</h3>
          <p class="text-xs text-muted-foreground">Semaine en cours (du <?= date('d/m', strtotime($curW['monday'])) ?> au <?= date('d/m') ?>), comparée à la précédente</p>
        </header>
        <section class="p-4 space-y-4">
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <?php
            stat_card('Séances', (string)$curW['sessions'], $dsub($curW['sessions'], $prevW ? $prevW['sessions'] : null));
            stat_card('Temps', fmt_dur($curW['time']), $dsub($curW['time'], $prevW ? $prevW['time'] : null));
            stat_card('Distance', n($curW['km'], 1) . ' km', $dsub($curW['km'], $prevW ? $prevW['km'] : null, 1, ' km'));
            stat_card('Charge', n($curW['tss']) . ' TSS', $dsub($curW['tss'], $prevW ? $prevW['tss'] : null, 0, ' TSS'));
            stat_card('RPE moyen', $rpeAvg !== null ? n($rpeAvg, 1) : '–', 'sur ' . $curW['rpe_n'] . ' séance(s) notée(s)');
            ?>
          </div>

          <!-- Objectifs de la semaine -->
          <div class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-semibold">Objectifs de la semaine</h4>
            <a href="#" onclick="goalsToggle(); return false;" class="text-xs text-muted-foreground hover:underline"><?= $gs['goals_set'] ? 'Modifier' : 'Définir' ?> les objectifs</a>
          </div>
          <div id="goalsForm" class="hidden card p-4 border bg-muted/20">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <?php foreach (PROGRESS_GOAL_SPORTS as $sp => $lb): ?>
                <div class="space-y-2">
                  <p class="text-sm font-medium"><?= h($lb) ?></p>
                  <label class="text-xs text-muted-foreground flex items-center justify-between gap-2">TSS / semaine
                    <input id="g-tss-<?= $sp ?>" type="number" min="0" max="3000" step="10" value="<?= (int)$gs['sports'][$sp]['goal_tss'] ?>" class="input h-8 w-24 text-sm">
                  </label>
                  <label class="text-xs text-muted-foreground flex items-center justify-between gap-2">Heures / semaine
                    <input id="g-time-<?= $sp ?>" type="number" min="0" max="28" step="0.5" value="<?= $gs['sports'][$sp]['goal_time'] > 0 ? round($gs['sports'][$sp]['goal_time'] / 3600, 1) : '0' ?>" class="input h-8 w-24 text-sm">
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="mt-4 flex items-center gap-2">
              <button type="button" class="btn" data-size="sm" onclick="goalsSave()">Enregistrer</button>
              <button type="button" class="btn" data-size="sm" data-variant="ghost" onclick="goalsToggle()">Annuler</button>
            </div>
          </div>
          <?php if ($gs['goals_set']): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              <?php render_goals_grid($gs); ?>
            </div>
          <?php else: ?>
            <p class="text-xs text-muted-foreground">Aucun objectif défini — clique sur « Définir les objectifs » pour fixer tes cibles TSS et heures par sport. Les jauges apparaîtront ici et sur le tableau de bord.</p>
          <?php endif; ?>

          <div class="table-container">
            <table class="table text-sm">
              <thead>
                <tr>
                  <th>Semaine</th>
                  <th>Séances</th>
                  <th>Temps</th>
                  <th>dont vélo</th>
                  <th>dont course</th>
                  <th>Charge</th>
                  <th>RPE moyen</th>
                  <th>Ressentis</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($wks as $w): ?>
                  <tr class="<?= $w === $curW ? 'bg-muted/40 font-semibold' : '' ?>">
                    <td>Sem. <?= date('W', strtotime($w['monday'])) ?> <span class="text-muted-foreground font-normal">(du <?= date('d/m', strtotime($w['monday'])) ?>)</span></td>
                    <td><?= $w['sessions'] ?></td>
                    <td><?= fmt_dur($w['time']) ?></td>
                    <td><?= n($w['km_sport']['ride']) ?> km</td>
                    <td><?= n($w['km_sport']['run']) ?> km</td>
                    <td><?= n($w['tss']) ?></td>
                    <td><?= $w['rpe_n'] ? n($w['rpe_sum'] / $w['rpe_n'], 1) : '–' ?></td>
                    <td class="text-xs">
                      <?php if ($w['feel']): foreach ($w['feel'] as $fk => $fnb): ?>
                        <span class="whitespace-nowrap mr-2"><?= (int)$fnb ?> × <?= $feelLb[$fk] ?? h($fk) ?></span>
                      <?php endforeach; else: ?><span class="text-muted-foreground">–</span><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-muted-foreground">Le RPE moyen et les ressentis se remplissent depuis le badge en haut de chaque séance — plus de séances notées, plus le rapport est précis.</p>
        </section>
      </div>

      <!-- Efficience TSS/RPE -->
      <div class="card" id="effCard">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Efficience — ratio TSS / RPE</h3>
          <p class="text-xs text-muted-foreground">Charge physique produite par unité d'effort perçu : une hausse régulière signifie que tu encaisses plus de charge pour une sensation identique (~90 jours)</p>
        </header>
        <section class="p-4">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
            <?php
            stat_card('30 derniers jours', $eff['avg30'] !== null ? n($eff['avg30'], 1) : '–', 'TSS/RPE moyen');
            stat_card('30 j précédents', $eff['avgPrev'] !== null ? n($eff['avgPrev'], 1) : '–', 'comparaison');
            stat_card('Tendance', $eff['delta'] !== null ? sprintf('%+.1f', $eff['delta']) : '–', $eff['delta'] !== null ? ($eff['delta'] > 0 ? 'en progression 🙂' : ($eff['delta'] < 0 ? 'en baisse — surveille' : 'stable')) : 'pas assez de données');
            stat_card('Séances notées', (string)count($eff['vals']), 'avec TSS et RPE renseignés (90 j)');
            ?>
          </div>
          <?php if ($eff['vals']): ?>
            <div class="h-64 w-full"><canvas id="effChart"></canvas></div>
          <?php else: ?>
            <p class="text-sm text-muted-foreground">Renseigne le RPE de quelques séances pour construire la courbe.</p>
          <?php endif; ?>
        </section>
      </div>

      <!-- Records personnels -->
      <?php if (!empty($prs['pending'])): ?>
        <div class="alert">
          <h3>Analyse des flux en cours</h3>
          <p class="text-xs text-muted-foreground"><?= (int)$prs['pending'] ?> séance(s) restante(s) à analyser pour les records (60 par chargement, pour ne pas ralentir la page). Recharge dans quelques instants pour la suite.</p>
        </div>
      <?php endif; ?>
      <div class="grid lg:grid-cols-2 gap-6" id="prCard">
        <?php foreach ([['key' => 'pw', 'title' => 'Puissance (vélo)', 'lab' => PR_POWER_LABELS, 'unit' => ' W'], ['key' => 'hr', 'title' => 'Fréquence cardiaque', 'lab' => PR_HR_LABELS, 'unit' => ' bpm']] as $grp): $store = $prs[$grp['key']] ?? []; ?>
        <div class="card">
          <header class="p-4 border-b">
            <h3 class="text-base font-semibold">Records — <?= $grp['title'] ?></h3>
            <p class="text-xs text-muted-foreground">Meilleures moyennes glissantes détectées automatiquement dans les flux (1 Hz)</p>
          </header>
          <section class="p-0">
            <div class="table-container border-0 rounded-none">
              <table class="table text-sm">
                <thead>
                  <tr>
                    <th>Durée</th>
                    <th>Record</th>
                    <th>Δ vs précédent</th>
                    <th>Date</th>
                    <th>Séance</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($grp['lab'] as $d => $lab): $rec = $store[$d] ?? null; $prevVal = ($rec && !empty($rec['hist'])) ? end($rec['hist'])['val'] : null; $dv = ($rec && $prevVal !== null) ? (float)$rec['val'] - $prevVal : null; ?>
                  <tr>
                    <td class="font-medium"><?= h($lab) ?></td>
                    <td class="tabular-nums font-semibold"><?= $rec ? n($rec['val']) . $grp['unit'] : '–' ?></td>
                    <td class="tabular-nums <?= $dv !== null && $dv > 0 ? 'text-emerald-600 dark:text-emerald-400' : '' ?>"><?= $dv !== null ? ($dv > 0 ? '+' . n($dv) : '0') . $grp['unit'] : '–' ?></td>
                    <td><?= $rec ? $dfmt($rec['date'] ?? null) : '–' ?></td>
                    <td class="max-w-[180px] truncate">
                      <?php if ($rec && !empty($rec['act_id'])): ?>
                        <a class="hover:underline" href="?p=activity&id=<?= h($rec['act_id']) ?>"><?= h($rec['name'] ?: 'Séance #' . $rec['act_id']) ?></a>
                      <?php else: ?>–<?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Alertes -->
      <div class="card" id="progressAlerts">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Alertes &amp; signaux de surmenage</h3>
          <p class="text-xs text-muted-foreground">Charge, fraîcheur (TSB), ressenti et restitution (HRV, FC repos) — indicateurs indicatifs, pas un diagnostic médical</p>
        </header>
        <section class="p-4 grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
          <?php if (!$alerts): ?>
            <p class="text-sm text-muted-foreground col-span-full">Aucun signal d'alerte — charge et récupération sont cohérentes. 👌</p>
          <?php else: foreach ($alerts as $al): $sev = alert_sev($al['sev']); ?>
            <div class="rounded-lg border p-3 <?= $sev['box'] ?>">
              <div class="flex items-center gap-2 text-sm font-medium mb-1"><?= $sev['dot'] ?><?= h($al['title']) ?></div>
              <p class="text-xs text-muted-foreground"><?= h($al['msg']) ?></p>
            </div>
          <?php endforeach; endif; ?>
        </section>
      </div>
    </div>

    <script>
    (() => {
      const eff = <?= json_encode($eff['vals']) ?>;
      if (!eff.length) return;
      const labels = eff.map(v => v.date.slice(8, 10) + '/' + v.date.slice(5, 7));
      new Chart('effChart', {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'TSS / RPE',
            data: eff.map(v => v.ratio),
            borderColor: '#0284c7',
            backgroundColor: 'rgba(2,132,199,.12)',
            fill: true,
            tension: .3,
            pointRadius: 2,
            borderWidth: 2
          }]
        },
        options: {
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { title: { display: true, text: 'TSS par unité de RPE' } },
            x: { ticks: { maxTicksLimit: 12 } }
          }
        }
      });
    })();
    </script>
    <script>
    function goalsToggle() {
      document.getElementById('goalsForm').classList.toggle('hidden');
    }

    async function goalsSave() {
      try {
        const goals = {};
        for (const sp of ['ride', 'run', 'other']) {
          goals[sp] = {
            tss: Math.max(0, parseInt(document.getElementById('g-tss-' + sp).value || '0', 10) || 0),
            time: Math.round(Math.max(0, parseFloat((document.getElementById('g-time-' + sp).value || '0').replace(',', '.')) || 0) * 3600)
          };
        }
        await call('week_goals_set', { goals: JSON.stringify(goals) });
        location.reload();
      } catch (e) {
        alert(e.message);
      }
    }
    </script>
    <?php foot(); exit;
}

/* ========================================================
   PARAMÈTRES (SETTINGS)
   ======================================================== */
if ($p === 'settings') {
    head('Paramètres', 'settings');
    $pt = (int)cfg('run_thr_pace', 0);
    $f = fn($k, $d = '') => h(cfg($k, $d));
    ?>
    <div class="space-y-6 max-w-3xl">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Paramètres physiologiques & Connexions</h1>
        <p class="text-sm text-muted-foreground">Configurez vos seuils d'entraînement et la synchronisation avec intervals.icu</p>
      </div>

      <?php if (isset($_GET['purged'])): ?><div class="alert" data-variant="destructive"><h2>Base réinitialisée : toutes les données locales ont été supprimées.</h2></div><?php endif; ?>

      <div class="card p-6 space-y-3">
        <header>
          <h3 class="text-base font-semibold">Apparence</h3>
          <p class="text-xs text-muted-foreground">Style de l'interface (Basecoat) — appliqué immédiatement, mémorisé sur cet appareil</p>
        </header>
        <div class="field max-w-56">
          <label class="text-xs font-medium">Style</label>
          <select id="styleSel" class="select h-9 text-sm">
            <?php foreach (['vega', 'nova', 'maia', 'lyra', 'mira', 'luma', 'sera', 'rhea'] as $s) echo '<option value="' . $s . '">' . ucfirst($s) . '</option>'; ?>
          </select>
        </div>
      </div>

      <?php if (isset($_GET['saved'])): ?>
        <div class="alert">
          <h3>Paramètres enregistrés</h3>
          <p class="text-xs text-muted-foreground">Les nouveaux seuils seront appliqués aux prochains calculs. Cliquez sur « Recalculer l’historique » pour mettre à jour les séances passées.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['welcome'])): ?>
        <div class="alert"><h3>Bienvenue sur TRQ 👋</h3><p class="text-xs text-muted-foreground">Pour commencer : renseignez votre clé API intervals.icu et vos seuils ci-dessous, enregistrez, puis lancez « Synchroniser ». Vous pouvez aussi générer des données de démonstration depuis le tableau de bord.</p></div>
      <?php endif; ?>

      <?php if (auth_enabled()): $cu = current_user(); ?>
      <div class="card p-6 space-y-4">
        <header class="flex items-start justify-between gap-3">
          <div><h3 class="text-base font-semibold">Mon compte</h3>
            <p class="text-xs text-muted-foreground"><?= h($cu['name']) ?> · <?= h($cu['email']) ?> · <?= $cu['role'] === 'admin' ? 'administrateur' : 'utilisateur' ?></p></div>
          <form method="post" action="login.php?a=logout"><button class="btn" data-variant="outline" data-size="sm" type="submit">Se déconnecter</button></form>
        </header>
        <div class="grid gap-3 sm:grid-cols-3">
          <div class="field"><label class="text-xs font-medium">Mot de passe actuel</label><input class="input text-sm" type="password" id="pwCur" autocomplete="current-password"></div>
          <div class="field"><label class="text-xs font-medium">Nouveau mot de passe</label><input class="input text-sm" type="password" id="pwNew" autocomplete="new-password"></div>
          <div class="field"><label class="text-xs font-medium">Confirmer</label><input class="input text-sm" type="password" id="pwNew2" autocomplete="new-password"></div>
        </div>
        <div class="flex flex-wrap items-center gap-3"><button type="button" class="btn" data-size="sm" id="pwChangeBtn">Changer le mot de passe</button><span class="text-xs" id="pwMsg"></span></div>
        <details class="rounded-lg border p-3">
          <summary class="cursor-pointer text-sm font-medium text-destructive">Supprimer mon compte</summary>
          <p class="mt-2 text-xs text-muted-foreground">Supprime définitivement votre compte et toutes vos données (activités, séances, réglages). Cette action est irréversible.</p>
          <div class="mt-3 flex flex-wrap items-center gap-2"><input class="input h-8 w-56 text-sm" type="password" id="accDelPw" placeholder="Votre mot de passe" autocomplete="current-password"><button type="button" class="btn" data-variant="destructive" data-size="sm" id="accDelBtn">Supprimer définitivement</button><span class="text-xs text-destructive" id="accDelMsg"></span></div>
        </details>
      </div>
      <script>
      document.getElementById('pwChangeBtn')?.addEventListener('click', async () => {
        const m = document.getElementById('pwMsg'); m.className = 'text-xs text-muted-foreground'; m.textContent = '…';
        try {
          await call('account_password', {current: pwCur.value, new: pwNew.value, new2: pwNew2.value});
          m.className = 'text-xs text-emerald-600 dark:text-emerald-400'; m.textContent = '✓ Mot de passe modifié'; pwCur.value = pwNew.value = pwNew2.value = '';
        } catch (e) { m.className = 'text-xs text-destructive'; m.textContent = e.message; }
      });
      document.getElementById('accDelBtn')?.addEventListener('click', async () => {
        if (!confirm('Supprimer définitivement votre compte et toutes vos données ?')) return;
        try { await call('account_delete', {password: accDelPw.value}); location.href = 'login.php'; }
        catch (e) { document.getElementById('accDelMsg').textContent = e.message; }
      });
      </script>
      <?php endif; ?>

      <form method="post" class="space-y-6">
        <div class="card p-6 space-y-4">
          <header>
            <h3 class="text-base font-semibold">Connexion intervals.icu</h3>
            <p class="text-xs text-muted-foreground">Générez une clé API sur intervals.icu → Settings → Developer Settings</p>
          </header>
          <div class="grid gap-4">
            <div class="field">
              <label class="text-xs font-medium">Clé API intervals.icu</label>
              <input class="input text-sm" type="password" name="api_key" value="<?= $f('api_key') ?>" autocomplete="off" placeholder="ex: 123456789abcdef">
            </div>
            <div class="field">
              <label class="text-xs font-medium">ID Athlète (laissez 0 pour votre propre profil)</label>
              <input class="input text-sm" type="text" name="athlete_id" value="<?= $f('athlete_id', '0') ?>" inputmode="numeric">
              <p class="text-[11px] text-muted-foreground">Nombre seul, sans « i » ni espaces (ex : 27729 — depuis une URL intervals.icu/athlete/<b>i27729</b>, ne gardez que 27729).</p>
            </div>
          </div>
        </div>

        <?php if (!auth_enabled() || (($cu['role'] ?? '') === 'admin')): ?>
        <div class="card p-6 space-y-4">
          <header>
            <h3 class="text-base font-semibold">Connexion sécurisée (SSL)</h3>
            <p class="text-xs text-muted-foreground">À régler uniquement si la connexion à intervals.icu échoue avec « certificat SSL » (antivirus ou proxy qui inspecte le HTTPS).</p>
          </header>
          <div class="grid gap-4">
            <div class="field">
              <label class="text-xs font-medium">Bundle de certificats personnalisé (optionnel)</label>
              <input class="input text-sm" type="text" name="ca_bundle" value="<?= $f('ca_bundle') ?>" placeholder="C:\wamp64\bin\php\cacert.pem">
              <p class="mt-1 text-[11px] text-muted-foreground">Fichier .pem. Une copie du bundle Mozilla est déjà fournie (<code>assets/cacert.pem</code>) ; pour un antivirus ou un proxy d’entreprise, ajoutez-y le certificat racine de cet outil (exportable depuis son interface).</p>
            </div>
            <label class="flex items-start gap-2 text-sm">
              <input type="checkbox" name="ssl_noverify" value="1" class="mt-1" <?= cfg('ssl_verify', '1') === '0' ? 'checked' : '' ?>>
              <span>Ne pas vérifier le certificat SSL d’intervals.icu <span class="badge" data-variant="destructive">déconseillé</span><br>
                <span class="text-xs text-muted-foreground">Dépannage rapide : la connexion reste chiffrée mais l’identité du serveur n’est plus contrôlée. À éviter sur un Wi-Fi public.</span></span>
            </label>
            <div class="flex flex-wrap items-center gap-3">
              <button type="button" class="btn" data-variant="outline" data-size="sm" id="icuTestBtn">Tester la connexion</button>
              <span class="text-xs" id="icuTestMsg"></span>
            </div>
            <p class="text-[11px] text-muted-foreground">Le test utilise les réglages déjà <b>enregistrés</b> : cliquez d’abord sur « Enregistrer ».</p>
          </div>
        </div>
        <?php endif; ?>

        <div class="card p-6 space-y-4">
          <header>
            <h3 class="text-base font-semibold">Seuils physiologiques de performance</h3>
            <p class="text-xs text-muted-foreground">Utilisés pour le calcul du TSS différencié (Power TSS, hrTSS, rTSS) et les zones</p>
          </header>
          <div class="grid sm:grid-cols-2 gap-4">
            <div class="field">
              <label class="text-xs font-medium">FTP Vélo (Watts)</label>
              <input class="input text-sm" type="number" name="ftp" value="<?= $f('ftp', '250') ?>" required>
            </div>
            <div class="field">
              <label class="text-xs font-medium">Poids de l'athlète (kg)</label>
              <input class="input text-sm" type="number" step="0.1" name="weight" value="<?= $f('weight', '68.5') ?>">
            </div>
            <div class="field">
              <label class="text-xs font-medium">Masse grasse (%) </label>
              <input class="input text-sm" type="number" step="0.1" min="3" max="60" name="body_fat" value="<?= $f('body_fat', '') ?>" placeholder="ex : 15">
            </div>
            <div class="field">
              <label class="text-xs font-medium">Seuil Fréquence Cardiaque (LTHR, bpm)</label>
              <input class="input text-sm" type="number" name="lthr" value="<?= $f('lthr', '172') ?>">
            </div>
            <div class="field">
              <label class="text-xs font-medium">FC Maximale (bpm)</label>
              <input class="input text-sm" type="number" name="max_hr" value="<?= $f('max_hr', '194') ?>">
            </div>
            <div class="field">
              <label class="text-xs font-medium">FC au repos (bpm)</label>
              <input class="input text-sm" type="number" name="rest_hr" value="<?= $f('rest_hr', '48') ?>">
            </div>
            <div class="field">
              <label class="text-xs font-medium">HRV rMSSD Baseline (ms)</label>
              <input class="input text-sm" type="number" name="base_hrv" value="<?= $f('base_hrv', '65') ?>">
            </div>
            <div class="field sm:col-span-2">
              <label class="text-xs font-medium">Allure seuil course à pied (min:s /km)</label>
              <input class="input text-sm" type="text" name="run_thr_pace" placeholder="4:00" value="<?= $pt ? sprintf('%d:%02d', intdiv($pt, 60), $pt % 60) : '4:00' ?>">
            </div>
          </div>
        </div>

        <div class="flex items-center justify-between gap-4 flex-wrap">
          <button class="btn" type="submit">Enregistrer les paramètres</button>
          
          <div class="flex items-center gap-2 flex-wrap">
            <button class="btn" data-variant="outline" type="button" onclick="call('import_settings').then(()=>location.reload()).catch(e=>alert(e.message))">
              Importer depuis intervals.icu
            </button>
            <button class="btn" data-variant="outline" type="button" onclick="runSync(365, true)">
              Recalculer l’historique
            </button>
          </div>
        </div>
      </form>

    </div>
    <?php ?>
    <script>
    document.getElementById('icuTestBtn')?.addEventListener('click', async () => {
      const msg = document.getElementById('icuTestMsg'), btn = document.getElementById('icuTestBtn');
      btn.disabled = true; msg.className = 'text-xs text-muted-foreground'; msg.textContent = 'Test en cours…';
      try {
        const j = await call('icu_test');
        msg.className = 'text-xs text-emerald-600 dark:text-emerald-400';
        msg.textContent = '✓ Connexion réussie : ' + j.name + (j.ssl_verify ? '' : ' (vérification SSL désactivée)');
      } catch (e) { msg.className = 'text-xs text-destructive'; msg.textContent = '✗ ' + e.message; }
      btn.disabled = false;
    });
    </script>
    <?php foot(); exit;
}

/* ========================================================
   PAGE 404
   ======================================================== */
/* ========================================================
   PAGE NUTRITION — cibles issues du statement UCI 2026
   ======================================================== */
if ($p === 'nutrition') {
    head('Nutrition', 'nutrition');
    $kg = nutrition_weight();
    $bfRaw = cfg('body_fat');
    $bf = ($bfRaw !== null && (float)$bfRaw > 0) ? (float)$bfRaw : null;
    $ffm = nutrition_ffm($kg, $bf);
    $review = nutrition_weekly_review($pdo, $kg);
    $today = null;
    foreach ($review['days'] as $d) if ($d['date'] === date('Y-m-d')) $today = $d;

    $simDur = (int)($_GET['dur'] ?? 0);
    $simInt = (int)($_GET['inten'] ?? 75);
    $simHot = !empty($_GET['hot']);
    $simGut = !empty($_GET['gut']);
    $sim = $simDur > 0 ? nutrition_race_simulation(min(720, max(15, $simDur)), (float)min(130, max(40, $simInt)), $kg, $simHot, $simGut, (float)cfg('ftp', 250)) : null;
    ?>
    <div class="space-y-6 max-w-5xl">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Nutrition</h1>
        <p class="text-sm text-muted-foreground">Cibles glucidiques, hydratation et énergie calculées à partir de vos séances réalisées ·
          <a class="hover:underline" href="<?= h(NUTRITION_REF_DOI) ?>" target="_blank" rel="noopener">Source : statement UCI 2026 (Burke et al., IJSNEM 36(6):700-726)</a> — fourchettes de référence, pas un conseil diététique personnalisé.</p>
      </div>

      <?php if ($kg === null): ?>
        <div class="alert"><h3>Poids manquant</h3><p class="text-xs text-muted-foreground">Renseignez votre poids dans Wellness ou Paramètres pour activer les cibles personnalisées.</p></div>
      <?php endif; ?>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php
        stat_card('Poids', $kg !== null ? n($kg, 1) . ' kg' : '–', 'dernière saisie (Wellness ou Paramètres)');
        stat_card('Masse maigre', $ffm !== null ? n($ffm, 1) . ' kg' : '–', $bf !== null ? n($bf, 0) . ' % de masse grasse' : 'renseignez la masse grasse (Paramètres)');
        stat_card('Dépense 7 jours', n($review['ea_risk']['week_kcal']) . ' kcal', 'kJ des sorties + estimation hors vélo');
        stat_card('Jours actifs (7 j)', (string)$review['ea_risk']['active_days'], 'séances ≥ 1 h');
        ?>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Cibles du jour (<?= date('d/m/Y') ?>)</h3>
          <p class="text-xs text-muted-foreground">Catégorie glucidique selon le volume réellement réalisé (Table 2 du statement) · protéines 1,6-2,1 g/kg/j réparties · lipides 20-35 % de l'énergie</p>
        </header>
        <section class="p-4">
          <?php if ($today === null): ?>
            <p class="text-sm text-muted-foreground">Aucune séance enregistrée aujourd'hui.</p>
          <?php else: $tb = $today['band']; ?>
            <div class="grid gap-4 sm:grid-cols-3">
              <div>
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Catégorie du jour</p>
                <p class="mt-1 text-sm font-semibold"><?= h($tb['label']) ?></p>
                <p class="text-xs text-muted-foreground">Table 2 du statement : <?= h($tb['raw']) ?></p>
              </div>
              <div>
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Glucides visés</p>
                <p class="mt-1 text-xl font-bold tabular-nums"><?= $today['cho_g'] !== null ? n($tb['range'][0], 1) . '-' . n($tb['range'][1], 1) . ' g/kg ≈ ' . $today['cho_g'] . ' g' : '–' ?></p>
                <p class="text-xs text-muted-foreground">poids <?= $kg !== null ? n($kg, 1) . ' kg' : 'manquant' ?></p>
              </div>
              <div>
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Volume & dépense du jour</p>
                <p class="mt-1 text-xl font-bold tabular-nums"><?= fmt_dur((int)round($today['hours'] * 3600)) ?></p>
                <p class="text-xs text-muted-foreground tabular-nums">≈ <?= n($today['kcal']) ?> kcal dépensées</p>
              </div>
            </div>
          <?php endif; ?>
        </section>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">7 derniers jours</h3>
          <p class="text-xs text-muted-foreground">Volume réel, catégorie glucidique associée et dépense estimée</p>
        </header>
        <section class="p-4 pt-0 overflow-x-auto table-container mt-4">
          <table class="w-full text-sm">
            <thead><tr class="border-b text-left text-xs text-muted-foreground">
              <th class="py-2 px-3 font-medium">Date</th>
              <th class="py-2 px-3 font-medium">Volume</th>
              <th class="py-2 px-3 font-medium">Catégorie glucidique</th>
              <th class="py-2 px-3 font-medium text-right">Cible CHO (g/jour)</th>
              <th class="py-2 px-3 font-medium text-right">Dépense (kcal)</th>
            </tr></thead>
            <tbody>
            <?php foreach (array_reverse($review['days']) as $d): ?>
              <tr class="border-b last:border-0">
                <td class="py-2 px-3"><?= h(date('d/m', strtotime($d['date']))) ?><?= $d['date'] === date('Y-m-d') ? ' <span class="badge ml-1">aujourd\'hui</span>' : '' ?></td>
                <td class="py-2 px-3 tabular-nums"><?= $d['hours'] > 0 ? fmt_dur((int)round($d['hours'] * 3600)) : '–' ?></td>
                <td class="py-2 px-3"><?= h($d['band']['label']) ?> <span class="text-xs text-muted-foreground">(<?= h($d['band']['raw']) ?>)</span></td>
                <td class="py-2 px-3 text-right tabular-nums"><?= $d['cho_g'] !== null ? $d['cho_g'] : '–' ?></td>
                <td class="py-2 px-3 text-right tabular-nums"><?= n($d['kcal']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Signaux à surveiller (apport énergétique)</h3>
          <p class="text-xs text-muted-foreground">Disponibilité énergétique cible ≈ 40-45 kcal/kg de masse maigre/jour — non calculable sans suivi alimentaire ; proxys ci-dessous</p>
        </header>
        <section class="p-4 space-y-2">
          <?php if (!$review['ea_risk']['reasons']): ?>
            <p class="text-sm text-emerald-600 dark:text-emerald-400">✔ Aucun signal d'alerte sur la période.</p>
          <?php else: foreach ($review['ea_risk']['reasons'] as $r): ?>
            <p class="text-sm flex gap-2"><span class="text-amber-500">⚠</span><span><?= h((string)$r) ?></span></p>
          <?php endforeach; endif; ?>
          <p class="text-[11px] text-muted-foreground">Le statement UCI recommande un suivi régulier (outil REDs CAT2) et un accompagnement par un médecin ou diététicien du sport en cas de signes d'apport insuffisant (fatigue persistante, cycles irréguliers, baisse de performance).</p>
        </section>
      </div>

      <div class="card">
        <header class="p-4 border-b">
          <h3 class="text-base font-semibold">Simulateur de ravitaillement</h3>
          <p class="text-xs text-muted-foreground">Plan horodaté pour une course ou une longue sortie (durée, intensité, température)</p>
        </header>
        <section class="p-4">
          <form method="get" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="p" value="nutrition">
            <div class="field">
              <label class="text-xs font-medium">Durée (min)</label>
              <input class="input text-sm w-28" type="number" name="dur" min="15" max="720" value="<?= (int)($simDur ?: 180) ?>" required>
            </div>
            <div class="field">
              <label class="text-xs font-medium">Intensité (% FTP)</label>
              <input class="input text-sm w-28" type="number" name="inten" min="40" max="130" value="<?= (int)($simInt ?: 75) ?>">
            </div>
            <label class="flex items-center gap-2 text-sm pb-2"><input type="checkbox" name="hot" <?= $simHot ? 'checked' : '' ?>> Chaleur</label>
            <label class="flex items-center gap-2 text-sm pb-2" title="Limite à 60 g/h la première saison d'entraînement digestif"><input type="checkbox" name="gut" <?= $simGut ? 'checked' : '' ?>> Montée digestive progressive</label>
            <button class="btn" type="submit">Simuler</button>
          </form>

          <?php if ($sim):
              $sob = $sim['onbike']; ?>
            <div class="mt-5 space-y-4">
              <div class="grid gap-3 sm:grid-cols-4">
                <div><p class="text-xs text-muted-foreground uppercase tracking-wider">Glucides</p><p class="text-lg font-bold tabular-nums"><?= $sob['target'] > 0 ? n($sob['target']) . ' g/h' : h($sob['mode']) ?></p></div>
                <div><p class="text-xs text-muted-foreground uppercase tracking-wider">Boisson</p><p class="text-lg font-bold tabular-nums"><?= $sim['hydration']['ml_h'] > 0 ? n($sim['hydration']['ml_h']) . ' ml/h' : '–' ?></p></div>
                <div><p class="text-xs text-muted-foreground uppercase tracking-wider">Repas pré (J-2h30)</p><p class="text-lg font-bold tabular-nums"><?= $sim['prep']['g'] !== null ? $sim['prep']['g'] . ' g CHO' : '–' ?></p></div>
                <div><p class="text-xs text-muted-foreground uppercase tracking-wider">Totaux</p><p class="text-lg font-bold tabular-nums"><?= $sim['totals']['cho_g'] ?> g CHO · <?= n($sim['totals']['fluid_ml']) ?> ml</p><p class="text-xs text-muted-foreground tabular-nums">effort ≈ <?= n($sim['totals']['effort_kcal']) ?> kcal · CHO ≈ <?= n($sim['totals']['kcal_cho']) ?> kcal</p></div>
              </div>
              <div class="table-container">
                <table class="w-full text-sm">
                  <thead><tr class="border-b text-left text-xs text-muted-foreground">
                    <th class="py-2 px-3 font-medium">Horaire</th>
                    <th class="py-2 px-3 font-medium">Action</th>
                    <th class="py-2 px-3 font-medium text-right">CHO (g)</th>
                    <th class="py-2 px-3 font-medium text-right">Boisson (ml)</th>
                    <th class="py-2 px-3 font-medium">Note</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ($sim['steps'] as $s): ?>
                    <tr class="border-b last:border-0">
                      <td class="py-2 px-3 tabular-nums whitespace-nowrap"><?= h($s['t']) ?></td>
                      <td class="py-2 px-3"><?= h($s['label']) ?></td>
                      <td class="py-2 px-3 text-right tabular-nums"><?= (int)$s['cho_g'] ?></td>
                      <td class="py-2 px-3 text-right tabular-nums"><?= (int)$s['fluid_ml'] ?></td>
                      <td class="py-2 px-3 text-xs text-muted-foreground"><?= h((string)$s['note']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <p class="text-xs text-muted-foreground"><?= h($sob['note']) ?> · <?= h($sim['discipline_note']) ?></p>
            </div>
          <?php endif; ?>
        </section>
        <footer class="px-4 pb-3 text-[11px] text-muted-foreground">Le rincage buccal (5-10 s de contact en bouche) est indiqué pour les courses de 40-75 min ; au-dessus de 60 g/h, utilisez des mélanges glucose+fructose et entraînez votre tolérance digestive à l'entraînement.</footer>
      </div>
    </div>
    <?php foot(); exit;
}

http_response_code(404);
head('Page introuvable', '');
echo '<div class="card p-8 text-center max-w-md mx-auto my-12">';
echo '<h2 class="text-xl font-bold">Page introuvable</h2>';
echo '<p class="text-sm text-muted-foreground mt-2 mb-4">La page demandée n’existe pas.</p>';
echo '<a href="?p=dashboard" class="btn">Retour au tableau de bord</a>';
echo '</div>';
foot();
