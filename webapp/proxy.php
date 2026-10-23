<?php
/**
 * CORS Proxy — Forwards requests server-side to Meu Danfe API v2
 *
 * Browser → proxy.php (same origin, no CORS)
 * proxy.php → api.meudanfe.com.br/v2 (server-to-server, no CORS)
 *
 * Transforms Meu Danfe response format to match what app.js expects.
 *
 * All credentials are read from environment variables (set via docker/.env).
 * NEVER hardcode API keys in this file.
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
// FISCALHUB_API_* is the current name. MEUDANFE_API_* (the previous product
// name) and the legacy API_* names are still read so existing deployments keep
// working without touching their .env.
$apiBase    = getenv('FISCALHUB_API_BASE')    ?: (getenv('MEUDANFE_API_BASE')    ?: (getenv('API_BASE')    ?: 'https://api.meudanfe.com.br/v2'));
$apiKey     = getenv('FISCALHUB_API_KEY')     ?: (getenv('MEUDANFE_API_KEY')     ?: (getenv('API_KEY')     ?: ''));
$apiTimeout = (int)(getenv('FISCALHUB_API_TIMEOUT') ?: (getenv('MEUDANFE_API_TIMEOUT') ?: (getenv('API_TIMEOUT') ?: 60)));

// ── Fail early if API key is missing ────────────────────────
if ($apiKey === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

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
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Missing or invalid JSON body']);
    exit;
}

// ── Route: NFe (Nota Fiscal Eletrônica) ─────────────────
if (empty($json['chave'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Missing required field. Send "chave" (NFe 44-digit access key).']);
    exit;
}

$chave = preg_replace('/\D/', '', $json['chave']); // strip non-digits
if (strlen($chave) !== 44) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Invalid access key: must be 44 digits']);
    exit;
}

// ── Step 1: Try to get the DANFE PDF directly ────────────
$url = $apiBase . '/fd/get/da/' . urlencode($chave);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Api-Key: ' . $apiKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $apiTimeout,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);

// ── Handle cURL failure ──────────────────────────────────
if ($error) {
    http_response_code(502);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    // Sanitize error: strip API key if it accidentally appears in cURL message
    $safeError = ($apiKey !== '') ? str_replace($apiKey, '[REDACTED]', $error) : $error;
    echo json_encode(['error' => 'Proxy error', 'detail' => $safeError]);
    exit;
}

// ── If the NFe is not yet in the account, try adding it ──
if ($httpCode === 404 || $httpCode === 422) {
    // Step 2: Add the NFe by access key (uses PUT, not POST)
    $addUrl = $apiBase . '/fd/add/' . urlencode($chave);
    $ch2 = curl_init($addUrl);
    curl_setopt_array($ch2, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_HTTPHEADER     => ['Api-Key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $apiTimeout,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $addResponse = curl_exec($ch2);
    $addHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $addError = curl_error($ch2);

    if ($addError) {
        http_response_code(502);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        $safeError = ($apiKey !== '') ? str_replace($apiKey, '[REDACTED]', $addError) : $addError;
        echo json_encode(['error' => 'Proxy error during add', 'detail' => $safeError]);
        exit;
    }

    // Handle payment required (no credits)
    if ($addHttpCode === 402) {
        http_response_code(402);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'error' => 'Sem créditos na API',
            'detail' => 'Sua conta no meudanfe.com.br não possui créditos. A consulta custa R$0,03. Acesse a Área do Cliente para adicionar créditos.',
        ]);
        exit;
    }

    $addJson = json_decode($addResponse, true);

    // Check if the add was accepted (202 = queued, 200/201 = already exists/added)
    if ($addHttpCode === 202 || $addHttpCode === 200 || $addHttpCode === 201) {
        // Poll for completion by retrying GET /fd/get/da/ (up to 15 attempts, 2s apart)
        $attempts = 0;
        $fetched = false;

        while ($attempts < 15) {
            sleep(2);
            $attempts++;

            $ch3 = curl_init($url);  // $url = GET /fd/get/da/{chave}
            curl_setopt_array($ch3, [
                CURLOPT_HTTPHEADER     => ['Api-Key: ' . $apiKey],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch3);
            $httpCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch3, CURLINFO_CONTENT_TYPE);
            $pollError = curl_error($ch3);

            if ($pollError) {
                http_response_code(502);
                header('Content-Type: application/json');
                header('Access-Control-Allow-Origin: *');
                $safeError = ($apiKey !== '') ? str_replace($apiKey, '[REDACTED]', $pollError) : $pollError;
                echo json_encode(['error' => 'Proxy error during poll', 'detail' => $safeError]);
                exit;
            }

            if ($httpCode === 200) {
                $fetched = true;
                break;
            }
        }

        if (!$fetched) {
            http_response_code(504);
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            echo json_encode([
                'error' => 'Timeout',
                'detail' => 'A consulta na SEFAZ está demorando mais que o esperado. Tente novamente em alguns instantes.',
            ]);
            exit;
        }
    } else {
        // Add failed with unexpected status — sanitize before forwarding
        http_response_code($addHttpCode);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        // Never pass raw upstream response directly; decode and strip sensitive fields
        $safeBody = json_decode($addResponse, true);
        if (!$safeBody) {
            $safeBody = ['error' => 'Upstream error', 'http_code' => $addHttpCode];
        }
        // Remove any key-like fields that might leak credentials
        unset($safeBody['api_key'], $safeBody['apikey'], $safeBody['api-key'], $safeBody['token']);
        echo json_encode($safeBody);
        exit;
    }
}

// ── Transform Meu Danfe response → app.js expected format ──
// Meu Danfe returns: { name, type, format, data }
// app.js expects:    { pdf_base64 }
if ($httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    if ($data && isset($data['data'])) {
        $transformed = ['pdf_base64' => $data['data']];
        http_response_code(200);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($transformed);
        exit;
    }
}

// ── Pass-through: return response as-is ──────────────────
http_response_code($httpCode);
header('Content-Type: ' . ($contentType ?: 'application/json'));
header('Access-Control-Allow-Origin: *');
echo $response;
