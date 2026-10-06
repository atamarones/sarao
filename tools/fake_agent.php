<?php
declare(strict_types=1);

/**
 * Simulador del agente del PC del bar: habla docs/karaoke-contrato-agente.md (v2) contra un servidor
 * (local con php -S o el real) para probar punta a punta sin KaraFun.
 *
 *   php tools/fake_agent.php --url=http://127.0.0.1:8000 --token=<64 hex> [opciones]
 *
 *   --seconds=N          cuánto tiempo correr (60). 0 = sin límite.
 *   --interval=S         segundos entre polls (1).
 *   --song-seconds=N     cuánto "suena" cada canción en el KaraFun simulado (20).
 *   --catalog=N          canciones locales que sube al arrancar (300). 0 = no sube.
 *   --karafun-catalog=N  canciones de KaraFun en línea que sube al arrancar (0 = no sube).
 *   --download-seconds=N cuánto tarda una descarga (5); mientras tanto la reporta en «working».
 *   --no-singer-for-downloads  una canción recién descargada entra sin cantante (singer_shown: false).
 *   --drop-ack-once      ejecuta el primer enqueue pero "se cae" antes de confirmarlo: prueba la
 *                        reentrega tras el lease y la reconciliación contra la cola (no lo duplica).
 *   --fail-download=ID   la descarga de ese youtube_id falla con too_long.
 *   --offline-after=N    deja de hacer polls después de N segundos (prueba "agente desconectado").
 *
 * Usa su propia implementación de natural_key (no incluye app/karaoke.php): si no coincide con la
 * de la nube, el servidor avisa en warnings.
 */

const AGENT_VERSION = 'fake-1.0.0';

$o = getopt('', ['url:', 'token:', 'seconds:', 'interval:', 'song-seconds:', 'catalog:', 'karafun-catalog:', 'download-seconds:', 'no-singer-for-downloads', 'drop-ack-once', 'fail-download:', 'offline-after:']);
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
$karafunSize = (int) ($o['karafun-catalog'] ?? 0);
$downloadSeconds = (int) ($o['download-seconds'] ?? 5);
$noSingerForDownloads = isset($o['no-singer-for-downloads']);
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

/** natural_key local según el contrato v2 §2 (artista|título, sin duración), implementada aparte de la nube a propósito. */
function nkey(string $artist, string $title): string
{
    $n = static function (string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $s = class_exists(Normalizer::class) ? (string) Normalizer::normalize($s, Normalizer::FORM_D) : (string) iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        $s = (string) preg_replace('/\p{Mn}/u', '', $s);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]/', ' ', $s)));
    };
    return $n($artist) . '|' . $n($title);
}

function fake_song(string $artist, string $title, int $dur, ?string $folder, ?string $youtubeId = null): array
{
    return ['natural_key' => nkey($artist, $title), 'source' => 'local', 'kf_id' => null, 'title' => $title, 'artist' => $artist, 'duration_s' => $dur, 'folder' => $folder, 'youtube_id' => $youtubeId];
}

function karafun_catalog(int $n): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $id = 50000 + $i;
        $out[] = ['natural_key' => "kf:$id", 'source' => 'karafun', 'kf_id' => $id, 'title' => "Éxito en línea $i", 'artist' => 'Artista KaraFun ' . ($i % 40), 'duration_s' => 180 + $i % 90, 'folder' => null, 'youtube_id' => null];
    }
    return $out;
}

function catalog(int $n): array
{
    $artists = ['Juanes', 'Shakira', 'Carlos Vives', 'Adriana Lucía', 'Silvestre Dangond', 'Joe Arroyo', 'Grupo Niche', 'Darío Gómez', 'Jessi Uribe', 'Paola Jara', 'Maná', 'Luis Miguel', 'Rocío Dúrcal', 'Marc Anthony', 'Ñengo Flow'];
    $words = ['Amor', 'Noche', 'Corazón', 'Olvido', 'Fiesta', 'Despecho', 'Canción', 'Bogotá', 'Mañana', 'Recuerdo', 'Lágrimas', 'Rumba'];
    $out = [fake_song('Adriana Lucía', 'Quisiera olvidarte', 187, null)];
    for ($i = 1; $i < $n; $i++) {
        $a = $artists[$i % count($artists)];
        $t = $words[$i % count($words)] . ' ' . $words[intdiv($i, count($words)) % count($words)] . " $i";
        $out[] = fake_song($a, $t, $i % 5 ? 150 + ($i * 7) % 180 : 0, null);
    }
    return $out;
}

function sync_catalog(int $n, string $source = 'local'): int
{
    $songs = $source === 'local' ? catalog($n) : karafun_catalog($n);
    $begin = must(call('catalog.begin', ['source' => $source]), 'catalog.begin');
    $chunks = array_chunk($songs, 500);
    foreach ($chunks as $i => $c) {
        must(call('catalog.chunk', ['sync_id' => $begin['sync_id'], 'index' => $i, 'songs' => $c]), "catalog.chunk $i");
    }
    $sum = must(call('catalog.commit', ['sync_id' => $begin['sync_id'], 'total_chunks' => count($chunks), 'total_songs' => count($songs)]), 'catalog.commit');
    say("catálogo $source: " . count($songs) . " canciones en " . count($chunks) . ' lotes → ' . json_encode($sum, JSON_UNESCAPED_UNICODE));
    return count($songs);
}

// ---------------------------------------------------------------------------
// KaraFun simulado: cola en memoria. La posición 0 es la que suena.
$queue = [];
$playingSince = null;
$journal = [];          // command_id => [ok, result|error]: una orden nunca se ejecuta dos veces
$inFlight = [];         // órdenes ejecutadas sin ack (caída simulada)
$working = [];          // command_id => [orden, termina_en]: descargas en marcha (contrato v2, «working»)
$downloaded = [];       // natural_key de descargas recientes que KaraFun «aún no indexó»

function kf_status(): array
{
    global $queue;
    return array_map(static fn (array $e, int $i): array => $e + ['pos' => $i, 'status' => $i === 0 ? 'playing' : 'ready'], $queue, array_keys($queue));
}

function execute(array $cmd): array
{
    global $queue, $failDownload, $catalogSize, $karafunSize, $downloaded, $noSingerForDownloads;
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
            // Una descarga reciente que KaraFun aún no indexó entra por ruta de archivo, sin cantante.
            $shown = !($noSingerForDownloads && isset($downloaded[$p['song']['natural_key']]));
            $queue[] = ['title' => $p['song']['title'], 'artist' => $p['song']['artist'], 'singer' => $shown ? $p['singer'] : ''];
            $how = $p['song']['source'] === 'karafun' ? "kf_id {$p['song']['kf_id']}" : ($shown ? 'búsqueda local' : 'ruta de archivo, SIN cantante');
            say("  + KaraFun: «{$p['song']['title']}» para {$p['singer']} (pos " . (count($queue) - 1) . ", $how)");
            return [true, ['queue_pos' => count($queue) - 1, 'singer_shown' => $shown]];
        case 'remove':
            foreach ($queue as $i => $e) {
                if ($i > 0 && ($e['singer'] === $p['singer'] || ($e['singer'] === '' && $e['title'] === ($p['song']['title'] ?? null)))) {
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
            // KaraFun da duración 0 hasta analizar el archivo.
            $song = fake_song('YouTube', 'Video ' . $p['youtube_id'], 0, 'Por aprobar', $p['youtube_id']);
            must(call('song.upsert', ['song' => $song]), 'song.upsert');
            $downloaded[$song['natural_key']] = true;
            say("  ↓ descargado {$p['youtube_id']} → Por aprobar");
            return [true, ['youtube_id' => $p['youtube_id'], 'song' => $song]];
        case 'catalog.resync':
            $src = $p['source'] ?? 'local';
            return [true, ['total_songs' => sync_catalog(max(1, $src === 'local' ? $catalogSize : $karafunSize), $src)]];
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
if ($karafunSize > 0) {
    sync_catalog($karafunSize, 'karafun');
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
        'working' => array_keys($working),
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
            if ($cmd['type'] === 'download' && $downloadSeconds > 0) {
                if (!isset($working[$id])) {
                    $working[$id] = [$cmd, time() + $downloadSeconds];
                    say("  ↓ descargando {$cmd['payload']['youtube_id']} ({$downloadSeconds} s, se reporta en working)");
                }
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
    // Descargas que terminaron: se ejecutan y se confirman.
    foreach ($working as $wid => [$wcmd, $until]) {
        if (time() >= $until) {
            unset($working[$wid]);
            $journal[$wid] = execute($wcmd);
            ack($wid, $journal[$wid]);
        }
    }
    usleep((int) ($interval * 1_000_000));
}
say('fin. Cola final de KaraFun: ' . json_encode(array_column($queue, 'singer'), JSON_UNESCAPED_UNICODE));
