<?php
declare(strict_types=1);

/**
 * Karaoke por mesa: lógica de la nube (docs/karaoke-arquitectura.md y docs/karaoke-contrato-agente.md).
 *
 * Sin dependencias de HTTP: cada función recibe el PDO y devuelve datos o lanza KaraokeError,
 * así tools/test_karaoke.php la prueba sobre SQLite. Las páginas (karaoke/, karaoke/agent.php,
 * admin/api.php) solo traducen peticiones a estas funciones.
 *
 * Estados de un pedido (§6):
 *   descargando → descargado → en_espera → enviado → en_cola → cantando → cantada
 *   fallido, retirado y cancelado son finales. Toda transición es un UPDATE … WHERE status = <esperado>.
 */

const KARAOKE_BUFFER = 3;              // Entradas en la cola real de KaraFun: la que suena + 2.
const KARAOKE_LEASE_S = 30;
const KARAOKE_MAX_ATTEMPTS = 5;
const KARAOKE_POLL_MAX_COMMANDS = 10;
const KARAOKE_AGENT_OFFLINE_S = 15;
const KARAOKE_MAX_VIDEO_S = 480;
const KARAOKE_CHUNK_MAX = 500;
const KARAOKE_NIGHT_MAX_H = 14;        // Una noche abierta caduca sola: el código no sirve al día siguiente.
const KARAOKE_SENT_TIMEOUT_S = 90;     // Enviado y confirmado, pero KaraFun nunca lo mostró en la cola.
const KARAOKE_SINGER_MAX = 30;
const KARAOKE_AVG_SONG_S = 240;

const KARAOKE_WAITING = ['descargando', 'descargado', 'en_espera'];
const KARAOKE_IN_KARAFUN = ['enviado', 'en_cola', 'cantando'];
const KARAOKE_FINAL = ['cantada', 'fallido', 'retirado', 'cancelado'];

const KARAOKE_TRANSITIONS = [
    'descargando' => ['descargado', 'fallido', 'cancelado'],
    'descargado' => ['en_espera', 'fallido', 'cancelado'],
    'en_espera' => ['enviado', 'fallido', 'cancelado'],
    'enviado' => ['en_cola', 'cantando', 'fallido', 'retirado'],
    'en_cola' => ['cantando', 'cantada', 'retirado'],
    'cantando' => ['cantada'],
];

const KARAOKE_DEFAULTS = [
    'karaoke_max_pending' => 3,   // Pedidos pendientes (sin llegar a KaraFun) por mesa.
    'karaoke_rate_table' => 4,    // Pedidos por minuto por mesa.
    'karaoke_rate_ip' => 6,       // Pedidos por minuto por IP.
];

final class KaraokeError extends RuntimeException
{
    public function __construct(string $detail, public readonly int $status = 422, public readonly string $errorCode = 'invalid', public readonly string $title = 'No se pudo completar')
    {
        parent::__construct($detail);
    }
}

// ---------------------------------------------------------------------------
// Reloj (las horas siempre las pone el servidor, en hora de Bogotá).

function karaoke_clock(?int $set = null, bool $reset = false): int
{
    static $fixed = null;
    if ($reset) {
        $fixed = null;
    } elseif ($set !== null) {
        $fixed = $set;
    }
    return $fixed ?? time();
}

function karaoke_now(int $offsetS = 0): string
{
    return date('Y-m-d H:i:s', karaoke_clock() + $offsetS);
}

/** DATETIME de la base → ISO 8601 con zona (2026-10-06T21:15:00-05:00). */
function karaoke_iso(?string $dt): ?string
{
    return $dt === null ? null : date('c', (int) strtotime($dt));
}

function karaoke_tx(PDO $pdo, callable $fn): mixed
{
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function karaoke_setting(PDO $pdo, string $key): string
{
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? (string) (KARAOKE_DEFAULTS[$key] ?? '') : (string) $v;
}

function karaoke_setting_int(PDO $pdo, string $key): int
{
    return max(1, (int) karaoke_setting($pdo, $key));
}

function karaoke_save_setting(PDO $pdo, string $key, string $value): void
{
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        : 'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v';
    $pdo->prepare($sql)->execute([$key, $value]);
}

// ---------------------------------------------------------------------------
// Normalización (contrato §2): minúsculas, sin tildes ni signos, espacios simples.

function karaoke_normalize(string $s, ?bool $useIntl = null): string
{
    static $from = 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöùúûüýÿĀāĂăĄąĆćĈĉĊċČčĎďĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĨĩĪīĬĭĮįİĴĵĶķĹĺĻļĽľŃńŅņŇňŌōŎŏŐőŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžƠơƯưǍǎǏǐǑǒǓǔǕǖǗǘǙǚǛǜǞǟǠǡǦǧǨǩǪǫǬǭǰǴǵǸǹǺǻȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏȐȑȒȓȔȕȖȗȘșȚțȞȟȦȧȨȩȪȫȬȭȮȯȰȱȲȳ';
    static $to = 'aaaaaaceeeeiiiinooooouuuuyaaaaaaceeeeiiiinooooouuuuyyaaaaaaccccccccddeeeeeeeeeegggggggghhiiiiiiiiijjkkllllllnnnnnnoooooorrrrrrssssssssttttuuuuuuuuuuuuwwyyyzzzzzzoouuaaiioouuuuuuuuuuaaaaggkkoooojggnnaaaaaaeeeeiiiioooorrrruuuusstthhaaeeooooooooyy';
    static $map = null;
    $s = mb_strtolower($s, 'UTF-8');
    if ($useIntl ?? class_exists(Normalizer::class)) {
        // Igual que el agente: NFD y fuera las marcas combinantes.
        $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
    } else {
        $map ??= array_combine(mb_str_split($from), mb_str_split($to));
        $s = strtr($s, $map);
    }
    $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
    $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim($s);
}

/** natural_key = artista|título|duración (segundos enteros, sin redondear a 5 s). */
function karaoke_natural_key(string $artist, string $title, int|float $durationS): string
{
    return karaoke_normalize($artist) . '|' . karaoke_normalize($title) . '|' . max(0, (int) round($durationS));
}

function karaoke_valid_natural_key(string $k): bool
{
    return strlen($k) <= 255 && preg_match('/^(?:[a-z0-9]+(?: [a-z0-9]+)*)?\|(?:[a-z0-9]+(?: [a-z0-9]+)*)?\|\d{1,5}$/', $k) === 1;
}

function karaoke_is_por_aprobar(?string $folder): bool
{
    return $folder !== null && karaoke_normalize($folder) === 'por aprobar';
}

// ---------------------------------------------------------------------------
// YouTube (arquitectura §10): solo un enlace de video; se guarda solo el id.

/** Devuelve el id de 11 caracteres o lanza KaraokeError con un mensaje para la mesa. */
function karaoke_parse_youtube(string $input): string
{
    $url = trim($input);
    if ($url === '') {
        throw new KaraokeError('Pega el enlace del video de YouTube.', 422, 'youtube_empty');
    }
    if (strlen($url) > 300 || preg_match('/\s/u', $url)) {
        throw new KaraokeError('Pega solo el enlace del video, sin texto adicional ni varios enlaces.', 422, 'youtube_not_url');
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        throw new KaraokeError('Eso no es un enlace. Abre el video en YouTube, toca «Compartir» y copia el enlace (empieza por https://).', 422, 'youtube_not_url');
    }
    $p = parse_url($url);
    if ($p === false || !isset($p['scheme'], $p['host'])) {
        throw new KaraokeError('El enlace no es válido. Cópialo de nuevo desde YouTube.', 422, 'youtube_not_url');
    }
    if (strtolower($p['scheme']) !== 'https') {
        throw new KaraokeError('El enlace debe empezar por https://', 422, 'youtube_not_https');
    }
    if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
        throw new KaraokeError('El enlace no es de YouTube.', 422, 'youtube_host');
    }
    $host = strtolower($p['host']);
    $path = $p['path'] ?? '';
    $query = [];
    parse_str($p['query'] ?? '', $query);

    if ($host === 'youtu.be') {
        $id = ltrim($path, '/');
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
        if ($path === '/watch') {
            if (!isset($query['v'])) {
                throw new KaraokeError(isset($query['list'])
                    ? 'Ese enlace es de una lista de reproducción. Abre una sola canción y copia su enlace.'
                    : 'Ese enlace no lleva a un video. Abre la canción y copia su enlace.', 422, 'youtube_form');
            }
            $id = is_string($query['v']) ? $query['v'] : '';
        } elseif (preg_match('#^/shorts/([^/]*)$#', $path, $m)) {
            $id = $m[1];
        } else {
            $msg = match (true) {
                $path === '/playlist' => 'Ese enlace es de una lista de reproducción. Abre una sola canción y copia su enlace.',
                str_starts_with($path, '/live') => 'No se aceptan transmisiones en directo. Busca la canción grabada.',
                str_starts_with($path, '/results') => 'Ese enlace es de una búsqueda. Abre la canción y copia el enlace del video.',
                str_starts_with($path, '/@') || str_starts_with($path, '/channel') || str_starts_with($path, '/c/') || str_starts_with($path, '/user') => 'Ese enlace es de un canal. Abre la canción y copia el enlace del video.',
                default => 'Ese enlace de YouTube no lleva a un video. Abre la canción y copia su enlace.',
            };
            throw new KaraokeError($msg, 422, 'youtube_form');
        }
    } else {
        throw new KaraokeError('Solo se aceptan enlaces de YouTube (youtube.com o youtu.be).', 422, 'youtube_host');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        throw new KaraokeError('El enlace está incompleto o no es un video de YouTube. Cópialo de nuevo.', 422, 'youtube_id');
    }
    return $id;
}

/**
 * Comprueba en el oEmbed público que el video existe y es público (§10.5).
 * Devuelve ['exists' => true|false|null, 'title' => ?string]; null = no se pudo comprobar (se acepta).
 */
function karaoke_youtube_oembed(string $id): array
{
    if (!function_exists('curl_init')) {
        return ['exists' => null, 'title' => null];
    }
    $ch = curl_init('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 200) {
        $j = json_decode((string) $body, true);
        return ['exists' => true, 'title' => is_array($j) && is_string($j['title'] ?? null) ? mb_substr($j['title'], 0, 200) : null];
    }
    // 400/404: no existe; 401/403: privado o con acceso restringido.
    if (in_array($code, [400, 401, 403, 404], true)) {
        return ['exists' => false, 'title' => null];
    }
    return ['exists' => null, 'title' => null];
}

// ---------------------------------------------------------------------------
// Máquina de estados.

function karaoke_log(PDO $pdo, string $requestId, ?string $from, string $to, string $actor, ?string $note = null): void
{
    $pdo->prepare('INSERT INTO karaoke_request_log (request_id, from_status, to_status, actor, note, at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$requestId, $from, $to, $actor, $note === null ? null : mb_substr($note, 0, 255), karaoke_now()]);
}

/**
 * Transición atómica: solo cambia si el pedido sigue en $from. Devuelve false si otro proceso
 * lo movió antes (dos clics a la vez no mueven un pedido dos veces).
 */
function karaoke_transition(PDO $pdo, string $id, string $from, string $to, string $actor, ?string $note = null, array $set = []): bool
{
    if (!in_array($to, KARAOKE_TRANSITIONS[$from] ?? [], true)) {
        throw new LogicException("Transición no permitida: $from → $to");
    }
    if (!in_array($actor, ['mesa', 'admin', 'agente', 'sistema'], true)) {
        throw new LogicException("Actor desconocido: $actor");
    }
    return karaoke_tx($pdo, static function () use ($pdo, $id, $from, $to, $actor, $note, $set): bool {
        $cols = 'status = ?, updated_at = ?';
        $params = [$to, karaoke_now()];
        if ($to === 'fallido' && $note !== null && !array_key_exists('error', $set)) {
            $set['error'] = $note;
        }
        foreach ($set as $col => $val) {
            if (!preg_match('/^[a-z_]+$/', $col)) {
                throw new LogicException('Columna no válida');
            }
            $cols .= ", $col = ?";
            $params[] = $col === 'error' && $val !== null ? mb_substr((string) $val, 0, 255) : $val;
        }
        $params[] = $id;
        $params[] = $from;
        $st = $pdo->prepare("UPDATE karaoke_requests SET $cols WHERE id = ? AND status = ?");
        $st->execute($params);
        if ($st->rowCount() !== 1) {
            return false;
        }
        karaoke_log($pdo, $id, $from, $to, $actor, $note);
        return true;
    });
}

function karaoke_request(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM karaoke_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------------------
// Mesas y noches.

function karaoke_new_token(): string
{
    return bin2hex(random_bytes(16));
}

function karaoke_table_save(PDO $pdo, ?int $id, int $number, string $name): int
{
    $name = trim($name) === '' ? 'Mesa ' . $number : trim($name);
    if ($number < 1 || $number > 999) {
        throw new KaraokeError('El número de mesa va de 1 a 999.', 422, 'table_number');
    }
    if (mb_strlen($name) > 40) {
        throw new KaraokeError('El nombre de la mesa admite máximo 40 caracteres.', 422, 'table_name');
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_tables WHERE number = ? AND id <> ?');
    $st->execute([$number, $id ?? 0]);
    if ((int) $st->fetchColumn() > 0) {
        throw new KaraokeError("Ya existe la mesa $number.", 409, 'table_exists');
    }
    $now = karaoke_now();
    if ($id) {
        $st = $pdo->prepare('UPDATE karaoke_tables SET number = ?, name = ?, updated_at = ? WHERE id = ?');
        $st->execute([$number, $name, $now, $id]);
        if ($st->rowCount() === 0 && !karaoke_table($pdo, $id)) {
            throw new KaraokeError('La mesa ya no existe.', 404, 'not_found');
        }
        return $id;
    }
    $pdo->prepare('INSERT INTO karaoke_tables (number, name, qr_token, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)')
        ->execute([$number, $name, karaoke_new_token(), $now, $now]);
    return (int) $pdo->lastInsertId();
}

function karaoke_table(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM karaoke_tables WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function karaoke_table_by_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM karaoke_tables WHERE qr_token = ?');
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** La noche abierta, o null. Una noche olvidada abierta caduca a las KARAOKE_NIGHT_MAX_H horas. */
function karaoke_current_night(PDO $pdo): ?array
{
    $st = $pdo->prepare('SELECT * FROM karaoke_nights WHERE closes_at IS NULL AND opens_at > ? ORDER BY id DESC LIMIT 1');
    $st->execute([karaoke_now(-KARAOKE_NIGHT_MAX_H * 3600)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Abre una noche nueva con un código de 4 cifras; cierra la anterior si seguía abierta. */
function karaoke_night_open(PDO $pdo, ?int $adminId = null): array
{
    return karaoke_tx($pdo, static function () use ($pdo, $adminId): array {
        karaoke_night_close($pdo, 'Empezó una noche nueva.');
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO karaoke_nights (night_code, opens_at, opened_by) VALUES (?, ?, ?)')->execute([$code, karaoke_now(), $adminId]);
        return karaoke_current_night($pdo);
    });
}

/** Cambia solo el código (si se filtró); los celulares lo vuelven a pedir. */
function karaoke_night_rotate_code(PDO $pdo): array
{
    $night = karaoke_current_night($pdo) ?? throw new KaraokeError('No hay una noche abierta.', 409, 'no_night');
    do {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    } while ($code === $night['night_code']);
    $pdo->prepare('UPDATE karaoke_nights SET night_code = ? WHERE id = ?')->execute([$code, $night['id']]);
    return karaoke_current_night($pdo);
}

/** Cierra las noches abiertas y cancela lo que no llegó a KaraFun. */
function karaoke_night_close(PDO $pdo, string $reason = 'La noche de karaoke terminó.'): int
{
    return karaoke_tx($pdo, static function () use ($pdo, $reason): int {
        $ids = $pdo->query('SELECT id FROM karaoke_nights WHERE closes_at IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        $cancelled = 0;
        foreach ($ids as $nid) {
            $st = $pdo->prepare('SELECT id, status FROM karaoke_requests WHERE night_id = ? AND status IN (?, ?, ?)');
            $st->execute(array_merge([$nid], KARAOKE_WAITING));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cancelled += (int) karaoke_transition($pdo, $r['id'], $r['status'], 'cancelado', 'sistema', $reason);
            }
            $pdo->prepare('UPDATE karaoke_nights SET closes_at = ? WHERE id = ?')->execute([karaoke_now(), $nid]);
        }
        return $cancelled;
    });
}

/**
 * Valida QR + código de la noche. $limiterKey identifica al cliente (hash de la IP) para frenar
 * a quien prueba códigos al azar.
 */
function karaoke_session(PDO $pdo, string $token, string $code, string $limiterKey = ''): array
{
    $table = karaoke_table_by_token($pdo, $token);
    if (!$table) {
        throw new KaraokeError('Este código QR no es válido. Pide ayuda en la barra.', 404, 'bad_table', 'QR no válido');
    }
    if (!(int) $table['is_active']) {
        throw new KaraokeError('Esta mesa no tiene pedidos de karaoke activos. Pide ayuda en la barra.', 403, 'table_inactive', 'Mesa inactiva');
    }
    $night = karaoke_current_night($pdo);
    if (!$night) {
        throw new KaraokeError('El karaoke por mesa no está abierto en este momento.', 409, 'no_night', 'Karaoke cerrado');
    }
    $bucket = 'code:' . $limiterKey;
    if ($limiterKey !== '' && karaoke_rate_count($pdo, $bucket, 600) >= 10) {
        throw new KaraokeError('Demasiados intentos con un código equivocado. Espera unos minutos.', 429, 'rate_code', 'Espera un momento');
    }
    if (!preg_match('/^\d{4}$/', $code) || !hash_equals((string) $night['night_code'], $code)) {
        if ($limiterKey !== '') {
            karaoke_rate_hit($pdo, $bucket);
        }
        throw new KaraokeError('El código de la noche no coincide. Míralo en la pantalla del karaoke.', 403, 'bad_code', 'Código equivocado');
    }
    return ['table' => $table, 'night' => $night];
}

// ---------------------------------------------------------------------------
// Límites de abuso (§11).

function karaoke_rate_count(PDO $pdo, string $bucket, int $windowS): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_rate_events WHERE bucket = ? AND at > ?');
    $st->execute([substr($bucket, 0, 80), karaoke_now(-$windowS)]);
    return (int) $st->fetchColumn();
}

function karaoke_rate_hit(PDO $pdo, string $bucket): void
{
    $pdo->prepare('INSERT INTO karaoke_rate_events (bucket, at) VALUES (?, ?)')->execute([substr($bucket, 0, 80), karaoke_now()]);
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM karaoke_rate_events WHERE at < ?')->execute([karaoke_now(-86400)]);
    }
}

/** Huella de la IP para los límites: no se guarda la IP (§11, sin datos personales). */
function karaoke_client_key(PDO $pdo, string $ip): string
{
    $salt = karaoke_setting($pdo, 'karaoke_salt');
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        karaoke_save_setting($pdo, 'karaoke_salt', $salt);
    }
    return substr(hash_hmac('sha256', $ip, $salt), 0, 32);
}

// ---------------------------------------------------------------------------
// Catálogo y búsqueda.

function karaoke_search(PDO $pdo, string $q, int $limit = 30): array
{
    $terms = array_slice(array_filter(explode(' ', karaoke_normalize(mb_substr($q, 0, 80)))), 0, 6);
    if (!$terms) {
        return [];
    }
    $where = implode(' AND ', array_fill(0, count($terms), 'search_text LIKE ?'));
    // Los términos normalizados solo tienen a-z0-9: no hay comodines de LIKE que escapar.
    $params = array_map(static fn (string $t): string => '%' . $t . '%', $terms);
    $st = $pdo->prepare("SELECT id, title, artist, duration_s, folder FROM karaoke_songs WHERE available = 1 AND $where ORDER BY artist, title LIMIT " . max(1, min(50, $limit)));
    $st->execute($params);
    return array_map(static fn (array $s): array => [
        'id' => (int) $s['id'],
        'title' => $s['title'],
        'artist' => $s['artist'],
        'duration_s' => (int) $s['duration_s'],
        'from_youtube' => karaoke_is_por_aprobar($s['folder']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Valida una canción en el formato del contrato. Devuelve la fila limpia. */
function karaoke_clean_song(mixed $s, string $where = 'song'): array
{
    if (!is_array($s)) {
        throw new KaraokeError("$where debe ser un objeto.", 422, 'bad_song');
    }
    $key = $s['natural_key'] ?? null;
    if (!is_string($key) || !karaoke_valid_natural_key($key)) {
        throw new KaraokeError("$where.natural_key no cumple el formato artista|título|duración normalizado.", 422, 'bad_song');
    }
    $title = $s['title'] ?? null;
    $artist = $s['artist'] ?? '';
    $dur = $s['duration_s'] ?? null;
    if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
        throw new KaraokeError("$where.title es obligatorio (máximo 200 caracteres).", 422, 'bad_song');
    }
    if (!is_string($artist) || mb_strlen($artist) > 200) {
        throw new KaraokeError("$where.artist admite máximo 200 caracteres.", 422, 'bad_song');
    }
    if (!is_int($dur) && !(is_float($dur) && floor($dur) === $dur) || $dur < 0 || $dur > 36000) {
        throw new KaraokeError("$where.duration_s debe ser un entero de segundos.", 422, 'bad_song');
    }
    foreach (['folder' => 120, 'file' => 500] as $f => $max) {
        if (isset($s[$f]) && (!is_string($s[$f]) || mb_strlen($s[$f]) > $max)) {
            throw new KaraokeError("$where.$f admite máximo $max caracteres.", 422, 'bad_song');
        }
    }
    $kf = $s['kf_id'] ?? null;
    return [
        'natural_key' => $key,
        'title' => trim($title),
        'artist' => trim($artist),
        'duration_s' => (int) $dur,
        'folder' => isset($s['folder']) && $s['folder'] !== '' ? $s['folder'] : null,
        'file' => isset($s['file']) && $s['file'] !== '' ? $s['file'] : null,
        'kf_id' => is_int($kf) ? $kf : null,
        // La nube no recalcula la clave, pero avisa si el agente la calculó distinto (contrato §2).
        'key_matches' => karaoke_natural_key(trim($artist), trim($title), (int) $dur) === $key,
    ];
}

/** Inserta o actualiza una canción por natural_key. Devuelve su id. */
function karaoke_song_upsert(PDO $pdo, array $song, ?string $seenAt = null): int
{
    $now = karaoke_now();
    $seenAt ??= $now;
    $search = mb_substr(karaoke_normalize($song['artist'] . ' ' . $song['title']), 0, 420);
    $st = $pdo->prepare('SELECT id FROM karaoke_songs WHERE natural_key = ?');
    $st->execute([$song['natural_key']]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        $pdo->prepare('UPDATE karaoke_songs SET title = ?, artist = ?, duration_s = ?, search_text = ?, kf_id = ?, folder = ?, file = ?, available = 1, seen_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$song['title'], $song['artist'], $song['duration_s'], $search, $song['kf_id'] ?? null, $song['folder'], $song['file'], $seenAt, $now, $id]);
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO karaoke_songs (source, natural_key, title, artist, duration_s, search_text, kf_id, folder, file, available, seen_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)')
        ->execute(['local', $song['natural_key'], $song['title'], $song['artist'], $song['duration_s'], $search, $song['kf_id'] ?? null, $song['folder'], $song['file'], $seenAt, $now, $now]);
    return (int) $pdo->lastInsertId();
}

function karaoke_catalog_begin(PDO $pdo): array
{
    // Sincronizaciones abandonadas hace más de un día: fuera su staging.
    $old = $pdo->prepare("SELECT id FROM karaoke_catalog_syncs WHERE status = 'open' AND started_at < ?");
    $old->execute([karaoke_now(-86400)]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $pdo->prepare('DELETE FROM karaoke_catalog_staging WHERE sync_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM karaoke_catalog_chunks WHERE sync_id = ?')->execute([$sid]);
        $pdo->prepare("UPDATE karaoke_catalog_syncs SET status = 'abandoned' WHERE id = ?")->execute([$sid]);
    }
    $id = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO karaoke_catalog_syncs (id, status, started_at) VALUES (?, 'open', ?)")->execute([$id, karaoke_now()]);
    return ['sync_id' => $id];
}

function karaoke_open_sync(PDO $pdo, mixed $syncId): array
{
    if (!is_string($syncId) || !preg_match('/^[0-9a-f]{32}$/', $syncId)) {
        throw new KaraokeError('sync_id no válido.', 422, 'bad_sync');
    }
    $st = $pdo->prepare('SELECT * FROM karaoke_catalog_syncs WHERE id = ?');
    $st->execute([$syncId]);
    $sync = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sync) {
        throw new KaraokeError('La sincronización no existe. Empieza con catalog.begin.', 404, 'unknown_sync');
    }
    return $sync;
}

/** Un lote: idempotente por sync_id + index (repetirlo reemplaza el mismo lote). */
function karaoke_catalog_chunk(PDO $pdo, array $body): array
{
    $sync = karaoke_open_sync($pdo, $body['sync_id'] ?? null);
    if ($sync['status'] !== 'open') {
        throw new KaraokeError('Esa sincronización ya terminó.', 409, 'sync_closed');
    }
    $index = $body['index'] ?? null;
    $songs = $body['songs'] ?? null;
    if (!is_int($index) || $index < 0 || $index > 10000) {
        throw new KaraokeError('index debe ser un entero desde 0.', 422, 'bad_chunk');
    }
    if (!is_array($songs) || !array_is_list($songs) || count($songs) > KARAOKE_CHUNK_MAX) {
        throw new KaraokeError('songs debe ser una lista de hasta ' . KARAOKE_CHUNK_MAX . ' canciones.', 422, 'bad_chunk');
    }
    $clean = [];
    $mismatches = [];
    foreach ($songs as $i => $s) {
        $c = karaoke_clean_song($s, "songs[$i]");
        if (!$c['key_matches'] && count($mismatches) < 5) {
            $mismatches[] = $c['natural_key'];
        }
        $clean[] = $c;
    }
    karaoke_tx($pdo, static function () use ($pdo, $sync, $index, $clean): void {
        $pdo->prepare('DELETE FROM karaoke_catalog_staging WHERE sync_id = ? AND chunk_index = ?')->execute([$sync['id'], $index]);
        $pdo->prepare('DELETE FROM karaoke_catalog_chunks WHERE sync_id = ? AND chunk_index = ?')->execute([$sync['id'], $index]);
        $ins = $pdo->prepare('INSERT INTO karaoke_catalog_staging (sync_id, chunk_index, natural_key, title, artist, duration_s, folder, file) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($clean as $c) {
            $ins->execute([$sync['id'], $index, $c['natural_key'], $c['title'], $c['artist'], $c['duration_s'], $c['folder'], $c['file']]);
        }
        $pdo->prepare('INSERT INTO karaoke_catalog_chunks (sync_id, chunk_index, song_count, received_at) VALUES (?, ?, ?, ?)')
            ->execute([$sync['id'], $index, count($clean), karaoke_now()]);
    });
    $r = ['ok' => true, 'index' => $index, 'received' => count($clean)];
    if ($mismatches) {
        $r['warnings'] = ['natural_key_mismatch' => $mismatches];
    }
    return $r;
}

/**
 * Activa la versión nueva en una transacción: las canciones que vinieron quedan disponibles y
 * las que no (y no se subieron sueltas durante la sincronización) pasan a available = 0.
 */
function karaoke_catalog_commit(PDO $pdo, array $body): array
{
    $sync = karaoke_open_sync($pdo, $body['sync_id'] ?? null);
    if ($sync['status'] === 'committed') {
        return json_decode((string) $sync['summary'], true) + ['already_committed' => true];
    }
    if ($sync['status'] !== 'open') {
        throw new KaraokeError('Esa sincronización se abandonó. Empieza otra con catalog.begin.', 409, 'sync_closed');
    }
    $chunks = $body['total_chunks'] ?? null;
    $total = $body['total_songs'] ?? null;
    if (!is_int($chunks) || $chunks < 1 || !is_int($total) || $total < 0) {
        throw new KaraokeError('total_chunks y total_songs deben ser enteros.', 422, 'bad_commit');
    }
    $st = $pdo->prepare('SELECT chunk_index, song_count FROM karaoke_catalog_chunks WHERE sync_id = ? ORDER BY chunk_index');
    $st->execute([$sync['id']]);
    $got = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $missing = array_values(array_diff(range(0, $chunks - 1), array_map('intval', array_keys($got))));
    $extra = array_values(array_filter(array_map('intval', array_keys($got)), static fn (int $i): bool => $i >= $chunks));
    if ($missing || $extra) {
        throw new KaraokeError('Faltan lotes: ' . implode(', ', array_slice($missing ?: $extra, 0, 20)) . '. No se activó el catálogo.', 409, 'missing_chunks');
    }
    $received = array_sum(array_map('intval', $got));
    if ($received !== $total) {
        throw new KaraokeError("Llegaron $received canciones y se anunciaron $total. No se activó el catálogo.", 409, 'count_mismatch');
    }

    return karaoke_tx($pdo, static function () use ($pdo, $sync, $chunks, $total): array {
        $now = karaoke_now();
        $rows = $pdo->prepare('SELECT natural_key, title, artist, duration_s, folder, file FROM karaoke_catalog_staging WHERE sync_id = ? ORDER BY chunk_index, id');
        $rows->execute([$sync['id']]);
        // Una misma canción en varios archivos: gana la que ya está en su carpeta de letra.
        $byKey = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $prev = $byKey[$r['natural_key']] ?? null;
            if ($prev === null || (karaoke_is_por_aprobar($prev['folder']) && !karaoke_is_por_aprobar($r['folder']))) {
                $byKey[$r['natural_key']] = $r;
            }
        }
        $existing = $pdo->query('SELECT natural_key, available FROM karaoke_songs')->fetchAll(PDO::FETCH_KEY_PAIR);
        $added = 0;
        foreach ($byKey as $key => $r) {
            if (!array_key_exists($key, $existing)) {
                $added++;
            }
            karaoke_song_upsert($pdo, ['natural_key' => (string) $key, 'title' => $r['title'], 'artist' => $r['artist'], 'duration_s' => (int) $r['duration_s'], 'folder' => $r['folder'], 'file' => $r['file'], 'kf_id' => null], $now);
        }
        $gone = $pdo->prepare("UPDATE karaoke_songs SET available = 0, updated_at = ? WHERE source = 'local' AND available = 1 AND seen_at < ?");
        $gone->execute([$now, $sync['started_at']]);
        $summary = [
            'ok' => true,
            'total_songs' => $total,
            'distinct' => count($byKey),
            'added' => $added,
            'unavailable' => $gone->rowCount(),
            'por_aprobar' => karaoke_count_por_aprobar($pdo),
        ];
        $pdo->prepare("UPDATE karaoke_catalog_syncs SET status = 'committed', committed_at = ?, total_chunks = ?, total_songs = ?, summary = ? WHERE id = ?")
            ->execute([$now, $chunks, $total, json_encode($summary), $sync['id']]);
        $pdo->prepare('DELETE FROM karaoke_catalog_staging WHERE sync_id = ?')->execute([$sync['id']]);
        karaoke_agent_row($pdo);
        $pdo->prepare('UPDATE karaoke_agent SET last_sync_at = ?, last_sync_songs = ? WHERE id = 1')->execute([$now, count($byKey)]);
        return $summary;
    });
}

function karaoke_count_por_aprobar(PDO $pdo): int
{
    $n = 0;
    foreach ($pdo->query('SELECT folder, COUNT(*) AS n FROM karaoke_songs WHERE available = 1 AND folder IS NOT NULL GROUP BY folder')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (karaoke_is_por_aprobar($r['folder'])) {
            $n += (int) $r['n'];
        }
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Pedidos de las mesas.

function karaoke_clean_singer(mixed $v): string
{
    $s = is_string($v) ? $v : '';
    // Sin caracteres de control ni el separador del marcador ("·"), espacios simples.
    $s = (string) preg_replace('/[\p{C}·|<>]+/u', ' ', $s);
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    if ($s === '') {
        throw new KaraokeError('Escribe el nombre de quien va a cantar.', 422, 'singer_empty');
    }
    if (mb_strlen($s) > KARAOKE_SINGER_MAX) {
        throw new KaraokeError('El nombre admite máximo ' . KARAOKE_SINGER_MAX . ' caracteres.', 422, 'singer_long');
    }
    return $s;
}

function karaoke_marker(array $table, string $requestId): string
{
    return 'M' . (int) $table['number'] . '·' . substr(str_replace('-', '', $requestId), 0, 3);
}

/** Lo que ve KaraFun en el campo cantante: «Ana · M7·k3f». */
function karaoke_singer_label(array $request): string
{
    return $request['singer'] . ' · ' . $request['marker'];
}

/**
 * Orden justo (§7): rotación entre mesas. El turno de una mesa nueva entra en la ronda que se está
 * cantando; cada pedido siguiente de la misma mesa va a la ronda siguiente. Dentro de una ronda,
 * por orden de llegada. fair_seq = ronda * 1e6 + llegada.
 */
function karaoke_fair_seq(PDO $pdo, int $nightId, int $tableId): int
{
    $st = $pdo->prepare('SELECT MAX(fair_seq) FROM karaoke_requests WHERE night_id = ? AND table_id = ? AND status NOT IN (?, ?)');
    $st->execute([$nightId, $tableId, 'cancelado', 'fallido']);
    $lastTable = intdiv((int) $st->fetchColumn(), 1_000_000);
    $st = $pdo->prepare('SELECT MAX(fair_seq) FROM karaoke_requests WHERE night_id = ? AND status IN (?, ?, ?, ?, ?)');
    $st->execute([$nightId, 'enviado', 'en_cola', 'cantando', 'cantada', 'retirado']);
    $floor = intdiv((int) $st->fetchColumn(), 1_000_000);
    $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_requests WHERE night_id = ?');
    $st->execute([$nightId]);
    $arrival = (int) $st->fetchColumn() + 1;
    return max($floor, $lastTable + 1, 1) * 1_000_000 + min($arrival, 999_999);
}

/**
 * Crea un pedido. $input: id (uuid del celular), singer, y song_id o youtube_url.
 * $oembed: callable(string $id): array{exists: ?bool, title: ?string}. $clientKey: huella de la IP.
 */
function karaoke_request_create(PDO $pdo, array $table, array $night, array $input, ?callable $oembed = null, string $clientKey = ''): array
{
    $id = strtolower(trim((string) ($input['id'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
        throw new KaraokeError('Identificador de pedido no válido. Recarga la página.', 422, 'bad_id');
    }
    $existing = karaoke_request($pdo, $id);
    if ($existing) {
        // Reintento del mismo pedido (la red falló): se devuelve tal cual, sin duplicarlo.
        if ((int) $existing['table_id'] === (int) $table['id'] && (int) $existing['night_id'] === (int) $night['id']) {
            return $existing;
        }
        throw new KaraokeError('Ese pedido ya existe.', 409, 'id_taken');
    }
    $singer = karaoke_clean_singer($input['singer'] ?? '');
    $songId = $input['song_id'] ?? null;
    $url = $input['youtube_url'] ?? null;
    if (($songId === null) === ($url === null)) {
        throw new KaraokeError('Elige una canción del catálogo o pega un enlace de YouTube.', 422, 'no_song');
    }

    $max = karaoke_setting_int($pdo, 'karaoke_max_pending');
    $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_requests WHERE night_id = ? AND table_id = ? AND status IN (?, ?, ?)');
    $st->execute(array_merge([$night['id'], $table['id']], KARAOKE_WAITING));
    if ((int) $st->fetchColumn() >= $max) {
        throw new KaraokeError("Tu mesa ya tiene $max canciones esperando. Pide otra cuando suene una de las tuyas.", 429, 'too_many_pending', 'Espera tu turno');
    }
    if (karaoke_rate_count($pdo, 'req:t:' . $table['id'], 60) >= karaoke_setting_int($pdo, 'karaoke_rate_table')
        || ($clientKey !== '' && karaoke_rate_count($pdo, 'req:ip:' . $clientKey, 60) >= karaoke_setting_int($pdo, 'karaoke_rate_ip'))) {
        throw new KaraokeError('Vas muy rápido. Espera un minuto antes de pedir otra canción.', 429, 'rate_request', 'Espera un momento');
    }

    $song = null;
    $youtubeId = null;
    $download = null;
    $title = null;
    if ($songId !== null) {
        $st = $pdo->prepare('SELECT * FROM karaoke_songs WHERE id = ? AND available = 1');
        $st->execute([(int) $songId]);
        $song = $st->fetch(PDO::FETCH_ASSOC) ?: throw new KaraokeError('Esa canción ya no está disponible. Búscala de nuevo.', 404, 'song_gone');
    } else {
        $youtubeId = karaoke_parse_youtube((string) $url);
        $download = karaoke_download($pdo, $youtubeId);
        $reusable = $download && $download['status'] === 'done' && $download['song_id'] && karaoke_song_available($pdo, (int) $download['song_id']);
        if (!$reusable && !($download && $download['status'] === 'downloading')) {
            $check = ($oembed ?? 'karaoke_youtube_oembed')($youtubeId);
            if (($check['exists'] ?? null) === false) {
                throw new KaraokeError('Ese video no existe o es privado. Prueba con otro enlace.', 422, 'youtube_missing');
            }
            $title = $check['title'] ?? null;
        }
    }

    $marker = karaoke_marker($table, $id);
    return karaoke_tx($pdo, static function () use ($pdo, $table, $night, $id, $singer, $song, $youtubeId, $download, $title, $marker, $clientKey): array {
        $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_requests WHERE night_id = ? AND marker = ? AND status NOT IN (?, ?, ?, ?)');
        $st->execute(array_merge([$night['id'], $marker], KARAOKE_FINAL));
        if ((int) $st->fetchColumn() > 0) {
            // El celular genera otro uuid y reintenta: el marcador debe ser único entre los pedidos vivos.
            throw new KaraokeError('Intenta de nuevo.', 409, 'marker_collision');
        }
        $songId = $song ? (int) $song['id'] : null;
        $status = 'en_espera';
        $needsDownload = false;
        if ($youtubeId !== null) {
            $download = karaoke_download($pdo, $youtubeId);
            if ($download && $download['status'] === 'done' && $download['song_id'] && karaoke_song_available($pdo, (int) $download['song_id'])) {
                $songId = (int) $download['song_id'];
            } else {
                $status = 'descargando';
                $needsDownload = !$download || $download['status'] !== 'downloading';
            }
        }
        $now = karaoke_now();
        $pdo->prepare('INSERT INTO karaoke_requests (id, night_id, table_id, singer, marker, song_id, youtube_id, status, fair_seq, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $night['id'], $table['id'], $singer, $marker, $songId, $youtubeId, $status, karaoke_fair_seq($pdo, (int) $night['id'], (int) $table['id']), $now, $now]);
        karaoke_log($pdo, $id, null, $status, 'mesa', $youtubeId !== null ? "YouTube $youtubeId" : null);
        if ($needsDownload) {
            if ($download) {
                $pdo->prepare("UPDATE karaoke_downloads SET status = 'downloading', song_id = NULL, error = NULL, requested_by = ?, title = COALESCE(?, title), updated_at = ? WHERE id = ?")
                    ->execute([$id, $title, $now, $download['id']]);
            } else {
                $pdo->prepare("INSERT INTO karaoke_downloads (youtube_id, status, title, requested_by, created_at, updated_at) VALUES (?, 'downloading', ?, ?, ?, ?)")
                    ->execute([$youtubeId, $title, $id, $now, $now]);
            }
            karaoke_command_create($pdo, 'download', ['request_id' => $id, 'youtube_id' => $youtubeId, 'max_duration_s' => KARAOKE_MAX_VIDEO_S], $id);
        }
        karaoke_rate_hit($pdo, 'req:t:' . $table['id']);
        if ($clientKey !== '') {
            karaoke_rate_hit($pdo, 'req:ip:' . $clientKey);
        }
        return karaoke_request($pdo, $id);
    });
}

function karaoke_download(PDO $pdo, string $youtubeId): ?array
{
    $st = $pdo->prepare('SELECT * FROM karaoke_downloads WHERE youtube_id = ?');
    $st->execute([$youtubeId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function karaoke_song_available(PDO $pdo, int $songId): bool
{
    $st = $pdo->prepare('SELECT available FROM karaoke_songs WHERE id = ?');
    $st->execute([$songId]);
    return (int) $st->fetchColumn() === 1;
}

/** Cancela un pedido que aún no llegó a KaraFun. $tableId limita a los pedidos de esa mesa. */
function karaoke_request_cancel(PDO $pdo, string $id, string $actor, ?int $tableId = null): array
{
    $r = karaoke_request($pdo, $id);
    if (!$r || ($tableId !== null && (int) $r['table_id'] !== $tableId)) {
        throw new KaraokeError('El pedido no existe.', 404, 'not_found');
    }
    if (!in_array($r['status'], KARAOKE_WAITING, true)) {
        throw new KaraokeError($r['status'] === 'cancelado' ? 'El pedido ya estaba cancelado.' : 'Ya no se puede cancelar: la canción ya está en KaraFun.', 409, 'not_cancellable');
    }
    if (!karaoke_transition($pdo, $id, $r['status'], 'cancelado', $actor, $actor === 'mesa' ? 'Cancelado por la mesa.' : 'Cancelado por el encargado.')) {
        throw new KaraokeError('El pedido cambió mientras tanto. Recarga e inténtalo de nuevo.', 409, 'conflict');
    }
    return karaoke_request($pdo, $id);
}

/** Quita de KaraFun una canción enviada o en cola (orden remove al agente). */
function karaoke_request_remove(PDO $pdo, string $id): void
{
    $r = karaoke_request($pdo, $id);
    if (!$r || !in_array($r['status'], ['enviado', 'en_cola'], true)) {
        throw new KaraokeError('Solo se puede quitar una canción enviada o en cola.', 409, 'not_removable');
    }
    karaoke_tx($pdo, static function () use ($pdo, $r): void {
        $st = $pdo->prepare("SELECT COUNT(*) FROM karaoke_commands WHERE request_id = ? AND type = 'remove' AND status IN ('pending', 'leased')");
        $st->execute([$r['id']]);
        if ((int) $st->fetchColumn() === 0) {
            karaoke_command_create($pdo, 'remove', ['request_id' => $r['id'], 'singer' => karaoke_singer_label($r)], $r['id']);
        }
    });
}

/** Lista de espera de la noche en orden de turno (pedidos que aún no llegaron a KaraFun). */
function karaoke_waiting(PDO $pdo, int $nightId): array
{
    $st = $pdo->prepare('SELECT r.*, t.number AS table_number, t.name AS table_name, s.title, s.artist, s.duration_s, d.title AS yt_title
        FROM karaoke_requests r
        JOIN karaoke_tables t ON t.id = r.table_id
        LEFT JOIN karaoke_songs s ON s.id = r.song_id
        LEFT JOIN karaoke_downloads d ON d.youtube_id = r.youtube_id
        WHERE r.night_id = ? AND r.status IN (?, ?, ?)
        ORDER BY r.fair_seq, r.created_at, r.id');
    $st->execute(array_merge([$nightId], KARAOKE_WAITING));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Sube o baja un pedido en la lista de espera (intercambia el turno con el vecino). */
function karaoke_request_move(PDO $pdo, string $id, int $dir): void
{
    karaoke_tx($pdo, static function () use ($pdo, $id, $dir): void {
        $r = karaoke_request($pdo, $id);
        if (!$r || !in_array($r['status'], KARAOKE_WAITING, true)) {
            throw new KaraokeError('Ese pedido ya no está en espera.', 409, 'not_waiting');
        }
        $list = karaoke_waiting($pdo, (int) $r['night_id']);
        $ids = array_column($list, 'id');
        $i = array_search($id, $ids, true);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($list)) {
            return;
        }
        $a = $list[$i];
        $b = $list[$j];
        $seqA = (int) $a['fair_seq'];
        $seqB = (int) $b['fair_seq'];
        if ($seqA === $seqB) {
            $seqB += $dir < 0 ? -1 : 1;
        }
        $up = $pdo->prepare('UPDATE karaoke_requests SET fair_seq = ?, updated_at = ? WHERE id = ?');
        $up->execute([$seqB, karaoke_now(), $a['id']]);
        $up->execute([$seqA, karaoke_now(), $b['id']]);
    });
}

/** Pedidos de una mesa en la noche, con su turno estimado. */
function karaoke_table_requests(PDO $pdo, int $nightId, int $tableId): array
{
    $agent = karaoke_agent_row($pdo);
    $queue = json_decode((string) ($agent['queue_snapshot'] ?? '[]'), true) ?: [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM karaoke_requests WHERE status = 'enviado'");
    $st->execute();
    $ahead = count($queue) + (int) $st->fetchColumn();
    $aheadS = $ahead * KARAOKE_AVG_SONG_S;
    $turn = [];
    foreach (karaoke_waiting($pdo, $nightId) as $w) {
        $turn[$w['id']] = ['ahead' => $ahead, 'eta_min' => (int) ceil($aheadS / 60)];
        $ahead++;
        $aheadS += (int) ($w['duration_s'] ?: KARAOKE_AVG_SONG_S);
    }
    $st = $pdo->prepare('SELECT r.id, r.singer, r.status, r.error, r.youtube_id, r.kf_queue_pos, r.created_at, s.title, s.artist, d.title AS yt_title
        FROM karaoke_requests r
        LEFT JOIN karaoke_songs s ON s.id = r.song_id
        LEFT JOIN karaoke_downloads d ON d.youtube_id = r.youtube_id
        WHERE r.night_id = ? AND r.table_id = ?
        ORDER BY r.created_at DESC, r.id');
    $st->execute([$nightId, $tableId]);
    return array_map(static fn (array $r): array => [
        'id' => $r['id'],
        'singer' => $r['singer'],
        'status' => $r['status'],
        'title' => $r['title'] ?? $r['yt_title'] ?? ($r['youtube_id'] ? 'Video de YouTube' : 'Canción'),
        'artist' => $r['artist'],
        'from_youtube' => $r['youtube_id'] !== null,
        'error' => $r['error'],
        'queue_pos' => $r['kf_queue_pos'] === null ? null : (int) $r['kf_queue_pos'],
        'turn' => $turn[$r['id']] ?? null,
        'cancellable' => in_array($r['status'], KARAOKE_WAITING, true),
        'created_at' => karaoke_iso($r['created_at']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------------------
// Outbox de órdenes hacia el agente (§8).

function karaoke_command_create(PDO $pdo, string $type, array $payload, ?string $requestId = null): int
{
    $now = karaoke_now();
    $pdo->prepare("INSERT INTO karaoke_commands (request_id, type, payload, status, attempts, created_at, updated_at) VALUES (?, ?, ?, 'pending', 0, ?, ?)")
        ->execute([$requestId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
    return (int) $pdo->lastInsertId();
}

function karaoke_command(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM karaoke_commands WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Consecuencias de una orden que falló del todo: el pedido pasa a fallido con el motivo. */
function karaoke_command_failed(PDO $pdo, array $cmd, string $message): void
{
    $payload = json_decode((string) $cmd['payload'], true) ?: [];
    if ($cmd['type'] === 'download') {
        $yt = (string) ($payload['youtube_id'] ?? '');
        $pdo->prepare("UPDATE karaoke_downloads SET status = 'failed', error = ?, updated_at = ? WHERE youtube_id = ? AND status = 'downloading'")
            ->execute([mb_substr($message, 0, 255), karaoke_now(), $yt]);
        $st = $pdo->prepare("SELECT id FROM karaoke_requests WHERE youtube_id = ? AND status = 'descargando'");
        $st->execute([$yt]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            karaoke_transition($pdo, $rid, 'descargando', 'fallido', 'agente', $message);
        }
    } elseif ($cmd['type'] === 'enqueue' && $cmd['request_id']) {
        karaoke_transition($pdo, $cmd['request_id'], 'enviado', 'fallido', 'agente', $message);
    }
}

/**
 * Entrega hasta 10 órdenes pendientes o con lease vencido, por id, y las arrienda 30 s.
 * Una orden que agotó sus 5 intentos sin confirmación pasa a failed.
 */
function karaoke_lease_commands(PDO $pdo): array
{
    $now = karaoke_now();
    $st = $pdo->prepare("SELECT * FROM karaoke_commands WHERE status = 'pending' OR (status = 'leased' AND lease_until < ?) ORDER BY id LIMIT " . (KARAOKE_POLL_MAX_COMMANDS * 3));
    $st->execute([$now]);
    $out = [];
    $leaseUntil = karaoke_now(KARAOKE_LEASE_S);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (count($out) >= KARAOKE_POLL_MAX_COMMANDS) {
            break;
        }
        if ((int) $c['attempts'] >= KARAOKE_MAX_ATTEMPTS) {
            karaoke_tx($pdo, static function () use ($pdo, $c, $now): void {
                $up = $pdo->prepare("UPDATE karaoke_commands SET status = 'failed', error = ?, updated_at = ?, done_at = ? WHERE id = ? AND status = ?");
                $up->execute(['El agente no confirmó la orden tras ' . KARAOKE_MAX_ATTEMPTS . ' intentos.', $now, $now, $c['id'], $c['status']]);
                if ($up->rowCount() === 1) {
                    karaoke_command_failed($pdo, $c, 'El bar no pudo procesar el pedido. Pide ayuda en la barra.');
                }
            });
            continue;
        }
        $up = $pdo->prepare("UPDATE karaoke_commands SET status = 'leased', lease_until = ?, attempts = attempts + 1, updated_at = ?
            WHERE id = ? AND (status = 'pending' OR (status = 'leased' AND lease_until < ?))");
        $up->execute([$leaseUntil, $now, $c['id'], $now]);
        if ($up->rowCount() === 1) {
            $out[] = [
                'id' => (int) $c['id'],
                'type' => $c['type'],
                'lease_until' => karaoke_iso($leaseUntil),
                'payload' => json_decode((string) $c['payload'], true) ?: new stdClass(),
            ];
        }
    }
    return $out;
}

/** ack: idempotente. Un segundo ack de una orden terminada responde sin cambiar nada. */
function karaoke_agent_ack(PDO $pdo, array $body): array
{
    $id = $body['command_id'] ?? null;
    if (!is_int($id) || $id < 1) {
        throw new KaraokeError('command_id debe ser un entero.', 422, 'bad_ack');
    }
    if (!is_bool($body['ok'] ?? null)) {
        throw new KaraokeError('ok debe ser true o false.', 422, 'bad_ack');
    }
    $ok = $body['ok'];
    $cmd = karaoke_command($pdo, $id) ?? throw new KaraokeError('La orden no existe.', 404, 'unknown_command');
    if (in_array($cmd['status'], ['done', 'failed'], true)) {
        return ['ok' => true, 'command_id' => $id, 'status' => $cmd['status'], 'duplicate' => true];
    }
    $result = $body['result'] ?? new stdClass();
    if (!is_array($result) && !$result instanceof stdClass) {
        throw new KaraokeError('result debe ser un objeto.', 422, 'bad_ack');
    }
    $result = (array) $result;

    if (!$ok) {
        $err = $body['error'] ?? [];
        $message = is_array($err) && is_string($err['message'] ?? null) && trim($err['message']) !== '' ? mb_substr(trim($err['message']), 0, 255) : 'El bar no pudo procesar el pedido.';
        $code = is_array($err) && is_string($err['code'] ?? null) ? mb_substr($err['code'], 0, 40) : 'error';
        $retryable = ($body['retryable'] ?? false) === true || (is_array($err) && ($err['retryable'] ?? false) === true);
        return karaoke_tx($pdo, static function () use ($pdo, $cmd, $id, $message, $code, $retryable): array {
            $now = karaoke_now();
            $errJson = json_encode(['code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
            if ($retryable && (int) $cmd['attempts'] < KARAOKE_MAX_ATTEMPTS) {
                $pdo->prepare("UPDATE karaoke_commands SET status = 'pending', lease_until = NULL, error = ?, updated_at = ? WHERE id = ? AND status IN ('pending', 'leased')")
                    ->execute([$errJson, $now, $id]);
                return ['ok' => true, 'command_id' => $id, 'status' => 'pending'];
            }
            $up = $pdo->prepare("UPDATE karaoke_commands SET status = 'failed', error = ?, updated_at = ?, done_at = ? WHERE id = ? AND status IN ('pending', 'leased')");
            $up->execute([$errJson, $now, $now, $id]);
            if ($up->rowCount() === 1) {
                karaoke_command_failed($pdo, $cmd, $message);
            }
            return ['ok' => true, 'command_id' => $id, 'status' => 'failed'];
        });
    }

    $song = null;
    if ($cmd['type'] === 'download') {
        $song = karaoke_clean_song($result['song'] ?? null, 'result.song');
    }
    return karaoke_tx($pdo, static function () use ($pdo, $cmd, $id, $result, $song): array {
        $now = karaoke_now();
        $up = $pdo->prepare("UPDATE karaoke_commands SET status = 'done', result = ?, updated_at = ?, done_at = ? WHERE id = ? AND status IN ('pending', 'leased')");
        $up->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now, $id]);
        if ($up->rowCount() !== 1) {
            return ['ok' => true, 'command_id' => $id, 'status' => 'done', 'duplicate' => true];
        }
        $payload = json_decode((string) $cmd['payload'], true) ?: [];
        switch ($cmd['type']) {
            case 'download':
                $songId = karaoke_song_upsert($pdo, $song);
                $yt = (string) ($payload['youtube_id'] ?? '');
                $pdo->prepare("UPDATE karaoke_downloads SET status = 'done', song_id = ?, duration_s = ?, error = NULL, downloaded_at = ?, updated_at = ? WHERE youtube_id = ?")
                    ->execute([$songId, $song['duration_s'], $now, $now, $yt]);
                $st = $pdo->prepare("SELECT id FROM karaoke_requests WHERE youtube_id = ? AND status = 'descargando'");
                $st->execute([$yt]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                    if (karaoke_transition($pdo, $rid, 'descargando', 'descargado', 'agente', null, ['song_id' => $songId])) {
                        karaoke_transition($pdo, $rid, 'descargado', 'en_espera', 'sistema');
                    }
                }
                break;
            case 'enqueue':
                $pos = $result['queue_pos'] ?? null;
                $pdo->prepare("UPDATE karaoke_requests SET acked_at = ?, kf_queue_pos = COALESCE(?, kf_queue_pos), updated_at = ? WHERE id = ? AND status = 'enviado'")
                    ->execute([$now, is_int($pos) ? $pos : null, $now, $cmd['request_id']]);
                break;
            case 'remove':
                if (($result['removed'] ?? false) === true && $cmd['request_id']) {
                    $r = karaoke_request($pdo, $cmd['request_id']);
                    if ($r && in_array($r['status'], ['enviado', 'en_cola'], true)) {
                        karaoke_transition($pdo, $r['id'], $r['status'], 'retirado', 'agente', 'Quitado de KaraFun desde el panel.');
                    }
                }
                break;
        }
        return ['ok' => true, 'command_id' => $id, 'status' => 'done'];
    });
}

// ---------------------------------------------------------------------------
// Latido, cola real de KaraFun y buffer corto (§7, §8).

function karaoke_agent_row(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM karaoke_agent WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->prepare('INSERT INTO karaoke_agent (id, kf_running, kf_connected, acks_pending) VALUES (1, 0, 0, 0)')->execute();
        $row = $pdo->query('SELECT * FROM karaoke_agent WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

/** Cola reportada por el agente → lista limpia [{pos, title, artist, singer, status}]. */
function karaoke_clean_queue(mixed $queue): array
{
    if (!is_array($queue) || !array_is_list($queue) || count($queue) > 200) {
        throw new KaraokeError('karafun.queue debe ser una lista.', 422, 'bad_poll');
    }
    $out = [];
    foreach ($queue as $i => $e) {
        if (!is_array($e)) {
            throw new KaraokeError("karafun.queue[$i] debe ser un objeto.", 422, 'bad_poll');
        }
        $s = static fn (string $k, int $max): string => is_scalar($e[$k] ?? null) ? mb_substr((string) $e[$k], 0, $max) : '';
        $out[] = [
            'pos' => is_int($e['pos'] ?? null) ? $e['pos'] : $i,
            'title' => $s('title', 200),
            'artist' => $s('artist', 200),
            'singer' => $s('singer', 120),
            'status' => $s('status', 20),
        ];
    }
    usort($out, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
    return $out;
}

/** Marcador del pedido dentro del cantante («Ana · M7·k3f» → «M7·k3f»). */
function karaoke_marker_of(string $singer): ?string
{
    return preg_match('/(M\d{1,3}·[0-9a-f]{3})\s*$/u', $singer, $m) ? $m[1] : null;
}

/**
 * Lo que dice KaraFun manda (§2.5): compara la cola real con los pedidos en KaraFun y los mueve
 * a en_cola, cantando, cantada o retirado.
 */
function karaoke_reconcile(PDO $pdo, array $prevQueue, array $queue): void
{
    $byMarker = [];
    foreach ($queue as $e) {
        $m = karaoke_marker_of($e['singer']);
        if ($m !== null && !isset($byMarker[$m])) {
            $byMarker[$m] = $e;
        }
    }
    $present = static function (array $entry) use ($queue): bool {
        foreach ($queue as $e) {
            if ($e['singer'] === $entry['singer'] && $e['title'] === $entry['title']) {
                return true;
            }
        }
        return false;
    };
    $st = $pdo->prepare('SELECT * FROM karaoke_requests WHERE status IN (?, ?, ?)');
    $st->execute(KARAOKE_IN_KARAFUN);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $e = $byMarker[$r['marker']] ?? null;
        if ($e !== null) {
            $to = $e['status'] === 'playing' ? 'cantando' : 'en_cola';
            if ($r['status'] !== $to && in_array($to, KARAOKE_TRANSITIONS[$r['status']], true)) {
                karaoke_transition($pdo, $r['id'], $r['status'], $to, 'agente', null, ['kf_queue_pos' => $e['pos']]);
            } elseif ((int) $r['kf_queue_pos'] !== $e['pos'] || $r['kf_queue_pos'] === null) {
                $pdo->prepare('UPDATE karaoke_requests SET kf_queue_pos = ? WHERE id = ?')->execute([$e['pos'], $r['id']]);
            }
            continue;
        }
        if ($r['status'] === 'cantando') {
            karaoke_transition($pdo, $r['id'], 'cantando', 'cantada', 'agente');
        } elseif ($r['status'] === 'en_cola') {
            // Si algo que estaba delante sigue en la cola, esta se quitó a mano; si no, avanzó y sonó.
            $pos = (int) $r['kf_queue_pos'];
            $removed = false;
            foreach ($prevQueue as $p) {
                if ($p['pos'] < $pos && $present($p)) {
                    $removed = true;
                    break;
                }
            }
            $removed
                ? karaoke_transition($pdo, $r['id'], 'en_cola', 'retirado', 'agente', 'Quitada de la cola de KaraFun.')
                : karaoke_transition($pdo, $r['id'], 'en_cola', 'cantada', 'agente');
        } elseif ($r['status'] === 'enviado' && $r['acked_at'] !== null && $r['acked_at'] < karaoke_now(-KARAOKE_SENT_TIMEOUT_S)) {
            karaoke_transition($pdo, $r['id'], 'enviado', 'fallido', 'sistema', 'KaraFun no mostró la canción en la cola. Pide ayuda en la barra.');
        }
    }
}

/**
 * Buffer corto (§7): solo se emite enqueue cuando la cola real de KaraFun tiene menos de 3 entradas,
 * contando también lo ya enviado que KaraFun aún no muestra.
 */
function karaoke_fill_buffer(PDO $pdo, array $queue): int
{
    $night = karaoke_current_night($pdo);
    if (!$night) {
        return 0;
    }
    $shown = [];
    foreach ($queue as $e) {
        if (($m = karaoke_marker_of($e['singer'])) !== null) {
            $shown[$m] = true;
        }
    }
    $st = $pdo->prepare("SELECT marker FROM karaoke_requests WHERE status = 'enviado'");
    $st->execute();
    $inFlight = count(array_filter($st->fetchAll(PDO::FETCH_COLUMN), static fn (string $m): bool => !isset($shown[$m])));
    $buffer = count($queue) + $inFlight;
    $emitted = 0;
    $next = $pdo->prepare("SELECT r.*, s.natural_key, s.title, s.artist, s.duration_s, s.available
        FROM karaoke_requests r LEFT JOIN karaoke_songs s ON s.id = r.song_id
        WHERE r.night_id = ? AND r.status = 'en_espera' ORDER BY r.fair_seq, r.created_at, r.id LIMIT 1");
    for ($guard = 0; $buffer < KARAOKE_BUFFER && $guard < 50; $guard++) {
        $next->execute([$night['id']]);
        $r = $next->fetch(PDO::FETCH_ASSOC);
        $next->closeCursor();
        if (!$r) {
            break;
        }
        if (!$r['song_id'] || !(int) $r['available']) {
            karaoke_transition($pdo, $r['id'], 'en_espera', 'fallido', 'sistema', 'La canción ya no está disponible en KaraFun.');
            continue;
        }
        if (karaoke_transition($pdo, $r['id'], 'en_espera', 'enviado', 'sistema', null, ['sent_at' => karaoke_now()])) {
            karaoke_command_create($pdo, 'enqueue', [
                'request_id' => $r['id'],
                'song' => ['natural_key' => $r['natural_key'], 'title' => $r['title'], 'artist' => $r['artist'], 'duration_s' => (int) $r['duration_s']],
                'singer' => karaoke_singer_label($r),
            ], $r['id']);
            $buffer++;
            $emitted++;
        }
    }
    return $emitted;
}

/** poll: latido + estado de KaraFun + entrega de órdenes. */
function karaoke_agent_poll(PDO $pdo, array $body): array
{
    $version = $body['agent_version'] ?? null;
    if (!is_string($version) || $version === '' || strlen($version) > 40) {
        throw new KaraokeError('agent_version es obligatorio (máximo 40 caracteres).', 422, 'bad_poll');
    }
    $kf = $body['karafun'] ?? [];
    if (!is_array($kf)) {
        throw new KaraokeError('karafun debe ser un objeto.', 422, 'bad_poll');
    }
    $connected = ($kf['connected'] ?? false) === true;
    $hasQueue = array_key_exists('queue', $kf) && $kf['queue'] !== null;
    $queue = $hasQueue ? karaoke_clean_queue($kf['queue']) : null;
    $state = is_string($kf['state'] ?? null) ? mb_substr($kf['state'], 0, 20) : null;
    $acks = is_int($body['acks_pending'] ?? null) ? max(0, $body['acks_pending']) : 0;

    karaoke_tx($pdo, static function () use ($pdo, $version, $kf, $connected, $queue, $state, $acks): void {
        $prev = karaoke_agent_row($pdo);
        $prevQueue = json_decode((string) ($prev['queue_snapshot'] ?? '[]'), true) ?: [];
        // Solo con KaraFun conectado y una cola reportada se puede comparar y rellenar el buffer.
        if ($connected && $queue !== null) {
            karaoke_reconcile($pdo, $prevQueue, $queue);
            karaoke_fill_buffer($pdo, $queue);
        }
        $pdo->prepare('UPDATE karaoke_agent SET last_seen_at = ?, version = ?, kf_running = ?, kf_connected = ?, kf_state = ?, queue_snapshot = ?, acks_pending = ? WHERE id = 1')
            ->execute([karaoke_now(), $version, ($kf['running'] ?? false) === true ? 1 : 0, $connected ? 1 : 0, $state,
                $connected && $queue !== null ? json_encode($queue, JSON_UNESCAPED_UNICODE) : $prev['queue_snapshot'], $acks]);
    });

    return ['server_time' => date('c', karaoke_clock()), 'commands' => karaoke_lease_commands($pdo)];
}

/** Estado del agente para el panel y las mesas. */
function karaoke_agent_status(PDO $pdo): array
{
    $a = karaoke_agent_row($pdo);
    $ago = $a['last_seen_at'] ? karaoke_clock() - (int) strtotime($a['last_seen_at']) : null;
    return [
        'online' => $ago !== null && $ago <= KARAOKE_AGENT_OFFLINE_S,
        'last_seen_at' => karaoke_iso($a['last_seen_at']),
        'seconds_ago' => $ago,
        'version' => $a['version'],
        'karafun_running' => (bool) $a['kf_running'],
        'karafun_connected' => (bool) $a['kf_connected'],
        'karafun_state' => $a['kf_state'],
        'queue' => json_decode((string) ($a['queue_snapshot'] ?? '[]'), true) ?: [],
        'acks_pending' => (int) $a['acks_pending'],
        'last_sync_at' => karaoke_iso($a['last_sync_at']),
        'last_sync_songs' => $a['last_sync_songs'] === null ? null : (int) $a['last_sync_songs'],
    ];
}

/**
 * API del agente (contrato §2), sin HTTP: devuelve el cuerpo de la respuesta o lanza KaraokeError.
 * karaoke/agent.php se encarga del token, del método y del JSON.
 */
function karaoke_agent_dispatch(PDO $pdo, string $action, array $body): array
{
    return match ($action) {
        'poll' => karaoke_agent_poll($pdo, $body),
        'ack' => karaoke_agent_ack($pdo, $body),
        'catalog.begin' => karaoke_catalog_begin($pdo),
        'catalog.chunk' => karaoke_catalog_chunk($pdo, $body),
        'catalog.commit' => karaoke_catalog_commit($pdo, $body),
        'song.upsert' => (static function () use ($pdo, $body): array {
            $song = karaoke_clean_song($body['song'] ?? null);
            $id = karaoke_tx($pdo, static fn (): int => karaoke_song_upsert($pdo, $song));
            $r = ['ok' => true, 'song_id' => $id];
            if (!$song['key_matches']) {
                $r['warnings'] = ['natural_key_mismatch' => [$song['natural_key']]];
            }
            return $r;
        })(),
        default => throw new KaraokeError('Acción desconocida.', 404, 'unknown_action', 'No encontrado'),
    };
}
