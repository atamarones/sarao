# Karaoke por mesa · arquitectura

**Fecha:** 2026-10-06 · **Estado:** parte en la nube implementada (página de mesa, panel, API del agente; ver §15). Falta el agente del PC del bar; comprobaciones en el PC casi completas (§14).

**Decisiones del negocio (2026-10-06):**
- Se sigue con **KaraFun Player 2** (2.6.2), no con KaraFun 3: es el único con API local verificada.
- Los enlaces de YouTube se **descargan y encolan solos**, sin aprobación previa. Lo estricto es la validación: solo se acepta un enlace válido de YouTube, nunca un nombre de canción ni texto libre.
- Lo descargado va a `Música\Karaoke\Por aprobar\`. El encargado, después, lo mueve a su carpeta de letra si sirve como karaoke o lo borra.

## 1. Problema

Cada mesa pide canciones en una hojita y el encargado (que también es cajero) las busca en KaraFun, o en YouTube si no están, y las mete en la cola. Con ~10 mesas pidiendo 3–4 canciones durante 9 horas, es un trabajo de tiempo completo.

**Objetivo:** que cada mesa busque y pida desde el celular sin intervención del encargado. El encargado solo depura después la carpeta `Por aprobar`.

## 2. Principios

1. **La nube es la fuente de verdad de los pedidos.** Base de datos en Hostinger, junto al sitio actual. Los clientes nunca hablan con el PC del bar.
2. **El PC del bar es un ejecutor.** Un agente obedece órdenes de la nube y le informa lo que pasa en KaraFun. No decide nada.
3. **Solo conexiones salientes desde el bar.** El agente llama a la nube por HTTPS. No se abren puertos en el router.
4. **Toda orden se puede repetir sin efectos dobles** (idempotencia). La red falla; reintentar debe ser seguro.
5. **Lo que dice KaraFun manda sobre la cola física.** Si el encargado mueve algo a mano en KaraFun, la nube lo acepta y se ajusta, no lo pelea.
6. **Si algo falla, el bar sigue funcionando.** Sin internet, sin agente o sin la nube, KaraFun se sigue usando a mano como hoy.

No existe un sistema distribuido sin fallos. El objetivo realista es que **ningún fallo pierda un pedido ni lo duplique sin que se vea**, y que cada fallo sea visible en el panel y recuperable.

## 3. Componentes

```
 Celular de la mesa ──HTTPS──►  Hostinger (PHP + MySQL)  ◄──HTTPS (saliente)──  Agente (PC del bar)
   /karaoke/m/<token>             API pública de mesas                              │ ws://127.0.0.1:57570
                                  API del agente (token)                            ▼
 Panel del encargado ─HTTPS──►    /admin/ → Karaoke                            KaraFun Player 2
                                  tablas karaoke_*                                  │
                                                                               yt-dlp → Música\Karaoke
```

| Componente | Dónde | Responsabilidad |
|---|---|---|
| Página de mesa | `public_html/karaoke/` | Buscar, pedir, ver mi turno, pegar enlace de YouTube |
| Panel | `public_html/admin/` (sección nueva) | Aprobar/rechazar, reordenar, ver estado del agente y de KaraFun |
| API y lógica | `public_html/app/karaoke*.php` | Máquina de estados, cola justa, outbox de órdenes |
| Agente | PC del bar, servicio de Windows | Ejecutar órdenes en KaraFun, descargar, sincronizar catálogo, latido |
| KaraFun Player 2 | PC del bar | Reproducir. Su API WebSocket queda solo en `127.0.0.1` |

## 4. Protocolo de KaraFun Player 2 (verificado el 2026-10-05)

WebSocket en el puerto **57570**, mensajes XML. Verificado en este PC:

| Orden | Formato | Resultado |
|---|---|---|
| Estado | `<action type="getStatus"></action>` | `<status>` con cola, posición, tono, tempo, volúmenes. **También llega solo cada vez que algo cambia.** |
| Catálogos | `<action type="getCatalogList"></action>` | Incluye la carpeta local `Karaoke` (id 524289) y las categorías en línea |
| Listar | `<action type="getList" id="…" offset="0" limit="…"></action>` | `<list total="…">` con id, título, artista, duración. **Máximo 100 por página** (~6 s cada una: la carpeta completa tarda ~4 min). El atributo `total` **no es fiable** (dio 2614 y 2 para la misma carpeta): se pagina hasta recibir una página incompleta |
| Buscar | `<action type="search" offset="0" limit="…">texto</action>` | Busca **en local y en línea a la vez**. Ids locales negativos, KaraFun positivos |
| Añadir | `<action type="addToQueue" song="-93345" singer="Mesa 7 · Ana">99999</action>` | Entra en la posición indicada (99999 = al final) |
| Quitar | `<action type="removeFromQueue" id="2"></action>` | `id` = posición en la cola |

Formato tomado de [AndroidKarafunAPI](https://github.com/polytope/AndoridKarafunAPI). **No es una API oficial documentada por KaraFun:** no se actualiza KaraFun Player 2 sin probar antes en un PC que no sea el de producción.

**Lo que la API no hace:** releer la carpeta. KaraFun **no detecta archivos nuevos** por su cuenta (verificado: un mp4 nuevo no aparece en la búsqueda tras 45 s). La única forma es el menú de la carpeta → **Actualizar**, que el agente tendrá que pulsar con automatización de Windows (UI Automation).

**Comportamientos de KaraFun Player 2 que el agente debe tolerar** (vistos el 2026-10-06):
- Se cuelga al salir con "The application seems to be frozen" y hay que terminar el proceso.
- Al arrancar, ese mismo aviso puede aparecer y **bloquea la carga hasta que alguien pulsa OK** (`tools/karafun/kf-dismiss-frozen.ps1` lo pulsa por UI Automation, nunca "Terminate").
- Un arranque puede quedarse sin responder esperando una conexión a internet; se resuelve terminando y volviendo a abrir. Arranque normal: 10–60 s hasta que el puerto 57570 escucha.
- "Actualizar" falla (Access violation en `TCacheManager.RemoveFilenames`) cuando tiene que **quitar muchas** canciones desaparecidas a la vez, y el índice nunca se guarda. Por eso la carpeta estuvo desactualizada del 2 al 6 de octubre. Arreglo aplicado el 2026-10-06: con KaraFun cerrado, dejar los `.kdir` de `C:\ProgramData\Recisio\KaraFun2` solo con su cabecera de 2 líneas y pulsar Actualizar (respaldo en `Documentos\KaraFun2-respaldo-20261006-103538`). Consecuencia operativa: **mover archivos entre carpetas de a poco** y pulsar Actualizar después, no reorganizar cientos de una vez.
- El `.kdir` guarda las rutas con tildes mal codificadas (UTF-8 leído como Windows-1252: `DÃ­as` = `Días`). Si alguna herramienta lo lee, debe decodificarlo así.

## 5. Modelo de datos (nube)

```
karaoke_songs        catálogo buscable
  id, source (local|karafun), natural_key, title, artist, duration_s,
  search_text (normalizado sin tildes), kf_id (último id visto, NO estable),
  folder (letra|por_aprobar|NULL para KaraFun en línea), available, seen_at
karaoke_downloads    una fila por video de YouTube: youtube_id (único), song_id NULL, status,
                     duration_s, requested_by (pedido), downloaded_at, error NULL
karaoke_tables       mesas: id, name, qr_token (fijo, impreso), is_active
karaoke_nights       noche de servicio: id, opens_at, closes_at, night_code (rota cada noche)
karaoke_requests     pedidos: id (uuid generado en el celular), night_id, table_id, singer,
                     song_id NULL, youtube_id NULL, status, fair_seq, kf_queue_pos NULL,
                     created_at, updated_at, error NULL
karaoke_request_log  cada transición: request_id, from, to, actor (mesa|admin|agente|sistema), at
karaoke_commands     outbox hacia el agente: id, request_id NULL, type, payload (JSON),
                     status (pending|leased|done|failed), attempts, lease_until, result (JSON)
karaoke_agent        latido: last_seen_at, version, kf_connected, kf_state, queue_snapshot (JSON)
```

`kf_id` de las canciones locales **no se usa como identidad**. Se conserva al reiniciar KaraFun (verificado: 2614 de 2614 iguales), pero **cambia al pulsar Actualizar** (tras el arreglo del 2026-10-06 cambiaron todos) y la misma canción puede existir en varios archivos con ids distintos. La identidad es `natural_key` = `artista|título|duración` normalizados (regla exacta en el contrato, §2). El agente vuelve a resolver el id justo antes de añadir a la cola.

## 6. Ciclo de vida de un pedido

```
catálogo ──────────────────────────────┐
                                       ▼
pedido ─┤                          en_espera ─► enviado ─► en_cola ─► cantando ─► cantada
        │                              ▲           │          │
YouTube ─► descargando ─► descargado ──┘           │          └► retirado (quitado a mano en KaraFun)
               │                                   │
               └────────────► fallido ◄────────────┘   (con motivo, visible para la mesa y en el panel)

en cualquier estado antes de `enviado`: cancelado (por la mesa o el encargado)
```

- Las transiciones solo las hace el servidor, en una transacción, con `UPDATE … WHERE status = <esperado>` (bloqueo optimista). Dos clics simultáneos no pueden mover un pedido dos veces.
- **Sin aprobación previa:** los pedidos del catálogo entran directo a `en_espera` y los de YouTube en cuanto la descarga termina. El encargado puede cancelar cualquiera antes de que llegue a KaraFun.
- `en_cola`, `cantando` y `cantada` **no los decide la nube**: salen del estado que reporta KaraFun.

## 7. Cola justa y buffer corto

La nube guarda la lista de espera completa y calcula el orden por **rotación entre mesas** (mesa 1, mesa 2, mesa 3…, con orden de llegada dentro de cada mesa y tope de pedidos pendientes por mesa).

KaraFun solo recibe un **buffer corto**: la canción que suena + las 2 siguientes. Cuando el buffer baja, la nube emite la orden de añadir la siguiente. Así:

- Reordenar, cancelar o adelantar se hace en la nube sin tocar KaraFun.
- El encargado ve en KaraFun una cola corta y entendible, y puede intervenir a mano.
- Si se cae internet, quedan 2 canciones en cola: ~8 minutos de margen.

## 8. Comunicación nube ↔ agente

**Outbox con arrendamiento (lease):**

1. Cada 1–2 s el agente pide órdenes: `POST /karaoke/api/agent/poll` con su estado. La nube le entrega las órdenes `pending` (o con lease vencido) y las marca `leased` por 30 s.
2. El agente ejecuta y confirma: `POST /karaoke/api/agent/ack` con `command_id` y resultado.
3. Si el agente muere a mitad, el lease vence y la orden se reentrega.

**Entrega al menos una vez + idempotencia = efecto exactamente una vez:**

- El agente guarda un **diario local** (`journal`) con cada `command_id` y su resultado. Una orden repetida devuelve el resultado guardado sin volver a ejecutarse.
- El caso difícil: el agente añade la canción y se cae **antes** de escribir el diario. Al reiniciar, toda orden marcada como "en curso" se **reconcilia contra la cola real de KaraFun** (título + cantante + posición) antes de reintentar.
- Para que la reconciliación sea fiable, el campo cantante lleva un marcador corto del pedido: `Ana · M7·k3f` (mesa 7, 3 caracteres del id del pedido).

**Estado real hacia la nube:** el agente reenvía cada `<status>` que KaraFun emite (llegan solos al cambiar) en la siguiente consulta. La nube compara la cola real con la esperada y actualiza los pedidos.

**Autenticación del agente:** token de 32+ bytes en Ajustes (mismo patrón que `MENU_FEED_TOKEN`), comparación en tiempo constante, solo HTTPS.

## 9. Catálogo

- **Sincronización completa** al arrancar y cada noche antes de abrir: el agente recorre `getList` de la carpeta Karaoke (~4 min) y de "Todas las canciones" en lotes, y los sube a la nube.
- **Sincronización puntual** tras cada descarga de YouTube: el agente pulsa Actualizar, busca la canción nueva con `search` (segundos, no minutos) y sube solo esa fila, marcada `folder = por_aprobar`.
- Cuando el encargado mueve un archivo de `Por aprobar` a su carpeta de letra (o lo borra), debe pulsar Actualizar en KaraFun; la siguiente sincronización completa refleja el cambio (la canción pierde la marca `por_aprobar`, o pasa a `available = 0`). El panel recordará cuántas canciones hay pendientes en `Por aprobar`.
- La nube carga cada sincronización en una tabla de staging y la activa en una sola transacción (versión de catálogo). Una sincronización a medias nunca deja el buscador vacío ni mezclado.
- Canciones que desaparecen se marcan `available = 0`, no se borran (pedidos antiguos siguen apuntando a ellas).
- La búsqueda de las mesas es contra MySQL (rápida y sin depender del bar).

## 10. YouTube

**Validación estricta en la nube** (lo único que se acepta es un enlace de video de YouTube):

1. El campo acepta solo una URL. Se rechaza cualquier texto que no sea una URL completa (un nombre de canción, varias URLs, texto adicional).
2. Esquema `https`, dominio exacto: `youtube.com`, `www.youtube.com`, `m.youtube.com`, `music.youtube.com` o `youtu.be`.
3. Forma reconocida: `/watch?v=<id>`, `youtu.be/<id>` o `/shorts/<id>`. Listas (`list=` sin `v=`), canales, búsquedas y directos se rechazan.
4. `<id>` debe cumplir `^[A-Za-z0-9_-]{11}$`. La nube guarda **solo el id**, nunca la URL tal como la escribió el cliente.
5. Comprobación de existencia antes de aceptar el pedido: consulta al oEmbed público de YouTube con ese id. Si no existe o es privado, la mesa ve el error al instante.

**Descarga y encolado automáticos** (sin aprobación):

1. Orden `download` al agente con el id. El agente consulta los metadatos con `yt-dlp` antes de bajar: rechaza directos y videos de más de 8 min (`fallido` con motivo).
2. Descarga mp4 ≤ 720p a `Música\Karaoke\Por aprobar\`, nombre `Artista - Título [id].mp4`. Si ese id ya se descargó antes (tabla de descargas en la nube), no se repite: se usa la canción existente.
3. El agente pulsa Actualizar, resuelve el id de KaraFun con `search` y el pedido pasa a `en_espera` como uno normal.
4. El encargado depura `Por aprobar` cuando pueda (§9).

Descargar de YouTube va contra sus términos de uso, y poner música en un local comercial puede requerir licencias distintas a la de KaraFun. Es una decisión del negocio; el sistema deja registro de cada descarga, de qué mesa la pidió y cuándo.

## 11. Seguridad y abuso

- QR por mesa con `qr_token` impreso + `night_code` que cambia cada noche y se muestra en la pantalla del karaoke. Un QR fotografiado no sirve desde la casa al día siguiente.
- Límites: pedidos pendientes por mesa, pedidos por minuto por mesa e IP, longitud del nombre del cantante.
- El nombre del cantante se escapa en todo HTML y en el XML hacia KaraFun.
- El puerto 57570 se cierra a la red con el Firewall de Windows: solo `127.0.0.1`.
- No se guardan datos personales más allá del nombre que la mesa escribe para el cantante.

## 12. Fallos y cómo se ven

| Falla | Qué pasa | Qué ve la gente |
|---|---|---|
| Se cae internet del bar | Lo que ya está en el buffer sigue sonando. Los pedidos se siguen recibiendo en la nube (los celulares usan datos móviles). | Panel: "Agente desconectado hace X s". Al volver, el agente se pone al día solo. |
| KaraFun cerrado o colgado | El agente reintenta con espera creciente. Si el proceso no responde durante 60 s, lo termina y lo vuelve a abrir, y pulsa OK en el aviso de "congelado" si aparece. Las órdenes no se pierden (siguen en el outbox). | Panel: "KaraFun no responde" / "Reiniciando KaraFun". |
| "Actualizar" no termina | El agente compara el índice antes y después; si no cambia en 2 min, avisa. | Panel: "KaraFun no pudo releer la carpeta". |
| PC reiniciado | El agente arranca como servicio con Windows y reconcilia. | Nada, si KaraFun también arranca solo. |
| Hostinger caído | El bar vuelve a la hojita y a KaraFun a mano. | La página de mesa muestra un aviso. |
| Descarga falla | Pedido `fallido` con motivo (no existe, muy largo, error de red). | La mesa ve el motivo y puede pegar otro enlace. |
| Encargado quita una canción en KaraFun | El pedido pasa a `retirado`. | La mesa lo ve en su lista. |

Las horas siempre las pone el servidor (hora de Bogotá), nunca el celular ni el PC.

## 13. Pruebas

- **Lógica pura** (estados, rotación justa, validación de enlaces): pruebas unitarias en PHP.
- **Agente:** un KaraFun simulado (servidor WebSocket que habla el mismo XML) para probar reintentos, caídas a mitad de orden y reconciliación sin tocar el KaraFun real.
- **Punta a punta** en local con SQLite (como el resto del repo) antes de cada despliegue.
- **Ensayo en el bar** en horario cerrado antes de usarlo con público.

## 14. Comprobaciones en el PC del bar

| # | Pregunta | Resultado (2026-10-06) |
|---|---|---|
| 1 | ¿Los ids de KaraFun se mantienen al reiniciar? | ✅ Sí, 2614 de 2614. Cambian al pulsar Actualizar (§5). |
| 2 | ¿KaraFun detecta solo un mp4 nuevo? | ❌ No. Hace falta Actualizar (§4). |
| 3 | ¿Por qué KaraFun veía 2614 de 3759 videos? | ✅ Índice congelado desde el 2 de octubre por el fallo de Actualizar (§4). **Arreglado:** KaraFun ve 3759 y todas las rutas existen. |
| 4 | Descarga con `yt-dlp` a `Por aprobar`, Actualizar y encolar | ⏳ Pendiente. Falta ver también qué título y artista asigna KaraFun a un mp4 descargado (¿nombre de archivo o metadatos?). |
| 5 | Lenguaje del agente | ⏳ El PC no tiene Python, PHP, Node ni .NET (solo PowerShell 5.1). Recomendado: **Python** (yt-dlp es Python, buena librería WebSocket, se instala como servicio con NSSM). |
| 6 | Límites de Hostinger para ~10 mesas cada 3 s + el agente cada 1–2 s | ⏳ Se mide cuando exista la página. |

Otros hallazgos: 4 mp4 dañados de menos de 50 bytes (`Corregir\Kaleth Morales - Destrozaste Mi Alma.mp4`, `G\Guayacan Orquesta - Cada dia que pasa.mp4`, `M\Maluma - ADMV.mp4` y uno en `Repetidos`). La carpeta `Descargas` ya no tiene videos; su índice en KaraFun quedó vacío.

Herramientas de prueba en `tools/karafun/`: `kfws.ps1` (consola de órdenes), `kf-call.ps1` (una orden, guarda la respuesta), `kf-dump-list.ps1` (exporta un catálogo a CSV), `kf-dismiss-frozen.ps1` (pulsa OK en el aviso de congelado).

## 15. Decisiones de la implementación en la nube (2026-10-06)

Lo que se decidió al construir la nube y no estaba arriba, o cambió:

- **Rutas.** La API del agente es la del contrato: `POST /karaoke/agent.php?action=…` (no `/karaoke/api/agent/…`). La página de mesa es `/karaoke/?m=<token>` en vez de `/karaoke/m/<token>`: funciona sin reglas de reescritura, igual en Hostinger que con `php -S`. Es lo que lleva el QR.
- **`natural_key`.** Duración en segundos exactos, sin redondear a 5 s (vale el ejemplo del contrato; regla completa en el contrato §2). La nube guarda la clave del agente sin recalcularla, y avisa en `warnings` si no coincide con la regla.
- **Catálogo en línea de KaraFun (contrato v1.1).** Se importa desde el panel con el CSV que exporta KaraFun: `source = karafun`, `kf_id` (estable en línea) y `natural_key = karafun:<kf_id>` (en ese catálogo hay versiones con igual artista y título). El orden del CSV (de más a menos cantada) se guarda como `popularity` y desempata la búsqueda. El `enqueue` lleva `source` y, para estas, `kf_id`, que la mesa nunca ve.
- **Búsqueda rápida.** `karaoke_song_words` indexa cada palabra normalizada de artista y título. La palabra más larga de la consulta se busca por prefijo (un rango en el índice, no un recorrido de la tabla) y las demás filtran ese grupo. Con 89.628 canciones: mediana de 3–16 ms y peor caso ~150 ms (palabras muy comunes de 2 letras), en MariaDB 10.11 y SQLite. Primero lo local, luego lo más cantado.
- **Tablas y columnas añadidas a §5.** `karaoke_song_words`; `karaoke_catalog_syncs`, `karaoke_catalog_chunks` y `karaoke_catalog_staging` (sincronización por lotes); `karaoke_rate_events` (límites). `karaoke_tables.number` es el número impreso, el del marcador `M7`. `karaoke_requests` añade `marker`, `sent_at` y `acked_at`; `karaoke_songs` añade `file` y `popularity`.
- **Noche.** Código de 4 cifras; la noche caduca sola a las 14 h. «Cambiar código» conserva la noche. Cerrar la noche cancela (actor `sistema`) lo que no llegó a KaraFun.
- **Cola justa.** `fair_seq = ronda × 10⁶ + llegada`. El pedido nuevo de una mesa va a la ronda siguiente a la última suya, pero nunca antes de la ronda que se está cantando. Así, una mesa que llega tarde canta antes que el segundo turno de las demás. Reordenar en el panel intercambia el `fair_seq` con el vecino.
- **Buffer.** Al decidir un `enqueue` se cuentan las entradas reales de KaraFun (también las puestas a mano) más los pedidos `enviado` que KaraFun aún no muestra, para no pasarse de 3. Un `enviado` confirmado que no aparece en la cola en 90 s pasa a `fallido` con motivo.
- **Cola real → estados.** Un pedido en `cantando` que desaparece pasa a `cantada`. Uno en `en_cola` que desaparece pasa a `retirado` si algo que estaba delante sigue en la cola (lo quitaron a mano), y a `cantada` si no. Si el encargado vacía la cola entera, se ven como `cantada`.
- **Órdenes que ya no hacen falta no se reentregan.** Un `enqueue` cuyo pedido ya apareció en la cola real (el agente se cayó antes de confirmar) queda `done` con `skipped`; reentregarlo la haría sonar dos veces si ya terminó. Lo mismo con una descarga que todas las mesas cancelaron y con un `remove` de algo que ya no está.
- **YouTube.** Un mismo video pedido por varias mesas se descarga una vez; uno ya descargado no se vuelve a bajar. El oEmbed rechaza 400/401/403/404 (no existe o es privado); si YouTube no responde, se acepta y decide el agente.
- **Límites (§11).** Por defecto, editables en el panel: 3 pendientes por mesa, 4 pedidos por minuto por mesa y 6 por IP; 10 códigos equivocados en 10 min bloquean esa IP. La IP se guarda como huella HMAC con una sal en `settings` (`karaoke_salt`, secreta), nunca en claro. Cantante: máximo 30 caracteres, sin caracteres de control ni el separador `·`.
- **Token del agente.** Se genera en el panel y se muestra una sola vez. Está en `SECRET_SETTINGS`, así que nunca vuelve al navegador.
