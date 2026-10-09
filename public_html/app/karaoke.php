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
 *   descargando → operador → puesta: la descarga automática falló y el personal la pone a mano en KaraFun.
 *   fallido, retirado, cancelado y puesta son finales. Toda transición es un UPDATE … WHERE status = <esperado>.
 */

const KARAOKE_BUFFER = 3;              // Entradas en la cola real de KaraFun: la que suena + 2.
const KARAOKE_LEASE_S = 30;
const KARAOKE_MAX_ATTEMPTS = 5;
const KARAOKE_POLL_MAX_COMMANDS = 10;
const KARAOKE_AGENT_OFFLINE_S = 15;
const KARAOKE_MAX_VIDEO_S = 600;
const KARAOKE_CHUNK_MAX = 500;
const KARAOKE_NIGHT_MAX_H = 14;        // Una noche abierta caduca sola: el código no sirve al día siguiente.
const KARAOKE_SENT_TIMEOUT_S = 90;     // Enviado y confirmado, pero KaraFun nunca lo mostró en la cola.
const KARAOKE_NOT_SHOWN = 'KaraFun no mostró la canción en la cola. Pide ayuda en la barra.';
const KARAOKE_SINGER_MAX = 30;
const KARAOKE_AVG_SONG_S = 240;

// En turno: lo que el sistema llevará solo a KaraFun. Pendientes: además lo que espera al operador.
const KARAOKE_QUEUED = ['descargando', 'descargado', 'en_espera'];
const KARAOKE_WAITING = [...KARAOKE_QUEUED, 'operador'];
const KARAOKE_IN_KARAFUN = ['enviado', 'en_cola', 'cantando'];
const KARAOKE_FINAL = ['cantada', 'fallido', 'retirado', 'cancelado', 'puesta'];
// Rechazos de una descarga que el operador tampoco puede arreglar: el pedido falla de una vez.
// Cualquier otro error (YouTube bloquea, sin conexión, yt-dlp roto…) pasa el pedido al operador.
const KARAOKE_DOWNLOAD_REJECTED = ['too_long', 'live', 'unavailable', 'bad_id', 'no_duration'];

const KARAOKE_TRANSITIONS = [
    'descargando' => ['descargado', 'operador', 'fallido', 'cancelado'],
    // operador → puesta: el personal la bajó y la puso en KaraFun (sin marcador, la nube ya no la sigue).
    'operador' => ['puesta', 'fallido', 'cancelado'],
    'descargado' => ['en_espera', 'fallido', 'cancelado'],
    'en_espera' => ['enviado', 'fallido', 'cancelado'],
    'enviado' => ['en_cola', 'cantando', 'fallido', 'retirado'],
    // en_cola/cantando → enviado: solo cuando KaraFun se reinicia y la nube los vuelve a enviar.
    'en_cola' => ['cantando', 'cantada', 'retirado', 'enviado'],
    'cantando' => ['cantada', 'enviado'],
    // fallido → en_cola/cantando: solo el fallido por KARAOKE_NOT_SHOWN que KaraFun sí muestra después.
    'fallido' => ['en_cola', 'cantando'],
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
        // Igual que el agente (contrato §2): NFKD, así «5ª» → «5a», «Nº» → «no», «²» → «2».
        $s = (string) Normalizer::normalize($s, Normalizer::FORM_KD);
    } else {
        // Sin intl: letras con tilde más los equivalentes de compatibilidad más comunes.
        $map ??= array_combine(mb_str_split($from), mb_str_split($to))
            + ['ª' => 'a', 'º' => 'o', '¹' => '1', '²' => '2', '³' => '3', 'ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', '…' => '...', '½' => '1⁄2'];
        $s = strtr($s, $map);
    }
    $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
    $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim($s);
}

/**
 * natural_key de una canción local (contrato v2): artista|título normalizados, sin duración
 * (KaraFun la da en 0 hasta analizar el archivo). Las de KaraFun en línea usan kf:<id>.
 */
function karaoke_natural_key(string $artist, string $title): string
{
    return karaoke_normalize(karaoke_artist_without_version($artist)) . '|' . karaoke_normalize($title);
}

/** Texto de búsqueda de una canción: la misma normalización que natural_key (contrato §2). */
function karaoke_search_text(string $artist, string $title): string
{
    return mb_substr(karaoke_normalize(karaoke_artist_without_version($artist) . ' ' . $title), 0, 420);
}

/**
 * Reindexa una sola vez las canciones guardadas con la normalización anterior (NFD): solo cambian
 * las que tienen caracteres de compatibilidad (ª, º, ², ligaduras) o artista con prefijo «vN».
 */
function karaoke_migrate_search_text(PDO $pdo): int
{
    if (karaoke_setting($pdo, 'karaoke_search_norm') === 'nfkd-1') {
        return 0;
    }
    @set_time_limit(300);
    return karaoke_tx($pdo, static function () use ($pdo): int {
        $changed = 0;
        $up = $pdo->prepare('UPDATE karaoke_songs SET search_text = ? WHERE id = ?');
        foreach ($pdo->query('SELECT id, title, artist, search_text FROM karaoke_songs')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $search = karaoke_search_text($r['artist'], $r['title']);
            if ($search !== $r['search_text']) {
                $up->execute([$search, $r['id']]);
                karaoke_set_words($pdo, (int) $r['id'], $search);
                $changed++;
            }
        }
        karaoke_save_setting($pdo, 'karaoke_search_norm', 'nfkd-1');
        return $changed;
    });
}

/** «v2 Adele» → «Adele»: las segundas versiones de un archivo son la misma canción (contrato §2). */
function karaoke_artist_without_version(string $artist): string
{
    return (string) preg_replace('/^\s*v\d{1,2}\s+(?=\S)/i', '', $artist);
}

function karaoke_valid_natural_key(string $k): bool
{
    return strlen($k) <= 255 && preg_match('/^(?:[a-z0-9]+(?: [a-z0-9]+)*)?\|(?:[a-z0-9]+(?: [a-z0-9]+)*)?$/', $k) === 1;
}

function karaoke_is_por_aprobar(?string $folder): bool
{
    return $folder !== null && karaoke_normalize($folder) === 'por aprobar';
}

/**
 * Qué copia de una canción repetida se ofrece: primero la de su carpeta de letra, luego
 * «Por aprobar», luego «Repetidos». «Corregir» guarda archivos dañados (arquitectura §14):
 * esas canciones no se ofrecen a las mesas (null).
 */
function karaoke_folder_rank(?string $folder): ?int
{
    return match ($folder === null ? '' : karaoke_normalize($folder)) {
        'corregir' => null,
        'por aprobar' => 1,
        'repetidos' => 2,
        default => 0,
    };
}

/** Deja una fila por natural_key con la mejor copia y quita las de «Corregir». */
function karaoke_pick_copies(iterable $rows): array
{
    $byKey = [];
    foreach ($rows as $r) {
        $rank = karaoke_folder_rank($r['folder'] ?? null);
        if ($rank === null) {
            continue;
        }
        $prev = $byKey[$r['natural_key']] ?? null;
        if ($prev === null || $rank < karaoke_folder_rank($prev['folder'] ?? null)) {
            $byKey[$r['natural_key']] = $r;
        }
    }
    return $byKey;
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
    $hasScheme = (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $url);
    if (($hasScheme || str_contains($url, '://')) && (strlen($url) > 300 || preg_match('/\s/u', $url))) {
        throw new KaraokeError('Pega solo el enlace del video, sin texto adicional ni varios enlaces.', 422, 'youtube_not_url');
    }
    if (!$hasScheme) {
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

/** «?, ?, …» para un IN con una lista de estados. */
function karaoke_marks(array $values): string
{
    return implode(', ', array_fill(0, count($values), '?'));
}

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
            $st = $pdo->prepare('SELECT id, status FROM karaoke_requests WHERE night_id = ? AND status IN (' . karaoke_marks(KARAOKE_WAITING) . ')');
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
        throw new KaraokeError('El código de la noche no coincide. Pídeselo al personal del bar.', 403, 'bad_code', 'Código equivocado');
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

/** Palabras de búsqueda de una canción (normalizadas, únicas, máximo 40 caracteres cada una). */
function karaoke_words(string $searchText): array
{
    $out = [];
    foreach (explode(' ', $searchText) as $w) {
        if ($w !== '') {
            $out[substr($w, 0, 40)] = true;
        }
    }
    return array_slice(array_keys($out), 0, 40);
}

/** Inserta palabras [[song_id, word], …] en lotes (multi-fila, válido en mysql y sqlite). */
function karaoke_insert_words(PDO $pdo, array $pairs): void
{
    foreach (array_chunk($pairs, 400) as $chunk) {
        $sql = 'INSERT INTO karaoke_song_words (song_id, word) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)'));
        $pdo->prepare($sql)->execute(array_merge(...$chunk));
    }
}

function karaoke_set_words(PDO $pdo, int $songId, string $searchText): void
{
    $pdo->prepare('DELETE FROM karaoke_song_words WHERE song_id = ?')->execute([$songId]);
    karaoke_insert_words($pdo, array_map(static fn (string $w): array => [$songId, $w], karaoke_words($searchText)));
}

/**
 * Búsqueda de las mesas por nombre de canción y/o artista, rápida con ~100 mil canciones:
 * la palabra más larga se busca por prefijo en el índice karaoke_song_words (un rango, no un
 * recorrido) y las demás filtran ese grupo pequeño. Primero las de KaraFun en línea que coinciden,
 * luego las de la carpeta local; dentro de cada grupo, las que coinciden mejor con el título.
 */
function karaoke_search(PDO $pdo, string $q, int $limit = 30): array
{
    $norm = karaoke_normalize(mb_substr($q, 0, 80));
    $terms = array_values(array_unique(array_slice(array_filter(explode(' ', $norm), 'strlen'), 0, 6)));
    if (!$terms) {
        return [];
    }
    $driver = array_reduce($terms, static fn (?string $a, string $t): string => $a === null || strlen($t) > strlen($a) ? $t : $a);
    if (strlen($driver) < 2) {
        return [];
    }
    $driver = substr($driver, 0, 40);
    // Prefijo como rango: palabras >= 'jua' y < 'jua~' ('~' va después de a-z0-9 en ASCII).
    $where = 's.available = 1 AND s.id IN (SELECT w.song_id FROM karaoke_song_words w WHERE w.word >= ? AND w.word < ?)';
    $params = [$driver, $driver . '~'];
    foreach ($terms as $t) {
        if ($t !== $driver) {
            // Cada término restante debe ser el comienzo de alguna palabra.
            $where .= ' AND (s.search_text LIKE ? OR s.search_text LIKE ?)';
            $params[] = $t . '%';
            $params[] = '% ' . $t . '%';
        }
    }
    $st = $pdo->prepare("SELECT s.id, s.title, s.artist, s.duration_s, s.folder, s.source, s.popularity FROM karaoke_songs s
        WHERE $where ORDER BY CASE WHEN s.source = 'karafun' THEN 0 ELSE 1 END, COALESCE(s.popularity, 999999999), s.id LIMIT 400");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $ranked = [];
    foreach ($rows as $s) {
        $title = karaoke_normalize($s['title']);
        $artist = karaoke_normalize($s['artist']);
        $score = $s['source'] === 'karafun' ? 1000 : 0;
        if ($title === $norm) {
            $score += 300;
        } elseif ($artist === $norm) {
            $score += 250;
        } elseif (str_starts_with($title, $norm)) {
            $score += 200;
        } elseif (str_starts_with($artist . ' ' . $title, $norm) || str_starts_with($title . ' ' . $artist, $norm)) {
            $score += 150;
        }
        $inTitle = 0;
        foreach ($terms as $t) {
            if (preg_match('/(^| )' . $t . '/', $title)) {
                $inTitle++;
            }
        }
        $score += 20 * $inTitle;
        // Desempate: la más cantada en KaraFun (posición en su catálogo), luego el título más corto.
        $ranked[] = [$score, $s['popularity'] === null ? PHP_INT_MAX : (int) $s['popularity'], strlen($title), $s];
    }
    usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
    return array_map(static fn (array $r): array => [
        'id' => (int) $r[3]['id'],
        'title' => $r[3]['title'],
        'artist' => $r[3]['artist'],
        'duration_s' => (int) $r[3]['duration_s'],
        'source' => $r[3]['source'],
        'from_youtube' => karaoke_is_por_aprobar($r[3]['folder']),
    ], array_slice($ranked, 0, max(1, min(50, $limit))));
}

/**
 * Valida una canción en el formato del contrato v2 y devuelve la fila limpia:
 * local → natural_key = artista|título normalizados (sin duración); karafun → natural_key = kf:<kf_id>.
 */
function karaoke_clean_song(mixed $s, string $where = 'song'): array
{
    if (!is_array($s)) {
        throw new KaraokeError("$where debe ser un objeto.", 422, 'bad_song');
    }
    $source = $s['source'] ?? null;
    if (!in_array($source, ['local', 'karafun'], true)) {
        throw new KaraokeError("$where.source debe ser \"local\" o \"karafun\".", 422, 'bad_song');
    }
    $kf = $s['kf_id'] ?? null;
    if ($kf !== null && (!is_int($kf) || $kf < 1)) {
        throw new KaraokeError("$where.kf_id debe ser un entero positivo o null.", 422, 'bad_song');
    }
    $key = $s['natural_key'] ?? null;
    if ($source === 'karafun') {
        if ($kf === null || $key !== 'kf:' . $kf) {
            throw new KaraokeError("$where: una canción de KaraFun en línea lleva kf_id y natural_key \"kf:<kf_id>\".", 422, 'bad_song');
        }
    } elseif (!is_string($key) || !karaoke_valid_natural_key($key)) {
        throw new KaraokeError("$where.natural_key no cumple el formato artista|título normalizado.", 422, 'bad_song');
    }
    $title = $s['title'] ?? null;
    $artist = $s['artist'] ?? '';
    $dur = $s['duration_s'] ?? 0;
    if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
        throw new KaraokeError("$where.title es obligatorio (máximo 200 caracteres).", 422, 'bad_song');
    }
    if (!is_string($artist) || mb_strlen($artist) > 200) {
        throw new KaraokeError("$where.artist admite máximo 200 caracteres.", 422, 'bad_song');
    }
    if (!is_int($dur) && !(is_float($dur) && floor($dur) === $dur) || $dur < 0 || $dur > 36000) {
        throw new KaraokeError("$where.duration_s debe ser un entero de segundos (0 si no se conoce).", 422, 'bad_song');
    }
    foreach (['folder' => 120, 'file' => 500] as $f => $max) {
        if (isset($s[$f]) && (!is_string($s[$f]) || mb_strlen($s[$f]) > $max)) {
            throw new KaraokeError("$where.$f admite máximo $max caracteres.", 422, 'bad_song');
        }
    }
    $yt = $s['youtube_id'] ?? null;
    if ($yt !== null && (!is_string($yt) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $yt))) {
        throw new KaraokeError("$where.youtube_id no es un id de YouTube.", 422, 'bad_song');
    }
    return [
        'natural_key' => $key,
        'source' => $source,
        'kf_id' => $kf,
        'title' => trim($title),
        'artist' => trim($artist),
        'duration_s' => (int) $dur,
        'folder' => isset($s['folder']) && $s['folder'] !== '' ? $s['folder'] : null,
        'file' => isset($s['file']) && $s['file'] !== '' ? $s['file'] : null,
        'youtube_id' => $yt,
        'popularity' => null,
        // La nube no recalcula la clave local, pero avisa si el agente la calculó distinto (contrato §2).
        'key_matches' => $source === 'karafun' || karaoke_natural_key(trim($artist), trim($title)) === $key,
    ];
}

/**
 * Inserta o actualiza canciones por natural_key, en lotes (una sentencia cada 200), y reindexa
 * solo las nuevas o con artista/título cambiado. Sirve igual para 1 canción que para 90 mil.
 * popularity solo se pisa si viene (la sincronización del agente no la trae; el CSV sí).
 */
function karaoke_bulk_upsert(PDO $pdo, array $songs, string $seenAt): array
{
    if (!$songs) {
        return ['added' => 0, 'updated' => 0];
    }
    $now = karaoke_now();
    $my = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $set = ['source', 'title', 'artist', 'duration_s', 'search_text', 'kf_id', 'folder', 'file', 'youtube_id', 'seen_at', 'updated_at'];
    $upsert = $my
        ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", $set)) . ', popularity = COALESCE(VALUES(popularity), popularity), available = 1'
        : ' ON CONFLICT(natural_key) DO UPDATE SET ' . implode(', ', array_map(static fn (string $c): string => "$c = excluded.$c", $set)) . ', popularity = COALESCE(excluded.popularity, popularity), available = 1';
    $existing = [];
    foreach (array_chunk(array_column($songs, 'natural_key'), 500) as $keys) {
        $st = $pdo->prepare('SELECT natural_key, search_text, title, artist FROM karaoke_songs WHERE natural_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[$r['natural_key']] = $r;
        }
    }
    $added = 0;
    $updated = 0;
    $reindex = [];
    foreach (array_chunk($songs, 200) as $chunk) {
        $vals = [];
        $params = [];
        foreach ($chunk as $s) {
            $search = karaoke_search_text($s['artist'], $s['title']);
            $vals[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)';
            array_push($params, $s['source'], $s['natural_key'], $s['title'], $s['artist'], $s['duration_s'], $search, $s['kf_id'], $s['popularity'] ?? null, $s['folder'], $s['file'] ?? null, $s['youtube_id'] ?? null, $seenAt, $now, $now);
            $old = $existing[$s['natural_key']] ?? null;
            if ($old === null) {
                $added++;
                $reindex[] = $s['natural_key'];
            } elseif ($old['title'] !== $s['title'] || $old['artist'] !== $s['artist']) {
                $updated++;
                if ($old['search_text'] !== $search) {
                    $reindex[] = $s['natural_key'];
                }
            }
        }
        $pdo->prepare('INSERT INTO karaoke_songs (source, natural_key, title, artist, duration_s, search_text, kf_id, popularity, folder, file, youtube_id, available, seen_at, created_at, updated_at) VALUES ' . implode(', ', $vals) . $upsert)
            ->execute($params);
    }
    foreach (array_chunk($reindex, 400) as $keys) {
        $st = $pdo->prepare('SELECT id, search_text FROM karaoke_songs WHERE natural_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $st->execute($keys);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $pdo->prepare('DELETE FROM karaoke_song_words WHERE song_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
        $pairs = [];
        foreach ($rows as $r) {
            foreach (karaoke_words($r['search_text']) as $w) {
                $pairs[] = [(int) $r['id'], $w];
            }
        }
        karaoke_insert_words($pdo, $pairs);
    }
    return ['added' => $added, 'updated' => $updated];
}

/** Inserta o actualiza una canción (song.upsert, descargas). Devuelve su id. */
function karaoke_song_upsert(PDO $pdo, array $song, ?string $seenAt = null): int
{
    karaoke_bulk_upsert($pdo, [$song], $seenAt ?? karaoke_now());
    $st = $pdo->prepare('SELECT id FROM karaoke_songs WHERE natural_key = ?');
    $st->execute([$song['natural_key']]);
    return (int) $st->fetchColumn();
}

/**
 * Importa un CSV de catálogo y decide por las columnas: con NaturalKey es el catálogo local que
 * exporta el agente (Folder, File, Duration, YoutubeId); sin ella, el catálogo en línea de KaraFun.
 */
function karaoke_import_catalog_csv(PDO $pdo, string $path): array
{
    $f = @fopen($path, 'rb');
    if (!$f) {
        throw new KaraokeError('No se pudo leer el archivo.', 422, 'csv_unreadable');
    }
    $header = fgetcsv($f, 0, ';', '"', '');
    fclose($f);
    $names = array_map(static fn ($h): string => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), is_array($header) ? $header : []);
    return in_array('naturalkey', $names, true)
        ? karaoke_import_local_csv($pdo, $path)
        : ['source' => 'karafun'] + karaoke_import_karafun_csv($pdo, $path);
}

/**
 * Catálogo local desde el CSV del agente (Id;Title;Artist;…;Duration;Folder;File;NaturalKey;YoutubeId).
 * Equivale a una sincronización local completa (contrato §2): misma validación de canciones y mismas
 * reglas de carpeta; las locales que no vienen pasan a available = 0. Todo en una transacción.
 */
function karaoke_import_local_csv(PDO $pdo, string $path): array
{
    $f = @fopen($path, 'rb');
    if (!$f) {
        throw new KaraokeError('No se pudo leer el archivo.', 422, 'csv_unreadable');
    }
    $header = fgetcsv($f, 0, ';', '"', '');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));
    $cols = array_change_key_case(array_flip(array_map(static fn ($h): string => trim((string) $h), $header)));
    foreach (['title', 'artist', 'naturalkey'] as $c) {
        if (!isset($cols[$c])) {
            throw new KaraokeError('El archivo no parece el catálogo local: falta la columna ' . $c . '.', 422, 'csv_columns');
        }
    }
    $col = static fn (array $r, string $c): string => isset($cols[$c]) ? trim((string) ($r[$cols[$c]] ?? '')) : '';
    $rows = [];
    $skipped = 0;
    $mismatch = [];
    while (($r = fgetcsv($f, 0, ';', '"', '')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $dur = $col($r, 'duration');
        $yt = $col($r, 'youtubeid');
        try {
            $s = karaoke_clean_song([
                'natural_key' => $col($r, 'naturalkey'),
                'source' => 'local',
                'kf_id' => null,
                'title' => $col($r, 'title'),
                'artist' => $col($r, 'artist'),
                'duration_s' => ctype_digit($dur) ? (int) $dur : 0,
                'folder' => $col($r, 'folder') ?: null,
                'file' => $col($r, 'file') ?: null,
                'youtube_id' => $yt === '' ? null : $yt,
            ]);
        } catch (KaraokeError) {
            $skipped++;
            continue;
        }
        if (!$s['key_matches'] && count($mismatch) < 5) {
            $mismatch[] = $s['natural_key'];
        }
        $rows[] = $s;
    }
    fclose($f);
    if (!$rows) {
        throw new KaraokeError('El archivo no tiene canciones válidas.', 422, 'csv_empty');
    }
    @set_time_limit(300);
    return karaoke_tx($pdo, static function () use ($pdo, $rows, $skipped, $mismatch): array {
        $now = karaoke_now();
        $byKey = karaoke_pick_copies($rows);
        $r = karaoke_bulk_upsert($pdo, array_values($byKey), $now);
        $gone = $pdo->prepare("UPDATE karaoke_songs SET available = 0, updated_at = ? WHERE source = 'local' AND available = 1 AND seen_at < ?");
        $gone->execute([$now, $now]);
        $summary = ['source' => 'local', 'songs' => count($byKey), 'added' => $r['added'], 'updated' => $r['updated'], 'unavailable' => $gone->rowCount(),
            'hidden' => count($rows) - count($byKey), 'skipped' => $skipped, 'por_aprobar' => karaoke_count_por_aprobar($pdo), 'imported_at' => karaoke_iso($now)];
        if ($mismatch) {
            $summary['natural_key_mismatch'] = $mismatch;
        }
        karaoke_save_setting($pdo, 'karaoke_local_import', json_encode($summary, JSON_UNESCAPED_UNICODE));
        karaoke_agent_row($pdo);
        $pdo->prepare('UPDATE karaoke_agent SET last_sync_at = ?, last_sync_songs = ? WHERE id = 1')->execute([$now, count($byKey)]);
        return $summary;
    });
}

/**
 * Importa el catálogo en línea de KaraFun desde el CSV que exporta KaraFun (Id;Title;Artist;…,
 * separado por «;»). Alternativa a la sincronización del agente (contrato §2, source karafun):
 * mismas claves kf:<id>. Además guarda la posición en el CSV (de más a menos cantada) como
 * popularity. Las que ya no vienen pasan a available = 0. Todo en una transacción.
 */
function karaoke_import_karafun_csv(PDO $pdo, string $path): array
{
    $f = @fopen($path, 'rb');
    if (!$f) {
        throw new KaraokeError('No se pudo leer el archivo.', 422, 'csv_unreadable');
    }
    $header = fgetcsv($f, 0, ';', '"', '');
    if (!is_array($header)) {
        throw new KaraokeError('El archivo está vacío.', 422, 'csv_empty');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $cols = array_change_key_case(array_flip(array_map(static fn ($h): string => trim((string) $h), $header)));
    foreach (['id', 'title', 'artist'] as $c) {
        if (!isset($cols[$c])) {
            throw new KaraokeError('El archivo no parece el catálogo de KaraFun: falta la columna ' . ucfirst($c) . ' (separador «;»).', 422, 'csv_columns');
        }
    }
    return karaoke_tx($pdo, static function () use ($pdo, $f, $cols): array {
        $now = karaoke_now();
        $seen = [];
        $skipped = 0;
        $totals = ['added' => 0, 'updated' => 0];
        $batch = [];
        $flush = static function () use ($pdo, $now, &$batch, &$totals): void {
            $r = karaoke_bulk_upsert($pdo, $batch, $now);
            $totals['added'] += $r['added'];
            $totals['updated'] += $r['updated'];
            $batch = [];
        };
        while (($row = fgetcsv($f, 0, ';', '"', '')) !== false) {
            $kf = trim((string) ($row[$cols['id']] ?? ''));
            $title = trim((string) ($row[$cols['title']] ?? ''));
            $artist = trim((string) ($row[$cols['artist']] ?? ''));
            if (!ctype_digit($kf) || (int) $kf < 1 || $title === '' || mb_strlen($title) > 200 || mb_strlen($artist) > 200 || !mb_check_encoding($title . $artist, 'UTF-8')) {
                $skipped++;
                continue;
            }
            $kf = (int) $kf;
            if (isset($seen[$kf])) {
                $skipped++;
                continue;
            }
            $seen[$kf] = true;
            $batch[] = ['natural_key' => 'kf:' . $kf, 'source' => 'karafun', 'kf_id' => $kf, 'title' => $title, 'artist' => $artist,
                'duration_s' => 0, 'folder' => null, 'file' => null, 'youtube_id' => null, 'popularity' => count($seen)];
            if (count($batch) >= 1000) {
                $flush();
            }
        }
        fclose($f);
        if ($batch) {
            $flush();
        }
        if (!$seen) {
            throw new KaraokeError('El archivo no tiene canciones válidas.', 422, 'csv_empty');
        }
        $gone = $pdo->prepare("UPDATE karaoke_songs SET available = 0, updated_at = ? WHERE source = 'karafun' AND available = 1 AND seen_at < ?");
        $gone->execute([$now, $now]);
        $summary = ['songs' => count($seen), 'added' => $totals['added'], 'updated' => $totals['updated'], 'unavailable' => $gone->rowCount(), 'skipped' => $skipped, 'imported_at' => karaoke_iso($now)];
        karaoke_save_setting($pdo, 'karaoke_karafun_import', json_encode($summary));
        return $summary;
    });
}

/** catalog.begin: { source: "local" | "karafun" } → { sync_id }. */
function karaoke_catalog_begin(PDO $pdo, array $body = []): array
{
    $source = $body['source'] ?? null;
    if (!in_array($source, ['local', 'karafun'], true)) {
        throw new KaraokeError('source debe ser "local" o "karafun".', 422, 'bad_sync');
    }
    // Sincronizaciones abandonadas hace más de un día: fuera su staging.
    $old = $pdo->prepare("SELECT id FROM karaoke_catalog_syncs WHERE status = 'open' AND started_at < ?");
    $old->execute([karaoke_now(-86400)]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $pdo->prepare('DELETE FROM karaoke_catalog_staging WHERE sync_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM karaoke_catalog_chunks WHERE sync_id = ?')->execute([$sid]);
        $pdo->prepare("UPDATE karaoke_catalog_syncs SET status = 'abandoned' WHERE id = ?")->execute([$sid]);
    }
    $id = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO karaoke_catalog_syncs (id, source, status, started_at) VALUES (?, ?, 'open', ?)")->execute([$id, $source, karaoke_now()]);
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
        if ($c['source'] !== $sync['source']) {
            throw new KaraokeError("songs[$i].source no coincide con la sincronización ({$sync['source']}).", 422, 'bad_song');
        }
        if (!$c['key_matches'] && count($mismatches) < 5) {
            $mismatches[] = $c['natural_key'];
        }
        $clean[] = $c;
    }
    karaoke_tx($pdo, static function () use ($pdo, $sync, $index, $clean): void {
        $pdo->prepare('DELETE FROM karaoke_catalog_staging WHERE sync_id = ? AND chunk_index = ?')->execute([$sync['id'], $index]);
        $pdo->prepare('DELETE FROM karaoke_catalog_chunks WHERE sync_id = ? AND chunk_index = ?')->execute([$sync['id'], $index]);
        foreach (array_chunk($clean, 100) as $part) {
            $vals = [];
            $params = [];
            foreach ($part as $c) {
                $vals[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $sync['id'], $index, $c['natural_key'], $c['title'], $c['artist'], $c['duration_s'], $c['folder'], $c['file'], $c['kf_id'], $c['youtube_id']);
            }
            $pdo->prepare('INSERT INTO karaoke_catalog_staging (sync_id, chunk_index, natural_key, title, artist, duration_s, folder, file, kf_id, youtube_id) VALUES ' . implode(', ', $vals))
                ->execute($params);
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
 * Activa la versión nueva de esa fuente en una transacción: las canciones que vinieron quedan
 * disponibles y las de esa fuente que no (y no se subieron sueltas durante la sincronización)
 * pasan a available = 0. La otra fuente no se toca.
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
    @set_time_limit(300);

    return karaoke_tx($pdo, static function () use ($pdo, $sync, $chunks, $total): array {
        $now = karaoke_now();
        $source = $sync['source'];
        $rows = $pdo->prepare('SELECT natural_key, title, artist, duration_s, folder, file, kf_id, youtube_id FROM karaoke_catalog_staging WHERE sync_id = ? ORDER BY chunk_index, id');
        $rows->execute([$sync['id']]);
        // Una misma canción en varios archivos: gana la de su carpeta de letra; «Corregir» no entra.
        $byKey = karaoke_pick_copies($rows->fetchAll(PDO::FETCH_ASSOC));
        $songs = [];
        foreach ($byKey as $key => $r) {
            $songs[] = ['natural_key' => (string) $key, 'source' => $source, 'kf_id' => $r['kf_id'] === null ? null : (int) $r['kf_id'], 'title' => $r['title'], 'artist' => $r['artist'],
                'duration_s' => (int) $r['duration_s'], 'folder' => $r['folder'], 'file' => $r['file'], 'youtube_id' => $r['youtube_id'], 'popularity' => null];
        }
        $added = 0;
        foreach (array_chunk($songs, 2000) as $part) {
            $added += karaoke_bulk_upsert($pdo, $part, $now)['added'];
        }
        $gone = $pdo->prepare('UPDATE karaoke_songs SET available = 0, updated_at = ? WHERE source = ? AND available = 1 AND seen_at < ?');
        $gone->execute([$now, $source, $sync['started_at']]);
        $summary = [
            'ok' => true,
            'source' => $source,
            'total_songs' => $total,
            'distinct' => count($byKey),
            'added' => $added,
            'unavailable' => $gone->rowCount(),
            'por_aprobar' => karaoke_count_por_aprobar($pdo),
        ];
        $pdo->prepare("UPDATE karaoke_catalog_syncs SET status = 'committed', committed_at = ?, total_chunks = ?, total_songs = ?, summary = ? WHERE id = ?")
            ->execute([$now, $chunks, $total, json_encode($summary), $sync['id']]);
        $pdo->prepare('DELETE FROM karaoke_catalog_staging WHERE sync_id = ?')->execute([$sync['id']]);
        if ($source === 'local') {
            karaoke_agent_row($pdo);
            $pdo->prepare('UPDATE karaoke_agent SET last_sync_at = ?, last_sync_songs = ? WHERE id = 1')->execute([$now, count($byKey)]);
        }
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
    $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_requests WHERE night_id = ? AND table_id = ? AND status IN (' . karaoke_marks(KARAOKE_WAITING) . ')');
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
        $st = $pdo->prepare('SELECT COUNT(*) FROM karaoke_requests WHERE night_id = ? AND marker = ? AND status NOT IN (' . karaoke_marks(KARAOKE_FINAL) . ')');
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

/** El operador bajó el video a mano y lo puso en KaraFun. */
function karaoke_request_placed(PDO $pdo, string $id): array
{
    $r = karaoke_request($pdo, $id);
    if (!$r || $r['status'] !== 'operador') {
        throw new KaraokeError('Ese pedido ya no espera al operador.', 409, 'not_manual');
    }
    if (!karaoke_transition($pdo, $id, 'operador', 'puesta', 'admin', 'Puesta en KaraFun por el personal.', ['error' => null])) {
        throw new KaraokeError('El pedido cambió mientras tanto. Recarga e inténtalo de nuevo.', 409, 'conflict');
    }
    return karaoke_request($pdo, $id);
}

/** El operador tampoco pudo conseguir el video: el pedido falla con el motivo que verá la mesa. */
function karaoke_request_manual_fail(PDO $pdo, string $id, string $reason): array
{
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if ($reason === '' || mb_strlen($reason) > 120) {
        throw new KaraokeError('Escribe el motivo para la mesa (máximo 120 caracteres).', 422, 'bad_reason');
    }
    $r = karaoke_request($pdo, $id);
    if (!$r || $r['status'] !== 'operador') {
        throw new KaraokeError('Ese pedido ya no espera al operador.', 409, 'not_manual');
    }
    if (!karaoke_transition($pdo, $id, 'operador', 'fallido', 'admin', $reason)) {
        throw new KaraokeError('El pedido cambió mientras tanto. Recarga e inténtalo de nuevo.', 409, 'conflict');
    }
    return karaoke_request($pdo, $id);
}

/** Pedidos de la noche que esperan al operador (la descarga automática falló), del más antiguo al más nuevo. */
function karaoke_manual(PDO $pdo, int $nightId): array
{
    $st = $pdo->prepare("SELECT r.*, t.name AS table_name, d.title AS yt_title
        FROM karaoke_requests r
        JOIN karaoke_tables t ON t.id = r.table_id
        LEFT JOIN karaoke_downloads d ON d.youtube_id = r.youtube_id
        WHERE r.night_id = ? AND r.status = 'operador'
        ORDER BY r.updated_at, r.id");
    $st->execute([$nightId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
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
            $song = $pdo->prepare('SELECT * FROM karaoke_songs WHERE id = ?');
            $song->execute([$r['song_id']]);
            $s = $song->fetch(PDO::FETCH_ASSOC) ?: [];
            karaoke_command_create($pdo, 'remove', ['request_id' => $r['id'], 'singer' => karaoke_singer_label($r), 'song' => $s ? karaoke_enqueue_song($s) : null], $r['id']);
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
        WHERE r.night_id = ? AND r.status IN (' . karaoke_marks(KARAOKE_QUEUED) . ')
        ORDER BY r.fair_seq, r.created_at, r.id');
    $st->execute(array_merge([$nightId], KARAOKE_QUEUED));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Sube o baja un pedido en la lista de espera (intercambia el turno con el vecino). */
function karaoke_request_move(PDO $pdo, string $id, int $dir): void
{
    karaoke_tx($pdo, static function () use ($pdo, $id, $dir): void {
        $r = karaoke_request($pdo, $id);
        if (!$r || !in_array($r['status'], KARAOKE_QUEUED, true)) {
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

/**
 * Consecuencias de una orden que falló del todo: el pedido pasa a fallido con el motivo. Una descarga
 * que falló por algo que no sea un rechazo definitivo pasa al operador, que la baja y la pone a mano.
 */
function karaoke_command_failed(PDO $pdo, array $cmd, string $message, ?string $code = null): void
{
    $payload = json_decode((string) $cmd['payload'], true) ?: [];
    if ($cmd['type'] === 'download') {
        $yt = (string) ($payload['youtube_id'] ?? '');
        $pdo->prepare("UPDATE karaoke_downloads SET status = 'failed', error = ?, updated_at = ? WHERE youtube_id = ? AND status = 'downloading'")
            ->execute([mb_substr($message, 0, 255), karaoke_now(), $yt]);
        $st = $pdo->prepare("SELECT id FROM karaoke_requests WHERE youtube_id = ? AND status = 'descargando'");
        $st->execute([$yt]);
        $manual = !in_array($code, KARAOKE_DOWNLOAD_REJECTED, true);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $manual
                ? karaoke_transition($pdo, $rid, 'descargando', 'operador', 'agente', $message, ['error' => $message])
                : karaoke_transition($pdo, $rid, 'descargando', 'fallido', 'agente', $message);
        }
    } elseif ($cmd['type'] === 'enqueue' && $cmd['request_id']) {
        karaoke_transition($pdo, $cmd['request_id'], 'enviado', 'fallido', 'agente', $message);
    }
}

/**
 * Una orden que ya no hace falta no se reentrega (devuelve el motivo, o null si sigue vigente):
 * - enqueue cuyo pedido ya no está «enviado»: la cola real de KaraFun ya lo mostró (o ya sonó)
 *   aunque el agente se cayera antes de confirmar; reentregarla lo haría sonar dos veces.
 * - download que ya nadie espera (todas las mesas cancelaron).
 * - remove de un pedido que ya no está en KaraFun.
 */
function karaoke_command_obsolete(PDO $pdo, array $c): ?string
{
    if (in_array($c['type'], ['enqueue', 'remove'], true) && $c['request_id']) {
        $r = karaoke_request($pdo, $c['request_id']);
        $live = $c['type'] === 'enqueue' ? ['enviado'] : ['enviado', 'en_cola'];
        return $r && in_array($r['status'], $live, true) ? null : 'request_' . ($r['status'] ?? 'missing');
    }
    if ($c['type'] === 'download') {
        $yt = (string) (json_decode((string) $c['payload'], true)['youtube_id'] ?? '');
        $st = $pdo->prepare("SELECT COUNT(*) FROM karaoke_requests WHERE youtube_id = ? AND status = 'descargando'");
        $st->execute([$yt]);
        return (int) $st->fetchColumn() > 0 ? null : 'no_requests';
    }
    return null;
}

/**
 * Entrega hasta 10 órdenes pendientes o con lease vencido, por id, y las arrienda 30 s.
 * Una orden que agotó sus 5 intentos sin confirmación pasa a failed. Con KaraFun no disponible
 * (contrato v2) las órdenes enqueue y remove esperan sin gastar intentos.
 */
function karaoke_lease_commands(PDO $pdo, bool $karafunAvailable = true): array
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
        if (!$karafunAvailable && in_array($c['type'], ['enqueue', 'remove'], true)) {
            continue;
        }
        if (($skip = karaoke_command_obsolete($pdo, $c)) !== null) {
            karaoke_tx($pdo, static function () use ($pdo, $c, $now, $skip): void {
                $up = $pdo->prepare("UPDATE karaoke_commands SET status = 'done', result = ?, updated_at = ?, done_at = ? WHERE id = ? AND status = ?");
                $up->execute([json_encode(['skipped' => $skip]), $now, $now, $c['id'], $c['status']]);
                if ($up->rowCount() === 1 && $c['type'] === 'download') {
                    $yt = (string) (json_decode((string) $c['payload'], true)['youtube_id'] ?? '');
                    $pdo->prepare("UPDATE karaoke_downloads SET status = 'failed', error = ?, updated_at = ? WHERE youtube_id = ? AND status = 'downloading'")
                        ->execute(['Nadie esperaba la descarga.', $now, $yt]);
                }
            });
            continue;
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
                karaoke_command_failed($pdo, $cmd, $message, $code);
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
                // singer_shown = false: KaraFun aún no indexaba el archivo y entró por ruta, sin cantante
                // (contrato v2); la cola real se reconcilia entonces por título.
                $shown = ($result['singer_shown'] ?? true) === false ? 0 : 1;
                $pdo->prepare("UPDATE karaoke_requests SET acked_at = ?, kf_queue_pos = COALESCE(?, kf_queue_pos), singer_shown = ?, updated_at = ? WHERE id = ? AND status IN ('enviado', 'en_cola', 'cantando')")
                    ->execute([$now, is_int($pos) ? $pos : null, $shown, $now, $cmd['request_id']]);
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
    $unmarked = [];   // entradas sin marcador: puestas a mano o descargas que entraron sin cantante
    foreach ($queue as $i => $e) {
        $m = karaoke_marker_of($e['singer']);
        if ($m !== null) {
            $byMarker[$m] ??= $e;
        } else {
            $unmarked[$i] = $e;
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
    $st = $pdo->prepare('SELECT r.*, s.title AS song_title FROM karaoke_requests r LEFT JOIN karaoke_songs s ON s.id = r.song_id
        WHERE r.status IN (?, ?, ?) ORDER BY r.sent_at, r.id');
    $st->execute(KARAOKE_IN_KARAFUN);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $e = $byMarker[$r['marker']] ?? null;
        if ($e === null && !(int) $r['singer_shown']) {
            // Entró sin cantante: se identifica por título, una entrada por pedido.
            foreach ($unmarked as $i => $u) {
                if (karaoke_normalize($u['title']) === karaoke_normalize((string) $r['song_title'])) {
                    $e = $u;
                    unset($unmarked[$i]);
                    break;
                }
            }
        }
        if ($e !== null) {
            unset($byMarker[$r['marker']]);
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
            karaoke_transition($pdo, $r['id'], 'enviado', 'fallido', 'sistema', KARAOKE_NOT_SHOWN);
        }
    }
    // Un fallido por no aparecer a tiempo que KaraFun sí muestra después (la cola llegó con retraso) vuelve:
    // está sonando o por sonar, y darlo por fallido hace que la mesa lo pida otra vez. Solo los de esta noche
    // y solo si ningún pedido vivo usa ya ese marcador (los vivos se emparejaron arriba y salieron de $byMarker).
    $night = karaoke_current_night($pdo);
    if ($byMarker && $night) {
        $st = $pdo->prepare("SELECT id, marker FROM karaoke_requests WHERE night_id = ? AND status = 'fallido' AND error = ? ORDER BY updated_at DESC");
        $st->execute([$night['id'], KARAOKE_NOT_SHOWN]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $e = $byMarker[$r['marker']] ?? null;
            if ($e === null) {
                continue;
            }
            unset($byMarker[$r['marker']]);
            karaoke_transition($pdo, $r['id'], 'fallido', $e['status'] === 'playing' ? 'cantando' : 'en_cola', 'agente',
                'KaraFun sí la tenía en la cola.', ['error' => null, 'kf_queue_pos' => $e['pos']]);
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
    // Tras karaoke_reconcile, lo que sigue «enviado» es lo que KaraFun aún no muestra.
    $inFlight = (int) $pdo->query("SELECT COUNT(*) FROM karaoke_requests WHERE status = 'enviado'")->fetchColumn();
    $buffer = count($queue) + $inFlight;
    $emitted = 0;
    $next = $pdo->prepare("SELECT r.*, s.natural_key, s.title, s.artist, s.duration_s, s.available, s.source, s.kf_id, s.file
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
                'song' => karaoke_enqueue_song($r),
                'singer' => karaoke_singer_label($r),
            ], $r['id']);
            $buffer++;
            $emitted++;
        }
    }
    return $emitted;
}

/** La canción tal como viaja en las órdenes enqueue y remove (formato de canción del contrato v2). */
function karaoke_enqueue_song(array $r): array
{
    return [
        'natural_key' => $r['natural_key'],
        'source' => $r['source'],
        'kf_id' => $r['kf_id'] === null ? null : (int) $r['kf_id'],
        'title' => $r['title'],
        'artist' => $r['artist'],
        'duration_s' => (int) $r['duration_s'],
        // Ruta relativa a Música\Karaoke: el agente la usa si KaraFun no la encuentra por nombre.
        'file' => $r['source'] === 'local' ? ($r['file'] ?? null) : null,
        // Descarga de YouTube que KaraFun aún no indexó: el agente busca su archivo por este id (contrato v2).
        'youtube_id' => $r['youtube_id'] ?? null,
    ];
}

/**
 * KaraFun se reinició (cambió karafun.session, contrato §2): tras una caída recarga una cola vieja
 * que el agente ya vació, así que lo que la nube había puesto en KaraFun se perdió. Esos pedidos
 * vuelven a «enviado» (no son «retirado» ni «cantada») y se reenvían con órdenes nuevas, en su
 * orden y empezando por el que se estaba cantando. Las órdenes viejas que seguían abiertas se cierran.
 */
function karaoke_karafun_restarted(PDO $pdo): int
{
    $st = $pdo->prepare("SELECT r.*, s.natural_key, s.title, s.artist, s.duration_s, s.source, s.kf_id, s.file, s.available
        FROM karaoke_requests r LEFT JOIN karaoke_songs s ON s.id = r.song_id
        WHERE r.status IN ('cantando', 'en_cola', 'enviado')
        ORDER BY CASE r.status WHEN 'cantando' THEN 0 WHEN 'en_cola' THEN 1 ELSE 2 END, COALESCE(r.kf_queue_pos, 999), r.sent_at, r.id");
    $st->execute();
    $now = karaoke_now();
    $resent = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $open = $pdo->prepare("SELECT id, type FROM karaoke_commands WHERE request_id = ? AND type IN ('enqueue', 'remove') AND status IN ('pending', 'leased')");
        $open->execute([$r['id']]);
        $wantedRemoved = false;
        foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $wantedRemoved = $wantedRemoved || $c['type'] === 'remove';
            $pdo->prepare("UPDATE karaoke_commands SET status = 'done', result = ?, updated_at = ?, done_at = ? WHERE id = ? AND status IN ('pending', 'leased')")
                ->execute([json_encode(['skipped' => 'karafun_restart']), $now, $now, $c['id']]);
        }
        if ($wantedRemoved) {
            // El encargado ya había pedido quitarla: el reinicio la quitó.
            if ($r['status'] !== 'cantando') {
                karaoke_transition($pdo, $r['id'], $r['status'], 'retirado', 'sistema', 'Quitada de KaraFun desde el panel.');
            }
            continue;
        }
        if ($r['status'] !== 'enviado'
            && !karaoke_transition($pdo, $r['id'], $r['status'], 'enviado', 'sistema', 'KaraFun se reinició: se vuelve a enviar.', ['kf_queue_pos' => null, 'acked_at' => null, 'sent_at' => $now])) {
            continue;
        }
        $pdo->prepare('UPDATE karaoke_requests SET kf_queue_pos = NULL, acked_at = NULL, singer_shown = 1, sent_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $r['id']]);
        karaoke_command_create($pdo, 'enqueue', [
            'request_id' => $r['id'],
            'song' => karaoke_enqueue_song($r),
            'singer' => karaoke_singer_label($r),
        ], $r['id']);
        $resent++;
    }
    return $resent;
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
    // Contrato v2: connected false o running false = KaraFun no disponible.
    $available = $connected && ($kf['running'] ?? false) === true;
    $working = $body['working'] ?? [];
    if (!is_array($working) || !array_is_list($working) || count($working) > 100 || array_filter($working, static fn ($w): bool => !is_int($w) || $w < 1)) {
        throw new KaraokeError('working debe ser una lista de ids de órdenes.', 422, 'bad_poll');
    }
    $hasQueue = array_key_exists('queue', $kf) && $kf['queue'] !== null;
    $queue = $hasQueue ? karaoke_clean_queue($kf['queue']) : null;
    $state = is_string($kf['state'] ?? null) ? mb_substr($kf['state'], 0, 20) : null;
    $session = $kf['session'] ?? null;
    if ($session !== null && (!is_scalar($session) || strlen((string) $session) > 100)) {
        throw new KaraokeError('karafun.session debe ser un texto corto.', 422, 'bad_poll');
    }
    $session = $session === null || $session === '' ? null : (string) $session;
    $startedAt = is_string($kf['started_at'] ?? null) ? mb_substr($kf['started_at'], 0, 40) : null;
    $acks = is_int($body['acks_pending'] ?? null) ? max(0, $body['acks_pending']) : 0;

    karaoke_tx($pdo, static function () use ($pdo, $version, $kf, $connected, $available, $queue, $state, $acks, $session, $startedAt): void {
        $prev = karaoke_agent_row($pdo);
        $prevQueue = json_decode((string) ($prev['queue_snapshot'] ?? '[]'), true) ?: [];
        if ($session !== null && $session !== $prev['kf_session']) {
            if ($prev['kf_session'] !== null) {
                // Antes de comparar colas: lo que desapareció por el reinicio no es «retirado».
                karaoke_karafun_restarted($pdo);
                $prevQueue = [];
                $prev['queue_snapshot'] = '[]';
            }
            $pdo->prepare('UPDATE karaoke_agent SET kf_session = ?, kf_started_at = ? WHERE id = 1')->execute([$session, $startedAt]);
        }
        // Solo con KaraFun disponible y una cola reportada se puede comparar y rellenar el buffer.
        if ($available && $queue !== null) {
            karaoke_reconcile($pdo, $prevQueue, $queue);
            karaoke_fill_buffer($pdo, $queue);
        }
        $pdo->prepare('UPDATE karaoke_agent SET last_seen_at = ?, version = ?, kf_running = ?, kf_connected = ?, kf_state = ?, queue_snapshot = ?, acks_pending = ? WHERE id = 1')
            ->execute([karaoke_now(), $version, ($kf['running'] ?? false) === true ? 1 : 0, $connected ? 1 : 0, $state,
                $available && $queue !== null ? json_encode($queue, JSON_UNESCAPED_UNICODE) : $prev['queue_snapshot'], $acks]);
    });

    // Órdenes en marcha en el agente (descargas largas): se extiende su lease y no se reentregan.
    if ($working) {
        $pdo->prepare("UPDATE karaoke_commands SET lease_until = ?, updated_at = ? WHERE status = 'leased' AND id IN (" . implode(',', array_fill(0, count($working), '?')) . ')')
            ->execute(array_merge([karaoke_now(KARAOKE_LEASE_S), karaoke_now()], $working));
    }
    return ['server_time' => date('c', karaoke_clock()), 'commands' => karaoke_lease_commands($pdo, $available && $queue !== null)];
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
        'catalog.begin' => karaoke_catalog_begin($pdo, $body),
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
