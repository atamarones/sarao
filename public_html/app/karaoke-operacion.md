# Karaoke · operación y solución de problemas

Guía para el encargado. En el día a día **no hay que correr ningún comando**: el agente del PC del bar arranca solo al iniciar sesión en Windows, abre KaraFun, vacía la cola vieja de la noche anterior y sube el catálogo local a la nube. Lo de abajo es para comprobar o resolver problemas.

## Cada noche

1. Encender el PC del bar e **iniciar sesión con el usuario «Sarao Pub»**. El PC no inicia sesión solo, y el agente arranca después de iniciar sesión.
2. Esperar 1–2 minutos: el agente abre KaraFun si está cerrado (abrirlo a mano antes también vale).
3. En el panel → **Karaoke**: el agente debe decir **Conectado**. Si dice otra cosa, ver «En el panel» más abajo.
4. Pulsar **Abrir noche**: el panel genera el **código de 4 números**. Dárselo a las mesas que lo pidan (o escribirlo en un tablero visible). Sin código, las mesas no pueden pedir. Lo genera el panel, no KaraFun: no aparece solo en ninguna pantalla.
5. Al terminar: **Cerrar noche** (se cancelan los pedidos que esperaban; lo que ya está en KaraFun sigue sonando) y apagar Windows normalmente. Si se olvida, la noche se cierra sola a las 14 horas.

## Lo que dicen las mesas

| La mesa ve | Qué pasa | Qué hacer |
|---|---|---|
| «El código de la noche no coincide» | Escribieron mal el código, o se cambió con **Cambiar código**. | Darles el código que aparece en el panel → Karaoke. |
| «El karaoke por mesa no está abierto en este momento» | No hay noche abierta (o se cerró sola a las 14 h). | Panel → Karaoke → **Abrir noche**. |
| «Esta mesa no tiene pedidos de karaoke activos» | La mesa está desactivada en el panel. | Panel → Karaoke → Mesas → activarla. |
| «Este código QR no es válido» | El QR es de una mesa que ya no existe o está dañado. | Reimprimir el QR desde el panel (**Ver e imprimir QR**). |
| «Demasiados intentos con un código equivocado» | Ese celular probó 10 códigos malos en 10 minutos. | Esperar 10 minutos y escribir el código correcto. |
| «Tu mesa ya tiene 3 canciones esperando» | Tope de pedidos por mesa, para que todos canten. | Esperar a que suene una. El tope se cambia en **Ajustes del karaoke**. |
| «Vas muy rápido. Espera un minuto» | Límite de pedidos por minuto por mesa o por celular. | Esperar un minuto. |
| «Karaoke del bar sin conexión» | El agente o KaraFun no están disponibles. Los pedidos **sí se guardan** y entran cuando vuelva. | Ver «Agente desconectado» o «KaraFun no responde». |
| «Eso no es un enlace» / «Solo se aceptan enlaces de YouTube» | Escribieron el nombre de la canción en vez de pegar el enlace. | En YouTube: **Compartir → Copiar enlace** y pegarlo. |
| «Ese video no existe o es privado» | El enlace no lleva a un video público. | Buscar otra versión del video. |
| La canción no aparece al buscar | No está en el catálogo, o escribieron otra cosa. | Probar con el artista. Si no está, pegar el enlace de YouTube. |

## En el panel

| El panel muestra | Qué pasa | Qué hacer |
|---|---|---|
| Agente: **Conectado** | Todo bien. | Nada. |
| Agente: **Agente desconectado** | La nube lleva más de 15 s sin noticias del PC del bar: PC apagado, sesión de Windows sin iniciar, sin internet o el agente detenido. | 1. Revisar que el PC esté encendido, con la sesión «Sarao Pub» iniciada y con internet. 2. Si sigue igual, reiniciar el agente (comando en «En el PC del bar»). |
| Agente: **KaraFun no responde** | El agente está bien, pero KaraFun está cerrado o colgado. | El agente lo reabre solo en 1–2 minutos. Si no, abrir KaraFun a mano. Si aparece el aviso «The application seems to be frozen», pulsar **OK**, nunca «Terminate». |
| Agente: **Desconocido** y aviso rojo arriba | El panel no pudo leer el estado (internet del equipo del encargado o el sitio no responde). No dice nada del bar. | Revisar la conexión de este equipo y recargar la página. |
| Pedido **fallido** con motivo | No se pudo cumplir. Los motivos más comunes: «El video dura más de 8 min», «Ese video no existe», «KaraFun no mostró la canción en la cola». | La mesa ve el motivo y puede pedir otra. Si es «KaraFun no mostró la canción», revisar KaraFun antes de pedirla de nuevo: si sí está en su cola, el pedido vuelve solo a «En cola» en unos segundos y no hay que repetirlo. |
| Pedido **retirado** | Alguien la quitó de la cola en KaraFun, o se pulsó **Quitar de KaraFun** en el panel. | Nada; la mesa lo ve en «Mis pedidos». |
| Hay pedidos **en espera** pero no entran a KaraFun | La nube solo pone en KaraFun la que suena y 2 más; las canciones añadidas a mano en KaraFun también ocupan esos lugares. | Normal: entran solas cuando hay espacio. Si KaraFun está vacío y no entran, ver el estado del agente. |
| **«Por aprobar»** con canciones | Descargas de YouTube que nadie ha revisado. | Revisarlas cuando se pueda (ver «Después de revisar Por aprobar»). |
| Aviso **«Falta la extensión PHP intl»** | El servidor perdió una extensión que usa la búsqueda. | hPanel → Avanzado → Configuración de PHP → activar **intl**. |

## Catálogo

- **Canción nueva en la carpeta del PC** (por ejemplo, después de mover una de «Por aprobar»): actualizar en KaraFun y pulsar **Releer carpeta local** en el panel (unos 4 minutos).
- **Catálogo en línea de KaraFun**: se carga en el panel con **Importar CSV de catálogo** y el archivo `karafuncatalog.csv` que exporta KaraFun (~1 minuto). Hacerlo cuando KaraFun anuncie canciones nuevas.
- Las canciones de la carpeta **«Corregir»** (archivos dañados) no se ofrecen a las mesas.

## Token del agente

El token conecta el PC del bar con la nube. **Solo se genera una vez.** Si se pulsa **Generar token nuevo**, el agente deja de conectarse hasta que se instale el nuevo en el PC del bar:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\Users\Sarao Pub\Desktop\sarao\agent\install.ps1"
```

El instalador pide el token: pegarlo ahí. No enviarlo por chat ni correo.

## En el PC del bar

Comprobar que todo responde; deben salir tres `[ok]` (KaraFun, yt-dlp y la nube):

```powershell
Set-Location "C:\Users\Sarao Pub\Desktop\sarao\agent"; .\.venv\Scripts\python.exe -m sarao_agent --check
```

Ver si el agente está corriendo (debe decir `Running`):

```powershell
Get-ScheduledTask -TaskName 'Sarao - Agente de karaoke' | Select-Object State
```

Ver qué está haciendo (últimas 20 líneas del registro):

```powershell
Get-Content 'C:\Users\Sarao Pub\Desktop\sarao\agent\data\logs\agent.log' -Encoding UTF8 -Tail 20
```

Reiniciar el agente (no toca la cola de KaraFun):

```powershell
powershell -ExecutionPolicy Bypass -File "C:\Users\Sarao Pub\Desktop\sarao\agent\scripts\restart-agent.ps1"
```

Mensajes habituales del registro:

| Mensaje | Qué significa |
|---|---|
| `Sin contacto con la nube` | Sin internet o la nube no responde. Los pedidos esperan en la nube y salen al volver. |
| `KaraFun no respondió a tiempo` / `interrupción de una conexión` aislados | KaraFun reinicia su control remoto de vez en cuando; el agente reconecta solo. |
| `KaraFun mostró el aviso de «congelado»; se pulsó OK` | El agente cerró el aviso que bloquea a KaraFun. |
| `KaraFun se reinició: se vació la cola vieja` | KaraFun se cerró y volvió; la nube reenvía los pedidos pendientes, empezando por quien cantaba. |
| `KaraFun lleva más de 90s sin control remoto y no responde … se reinicia` | KaraFun quedó colgado de verdad y el agente lo reabrió. Si se repite, avisar. |

## Después de revisar «Por aprobar»

Las canciones que las mesas piden por YouTube se descargan solas en `Música\Karaoke\Por aprobar`.

1. Mover a su carpeta de letra las que sirven (corregir el nombre si quedó al revés: YouTube no dice cuál parte es el artista; el formato es `Artista - Título [id].mp4`) y borrar las que no.
2. En KaraFun: clic derecho en **Mi equipo → Karaoke → Actualizar**. **Pocos archivos cada vez**: KaraFun falla si tiene que actualizar cientos de una vez.
3. Subir el catálogo actualizado: botón **Releer carpeta local** en el panel, o en el PC del bar:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\Users\Sarao Pub\Desktop\sarao\agent\scripts\restart-agent.ps1" -ResyncLocal
```

## En KaraFun

- En el buscador **no pulsar Enter**: escribir y esperar los resultados.
- Si aparece **«Error occurred / The application seems to be frozen»**, el agente pulsa OK solo; si lo ves antes, pulsa **OK**, nunca «Terminate».
- No actualizar KaraFun Player 2 sin probar antes: el agente usa su control remoto, que no tiene documentación oficial.

## Si se cae internet o el sitio

- **Sin internet en el bar:** lo que ya está en KaraFun sigue sonando (2–3 canciones). Las mesas siguen pidiendo con sus datos móviles y los pedidos esperan en la nube. Al volver internet, el agente se pone al día solo.
- **El sitio no responde** (las mesas no cargan la página): volver a la hojita y poner las canciones a mano en KaraFun, como antes.

## Ajustes hechos en el PC del bar (una vez, 2026-10-06)

- Componente `plugins\video_filter\libremoteosd_plugin.dll` de KaraFun renombrado a `.desactivado`: era el origen de los cierres por fallo desde 2025. Para deshacerlo, devolverle el nombre original (requiere administrador).
- Volcados de memoria de Windows para KaraFun en `C:\KaraFunDumps` (`agent\scripts\enable-karafun-dumps.ps1`). Si KaraFun se cierra por un fallo, ahí queda el archivo para analizar la causa.
- Detalle técnico de las caídas: `docs/karaoke-arquitectura.md` §14 «Cierres de KaraFun». Agente: `agent/README.md`.
