"""Synthetic photos for the tests: a pile of plain shapes, and a base with text.

Not a stand-in for real photos — real-photo accuracy has to be measured on real
photos — but enough to pin down what each stage does.
"""

from __future__ import annotations

import cv2
import numpy as np

BGR = {"red": (40, 30, 200), "blue": (190, 90, 30), "yellow": (40, 210, 235), "green": (60, 160, 40), "black": (25, 25, 25)}


def car(img: np.ndarray, centre: tuple[int, int], size: tuple[int, int], colour: str, angle: float = 0.0) -> None:
    """A car seen from above: a body, dark windows, four black wheels."""
    cx, cy = centre
    w, h = size
    body = cv2.boxPoints(((cx, cy), (w, h), angle)).astype(np.int32)
    cv2.fillPoly(img, [body], BGR[colour])
    window = cv2.boxPoints(((cx, cy), (w * 0.3, h * 0.7), angle)).astype(np.int32)
    cv2.fillPoly(img, [window], (60, 50, 50))
    rad = np.deg2rad(angle)
    for dx, dy in ((-0.32, -0.55), (0.32, -0.55), (-0.32, 0.55), (0.32, 0.55)):
        x = cx + dx * w * np.cos(rad) - dy * h * np.sin(rad)
        y = cy + dx * w * np.sin(rad) + dy * h * np.cos(rad)
        cv2.circle(img, (int(x), int(y)), int(h * 0.12), (20, 20, 20), -1)


def pile(cars: list[tuple[str, tuple[int, int], float]], size=(900, 1400)) -> np.ndarray:
    img = np.full((size[0], size[1], 3), (225, 228, 230), dtype=np.uint8)
    noise = np.random.default_rng(1).normal(0, 3, img.shape)
    img = np.clip(img + noise, 0, 255).astype(np.uint8)
    for colour, centre, angle in cars:
        car(img, centre, (300, 130), colour, angle)
    return img


def base(lines: list[str], size=(420, 1500), upside_down: bool = False) -> np.ndarray:
    """A base plate: grey metal with dark printed text, filling the frame."""
    img = np.full((size[0], size[1], 3), (150, 150, 155), dtype=np.uint8)
    y = 120
    for line in lines:
        cv2.putText(img, line, (60, y), cv2.FONT_HERSHEY_SIMPLEX, 2.2, (35, 35, 35), 5, cv2.LINE_AA)
        y += 120
    return cv2.rotate(img, cv2.ROTATE_180) if upside_down else img


CARD_ART = {"orange": (30, 110, 235), "teal": (150, 140, 20), "purple": (140, 40, 110), "lime": (40, 190, 120)}


def card(name: str, series: str | None = None, collector: str | None = None, toy: str | None = None,
         size=(620, 420), art: str = "orange", landscape: bool = False) -> np.ndarray:
    """A blister card: printed art, a blister bubble with a dark car in it, and
    the casting name in big letters across the bottom — the only part of a
    package that names the car."""
    h, w = size
    img = np.full((h, w, 3), CARD_ART[art], dtype=np.uint8)
    cv2.rectangle(img, (6, 6), (w - 7, h - 7), (240, 240, 240), 4)           # the printed edge
    cv2.rectangle(img, (int(w * 0.08), int(h * 0.14)), (int(w * 0.92), int(h * 0.60)), (235, 238, 240), -1)
    car(img, (w // 2, int(h * 0.37)), (int(w * 0.62), int(h * 0.2)), "black")  # behind the plastic

    scale = w / 420 * 0.95
    cv2.putText(img, name, (int(w * 0.07), int(h * 0.75)), cv2.FONT_HERSHEY_DUPLEX, scale, (255, 255, 255), max(1, int(scale * 2)), cv2.LINE_AA)
    if series:
        cv2.putText(img, series, (int(w * 0.07), int(h * 0.84)), cv2.FONT_HERSHEY_SIMPLEX, scale * 0.5, (250, 250, 250), 1, cv2.LINE_AA)
    if collector:
        cv2.putText(img, collector, (int(w * 0.07), int(h * 0.93)), cv2.FONT_HERSHEY_SIMPLEX, scale * 0.45, (250, 250, 250), 1, cv2.LINE_AA)
    if toy:
        cv2.putText(img, toy, (int(w * 0.62), int(h * 0.93)), cv2.FONT_HERSHEY_SIMPLEX, scale * 0.45, (250, 250, 250), 1, cv2.LINE_AA)
    return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE) if landscape else img


def layout(cards: list[np.ndarray], columns: int = 4, gap: int = 26, margin: int = 50,
           surface=(206, 208, 210)) -> np.ndarray:
    """Packages laid out in rows on a plain surface, the way a collection gets
    photographed. Cell-sized so a landscape box sits beside a portrait card."""
    cell_w = max(c.shape[1] for c in cards) + gap
    cell_h = max(c.shape[0] for c in cards) + gap
    rows = (len(cards) + columns - 1) // columns
    img = np.full((rows * cell_h + 2 * margin - gap, columns * cell_w + 2 * margin - gap, 3), surface, dtype=np.uint8)
    noise = np.random.default_rng(7).normal(0, 2.5, img.shape)
    img = np.clip(img + noise, 0, 255).astype(np.uint8)
    for i, c in enumerate(cards):
        top = margin + (i // columns) * cell_h + (cell_h - gap - c.shape[0]) // 2
        left = margin + (i % columns) * cell_w + (cell_w - gap - c.shape[1]) // 2
        img[top:top + c.shape[0], left:left + c.shape[1]] = c
    return img


def logo(text: str = "HOT WHEELS", size=(120, 420), field=(40, 90, 235), ink=(40, 240, 250)) -> np.ndarray:
    """A brand wordmark as artwork: drawn type on a field of brand colour.

    Not the real logo — a stand-in with the one property that matters, which
    is that it is a word on a saturated red-to-yellow patch rather than text
    on a page. Tesseract reads a real one badly; the point of the test is that
    badly is enough.
    """
    h, w = size
    img = np.full((h, w, 3), field, dtype=np.uint8)
    scale = w / 420 * 1.5
    cv2.putText(img, text, (int(w * 0.04), int(h * 0.68)), cv2.FONT_HERSHEY_DUPLEX, scale, ink,
                max(2, int(scale * 2)), cv2.LINE_AA)
    return img


def card_with_logo(name: str, brand: str = "HOT WHEELS", size=(620, 420), art: str = "teal", **kwargs) -> np.ndarray:
    """A card whose brand is only ever stated by its logo, never as plain text.

    Teal art by default: a real card's artwork is not the colour of its logo,
    and on one that is the logo cannot be told from the card by colour alone.
    """
    img = card(name, size=size, art=art, **kwargs)
    mark = logo(brand, size=(int(size[0] * 0.17), int(size[1] * 0.78)))
    img[int(size[0] * 0.02):int(size[0] * 0.02) + mark.shape[0],
        int(size[1] * 0.06):int(size[1] * 0.06) + mark.shape[1]] = mark
    return img
