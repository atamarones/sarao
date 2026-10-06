<?php
declare(strict_types=1);

/**
 * Generador de códigos QR sin dependencias (ISO/IEC 18004): modo byte, corrección de errores M,
 * versiones 1 a 10 (hasta 213 bytes; un enlace de mesa usa ~60). Salida SVG para imprimir.
 * Basado en el algoritmo de referencia de Project Nayuki (MIT).
 */

/** Por versión (nivel M): [codewords de corrección por bloque, [[bloques, codewords de datos], …]]. */
const QR_BLOCKS_M = [
    1 => [10, [[1, 16]]],
    2 => [16, [[1, 28]]],
    3 => [26, [[1, 44]]],
    4 => [18, [[2, 32]]],
    5 => [24, [[2, 43]]],
    6 => [16, [[4, 27]]],
    7 => [18, [[4, 31]]],
    8 => [22, [[2, 38], [2, 39]]],
    9 => [22, [[3, 36], [2, 37]]],
    10 => [26, [[4, 43], [1, 44]]],
];

/** Devuelve la matriz de módulos (true = oscuro), filas × columnas. */
function qr_matrix(string $data): array
{
    $len = strlen($data);
    $version = null;
    foreach (QR_BLOCKS_M as $v => [, $groups]) {
        $capacity = 0;
        foreach ($groups as [$n, $k]) {
            $capacity += $n * $k;
        }
        if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $capacity * 8) {
            $version = $v;
            break;
        }
    }
    if ($version === null) {
        throw new InvalidArgumentException('Texto demasiado largo para el código QR.');
    }
    [$ecLen, $groups] = QR_BLOCKS_M[$version];
    $dataCw = 0;
    foreach ($groups as [$n, $k]) {
        $dataCw += $n * $k;
    }

    // Flujo de bits: modo byte (0100), longitud, datos, terminador y relleno.
    $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $codewords = array_map('bindec', str_split($bits, 8));
    for ($pad = 0xEC; count($codewords) < $dataCw; $pad ^= 0xEC ^ 0x11) {
        $codewords[] = $pad;
    }

    // Bloques con su corrección Reed-Solomon, intercalados.
    $blocks = [];
    $eccs = [];
    $offset = 0;
    $gen = qr_rs_generator($ecLen);
    foreach ($groups as [$n, $k]) {
        for ($b = 0; $b < $n; $b++) {
            $block = array_slice($codewords, $offset, $k);
            $offset += $k;
            $blocks[] = $block;
            $eccs[] = qr_rs_remainder($block, $gen);
        }
    }
    $final = [];
    $maxK = max(array_map('count', $blocks));
    for ($i = 0; $i < $maxK; $i++) {
        foreach ($blocks as $block) {
            if (isset($block[$i])) {
                $final[] = $block[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($eccs as $ecc) {
            $final[] = $ecc[$i];
        }
    }

    $size = 17 + 4 * $version;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $x, int $y, bool $dark) use (&$m, &$fn): void {
        $m[$y][$x] = $dark;
        $fn[$y][$x] = true;
    };

    // Patrones de temporización, buscadores y alineación.
    for ($i = 0; $i < $size; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                    $d = max(abs($dx), abs($dy));
                    $set($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
    }
    $align = qr_alignment_positions($version);
    $na = count($align);
    foreach ($align as $i => $ax) {
        foreach ($align as $j => $ay) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }
    qr_draw_format($set, $size, 0);
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $vbits = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($vbits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $set($a, $b, $bit);
            $set($b, $a, $bit);
        }
    }

    // Datos en zigzag desde la esquina inferior derecha.
    $i = 0;
    $total = count($final) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $total) {
                    $m[$y][$x] = (($final[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    // La máscara con menor penalización.
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $c = qr_apply_mask($m, $fn, $size, $mask);
        $s = $set;
        qr_draw_format(static function (int $x, int $y, bool $d) use (&$c): void {
            $c[$y][$x] = $d;
        }, $size, $mask);
        $score = qr_penalty($c, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    return $best;
}

function qr_alignment_positions(int $version): array
{
    if ($version === 1) {
        return [];
    }
    $n = intdiv($version, 7) + 2;
    $step = (int) (ceil(($version * 4 + 4) / ($n * 2 - 2)) * 2);
    $pos = [6];
    for ($i = 0, $p = $version * 4 + 10; $i < $n - 1; $i++, $p -= $step) {
        array_splice($pos, 1, 0, [$p]);
    }
    return $pos;
}

/** Información de formato (nivel M = 00 + máscara), con su BCH, en sus dos copias. */
function qr_draw_format(callable $set, int $size, int $mask): void
{
    $data = $mask;
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    }
    $bits = (($data << 10) | $rem) ^ 0x5412;
    $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
    for ($i = 0; $i <= 5; $i++) {
        $set(8, $i, $bit($i));
    }
    $set(8, 7, $bit(6));
    $set(8, 8, $bit(7));
    $set(7, 8, $bit(8));
    for ($i = 9; $i < 15; $i++) {
        $set(14 - $i, 8, $bit($i));
    }
    for ($i = 0; $i < 8; $i++) {
        $set($size - 1 - $i, 8, $bit($i));
    }
    for ($i = 8; $i < 15; $i++) {
        $set(8, $size - 15 + $i, $bit($i));
    }
    $set(8, $size - 8, true);
}

function qr_apply_mask(array $m, array $fn, int $size, int $mask): array
{
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($fn[$y][$x]) {
                continue;
            }
            $invert = match ($mask) {
                0 => ($x + $y) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($x + $y) % 3 === 0,
                4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
            };
            if ($invert) {
                $m[$y][$x] = !$m[$y][$x];
            }
        }
    }
    return $m;
}

function qr_penalty(array $m, int $size): int
{
    $score = 0;
    $dark = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) {
        $row = '';
        $col = '';
        for ($j = 0; $j < $size; $j++) {
            $row .= $m[$i][$j] ? '1' : '0';
            $col .= $m[$j][$i] ? '1' : '0';
        }
        $lines[] = $row;
        $lines[] = $col;
        $dark += substr_count($row, '1');
    }
    foreach ($lines as $line) {
        preg_match_all('/0{5,}|1{5,}/', $line, $runs);
        foreach ($runs[0] as $r) {
            $score += 3 + strlen($r) - 5;
        }
        $score += 40 * (substr_count('0000' . $line . '0000', '10111010000') + substr_count('0000' . $line . '0000', '00001011101'));
    }
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $c = $m[$y][$x];
            if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }
    $total = $size * $size;
    $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
    return $score + max(0, $k) * 10;
}

function qr_gf_tables(): array
{
    static $t = null;
    if ($t === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        $t = [$exp, $log];
    }
    return $t;
}

function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = qr_gf_tables();
    return $exp[$log[$a] + $log[$b]];
}

/** Polinomio generador de grado $n (coeficientes sin el término principal). */
function qr_rs_generator(int $n): array
{
    [$exp] = qr_gf_tables();
    $g = array_fill(0, $n, 0);
    $g[$n - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $g[$j] = qr_gf_mul($g[$j], $root);
            if ($j + 1 < $n) {
                $g[$j] ^= $g[$j + 1];
            }
        }
        $root = qr_gf_mul($root, 0x02);
    }
    return $g;
}

function qr_rs_remainder(array $data, array $gen): array
{
    $n = count($gen);
    $r = array_fill(0, $n, 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($r);
        $r[] = 0;
        for ($i = 0; $i < $n; $i++) {
            $r[$i] ^= qr_gf_mul($gen[$i], $factor);
        }
    }
    return $r;
}

/** SVG del código con 4 módulos de margen. $size en píxeles del lado (solo atributos). */
function qr_svg(string $data, int $size = 240): string
{
    $m = qr_matrix($data);
    $n = count($m);
    $q = 4;
    $path = '';
    foreach ($m as $y => $row) {
        $x = 0;
        while ($x < $n) {
            if (!$row[$x]) {
                $x++;
                continue;
            }
            $start = $x;
            while ($x < $n && $row[$x]) {
                $x++;
            }
            $path .= 'M' . ($start + $q) . ' ' . ($y + $q) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
        }
    }
    $w = $n + 2 * $q;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $w . '" width="' . $size . '" height="' . $size . '" shape-rendering="crispEdges" role="img" aria-label="Código QR">'
        . '<rect width="' . $w . '" height="' . $w . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
}
