# Contrato nube ↔ agente del bar

**Versión:** 2 · **Fecha:** 2026-10-06 · Complementa `docs/karaoke-arquitectura.md` (§8).

Cambios respecto a la versión 1: `natural_key` ya no incluye la duración; las canciones llevan `source` (`local` o `karafun`) y el catálogo en línea de KaraFun también se sincroniza; una canción descargada puede entrar a la cola sin cantante (`singer_shown: false`).

**URL de producción del agente:** `https://karaoke.saraopub.com/karaoke/agent.php` (configurable en el agente si la ruta cambia).

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
  "working": [813],
  "acks_pending": 0
}
```
- `working`: ids de órdenes que el agente tiene en marcha (una descarga tarda 10–60 s). La nube **extiende el lease 30 s** de cada una y no las reentrega mientras sigan apareciendo aquí.
- `karafun.connected: false` o `running: false` significa que KaraFun no está disponible: la nube no debe emitir órdenes `enqueue`/`remove` hasta que vuelva.
Respuesta:
```json
{
  "server_time": "2026-10-06T21:15:00-05:00",
  "commands": [
    { "id": 812, "type": "enqueue", "lease_until": "2026-10-06T21:15:30-05:00",
      "payload": { "request_id": "9f1c…", "song": { "natural_key": "…", "title": "…", "artist": "…", "duration_s": 228 },
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

### `catalog.begin` / `catalog.chunk` / `catalog.commit` — catálogo

1. `catalog.begin` con `{ "source": "local" }` o `{ "source": "karafun" }` → `{ "sync_id": "…" }`
2. `catalog.chunk` con `{ "sync_id", "index": 0, "songs": [ … hasta 500 … ] }` (cada lote idempotente por `sync_id` + `index`)
3. `catalog.commit` con `{ "sync_id", "total_chunks": 8, "total_songs": 3759 }` → la nube activa la versión nueva **de esa fuente** en una transacción. Las canciones de esa fuente que no vinieron pasan a `available = 0` (no se borran).

Las dos fuentes se sincronizan por separado: la local al arrancar el agente y cada noche (~4 min); la de KaraFun en línea con menos frecuencia, porque es mucho más grande.

Canción:
```json
{ "natural_key": "adriana lucia|quisiera olvidarte", "source": "local", "kf_id": null,
  "title": "Quisiera olvidarte", "artist": "Adriana Lucia", "duration_s": 279,
  "folder": null, "youtube_id": null }
{ "natural_key": "kf:76237", "source": "karafun", "kf_id": 76237,
  "title": "Caballero", "artist": "Alejandro Fernández", "duration_s": 228, "folder": null, "youtube_id": null }
```
- Canciones locales: `natural_key` = artista + `|` + título, normalizados (minúsculas, sin tildes, todo lo que no sea letra o número pasa a espacio, espacios simples). **Sin duración:** KaraFun la reporta en 0 hasta que analiza el archivo. Dos archivos con el mismo artista y título son la misma canción para el cliente.
- Canciones de KaraFun en línea: `natural_key` = `kf:<id>`; su id sí es estable.
- `duration_s` es informativa y puede ser 0.
- `folder = "Por aprobar"` marca canciones descargadas de YouTube que el encargado todavía no revisó; `youtube_id` se rellena en esas.

### `song.upsert` — una canción suelta (tras una descarga)

`{ "song": { …mismo formato… } }` → inserta o actualiza sin tocar el resto del catálogo.

## 3. Órdenes (`type`)

| type | payload | qué hace el agente | `result` en el ack |
|---|---|---|---|
| `download` | `request_id`, `youtube_id`, `max_duration_s` (480) | Comprueba metadatos con yt-dlp (no directos, duración ≤ máximo), descarga mp4 ≤ 720p a `Música\Karaoke\Por aprobar\Artista - Título [youtube_id].mp4` (si ese id ya existe en la carpeta, no repite) y sube la canción con `song.upsert` | `{ youtube_id, song: {…} }` |
| `enqueue` | `request_id`, `song`, `singer` | Añade la canción al final de la cola de KaraFun con ese cantante. Si KaraFun todavía no indexó el archivo (descarga reciente), la añade por ruta de archivo, y entonces sin cantante | `{ queue_pos, singer_shown }` |
| `remove` | `request_id`, `singer`, `song` | Quita de la cola de KaraFun la entrada con ese cantante (marcador) que aún no suena; si entró sin cantante, la de ese título | `{ removed: true/false }` |
| `catalog.resync` | `source` (`local` o `karafun`) | Vuelve a leer el catálogo de esa fuente y lo sube completo (§2) | `{ total_songs }` |

Reglas:
- **La nube decide cuándo** emitir `enqueue`: solo cuando la cola real de KaraFun tiene menos de 3 entradas (la que suena + 2). La rotación justa entre mesas vive en la nube.
- El `singer` lleva un marcador único corto del pedido (`Ana · M7·k3f` = nombre, mesa 7, 3 caracteres del id) para que el agente reconcilie si se cae a mitad de una orden.
- El agente nunca recibe la URL que escribió el cliente, solo el `youtube_id` ya validado.
- Una orden con el mismo `id` nunca se ejecuta dos veces: el agente guarda un diario local de ids ejecutados.

## 4. Lo que la nube no debe asumir

- Que el agente esté conectado: si no hay `poll` en 15 s, el panel muestra "Agente desconectado" y la mesa ve un aviso; los pedidos se siguen aceptando y esperan.
- Que la descarga sea inmediata: puede tardar 10–60 s.
- Que KaraFun conserve los ids internos: la identidad de una canción es `natural_key`.
