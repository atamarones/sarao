<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (!is_installed()) {
    header('Location: ../install.php');
    exit;
}

security_headers();
header('Cache-Control: no-store');
start_session();

const MAX_ATTEMPTS = 5;
const WINDOW_MIN = 15;

$error = null;
$admin = current_admin();

if (!$admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $since = date('Y-m-d H:i:s', time() - WINDOW_MIN * 60);
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $st->execute([client_ip(), $since]);

    if ((int) $st->fetchColumn() >= MAX_ATTEMPTS) {
        $error = 'Demasiados intentos. Espera ' . WINDOW_MIN . ' minutos e inténtalo de nuevo.';
    } elseif (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } else {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $st = $pdo->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $st->execute([$user]);
        $row = $st->fetch();
        // Se verifica siempre contra un hash para no revelar si el usuario existe por el tiempo de respuesta.
        $hash = $row['password_hash'] ?? '$2y$12$igadPMSVFYvh97RCLEYP6uXomNoEuWlcAoDXXbPiJUnoL9x8DLgCS';
        if ($row && password_verify($pass, $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $row['id'];
            $_SESSION['last_seen'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([now(), $row['id']]);
            $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
            if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row['id']]);
            }
            audit((int) $row['id'], 'login', 'admin', (int) $row['id']);
            header('Location: ./');
            exit;
        }
        $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([client_ip(), now()]);
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$csrf = csrf_token();
$v = static fn (string $f): int => (int) @filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<title>Panel · El Sarao Pub</title>
<link rel="icon" href="../assets/img/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@800;900&family=Outfit:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/admin.css?v=<?= $v('admin.css') ?>">
</head>
<?php if (!$admin): ?>
<body class="auth">
<main class="auth-card">
  <img src="../assets/img/logo-dark.webp" alt="El Sarao Pub" class="auth-logo" width="188" height="96">
  <h1>Panel de la carta</h1>
  <p class="muted">Entra para editar productos, precios y promos.</p>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Usuario<input name="username" autocomplete="username" required autofocus value="<?= e((string) ($_POST['username'] ?? '')) ?>"></label>
    <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary btn-block">Entrar</button>
  </form>
  <a class="back-link" href="../">Ir al sitio</a>
</main>
</body>
<?php else: ?>
<body class="app">
<header class="topbar">
  <a class="brand" href="./"><img src="../assets/img/logo-dark.webp" alt="El Sarao Pub" width="94" height="48"><span>Panel</span></a>
  <nav class="tabs" aria-label="Secciones">
    <a href="#productos" data-view="productos">Productos</a>
    <a href="#categorias" data-view="categorias">Categorías</a>
    <a href="#promos" data-view="promos">Promos</a>
    <a href="#testimonios" data-view="testimonios">Testimonios</a>
    <a href="#karaoke" data-view="karaoke">Karaoke</a>
    <a href="#operacion" data-view="operacion">Operación</a>
    <a href="#ajustes" data-view="ajustes">Ajustes</a>
  </nav>
  <div class="topbar-end">
    <a class="btn btn-ghost" href="../" target="_blank" rel="noopener">Ver sitio</a>
    <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost">Salir</button></form>
  </div>
</header>
<main id="view" class="view" tabindex="-1"><p class="loading">Cargando la carta…</p></main>
<div class="toasts" role="status" aria-live="polite"></div>
<script src="assets/admin.js?v=<?= $v('admin.js') ?>" defer></script>
</body>
<?php endif; ?>
</html>
