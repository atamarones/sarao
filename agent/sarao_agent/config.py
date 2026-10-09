"""Configuración del agente: agent/config.json (no se versiona) + token en variable de entorno o archivo."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path

AGENT_ROOT = Path(__file__).resolve().parent.parent


@dataclass(frozen=True)
class Config:
    cloud_url: str
    token: str
    karaoke_dir: Path
    pending_dir: Path
    karafun_folder_name: str
    karafun_exe: Path
    karafun_ws: str
    ytdlp: str
    max_duration_s: int
    poll_interval_s: float
    local_sync_time: str      # "HH:MM": sincronización diaria del catálogo local, antes de abrir
    status_interval_s: float  # cada cuánto se pregunta el estado a KaraFun (además de lo que él empuja)
    online_sync_days: int     # 0 = nunca (la nube lo carga desde karafuncatalog.csv); >0 = cada cuántos días
    data_dir: Path

    @property
    def journal_path(self) -> Path:
        return self.data_dir / "agent.db"

    @property
    def tmp_dir(self) -> Path:
        return self.data_dir / "tmp"

    @property
    def log_dir(self) -> Path:
        return self.data_dir / "logs"


def _read_token(data_dir: Path) -> str:
    token = os.environ.get("SARAO_AGENT_TOKEN", "").strip()
    if not token:
        f = data_dir / "token.txt"
        token = f.read_text(encoding="utf-8").strip() if f.exists() else ""
    return token


def load(path: Path | None = None) -> Config:
    path = path or AGENT_ROOT / "config.json"
    raw = json.loads(path.read_text(encoding="utf-8"))
    data_dir = Path(raw.get("data_dir") or AGENT_ROOT / "data")
    karaoke_dir = Path(raw["karaoke_dir"])
    cfg = Config(
        cloud_url=raw["cloud_url"],
        token=_read_token(data_dir),
        karaoke_dir=karaoke_dir,
        pending_dir=Path(raw.get("pending_dir") or karaoke_dir / "Por aprobar"),
        karafun_folder_name=raw.get("karafun_folder_name", "Karaoke"),
        karafun_exe=Path(raw.get("karafun_exe", r"C:\Program Files (x86)\KaraFun Player 2\KaraFunPlayer.exe")),
        karafun_ws=raw.get("karafun_ws", "ws://127.0.0.1:57570/"),
        ytdlp=raw.get("ytdlp", "yt-dlp"),
        max_duration_s=int(raw.get("max_duration_s", 600)),
        poll_interval_s=float(raw.get("poll_interval_s", 2)),
        local_sync_time=raw.get("local_sync_time", "17:00"),
        status_interval_s=float(raw.get("status_interval_s", 5)),
        online_sync_days=int(raw.get("online_sync_days", 0)),
        data_dir=data_dir,
    )
    problems = []
    if not cfg.token:
        problems.append("falta el token del agente (SARAO_AGENT_TOKEN o data/token.txt)")
    if not cfg.karaoke_dir.is_dir():
        problems.append(f"no existe la carpeta de karaoke {cfg.karaoke_dir}")
    if not cfg.karafun_exe.is_file():
        problems.append(f"no existe {cfg.karafun_exe}")
    if problems:
        raise SystemExit("Configuración incompleta: " + "; ".join(problems))
    return cfg
