<?php
declare(strict_types=1);

/**
 * Markdown mínimo para las guías del panel (karaoke-operacion.md), sin dependencias.
 * Todo el texto se escapa primero; solo se generan las etiquetas de este subconjunto:
 * títulos #/##/###, párrafos, listas (- y 1.), tablas, bloques ``` (con o sin sangría),
 * `código`, **negrita**, *cursiva* y [enlaces](https://…). Los títulos ## llevan id para el índice.
 */
function md_to_html(string $md): string
{
    $lines = preg_split('/\R/', str_replace("\t", '    ', $md));
    $out = [];
    $para = [];
    $list = null;       // ['ul'|'ol', items[]]
    $i = 0;
    $n = count($lines);

    $flushPara = static function () use (&$para, &$out): void {
        if ($para) {
            $out[] = '<p>' . md_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$out): void {
        if ($list) {
            [$tag, $items, $start] = $list;
            $out[] = "<$tag" . ($tag === 'ol' && $start > 1 ? " start=\"$start\"" : '') . '>'
                . implode('', array_map(static fn (string $it): string => '<li>' . md_inline($it) . '</li>', $items)) . "</$tag>";
            $list = null;
        }
    };

    while ($i < $n) {
        $line = $lines[$i];
        $trim = trim($line);

        if (preg_match('/^```\s*([\w-]*)$/', $trim, $m)) {
            $flushPara();
            $flushList();
            $lang = $m[1];
            $code = [];
            $indent = strlen($line) - strlen(ltrim($line));
            for ($i++; $i < $n && trim($lines[$i]) !== '```'; $i++) {
                $code[] = substr($lines[$i], min($indent, strlen($lines[$i]) - strlen(ltrim($lines[$i]))));
            }
            $i++;
            $out[] = '<pre' . ($lang !== '' ? ' data-lang="' . md_e($lang) . '"' : '') . '><code>' . md_e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if ($trim === '') {
            $flushPara();
            // Una línea en blanco dentro de una lista no la cierra si la siguiente sigue la lista.
            $next = $lines[$i + 1] ?? '';
            if ($list && !preg_match('/^\s*(?:[-*]|\d+\.)\s+/', $next)) {
                $flushList();
            }
            $i++;
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $trim, $m)) {
            $flushPara();
            $flushList();
            $level = strlen($m[1]);
            $id = $level === 2 ? ' id="' . md_slug($m[2]) . '"' : '';
            $out[] = "<h$level$id>" . md_inline($m[2]) . "</h$level>";
            $i++;
            continue;
        }
        if (str_starts_with($trim, '|') && isset($lines[$i + 1]) && preg_match('/^\|?\s*:?-{3,}/', trim($lines[$i + 1]))) {
            $flushPara();
            $flushList();
            $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
            $head = $cells($trim);
            $rows = [];
            for ($i += 2; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++) {
                $rows[] = $cells($lines[$i]);
            }
            $html = '<div class="md-table"><table><thead><tr>' . implode('', array_map(static fn ($c) => '<th>' . md_inline($c) . '</th>', $head)) . '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                foreach ($r as $k => $c) {
                    // data-label: en pantallas angostas cada celda muestra su encabezado.
                    $html .= '<td data-label="' . md_e(strip_tags(md_inline($head[$k] ?? ''))) . '">' . md_inline($c) . '</td>';
                }
                $html .= '</tr>';
            }
            $out[] = $html . '</tbody></table></div>';
            continue;
        }
        if (preg_match('/^(?:([-*])|(\d+)\.)\s+(.+)$/', $trim, $m)) {
            $flushPara();
            $tag = $m[1] !== '' ? 'ul' : 'ol';
            if (!$list || $list[0] !== $tag) {
                $flushList();
                $list = [$tag, [], $tag === 'ol' ? (int) $m[2] : 1];
            }
            $list[1][] = $m[3];
            $i++;
            continue;
        }
        if ($list && preg_match('/^\s{2,}\S/', $line)) {
            // Continuación de un elemento de lista con sangría.
            $list[1][count($list[1]) - 1] .= ' ' . $trim;
            $i++;
            continue;
        }
        $flushList();
        $para[] = $trim;
        $i++;
    }
    $flushPara();
    $flushList();
    return implode("\n", $out);
}

function md_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formato en línea sobre texto ya escapado: el código se aparta primero para no tocar su interior. */
function md_inline(string $text): string
{
    $codes = [];
    $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
        $codes[] = '<code>' . md_e($m[1]) . '</code>';
        return "\x00" . (count($codes) - 1) . "\x00";
    }, $text);
    $text = md_e($text);
    $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $text);
    $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        // Solo enlaces web; las rutas internas del repositorio quedan como texto.
        return preg_match('#^https?://#i', $url) ? '<a href="' . md_e($url) . '" target="_blank" rel="noopener">' . $m[1] . '</a>' : $m[1];
    }, $text);
    return (string) preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $codes[(int) $m[1]], $text);
}

function md_slug(string $s): string
{
    $s = mb_strtolower(strip_tags($s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: 'seccion';
}
