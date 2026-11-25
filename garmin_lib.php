<?php
/* Garmin Connect — socle de connexion OAuth 2.0 (TERRAIN PRÉPARÉ, INACTIF).

   Ce socle n'appelle AUCUN endpoint Garmin tant que l'accès développeur
   (programme Garmin Health API / Connect Developer Program) n'a pas été accordé
   et que les clés client n'ont pas été renseignées dans les Paramètres.
   Tant que garmin_status()['connected'] est false, aucune donnée ne quitte TRQ.

   ROADMAP d'activation (une fois les accès accordés) :
     1. Renseigner garmin_client_id + garmin_client_secret (Paramètres ou config).
     2. Enregistrer l'URL de rappel (garmin_redirect_uri()) dans le portail
        développeur Garmin (oauth_garmin.php, déjà en place).
     3. Activer le bouton « Connecter » côté UI → GET oauth_garmin.php (start)
        → consentement Garmin → oauth_garmin.php (callback) → tokens stockés.
     4. Brancher la synchro : pull quotidien + webhook push Garmin
        (activités API + wellness : sommeil, FC repos, HRV rMSSD).

   Clés de configuration (table settings de la base utilisateur) :
     - garmin_client_id        (public)
     - garmin_client_secret    (chiffré AES-256-GCM automatiquement via cfg_set)
     - garmin_access_token     (chiffré)
     - garmin_refresh_token    (chiffré)
     - garmin_token_exp        (expiration du token, timestamp unix)
     - garmin_user_display     (identifiant Garmin affiché après liaison)
     - garmin_webhook_ack      (réponse enregistrée pour l'endpoint webhooks)
*/

/* OAuth 2.0 Garmin (officiels, documentation developer.garmin.com) */
const GARMIN_SSO_AUTHORIZE = 'https://connect.garmin.com/oauth2Confirm';
const GARMIN_SSO_TOKEN     = 'https://sso.garmin.com/sso/oauth2/access-token';
const GARMIN_API_BASE      = 'https://apis.garmin.com';

/** URL de rappel OAuth à enregistrer chez Garmin : <origine de l'app>/oauth_garmin.php */
function garmin_redirect_uri(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (string)cfg('trust_proxy', '0') === '1';           // hébergé derrière un proxy (LWS, InfinityFree…)
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host . '/oauth_garmin.php';
}

/** Statut de la connexion Garmin — aucun appel réseau, lecture Locale des réglages. */
function garmin_status(): array {
    $clientId = trim((string)cfg('garmin_client_id', ''));
    $secret   = trim((string)cfg('garmin_client_secret', ''));
    $token    = trim((string)cfg('garmin_access_token', ''));
    $exp      = (int)cfg('garmin_token_exp', '0');
    return [
        'configured' => $clientId !== '' && $secret !== '',               // clés développeur en place
        'connected'  => $token !== '' && ($exp === 0 || $exp > time()),   // compte lié + token valable
        'client_id'  => $clientId,
        'expires_at' => $exp,
        'display'    => (string)cfg('garmin_user_display', ''),
    ];
}

/** URL d'autorisation à ouvrir dans le navigateur (start du flux OAuth). */
function garmin_authorize_url(): string {
    return GARMIN_SSO_AUTHORIZE . '?response_type=code&client_id=' . urlencode((string)cfg('garmin_client_id', ''))
        . '&redirect_uri=' . urlencode(garmin_redirect_uri())
        . '&scope=ACTIVITY_EXPORT%20HEALTH_EXPORT&state=' . bin2hex(random_bytes(8));
}

/** Échange le code d'autorisation contre les tokens (callback oauth_garmin.php). */
function garmin_exchange_code(string $code): array {
    $resp = garmin_http(GARMIN_SSO_TOKEN, [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => (string)cfg('garmin_client_id', ''),
        'client_secret' => (string)cfg('garmin_client_secret', ''),
        'redirect_uri'  => garmin_redirect_uri(),
    ]);
    if (empty($resp['access_token'])) throw new RuntimeException('Garmin : réponse OAuth inattendue.');
    cfg_set('garmin_access_token', (string)$resp['access_token']);
    cfg_set('garmin_refresh_token', (string)($resp['refresh_token'] ?? ''));
    cfg_set('garmin_token_exp', (string)(time() + (int)($resp['expires_in'] ?? 3600) - 60));
    return $resp;
}

/** Rafraîchit le token d'accès (à appeler quand garmin_status()['expires_at'] < time()+60). */
function garmin_refresh_token(): void {
    $rt = trim((string)cfg('garmin_refresh_token', ''));
    if ($rt === '') throw new RuntimeException('Garmin : pas de refresh token — reconnectez le compte.');
    $resp = garmin_http(GARMIN_SSO_TOKEN, [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $rt,
        'client_id'     => (string)cfg('garmin_client_id', ''),
        'client_secret' => (string)cfg('garmin_client_secret', ''),
    ]);
    if (empty($resp['access_token'])) throw new RuntimeException('Garmin : rafraîchissement du token échoué.');
    cfg_set('garmin_access_token', (string)$resp['access_token']);
    cfg_set('garmin_refresh_token', (string)($resp['refresh_token'] ?? $rt));
    cfg_set('garmin_token_exp', (string)(time() + (int)($resp['expires_in'] ?? 3600) - 60));
}

/** Garantit un token d'accès frais (refresh automatique si proche de l'expiration). */
function garmin_access_token(): string {
    $exp = (int)cfg('garmin_token_exp', '0');
    if ($exp !== 0 && $exp < time() + 60) garmin_refresh_token();
    return (string)cfg('garmin_access_token', '');
}

/** Déconnexion : efface toutes les clés Garmin de la base de l'utilisateur. */
function garmin_disconnect(): void {
    db()->prepare('DELETE FROM settings WHERE k LIKE ?')->execute(['garmin_%']);
}

/** POST form-urlencoded minimal (OAuth + futurs appels API). */
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
    return is_array($j) ? $j : [];
}
