<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/upgrade.php';

/** Crea tablas, carga el menú inicial (si la BD está vacía) y crea el administrador. */
function run_install(PDO $pdo, string $driver, string $adminUser, string $adminPass): array
{
    foreach (schema_statements($driver) as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Los índices ya existentes no son un error en una reinstalación.
            if (!str_starts_with($sql, 'CREATE INDEX')) {
                throw $e;
            }
        }
    }

    run_upgrades($pdo, $driver);

    $seeded = false;
    $count = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0 && is_file(__DIR__ . '/seed.json')) {
        seed_menu($pdo, json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true, 512, JSON_THROW_ON_ERROR));
        $seeded = true;
    }

    $ts = date('Y-m-d H:i:s');
    $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $st = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
    $st->execute([$adminUser]);
    if ($id = $st->fetchColumn()) {
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    } else {
        $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)')->execute([$adminUser, $hash, $ts]);
    }
    return ['seeded' => $seeded];
}

function seed_menu(PDO $pdo, array $seed): void
{
    $ts = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $insCat = $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $insProd = $pdo->prepare('INSERT INTO products (category_id, name, description, price, badge, image, is_active, is_featured, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)');
        $insVar = $pdo->prepare('INSERT INTO product_variants (product_id, label, detail, note, price, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $slugs = [];
        foreach ($seed['categories'] as $ci => $c) {
            $slug = slugify($c['name']);
            while (isset($slugs[$slug])) {
                $slug .= '-2';
            }
            $slugs[$slug] = true;
            $insCat->execute([$c['name'], $slug, $c['description'], ($ci + 1) * 10, $ts, $ts]);
            $catId = (int) $pdo->lastInsertId();
            foreach ($c['products'] as $pi => $p) {
                $insProd->execute([$catId, $p['name'], $p['description'], $p['price'], $p['badge'], $p['image'], $p['is_featured'] ? 1 : 0, ($pi + 1) * 10, $ts, $ts]);
                $prodId = (int) $pdo->lastInsertId();
                foreach ($p['variants'] as $vi => $v) {
                    $insVar->execute([$prodId, $v['label'], $v['detail'], $v['note'], $v['price'], ($vi + 1) * 10]);
                }
            }
        }
        $insPromo = $pdo->prepare('INSERT INTO promotions (title, detail, days, time_from, time_to, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)');
        foreach ($seed['promotions'] ?? [] as $i => $pr) {
            $insPromo->execute([$pr['title'], $pr['detail'], $pr['days'], $pr['time_from'], $pr['time_to'], ($i + 1) * 10, $ts, $ts]);
        }
        $insT = $pdo->prepare('INSERT INTO testimonials (author, body, rating, source, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
        foreach ($seed['testimonials'] ?? [] as $i => $t) {
            $insT->execute([$t['author'], $t['body'], $t['rating'], $t['source'], ($i + 1) * 10, $ts, $ts]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function write_config(array $db): void
{
    $cfg = ['db' => $db, 'installed_at' => date('c')];
    $php = "<?php\n// Generado por install.php. No subir a repositorios públicos.\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents(APP_ROOT . '/config.php', $php, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir app/config.php. Revisa los permisos de la carpeta app/.');
    }
}
