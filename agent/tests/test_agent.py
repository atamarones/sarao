"""Pruebas del agente contra KaraFun y la nube simulados.

Ejecutar desde agent/:  .venv\\Scripts\\python.exe -m unittest discover -s tests -v
"""

from __future__ import annotations

import json
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sarao_agent.catalog import chunks, clean_artist, natural_key, normalize, strip_youtube_tag  # noqa: E402
from sarao_agent.cloud import CloudClient  # noqa: E402
from sarao_agent.config import Config  # noqa: E402
from sarao_agent.downloader import Downloader, DownloadError, clean_title, safe_name, split_artist_title  # noqa: E402
from sarao_agent.journal import Journal  # noqa: E402
from sarao_agent.karafun import KaraFunClient, Song, parse_xml  # noqa: E402
from sarao_agent.runner import Agent  # noqa: E402

from fakes import FakeCloud, FakeKaraFun  # noqa: E402

HERE = Path(__file__).resolve().parent

FIXTURES = {
    "gzzLTaU_yXA": {"id": "gzzLTaU_yXA", "title": "La Bicicleta - Carlos Vives feat Shakira (Karaoke)",
                    "uploader": "Karaoke Latino", "duration": 229, "live_status": "not_live"},
    "LONGLONG001": {"id": "LONGLONG001", "title": "Concierto completo", "uploader": "X", "duration": 3600},
    "LIVELIVE001": {"id": "LIVELIVE001", "title": "En vivo", "uploader": "X", "duration": 0, "is_live": True},
    "MUSICMETA01": {"id": "MUSICMETA01", "title": "Cualquier cosa", "artist": "Shakira, Carlos Vives",
                    "track": "La Bicicleta", "uploader": "X", "duration": 230},
}


def wait_until(cond, timeout=15.0, step=0.1):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if cond():
            return True
        time.sleep(step)
    return False


class TextTests(unittest.TestCase):
    def test_normalize_and_keys(self):
        self.assertEqual(normalize("  En Los Días Que Te Quise! "), "en los dias que te quise")
        self.assertEqual(normalize("ANA &amp; JAIME"), "ana amp jaime")
        self.assertEqual(natural_key(Song(-1, "Quisiera olvidarte", "Adriana Lucía", 0)),
                         "adriana lucia|quisiera olvidarte")
        self.assertEqual(natural_key(Song(76237, "Caballero", "Alejandro Fernández", 228)), "kf:76237")
        # NFKD: los caracteres de compatibilidad pasan a su letra base (con NFD «5ª» quedaría «5»).
        self.assertEqual(natural_key(Song(-2, "Me Dueles", "La 5ª Estación", 0)), "la 5a estacion|me dueles")
        self.assertEqual(normalize("Nº 1 ²"), "no 1 2")

    def test_alt_versions_share_identity(self):
        self.assertEqual(natural_key(Song(-3, "Make You Feel My Love", "v2 Adele", 0)),
                         natural_key(Song(-4, "Make You Feel My Love", "Adele", 0)))
        self.assertEqual(clean_artist("V3 Kaleth Morales"), "Kaleth Morales")
        self.assertEqual(clean_artist("V6"), "V6")  # un artista que se llama así no se toca
        self.assertEqual(clean_artist("Vivir Quintana"), "Vivir Quintana")

    def test_strip_youtube_tag(self):
        song, yid = strip_youtube_tag(Song(-5, "La Bicicleta [gzzLTaU_yXA]", "Carlos Vives", 0))
        self.assertEqual((song.title, yid), ("La Bicicleta", "gzzLTaU_yXA"))
        self.assertEqual(strip_youtube_tag(Song(-5, "Hello [Live]", "Adele", 0))[1], None)

    def test_xml_sanitizer(self):
        root = parse_xml('<list total="1"><item id="1"><title>pa ti toa <3</title><artist>A & B</artist></item></list>')
        self.assertEqual(root.find("item/title").text, "pa ti toa <3")
        self.assertEqual(root.find("item/artist").text, "A & B")

    def test_titles_and_names(self):
        self.assertEqual(clean_title("La Bicicleta - Carlos Vives & Shakira | Versión Karaoke | KaraFun"),
                         "La Bicicleta - Carlos Vives & Shakira")
        self.assertEqual(split_artist_title(FIXTURES["gzzLTaU_yXA"]), ("La Bicicleta", "Carlos Vives feat Shakira"))
        self.assertEqual(split_artist_title(FIXTURES["MUSICMETA01"]), ("Shakira", "La Bicicleta"))
        self.assertEqual(split_artist_title({"title": "Solo título (Karaoke)", "uploader": "Canal"}),
                         ("Canal", "Solo título"))
        self.assertEqual(safe_name('AC/DC: "Back" <In> Black?'), "AC DC Back In Black")

    def test_chunks(self):
        self.assertEqual([len(c) for c in chunks(range(1201), 500)], [500, 500, 201])


class WatchdogTests(unittest.TestCase):
    """El vigilante solo reinicia KaraFun si lleva rato sin control remoto Y Windows lo da por colgado varias veces."""

    def make(self, port_open, responding, dialog=False):
        from sarao_agent.watchdog import KaraFunWatchdog
        wd = KaraFunWatchdog(Path("KaraFunPlayer.exe"), hang_grace_s=-1, hang_confirmations=3)  # sin espera: no depende del reloj
        wd.killed = 0
        wd.port_open = lambda: port_open
        wd.is_running = lambda: True
        wd.is_responding = lambda: responding.pop(0) if responding else True
        wd.dismiss_frozen_dialog = lambda: dialog
        wd.kill = lambda: setattr(wd, "killed", wd.killed + 1)
        wd.start_and_wait = lambda: True
        return wd

    def test_single_not_responding_does_not_kill(self):
        wd = self.make(False, [False, True, False, False])
        for _ in range(4):
            wd.ensure()
            time.sleep(0.01)
        self.assertEqual(wd.killed, 0)  # nunca hubo 3 seguidas

    def test_three_in_a_row_restarts(self):
        wd = self.make(False, [False, False, False])
        for _ in range(4):
            wd.ensure()
            time.sleep(0.01)
        self.assertEqual(wd.killed, 1)

    def test_frozen_dialog_is_dismissed_not_killed(self):
        wd = self.make(False, [False] * 10, dialog=True)
        for _ in range(5):
            wd.ensure()
        self.assertEqual(wd.killed, 0)


class KaraFunClientTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fake = FakeKaraFun().start()

    @classmethod
    def tearDownClass(cls):
        cls.fake.stop()

    def setUp(self):
        self.fake.queue.clear()
        self.kf = KaraFunClient(self.fake.url, timeout_s=5)

    def tearDown(self):
        self.kf.close()

    def test_search_and_double_escape(self):
        self.fake.queue.append({"title": "Decimo Grado", "artist": "Ana & Jaime", "duration": 187.0, "singer": "Ana"})
        st = self.kf.status()
        self.assertEqual(st.queue[0].artist, "Ana & Jaime")
        res = self.kf.search("quisiera")
        self.assertEqual({s.id for s in res}, {-101, -102})

    def test_add_and_remove(self):
        st = self.kf.add_to_queue(-100, 'Ana · M7·k3f "&" <b>')
        self.assertEqual(st.queue[-1].singer, "Ana · M7·k3f & b")
        st = self.kf.remove_from_queue(0)
        self.assertEqual(len(st.queue), 0)

    def test_status_pushed_while_playing_does_not_hide_the_new_song(self):
        # Visto en el bar (2026-10-08): con algo sonando, los <status> empujados se apilaban y el agente
        # leía la cola de minutos antes; la canción recién añadida «no aparecía» y la nube la daba por fallida.
        self.fake.queue.append({"title": "Decimo Grado", "artist": "Ana & Jaime", "duration": 187.0,
                                "singer": "Ana · M1·aaa", "playing": True})
        self.kf.status()
        self.fake.push_status(times=5)
        time.sleep(0.3)  # que los empujes lleguen al socket antes de la orden
        st = self.kf.add_to_queue(76237, "Luis · M2·bbb")
        self.assertEqual([q.singer for q in st.queue], ["Ana · M1·aaa", "Luis · M2·bbb"])
        self.fake.queue.pop(0)  # terminó la primera y KaraFun lo avisa varias veces
        self.fake.push_status(times=5)
        time.sleep(0.3)
        self.assertEqual([q.singer for q in self.kf.status().queue], ["Luis · M2·bbb"])

    def test_reconnects_when_karafun_restarts_its_remote(self):
        self.fake.drop_next = 1
        res = self.kf.search("decimo")
        self.assertEqual(res[0].id, -100)

    def test_list_paging_ignores_false_total(self):
        ids = [s.id for s in self.kf.iter_list(524289, page_size=2)]
        self.assertEqual(ids, [-100, -101, -102, -103])


class AgentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.karaoke = root / "Karaoke"
        (self.karaoke / "A").mkdir(parents=True)
        fixtures = root / "fixtures.json"
        fixtures.write_text(json.dumps(FIXTURES), encoding="utf-8")
        self.counter = root / "downloads.txt"
        os.environ["FAKE_YTDLP_FIXTURES"] = str(fixtures)
        os.environ["FAKE_YTDLP_COUNTER"] = str(self.counter)

        self.kf_fake = FakeKaraFun().start()
        self.cloud = FakeCloud().start()
        self.cfg = Config(
            cloud_url=self.cloud.url, token=FakeCloud.TOKEN, karaoke_dir=self.karaoke,
            pending_dir=self.karaoke / "Por aprobar", karafun_folder_name="Karaoke",
            karafun_exe=self.kf_fake.make_exe(root), karafun_ws=self.kf_fake.url, ytdlp="yt-dlp",
            max_duration_s=480, poll_interval_s=0.2, local_sync_time="17:00", status_interval_s=0,
            online_sync_days=7,
            data_dir=root / "data",
        )
        self.journal = Journal(self.cfg.journal_path)
        self.agent = self.make_agent()
        # Las sincronizaciones automáticas se prueban aparte.
        self.agent.state = {"last_local_sync": "2999-01-01T00:00:00-05:00",
                            "last_karafun_sync": "2999-01-01T00:00:00-05:00"}

    def make_agent(self) -> Agent:
        return Agent(self.cfg, CloudClient(self.cfg.cloud_url, self.cfg.token),
                     KaraFunClient(self.cfg.karafun_ws, self.cfg.karafun_exe, timeout_s=5), self.journal,
                     Downloader([sys.executable, str(HERE / "fake_ytdlp.py")], self.cfg.karaoke_dir,
                                self.cfg.pending_dir, self.cfg.tmp_dir),
                     watchdog=None)

    def tearDown(self):
        self.agent.stop.set()
        self.agent.kf.close()
        self.journal.close()
        self.kf_fake.stop()
        self.cloud.stop()
        self.tmp.cleanup()

    def run_until(self, cond, timeout=20.0):
        self.agent.start()
        end = time.monotonic() + timeout
        while time.monotonic() < end:
            self.agent.tick()
            if cond():
                return True
            time.sleep(0.2)
        return False

    def acks_for(self, cid):
        return [a for a in self.cloud.acks if a["command_id"] == cid]

    def enqueue_payload(self, song, singer="Ana · M7·k3f"):
        return {"request_id": "r1", "song": song, "singer": singer}

    # -------------------------------------------------------------- órdenes

    def test_enqueue_local_song_once_even_if_delivered_twice(self):
        song = {"natural_key": "adriana lucia|quisiera olvidarte", "source": "local", "title": "Quisiera olvidarte",
                "artist": "Adriana Lucia"}
        self.cloud.add_command(1, "enqueue", self.enqueue_payload(song))
        self.assertTrue(self.run_until(lambda: self.acks_for(1)))
        self.assertEqual(len(self.kf_fake.queue), 1)
        self.assertEqual(self.kf_fake.queue[0]["singer"], "Ana · M7·k3f")
        self.assertEqual(self.acks_for(1)[0]["result"], {"queue_pos": 0, "singer_shown": True})
        # La nube no recibió el ack (se «pierde») y reentrega la orden: no se añade dos veces.
        self.cloud.commands[1]["status"] = "pending"
        self.assertTrue(self.run_until(lambda: len(self.acks_for(1)) >= 2))
        self.assertEqual(len(self.kf_fake.queue), 1)

    def test_enqueue_online_song(self):
        song = {"natural_key": "kf:76237", "source": "karafun", "kf_id": 76237, "title": "Caballero",
                "artist": "Alejandro Fernández"}
        self.cloud.add_command(2, "enqueue", self.enqueue_payload(song, "Luis · M2·aaa"))
        self.assertTrue(self.run_until(lambda: self.acks_for(2)))
        self.assertEqual(self.kf_fake.queue[0]["title"], "Caballero")

    def test_enqueue_not_visible_reports_no_position(self):
        # Antes se respondía «última posición» aunque la canción no estuviera: el ack mentía.
        self.kf_fake.ignore_adds = True
        song = {"natural_key": "kf:76237", "source": "karafun", "kf_id": 76237, "title": "Caballero",
                "artist": "Alejandro Fernández"}
        self.cloud.add_command(16, "enqueue", self.enqueue_payload(song))
        self.assertTrue(self.run_until(lambda: self.acks_for(16)))
        self.assertEqual(self.acks_for(16)[0]["result"], {"queue_pos": None, "singer_shown": True})

    def test_enqueue_missing_song_fails_without_retry(self):
        song = {"natural_key": "nadie|nada", "source": "local", "title": "Nada", "artist": "Nadie"}
        self.cloud.add_command(3, "enqueue", self.enqueue_payload(song))
        self.assertTrue(self.run_until(lambda: self.acks_for(3)))
        ack = self.acks_for(3)[0]
        self.assertFalse(ack["ok"])
        self.assertEqual(ack["error"]["code"], "not_found")
        self.assertNotIn("retryable", ack)

    def test_enqueue_falls_back_to_file_path(self):
        # KaraFun muestra este archivo con los datos internos del video, así que no lo encuentra por nombre.
        f = self.karaoke / "C" / "Cuco Sanchez - Tres Corazones.mp4"
        f.parent.mkdir()
        f.write_bytes(b"x")
        song = {"natural_key": "cuco sanchez|tres corazones", "source": "local", "title": "Tres Corazones",
                "artist": "Cuco Sanchez", "file": "C/Cuco Sanchez - Tres Corazones.mp4"}
        self.cloud.add_command(15, "enqueue", self.enqueue_payload(song))
        # Una ruta que intenta salir de la carpeta de karaoke se ignora.
        evil = dict(song, file="../../Windows/win.ini", natural_key="x|y", title="Nada")
        self.cloud.add_command(16, "enqueue", self.enqueue_payload(evil))
        self.assertTrue(self.run_until(lambda: self.acks_for(15) and self.acks_for(16), timeout=30))
        self.assertEqual(self.acks_for(15)[0]["result"]["singer_shown"], False)
        self.assertEqual(self.acks_for(16)[0]["error"]["code"], "not_found")
        self.assertEqual(len(self.kf_fake.queue), 1)

    def test_remove(self):
        self.kf_fake.queue += [
            {"title": "Decimo Grado", "artist": "Ana & Jaime", "duration": 187.0, "singer": "Otro · M1·zzz", "playing": True},
            {"title": "Decimo Grado", "artist": "Ana & Jaime", "duration": 187.0, "singer": "Ana · M7·k3f"},
        ]
        self.cloud.add_command(4, "remove", {"request_id": "r1", "singer": "Ana · M7·k3f",
                                             "song": {"title": "Decimo Grado"}})
        self.assertTrue(self.run_until(lambda: self.acks_for(4)))
        self.assertEqual(self.acks_for(4)[0]["result"], {"removed": True})
        self.assertEqual([q["singer"] for q in self.kf_fake.queue], ["Otro · M1·zzz"])

    def test_download_then_enqueue_by_file(self):
        self.cloud.add_command(5, "download", {"request_id": "r2", "youtube_id": "gzzLTaU_yXA", "max_duration_s": 480})
        self.assertTrue(self.run_until(lambda: self.acks_for(5), timeout=30))
        ack = self.acks_for(5)[0]
        self.assertTrue(ack["ok"], ack)
        song = ack["result"]["song"]
        self.assertEqual(song["folder"], "Por aprobar")
        self.assertEqual(song["natural_key"], "la bicicleta|carlos vives feat shakira")
        files = list((self.karaoke / "Por aprobar").glob("*.mp4"))
        self.assertEqual([f.name for f in files], ["La Bicicleta - Carlos Vives feat Shakira [gzzLTaU_yXA].mp4"])
        self.assertEqual(self.cloud.upserts[-1]["youtube_id"], "gzzLTaU_yXA")
        # KaraFun todavía no lo indexó: entra a la cola por ruta de archivo, sin cantante.
        self.cloud.add_command(6, "enqueue", self.enqueue_payload(song, "Ana · M7·k3f"))
        self.assertTrue(self.run_until(lambda: self.acks_for(6), timeout=30))
        self.assertEqual(self.acks_for(6)[0]["result"]["singer_shown"], False)
        self.assertEqual(len(self.kf_fake.queue), 1)
        # Un segundo pedido del mismo video no lo vuelve a descargar.
        self.cloud.add_command(7, "download", {"request_id": "r3", "youtube_id": "gzzLTaU_yXA", "max_duration_s": 480})
        self.assertTrue(self.run_until(lambda: self.acks_for(7)))
        self.assertEqual(self.counter.read_text().split(), ["gzzLTaU_yXA"])

    def test_download_rejections(self):
        for cid, yid, code in ((8, "LONGLONG001", "too_long"), (9, "LIVELIVE001", "live"), (10, "NOEXISTE001", "unavailable")):
            self.cloud.add_command(cid, "download", {"request_id": "r", "youtube_id": yid, "max_duration_s": 480})
        self.assertTrue(self.run_until(lambda: all(self.acks_for(c) for c in (8, 9, 10)), timeout=30))
        for cid, code in ((8, "too_long"), (9, "live"), (10, "unavailable")):
            ack = self.acks_for(cid)[0]
            self.assertFalse(ack["ok"])
            self.assertEqual(ack["error"]["code"], code)
        self.assertFalse(self.counter.exists())

    def test_long_command_keeps_lease_alive(self):
        # Mientras una orden está en marcha, el agente la informa en `working` y la nube no la reentrega.
        self.agent.dispatch({"id": 11, "type": "download", "payload": {"youtube_id": "gzzLTaU_yXA"}})
        with self.agent._lock:
            self.agent._working.add(11)
        self.agent.tick()
        self.assertIn(11, self.cloud.polls[-1]["working"])

    def test_recovery_after_crash_mid_enqueue(self):
        song = {"natural_key": "ana jaime|decimo grado", "source": "local", "title": "Decimo Grado", "artist": "Ana & Jaime"}
        payload = self.enqueue_payload(song, "Ana · M7·k3f")
        # El agente anotó la orden, la ejecutó en KaraFun y murió antes de anotar el resultado.
        self.journal.begin(12, "enqueue", payload)
        self.kf_fake.queue.append({"title": "Decimo Grado", "artist": "Ana & Jaime", "duration": 187.0, "singer": "Ana · M7·k3f"})
        # Otra orden quedó a medias sin efecto visible: se olvidará y se ejecutará cuando la nube la reentregue.
        self.journal.begin(13, "enqueue", self.enqueue_payload(song, "Luis · M3·bbb"))
        self.cloud.add_command(12, "enqueue", payload)
        self.cloud.add_command(13, "enqueue", self.enqueue_payload(song, "Luis · M3·bbb"))
        self.assertTrue(self.run_until(lambda: self.acks_for(12) and self.acks_for(13)))
        self.assertEqual([q["singer"] for q in self.kf_fake.queue], ["Ana · M7·k3f", "Luis · M3·bbb"])

    def test_cloud_outage_does_not_lose_acks(self):
        song = {"natural_key": "kf:76237", "source": "karafun", "kf_id": 76237, "title": "Caballero", "artist": ""}
        self.cloud.add_command(14, "enqueue", self.enqueue_payload(song))
        self.assertTrue(self.run_until(lambda: self.journal.get(14) and self.journal.get(14)["status"] == "done"))
        self.cloud.fail_next = 3
        self.assertTrue(self.run_until(lambda: self.acks_for(14)))
        self.assertEqual(len(self.kf_fake.queue), 1)

    # -------------------------------------------------------------- reinicios de KaraFun

    def test_karafun_restart_clears_stale_queue_and_reports_session(self):
        self.agent.state["karafun_session"] = "100:1"
        # KaraFun se reinició y recargó de disco una cola vieja (canciones de hace horas).
        self.kf_fake.queue += [{"title": "Vieja", "artist": "X", "duration": 1.0, "singer": "Ayer"}]
        self.agent.check_session("200:2")
        self.assertEqual(self.kf_fake.queue, [])
        self.assertEqual(self.agent.state["karafun_session"], "200:2")
        self.agent.tick()
        self.assertEqual(self.cloud.polls[-1]["karafun"]["session"], "200:2")
        saved = json.loads((self.cfg.data_dir / "state.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["karafun_session"], "200:2")

    def test_same_session_keeps_queue(self):
        # Se reinició el agente, no KaraFun: la cola es válida y no se toca.
        self.agent.state["karafun_session"] = "100:1"
        self.kf_fake.queue += [{"title": "Decimo Grado", "artist": "Ana & Jaime", "duration": 187.0, "singer": "Ana"}]
        self.agent.check_session("100:1")
        self.assertEqual(len(self.kf_fake.queue), 1)

    def test_restart_while_playing_does_not_clear(self):
        self.agent.state["karafun_session"] = "100:1"
        self.kf_fake.queue += [{"title": "Sonando", "artist": "X", "duration": 1.0, "singer": "A", "playing": True}]
        self.agent.check_session("200:2")
        self.assertEqual(len(self.kf_fake.queue), 1)

    def test_state_file_with_bom_is_read(self):
        f = self.cfg.data_dir / "state.json"
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_text('{"last_karafun_sync": "2026-10-06T15:12:56-05:00"}', encoding="utf-8-sig")
        self.assertEqual(self.make_agent().state["last_karafun_sync"], "2026-10-06T15:12:56-05:00")

    # -------------------------------------------------------------- catálogo

    def test_catalog_sync_local_and_online(self):
        # «v2» llega antes que el original: debe quedar el original, con su duración.
        self.kf_fake.local = {-99: ("Decimo Grado", "v2 Ana & Jaime", 0.0), **self.kf_fake.local}
        self.assertEqual(self.agent.sync_catalog("local"), 3)  # repetidos y «v2» cuentan una vez
        keys = {s["natural_key"] for s in self.cloud.committed["local"]}
        self.assertIn("adriana lucia|quisiera olvidarte", keys)
        decimo = next(s for s in self.cloud.committed["local"] if s["natural_key"] == "ana jaime|decimo grado")
        self.assertEqual((decimo["artist"], decimo["duration_s"]), ("Ana & Jaime", 187))
        self.assertEqual(self.agent.sync_catalog("karafun"), 3)
        titles = {s["title"] for s in self.cloud.committed["karafun"]}
        self.assertIn("pa ti toa <3", titles)
        self.assertTrue(all(s["source"] == "karafun" and s["kf_id"] for s in self.cloud.committed["karafun"]))

    def test_catalog_marks_pending_downloads(self):
        self.kf_fake.local[-200] = ("La Bicicleta [gzzLTaU_yXA]", "Carlos Vives", 229.0)
        pending = self.karaoke / "Por aprobar"
        pending.mkdir()
        f = pending / "Carlos Vives - La Bicicleta [gzzLTaU_yXA].mp4"
        f.write_bytes(b"x")
        self.journal.save_download("gzzLTaU_yXA", str(f), "carlos vives|la bicicleta", "La Bicicleta", "Carlos Vives", 229)
        self.agent.sync_catalog("local")
        bici = next(s for s in self.cloud.committed["local"] if s["youtube_id"] == "gzzLTaU_yXA")
        self.assertEqual((bici["title"], bici["folder"], bici["natural_key"]),
                         ("La Bicicleta", "Por aprobar", "carlos vives|la bicicleta"))
        # El encargado lo movió a su carpeta de letra: deja de estar «Por aprobar».
        moved = self.karaoke / "C" / f.name
        moved.parent.mkdir()
        f.rename(moved)
        self.agent.sync_catalog("local")
        bici = next(s for s in self.cloud.committed["local"] if s["youtube_id"] == "gzzLTaU_yXA")
        self.assertIsNone(bici["folder"])

    def test_scheduled_sync_runs_when_due(self):
        self.agent.state = {}
        self.assertTrue(self.run_until(lambda: "local" in self.cloud.committed and "karafun" in self.cloud.committed))


if __name__ == "__main__":
    unittest.main()
