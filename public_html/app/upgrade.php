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

function karaoke_backfill_words(PDO $pdo): int
{
    $songs = $pdo->query('SELECT id, search_text FROM karaoke_songs')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pairs = [];
    foreach ($songs as $id => $text) {
        foreach (array_slice(array_unique(array_map(static fn (string $w): string => substr($w, 0, 40), array_filter(explode(' ', (string) $text), 'strlen'))), 0, 40) as $w) {
            $pairs[] = [(int) $id, $w];
        }
    }
    foreach (array_chunk($pairs, 400) as $chunk) {
        $pdo->prepare('INSERT INTO karaoke_song_words (song_id, word) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')))
            ->execute(array_merge(...$chunk));
    }
    return count($songs);
}

function karaoke_table_name(string $createSql): string
{
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $createSql, $m);
    return $m[1];
}

/**
 * Roles del panel: columna admins.role y la cuenta del operador (solo Karaoke y Operación).
 * Va dentro de run_upgrades, que también corre en el login (el operador no abre la carta).
 */
function admin_roles_upgrade(PDO $pdo, string $driver): array
{
    $applied = [];
    if (!column_exists($pdo, $driver, 'admins', 'role')) {
        $sql = "ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'";
        $pdo->exec($sql);
        $applied[] = $sql;
    }
    $st = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
    $st->execute([OPERATOR_USERNAME]);
    if (!$st->fetchColumn()) {
        $pdo->prepare('INSERT INTO admins (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')
            ->execute([OPERATOR_USERNAME, NO_PASSWORD, ROLE_OPERATOR, now()]);
        $applied[] = 'admins: cuenta ' . OPERATOR_USERNAME;
    }
    return $applied;
}

/** Devuelve la lista de sentencias aplicadas (vacía si no había nada pendiente). */
function run_upgrades(PDO $pdo, string $driver): array
{
    $applied = admin_roles_upgrade($pdo, $driver);
    // Enlace con angelo-pos (uuid del producto en el POS) y marca de última sincronización.
    $cols = [
        ['products', 'pos_product_id', 'VARCHAR(36) NULL'],
        ['products', 'pos_synced_at', 'DATETIME NULL'],
        ['product_variants', 'pos_product_id', 'VARCHAR(36) NULL'],
        ['product_variants', 'pos_synced_at', 'DATETIME NULL'],
        // Promociones que vienen del POS (las creadas a mano en el panel quedan con NULL).
        ['promotions', 'pos_promotion_id', 'VARCHAR(36) NULL'],
    ];
    // Karaoke: tablas nuevas primero (abajo); estas columnas llegaron después de la primera versión.
    $karaokeCols = [
        ['karaoke_songs', 'popularity', 'INT NULL'],
        // Contrato v2.
        ['karaoke_songs', 'youtube_id', 'VARCHAR(11)' . ($driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : '') . ' NULL'],
        ['karaoke_requests', 'singer_shown', ($driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER') . ' NOT NULL DEFAULT 1'],
        ['karaoke_catalog_syncs', 'source', "VARCHAR(10) NOT NULL DEFAULT 'local'"],
        ['karaoke_catalog_staging', 'kf_id', 'INT NULL'],
        ['karaoke_catalog_staging', 'youtube_id', 'VARCHAR(11) NULL'],
        // karafun.session: detectar reinicios de KaraFun.
        ['karaoke_agent', 'kf_session', 'VARCHAR(100) NULL'],
        ['karaoke_agent', 'kf_started_at', 'VARCHAR(40) NULL'],
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
                if ($table === 'karaoke_song_words') {
                    // Canciones de una versión anterior sin índice de palabras: se indexan ya.
                    $n = karaoke_backfill_words($pdo);
                    $n && $applied[] = "karaoke_song_words: $n canciones indexadas";
                }
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
    foreach ($karaokeCols as [$table, $col, $type]) {
        if (!column_exists($pdo, $driver, $table, $col)) {
            $sql = "ALTER TABLE $table ADD COLUMN $col $type";
            $pdo->exec($sql);
            $applied[] = $sql;
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
