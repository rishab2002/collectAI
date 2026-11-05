"""Brand logos, read as the text they are.

A Hot Wheels flame and a Matchbox box are type — "HOT WHEELS", "MATCHBOX" —
drawn as artwork: slanted, outlined, on a coloured field. Tesseract reading a
whole card never finds them, so until now a Hot Wheels card came back with no
brand at all, and the brand is what tells two castings of one name apart.

Handed just the logo, though, it does read them, badly and consistently:
"Hor wres", "JTIATLHBOX", "MTATLHBON WWF". Badly is enough, because the
vocabulary is four names rather than a catalog. So the logo is found by its
colour, read on its own, and matched against the brand names at a bar
measured in the gap between the two populations.

**Measured** on the owner's photographs and the fixtures. A real wordmark
scores 0.59–0.80 against its own brand; everything else — carpet Tesseract
made words of, and genuine card text like PORSCHE, CHALLENGER, FACTORY FRESH,
GUARANTEED FOR LIFE — reaches at most 0.40. The bar sits at 0.55, in the
empty gap, the same way the generation threshold was placed. Re-measure the
gap rather than nudging the number.

What this is not: reading the *name* off artwork. A logo says which company
made the car and nothing else, so what it yields is a brand and is used as
corroboration — it never names a car, and never counts against one.
"""

from __future__ import annotations

import cv2
import numpy as np

from . import brands, ocr

# Red through yellow. Red alone was the obvious choice and it is wrong: the
# mainline Hot Wheels logo is a red flame, but the Premium one is **yellow on
# black**, and Matchbox's is white on red. Measured — with red alone, not one
# logo in the owner's photographs was found.
HUE_BANDS = ((0, 16), (168, 180), (18, 36))

# Low, because a package lying on a carpet under a ceiling light is dim. The
# cost of going lower is Tesseract calls on patches that are not logos, and
# those come back as nothing rather than as a brand.
MIN_SATURATION = 90
MIN_VALUE = 60

# A logo is a sizeable patch of the package, and wider than it is tall without
# being a stripe. Below the floor is a red tail light in the artwork.
MIN_SHARE = 0.0006
MAX_SHARE = 0.30
MIN_RATIO = 1.1
MAX_RATIO = 5.0
MIN_SIDE = 24

# In the empty gap between a real wordmark (0.59–0.80) and everything else
# (≤ 0.40). See the module docstring.
MIN_SIMILARITY = 0.55

# Enough to read letters that were drawn rather than typeset.
TARGET_HEIGHT = 220

# Only the biggest few patches are worth a Tesseract call each.
MAX_REGIONS = 3

def regions(bgr: np.ndarray) -> list[tuple[int, int, int, int]]:
    """The logo-shaped patches of brand colour, biggest first."""
    hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)
    hue, saturation, value = (hsv[:, :, i].astype(np.int16) for i in range(3))

    in_band = np.zeros(hue.shape, dtype=bool)
    for low, high in HUE_BANDS:
        in_band |= (hue >= low) & (hue <= high)
    mask = (in_band & (saturation >= MIN_SATURATION) & (value >= MIN_VALUE)).astype(np.uint8) * 255

    k = max(3, int(min(bgr.shape[:2]) * 0.01)) | 1
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k)))

    frame = float(bgr.shape[0] * bgr.shape[1])
    found = []
    for contour in cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)[0]:
        x, y, w, h = cv2.boundingRect(contour)
        if w < MIN_SIDE or h < MIN_SIDE // 2 or h == 0:
            continue
        if not MIN_SHARE <= (w * h) / frame <= MAX_SHARE:
            continue
        if not MIN_RATIO <= w / h <= MAX_RATIO:
            continue
        found.append((x, y, w, h))

    return sorted(found, key=lambda r: -r[2] * r[3])[:MAX_REGIONS]


def read_patch(bgr: np.ndarray, box: tuple[int, int, int, int], scale: float = 1.0,
               vocabulary: "brands.Vocabulary | None" = None) -> tuple[str, float] | None:
    """The brand one patch of artwork spells out, and how sure, or None.

    `scale` is how much smaller the image the box was found in is than `bgr`,
    so the patch is cut from the photo at full size — a logo 30 pixels tall
    where it was found is 90 in the original, and that is the difference
    between "Hor wres" and nothing.

    psm 8 — one word — because that is what a wordmark is, and because asking
    Tesseract to find text on a page of flame finds none.
    """
    x, y, w, h = (int(round(v / scale)) for v in box)
    crop = bgr[max(0, y):y + h, max(0, x):x + w]
    if crop.size == 0:
        return None

    big = cv2.resize(crop, None, fx=(f := max(1.0, TARGET_HEIGHT / max(1, crop.shape[0]))), fy=f,
                     interpolation=cv2.INTER_CUBIC)
    gray = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8)).apply(cv2.cvtColor(big, cv2.COLOR_BGR2GRAY))
    _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    words = vocabulary or brands.known()
    best: tuple[str, float] | None = None
    for image in (binary, gray):
        text = ocr.tesseract(image, psm=8).text
        found = words.match(text, MIN_SIMILARITY)
        if found and (best is None or found[1] > best[1]):
            best = found
    return best


def read(bgr: np.ndarray, vocabulary: "brands.Vocabulary | None" = None) -> tuple[str, float] | None:
    """The brand the artwork in this one image says it is. For a whole card."""
    for box in regions(bgr):
        if (found := read_patch(bgr, box, vocabulary=vocabulary)) is not None:
            return found
    return None


def find(full: np.ndarray, work: np.ndarray, scale: float,
         vocabulary: "brands.Vocabulary | None" = None) -> list[tuple[tuple[int, int, int, int], str]]:
    """Every brand logo in the photo: where it is, in `work` coordinates, and
    what it says. One pass, because a logo belongs to a card and a card can
    hold two cars — and because a Tesseract call per package per logo adds up.
    """
    out = []
    for box in regions(work):
        if (found := read_patch(full, box, scale, vocabulary)) is not None:
            out.append((box, found[0]))
    return out


def nearest(boxes: list[tuple[tuple[int, int, int, int], str]], package: tuple[int, int, int, int]) -> str | None:
    """The brand of the logo closest to this package, if one is close enough.

    Close enough is three times the logo's own width: a logo is about half the
    width of the card it is printed on, so that reaches the far corner of its
    own card and rarely the next one. A card with two cars on it hands its
    brand to both, which is right — and a package that takes its neighbour's
    logo is wrong but harmless, because a brand read off artwork only ever
    corroborates a casting and is shown to the admin to correct.
    """
    px, py, pw, ph = package
    centre = (px + pw / 2, py + ph / 2)
    best, closest = None, None

    for (x, y, w, h), brand in boxes:
        distance = np.hypot(centre[0] - (x + w / 2), centre[1] - (y + h / 2))
        if distance <= 3 * w and (closest is None or distance < closest):
            best, closest = brand, distance

    return best
