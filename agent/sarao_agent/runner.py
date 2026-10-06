"""Ciclo principal del agente.

- Hilo principal: latido + estado de KaraFun + acks + recoger órdenes, cada `poll_interval_s`. Nunca se bloquea
  con trabajo largo, para que la nube no dé el agente por desconectado.
- Hilo «cola»: órdenes rápidas sobre la cola de KaraFun (enqueue, remove), en orden de llegada.
- Hilo «lento»: descargas y sincronización de catálogo.
- Hilo «vigilante»: mantiene KaraFun abierto.

Idempotencia (contrato §3): cada orden se anota en el diario ANTES de tocar KaraFun. Si el agente muere a mitad,
al arrancar reconcilia contra la cola real de KaraFun; la nube la vuelve a entregar cuando vence el lease.
"""

from __future__ import annotations

import json
import logging
import queue
import threading
import time
from datetime import datetime, timedelta
from pathlib import Path

from . import __version__
from .catalog import (PENDING_FOLDER, chunks, find_downloaded, iter_local, iter_online, natural_key,
                      normalize, strip_youtube_tag)
from .cloud import CloudClient, CloudError
from .config import Config
from .downloader import Downloader, DownloadError, VideoInfo
from .journal import Journal
from .karafun import KaraFunClient, KaraFunError, Status, clean_singer
from .watchdog import KaraFunWatchdog

log = logging.getLogger(__name__)

FAST_TYPES = {"enqueue", "remove"}
SLOW_TYPES = {"download", "catalog.resync"}


class CommandError(Exception):
    def __init__(self, code: str, message: str, retryable: bool = False):
        super().__init__(message)
        self.code = code
        self.retryable = retryable

    def as_error(self) -> dict:
        return {"code": self.code, "message": str(self), "retryable": self.retryable}


class Agent:
    def __init__(self, cfg: Config, cloud: CloudClient, kf: KaraFunClient, journal: Journal,
                 downloader: Downloader, watchdog: KaraFunWatchdog | None):
        self.cfg = cfg
        self.cloud = cloud
        self.kf = kf
        self.journal = journal
        self.downloader = downloader
        self.watchdog = watchdog
        self.stop = threading.Event()
        self.fast_q: queue.Queue = queue.Queue()
        self.slow_q: queue.Queue = queue.Queue()
        self._working: set[int] = set()
        self._lock = threading.Lock()
        self.kf_ok = watchdog is None  # sin vigilante (pruebas) se asume KaraFun disponible
        self._sync_lock = threading.Lock()
        self._sync_retry_at = 0.0
        # Serializa todo lo que toca la cola de KaraFun: órdenes y vaciado tras un reinicio.
        self._queue_lock = threading.Lock()
        self.kf_session_ok = watchdog is None  # False mientras se atiende un reinicio de KaraFun
        self._state_file = cfg.data_dir / "state.json"
        self.state = self._load_state()

    # ------------------------------------------------------------------ estado persistente

    def _load_state(self) -> dict:
        try:
            # utf-8-sig: tolera el BOM que añade PowerShell si alguien edita el archivo a mano.
            return json.loads(self._state_file.read_text(encoding="utf-8-sig"))
        except (OSError, ValueError):
            return {}

    def _save_state(self) -> None:
        self._state_file.parent.mkdir(parents=True, exist_ok=True)
        tmp = self._state_file.with_suffix(".tmp")
        tmp.write_text(json.dumps(self.state, indent=2), encoding="utf-8")
        tmp.replace(self._state_file)

    # ------------------------------------------------------------------ arranque

    def recover(self) -> None:
        """Órdenes que quedaron «en curso» porque el agente murió a mitad."""
        rows = self.journal.in_progress()
        if not rows:
            return
        try:
            st = self.kf.status()
        except KaraFunError:
            st = None
        for row in rows:
            payload = json.loads(row["payload"])
            if row["type"] == "enqueue" and st is not None:
                item = self._find_in_queue(st, payload)
                if item is not None:
                    log.info("Recuperación: la orden %s ya estaba en la cola de KaraFun", row["id"])
                    self.journal.finish(row["id"], True, {"queue_pos": item.pos, "singer_shown": bool(item.singer)})
                    continue
            # Nada visible en KaraFun: se borra del diario y se ejecutará cuando la nube la reentregue.
            log.info("Recuperación: la orden %s (%s) se repetirá", row["id"], row["type"])
            self.journal.forget(row["id"])

    def start(self) -> list[threading.Thread]:
        self.recover()
        threads = [
            threading.Thread(target=self._worker, args=(self.fast_q,), name="cola", daemon=True),
            threading.Thread(target=self._worker, args=(self.slow_q,), name="lento", daemon=True),
        ]
        if self.watchdog is not None:
            threads.append(threading.Thread(target=self._watch, name="vigilante", daemon=True))
        for t in threads:
            t.start()
        return threads

    def run_forever(self) -> None:
        self.start()
        log.info("Agente %s en marcha contra %s", __version__, self.cfg.cloud_url)
        while not self.stop.is_set():
            started = time.monotonic()
            try:
                self.tick()
            except Exception:  # noqa: BLE001 - el ciclo principal nunca debe morir
                log.exception("Error en el ciclo principal")
            self.stop.wait(max(0.2, self.cfg.poll_interval_s - (time.monotonic() - started)))

    # ------------------------------------------------------------------ ciclo

    def _watch(self) -> None:
        while not self.stop.is_set():
            try:
                self.kf_ok = self.watchdog.ensure()
                if self.kf_ok:
                    self.check_session(self.watchdog.session())
            except Exception:  # noqa: BLE001
                log.exception("Vigilante de KaraFun")
                self.kf_ok = False
            self.stop.wait(5)

    def check_session(self, session: str | None) -> None:
        """KaraFun se reinició (se cayó, lo reabrió el vigilante o es un día nuevo).

        Al arrancar, KaraFun recarga la cola que guardó en disco la última vez que se cerró BIEN (FileQueue.kplst),
        que puede ser de horas antes: canciones ya cantadas y sin las pendientes. Esa cola vieja se vacía y la nube,
        al ver la sesión nueva en el latido, vuelve a enviar los pedidos que estaban en KaraFun, en orden.
        """
        if not session:
            return
        if session == self.state.get("karafun_session"):
            self.kf_session_ok = True
            return
        self.kf_session_ok = False  # la nube no manda órdenes de cola mientras se atiende el reinicio
        with self._queue_lock:
            try:
                self.kf.close()  # la conexión anterior era con el proceso viejo
                st = self.kf.status()
                if st.state == "playing":
                    log.warning("KaraFun se reinició y ya está reproduciendo: no se toca su cola")
                elif st.queue:
                    titles = ", ".join(q.title for q in st.queue[:5])
                    self.kf.clear_queue()
                    log.warning("KaraFun se reinició: se vació la cola vieja que recargó de disco (%d: %s)",
                                len(st.queue), titles)
            except KaraFunError as exc:
                log.warning("KaraFun se reinició pero aún no responde (%s); se reintenta", exc)
                return
            self.state["karafun_session"] = session
            self.state["karafun_started_at"] = datetime.now().astimezone().isoformat(timespec="seconds")
            self._save_state()
            self.kf_session_ok = True
            log.info("Sesión de KaraFun %s lista", session)

    def karafun_state(self) -> dict:
        if not self.kf_ok or not self.kf_session_ok:
            return {"running": self.kf_ok, "connected": False, "state": None, "queue": [],
                    "session": self.state.get("karafun_session")}
        st = self.kf.last_status
        try:
            # KaraFun empuja su estado cuando cambia; solo se le pregunta si lo último es viejo.
            if st is None or time.monotonic() - self.kf.last_status_at > self.cfg.status_interval_s:
                st = self.kf.status()
            return {"running": True, "connected": True, "session": self.state.get("karafun_session"),
                    "started_at": self.state.get("karafun_started_at"), **st.as_dict()}
        except KaraFunError as exc:
            log.warning("No se pudo leer el estado de KaraFun: %s", exc)
            return {"running": True, "connected": False, "state": None, "queue": [],
                    "session": self.state.get("karafun_session")}

    def flush_acks(self) -> None:
        for row in self.journal.pending_acks():
            try:
                self.cloud.call("ack", self.journal.ack_body(row))
                self.journal.mark_acked(row["id"])
            except CloudError as exc:
                if not exc.retryable:
                    log.error("La nube rechazó el ack de %s (%s); se descarta", row["id"], exc)
                    self.journal.mark_acked(row["id"])
                else:
                    log.warning("Ack pendiente de %s: %s", row["id"], exc)
                    return

    def tick(self) -> None:
        self.flush_acks()
        with self._lock:
            working = sorted(self._working)
        body = {
            "agent_version": __version__,
            "karafun": self.karafun_state(),
            "working": working,
            "acks_pending": len(self.journal.pending_acks()),
        }
        try:
            resp = self.cloud.call("poll", body)
        except CloudError as exc:
            log.warning("Sin contacto con la nube: %s", exc)
            return
        for cmd in resp.get("commands") or []:
            self.dispatch(cmd)
        self.schedule_syncs()

    def dispatch(self, cmd: dict) -> None:
        cid, ctype, payload = int(cmd["id"]), str(cmd["type"]), cmd.get("payload") or {}
        with self._lock:
            if cid in self._working:
                return  # sigue en marcha; la nube extiende el lease porque va en `working`
        row = self.journal.get(cid)
        if row is not None and row["status"] in ("done", "failed"):
            # Ya se ejecutó: el ack se perdió por el camino. Se reenvía el mismo resultado, sin repetir la orden.
            self.journal.reset_ack(cid)
            return
        if ctype not in FAST_TYPES | SLOW_TYPES:
            self.journal.begin(cid, ctype, payload)
            self.journal.finish(cid, False, error={"code": "unknown_type", "message": f"Orden desconocida: {ctype}"})
            return
        self.journal.begin(cid, ctype, payload)
        with self._lock:
            self._working.add(cid)
        (self.fast_q if ctype in FAST_TYPES else self.slow_q).put((cid, ctype, payload))

    def _worker(self, q: queue.Queue) -> None:
        while not self.stop.is_set():
            try:
                cid, ctype, payload = q.get(timeout=0.5)
            except queue.Empty:
                continue
            try:
                if ctype in FAST_TYPES:
                    with self._queue_lock:
                        self.execute(cid, ctype, payload)
                else:
                    self.execute(cid, ctype, payload)
            finally:
                with self._lock:
                    self._working.discard(cid)
                q.task_done()

    def execute(self, cid: int, ctype: str, payload: dict) -> None:
        try:
            handler = {
                "enqueue": self.do_enqueue,
                "remove": self.do_remove,
                "download": self.do_download,
                "catalog.resync": self.do_resync,
            }[ctype]
            result = handler(payload)
            self.journal.finish(cid, True, result)
            log.info("Orden %s (%s) hecha: %s", cid, ctype, result)
        except (CommandError, DownloadError) as exc:
            log.warning("Orden %s (%s) falló: %s", cid, ctype, exc)
            self.journal.finish(cid, False, error=exc.as_error())
        except KaraFunError as exc:
            log.warning("Orden %s (%s): KaraFun no disponible: %s", cid, ctype, exc)
            self.journal.finish(cid, False, error={"code": "karafun_unavailable",
                                                   "message": "KaraFun no responde.", "retryable": True})
        except CloudError as exc:
            self.journal.finish(cid, False, error={"code": "cloud_unavailable", "message": str(exc),
                                                   "retryable": exc.retryable})
        except Exception:  # noqa: BLE001
            log.exception("Orden %s (%s): error inesperado", cid, ctype)
            self.journal.finish(cid, False, error={"code": "internal", "message": "Error interno del agente.",
                                                   "retryable": True})

    # ------------------------------------------------------------------ órdenes

    @staticmethod
    def _find_in_queue(st: Status, payload: dict):
        singer = clean_singer(str(payload.get("singer") or ""))
        title = normalize(str((payload.get("song") or {}).get("title") or ""))
        matches = [q for q in st.queue if singer and q.singer == singer]
        if not matches and title:
            # Entradas añadidas por ruta de archivo: llegan sin cantante.
            matches = [q for q in st.queue if not q.singer and normalize(strip_title(q.title)) == title]
        return matches[-1] if matches else None

    def _local_file(self, song: dict) -> Path | None:
        """Plan B cuando KaraFun no encuentra la canción por nombre: su archivo en el PC."""
        rel = str(song.get("file") or "")
        if rel:
            path = (self.cfg.karaoke_dir / rel).resolve()
            # Solo archivos dentro de la carpeta de karaoke: la ruta viene de la nube.
            if is_under(path, self.cfg.karaoke_dir) and path.is_file():
                return path
        yid = song.get("youtube_id")
        if not yid:
            return None
        row = self.journal.download(yid)
        if row is not None and Path(row["file"]).exists():
            return Path(row["file"])
        return find_downloaded(self.cfg.karaoke_dir, yid)

    def do_enqueue(self, payload: dict) -> dict:
        song = payload.get("song") or {}
        singer = clean_singer(str(payload.get("singer") or ""))
        if song.get("source") == "karafun":
            st = self.kf.add_to_queue(int(song["kf_id"]), singer)
        else:
            want = song.get("natural_key")
            found = None
            for cand in self.kf.search(str(song.get("title") or ""), limit=50):
                if cand.is_local and natural_key(strip_youtube_tag(cand)[0]) == want:
                    found = cand
                    break
            if found is not None:
                st = self.kf.add_to_queue(found.id, singer)
            else:
                path = self._local_file(song)
                if path is None:
                    raise CommandError("not_found", "La canción ya no está en el PC del bar.")
                st = self.kf.add_file(path)
                item = st.queue[-1]
                return {"queue_pos": item.pos, "singer_shown": False}
        item = self._find_in_queue(st, payload)
        return {"queue_pos": item.pos if item else len(st.queue) - 1, "singer_shown": True}

    def do_remove(self, payload: dict) -> dict:
        st = self.kf.status()
        item = self._find_in_queue(st, payload)
        if item is None or item.status == "playing":
            return {"removed": False}
        self.kf.remove_from_queue(item.pos)
        return {"removed": True}

    def do_download(self, payload: dict) -> dict:
        yid = str(payload.get("youtube_id") or "")
        max_s = int(payload.get("max_duration_s") or self.cfg.max_duration_s)
        row = self.journal.download(yid)
        if row is not None and Path(row["file"]).exists():
            info = VideoInfo(yid, row["title"], row["artist"], int(row["duration_s"]))
            path = Path(row["file"])
        else:
            existing = find_downloaded(self.cfg.karaoke_dir, yid)
            if existing is not None:
                info = video_info_from_name(existing, yid)
                path = existing
            else:
                info = self.downloader.probe(yid, max_s)
                path = self.downloader.download(info)
            self.journal.save_download(yid, str(path), song_key(info), info.title, info.artist, info.duration_s)
        song = {
            "natural_key": song_key(info),
            "source": "local",
            "kf_id": None,
            "title": info.title,
            "artist": info.artist,
            "duration_s": info.duration_s,
            "folder": PENDING_FOLDER if is_under(path, self.cfg.pending_dir) else None,
            "youtube_id": yid,
        }
        self.cloud.call("song.upsert", {"song": song})
        return {"youtube_id": yid, "song": song}

    def do_resync(self, payload: dict) -> dict:
        source = payload.get("source") or "local"
        return {"total_songs": self.sync_catalog(source)}

    # ------------------------------------------------------------------ catálogo

    def sync_catalog(self, source: str) -> int:
        with self._sync_lock:
            if source == "local":
                pending = {r["youtube_id"]: r["natural_key"] for r in self.journal.downloads()
                           if is_under(Path(r["file"]), self.cfg.pending_dir) and Path(r["file"]).exists()}
                songs = iter_local(self.kf, self.cfg.karafun_folder_name, pending)
            elif source == "karafun":
                songs = iter_online(self.kf)
            else:
                raise CommandError("bad_source", f"Fuente de catálogo desconocida: {source}")
            started = time.monotonic()
            sync_id = self.cloud.call("catalog.begin", {"source": source})["sync_id"]
            total = n_chunks = 0
            for i, batch in enumerate(chunks(songs, 500)):
                self.cloud.call("catalog.chunk", {"sync_id": sync_id, "index": i, "songs": batch})
                total += len(batch)
                n_chunks = i + 1
            self.cloud.call("catalog.commit", {"sync_id": sync_id, "total_chunks": n_chunks, "total_songs": total})
            log.info("Catálogo %s sincronizado: %d canciones en %.0fs", source, total, time.monotonic() - started)
            self.state[f"last_{source}_sync"] = datetime.now().astimezone().isoformat(timespec="seconds")
            self._save_state()
            return total

    def _due(self, source: str, every: timedelta) -> bool:
        last = self.state.get(f"last_{source}_sync")
        if not last:
            return True
        try:
            return datetime.now().astimezone() - datetime.fromisoformat(last) >= every
        except ValueError:
            return True

    def schedule_syncs(self) -> None:
        if (not self.kf_ok or self._sync_lock.locked() or not self.slow_q.empty()
                or time.monotonic() < self._sync_retry_at):
            return
        if self._due("local", timedelta(hours=20)):
            self._spawn_sync("local")
        elif self.cfg.online_sync_days > 0 and self._due("karafun", timedelta(days=self.cfg.online_sync_days)):
            # Desactivado por defecto: son ~2000 peticiones a KaraFun y la nube ya lo carga desde el CSV.
            self._spawn_sync("karafun")

    def _spawn_sync(self, source: str) -> None:
        # Se marca antes de lanzar el hilo para que el siguiente ciclo no lance otro mientras este arranca.
        self._sync_retry_at = time.monotonic() + 10

        def run() -> None:
            try:
                self.sync_catalog(source)
            except Exception as exc:  # noqa: BLE001 - se reintenta más tarde
                log.warning("Sincronización del catálogo %s falló: %s; se reintenta en 5 min", source, exc)
                self._sync_retry_at = time.monotonic() + 300
        threading.Thread(target=run, name=f"sync-{source}", daemon=True).start()


# ---------------------------------------------------------------------- utilidades

def strip_title(title: str) -> str:
    from .karafun import Song
    return strip_youtube_tag(Song(0, title, "", 0))[0].title


def song_key(info: VideoInfo) -> str:
    return f"{normalize(info.artist)}|{normalize(info.title)}"


def is_under(path: Path, folder: Path) -> bool:
    try:
        path.resolve().relative_to(folder.resolve())
        return True
    except ValueError:
        return False


def video_info_from_name(path: Path, yid: str) -> VideoInfo:
    """`Artista - Título [id].mp4` → VideoInfo (cuando el diario no tiene la descarga)."""
    stem = path.stem
    if stem.endswith(f"[{yid}]"):
        stem = stem[: -len(yid) - 2].strip()
    artist, _, title = stem.partition(" - ")
    if not title:
        artist, title = "YouTube", stem
    return VideoInfo(yid, title.strip(), artist.strip(), 0)
