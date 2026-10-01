<?php
declare(strict_types=1);

/**
 * Instalador de un solo uso.
 *  - Web (Hostinger): abre https://tu-dominio/install.php y completa el formulario.
 *  - CLI (local):     php install.php --sqlite=../database/sarao.sqlite --user=admin --pass=ClaveSegura123
 * Tras instalar, se bloquea solo (existe app/config.php). Borra este archivo del servidor.
 */

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/install_lib.php';

if (PHP_SAPI === 'cli') {
    $o = getopt('', ['sqlite:', 'user:', 'pass:', 'force']);
    if (is_installed() && !isset($o['force'])) {
        fwrite(STDERR, "Ya instalado. Usa --force para reinstalar sobre la misma BD.\n");
        exit(1);
    }
    if (empty($o['sqlite']) || empty($o['user']) || strlen((string) ($o['pass'] ?? '')) < 10) {
        fwrite(STDERR, "Uso: php install.php --sqlite=RUTA --user=USUARIO --pass=CLAVE(>=10)\n");
        exit(1);
    }
    $path = $o['sqlite'];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $path = realpath(dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $r = run_install($pdo, 'sqlite', $o['user'], $o['pass']);
    write_config(['driver' => 'sqlite', 'path' => $path]);
    echo 'Instalado en ' . $path . ($r['seeded'] ? ' (menú inicial cargado)' : '') . "\n";
    exit(0);
}

security_headers();
start_session();

if (is_installed()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Instalado</title><p style="font:16px system-ui;padding:2rem">El menú ya está instalado. Por seguridad, borra <code>install.php</code> del servidor.</p>';
    exit;
}

$error = null;
$done = null;
$f = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'admin' => 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = array_merge($f, array_map('trim', array_intersect_key($_POST, $f)));
    $pass = (string) ($_POST['pass'] ?? '');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    try {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            throw new RuntimeException('La sesión expiró. Recarga la página e inténtalo de nuevo.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $f['admin'])) {
            throw new RuntimeException('El usuario admin debe tener de 3 a 60 caracteres: letras, números, punto, guion o guion bajo.');
        }
        if (strlen($adminPass) < 10) {
            throw new RuntimeException('La contraseña del administrador debe tener al menos 10 caracteres.');
        }
        $db = ['driver' => 'mysql', 'host' => $f['host'], 'port' => (int) $f['port'], 'name' => $f['name'], 'user' => $f['user'], 'pass' => $pass];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        try {
            $pdo = new PDO($dsn, $db['user'], $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException) {
            throw new RuntimeException('No se pudo conectar a MySQL. Revisa host, nombre de la base, usuario y contraseña en hPanel → Bases de datos.');
        }
        $r = run_install($pdo, 'mysql', $f['admin'], $adminPass);
        write_config($db);
        $done = $r['seeded'];
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$token = csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalar menú · El Sarao Pub</title>
<link rel="stylesheet" href="admin/assets/admin.css">
</head>
<body class="auth">
<main class="auth-card">
  <img src="assets/img/logo.png" alt="El Sarao Pub" class="auth-logo" width="188" height="96">
  <?php if ($done !== null): ?>
    <h1>Menú instalado</h1>
    <p class="muted"><?= $done ? 'Se cargó el menú actual con sus 14 categorías.' : 'La base ya tenía datos; se conservaron.' ?></p>
    <p class="alert">Borra <code>install.php</code> del servidor desde el administrador de archivos de Hostinger.</p>
    <a class="btn btn-primary btn-block" href="admin/">Entrar al panel</a>
  <?php else: ?>
    <h1>Instalar el menú</h1>
    <p class="muted">Crea la base de datos MySQL en hPanel y copia aquí sus datos.</p>
    <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <fieldset>
        <legend>Base de datos MySQL</legend>
        <label>Host<input name="host" value="<?= e($f['host']) ?>" required></label>
        <label>Puerto<input name="port" value="<?= e($f['port']) ?>" inputmode="numeric" required></label>
        <label>Nombre de la base<input name="name" value="<?= e($f['name']) ?>" placeholder="u123456789_menu" required></label>
        <label>Usuario<input name="user" value="<?= e($f['user']) ?>" autocomplete="off" required></label>
        <label>Contraseña<input name="pass" type="password" autocomplete="new-password"></label>
      </fieldset>
      <fieldset>
        <legend>Administrador del menú</legend>
        <label>Usuario<input name="admin" value="<?= e($f['admin']) ?>" autocomplete="username" required></label>
        <label>Contraseña (mínimo 10 caracteres)<input name="admin_pass" type="password" minlength="10" autocomplete="new-password" required></label>
      </fieldset>
      <button class="btn btn-primary btn-block">Instalar</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
