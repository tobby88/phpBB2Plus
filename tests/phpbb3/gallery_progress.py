"""Save Nuffload settings, submit normal multipart forms and optionally throttle an actual Chromium upload."""
import json
import os
from pathlib import Path
import random
import shutil
import struct
import subprocess
import tempfile
import time
from urllib.parse import urljoin, urlsplit
import zlib

from gallery import ORIGINAL, png
from gallery_media import settings_url
from http_board import FormFields, Page, submit_form


def browse(admin, guest, check):
    acp = settings_url(admin)
    form = "album-media-settings"
    public = "app.php/album/category/950/upload"
    pending = "app.php/album/category/951/upload"
    if urlsplit(guest.base).hostname != "127.0.0.1":
        raise RuntimeError("Upload fixtures require the synthetic loopback board")

    def save(fields):
        saved = submit_form(admin, acp, admin.fetch(acp), form, fields)
        if 'class="successbox"' not in saved or "PLUS_ALBUM_CFG_" in saved:
            raise RuntimeError("The Nuffload settings could not be saved through the ACP")
        return FormFields(saved, form).values

    def post(route, title, ajax):
        page = guest.fetch(route)
        action, fields = Page(page).forms["album-upload"]
        fields.update(title=title)
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return guest.fetch(urljoin(urljoin(guest.base,route),action), fields, ajax=ajax, files=[("photos[]","sample.png","image/png",ORIGINAL)])

    def with_browser():
        engine = os.environ.get("PLUS_TEST_ALBUM_CHROME") or os.environ.get("PLUS_TEST_CHROME")
        if not engine:
            return {}
        browser = shutil.which(engine)
        node = shutil.which("node")
        if not browser or not node:
            raise RuntimeError("The real album-progress check requires the requested Chromium and Node.js with WebSocket support")
        random_bytes = random.Random(85)
        width, height = 256, 180
        rows = b"".join(b"\0" + random_bytes.randbytes(width*3) for _ in range(height))
        def chunk(kind, data):
            return struct.pack(">I",len(data)) + kind + data + struct.pack(">I",zlib.crc32(kind+data))
        sample = (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR",struct.pack(">IIBBBBB",width,height,8,2,0,0,0)) +
                  chunk(b"IDAT",zlib.compress(rows)) + chunk(b"IEND",b""))
        expected = {}
        with tempfile.TemporaryDirectory(prefix="plus3-album-transfer-") as tmp:
            root = Path(tmp)
            huge = root / "oversized.png"
            huge.write_bytes(png(301,1,b"\x00\x88\xcc"))
            for mode, route in (("expanded",public),("compact",pending),("native",public)):
                if mode == "compact":
                    save({"close_on_finish":"1","simple_format":"0","max_pause":"47"})
                    full = guest.fetch(pending)
                    if 'data-idle="47"' not in full or '<section class="panel" id="album-upload-status"' not in full:
                        raise RuntimeError("The configured idle notice did not reach the upload")
                picture = root / (mode+".png")
                picture.write_bytes(sample)
                process = subprocess.run([node, "--experimental-websocket", str(Path(__file__).with_name("gallery_progress_browser.cjs")), browser, str(root / ("profile-"+mode)),
                                          urljoin(guest.base,route), mode, str(picture), str(huge)], capture_output=True, text=True, timeout=60)
                if process.returncode:
                    raise RuntimeError("Chromium album transfer failed ("+mode+"): "+process.stderr[-350:])
                if mode != "native" and json.loads(process.stdout)["bytes"] <= len(sample):
                    raise RuntimeError("The reported upload size excluded part of the real multipart body")
                expected[mode] = (950 if route == public else 951, int(route == public), len(sample))
        return expected

    check("seed")
    try:
        options = save(dict(show_progress_bar="0", simple_format="1", close_on_finish="0", max_pause="10", gd_version="2", png_allowed="1", resize_pic="0",
                            max_width="300", max_height="300", max_file_size="250000", max_pics="-1", user_pics_limit="-1"))
        if options["max_pause"] != "10" or 'id="album-upload-status"' in guest.fetch(public):
            raise RuntimeError("Explicitly disabled upload progress still rendered its script panel")
        if 'role="status"' not in post(public,"Ordinary form",False):
            raise RuntimeError("The normal multipart upload lost its success page")
        save({"show_progress_bar":"1"})
        enabled = guest.fetch(public)
        if ('class="plus-album-progress-simple" id="album-upload-status"' not in enabled or 'id="album-transfer-title" class="sr-only"' not in enabled
                or 'id="album-bytes"' not in enabled or 'data-close="0" data-idle="30"' not in enabled):
            raise RuntimeError("The enabled progress panel lost its historic minimum idle interval")
        reply = json.loads(post(pending,"Approval notice",True))
        if "/album/category/951" not in reply["location"] or not reply["message"]:
            raise RuntimeError("The upload response lost its moderated destination")
        expected = {"Ordinary form":(950,1,len(ORIGINAL)),"Approval notice":(951,0,len(ORIGINAL))}
        expected.update(with_browser())
        rows = json.loads(check("state"))
        actual = {row["title"]:(int(row["cat_id"]),int(row["approved"]),int(row["bytes"])) for row in rows}
        if len(rows) != len(expected) or actual != expected:
            raise RuntimeError("A completed, failed or retried browser upload stored the wrong pictures")
    finally:
        check("cleanup")
