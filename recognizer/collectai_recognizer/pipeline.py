"""Photo in, detections out. The one function the CLI and the server both call."""

from __future__ import annotations

import base64

import cv2
import numpy as np

from . import ocr, packaging
from .catalog import CatalogIndex, Matcher
from .colours import classify_pixels, dominant
from .evidence import Evidence
from .segment import aligned_crop, find_objects, padded_crop, working_copy


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
    if layout == "packaged":
        return recognise_packages(bgr, index, min_confidence=min_confidence, min_margin=min_margin,
                                  read_text=read_text, max_objects=max_objects, crops=crops)

    work, scale = working_copy(bgr)
    everything = find_objects(work, layout=layout)
    found = everything[:max_objects]
    matcher = Matcher(index, min_confidence, min_margin) if index else None
    detections = []

    # One batch through the image model for every car in the photo.
    looks = []
    if visual is not None and found:
        visual.bind(index)
        raws, vectors = visual.embed_pair([padded_crop(work, obj.box) for obj in found])
        allowed = set(index.castings) if index else None
        looks = [visual.match(v, allowed, raw=r) for v, r in zip(vectors, raws)]

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
            # Whichever of the two readings of this package got more of its
            # name: the crop's, or the whole photo's that found it.
            "text": {**reading.as_dict(), "headline": _headline(reading, obj.printed)},
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

    What the card says, as printed, with per-field confidence — the local
    stand-in for the Claude vision call. One package filling the frame; the
    same reader runs over every package found in a scan photo
    (`packaging.recognise_packages`), so a capture and a scan read a card
    identically. What it reports, and what it leaves null, is in
    `packaging.card_output`.
    """
    work, _ = working_copy(bgr)
    # Three rotations, all of them: one card in the frame is worth the time,
    # and a close-up of a card is rarely upside down.
    _, evidence, match = packaging.read_printed(
        work, index, min_confidence=min_confidence, min_margin=min_margin,
        rotations=(0, 90, 270), stop_score=None,
    )
    return packaging.card_output(evidence, match, index)


def _headline(reading: ocr.Reading, printed: str) -> str | None:
    best = reading.headline
    if printed and len(printed) > len(best or ""):
        return printed
    return best


def recognise_packages(
    bgr: np.ndarray,
    index: CatalogIndex | None,
    *,
    min_confidence: float = 0.9,
    min_margin: float = 0.08,
    read_text: bool = True,
    max_objects: int = 40,
    crops: bool = True,
) -> dict:
    """Every retail package in the photo, each named by what is printed on it.

    No visual model and no colour: see `packaging`. The crops are cut from the
    photo as taken rather than from the downscaled working copy, because the
    name on a card in a photo of a dozen is small and reading it is the whole
    job.
    """
    work, scale = working_copy(bgr, packaging.SEGMENT_MAX_SIDE)
    everything = packaging.find_packages(work, read_text=read_text)
    found = everything[:max_objects]
    matcher = Matcher(index, min_confidence, min_margin) if index else None
    detections = []

    for n, obj in enumerate(found):
        crop = packaging.deskewed_crop(bgr, obj.contour, scale)
        reading, evidence, match = (ocr.Reading(), Evidence.from_text(""), None)
        if read_text:
            reading, evidence, match = packaging.read_printed(
                crop, index, min_confidence=min_confidence, min_margin=min_margin,
                context=obj.context, printed=obj.printed,
            )

        x, y, w, h = obj.box
        detections.append({
            "n": n + 1,
            "box": [round(x / scale), round(y / scale), round(w / scale), round(h / scale)],
            "whole_frame": False,
            # The colour of a package is the colour of its artwork: it says
            # nothing about the car sealed behind the plastic, so it is not
            # reported and never orders the versions offered.
            "colour": {"family": None, "share": 0.0, "distribution": {}},
            # Whichever of the two readings of this package got more of its
            # name: the crop's, or the whole photo's that found it.
            "text": {**reading.as_dict(), "headline": _headline(reading, obj.printed)},
            "read": {
                "brand": evidence.brand,
                "copyright_year": evidence.copyright_year,
                "codes": evidence.codes,
            },
            "card": packaging.card_output(evidence, match, index),
            "match": match.as_dict() if match else None,
            "looks": None,
            "confident": bool(match and match.confident),
            "crop_jpeg": jpeg_base64(packaging.upright(crop, reading.rotation)) if crops else None,
        })

    return {
        "width": bgr.shape[1],
        "height": bgr.shape[0],
        "found": len(everything),
        "truncated": len(everything) > max_objects,
        "confident": sum(1 for d in detections if d["confident"]),
        "index": index.summary() if index else None,
        "model": None,
        "detections": detections,
    }
