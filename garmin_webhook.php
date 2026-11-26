<?php
/* Garmin webhooks push — endpoint appelé PAR GARMIN (server-to-server, SANS session utilisateur).

   Enregistre dans le portail développeur Garmin (chaque endpoint push) :
     <origine>/garmin_webhook.php?token=<garmin_webhook_token>
   (le token est affiché/copiable dans la carte Garmin des Paramètres ; il refait
   le lien entre le push et la bonne base utilisateur — aucun autre secret exposé).

   Répond {"response": "ACKNOWLEDGED"} (format attendu par Garmin), puis importe :
     - ACTIVITY_SUMMARY  → activities (séances détaillées, sport déduit du type)
     - DAILY_SUMMARY     → wellness (FC repos…)
     - SLEEP_SUMMARY     → wellness (durée + score de sommeil)
     - HRV_SUMMARY       → wellness (rMSSD nuit)
     - USERMETRICS / BODYCOMPOSITION → wellness (poids)
   Multi-utilisateurs : le payload contient userId → routage vers la bonne base
   (settings.garmin_user_id) ; usage mono-utilisateur : data/app.sqlite.
*/
require_once __DIR__ . '/lib.php';
bootstrap_errors(true);

header('Content-Type: application/json');
while (ob_get_level() > 0) ob_end_clean();

function garmin_wh_fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') garmin_wh_fail(405, 'POST requis.');
$raw = (string)file_get_contents('php://input');
if (trim($raw) === '') garmin_wh_fail(400, 'Corps vide.');

/* IMPORTANT : aucun cfg()/db() ici — pas de session.
   Authentification = token secret dans l'URL. Deux cas :
     - jeton GLOBAL d'application (app_cfg d'auth.sqlite) : la base utilisateur est résolue
       via le userId du payload (Garmin l'envoie toujours) ;
     - jeton PAR UTILISATEUR (installations existantes) : scanné comme avant. */
$tok = trim((string)($_GET['token'] ?? ''));
$pdo = null;
$gtok = trim((string)gcfg('garmin_webhook_token', ''));
if ($gtok !== '' && $tok !== '' && hash_equals($gtok, $tok)) {
    $j = json_decode($raw, true);
    $guserId = '';
    if (is_array($j)) {
        $guserId = (string)($j['userId'] ?? '');
        if ($guserId === '' && isset($j[0]) && is_array($j[0])) $guserId = (string)($j[0]['userId'] ?? '');
    }
    if ($guserId === '') garmin_wh_fail(400, 'Payload sans userId.');
    $pdo = garmin_find_db($guserId);
    if (!$pdo) garmin_wh_fail(403, 'Aucun compte TRQ lié à cet utilisateur Garmin.');
} else {
    $pdo = garmin_db_for_webhook_token($tok);
    if (!$pdo) garmin_wh_fail(403, 'Token de webhook invalide.');
}

/* Contrôle additionnel : client-id exposé par Garmin dans les en-têtes du push (réglage global) */
$cid = trim((string)($_SERVER['HTTP_GARMIN_CLIENT_ID'] ?? ''));
try {
    $myCid = garmin_client_creds()[0];
    if ($myCid !== '' && $cid !== '' && !hash_equals($myCid, $cid)) garmin_wh_fail(403, 'Client Garmin inconnu.');
} catch (Throwable $e) {}

try {
    garmin_webhook_process($pdo, $raw);
} catch (Throwable $e) {
    /* ACK quand même : si Garmin ne reçoit pas d'ack, il redirigera le payload en boucle.
       L'erreur est journalisée dans garmin_webhook_last pour la carte Paramètres. */
    try { garmin_cfg_set($pdo, 'garmin_webhook_last', json_encode(['when' => time(), 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)); } catch (Throwable $e2) {}
    echo GARMIN_WEBHOOK_ACK;
    exit;
}
echo GARMIN_WEBHOOK_ACK;
