<?php
declare(strict_types=1);

/**
 * Exporta el catálogo del sitio (categorías, productos, presentaciones y promociones)
 * a JSON para cargarlo en angelo-pos con scripts/import-from-sarao.ts.
 *
 *   php -d extension=pdo_sqlite tools/export_catalog.php > catalogo-sarao.json
 *
 * Usa la base configurada en public_html/app/config.php (o SARAO_CONFIG_FILE).
 */

require __DIR__ . '/../public_html/app/bootstrap.php';
require __DIR__ . '/../public_html/app/menu.php';

$pdo = db();
$cats = $pdo->query('SELECT id, name, slug, description, sort_order, is_active FROM categories ORDER BY sort_order, id')->fetchAll();
$prods = $pdo->query('SELECT id, category_id, name, description, price, badge, image, is_active, is_featured, sort_order FROM products ORDER BY sort_order, id')->fetchAll();
$vars = $pdo->query('SELECT id, product_id, label, detail, note, price, sort_order, is_active FROM product_variants ORDER BY sort_order, id')->fetchAll();
$promos = $pdo->query('SELECT id, title, detail, days, time_from, time_to, is_active, sort_order FROM promotions ORDER BY sort_order, id')->fetchAll();

$varsByProduct = [];
foreach ($vars as $v) {
    $varsByProduct[(int) $v['product_id']][] = [
        'id' => (int) $v['id'],
        'label' => $v['label'],
        'detail' => $v['detail'],
        'note' => $v['note'],
        'price' => (int) $v['price'],
        'is_active' => (bool) $v['is_active'],
    ];
}
$prodsByCat = [];
foreach ($prods as $p) {
    $prodsByCat[(int) $p['category_id']][] = [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'description' => $p['description'],
        'price' => $p['price'] === null ? null : (int) $p['price'],
        'badge' => $p['badge'],
        'image' => $p['image'],
        'is_active' => (bool) $p['is_active'],
        'is_featured' => (bool) $p['is_featured'],
        'variants' => $varsByProduct[(int) $p['id']] ?? [],
    ];
}
$out = [
    'exported_at' => date('c'),
    'source' => 'sarao',
    'categories' => array_map(static fn ($c) => [
        'id' => (int) $c['id'],
        'name' => $c['name'],
        'slug' => $c['slug'],
        'description' => $c['description'],
        'sort_order' => (int) $c['sort_order'],
        'is_active' => (bool) $c['is_active'],
        'products' => $prodsByCat[(int) $c['id']] ?? [],
    ], $cats),
    'promotions' => array_map(static fn ($p) => [
        'id' => (int) $p['id'],
        'title' => $p['title'],
        'detail' => $p['detail'],
        'days' => array_values(array_filter(array_map('intval', explode(',', (string) $p['days'])))),
        'time_from' => $p['time_from'],
        'time_to' => $p['time_to'],
        'is_active' => (bool) $p['is_active'],
    ], $promos),
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
