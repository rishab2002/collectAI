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
import hashlib
import json
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import cv2
import numpy as np

from collectai_recognizer.visual import Encoder, Head, normalise


# ---------------------------------------------------------------------------
# Data


def read_manifests(dirs: list[str]) -> list[dict]:
    rows = []
    for d in dirs:
        base = Path(d)
        for line in (base / "manifest.jsonl").read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            row = json.loads(line)
            if not row.get("casting_id"):
                continue
            row["path"] = str(base / row["image"])
            rows.append(row)
    return rows


def embed_all(rows: list[dict], encoder, cache_path: Path) -> np.ndarray:
    cache: dict[str, np.ndarray] = {}
    if cache_path.is_file():
        data = np.load(cache_path)
        cache = dict(zip(data["keys"].tolist(), data["vectors"]))
    keys, todo = [], []
    for row in rows:
        key = hashlib.sha1(Path(row["path"]).read_bytes()).hexdigest()
        keys.append(key)
        if key not in cache:
            todo.append((key, row["path"]))
    for i in range(0, len(todo), 32):
        chunk = todo[i:i + 32]
        images = [cv2.imread(p, cv2.IMREAD_COLOR) for _, p in chunk]
        ok = [(k, im) for (k, _), im in zip(chunk, images) if im is not None]
        if ok:
            vecs = encoder.embed([im for _, im in ok])
            for (k, _), v in zip(ok, vecs):
                cache[k] = v
        print(f"  embedded {min(i + 32, len(todo))}/{len(todo)} new images", flush=True)
    if todo:
        np.savez(cache_path, keys=np.array(list(cache)), vectors=np.stack(list(cache.values())))
    missing = [r["path"] for r, k in zip(rows, keys) if k not in cache]
    if missing:
        raise SystemExit(f"could not read {len(missing)} image(s), e.g. {missing[0]}")
    return np.stack([cache[k] for k in keys]).astype(np.float32)


# ---------------------------------------------------------------------------
# Learning and measuring — pure functions, tested in tests/test_training.py


def split(labels: np.ndarray, holdout: float, seed: int = 7) -> tuple[np.ndarray, np.ndarray]:
    """Indices (train, test). Only classes with 2+ images give test images, and
    every class keeps at least one training image."""
    rng = np.random.default_rng(seed)
    train, test = [], []
    for label in np.unique(labels):
        idx = np.flatnonzero(labels == label)
        rng.shuffle(idx)
        n_test = int(len(idx) * holdout) if len(idx) >= 2 else 0
        n_test = min(max(n_test, 1 if len(idx) >= 2 else 0), len(idx) - 1)
        test.extend(idx[:n_test])
        train.extend(idx[n_test:])
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
    sims = queries @ gallery.T
    classes = np.unique(gallery_labels)
    per_class = np.full((len(queries), len(classes)), -2.0, dtype=np.float32)
    for j, c in enumerate(classes):
        per_class[:, j] = sims[:, gallery_labels == c].max(axis=1)
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


def calibrate(best: np.ndarray, margin: np.ndarray, correct: np.ndarray, precision: float, min_support: int) -> dict:
    """The loosest (similarity, margin) whose accepted answers reach `precision`
    over at least `min_support` answers — most coverage at the required precision."""
    choice = {"visual_only": False, "min_similarity": 1.0, "min_margin": 1.0, "precision": None, "coverage": 0.0, "support": 0}
    if len(best) < min_support:
        choice["reason"] = f"only {len(best)} held-out answers; need {min_support} to measure precision"
        return choice
    for s in np.round(np.arange(0.30, 0.99, 0.01), 2):
        for m in (0.0, 0.01, 0.02, 0.03, 0.05, 0.08, 0.12):
            accept = (best >= s) & (margin >= m)
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
    args = p.parse_args(argv)

    model_dir = Path(args.model_dir)
    rows = [r for r in read_manifests(args.data) if r.get("source") not in set(args.exclude_source)]
    if not rows:
        raise SystemExit("no labelled images found")
    encoder = Encoder(model_dir / "encoder.onnx")

    print(f"{len(rows)} labelled images, {len({r['casting_id'] for r in rows})} castings")
    raw = embed_all(rows, encoder, model_dir / "embeddings-cache.npz")
    labels = np.array([int(r["casting_id"]) for r in rows])
    variations = np.array([int(r.get("variation_id") or 0) for r in rows])

    train_idx, test_idx = split(labels, args.holdout)
    plain = normalise(raw)
    results = {"plain": evaluate(plain[train_idx], labels[train_idx], plain[test_idx], labels[test_idx]) if len(test_idx) else None}

    head = fit_wccn(raw[train_idx], labels[train_idx])
    if head is not None and len(test_idx):
        projected = head.apply(raw)
        results["wccn"] = evaluate(projected[train_idx], labels[train_idx], projected[test_idx], labels[test_idx])
    use_head = bool(results.get("wccn") and results["wccn"]["top1"] >= results["plain"]["top1"] + 0.01)
    chosen = "wccn" if use_head else "plain"

    if results[chosen] is not None:
        r = results[chosen]
        thresholds = calibrate(r["_best"], r["_margin"], r["_correct"], args.precision, args.min_support)
    else:
        thresholds = {"visual_only": False, "min_similarity": 1.0, "min_margin": 1.0,
                      "reason": "no casting has two photos yet, so nothing could be held out and measured"}

    # Final model: refit on everything, gallery of every image.
    if use_head:
        head = fit_wccn(raw, labels)
    final = head.apply(raw) if use_head and head is not None else normalise(raw)
    model_dir.mkdir(parents=True, exist_ok=True)
    arrays = {"vectors": final.astype(np.float16), "casting_ids": labels, "variation_ids": variations}
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
