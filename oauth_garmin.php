<?php
/* Garmin OAuth — callback / start de connexion (endpoint officiel enregistré chez Garmin).

   Tant que garmin_status()['configured'] est false, ce endpoint affiche
   « Connexion Garmin pas encore disponible » et ne fait AUCUN appel réseau.

   Une fois les clés en place :
     - GET sans ?code  → émet le consentement Garmin (garmin_authorize_url())
     - GET avec ?code  → échange le code (garmin_exchange_code()), stocke les tokens
     - ?disconnect=1 (via ?p=settings) → garmin_disconnect()
*/
require_once __DIR__ . '/lib.php';
bootstrap_errors();
require_login();

$gs = garmin_status();

/* Déconnexion (appelée depuis les Paramètres) */
if (isset($_GET['disconnect'])) {
    garmin_disconnect();
    header('Location: ?p=settings&garmin=0');
    exit;
}

/* Callback : échange du code d'autorisation */
if (isset($_GET['code'])) {
    try {
        if (!$gs['configured']) throw new RuntimeException('Connexion Garmin non configurée.');
        garmin_exchange_code((string)$_GET['code']);
        /* Identifiant affiché après liaison (affiné par la future synchro) */
        cfg_set('garmin_user_display', 'Garmin Connect');
        header('Location: ?p=settings&garmin=1#connexion-garmin');
    } catch (Throwable $e) {
        header('Location: ?p=settings&err=' . urlencode($e->getMessage()));
    }
    exit;
}

/* Erreur renvoyée par Garmin (refus utilisateur, etc.) */
if (isset($_GET['error'])) {
    header('Location: ?p=settings&err=' . urlencode('Connexion Garmin annulée.'));
    exit;
}

/* Start : pas encore configuré → page d'information (aucun appel réseau) */
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion Garmin — TRQ</title>
<style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; background:#0b0b0d; color:#fafafa; display:grid; place-items:center; min-height:100vh; margin:0; }
    .box { max-width:32rem; padding:2rem; border:1px solid #27272a; border-radius:1rem; background:#111113; }
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
        <p><a href="<?= htmlspecialchars(garmin_authorize_url(), ENT_QUOTES) ?>">Continuer vers Garmin Connect</a></p>
        <script>location.replace(<?= json_encode(garmin_authorize_url()) ?>);</script>
    <?php else: ?>
        <p>Les accès développeur de TRQ à l'API officielle Garmin Health sont en cours d'attribution.
        La liaison de votre compte sera ouverte dès l'approbation — rien à faire de votre côté.</p>
        <p>En attendant, la synchronisation <a href="?p=settings">intervals.icu</a> reste la source active de vos séances.</p>
        <p><a href="?p=settings">← Retour aux paramètres</a></p>
    <?php endif; ?>
</div>
</body>
</html>
