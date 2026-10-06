<?php
declare(strict_types=1);

/**
 * API de la página de mesa. Sin login: cada petición trae el token del QR (t) y el código de la
 * noche (code). Sin cookies, así que no hay CSRF que proteger. Todo POST con cuerpo JSON.
 *   session · search · request · cancel · mine
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/karaoke.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function table_respond(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function table_problem(int $status, string $title, string $detail, ?string $code = null): never
{
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(array_filter(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'code' => $code]), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_installed()) {
    table_problem(503, 'No disponible', 'El karaoke por mesa no está disponible en este momento.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    table_problem(405, 'Método no permitido', 'Usa POST.');
}
$raw = (string) file_get_contents('php://input', false, null, 0, 16384);
$in = json_decode($raw, true);
if (!is_array($in)) {
    table_problem(400, 'Solicitud no válida', 'Recarga la página e inténtalo de nuevo.', 'bad_json');
}

$pdo = db();
$action = (string) ($_GET['action'] ?? '');
try {
    $clientKey = karaoke_client_key($pdo, client_ip());
    ['table' => $table, 'night' => $night] = karaoke_session($pdo, (string) ($in['t'] ?? ''), (string) ($in['code'] ?? ''), $clientKey);
    $tableId = (int) $table['id'];
    $nightId = (int) $night['id'];
    $agent = karaoke_agent_status($pdo);
    $common = [
        'server_time' => date('c', karaoke_clock()),
        'agent_online' => $agent['online'] && $agent['karafun_connected'],
    ];

    switch ($action) {
        case 'session':
            table_respond($common + [
                'table' => ['number' => (int) $table['number'], 'name' => $table['name']],
                'max_pending' => karaoke_setting_int($pdo, 'karaoke_max_pending'),
            ]);

        case 'search':
            table_respond($common + ['songs' => karaoke_search($pdo, (string) ($in['q'] ?? ''))]);

        case 'request':
            $input = [
                'id' => $in['id'] ?? '',
                'singer' => $in['singer'] ?? '',
                'song_id' => isset($in['song_id']) && is_int($in['song_id']) ? $in['song_id'] : null,
                'youtube_url' => isset($in['youtube_url']) && is_string($in['youtube_url']) ? $in['youtube_url'] : null,
            ];
            $r = karaoke_request_create($pdo, $table, $night, $input, null, $clientKey);
            table_respond($common + ['request_id' => $r['id'], 'requests' => karaoke_table_requests($pdo, $nightId, $tableId)]);

        case 'cancel':
            karaoke_request_cancel($pdo, (string) ($in['id'] ?? ''), 'mesa', $tableId);
            table_respond($common + ['requests' => karaoke_table_requests($pdo, $nightId, $tableId)]);

        case 'mine':
            table_respond($common + ['requests' => karaoke_table_requests($pdo, $nightId, $tableId)]);

        default:
            table_problem(404, 'No encontrado', 'Acción desconocida.');
    }
} catch (KaraokeError $e) {
    table_problem($e->status, $e->title, $e->getMessage(), $e->errorCode);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[sarao-karaoke-mesa] ' . $action . ': ' . $e->getMessage());
    table_problem(500, 'Error del servidor', 'Algo falló. Inténtalo de nuevo en un momento.');
}
