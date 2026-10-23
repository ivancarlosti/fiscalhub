<?php
/**
 * BrasilAPI Proxy — Forwards CNPJ lookups server-side
 *
 * Browser → brasilapi-proxy.php (same origin, no CORS)
 * brasilapi-proxy.php → brasilapi.com.br (server-to-server, no CORS)
 *
 * BrasilAPI is free and requires no API key, so unlike the previous
 * CNPJá integration there is no Authorization header to manage.
 *
 * Supported action (JSON body):
 *   - cnpj: GET /api/cnpj/v1/{cnpj}  (full 14-digit CNPJ detail,
 *           including the quadro societário in the "qsa" field)
 *
 * Configuration is read from environment variables (set via docker/.env).
 */

// ── Authentication gate (defense-in-depth) ──────────────────
require_once __DIR__ . '/auth.php';

// Router already enforces auth, but check again in case of
// direct access or misconfiguration
if (!auth_check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// ── Load configuration from environment ─────────────────────
$apiBase    = rtrim(getenv('BRASILAPI_API_BASE') ?: 'https://brasilapi.com.br/api/cnpj/v1', '/');
$apiTimeout = (int)(getenv('BRASILAPI_API_TIMEOUT') ?: 10);

// ── CORS preflight (OPTIONS) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// ── Only accept POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Read and validate incoming JSON ──────────────────────
$body = file_get_contents('php://input');
$json = json_decode($body, true);

if (!$json) {
    json_error(400, 'Missing or invalid JSON body');
}

$action = strtolower((string)($json['action'] ?? ''));

// ── Build upstream path per action ───────────────────────
if ($action !== 'cnpj') {
    json_error(400, 'Invalid action. Send "cnpj".');
}

$taxId = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($json['taxId'] ?? '')));
if (strlen($taxId) !== 14) {
    json_error(400, 'Invalid CNPJ: must have exactly 14 positions');
}
if (!preg_match('/^[A-Z0-9]{12}\d{2}$/', $taxId) || !cnpj_valido($taxId)) {
    json_error(400, 'Invalid CNPJ: check digits do not match');
}

$url = $apiBase . '/' . rawurlencode($taxId);

// ── Forward GET request to BrasilAPI ─────────────────────
if (!function_exists('curl_init')) {
    json_error(500, 'Proxy error: cURL extension is not available');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: FiscalHub/1.0'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $apiTimeout,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response    = curl_exec($ch);
$httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error       = curl_error($ch);

// ── Handle cURL failure ──────────────────────────────────
if ($error) {
    json_error(502, 'Proxy error', $error);
}

// ── Diagnostic log for upstream errors (safe to remove) ──
if ($httpCode >= 400) {
    error_log(sprintf(
        '[brasilapi-proxy] upstream error url=%s http=%d body=%s',
        $url,
        $httpCode,
        substr($response, 0, 600)
    ));
}

// ── Pass through a successful response ───────────────────
if ($httpCode >= 200 && $httpCode < 300) {
    http_response_code($httpCode);
    header('Content-Type: ' . ($contentType ?: 'application/json'));
    header('Access-Control-Allow-Origin: *');
    echo $response;
    exit;
}

// ── Sanitized upstream error forwarding ──────────────────
$safeBody = json_decode($response, true);
if (!is_array($safeBody)) {
    $safeBody = ['error' => 'Upstream error', 'http_code' => $httpCode];
}

// Remove any key-like fields that might leak credentials
unset($safeBody['api_key'], $safeBody['apikey'], $safeBody['api-key'], $safeBody['token'], $safeBody['authorization']);

// BrasilAPI uses "message" for human-readable errors — surface it
// consistently alongside the generic error message.
if ($httpCode === 404 && empty($safeBody['error'])) {
    $safeBody['error'] = 'CNPJ não encontrado';
}

http_response_code($httpCode);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
echo json_encode($safeBody);

/**
 * Emit a JSON error response and stop.
 */
function json_error(int $code, string $error, ?string $detail = null): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $payload = ['error' => $error];
    if ($detail !== null) {
        $payload['detail'] = $detail;
    }
    echo json_encode($payload);
    exit;
}

/**
 * Validate a 14-position CNPJ (numeric or alphanumeric) including its check digits.
 *
 * Alphanumeric mapping follows the official RFB rule (ASCII code minus 48):
 *   - '0'..'9' -> 0..9
 *   - 'A'..'Z' -> 17..42
 * Weights are the same as the numeric CNPJ algorithm.
 */
function cnpj_valido(string $cnpj): bool {
    $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    $valor = static function (string $c): int {
        $ord = ord($c);
        if (($ord >= 48 && $ord <= 57) || ($ord >= 65 && $ord <= 90)) {
            return $ord - 48; // official RFB rule: ASCII code minus 48 (A=17..Z=42)
        }
        return -1;
    };

    $calc = static function (array $pesos, ?int $extra = null) use ($cnpj, $valor): int {
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $v = $valor($cnpj[$i]);
            if ($v < 0) {
                return -1;
            }
            $soma += $v * $pesos[$i];
        }
        if ($extra !== null) {
            $soma += $extra * $pesos[12];
        }
        $dv = 11 - ($soma % 11);
        return $dv >= 10 ? 0 : $dv;
    };

    $dv1 = $calc($pesos1);
    $dv2 = $calc($pesos2, $dv1);

    return $dv1 === (int)$cnpj[12] && $dv2 === (int)$cnpj[13];
}
