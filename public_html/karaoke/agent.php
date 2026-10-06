<?php
declare(strict_types=1);

/**
 * API del agente del PC del bar (docs/karaoke-contrato-agente.md).
 *   POST /karaoke/agent.php?action=poll|ack|catalog.begin|catalog.chunk|catalog.commit|song.upsert
 *   Authorization: Bearer <karaoke_agent_token>  ·  cuerpo y respuesta JSON  ·  errores RFC 7807.
 * La lógica vive en app/karaoke.php; aquí solo hay transporte y autenticación.
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/karaoke.php';

const AGENT_MAX_BODY = 2 * 1024 * 1024;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function agent_respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function agent_problem(int $status, string $title, string $detail, ?string $code = null): never
{
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(array_filter(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'code' => $code]), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Hostinger (LiteSpeed/Apache con PHP por CGI) puede esconder la cabecera; .htaccess la reenvía. */
function agent_authorization(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    return trim((string) $h);
}

if (!is_installed()) {
    agent_problem(503, 'No instalado', 'Ejecuta install.php primero.');
}
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!is_https() && !in_array($remote, ['127.0.0.1', '::1'], true)) {
    agent_problem(403, 'Solo HTTPS', 'La API del agente solo responde por HTTPS.', 'https_required');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    agent_problem(405, 'Método no permitido', 'Usa POST.', 'method');
}

$pdo = db();
try {
    $token = karaoke_setting($pdo, 'karaoke_agent_token');
} catch (PDOException) {
    // La tabla settings existe desde la instalación; si falla, la base no responde.
    agent_problem(503, 'Base de datos no disponible', 'Inténtalo de nuevo en unos segundos.');
}
$given = preg_match('/^Bearer\s+([0-9a-f]{64})$/i', agent_authorization(), $m) ? strtolower($m[1]) : '';
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    header('WWW-Authenticate: Bearer realm="karaoke-agent"');
    agent_problem(401, 'No autorizado', 'Token del agente ausente o no válido. Genéralo en el panel → Karaoke.', 'unauthorized');
}

$ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($ct, 'application/json')) {
    agent_problem(415, 'Tipo de contenido no admitido', 'Envía el cuerpo como application/json.', 'content_type');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AGENT_MAX_BODY) {
    agent_problem(413, 'Cuerpo demasiado grande', 'Máximo 2 MB por petición.', 'too_large');
}
$raw = (string) file_get_contents('php://input', false, null, 0, AGENT_MAX_BODY + 1);
if (strlen($raw) > AGENT_MAX_BODY) {
    agent_problem(413, 'Cuerpo demasiado grande', 'Máximo 2 MB por petición.', 'too_large');
}
$body = trim($raw) === '' ? [] : json_decode($raw, true);
if (!is_array($body)) {
    agent_problem(400, 'JSON no válido', 'El cuerpo debe ser un objeto JSON.', 'bad_json');
}

$action = (string) ($_GET['action'] ?? '');
try {
    agent_respond(karaoke_agent_dispatch($pdo, $action, $body));
} catch (KaraokeError $e) {
    agent_problem($e->status, $e->title, $e->getMessage(), $e->errorCode);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[sarao-karaoke-agent] ' . $action . ': ' . $e->getMessage());
    agent_problem(500, 'Error del servidor', 'Algo falló. Reintenta la petición.', 'server');
}
