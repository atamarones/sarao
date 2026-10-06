"""Identidad y lectura del catálogo (contrato §2)."""

from __future__ import annotations

import re
import unicodedata
from collections.abc import Iterable, Iterator
from pathlib import Path

from .karafun import KaraFunClient, Song

PENDING_FOLDER = "Por aprobar"


def normalize(text: str) -> str:
    """Minúsculas, sin tildes, todo lo que no es letra o número pasa a espacio, espacios simples.

    Se usa NFKD (no NFD) a propósito: convierte los caracteres de compatibilidad en su letra base
    («5ª» → «5a», «Nº» → «no», «²» → «2»), que es como la gente los escribe al buscar.
    """
    t = unicodedata.normalize("NFKD", text.casefold())
    t = "".join(ch for ch in t if not unicodedata.combining(ch))
    t = re.sub(r"[^0-9a-z]+", " ", t)
    return " ".join(t.split())


# Las segundas versiones de un archivo se guardan como «v2 Artista - Título» (carpeta Repetidos).
_VERSION_PREFIX = re.compile(r"^\s*v\d{1,2}\s+(?=\S)", re.IGNORECASE)


def clean_artist(artist: str) -> str:
    return _VERSION_PREFIX.sub("", artist).strip()


def is_alt_version(artist: str) -> bool:
    return bool(_VERSION_PREFIX.match(artist))


def natural_key(song: Song) -> str:
    if song.is_local:
        return f"{normalize(clean_artist(song.artist))}|{normalize(song.title)}"
    return f"kf:{song.id}"


def song_payload(song: Song, folder: str | None = None, youtube_id: str | None = None) -> dict:
    return {
        "natural_key": natural_key(song),
        "source": "local" if song.is_local else "karafun",
        "kf_id": None if song.is_local else song.id,
        "title": song.title,
        "artist": clean_artist(song.artist) if song.is_local else song.artist,
        "duration_s": song.duration_s,
        "folder": folder,
        "youtube_id": youtube_id,
    }


def local_catalog_id(kf: KaraFunClient, folder_name: str) -> int:
    for c in kf.catalogs():
        if c["type"] == "localDirectory" and c["name"].strip().casefold() == folder_name.casefold():
            return c["id"]
    raise LookupError(f"KaraFun no tiene la carpeta «{folder_name}» en Mi equipo")


_YT_TAG = re.compile(r"\s*\[([A-Za-z0-9_-]{11})\]\s*$")


def strip_youtube_tag(song: Song) -> tuple[Song, str | None]:
    """Los videos descargados se llaman `Artista - Título [youtube_id].mp4`; KaraFun deja la marca en el título."""
    m = _YT_TAG.search(song.title)
    if not m:
        return song, None
    return Song(id=song.id, title=song.title[: m.start()].strip(), artist=song.artist,
                duration_s=song.duration_s), m.group(1)


def iter_local(kf: KaraFunClient, folder_name: str, pending: dict[str, str]) -> Iterator[dict]:
    """Canciones de la carpeta local, sin duplicados por natural_key.

    Si una canción existe como original y como «v2 …», se queda la entrada original (con su duración).
    `pending` = youtube_id → natural_key de las descargas cuyo archivo sigue en «Por aprobar».
    """
    by_key = {key: yid for yid, key in pending.items()}
    best: dict[str, tuple[bool, dict]] = {}
    for raw in kf.iter_list(local_catalog_id(kf, folder_name), page_delay_s=0.25):
        song, tag = strip_youtube_tag(raw)
        if not song.title.strip():
            continue
        key = natural_key(song)
        alt = is_alt_version(song.artist)
        prev = best.get(key)
        if prev is not None and (alt or not prev[0]):
            continue  # ya hay un original, o ambas son versiones alternativas
        yid = tag or by_key.get(key)
        folder = PENDING_FOLDER if yid and yid in pending else None
        best[key] = (alt, song_payload(song, folder=folder, youtube_id=yid))
    for _alt, payload in best.values():
        yield payload


def iter_online(kf: KaraFunClient) -> Iterator[dict]:
    """Catálogo en línea de KaraFun recorriendo sus categorías («Todas las canciones» nunca responde)."""
    seen: set[int] = set()
    for c in kf.catalogs():
        if c["type"] not in ("onlineStyle", "onlineNews"):
            continue
        for song in kf.iter_list(c["id"], page_delay_s=0.25):
            if song.id in seen or song.is_local:
                continue
            seen.add(song.id)
            yield song_payload(song)


def chunks(items: Iterable[dict], size: int = 500) -> Iterator[list[dict]]:
    batch: list[dict] = []
    for it in items:
        batch.append(it)
        if len(batch) == size:
            yield batch
            batch = []
    if batch:
        yield batch


def find_downloaded(karaoke_dir: Path, youtube_id: str) -> Path | None:
    """Un video ya descargado lleva `[youtube_id]` al final del nombre, esté en la carpeta que esté."""
    suffix = f"[{youtube_id}].mp4"
    for p in karaoke_dir.rglob("*.mp4"):
        if p.name.endswith(suffix):
            return p
    return None
