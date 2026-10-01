<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/menu.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

// El micrófono se permite solo aquí, para «Prueba tu voz»: el audio se analiza en el navegador y nunca se envía.
security_headers(false, true);
header('Cache-Control: no-cache');

$s = settings();
$hours = hours_from_settings($s);
$status = open_status($hours);
$today = (int) date('N');
$promos = active_promotions();
$testimonials = active_testimonials();
$reserve = (string) $s['reservation_url'];
$wa = preg_replace('/\D+/', '', (string) $s['whatsapp']);
$ig = ltrim((string) $s['instagram'], '@');
$tt = ltrim((string) $s['tiktok'], '@');
$songs = trim((string) $s['songs_count']) ?: '8.000';

$featured = db()->query('SELECT p.id, p.name, p.price, p.image, c.name AS category,
        (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1 AND v.price > 0) AS from_price
    FROM products p JOIN categories c ON c.id = p.category_id
    WHERE p.is_featured = 1 AND p.is_active = 1 AND c.is_active = 1
    ORDER BY c.sort_order, p.sort_order LIMIT 6')->fetchAll();

$promosByDay = [];
foreach ($promos as $pr) {
    foreach (array_map('intval', explode(',', $pr['days'])) as $d) {
        $promosByDay[$d][] = $pr;
    }
}

$heroSlides = [
    ['escenario-duo', 'Dos amigas cantando bajo el letrero de neón de El Sarao Pub'],
    ['escenario-grupo', 'Grupo cantando en el escenario con sombrero mexicano'],
    ['escenario-voz', 'Una cantante en el escenario de El Sarao Pub'],
    ['salon', 'El salón de El Sarao Pub con sus mesas y luces'],
];
$gallery = [
    ['escenario-publico', 'El público grabando al cantante desde las mesas', 'tall'],
    ['mesa-amigas', 'Amigas brindando en una mesa', 'wide'],
    ['escenario-solista', 'Un cantante bajo el neón de la corona', 'wide'],
    ['mesa-grupo', 'Grupo de amigos junto al mural de la guitarra', 'wide'],
    ['escenario-mural', 'Cantante frente al mural de colores', 'tall'],
    ['mesa-ladrillo', 'Tres amigas en la mesa junto al muro de ladrillo', 'wide'],
    ['escenario-voz', 'Cantante en pleno solo', 'tall'],
];

function status_line(array $st): string
{
    if ($st['open']) {
        return 'Abierto ahora · hasta las ' . format_time($st['until']);
    }
    return isset($st['opens']) ? 'Abrimos ' . $st['day'] . ' a las ' . format_time($st['opens']) : 'Hoy cerrado';
}

function img(string $name): string
{
    return 'assets/img/site/' . $name . '.webp';
}

$v = static fn (string $f): int => (int) @filemtime(__DIR__ . '/' . $f);
$title = 'El Sarao Pub · El mejor karaoke de Bogotá';
$desc = 'Karaoke bar en La Candelaria, Bogotá: más de ' . $songs . ' canciones, rumba crossover y cócteles. Reserva tu mesa y sube al escenario.';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="theme-color" content="#07090D">
<?= share_meta($s, $title, $desc, '/', 'og-home.jpg') ?>
<link rel="icon" href="assets/img/favicon.png">
<link rel="preload" as="image" href="<?= img('escenario-duo') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800;900&family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500;600&display=swap">
<link rel="stylesheet" href="assets/css/site.css?v=<?= $v('assets/css/site.css') ?>">
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BarOrPub',
    'name' => $s['business_name'],
    'description' => $desc,
    'url' => $s['website'],
    'telephone' => $s['phone'],
    'email' => $s['email'],
    'image' => rtrim((string) $s['website'], '/') . '/assets/img/site/escenario-duo.webp',
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Carrera 5 #17-69', 'addressLocality' => 'Bogotá', 'addressRegion' => 'Bogotá D.C.', 'addressCountry' => 'CO'],
    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 4.6032742, 'longitude' => -74.0707668],
    'hasMenu' => rtrim((string) $s['website'], '/') . '/carta/',
    'acceptsReservations' => $reserve,
    'sameAs' => array_values(array_filter([$ig ? "https://www.instagram.com/$ig/" : null, $tt ? "https://www.tiktok.com/@$tt" : null, $s['facebook'] ?: null])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body>
<a class="skip" href="#main">Saltar al contenido</a>

<!-- Navegación flotante -->
<header class="nav" data-nav>
  <a class="nav-logo" href="#top" aria-label="El Sarao Pub, inicio"><img src="assets/img/logo-dark.webp" alt="" width="375" height="191"></a>
  <nav class="nav-links" aria-label="Principal">
    <a href="#escenario">El escenario</a>
    <a href="#semana">La semana</a>
    <a href="carta/">La carta</a>
    <a href="#galeria">Galería</a>
    <a href="#llegar">Cómo llegar</a>
  </nav>
  <a class="btn btn-neon nav-cta" href="<?= e($reserve) ?>" target="_blank" rel="noopener">Reservar</a>
  <button class="nav-burger" type="button" aria-expanded="false" aria-controls="menu" data-burger><span></span><span></span><span class="sr-only">Menú</span></button>
</header>
<div class="menu" id="menu" hidden data-menu>
  <nav aria-label="Menú móvil">
    <a href="#escenario">El escenario</a>
    <a href="#semana">La semana</a>
    <a href="carta/">La carta</a>
    <a href="#galeria">Galería</a>
    <a href="#celebra">Celebraciones</a>
    <a href="#llegar">Cómo llegar</a>
  </nav>
  <a class="btn btn-neon btn-lg" href="<?= e($reserve) ?>" target="_blank" rel="noopener">Reservar mesa</a>
</div>

<main id="main">
<!-- ============ HERO ============ -->
<section class="hero" id="top" aria-label="Bienvenida">
  <div class="hero-slides" data-slides>
    <?php foreach ($heroSlides as $i => [$file, $alt]): ?>
    <figure class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>">
      <img src="<?= img($file) ?>" alt="<?= e($alt) ?>" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
    </figure>
    <?php endforeach; ?>
  </div>
  <div class="hero-shade" aria-hidden="true"></div>
  <div class="hero-beams" aria-hidden="true"><span></span><span></span><span></span></div>

  <div class="hero-content">
    <p class="eyebrow hero-eyebrow" data-hero-in><span class="live-dot" aria-hidden="true"></span>Karaoke bar · La Candelaria, Bogotá</p>
    <h1 class="hero-title" data-hero-in>
      <span class="line">El mejor</span>
      <span class="line">karaoke</span>
      <span class="line"><em>de Bogotá</em></span>
    </h1>
    <!-- Firma: la letra en pantalla, palabra por palabra, con la bolita que marca el ritmo -->
    <div class="lyric" data-lyric aria-live="off" data-hero-in>
      <span class="lyric-ball" aria-hidden="true"></span>
      <p class="lyric-line" data-lines='<?= e(json_encode([
          'La noche es tuya, el micrófono también.',
          "Más de $songs canciones esperando tu voz.",
          'Del reggaetón al rock, del merengue al pop.',
          'Hoy no se canta bajito: se canta a grito herido.',
      ], JSON_UNESCAPED_UNICODE)) ?>'>La noche es tuya, el micrófono también.</p>
    </div>
    <div class="hero-ctas" data-hero-in>
      <a class="btn btn-neon btn-lg" href="<?= e($reserve) ?>" target="_blank" rel="noopener">
        Reservar mesa
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
      <a class="btn btn-glass btn-lg" href="carta/">Ver la carta</a>
    </div>
  </div>

  <div class="hero-counter" aria-hidden="true">
    <span data-slide-num>01</span>
    <span class="hero-progress"><span data-slide-bar></span></span>
    <span><?= sprintf('%02d', count($heroSlides)) ?></span>
  </div>

  <ul class="hero-stats" data-hero-in>
    <li><strong>+<?= e($songs) ?></strong><span>canciones de todos los géneros</span></li>
    <li><strong>Crossover</strong><span>del reggaetón al rock</span></li>
    <li><strong>Cócteles</strong><span>licores y cervezas para la garganta</span></li>
    <li class="<?= $status['open'] ? 'is-open' : '' ?>"><strong><span class="live-dot" aria-hidden="true"></span><?= $status['open'] ? 'Abierto' : 'Hoy' ?></strong><span><?= e(status_line($status)) ?></span></li>
  </ul>
</section>

<!-- ============ GÉNEROS ============ -->
<section class="genres" aria-label="Géneros que suenan">
  <div class="marquee" data-marquee>
    <div class="marquee-track">
      <?php $g = ['Reggaetón', 'Rock en español', 'Salsa', 'Vallenato', 'Merengue', 'Pop', 'Despecho', 'Baladas', 'Rancheras', 'Clásicos en inglés'];
      for ($k = 0; $k < 2; $k++): foreach ($g as $i => $name): ?>
      <span class="<?= $i % 2 ? 'outline' : '' ?>"<?= $k ? ' aria-hidden="true"' : '' ?>><?= e($name) ?></span><i aria-hidden="true">✦</i>
      <?php endforeach; endfor; ?>
    </div>
  </div>
</section>

<!-- ============ EL ESCENARIO (interactivo) ============ -->
<section class="stage" id="escenario" aria-labelledby="stage-title">
  <div class="wrap">
    <header class="section-head" data-reveal>
      <p class="eyebrow">El escenario</p>
      <h2 class="section-title" id="stage-title">¿Qué cantas <em>hoy?</em></h2>
      <p class="section-lead">¿No te decides? Elige un género, gira y deja que la noche escoja por ti. Después calienta la voz aquí mismo.</p>
    </header>

    <div class="stage-grid">
      <div class="jukebox" data-reveal>
        <div class="chips" role="group" aria-label="Género" data-genres></div>
        <div class="reel" aria-live="polite" data-reel>
          <div class="reel-window">
            <div class="reel-strip" data-strip>
              <div class="reel-item"><p class="reel-song">Toca «Girar»</p><p class="reel-artist">y descubre tu canción</p></div>
            </div>
          </div>
          <div class="reel-glow" aria-hidden="true"></div>
        </div>
        <div class="jukebox-actions">
          <button class="btn btn-neon btn-lg" type="button" data-spin>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>
            Girar
          </button>
          <p class="fine">Ideas para tu turno. Si tu canción no aparece, pídela: el catálogo tiene más de <?= e($songs) ?>.</p>
        </div>
      </div>

      <div class="voice" data-reveal>
        <div class="voice-head">
          <p class="eyebrow">Prueba tu voz</p>
          <p class="voice-score"><span data-score>0</span><small>/100</small></p>
        </div>
        <canvas class="voice-viz" width="640" height="260" data-viz aria-hidden="true"></canvas>
        <p class="voice-label" data-voice-label>Canta, grita o aplaude: medimos tu potencia como un aplausómetro.</p>
        <div class="voice-actions">
          <button class="btn btn-glass btn-lg" type="button" data-mic aria-pressed="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
            <span data-mic-label>Encender micrófono</span>
          </button>
        </div>
        <p class="fine">El sonido se analiza solo en tu celular o computador. No se graba ni se envía.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============ LA EXPERIENCIA ============ -->
<section class="exp" aria-labelledby="exp-title">
  <div class="wrap">
    <header class="section-head" data-reveal>
      <p class="eyebrow">La experiencia</p>
      <h2 class="section-title" id="exp-title">Una noche, <em>tres actos</em></h2>
    </header>
    <div class="acts">
      <article class="act" data-reveal>
        <div class="act-img"><img src="<?= img('escenario-solista') ?>" alt="Un cantante en el escenario bajo el neón" loading="lazy"></div>
        <div class="act-body">
          <p class="act-num">Primer acto</p>
          <h3>Noches de karaoke</h3>
          <p>Más de <?= e($songs) ?> canciones, sonido envolvente y un escenario con tu nombre. Canta solo, a dúo o con todo el parche.</p>
        </div>
      </article>
      <article class="act" data-reveal>
        <div class="act-img"><img src="<?= img('escenario-grupo') ?>" alt="Grupo de amigas cantando juntas" loading="lazy"></div>
        <div class="act-body">
          <p class="act-num">Segundo acto</p>
          <h3>Rumba crossover</h3>
          <p>Del reggaetón al rock, del merengue al pop. Tú eliges el ritmo y nosotros ponemos la fiesta.</p>
        </div>
      </article>
      <article class="act" data-reveal>
        <div class="act-img"><img src="<?= img('mesa-ladrillo') ?>" alt="Amigas brindando con cerveza y vino" loading="lazy"></div>
        <div class="act-body">
          <p class="act-num">Tercer acto</p>
          <h3>Cócteles y licores</h3>
          <p>¿Tu garganta necesita un incentivo? Cócteles, aguardiente, whisky y cervezas para cantar mejor.</p>
          <a class="link-arrow" href="carta/">Ver la carta <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- ============ LA SEMANA ============ -->
<section class="week" id="semana" aria-labelledby="week-title">
  <div class="wrap">
    <header class="section-head section-head-row" data-reveal>
      <div>
        <p class="eyebrow">La semana</p>
        <h2 class="section-title" id="week-title">Cada noche <em>suena distinto</em></h2>
      </div>
      <p class="week-status <?= $status['open'] ? 'is-open' : '' ?>"><span class="live-dot" aria-hidden="true"></span><?= e(status_line($status)) ?></p>
    </header>
    <ol class="days">
      <?php foreach (WEEKDAYS as $d => $name): $h = $hours[(string) $d] ?? null; $dp = $promosByDay[$d] ?? []; ?>
      <li class="day<?= $d === $today ? ' is-today' : '' ?><?= $h ? '' : ' is-closed' ?>" data-reveal>
        <p class="day-name"><?= e($name) ?><?php if ($d === $today): ?> <span class="tag">Hoy</span><?php endif; ?></p>
        <p class="day-hours"><?= $h ? e(format_time($h[0]) . ' – ' . format_time($h[1])) : 'Cerrado' ?></p>
        <?php foreach ($dp as $pr): ?>
        <div class="day-promo">
          <p class="day-promo-title"><?= e($pr['title']) ?></p>
          <?php if ($pr['time_from'] && $pr['time_to']): ?><p class="day-promo-time"><?= e(format_time($pr['time_from']) . ' a ' . format_time($pr['time_to'])) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- ============ LA CARTA ============ -->
<?php if ($featured): ?>
<section class="menu-teaser" aria-labelledby="menu-title">
  <div class="wrap">
    <header class="section-head section-head-row" data-reveal>
      <div>
        <p class="eyebrow">La carta</p>
        <h2 class="section-title" id="menu-title">Lo que <em>más se pide</em></h2>
      </div>
      <a class="btn btn-glass" href="carta/">Ver carta completa</a>
    </header>
    <ul class="bottles">
      <?php foreach ($featured as $p): $price = $p['price'] !== null ? (int) $p['price'] : ($p['from_price'] !== null ? (int) $p['from_price'] : null); ?>
      <li class="bottle" data-reveal>
        <a href="carta/">
          <span class="bottle-img"><?php if ($p['image']): ?><img src="<?= e(image_url($p['image'])) ?>" alt="" loading="lazy"><?php endif; ?></span>
          <span class="bottle-cat"><?= e($p['category']) ?></span>
          <span class="bottle-name"><?= e($p['name']) ?></span>
          <?php if ($price !== null): ?><span class="bottle-price"><?= $p['price'] === null ? 'desde ' : '' ?><?= e(money($price)) ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<!-- ============ GALERÍA (desplazamiento horizontal) ============ -->
<section class="gallery" id="galeria" aria-labelledby="gallery-title" data-gallery>
  <div class="gallery-pin">
    <header class="gallery-head">
      <p class="eyebrow">Galería</p>
      <h2 class="section-title" id="gallery-title">Así suena <em>un sábado</em></h2>
      <?php if ($ig): ?><a class="link-arrow" href="https://www.instagram.com/<?= e($ig) ?>/" target="_blank" rel="noopener">Síguenos en @<?= e($ig) ?> <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a><?php endif; ?>
    </header>
    <ul class="gallery-track" data-track>
      <?php foreach ($gallery as [$file, $alt, $shape]): ?>
      <li class="shot shot-<?= $shape ?>"><img src="<?= img($file) ?>" alt="<?= e($alt) ?>" loading="lazy" decoding="async"></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ============ CELEBRA ============ -->
<section class="celebrate" id="celebra" aria-labelledby="celebrate-title">
  <div class="wrap celebrate-grid">
    <div class="celebrate-img" data-reveal>
      <img src="<?= img('mesa-grupo') ?>" alt="Un grupo celebrando en una mesa de El Sarao Pub" loading="lazy">
      <span class="sticker" aria-hidden="true">¡Feliz<br>cumple!</span>
    </div>
    <div data-reveal>
      <p class="eyebrow">Celebraciones</p>
      <h2 class="section-title" id="celebrate-title">Tu cumpleaños <em>merece escenario</em></h2>
      <p class="section-lead">Cumpleaños, despedidas, fiestas de oficina y eventos privados. Reserva la mesa y nosotros montamos la fiesta.</p>
      <ul class="checks">
        <li>Decoración de cumpleaños con cortina, globos y letrero</li>
        <li>Combos de botella con cerveza para compartir</li>
        <li>Fiestas temáticas y eventos privados</li>
      </ul>
      <div class="hero-ctas">
        <a class="btn btn-neon btn-lg" href="<?= e($reserve) ?>" target="_blank" rel="noopener">Reservar celebración</a>
        <?php if ($wa): ?><a class="btn btn-glass btn-lg" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, quiero cotizar una celebración en El Sarao Pub') ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============ TESTIMONIOS ============ -->
<?php if ($testimonials): ?>
<section class="reviews" aria-labelledby="reviews-title">
  <div class="wrap">
    <header class="section-head section-head-row" data-reveal>
      <div>
        <p class="eyebrow">Aplausos</p>
        <h2 class="section-title" id="reviews-title">Lo que dicen <em>después de cantar</em></h2>
      </div>
      <?php if ($s['reviews_url']): ?><a class="btn btn-glass" href="<?= e($s['reviews_url']) ?>" target="_blank" rel="noopener">Ver reseñas en Google</a><?php endif; ?>
    </header>
  </div>
  <div class="review-rail" data-rail>
    <ul class="review-track">
      <?php foreach ($testimonials as $t): ?>
      <li class="review" data-reveal>
        <p class="review-stars" aria-label="<?= (int) $t['rating'] ?> de 5 estrellas"><?= str_repeat('★', (int) $t['rating']) ?><span><?= str_repeat('★', 5 - (int) $t['rating']) ?></span></p>
        <blockquote><p>«<?= e($t['body']) ?>»</p></blockquote>
        <p class="review-author"><?= e($t['author']) ?><?php if ($t['source']): ?> <span>· <?= e($t['source']) ?></span><?php endif; ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<!-- ============ CÓMO LLEGAR ============ -->
<section class="visit" id="llegar" aria-labelledby="visit-title">
  <div class="wrap visit-grid">
    <div data-reveal>
      <p class="eyebrow">Cómo llegar</p>
      <h2 class="section-title" id="visit-title">En el corazón <em>de Bogotá</em></h2>
      <p class="visit-address"><?= e($s['address']) ?></p>
      <p class="section-lead">A pasos de La Candelaria, Monserrate, el Museo del Oro y la Casa de Bolívar.</p>
      <dl class="visit-facts">
        <div><dt>TransMilenio</dt><dd>Estaciones Las Aguas, Museo del Oro o Universidades</dd></div>
        <div><dt>Horario</dt><dd>
          <?php foreach (WEEKDAYS as $d => $name): $h = $hours[(string) $d] ?? null; if (!$h) { continue; } ?>
          <span class="<?= $d === $today ? 'is-today' : '' ?>"><?= e(WEEKDAYS_SHORT[$d]) ?> <?= e(format_time($h[0]) . ' – ' . format_time($h[1])) ?></span>
          <?php endforeach; ?>
        </dd></div>
      </dl>
      <div class="hero-ctas">
        <?php if ($s['maps_url']): ?><a class="btn btn-neon" href="<?= e($s['maps_url']) ?>" target="_blank" rel="noopener">Abrir en Google Maps</a><?php endif; ?>
        <a class="btn btn-glass" href="https://waze.com/ul?ll=4.6032742,-74.0707668&amp;navigate=yes" target="_blank" rel="noopener">Ir con Waze</a>
      </div>
    </div>
    <figure class="visit-img" data-reveal>
      <img src="<?= img('fachada') ?>" alt="Fachada de El Sarao Pub con murales de colores" loading="lazy">
      <figcaption>Busca los murales de la Carrera 5</figcaption>
    </figure>
  </div>
</section>

<!-- ============ CIERRE ============ -->
<section class="finale" aria-labelledby="finale-title">
  <div class="finale-marquee" aria-hidden="true"><span>Sube al escenario ✦ Sube al escenario ✦ Sube al escenario ✦ Sube al escenario ✦ </span></div>
  <div class="wrap finale-inner" data-reveal>
    <h2 class="finale-title" id="finale-title">¿Listo para <em>tu canción?</em></h2>
    <a class="btn btn-neon btn-xl" href="<?= e($reserve) ?>" target="_blank" rel="noopener">Reservar mesa</a>
  </div>
</section>
</main>

<footer class="foot">
  <div class="wrap foot-grid">
    <div>
      <img class="foot-logo" src="assets/img/logo-dark.webp" alt="El Sarao Pub" width="375" height="191" loading="lazy">
      <p class="muted"><?= e($s['tagline']) ?></p>
    </div>
    <nav aria-label="Enlaces del pie">
      <p class="eyebrow">Visítanos</p>
      <p><?= e($s['address']) ?></p>
      <?php if ($s['phone']): ?><p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $s['phone'])) ?>"><?= e($s['phone']) ?></a></p><?php endif; ?>
      <?php if ($s['email']): ?><p><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></p><?php endif; ?>
    </nav>
    <nav aria-label="Redes sociales">
      <p class="eyebrow">Síguenos</p>
      <?php if ($ig): ?><p><a href="https://www.instagram.com/<?= e($ig) ?>/" target="_blank" rel="noopener">Instagram</a></p><?php endif; ?>
      <?php if ($tt): ?><p><a href="https://www.tiktok.com/@<?= e($tt) ?>" target="_blank" rel="noopener">TikTok</a></p><?php endif; ?>
      <?php if ($s['facebook']): ?><p><a href="<?= e($s['facebook']) ?>" target="_blank" rel="noopener">Facebook</a></p><?php endif; ?>
      <p><a href="carta/">La carta</a></p>
    </nav>
  </div>
  <p class="wrap fine foot-legal">© <?= date('Y') ?> <?= e($s['business_name']) ?>. Prohíbase el expendio de bebidas embriagantes a menores de edad. El exceso de alcohol es perjudicial para la salud.</p>
</footer>

<!-- Barra fija en móvil -->
<nav class="dock" aria-label="Accesos rápidos">
  <a href="carta/">Carta</a>
  <a class="dock-main" href="<?= e($reserve) ?>" target="_blank" rel="noopener">Reservar mesa</a>
  <?php if ($wa): ?><a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
</nav>

<script src="assets/vendor/gsap.min.js?v=3.12.5" defer></script>
<script src="assets/vendor/ScrollTrigger.min.js?v=3.12.5" defer></script>
<script src="assets/vendor/lenis.min.js?v=1.1.13" defer></script>
<script src="assets/js/site.js?v=<?= $v('assets/js/site.js') ?>" defer></script>
</body>
</html>
