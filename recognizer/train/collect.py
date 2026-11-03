"""Download the wiki photos named in a labels file, embed them, keep only the vectors.

    python -m train.collect --labels wiki-labels-hot-wheels.jsonl --model-dir MODEL_DIR --out DATA_DIR

The labels come from `php artisan catalog:export-wiki-photo-labels`. Used under
Fandom's written consent to the owner, for training only (CLAUDE.md,
Recognition): **no photo is written to disk.** Each one is fetched (a 448 px
thumbnail, through the wiki's API and image server, with an identifying user
agent and a request-rate limit), embedded in memory and dropped. What remains is
its 384-number vector in MODEL_DIR/embeddings-cache.npz and one manifest line
per label in DATA_DIR/manifest.jsonl — the input to `python -m train.build`.

Resumable: files already embedded, or already found missing, are skipped, so it
can be stopped and started at will.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import threading
import time
import urllib.parse
import urllib.request
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import cv2
import numpy as np

from collectai_recognizer.visual import Encoder

from .cache import EmbeddingCache

PLACEHOLDER = re.compile(r"not[ _]?available|^File:INA\b|no[ _]?(image|photo|picture)|placeholder|coming[ _]soon", re.I)

USER_AGENT = "collectAI-training/1.0 (+https://collectai.club; training under written permission from Fandom)"


class RateLimit:
    def __init__(self, per_second: float):
        self.interval = 1.0 / per_second
        self.lock = threading.Lock()
        self.next = 0.0

    def wait(self) -> None:
        with self.lock:
            now = time.monotonic()
            delay = self.next - now
            self.next = max(now, self.next) + self.interval
        if delay > 0:
            time.sleep(delay)


def fetch(url: str, limit: RateLimit, tries: int = 4) -> bytes | None:
    for attempt in range(tries):
        limit.wait()
        try:
            req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read()
        except urllib.error.HTTPError as e:
            if e.code in (429, 503):
                time.sleep(2 ** attempt * 5)          # back off when asked to
                continue
            if e.code == 404:
                return None
            time.sleep(2 ** attempt)
        except Exception:
            time.sleep(2 ** attempt)
    return None


def describe(api: str, titles: list[str], width: int, limit: RateLimit) -> dict[str, dict]:
    """File title -> {thumb, sha1} for up to 50 titles in one API call."""
    q = urllib.parse.urlencode({
        "action": "query", "format": "json", "formatversion": "2", "prop": "imageinfo",
        "iiprop": "url|sha1|mime", "iiurlwidth": str(width), "titles": "|".join(titles), "maxlag": "5",
    })
    body = fetch(f"{api}?{q}", limit)
    if body is None:
        return {}
    data = json.loads(body)
    names = {n["to"]: n["from"] for n in data.get("query", {}).get("normalized", [])}
    out = {}
    for page in data.get("query", {}).get("pages", []):
        info = (page.get("imageinfo") or [None])[0]
        title = names.get(page["title"], page["title"])
        if info and info.get("mime", "").startswith("image/") and "svg" not in info.get("mime", ""):
            out[title] = {"thumb": info.get("thumburl") or info.get("url"), "sha1": info.get("sha1") or hashlib.sha1(title.encode()).hexdigest()}
    return out


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--labels", action="append", required=True)
    p.add_argument("--model-dir", required=True)
    p.add_argument("--out", required=True, help="training-data directory to append the manifest to")
    p.add_argument("--rate", type=float, default=4.0, help="requests per second, all kinds together")
    p.add_argument("--width", type=int, default=448)
    p.add_argument("--limit", type=int, default=0, help="stop after this many new photos (0 = all)")
    args = p.parse_args(argv)

    model_dir, out = Path(args.model_dir), Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    encoder = Encoder(model_dir / "encoder.onnx")
    cache = EmbeddingCache(model_dir / "embeddings-cache.npz", model_dir / "encoder.onnx")
    manifest_path, missing_path = out / "manifest.jsonl", out / "missing-wiki-files.txt"

    # A row only counts as done if its vector really is in the cache: a run
    # stopped between saves can leave rows whose vectors were never written.
    done = set()
    if manifest_path.is_file():
        good, orphaned = [], 0
        for line in manifest_path.read_text(encoding="utf-8").splitlines():
            row = json.loads(line)
            if row.get("key") and row["key"] not in cache:
                orphaned += 1
                continue
            good.append(line)
            if row.get("file"):
                done.add((row.get("api"), row["file"]))
        if orphaned:
            manifest_path.write_text("".join(l + "\n" for l in good), encoding="utf-8")
            print(f"dropped {orphaned} row(s) whose vectors were never saved; they'll be fetched again", flush=True)
    if missing_path.is_file():
        done |= {tuple(l.split("\t", 1)) for l in missing_path.read_text(encoding="utf-8").splitlines() if "\t" in l}

    labels: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for path in args.labels:
        for line in Path(path).read_text(encoding="utf-8").splitlines():
            row = json.loads(line)
            labels[(row["api"], row["file"])].append(row)
    # Placeholders ("Image Not Available.jpg", "INA.jpg") stand in for
    # thousands of different cars; one would teach the model that they all
    # look alike. A real photo shows one casting, so a file labelled for more
    # than a few castings is not a photo of any of them.
    placeholders = {k for k, rows in labels.items()
                    if len({r["casting_id"] for r in rows}) > 3 or PLACEHOLDER.search(k[1])}
    for k in placeholders:
        del labels[k]
    if placeholders:
        print(f"skipping {len(placeholders)} placeholder/shared file(s)", flush=True)

    todo = [k for k in labels if k not in done]
    print(f"{len(labels)} wiki files labelled, {len(labels) - len(todo)} already done, {len(todo)} to go"
          + (f", fetching {args.limit} this run" if args.limit else ""), flush=True)
    if args.limit:
        todo = todo[:args.limit]

    limit = RateLimit(args.rate)
    manifest = manifest_path.open("a", encoding="utf-8")
    buffered: list[str] = []          # rows wait here until their vectors are saved
    missing = missing_path.open("a", encoding="utf-8")
    started, fetched = time.monotonic(), 0

    def prepare(chunk):
        """Look the files up and start downloading them; returns at once."""
        by_api = defaultdict(list)
        for api, file in chunk:
            by_api[api].append(file)
        infos = {}
        for api, files in by_api.items():
            for title, info in describe(api, files, args.width, limit).items():
                infos[(api, title)] = info
        wanted = [k for k in chunk if k in infos and f"wiki:{infos[k]['sha1']}" not in cache]
        return chunk, infos, wanted, [pool.submit(fetch, infos[k]["thumb"], limit) for k in wanted]

    chunks = [todo[i:i + 50] for i in range(0, len(todo), 50)]
    with ThreadPoolExecutor(max_workers=6) as pool:
        pending = prepare(chunks[0]) if chunks else None
        for n in range(len(chunks)):
            chunk, infos, wanted, futures = pending
            # The next batch downloads while this one is embedded.
            pending = prepare(chunks[n + 1]) if n + 1 < len(chunks) else None

            images, keys = [], []
            for k, future in zip(wanted, futures):
                blob = future.result()
                img = cv2.imdecode(np.frombuffer(blob, np.uint8), cv2.IMREAD_COLOR) if blob else None
                if img is not None:
                    images.append(img)
                    keys.append(f"wiki:{infos[k]['sha1']}")
            if images:
                for key, vec in zip(keys, encoder.embed(images)):
                    cache.put(key, vec)
            del images, futures                   # the photos go here; only vectors stay

            for k in chunk:
                key = f"wiki:{infos[k]['sha1']}" if k in infos else None
                if key is None or key not in cache:
                    missing.write(f"{k[0]}\t{k[1]}\n")
                    continue
                for row in labels[k]:
                    buffered.append(json.dumps({
                        "key": key, "source": "wiki_consented", "licence": "Fandom written consent (training only)",
                        "casting_id": row["casting_id"], "variation_id": row["variation_id"],
                        "casting_key": row.get("casting_key"), "variation_key": row.get("variation_key"),
                        "file": row["file"], "api": row["api"], "page_url": row["page_url"],
                    }) + "\n")
                fetched += 1
            missing.flush()
            if n % 10 == 9 or n + 1 == len(chunks):
                cache.save()
                manifest.writelines(buffered)
                manifest.flush()
                buffered.clear()
                rate = fetched / max(1e-9, time.monotonic() - started)
                print(f"  {min((n + 1) * 50, len(todo))}/{len(todo)} files · {fetched} embedded · {rate:.1f}/s", flush=True)

    cache.save()
    manifest.writelines(buffered)
    manifest.close()
    missing.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
