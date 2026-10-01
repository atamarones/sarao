<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/menu.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

security_headers();
header('Cache-Control: public, max-age=60');

$s = settings();
$menu = public_menu();
$promos = active_promotions();
$hours = hours_from_settings($s);
$status = open_status($hours);
$today = (int) date('N');

$featured = [];
foreach ($menu as $c) {
    foreach ($c['products'] as $p) {
        if ($p['is_featured']) {
            $featured[] = $p;
        }
    }
}

/** Precio mínimo para las tarjetas de destacados ("desde $X"). */
function price_from(array $p): ?int
{
    if ($p['price'] !== null) {
        return $p['price'];
    }
    $prices = array_filter(array_column($p['variants'], 'price'));
    return $prices ? min($prices) : null;
}

function price_html(int $price): string
{
    return $price === 0 ? '<span class="price price-free">Gratis</span>' : '<span class="price">' . e(money($price)) . '</span>';
}

function status_text(array $st): string
{
    if ($st['open']) {
        return 'Abierto · hasta las ' . format_time($st['until']);
    }
    if (isset($st['opens'])) {
        return 'Cerrado · abre ' . $st['day'] . ' a las ' . format_time($st['opens']);
    }
    return 'Cerrado';
}

$payload = [];
foreach ($menu as $c) {
    foreach ($c['products'] as $p) {
        $payload[$p['id']] = [
            'name' => $p['name'],
            'description' => $p['description'],
            'price' => $p['price'],
            'badge' => $p['badge'],
            'image' => image_url($p['image']),
            'category' => $c['name'],
            'variants' => array_map(static fn ($v) => array_intersect_key($v, array_flip(['label', 'detail', 'note', 'price'])), $p['variants']),
        ];
    }
}
$wa = preg_replace('/\D+/', '', (string) $s['whatsapp']);
$title = $s['business_name'] . ' · Carta';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="Carta de bebidas de <?= e($s['business_name']) ?>: cócteles, cervezas, aguardiente, ron, whisky y promociones de la semana. <?= e($s['address']) ?>.">
<meta name="theme-color" content="#0D141A">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($s['tagline']) ?>">
<meta property="og:image" content="assets/img/logo.png">
<link rel="icon" href="assets/img/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;800;900&family=Outfit:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/css/menu.css?v=<?= filemtime(__DIR__ . '/assets/css/menu.css') ?>">
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BarOrPub',
    'name' => $s['business_name'],
    'address' => $s['address'],
    'telephone' => $s['phone'],
    'url' => $s['website'],
    'hasMenu' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body>
<a class="skip" href="#carta">Saltar a la carta</a>

<header class="stage">
  <div class="stage-lights" aria-hidden="true"></div>
  <div class="stage-inner">
    <h1 class="logo">
      <img src="assets/img/logo-dark.webp" width="375" height="191" alt="<?= e($s['business_name']) ?>">
    </h1>
    <p class="tagline"><?= e($s['tagline']) ?></p>
    <p class="status <?= $status['open'] ? 'is-open' : 'is-closed' ?>"><span class="status-dot" aria-hidden="true"></span><?= e(status_text($status)) ?></p>
    <div class="stage-actions">
      <?php if ($s['maps_url']): ?>
      <a class="chip-link" href="<?= e($s['maps_url']) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>
        Cómo llegar
      </a>
      <?php endif; ?>
      <?php if ($wa): ?>
      <a class="chip-link" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.1L4 20Z"/></svg>
        Reservar por WhatsApp
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (trim((string) $s['notice']) !== ''): ?>
  <p class="notice" role="note"><?= e($s['notice']) ?></p>
  <?php endif; ?>
</header>

<?php if ($promos): ?>
<section class="promos" aria-labelledby="promos-title">
  <h2 id="promos-title" class="eyebrow">Promos de la semana</h2>
  <ul class="promo-track">
    <?php foreach ($promos as $pr):
        $isToday = in_array($today, array_map('intval', explode(',', $pr['days'])), true); ?>
    <li class="promo<?= $isToday ? ' is-today' : '' ?>">
      <p class="promo-when">
        <?php if ($isToday): ?><span class="today">Hoy</span><?php endif; ?>
        <?= e(format_days($pr['days'])) ?>
        <?php if ($pr['time_from'] && $pr['time_to']): ?> · <?= e(format_time($pr['time_from'])) ?> a <?= e(format_time($pr['time_to'])) ?><?php endif; ?>
      </p>
      <p class="promo-title"><?= e($pr['title']) ?></p>
      <?php if ($pr['detail']): ?><p class="promo-detail"><?= e($pr['detail']) ?></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<nav class="setlist" aria-label="Categorías">
  <div class="setlist-inner">
    <button class="search-toggle" type="button" aria-controls="search" aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
      <span class="sr-only">Buscar en la carta</span>
    </button>
    <ul class="setlist-chips">
      <?php foreach ($menu as $c): ?>
      <li><a href="#<?= e($c['slug']) ?>" data-cat="<?= e($c['slug']) ?>"><?= e($c['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="search" id="search" hidden>
    <label class="sr-only" for="q">Buscar bebida</label>
    <input id="q" type="search" placeholder="Busca: mojito, michelada, Old Parr…" autocomplete="off" enterkeyhint="search">
    <button type="button" class="search-close">Cerrar</button>
  </div>
</nav>

<main id="carta">
  <?php if ($featured): ?>
  <section class="featured" aria-labelledby="featured-title">
    <h2 id="featured-title" class="eyebrow">Los más pedidos</h2>
    <ul class="featured-track">
      <?php foreach ($featured as $p): $from = price_from($p); ?>
      <li>
        <button class="feat" type="button" data-open="<?= (int) $p['id'] ?>">
          <span class="feat-img<?= $p['image'] ? '' : ' is-empty' ?>"><?php if ($p['image']): ?><img src="<?= e(image_url($p['image'])) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= e(mb_substr($p['name'], 0, 1)) ?><?php endif; ?></span>
          <span class="feat-name"><?= e($p['name']) ?></span>
          <?php if ($from !== null): ?><span class="feat-price"><?= $p['variants'] && $p['price'] === null ? 'desde ' : '' ?><?= e(money($from)) ?></span><?php endif; ?>
        </button>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php foreach ($menu as $c): $n = count($c['products']); ?>
  <section class="cat" id="<?= e($c['slug']) ?>" aria-labelledby="h-<?= e($c['slug']) ?>">
    <header class="cat-head">
      <h2 class="kara" id="h-<?= e($c['slug']) ?>"><?= e($c['name']) ?></h2>
      <p class="cat-meta"><?= $n ?> <?= $n === 1 ? 'opción' : 'opciones' ?><?php if ($c['description']): ?> · <?= e($c['description']) ?><?php endif; ?></p>
    </header>
    <ul class="items">
      <?php foreach ($c['products'] as $p):
          $search = mb_strtolower($p['name'] . ' ' . $c['name'] . ' ' . $p['description'] . ' ' . implode(' ', array_column($p['variants'], 'label'))); ?>
      <li class="item" data-search="<?= e($search) ?>">
        <div class="item-img<?= $p['image'] ? '' : ' is-empty' ?>">
          <?php if ($p['image']): ?><img src="<?= e(image_url($p['image'])) ?>" alt="" loading="lazy" decoding="async" width="96" height="96"><?php else: ?><span aria-hidden="true"><?= e(mb_substr($p['name'], 0, 1)) ?></span><?php endif; ?>
        </div>
        <div class="item-body">
          <h3 class="item-name">
            <button type="button" data-open="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></button>
            <?php if ($p['badge']): ?><span class="badge"><?= e($p['badge']) ?></span><?php endif; ?>
          </h3>
          <?php if ($p['description']): ?><p class="item-desc"><?= e($p['description']) ?></p><?php endif; ?>
          <?php if ($p['variants']): ?>
          <ul class="prices">
            <?php foreach ($p['variants'] as $v): ?>
            <li>
              <span class="v-label"><?= e($v['label']) ?><?php if ($v['detail']): ?> <small><?= e($v['detail']) ?></small><?php endif; ?><?php if ($v['note']): ?> <em><?= e($v['note']) ?></em><?php endif; ?></span>
              <span class="leader" aria-hidden="true"></span>
              <?= price_html($v['price']) ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php elseif ($p['price'] !== null): ?>
          <p class="single"><?= price_html($p['price']) ?></p>
          <?php else: ?>
          <p class="single"><span class="ask">Pregunta el precio</span></p>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>

  <div class="empty" hidden>
    <p class="empty-title">No encontramos «<span></span>»</p>
    <p>Prueba con otra palabra, como «cerveza», «trago» o el nombre de la marca.</p>
  </div>
</main>

<footer class="foot" id="info">
  <div class="foot-grid">
    <section>
      <h2 class="eyebrow">Horario</h2>
      <dl class="hours">
        <?php foreach (WEEKDAYS as $d => $label): $h = $hours[(string) $d] ?? null; ?>
        <div class="<?= $d === $today ? 'is-today' : '' ?>">
          <dt><?= e($label) ?></dt>
          <dd><?= $h ? e(format_time($h[0]) . ' – ' . format_time($h[1])) : 'Cerrado' ?></dd>
        </div>
        <?php endforeach; ?>
      </dl>
    </section>
    <section>
      <h2 class="eyebrow">Dónde estamos</h2>
      <p class="foot-strong"><?= e($s['address']) ?></p>
      <?php if ($s['phone']): ?><p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $s['phone'])) ?>"><?= e($s['phone']) ?></a></p><?php endif; ?>
      <?php if ($s['instagram']): ?><p><a href="https://instagram.com/<?= e(ltrim((string) $s['instagram'], '@')) ?>" target="_blank" rel="noopener">@<?= e(ltrim((string) $s['instagram'], '@')) ?></a></p><?php endif; ?>
      <?php if ($s['website']): ?><p><a href="<?= e($s['website']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://|/$#', '', (string) $s['website'])) ?></a></p><?php endif; ?>
    </section>
  </div>
  <p class="fine"><?= e($s['currency_note']) ?> El consumo de alcohol es solo para mayores de 18 años. El exceso de alcohol es perjudicial para la salud.</p>
</footer>

<dialog class="sheet" aria-labelledby="sheet-title">
  <div class="sheet-inner">
    <button class="sheet-close" type="button" aria-label="Cerrar">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
    <div class="sheet-img"><img alt="" hidden></div>
    <div class="sheet-body">
      <p class="sheet-cat eyebrow"></p>
      <h2 id="sheet-title" class="sheet-title"></h2>
      <p class="sheet-desc"></p>
      <ul class="prices sheet-prices"></ul>
    </div>
  </div>
</dialog>

<script type="application/json" id="menu-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/js/menu.js?v=<?= filemtime(__DIR__ . '/assets/js/menu.js') ?>" defer></script>
</body>
</html>
