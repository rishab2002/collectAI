"""Embeddings, keyed by image hash (or wiki file sha1), shared by collect and build.

Stored as float16 (half the space; similarities don't notice) together with the
identity of the encoder that made them (encoder-id.txt beside it: model,
pinned revision, export format) — vectors from a different encoder are
meaningless, so a changed encoder discards the cache rather than mixing them.
Without that file, the encoder file's hash stands in.
"""

from __future__ import annotations

import hashlib
import os
from pathlib import Path

import numpy as np


def file_hash(path: Path) -> str:
    h = hashlib.sha1()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


class EmbeddingCache:
    def __init__(self, path: Path, encoder: Path):
        self.path = Path(path)
        identity = Path(encoder).with_name("encoder-id.txt")
        if identity.is_file():
            self.encoder = identity.read_text(encoding="utf-8").strip()
        else:
            self.encoder = file_hash(encoder) if Path(encoder).is_file() else "unknown"
        self.vectors: dict[str, np.ndarray] = {}
        if self.path.is_file():
            data = np.load(self.path, allow_pickle=False)
            if "encoder" in data.files and str(data["encoder"]) == self.encoder:
                self.vectors = dict(zip(data["keys"].tolist(), data["vectors"]))
            else:
                print(f"note: {self.path.name} was made by a different encoder; starting afresh", flush=True)
        self.dirty = False

    def __contains__(self, key: str) -> bool:
        return key in self.vectors

    def __getitem__(self, key: str) -> np.ndarray:
        return self.vectors[key].astype(np.float32)

    def put(self, key: str, vector: np.ndarray) -> None:
        self.vectors[key] = vector.astype(np.float16)
        self.dirty = True

    def save(self) -> None:
        if not self.dirty or not self.vectors:
            return
        tmp = self.path.with_suffix(".tmp.npz")
        np.savez(tmp, keys=np.array(list(self.vectors)), vectors=np.stack(list(self.vectors.values())),
                 encoder=np.array(self.encoder))
        os.replace(tmp, self.path)
        self.dirty = False
