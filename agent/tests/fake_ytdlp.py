"""yt-dlp falso: responde según tests/ytdlp_fixtures.json (ruta en FAKE_YTDLP_FIXTURES)."""

import json
import os
import re
import sys

fixtures = json.load(open(os.environ["FAKE_YTDLP_FIXTURES"], encoding="utf-8"))
args = sys.argv[1:]
if "--version" in args:
    print("2026.08.19-fake")
    sys.exit(0)
url = args[-1]
yid = re.search(r"v=([A-Za-z0-9_-]{11})", url).group(1)
info = fixtures.get(yid)
if info is None:
    print("ERROR: [youtube] Video unavailable. This video is private", file=sys.stderr)
    sys.exit(1)
if "--dump-single-json" in args:
    print(json.dumps(info))
    sys.exit(0)
out = args[args.index("-o") + 1].replace("%(ext)s", "mp4")
with open(out, "wb") as f:
    f.write(b"\0" * 200_000)
counter = os.environ.get("FAKE_YTDLP_COUNTER")
if counter:
    with open(counter, "a", encoding="utf-8") as f:
        f.write(yid + "\n")
