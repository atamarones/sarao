<?php
declare(strict_types=1);

/**
 * QR de las mesas para imprimir (una mesa con ?id=N o todas las activas). Solo con sesión del panel.
 * El QR lleva el token fijo de la mesa; sin el código de la noche no sirve.
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/menu.php';
require __DIR__ . '/../app/qr.php';

security_headers(true);
header('Cache-Control: no-store');

if (!is_installed() || !current_admin()) {
    header('Location: ./');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$st = $id
    ? db()->prepare('SELECT * FROM karaoke_tables WHERE id = ?')
    : db()->prepare('SELECT * FROM karaoke_tables WHERE is_active = 1 ORDER BY number');
$st->execute($id ? [$id] : []);
$tables = $st->fetchAll();
$root = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'], 2)), '/');
$base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $root . '/karaoke/';
$s = settings();
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>QR de mesas · Karaoke</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@800;900&family=Outfit:wght@400;500;600&display=swap">
<style>
  :root { --night: #0D141A; --crown: #F8B800; --muted: #5B6570; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #E9ECEF; color: var(--night); font: 400 15px/1.45 'Outfit', system-ui, sans-serif; }
  .bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; padding: 14px 16px; background: var(--night); color: #F4EFF8; }
  .bar a, .bar button { color: inherit; font: 600 15px 'Outfit', sans-serif; }
  .bar button { min-height: 44px; padding: 0 18px; border: 0; border-radius: 999px; background: var(--crown); color: var(--night); cursor: pointer; }
  .sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 16px; padding: 16px; max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border-radius: 18px; padding: 22px 20px 18px; display: grid; justify-items: center; gap: 10px; text-align: center; break-inside: avoid; border: 2px solid var(--night); }
  .card h1 { margin: 0; font: 900 52px/.9 'Big Shoulders Display', 'Arial Narrow', sans-serif; text-transform: uppercase; }
  .card .eyebrow { margin: 0; font: 600 12px/1 'Outfit', sans-serif; letter-spacing: .16em; text-transform: uppercase; color: var(--muted); }
  .card svg { width: 220px; height: 220px; }
  .card ol { margin: 0; padding-left: 20px; text-align: left; font-size: 14px; }
  .card small { color: var(--muted); font-size: 11px; overflow-wrap: anywhere; }
  .empty { padding: 40px 16px; text-align: center; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .sheet { grid-template-columns: repeat(2, 1fr); gap: 10mm; padding: 0; }
    .card { page-break-inside: avoid; }
  }
</style>
</head>
<body>
<div class="bar">
  <a href="./#karaoke">← Volver al panel</a>
  <button type="button" id="print">Imprimir</button>
</div>
<?php if (!$tables): ?>
<p class="empty">No hay mesas activas. Créalas en el panel → Karaoke.</p>
<?php endif; ?>
<main class="sheet">
  <?php foreach ($tables as $t): $url = $base . '?m=' . $t['qr_token']; ?>
  <section class="card">
    <p class="eyebrow"><?= e($s['business_name']) ?> · Karaoke</p>
    <h1><?= e($t['name']) ?></h1>
    <?= qr_svg($url, 220) ?>
    <ol>
      <li>Escanea con la cámara del celular.</li>
      <li>Escribe el código de la noche (está en la pantalla).</li>
      <li>Busca tu canción y pídela.</li>
    </ol>
    <small><?= e($url) ?></small>
  </section>
  <?php endforeach; ?>
</main>
<script src="assets/print.js"></script>
</body>
</html>
