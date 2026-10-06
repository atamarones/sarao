"""Mantiene KaraFun Player 2 abierto y usable.

Lo que se ha visto en el PC del bar (2026-10-06):
- Al arrancar puede mostrar «Error occurred / The application seems to be frozen»; ese aviso BLOQUEA la carga
  hasta que alguien pulsa OK. Se pulsa OK por UI Automation (nunca «Terminate»).
- A veces arranca y se queda sin responder; terminarlo y volver a abrirlo lo resuelve.
- Arranque normal: 10–60 s hasta que el control remoto escucha en el puerto 57570.
"""

from __future__ import annotations

import logging
import socket
import subprocess
import time
from pathlib import Path

log = logging.getLogger(__name__)

PROCESS = "KaraFunPlayer.exe"
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)
SCRIPTS = Path(__file__).resolve().parent.parent / "scripts"


def _process_start(pid: int) -> int:
    """Hora de creación del proceso (FILETIME de Windows); 0 si no se puede leer."""
    try:
        import ctypes
        from ctypes import wintypes
        k32 = ctypes.WinDLL("kernel32", use_last_error=True)
        h = k32.OpenProcess(0x1000, False, pid)  # PROCESS_QUERY_LIMITED_INFORMATION
        if not h:
            return 0
        try:
            c, e, k, u = (wintypes.FILETIME() for _ in range(4))
            if not k32.GetProcessTimes(h, ctypes.byref(c), ctypes.byref(e), ctypes.byref(k), ctypes.byref(u)):
                return 0
            return (c.dwHighDateTime << 32) | c.dwLowDateTime
        finally:
            k32.CloseHandle(h)
    except (OSError, AttributeError):
        return 0


def _powershell(script: Path, timeout_s: int = 30) -> str:
    proc = subprocess.run(  # noqa: S603 - script propio, sin shell
        ["powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", str(script)],
        capture_output=True, text=True, timeout=timeout_s, creationflags=NO_WINDOW,
    )
    return (proc.stdout or "").strip()


class KaraFunWatchdog:
    def __init__(self, exe: Path, port: int = 57570, start_timeout_s: int = 150, hang_grace_s: int = 90,
                 hang_confirmations: int = 3):
        self.exe = exe
        self.port = port
        self.start_timeout_s = start_timeout_s
        self.hang_grace_s = hang_grace_s
        self.hang_confirmations = hang_confirmations
        self._failing_since: float | None = None
        self._unresponsive_hits = 0

    def _pid(self) -> int | None:
        out = subprocess.run(
            ["tasklist", "/FI", f"IMAGENAME eq {PROCESS}", "/FO", "CSV", "/NH"],
            capture_output=True, text=True, creationflags=NO_WINDOW,
        ).stdout
        for line in out.splitlines():
            parts = [p.strip('"') for p in line.split('","')]
            if len(parts) > 1 and parts[0].lower() == PROCESS.lower() and parts[1].isdigit():
                return int(parts[1])
        return None

    def is_running(self) -> bool:
        return self._pid() is not None

    def session(self) -> str | None:
        """Identifica la ejecución actual de KaraFun (pid + hora de arranque del proceso).

        Si cambia, KaraFun se reinició: recargó la cola vieja que guardó en disco la última vez que se cerró bien.
        """
        pid = self._pid()
        if pid is None:
            return None
        return f"{pid}:{_process_start(pid)}"

    def port_open(self) -> bool:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(1.0)
            return s.connect_ex(("127.0.0.1", self.port)) == 0

    def dismiss_frozen_dialog(self) -> bool:
        out = _powershell(SCRIPTS / "kf-dismiss-frozen.ps1")
        if "pressed OK" in out:
            log.warning("KaraFun mostró el aviso de «congelado»; se pulsó OK")
            return True
        return False

    def is_responding(self) -> bool:
        out = _powershell(SCRIPTS / "kf-responding.ps1", timeout_s=20)
        return out.strip().lower() == "true"

    def kill(self) -> None:
        subprocess.run(["taskkill", "/F", "/IM", PROCESS], capture_output=True, creationflags=NO_WINDOW)
        time.sleep(3)

    def start_and_wait(self) -> bool:
        if not self.is_running():
            log.info("Abriendo KaraFun Player 2")
            subprocess.Popen([str(self.exe)], close_fds=True)  # noqa: S603
        deadline = time.monotonic() + self.start_timeout_s
        while time.monotonic() < deadline:
            if self.port_open():
                return True
            self.dismiss_frozen_dialog()
            time.sleep(3)
        return False

    def ensure(self) -> bool:
        """Devuelve True si el control remoto de KaraFun está escuchando. Lo abre o lo reinicia si hace falta."""
        if self.port_open():
            self._failing_since = None
            self._unresponsive_hits = 0
            return True
        if not self.is_running():
            return self.start_and_wait()
        # Abierto pero sin control remoto: puede estar arrancando, reiniciando su servidor o colgado.
        if self.dismiss_frozen_dialog():
            self._failing_since = None
            self._unresponsive_hits = 0
            return False
        now = time.monotonic()
        self._failing_since = self._failing_since or now
        if now - self._failing_since <= self.hang_grace_s:
            return False
        # Reiniciar corta la canción que suena: solo si además Windows lo da por colgado varias veces seguidas
        # (un «no responde» aislado es normal mientras carga o analiza archivos).
        if self.is_responding():
            self._unresponsive_hits = 0
            return False
        self._unresponsive_hits += 1
        if self._unresponsive_hits < self.hang_confirmations:
            return False
        log.error("KaraFun lleva más de %ds sin control remoto y no responde (%d comprobaciones): se reinicia",
                  self.hang_grace_s, self._unresponsive_hits)
        self.kill()
        self._failing_since = None
        self._unresponsive_hits = 0
        return self.start_and_wait()
