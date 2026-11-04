"""Keep the catalog index fresh by fetching it from the app.

Under docker compose the app writes catalog-index.json onto a volume the
recognizer also mounts, and this module is not used. On Railway each service
has its own disk, so the recognizer polls the app's
GET /internal/recognition-index over the private network instead
(RECOGNIZER_INDEX_URL) and writes what it gets to its own index path, where
IndexCache picks it up exactly as it would a file the app wrote.

Standard library only. The file is written beside and renamed over, so the
recognizer never reads half of one; a failed fetch keeps the last good file.
Unchanged indexes cost a 304 (ETag).

Headers sent:
    Authorization: Bearer $RECOGNIZER_INDEX_TOKEN   the app's own check
    X-Origin-Auth: $ORIGIN_SECRET                   the web server's (Railway mode)
"""

from __future__ import annotations

import os
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path


class IndexSync:
    def __init__(self, url: str, path: str, token: str, origin_secret: str | None = None,
                 interval: float = 900, retry: float = 30, timeout: float = 180):
        self.url = url
        self.path = Path(path)
        self.token = token
        self.origin_secret = origin_secret or None
        self.interval = interval
        self.retry = retry
        self.timeout = timeout
        self.etag: str | None = None
        self.last_ok: float | None = None
        self.last_error: str | None = None
        self._stop = threading.Event()

    def fetch(self) -> bool:
        """One poll. True when the file on disk is current (fresh or 304)."""
        headers = {"Authorization": f"Bearer {self.token}", "Accept": "application/json"}
        if self.origin_secret:
            headers["X-Origin-Auth"] = self.origin_secret
        if self.etag and self.path.is_file():
            headers["If-None-Match"] = self.etag

        try:
            with urllib.request.urlopen(urllib.request.Request(self.url, headers=headers), timeout=self.timeout) as r:
                body = r.read()
                self.path.parent.mkdir(parents=True, exist_ok=True)
                temporary = self.path.with_name(f".{self.path.name}.{os.getpid()}.tmp")
                temporary.write_bytes(body)
                os.replace(temporary, self.path)
                self.etag = r.headers.get("ETag")
                print(f"index: fetched {len(body) // 1024} KB from {self.url}", flush=True)
        except urllib.error.HTTPError as e:
            if e.code != 304:
                # 404 is how the app answers a missing or wrong token, by design.
                hint = " (check RECOGNIZER_INDEX_TOKEN matches the app's)" if e.code == 404 else ""
                hint = " (check ORIGIN_SECRET matches the app's)" if e.code == 403 else hint
                return self._failed(f"HTTP {e.code} from {self.url}{hint}")
        except (urllib.error.URLError, OSError, TimeoutError) as e:
            return self._failed(f"cannot reach {self.url}: {getattr(e, 'reason', e)}")

        self.last_ok, self.last_error = time.time(), None
        return True

    def _failed(self, message: str) -> bool:
        if message != self.last_error:
            print(f"index: {message}", flush=True)
        self.last_error = message
        return False

    def run(self) -> None:
        while not self._stop.is_set():
            ok = self.fetch()
            self._stop.wait(self.interval if ok else self.retry)

    def start(self) -> threading.Thread:
        thread = threading.Thread(target=self.run, name="index-sync", daemon=True)
        thread.start()
        return thread

    def stop(self) -> None:
        self._stop.set()

    def summary(self) -> dict:
        return {"url": self.url, "last_ok": self.last_ok, "error": self.last_error}


def from_env(path: str) -> IndexSync | None:
    url = os.environ.get("RECOGNIZER_INDEX_URL", "").strip()
    if not url:
        return None
    token = os.environ.get("RECOGNIZER_INDEX_TOKEN", "").strip()
    if not token:
        raise SystemExit("RECOGNIZER_INDEX_URL is set but RECOGNIZER_INDEX_TOKEN is not: the app answers 404 without it")
    return IndexSync(url, path, token, os.environ.get("ORIGIN_SECRET", "").strip() or None,
                     interval=float(os.environ.get("RECOGNIZER_INDEX_REFRESH", "900")))
