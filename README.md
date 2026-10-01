# El Sarao Pub · sitio web, carta digital y panel

- **Sitio** (`/`): home de karaoke con hero de fotos reales, letra de karaoke animada, rocola «¿Qué cantas hoy?», medidor de voz con el micrófono, semana con horarios y promos, galería, celebraciones, testimonios y cómo llegar. Todos los botones «Reservar» van al formulario de reservas configurado en el panel.
- **Carta** (`/carta/`): para el QR de las mesas.
- **Panel** (`/admin/`): CRUD de productos, categorías, promos, testimonios y ajustes. Lo que cambies aquí se ve al instante en el sitio y en la carta.

- **Stack:** PHP 8.1+ y MySQL/MariaDB. Sin Node, sin compilación y sin dependencias externas. Corre en cualquier plan de hosting compartido de Hostinger.
- **Contenido inicial:** 14 categorías, 54 productos, 94 presentaciones y 3 promos, importados del menú actual de Pirpos. Las 54 fotos están optimizadas en WebP; las 2 cubetas combo, que no tenían foto, llevan una imagen compuesta con las fotos reales de sus botellas.

## Estructura

```
public_html/            ← todo esto se sube a public_html en Hostinger
  index.php             home del sitio
  carta/index.php       carta pública (QR)
  install.php           instalador de un solo uso (bórralo después de instalar)
  admin/                panel (index.php, api.php, logout.php, assets/)
  app/                  núcleo, esquema de BD y seed.json (bloqueado por .htaccess)
  assets/               CSS, JS, fotos del local (img/site), logo y librerías (vendor: GSAP, ScrollTrigger, Lenis)
  uploads/products/     fotos de productos (no ejecuta scripts)
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

## Probar en local (opcional)

```bash
cd public_html
php -d extension=pdo_sqlite install.php --sqlite=../database/sarao.sqlite --user=admin --pass=UnaClaveLocal123
php -d extension=pdo_sqlite -S 127.0.0.1:8000
```

Abre http://127.0.0.1:8000 (sitio), http://127.0.0.1:8000/carta/ (carta) y http://127.0.0.1:8000/admin/ (panel). No subas `app/config.php` ni la carpeta `database/` al servidor: el instalador genera la configuración de producción.

## Copias de seguridad

Hostinger hace copias automáticas. Para una copia manual: hPanel → Bases de datos → phpMyAdmin → Exportar, y descarga también `public_html/uploads/products/`.
