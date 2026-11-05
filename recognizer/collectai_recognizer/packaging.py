"""Packaged cars: find each retail package in a photo and read its card.

A collection photo is not a pile of loose cars. It is a dozen or more blister
cards and boxes laid out in rows, face up, and what names each one is the large
printed text on the card — "2024 TOYOTA TACOMA", "'66 BUICK RIVIERA" — not the
silhouette or the colour of the car behind the plastic. Three consequences, and
they are why this is a separate mode rather than a change to `segment.py`:

* **Packages are found as rectangles.** The loose-car segmenter separates
  whatever differs from the table's colour and keeps blobs up to 4.5:1; here
  every package is an oblong rectangle, packages touch each other, and the
  artwork printed inside one differs from the table exactly as the card does.
* **Each package is read with the card reader** the capture screen already
  uses — the same `read_printed` behind `/read-card` — so a scan and a capture
  read a card the same way, into `ocr_output.json`'s shape.
* **Looks and colour name nothing here.** The dominant colour of a package is
  the colour of its artwork, and the trained visual model learned loose
  castings photographed from above. A package is named by what is printed on
  it, and by the toy number before the name.
"""

from __future__ import annotations

from dataclasses import dataclass

import cv2
import numpy as np

from . import ocr
from .catalog import CatalogIndex, Match, Matcher
from .evidence import Evidence
from .segment import Found, background_difference
from .textmatch import best_window, compact

# A package in a photo of a dozen is a few per cent of the frame; in a photo of
# two it is a third. Below the floor is artwork inside a card, above the ceiling
# is the whole pile read as one package.
MIN_SHARE = 0.006
MAX_SHARE = 0.55

# Blister cards and boxes are oblong: a Hot Wheels card is about 1.5:1, a
# Matchbox box face about 1.8:1, a premium card nearer 1.3:1, and a camera held
# off to one side stretches any of them. A long thin strip is a shelf edge or a
# line of printed text, not a package.
MIN_RATIO = 1.02
MAX_RATIO = 3.4

# How much of its own bounding rectangle the proposal fills. A card is a
# rectangle; a car-shaped blob, or a cluster of artwork, is not.
MIN_RECTANGULARITY = 0.78

# Two proposals of the same package overlap almost entirely; two packages side
# by side barely touch, even when their edges do.
MAX_OVERLAP = 0.4

# Upright first — most people photograph a layout the right way up — then
# upside down, then on its side. A rotation this good ends the search: a card
# read at 0° with several confident words is not also going to read at 90°.
ROTATIONS = (0, 180, 90, 270)
STOP_SCORE = 4.0


@dataclass
class Proposal:
    """One rectangle somebody might call a package, and how rectangular it is."""

    rect: tuple                 # ((cx, cy), (w, h), angle), as cv2.minAreaRect gives it
    fill: float                 # contour area / rectangle area
    source: str

    @property
    def area(self) -> float:
        return float(self.rect[1][0] * self.rect[1][1])

    @property
    def ratio(self) -> float:
        w, h = self.rect[1]
        return max(w, h) / max(1e-6, min(w, h))

    @property
    def points(self) -> np.ndarray:
        return cv2.boxPoints(self.rect).astype(np.int32)


def _proposal(contour: np.ndarray, source: str) -> Proposal | None:
    rect = cv2.minAreaRect(contour)
    area = rect[1][0] * rect[1][1]
    if area <= 0:
        return None
    return Proposal(rect=rect, fill=float(cv2.contourArea(contour) / area), source=source)


def _from_surface(bgr: np.ndarray) -> list[Proposal]:
    """Packages as whatever stands out from the surface they are lying on.

    Right when they are laid out with gaps; two that touch come back as one
    rectangle, which the edge proposals below are there to split.
    """
    differs = background_difference(bgr)
    k = max(3, int(min(bgr.shape[:2]) * 0.012)) | 1
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k))
    mask = cv2.morphologyEx(differs, cv2.MORPH_CLOSE, kernel, iterations=2)
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    return [p for c in contours if (p := _proposal(c, "stands out from the surface"))]


def _from_edges(bgr: np.ndarray) -> list[Proposal]:
    """Packages as four straight edges — the only thing that separates two
    cards lying against each other, and what finds a card whose art is the
    same colour as the table."""
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    # Keeps an edge sharp while flattening the artwork either side of it.
    gray = cv2.bilateralFilter(gray, 7, 60, 60)
    middle = float(np.median(gray))
    edges = cv2.Canny(gray, int(max(10, 0.66 * middle)), int(min(255, 1.33 * middle)), L2gradient=True)
    k = max(3, int(min(bgr.shape[:2]) * 0.006)) | 1
    edges = cv2.morphologyEx(edges, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (k, k)))

    out: list[Proposal] = []
    # RETR_LIST, not RETR_EXTERNAL: a card inside the outline of the whole
    # layout is a child contour, and it is the one we want.
    contours, _ = cv2.findContours(edges, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    for contour in contours:
        poly = cv2.approxPolyDP(contour, 0.02 * cv2.arcLength(contour, True), True)
        if len(poly) != 4 or not cv2.isContourConvex(poly):
            continue
        if (p := _proposal(poly, "four straight edges")) is not None:
            out.append(p)
    return out


def _overlap(a: Proposal, b: Proposal) -> float:
    """Shared area as a share of the smaller one, so a rectangle nested inside
    another scores 1.0 however much smaller it is."""
    kind, points = cv2.rotatedRectangleIntersection(a.rect, b.rect)
    if kind == cv2.INTERSECT_NONE or points is None or len(points) < 3:
        return 0.0
    return float(cv2.contourArea(cv2.convexHull(points)) / max(1.0, min(a.area, b.area)))


def _choose(proposals: list[Proposal], frame: float) -> list[Proposal]:
    """The proposals that look like packages, each package only once.

    Ordered by how close its area is to the median — **not** by area, and not
    by how rectangular it is. Both of those get a photograph of a layout wrong
    in a way that is hard to see afterwards: largest-first keeps two touching
    cards read as one rectangle (two rectangles side by side are a rectangle,
    and a very clean one), and most-rectangular-first keeps the printed border
    just inside a card's edge instead of the card. In a photo of several
    packages the median proposal *is* one package, so a merged pair at twice
    the area and an inner border at two thirds of it both lose to the real
    thing, and overlap then drops them.
    """
    fits = [
        p for p in proposals
        if MIN_SHARE <= p.area / frame <= MAX_SHARE
        and MIN_RATIO <= p.ratio <= MAX_RATIO
        and p.fill >= MIN_RECTANGULARITY
    ]
    if not fits:
        return []

    median = float(np.median([p.area for p in fits]))
    fits.sort(key=lambda p: (abs(np.log(p.area / median)), -p.fill))

    kept: list[Proposal] = []
    for p in fits:
        if all(_overlap(p, k) <= MAX_OVERLAP for k in kept):
            kept.append(p)
    return kept


def find_packages(bgr: np.ndarray, limit: int = 60) -> list[Found]:
    """Every retail package in the photo, in reading order."""
    h, w = bgr.shape[:2]
    kept = _choose(_from_surface(bgr) + _from_edges(bgr), float(h * w))

    found: list[Found] = []
    for p in kept:
        filled = np.zeros((h, w), dtype=np.uint8)
        cv2.drawContours(filled, [p.points], -1, 255, thickness=cv2.FILLED)
        found.append(Found(box=cv2.boundingRect(p.points), contour=p.points, mask=filled, foreground=filled))

    # Reading order: rows top to bottom, then left to right. The row band is a
    # package's own height, so a layout of five rows reads as five rows.
    row = max(1, int(np.median([f.box[3] for f in found]) * 0.6)) if found else 1
    found.sort(key=lambda f: (f.box[1] // row, f.box[0]))
    return found[:limit]


def deskewed_crop(bgr: np.ndarray, contour: np.ndarray, scale: float = 1.0, pad: float = 0.02) -> np.ndarray:
    """The package, turned upright, cut from `bgr`.

    `scale` is how much smaller the image the contour was found in is than
    `bgr`: segmentation runs on a downscaled copy, but the text is read from
    the photo as taken, where a name 20 pixels tall in the working copy is 60
    in the original.

    Turned upright, never onto its side. The loose-car crop forces the long
    edge horizontal because base text runs along the car; on packaging that
    would stand half of them on end — a blister card is portrait with its name
    across the short edge, a boxed car landscape with its name along the long
    one. Which way up it is, is for the reader to find.
    """
    (cx, cy), (rw, rh), angle = cv2.minAreaRect(np.round(contour.astype(np.float32) / scale).astype(np.int32))
    # minAreaRect's angle is in (0, 90]; turn by the smaller of the two ways.
    if angle > 45:
        angle, rw, rh = angle - 90, rh, rw
    matrix = cv2.getRotationMatrix2D((cx, cy), angle, 1.0)
    rotated = cv2.warpAffine(bgr, matrix, (bgr.shape[1], bgr.shape[0]), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
    size = (max(8, int(rw * (1 + pad))), max(8, int(rh * (1 + pad))))
    return cv2.getRectSubPix(rotated, size, (cx, cy))


def read_printed(
    bgr: np.ndarray,
    index: CatalogIndex | None,
    *,
    min_confidence: float = 0.9,
    min_margin: float = 0.08,
    rotations: tuple[int, ...] = ROTATIONS,
    stop_score: float | None = STOP_SCORE,
) -> tuple[ocr.Reading, Evidence, Match | None]:
    """One package, read and matched. The shared half of `/read-card` and the
    packaged scan: a capture and a scan read a card the same way."""
    reading = ocr.read(bgr, rotations=rotations, stop_score=stop_score)
    evidence = Evidence.from_words(reading.words)
    match = Matcher(index, min_confidence, min_margin).match(evidence, printed=True) if index else None
    return reading, evidence, match


def card_output(evidence: Evidence, match: Match | None, index: CatalogIndex | None) -> dict:
    """What the card says, in the OCR Agent's output shape (ocr_output.json v1).

    Year and series are left null: the year on a card is artwork, not text
    Tesseract reads reliably, and the schema is explicit that the copyright
    year in the small print is not it — a wrong year is worse than none. The
    casting name is reported only when it matches a catalog casting
    confidently, and then as the catalog spells it.
    """
    casting_name, casting_conf = None, 0.0
    if match is not None and match.confident and match.best:
        casting_name, casting_conf = match.best.name, round(match.best.score, 3)

    toy_number, toy_conf = None, 0.0
    if index is not None:
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
