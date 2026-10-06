# Karaoke · operación diaria en el PC del bar

Guía para el encargado. En el día a día **no hay que correr ningún comando**: el agente (`agent/`) arranca solo al iniciar sesión en Windows, abre KaraFun, vacía la cola vieja de la noche anterior y sube el catálogo local a la nube. Los comandos de abajo son solo para comprobar o resolver problemas.

## Al prender

1. Encender el PC e **iniciar sesión con el usuario «Sarao Pub»**. Este PC no inicia sesión automáticamente, y el agente solo arranca después de iniciar sesión.
2. Esperar 1–2 minutos: el agente arranca 30 s después del inicio de sesión y abre KaraFun si está cerrado (abrirlo a mano antes también vale).
3. *(Opcional)* Comprobar que todo responde; deben salir tres `[ok]` (KaraFun, yt-dlp y la nube):

   ```powershell
   Set-Location "C:\Users\Sarao Pub\Desktop\sarao\agent"; .\.venv\Scripts\python.exe -m sarao_agent --check
   ```

## Al apagar

Apagar Windows normalmente; no hace falta nada más. Al día siguiente el agente detecta que KaraFun es una sesión nueva y vacía la cola vieja que KaraFun recarga de disco.

## Si algo falla durante la noche

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
3. Subir el catálogo actualizado a la nube (~3 min):

   ```powershell
   powershell -ExecutionPolicy Bypass -File "C:\Users\Sarao Pub\Desktop\sarao\agent\scripts\restart-agent.ps1" -ResyncLocal
   ```

## En KaraFun

- En el buscador **no pulsar Enter**: escribir y esperar los resultados.
- Si aparece **«Error occurred / The application seems to be frozen»**, el agente pulsa OK solo; si lo ves antes, pulsa **OK**, nunca «Terminate».
- No actualizar KaraFun Player 2 sin probar antes: el agente usa su control remoto, que no tiene documentación oficial.

## Ajustes hechos en este PC (una vez, 2026-10-06)

- Componente `plugins\video_filter\libremoteosd_plugin.dll` de KaraFun renombrado a `.desactivado`: era el origen de los cierres por fallo desde 2025. Para deshacerlo, devolverle el nombre original (requiere administrador).
- Volcados de memoria de Windows para KaraFun en `C:\KaraFunDumps` (`agent\scripts\enable-karafun-dumps.ps1`). Si KaraFun se cierra por un fallo, ahí queda el archivo para analizar la causa.
- Detalle técnico de las caídas: `docs/karaoke-arquitectura.md` §14 «Cierres de KaraFun». Agente: `agent/README.md`.
