"""A small HTTP service around the pipeline, for the Laravel app.

Standard library only. It listens on the private Docker network; it has no
authentication of its own and must not be published to the internet.

    GET  /health        index, model and Tesseract status
    POST /recognize     body = one image; finds and identifies every car
    POST /read-card     body = one image; reads a card into the OCR schema

Query parameters on POST: min_confidence, min_margin, crops=0, and on
/recognize layout=auto|pile|single|packaged (single = one car or base, close
up; packaged = cars still on their cards or in their boxes). `region=x,y,w,h`
— fractions of the photo — scans only that part of it, which is how a person
crops a photo by hand and hands back the part the segmenter missed; with
`whole=1` that part is taken to be one object, because somebody chose it.
`context=...` is words about the photo that are not in it — the collector's
own file name — read as print beside a package is: brand, year and codes,
never the car's name.
The index file is re-read whenever it changes on disk, so a fresh export
needs no restart. With RECOGNIZER_INDEX_URL set (Railway), the recognizer
fetches that file from the app itself — see index_sync.

Listens on IPv4 for "0.0.0.0", or on IPv6 *and* IPv4 for "::" — what
Railway's private network needs.
"""

from __future__ import annotations

import json
import os
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from . import __version__, index_sync, ocr
from .catalog import CatalogIndex
from .pipeline import decode, read_card, recognise, region_of
from .segment import LAYOUTS

MAX_BODY = 25 * 1024 * 1024


class ModelCache:
    """The trained visual model, reloaded when manifest.json changes. Optional."""

    def __init__(self, directory: str):
        self.directory = Path(directory)
        self._model = None
        self._mtime = None
        self._lock = threading.Lock()

    def get(self):
        from .visual import VisualModel

        with self._lock:
            try:
                mtime = (self.directory / "manifest.json").stat().st_mtime
            except FileNotFoundError:
                self._model, self._mtime = None, None
                return None
            if mtime != self._mtime:
                try:
                    self._model = VisualModel.load(self.directory)
                except Exception as e:  # a half-copied model: keep the old one
                    print(f"could not load the model in {self.directory}: {e}", flush=True)
                self._mtime = mtime
            return self._model


class IndexCache:
    def __init__(self, path: str):
        self.path = Path(path)
        self._index: CatalogIndex | None = None
        self._mtime: float | None = None
        self._error: str | None = None
        self._lock = threading.Lock()

    def get(self) -> CatalogIndex | None:
        with self._lock:
            try:
                mtime = self.path.stat().st_mtime
            except FileNotFoundError:
                self._index, self._mtime = None, None
                self._error = f"no index at {self.path} — run php artisan catalog:export-recognition-index"
                return None
            if mtime != self._mtime:
                try:
                    self._index = CatalogIndex.load(self.path)
                    self._error = None
                except Exception as e:  # a half-written file: keep the old one
                    self._error = f"could not read {self.path}: {e}"
                self._mtime = mtime
            return self._index

    @property
    def error(self) -> str | None:
        return self._error


def make_handler(cache: IndexCache, defaults: dict, models: ModelCache | None = None, sync=None):
    class Handler(BaseHTTPRequestHandler):
        server_version = f"collectai-recognizer/{__version__}"

        def _send(self, status: int, payload: dict) -> None:
            body = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, fmt, *args):  # one line per request, no bodies
            print(f"{self.address_string()} {fmt % args}", flush=True)

        def do_GET(self):
            if urlparse(self.path).path != "/health":
                return self._send(404, {"error": "not found"})
            index = cache.get()
            self._send(200, {
                "ok": index is not None and ocr.available() is not None,
                "version": __version__,
                "tesseract": ocr.available(),
                "index": index.summary() if index else None,
                "index_error": cache.error,
                "model": model.summary() if (model := models.get() if models else None) else None,
                "index_sync": sync.summary() if sync else None,
            })

        def do_POST(self):
            url = urlparse(self.path)
            if url.path not in ("/recognize", "/read-card"):
                return self._send(404, {"error": "not found"})
            length = int(self.headers.get("Content-Length") or 0)
            if length <= 0:
                return self._send(422, {"error": "send the image as the request body"})
            if length > MAX_BODY:
                return self._send(413, {"error": "image too large"})
            try:
                image = decode(self.rfile.read(length))
            except ValueError as e:
                return self._send(422, {"error": str(e)})

            q = {k: v[-1] for k, v in parse_qs(url.query).items()}
            try:
                region = _region(q.get("region"))
            except ValueError as e:
                return self._send(422, {"error": str(e)})
            image = region_of(image, region)

            options = {
                "min_confidence": float(q.get("min_confidence", defaults["min_confidence"])),
                "min_margin": float(q.get("min_margin", defaults["min_margin"])),
            }
            started = time.monotonic()
            index = cache.get()
            if url.path == "/read-card":
                result = read_card(image, index, **options)
            else:
                layout = q.get("layout", "auto")
                if layout not in LAYOUTS:
                    return self._send(422, {"error": f"layout must be one of {', '.join(LAYOUTS)}"})
                result = recognise(image, index, crops=q.get("crops", "1") != "0", layout=layout,
                                   whole=q.get("whole", "0") == "1", context=q.get("context", "")[:200],
                                   visual=models.get() if models else None, **options)
                result["index_error"] = cache.error
            result["seconds"] = round(time.monotonic() - started, 2)
            self._send(200, result)

    return Handler


def _region(value: str | None) -> tuple[float, float, float, float] | None:
    """`region=x,y,w,h`, each a fraction of the photo — the part to scan."""
    if not value:
        return None
    parts = value.split(",")
    if len(parts) != 4:
        raise ValueError("region must be x,y,w,h as four fractions of the photo")
    try:
        x, y, w, h = (float(p) for p in parts)
    except ValueError:
        raise ValueError("region must be four numbers") from None
    if w <= 0 or h <= 0 or not all(0 <= v <= 1 for v in (x, y, x + w, y + h)):
        raise ValueError("region must sit inside the photo and have a positive size")
    return (x, y, w, h)


class DualStackServer(ThreadingHTTPServer):
    """IPv6 and IPv4 on one socket, for host "::"."""

    address_family = socket.AF_INET6

    def server_bind(self):
        try:
            self.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
        except (AttributeError, OSError):
            pass
        super().server_bind()


def make_server(host: str, port: int, handler) -> ThreadingHTTPServer:
    return (DualStackServer if ":" in host else ThreadingHTTPServer)((host, port), handler)


def serve(host: str, port: int, index_path: str, min_confidence: float, min_margin: float, model_dir: str | None = None) -> None:
    sync = index_sync.from_env(index_path)
    if sync:
        sync.fetch()  # once before serving, so a fresh container has an index at once
        sync.start()
    cache = IndexCache(index_path)
    cache.get()
    models = ModelCache(model_dir) if model_dir else None
    model = models.get() if models else None
    print(f"visual model: {model.summary() if model else 'none (text and colour only)'}", flush=True)
    handler = make_handler(cache, {"min_confidence": min_confidence, "min_margin": min_margin}, models, sync)
    httpd = make_server(host, port, handler)
    print(f"collectAI recognizer {__version__} on http://{host}:{port} · index {index_path}"
          f" ({cache.error or 'loaded'}) · tesseract: {ocr.available() or 'MISSING'}", flush=True)
    httpd.serve_forever()


def env_defaults() -> dict:
    return {
        "index": os.environ.get("RECOGNIZER_INDEX", "/data/catalog-index.json"),
        "model": os.environ.get("RECOGNIZER_MODEL_DIR", "/data/model"),
        "min_confidence": float(os.environ.get("RECOGNIZER_MIN_CONFIDENCE", "0.9")),
        "min_margin": float(os.environ.get("RECOGNIZER_MIN_MARGIN", "0.08")),
    }
