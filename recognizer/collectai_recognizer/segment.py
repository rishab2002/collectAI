"""Find the separate cars in a photo.

Built for the way people photograph a pile: cars spread out on a plain
surface (a table, a sheet, a mat). The background colour is read from the
photo's border, everything that differs from it enough is foreground, and each
connected blob is one car. Cars that touch merge into one blob — spread them
out. A photo with nothing distinct from its border (a close-up of one base) is
treated as one object filling the frame.
"""

from __future__ import annotations

from dataclasses import dataclass

import cv2
import numpy as np

MAX_SIDE = 1600


@dataclass
class Found:
    box: tuple[int, int, int, int]          # x, y, w, h in the working image
    contour: np.ndarray
    mask: np.ndarray                          # full-image uint8 mask, filled
    foreground: np.ndarray                    # mask AND "differs from background"
    whole_frame: bool = False


def working_copy(bgr: np.ndarray) -> tuple[np.ndarray, float]:
    """Downscale so the long side is at most MAX_SIDE; returns the scale used."""
    h, w = bgr.shape[:2]
    scale = min(1.0, MAX_SIDE / max(h, w))
    if scale < 1.0:
        bgr = cv2.resize(bgr, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)
    return bgr, scale


LAYOUTS = ("auto", "pile", "single")


def whole(h: int, w: int) -> list[Found]:
    full = np.full((h, w), 255, dtype=np.uint8)
    contour = np.array([[[0, 0]], [[w - 1, 0]], [[w - 1, h - 1]], [[0, h - 1]]], dtype=np.int32)
    return [Found(box=(0, 0, w, h), contour=contour, mask=full, foreground=full, whole_frame=True)]


def find_objects(bgr: np.ndarray, min_area_share: float = 0.004, layout: str = "auto") -> list[Found]:
    """`layout` is what the photographer says the photo is.

    "single" — one car or one base, close up: the whole frame is the object.
    Worth saying, because on a close-up the words printed on a base stand out
    from the metal around them exactly as a car stands out from a table.
    "pile" — several cars spread out; never falls back to the whole frame.
    "auto" — guess: a pile, unless nothing (or one huge blob) stands out.
    """
    h, w = bgr.shape[:2]
    if layout == "single":
        return whole(h, w)
    lab = cv2.cvtColor(bgr, cv2.COLOR_BGR2LAB).astype(np.float32)
    # Lightness counts for less than colour: a shadow on a white table is a
    # change in L only, and shouldn't swallow the gap between two cars.
    lab[:, :, 0] *= 0.6

    b = max(4, int(min(h, w) * 0.03))
    border = np.concatenate([
        lab[:b].reshape(-1, 3), lab[-b:].reshape(-1, 3),
        lab[:, :b].reshape(-1, 3), lab[:, -b:].reshape(-1, 3),
    ])
    background = np.median(border, axis=0)
    distance = np.linalg.norm(lab - background, axis=2)
    noise = np.percentile(np.linalg.norm(border - background, axis=1), 95)
    threshold = max(18.0, noise * 1.6)

    differs = (distance > threshold).astype(np.uint8) * 255
    k = max(3, int(min(h, w) * 0.012)) | 1
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k))
    mask = cv2.morphologyEx(differs, cv2.MORPH_CLOSE, kernel, iterations=2)
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k // 2 | 1, k // 2 | 1)))

    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    min_area = h * w * min_area_share
    found: list[Found] = []
    for contour in contours:
        area = cv2.contourArea(contour)
        if area < min_area:
            continue
        # A line of printed text on a close-up stands out from its border too.
        # Cars seen from any side are at most ~4:1; a text line is far longer.
        (_, _), (rw, rh), _ = cv2.minAreaRect(contour)
        if min(rw, rh) == 0 or max(rw, rh) / min(rw, rh) > 4.5:
            continue
        filled = np.zeros((h, w), dtype=np.uint8)
        cv2.drawContours(filled, [contour], -1, 255, thickness=cv2.FILLED)
        found.append(Found(
            box=cv2.boundingRect(contour),
            contour=contour,
            mask=filled,
            foreground=cv2.bitwise_and(filled, differs),
        ))

    # Nothing stands out from the border, or one blob is most of the frame:
    # a close-up. Treat the whole photo as the object.
    if layout == "auto" and (not found or (len(found) == 1 and cv2.contourArea(found[0].contour) > 0.6 * h * w)):
        return whole(h, w)

    # Reading order: top to bottom in rows, then left to right.
    row = max(1, h // 6)
    found.sort(key=lambda f: (f.box[1] // row, f.box[0]))
    return found


def padded_crop(bgr: np.ndarray, box: tuple[int, int, int, int], pad: float = 0.08) -> np.ndarray:
    x, y, w, h = box
    px, py = int(w * pad), int(h * pad)
    H, W = bgr.shape[:2]
    return bgr[max(0, y - py):min(H, y + h + py), max(0, x - px):min(W, x + w + px)]


def aligned_crop(bgr: np.ndarray, found: Found) -> np.ndarray:
    """The object rotated so its long side is horizontal — how base text runs."""
    if found.whole_frame:
        crop = bgr
    else:
        (cx, cy), (rw, rh), angle = cv2.minAreaRect(found.contour)
        if rw < rh:
            rw, rh = rh, rw
            angle += 90
        matrix = cv2.getRotationMatrix2D((cx, cy), angle, 1.0)
        rotated = cv2.warpAffine(bgr, matrix, (bgr.shape[1], bgr.shape[0]), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
        crop = cv2.getRectSubPix(rotated, (int(rw) + 4, int(rh) + 4), (cx, cy))
    if crop.shape[0] > crop.shape[1]:
        crop = cv2.rotate(crop, cv2.ROTATE_90_CLOCKWISE)
    return crop
