"""Photo in, detections out. The one function the CLI and the server both call."""

from __future__ import annotations

import base64

import cv2
import numpy as np

from . import ocr
from .catalog import CatalogIndex, Matcher
from .colours import classify_pixels, dominant
from .evidence import Evidence
from .segment import aligned_crop, find_objects, padded_crop, working_copy
from .textmatch import best_window, compact


def decode(data: bytes) -> np.ndarray:
    img = cv2.imdecode(np.frombuffer(data, dtype=np.uint8), cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("not an image this recognizer can decode (JPEG, PNG, WebP, BMP, TIFF)")
    return img


def jpeg_base64(bgr: np.ndarray, max_side: int = 480) -> str:
    h, w = bgr.shape[:2]
    scale = min(1.0, max_side / max(h, w, 1))
    if scale < 1.0:
        bgr = cv2.resize(bgr, (max(1, int(w * scale)), max(1, int(h * scale))), interpolation=cv2.INTER_AREA)
    ok, data = cv2.imencode(".jpg", bgr, [cv2.IMWRITE_JPEG_QUALITY, 85])
    return base64.b64encode(data.tobytes()).decode() if ok else ""


def recognise(
    bgr: np.ndarray,
    index: CatalogIndex | None,
    *,
    min_confidence: float = 0.9,
    min_margin: float = 0.08,
    read_text: bool = True,
    max_objects: int = 40,
    crops: bool = True,
    layout: str = "auto",
    visual=None,
) -> dict:
    """`visual` is a loaded visual.VisualModel, or None to use text and colour only."""
    work, scale = working_copy(bgr)
    everything = find_objects(work, layout=layout)
    found = everything[:max_objects]
    matcher = Matcher(index, min_confidence, min_margin) if index else None
    detections = []

    # One batch through the image model for every car in the photo.
    looks = []
    if visual is not None and found:
        vectors = visual.embed([padded_crop(work, obj.box) for obj in found])
        allowed = set(index.castings) if index else None
        looks = [visual.match(v, allowed) for v in vectors]

    for n, obj in enumerate(found):
        distribution = classify_pixels(work, obj.foreground)
        family, share = dominant(distribution)
        reading = ocr.read(aligned_crop(work, obj)) if read_text else ocr.Reading()
        evidence = Evidence.from_words(reading.words)
        seen = looks[n] if looks else None
        match = matcher.match(evidence, family, seen).as_dict() if matcher else None

        x, y, w, h = obj.box
        detections.append({
            "n": n + 1,
            "box": [round(x / scale), round(y / scale), round(w / scale), round(h / scale)],
            "whole_frame": obj.whole_frame,
            "colour": {"family": family, "share": share, "distribution": dict(list(distribution.items())[:4])},
            "text": reading.as_dict(),
            "read": {
                "brand": evidence.brand,
                "copyright_year": evidence.copyright_year,
                "codes": evidence.codes,
            },
            "match": match,
            "looks": seen.as_dict() if seen else None,
            "confident": bool(match and match["confident"]),
            "crop_jpeg": jpeg_base64(padded_crop(work, obj.box)) if crops else None,
        })

    return {
        "width": bgr.shape[1],
        "height": bgr.shape[0],
        "found": len(everything),
        "truncated": len(everything) > max_objects,
        "confident": sum(1 for d in detections if d["confident"]),
        "index": index.summary() if index else None,
        "model": visual.summary() if visual else None,
        "detections": detections,
    }


def read_card(bgr: np.ndarray, index: CatalogIndex | None, *, min_confidence: float = 0.9, min_margin: float = 0.08) -> dict:
    """A carded car, read into the OCR Agent's output shape (ocr_output.json v1).

    What the card says, as printed, with per-field confidence — the local stand-in
    for the Claude vision call. Year and series are left null: on a card they are
    artwork, not text Tesseract reads reliably, and a wrong year is worse than
    none. The casting name is reported only when it matches a catalog casting
    confidently, and then as the catalog spells it.
    """
    work, _ = working_copy(bgr)
    reading = ocr.read(work, rotations=(0, 90, 270))
    evidence = Evidence.from_words(reading.words)

    casting_name, casting_conf = None, 0.0
    toy_number, toy_conf = None, 0.0
    if index:
        match = Matcher(index, min_confidence, min_margin).match(evidence)
        if match.confident and match.best:
            casting_name, casting_conf = match.best.name, round(match.best.score, 3)
        for code in evidence.codes:
            if code in index.toy_numbers:
                toy_number, toy_conf = code, 0.95
                break
    if toy_number is None and evidence.codes:
        toy_number, toy_conf = evidence.codes[0], 0.5

    flat = compact(evidence.raw)
    brand_conf = 0.0
    if evidence.brand:
        brand_conf = 0.9 if best_window(compact(evidence.brand), flat) >= 0.95 else 0.75

    return {
        "schema_version": 1,
        "brand": evidence.brand,
        "year": None,
        "series": None,
        "casting_name": casting_name,
        "collector_number": evidence.collector_number,
        "toy_number": toy_number,
        "confidence": {
            "brand": brand_conf,
            "year": 0.0,
            "series": 0.0,
            "casting_name": casting_conf,
            "collector_number": 0.7 if evidence.collector_number else 0.0,
            "toy_number": toy_conf,
        },
    }
