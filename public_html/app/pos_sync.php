<?php
declare(strict_types=1);

/**
 * Sincronización de catálogo y precios desde angelo-pos (el POS / back-office).
 *
 * Modelo: angelo-pos es la fuente de verdad de QUÉ se vende y A QUÉ PRECIO.
 * El sitio conserva lo editorial: foto, descripción, etiqueta, destacado, orden.
 * El enlace entre ambos es products.pos_product_id / product_variants.pos_product_id
 * (el uuid del producto en angelo-pos; una presentación del sitio = un producto
 * con `size` en el POS).
 *
 * Reglas, en orden:
 *  1. Ítem ya enlazado por pos_product_id  -> actualiza precio; si el POS lo desactiva, se oculta
 *     (la visibilidad solo baja desde el POS; mostrar lo decide el admin del sitio).
 *  2. Sin enlace: busca por nombre normalizado (+ presentación ~ size)  -> enlaza y actualiza precio.
 *  3. Sin coincidencia: crea el producto OCULTO (is_active = 0) en su categoría
 *     (la categoría se crea oculta si no existe). El admin le pone foto y lo activa.
 *  Nunca borra nada. Un producto del sitio que no existe en el POS se deja como está y se reporta.
 *
 * Igual que instagram.php: el token vive en settings y la sincronización corre
 * en segundo plano tras responder al visitante, como mucho una vez por hora.
 */

const POS_SYNC_EVERY = 3600;
const POS_TIMEOUT = 15;

final class PosSyncError extends RuntimeException
{
}

function pos_setting(string $key): string
{
    return (string) (settings()[$key] ?? '');
}

function pos_configured(): bool
{
    return pos_setting('pos_feed_url') !== '' && pos_setting('pos_feed_token') !== '' && pos_setting('pos_store_id') !== '';
}

/** Clave de comparación: minúsculas, sin tildes ni signos, espacios colapsados. */
function pos_norm(?string $s): string
{
    $t = mb_strtolower(trim((string) $s));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
    return trim(preg_replace('/\s+/', ' ', $t) ?? '');
}

/** Descarga el feed del POS. */
function pos_fetch_menu(): array
{
    $url = rtrim(pos_setting('pos_feed_url'), '/');
    $store = pos_setting('pos_store_id');
    if (!preg_match('#^https://#i', $url)) {
        throw new PosSyncError('La URL del POS debe empezar por https://');
    }
    if (!preg_match('/^[0-9a-f-]{36}$/i', $store)) {
        throw new PosSyncError('El id de tienda del POS no tiene formato de uuid.');
    }
    $ch = curl_init($url . '/api/public/menu?store=' . rawurlencode($store));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => POS_TIMEOUT,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . pos_setting('pos_feed_token'), 'Accept: application/json'],
        CURLOPT_USERAGENT => 'SaraoPubSite/1.0',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new PosSyncError('El POS no respondió: ' . $err);
    }
    if ($status === 401) {
        throw new PosSyncError('El POS rechazó el token. Revísalo en Ajustes.');
    }
    if ($status !== 200) {
        $msg = json_decode((string) $body, true)['error'] ?? "HTTP $status";
        throw new PosSyncError('El POS devolvió un error: ' . $msg);
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        throw new PosSyncError('Respuesta inesperada del POS.');
    }
    return $data;
}

/**
 * Aplica un feed al catálogo local. Separado de la descarga para poder probarlo sin red.
 * Devuelve un resumen: ['updated' => n, 'linked' => n, 'created' => n, 'unchanged' => n, 'orphans' => [...]].
 */
function pos_apply_menu(array $feed, ?int $adminId = null): array
{
    $pdo = db();
    $ts = now();
    $summary = ['updated' => 0, 'linked' => 0, 'created' => 0, 'unchanged' => 0, 'categories_created' => 0, 'orphans' => []];

    // --- Estado local indexado ---
    $cats = $pdo->query('SELECT id, name, is_active FROM categories')->fetchAll();
    $catByNorm = [];
    foreach ($cats as $c) {
        $catByNorm[pos_norm($c['name'])] = (int) $c['id'];
    }
    $prods = $pdo->query('SELECT id, category_id, name, price, is_active, pos_product_id FROM products')->fetchAll();
    $vars = $pdo->query('SELECT id, product_id, label, price, is_active, pos_product_id FROM product_variants')->fetchAll();

    $prodByPos = [];
    $prodByNorm = [];
    $varsByProduct = [];
    foreach ($prods as $p) {
        if ($p['pos_product_id']) {
            $prodByPos[$p['pos_product_id']] = $p;
        }
        $prodByNorm[pos_norm($p['name'])][] = $p;
    }
    $varByPos = [];
    foreach ($vars as $v) {
        $varsByProduct[(int) $v['product_id']][] = $v;
        if ($v['pos_product_id']) {
            $varByPos[$v['pos_product_id']] = $v;
        }
    }

    $updProd = $pdo->prepare('UPDATE products SET price = ?, is_active = ?, pos_product_id = ?, pos_synced_at = ?, updated_at = ? WHERE id = ?');
    $updVar = $pdo->prepare('UPDATE product_variants SET price = ?, is_active = ?, pos_product_id = ?, pos_synced_at = ? WHERE id = ?');
    $insCat = $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, NULL, ?, 0, ?, ?)');
    $insProd = $pdo->prepare('INSERT INTO products (category_id, name, description, price, badge, image, is_active, is_featured, sort_order, created_at, updated_at, pos_product_id, pos_synced_at) VALUES (?, ?, NULL, ?, NULL, NULL, 0, 0, ?, ?, ?, ?, ?)');
    $insVar = $pdo->prepare('INSERT INTO product_variants (product_id, label, detail, note, price, sort_order, is_active, pos_product_id, pos_synced_at) VALUES (?, ?, NULL, NULL, ?, ?, 1, ?, ?)');

    // Agrupar el feed: un nombre dentro de una categoría = un producto del sitio; sus sizes = presentaciones.
    $groups = [];
    foreach ($feed['products'] as $fp) {
        if (empty($fp['id']) || empty($fp['name'])) {
            continue;
        }
        $key = pos_norm((string) $fp['category']) . '|' . pos_norm((string) $fp['name']);
        $groups[$key]['category'] = (string) $fp['category'];
        $groups[$key]['name'] = (string) $fp['name'];
        $groups[$key]['items'][] = $fp;
    }

    $seenPos = [];
    $pdo->beginTransaction();
    try {
        foreach ($groups as $g) {
            $items = $g['items'];
            $single = count($items) === 1 && ($items[0]['size'] === null || $items[0]['size'] === '');

            // 1) ¿Ya hay un producto local enlazado a alguno de estos ids?
            $local = null;
            foreach ($items as $it) {
                if (isset($prodByPos[$it['id']])) {
                    $local = $prodByPos[$it['id']];
                    break;
                }
                if (isset($varByPos[$it['id']])) {
                    $pid = (int) $varByPos[$it['id']]['product_id'];
                    foreach ($prods as $p) {
                        if ((int) $p['id'] === $pid) {
                            $local = $p;
                            break 2;
                        }
                    }
                }
            }
            // 2) Por nombre normalizado (si es único).
            if (!$local) {
                $cands = $prodByNorm[pos_norm($g['name'])] ?? [];
                if (count($cands) === 1) {
                    $local = $cands[0];
                    $summary['linked']++;
                }
            }

            if ($local) {
                $localId = (int) $local['id'];
                $localVars = $varsByProduct[$localId] ?? [];
                if ($single) {
                    $it = $items[0];
                    $seenPos[$it['id']] = true;
                    $price = (int) $it['sale_price'];
                    $active = !empty($it['is_active']) ? 1 : 0;
                    // La visibilidad solo BAJA desde el POS: si allá se desactiva, acá se oculta.
                    // Mostrar lo decide siempre el admin (los creados por la sincronización nacen ocultos).
                    $newActive = ($local['pos_product_id'] && !$active) ? 0 : (int) $local['is_active'];
                    if ($localVars) {
                        // El sitio lo tiene con presentaciones pero el POS con precio único: no se adivina.
                        $summary['orphans'][] = $g['name'] . ' (presentaciones en el sitio, precio único en el POS)';
                        continue;
                    }
                    if ((int) $local['price'] !== $price || (int) $local['is_active'] !== $newActive || $local['pos_product_id'] !== $it['id']) {
                        $updProd->execute([$price, $newActive, $it['id'], $ts, $ts, $localId]);
                        $summary['updated']++;
                    } else {
                        $summary['unchanged']++;
                    }
                    continue;
                }

                // Varias presentaciones: casar cada size con una variante local.
                $varByNorm = [];
                foreach ($localVars as $v) {
                    $varByNorm[pos_norm($v['label'])] = $v;
                }
                $touched = false;
                foreach ($items as $i => $it) {
                    $seenPos[$it['id']] = true;
                    $price = (int) $it['sale_price'];
                    $active = !empty($it['is_active']) ? 1 : 0;
                    $v = $varByPos[$it['id']] ?? $varByNorm[pos_norm((string) $it['size'])] ?? null;
                    if ($v) {
                        $newActive = ($v['pos_product_id'] && !$active) ? 0 : (int) $v['is_active'];
                        if ((int) $v['price'] !== $price || (int) $v['is_active'] !== $newActive || $v['pos_product_id'] !== $it['id']) {
                            $updVar->execute([$price, $newActive, $it['id'], $ts, (int) $v['id']]);
                            $touched = true;
                        }
                    } else {
                        // Presentación nueva en el POS: se agrega (visible dentro del producto, que ya decide el admin).
                        $insVar->execute([$localId, (string) $it['size'], $price, (count($localVars) + $i + 1) * 10, $it['id'], $ts]);
                        $touched = true;
                    }
                }
                if ($touched) {
                    // Un producto con presentaciones no lleva precio propio.
                    $updProd->execute([null, (int) $local['is_active'], $local['pos_product_id'], $ts, $ts, $localId]);
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }
                continue;
            }

            // 3) No existe: crear oculto.
            $catKey = pos_norm($g['category']);
            if (!isset($catByNorm[$catKey])) {
                $slug = slugify($g['category']);
                $n = 2;
                $try = $slug;
                $exists = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ?');
                while (true) {
                    $exists->execute([$try]);
                    if ((int) $exists->fetchColumn() === 0) {
                        break;
                    }
                    $try = $slug . '-' . $n++;
                }
                $order = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM categories')->fetchColumn();
                $insCat->execute([$g['category'], $try, $order, $ts, $ts]);
                $catByNorm[$catKey] = (int) $pdo->lastInsertId();
                $summary['categories_created']++;
            }
            $catId = $catByNorm[$catKey];
            $orderSt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM products WHERE category_id = ?');
            $orderSt->execute([$catId]);
            $order = (int) $orderSt->fetchColumn();
            if ($single) {
                $it = $items[0];
                $seenPos[$it['id']] = true;
                $insProd->execute([$catId, $g['name'], (int) $it['sale_price'], $order, $ts, $ts, $it['id'], $ts]);
            } else {
                $insProd->execute([$catId, $g['name'], null, $order, $ts, $ts, null, $ts]);
                $newId = (int) $pdo->lastInsertId();
                foreach ($items as $i => $it) {
                    $seenPos[$it['id']] = true;
                    $insVar->execute([$newId, (string) $it['size'], (int) $it['sale_price'], ($i + 1) * 10, $it['id'], $ts]);
                }
            }
            $summary['created']++;
        }

        // Ítems locales enlazados a un id que ya no viene en el feed: se reportan, no se tocan.
        foreach ($prods as $p) {
            if ($p['pos_product_id'] && !isset($seenPos[$p['pos_product_id']])) {
                $summary['orphans'][] = $p['name'] . ' (ya no está en el POS)';
            }
        }

        // Promociones: filas de la tabla promotions + presentaciones promocionales materializadas.
        if (isset($feed['promotions']) && is_array($feed['promotions'])) {
            $summary['promotions'] = pos_apply_promotions($pdo, $feed['promotions'], $feed['products'], $ts);
        }

        if ($adminId !== null) {
            audit($adminId, 'sync', 'pos');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $summary;
}

/** Prefijo con el que se marcan las presentaciones promocionales que genera la sincronización. */
const POS_PROMO_VARIANT_PREFIX = 'promo:';

/** "Solo miércoles", "Mié a Sáb", "" (todos los días). */
function pos_days_note(array $days): string
{
    $d = array_values(array_unique(array_filter(array_map('intval', $days), static fn ($x) => $x >= 1 && $x <= 7)));
    sort($d);
    if (!$d || count($d) === 7) {
        return '';
    }
    if (count($d) === 1) {
        return 'Solo ' . mb_strtolower(WEEKDAYS[$d[0]]);
    }
    return format_days(implode(',', $d));
}

/** Precio promocional por unidad según el tipo de promoción del POS. */
function pos_promo_price(array $promo, int $base): int
{
    return match ((string) $promo['kind']) {
        'percent_off' => (int) round($base * (1 - ((float) $promo['value']) / 100)),
        'fixed_price' => (int) round((float) $promo['value']),
        'bundle' => (int) round(((float) $promo['value']) / max(2, (int) ($promo['bundle_qty'] ?? 2))),
        default => $base,
    };
}

/** Etiqueta de la presentación promocional, en el estilo que ya usa la carta. */
function pos_promo_label(array $promo, string $baseLabel): string
{
    $v = (float) $promo['value'];
    $pct = $v == (int) $v ? (string) (int) $v : number_format($v, 1, ',', '');
    return match ((string) $promo['kind']) {
        'percent_off' => mb_strtolower($baseLabel) === 'botella' ? "Promo $pct %" : "$baseLabel al $pct %",
        'fixed_price' => $v == 0.0 ? "$baseLabel gratis" : (string) $promo['name'],
        'bundle' => (int) ($promo['bundle_qty'] ?? 2) . ' por ' . money((int) round($v)),
        default => (string) $promo['name'],
    };
}

/**
 * Aplica las promociones del feed:
 *  1. Upsert en `promotions` (título, detalle, días, horario) enlazado por pos_promotion_id;
 *     las creadas a mano en el panel (pos_promotion_id NULL) no se tocan; las del POS que
 *     desaparecen se borran.
 *  2. Materializa presentaciones promocionales ("Promo 50 %", "Cubeta al 50 %", "Cubeta gratis")
 *     en los productos que ya tienen presentaciones enlazadas al POS, con la nota del día.
 *     Marcadas con pos_product_id = "promo:<promo>:<producto>"; las que ya no aplican se borran.
 *  Los paquetes ("2 por $40.000") y las promos sobre productos de precio único solo van a la
 *  tabla promotions (la carta los muestra en la franja "Promos de la semana").
 */
function pos_apply_promotions(PDO $pdo, array $promos, array $feedProducts, string $ts): array
{
    $out = ['upserted' => 0, 'removed' => 0, 'variants' => 0, 'variants_removed' => 0];

    // --- 1) tabla promotions ---
    $existing = $pdo->query('SELECT id, pos_promotion_id, sort_order FROM promotions WHERE pos_promotion_id IS NOT NULL')->fetchAll();
    $byPos = [];
    foreach ($existing as $e) {
        $byPos[$e['pos_promotion_id']] = $e;
    }
    $ins = $pdo->prepare('INSERT INTO promotions (title, detail, days, time_from, time_to, is_active, sort_order, created_at, updated_at, pos_promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE promotions SET title = ?, detail = ?, days = ?, time_from = ?, time_to = ?, is_active = ?, updated_at = ? WHERE id = ?');
    $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM promotions')->fetchColumn();
    $seen = [];
    foreach ($promos as $pr) {
        $pid = (string) ($pr['id'] ?? '');
        if ($pid === '' || empty($pr['name'])) {
            continue;
        }
        $seen[$pid] = true;
        $days = array_values(array_filter(array_map('intval', (array) ($pr['days'] ?? [])), static fn ($d) => $d >= 1 && $d <= 7));
        sort($days);
        // La carta exige al menos un día: "todos los días" se guarda como 1..7.
        $daysCsv = implode(',', $days ?: range(1, 7));
        $detail = trim((string) ($pr['description'] ?? ''));
        if ($detail === '') {
            $detail = match ((string) $pr['kind']) {
                'percent_off' => number_format((float) $pr['value'], 0, ',', '.') . ' % de descuento.',
                'bundle' => (int) ($pr['bundle_qty'] ?? 2) . ' por ' . money((int) round((float) $pr['value'])) . '.',
                default => '',
            };
        }
        $active = !empty($pr['is_active']) ? 1 : 0;
        $from = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($pr['time_from'] ?? '')) ? $pr['time_from'] : null;
        $to = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($pr['time_to'] ?? '')) ? $pr['time_to'] : null;
        if (isset($byPos[$pid])) {
            $upd->execute([mb_substr((string) $pr['name'], 0, 80), mb_substr($detail, 0, 255) ?: null, $daysCsv, $from, $to, $active, $ts, (int) $byPos[$pid]['id']]);
        } else {
            $maxOrder += 10;
            $ins->execute([mb_substr((string) $pr['name'], 0, 80), mb_substr($detail, 0, 255) ?: null, $daysCsv, $from, $to, $active, $maxOrder, $ts, $ts, $pid]);
        }
        $out['upserted']++;
    }
    $del = $pdo->prepare('DELETE FROM promotions WHERE id = ?');
    foreach ($byPos as $pid => $e) {
        if (!isset($seen[$pid])) {
            $del->execute([(int) $e['id']]);
            $out['removed']++;
        }
    }

    // --- 2) presentaciones promocionales ---
    $catByPosId = [];
    foreach ($feedProducts as $fp) {
        if (!empty($fp['id'])) {
            $catByPosId[(string) $fp['id']] = pos_norm((string) ($fp['category'] ?? ''));
        }
    }
    $vars = $pdo->query('SELECT id, product_id, label, price, is_active, sort_order, pos_product_id FROM product_variants WHERE pos_product_id IS NOT NULL')->fetchAll();
    $baseByPos = [];
    $promoVarByKey = [];
    foreach ($vars as $v) {
        if (str_starts_with((string) $v['pos_product_id'], POS_PROMO_VARIANT_PREFIX)) {
            $promoVarByKey[$v['pos_product_id']] = $v;
        } else {
            $baseByPos[$v['pos_product_id']] = $v;
        }
    }
    $insVar = $pdo->prepare('INSERT INTO product_variants (product_id, label, detail, note, price, sort_order, is_active, pos_product_id, pos_synced_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)');
    $updVar = $pdo->prepare('UPDATE product_variants SET label = ?, note = ?, price = ?, is_active = ?, pos_synced_at = ? WHERE id = ?');
    $keep = [];
    foreach ($promos as $pr) {
        $pid = (string) ($pr['id'] ?? '');
        if ($pid === '' || ($pr['kind'] ?? '') === 'bundle') {
            continue;
        }
        $targets = array_map('strval', (array) ($pr['product_ids'] ?? []));
        foreach ((array) ($pr['categories'] ?? []) as $cat) {
            $cn = pos_norm((string) $cat);
            foreach ($catByPosId as $posId => $pc) {
                if ($pc === $cn) {
                    $targets[] = $posId;
                }
            }
        }
        $note = pos_days_note((array) ($pr['days'] ?? []));
        foreach (array_unique($targets) as $posId) {
            $base = $baseByPos[$posId] ?? null;
            if (!$base) {
                continue; // precio único o no enlazado: la franja de promos lo cubre
            }
            $key = POS_PROMO_VARIANT_PREFIX . $pid . ':' . $posId;
            $keep[$key] = true;
            $label = mb_substr(pos_promo_label($pr, (string) $base['label']), 0, 60);
            $price = pos_promo_price($pr, (int) $base['price']);
            $active = (!empty($pr['is_active']) && (int) $base['is_active'] === 1) ? 1 : 0;
            if (isset($promoVarByKey[$key])) {
                $pv = $promoVarByKey[$key];
                if ($pv['label'] !== $label || (int) $pv['price'] !== $price || (int) $pv['is_active'] !== $active) {
                    $updVar->execute([$label, $note ?: null, $price, $active, $ts, (int) $pv['id']]);
                    $out['variants']++;
                }
            } else {
                $insVar->execute([(int) $base['product_id'], $label, $note ?: null, $price, (int) $base['sort_order'] + 1, $active, $key, $ts]);
                $out['variants']++;
            }
        }
    }
    $delVar = $pdo->prepare('DELETE FROM product_variants WHERE id = ?');
    foreach ($promoVarByKey as $key => $pv) {
        if (!isset($keep[$key])) {
            $delVar->execute([(int) $pv['id']]);
            $out['variants_removed']++;
        }
    }
    return $out;
}

/** Descarga + aplica. Guarda estado en settings. */
function pos_sync(bool $force = false, ?int $adminId = null): array
{
    if (!pos_configured()) {
        throw new PosSyncError('El POS no está configurado. Completa URL, tienda y token en Ajustes.');
    }
    $last = (int) pos_setting('pos_synced_at');
    if (!$force && time() - $last < POS_SYNC_EVERY) {
        return json_decode(pos_setting('pos_last_summary'), true) ?: [];
    }
    // Marca de inmediato para que dos visitas simultáneas no sincronicen a la vez.
    save_setting('pos_synced_at', (string) time());
    try {
        $feed = pos_fetch_menu();
        $summary = pos_apply_menu($feed, $adminId);
        $summary['at'] = time();
        $summary['store'] = (string) ($feed['store']['name'] ?? '');
        save_setting('pos_last_summary', json_encode($summary, JSON_UNESCAPED_UNICODE));
        save_setting('pos_error', '');
        return $summary;
    } catch (PosSyncError|PDOException $e) {
        save_setting('pos_error', mb_substr($e->getMessage(), 0, 240));
        throw $e instanceof PosSyncError ? $e : new PosSyncError('Error de base de datos al sincronizar.');
    }
}

/** Sincroniza en segundo plano tras responder al visitante, si toca. */
function pos_sync_after_response(): void
{
    if (!pos_configured() || time() - (int) pos_setting('pos_synced_at') < POS_SYNC_EVERY) {
        return;
    }
    register_shutdown_function(static function (): void {
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        set_time_limit(60);
        try {
            pos_sync();
        } catch (Throwable $e) {
            error_log('[sarao-pos] ' . $e->getMessage());
        }
    });
}

function pos_status(): array
{
    $s = settings();
    return [
        'configured' => pos_configured(),
        'feed_url' => (string) ($s['pos_feed_url'] ?? ''),
        'store_id' => (string) ($s['pos_store_id'] ?? ''),
        'token_set' => ($s['pos_feed_token'] ?? '') !== '',
        'synced_at' => (int) ($s['pos_synced_at'] ?? 0),
        'error' => (string) ($s['pos_error'] ?? ''),
        'last' => json_decode((string) ($s['pos_last_summary'] ?? ''), true) ?: null,
    ];
}
