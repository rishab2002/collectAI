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
    """What a reading says. `text`/`words` are the main reading; `alternates`
    are other readings of the same print — see `from_words`."""

    raw: str
    text: str                      # compacted, boilerplate removed — what names are matched in
    words: str                     # normalised, boilerplate removed, spaces kept
    # Other readings of the same print, compacted the same way. A name is
    # looked for in each of them separately and the best answer wins; they are
    # never joined up, because the join invents letter pairs that were never
    # printed. Measured: a card printing "FERRARI" read twice, concatenated,
    # makes "…ferrariferrari…", whose window "ferrarif" is a 0.94 match for
    # the casting "Ferrari FF" — a car that is not in the photo, named
    # confidently, out of a join.
    alternates: list[str] = field(default_factory=list)
    brand: str | None = None
    copyright_year: int | None = None
    codes: list[str] = field(default_factory=list)
    collector_number: str | None = None
    # True when the brand was read off artwork rather than off type — see
    # logos.py. Weaker evidence, so the matcher lets it corroborate a casting
    # and break a tie but never marks one down for disagreeing with it.
    brand_from_logo: bool = False

    @classmethod
    def from_words(cls, words: list[tuple[str, float]], min_word_confidence: float = 0.4,
                   context: str = "", also: str = "", logo_brand: str | None = None) -> "Evidence":
        """Low-confidence words are noise from tampos and scratches; leave them out.

        `context` is print read near this one but not on the crop — on a
        packaged scan, the brand logo at the top of a card whose name is read
        off the bottom. It can say what brand, what year and what code, and it
        deliberately cannot say what the car is: a name belongs to the package
        it is printed on, and the one stacked behind it is a different car.

        `also` is a second reading of this same print, which can say all of
        those things including the name. See `packaging.read_printed`.
        """
        kept = " ".join(w for w, c in words if c >= min_word_confidence)
        evidence = cls.from_text(kept, everything=" ".join(w for w, _ in words) + " " + also + " " + context)
        if also.strip():
            second = cls.from_text(also).text
            if second and second != evidence.text:
                evidence.alternates = [second]
        # Type beats artwork: a brand spelled out in the small print is read
        # properly, and only when there is none does the logo get to answer.
        if evidence.brand is None and logo_brand is not None:
            evidence.brand, evidence.brand_from_logo = logo_brand, True
        return evidence

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
        #
        # The collector number comes out whole, as the text it was printed as.
        # Taking it out word by word would take out its digits wherever they
        # appear, and on a card that is most of the catalog: "3/250" under the
        # name "MAZDA RX-3" leaves "mazda rx", which then fails the rule that
        # every number in a name must be read exactly, and the car goes
        # unnamed. Measured on a layout of twelve packages, this was the
        # difference between 8 identified and 9.
        drop = {str(year)} | {c.lower() for c in codes}
        words = " ".join(w for w in meaningful(COLLECTOR.sub(" ", text)).split() if w not in drop)
        return cls(
            raw=everything,
            text=words.replace(" ", ""),
            words=words,
            brand=brand,
            copyright_year=year,
            codes=codes,
            collector_number=f"{collector.group(1)}/{collector.group(2)}" if collector else None,
        )

    @property
    def readings(self) -> list[str]:
        """Every compacted reading of this print, the main one first."""
        return [self.text, *self.alternates]

    @property
    def tokens(self) -> set[str]:
        """Every word read, across every reading."""
        out = set(self.words.split())
        for alternate in self.alternates:
            out |= set(alternate.split())
        return out
