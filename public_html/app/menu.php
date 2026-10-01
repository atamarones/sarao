<?php
declare(strict_types=1);

/**
 * Consultas de lectura del menú y ajustes, compartidas por la carta pública y el panel.
 */

const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
const WEEKDAYS_SHORT = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

const DEFAULT_SETTINGS = [
    'business_name' => 'El Sarao Pub',
    'tagline' => 'Karaoke, rumba y cócteles en Bogotá',
    'address' => 'Cra 5 #17-69, Bogotá',
    'phone' => '+573163936616',
    'whatsapp' => '573163936616',
    'instagram' => 'elsaraopub',
    'tiktok' => 'elsaraopub',
    'facebook' => 'https://www.facebook.com/people/El-Sarao/100067084803215',
    'email' => 'saraopub@gmail.com',
    'reservation_url' => 'https://aima-n8n.yau1cn.easypanel.host/form/637e1a7f-f598-4824-b67e-a1c39c26953c',
    'reviews_url' => 'https://www.google.com/maps/place/El+Sarao+Pub+-+Karaoke+Bar/@4.6032742,-74.0707668,17z/data=!4m8!3m7!1s0x8e3f99a8c8d17093:0x8f560dbc46b46a00!8m2!3d4.6032742!4d-74.0707668!9m1!1b1',
    'songs_count' => '8.000',
    'website' => 'https://saraopub.com/',
    'maps_url' => 'https://maps.google.com/?q=4.603288,-74.070768',
    'notice' => '',
    'currency_note' => 'Precios en pesos colombianos (COP).',
    'hours' => '{"1":["18:00","23:00"],"2":["18:00","23:00"],"3":["18:00","23:00"],"4":["18:00","01:00"],"5":["18:00","03:00"],"6":["18:00","03:00"],"7":null}',
];

function settings(): array
{
    $rows = db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge(DEFAULT_SETTINGS, $rows);
}

function save_setting(string $key, string $value): void
{
    $sql = db_driver() === 'mysql'
        ? 'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        : 'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v';
    db()->prepare($sql)->execute([$key, $value]);
}

function hours_from_settings(array $s): array
{
    $h = json_decode($s['hours'] ?? '', true);
    return is_array($h) ? $h : json_decode(DEFAULT_SETTINGS['hours'], true);
}

/**
 * Estado de apertura. Un cierre menor o igual a la apertura cruza la medianoche,
 * así que el jueves 17:00-01:00 sigue abierto el viernes a la 1 a. m.
 */
function open_status(array $hours, ?DateTimeImmutable $at = null): array
{
    $at ??= new DateTimeImmutable('now');
    $dow = (int) $at->format('N');
    $mins = (int) $at->format('G') * 60 + (int) $at->format('i');
    $toMin = static fn (string $t): int => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);

    $prev = $dow === 1 ? 7 : $dow - 1;
    $p = $hours[(string) $prev] ?? null;
    if ($p && $toMin($p[1]) <= $toMin($p[0]) && $mins < $toMin($p[1])) {
        return ['open' => true, 'until' => $p[1]];
    }
    $t = $hours[(string) $dow] ?? null;
    if ($t) {
        $o = $toMin($t[0]);
        $c = $toMin($t[1]);
        if ($mins >= $o && ($c <= $o || $mins < $c)) {
            return ['open' => true, 'until' => $t[1]];
        }
        if ($mins < $o) {
            return ['open' => false, 'opens' => $t[0], 'day' => 'hoy'];
        }
    }
    for ($i = 1; $i <= 7; $i++) {
        $d = (($dow - 1 + $i) % 7) + 1;
        if (!empty($hours[(string) $d])) {
            return ['open' => false, 'opens' => $hours[(string) $d][0], 'day' => $i === 1 ? 'mañana' : mb_strtolower(WEEKDAYS[$d])];
        }
    }
    return ['open' => false];
}

function format_time(string $hhmm): string
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    $suffix = $h >= 12 ? 'p. m.' : 'a. m.';
    $h12 = $h % 12 ?: 12;
    return $m ? sprintf('%d:%02d %s', $h12, $m, $suffix) : "$h12 $suffix";
}

function format_days(string $csv): string
{
    $days = array_values(array_filter(array_map('intval', explode(',', $csv))));
    if (!$days) {
        return '';
    }
    if (count($days) === 7) {
        return 'Todos los días';
    }
    sort($days);
    $isRange = count($days) > 2 && end($days) - $days[0] === count($days) - 1;
    if ($isRange) {
        return WEEKDAYS_SHORT[$days[0]] . ' a ' . WEEKDAYS_SHORT[end($days)];
    }
    return implode(', ', array_map(static fn ($d) => WEEKDAYS_SHORT[$d], $days));
}

/** Menú público: solo categorías y productos activos, con sus presentaciones activas. */
function public_menu(): array
{
    $cats = db()->query('SELECT id, name, slug, description FROM categories WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    $prods = db()->query('SELECT p.id, p.category_id, p.name, p.description, p.price, p.badge, p.image, p.is_featured
        FROM products p JOIN categories c ON c.id = p.category_id
        WHERE p.is_active = 1 AND c.is_active = 1 ORDER BY p.sort_order, p.id')->fetchAll();
    $vars = db()->query('SELECT v.product_id, v.label, v.detail, v.note, v.price FROM product_variants v
        JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 AND p.is_active = 1 ORDER BY v.sort_order, v.id')->fetchAll();

    $byProduct = [];
    foreach ($vars as $v) {
        $v['price'] = (int) $v['price'];
        $byProduct[$v['product_id']][] = $v;
    }
    $byCat = [];
    foreach ($prods as $p) {
        $p['price'] = $p['price'] === null ? null : (int) $p['price'];
        $p['variants'] = $byProduct[$p['id']] ?? [];
        $byCat[$p['category_id']][] = $p;
    }
    $out = [];
    foreach ($cats as $c) {
        if (!empty($byCat[$c['id']])) {
            $c['products'] = $byCat[$c['id']];
            $out[] = $c;
        }
    }
    return $out;
}

function active_promotions(): array
{
    return db()->query('SELECT id, title, detail, days, time_from, time_to FROM promotions WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

/** URL relativa de una foto de producto; $base ajusta la ruta desde subcarpetas (por ejemplo, '../'). */
function active_testimonials(): array
{
    return db()->query('SELECT author, body, rating, source FROM testimonials WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

function image_url(?string $file, string $base = ''): ?string
{
    return $file ? $base . UPLOAD_URL . '/' . rawurlencode($file) : null;
}
