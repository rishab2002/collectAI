"""Fetching the catalog index from the app (Railway), and the dual-stack bind."""

from __future__ import annotations

import json
import socket
import tempfile
import threading
import unittest
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from collectai_recognizer import index_sync
from collectai_recognizer.server import IndexCache, make_server


class FakeApp:
    """The app's /internal/recognition-index, as RecognitionIndexController answers it."""

    def __init__(self, token="t0ken", origin_secret=None):
        self.token, self.origin_secret = token, origin_secret
        self.body = json.dumps({"format": "collectai-recognition-index", "version": 1,
                                "castings": [], "variations": []}).encode()
        self.etag = '"v1"'
        self.requests: list[dict] = []
        app = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                app.requests.append(dict(self.headers))
                if app.origin_secret and self.headers.get("X-Origin-Auth") != app.origin_secret:
                    self.send_response(403); self.end_headers(); return
                if self.headers.get("Authorization") != f"Bearer {app.token}":
                    self.send_response(404); self.end_headers(); return
                if self.headers.get("If-None-Match") == app.etag:
                    self.send_response(304); self.end_headers(); return
                self.send_response(200)
                self.send_header("ETag", app.etag)
                self.send_header("Content-Length", str(len(app.body)))
                self.end_headers()
                self.wfile.write(app.body)

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()
        self.url = f"http://127.0.0.1:{self.httpd.server_address[1]}/internal/recognition-index"

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


class IndexSyncTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = Path(self.dir.name) / "sub" / "catalog-index.json"

    def tearDown(self):
        self.dir.cleanup()

    def test_fetches_the_index_with_both_secrets_and_the_cache_loads_it(self):
        app = FakeApp(origin_secret="s" * 64)
        try:
            sync = index_sync.IndexSync(app.url, str(self.path), "t0ken", "s" * 64)
            self.assertTrue(sync.fetch())
            self.assertEqual(app.requests[0]["Authorization"], "Bearer t0ken")
            self.assertEqual(app.requests[0]["X-Origin-Auth"], "s" * 64)
            self.assertEqual(self.path.read_bytes(), app.body)
            self.assertIsNotNone(IndexCache(str(self.path)).get(), "the fetched file is a loadable index")
            self.assertEqual([], [p for p in self.path.parent.iterdir() if p.name.endswith(".tmp")])
        finally:
            app.close()

    def test_an_unchanged_index_is_a_304_and_the_file_is_kept(self):
        app = FakeApp()
        try:
            sync = index_sync.IndexSync(app.url, str(self.path), "t0ken")
            self.assertTrue(sync.fetch())
            mtime = self.path.stat().st_mtime_ns
            self.assertTrue(sync.fetch())
            self.assertEqual(app.requests[1]["If-None-Match"], '"v1"')
            self.assertEqual(self.path.stat().st_mtime_ns, mtime)
        finally:
            app.close()

    def test_a_wrong_token_keeps_the_last_good_file_and_says_what_to_check(self):
        app = FakeApp()
        try:
            self.assertTrue(index_sync.IndexSync(app.url, str(self.path), "t0ken").fetch())
            app.body = b"must not be written"
            app.etag = '"v2"'
            sync = index_sync.IndexSync(app.url, str(self.path), "wrong")
            self.assertFalse(sync.fetch())
            self.assertIn("RECOGNIZER_INDEX_TOKEN", sync.last_error)
            self.assertIn(b"collectai-recognition-index", self.path.read_bytes())
        finally:
            app.close()

    def test_a_missing_origin_secret_is_named(self):
        app = FakeApp(origin_secret="s" * 64)
        try:
            sync = index_sync.IndexSync(app.url, str(self.path), "t0ken")
            self.assertFalse(sync.fetch())
            self.assertIn("ORIGIN_SECRET", sync.last_error)
            self.assertFalse(self.path.exists())
        finally:
            app.close()

    def test_an_unreachable_app_is_a_failed_poll_not_a_crash(self):
        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            port = s.getsockname()[1]
        sync = index_sync.IndexSync(f"http://127.0.0.1:{port}/x", str(self.path), "t0ken", timeout=2)
        self.assertFalse(sync.fetch())
        self.assertIn("cannot reach", sync.last_error)

    def test_configured_only_by_url_and_never_without_a_token(self):
        import os
        from unittest import mock

        with mock.patch.dict(os.environ, {}, clear=True):
            self.assertIsNone(index_sync.from_env(str(self.path)))
        with mock.patch.dict(os.environ, {"RECOGNIZER_INDEX_URL": "http://web/x"}, clear=True):
            with self.assertRaises(SystemExit):
                index_sync.from_env(str(self.path))
        with mock.patch.dict(os.environ, {"RECOGNIZER_INDEX_URL": "http://web/x", "RECOGNIZER_INDEX_TOKEN": "t",
                                          "ORIGIN_SECRET": "o", "RECOGNIZER_INDEX_REFRESH": "60"}, clear=True):
            sync = index_sync.from_env(str(self.path))
            self.assertEqual((sync.token, sync.origin_secret, sync.interval), ("t", "o", 60.0))


class DualStackTest(unittest.TestCase):
    def test_host_colon_colon_answers_on_ipv4_too(self):
        if not socket.has_ipv6:
            self.skipTest("no IPv6 in this Python")
        try:
            probe = socket.socket(socket.AF_INET6)
            probe.bind(("::", 0))
            probe.close()
        except OSError:
            self.skipTest("this machine has no IPv6")

        class Hello(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                self.send_response(200); self.end_headers(); self.wfile.write(b"hi")

        httpd = make_server("::", 0, Hello)
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        try:
            port = httpd.server_address[1]
            self.assertEqual(urllib.request.urlopen(f"http://127.0.0.1:{port}/", timeout=5).read(), b"hi")
            self.assertEqual(urllib.request.urlopen(f"http://[::1]:{port}/", timeout=5).read(), b"hi")
        finally:
            httpd.shutdown()
            httpd.server_close()

    def test_an_ipv4_host_stays_ipv4(self):
        httpd = make_server("127.0.0.1", 0, BaseHTTPRequestHandler)
        try:
            self.assertEqual(httpd.address_family, socket.AF_INET)
        finally:
            httpd.server_close()


if __name__ == "__main__":
    unittest.main()
