<?php
declare(strict_types=1);

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
    ];
    foreach ($cols as [$table, $col, $type]) {
        if (!column_exists($pdo, $driver, $table, $col)) {
            $sql = "ALTER TABLE $table ADD COLUMN $col $type";
            $pdo->exec($sql);
            $applied[] = $sql;
        }
    }
    foreach (['CREATE INDEX idx_products_pos ON products (pos_product_id)', 'CREATE INDEX idx_variants_pos ON product_variants (pos_product_id)'] as $sql) {
        try {
            $pdo->exec($sql);
            $applied[] = $sql;
        } catch (PDOException) {
            // Ya existe.
        }
    }
    return $applied;
}
