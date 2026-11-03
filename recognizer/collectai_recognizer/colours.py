"""Pixel colour -> colour family, using data/colour_families.json.

The same file maps catalog colour *words* to families on the PHP side, so a
red car in a photo and "Metalflake Dark Red" in the catalog land on the same
word. Families are coarse on purpose: a phone photo can't tell Spectraflame
from Metalflake, and evidence only counts when both sides agree.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

import cv2
import numpy as np

DATA_FILE = Path(__file__).resolve().parent.parent / "data" / "colour_families.json"


@dataclass(frozen=True)
class Palette:
    names: tuple[str, ...]
    rules: tuple[dict, ...]
    neighbours: frozenset[frozenset[str]]

    def similarity(self, a: str | None, b: str | None) -> float:
        """1 for the same family, 0.5 for a neighbour (red/orange), else 0."""
        if not a or not b:
            return 0.0
        if a == b:
            return 1.0
        return 0.5 if frozenset((a, b)) in self.neighbours else 0.0


@lru_cache(maxsize=4)
def palette(path: str | None = None) -> Palette:
    data = json.loads(Path(path or DATA_FILE).read_text(encoding="utf-8"))
    return Palette(
        names=tuple(f["name"] for f in data["families"]),
        rules=tuple(data["pixel_rules"]["rules"]),
        neighbours=frozenset(frozenset(pair) for pair in data["neighbours"]),
    )


def classify_pixels(bgr: np.ndarray, mask: np.ndarray | None = None) -> dict[str, float]:
    """Share of the (masked) pixels in each family, largest first."""
    hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)
    pixels = hsv.reshape(-1, 3) if mask is None else hsv[mask > 0]
    if len(pixels) == 0:
        return {}

    # Cap the work on big crops; 40k pixels is plenty for a share.
    if len(pixels) > 40_000:
        step = len(pixels) // 40_000 + 1
        pixels = pixels[::step]

    h = pixels[:, 0].astype(np.int16)
    s = pixels[:, 1].astype(np.int16)
    v = pixels[:, 2].astype(np.int16)
    unclaimed = np.ones(len(pixels), dtype=bool)
    counts: dict[str, int] = {}

    for rule in palette().rules:
        hit = unclaimed.copy()
        if "v_max" in rule:
            hit &= v <= rule["v_max"]
        if "v_min" in rule:
            hit &= v >= rule["v_min"]
        if "s_max" in rule:
            hit &= s <= rule["s_max"]
        if "h" in rule:
            in_range = np.zeros(len(pixels), dtype=bool)
            for lo, hi in rule["h"]:
                in_range |= (h >= lo) & (h <= hi)
            hit &= in_range
        n = int(hit.sum())
        if n:
            counts[rule["family"]] = counts.get(rule["family"], 0) + n
        unclaimed &= ~hit

    total = sum(counts.values()) or 1
    return dict(sorted(((k, round(c / total, 3)) for k, c in counts.items()), key=lambda kv: -kv[1]))


def dominant(distribution: dict[str, float]) -> tuple[str | None, float]:
    """The car's body colour, best guess.

    Windows, tyres and shadows are black or grey and can be a third of a car,
    so a chromatic family with a solid share beats an achromatic one that only
    just leads. A genuinely black or silver car still wins: its body is most
    of the pixels and no chromatic family comes close.
    """
    if not distribution:
        return None, 0.0
    ranked = list(distribution.items())
    top, share = ranked[0]
    if top in ("black", "grey"):
        for name, s in ranked[1:]:
            if name not in ("black", "grey", "white") and s >= 0.25 and s >= share * 0.6:
                return name, s
    return top, share
