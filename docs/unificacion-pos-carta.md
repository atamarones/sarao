# Unificación Sarao (sitio + carta) ↔ angelo-pos (sistema operativo)

**Fecha:** 2026-10-03 · **Negocio:** El Sarao Pub (karaoke bar, Bogotá) · **Estado:** integración implementada, pendiente de desplegar y conectar.

## 1. Qué hace cada proyecto y por qué no se funden en uno

| | Sarao (este repo) | angelo-pos |
|---|---|---|
| Qué es | Sitio público, carta digital (QR) y panel editorial | Back-office operativo: ventas diarias, inventario, recetas, costos, márgenes, gastos, caja |
| Stack | PHP 8.1 + MySQL, sin Node, Hostinger compartido | Next.js 16 + Neon Postgres + Drizzle, Docker/Coolify |
| Usuarios | Clientes del bar y 1 admin del sitio | Dueño, gerente, cajeros (roles por tienda) |
| Datos propios | Fotos, descripciones, etiquetas, destacados, orden, promos, testimonios, horario, redes | Costos, recetas, stock, movimientos, ventas, OPEX, transferencias entre baldes |
| Datos compartidos | **Catálogo y precios** | **Catálogo y precios** |

Fundirlos en una sola aplicación obligaría a abandonar el hosting PHP barato del sitio o a reescribir el back-office en PHP. Ninguna de las dos cosas aporta nada al negocio. Lo que sí duele es mantener **dos catálogos a mano**: hoy un cambio de precio hay que escribirlo dos veces y tarde o temprano divergen.

**Decisión:** un solo dueño del catálogo y los precios (angelo-pos), y el sitio los consume. Cada sistema conserva lo suyo.

## 2. Modelo de datos compartido

```
angelo-pos.products (id uuid, name, size, category, sale_price, is_active)
        │  1 producto POS = 1 producto vendible con una presentación
        │
        ├── size NULL          ──►  Sarao.products.price            (precio único)
        └── size "Trago"/"Media"/"Botella" ──► Sarao.product_variants.label + price
                                               (varios productos POS con el mismo nombre
                                                y categoría = un producto del sitio con presentaciones)

Enlace persistente: Sarao.products.pos_product_id / Sarao.product_variants.pos_product_id = uuid del POS
```

Las sub-recetas del POS (`size = "Base"`, por ejemplo una mezcla para cócteles) no se venden y no viajan al sitio.

## 3. Contrato: feed de carta

`GET https://<pos>/api/public/menu?store=<uuid>` con `Authorization: Bearer <MENU_FEED_TOKEN>`

```json
{
  "store": { "id": "…", "name": "El Sarao Pub" },
  "generated_at": "2026-10-03T20:00:00.000Z",
  "categories": [ { "name": "Cócteles", "sort_order": 1, "has_recipe": true } ],
  "products": [
    { "id": "…", "name": "Aguardiente Antioqueño", "size": "Media", "category": "Aguardiente",
      "sale_price": 60000, "is_active": true, "is_combo": false, "updated_at": "…" }
  ]
}
```

- Solo lectura. Token compartido de al menos 32 caracteres (`openssl rand -hex 32`), comparación en tiempo constante.
- La ruta queda fuera del middleware de sesión de angelo-pos (`proxy.ts`) y la protege el token.
- Incluye productos inactivos con su bandera, para que el sitio pueda ocultarlos.
- Precios en COP entero.

Implementación: `angelo-pos/app/api/public/menu/route.ts`. Variable nueva: `MENU_FEED_TOKEN` (ver `.env.example`).

## 4. Sincronización en el sitio

Módulo `public_html/app/pos_sync.php`, mismo patrón que `instagram.php`: credenciales en `settings`, sincronización en segundo plano tras responder al visitante de `/carta/`, como mucho una vez por hora, y botón "Sincronizar ahora" en Ajustes.

Reglas de `pos_apply_menu()`:

1. **Ya enlazado** (`pos_product_id`): actualiza precio. Si el POS lo desactiva, se oculta. **La visibilidad solo baja desde el POS**; mostrar lo decide el admin del sitio.
2. **Sin enlace pero mismo nombre** (normalizado, único): se enlaza y actualiza precio. Presentaciones por nombre de talla.
3. **No existe**: se crea **oculto** en su categoría (la categoría se crea oculta si no existe). El admin le pone foto, descripción y lo activa. El panel avisa con un toast.
4. **Nunca borra.** Un producto del sitio cuyo id ya no viene del POS, o cuya forma no coincide (presentaciones en el sitio y precio único en el POS), se reporta en "Revisar:" y no se toca.
5. Todo en una transacción; queda en `audit_log` como `sync / pos`.

Columnas nuevas (`pos_product_id`, `pos_synced_at` en `products` y `product_variants`): las añade `app/upgrade.php` de forma idempotente al abrir el panel o reinstalar, y `schema.php` las incluye para instalaciones nuevas.

Ajustes nuevos en el panel: URL del POS, id de tienda, token (nunca vuelve al navegador; `public_settings()` lo quita del estado, igual que el token de Instagram).

Prueba: `pos_apply_menu()` se verificó sobre una copia de la base local con tres pasadas (actualizar precio único y presentaciones, agregar presentación, crear producto y categoría ocultos, bajar precio y ocultar, detectar huérfano).

## 4b. Modelo de inventario del bar en angelo-pos

El catálogo del sitio se carga en angelo-pos con `scripts/import-from-sarao.ts` (ver §5) usando este modelo:

| Tipo | Inventario | Productos vendibles | Fuente de costo y stock |
|---|---|---|---|
| Licores por trago / media / botella (Ron, Aguardiente, Tequila, Whisky, Ginebra) | **1 ingrediente por marca en ml**, con `pack_size` = ml de la botella (750; 700 si el nombre lo dice) | Trago = 45 ml · Media = 375 ml (o lo que diga el detalle: 350, 500) · Botella = ml de la botella · Combo media/botella = ml + 4 Andina | Pirpos: `pricePurchase` de la presentación Botella ÷ ml; stock = botellas × ml + medias × 375 |
| Cervezas con presentaciones | **1 ingrediente por marca en unidades** | Botella = 1 · Michelada = 1 · Cubeta = 6 | Pirpos: costo y stock de la presentación "botella" (las de cubeta/promo son contadores duplicados y se ignoran) |
| Precio único (gaseosas, aguas, vinos por botella, Smirnoff, Jack Daniel's) | **Producto inventariable** por unidad | el mismo producto | Pirpos: `avgCost`/`pricePurchase` y `stock` del producto |
| Cubeta Andina + ½ Antioqueño / Néctar | — | producto con receta: 6 Andina + 375 ml del aguardiente | — |
| Cócteles | — | producto sin receta (Pirpos no trae ingredientes); costo manual 0 hasta cargar la receta | — |
| Adicionales, Decoración | — | producto sin inventario ni costo | — |

Supuestos que conviene validar en el POS después de importar: trago = 45 ml, cubeta = 6 cervezas, combo = 4 Andina. Los tragos de Pirpos tenían stock negativo porque nadie los ataba a la botella; en angelo-pos el trigger `apply_sale_to_stock` descuenta los ml del ingrediente al registrar la venta.

## 4c. Promociones

angelo-pos gana el módulo **Promociones** (`/promociones`, tablas `promotions` + `promotion_scopes`, migración 0005):

- Tipos: `percent_off` (% sobre el precio de lista), `fixed_price` (precio por unidad; 0 = gratis) y `bundle` ("N por $X").
- Cuándo: días ISO (vacío = todos), franja horaria (puede cruzar medianoche), vigencia por fechas, activa/pausada.
- Alcance: productos puntuales (presentaciones) y/o categorías completas.
- Ventas: `daily_sale_items.promotion_id` registra la promo aplicada; `unit_price` ya es el precio promocional. Un producto puede venderse normal y en promo el mismo día.
- Lógica pura en `lib/domain/promotions.ts` (vigencia, precio unitario, total de paquete, etiquetas), con tests.

**Una promoción nunca regala.** Precio 0 y 100 % de descuento están prohibidos (restricción `chk_promotion_not_free` en base de datos, validación en servidor y aviso en pantalla). Lo gratis se registra en **Cortesías y consumo interno** (`/consumos`, tabla `consumptions`, migración 0006):

- `courtesy` (se regala a un cliente; exige quién autoriza) e `internal` (lo consume el equipo), por producto con receta o por ingrediente/botella, con cantidad, motivo, destinatario y fecha.
- Descuenta inventario con la misma expansión de receta que las ventas (`consume_product_stock`, compartida ahora con el trigger de ventas). Borrar un registro repone el stock.
- **Se contabiliza a costo, nunca a precio de venta:** `unit_cost` se congela al registrar (costo de receta del producto o costo del ingrediente) y `total_cost` es generado. No entra en `venta_total`; el dashboard operativo por rango lo muestra aparte (`cortesias_costo`, `consumo_interno_costo`) y el balance de inventario lo cuenta en el consumo de 30 días.

El importador convierte las presentaciones promocionales del sitio en promociones reales: "Promo 50 %" (miércoles, botellas de 8 cervezas) → `percent_off 50`; "Cubeta al 50 %" (jueves, Heineken y Andina) → `percent_off 50`; "Cócteles 2×40" (mié a sáb, 6 a 10 p. m.) → `bundle 40000 × 2` sobre los 8 cócteles (Gin Tonic, Margarita Red y Mojito Sarao se crean ocultos a 30.000 porque no tenían precio individual). "Cubeta gratis" **no** se importa: queda mencionada en la descripción de "Jueves de cubetas" y cada cubeta regalada se registra como cortesía cuando ocurre.

El feed publica `promotions[]`, y la sincronización del sitio:
1. Hace upsert en la tabla `promotions` de Sarao (título, detalle, días, horario) enlazando por `pos_promotion_id`; borra las del POS que desaparecen; no toca las creadas a mano en el panel.
2. **Materializa** presentaciones promocionales ("Promo 50 %", "Cubeta al 50 %", "Cubeta gratis") en los productos con presentaciones enlazadas, con la nota del día, marcadas `pos_product_id = promo:<promo>:<producto>`. Así la carta se ve exactamente como hoy sin tocar su render. Las que dejan de aplicar se borran.
3. Los paquetes y las promos sobre productos de precio único solo van a la franja "Promos de la semana".

## 5. Puesta en marcha

1. **angelo-pos:** definir `MENU_FEED_TOKEN` en el entorno de producción y `NEXT_PUBLIC_APP_NAME="El Sarao Pub"`. Desplegar. Aplicar `drizzle/0004_hardening.sql` (`npx drizzle-kit migrate`).
2. **Catálogo inicial en el POS** (dos comandos):
   ```bash
   # en Sarao: exporta categorías, productos, presentaciones y promos del sitio
   php -d extension=pdo_sqlite tools/export_catalog.php > catalogo-sarao.json
   # en angelo-pos: primero --dry-run (escribe scripts/out/import-plan.json), luego sin él
   npx tsx scripts/import-from-sarao.ts --catalog catalogo-sarao.json --pirpos ../Sarao/tools/pirpos-menu.json --store "El Sarao Pub" --dry-run
   ```
   Resultado con los datos actuales: 14 categorías, 25 ingredientes (24 con costo), 102 productos (73 con receta, 17 inventariables), 4 promociones. Es idempotente: lo que ya exista por nombre se salta.
3. **Sarao:** subir los archivos cambiados, abrir el panel (aplica las columnas nuevas), Ajustes → "Precios desde el POS": URL, id de tienda, token. Guardar y "Sincronizar ahora". Revisar en Productos los que quedaron ocultos.
4. A partir de ahí, **los precios se cambian solo en angelo-pos**. El sitio los recibe en menos de una hora o al pulsar "Sincronizar ahora".

## 6. Lo que queda abierto

- **Dirección del flujo editorial.** El sitio no manda nada al POS. Si más adelante se quiere que el POS muestre la foto del producto, el feed inverso es un `GET` del POS a `carta/menu.json` del sitio; no está implementado.
- **Pirpos.** Mientras el bar siga cobrando con Pirpos, angelo-pos es back-office (ventas se cargan al cierre, con IA o manual). El roadmap de angelo-pos (fase 2) contempla la caja en tiempo real; Pirpos quedaría como fuente de la venta diaria hasta entonces.
- **`instagram.php`** está sin versionar y sin conectar al panel ni al sitio (no hay acciones `ig.*` en `api.php` ni consumo de `ig_posts()`). Es trabajo en curso ajeno a esta integración.
- **Nombres de categoría.** El sitio normaliza tildes y mayúsculas para casar categorías ("COCTELES" = "Cócteles"), pero no sinónimos. Conviene que las categorías del POS se llamen igual que en el sitio al cargar el catálogo inicial.
