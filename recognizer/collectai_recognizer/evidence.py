"""What a reading says, in the terms the matcher uses."""

from __future__ import annotations

import re
from dataclasses import dataclass, field

from .textmatch import best_window, compact, meaningful

BRANDS = {
    "Hot Wheels": ["hotwheels"],
    "Matchbox": ["matchbox", "lesney"],
    "Tomica": ["tomica", "tomytec"],
    "Mini GT": ["minigt"],
}

# A year only counts when it is marked as a copyright year (©, (C), "Mattel").
# A bare 4-digit number on a base is as likely to be part of a name or a mould
# number, and a wrong year would push the matcher the wrong way.
COPYRIGHT = [
    re.compile(r"(?:©|\(c\)|\bc\)|\(c\b|@|copyright)\s*'?((?:19[5-9]|20[0-4])\d)", re.I),
    re.compile(r"\b((?:19[5-9]|20[0-4])\d)\s*,?\s*(?:mattel|lesney|tomy)", re.I),
    re.compile(r"(?:mattel|lesney|tomy)\s*(?:inc\.?|ltd\.?)?\s*,?\s*((?:19[5-9]|20[0-4])\d)", re.I),
]

CODES = [
    re.compile(r"\b([A-Z]{3}\d{2})\b"),         # modern Mattel toy number: HTB22
    re.compile(r"\b([A-Z]\d{4})\b"),             # older Mattel: T9819
    re.compile(r"\b(MB\s?-?\d{2,4})\b"),         # Matchbox model code: MB1069
    re.compile(r"\b(MGT\s?-?\d{3,5})\b"),        # Mini GT item: MGT00123
]

COLLECTOR = re.compile(r"\b(\d{1,3})\s*/\s*(\d{2,3})\b")


@dataclass
class Evidence:
    raw: str
    text: str                      # compacted, boilerplate removed — what names are matched in
    words: str                     # normalised, boilerplate removed, spaces kept
    brand: str | None = None
    copyright_year: int | None = None
    codes: list[str] = field(default_factory=list)
    collector_number: str | None = None

    @classmethod
    def from_words(cls, words: list[tuple[str, float]], min_word_confidence: float = 0.4) -> "Evidence":
        """Low-confidence words are noise from tampos and scratches; leave them out."""
        kept = " ".join(w for w, c in words if c >= min_word_confidence)
        return cls.from_text(kept, everything=" ".join(w for w, _ in words))

    @classmethod
    def from_text(cls, text: str, everything: str | None = None) -> "Evidence":
        everything = everything if everything is not None else text
        flat = compact(everything)
        brand = None
        for name, needles in BRANDS.items():
            if any(best_window(n, flat) >= 0.86 for n in needles):
                brand = name
                break

        year = None
        for pattern in COPYRIGHT:
            m = pattern.search(everything)
            if m:
                year = int(m.group(1))
                break

        codes: list[str] = []
        upper = everything.upper()
        for pattern in CODES:
            for m in pattern.finditer(upper):
                code = re.sub(r"[\s-]", "", m.group(1))
                if code not in codes:
                    codes.append(code)

        collector = COLLECTOR.search(everything)
        # The year, codes and collector number are evidence of their own; left
        # in, they would be read as part of a name.
        drop = {str(year)} | {c.lower() for c in codes}
        if collector:
            drop |= {collector.group(1), collector.group(2)}
        words = " ".join(w for w in meaningful(text).split() if w not in drop)
        return cls(
            raw=everything,
            text=words.replace(" ", ""),
            words=words,
            brand=brand,
            copyright_year=year,
            codes=codes,
            collector_number=f"{collector.group(1)}/{collector.group(2)}" if collector else None,
        )
