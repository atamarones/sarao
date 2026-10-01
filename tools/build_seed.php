<?php
declare(strict_types=1);

/**
 * Genera public_html/app/seed.json a partir del menú exportado de Pirpos (tools/pirpos-menu.json)
 * y descarga + optimiza las imágenes a public_html/uploads/products/*.webp.
 *
 * Uso (una sola vez, en local):  php tools/build_seed.php
 * Los nombres y presentaciones se normalizan aquí; luego todo se edita desde el panel.
 */

$root = dirname(__DIR__);
$src = json_decode((string) file_get_contents(__DIR__ . '/pirpos-menu.json'), true, 512, JSON_THROW_ON_ERROR);
$uploadDir = $root . '/public_html/uploads/products';

$categoryNames = [
    'COCTELES' => 'Cócteles', 'RON' => 'Ron', 'AGUARDIENTE' => 'Aguardiente', 'CERVEZA' => 'Cervezas',
    'VINO' => 'Vinos', 'TEQUILA' => 'Tequila', 'VODKA' => 'Vodka', 'WHISKY' => 'Whisky',
    'ADICIONAL' => 'Adicionales', 'GINEBRA' => 'Ginebra', 'DECORACION' => 'Decoración',
    'GASEOSAS' => 'Gaseosas', 'AGUA TONICA' => 'Agua tónica', 'AGUA' => 'Agua',
];
$categoryDescriptions = [
    'ADICIONAL' => 'Cobros adicionales',
];
// Adicionales y Decoración se mueven al final; el resto conserva el orden original.
$lastCategories = ['ADICIONAL', 'DECORACION'];

$nameOverrides = [
    'MARGARITA BLUE' => 'Margarita Blue', 'CUBA LIBRE' => 'Cuba Libre', 'CAIPIROSKA' => 'Caipiroska',
    'MOJITO CLÁSICO' => 'Mojito clásico', 'MARGARITA CLÁSICO' => 'Margarita clásica', '2X40 COCTELES' => 'Cócteles 2×40',
    'CLUB COLOMBIA DORADA' => 'Club Colombia Dorada', 'STELLA ARTOIS' => 'Stella Artois', 'CORONA' => 'Corona',
    'Cubeta Andina y 1/2 Antioqueño' => 'Cubeta Andina + ½ Antioqueño', 'AGUILA' => 'Águila',
    'HEINEKEN 250ML' => 'Heineken 250 ml', 'Cubeta Andina y Nectar' => 'Cubeta Andina + ½ Néctar',
    'CLUB COLOMBIA ROJA' => 'Club Colombia Roja', 'ANDINA' => 'Andina', 'AGUILA LIGHT' => 'Águila Light',
    'Nectar Club' => 'Néctar Club', 'Amarillo De Manzanares' => 'Amarillo de Manzanares',
    'Jose Cuervo Especial Reposado' => 'José Cuervo Especial Reposado', 'SMIRNOFF' => 'Smirnoff',
    'Jack Daniels Honey 700ml' => "Jack Daniel's Honey 700 ml", 'Black And White' => 'Black & White',
    'Jack Daniels' => "Jack Daniel's", 'Whisky Buchanans 12 Años' => "Buchanan's 12 años",
    'Descorche' => 'Descorche', 'Vaso' => 'Vaso', 'Vaso Michelado' => 'Vaso michelado',
    'GINEBRA GORDONS' => "Gordon's", 'DECORACIÓN CUMPLEAÑOS' => 'Decoración de cumpleaños',
    'CANADA DRY' => 'Canada Dry', 'GATORADE' => 'Gatorade', 'COCA COLA' => 'Coca-Cola',
    'Agua Tonica Schweppes' => 'Agua tónica Schweppes', 'AGUA CIELO' => 'Agua Cielo',
    'AGUA BRISA CON GAS 600 ML' => 'Agua Brisa con gas 600 ml', 'AGUA NATURAL ZALVA' => 'Agua Zalva',
    'AGUA NATURAL CRISTAL' => 'Agua Cristal', 'AGUA CON GAS CRISTAL' => 'Agua Cristal con gas', 'BRETAÑA' => 'Bretaña',
    'Medellín Añejo 3 Años' => 'Medellín Añejo 3 años', 'Havana Club Añejo Especial' => 'Havana Club Añejo Especial',
];

$descriptionOverrides = [
    '2X40 COCTELES' => 'Dos cócteles iguales por $40.000. Miércoles a sábado, de 6 a 10 p. m.',
    'Cacique 500 Gran Reserva' => 'Ron venezolano.',
    'Cubeta Andina y 1/2 Antioqueño' => 'Cubeta de cerveza Andina con media de Antioqueño.',
    'Cubeta Andina y Nectar' => 'Cubeta de cerveza Andina con media de Néctar.',
    'Descorche' => 'Por botella que traigas.',
    'DECORACIÓN CUMPLEAÑOS' => "1 cortina, 20 globos, letrero de «Feliz cumpleaños» y set de globos decorativos (estrellas, corazones, números u otro motivo; máximo 2).\nImagen solo de referencia.",
];

$featured = ['Amarillo De Manzanares', 'Cerveza Poker', 'Whisky Buchanans 12 Años', 'Frontera Merlot'];
// Productos sin foto en Pirpos: se compone la imagen con las fotos reales de sus botellas.
$composites = [
    'Cubeta Andina y 1/2 Antioqueño' => ['andina', 'andina', 'andina', 'antioqueno-azul'],
    'Cubeta Andina y Nectar' => ['andina', 'andina', 'andina', 'nectar-club'],
];
$badges = ['2X40 COCTELES' => 'Promo', 'Cubeta Andina y 1/2 Antioqueño' => 'Combo', 'Cubeta Andina y Nectar' => 'Combo'];

/** Presentación normalizada: [label, detail, note]. */
function variant_label(string $productName, array $sub): array
{
    $short = trim((string) ($sub['nameShort'] ?? ''));
    $full = trim(preg_replace('/\s+/', ' ', (string) ($sub['name'] ?? '')));
    $lower = mb_strtolower($short . ' ' . $full);

    $size = null;
    if (preg_match('/(\d{3})\s?ml/i', $full, $m)) {
        $size = $m[1] . ' ml';
    }
    $note = null;
    if (preg_match('/\((mie|mier)\)/i', $short)) {
        $note = 'Solo miércoles';
    } elseif (preg_match('/\(jue\)/i', $short)) {
        $note = 'Solo jueves';
    }

    if ($productName === '2X40 COCTELES') {
        $n = trim(preg_replace('/^2x40\s*/i', '', $full));
        $n = mb_convert_case(mb_strtolower($n), MB_CASE_TITLE);
        return [$n, null, null];
    }
    if (str_contains($lower, 'combo')) {
        $plus = preg_match('/\+\s*(.+)$/', $full, $m) ? '+ ' . str_replace('Cervezas ', '', $m[1]) : null;
        return [str_contains($lower, 'media') ? 'Combo media' : 'Combo botella', trim(($size ? $size . ' ' : '') . ($plus ?? '')), null];
    }
    foreach (['botella' => 'Botella', 'media' => 'Media', 'trago' => 'Trago'] as $k => $label) {
        if (preg_match('/\b' . $k . '\b/', mb_strtolower($short))) {
            return [$label, $label === 'Trago' ? null : $size, null];
        }
    }
    if (str_contains($lower, 'gratis')) {
        return ['Cubeta gratis', null, $note];
    }
    if (str_contains($lower, 'cubeta') || str_contains($lower, 'cubetazo')) {
        return [str_contains($lower, '50%') ? 'Cubeta al 50 %' : 'Cubeta', null, $note];
    }
    if (str_contains($lower, 'promo')) {
        return ['Promo 50 %', null, $note ?? 'Solo miércoles'];
    }
    if (str_contains($lower, 'michelad')) {
        return ['Michelada', null, null];
    }
    return ['Botella', $size, null];
}

function fetch_image(string $url, string $destBase): ?string
{
    $bin = @file_get_contents($url);
    if ($bin === false || $bin === '') {
        fwrite(STDERR, "  ! sin imagen: $url\n");
        return null;
    }
    $img = @imagecreatefromstring($bin);
    if (!$img) {
        fwrite(STDERR, "  ! formato no soportado: $url\n");
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $max = 900;
    $scale = min(1, $max / max($w, $h));
    $nw = (int) round($w * $scale);
    $nh = (int) round($h * $scale);
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $file = $destBase . '.webp';
    imagewebp($out, $file, 82);
    return basename($file) . '|' . $w . 'x' . $h;
}

/** Recorta una imagen a su contenido (ignora fondo blanco o transparente). */
function crop_content(GdImage $im): GdImage
{
    $w = imagesx($im);
    $h = imagesy($im);
    [$x0, $y0, $x1, $y1] = [$w, $h, 0, 0];
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 127;
            if ($a < 100 && ((($c >> 16) & 255) < 245 || (($c >> 8) & 255) < 245 || ($c & 255) < 245)) {
                $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y);
            }
        }
    }
    return imagecrop($im, ['x' => $x0, 'y' => $y0, 'width' => $x1 - $x0 + 1, 'height' => $y1 - $y0 + 1]);
}

/**
 * Compone las botellas lado a lado sobre blanco, alineadas a una base con sombra suave.
 * Solo se copian los píxeles que no son fondo, así las sombras quedan visibles.
 * La última botella (el licor) va más alta que las cervezas.
 */
function compose_bottles(array $sources, string $dir, string $dest): string
{
    $W = 900;
    $H = 900;
    $base = 780;
    $canvas = imagecreatetruecolor($W, $H);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagealphablending($canvas, true);

    $parts = [];
    $n = count($sources);
    foreach ($sources as $i => $name) {
        $src = crop_content(imagecreatefromwebp("$dir/$name.webp"));
        $targetH = $i === $n - 1 ? 620 : 520;
        $scale = $targetH / imagesy($src);
        $parts[] = [$src, (int) round(imagesx($src) * $scale), $targetH];
    }
    $gap = 18;
    $total = array_sum(array_column($parts, 1)) + $gap * ($n - 1) + 24;
    $x = (int) (($W - $total) / 2);
    foreach ($parts as $i => [$src, $pw, $ph]) {
        if ($i === $n - 1) {
            $x += 24;
        }
        imagefilledellipse($canvas, $x + (int) ($pw / 2), $base + 4, (int) ($pw * 1.25), 26, imagecolorallocatealpha($canvas, 0, 0, 0, 112));
        $scaled = imagecreatetruecolor($pw, $ph);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 255, 255, 255, 127));
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $pw, $ph, imagesx($src), imagesy($src));
        $top = $base - $ph;
        for ($yy = 0; $yy < $ph; $yy++) {
            for ($xx = 0; $xx < $pw; $xx++) {
                $c = imagecolorat($scaled, $xx, $yy);
                $a = ($c >> 24) & 127;
                $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
                if ($a > 110 || ($r > 250 && $g > 250 && $b > 250)) {
                    continue;
                }
                imagesetpixel($canvas, $x + $xx, $top + $yy, imagecolorallocatealpha($canvas, $r, $g, $b, $a));
            }
        }
        $x += $pw + $gap;
    }
    imagewebp($canvas, "$dir/$dest.webp", 85);
    return "$dest.webp";
}

$cats = [];
$tail = [];
foreach ($src as $block) {
    $raw = $block['category']['name'];
    $cat = [
        'name' => $categoryNames[$raw] ?? $raw,
        'description' => $categoryDescriptions[$raw] ?? null,
        'products' => [],
    ];
    $sorted = $block['products'];
    usort($sorted, static fn ($a, $b) => $a['index'] <=> $b['index']);
    foreach ($sorted as $row) {
        if (empty($row['show'])) {
            continue;
        }
        $p = $row['internalId'];
        $rawName = trim($p['name']);
        $name = $nameOverrides[$rawName] ?? $rawName;
        $variants = [];
        foreach ($p['subProducts'] ?? [] as $sub) {
            if (!empty($sub['deleted']) || empty($sub['isActive'])) {
                continue;
            }
            [$label, $detail, $note] = variant_label($rawName, $sub);
            $variants[] = [
                'label' => $label,
                'detail' => $detail ?: null,
                'note' => $note,
                'price' => (int) ($sub['locationsStock'][0]['price'] ?? $sub['price'] ?? 0),
            ];
        }
        // Orden lógico de presentaciones: trago < media < botella < combos; en cervezas, botella primero.
        $rank = ['Trago' => 1, 'Media' => 2, 'Botella' => 3, 'Michelada' => 4, 'Combo media' => 5, 'Combo botella' => 6, 'Cubeta' => 7, 'Promo 50 %' => 8, 'Cubeta al 50 %' => 9, 'Cubeta gratis' => 10];
        usort($variants, static fn ($a, $b) => ($rank[$a['label']] ?? 0) <=> ($rank[$b['label']] ?? 0));

        $basePrice = (int) ($p['locationsStock'][0]['price'] ?? 0);
        $ascii = strtr(mb_strtolower($name), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '×' => 'x', '½' => 'media', "'" => '', '&' => 'y']);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $ascii) ?: 'producto', '-');

        $image = null;
        if (isset($composites[$rawName])) {
            $image = ['compose' => $composites[$rawName], 'slug' => $slug];  // se genera al final, cuando ya existen las fotos base
        } elseif (!empty($p['urlImage'])) {
            $res = fetch_image($p['urlImage'], $uploadDir . '/' . $slug);
            if ($res) {
                [$image, $dims] = explode('|', $res);
                echo str_pad($name, 40) . " $dims\n";
            }
        }
        $cat['products'][] = [
            'name' => $name,
            'description' => $descriptionOverrides[$rawName] ?? (trim((string) $p['description']) ?: null),
            'price' => $variants ? null : ($basePrice ?: null),
            'badge' => $badges[$rawName] ?? null,
            'image' => $image,
            'is_featured' => in_array($rawName, $featured, true),
            'variants' => $variants,
        ];
    }
    if (in_array($raw, $lastCategories, true)) {
        $tail[] = $cat;
    } else {
        $cats[] = $cat;
    }
}
$cats = array_merge($cats, $tail);
foreach ($cats as &$c) {
    foreach ($c['products'] as &$prod) {
        if (is_array($prod['image'])) {
            $prod['image'] = compose_bottles($prod['image']['compose'], $uploadDir, $prod['image']['slug']);
            echo str_pad($prod['name'], 40) . " compuesta
";
        }
    }
    unset($prod);
}
unset($c);

$promotions = [
    ['title' => '2 cócteles por $40.000', 'detail' => 'Mojito, margarita, margarita blue, gin tonic, caipiroska, cuba libre y más.', 'days' => '3,4,5,6', 'time_from' => '18:00', 'time_to' => '22:00'],
    ['title' => 'Cerveza al 50 %', 'detail' => 'Águila, Águila Light, Andina, Poker, Heineken y Club Colombia Dorada, Roja y Trigo.', 'days' => '3', 'time_from' => null, 'time_to' => null],
    ['title' => 'Jueves de cubetas', 'detail' => 'Cubetas de Heineken y Andina al 50 %. Pregunta por la cubeta gratis.', 'days' => '4', 'time_from' => null, 'time_to' => null],
];

// Testimonios publicados en el sitio actual (saraopub.com). Se amplían desde el panel.
$testimonials = [
    ['author' => 'Paito Pao', 'rating' => 5, 'source' => null, 'body' => 'Es un lugar entretenido para sacar a flote ese artista que llevamos dentro, se comparte muy bien con amigos y el lugar es limpio y organizado.'],
    ['author' => 'Angela Espinel', 'rating' => 5, 'source' => null, 'body' => 'Excelente lugar para compartir un rato, es un lugar súper limpio, la atención amable y buenos precios. ¡Definitivamente regresaría!'],
];

$seed = ['categories' => $cats, 'promotions' => $promotions, 'testimonials' => $testimonials];
file_put_contents($root . '/public_html/app/seed.json', json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$count = array_sum(array_map(static fn ($c) => count($c['products']), $cats));
echo "\nOK: " . count($cats) . " categorías, $count productos\n";
