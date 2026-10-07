<?php
declare(strict_types=1);

/**
 * API JSON del panel. Toda llamada exige sesión de administrador; las escrituras exigen
 * además POST + cabecera X-CSRF-Token. Errores en formato RFC 7807 (application/problem+json).
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/menu.php';
require __DIR__ . '/../app/upgrade.php';
require __DIR__ . '/../app/pos_sync.php';
require __DIR__ . '/../app/karaoke.php';
require __DIR__ . '/../app/markdown.php';

security_headers();
header('Cache-Control: no-store');

final class ApiError extends RuntimeException
{
    public function __construct(string $detail, public readonly int $status = 422, public readonly array $fields = [])
    {
        parent::__construct($detail);
    }
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function problem(int $status, string $title, string $detail, array $fields = []): never
{
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(array_filter(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'fields' => $fields ?: null]), JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array
{
    static $in = null;
    if ($in === null) {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        $in = str_starts_with($ct, 'application/json')
            ? (json_decode((string) file_get_contents('php://input'), true) ?: [])
            : $_POST;
    }
    return $in;
}

function str_field(string $key, int $max, bool $required = false, string $label = ''): ?string
{
    $v = trim((string) (input()[$key] ?? ''));
    if ($v === '') {
        if ($required) {
            throw new ApiError("Escribe $label.", 422, [$key => "Escribe $label."]);
        }
        return null;
    }
    if (mb_strlen($v) > $max) {
        throw new ApiError("$label admite máximo $max caracteres.", 422, [$key => "Máximo $max caracteres."]);
    }
    return $v;
}

function int_field(string $key): ?int
{
    $v = input()[$key] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v) || (int) $v < 0) {
        throw new ApiError('Revisa los valores numéricos.', 422, [$key => 'Debe ser un número positivo.']);
    }
    return (int) $v;
}

function bool_field(string $key, bool $default = false): int
{
    $v = input()[$key] ?? null;
    if ($v === null) {
        return $default ? 1 : 0;
    }
    return filter_var($v, FILTER_VALIDATE_BOOL) ? 1 : 0;
}

function parse_price(mixed $v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    $digits = preg_replace('/\D+/', '', (string) $v);
    if ($digits === '' || strlen($digits) > 9) {
        throw new ApiError('Revisa el precio: usa solo números, por ejemplo 25000.');
    }
    return (int) $digits;
}

function require_row(string $table, int $id): array
{
    $st = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        throw new ApiError('El elemento ya no existe. Recarga el panel.', 404);
    }
    return $row;
}

function next_order(string $table, string $where = '1=1', array $params = []): int
{
    $st = db()->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM $table WHERE $where");
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function reorder(string $table, array $ids, string $scope = '', array $scopeParams = []): void
{
    $st = db()->prepare("UPDATE $table SET sort_order = ? WHERE id = ?" . ($scope ? " AND $scope" : ''));
    foreach (array_values($ids) as $i => $id) {
        $st->execute(array_merge([($i + 1) * 10, (int) $id], $scopeParams));
    }
}

/** Valida, reescala y re-codifica la imagen a WebP: descarta metadatos y cualquier contenido incrustado. */
function store_upload(array $file, string $baseName): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'La imagen pesa demasiado. Sube una de máximo 8 MB.'
            : 'No se pudo subir la imagen. Inténtalo de nuevo.');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new ApiError('La imagen pesa demasiado. Sube una de máximo 8 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $loaders = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp', 'image/gif' => 'imagecreatefromgif'];
    if (!isset($loaders[$mime])) {
        throw new ApiError('Formato no admitido. Sube una imagen JPG, PNG, WebP o GIF.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[0] * $info[1] > 40_000_000) {
        throw new ApiError('La imagen no es válida o es demasiado grande en píxeles.');
    }
    $src = @$loaders[$mime]($file['tmp_name']);
    if (!$src) {
        throw new ApiError('No se pudo leer la imagen. Prueba exportarla de nuevo como JPG o PNG.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $src = match ($o) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src };
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 1000 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = substr(slugify($baseName), 0, 60) . '-' . bin2hex(random_bytes(4)) . '.webp';
    if (!imagewebp($dst, UPLOAD_DIR . '/' . $name, 82)) {
        throw new ApiError('No se pudo guardar la imagen en el servidor.', 500);
    }
    return $name;
}

function delete_upload(?string $name): void
{
    if ($name && preg_match('/^[a-z0-9-]+\.webp$/', $name)) {
        $path = UPLOAD_DIR . '/' . $name;
        // Solo se borra si ningún otro producto la usa (por ejemplo, un duplicado).
        $st = db()->prepare('SELECT COUNT(*) FROM products WHERE image = ?');
        $st->execute([$name]);
        if ((int) $st->fetchColumn() === 0 && is_file($path)) {
            unlink($path);
        }
    }
}

function state(): array
{
    $pdo = db();
    $cats = $pdo->query('SELECT c.id, c.name, c.slug, c.description, c.is_active, c.sort_order,
        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
        FROM categories c ORDER BY c.sort_order, c.id')->fetchAll();
    $prods = $pdo->query('SELECT id, category_id, name, description, price, badge, image, is_active, is_featured, sort_order, updated_at FROM products ORDER BY sort_order, id')->fetchAll();
    $vars = $pdo->query('SELECT id, product_id, label, detail, note, price, is_active FROM product_variants ORDER BY sort_order, id')->fetchAll();
    $byP = [];
    foreach ($vars as $v) {
        $v = array_merge($v, ['id' => (int) $v['id'], 'price' => (int) $v['price'], 'is_active' => (bool) $v['is_active']]);
        $byP[$v['product_id']][] = $v;
    }
    foreach ($cats as &$c) {
        $c = array_merge($c, ['id' => (int) $c['id'], 'is_active' => (bool) $c['is_active'], 'product_count' => (int) $c['product_count']]);
    }
    foreach ($prods as &$p) {
        $p = array_merge($p, [
            'id' => (int) $p['id'],
            'category_id' => (int) $p['category_id'],
            'price' => $p['price'] === null ? null : (int) $p['price'],
            'is_active' => (bool) $p['is_active'],
            'is_featured' => (bool) $p['is_featured'],
            'image_url' => image_url($p['image']),
            'variants' => $byP[$p['id']] ?? [],
        ]);
    }
    $promos = $pdo->query('SELECT id, title, detail, days, time_from, time_to, is_active FROM promotions ORDER BY sort_order, id')->fetchAll();
    foreach ($promos as &$pr) {
        $pr = array_merge($pr, ['id' => (int) $pr['id'], 'is_active' => (bool) $pr['is_active']]);
    }
    $tests = $pdo->query('SELECT id, author, body, rating, source, is_active FROM testimonials ORDER BY sort_order, id')->fetchAll();
    foreach ($tests as &$t) {
        $t = array_merge($t, ['id' => (int) $t['id'], 'rating' => (int) $t['rating'], 'is_active' => (bool) $t['is_active']]);
    }
    $s = public_settings(settings());
    $s['hours'] = hours_from_settings($s);
    $st = $pdo->prepare('SELECT password_hash FROM admins WHERE username = ? AND role = ?');
    $st->execute([OPERATOR_USERNAME, ROLE_OPERATOR]);
    $opHash = $st->fetchColumn();
    $operator = ['username' => OPERATOR_USERNAME, 'exists' => $opHash !== false, 'has_password' => $opHash !== false && $opHash !== NO_PASSWORD];
    return ['categories' => $cats, 'products' => $prods, 'promotions' => $promos, 'testimonials' => $tests, 'settings' => $s, 'pos' => pos_status(), 'operator' => $operator];
}

/** Estado del karaoke para el panel (se consulta cada pocos segundos en la pestaña Karaoke). */
function karaoke_state(): array
{
    $pdo = db();
    $night = karaoke_current_night($pdo);
    $base = karaoke_public_base();
    $tables = array_map(static fn (array $t): array => [
        'id' => (int) $t['id'],
        'number' => (int) $t['number'],
        'name' => $t['name'],
        'is_active' => (bool) $t['is_active'],
        'url' => $base . '?m=' . $t['qr_token'],
    ], $pdo->query('SELECT * FROM karaoke_tables ORDER BY number')->fetchAll());

    $row = static fn (array $r): array => [
        'id' => $r['id'],
        'table' => $r['table_name'],
        'singer' => $r['singer'],
        'status' => $r['status'],
        'title' => $r['title'] ?? $r['yt_title'] ?? ($r['youtube_id'] ? 'Video de YouTube ' . $r['youtube_id'] : 'Canción'),
        'artist' => $r['artist'] ?? null,
        'source' => $r['source'] ?? null,
        'youtube_id' => $r['youtube_id'],
        'error' => $r['error'],
        'queue_pos' => $r['kf_queue_pos'] === null ? null : (int) $r['kf_queue_pos'],
        'created_at' => karaoke_iso($r['created_at']),
        'updated_at' => karaoke_iso($r['updated_at']),
    ];
    $select = 'SELECT r.*, t.name AS table_name, s.title, s.artist, s.source, d.title AS yt_title FROM karaoke_requests r
        JOIN karaoke_tables t ON t.id = r.table_id LEFT JOIN karaoke_songs s ON s.id = r.song_id LEFT JOIN karaoke_downloads d ON d.youtube_id = r.youtube_id';
    $inKf = $pdo->query("$select WHERE r.status IN ('enviado', 'en_cola', 'cantando') ORDER BY COALESCE(r.kf_queue_pos, 99), r.sent_at")->fetchAll();
    $waiting = $night ? karaoke_waiting($pdo, (int) $night['id']) : [];
    $failed = [];
    if ($night) {
        $st = $pdo->prepare("$select WHERE r.night_id = ? AND r.status IN ('fallido', 'retirado') ORDER BY r.updated_at DESC LIMIT 30");
        $st->execute([$night['id']]);
        $failed = $st->fetchAll();
    }
    $counts = $pdo->query('SELECT source, COUNT(*) AS n FROM karaoke_songs WHERE available = 1 GROUP BY source')->fetchAll(PDO::FETCH_KEY_PAIR);
    $lastSync = static function (string $source) use ($pdo): ?array {
        $st = $pdo->prepare("SELECT summary, committed_at FROM karaoke_catalog_syncs WHERE status = 'committed' AND source = ? ORDER BY committed_at DESC LIMIT 1");
        $st->execute([$source]);
        $r = $st->fetch();
        return $r ? json_decode((string) $r['summary'], true) + ['at' => karaoke_iso($r['committed_at'])] : null;
    };
    return [
        'night' => $night ? ['id' => (int) $night['id'], 'code' => $night['night_code'], 'opens_at' => karaoke_iso($night['opens_at'])] : null,
        'tables' => $tables,
        'in_karafun' => array_map($row, $inKf),
        'waiting' => array_map($row, $waiting),
        'failed' => array_map($row, $failed),
        'agent' => karaoke_agent_status($pdo),
        'catalog' => [
            'local' => (int) ($counts['local'] ?? 0),
            'karafun' => (int) ($counts['karafun'] ?? 0),
            'por_aprobar' => karaoke_count_por_aprobar($pdo),
            'last_sync' => $lastSync('local'),
            'last_sync_karafun' => $lastSync('karafun'),
            'karafun_import' => json_decode(karaoke_setting($pdo, 'karaoke_karafun_import'), true) ?: null,
            'local_import' => json_decode(karaoke_setting($pdo, 'karaoke_local_import'), true) ?: null,
        ],
        'token_set' => karaoke_setting($pdo, 'karaoke_agent_token') !== '',
        'intl' => extension_loaded('intl'),
        'settings' => array_map(static fn (string $k): int => karaoke_setting_int($pdo, $k), array_combine(array_keys(KARAOKE_DEFAULTS), array_keys(KARAOKE_DEFAULTS))),
        'server_time' => date('c', karaoke_clock()),
    ];
}

/** URL pública de la página de mesa, a partir de la del panel (…/admin/api.php → …/karaoke/). */
function karaoke_public_base(): string
{
    $root = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), 2)), '/');
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $root . '/karaoke/';
}

function request_id_field(): string
{
    $id = (string) (input()['id'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/', $id)) {
        throw new ApiError('Pedido no válido.');
    }
    return $id;
}

// ---------------------------------------------------------------------------

if (!is_installed()) {
    problem(503, 'No instalado', 'Ejecuta install.php primero.');
}
$admin = current_admin();
if (!$admin) {
    problem(401, 'Sesión expirada', 'Tu sesión terminó. Vuelve a iniciar sesión.');
}
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    if ($method !== 'POST' || !csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        problem(403, 'Solicitud rechazada', 'La sesión del panel no es válida. Recarga la página.');
    }
}
$aid = (int) $admin['id'];
$pdo = db();

// El operador solo usa las pestañas Karaoke y Operación (cuya guía sale de karaoke.help).
if ($admin['role'] === ROLE_OPERATOR && !str_starts_with($action, 'karaoke.')) {
    problem(403, 'Sin permiso', 'Tu cuenta solo puede usar Karaoke y Operación.');
}

try {
    switch ($method . ' ' . $action) {
        case 'GET state':
            // Una base instalada antes de esta versión gana las columnas nuevas al abrir el panel.
            run_upgrades($pdo, db_driver());
            karaoke_migrate_search_text($pdo);
            respond(state());

        // ----- Categorías -----
        case 'POST category.save':
            $id = int_field('id');
            $name = str_field('name', 80, true, 'el nombre de la categoría');
            $desc = str_field('description', 255, false, 'La descripción');
            $active = bool_field('is_active', true);
            $slug = slugify($name);
            $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ? AND id <> ?');
            $st->execute([$slug, $id ?? 0]);
            if ((int) $st->fetchColumn() > 0) {
                throw new ApiError('Ya existe una categoría con ese nombre.', 409, ['name' => 'Ya existe una categoría con ese nombre.']);
            }
            if ($id) {
                require_row('categories', $id);
                $pdo->prepare('UPDATE categories SET name = ?, slug = ?, description = ?, is_active = ?, updated_at = ? WHERE id = ?')
                    ->execute([$name, $slug, $desc, $active, now(), $id]);
                audit($aid, 'update', 'category', $id);
            } else {
                $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $slug, $desc, next_order('categories'), $active, now(), now()]);
                $id = (int) $pdo->lastInsertId();
                audit($aid, 'create', 'category', $id);
            }
            respond(['ok' => true, 'id' => $id, 'state' => state()]);

        case 'POST category.delete':
            $id = (int) int_field('id');
            require_row('categories', $id);
            $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn() > 0) {
                throw new ApiError('Esta categoría tiene productos. Muévelos o elimínalos antes de borrarla.', 409);
            }
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            audit($aid, 'delete', 'category', $id);
            respond(['ok' => true, 'state' => state()]);

        case 'POST category.reorder':
            reorder('categories', (array) (input()['ids'] ?? []));
            audit($aid, 'reorder', 'category');
            respond(['ok' => true, 'state' => state()]);

        // ----- Productos -----
        case 'POST product.save':
            $id = int_field('id');
            $catId = (int) int_field('category_id');
            require_row('categories', $catId);
            $name = str_field('name', 120, true, 'el nombre del producto');
            $desc = str_field('description', 1000, false, 'La descripción');
            $badge = str_field('badge', 24, false, 'La etiqueta');
            $price = parse_price(input()['price'] ?? null);
            $active = bool_field('is_active', true);
            $featuredFlag = bool_field('is_featured');
            $variants = json_decode((string) (input()['variants'] ?? '[]'), true);
            if (!is_array($variants)) {
                throw new ApiError('Las presentaciones no son válidas.');
            }
            $clean = [];
            foreach ($variants as $i => $v) {
                $label = trim((string) ($v['label'] ?? ''));
                $vp = parse_price($v['price'] ?? null);
                if ($label === '' && $vp === null) {
                    continue;
                }
                if ($label === '' || $vp === null) {
                    throw new ApiError('Cada presentación necesita nombre y precio (fila ' . ($i + 1) . ').');
                }
                if (mb_strlen($label) > 60 || mb_strlen((string) ($v['detail'] ?? '')) > 80 || mb_strlen((string) ($v['note'] ?? '')) > 60) {
                    throw new ApiError('Una presentación tiene un texto demasiado largo (fila ' . ($i + 1) . ').');
                }
                $clean[] = [
                    'label' => $label,
                    'detail' => trim((string) ($v['detail'] ?? '')) ?: null,
                    'note' => trim((string) ($v['note'] ?? '')) ?: null,
                    'price' => $vp,
                    'is_active' => !isset($v['is_active']) || $v['is_active'] ? 1 : 0,
                ];
            }
            if ($clean) {
                $price = null;
            }

            $old = $id ? require_row('products', $id) : null;
            $image = $old['image'] ?? null;
            $newImage = null;
            if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $newImage = store_upload($_FILES['image'], $name);
            }

            $pdo->beginTransaction();
            try {
                if ($newImage) {
                    $image = $newImage;
                } elseif (bool_field('remove_image')) {
                    $image = null;
                }
                if ($old) {
                    $moved = (int) $old['category_id'] !== $catId;
                    $order = $moved ? next_order('products', 'category_id = ?', [$catId]) : (int) $old['sort_order'];
                    $pdo->prepare('UPDATE products SET category_id = ?, name = ?, description = ?, price = ?, badge = ?, image = ?, is_active = ?, is_featured = ?, sort_order = ?, updated_at = ? WHERE id = ?')
                        ->execute([$catId, $name, $desc, $price, $badge, $image, $active, $featuredFlag, $order, now(), $id]);
                    $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$id]);
                } else {
                    $pdo->prepare('INSERT INTO products (category_id, name, description, price, badge, image, is_active, is_featured, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$catId, $name, $desc, $price, $badge, $image, $active, $featuredFlag, next_order('products', 'category_id = ?', [$catId]), now(), now()]);
                    $id = (int) $pdo->lastInsertId();
                }
                $ins = $pdo->prepare('INSERT INTO product_variants (product_id, label, detail, note, price, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($clean as $i => $v) {
                    $ins->execute([$id, $v['label'], $v['detail'], $v['note'], $v['price'], ($i + 1) * 10, $v['is_active']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                if ($newImage) {
                    delete_upload($newImage);
                }
                throw $e;
            }
            if ($old && $old['image'] && $old['image'] !== $image) {
                delete_upload($old['image']);
            }
            audit($aid, $old ? 'update' : 'create', 'product', $id);
            respond(['ok' => true, 'id' => $id, 'state' => state()]);

        case 'POST product.toggle':
            $id = (int) int_field('id');
            $field = (string) (input()['field'] ?? '');
            if (!in_array($field, ['is_active', 'is_featured'], true)) {
                throw new ApiError('Campo no válido.');
            }
            require_row('products', $id);
            $pdo->prepare("UPDATE products SET $field = ?, updated_at = ? WHERE id = ?")->execute([bool_field('value'), now(), $id]);
            audit($aid, 'update', 'product', $id);
            respond(['ok' => true, 'state' => state()]);

        case 'POST product.duplicate':
            $id = (int) int_field('id');
            $p = require_row('products', $id);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO products (category_id, name, description, price, badge, image, is_active, is_featured, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)')
                ->execute([$p['category_id'], mb_substr($p['name'] . ' (copia)', 0, 120), $p['description'], $p['price'], $p['badge'], $p['image'], (int) $p['sort_order'] + 1, now(), now()]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO product_variants (product_id, label, detail, note, price, sort_order, is_active)
                SELECT ?, label, detail, note, price, sort_order, is_active FROM product_variants WHERE product_id = ?')->execute([$newId, $id]);
            $pdo->commit();
            audit($aid, 'create', 'product', $newId);
            respond(['ok' => true, 'id' => $newId, 'state' => state()]);

        case 'POST product.delete':
            $id = (int) int_field('id');
            $p = require_row('products', $id);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            delete_upload($p['image']);
            audit($aid, 'delete', 'product', $id);
            respond(['ok' => true, 'state' => state()]);

        case 'POST product.reorder':
            $catId = (int) int_field('category_id');
            reorder('products', (array) (input()['ids'] ?? []), 'category_id = ?', [$catId]);
            audit($aid, 'reorder', 'product');
            respond(['ok' => true, 'state' => state()]);

        // ----- Promociones -----
        case 'POST promo.save':
            $id = int_field('id');
            $title = str_field('title', 80, true, 'el título de la promo');
            $detail = str_field('detail', 255, false, 'El detalle');
            $days = array_values(array_unique(array_filter(array_map('intval', (array) (input()['days'] ?? [])), static fn ($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if (!$days) {
                throw new ApiError('Elige al menos un día.', 422, ['days' => 'Elige al menos un día.']);
            }
            $from = str_field('time_from', 5);
            $to = str_field('time_to', 5);
            foreach ([$from, $to] as $t) {
                if ($t !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                    throw new ApiError('Revisa el horario de la promo.');
                }
            }
            if (($from === null) !== ($to === null)) {
                throw new ApiError('Indica la hora de inicio y la de fin, o deja ambas vacías.');
            }
            $active = bool_field('is_active', true);
            if ($id) {
                require_row('promotions', $id);
                $pdo->prepare('UPDATE promotions SET title = ?, detail = ?, days = ?, time_from = ?, time_to = ?, is_active = ?, updated_at = ? WHERE id = ?')
                    ->execute([$title, $detail, implode(',', $days), $from, $to, $active, now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO promotions (title, detail, days, time_from, time_to, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$title, $detail, implode(',', $days), $from, $to, $active, next_order('promotions'), now(), now()]);
                $id = (int) $pdo->lastInsertId();
            }
            audit($aid, 'save', 'promotion', $id);
            respond(['ok' => true, 'id' => $id, 'state' => state()]);

        case 'POST promo.toggle':
            $id = (int) int_field('id');
            require_row('promotions', $id);
            $pdo->prepare('UPDATE promotions SET is_active = ?, updated_at = ? WHERE id = ?')->execute([bool_field('value'), now(), $id]);
            respond(['ok' => true, 'state' => state()]);

        case 'POST promo.delete':
            $id = (int) int_field('id');
            require_row('promotions', $id);
            $pdo->prepare('DELETE FROM promotions WHERE id = ?')->execute([$id]);
            audit($aid, 'delete', 'promotion', $id);
            respond(['ok' => true, 'state' => state()]);

        case 'POST promo.reorder':
            reorder('promotions', (array) (input()['ids'] ?? []));
            respond(['ok' => true, 'state' => state()]);

        // ----- Testimonios -----
        case 'POST testimonial.save':
            $id = int_field('id');
            $author = str_field('author', 80, true, 'el nombre de quien opina');
            $body = str_field('body', 600, true, 'el testimonio');
            $source = str_field('source', 40, false, 'La fuente');
            $rating = (int) (int_field('rating') ?? 5);
            if ($rating < 1 || $rating > 5) {
                throw new ApiError('La calificación va de 1 a 5 estrellas.', 422, ['rating' => 'De 1 a 5.']);
            }
            $active = bool_field('is_active', true);
            if ($id) {
                require_row('testimonials', $id);
                $pdo->prepare('UPDATE testimonials SET author = ?, body = ?, rating = ?, source = ?, is_active = ?, updated_at = ? WHERE id = ?')
                    ->execute([$author, $body, $rating, $source, $active, now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO testimonials (author, body, rating, source, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$author, $body, $rating, $source, $active, next_order('testimonials'), now(), now()]);
                $id = (int) $pdo->lastInsertId();
            }
            audit($aid, 'save', 'testimonial', $id);
            respond(['ok' => true, 'id' => $id, 'state' => state()]);

        case 'POST testimonial.toggle':
            $id = (int) int_field('id');
            require_row('testimonials', $id);
            $pdo->prepare('UPDATE testimonials SET is_active = ?, updated_at = ? WHERE id = ?')->execute([bool_field('value'), now(), $id]);
            respond(['ok' => true, 'state' => state()]);

        case 'POST testimonial.delete':
            $id = (int) int_field('id');
            require_row('testimonials', $id);
            $pdo->prepare('DELETE FROM testimonials WHERE id = ?')->execute([$id]);
            audit($aid, 'delete', 'testimonial', $id);
            respond(['ok' => true, 'state' => state()]);

        case 'POST testimonial.reorder':
            reorder('testimonials', (array) (input()['ids'] ?? []));
            respond(['ok' => true, 'state' => state()]);

        // ----- Ajustes -----
        case 'POST settings.save':
            $limits = ['business_name' => 80, 'tagline' => 120, 'address' => 120, 'phone' => 30, 'whatsapp' => 20, 'instagram' => 40, 'tiktok' => 40, 'facebook' => 300, 'email' => 120, 'reservation_url' => 400, 'reviews_url' => 600, 'songs_count' => 12, 'website' => 200, 'maps_url' => 300, 'notice' => 240, 'currency_note' => 160, 'pos_feed_url' => 200, 'pos_store_id' => 36, 'pos_feed_token' => 200];
            $urlKeys = ['website', 'maps_url', 'facebook', 'reservation_url', 'reviews_url', 'pos_feed_url'];
            $in = input();
            foreach ($limits as $k => $max) {
                if (array_key_exists($k, $in)) {
                    $v = trim((string) $in[$k]);
                    if (mb_strlen($v) > $max) {
                        throw new ApiError("Un campo supera los $max caracteres.", 422, [$k => "Máximo $max caracteres."]);
                    }
                    if (in_array($k, $urlKeys, true) && $v !== '' && !preg_match('#^https://#i', $v)) {
                        throw new ApiError('Los enlaces deben empezar por https://', 422, [$k => 'Debe empezar por https://']);
                    }
                    if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        throw new ApiError('Revisa el correo electrónico.', 422, [$k => 'Correo no válido.']);
                    }
                    if ($k === 'pos_store_id' && $v !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
                        throw new ApiError('El id de tienda del POS debe ser un uuid.', 422, [$k => 'Debe ser un uuid.']);
                    }
                    // El token nunca viaja al navegador; un campo vacío significa "conservar el actual".
                    if ($k === 'pos_feed_token' && $v === '') {
                        continue;
                    }
                    save_setting($k, $v);
                }
            }
            if (isset($in['hours']) && is_array($in['hours'])) {
                $hours = [];
                for ($d = 1; $d <= 7; $d++) {
                    $h = $in['hours'][$d] ?? $in['hours'][(string) $d] ?? null;
                    if (is_array($h) && count($h) === 2 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $h[0]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $h[1])) {
                        $hours[(string) $d] = [$h[0], $h[1]];
                    } else {
                        $hours[(string) $d] = null;
                    }
                }
                save_setting('hours', json_encode($hours));
            }
            audit($aid, 'update', 'settings');
            respond(['ok' => true, 'state' => state()]);

        // ----- POS (angelo-pos) -----
        case 'POST pos.sync':
            try {
                $summary = pos_sync(true, $aid);
            } catch (PosSyncError $e) {
                throw new ApiError($e->getMessage(), 502);
            }
            respond(['ok' => true, 'summary' => $summary, 'state' => state()]);

        case 'POST account.password':
            $current = (string) (input()['current'] ?? '');
            $new = (string) (input()['new'] ?? '');
            $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
            $st->execute([$aid]);
            if (!password_verify($current, (string) $st->fetchColumn())) {
                throw new ApiError('La contraseña actual no coincide.', 422, ['current' => 'No coincide.']);
            }
            if (strlen($new) < 10) {
                throw new ApiError('La nueva contraseña debe tener al menos 10 caracteres.', 422, ['new' => 'Mínimo 10 caracteres.']);
            }
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $aid]);
            session_regenerate_id(true);
            audit($aid, 'update', 'password');
            respond(['ok' => true]);

        case 'POST operator.password':
            // Solo un admin llega aquí: al operador lo frena la comprobación de rol de arriba.
            $new = (string) (input()['new'] ?? '');
            if (strlen($new) < 8) {
                throw new ApiError('La contraseña del operador debe tener al menos 8 caracteres.', 422, ['new' => 'Mínimo 8 caracteres.']);
            }
            $st = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE username = ? AND role = ?');
            $st->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), OPERATOR_USERNAME, ROLE_OPERATOR]);
            if (!$st->rowCount()) {
                throw new ApiError('La cuenta del operador no existe. Recarga el panel.', 404);
            }
            audit($aid, 'update', 'operator_password');
            respond(['ok' => true, 'state' => state()]);

        // ----- Karaoke por mesa -----
        case 'GET karaoke.help':
            // Guía de operación (app/karaoke-operacion.md), convertida a HTML con todo el texto escapado.
            $file = __DIR__ . '/../app/karaoke-operacion.md';
            respond(['html' => is_file($file) ? md_to_html((string) file_get_contents($file)) : '', 'updated_at' => is_file($file) ? date('c', (int) filemtime($file)) : null]);

        case 'GET karaoke.state':
            karaoke_migrate_search_text($pdo);
            respond(karaoke_state());

        case 'POST karaoke.table.save':
            $id = int_field('id');
            $number = (int) int_field('number');
            $tid = karaoke_table_save($pdo, $id ?: null, $number, (string) str_field('name', 40, false, 'El nombre'));
            audit($aid, $id ? 'update' : 'create', 'karaoke_table', $tid);
            respond(['ok' => true, 'id' => $tid, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.table.toggle':
            $id = (int) int_field('id');
            if (!karaoke_table($pdo, $id)) {
                throw new ApiError('La mesa ya no existe.', 404);
            }
            $pdo->prepare('UPDATE karaoke_tables SET is_active = ?, updated_at = ? WHERE id = ?')->execute([bool_field('value'), now(), $id]);
            audit($aid, 'update', 'karaoke_table', $id);
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.night.open':
            $night = karaoke_night_open($pdo, $aid);
            audit($aid, 'create', 'karaoke_night', (int) $night['id']);
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.night.rotate':
            $night = karaoke_night_rotate_code($pdo);
            audit($aid, 'update', 'karaoke_night', (int) $night['id']);
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.night.close':
            $n = karaoke_night_close($pdo, 'El encargado cerró la noche de karaoke.');
            audit($aid, 'close', 'karaoke_night');
            respond(['ok' => true, 'cancelled' => $n, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.request.cancel':
            karaoke_request_cancel($pdo, request_id_field(), 'admin');
            audit($aid, 'cancel', 'karaoke_request');
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.request.move':
            karaoke_request_move($pdo, request_id_field(), (int) (input()['dir'] ?? 0) < 0 ? -1 : 1);
            audit($aid, 'reorder', 'karaoke_request');
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.request.remove':
            karaoke_request_remove($pdo, request_id_field());
            audit($aid, 'remove', 'karaoke_request');
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.catalog.resync':
            // El agente ya no recorre el catálogo en línea de KaraFun: ese se carga con el CSV.
            $source = 'local';
            karaoke_command_create($pdo, 'catalog.resync', ['source' => $source]);
            audit($aid, 'resync', 'karaoke_catalog');
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.catalog.import':
            $file = $_FILES['csv'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ApiError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'El archivo supera el tamaño que permite el servidor.' : 'Elige el archivo CSV del catálogo.');
            }
            if ($file['size'] > 40 * 1024 * 1024) {
                throw new ApiError('El archivo pesa demasiado (máximo 40 MB).');
            }
            @set_time_limit(300);
            $summary = karaoke_import_catalog_csv($pdo, $file['tmp_name']);
            audit($aid, 'import', 'karaoke_catalog');
            respond(['ok' => true, 'summary' => $summary, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.settings.save':
            foreach (['karaoke_max_pending' => [1, 10], 'karaoke_rate_table' => [1, 30], 'karaoke_rate_ip' => [1, 60]] as $k => [$min, $max]) {
                $v = int_field($k);
                if ($v === null) {
                    continue;
                }
                if ($v < $min || $v > $max) {
                    throw new ApiError("Un límite está fuera de rango ($min a $max).", 422, [$k => "De $min a $max."]);
                }
                save_setting($k, (string) $v);
            }
            audit($aid, 'update', 'karaoke_settings');
            respond(['ok' => true, 'karaoke' => karaoke_state()]);

        case 'POST karaoke.token.rotate':
            // Se muestra una sola vez para copiarlo en el agente; nunca vuelve a salir del servidor.
            $token = bin2hex(random_bytes(32));
            save_setting('karaoke_agent_token', $token);
            audit($aid, 'rotate', 'karaoke_agent_token');
            respond(['ok' => true, 'token' => $token, 'karaoke' => karaoke_state()]);

        default:
            problem(404, 'No encontrado', 'Acción desconocida.');
    }
} catch (KaraokeError $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    problem($e->status, $e->title, $e->getMessage());
} catch (ApiError $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    problem($e->status, 'No se pudo guardar', $e->getMessage(), $e->fields);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[sarao-admin] ' . $action . ': ' . $e->getMessage());
    problem(500, 'Error del servidor', 'Algo falló al guardar. Inténtalo de nuevo en un momento.');
}
