"""Descarga de videos de YouTube con yt-dlp hacia la carpeta «Por aprobar»."""

from __future__ import annotations

import json
import logging
import re
import shutil
import subprocess
from dataclasses import dataclass
from pathlib import Path

log = logging.getLogger(__name__)

YOUTUBE_ID = re.compile(r"^[A-Za-z0-9_-]{11}$")
# Ruido habitual en los títulos de videos de karaoke.
_NOISE = re.compile(
    r"\s*[\(\[\{][^)\]\}]*(karaoke|lyrics?|letra|instrumental|pista|versi[oó]n|cover|official|oficial|video|audio|hd|4k)"
    r"[^)\]\}]*[\)\]\}]",
    re.IGNORECASE,
)
_TAIL_NOISE = re.compile(
    r"\s*[|\-–—]\s*(karaoke|karafun|letra|lyrics|versi[oó]n karaoke|con letra|instrumental)\b.*$", re.IGNORECASE
)
_FORBIDDEN = re.compile(r'[<>:"/\\|?*\x00-\x1f]')
# Al cliente «web» YouTube le pide a yt-dlp «confirma que no eres un robot»; «mweb» (con un runtime de JS
# como Deno para resolver el desafío) sí entrega los formatos combinados, hasta 360p.
DEFAULT_YTDLP_ARGS = ("--extractor-args", "youtube:player_client=default,mweb")


class DownloadError(Exception):
    def __init__(self, code: str, message: str, retryable: bool = False):
        super().__init__(message)
        self.code = code
        self.retryable = retryable

    def as_error(self) -> dict:
        return {"code": self.code, "message": str(self), "retryable": self.retryable}


@dataclass(frozen=True)
class VideoInfo:
    youtube_id: str
    title: str
    artist: str
    duration_s: int


def clean_title(raw: str) -> str:
    t = _NOISE.sub("", raw)
    t = _TAIL_NOISE.sub("", t)
    return " ".join(t.replace("_", " ").split()).strip(" -–—|·")


def split_artist_title(info: dict) -> tuple[str, str]:
    """Artista y título para el nombre del archivo. Si YouTube trae metadatos de música, se usan."""
    if info.get("artist") and info.get("track"):
        return str(info["artist"]).split(",")[0].strip(), str(info["track"]).strip()
    title = clean_title(str(info.get("title") or ""))
    for sep in (" - ", " – ", " — "):
        if sep in title:
            left, right = title.split(sep, 1)
            if left.strip() and right.strip():
                return left.strip(), clean_title(right)
    return clean_title(str(info.get("uploader") or info.get("channel") or "YouTube")), title or "Sin título"


def safe_name(text: str, limit: int = 80) -> str:
    t = _FORBIDDEN.sub(" ", text)
    t = " ".join(t.split()).strip(" .")
    return t[:limit].rstrip(" .") or "Sin nombre"


class Downloader:
    def __init__(self, ytdlp: str | list[str], karaoke_dir: Path, pending_dir: Path, tmp_dir: Path,
                 max_height: int = 720, timeout_s: int = 900, extra_args: tuple[str, ...] = DEFAULT_YTDLP_ARGS):
        # Ruta del ejecutable o comando con argumentos (p. ej. [python, script] en las pruebas).
        self.ytdlp = [ytdlp] if isinstance(ytdlp, str) else list(ytdlp)
        self.extra_args = list(extra_args)
        self.karaoke_dir = karaoke_dir
        self.pending_dir = pending_dir
        self.tmp_dir = tmp_dir
        self.max_height = max_height
        self.timeout_s = timeout_s

    def _run(self, args: list[str], timeout_s: int) -> subprocess.CompletedProcess:
        try:
            return subprocess.run(  # noqa: S603 - argumentos controlados, sin shell
                [*self.ytdlp, *self.extra_args, *args], capture_output=True, text=True, encoding="utf-8", errors="replace",
                timeout=timeout_s, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            )
        except subprocess.TimeoutExpired:
            raise DownloadError("timeout", "La descarga tardó demasiado.", retryable=True) from None
        except FileNotFoundError:
            raise DownloadError("no_ytdlp", f"No se encontró yt-dlp en {self.ytdlp[0]}.", retryable=True) from None

    def probe(self, youtube_id: str, max_duration_s: int) -> VideoInfo:
        if not YOUTUBE_ID.match(youtube_id):
            raise DownloadError("bad_id", "El identificador del video no es válido.")
        url = f"https://www.youtube.com/watch?v={youtube_id}"
        proc = self._run(["--dump-single-json", "--no-playlist", "--skip-download", "--no-warnings", url], 120)
        if proc.returncode != 0:
            log.error("yt-dlp no pudo consultar %s (%s): %s", youtube_id, proc.returncode, proc.stderr[-800:])
            err = proc.stderr.lower()
            if "not a bot" in err:
                raise DownloadError("blocked", "YouTube bloqueó la descarga en el bar. Pide ayuda en la barra.")
            if "confirm your age" in err or "age-restricted" in err or "inappropriate for some users" in err:
                raise DownloadError("restricted", "El video tiene restricción de edad.")
            if "private" in err or "unavailable" in err or "not available" in err or "removed" in err:
                raise DownloadError("unavailable", "El video no existe o no está disponible.")
            if "sign in" in err:
                raise DownloadError("restricted", "YouTube pide iniciar sesión para ver este video.")
            raise DownloadError("probe_failed", "No se pudo consultar el video en YouTube.", retryable=True)
        info = json.loads(proc.stdout)
        if info.get("is_live") or info.get("live_status") in ("is_live", "is_upcoming", "post_live"):
            raise DownloadError("live", "Es una transmisión en directo; pega el enlace de un video normal.")
        duration = int(info.get("duration") or 0)
        if duration <= 0:
            raise DownloadError("no_duration", "No se pudo saber cuánto dura el video.")
        if duration > max_duration_s:
            raise DownloadError(
                "too_long", f"El video dura {duration // 60} min; el máximo es {max_duration_s // 60} min."
            )
        artist, title = split_artist_title(info)
        return VideoInfo(youtube_id=youtube_id, title=title, artist=artist, duration_s=duration)

    def final_path(self, info: VideoInfo) -> Path:
        return self.pending_dir / f"{safe_name(info.artist, 60)} - {safe_name(info.title, 80)} [{info.youtube_id}].mp4"

    def download(self, info: VideoInfo) -> Path:
        """Descarga a una carpeta temporal y mueve el resultado de una vez: nunca deja un mp4 a medias."""
        self.pending_dir.mkdir(parents=True, exist_ok=True)
        self.tmp_dir.mkdir(parents=True, exist_ok=True)
        for leftover in self.tmp_dir.glob(f"{info.youtube_id}.*"):
            leftover.unlink(missing_ok=True)
        h = self.max_height
        fmt = f"bv*[height<={h}][ext=mp4]+ba[ext=m4a]/b[height<={h}][ext=mp4]/bv*[height<={h}]+ba/b[height<={h}]"
        out = self.tmp_dir / f"{info.youtube_id}.%(ext)s"
        proc = self._run(
            ["-f", fmt, "--merge-output-format", "mp4", "--remux-video", "mp4", "--no-playlist",
             "--no-warnings", "--no-progress", "-o", str(out), f"https://www.youtube.com/watch?v={info.youtube_id}"],
            self.timeout_s,
        )
        produced = self.tmp_dir / f"{info.youtube_id}.mp4"
        if proc.returncode != 0 or not produced.exists() or produced.stat().st_size < 100_000:
            log.error("yt-dlp falló (%s): %s", proc.returncode, proc.stderr[-800:])
            raise DownloadError("download_failed", "No se pudo descargar el video.", retryable=True)
        target = self.final_path(info)
        shutil.move(str(produced), str(target))
        return target
