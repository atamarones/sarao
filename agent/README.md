# Agente del bar

Programa que corre en el PC del karaoke y conecta los pedidos de las mesas (nube, `https://saraopub.com/karaoke/agent.php`) con **KaraFun Player 2**. Solo hace conexiones salientes por HTTPS; no hay que abrir puertos en el router. Contrato con la nube: `docs/karaoke-contrato-agente.md`.

## Qué hace

- **Cada 2 s** avisa a la nube que está vivo, le manda la cola real de KaraFun y recoge órdenes.
- **Encola** canciones en KaraFun con el nombre del cantante (canciones locales y del catálogo en línea).
- **Descarga** los enlaces de YouTube que piden las mesas (máx. 10 min, mp4; hoy YouTube solo entrega hasta 360p sin iniciar sesión) a `Música\Karaoke\Por aprobar\Artista - Título [id].mp4` y los mete en la cola por ruta de archivo, sin esperar a que KaraFun los indexe (en ese caso entran **sin nombre de cantante**).
- **Sube el catálogo**: el local al arrancar y cada día (~2,5 min para 3759 canciones) y el de KaraFun en línea cada 7 días (~89 000 canciones, ~2 min).
- **Vigila KaraFun**: lo abre si está cerrado, pulsa OK en el aviso «The application seems to be frozen» que bloquea el arranque, y lo reinicia si deja de responder más de 90 s.
- Nunca ejecuta dos veces la misma orden (diario local en `data/agent.db`) y, si se cae a mitad de una, al volver revisa la cola real de KaraFun antes de repetirla.

## Instalar (una vez)

Requisitos ya instalados en el PC del bar: Python 3.12, yt-dlp (con FFmpeg) y KaraFun Player 2.

yt-dlp necesita además **Deno** para resolver el desafío de JavaScript de YouTube; sin él, YouTube responde «confirma que no eres un robot» y ningún video se descarga. Instalarlo y mantener yt-dlp al día (YouTube cambia a menudo):

```powershell
winget install DenoLand.Deno
yt-dlp -U    # o: winget upgrade yt-dlp.yt-dlp
```

Después de instalar Deno, reinicia la tarea del agente (`agent\scripts\restart-agent.ps1`) para que tome el PATH nuevo.

```powershell
powershell -ExecutionPolicy Bypass -File agent\install.ps1
```

1. Pide el **token del agente**: lo genera el panel (Karaoke → Agente). Pégalo cuando lo pida; se guarda en `agent\data\token.txt`, que solo puede leer este usuario de Windows. **No lo envíes por chat ni lo pongas en el repositorio.**
2. Crea `agent\config.json` desde `config.example.json` (rutas de este PC y URL de la nube).
3. Programa la tarea «Sarao - Agente de karaoke»: arranca 30 s después de iniciar sesión en Windows y se reinicia sola si se cae.

Comprobar que todo responde (KaraFun, yt-dlp y la nube):

```powershell
agent\.venv\Scripts\python.exe -m sarao_agent --check
```

Cambiar el token: `install.ps1 -SetToken`. Quitar el arranque automático: `install.ps1 -Uninstall`.

## Uso diario

Nada: al encender el PC e iniciar sesión, el agente arranca y abre KaraFun si hace falta. El encargado solo revisa de vez en cuando `Música\Karaoke\Por aprobar`:
- si el video sirve, lo mueve a su carpeta de letra (y corrige el nombre si quedó al revés: YouTube no dice cuál parte es el artista);
- si no sirve, lo borra;
- después, en KaraFun: clic derecho en **Mi equipo → Karaoke → Actualizar**. **No mover cientos de archivos de una vez**: KaraFun falla al quitar muchas canciones del índice a la vez (pasó el 6 de octubre de 2026).

En el buscador de KaraFun **no pulsar Enter**: escribir y esperar los resultados.

## Exportar el catálogo local a CSV

Para cargarlo en la nube junto con `karafuncatalog.csv` (mismo formato, columnas extra `Duration;Folder;File;NaturalKey;YoutubeId`):

```powershell
agent\.venv\Scripts\python.exe agent\export_local_catalog.py "$HOME\Downloads\karaoke-catalogo-local.csv"
```

Con KaraFun abierto añade las duraciones y compara con lo que KaraFun tiene indexado (~2,5 min). Une los archivos repetidos y omite los dañados (< 100 KB).

## Registro y problemas

- Registro: `agent\data\logs\agent.log` (rota a los 5 MB, guarda 5).
- Estado del catálogo: `agent\data\state.json` (última sincronización local y en línea).
- «Sin contacto con la nube»: revisar internet; los pedidos esperan en la nube y salen al volver.
- «KaraFun no responde»: el agente lo reinicia solo; si se repite, cerrar KaraFun a mano y dejar que el agente lo abra.

## Seguridad recomendada (requiere administrador, una vez)

KaraFun deja su control remoto (puerto 57570) abierto a toda la red y sin contraseña: cualquiera en el WiFi del bar podría manejar la cola. El agente solo lo usa desde el propio PC, así que conviene bloquearlo para la red:

```powershell
New-NetFirewallRule -DisplayName 'KaraFun: control remoto solo local' -Direction Inbound -Protocol TCP -LocalPort 57570 -Action Block
```

(El tráfico desde el mismo PC no pasa por el firewall, así que el agente sigue funcionando. Si el bar usa la app de KaraFun en un celular conectado por WiFi para manejar la cola, esa app dejará de funcionar.)

## Desarrollo

```powershell
cd agent
.venv\Scripts\python.exe -m pip install -r requirements-dev.txt
.venv\Scripts\python.exe -m unittest discover -s tests -v
```

Las pruebas usan un KaraFun y una nube simulados (`tests/fakes.py`) que imitan sus manías reales: textos doblemente escapados, `<` sin escapar («pa ti toa <3»), `total` falso en las listas y cortes del control remoto.
