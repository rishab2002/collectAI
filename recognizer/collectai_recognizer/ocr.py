"""Text off a car base or a card, with Tesseract — locally, no API.

Base text is small, embossed and often upside down, so each crop is tried
the right way up and rotated 180°, with a contrast-boosted and a binarised
version, and the reading Tesseract is most sure of wins. The words keep their
per-word confidence so the matcher can discount guesses.
"""

from __future__ import annotations

import os
import shutil
import subprocess
from dataclasses import dataclass, field

import cv2
import numpy as np

TESSERACT = os.environ.get("TESSERACT_CMD", "tesseract")


@dataclass
class Reading:
    words: list[tuple[str, float]] = field(default_factory=list)   # (word, confidence 0-1)
    lines: list[str] = field(default_factory=list)
    rotation: int = 0

    @property
    def text(self) -> str:
        return "\n".join(self.lines)

    @property
    def score(self) -> float:
        """Sum of confidences of real-looking words — what picks the rotation."""
        return sum(c for w, c in self.words if c >= 0.5 and sum(ch.isalnum() for ch in w) >= 3)

    @property
    def confidence(self) -> float:
        good = [c for w, c in self.words if sum(ch.isalnum() for ch in w) >= 2]
        return round(sum(good) / len(good), 3) if good else 0.0

    def as_dict(self) -> dict:
        return {"lines": self.lines, "confidence": self.confidence, "rotation": self.rotation}


def available() -> str | None:
    """Tesseract's version line, or None if it isn't installed."""
    if shutil.which(TESSERACT) is None:
        return None
    out = subprocess.run([TESSERACT, "--version"], capture_output=True, text=True, timeout=10)
    first = (out.stdout or out.stderr).splitlines()
    return first[0] if first else "tesseract"


def tesseract(gray: np.ndarray, psm: int = 11) -> Reading:
    ok, png = cv2.imencode(".png", gray)
    if not ok:
        return Reading()
    done = subprocess.run(
        [TESSERACT, "stdin", "stdout", "--psm", str(psm), "-l", "eng", "tsv"],
        input=png.tobytes(), capture_output=True, timeout=60,
    )
    if done.returncode != 0:
        return Reading()
    return parse_tsv(done.stdout.decode("utf-8", "replace"))


def parse_tsv(tsv: str) -> Reading:
    words: list[tuple[str, float]] = []
    lines: dict[tuple[str, str, str], list[str]] = {}
    for row in tsv.splitlines()[1:]:
        cols = row.split("\t")
        if len(cols) < 12 or not cols[11].strip():
            continue
        try:
            conf = float(cols[10])
        except ValueError:
            continue
        if conf < 0:
            continue
        text = cols[11].strip()
        words.append((text, round(conf / 100, 3)))
        lines.setdefault((cols[2], cols[3], cols[4]), []).append(text)
    return Reading(words=words, lines=[" ".join(ws) for ws in lines.values()])


def prepare(bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY) if bgr.ndim == 3 else bgr
    h, w = gray.shape[:2]
    # Tesseract reads best with letters ~30px tall; base text in a pile photo
    # is a few pixels, so scale small crops up and huge ones down.
    scale = 1.0
    if min(h, w) < 400:
        scale = min(4.0, 400 / max(1, min(h, w)))
    if max(h, w) * scale > 3000:
        scale = 3000 / max(h, w)
    if scale != 1.0:
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    return cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8)).apply(gray)


def read(bgr: np.ndarray, rotations: tuple[int, ...] = (0, 180), stop_score: float | None = None) -> Reading:
    """`stop_score` ends the search as soon as one rotation reads this well.

    Worth having when there are four rotations and a dozen crops to get
    through — a card that reads at 0° with several confident words is not also
    going to read at 90°. Left unset, every rotation is tried and the best
    wins.
    """
    base = prepare(bgr)
    best = Reading()
    for rotation in rotations:
        img = base if rotation == 0 else cv2.rotate(base, {90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}[rotation])
        reading = tesseract(img)
        if reading.score < 2:
            # Embossed metal reads better binarised.
            binary = cv2.adaptiveThreshold(cv2.GaussianBlur(img, (3, 3), 0), 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 10)
            alt = tesseract(binary)
            if alt.score > reading.score:
                reading = alt
        reading.rotation = rotation
        if reading.score > best.score:
            best = reading
        if stop_score is not None and best.score >= stop_score:
            break
    return best
