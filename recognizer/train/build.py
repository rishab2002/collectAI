"""Train the visual model from labelled photos, measure it, and write it out.

    python -m train.build --data DIR [--data DIR ...] --model-dir MODEL_DIR

Each DIR is what `php artisan catalog:export-training-data` writes: a
manifest.jsonl (one labelled image per line) and the images beside it. Several
can be combined — collectors' consented scans, your own photos, licensed wiki
photos, or any folder you label in the same format.

What it does, in order:

1. Embeds every image with MODEL_DIR/encoder.onnx (train.export_encoder),
   through the same preprocessing the recognizer uses. Cached by file hash, so
   re-running after adding photos only embeds the new ones.
2. Holds out photos to test on: for every casting with 2+ photos, a share of
   them (never the last one) becomes a query against the rest.
3. Fits a head — within-class covariance normalisation (WCCN), which shrinks
   the directions in which photos of the *same* car vary (lighting, angle,
   background) — and keeps it only if it measurably improves held-out top-1.
4. Calibrates: picks the lowest similarity and margin at which held-out
   answers reach --precision (default 0.95) on at least --min-support
   answers. If no setting does, or there is too little held-out data, looks
   alone are never trusted (visual_only=false) and the model only
   corroborates text matches and orders versions.
5. Writes model.npz (head + gallery of every image) and manifest.json
   (sources, counts, measured accuracy, thresholds). The recognizer reloads it.
"""

from __future__ import annotations

import argparse
import json
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import cv2
import numpy as np

from collectai_recognizer.visual import Encoder, Head, normalise

from .cache import EmbeddingCache, file_hash


# ---------------------------------------------------------------------------
# Data


def read_manifests(dirs: list[str]) -> list[dict]:
    rows = []
    for d in dirs:
        base = Path(d)
        if not (base / "manifest.jsonl").is_file():
            print(f"  note: {base} has no manifest.jsonl yet — skipped", flush=True)
            continue
        for line in (base / "manifest.jsonl").read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            row = json.loads(line)
            if not row.get("casting_key") and not row.get("casting_id"):
                continue
            # A row has an image file, or (wiki photos, never kept) only the
            # key of a vector train.collect already put in the cache.
            row["path"] = str(base / row["image"]) if row.get("image") else None
            rows.append(row)
    return rows


def dedupe(rows: list[dict], keys: list[str]) -> tuple[list[dict], list[str]]:
    """One row per (photo, casting): the same file labelled twice would otherwise
    sit on both sides of the held-out split and flatter the measurement."""
    seen, out_rows, out_keys = set(), [], []
    for row, key in zip(rows, keys):
        label = row.get("casting_key") or row.get("casting_id")
        if (key, label) in seen:
            continue
        seen.add((key, label))
        out_rows.append(row)
        out_keys.append(key)
    return out_rows, out_keys


def embed_all(rows: list[dict], encoder, cache: EmbeddingCache) -> tuple[list[dict], list[str]]:
    """Every row's cache key, embedding any image file not yet cached.
    Rows whose vector can't be found (a key-only row from another encoder) are dropped."""
    keys = [row.get("key") or file_hash(Path(row["path"])) for row in rows]
    todo = [(k, r["path"]) for k, r in zip(keys, rows) if k not in cache and r["path"]]
    todo = list(dict(todo).items())
    for i in range(0, len(todo), 32):
        chunk = todo[i:i + 32]
        loaded = [(k, cv2.imread(path, cv2.IMREAD_COLOR)) for k, path in chunk]
        loaded = [(k, im) for k, im in loaded if im is not None]
        if loaded:
            for (k, _), v in zip(loaded, encoder.embed([im for _, im in loaded])):
                cache.put(k, v)
        print(f"  embedded {min(i + 32, len(todo))}/{len(todo)} new images", flush=True)
    cache.save()
    kept = [(r, k) for r, k in zip(rows, keys) if k in cache]
    if len(kept) < len(rows):
        print(f"  {len(rows) - len(kept)} row(s) had no vector and were left out", flush=True)
    return [r for r, _ in kept], [k for _, k in kept]


# ---------------------------------------------------------------------------
# Learning and measuring — pure functions, tested in tests/test_training.py


def split(labels: np.ndarray, holdout: float, seed: int = 7, groups: np.ndarray | None = None) -> tuple[np.ndarray, np.ndarray]:
    """Indices (train, test), holding out whole *groups* within each class.

    Groups are versions (variations): a held-out photo is then of a version of
    the car the model never saw — a red one when it learned from the blue one —
    which is the honest test. Only classes with 2+ groups give test images, and
    every class keeps at least one group for training. Without groups, each
    image is its own group."""
    if groups is None:
        groups = np.arange(len(labels))
    rng = np.random.default_rng(seed)
    train, test = [], []
    for label in np.unique(labels):
        idx = np.flatnonzero(labels == label)
        ids = np.unique(groups[idx])
        rng.shuffle(ids)
        n_test = int(len(ids) * holdout) if len(ids) >= 2 else 0
        n_test = min(max(n_test, 1 if len(ids) >= 2 else 0), len(ids) - 1)
        held = set(ids[:n_test].tolist())
        for i in idx:
            (test if groups[i] in held else train).append(i)
    return np.array(sorted(train), dtype=int), np.array(sorted(test), dtype=int)


def fit_wccn(x: np.ndarray, labels: np.ndarray, reg: float = 0.1, min_classes: int = 20) -> Head | None:
    """Within-class covariance normalisation. None when too few classes repeat."""
    x = normalise(x)
    groups = [x[labels == c] for c in np.unique(labels) if (labels == c).sum() >= 2]
    if len(groups) < min_classes:
        return None
    centred = np.concatenate([g - g.mean(axis=0) for g in groups])
    cov = centred.T @ centred / len(centred)
    cov = cov + reg * np.trace(cov) / cov.shape[0] * np.eye(cov.shape[0])
    vals, vecs = np.linalg.eigh(cov)
    matrix = vecs @ np.diag(1.0 / np.sqrt(np.maximum(vals, 1e-9))) @ vecs.T
    return Head(mean=x.mean(axis=0).astype(np.float32), matrix=matrix.astype(np.float32))


def evaluate(gallery: np.ndarray, gallery_labels: np.ndarray, queries: np.ndarray, query_labels: np.ndarray) -> dict:
    """Nearest-neighbour by casting: top-1/top-5, and each query's (best, margin, correct)."""
    # Gallery sorted by class so each class is one contiguous block, then the
    # best similarity per class with reduceat — in chunks of queries, so tens
    # of thousands of photos never need the whole similarity matrix at once.
    by_class = np.argsort(gallery_labels, kind="stable")
    gallery, gallery_labels = gallery[by_class], gallery_labels[by_class]
    classes, starts = np.unique(gallery_labels, return_index=True)
    per_class = np.empty((len(queries), len(classes)), dtype=np.float32)
    for i in range(0, len(queries), 256):
        per_class[i:i + 256] = np.maximum.reduceat(queries[i:i + 256] @ gallery.T, starts, axis=1)
    order = np.argsort(-per_class, axis=1)
    ranked = classes[order]
    best = per_class[np.arange(len(queries)), order[:, 0]]
    second = per_class[np.arange(len(queries)), order[:, 1]] if len(classes) > 1 else np.zeros(len(queries))
    correct = ranked[:, 0] == query_labels
    return {
        "queries": int(len(queries)),
        "top1": float(correct.mean()) if len(queries) else 0.0,
        "top5": float((ranked[:, :5] == query_labels[:, None]).any(axis=1).mean()) if len(queries) else 0.0,
        "_best": best, "_margin": best - second, "_correct": correct,
    }


def familiarity_gate(raw_best_known: np.ndarray, percentile: float = 2.0) -> float:
    """The base model's similarity a photo needs to its nearest training photo
    to count as "a photo like the ones it learned from": what 98% of real
    held-out car photos reach. Things that aren't diecast photos at all — and
    measured, flat shapes score ~0.5 against real photos' ~0.7+ — fall below it."""
    return float(np.percentile(raw_best_known, percentile)) if len(raw_best_known) else 1.0


def raw_best(queries: np.ndarray, gallery: np.ndarray) -> np.ndarray:
    out = np.empty(len(queries), dtype=np.float32)
    for i in range(0, len(queries), 256):
        out[i:i + 256] = (queries[i:i + 256] @ gallery.T).max(axis=1)
    return out


def calibrate(best: np.ndarray, margin: np.ndarray, correct: np.ndarray, precision: float, min_support: int,
              eligible: np.ndarray | None = None) -> dict:
    """The loosest (similarity, margin) whose accepted answers reach `precision`
    over at least `min_support` answers — most coverage at the required precision.
    `eligible` marks answers allowed at all (the familiarity gate); the rest
    count as declined."""
    if eligible is None:
        eligible = np.ones(len(best), dtype=bool)
    choice = {"visual_only": False, "min_similarity": 1.0, "min_margin": 1.0, "precision": None, "coverage": 0.0, "support": 0}
    if len(best) < min_support:
        choice["reason"] = f"only {len(best)} held-out answers; need {min_support} to measure precision"
        return choice
    for s in np.round(np.arange(0.10, 0.99, 0.01), 2):
        for m in (0.0, 0.01, 0.02, 0.03, 0.05, 0.08, 0.12, 0.16, 0.2):
            accept = (best >= s) & (margin >= m) & eligible
            n = int(accept.sum())
            if n < min_support:
                continue
            p = float(correct[accept].mean())
            if p >= precision and n / len(best) > choice["coverage"]:
                choice = {"visual_only": True, "min_similarity": float(s), "min_margin": float(m),
                          "precision": round(p, 3), "coverage": round(n / len(best), 3), "support": n}
    if not choice["visual_only"]:
        choice["reason"] = f"no setting reached {precision:.0%} precision on {min_support}+ held-out answers"
    return choice


# ---------------------------------------------------------------------------


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--data", action="append", required=True, help="a catalog:export-training-data directory; repeatable")
    p.add_argument("--model-dir", required=True)
    p.add_argument("--holdout", type=float, default=0.2)
    p.add_argument("--precision", type=float, default=0.95, help="precision visual-only answers must reach on held-out photos")
    p.add_argument("--min-support", type=int, default=30, help="held-out answers needed before precision counts as measured")
    p.add_argument("--exclude-source", action="append", default=[], help="leave out rows whose source is this; repeatable")
    p.add_argument("--open-set", type=float, default=0.1,
                   help="share of castings held out entirely, as cars the model doesn't know; any answer for them is wrong")
    args = p.parse_args(argv)

    model_dir = Path(args.model_dir)
    rows = [r for r in read_manifests(args.data) if r.get("source") not in set(args.exclude_source)]
    if not rows:
        raise SystemExit("no labelled images found")
    encoder = Encoder(model_dir / "encoder.onnx")
    cache = EmbeddingCache(model_dir / "embeddings-cache.npz", model_dir / "encoder.onnx")

    rows, keys = embed_all(rows, encoder, cache)
    rows, keys = dedupe(rows, keys)
    print(f"{len(rows)} labelled images, {len({r['casting_id'] for r in rows})} castings")
    raw = np.stack([cache[k] for k in keys]).astype(np.float32)
    # Labels are stable keys where the record has provenance, so a model
    # trained on one database serves another; `id:<n>` otherwise.
    labels = np.array([r.get("casting_key") or f"id:{int(r['casting_id'])}" for r in rows])
    variations = np.array([r.get("variation_key") or (f"id:{int(r['variation_id'])}" if r.get("variation_id") else "") for r in rows])
    # Group = version where known, else the photo itself.
    groups = np.array([f"v{v}" if v else f"k{k}" for v, k in zip(variations, keys)])

    train_idx, test_idx = split(labels, args.holdout, groups=groups)
    # Cars the model has never seen at all. A collector's car is often one
    # (thousands of catalog castings have no photo yet), and the right answer
    # for it is silence — so these are part of the calibration, as queries
    # whose every answer is wrong. Without them the thresholds are tuned only
    # on cars that are in the gallery, and anything car-shaped gets named.
    castings = np.unique(labels)
    open_idx = np.array([], dtype=int)
    if len(castings) >= 20 and args.open_set > 0:
        held = np.random.default_rng(11).choice(castings, max(1, int(len(castings) * args.open_set)), replace=False)
        is_open = np.isin(labels, held)
        open_idx = np.flatnonzero(is_open)
        train_idx, test_idx = train_idx[~is_open[train_idx]], test_idx[~is_open[test_idx]]

    plain_all = normalise(raw)
    queries = np.concatenate([test_idx, open_idx]).astype(int)
    familiarity = raw_best(plain_all[queries], plain_all[train_idx]) if len(queries) else np.zeros(0)
    gate = familiarity_gate(familiarity[:len(test_idx)])

    def measure(vectors):
        known = evaluate(vectors[train_idx], labels[train_idx], vectors[test_idx], labels[test_idx])
        if len(open_idx):
            unknown = evaluate(vectors[train_idx], labels[train_idx], vectors[open_idx], np.full(len(open_idx), "", dtype=labels.dtype))
            for k in ("_best", "_margin", "_correct"):
                known[k] = np.concatenate([known[k], unknown[k]])
        known["unknown_cars"] = int(len(open_idx))
        return known

    plain = normalise(raw)
    results = {"plain": measure(plain) if len(test_idx) else None}

    head = fit_wccn(raw[train_idx], labels[train_idx])
    if head is not None and len(test_idx):
        results["wccn"] = measure(head.apply(raw))
    use_head = bool(results.get("wccn") and results["wccn"]["top1"] >= results["plain"]["top1"] + 0.01)
    chosen = "wccn" if use_head else "plain"

    if results[chosen] is not None:
        r = results[chosen]
        thresholds = calibrate(r["_best"], r["_margin"], r["_correct"], args.precision, args.min_support,
                               eligible=familiarity >= gate)
        thresholds["min_familiarity"] = round(gate, 4)
    else:
        thresholds = {"visual_only": False, "min_similarity": 1.0, "min_margin": 1.0,
                      "reason": "no casting has two photos yet, so nothing could be held out and measured"}

    # Final model: refit on everything, gallery of every image.
    if use_head:
        head = fit_wccn(raw, labels)
    final = head.apply(raw) if use_head and head is not None else normalise(raw)
    model_dir.mkdir(parents=True, exist_ok=True)
    arrays = {"vectors": final.astype(np.float16), "raw_vectors": plain_all.astype(np.float16),
              "casting_labels": labels, "variation_labels": variations}
    if use_head and head is not None:
        arrays |= {"head_mean": head.mean, "head_matrix": head.matrix}
    np.savez_compressed(model_dir / "model.npz", **arrays)

    public = {k: {kk: vv for kk, vv in v.items() if not kk.startswith("_")} if v else None for k, v in results.items()}
    manifest = {
        "format": "collectai-visual-model",
        "version": 1,
        "trained_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "encoder": (model_dir / "NOTICE-encoder.txt").read_text(encoding="utf-8").strip() if (model_dir / "NOTICE-encoder.txt").is_file() else "encoder.onnx",
        "images": len(rows),
        "castings": int(len(np.unique(labels))),
        "sources": dict(Counter(r.get("source", "unknown") for r in rows)),
        "licences": dict(Counter(r.get("licence") or "own/consented" for r in rows)),
        "head": "wccn" if use_head else None,
        "evaluation": {"holdout_images": int(len(test_idx)), "chosen": chosen, **public},
        "thresholds": thresholds,
    }
    (model_dir / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")

    print(json.dumps({k: manifest[k] for k in ("images", "castings", "sources", "head", "evaluation", "thresholds")}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
