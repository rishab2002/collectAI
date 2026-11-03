"""Recognising a car by how it looks — the trained part of the recognizer.

Phase 3 of docs/multi-car-recognition.md. A small, openly licensed image model
(DINOv2-small, Apache 2.0, run with ONNX Runtime) turns a crop into a vector;
the "trained model" is that encoder plus what was learned from labelled photos:
an optional projection (the head) and a gallery of vectors, each labelled with
its casting and variation. A crop is recognised by its nearest neighbours.

The model directory (`RECOGNIZER_MODEL_DIR`, default /data/model) is written
by `python -m train.build` and holds:

    encoder.onnx    the base image model (see NOTICE in manifest.json)
    model.npz       head (mean + projection) and gallery (vectors + labels)
    manifest.json   counts, sources, measured accuracy and the thresholds
                    a visual-only answer must clear

**A visual match is only ever offered on its own if training measured it.**
`manifest.thresholds.visual_only` is true only when held-out photos showed the
chosen similarity and margin reach the target precision. Otherwise looks are
used as corroboration — they order versions and nudge a text match — never as
the reason a car is offered.

Runtime needs numpy and onnxruntime only; training needs PyTorch (train/).
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, field
from pathlib import Path

import cv2
import numpy as np

SIZE = 224
MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32)
STD = np.array([0.229, 0.224, 0.225], dtype=np.float32)


def preprocess(bgr: np.ndarray, size: int = SIZE) -> np.ndarray:
    """Letterbox to a square (the whole car, never cropped), RGB, normalised, CHW.

    Training and recognition both come through here — one definition, so the
    two can't disagree about what a photo looks like to the model.
    """
    h, w = bgr.shape[:2]
    side = max(h, w)
    canvas = np.full((side, side, 3), (124, 116, 104), dtype=np.uint8)
    y, x = (side - h) // 2, (side - w) // 2
    canvas[y:y + h, x:x + w] = bgr
    rgb = cv2.cvtColor(cv2.resize(canvas, (size, size), interpolation=cv2.INTER_AREA), cv2.COLOR_BGR2RGB)
    return ((rgb.astype(np.float32) / 255.0 - MEAN) / STD).transpose(2, 0, 1)


def normalise(v: np.ndarray) -> np.ndarray:
    n = np.linalg.norm(v, axis=-1, keepdims=True)
    return v / np.maximum(n, 1e-12)


class Encoder:
    """The ONNX image model. Anything with `embed(list[bgr]) -> (n, d)` will do in tests."""

    def __init__(self, path: str | Path, threads: int = 0):
        import onnxruntime as ort

        opts = ort.SessionOptions()
        if threads:
            opts.intra_op_num_threads = threads
        self.session = ort.InferenceSession(str(path), opts, providers=["CPUExecutionProvider"])
        self.input = self.session.get_inputs()[0].name

    def embed(self, images: list[np.ndarray], batch: int = 16) -> np.ndarray:
        out = []
        for i in range(0, len(images), batch):
            x = np.stack([preprocess(im) for im in images[i:i + batch]])
            out.append(self.session.run(None, {self.input: x})[0])
        return np.concatenate(out).astype(np.float32) if out else np.zeros((0, 0), np.float32)


@dataclass
class Head:
    """Learned projection: z = normalise((x - mean) @ matrix). Identity when absent."""

    mean: np.ndarray | None = None
    matrix: np.ndarray | None = None

    def apply(self, x: np.ndarray) -> np.ndarray:
        x = normalise(x)
        if self.matrix is not None:
            x = (x - self.mean) @ self.matrix
        return normalise(x)


@dataclass
class VisualMatch:
    casting_id: int
    score: float                                     # cosine similarity, -1..1
    variations: dict[int, float] = field(default_factory=dict)   # variation id -> best similarity


@dataclass
class VisualResult:
    candidates: list[VisualMatch]
    visual_only: bool
    min_similarity: float
    min_margin: float
    familiarity: float = 1.0          # base-model similarity to the nearest training photo
    min_familiarity: float = 0.0

    @property
    def top(self) -> VisualMatch | None:
        return self.candidates[0] if self.candidates else None

    @property
    def margin(self) -> float:
        if not self.candidates:
            return 0.0
        return self.candidates[0].score - (self.candidates[1].score if len(self.candidates) > 1 else 0.0)

    @property
    def sure(self) -> bool:
        """Clears the bar training measured — the only way looks alone name a car."""
        return bool(self.visual_only and self.top and self.familiarity >= self.min_familiarity
                    and self.top.score >= self.min_similarity and self.margin >= self.min_margin)

    def as_dict(self, limit: int = 3) -> dict:
        return {
            "sure": self.sure,
            "familiarity": round(self.familiarity, 3),
            "candidates": [{"casting_id": c.casting_id, "score": round(c.score, 3)} for c in self.candidates[:limit]],
        }


def resolve(labels: np.ndarray, keys: dict[str, int]) -> np.ndarray:
    """Labels -> this database's ids: a stable key through the index, `id:<n>`
    as written, anything unknown here as -1 (never offered)."""
    if labels.dtype.kind in "iu":
        return labels.astype(np.int64)
    out = np.full(len(labels), -1, dtype=np.int64)
    for i, label in enumerate(labels.tolist()):
        if label.startswith("id:"):
            out[i] = int(label[3:])
        elif label in keys:
            out[i] = keys[label]
    return out


class VisualModel:
    """Gallery labels are stable keys (StableKeys in the app) or `id:<n>`;
    integer arrays are taken as ids directly (older models, and tests)."""

    def __init__(self, encoder, head: Head, vectors: np.ndarray, casting_labels: np.ndarray,
                 variation_labels: np.ndarray, manifest: dict, raw_vectors: np.ndarray | None = None):
        self.encoder = encoder
        self.head = head
        self.vectors = vectors.astype(np.float32)
        self.raw_vectors = raw_vectors.astype(np.float32) if raw_vectors is not None else None
        self.casting_labels = np.asarray(casting_labels)
        self.variation_labels = np.asarray(variation_labels)
        self.casting_ids = resolve(self.casting_labels, {})
        self.variation_ids = resolve(self.variation_labels, {})
        self._bound_to = None
        self.manifest = manifest
        t = manifest.get("thresholds", {})
        # RECOGNIZER_TRUST_LOOKS=never keeps looks to hints and ordering even
        # when training measured them good enough — the bar was measured on
        # the training photos, and a phone photo of a pile is not one of those.
        self.visual_only = bool(t.get("visual_only", False)) and os.environ.get("RECOGNIZER_TRUST_LOOKS", "auto") != "never"
        self.min_similarity = float(t.get("min_similarity", 1.0))
        self.min_margin = float(t.get("min_margin", 1.0))
        self.min_familiarity = float(t.get("min_familiarity", 0.0))

    @classmethod
    def load(cls, directory: str | Path, encoder=None) -> "VisualModel | None":
        d = Path(directory)
        if not (d / "model.npz").is_file() or not (d / "manifest.json").is_file():
            return None
        if encoder is None:
            if not (d / "encoder.onnx").is_file():
                return None
            encoder = Encoder(d / "encoder.onnx")
        data = np.load(d / "model.npz")
        head = Head(data["head_mean"], data["head_matrix"]) if "head_matrix" in data.files else Head()
        castings = data["casting_labels"] if "casting_labels" in data.files else data["casting_ids"]
        variations = data["variation_labels"] if "variation_labels" in data.files else data["variation_ids"]
        return cls(encoder, head, data["vectors"], castings, variations,
                   json.loads((d / "manifest.json").read_text(encoding="utf-8")),
                   data["raw_vectors"] if "raw_vectors" in data.files else None)

    def bind(self, index) -> None:
        """Translate the gallery's labels into `index`'s database ids (once per index)."""
        if index is None or self._bound_to is index:
            return
        self.casting_ids = resolve(self.casting_labels, index.casting_keys)
        self.variation_ids = resolve(self.variation_labels, index.variation_keys)
        self._bound_to = index

    def summary(self) -> dict:
        return {
            "images": int(len(self.casting_labels)),
            "castings": int(len(np.unique(self.casting_labels))),
            "resolved": int((self.casting_ids >= 0).sum()),
            "visual_only": self.visual_only,
            "trained_at": self.manifest.get("trained_at"),
            "holdout": self.manifest.get("evaluation", {}).get("chosen"),
        }

    def embed(self, images: list[np.ndarray]) -> np.ndarray:
        return self.head.apply(self.encoder.embed(images))

    def embed_pair(self, images: list[np.ndarray]) -> tuple[np.ndarray, np.ndarray]:
        """(base-model vectors, projected vectors) — the first for familiarity."""
        raw = self.encoder.embed(images)
        return normalise(raw), self.head.apply(raw)

    def match(self, vector: np.ndarray, allowed: set[int] | None = None, limit: int = 10,
              raw: np.ndarray | None = None) -> VisualResult:
        familiarity = 1.0
        if raw is not None and self.raw_vectors is not None:
            familiarity = float((self.raw_vectors @ raw).max())
        elif self.raw_vectors is not None:
            familiarity = 0.0          # a gated model must be shown the base vector
        sims = self.vectors @ vector
        order = np.argsort(-sims)
        best: dict[int, VisualMatch] = {}
        for i in order[:400]:
            cid = int(self.casting_ids[i])
            if cid < 0 or (allowed is not None and cid not in allowed):
                continue
            if cid not in best:
                if len(best) >= limit:
                    continue
                best[cid] = VisualMatch(cid, float(sims[i]))
            m = best[cid]
            vid = int(self.variation_ids[i])
            if vid > 0 and vid not in m.variations:
                m.variations[vid] = float(sims[i])
        ranked = sorted(best.values(), key=lambda m: -m.score)[:limit]
        return VisualResult(ranked, self.visual_only, self.min_similarity, self.min_margin,
                            familiarity, self.min_familiarity)
