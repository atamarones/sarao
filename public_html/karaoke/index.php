<?php
declare(strict_types=1);

/**
 * Página de mesa del karaoke (QR impreso → /karaoke/?m=<token>). Sin login: el token del QR
 * identifica la mesa y el código de la noche, que genera el panel y da el personal del bar, abre los pedidos.
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/menu.php';
require __DIR__ . '/../app/karaoke.php';

if (!is_installed()) {
    header('Location: ../install.php');
    exit;
}

security_headers();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$s = settings();
$token = (string) ($_GET['m'] ?? '');
$table = karaoke_table_by_token(db(), $token);
if (!$table || !(int) $table['is_active']) {
    http_response_code(404);
}
$v = static fn (string $f): int => (int) @filemtime(__DIR__ . '/../assets/' . $f);
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= $table ? e($table['name']) . ' · ' : '' ?>Karaoke · <?= e($s['business_name']) ?></title>
<meta name="theme-color" content="#0D141A">
<link rel="icon" href="../assets/img/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;800;900&family=Outfit:wght@400;500;600&display=swap">
<link rel="stylesheet" href="../assets/css/menu.css?v=<?= $v('css/menu.css') ?>">
<link rel="stylesheet" href="../assets/css/karaoke.css?v=<?= $v('css/karaoke.css') ?>">
</head>
<body class="kk">
<header class="stage kk-stage">
  <div class="stage-lights" aria-hidden="true"></div>
  <div class="stage-inner">
    <a class="kk-logo" href="../carta/" title="Ver la carta"><img src="../assets/img/logo-dark.webp" width="375" height="191" alt="<?= e($s['business_name']) ?>"></a>
    <?php if ($table && (int) $table['is_active']): ?>
    <p class="eyebrow">Karaoke · pide desde tu mesa</p>
    <h1 class="kara kk-table"><?= e($table['name']) ?></h1>
    <p class="status kk-status" id="kk-status" hidden><span class="status-dot" aria-hidden="true"></span><span></span></p>
    <?php else: ?>
    <h1 class="kara kk-table">QR no válido</h1>
    <p class="tagline">Este código no corresponde a ninguna mesa activa. Pide ayuda en la barra.</p>
    <?php endif; ?>
  </div>
</header>

<?php if ($table && (int) $table['is_active']): ?>
<main id="kk" class="kk-main" data-token="<?= e($token) ?>">
  <section class="kk-gate" id="kk-gate" aria-labelledby="gate-title" hidden>
    <h2 id="gate-title" class="kk-h2">Código de la noche</h2>
    <p class="kk-muted">Pídeselo al personal del bar. Cambia cada noche.</p>
    <form id="gate-form" class="kk-gate-form" novalidate>
      <label class="sr-only" for="gate-code">Código de 4 números</label>
      <input id="gate-code" class="kk-code" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="one-time-code" placeholder="0000" required>
      <button class="kk-btn kk-btn-primary" type="submit">Entrar</button>
    </form>
    <p class="kk-error" id="gate-error" role="alert" hidden></p>
  </section>

  <p class="kk-closed" id="kk-closed" hidden></p>

  <div id="kk-app" hidden>
    <nav class="setlist kk-nav" aria-label="Secciones">
      <ul class="kk-tabs">
        <li><a href="#buscar" data-tab="buscar">Buscar</a></li>
        <li><a href="#enlace" data-tab="enlace">YouTube</a></li>
        <li><a href="#pedidos" data-tab="pedidos">Mis pedidos<span class="kk-count" id="kk-count" hidden></span></a></li>
      </ul>
    </nav>

    <section class="kk-panel" id="tab-buscar" aria-labelledby="h-buscar">
      <h2 id="h-buscar" class="sr-only">Buscar canción</h2>
      <div class="search kk-search">
        <label class="sr-only" for="q">Canción o artista</label>
        <input id="q" type="search" placeholder="Canción o artista: Juanes, Antología…" autocomplete="off" enterkeyhint="search" maxlength="80">
      </div>
      <p class="kk-hint" id="search-hint">Busca en las canciones del karaoke. Si no está, pega el enlace de YouTube.</p>
      <ul class="kk-results" id="results"></ul>
    </section>

    <section class="kk-panel" id="tab-enlace" aria-labelledby="h-enlace" hidden>
      <h2 id="h-enlace" class="kk-h2">¿No está en el catálogo?</h2>
      <ol class="kk-steps">
        <li>Abre la canción en YouTube (mejor si dice «karaoke» o «letra»).</li>
        <li>Toca <strong>Compartir</strong> y luego <strong>Copiar enlace</strong>.</li>
        <li>Pégalo aquí. Videos de máximo 10 minutos.</li>
      </ol>
      <form id="link-form" class="kk-form" novalidate>
        <label>Enlace del video
          <input name="youtube_url" type="url" inputmode="url" autocomplete="off" placeholder="https://youtu.be/…" maxlength="300" required>
        </label>
        <label>¿Quién canta?
          <input name="singer" maxlength="30" autocomplete="given-name" required>
        </label>
        <p class="kk-error" role="alert" hidden></p>
        <button class="kk-btn kk-btn-primary" type="submit">Pedir este video</button>
      </form>
    </section>

    <section class="kk-panel" id="tab-pedidos" aria-labelledby="h-pedidos" hidden>
      <h2 id="h-pedidos" class="kk-h2">Pedidos de tu mesa</h2>
      <p class="kk-hint" id="agent-warning" hidden>El karaoke del bar no está conectado en este momento. Tus pedidos se guardan y entran en cuanto vuelva.</p>
      <ul class="kk-orders" id="orders"></ul>
    </section>
  </div>
</main>

<dialog class="sheet kk-sheet" id="ask" aria-labelledby="ask-title">
  <form method="dialog" class="sheet-inner kk-sheet-body" id="ask-form" novalidate>
    <button class="sheet-close" type="button" value="cancel" aria-label="Cerrar" data-close>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
    <p class="eyebrow" id="ask-artist"></p>
    <h2 class="sheet-title" id="ask-title"></h2>
    <label>¿Quién canta?
      <input name="singer" maxlength="30" autocomplete="given-name" required>
    </label>
    <p class="kk-error" role="alert" hidden></p>
    <button class="kk-btn kk-btn-primary" type="submit" value="ok">Pedir canción</button>
  </form>
</dialog>
<div class="kk-toast" role="status" aria-live="polite"></div>
<script src="../assets/js/karaoke.js?v=<?= $v('js/karaoke.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
