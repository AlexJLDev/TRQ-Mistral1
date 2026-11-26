<?php
/* Garmin OAuth — callback / start de connexion (endpoint officiel enregistré chez Garmin).

   Tant que garmin_status()['configured'] est false, ce endpoint affiche
   « Connexion Garmin pas encore disponible » et ne fait AUCUN appel réseau.

   Une fois les clés en place :
     - GET sans ?code             → redirige vers le consentement Garmin (garmin_authorize_url())
     - GET avec ?code             → échange le code (garmin_exchange_code()), stocke les tokens
     - GET ?popup=1               → démarre le flux en mode POP-UP (fermeture auto + reload de l'ouvreuse)
     - ?disconnect=1 (settings)   → garmin_disconnect()

   Le mode pop-up est mémorisé de deux façons redondantes :
     1. un flag de session (garmin_oauth_popup) posé sur ?popup=1
     2. un préfixe du paramètre OAuth `state` ('p…' = pop-up, 'r…' = redirection pleine page)
*/
require_once __DIR__ . '/lib.php';
bootstrap_errors();
require_login();

$gs = garmin_status();

/* Détecte le mode pop-up : flag de session OU préfixe du state renvoyé par Garmin. */
$state       = (string)($_GET['state'] ?? '');
$popupFlag   = !empty($_SESSION['garmin_oauth_popup']) || str_starts_with($state, 'p');

/* Mini-page autonome rendue DANS la pop-up (thème sombre cohérent avec TRQ). */
function garmin_popup_page(string $title, string $msg, string $color, bool $success): void {
    $goBack = "?p=settings" . ($success ? '&garmin=1' : '') . "#connexion-garmin";
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — TRQ</title>
<style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; background:#0b0b0d; color:#fafafa; display:grid; place-items:center; min-height:100vh; margin:0; }
    .box { max-width:26rem; margin:1rem; padding:2rem; border:1px solid #27272a; border-radius:1rem; background:#111113; text-align:center; }
    h1 { font-size:1.05rem; margin:0 0 .5rem; color:<?= $color ?>; }
    p { font-size:.85rem; color:#a1a1aa; line-height:1.6; margin:.4rem 0; }
    a, button { color:#60a5fa; font-size:.8rem; background:none; border:1px solid #27272a; border-radius:.5rem; padding:.4rem .8rem; cursor:pointer; text-decoration:none; display:inline-block; margin-top:.75rem; }
</style>
</head>
<body>
<div class="box">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <p style="font-size:.75rem;color:#71717a">Cette fenêtre va se fermer automatiquement…</p>
    <button onclick="window.close()">Fermer</button>
    <br><a href="<?= $goBack ?>" target="_blank">Si rien ne se passe, ouvrir les paramètres</a>
</div>
<script>
    try {
        if (window.opener && !window.opener.closed) {
            window.opener.location.href = <?= json_encode($goBack) ?>;
        }
    } catch (e) {}
    setTimeout(function () { try { window.close(); } catch (e) {} }, 1200);
</script>
</body>
</html>
    <?php
    exit;
}

/* Déconnexion (appelée depuis les Paramètres) */
if (isset($_GET['disconnect'])) {
    unset($_SESSION['garmin_oauth_popup']);
    garmin_disconnect();
    header('Location: ?p=settings&garmin=0');
    exit;
}

/* Callback : échange du code d'autorisation */
if (isset($_GET['code'])) {
    try {
        if (!$gs['configured']) throw new RuntimeException('Connexion Garmin non configurée.');
        garmin_exchange_code((string)$_GET['code']);
        /* userId + permissions consenties (non bloquant si l'endpoint n'est pas encore actif côté Starter) */
        try { garmin_permissions(); } catch (Throwable $pe) { try { cfg_set('garmin_permissions', json_encode(['err' => $pe->getMessage()], JSON_UNESCAPED_UNICODE)); } catch (Throwable $pe2) {} }

        if ($popupFlag) garmin_popup_page('✓ Connexion Garmin réussie', 'Ton compte Garmin Connect est maintenant lié à TRQ.', '#34d399', true);

        unset($_SESSION['garmin_oauth_popup']);
        header('Location: ?p=settings&garmin=1#connexion-garmin');
    } catch (Throwable $e) {
        if ($popupFlag) garmin_popup_page('✗ Connexion Garmin échouée', $e->getMessage(), '#f87171', false);

        unset($_SESSION['garmin_oauth_popup']);
        header('Location: ?p=settings&err=' . urlencode($e->getMessage()));
    }
    exit;
}

/* Erreur renvoyée par Garmin (refus utilisateur, etc.) */
if (isset($_GET['error'])) {
    if ($popupFlag) garmin_popup_page('✗ Connexion Garmin annulée', 'Tu as refusé l’autorisation côté Garmin — aucune donnée n’a été partagée.', '#f87171', false);

    unset($_SESSION['garmin_oauth_popup']);
    header('Location: ?p=settings&err=' . urlencode('Connexion Garmin annulée.'));
    exit;
}

/* Start : pop-up demandée → mémorise le mode (le state OAuth le re-transporte aussi jusqu'au callback).
   NB : le flag est posé APRÈS garmin_authorize_url() car celle-ci rouvre la session via garmin_pkce_pair()
   (require_login() la ferme — toute écriture avant serait écrasée par la relecture). */
$isPopup = isset($_GET['popup']);

/* Start : pas encore configuré → page d'information (aucun appel réseau) */
header('Content-Type: text/html; charset=utf-8');
$authUrl = $gs['configured'] ? garmin_authorize_url($isPopup) : '';
if ($isPopup && $authUrl !== '') { $_SESSION['garmin_oauth_popup'] = 1; }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion Garmin — TRQ</title>
<style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; background:#0b0b0d; color:#fafafa; display:grid; place-items:center; min-height:100vh; margin:0; }
    .box { max-width:32rem; margin:1rem; padding:2rem; border:1px solid #27272a; border-radius:1rem; background:#111113; }
    h1 { font-size:1.1rem; margin:0 0 .5rem; }
    p { font-size:.85rem; color:#a1a1aa; line-height:1.6; margin:.4rem 0; }
    a { color:#60a5fa; }
</style>
</head>
<body>
<div class="box">
    <h1>⌚ Connexion Garmin <?= $gs['configured'] ? '— démarrage de la liaison' : '— pas encore disponible' ?></h1>
    <?php if ($gs['configured']): ?>
        <p>Redirection vers le consentement Garmin…</p>
        <p><a href="<?= htmlspecialchars($authUrl, ENT_QUOTES) ?>">Continuer vers Garmin Connect</a></p>
        <script>location.replace(<?= json_encode($authUrl) ?>);</script>
    <?php else: ?>
        <p>Les accès développeur de TRQ à l'API officielle Garmin Health sont en cours d'attribution.
        La liaison de votre compte sera ouverte dès l'approbation — rien à faire de votre côté.</p>
        <p>En attendant, la synchronisation <a href="?p=settings">intervals.icu</a> reste la source active de vos séances.</p>
        <p><a href="?p=settings">← Retour aux paramètres</a></p>
    <?php endif; ?>
</div>
</body>
</html>
