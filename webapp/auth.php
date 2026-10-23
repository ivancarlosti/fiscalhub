<?php
/**
 * Authentication Module — session-based login with HTML form.
 *
 * Reads AUTH_METHOD from environment:
 *   - none:      Pass-through, no authentication required
 *   - account:   Session-based login form (ACCOUNT_LOGIN / ACCOUNT_PASSWORD)
 *   - keycloak:  OIDC Bearer token validation via Keycloak
 *
 * Sessions are bound to the auth method that created them
 * ($_SESSION['fiscalhub_auth_method']), so switching AUTH_METHOD (e.g. account →
 * keycloak) revokes every session minted by the previous method.
 *
 * Called by router.php on every request and by proxy.php as defense-in-depth.
 */

// ── Detect auth method ─────────────────────────────────────
$authMethod = strtolower(getenv('AUTH_METHOD') ?: 'none');

// ── Secure session configuration ───────────────────────────
if ($authMethod !== 'none') {
    ini_set('session.cookie_httponly', '1');
    // keycloak mode returns through a cross-site navigation from the provider
    // (/keycloak-callback.php): same-site=Strict would drop the session cookie
    // there, so the state stored by /keycloak-login.php could never be checked.
    // Lax still blocks cross-site POST, which is the CSRF that matters here.
    ini_set('session.cookie_samesite', $authMethod === 'keycloak' ? 'Lax' : 'Strict');
    ini_set('session.use_strict_mode', '1');
    // Mark secure if behind HTTPS reverse proxy
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        ini_set('session.cookie_secure', '1');
    }
}

// ── Session state helpers ──────────────────────────────────

/**
 * Start the PHP session if it is not already active.
 */
function auth_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Whether the active session was minted by the current auth method.
 *
 * Binding the session to its auth method means a session created in
 * account mode can never satisfy keycloak mode (and vice versa).
 */
function auth_session_valid(): bool {
    global $authMethod;
    return !empty($_SESSION['fiscalhub_authenticated'])
        && ($_SESSION['fiscalhub_auth_method'] ?? '') === $authMethod;
}

/**
 * Mark the current session as authenticated for the active auth method.
 */
function auth_set_authenticated(): void {
    global $authMethod;
    auth_session_start();
    session_regenerate_id(true);
    $_SESSION['fiscalhub_authenticated'] = true;
    $_SESSION['fiscalhub_auth_method']   = $authMethod;
    $_SESSION['fiscalhub_login_time']    = time();
}

/**
 * Revoke a session that was minted while a different auth method was active.
 */
function auth_discard_stale_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        auth_logout();
    }
}

/**
 * Check if the current request is authenticated.
 */
function auth_check(): bool {
    global $authMethod;

    if ($authMethod === 'none') {
        return true;
    }

    if ($authMethod === 'account') {
        auth_session_start();
        if (auth_session_valid()) {
            return true;
        }
        if (!empty($_SESSION['fiscalhub_authenticated'])) {
            // Session created while another AUTH_METHOD was active — revoke it.
            auth_discard_stale_session();
        }
        return false;
    }

    if ($authMethod === 'keycloak') {
        return auth_check_keycloak();
    }

    return false;
}

/**
 * Attempt login with username/password (account mode).
 */
function auth_login(string $username, string $password): bool {
    global $authMethod;

    // Username/password login only exists in account mode. Without this guard
    // a leftover ACCOUNT_LOGIN/ACCOUNT_PASSWORD in .env would still mint a
    // session while keycloak mode is active.
    if ($authMethod !== 'account') {
        return false;
    }

    $expectedLogin = getenv('ACCOUNT_LOGIN') ?: '';
    $expectedPassword = getenv('ACCOUNT_PASSWORD') ?: '';

    if ($expectedLogin === '' || $expectedPassword === '') {
        return false;
    }

    // Constant-time comparison to prevent timing attacks
    if (hash_equals($expectedLogin, $username) && hash_equals($expectedPassword, $password)) {
        auth_set_authenticated();
        return true;
    }

    return false;
}

/**
 * Destroy session (logout).
 */
function auth_logout(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Get the current auth method.
 */
function auth_method(): string {
    global $authMethod;
    return $authMethod;
}

/**
 * Login entry point for the active auth method (URL-encodes the destination).
 *
 * account  → /login.html?redirect=…
 * keycloak → /login.html?sso=1&redirect=…   (credentials form is suppressed)
 */
function auth_login_path(string $destination): string {
    global $authMethod;

    if ($authMethod === 'keycloak') {
        return '/login.html?sso=1&redirect=' . urlencode($destination);
    }

    return '/login.html?redirect=' . urlencode($destination);
}

/**
 * Sanitize a redirect target: only local paths are accepted, so a crafted
 * ?redirect= value can never bounce the browser to another host.
 */
function auth_safe_redirect(string $target): string {
    if (!str_starts_with($target, '/') || str_contains($target, '//')) {
        return '/';
    }
    return parse_url($target, PHP_URL_PATH) ?: '/';
}

// ── reCAPTCHA (account mode only) ──────────────────────────

/** Default Google reCAPTCHA verification endpoint. */
function auth_recaptcha_default_verify_url(): string {
    return 'https://www.google.com/recaptcha/api/siteverify';
}

/** Public reCAPTCHA site key (safe to expose to the browser). */
function auth_recaptcha_site_key(): string {
    return trim(getenv('RECAPTCHA_SITE_KEY') ?: '');
}

/**
 * Whether reCAPTCHA is enforced. Only meaningful in account mode and only
 * when both the site key and the secret key are configured.
 */
function auth_recaptcha_enabled(): bool {
    global $authMethod;

    if ($authMethod !== 'account') {
        return false;
    }

    return auth_recaptcha_site_key() !== '' && (getenv('RECAPTCHA_SECRET_KEY') ?: '') !== '';
}

/**
 * Verification endpoint — overridable via RECAPTCHA_VERIFY_URL for testing.
 */
function auth_recaptcha_verify_url(): string {
    $url = trim(getenv('RECAPTCHA_VERIFY_URL') ?: '');
    return $url !== '' ? $url : auth_recaptcha_default_verify_url();
}

/**
 * Verify a reCAPTCHA v2 token against Google's siteverify endpoint.
 *
 * Returns true when reCAPTCHA is disabled (no-op). Fails closed on
 * transport/HTTP/JSON errors, logging the reason to the PHP error log.
 */
function auth_verify_recaptcha(string $token): bool {
    if (!auth_recaptcha_enabled()) {
        return true;
    }

    $secret = getenv('RECAPTCHA_SECRET_KEY') ?: '';
    $token  = trim($token);

    if ($token === '') {
        error_log('reCAPTCHA rejected: empty response token');
        return false;
    }

    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $ch = curl_init(auth_recaptcha_verify_url());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('reCAPTCHA verification failed: '
            . ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode));
        return false;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || ($data['success'] ?? false) !== true) {
        $codes = isset($data['error-codes']) && is_array($data['error-codes'])
            ? implode(',', $data['error-codes'])
            : 'unknown error';
        error_log('reCAPTCHA rejected: ' . $codes);
        return false;
    }

    return true;
}

// ── Keycloak helpers ───────────────────────────────────────

/**
 * Whether Keycloak SSO is the active auth method and is configured.
 */
function auth_keycloak_enabled(): bool {
    global $authMethod;

    if ($authMethod !== 'keycloak') {
        return false;
    }

    return (getenv('KEYCLOAK_BASE_URL') ?: '') !== ''
        && (getenv('KEYCLOAK_REALM') ?: '') !== '';
}

/**
 * Build the Keycloak RP-initiated logout URL (end_session_endpoint).
 * Returns '' when SSO logout cannot be built, so callers fall back to the
 * local login page.
 */
function auth_keycloak_logout_url(): string {
    if (!auth_keycloak_enabled()) {
        return '';
    }

    $baseUrl  = rtrim(getenv('KEYCLOAK_BASE_URL') ?: '', '/');
    $realm    = getenv('KEYCLOAK_REALM') ?: '';
    $clientId = getenv('KEYCLOAK_CLIENT_ID') ?: '';

    if ($baseUrl === '' || $realm === '' || $clientId === '') {
        return '';
    }

    $params = ['client_id' => $clientId];

    // The ID token stored by the login flow lets Keycloak skip its own logout
    // confirmation page. It has to be read while the session is still alive:
    // logout.php builds this URL before it calls auth_logout().
    auth_session_start();
    $idToken = $_SESSION[AUTH_KC_ID_TOKEN_KEY] ?? '';
    if (is_string($idToken) && $idToken !== '') {
        $params['id_token_hint'] = $idToken;
    }

    $postLogoutRedirect = trim(getenv('KEYCLOAK_REDIRECT_URI') ?: '');
    if ($postLogoutRedirect !== '') {
        $params['post_logout_redirect_uri'] = $postLogoutRedirect;
    }

    return $baseUrl . '/realms/' . urlencode($realm)
         . '/protocol/openid-connect/logout?' . http_build_query($params);
}

// ── Keycloak browser login (Authorization Code + PKCE) ────
//
// auth_check_keycloak() above validates a Bearer header, which only an API
// client or an SSO aware reverse proxy can inject. A browser that reaches
// Fiscal Hub directly is served by the flow below: /keycloak-login.php sends it
// to the provider, the provider returns it to /keycloak-callback.php with a one
// time code and the code is exchanged server side (PKCE + client secret).

/** Session keys of an in-flight authorization request. */
const AUTH_KC_STATE_KEY    = 'fiscalhub_oidc_state';
const AUTH_KC_NONCE_KEY    = 'fiscalhub_oidc_nonce';
const AUTH_KC_VERIFIER_KEY = 'fiscalhub_oidc_verifier';
const AUTH_KC_REDIRECT_KEY = 'fiscalhub_oidc_redirect';
const AUTH_KC_STARTED_KEY  = 'fiscalhub_oidc_started';
const AUTH_KC_ID_TOKEN_KEY = 'fiscalhub_oidc_id_token';

/** How long an in-flight authorization request stays valid (seconds). */
const AUTH_KC_REQUEST_TTL = 600;

/** base64url without padding (RFC 7636 / JWT encoding). */
function auth_base64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Random URL-safe token: state, nonce and PKCE verifier. */
function auth_random_token(int $bytes = 32): string {
    return auth_base64url(random_bytes($bytes));
}

/** PKCE S256 challenge for a verifier (RFC 7636 section 4.2). */
function auth_code_challenge(string $verifier): string {
    return auth_base64url(hash('sha256', $verifier, true));
}

/**
 * Absolute URL the provider redirects back to (/keycloak-callback.php).
 *
 * KEYCLOAK_CALLBACK_URI overrides it for deployments whose request headers do
 * not carry the public address; the value must be registered on the client as
 * a valid redirect URI.
 */
function auth_keycloak_callback_uri(): string {
    $override = trim(getenv('KEYCLOAK_CALLBACK_URI') ?: '');
    if ($override !== '') {
        return $override;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return '';
    }

    return ($https ? 'https://' : 'http://') . $host . '/keycloak-callback.php';
}

/**
 * Start the flow: store the single use values in the session and return the
 * provider authorization URL ('' when the mode is not usable).
 */
function auth_keycloak_authorize_url(string $destination): string {
    if (!auth_keycloak_enabled()) {
        return '';
    }

    $baseUrl     = rtrim(getenv('KEYCLOAK_BASE_URL') ?: '', '/');
    $realm       = getenv('KEYCLOAK_REALM') ?: '';
    $clientId    = getenv('KEYCLOAK_CLIENT_ID') ?: '';
    $redirectUri = auth_keycloak_callback_uri();

    if ($baseUrl === '' || $realm === '' || $clientId === '' || $redirectUri === '') {
        return '';
    }

    $state    = auth_random_token(24);
    $nonce    = auth_random_token(24);
    $verifier = auth_random_token(48);

    auth_session_start();
    $_SESSION[AUTH_KC_STATE_KEY]    = $state;
    $_SESSION[AUTH_KC_NONCE_KEY]    = $nonce;
    $_SESSION[AUTH_KC_VERIFIER_KEY] = $verifier;
    $_SESSION[AUTH_KC_REDIRECT_KEY] = $destination;
    $_SESSION[AUTH_KC_STARTED_KEY]  = time();

    return $baseUrl . '/realms/' . urlencode($realm) . '/protocol/openid-connect/auth?' . http_build_query([
        'client_id'             => $clientId,
        'response_type'         => 'code',
        'scope'                 => 'openid profile email',
        'redirect_uri'          => $redirectUri,
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => auth_code_challenge($verifier),
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Claims of a JWT payload, without verifying the signature: the token was
 * received over TLS straight from the token endpoint of the provider and the
 * access token is validated by Keycloak's /userinfo. It is used for the nonce
 * check only, never to take an identity decision.
 */
function auth_jwt_claims(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [];
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    if ($payload === false) {
        return [];
    }
    $claims = json_decode($payload, true);
    return is_array($claims) ? $claims : [];
}

/** POST a form to a Keycloak endpoint and decode the JSON answer (null on error). */
function auth_keycloak_post(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('Keycloak request failed: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode));
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/** Userinfo claims for an access token (null when the provider rejects it). */
function auth_keycloak_userinfo(string $accessToken): ?array {
    $baseUrl = rtrim(getenv('KEYCLOAK_BASE_URL') ?: '', '/');
    $realm   = getenv('KEYCLOAK_REALM') ?: '';
    if ($baseUrl === '' || $realm === '' || $accessToken === '') {
        return null;
    }

    $ch = curl_init($baseUrl . '/realms/' . urlencode($realm) . '/protocol/openid-connect/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $httpCode !== 200 || $response === '') {
        return null;
    }
    $claims = json_decode($response, true);
    return is_array($claims) ? $claims : null;
}

/**
 * Finish the flow: check the single use state, exchange the code and validate
 * the identity before opening the session.
 *
 * Returns the sanitized redirect target stored by /keycloak-login.php on
 * success, null on failure (the reason is written to the PHP error log).
 */
function auth_keycloak_complete_login(string $code, string $state): ?string {
    if (!auth_keycloak_enabled() || $code === '' || $state === '') {
        return null;
    }

    auth_session_start();

    $expectedState = $_SESSION[AUTH_KC_STATE_KEY] ?? '';
    $expectedNonce = $_SESSION[AUTH_KC_NONCE_KEY] ?? '';
    $verifier      = $_SESSION[AUTH_KC_VERIFIER_KEY] ?? '';
    $destination   = $_SESSION[AUTH_KC_REDIRECT_KEY] ?? '/';
    $startedAt     = (int) ($_SESSION[AUTH_KC_STARTED_KEY] ?? 0);

    // Single use: the values are gone whether the exchange succeeds or not.
    unset(
        $_SESSION[AUTH_KC_STATE_KEY],
        $_SESSION[AUTH_KC_NONCE_KEY],
        $_SESSION[AUTH_KC_VERIFIER_KEY],
        $_SESSION[AUTH_KC_REDIRECT_KEY],
        $_SESSION[AUTH_KC_STARTED_KEY]
    );

    if (!is_string($expectedState) || $expectedState === '' || !hash_equals($expectedState, $state)) {
        error_log('Keycloak callback rejected: state mismatch');
        return null;
    }
    if ($startedAt > 0 && $startedAt + AUTH_KC_REQUEST_TTL < time()) {
        error_log('Keycloak callback rejected: expired authorization request');
        return null;
    }
    if (!is_string($verifier) || $verifier === '') {
        error_log('Keycloak callback rejected: missing PKCE verifier');
        return null;
    }

    $baseUrl     = rtrim(getenv('KEYCLOAK_BASE_URL') ?: '', '/');
    $realm       = getenv('KEYCLOAK_REALM') ?: '';
    $clientId    = getenv('KEYCLOAK_CLIENT_ID') ?: '';
    $redirectUri = auth_keycloak_callback_uri();

    if ($baseUrl === '' || $realm === '' || $clientId === '' || $redirectUri === '') {
        return null;
    }

    $token = auth_keycloak_post(
        $baseUrl . '/realms/' . urlencode($realm) . '/protocol/openid-connect/token',
        [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $clientId,
            'client_secret' => getenv('KEYCLOAK_CLIENT_SECRET') ?: '',
            'code_verifier' => $verifier,
        ]
    );

    if ($token === null || empty($token['access_token'])) {
        error_log('Keycloak callback rejected: token exchange failed');
        return null;
    }

    $idToken = is_string($token['id_token'] ?? null) ? $token['id_token'] : '';
    if ($idToken !== '' && is_string($expectedNonce) && $expectedNonce !== '') {
        $claims = auth_jwt_claims($idToken);
        if (($claims['nonce'] ?? '') !== $expectedNonce) {
            error_log('Keycloak callback rejected: nonce mismatch');
            return null;
        }
    }

    // The access token is validated by Keycloak itself: /userinfo answers 401
    // for an expired or foreign token, exactly like the Bearer path above.
    $userinfo = auth_keycloak_userinfo((string) $token['access_token']);
    if ($userinfo === null || empty($userinfo['email'])) {
        error_log('Keycloak callback rejected: userinfo returned no e-mail');
        return null;
    }

    $email        = (string) $userinfo['email'];
    $allowedEmail = getenv('KEYCLOAK_EMAIL_ACCOUNT') ?: '';
    if ($allowedEmail !== '' && $email !== $allowedEmail) {
        error_log('Keycloak callback rejected: ' . $email . ' is not the allowed account');
        return null;
    }

    auth_set_authenticated();
    if ($idToken !== '') {
        $_SESSION[AUTH_KC_ID_TOKEN_KEY] = $idToken;
    }

    return auth_safe_redirect(is_string($destination) ? $destination : '/');
}

// ── Keycloak Bearer token validation (internal) ────────────
function auth_check_keycloak(): bool {
    static $checked = false;
    static $result = false;

    // Only validate once per request
    if ($checked) {
        return $result;
    }
    $checked = true;

    // If already in session for this auth method, skip re-validation
    auth_session_start();
    if (auth_session_valid()) {
        $result = true;
        return true;
    }
    if (!empty($_SESSION['fiscalhub_authenticated'])) {
        // Session minted by a different AUTH_METHOD (e.g. account) — revoke it
        // instead of trusting it as a keycloak session.
        auth_discard_stale_session();
    }

    $keycloakBaseUrl  = rtrim(getenv('KEYCLOAK_BASE_URL') ?: '', '/');
    $keycloakRealm    = getenv('KEYCLOAK_REALM') ?: '';
    $keycloakClientId = getenv('KEYCLOAK_CLIENT_ID') ?: '';
    $keycloakEmailAccount = getenv('KEYCLOAK_EMAIL_ACCOUNT') ?: '';

    if ($keycloakBaseUrl === '' || $keycloakRealm === '' || $keycloakClientId === '') {
        return false;
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        return false;
    }

    $accessToken = substr($authHeader, 7);

    $userinfo = auth_keycloak_userinfo($accessToken);
    if ($userinfo === null || !isset($userinfo['email'])) {
        return false;
    }

    if ($keycloakEmailAccount !== '' && $userinfo['email'] !== $keycloakEmailAccount) {
        return false;
    }

    // Valid — persist in session so subsequent requests skip re-validation
    auth_set_authenticated();
    $result = true;
    return true;
}
