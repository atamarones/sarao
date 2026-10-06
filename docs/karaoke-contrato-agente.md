# Contrato nube ↔ agente del bar

**Versión:** 1.1 · **Fecha:** 2026-10-06 · Complementa `docs/karaoke-arquitectura.md` (§8).

La nube (PHP en Hostinger) es la fuente de verdad. El agente (PC del bar) **solo hace peticiones salientes por HTTPS** y obedece órdenes. Este documento es el acuerdo entre las dos partes: si algo cambia aquí, cambia en ambos lados.

## 1. Transporte y autenticación

- Base: `https://<dominio>/karaoke/agent.php?action=<acción>`
- Todas las peticiones: `POST`, cuerpo JSON (`Content-Type: application/json`), respuesta JSON.
- Cabecera `Authorization: Bearer <AGENT_TOKEN>`. Token de 64 caracteres hex guardado en `settings` (`karaoke_agent_token`, en `SECRET_SETTINGS`), comparación con `hash_equals`. Sin token válido: `401`.
- Errores con el formato RFC 7807 que ya usa `admin/api.php` (`application/problem+json`).
- Horas en ISO 8601 con zona (`2026-10-06T21:15:00-05:00`). La hora válida es siempre la del servidor.

## 2. Acciones

### `poll` — latido + estado + recoger órdenes (cada 2 s)

Petición:
```json
{
  "agent_version": "1.0.0",
  "karafun": { "running": true, "connected": true, "state": "playing",
               "queue": [ { "pos": 0, "title": "Caballero", "artist": "Alejandro Fernández",
                            "singer": "Ana · M7·k3f", "status": "playing" } ] },
  "acks_pending": 0
}
```
Respuesta:
```json
{
  "server_time": "2026-10-06T21:15:00-05:00",
  "commands": [
    { "id": 812, "type": "enqueue", "lease_until": "2026-10-06T21:15:30-05:00",
      "payload": { "request_id": "9f1c…", "song": { "natural_key": "…", "title": "…", "artist": "…", "duration_s": 228, "source": "local" },
                   "singer": "Ana · M7·k3f" } }
  ]
}
```
- La nube guarda el latido (`karaoke_agent`) y la cola real de KaraFun, y con ella actualiza los pedidos (`en_cola`, `cantando`, `cantada`, `retirado`).
- Entrega como máximo 10 órdenes `pending` o con lease vencido, ordenadas por id, y las marca `leased` 30 s.

### `ack` — resultado de una orden

```json
{ "command_id": 812, "ok": true, "result": { } }
{ "command_id": 813, "ok": false, "error": { "code": "too_long", "message": "El video dura 12 min (máximo 8)." } }
```
- Idempotente: un segundo `ack` de la misma orden responde `200` sin cambiar nada.
- `ok: false` con `retryable: true` vuelve la orden a `pending` (máx. 5 intentos); sin `retryable`, el pedido pasa a `fallido` con el mensaje, que la mesa ve.

### `catalog.begin` / `catalog.chunk` / `catalog.commit` — catálogo de canciones locales

1. `catalog.begin` → `{ "sync_id": "…" }`
2. `catalog.chunk` con `{ "sync_id", "index": 0, "songs": [ … hasta 500 … ] }` (cada lote idempotente por `sync_id` + `index`)
3. `catalog.commit` con `{ "sync_id", "total_chunks": 8, "total_songs": 3759 }` → la nube activa la versión nueva en una transacción. Las canciones que no vinieron pasan a `available = 0` (no se borran).

Canción:
```json
{ "natural_key": "adriana lucia|quisiera olvidarte|187",
  "title": "Quisiera olvidarte", "artist": "Adriana Lucia", "duration_s": 187,
  "folder": "A", "file": "A/Adriana Lucia - Quisiera olvidarte.mp4" }
```
- `natural_key` = `artista|título|duración`. La calcula el agente y la nube la usa como identidad (la guarda tal cual, sin recalcularla). Regla exacta, la misma en los dos lados:
  1. Artista y título se normalizan así: minúsculas; se quitan tildes y diéresis (`á→a`, `ü→u`) y `ñ→n` (equivale a descomponer en Unicode NFD y quitar las marcas combinantes; en Python, `unicodedata.normalize('NFD', s)` y descartar los caracteres con `unicodedata.combining(c)`); todo carácter que no sea `a-z` o `0-9` pasa a espacio; los espacios seguidos se reducen a uno y se recortan los extremos.
  2. Duración: segundos enteros, **sin redondear a 5 s** (los decimales se redondean al segundo más cercano). Si se desconoce, `0`.
  3. Se unen con `|` en el orden artista, título, duración.
  Ejemplo: `Adriana Lucía` · `¡Quisiera olvidarte!` · 187 s → `adriana lucia|quisiera olvidarte|187`.
  (Aclarado el 2026-10-06: la versión anterior decía "redondeada a 5 s", lo que contradecía este ejemplo; vale el ejemplo.)
- `folder = "Por aprobar"` marca canciones descargadas de YouTube que el encargado todavía no revisó.

### Catálogo en línea de KaraFun (v1.1)

El catálogo en línea (~90 mil canciones, ids positivos y estables) **no lo sube el agente**: el encargado sube en el panel el CSV que exporta KaraFun (`Id;Title;Artist;…`). Esas canciones quedan con `source = "karafun"`, su `kf_id` y `natural_key = "karafun:<kf_id>"` (no llevan duración). Las mesas buscan en las dos fuentes; las de la carpeta local salen primero.

### `song.upsert` — una canción suelta (tras una descarga)

`{ "song": { …mismo formato… } }` → inserta o actualiza sin tocar el resto del catálogo.

## 3. Órdenes (`type`)

Cambios de la v1.1 (aditivos: un agente v1 sigue funcionando con las canciones locales): `enqueue.payload.song` lleva `source` siempre y `kf_id` cuando `source = "karafun"`.


| type | payload | qué hace el agente | `result` en el ack |
|---|---|---|---|
| `download` | `request_id`, `youtube_id`, `max_duration_s` (480) | Comprueba metadatos con yt-dlp (no directos, duración ≤ máximo), descarga mp4 ≤ 720p a `Música\Karaoke\Por aprobar\Artista - Título [youtube_id].mp4`, hace que KaraFun lo vea y sube la canción con `song.upsert` | `{ youtube_id, song: {…} }` |
| `enqueue` | `request_id`, `song`, `singer` | Añade la canción al final de la cola de KaraFun con ese cantante. `song.source` (v1.1) dice de dónde es: `"local"` → la resuelve con `search` como en v1; `"karafun"` → trae además `song.kf_id` y la añade directamente con `addToQueue song="<kf_id>"`, sin buscar | `{ queue_pos }` |
| `remove` | `request_id`, `singer` | Quita de la cola de KaraFun la entrada con ese cantante (marcador) si aún no ha sonado | `{ removed: true/false }` |
| `catalog.resync` | — | Relee la carpeta y sube el catálogo completo (§2) | `{ total_songs }` |

Reglas:
- **La nube decide cuándo** emitir `enqueue`: solo cuando la cola real de KaraFun tiene menos de 3 entradas (la que suena + 2). La rotación justa entre mesas vive en la nube.
- El `singer` lleva un marcador único corto del pedido (`Ana · M7·k3f` = nombre, mesa 7, 3 caracteres del id) para que el agente reconcilie si se cae a mitad de una orden.
- El agente nunca recibe la URL que escribió el cliente, solo el `youtube_id` ya validado.
- Una orden con el mismo `id` nunca se ejecuta dos veces: el agente guarda un diario local de ids ejecutados.

## 4. Lo que la nube no debe asumir

- Que el agente esté conectado: si no hay `poll` en 15 s, el panel muestra "Agente desconectado" y la mesa ve un aviso; los pedidos se siguen aceptando y esperan.
- Que la descarga sea inmediata: puede tardar 10–60 s.
- Que KaraFun conserve los ids internos: la identidad de una canción es `natural_key`.
