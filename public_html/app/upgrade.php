<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';

/**
 * Cambios de esquema sobre una base ya instalada (schema.php solo hace CREATE IF NOT EXISTS).
 * Idempotente: comprueba la columna antes de añadirla. Lo ejecutan install.php (reinstalación)
 * y el panel al cargar su estado, así una base instalada antes de esta versión se actualiza sola.
 */

function column_exists(PDO $pdo, string $driver, string $table, string $column): bool
{
    if ($driver === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) {
            if (strcasecmp((string) $c['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $driver, string $table): bool
{
    $st = $driver === 'sqlite'
        ? $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?")
        : $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function karaoke_table_name(string $createSql): string
{
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $createSql, $m);
    return $m[1];
}

/** Devuelve la lista de sentencias aplicadas (vacía si no había nada pendiente). */
function run_upgrades(PDO $pdo, string $driver): array
{
    $applied = [];
    // Enlace con angelo-pos (uuid del producto en el POS) y marca de última sincronización.
    $cols = [
        ['products', 'pos_product_id', 'VARCHAR(36) NULL'],
        ['products', 'pos_synced_at', 'DATETIME NULL'],
        ['product_variants', 'pos_product_id', 'VARCHAR(36) NULL'],
        ['product_variants', 'pos_synced_at', 'DATETIME NULL'],
        // Promociones que vienen del POS (las creadas a mano en el panel quedan con NULL).
        ['promotions', 'pos_promotion_id', 'VARCHAR(36) NULL'],
    ];
    foreach ($cols as [$table, $col, $type]) {
        if (!column_exists($pdo, $driver, $table, $col)) {
            $sql = "ALTER TABLE $table ADD COLUMN $col $type";
            $pdo->exec($sql);
            $applied[] = $sql;
        }
    }
    // Karaoke por mesa: tablas nuevas (CREATE IF NOT EXISTS) e índices (fallan si ya existen).
    foreach (karaoke_schema_statements($driver) as $sql) {
        if (str_starts_with($sql, 'CREATE TABLE')) {
            $table = karaoke_table_name($sql);
            if (!table_exists($pdo, $driver, $table)) {
                $pdo->exec($sql);
                $applied[] = "CREATE TABLE $table";
            }
            continue;
        }
        try {
            $pdo->exec($sql);
            $applied[] = $sql;
        } catch (PDOException) {
            // Ya existe.
        }
    }
    foreach (['CREATE INDEX idx_products_pos ON products (pos_product_id)', 'CREATE INDEX idx_variants_pos ON product_variants (pos_product_id)', 'CREATE INDEX idx_promotions_pos ON promotions (pos_promotion_id)'] as $sql) {
        try {
            $pdo->exec($sql);
            $applied[] = $sql;
        } catch (PDOException) {
            // Ya existe.
        }
    }
    return $applied;
}
