"""Exporta el catálogo local (Música\\Karaoke) a CSV con el mismo formato que karafuncatalog.csv.

Uso (desde agent/):
    .venv\\Scripts\\python.exe export_local_catalog.py [salida.csv] [--karaoke-dir RUTA] [--no-karafun]

Columnas: las 9 de KaraFun (Id;Title;Artist;Year;Duo;Explicit;"Date Added";Styles;Languages) y además
Duration;Folder;File;NaturalKey;YoutubeId, separadas por «;», UTF-8.

- Título y artista salen del nombre del archivo («Artista - Título.mp4»), igual que los muestra KaraFun.
- NaturalKey es la identidad que usan la nube y el agente (docs/karaoke-contrato-agente.md); Id es un hash
  estable de ella con prefijo L para no chocar con los ids numéricos de KaraFun.
- La duración se toma de KaraFun si está abierto (sin --no-karafun); si no, queda vacía.
- Archivos repetidos (mismo artista y título, ignorando el prefijo «v2 » de las segundas versiones) salen una
  sola vez; se prefiere el original, que no está en Repetidos/Corregir.
- Se omiten archivos dañados (< 100 KB).
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from sarao_agent.catalog import (PENDING_FOLDER, clean_artist, is_alt_version, local_catalog_id,  # noqa: E402
                                 natural_key, strip_youtube_tag)
from sarao_agent.karafun import KaraFunClient, KaraFunError, Song  # noqa: E402

MEDIA = {".mp4", ".mkv", ".avi", ".mov", ".wmv", ".mpg", ".mpeg", ".mp3", ".kfn", ".cdg", ".zip", ".kar", ".mid"}
LOW_PRIORITY = {"repetidos", "corregir"}
HEADER = ["Id", "Title", "Artist", "Year", "Duo", "Explicit", "Date Added", "Styles", "Languages",
          "Duration", "Folder", "File", "NaturalKey", "YoutubeId"]


def split_name(stem: str) -> tuple[str, str]:
    """«Artista - Título» → (artista, título). KaraFun corta en el primer « - »; sin separador repite el nombre."""
    artist, sep, title = stem.partition(" - ")
    if not sep or not title.strip():
        return stem.strip(), stem.strip()
    return artist.strip(), title.strip()


def karafun_local(kf: KaraFunClient, folder_name: str) -> tuple[set[str], dict[str, int]]:
    """Claves que KaraFun tiene en su carpeta local y la duración que conoce de cada una."""
    keys: set[str] = set()
    durations: dict[str, int] = {}
    for raw in kf.iter_list(local_catalog_id(kf, folder_name)):
        key = natural_key(strip_youtube_tag(raw)[0])
        keys.add(key)
        if raw.duration_s and key not in durations:
            durations[key] = raw.duration_s
    return keys, durations


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("out", nargs="?", default=str(Path.home() / "Downloads" / "karaoke-catalogo-local.csv"))
    ap.add_argument("--karaoke-dir", default=str(Path.home() / "Music" / "Karaoke"))
    ap.add_argument("--folder-name", default="Karaoke", help="nombre de la carpeta en KaraFun (Mi equipo)")
    ap.add_argument("--no-karafun", action="store_true", help="no consultar duraciones a KaraFun")
    args = ap.parse_args()
    root = Path(args.karaoke_dir)

    durations: dict[str, int] = {}
    kf_keys: set[str] = set()
    if not args.no_karafun:
        kf = KaraFunClient()
        try:
            kf_keys, durations = karafun_local(kf, args.folder_name)
        except (KaraFunError, LookupError, OSError) as exc:
            print(f"Aviso: KaraFun no disponible ({exc}); duraciones vacías.", file=sys.stderr)
        finally:
            kf.close()

    best: dict[str, tuple[int, Path, str, str, str | None]] = {}
    skipped_small = 0
    for p in root.rglob("*"):
        if not p.is_file() or p.suffix.lower() not in MEDIA:
            continue
        if p.stat().st_size < 100_000:
            skipped_small += 1
            continue
        artist, title = split_name(p.stem)
        song, yid = strip_youtube_tag(Song(-1, title, artist, 0))
        if not song.title:
            continue
        key = natural_key(song)
        folder = p.parent.name if p.parent != root else ""
        # Se prefiere el original: no en Repetidos/Corregir y sin prefijo «v2».
        rank = int(folder.casefold() in LOW_PRIORITY) + int(is_alt_version(song.artist))
        prev = best.get(key)
        if prev is None or (rank, p.stat().st_ctime) < (prev[0], prev[1].stat().st_ctime):
            best[key] = (rank, p, song.title, clean_artist(song.artist), yid)

    rows = []
    for key, (_rank, p, title, artist, yid) in best.items():
        folder = p.parent.name if p.parent != root else ""
        added = datetime.fromtimestamp(min(p.stat().st_ctime, p.stat().st_mtime)).strftime("%Y-%m-%d")
        rows.append([
            "L" + hashlib.sha1(key.encode("utf-8")).hexdigest()[:10],
            title, artist, "", 0, 0, added, "Karaoke local", "",
            durations.get(key, ""), folder if folder != PENDING_FOLDER else PENDING_FOLDER,
            p.relative_to(root).as_posix(), key, yid or "",
        ])
    rows.sort(key=lambda r: (r[2].casefold(), r[1].casefold()))

    out = Path(args.out)
    with out.open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f, delimiter=";", quoting=csv.QUOTE_MINIMAL, lineterminator="\n")
        w.writerow(HEADER)
        w.writerows(rows)

    with_duration = sum(1 for r in rows if r[9] != "")
    print(f"{len(rows)} canciones -> {out}")
    print(f"  con duración de KaraFun: {with_duration}; archivos dañados omitidos: {skipped_small}")
    if kf_keys:
        missing = kf_keys - set(best)
        extra = set(best) - kf_keys
        print(f"  coinciden con KaraFun: {len(set(best) & kf_keys)}; solo en KaraFun: {len(missing)}; "
              f"solo en archivos: {len(extra)}")
        for k in sorted(missing)[:10]:
            print(f"    solo en KaraFun: {k}")
        for k in sorted(extra)[:10]:
            print(f"    solo en archivos: {k}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
