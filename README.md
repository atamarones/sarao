# El Sarao Pub · sitio web, carta digital y panel

- **Sitio** (`/`): home de karaoke con hero de fotos reales, letra de karaoke animada, rocola «¿Qué cantas hoy?», medidor de voz con el micrófono, semana con horarios y promos, galería, celebraciones, testimonios y cómo llegar. Todos los botones «Reservar» van al formulario de reservas configurado en el panel.
- **Carta** (`/carta/`): para el QR de las mesas.
- **Panel** (`/admin/`): CRUD de productos, categorías, promos, testimonios y ajustes. Lo que cambies aquí se ve al instante en el sitio y en la carta.
- **Karaoke por mesa** (`/karaoke/`): cada mesa escanea su QR, escribe el código de la noche y pide canciones desde el celular. Un agente en el PC del bar las pone en KaraFun (ver [Karaoke por mesa](#karaoke-por-mesa)).

- **Stack:** PHP 8.1+ y MySQL/MariaDB. Sin Node, sin compilación y sin dependencias externas. Corre en cualquier plan de hosting compartido de Hostinger.
- **Contenido inicial:** 14 categorías, 54 productos, 94 presentaciones y 3 promos, importados del menú actual de Pirpos. Las 54 fotos están optimizadas en WebP; las 2 cubetas combo, que no tenían foto, llevan una imagen compuesta con las fotos reales de sus botellas.

## Estructura

```
public_html/            ← todo esto se sube a public_html en Hostinger
  index.php             home del sitio
  carta/index.php       carta pública (QR)
  karaoke/              página de mesa (index.php + api.php) y API del agente del bar (agent.php)
  install.php           instalador de un solo uso (bórralo después de instalar)
  admin/                panel (index.php, api.php, logout.php, assets/)
  app/                  núcleo, esquema de BD y seed.json (bloqueado por .htaccess)
  assets/               CSS, JS, fotos del local (img/site), logo y librerías (vendor: GSAP, ScrollTrigger, Lenis)
  uploads/products/     fotos de productos (no ejecuta scripts)
tools/test_karaoke.php  pruebas de la lógica del karaoke (SQLite en memoria, o MySQL de pruebas)
tools/fake_agent.php    simulador del agente del PC del bar para probar punta a punta
tools/build_seed.php    regenera seed.json e imágenes desde el export de Pirpos (solo en local;
                        requiere tools/pirpos-menu.json, que no se versiona)
```

## Publicar en Hostinger

1. **SSL:** en hPanel → Seguridad → SSL, activa el certificado del dominio. El `.htaccess` redirige todo a HTTPS.
2. **PHP:** en hPanel → Avanzado → Configuración de PHP, elige PHP 8.1 o superior. Verifica que estén activas las extensiones `pdo_mysql`, `gd` (con WebP) y `fileinfo`. Vienen activas por defecto.
3. **Base de datos:** en hPanel → Bases de datos → Administración, crea una base MySQL con su usuario y contraseña. Anota los tres datos; el host es `localhost`.
4. **Subir archivos:** en el Administrador de archivos, sube el **contenido** de `public_html/` dentro de `public_html/` del dominio (o de un subdominio, por ejemplo `carta.saraopub.com`).
5. **Instalar:** abre `https://tu-dominio/install.php`, pega los datos de la base y crea el usuario administrador (contraseña de 10 caracteres o más). El instalador crea las tablas y carga el menú actual.
6. **Borrar el instalador:** elimina `install.php` desde el Administrador de archivos. Aunque queda bloqueado solo, no conviene dejarlo en el servidor.
7. **QR:** apunta el QR de las mesas a `https://tu-dominio/carta/`.

El panel queda en `https://tu-dominio/admin/`.

## Uso del panel

| Sección | Qué permite |
|---|---|
| Productos | Crear, editar, duplicar, eliminar, ocultar/mostrar, destacar en «Los más pedidos» y cambiar el orden. Cada producto tiene precio único o varias presentaciones (trago, media, botella, michelada, cubeta…) con detalle, nota («Solo miércoles») y precio propio. Fotos JPG/PNG/WebP de hasta 8 MB: se reescalan y convierten a WebP. |
| Categorías | Crear, renombrar, ocultar, ordenar y eliminar (solo si están vacías). El orden de la lista es el orden de la carta. |
| Promos | Promos por día y franja horaria. La del día sale marcada como «Hoy» en la carta. |
| Testimonios | Crear, editar, ocultar, ordenar y borrar reseñas (nombre, texto, estrellas y fuente). |
| Ajustes | Nombre, frase, dirección, teléfono, WhatsApp, Instagram, TikTok, Facebook, correo, enlace de reservas, enlace a reseñas de Google, número de canciones, aviso destacado, horario semanal y cambio de contraseña. |

La carta muestra «Abierto · hasta las 3 a. m.» o «Cerrado · abre hoy a las 6 p. m.» según el horario de Ajustes. Usa la hora de Bogotá y admite cierres después de medianoche.

## Seguridad

- Contraseñas con bcrypt (cost 12). Sesión con cookie `HttpOnly`, `Secure` y `SameSite=Strict`, que expira tras 8 horas sin uso.
- Máximo 5 intentos de acceso cada 15 minutos por IP.
- Token CSRF en todas las escrituras. Consultas siempre preparadas (PDO).
- Cabeceras CSP, HSTS, `X-Frame-Options` y `nosniff`. Todos los scripts se sirven desde el propio dominio (sin CDNs de JavaScript).
- El micrófono solo se permite en la home (`Permissions-Policy`) y el audio se analiza en el navegador: no se graba ni se envía.
- Cada foto subida se valida por contenido y se vuelve a codificar a WebP, lo que elimina metadatos y cualquier código incrustado. `uploads/` no ejecuta scripts.
- `app/` (configuración con credenciales) está bloqueada desde el navegador.
- Registro de auditoría (`audit_log`): quién creó, editó o borró qué y cuándo.
- Karaoke: la API del agente exige token Bearer de 64 hex (comparación en tiempo constante, solo HTTPS); el token nunca vuelve al navegador. Las mesas entran con el token del QR más el código de la noche (10 intentos fallidos por 10 min bloquean). Nombres de cantante y títulos se pintan como texto, nunca como HTML. Para los límites por celular se guarda una huella HMAC de la IP, no la IP.

## Precios desde el POS (angelo-pos)

El catálogo y los precios se administran en el sistema operativo (angelo-pos) y el sitio los recibe solo. En Ajustes → «Precios desde el POS» se configura la URL del POS, el id de la tienda y el token del feed (`MENU_FEED_TOKEN`). La carta sincroniza en segundo plano como mucho una vez por hora, y el botón «Sincronizar ahora» lo fuerza. Los productos nuevos del POS llegan **ocultos**: en Productos se les pone foto y se activan. El sitio nunca borra productos ni los muestra por su cuenta; si el POS desactiva uno, aquí se oculta. Detalle del contrato y reglas: `docs/unificacion-pos-carta.md`.

## Probar en local (opcional)

```bash
cd public_html
php -d extension=pdo_sqlite install.php --sqlite=../database/sarao.sqlite --user=admin --pass=UnaClaveLocal123
php -d extension=pdo_sqlite -S 127.0.0.1:8000
```

Abre http://127.0.0.1:8000 (sitio), http://127.0.0.1:8000/carta/ (carta) y http://127.0.0.1:8000/admin/ (panel). No subas `app/config.php` ni la carpeta `database/` al servidor: el instalador genera la configuración de producción.

## Karaoke por mesa

Diseño completo en `docs/karaoke-arquitectura.md`; el acuerdo con el agente del PC del bar, en `docs/karaoke-contrato-agente.md` (versión 2). La nube guarda los pedidos y decide el orden; el agente (lo construye otra persona según el contrato) solo ejecuta órdenes en KaraFun Player 2.

### Instalación

1. Sube los archivos como siempre (incluida la carpeta `karaoke/` con su `.htaccess`, que deja pasar la cabecera `Authorization` del agente en Hostinger).
2. Abre el panel: al cargar crea solas las tablas `karaoke_*` (también en una base instalada antes de esta versión). En una instalación nueva las crea `install.php`.
3. Panel → **Karaoke** → **Generar token nuevo**. Cópialo en la configuración del agente: se muestra una sola vez. El agente llama a `https://tu-dominio/karaoke/agent.php?action=…` con `Authorization: Bearer <token>`.
4. El catálogo lo sube el agente (la carpeta local y el catálogo en línea de KaraFun, por separado). Mientras tanto, o para ordenar por popularidad, el panel puede **Importar CSV de KaraFun** con el archivo que exporta KaraFun (`Id;Title;Artist;…`, unas 90 mil canciones, ~1 minuto); usa las mismas claves `kf:<id>` que el agente. Si Hostinger rechaza el archivo por tamaño, sube `upload_max_filesize` y `post_max_size` a 16M en hPanel → Configuración de PHP.
5. La comprobación de que un enlace de YouTube existe usa `curl` hacia `youtube.com` (activo por defecto en Hostinger). Si no responde, el pedido se acepta y el agente lo valida al descargar.

### Uso en el bar

| Paso | Dónde |
|---|---|
| Crear las mesas (número y nombre) e imprimir sus QR | Panel → Karaoke → Mesas → **Ver e imprimir QR** (o «Imprimir los QR de todas las mesas activas»). El QR no cambia al renombrar la mesa. |
| Abrir la noche | **Abrir noche** genera un código de 4 números. Muéstralo en la pantalla del karaoke: sin él, un QR fotografiado no sirve desde la casa. La noche se cierra sola a las 14 h. |
| Durante la noche | La pestaña se actualiza cada 5 s: lo que está en KaraFun, la lista de espera (flechas para adelantar o atrasar, **Cancelar**), los fallidos con su motivo, el estado del agente y cuántas canciones hay en «Por aprobar». |
| Si el código se filtra | **Cambiar código**: los celulares lo vuelven a pedir. |
| Al terminar | **Cerrar noche**: se cancelan los pedidos que esperaban; lo que ya está en KaraFun sigue sonando. |

Las mesas buscan por canción o artista (primero la carpeta local del bar, luego el catálogo en línea de KaraFun), piden con el nombre de quien canta y ven su turno estimado. Si la canción no está, pegan un enlace de YouTube: solo se acepta un enlace de video (`youtube.com/watch?v=`, `youtu.be/`, `/shorts/`), nunca texto libre. Límites por defecto, editables en el panel: 3 canciones esperando por mesa, 4 pedidos por minuto por mesa y 6 por celular. KaraFun recibe como mucho la canción que suena y 2 más; la rotación entre mesas vive en la nube.

### Probar el karaoke en local

```bash
php -d extension=pdo_sqlite tools/test_karaoke.php        # lógica completa sobre SQLite en memoria
# Opcional, sobre una base MySQL/MariaDB DE PRUEBAS (borra sus tablas):
# KARAOKE_TEST_DSN="mysql:host=127.0.0.1;port=3306;dbname=pruebas" KARAOKE_TEST_USER=root KARAOKE_TEST_PASS=… php tools/test_karaoke.php

cd public_html && php -d extension=pdo_sqlite -S 127.0.0.1:8000     # servidor local (ver «Probar en local»)
# En el panel local: Karaoke → crear mesas, Abrir noche y Generar token. Luego, en otra terminal:
php tools/fake_agent.php --url=http://127.0.0.1:8000 --token=<token> --catalog=300 --seconds=120 --song-seconds=15
```

Abre el enlace del QR de una mesa (`/karaoke/?m=…`), escribe el código de la noche y pide canciones: el simulador las pone en su KaraFun de mentira, las «canta» y el panel muestra los cambios. `--karafun-catalog=N` sube además un catálogo en línea simulado; `--download-seconds=N` hace que cada descarga tarde N s (la reporta en `working`); `--no-singer-for-downloads` hace que lo recién descargado entre sin cantante; `--drop-ack-once` simula que el agente se cae antes de confirmar una orden y `--fail-download=<id>` hace fallar una descarga de YouTube.

## Copias de seguridad

Hostinger hace copias automáticas. Para una copia manual: hPanel → Bases de datos → phpMyAdmin → Exportar, y descarga también `public_html/uploads/products/`.
