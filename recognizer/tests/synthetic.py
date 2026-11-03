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
