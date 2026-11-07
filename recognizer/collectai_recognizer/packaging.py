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

from . import brands, logos, ocr
from .catalog import CatalogIndex, Match, Matcher
from .evidence import Evidence
from .segment import Found, background_difference
from .textmatch import best_window, compact, normalise

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
    # Only enough morphology to close speckle. The loose-car segmenter closes
    # hard, to join a car's body to its dark windows; here the same kernel
    # welds a column of packages into one strip, because what separates two
    # packages is a gap of the same few pixels. Holes inside a package — a
    # blister bubble the colour of the table — need no closing at all:
    # RETR_EXTERNAL returns the outline, and its area counts them in.
    k = max(3, int(min(bgr.shape[:2]) * 0.004)) | 1
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k))
    mask = cv2.morphologyEx(differs, cv2.MORPH_OPEN, kernel)
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel)
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    return [p for c in contours if (p := _proposal(c, "stands out from the surface"))]


def _from_edges(bgr: np.ndarray) -> list[Proposal]:
    """Packages as four straight edges — the only thing that separates two
    cards lying against each other, and what finds a card whose art is the
    same colour as the table."""
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    # Keeps an edge sharp while flattening the artwork either side of it.
    gray = cv2.bilateralFilter(gray, 7, 60, 60)
    # Thresholds from how steep this photo's edges actually are, not from how
    # bright it is. The usual median-brightness rule sets them from the table:
    # photograph packages on a white sheet and the upper threshold lands at
    # 255, so only the highest-contrast packages are outlined at all and a
    # whole column of pale ones is never proposed (measured).
    gx = cv2.Sobel(gray, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(gray, cv2.CV_32F, 0, 1, ksize=3)
    steep = max(30.0, float(np.percentile(cv2.magnitude(gx, gy), 97)))
    edges = cv2.Canny(gray, steep * 0.4, steep, L2gradient=True)
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


# What a line of print has to look like to be a package's name rather than
# something Tesseract made out of the carpet. Measured on the owner's photos:
# over a hundred lines come back from a blanket or a rug, and almost every one
# of them is short, lower case and badly scored, while every real name is six
# or more characters printed in capitals. Hot Wheels and Matchbox both print
# their car names that way, which is nearly the whole catalog — so a line in
# mixed case has to have been read *well* instead, which is what a real
# mixed-case name on a Mini GT or Tomica box reads like and what invented
# words never do.
MIN_PRINTED = 6
MIN_CAPITALS = 0.6
MIN_SCORE = 0.25
CLEARLY_READ = 0.7


def _is_printing(text: str, score: float) -> bool:
    letters = [c for c in text if c.isalpha()]
    if sum(c.isalnum() for c in text) < MIN_PRINTED or not letters:
        return False
    capitals = sum(c.isupper() for c in letters) / len(letters)
    return score >= MIN_SCORE and (capitals >= MIN_CAPITALS or score >= CLEARLY_READ)

# Packages are found at a bigger size than loose cars are. The name on a card
# in a photo of a dozen is about 20 pixels tall here and about 14 at the
# loose-car size, which is the difference between Tesseract reading it in the
# whole-photo pass and not. Each package is then read again, from the photo as
# taken, where it is taller still.
SEGMENT_MAX_SIDE = 2400

# How far from its own printing a package's crop reaches when no rectangle
# was found round it, as a multiple of the printing's letter height. Enough
# to take in the lines either side of a name; not so much that it reaches
# into the package stacked behind it.
TEXT_PAD = 1.5


# How far beyond its own printing a package's evidence reaches, as a multiple
# of the printing's letter height. A blister card carries its brand at the top
# and its name at the bottom, so without this the brand is never read off a
# card found by its name — and the brand is what tells two castings of one
# name apart.
CONTEXT_REACH = 4.0


@dataclass
class Region:
    box: tuple[int, int, int, int]      # x, y, w, h of the printing, padded
    printed: str                        # the print that marked it out, read whole-photo
    context: str                        # print read near it, but not on it


# How the photo is prepared before Tesseract looks for printing in it. Two
# passes, because **no single preparation reads every package** and the one
# that misses is not the same one each time. Measured on three photographs:
# over a card half in sun and half in shadow, 8 tiles reads "2003 DODGE VIPER
# SRT-10" and not the Corvette beside it on the same card; 16 tiles reads the
# Corvette and not the Viper. Running both and taking the union found 6 names
# of 10 where one pass found 5, 4 of 4 where one found 3, and recovered a
# whole two-pack that had been invisible. A third pass on the plain grey
# added nothing either of these missed, so there are two.
#
# Enlarging the photo first was measured too, and it is worse: this photo
# reads "2O12,CORVETTES 206" at its own size and "2002 GORVETTE® 06" blown up
# to 2400. The working copy still only ever shrinks.
CLAHE_TILES = (8, 16)
CLAHE_CLIP = 2.0

# Two passes find the same line twice. Overlapping this much, it is one piece
# of printing read twice, and the better-scored reading is the one to keep —
# which also hands the matcher the better text.
SAME_LINE = 0.5


def _printed_lines(bgr: np.ndarray) -> list[tuple[tuple[int, int, int, int], float, str]]:
    """Every line of printing in the photo, from each preparation in turn."""
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    found: list[tuple[float, tuple[int, int, int, int], float, str]] = []

    for tiles in CLAHE_TILES:
        reading = ocr.tesseract(cv2.createCLAHE(clipLimit=CLAHE_CLIP, tileGridSize=(tiles, tiles)).apply(gray))
        for box, height, text, score in zip(reading.boxes, reading.heights, reading.lines, reading.scores):
            if box[2] > 0 and box[3] > 0 and _is_printing(text, score):
                found.append((score, box, height, text))

    kept: list[tuple[tuple[int, int, int, int], float, str]] = []
    for _, box, height, text in sorted(found, key=lambda line: -line[0]):
        if not any(_covers(box, already) > SAME_LINE for already, _, _ in kept):
            kept.append((box, height, text))
    return kept


def _covers(box: tuple[int, int, int, int], other: tuple[int, int, int, int]) -> float:
    """Shared area as a share of the smaller box."""
    x, y, w, h = box
    ox, oy, ow, oh = other
    over = max(0, min(x + w, ox + ow) - max(x, ox)) * max(0, min(y + h, oy + oh) - max(y, oy))

    return over / max(1, min(w * h, ow * oh))


def _text_regions(bgr: np.ndarray) -> list[Region]:
    """The blocks of printing in the photo, one per package that can be read.

    This, and not the rectangle proposals, is what finds packages in the
    photographs people actually take. Measured on the owner's own: packages
    are laid overlapping, in a fan or a pile, on a carpet or a blanket — so
    there is no gap between them to separate them by, no four clean edges
    round any but the top one, and nothing like the plain contrasting surface
    the loose-car instructions ask for. What there is, on every package that
    can be identified at all, is its name printed across it. A package whose
    printing is hidden under the next package cannot be named by anything, so
    finding it would only produce a crop with no answer in it.
    """
    lines = _printed_lines(bgr)
    if not lines:
        return []

    # Lines of one package's printing join up; the next package's are a line
    # or more away. Both tests are in units of the printing's own size, so a
    # close-up and a shelf shot are judged the same way.
    parent = list(range(len(lines)))

    def root(i: int) -> int:
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    for i, (a, ah, _) in enumerate(lines):
        for j in range(i + 1, len(lines)):
            b, bh, _ = lines[j]
            reach = max(4.0, 1.2 * max(ah, bh))
            across = max(0, max(a[0], b[0]) - min(a[0] + a[2], b[0] + b[2]))
            down = max(0, max(a[1], b[1]) - min(a[1] + a[3], b[1] + b[3]))
            if across <= reach and down <= reach:
                parent[root(i)] = root(j)

    groups: dict[int, list[tuple[tuple[int, int, int, int], float, str]]] = {}
    for i in range(len(lines)):
        groups.setdefault(root(i), []).append(lines[i])

    H, W = bgr.shape[:2]
    regions = []
    for group in groups.values():
        tall = max(h for _, h, _ in group)
        pad = TEXT_PAD * tall
        x0 = max(0, int(min(b[0] for b, _, _ in group) - pad))
        y0 = max(0, int(min(b[1] for b, _, _ in group) - pad))
        x1 = min(W, int(max(b[0] + b[2] for b, _, _ in group) + pad))
        y1 = min(H, int(max(b[1] + b[3] for b, _, _ in group) + pad))
        box = (x0, y0, x1 - x0, y1 - y0)
        regions.append(Region(box, " ".join(t for _, _, t in group), _near(lines, box, CONTEXT_REACH * tall)))
    return regions


def _near(lines: list[tuple[tuple[int, int, int, int], float, str]],
          box: tuple[int, int, int, int], reach: float) -> str:
    """Every line read within `reach` of the box, but not inside it."""
    x, y, w, h = box
    out = []
    for (bx, by, bw, bh), _, line in lines:
        if bw <= 0 or bh <= 0 or (x <= bx and by >= y and bx + bw <= x + w and by + bh <= y + h):
            continue
        if (max(0, max(x, bx) - min(x + w, bx + bw)) <= reach
                and max(0, max(y, by) - min(y + h, by + bh)) <= reach):
            out.append(line)
    return " ".join(out)


# What a blister card looks like around its name. The name is printed across
# the lower part of the face, not quite reaching the edges, and the card is
# about half as tall again as it is wide.
CARD_RATIO = 1.5
NAME_SITS_AT = 0.86

# The name does not sit in the middle of the card. On a mainline card it runs
# across the left and centre of the face and the right eighth or so is the
# segment strip — the wave, printed on its side. So the box grows further
# right than left, which is what puts that strip inside the crop and lets
# `read_edge_series` find it at all.
NAME_WIDTH = 0.72
GROW_RIGHT = 0.62


def _card_box(region: "Region", others: list["Region"], shape: tuple[int, int]) -> tuple[int, int, int, int]:
    """A name strip grown into the card it is printed on.

    Without this a package's crop is the strip and nothing else — a band of
    plastic with a name on it, no car, no artwork, no use as a picture of
    anything. The strip's own width is most of the card's, so the card can be
    estimated round it: wider by a little, half as tall again as it is wide,
    with the name near the bottom.

    Where that reaches into the next package it is pulled back to **halfway
    between the two names**. Each package owns the space up to the midpoint
    with its neighbour, which needs no judgement about which of them the
    ground belongs to and degrades sensibly however densely they are piled.
    A guess at the card's extent is better than a certainty about the strip:
    the reader works on the crop either way, and the collector gets something
    they can recognise.
    """
    x, y, w, h = region.box
    height, width = shape

    wide = min(float(width), w / NAME_WIDTH)
    tall = wide * CARD_RATIO
    spare = wide - w
    bottom = y + h + (1 - NAME_SITS_AT) * tall

    left = x - spare * (1 - GROW_RIGHT)
    right = x + w + spare * GROW_RIGHT
    top = bottom - tall

    for other in others:
        if other is region:
            continue
        ox, oy, ow, oh = other.box
        # Only a neighbour the grown box would actually swallow.
        if right <= ox or left >= ox + ow or bottom <= oy or top >= oy + oh:
            continue
        if oy + oh <= y:                                   # above this one
            top = max(top, (oy + oh + y) / 2)
        elif oy >= y + h:                                  # below it
            bottom = min(bottom, (oy + y + h) / 2)
        elif ox + ow <= x:                                 # to its left
            left = max(left, (ox + ow + x) / 2)
        elif ox >= x + w:                                  # to its right
            right = min(right, (ox + x + w) / 2)

    left, top = max(0, int(left)), max(0, int(top))
    right, bottom = min(width, int(right)), min(height, int(bottom))

    return (left, top, max(1, right - left), max(1, bottom - top))


def _inside(region: tuple[int, int, int, int], p: Proposal, share: float = 0.8) -> bool:
    """Is this block of printing on that package?

    Mostly within, not wholly: the block is padded out from the printing
    itself, so on a card whose name runs to the edge it spills a few pixels
    over, and demanding all four corners then says the name is not on the
    card it is printed on. Two blocks landing on one rectangle is how a card
    with its name and its series on separate lines stays one package.
    """
    x, y, w, h = region
    kind, points = cv2.rotatedRectangleIntersection(((x + w / 2, y + h / 2), (w, h), 0.0), p.rect)
    if kind == cv2.INTERSECT_NONE or points is None or len(points) < 3:
        return False
    return cv2.contourArea(cv2.convexHull(points)) >= share * w * h


def _as_found(points: np.ndarray, shape: tuple[int, int], region: "Region | None" = None) -> Found:
    filled = np.zeros(shape, dtype=np.uint8)
    cv2.drawContours(filled, [points], -1, 255, thickness=cv2.FILLED)
    found = Found(box=cv2.boundingRect(points), contour=points, mask=filled, foreground=filled)
    if region is not None:
        found.printed, found.context = region.printed, region.context
    return found


def _one_each(found: list[Found], share: float = 0.5) -> list[Found]:
    """One detection per package: drop a box mostly covered by a bigger one."""
    kept: list[Found] = []
    for f in sorted(found, key=lambda f: -f.box[2] * f.box[3]):
        x, y, w, h = f.box
        covered = False
        for k in kept:
            kx, ky, kw, kh = k.box
            over = max(0, min(x + w, kx + kw) - max(x, kx)) * max(0, min(y + h, ky + kh) - max(y, ky))
            covered = covered or over >= share * w * h
        if not covered:
            kept.append(f)
    return kept


def whole_package(bgr: np.ndarray) -> Found:
    """The frame itself, as one package — for a rectangle somebody cut out.

    A hand-cut region has already been segmented, by a person, and looking
    for printing in it to decide whether a package is there can only
    disagree with them. It did: the way back in for a package the segmenter
    walks past is to cut it out by hand, and re-segmenting the cut-out
    rediscovered the same miss and returned nothing — so the one thing that
    was meant to always give an answer sometimes gave none at all, and a
    crop with nothing to name is a crop that cannot be labelled either.

    Whatever is in that rectangle is a package because somebody said so. The
    reader still runs over it, at full size and every way up, so it is named
    if it can be; when it cannot, the honest answer is an unnamed package
    with its picture, which is what a label needs.
    """
    h, w = bgr.shape[:2]
    points = np.array([[[0, 0]], [[w - 1, 0]], [[w - 1, h - 1]], [[0, h - 1]]], np.int32)
    found = _as_found(points, (h, w))
    found.whole_frame = True
    return found


def find_packages(bgr: np.ndarray, limit: int = 60, read_text: bool = True) -> list[Found]:
    """Every retail package the photo can name, in reading order.

    Two ways of seeing a package, used together: the printing on it says
    there is one and roughly where, and a rectangle round it says exactly
    where. The printing decides what is a package — rectangles alone find
    nothing in a pile — and a rectangle, when there is one round that
    printing, gives the straight, deskewed crop to read and to show.
    """
    h, w = bgr.shape[:2]
    rectangles = _choose(_from_surface(bgr) + _from_edges(bgr), float(h * w))
    regions = _text_regions(bgr) if (read_text and ocr.available()) else []

    taken: list[Proposal] = []
    found: list[Found] = []
    for region in regions:
        around = [p for p in rectangles if _inside(region.box, p)]
        if around:
            rectangle = min(around, key=lambda p: p.area)
            if any(r is rectangle for r in taken):
                continue        # one card, two blocks of printing on it
            taken.append(rectangle)
            found.append(_as_found(rectangle.points, (h, w), region))
        else:
            # No rectangle round it, so the card is estimated from the name.
            x, y, rw, rh = _card_box(region, regions, (h, w))
            found.append(_as_found(np.array([[[x, y]], [[x + rw, y]], [[x + rw, y + rh]], [[x, y + rh]]], np.int32),
                                   (h, w), region))

    # A rectangle with no printing found on it is a package too — most often
    # one lying on its side, whose name the whole-photo pass reads sideways
    # and so does not read at all. It is worth keeping because the per-package
    # reader turns each crop every way up, so what the photo missed the crop
    # can still get; and if it does not, an unnamed package is the honest
    # answer. It is also all there is when nothing was printed anywhere — a
    # layout photographed too far away to read, or no Tesseract.
    found += [_as_found(p.points, (h, w)) for p in rectangles if not any(r is p for r in taken)]
    found = _one_each(found)

    # Reading order: rows top to bottom, then left to right, banded by a
    # package's own height so a layout of five rows reads as five rows. By
    # centre, not by top edge: a box laid on its side among upright cards
    # starts lower down and ends higher up, but sits in the same row, and
    # banding by its top edge puts it in a row of its own.
    band = max(1, int(np.median([f.box[3] for f in found]) * 0.7)) if found else 1
    found.sort(key=lambda f: ((f.box[1] + f.box[3] // 2) // band, f.box[0]))
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


def upright(bgr: np.ndarray, rotation: int) -> np.ndarray:
    """The crop turned the way its text read best.

    Deskewing leaves a package in whatever orientation it was lying in, and a
    boxed car laid on its side reads sideways. The reader already worked out
    which way up the printing is, so the collector may as well be shown it
    that way on the confirm screen.
    """
    turns = {90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}
    return bgr if rotation not in turns else cv2.rotate(bgr, turns[rotation])


# Where the wave is printed on a mainline card: up the right-hand edge,
# bottom to top, in the lower part of the face. A band rather than a line,
# because how far in it sits varies with the brand and the year.
EDGE_BAND = 0.26
EDGE_FROM = 0.18

# A segment read off a 90°-rotated strip is a decent read, not a wordmark
# guess, so the bar is near the printed-type one. Measured: on the owner's
# mainline card "FACTORY FRESH" comes back clean, and the rest of the band —
# the collector number, the Mattel small print — reaches nothing.
EDGE_SIMILARITY = 0.8

# …and it has to line up with whole words, not with any window anywhere in the
# band. Similarity alone is not enough against 1,200 wave names: a band that
# OCRs as "ly aba . DO /m,. OT js (\) 7 4 2 mA" hands "Mopar" a five-character
# window at 0.80, and a card that plainly says FACTORY FRESH came back as
# X-Men. Matching run-by-run fixes it where a coverage floor could not — the
# band legitimately carries the collector number and the small print beside
# the wave, so the wave is never most of what is read.
EDGE_RUN = 4
EDGE_LENGTH = (0.7, 1.45)

# The strip is a few pixels wide on the card and has to be blown up before
# Tesseract will take it seriously.
EDGE_HEIGHT = 220


def _runs(words: list[str], longest: int) -> list[str]:
    """Every run of up to `longest` consecutive words, joined up."""
    return [
        "".join(words[start:start + length])
        for length in range(1, longest + 1)
        for start in range(len(words) - length + 1)
    ]


def read_edge_series(upright_card: np.ndarray, names: list[str]) -> tuple[str, float] | None:
    """The wave printed up the right-hand edge of a card, if it says one.

    On a mainline card the segment — "Factory Fresh", "HW J-Imports" — runs
    bottom to top along the bottom right, which is why the whole-card pass
    never finds it: Tesseract reads a page left to right and this is turned
    on its side. The band is cut out and turned the right way up on its own.

    Matched against the catalog's own wave names rather than reported raw: a
    name read off a 90° strip is good but not perfect, and a wave that
    already exists should land on the spelling the catalog has.
    """
    if not names or upright_card.size == 0:
        return None

    height, width = upright_card.shape[:2]
    band = upright_card[int(height * EDGE_FROM):, int(width * (1 - EDGE_BAND)):]
    if band.size == 0 or min(band.shape[:2]) < 8:
        return None

    # Both ways round. Which way the segment runs is not a convention you can
    # rely on — it reads bottom-to-top on one card and top-to-bottom on the
    # next, and turned the wrong way "FACTORY FRESH" comes back as mirrored
    # nonsense rather than as nothing, so there is no telling from the result.
    best: tuple[str, float] | None = None
    for turn in (cv2.ROTATE_90_CLOCKWISE, cv2.ROTATE_90_COUNTERCLOCKWISE):
        turned = cv2.rotate(band, turn)
        scale = max(1.0, EDGE_HEIGHT / max(1, turned.shape[0]))
        turned = cv2.resize(turned, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
        gray = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8)).apply(cv2.cvtColor(turned, cv2.COLOR_BGR2GRAY))

        for psm in (7, 6):
            text = ocr.tesseract(gray, psm=psm).text
            if len(compact(text)) < 4:
                continue
            runs = _runs(normalise(text).split(), EDGE_RUN)
            for name in names:
                wave = compact(name)
                low, high = EDGE_LENGTH[0] * len(wave), EDGE_LENGTH[1] * len(wave)
                score = max((best_window(wave, run) for run in runs if low <= len(run) <= high),
                            default=0.0)
                if score >= EDGE_SIMILARITY and (best is None or score > best[1]):
                    best = (name, round(score, 3))
    return best


def read_printed(
    bgr: np.ndarray,
    index: CatalogIndex | None,
    *,
    min_confidence: float = 0.9,
    min_margin: float = 0.08,
    rotations: tuple[int, ...] = ROTATIONS,
    stop_score: float | None = STOP_SCORE,
    context: str = "",
    printed: str = "",
    artwork: bool = True,
    logo_brand: str | None = None,
    vocabulary: "brands.Vocabulary | None" = None,
) -> tuple[ocr.Reading, Evidence, Match | None]:
    """One package, read and matched. The shared half of `/read-card` and the
    packaged scan: a capture and a scan read a card the same way.

    `printed` is the same package's print as the whole-photo pass read it, and
    both readings count. They disagree more than you would expect: on a
    Matchbox card photographed from across a table the whole-photo pass read
    "2024 TOYOTA TACOMA" at 92% and the crop, cut from the photo at full size
    and enlarged, scored TOYOTA at 0 — correct, and thrown away by the rule
    that a word under 0.4 is not evidence. That rule is right and stays; what
    was wrong was using only one of the two readings. Only print that has
    already passed `_is_printing` arrives here.

    The trained model takes no part. It was wired in here and measured, and
    it does not recognise a car through its packaging — see `Recognition →
    Packaged cars` in CLAUDE.md for the numbers.
    """
    reading = ocr.read(bgr, rotations=rotations, stop_score=stop_score)
    # `artwork` looks for a logo on this crop, which is right when the crop is
    # a whole card — `/read-card`. In a scan the logos are found across the
    # photo instead and the answer arrives as `logo_brand`.
    vocabulary = vocabulary or brands.known(index)
    logo = logos.read(bgr, brands.on_artwork(index)) if artwork else None
    evidence = Evidence.from_words(reading.words, context=context, also=printed,
                                   logo_brand=logo[0] if logo else logo_brand, vocabulary=vocabulary)
    match = Matcher(index, min_confidence, min_margin).match(evidence, printed=True) if index else None
    return reading, evidence, match


def card_output(evidence: Evidence, match: Match | None, index: CatalogIndex | None,
                series: tuple[str, float] | None = None) -> dict:
    """What the card says, in the OCR Agent's output shape (ocr_output.json v1).

    Year is left null: the year on a card is artwork, not text Tesseract reads
    reliably, and the schema is explicit that the copyright year in the small
    print is not it — a wrong year is worse than none. The casting name is
    reported only when it matches a catalog casting confidently, and then as
    the catalog spells it.

    `series` is the wave read up the right-hand edge, where a mainline card
    prints it — see `read_edge_series`. Null for a package with no such
    strip, which is most of them outside the mainlines.
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
    if evidence.brand_from_logo:
        # Read off artwork, not off type: right in every measurement so far,
        # and read badly enough on purpose that it should not claim as much as
        # a wordmark set in plain type does.
        brand_conf = 0.6
    elif evidence.brand:
        brand_conf = 0.9 if best_window(compact(evidence.brand), flat) >= 0.95 else 0.75

    return {
        "schema_version": 1,
        "brand": evidence.brand,
        "year": None,
        "series": series[0] if series else None,
        "casting_name": casting_name,
        "collector_number": evidence.collector_number,
        "toy_number": toy_number,
        "confidence": {
            "brand": brand_conf,
            "year": 0.0,
            "series": round(series[1], 3) if series else 0.0,
            "casting_name": casting_conf,
            "collector_number": 0.7 if evidence.collector_number else 0.0,
            "toy_number": toy_conf,
        },
    }
