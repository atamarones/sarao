<?php
declare(strict_types=1);

/**
 * Simulador del agente del PC del bar: habla docs/karaoke-contrato-agente.md contra un servidor
 * (local con php -S o el real) para probar punta a punta sin KaraFun.
 *
 *   php tools/fake_agent.php --url=http://127.0.0.1:8000 --token=<64 hex> [opciones]
 *
 *   --seconds=N          cuánto tiempo correr (60). 0 = sin límite.
 *   --interval=S         segundos entre polls (1).
 *   --song-seconds=N     cuánto "suena" cada canción en el KaraFun simulado (20).
 *   --catalog=N          canciones del catálogo simulado que sube al arrancar (300). 0 = no sube.
 *   --drop-ack-once      ejecuta el primer enqueue pero "se cae" antes de confirmarlo: prueba la
 *                        reentrega tras el lease y la reconciliación contra la cola (no lo duplica).
 *   --fail-download=ID   la descarga de ese youtube_id falla con too_long.
 *   --offline-after=N    deja de hacer polls después de N segundos (prueba "agente desconectado").
 *
 * Usa su propia implementación de natural_key (no incluye app/karaoke.php): si no coincide con la
 * de la nube, el servidor avisa en warnings.
 */

const AGENT_VERSION = 'fake-1.0.0';

$o = getopt('', ['url:', 'token:', 'seconds:', 'interval:', 'song-seconds:', 'catalog:', 'drop-ack-once', 'fail-download:', 'offline-after:']);
if (empty($o['url']) || empty($o['token'])) {
    fwrite(STDERR, "Uso: php tools/fake_agent.php --url=http://127.0.0.1:8000 --token=<64 hex> [--seconds=60] [--catalog=300]\n");
    exit(2);
}
$base = rtrim($o['url'], '/') . '/karaoke/agent.php?action=';
$token = $o['token'];
$seconds = (int) ($o['seconds'] ?? 60);
$interval = (float) ($o['interval'] ?? 1);
$songSeconds = (int) ($o['song-seconds'] ?? 20);
$catalogSize = (int) ($o['catalog'] ?? 300);
$dropAckOnce = isset($o['drop-ack-once']);
$failDownload = $o['fail-download'] ?? null;
$offlineAfter = isset($o['offline-after']) ? (int) $o['offline-after'] : null;

function say(string $msg): void
{
    echo date('H:i:s') . "  $msg\n";
}

/** POST JSON; devuelve [status, cuerpo decodificado]. */
function call(string $action, array $body = []): array
{
    global $base, $token;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\nAccept: application/json\r\n",
        'content' => json_encode($body ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents($base . rawurlencode($action), false, $ctx);
    if ($raw === false) {
        return [0, ['detail' => 'sin conexión']];
    }
    preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode($raw, true) ?? ['raw' => $raw]];
}

function must(array $r, string $what): array
{
    [$status, $body] = $r;
    if ($status !== 200) {
        say("✗ $what → HTTP $status " . json_encode($body, JSON_UNESCAPED_UNICODE));
        exit(1);
    }
    if (!empty($body['warnings'])) {
        say("! $what avisa: " . json_encode($body['warnings'], JSON_UNESCAPED_UNICODE));
    }
    return $body;
}

/** natural_key según el contrato §2, implementada aparte de la nube a propósito. */
function nkey(string $artist, string $title, float $dur): string
{
    $n = static function (string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $s = class_exists(Normalizer::class) ? (string) Normalizer::normalize($s, Normalizer::FORM_D) : (string) iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        $s = (string) preg_replace('/\p{Mn}/u', '', $s);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]/', ' ', $s)));
    };
    return $n($artist) . '|' . $n($title) . '|' . (int) round($dur);
}

function fake_song(string $artist, string $title, int $dur, string $folder): array
{
    return ['natural_key' => nkey($artist, $title, $dur), 'title' => $title, 'artist' => $artist, 'duration_s' => $dur, 'folder' => $folder, 'file' => "$folder/$artist - $title.mp4"];
}

function catalog(int $n): array
{
    $artists = ['Juanes', 'Shakira', 'Carlos Vives', 'Adriana Lucía', 'Silvestre Dangond', 'Joe Arroyo', 'Grupo Niche', 'Darío Gómez', 'Jessi Uribe', 'Paola Jara', 'Maná', 'Luis Miguel', 'Rocío Dúrcal', 'Marc Anthony', 'Ñengo Flow'];
    $words = ['Amor', 'Noche', 'Corazón', 'Olvido', 'Fiesta', 'Despecho', 'Canción', 'Bogotá', 'Mañana', 'Recuerdo', 'Lágrimas', 'Rumba'];
    $out = [['natural_key' => nkey('Adriana Lucía', 'Quisiera olvidarte', 187), 'title' => 'Quisiera olvidarte', 'artist' => 'Adriana Lucía', 'duration_s' => 187, 'folder' => 'A', 'file' => 'A/Adriana Lucia - Quisiera olvidarte.mp4']];
    for ($i = 1; $i < $n; $i++) {
        $a = $artists[$i % count($artists)];
        $t = $words[$i % count($words)] . ' ' . $words[intdiv($i, count($words)) % count($words)] . " $i";
        $out[] = fake_song($a, $t, 150 + ($i * 7) % 180, mb_substr($a, 0, 1));
    }
    return $out;
}

function sync_catalog(int $n): int
{
    $songs = catalog($n);
    $begin = must(call('catalog.begin'), 'catalog.begin');
    $chunks = array_chunk($songs, 500);
    foreach ($chunks as $i => $c) {
        must(call('catalog.chunk', ['sync_id' => $begin['sync_id'], 'index' => $i, 'songs' => $c]), "catalog.chunk $i");
    }
    $sum = must(call('catalog.commit', ['sync_id' => $begin['sync_id'], 'total_chunks' => count($chunks), 'total_songs' => count($songs)]), 'catalog.commit');
    say("catálogo: " . count($songs) . " canciones en " . count($chunks) . ' lotes → ' . json_encode($sum, JSON_UNESCAPED_UNICODE));
    return count($songs);
}

// ---------------------------------------------------------------------------
// KaraFun simulado: cola en memoria. La posición 0 es la que suena.
$queue = [];
$playingSince = null;
$journal = [];          // command_id => [ok, result|error]: una orden nunca se ejecuta dos veces
$inFlight = [];         // órdenes ejecutadas sin ack (caída simulada)

function kf_status(): array
{
    global $queue;
    return array_map(static fn (array $e, int $i): array => $e + ['pos' => $i, 'status' => $i === 0 ? 'playing' : 'ready'], $queue, array_keys($queue));
}

function execute(array $cmd): array
{
    global $queue, $failDownload, $catalogSize;
    $p = $cmd['payload'];
    switch ($cmd['type']) {
        case 'enqueue':
            // Reconciliación: si ya está en la cola con ese cantante (marcador), no se añade otra vez.
            foreach ($queue as $i => $e) {
                if ($e['singer'] === $p['singer']) {
                    say("  · reconciliado: «{$p['singer']}» ya estaba en la cola (pos $i), no se duplica");
                    return [true, ['queue_pos' => $i]];
                }
            }
            $queue[] = ['title' => $p['song']['title'], 'artist' => $p['song']['artist'], 'singer' => $p['singer']];
            say("  + KaraFun: «{$p['song']['title']}» para {$p['singer']} (pos " . (count($queue) - 1) . ')');
            return [true, ['queue_pos' => count($queue) - 1]];
        case 'remove':
            foreach ($queue as $i => $e) {
                if ($e['singer'] === $p['singer'] && $i > 0) {
                    array_splice($queue, $i, 1);
                    say("  - KaraFun: quitada la de {$p['singer']}");
                    return [true, ['removed' => true]];
                }
            }
            return [true, ['removed' => false]];
        case 'download':
            if ($p['youtube_id'] === $failDownload) {
                return [false, ['code' => 'too_long', 'message' => 'El video dura 12 min (máximo 8).']];
            }
            $song = fake_song('YouTube', 'Video ' . $p['youtube_id'], 200, 'Por aprobar');
            $song['file'] = "Por aprobar/YouTube - Video [{$p['youtube_id']}].mp4";
            must(call('song.upsert', ['song' => $song]), 'song.upsert');
            say("  ↓ descargado {$p['youtube_id']} → Por aprobar");
            return [true, ['youtube_id' => $p['youtube_id'], 'song' => $song]];
        case 'catalog.resync':
            return [true, ['total_songs' => sync_catalog(max(1, $catalogSize))]];
        default:
            return [false, ['code' => 'unknown_type', 'message' => 'Orden desconocida.']];
    }
}

function ack(int $id, array $outcome): void
{
    [$ok, $data] = $outcome;
    $body = $ok ? ['command_id' => $id, 'ok' => true, 'result' => $data ?: new stdClass()] : ['command_id' => $id, 'ok' => false, 'error' => $data];
    [$status, $r] = call('ack', $body);
    say("  ✓ ack $id → HTTP $status " . ($r['status'] ?? '') . (!empty($r['duplicate']) ? ' (repetido)' : ''));
}

say("agente simulado contra $base");
// Sin poll de prueba: toda orden recibida en un poll se ejecuta (ignorarla la dejaría arrendada 30 s).
if ($catalogSize > 0) {
    sync_catalog($catalogSize);
}

$start = microtime(true);
while ($seconds === 0 || microtime(true) - $start < $seconds) {
    $elapsed = microtime(true) - $start;
    if ($offlineAfter !== null && $elapsed > $offlineAfter) {
        say('… simulando agente desconectado (sin polls)');
        sleep(max(1, (int) ($seconds - $elapsed)));
        break;
    }
    // Reproducción: la que suena termina a los --song-seconds.
    if ($queue && $playingSince !== null && time() - $playingSince >= $songSeconds) {
        $done = array_shift($queue);
        say("♪ terminó «{$done['title']}» ({$done['singer']})");
        $playingSince = $queue ? time() : null;
    }
    if ($queue && $playingSince === null) {
        $playingSince = time();
    }

    [$status, $res] = call('poll', [
        'agent_version' => AGENT_VERSION,
        'karafun' => ['running' => true, 'connected' => true, 'state' => $queue ? 'playing' : 'idle', 'queue' => kf_status()],
        'acks_pending' => count($inFlight),
    ]);
    if ($status === 401) {
        say('✗ token rechazado (401). Genera el token en el panel → Karaoke.');
        exit(1);
    }
    if ($status !== 200) {
        say("✗ poll → HTTP $status " . json_encode($res, JSON_UNESCAPED_UNICODE));
    } else {
        foreach ($res['commands'] as $cmd) {
            $id = (int) $cmd['id'];
            say("← orden $id {$cmd['type']} (lease hasta {$cmd['lease_until']})");
            if (isset($journal[$id])) {
                say('  · ya estaba en el diario: se reenvía el resultado guardado');
                ack($id, $journal[$id]);
                continue;
            }
            $outcome = execute($cmd);
            if ($dropAckOnce && $cmd['type'] === 'enqueue' && !isset($inFlight[$id])) {
                $dropAckOnce = false;
                $inFlight[$id] = true;
                say("  ✗ simulando caída antes de escribir el diario y confirmar la orden $id");
                continue;
            }
            unset($inFlight[$id]);
            $journal[$id] = $outcome;
            ack($id, $outcome);
        }
    }
    usleep((int) ($interval * 1_000_000));
}
say('fin. Cola final de KaraFun: ' . json_encode(array_column($queue, 'singer'), JSON_UNESCAPED_UNICODE));
