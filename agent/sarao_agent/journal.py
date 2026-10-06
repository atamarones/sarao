"""Diario local (SQLite): garantiza que una orden de la nube se ejecute una sola vez aunque llegue repetida."""

from __future__ import annotations

import json
import sqlite3
import threading
from datetime import datetime, timedelta
from pathlib import Path

SCHEMA = """
CREATE TABLE IF NOT EXISTS commands (
    id          INTEGER PRIMARY KEY,
    type        TEXT NOT NULL,
    payload     TEXT NOT NULL,
    status      TEXT NOT NULL CHECK (status IN ('in_progress', 'done', 'failed')),
    result      TEXT,
    error       TEXT,
    acked       INTEGER NOT NULL DEFAULT 0,
    started_at  TEXT NOT NULL,
    finished_at TEXT
);
CREATE TABLE IF NOT EXISTS downloads (
    youtube_id  TEXT PRIMARY KEY,
    file        TEXT NOT NULL,
    natural_key TEXT NOT NULL,
    title       TEXT NOT NULL,
    artist      TEXT NOT NULL,
    duration_s  INTEGER NOT NULL,
    created_at  TEXT NOT NULL
);
"""


def _now() -> str:
    return datetime.now().astimezone().isoformat(timespec="seconds")


class Journal:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self._db = sqlite3.connect(str(path), check_same_thread=False, isolation_level=None)
        self._db.row_factory = sqlite3.Row
        self._db.execute("PRAGMA journal_mode = WAL")
        self._db.execute("PRAGMA synchronous = FULL")
        self._db.executescript(SCHEMA)
        self._lock = threading.Lock()

    def close(self) -> None:
        self._db.close()

    # ---------------------------------------------------------------- órdenes

    def get(self, command_id: int) -> sqlite3.Row | None:
        return self._db.execute("SELECT * FROM commands WHERE id = ?", (command_id,)).fetchone()

    def begin(self, command_id: int, ctype: str, payload: dict) -> None:
        """Se escribe ANTES de tocar KaraFun: si el agente muere a mitad, al volver sabe que debe reconciliar."""
        with self._lock:
            self._db.execute(
                "INSERT OR IGNORE INTO commands (id, type, payload, status, started_at) VALUES (?, ?, ?, 'in_progress', ?)",
                (command_id, ctype, json.dumps(payload, ensure_ascii=False), _now()),
            )

    def finish(self, command_id: int, ok: bool, result: dict | None = None, error: dict | None = None) -> None:
        with self._lock:
            self._db.execute(
                "UPDATE commands SET status = ?, result = ?, error = ?, finished_at = ?, acked = 0 WHERE id = ?",
                ("done" if ok else "failed",
                 json.dumps(result, ensure_ascii=False) if result is not None else None,
                 json.dumps(error, ensure_ascii=False) if error is not None else None,
                 _now(), command_id),
            )

    def mark_acked(self, command_id: int) -> None:
        with self._lock:
            self._db.execute("UPDATE commands SET acked = 1 WHERE id = ?", (command_id,))

    def reset_ack(self, command_id: int) -> None:
        """La nube reentregó una orden ya terminada: su ack se perdió y hay que reenviarlo."""
        with self._lock:
            self._db.execute("UPDATE commands SET acked = 0 WHERE id = ?", (command_id,))

    def forget(self, command_id: int) -> None:
        """Quita una orden que quedó a medias sin efecto visible: se ejecutará de nuevo cuando la nube la reentregue."""
        with self._lock:
            self._db.execute("DELETE FROM commands WHERE id = ? AND status = 'in_progress'", (command_id,))

    def pending_acks(self) -> list[sqlite3.Row]:
        return self._db.execute(
            "SELECT * FROM commands WHERE status IN ('done', 'failed') AND acked = 0 ORDER BY id"
        ).fetchall()

    def in_progress(self) -> list[sqlite3.Row]:
        return self._db.execute("SELECT * FROM commands WHERE status = 'in_progress' ORDER BY id").fetchall()

    def ack_body(self, row: sqlite3.Row) -> dict:
        if row["status"] == "done":
            return {"command_id": row["id"], "ok": True, "result": json.loads(row["result"] or "{}")}
        err = json.loads(row["error"] or "{}")
        body = {"command_id": row["id"], "ok": False, "error": err}
        if err.get("retryable"):
            body["retryable"] = True
        return body

    def prune(self, keep_days: int = 30) -> None:
        cutoff = (datetime.now().astimezone() - timedelta(days=keep_days)).isoformat(timespec="seconds")
        with self._lock:
            self._db.execute("DELETE FROM commands WHERE acked = 1 AND finished_at < ?", (cutoff,))

    # ---------------------------------------------------------------- descargas

    def save_download(self, youtube_id: str, file: str, natural_key: str, title: str, artist: str,
                      duration_s: int) -> None:
        with self._lock:
            self._db.execute(
                "INSERT OR REPLACE INTO downloads (youtube_id, file, natural_key, title, artist, duration_s, created_at)"
                " VALUES (?, ?, ?, ?, ?, ?, ?)",
                (youtube_id, file, natural_key, title, artist, duration_s, _now()),
            )

    def download(self, youtube_id: str) -> sqlite3.Row | None:
        return self._db.execute("SELECT * FROM downloads WHERE youtube_id = ?", (youtube_id,)).fetchone()

    def downloads(self) -> list[sqlite3.Row]:
        return self._db.execute("SELECT * FROM downloads").fetchall()
