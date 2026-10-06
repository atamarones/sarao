<?php
declare(strict_types=1);

/**
 * Pruebas de la lógica del karaoke por mesa (public_html/app/karaoke.php) sobre SQLite en memoria.
 *   php -d extension=pdo_sqlite tools/test_karaoke.php
 * Sale con código 1 si alguna falla.
 */

date_default_timezone_set('America/Bogota');
mb_internal_encoding('UTF-8');
require __DIR__ . '/../public_html/app/schema.php';
require __DIR__ . '/../public_html/app/karaoke.php';

$passed = 0;
$failed = [];

function check(bool $cond, string $what): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
    } else {
        $failed[] = $what;
        echo "  ✗ $what\n";
    }
}

function eq(mixed $actual, mixed $expected, string $what): void
{
    check($actual === $expected, $what . ($actual === $expected ? '' : ' — esperado ' . var_export($expected, true) . ', obtenido ' . var_export($actual, true)));
}

/** Ejecuta $fn y comprueba que lance KaraokeError con ese código. */
function throws(callable $fn, string $code, string $what): ?KaraokeError
{
    try {
        $fn();
    } catch (KaraokeError $e) {
        eq($e->errorCode, $code, $what);
        return $e;
    }
    check(false, "$what — no lanzó $code");
    return null;
}

function section(string $name): void
{
    echo "· $name\n";
}

/**
 * Base limpia. Por defecto SQLite en memoria; con KARAOKE_TEST_DSN (y KARAOKE_TEST_USER/PASS) corre
 * sobre MySQL/MariaDB de pruebas: BORRA y recrea todas las tablas de esa base, nunca la apuntes a producción.
 */
function fresh_db(): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $dsn = getenv('KARAOKE_TEST_DSN');
    if (!$dsn) {
        $pdo = new PDO('sqlite::memory:', null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        foreach (schema_statements('sqlite') as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }
    $pdo = new PDO($dsn, getenv('KARAOKE_TEST_USER') ?: null, getenv('KARAOKE_TEST_PASS') ?: null, $opts);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (schema_statements('mysql') as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}

function uuid(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}

function song(string $artist, string $title, int $dur, ?string $folder = 'A'): array
{
    return ['natural_key' => karaoke_natural_key($artist, $title, $dur), 'title' => $title, 'artist' => $artist, 'duration_s' => $dur, 'folder' => $folder, 'file' => "$folder/$artist - $title.mp4"];
}

function add_song(PDO $pdo, string $artist, string $title, int $dur = 200): int
{
    return karaoke_song_upsert($pdo, karaoke_clean_song(song($artist, $title, $dur)));
}

function req(PDO $pdo, array $table, array $night, array $extra): array
{
    return karaoke_request_create($pdo, $table, $night, $extra + ['id' => uuid(), 'singer' => 'Ana'], static fn () => ['exists' => true, 'title' => 'Video de prueba']);
}

function status_of(PDO $pdo, string $id): string
{
    return (string) karaoke_request($pdo, $id)['status'];
}

function poll(PDO $pdo, array $queue, bool $connected = true): array
{
    return karaoke_agent_dispatch($pdo, 'poll', ['agent_version' => 'test', 'karafun' => ['running' => true, 'connected' => $connected, 'state' => 'playing', 'queue' => $queue], 'acks_pending' => 0]);
}

/** Entrada de la cola de KaraFun para un pedido. */
function entry(PDO $pdo, string $requestId, int $pos, string $status = 'ready'): array
{
    $r = karaoke_request($pdo, $requestId);
    return ['pos' => $pos, 'title' => 'x', 'artist' => 'y', 'singer' => karaoke_singer_label($r), 'status' => $status];
}

$T0 = (int) strtotime('2026-10-06 20:00:00');
karaoke_clock($T0);

// ---------------------------------------------------------------------------
section('Normalización y natural_key (contrato §2)');
eq(karaoke_natural_key('Adriana Lucía', '¡Quisiera olvidarte!', 187), 'adriana lucia|quisiera olvidarte|187', 'ejemplo del contrato, sin redondear a 5 s');
eq(karaoke_natural_key('Ñengo Flow', 'Pa’ que me llames  (Remix)', 187.4), 'nengo flow|pa que me llames remix|187', 'ñ→n, signos fuera, decimales al segundo');
eq(karaoke_natural_key('', 'Canción', 187.6), '|cancion|188', 'artista vacío y redondeo hacia arriba');
eq(karaoke_natural_key('Beyoncé & JAY-Z', 'Crazy in Love', 0), 'beyonce jay z|crazy in love|0', 'mayúsculas y símbolos');
foreach (['Adriana Lucía', 'ÑANDÚ Çava', 'İstanbul', 'Dvořák — Señor', 'Ünïcödé'] as $s) {
    eq(karaoke_normalize($s, false), karaoke_normalize($s, true), "normalización sin intl igual a la de intl: $s");
}
check(karaoke_valid_natural_key('adriana lucia|quisiera olvidarte|187'), 'clave válida');
check(!karaoke_valid_natural_key('Adriana Lucia|x|187'), 'clave con mayúsculas no es válida');
check(!karaoke_valid_natural_key('a|b'), 'clave sin duración no es válida');
check(!karaoke_valid_natural_key('a |b|1'), 'clave con espacio sobrante no es válida');

// ---------------------------------------------------------------------------
section('Enlaces de YouTube (§10)');
$valid = [
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s' => 'dQw4w9WgXcQ',
    'https://m.youtube.com/watch?v=a-B_c1D2e3F' => 'a-B_c1D2e3F',
    'https://music.youtube.com/watch?v=dQw4w9WgXcQ&list=RDAMVM' => 'dQw4w9WgXcQ',
    'https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?si=AbCdEf123' => 'dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
    '  https://youtu.be/dQw4w9WgXcQ  ' => 'dQw4w9WgXcQ',
    'HTTPS://WWW.YOUTUBE.COM/watch?v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
];
foreach ($valid as $url => $id) {
    try {
        eq(karaoke_parse_youtube($url), $id, "válido: $url");
    } catch (KaraokeError $e) {
        check(false, "válido: $url — rechazado: " . $e->getMessage());
    }
}
$invalid = [
    '' => 'youtube_empty',
    'Despacito Luis Fonsi' => 'youtube_not_url',
    'despacito' => 'youtube_not_url',
    'youtu.be/dQw4w9WgXcQ' => 'youtube_not_url',
    'www.youtube.com/watch?v=dQw4w9WgXcQ' => 'youtube_not_url',
    'http://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'youtube_not_https',
    'mira esta https://youtu.be/dQw4w9WgXcQ' => 'youtube_not_url',
    'https://youtu.be/dQw4w9WgXcQ https://youtu.be/aaaaaaaaaaa' => 'youtube_not_url',
    "https://youtu.be/dQw4w9WgXcQ\nhttps://youtu.be/aaaaaaaaaaa" => 'youtube_not_url',
    'https://www.youtube.com/playlist?list=PL1234567890' => 'youtube_form',
    'https://www.youtube.com/watch?list=PL1234567890' => 'youtube_form',
    'https://www.youtube.com/@LuisFonsi' => 'youtube_form',
    'https://www.youtube.com/channel/UC123' => 'youtube_form',
    'https://www.youtube.com/results?search_query=despacito' => 'youtube_form',
    'https://www.youtube.com/live/dQw4w9WgXcQ' => 'youtube_form',
    'https://www.youtube.com/embed/dQw4w9WgXcQ' => 'youtube_form',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ/extra' => 'youtube_form',
    'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ' => 'youtube_host',
    'https://evilyoutube.com/watch?v=dQw4w9WgXcQ' => 'youtube_host',
    'https://www.youtube.co/watch?v=dQw4w9WgXcQ' => 'youtube_host',
    'https://vimeo.com/123456' => 'youtube_host',
    'https://user@youtube.com/watch?v=dQw4w9WgXcQ' => 'youtube_host',
    'https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ' => 'youtube_host',
    'https://www.youtube.com/watch?v=dQw4w9WgXc' => 'youtube_id',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQQ' => 'youtube_id',
    'https://www.youtube.com/watch?v=dQw4w9WgX%3CQ' => 'youtube_id',
    'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ' => 'youtube_id',
    'https://youtu.be/' => 'youtube_id',
    'https://youtu.be/dQw4w9WgXcQ/extra' => 'youtube_id',
    'javascript:alert(1)' => 'youtube_not_url',
    'ftp://youtu.be/dQw4w9WgXcQ' => 'youtube_not_https',
];
foreach ($invalid as $url => $code) {
    throws(static fn () => karaoke_parse_youtube($url), $code, 'inválido: ' . json_encode($url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------------------------------------------------------------------------
section('Mesas, noche y código');
$pdo = fresh_db();
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$t2 = karaoke_table($pdo, karaoke_table_save($pdo, null, 2, 'Mesa 2'));
$t3 = karaoke_table($pdo, karaoke_table_save($pdo, null, 3, 'Barra'));
eq($t1['name'], 'Mesa 1', 'nombre por defecto');
check((bool) preg_match('/^[0-9a-f]{32}$/', $t1['qr_token']), 'token de QR de 32 hex');
throws(static fn () => karaoke_table_save($pdo, null, 2, 'Otra'), 'table_exists', 'número de mesa repetido');
throws(static fn () => karaoke_session($pdo, $t1['qr_token'], '0000'), 'no_night', 'sin noche abierta');
$night = karaoke_night_open($pdo);
check((bool) preg_match('/^\d{4}$/', $night['night_code']), 'código de 4 cifras');
$code = $night['night_code'];
$wrong = $code === '0000' ? '1111' : '0000';
eq(karaoke_session($pdo, $t1['qr_token'], $code, 'ip1')['table']['id'], $t1['id'], 'sesión con QR y código');
throws(static fn () => karaoke_session($pdo, str_repeat('a', 32), $code), 'bad_table', 'QR desconocido');
for ($i = 0; $i < 10; $i++) {
    throws(static fn () => karaoke_session($pdo, $t1['qr_token'], $wrong, 'ip-bad'), 'bad_code', "código equivocado $i");
}
throws(static fn () => karaoke_session($pdo, $t1['qr_token'], $code, 'ip-bad'), 'rate_code', 'demasiados intentos bloquean incluso el código bueno');
eq(karaoke_session($pdo, $t1['qr_token'], $code, 'ip-ok')['night']['id'], $night['id'], 'otra IP sigue entrando');
$pdo->prepare('UPDATE karaoke_tables SET is_active = 0 WHERE id = ?')->execute([$t3['id']]);
throws(static fn () => karaoke_session($pdo, $t3['qr_token'], $code), 'table_inactive', 'mesa inactiva');
$pdo->prepare('UPDATE karaoke_tables SET is_active = 1 WHERE id = ?')->execute([$t3['id']]);
karaoke_clock($T0 + KARAOKE_NIGHT_MAX_H * 3600 + 1);
eq(karaoke_current_night($pdo), null, 'la noche caduca sola a las ' . KARAOKE_NIGHT_MAX_H . ' h');
karaoke_clock($T0);
$rot = karaoke_night_rotate_code($pdo);
check($rot['night_code'] !== $code && (int) $rot['id'] === (int) $night['id'], 'rotar el código mantiene la noche');
throws(static fn () => karaoke_session($pdo, $t1['qr_token'], $code), 'bad_code', 'el código viejo deja de servir');

// ---------------------------------------------------------------------------
section('Transiciones atómicas (§6)');
$pdo = fresh_db();
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$night = karaoke_night_open($pdo);
$s1 = add_song($pdo, 'Juanes', 'La camisa negra');
$r = req($pdo, $t1, $night, ['song_id' => $s1]);
eq($r['status'], 'en_espera', 'pedido del catálogo entra directo a en_espera');
eq($r['marker'], 'M1·' . substr(str_replace('-', '', $r['id']), 0, 3), 'marcador M<mesa>·<3 del id>');
check(karaoke_transition($pdo, $r['id'], 'en_espera', 'enviado', 'sistema'), 'en_espera → enviado');
check(!karaoke_transition($pdo, $r['id'], 'en_espera', 'enviado', 'sistema'), 'la misma transición dos veces no mueve el pedido dos veces');
check(!karaoke_transition($pdo, $r['id'], 'en_espera', 'cancelado', 'mesa'), 'cancelar con estado viejo no hace nada');
try {
    karaoke_transition($pdo, $r['id'], 'enviado', 'en_espera', 'sistema');
    check(false, 'transición hacia atrás rechazada');
} catch (LogicException) {
    check(true, 'transición hacia atrás rechazada');
}
try {
    karaoke_transition($pdo, $r['id'], 'cantada', 'cantando', 'sistema');
    check(false, 'un estado final no sale');
} catch (LogicException) {
    check(true, 'un estado final no sale');
}
$log = $pdo->prepare('SELECT from_status, to_status, actor FROM karaoke_request_log WHERE request_id = ? ORDER BY id');
$log->execute([$r['id']]);
eq($log->fetchAll(PDO::FETCH_NUM), [[null, 'en_espera', 'mesa'], ['en_espera', 'enviado', 'sistema']], 'cada transición queda en karaoke_request_log');
throws(static fn () => karaoke_request_cancel($pdo, $r['id'], 'mesa', (int) $t1['id']), 'not_cancellable', 'no se cancela lo que ya va para KaraFun');

$again = req($pdo, $t1, $night, ['song_id' => $s1]);
eq(karaoke_request_create($pdo, $t1, $night, ['id' => $again['id'], 'singer' => 'Ana', 'song_id' => $s1])['id'], $again['id'], 'repetir el mismo id devuelve el mismo pedido');
eq((int) $pdo->query('SELECT COUNT(*) FROM karaoke_requests')->fetchColumn(), 2, 'sin duplicados');
$t2 = karaoke_table($pdo, karaoke_table_save($pdo, null, 2, ''));
throws(static fn () => karaoke_request_create($pdo, $t2, $night, ['id' => $again['id'], 'singer' => 'Ana', 'song_id' => $s1]), 'id_taken', 'otro pedido no puede reutilizar el id');
throws(static fn () => karaoke_request_cancel($pdo, $again['id'], 'mesa', (int) $t2['id']), 'not_found', 'una mesa no cancela pedidos de otra');
eq(karaoke_request_cancel($pdo, $again['id'], 'mesa', (int) $t1['id'])['status'], 'cancelado', 'la mesa cancela su pedido en espera');
throws(static fn () => req($pdo, $t1, $night, ['song_id' => $s1, 'singer' => '   ']), 'singer_empty', 'cantante vacío');
throws(static fn () => req($pdo, $t1, $night, ['song_id' => $s1, 'singer' => str_repeat('a', 31)]), 'singer_long', 'cantante largo');
eq(karaoke_clean_singer("  Ana\u{0007}  ·  María  "), 'Ana María', 'cantante sin control ni separador del marcador');
throws(static fn () => req($pdo, $t1, $night, ['song_id' => 9999]), 'song_gone', 'canción inexistente');

// Colisión de marcador: mismo prefijo de 3 caracteres en la misma mesa.
$a = req($pdo, $t1, $night, ['song_id' => $s1, 'id' => 'abc00000-0000-4000-a000-000000000001']);
throws(static fn () => req($pdo, $t1, $night, ['song_id' => $s1, 'id' => 'abc00000-0000-4000-a000-000000000002']), 'marker_collision', 'marcador repetido entre pedidos vivos');
eq(req($pdo, $t2, $night, ['song_id' => $s1, 'id' => 'abc00000-0000-4000-a000-000000000003'])['status'], 'en_espera', 'el mismo prefijo en otra mesa no choca');

// ---------------------------------------------------------------------------
section('Cola justa por rotación (§7) y límites (§11)');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$tA = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, 'A'));
$tB = karaoke_table($pdo, karaoke_table_save($pdo, null, 2, 'B'));
$tC = karaoke_table($pdo, karaoke_table_save($pdo, null, 3, 'C'));
$sid = add_song($pdo, 'Shakira', 'Antología');
$names = [];
foreach ([[$tA, 'A1'], [$tA, 'A2'], [$tA, 'A3'], [$tB, 'B1'], [$tB, 'B2'], [$tC, 'C1']] as [$t, $n]) {
    $names[req($pdo, $t, $night, ['song_id' => $sid, 'singer' => $n])['id']] = $n;
    karaoke_clock(karaoke_clock() + 1);
}
$order = array_map(static fn (array $w): string => $w['singer'], karaoke_waiting($pdo, (int) $night['id']));
eq($order, ['A1', 'B1', 'C1', 'A2', 'B2', 'A3'], 'rotación entre mesas, llegada dentro de cada mesa');
throws(static fn () => req($pdo, $tA, $night, ['song_id' => $sid, 'singer' => 'A4']), 'too_many_pending', 'tope de 3 pendientes por mesa');

// Se envían A1, B1, C1 (buffer vacío) y llega la mesa D: entra en la ronda en curso, antes de los
// segundos turnos de las demás (no ha cantado ninguna); C2 va después de A2 y B2.
poll($pdo, []);
$tD = karaoke_table($pdo, karaoke_table_save($pdo, null, 4, 'D'));
req($pdo, $tD, $night, ['song_id' => $sid, 'singer' => 'D1']);
karaoke_clock(karaoke_clock() + 1);
req($pdo, $tC, $night, ['song_id' => $sid, 'singer' => 'C2']);
$order = array_map(static fn (array $w): string => $w['singer'], karaoke_waiting($pdo, (int) $night['id']));
eq($order, ['D1', 'A2', 'B2', 'C2', 'A3'], 'una mesa nueva canta antes que el segundo turno de las demás');

// Reordenar desde el panel.
$w = karaoke_waiting($pdo, (int) $night['id']);
karaoke_request_move($pdo, $w[2]['id'], -1);
$order = array_map(static fn (array $w): string => $w['singer'], karaoke_waiting($pdo, (int) $night['id']));
eq($order, ['D1', 'B2', 'A2', 'C2', 'A3'], 'subir un pedido intercambia el turno con el anterior');
karaoke_request_move($pdo, $w[0]['id'], -1);
eq(karaoke_waiting($pdo, (int) $night['id'])[0]['singer'], 'D1', 'el primero no sube más');

// Límite por minuto por mesa y por IP.
karaoke_save_setting($pdo, 'karaoke_max_pending', '20');
$pdo->exec('DELETE FROM karaoke_rate_events');
$tE = karaoke_table($pdo, karaoke_table_save($pdo, null, 5, 'E'));
for ($i = 0; $i < 4; $i++) {
    req($pdo, $tE, $night, ['song_id' => $sid]);
}
throws(static fn () => req($pdo, $tE, $night, ['song_id' => $sid]), 'rate_request', '4 pedidos por minuto por mesa');
karaoke_clock(karaoke_clock() + 61);
eq(req($pdo, $tE, $night, ['song_id' => $sid])['status'], 'en_espera', 'pasado el minuto se puede volver a pedir');
$tF = karaoke_table($pdo, karaoke_table_save($pdo, null, 6, 'F'));
$tG = karaoke_table($pdo, karaoke_table_save($pdo, null, 7, 'G'));
$mk = static fn (array $t) => karaoke_request_create($pdo, $t, $night, ['id' => uuid(), 'singer' => 'X', 'song_id' => $sid], null, 'misma-ip');
for ($i = 0; $i < 3; $i++) {
    $mk($tF);
    $mk($tG);
}
throws(static fn () => $mk($tF), 'rate_request', '6 pedidos por minuto por IP aunque cambie de mesa');

// ---------------------------------------------------------------------------
section('Buffer corto: enqueue solo con menos de 3 en KaraFun');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$t2 = karaoke_table($pdo, karaoke_table_save($pdo, null, 2, ''));
$t3 = karaoke_table($pdo, karaoke_table_save($pdo, null, 3, ''));
$sid = add_song($pdo, 'Juanes', 'A Dios le pido', 215);
$ids = [];
foreach ([$t1, $t2, $t3, $t1, $t2] as $t) {
    $ids[] = req($pdo, $t, $night, ['song_id' => $sid])['id'];
}
$out = poll($pdo, [], false);
eq($out['commands'], [], 'con KaraFun desconectado no se envía nada');
$out = poll($pdo, []);
eq(count($out['commands']), 3, 'cola vacía: 3 órdenes enqueue');
eq(array_column($out['commands'], 'type'), ['enqueue', 'enqueue', 'enqueue'], 'tipo enqueue');
$c0 = $out['commands'][0];
eq($c0['payload']['request_id'], $ids[0], 'la primera orden es el primer turno');
eq($c0['payload']['song'], ['natural_key' => 'juanes|a dios le pido|215', 'title' => 'A Dios le pido', 'artist' => 'Juanes', 'duration_s' => 215, 'source' => 'local'], 'payload.song según el contrato (v1.1: source, sin kf_id si es local)');
eq($c0['payload']['singer'], 'Ana · ' . karaoke_request($pdo, $ids[0])['marker'], 'payload.singer con marcador');
check((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-05:00$/', $c0['lease_until']), 'lease_until en ISO 8601 con zona de Bogotá');
eq(status_of($pdo, $ids[0]), 'enviado', 'pedido enviado');
$out = poll($pdo, []);
eq($out['commands'], [], 'lo enviado que KaraFun aún no muestra cuenta como cola: no se envía de más');
$cmds = $pdo->query("SELECT id, request_id FROM karaoke_commands WHERE type = 'enqueue' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($cmds as $cid => $rid) {
    karaoke_agent_dispatch($pdo, 'ack', ['command_id' => (int) $cid, 'ok' => true, 'result' => ['queue_pos' => array_search($rid, $ids, true)]]);
}
$queue = [entry($pdo, $ids[0], 0, 'playing'), entry($pdo, $ids[1], 1), entry($pdo, $ids[2], 2)];
$out = poll($pdo, $queue);
eq([status_of($pdo, $ids[0]), status_of($pdo, $ids[1]), status_of($pdo, $ids[2])], ['cantando', 'en_cola', 'en_cola'], 'la cola real mueve a cantando / en_cola');
eq($out['commands'], [], 'con 3 en KaraFun no se envía nada');
$queue = [entry($pdo, $ids[1], 0, 'playing'), entry($pdo, $ids[2], 1)];
$out = poll($pdo, $queue);
eq(status_of($pdo, $ids[0]), 'cantada', 'la que sonaba desaparece: cantada');
eq(status_of($pdo, $ids[1]), 'cantando', 'la siguiente pasa a cantando');
eq(count($out['commands']), 1, 'baja a 2 en KaraFun: una orden más');
eq($out['commands'][0]['payload']['request_id'], $ids[3], 'el siguiente turno');

// El encargado quita a mano una canción que tenía otra delante: retirado.
karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $out['commands'][0]['id'], 'ok' => true, 'result' => ['queue_pos' => 2]]);
$queue = [entry($pdo, $ids[1], 0, 'playing'), entry($pdo, $ids[2], 1), entry($pdo, $ids[3], 2)];
poll($pdo, $queue);
$queue = [entry($pdo, $ids[1], 0, 'playing'), entry($pdo, $ids[3], 1)];
$out = poll($pdo, $queue);
eq(status_of($pdo, $ids[2]), 'retirado', 'quitada a mano en KaraFun: retirado');
eq(array_column(array_column($out['commands'], 'payload'), 'request_id'), [$ids[4]], 'el hueco se rellena con el siguiente turno');
$st = $pdo->prepare('SELECT kf_queue_pos FROM karaoke_requests WHERE id = ?');
$st->execute([$ids[3]]);
eq((int) $st->fetchColumn(), 1, 'kf_queue_pos sigue a la cola real');
// Entradas que el encargado añadió a mano (sin marcador) cuentan para el buffer.
$ids[] = req($pdo, $t3, $night, ['song_id' => $sid])['id'];
$manual = ['pos' => 2, 'title' => 'Manual', 'artist' => 'X', 'singer' => 'Pedro', 'status' => 'ready'];
$out = poll($pdo, [entry($pdo, $ids[1], 0, 'playing'), entry($pdo, $ids[3], 1), $manual]);
eq($out['commands'], [], 'una canción puesta a mano también ocupa el buffer');
eq(status_of($pdo, $ids[5]), 'en_espera', 'el resto sigue esperando en la nube');
// Enviado y confirmado, pero KaraFun nunca lo muestra.
eq(status_of($pdo, $ids[4]), 'enviado', 'enviado y sin aparecer en KaraFun');
$enq = $pdo->prepare("SELECT id FROM karaoke_commands WHERE request_id = ? AND type = 'enqueue'");
$enq->execute([$ids[4]]);
karaoke_agent_dispatch($pdo, 'ack', ['command_id' => (int) $enq->fetchColumn(), 'ok' => true, 'result' => ['queue_pos' => 2]]);
karaoke_clock(karaoke_clock() + KARAOKE_SENT_TIMEOUT_S - 5);
poll($pdo, [entry($pdo, $ids[1], 0, 'playing'), entry($pdo, $ids[3], 1), $manual]);
eq(status_of($pdo, $ids[4]), 'enviado', 'antes de ' . KARAOKE_SENT_TIMEOUT_S . ' s sigue enviado');
karaoke_clock(karaoke_clock() + 10);
$out = poll($pdo, [entry($pdo, $ids[1], 0, 'playing'), entry($pdo, $ids[3], 1)]);
eq(status_of($pdo, $ids[4]), 'fallido', 'enviado que nunca aparece en KaraFun termina en fallido con motivo');
eq(array_column(array_column($out['commands'], 'payload'), 'request_id'), [$ids[5]], 'y su hueco pasa al siguiente');

// ---------------------------------------------------------------------------
section('Outbox: lease de 30 s, reentrega y máximo 5 intentos');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$sid = add_song($pdo, 'Joe Arroyo', 'La rebelión');
$rid = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$first = poll($pdo, [])['commands'];
eq(count($first), 1, 'una orden entregada');
$cid = $first[0]['id'];
eq(poll($pdo, [])['commands'], [], 'con lease vigente no se reentrega');
karaoke_clock(karaoke_clock() + KARAOKE_LEASE_S - 1);
eq(poll($pdo, [])['commands'], [], 'a los 29 s sigue arrendada');
karaoke_clock(karaoke_clock() + 2);
$again = poll($pdo, [])['commands'];
eq(array_column($again, 'id'), [$cid], 'vencido el lease se reentrega la misma orden (mismo id)');
for ($i = 3; $i <= KARAOKE_MAX_ATTEMPTS; $i++) {
    karaoke_clock(karaoke_clock() + KARAOKE_LEASE_S + 1);
    eq(array_column(poll($pdo, [])['commands'], 'id'), [$cid], "intento $i");
}
eq((int) karaoke_command($pdo, $cid)['attempts'], KARAOKE_MAX_ATTEMPTS, 'attempts = 5');
karaoke_clock(karaoke_clock() + KARAOKE_LEASE_S + 1);
eq(poll($pdo, [])['commands'], [], 'tras 5 intentos no se vuelve a entregar');
eq(karaoke_command($pdo, $cid)['status'], 'failed', 'la orden queda failed');
eq(status_of($pdo, $rid), 'fallido', 'y el pedido fallido con motivo');
check((string) karaoke_request($pdo, $rid)['error'] !== '', 'motivo guardado');
$late = karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $cid, 'ok' => true, 'result' => ['queue_pos' => 0]]);
check($late['duplicate'] ?? false, 'un ack tardío de una orden ya fallida no cambia nada');

// El agente añade la canción y se cae antes de confirmar; la canción suena y termina antes de
// que venza el lease. La orden no se reentrega: volver a añadirla la haría sonar dos veces.
$rid2 = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$c2 = poll($pdo, [])['commands'][0]['id'];
poll($pdo, [entry($pdo, $rid2, 0, 'playing')]);
poll($pdo, []);
eq(status_of($pdo, $rid2), 'cantada', 'sin ack, la cola real la llevó hasta cantada');
karaoke_clock(karaoke_clock() + KARAOKE_LEASE_S + 1);
eq(array_column(poll($pdo, [])['commands'], 'id'), [], 'vencido el lease, la orden ya no se reentrega');
eq(karaoke_command($pdo, $c2)['status'], 'done', 'queda done');
eq(json_decode(karaoke_command($pdo, $c2)['result'], true), ['skipped' => 'request_cantada'], 'con el motivo');
eq(karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c2, 'ok' => true, 'result' => ['queue_pos' => 0]])['duplicate'] ?? false, true, 'el ack tardío es un duplicado sin efectos');

// Descarga que ya nadie espera: no se entrega y la próxima petición del mismo video la reintenta.
$yr = karaoke_request_create($pdo, $t1, $night, ['id' => uuid(), 'singer' => 'Ana', 'youtube_url' => 'https://youtu.be/ccccccccccc'], static fn () => ['exists' => true, 'title' => null]);
karaoke_request_cancel($pdo, $yr['id'], 'mesa', (int) $t1['id']);
eq(array_column(poll($pdo, [])['commands'], 'type'), [], 'la descarga cancelada por todos no se entrega');
eq(karaoke_download($pdo, 'ccccccccccc')['status'], 'failed', 'y queda lista para reintentarse');

// Máximo 10 órdenes por poll, ordenadas por id.
for ($i = 0; $i < 12; $i++) {
    karaoke_command_create($pdo, 'catalog.resync', []);
}
$batch = poll($pdo, [], false)['commands'];
eq(count($batch), KARAOKE_POLL_MAX_COMMANDS, 'como máximo 10 órdenes por poll');
$bids = array_column($batch, 'id');
$sorted = $bids;
sort($sorted);
eq($bids, $sorted, 'ordenadas por id');
eq(count(poll($pdo, [], false)['commands']), 2, 'las 2 restantes en el siguiente poll');
eq(json_encode($batch[0]['payload']), '{}', 'payload vacío se envía como objeto {}');

// ---------------------------------------------------------------------------
section('ack idempotente y reintentos');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$sid = add_song($pdo, 'Carlos Vives', 'La gota fría');
$r1 = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$r2 = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$r3 = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$cmds = poll($pdo, [])['commands'];
[$c1, $c2, $c3] = array_column($cmds, 'id');
$a1 = karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c1, 'ok' => true, 'result' => ['queue_pos' => 0]]);
eq($a1['status'], 'done', 'ack ok');
$before = $pdo->query('SELECT COUNT(*) FROM karaoke_request_log')->fetchColumn();
$a1b = karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c1, 'ok' => true, 'result' => ['queue_pos' => 5]]);
check($a1b['duplicate'] ?? false, 'segundo ack de la misma orden: 200 sin cambios');
eq($pdo->query('SELECT COUNT(*) FROM karaoke_request_log')->fetchColumn(), $before, 'el ack repetido no escribe transiciones');
$st = $pdo->prepare('SELECT kf_queue_pos FROM karaoke_requests WHERE id = ?');
$st->execute([$r1]);
eq((int) $st->fetchColumn(), 0, 'el ack repetido no pisa el resultado');
$ar = karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c2, 'ok' => false, 'retryable' => true, 'error' => ['code' => 'kf_busy', 'message' => 'KaraFun ocupado']]);
eq($ar['status'], 'pending', 'retryable: vuelve a pending');
eq(status_of($pdo, $r2), 'enviado', 'el pedido sigue enviado');
eq(array_column(poll($pdo, [])['commands'], 'id'), [$c2], 'y se reentrega sin esperar el lease');
$af = karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c3, 'ok' => false, 'error' => ['code' => 'not_found', 'message' => 'La canción no está en KaraFun.']]);
eq($af['status'], 'failed', 'sin retryable: failed');
eq(status_of($pdo, $r3), 'fallido', 'el pedido pasa a fallido');
eq(karaoke_request($pdo, $r3)['error'], 'La canción no está en KaraFun.', 'con el mensaje que ve la mesa');
throws(static fn () => karaoke_agent_dispatch($pdo, 'ack', ['command_id' => 99999, 'ok' => true]), 'unknown_command', 'ack de una orden que no existe');
throws(static fn () => karaoke_agent_dispatch($pdo, 'ack', ['command_id' => '1', 'ok' => true]), 'bad_ack', 'command_id como texto');
throws(static fn () => karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c2]), 'bad_ack', 'ack sin ok');
throws(static fn () => karaoke_agent_dispatch($pdo, 'nada', []), 'unknown_action', 'acción desconocida');
throws(static fn () => karaoke_agent_dispatch($pdo, 'poll', []), 'bad_poll', 'poll sin agent_version');
throws(static fn () => karaoke_agent_dispatch($pdo, 'poll', ['agent_version' => '1', 'karafun' => ['connected' => true, 'queue' => 'x']]), 'bad_poll', 'poll con cola que no es lista');
// Quitar desde el panel: orden remove y retirado al confirmarse.
karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $c2, 'ok' => true, 'result' => ['queue_pos' => 1]]);
poll($pdo, [entry($pdo, $r1, 0, 'playing'), entry($pdo, $r2, 1)]);
karaoke_request_remove($pdo, $r2);
karaoke_request_remove($pdo, $r2);
$rm = poll($pdo, [entry($pdo, $r1, 0, 'playing'), entry($pdo, $r2, 1)])['commands'];
eq(array_column($rm, 'type'), ['remove'], 'una sola orden remove aunque se pulse dos veces');
eq($rm[0]['payload'], ['request_id' => $r2, 'singer' => karaoke_singer_label(karaoke_request($pdo, $r2))], 'payload remove según el contrato');
karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $rm[0]['id'], 'ok' => true, 'result' => ['removed' => true]]);
eq(status_of($pdo, $r2), 'retirado', 'remove confirmado: retirado');

// ---------------------------------------------------------------------------
section('YouTube: descarga, reutilización y fallos');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$t2 = karaoke_table($pdo, karaoke_table_save($pdo, null, 2, ''));
$yt = 'https://youtu.be/dQw4w9WgXcQ?si=x';
$checks = 0;
$oembed = static function (string $id) use (&$checks): array {
    $checks++;
    return ['exists' => true, 'title' => 'Rick Astley - Never Gonna Give You Up'];
};
$y1 = karaoke_request_create($pdo, $t1, $night, ['id' => uuid(), 'singer' => 'Ana', 'youtube_url' => $yt], $oembed);
eq($y1['status'], 'descargando', 'enlace válido: descargando');
eq($y1['youtube_id'], 'dQw4w9WgXcQ', 'se guarda solo el id');
eq((int) $pdo->query("SELECT COUNT(*) FROM karaoke_requests WHERE youtube_id LIKE '%youtu%'")->fetchColumn(), 0, 'nunca la URL del cliente');
$y2 = karaoke_request_create($pdo, $t2, $night, ['id' => uuid(), 'singer' => 'Beto', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], $oembed);
eq($y2['status'], 'descargando', 'segunda mesa con el mismo video: espera la misma descarga');
eq($checks, 1, 'oEmbed solo para la primera');
$dl = poll($pdo, [])['commands'];
eq(array_column($dl, 'type'), ['download'], 'una sola orden download');
eq($dl[0]['payload'], ['request_id' => $y1['id'], 'youtube_id' => 'dQw4w9WgXcQ', 'max_duration_s' => 480], 'payload download según el contrato');
throws(static fn () => karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $dl[0]['id'], 'ok' => true, 'result' => ['youtube_id' => 'dQw4w9WgXcQ']]), 'bad_song', 'ack de descarga sin canción: 422 y la orden sigue pendiente');
$ytSong = song('Rick Astley', 'Never Gonna Give You Up', 213, 'Por aprobar');
karaoke_agent_dispatch($pdo, 'song.upsert', ['song' => $ytSong]);
karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $dl[0]['id'], 'ok' => true, 'result' => ['youtube_id' => 'dQw4w9WgXcQ', 'song' => $ytSong]]);
eq([status_of($pdo, $y1['id']), status_of($pdo, $y2['id'])], ['en_espera', 'en_espera'], 'descarga terminada: las dos mesas a en_espera');
$log = $pdo->prepare('SELECT to_status FROM karaoke_request_log WHERE request_id = ? ORDER BY id');
$log->execute([$y1['id']]);
eq($log->fetchAll(PDO::FETCH_COLUMN), ['descargando', 'descargado', 'en_espera'], 'pasa por descargado');
eq(karaoke_count_por_aprobar($pdo), 1, 'una canción en Por aprobar');
eq(karaoke_search($pdo, 'never gonna')[0]['from_youtube'], true, 'la búsqueda la marca como de YouTube');
$checks = 0;
$y3 = karaoke_request_create($pdo, $t1, $night, ['id' => uuid(), 'singer' => 'Ana', 'youtube_url' => $yt], $oembed);
eq($y3['status'], 'en_espera', 'un video ya descargado no se vuelve a bajar');
eq($checks, 0, 'ni se consulta oEmbed');
throws(static fn () => karaoke_request_create($pdo, $t2, $night, ['id' => uuid(), 'singer' => 'Beto', 'youtube_url' => 'https://youtu.be/aaaaaaaaaaa'], static fn () => ['exists' => false, 'title' => null]), 'youtube_missing', 'video inexistente o privado: error al instante');
throws(static fn () => karaoke_request_create($pdo, $t2, $night, ['id' => uuid(), 'singer' => 'Beto', 'youtube_url' => 'Despacito']), 'youtube_not_url', 'un nombre de canción se rechaza');
$y4 = karaoke_request_create($pdo, $t2, $night, ['id' => uuid(), 'singer' => 'Beto', 'youtube_url' => 'https://youtu.be/bbbbbbbbbbb'], static fn () => ['exists' => null, 'title' => null]);
eq($y4['status'], 'descargando', 'si oEmbed no responde, se acepta y decide el agente');
$dl = array_values(array_filter(poll($pdo, [entry($pdo, $y1['id'], 0, 'playing'), entry($pdo, $y2['id'], 1), entry($pdo, $y3['id'], 2)])['commands'], static fn ($c) => $c['type'] === 'download'));
karaoke_agent_dispatch($pdo, 'ack', ['command_id' => $dl[0]['id'], 'ok' => false, 'error' => ['code' => 'too_long', 'message' => 'El video dura 12 min (máximo 8).']]);
eq(status_of($pdo, $y4['id']), 'fallido', 'descarga rechazada: fallido');
eq(karaoke_request($pdo, $y4['id'])['error'], 'El video dura 12 min (máximo 8).', 'con el motivo del agente');
eq(karaoke_download($pdo, 'bbbbbbbbbbb')['status'], 'failed', 'descarga marcada failed');
$y5 = karaoke_request_create($pdo, $t2, $night, ['id' => uuid(), 'singer' => 'Beto', 'youtube_url' => 'https://youtu.be/bbbbbbbbbbb'], static fn () => ['exists' => true, 'title' => null]);
eq($y5['status'], 'descargando', 'pedir de nuevo un video fallido reintenta la descarga');
eq(karaoke_download($pdo, 'bbbbbbbbbbb')['status'], 'downloading', 'descarga reactivada');

// ---------------------------------------------------------------------------
section('Catálogo por lotes con commit (§9)');
$pdo = fresh_db();
$cat = [];
for ($i = 0; $i < 1203; $i++) {
    $cat[] = song('Artista ' . intdiv($i, 10), "Canción número $i", 150 + $i % 200);
}
$cat[5] = song('Adriana Lucía', 'Quisiera olvidarte', 187);
$sync = karaoke_agent_dispatch($pdo, 'catalog.begin', []);
check((bool) preg_match('/^[0-9a-f]{32}$/', $sync['sync_id']), 'sync_id');
$chunks = array_chunk($cat, 500);
throws(static fn () => karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 0, 'songs' => array_merge($chunks[0], [$cat[0]])]), 'bad_chunk', 'más de 500 por lote');
$bad = $chunks[0];
$bad[3]['natural_key'] = 'Mal|clave|1';
throws(static fn () => karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 0, 'songs' => $bad]), 'bad_song', 'natural_key fuera de formato');
karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 0, 'songs' => $chunks[0]]);
karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 2, 'songs' => array_slice($chunks[1], 0, 10)]);
throws(static fn () => karaoke_agent_dispatch($pdo, 'catalog.commit', ['sync_id' => $sync['sync_id'], 'total_chunks' => 3, 'total_songs' => 1203]), 'missing_chunks', 'commit con un lote faltante');
eq((int) $pdo->query('SELECT COUNT(*) FROM karaoke_songs')->fetchColumn(), 0, 'una sincronización a medias no activa nada');
karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 1, 'songs' => $chunks[1]]);
karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 2, 'songs' => $chunks[2]]);
karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 2, 'songs' => $chunks[2]]);
eq((int) $pdo->query("SELECT COUNT(*) FROM karaoke_catalog_staging WHERE chunk_index = 2")->fetchColumn(), count($chunks[2]), 'repetir un lote lo reemplaza, no lo duplica');
throws(static fn () => karaoke_agent_dispatch($pdo, 'catalog.commit', ['sync_id' => $sync['sync_id'], 'total_chunks' => 3, 'total_songs' => 1300]), 'count_mismatch', 'commit con total que no cuadra');
$sum = karaoke_agent_dispatch($pdo, 'catalog.commit', ['sync_id' => $sync['sync_id'], 'total_chunks' => 3, 'total_songs' => 1203]);
eq([$sum['distinct'], $sum['added'], $sum['unavailable']], [1203, 1203, 0], 'commit: 1203 canciones activas');
eq(karaoke_agent_dispatch($pdo, 'catalog.commit', ['sync_id' => $sync['sync_id'], 'total_chunks' => 3, 'total_songs' => 1203])['already_committed'], true, 'commit repetido: idempotente');
throws(static fn () => karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync['sync_id'], 'index' => 3, 'songs' => []]), 'sync_closed', 'no se añaden lotes a una sincronización terminada');
eq(karaoke_search($pdo, 'adriana QUISIERA')[0]['title'], 'Quisiera olvidarte', 'búsqueda sin tildes ni mayúsculas');
eq(karaoke_search($pdo, 'lucía olvidarte')[0]['artist'], 'Adriana Lucía', 'búsqueda con tildes');
eq(count(karaoke_search($pdo, 'cancion numero')), 30, 'la búsqueda devuelve como máximo 30');
eq(karaoke_search($pdo, '%'), [], 'comodines de LIKE no buscan todo');

// Segunda sincronización sin la canción 7; mientras tanto llega una descarga suelta.
karaoke_clock(karaoke_clock() + 3600);
$sync2 = karaoke_agent_dispatch($pdo, 'catalog.begin', []);
karaoke_clock(karaoke_clock() + 60);
karaoke_agent_dispatch($pdo, 'song.upsert', ['song' => song('Nuevo', 'Recién bajada', 200, 'Por aprobar')]);
$cat2 = $cat;
unset($cat2[7]);
$cat2[] = $cat[0];          // misma canción en dos archivos
$cat2[] = ['folder' => 'Por aprobar'] + $cat[1];
$cat2 = array_values($cat2);
foreach (array_chunk($cat2, 500) as $i => $ch) {
    karaoke_agent_dispatch($pdo, 'catalog.chunk', ['sync_id' => $sync2['sync_id'], 'index' => $i, 'songs' => $ch]);
}
$sum2 = karaoke_agent_dispatch($pdo, 'catalog.commit', ['sync_id' => $sync2['sync_id'], 'total_chunks' => 3, 'total_songs' => count($cat2)]);
eq($sum2['unavailable'], 1, 'la canción que no vino pasa a available = 0');
$st = $pdo->prepare('SELECT available FROM karaoke_songs WHERE natural_key = ?');
$st->execute([$cat[7]['natural_key']]);
eq((int) $st->fetchColumn(), 0, 'no se borra: available = 0');
$st->execute([karaoke_natural_key('Nuevo', 'Recién bajada', 200)]);
eq((int) $st->fetchColumn(), 1, 'la subida suelta durante la sincronización se conserva');
$st = $pdo->prepare('SELECT folder FROM karaoke_songs WHERE natural_key = ?');
$st->execute([$cat[1]['natural_key']]);
eq($st->fetchColumn(), 'A', 'duplicada en Por aprobar y en su letra: gana la carpeta de letra');
eq((int) $pdo->query('SELECT COUNT(*) FROM karaoke_catalog_staging')->fetchColumn(), 0, 'staging vacío tras el commit');
$w = karaoke_agent_dispatch($pdo, 'song.upsert', ['song' => ['natural_key' => 'otra|cosa|1'] + song('X', 'Y', 1)]);
check(isset($w['warnings']['natural_key_mismatch']), 'aviso si la clave del agente no coincide con la regla');

// ---------------------------------------------------------------------------
section('Catálogo en línea de KaraFun (CSV) y búsqueda rápida');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
add_song($pdo, 'Queen', 'Bohemian Rhapsody', 354);
$csv = tempnam(sys_get_temp_dir(), 'kf');
file_put_contents($csv, implode("\n", [
    "\u{FEFF}" . 'Id;Title;Artist;Year;Duo;Explicit;"Date Added";Styles;Languages',
    '12617;"Bohemian Rhapsody";Queen;1975;0;0;2008-07-21;Rock;English',
    '5632;"Mr. Brightside";"The Killers";2003;0;0;2016-03-21;Rock;English',
    '70001;"Corazón; partío";"Alejandro Sanz";1997;0;0;2010-01-01;Pop;Spanish',
    '70002;"La camisa negra";Juanes;2004;0;0;2010-01-01;Pop;Spanish',
    '70003;"Camisa";"Otro";2004;0;0;2010-01-01;Pop;Spanish',
    'abc;Mala;Fila;;;;;;',
    '70002;"Duplicada";Juanes;2004;0;0;2010-01-01;Pop;Spanish',
]) . "\n");
$sum = karaoke_import_karafun_csv($pdo, $csv);
eq([$sum['songs'], $sum['added'], $sum['skipped']], [5, 5, 2], 'importa 5, salta la fila mala y el id repetido');
$kf = $pdo->query("SELECT natural_key, kf_id, duration_s FROM karaoke_songs WHERE source = 'karafun' AND kf_id = 70001")->fetch();
eq($kf, ['natural_key' => 'karafun:70001', 'kf_id' => 70001, 'duration_s' => 0], 'natural_key karafun:<id>, sin duración');
eq(karaoke_search($pdo, 'corazon partio')[0]['title'], 'Corazón; partío', 'el «;» dentro de comillas no rompe la fila');
eq(array_column(karaoke_search($pdo, 'bohemian'), 'duration_s'), [354, 0], 'la versión local va antes que la de KaraFun en línea');
eq(array_column(karaoke_search($pdo, 'camisa'), 'title'), ['Camisa', 'La camisa negra'], 'título exacto primero');
eq(array_column(karaoke_search($pdo, 'jua cami'), 'title'), ['La camisa negra'], 'prefijos de varias palabras (artista + título)');
eq(karaoke_search($pdo, 'amisa'), [], 'busca por comienzo de palabra, no por pedazos sueltos');
eq(karaoke_search($pdo, 'k'), [], 'una sola letra no busca');
eq(array_column(karaoke_search($pdo, 'killers'), 'artist'), ['The Killers'], 'por artista');
// Pedido de una canción de KaraFun en línea: el enqueue lleva kf_id.
$sidKf = karaoke_search($pdo, 'brightside')[0]['id'];
req($pdo, $t1, $night, ['song_id' => $sidKf]);
$cmd = poll($pdo, [])['commands'][0];
eq($cmd['payload']['song'], ['natural_key' => 'karafun:5632', 'title' => 'Mr. Brightside', 'artist' => 'The Killers', 'duration_s' => 0, 'source' => 'karafun', 'kf_id' => 5632], 'enqueue de KaraFun en línea con source y kf_id');
// Reimportar sin una canción: available = 0; renombrar: se reindexa.
karaoke_clock(karaoke_clock() + 10);
file_put_contents($csv, implode("\n", [
    'Id;Title;Artist',
    '12617;"Bohemian Rhapsody";Queen',
    '5632;"Mr. Brightside (Live)";"The Killers"',
    '70001;"Corazón partío";"Alejandro Sanz"',
    '70002;"La camisa negra";Juanes',
]) . "\n");
$sum = karaoke_import_karafun_csv($pdo, $csv);
eq([$sum['added'], $sum['updated'], $sum['unavailable']], [0, 2, 1], 'reimportar: 2 cambiadas, 1 desaparecida');
eq(karaoke_search($pdo, 'camisa')[0]['title'], 'La camisa negra', 'la desaparecida ya no sale');
eq(karaoke_search($pdo, 'brightside live')[0]['title'], 'Mr. Brightside (Live)', 'el título nuevo se busca');
eq((int) $pdo->query("SELECT COUNT(*) FROM karaoke_songs WHERE source = 'local' AND available = 1")->fetchColumn(), 1, 'la importación no toca las canciones locales');
$sync = karaoke_catalog_begin($pdo);
karaoke_catalog_chunk($pdo, ['sync_id' => $sync['sync_id'], 'index' => 0, 'songs' => [song('Otro', 'Tema', 100)]]);
karaoke_clock(karaoke_clock() + 10);
karaoke_catalog_commit($pdo, ['sync_id' => $sync['sync_id'], 'total_chunks' => 1, 'total_songs' => 1]);
eq((int) $pdo->query("SELECT COUNT(*) FROM karaoke_songs WHERE source = 'karafun' AND available = 1")->fetchColumn(), 4, 'y la sincronización local no toca las de KaraFun');
file_put_contents($csv, "Nombre,Artista\nx,y\n");
throws(static fn () => karaoke_import_karafun_csv($pdo, $csv), 'csv_columns', 'un CSV que no es de KaraFun se rechaza');
unlink($csv);

// ---------------------------------------------------------------------------
section('Noche: cierre cancela lo pendiente');
$pdo = fresh_db();
$night = karaoke_night_open($pdo);
$t1 = karaoke_table($pdo, karaoke_table_save($pdo, null, 1, ''));
$sid = add_song($pdo, 'Silvestre Dangond', 'Materialista');
$ra = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$rb = req($pdo, $t1, $night, ['song_id' => $sid])['id'];
$manual = static fn (int $pos): array => ['pos' => $pos, 'title' => 'a', 'artist' => 'b', 'singer' => "x$pos", 'status' => ''];
poll($pdo, [$manual(0), $manual(1), $manual(2)]);
$mine = karaoke_table_requests($pdo, (int) $night['id'], (int) $t1['id']);
$byId = array_column($mine, null, 'id');
eq($byId[$ra]['turn'], ['ahead' => 3, 'eta_min' => 12], 'turno estimado: 3 canciones de KaraFun delante');
eq($byId[$rb]['turn'], ['ahead' => 4, 'eta_min' => 16], 'el siguiente suma la duración de la anterior');
eq($byId[$rb]['cancellable'], true, 'se puede cancelar mientras espera');
eq(karaoke_night_close($pdo), 2, 'cerrar la noche cancela lo que esperaba');
eq(status_of($pdo, $rb), 'cancelado', 'pedido cancelado por el sistema');
eq(karaoke_current_night($pdo), null, 'sin noche abierta');
eq(karaoke_fill_buffer($pdo, []), 0, 'sin noche no se envía nada');

// ---------------------------------------------------------------------------
karaoke_clock(null, true);
$total = $passed + count($failed);
echo "\n$passed de $total comprobaciones correctas.\n";
if ($failed) {
    echo count($failed) . " fallaron.\n";
    exit(1);
}
