"""Name normalisation and fuzzy matching, shared by the matcher and the card reader."""

from __future__ import annotations

import re
import unicodedata
from difflib import SequenceMatcher

# Printed on bases and cards but never part of a casting name.
STOPWORDS = {
    "hot", "wheels", "mattel", "inc", "made", "in", "malaysia", "china", "thailand",
    "indonesia", "vietnam", "india", "matchbox", "lesney", "england", "tomy", "tomica",
    "japan", "el", "segundo", "ca", "usa", "co", "ltd", "the", "reg", "pat", "pend",
    "mini", "gt", "tsm", "all", "rights", "reserved", "c", "copyright",
}


def normalise(text: str) -> str:
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    text = text.lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return " ".join(text.split())


def compact(text: str) -> str:
    return normalise(text).replace(" ", "")


def aliases(name: str) -> list[tuple[str, bool]]:
    """(alias, is_full_name). A base often omits the leading '93 of '93 Mazda RX-7."""
    full = normalise(name)
    out = [(full, True)] if full else []
    m = re.match(r"^(\d{2}|\d{4}) (.+)$", full)
    if m and len(m.group(2)) >= 4:
        out.append((m.group(2), False))
    return out


def trigrams(s: str) -> set[str]:
    s = f"  {s} "
    return {s[i:i + 3] for i in range(len(s) - 2)}


def best_window(needle: str, haystack: str) -> float:
    """Best similarity of `needle` to any same-length-ish window of `haystack`.

    Both compacted (spaces removed): OCR splits and joins words freely, so
    "RX-7" may come back as "RX 7" or "RX7".
    """
    n = len(needle)
    if n == 0 or not haystack:
        return 0.0
    if needle in haystack:
        return 1.0
    best = 0.0
    for size in (n - 1, n, n + 1):
        if size <= 0 or size > len(haystack):
            continue
        for start in range(0, len(haystack) - size + 1):
            window = haystack[start:start + size]
            if window[0] != needle[0] and window[-1] != needle[-1]:
                continue
            r = SequenceMatcher(None, needle, window, autojunk=False).ratio()
            if r > best:
                best = r
    if best == 0.0 and len(haystack) < n + 2:
        best = SequenceMatcher(None, needle, haystack, autojunk=False).ratio()
    return best


def meaningful(text: str) -> str:
    """Normalised text with the boilerplate words removed."""
    return " ".join(w for w in normalise(text).split() if w not in STOPWORDS)
