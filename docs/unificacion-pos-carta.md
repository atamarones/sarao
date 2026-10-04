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

## 5. Puesta en marcha

1. **angelo-pos:** definir `MENU_FEED_TOKEN` en el entorno de producción y `NEXT_PUBLIC_APP_NAME="El Sarao Pub"`. Desplegar. Aplicar `drizzle/0004_hardening.sql` (`npx drizzle-kit migrate`).
2. **Catálogo inicial en el POS:** cargar las 14 categorías y 54 productos del bar en angelo-pos. Fuente recomendada: el export de Pirpos (`tools/pirpos-menu.json`, no versionado) que ya trae costos y stock, que el sitio no tiene. Un producto del sitio con presentaciones se carga como N productos POS con el mismo nombre y `size` = etiqueta de la presentación.
3. **Sarao:** subir los archivos cambiados, abrir el panel (aplica las columnas nuevas), Ajustes → "Precios desde el POS": URL, id de tienda, token. Guardar y "Sincronizar ahora". Revisar en Productos los que quedaron ocultos.
4. A partir de ahí, **los precios se cambian solo en angelo-pos**. El sitio los recibe en menos de una hora o al pulsar "Sincronizar ahora".

## 6. Lo que queda abierto

- **Dirección del flujo editorial.** El sitio no manda nada al POS. Si más adelante se quiere que el POS muestre la foto del producto, el feed inverso es un `GET` del POS a `carta/menu.json` del sitio; no está implementado.
- **Pirpos.** Mientras el bar siga cobrando con Pirpos, angelo-pos es back-office (ventas se cargan al cierre, con IA o manual). El roadmap de angelo-pos (fase 2) contempla la caja en tiempo real; Pirpos quedaría como fuente de la venta diaria hasta entonces.
- **`instagram.php`** está sin versionar y sin conectar al panel ni al sitio (no hay acciones `ig.*` en `api.php` ni consumo de `ig_posts()`). Es trabajo en curso ajeno a esta integración.
- **Nombres de categoría.** El sitio normaliza tildes y mayúsculas para casar categorías ("COCTELES" = "Cócteles"), pero no sinónimos. Conviene que las categorías del POS se llamen igual que en el sitio al cargar el catálogo inicial.
