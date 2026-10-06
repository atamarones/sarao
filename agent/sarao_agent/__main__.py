"""Arranque: `python -m sarao_agent [--config ruta] [--check]`."""

from __future__ import annotations

import argparse
import logging
import sys
from logging.handlers import RotatingFileHandler
from pathlib import Path

from . import __version__
from .cloud import CloudClient, CloudError
from .config import load
from .downloader import Downloader
from .journal import Journal
from .karafun import KaraFunClient, KaraFunError
from .runner import Agent
from .watchdog import KaraFunWatchdog


def setup_logging(log_dir: Path) -> None:
    log_dir.mkdir(parents=True, exist_ok=True)
    fmt = logging.Formatter("%(asctime)s %(levelname)s [%(threadName)s] %(name)s: %(message)s")
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    fh = RotatingFileHandler(log_dir / "agent.log", maxBytes=5_000_000, backupCount=5, encoding="utf-8")
    fh.setFormatter(fmt)
    root.addHandler(fh)
    if sys.stderr is not None:  # con pythonw no hay consola
        sh = logging.StreamHandler()
        sh.setFormatter(fmt)
        root.addHandler(sh)
    logging.getLogger("websocket").setLevel(logging.WARNING)


def check(cfg) -> int:
    """Diagnóstico rápido para la instalación: KaraFun, yt-dlp y la nube."""
    ok = True
    kf = KaraFunClient(cfg.karafun_ws, cfg.karafun_exe)
    try:
        st = kf.status()
        print(f"[ok] KaraFun responde (estado {st.state}, {len(st.queue)} en cola)")
    except KaraFunError as exc:
        ok = False
        print(f"[!!] KaraFun no responde: {exc}")
    finally:
        kf.close()
    import subprocess
    try:
        v = subprocess.run([cfg.ytdlp, "--version"], capture_output=True, text=True, timeout=30).stdout.strip()
        print(f"[ok] yt-dlp {v}")
    except (OSError, subprocess.TimeoutExpired) as exc:
        ok = False
        print(f"[!!] yt-dlp no disponible: {exc}")
    try:
        CloudClient(cfg.cloud_url, cfg.token).call("poll", {"agent_version": __version__, "karafun": {},
                                                           "working": [], "acks_pending": 0, "check": True})
        print(f"[ok] La nube responde en {cfg.cloud_url}")
    except CloudError as exc:
        ok = False
        print(f"[!!] La nube no responde: {exc}")
    return 0 if ok else 1


def main() -> int:
    p = argparse.ArgumentParser(prog="sarao_agent")
    p.add_argument("--config", type=Path)
    p.add_argument("--check", action="store_true", help="comprueba KaraFun, yt-dlp y la nube, y sale")
    args = p.parse_args()
    cfg = load(args.config)
    if args.check:
        return check(cfg)
    setup_logging(cfg.log_dir)
    journal = Journal(cfg.journal_path)
    journal.prune()
    agent = Agent(
        cfg,
        CloudClient(cfg.cloud_url, cfg.token),
        KaraFunClient(cfg.karafun_ws, cfg.karafun_exe),
        journal,
        Downloader(cfg.ytdlp, cfg.karaoke_dir, cfg.pending_dir, cfg.tmp_dir),
        KaraFunWatchdog(cfg.karafun_exe),
    )
    try:
        agent.run_forever()
    except KeyboardInterrupt:
        agent.stop.set()
    return 0


if __name__ == "__main__":
    sys.exit(main())
