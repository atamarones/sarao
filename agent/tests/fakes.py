"""Simuladores para probar el agente sin el KaraFun real ni la nube real."""

from __future__ import annotations

import asyncio
import html
import json
import re
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from xml.sax.saxutils import escape

import websockets

LOCAL_DIR_ID = 524289
STYLE_ES = 458755
STYLE_POP = 458766


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def esc2(text: str) -> str:
    """KaraFun escapa dos veces los textos de la cola: `&` llega como `&amp;amp;`."""
    return escape(escape(text))


class FakeKaraFun:
    """Servidor WebSocket que imita el control remoto de KaraFun Player 2."""

    def __init__(self):
        self.port = free_port()
        self.url = f"ws://127.0.0.1:{self.port}/"
        self.local = {  # id → (título, artista, duración)
            -100: ("Decimo Grado", "Ana & Jaime", 187.0),
            -101: ("Quisiera olvidarte", "Adriana Lucia", 279.0),
            -102: ("Quisiera olvidarte", "Adriana Lucia", 279.0),  # archivo repetido
            -103: ("En Los Días Que Te Quise", "Adriana Lucia", 0.0),  # todavía sin analizar
        }
        self.online = {
            76237: ("Caballero", "Alejandro Fernández", 228.0, STYLE_ES),
            112957: ("pa ti toa <3", "Ana Mena & Lola Índigo", 202.0, STYLE_POP),  # KaraFun no escapa el <
            50803: ("Nessun grado di separazione", "Francesca Michielin", 217.0, STYLE_POP),
        }
        self.files: dict[str, tuple[str, str]] = {}  # ruta → (título, artista) de archivos añadidos por ruta
        self.queue: list[dict] = []
        self.received: list[str] = []
        self.drop_next = 0  # cuántas órdenes siguientes se cortan sin responder (reinicio del control remoto)
        self._clients: set = set()
        self._loop: asyncio.AbstractEventLoop | None = None
        self._ready = threading.Event()
        self._thread = threading.Thread(target=self._run, daemon=True)

    # ------------------------------------------------------------ XML
    def status_xml(self) -> str:
        items = "".join(
            f'<item id="{i}" status="{"playing" if q.get("playing") else "ready"}"><title>{esc2(q["title"])}</title>'
            f'<artist>{esc2(q["artist"])}</artist><year></year><duration>{q["duration"]:.2f}</duration>'
            f'<singer>{esc2(q["singer"])}</singer></item>'
            for i, q in enumerate(self.queue)
        )
        state = "playing" if any(q.get("playing") for q in self.queue) else "idle"
        return f'<status state="{state}"><position>0</position><queue>{items}</queue></status>'

    @staticmethod
    def list_xml(rows) -> str:
        # Se escapa el &, pero NO el < (igual que KaraFun).
        items = "".join(
            f'<item id="{sid}"><title>{t.replace("&", "&amp;")}</title><artist>{a.replace("&", "&amp;")}</artist>'
            f'<year></year><duration>{d:.2f}</duration></item>'
            for sid, t, a, d in rows
        )
        return f'<list total="2">{items}</list>'  # total falso a propósito

    def all_songs(self):
        for sid, (t, a, d) in self.local.items():
            yield sid, t, a, d
        for sid, (t, a, d, _s) in self.online.items():
            yield sid, t, a, d

    def handle(self, msg: str) -> str | None:
        typ = re.search(r'type="([^"]+)"', msg).group(1)
        attr = lambda name: (re.search(fr'{name}="([^"]*)"', msg) or [None, None])[1]  # noqa: E731
        body = re.sub(r"^<action[^>]*>|</action>$", "", msg)
        if typ == "getStatus":
            return self.status_xml()
        if typ == "getCatalogList":
            return ('<catalogList><catalog id="458752" type="onlineComplete">Todas las canciones</catalog>'
                    f'<catalog id="{STYLE_ES}" type="onlineStyle">En español</catalog>'
                    f'<catalog id="{STYLE_POP}" type="onlineStyle">Pop</catalog>'
                    f'<catalog id="{LOCAL_DIR_ID}" type="localDirectory">Karaoke</catalog></catalogList>')
        if typ == "search":
            q = body.lower()
            rows = [r for r in self.all_songs() if q in r[1].lower() or q in r[2].lower()]
            return self.list_xml(rows[: int(attr("limit"))])
        if typ == "getList":
            cid, off, lim = int(attr("id")), int(attr("offset")), int(attr("limit"))
            if cid == LOCAL_DIR_ID:
                rows = [(sid, t, a, d) for sid, (t, a, d) in self.local.items()]
            else:
                rows = [(sid, t, a, d) for sid, (t, a, d, s) in self.online.items() if s == cid]
            return self.list_xml(rows[off: off + lim])
        if typ == "addToQueue":
            sid = int(attr("song"))
            t, a, d = next((t, a, d) for s, t, a, d in self.all_songs() if s == sid)
            self.queue.append({"title": t, "artist": a, "duration": d, "singer": html.unescape(attr("singer") or "")})
            return self.status_xml()
        if typ == "clearQueue":
            self.queue.clear()
            return self.status_xml()
        if typ == "removeFromQueue":
            del self.queue[int(attr("id"))]
            return self.status_xml()
        if typ == "__addFile":  # lo envía el «KaraFunPlayer.exe» falso
            t, a = self.files.get(body, (Path(body).stem, ""))
            self.queue.append({"title": t, "artist": a, "duration": 0.0, "singer": ""})
            return self.status_xml()
        return None

    def push_status(self, times: int = 1) -> None:
        """KaraFun empuja <status> sin que se lo pidan (lo hace sin parar mientras reproduce)."""
        async def push():
            for ws in list(self._clients):
                for _ in range(times):
                    await ws.send(self.status_xml())
        asyncio.run_coroutine_threadsafe(push(), self._loop).result(5)

    # ------------------------------------------------------------ servidor
    async def _serve(self, ws):
        self._clients.add(ws)
        try:
            await ws.send(self.status_xml())
            async for msg in ws:
                self.received.append(msg)
                if self.drop_next > 0:
                    self.drop_next -= 1
                    await ws.close()
                    return
                reply = self.handle(msg)
                if reply is not None:
                    await ws.send(reply)
                    if reply.startswith("<status") and "getStatus" not in msg:
                        for other in list(self._clients - {ws}):
                            await other.send(reply)
        except websockets.ConnectionClosed:
            pass
        finally:
            self._clients.discard(ws)

    def _run(self):
        self._loop = asyncio.new_event_loop()
        asyncio.set_event_loop(self._loop)

        self._stopping = self._loop.create_future()

        async def main():
            async with websockets.serve(self._serve, "127.0.0.1", self.port):
                self._ready.set()
                await self._stopping

        self._loop.run_until_complete(main())
        self._loop.close()

    def start(self) -> "FakeKaraFun":
        self._thread.start()
        self._ready.wait(5)
        return self

    def stop(self):
        if self._loop and not self._loop.is_closed():
            self._loop.call_soon_threadsafe(lambda: self._stopping.done() or self._stopping.set_result(None))
            self._thread.join(5)

    def make_exe(self, folder: Path) -> Path:
        """Un «KaraFunPlayer.exe» falso: al recibir una ruta, la añade a la cola como hace el real."""
        script = folder / "fake_karafun_exe.py"
        script.write_text(
            "import sys, websocket\n"
            f"ws = websocket.create_connection('{self.url}')\n"
            "ws.recv()\n"
            "ws.send('<action type=\"__addFile\">' + sys.argv[1] + '</action>')\n"
            "ws.recv()\nws.close()\n",
            encoding="utf-8",
        )
        bat = folder / "KaraFunPlayer.bat"
        bat.write_text(f'@"{sys.executable}" "{script}" %*\n', encoding="utf-8")
        return bat


class FakeCloud:
    """Implementación mínima de docs/karaoke-contrato-agente.md."""

    TOKEN = "t" * 64

    def __init__(self):
        self.port = free_port()
        self.url = f"http://127.0.0.1:{self.port}/karaoke/agent.php"
        self.commands: dict[int, dict] = {}   # id → {type, payload, status, lease_until}
        self.acks: list[dict] = []
        self.polls: list[dict] = []
        self.upserts: list[dict] = []
        self.syncs: dict[str, dict] = {}
        self.committed: dict[str, list[dict]] = {}
        self.lease_s = 30.0
        self.fail_next = 0
        self._lock = threading.Lock()
        cloud = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_POST(self):  # noqa: N802
                if self.headers.get("Authorization") != f"Bearer {cloud.TOKEN}":
                    return self._send(401, {"detail": "token"})
                if cloud.fail_next > 0:
                    cloud.fail_next -= 1
                    return self._send(503, {"detail": "caída simulada"})
                action = self.path.split("action=")[-1]
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])) or b"{}")
                with cloud._lock:
                    status, data = cloud.handle(action, body)
                self._send(status, data)

            def _send(self, status, data):
                raw = json.dumps(data).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

        self.server = ThreadingHTTPServer(("127.0.0.1", self.port), Handler)
        self._thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def add_command(self, cid: int, ctype: str, payload: dict) -> None:
        with self._lock:
            self.commands[cid] = {"type": ctype, "payload": payload, "status": "pending", "lease_until": 0.0}

    def handle(self, action: str, body: dict):
        now = time.monotonic()
        if action == "poll":
            self.polls.append(body)
            for cid in body.get("working", []):
                if cid in self.commands:
                    self.commands[cid]["lease_until"] = now + self.lease_s
            out = []
            for cid, c in sorted(self.commands.items()):
                if c["status"] == "pending" or (c["status"] == "leased" and c["lease_until"] < now):
                    c["status"], c["lease_until"] = "leased", now + self.lease_s
                    out.append({"id": cid, "type": c["type"], "payload": c["payload"]})
            return 200, {"server_time": "", "commands": out[:10]}
        if action == "ack":
            self.acks.append(body)
            c = self.commands.get(body["command_id"])
            if c is None:
                return 404, {"detail": "orden desconocida"}
            if c["status"] not in ("done", "failed"):
                if body["ok"]:
                    c["status"] = "done"
                elif body.get("retryable"):
                    c["status"] = "pending"
                else:
                    c["status"] = "failed"
            return 200, {"ok": True}
        if action == "song.upsert":
            self.upserts.append(body["song"])
            return 200, {"ok": True}
        if action == "catalog.begin":
            sid = f"s{len(self.syncs) + 1}"
            self.syncs[sid] = {"source": body["source"], "chunks": {}}
            return 200, {"sync_id": sid}
        if action == "catalog.chunk":
            self.syncs[body["sync_id"]]["chunks"][body["index"]] = body["songs"]
            return 200, {"ok": True}
        if action == "catalog.commit":
            s = self.syncs[body["sync_id"]]
            songs = [x for i in sorted(s["chunks"]) for x in s["chunks"][i]]
            assert len(songs) == body["total_songs"] and len(s["chunks"]) == body["total_chunks"]
            self.committed[s["source"]] = songs
            return 200, {"ok": True}
        return 404, {"detail": "acción desconocida"}

    def start(self) -> "FakeCloud":
        self._thread.start()
        return self

    def stop(self):
        self.server.shutdown()
