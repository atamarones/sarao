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
            updated_at DATETIME NOT NULL,
            pos_promotion_id VARCHAR(36) NULL
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
        'CREATE INDEX idx_promotions_pos ON promotions (pos_promotion_id)',
        ...karaoke_schema_statements($driver),
    ];
}

/**
 * Tablas del karaoke por mesa (docs/karaoke-arquitectura.md §5). Las usan schema_statements()
 * en una instalación nueva y run_upgrades() en una base ya instalada; todo es CREATE IF NOT EXISTS.
 */
function karaoke_schema_statements(string $driver): array
{
    $my = $driver === 'mysql';
    $pk = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $bool = $my ? 'TINYINT(1)' : 'INTEGER';
    $big = $my ? 'BIGINT' : 'INTEGER';
    // Identificadores que distinguen mayúsculas (ids de YouTube) o que son ASCII por contrato (natural_key).
    $bin = $my ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';

    return [
        "CREATE TABLE IF NOT EXISTS karaoke_tables (
            id $pk,
            number INT NOT NULL UNIQUE,
            name VARCHAR(40) NOT NULL,
            qr_token VARCHAR(64)$bin NOT NULL UNIQUE,
            is_active $bool NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT ck_ktables_number CHECK (number BETWEEN 1 AND 999)
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_nights (
            id $pk,
            night_code VARCHAR(8) NOT NULL,
            opens_at DATETIME NOT NULL,
            closes_at DATETIME NULL,
            opened_by $fk NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_songs (
            id $pk,
            source VARCHAR(10) NOT NULL DEFAULT 'local',
            natural_key VARCHAR(255)$bin NOT NULL UNIQUE,
            title VARCHAR(200) NOT NULL,
            artist VARCHAR(200) NOT NULL DEFAULT '',
            duration_s INT NOT NULL DEFAULT 0,
            search_text VARCHAR(420) NOT NULL,
            kf_id INT NULL,
            popularity INT NULL,
            folder VARCHAR(120) NULL,
            file VARCHAR(500) NULL,
            youtube_id VARCHAR(11)$bin NULL,
            available $bool NOT NULL DEFAULT 1,
            seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",

        // Índice de palabras para buscar por prefijo en ~100 mil canciones sin recorrer la tabla.
        "CREATE TABLE IF NOT EXISTS karaoke_song_words (
            word VARCHAR(40)$bin NOT NULL,
            song_id $fk NOT NULL,
            PRIMARY KEY (word, song_id),
            CONSTRAINT fk_kwords_song FOREIGN KEY (song_id) REFERENCES karaoke_songs(id) ON DELETE CASCADE
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_downloads (
            id $pk,
            youtube_id VARCHAR(11)$bin NOT NULL UNIQUE,
            song_id $fk NULL,
            status VARCHAR(12) NOT NULL,
            title VARCHAR(200) NULL,
            duration_s INT NULL,
            requested_by VARCHAR(36) NULL,
            error VARCHAR(255) NULL,
            downloaded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_kdownloads_song FOREIGN KEY (song_id) REFERENCES karaoke_songs(id)
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_requests (
            id VARCHAR(36)$bin NOT NULL PRIMARY KEY,
            night_id $fk NOT NULL,
            table_id $fk NOT NULL,
            singer VARCHAR(40) NOT NULL,
            marker VARCHAR(16) NOT NULL,
            song_id $fk NULL,
            youtube_id VARCHAR(11)$bin NULL,
            status VARCHAR(12) NOT NULL,
            fair_seq $big NOT NULL,
            kf_queue_pos INT NULL,
            singer_shown $bool NOT NULL DEFAULT 1,
            error VARCHAR(255) NULL,
            sent_at DATETIME NULL,
            acked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_krequests_night FOREIGN KEY (night_id) REFERENCES karaoke_nights(id),
            CONSTRAINT fk_krequests_table FOREIGN KEY (table_id) REFERENCES karaoke_tables(id),
            CONSTRAINT fk_krequests_song FOREIGN KEY (song_id) REFERENCES karaoke_songs(id)
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_request_log (
            id $pk,
            request_id VARCHAR(36)$bin NOT NULL,
            from_status VARCHAR(12) NULL,
            to_status VARCHAR(12) NOT NULL,
            actor VARCHAR(8) NOT NULL,
            note VARCHAR(255) NULL,
            at DATETIME NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_commands (
            id $pk,
            request_id VARCHAR(36)$bin NULL,
            type VARCHAR(20) NOT NULL,
            payload TEXT NOT NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            lease_until DATETIME NULL,
            result TEXT NULL,
            error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            done_at DATETIME NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_agent (
            id INT NOT NULL PRIMARY KEY,
            last_seen_at DATETIME NULL,
            version VARCHAR(40) NULL,
            kf_running $bool NOT NULL DEFAULT 0,
            kf_connected $bool NOT NULL DEFAULT 0,
            kf_state VARCHAR(20) NULL,
            queue_snapshot TEXT NULL,
            acks_pending INT NOT NULL DEFAULT 0,
            last_sync_at DATETIME NULL,
            last_sync_songs INT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_catalog_syncs (
            id VARCHAR(32)$bin NOT NULL PRIMARY KEY,
            source VARCHAR(10) NOT NULL DEFAULT 'local',
            status VARCHAR(10) NOT NULL,
            started_at DATETIME NOT NULL,
            committed_at DATETIME NULL,
            total_chunks INT NULL,
            total_songs INT NULL,
            summary TEXT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_catalog_chunks (
            sync_id VARCHAR(32)$bin NOT NULL,
            chunk_index INT NOT NULL,
            song_count INT NOT NULL,
            received_at DATETIME NOT NULL,
            PRIMARY KEY (sync_id, chunk_index)
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_catalog_staging (
            id $pk,
            sync_id VARCHAR(32)$bin NOT NULL,
            chunk_index INT NOT NULL,
            natural_key VARCHAR(255)$bin NOT NULL,
            title VARCHAR(200) NOT NULL,
            artist VARCHAR(200) NOT NULL,
            duration_s INT NOT NULL,
            folder VARCHAR(120) NULL,
            file VARCHAR(500) NULL,
            kf_id INT NULL,
            youtube_id VARCHAR(11)$bin NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS karaoke_rate_events (
            id $pk,
            bucket VARCHAR(80)$bin NOT NULL,
            at DATETIME NOT NULL
        )$tail",

        'CREATE INDEX idx_ksongs_folder ON karaoke_songs (available, folder)',
        'CREATE INDEX idx_ksongs_seen ON karaoke_songs (source, seen_at)',
        'CREATE INDEX idx_ksongs_kf ON karaoke_songs (source, kf_id)',
        'CREATE INDEX idx_kwords_song ON karaoke_song_words (song_id)',
        'CREATE INDEX idx_krequests_queue ON karaoke_requests (night_id, status, fair_seq)',
        'CREATE INDEX idx_krequests_table ON karaoke_requests (night_id, table_id, status)',
        'CREATE INDEX idx_krequests_youtube ON karaoke_requests (youtube_id, status)',
        'CREATE INDEX idx_krlog_request ON karaoke_request_log (request_id, id)',
        'CREATE INDEX idx_kcommands_status ON karaoke_commands (status, id)',
        'CREATE INDEX idx_kstaging_sync ON karaoke_catalog_staging (sync_id, chunk_index)',
        'CREATE INDEX idx_krate_bucket ON karaoke_rate_events (bucket, at)',
        'CREATE INDEX idx_krate_at ON karaoke_rate_events (at)',
    ];
}
