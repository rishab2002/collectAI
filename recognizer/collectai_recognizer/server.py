"""A small HTTP service around the pipeline, for the Laravel app.

Standard library only. It listens on the private Docker network; it has no
authentication of its own and must not be published to the internet.

    GET  /health        index and Tesseract status
    POST /recognize     body = one image; finds and identifies every car
    POST /read-card     body = one image; reads a card into the OCR schema

Query parameters on POST: min_confidence, min_margin, crops=0, and on
/recognize layout=auto|pile|single (single = one car or base, close up).
The index file is re-read whenever it changes on disk, so a fresh export
needs no restart.
"""

from __future__ import annotations

import json
import os
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from . import __version__, ocr
from .catalog import CatalogIndex
from .pipeline import decode, read_card, recognise
from .segment import LAYOUTS

MAX_BODY = 25 * 1024 * 1024


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


def make_handler(cache: IndexCache, defaults: dict):
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
                result = recognise(image, index, crops=q.get("crops", "1") != "0", layout=layout, **options)
                result["index_error"] = cache.error
            result["seconds"] = round(time.monotonic() - started, 2)
            self._send(200, result)

    return Handler


def serve(host: str, port: int, index_path: str, min_confidence: float, min_margin: float) -> None:
    cache = IndexCache(index_path)
    cache.get()
    handler = make_handler(cache, {"min_confidence": min_confidence, "min_margin": min_margin})
    httpd = ThreadingHTTPServer((host, port), handler)
    print(f"collectAI recognizer {__version__} on http://{host}:{port} · index {index_path}"
          f" ({cache.error or 'loaded'}) · tesseract: {ocr.available() or 'MISSING'}", flush=True)
    httpd.serve_forever()


def env_defaults() -> dict:
    return {
        "index": os.environ.get("RECOGNIZER_INDEX", "/data/catalog-index.json"),
        "min_confidence": float(os.environ.get("RECOGNIZER_MIN_CONFIDENCE", "0.9")),
        "min_margin": float(os.environ.get("RECOGNIZER_MIN_MARGIN", "0.08")),
    }
