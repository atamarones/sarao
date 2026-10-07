<?php
declare(strict_types=1);

/**
 * Núcleo compartido: configuración, conexión PDO, sesión, CSRF y utilidades.
 * Todo archivo PHP público incluye este archivo primero.
 */

const APP_ROOT = __DIR__;
const PUBLIC_ROOT = __DIR__ . '/..';
const UPLOAD_DIR = PUBLIC_ROOT . '/uploads/products';
const UPLOAD_URL = 'uploads/products';

date_default_timezone_set('America/Bogota');
mb_internal_encoding('UTF-8');

function config(?string $key = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        // SARAO_CONFIG_FILE permite apuntar a otra configuración (pruebas sobre una copia de la base).
        $file = getenv('SARAO_CONFIG_FILE') ?: APP_ROOT . '/config.php';
        $cfg = is_file($file) ? require $file : [];
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function is_installed(): bool
{
    return is_file(getenv('SARAO_CONFIG_FILE') ?: APP_ROOT . '/config.php');
}

/** Claves de settings que nunca deben llegar al navegador (tokens de integraciones). */
const SECRET_SETTINGS = ['ig_token', 'pos_feed_token', 'karaoke_agent_token', 'karaoke_salt'];

function public_settings(array $s): array
{
    foreach (SECRET_SETTINGS as $k) {
        unset($s[$k]);
    }
    return $s;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    if (!$c) {
        throw new RuntimeException('La base de datos no está configurada. Ejecuta install.php.');
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if ($c['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) ($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    }
    return $pdo;
}

function db_driver(): string
{
    return (string) (config('db')['driver'] ?? 'mysql');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $text): string
{
    $t = mb_strtolower(trim($text));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
    return trim($t, '-') ?: 'item';
}

function money(?int $cop): string
{
    return $cop === null ? '' : '$' . number_format($cop, 0, ',', '.');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function security_headers(bool $allowInlineStyle = false, bool $allowMicrophone = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // El micrófono solo se habilita en la home, para el medidor de voz (el audio se analiza en el navegador y no se envía).
    header('Permissions-Policy: camera=(), microphone=' . ($allowMicrophone ? '(self)' : '()') . ', geolocation=()');
    $style = "'self' https://fonts.googleapis.com" . ($allowInlineStyle ? " 'unsafe-inline'" : '');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src $style; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('sarao_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    start_session();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

const ROLE_ADMIN = 'admin';
// El operador solo ve las pestañas Karaoke y Operación del panel.
const ROLE_OPERATOR = 'operador';
const OPERATOR_USERNAME = 'operador@saraopub.com';
// La cuenta nace sin contraseña válida (password_verify nunca acepta '!'): la pone un admin en Ajustes.
const NO_PASSWORD = '!';

function current_admin(): ?array
{
    start_session();
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return null;
    }
    // Expira la sesión tras 8 horas de inactividad.
    if (time() - (int) ($_SESSION['last_seen'] ?? 0) > 8 * 3600) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    $_SESSION['last_seen'] = time();
    // SELECT * para no romper una sesión abierta antes de que exista la columna role.
    $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return ['id' => $row['id'], 'username' => $row['username'], 'role' => $row['role'] ?? ROLE_ADMIN];
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function audit(?int $adminId, string $action, string $entity, ?int $entityId = null): void
{
    $st = db()->prepare('INSERT INTO audit_log (admin_id, action, entity, entity_id, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$adminId, $action, $entity, $entityId, now()]);
}
