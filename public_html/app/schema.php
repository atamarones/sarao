<?php
declare(strict_types=1);

/**
 * DDL del menú. Devuelve las sentencias según el motor (mysql en Hostinger, sqlite en local).
 */
function schema_statements(string $driver): array
{
    $my = $driver === 'mysql';
    $pk = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $bool = $my ? 'TINYINT(1)' : 'INTEGER';
    $text = 'TEXT';

    return [
        "CREATE TABLE IF NOT EXISTS categories (
            id $pk,
            name VARCHAR(80) NOT NULL,
            slug VARCHAR(90) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active $bool NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS products (
            id $pk,
            category_id $fk NOT NULL,
            name VARCHAR(120) NOT NULL,
            description $text NULL,
            price INT NULL,
            badge VARCHAR(24) NULL,
            image VARCHAR(160) NULL,
            is_active $bool NOT NULL DEFAULT 1,
            is_featured $bool NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            pos_product_id VARCHAR(36) NULL,
            pos_synced_at DATETIME NULL,
            CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            CONSTRAINT ck_products_price CHECK (price IS NULL OR price >= 0)
        )$tail",

        "CREATE TABLE IF NOT EXISTS product_variants (
            id $pk,
            product_id $fk NOT NULL,
            label VARCHAR(60) NOT NULL,
            detail VARCHAR(80) NULL,
            note VARCHAR(60) NULL,
            price INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active $bool NOT NULL DEFAULT 1,
            pos_product_id VARCHAR(36) NULL,
            pos_synced_at DATETIME NULL,
            CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT ck_variants_price CHECK (price >= 0)
        )$tail",

        "CREATE TABLE IF NOT EXISTS promotions (
            id $pk,
            title VARCHAR(80) NOT NULL,
            detail VARCHAR(255) NULL,
            days VARCHAR(20) NOT NULL DEFAULT '',
            time_from VARCHAR(5) NULL,
            time_to VARCHAR(5) NULL,
            is_active $bool NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS testimonials (
            id $pk,
            author VARCHAR(80) NOT NULL,
            body VARCHAR(600) NOT NULL,
            rating INT NOT NULL DEFAULT 5,
            source VARCHAR(40) NULL,
            is_active $bool NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT ck_testimonials_rating CHECK (rating BETWEEN 1 AND 5)
        )$tail",

        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(60) NOT NULL PRIMARY KEY,
            v $text NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS admins (
            id $pk,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id $pk,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id $pk,
            admin_id $fk NULL,
            action VARCHAR(20) NOT NULL,
            entity VARCHAR(30) NOT NULL,
            entity_id INT NULL,
            created_at DATETIME NOT NULL
        )$tail",

        'CREATE INDEX idx_products_category ON products (category_id, sort_order)',
        'CREATE INDEX idx_variants_product ON product_variants (product_id, sort_order)',
        'CREATE INDEX idx_login_ip ON login_attempts (ip, attempted_at)',
        'CREATE INDEX idx_products_pos ON products (pos_product_id)',
        'CREATE INDEX idx_variants_pos ON product_variants (pos_product_id)',
    ];
}
