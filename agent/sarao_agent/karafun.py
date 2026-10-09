"""Cliente del control remoto local de KaraFun Player 2 (WebSocket en 127.0.0.1:57570, mensajes XML).

Comportamientos de KaraFun que este módulo absorbe (vistos en el PC del bar, 2026-10-06):
- Al conectar, KaraFun emite un <status> sin que se lo pidan, y vuelve a emitirlo cada vez que algo cambia
  (también mientras reproduce): antes de cada acción se vacía lo empujado, o se leería una cola vieja.
- Las respuestas no llevan id: se reconocen por la etiqueta raíz (<list>, <catalogList>, <status>).
- KaraFun reinicia su servidor de control cada cierto tiempo y corta la conexión a mitad de una orden:
  toda llamada reconecta y se reintenta una vez.
- Los textos llegan doblemente escapados (`ANA &amp;amp; JAIME`).
- getList devuelve como máximo 100 elementos y su atributo `total` no es fiable.
"""

from __future__ import annotations

import html
import logging
import re
import select
import subprocess
import threading
import time
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path
from xml.sax.saxutils import escape

import websocket

log = logging.getLogger(__name__)

PAGE_SIZE = 100
END_OF_QUEUE = 99999


class KaraFunError(Exception):
    """KaraFun no respondió o respondió algo inesperado."""


@dataclass(frozen=True)
class Song:
    id: int
    title: str
    artist: str
    duration_s: int

    @property
    def is_local(self) -> bool:
        # Las canciones de las carpetas del PC tienen id negativo; las del catálogo en línea, positivo.
        return self.id < 0


@dataclass(frozen=True)
class QueueItem:
    pos: int
    status: str  # playing | ready | …
    title: str
    artist: str
    singer: str
    duration_s: int


@dataclass(frozen=True)
class Status:
    state: str
    queue: tuple[QueueItem, ...]

    def as_dict(self) -> dict:
        return {
            "state": self.state,
            "queue": [
                {"pos": q.pos, "status": q.status, "title": q.title, "artist": q.artist,
                 "singer": q.singer, "duration_s": q.duration_s}
                for q in self.queue
            ],
        }


_STRAY_LT = re.compile(r"<(?![/A-Za-z?!])")
_STRAY_AMP = re.compile(r"&(?!(?:[A-Za-z]+|#\d+|#x[0-9A-Fa-f]+);)")
_CONTROL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f]")


def parse_xml(raw: str) -> ET.Element:
    """KaraFun no escapa `<` ni `&` sueltos dentro de los textos (p. ej. el título "pa ti toa <3")."""
    try:
        return ET.fromstring(raw)
    except ET.ParseError:
        fixed = _CONTROL.sub("", _STRAY_AMP.sub("&amp;", _STRAY_LT.sub("&lt;", raw)))
        return ET.fromstring(fixed)


def clean_singer(name: str, limit: int = 60) -> str:
    """El cantante viaja en un atributo XML: sin comillas, sin < > y sin caracteres de control."""
    t = re.sub(r'["<>\x00-\x1f]', " ", name)
    return " ".join(t.split())[:limit]


def _text(el: ET.Element | None, tag: str) -> str:
    node = el.find(tag) if el is not None else None
    # html.unescape deshace el escape doble de KaraFun; el parser XML ya deshizo el primero.
    return html.unescape(node.text or "") if node is not None else ""


def _seconds(value: str) -> int:
    try:
        return int(round(float(value)))
    except ValueError:
        return 0


def parse_status(root: ET.Element) -> Status:
    items = []
    queue = root.find("queue")
    for it in (queue.findall("item") if queue is not None else []):
        items.append(QueueItem(
            pos=int(it.get("id", "0")),
            status=it.get("status", ""),
            title=_text(it, "title"),
            artist=_text(it, "artist"),
            singer=_text(it, "singer"),
            duration_s=_seconds(_text(it, "duration") or "0"),
        ))
    return Status(state=root.get("state", ""), queue=tuple(items))


def parse_songs(root: ET.Element) -> list[Song]:
    return [
        Song(id=int(it.get("id", "0")), title=_text(it, "title"), artist=_text(it, "artist"),
             duration_s=_seconds(_text(it, "duration") or "0"))
        for it in root.findall("item")
    ]


class KaraFunClient:
    def __init__(self, url: str = "ws://127.0.0.1:57570/", exe: Path | None = None,
                 timeout_s: float = 30.0, retries: int = 1):
        self.url = url
        self.exe = exe
        self.timeout_s = timeout_s
        self.retries = retries
        self._ws: websocket.WebSocket | None = None
        self._lock = threading.RLock()
        self.last_status: Status | None = None
        self.last_status_at = 0.0  # time.monotonic() del último <status> recibido (pedido o empujado)

    # ------------------------------------------------------------------ conexión

    def close(self) -> None:
        with self._lock:
            if self._ws is not None:
                try:
                    self._ws.close()
                except Exception:  # noqa: BLE001 - cerrar nunca debe fallar
                    pass
                self._ws = None

    def _connect(self) -> websocket.WebSocket:
        if self._ws is None:
            ws = websocket.create_connection(self.url, timeout=self.timeout_s)
            self._ws = ws
            # KaraFun saluda con su estado: se consume para no confundirlo con una respuesta.
            self._read_until(lambda tag: tag == "status", deadline=time.monotonic() + 5, required=False)
        return self._ws

    def _read_until(self, want, deadline: float, required: bool = True) -> ET.Element | None:
        ws = self._ws
        assert ws is not None
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                if required:
                    raise KaraFunError("KaraFun no respondió a tiempo")
                return None
            ws.settimeout(remaining)
            try:
                raw = ws.recv()
            except websocket.WebSocketTimeoutException:
                if required:
                    raise KaraFunError("KaraFun no respondió a tiempo") from None
                return None
            if not raw:
                raise KaraFunError("KaraFun cerró la conexión")
            try:
                root = parse_xml(raw)
            except ET.ParseError as exc:
                raise KaraFunError(f"Respuesta XML inválida: {raw[:120]!r}") from exc
            if root.tag == "status":
                self.last_status = parse_status(root)
                self.last_status_at = time.monotonic()
            if want(root.tag):
                return root

    def _drain(self) -> ET.Element | None:
        """Lee, sin esperar, lo que KaraFun empujó mientras nadie leía el socket.

        Mientras reproduce, KaraFun emite <status> por su cuenta; si no se consumen, se apilan y cada
        llamada leería el más viejo de la pila en vez de la respuesta, con la cola de hace minutos.
        Devuelve el último <status> leído (el más reciente), o None si no había ninguno.
        """
        ws = self._ws
        assert ws is not None
        newest = None
        while ws.sock is not None and select.select([ws.sock], [], [], 0)[0]:
            ws.settimeout(self.timeout_s)  # hay datos: se lee el mensaje completo
            raw = ws.recv()
            if not raw:
                raise KaraFunError("KaraFun cerró la conexión")
            try:
                root = parse_xml(raw)
            except ET.ParseError:
                continue
            if root.tag == "status":
                self.last_status = parse_status(root)
                self.last_status_at = time.monotonic()
                newest = root
        return newest

    def _call(self, message: str, want, timeout_s: float | None = None) -> ET.Element:
        """Envía una acción y espera la respuesta cuya raíz cumpla `want`. Reconecta y reintenta."""
        last_exc: Exception | None = None
        for attempt in range(self.retries + 1):
            with self._lock:
                try:
                    ws = self._connect()
                    self._drain()  # lo viejo fuera: la respuesta que se lea será a esta acción
                    ws.send(message)
                    root = self._read_until(want, time.monotonic() + (timeout_s or self.timeout_s))
                    newer = self._drain()
                    # Si tras la respuesta llegó otro <status>, ese es el estado vigente.
                    return newer if newer is not None and root.tag == "status" else root
                except (OSError, websocket.WebSocketException, KaraFunError) as exc:
                    last_exc = exc
                    log.warning("KaraFun: %s (intento %d)", exc, attempt + 1)
                    self.close()
            time.sleep(1.0)
        raise KaraFunError(str(last_exc))

    # ------------------------------------------------------------------ lectura

    def status(self) -> Status:
        root = self._call('<action type="getStatus"></action>', lambda t: t == "status")
        return parse_status(root)

    def search(self, text: str, limit: int = 20) -> list[Song]:
        msg = f'<action type="search" offset="0" limit="{int(limit)}">{escape(text)}</action>'
        return parse_songs(self._call(msg, lambda t: t == "list"))

    def catalogs(self) -> list[dict]:
        root = self._call('<action type="getCatalogList"></action>', lambda t: t == "catalogList")
        return [{"id": int(c.get("id", "0")), "type": c.get("type", ""), "name": (c.text or "").strip()}
                for c in root.findall("catalog")]

    def iter_list(self, catalog_id: int, page_size: int = PAGE_SIZE, page_delay_s: float = 0.0):
        """Recorre un catálogo completo página a página (el `total` de KaraFun no es fiable).

        `page_delay_s` deja respirar a KaraFun entre páginas: atiende el control remoto en el mismo hilo que
        reproduce, y una ráfaga de cientos de peticiones por minuto puede congelarlo.
        """
        offset = 0
        while True:
            if offset and page_delay_s:
                time.sleep(page_delay_s)
            msg = f'<action type="getList" id="{int(catalog_id)}" offset="{offset}" limit="{page_size}"></action>'
            page = parse_songs(self._call(msg, lambda t: t == "list", timeout_s=60))
            yield from page
            if len(page) < page_size:
                return
            offset += page_size

    # ------------------------------------------------------------------ escritura

    def _call_and_wait_status(self, message: str, changed) -> Status:
        """Las órdenes que cambian la cola no tienen respuesta propia: KaraFun emite un <status> nuevo."""
        root = self._call(message, lambda t: t == "status", timeout_s=15)
        st = parse_status(root)
        if not changed(st):
            # El primer <status> puede ser el saludo de una conexión nueva: se confirma con otro.
            st = self.status()
        return st

    def _queue_len_now(self) -> int:
        """Largo de la cola según el último <status>, tras consumir lo que KaraFun haya empujado."""
        with self._lock:
            try:
                self._connect()
                self._drain()
            except (OSError, websocket.WebSocketException, KaraFunError):
                self.close()  # _call reconecta
            return len(self.last_status.queue) if self.last_status else -1

    def add_to_queue(self, song_id: int, singer: str, position: int = END_OF_QUEUE) -> Status:
        before = self._queue_len_now()
        msg = (f'<action type="addToQueue" song="{int(song_id)}" singer="{escape(clean_singer(singer))}">'
               f'{int(position)}</action>')
        return self._call_and_wait_status(msg, lambda st: len(st.queue) != before)

    def clear_queue(self) -> Status:
        st = self._call_and_wait_status('<action type="clearQueue"></action>', lambda s: len(s.queue) == 0)
        if st.queue:
            raise KaraFunError("KaraFun no vació la cola")
        return st

    def remove_from_queue(self, pos: int) -> Status:
        before = self._queue_len_now()
        msg = f'<action type="removeFromQueue" id="{int(pos)}"></action>'
        return self._call_and_wait_status(msg, lambda st: len(st.queue) != before)

    def add_file(self, path: Path, wait_s: float = 20.0) -> Status:
        """Añade un archivo a la cola abriéndolo con KaraFunPlayer.exe (entra al final y sin cantante).

        Sirve para un video recién descargado que KaraFun todavía no tiene indexado.
        """
        if self.exe is None:
            raise KaraFunError("No está configurada la ruta de KaraFunPlayer.exe")
        before = self.status()
        subprocess.Popen([str(self.exe), str(path)], close_fds=True)  # noqa: S603 - ruta controlada
        deadline = time.monotonic() + wait_s
        while time.monotonic() < deadline:
            time.sleep(1.0)
            st = self.status()
            if len(st.queue) > len(before.queue):
                return st
        raise KaraFunError(f"KaraFun no añadió {path.name} a la cola")
