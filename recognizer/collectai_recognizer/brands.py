"""Which company made it: one vocabulary, grown from two directions.

`data/brands.json` is the seed — the diecast makers worth knowing before the
catalog has met them. The catalog index is the other half: every brand it
carries is added to the vocabulary at scan time, so a brand typed into the
Add-a-car form is recognised from the next index export with nothing edited
here. There are far more diecast makers than any list will hold, and the two
halves cover the two ways a new one turns up — one somebody added to the
catalog, and one that is only on a box on the table.

Short spellings are the hazard a long list brings. "BBR", "TLV", "M2" and
"GCD" are three characters, and three characters fall inside plenty of words
that are not a brand, so anything under five is matched as a whole word only
— the same rule, for the same reason, as short casting names in `catalog.py`.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

from .textmatch import best_window, compact, normalise

DATA_FILE = Path(__file__).resolve().parent.parent / "data" / "brands.json"

# Below this, a spelling has to be spelled out exactly, as whole words,
# rather than found as a close window inside a longer string.
#
# Measured, and the number is not cosmetic. A card's crop carries 70-odd
# characters of OCR noise round its printing ("oostilsudionlanucoro…"), and in
# that much garbage a five-letter name finds a five-letter window that matches
# it at 0.80 or better: Herpa and Norev were both reported off photographs of
# Hot Wheels cards that say neither. Eight is where the catalog's own short
# names stop needing fuzziness — "matchbox" is exactly eight and does need it,
# because OCR drops a letter out of a word it otherwise reads.
WHOLE_WORD_UNDER = 8


@dataclass(frozen=True)
class Vocabulary:
    """(brand as it should be reported, spelling compacted for matching)."""

    spellings: tuple[tuple[str, str], ...]

    @property
    def names(self) -> tuple[str, ...]:
        return tuple(dict.fromkeys(name for name, _ in self.spellings))

    def match(self, text: str, threshold: float) -> tuple[str, float] | None:
        """The brand this text names, and how well, or None.

        `text` is matched both ways it has to be: compacted, because OCR
        splits and joins words freely, and as words, because a short spelling
        is only allowed to match a whole one.
        """
        flat = compact(text)
        if not flat:
            return None

        runs = _runs(normalise(text).split())

        best: tuple[str, float] | None = None
        for name, spelling in self.spellings:
            # Whole words, not whole *word*: "Mini GT" is two of them and
            # compacts to six characters, so a single-token test would never
            # find it however plainly the box says it.
            score = (1.0 if spelling in runs else 0.0) if len(spelling) < WHOLE_WORD_UNDER \
                else best_window(spelling, flat)
            if score >= threshold and (best is None or score > best[1]):
                best = (name, round(score, 3))
        return best

    def with_names(self, names: list[str]) -> "Vocabulary":
        """This vocabulary plus brands the catalog knows.

        A catalog brand the seed already lists keeps the seed's spellings —
        "Lesney" for Matchbox, "Tomytec" for Tomica — rather than being added
        again as its bare name.
        """
        extra: list[tuple[str, str]] = []
        for name in names:
            flat = compact(name)
            if not flat:
                continue
            if any(flat == spelling for _, spelling in self.spellings):
                continue
            # The seed knows this brand under other spellings: take them all.
            aliases = [(n, s) for n, s in seed().spellings if compact(n) == flat]
            extra.extend(aliases or [(name, flat)])
        return Vocabulary(tuple(sorted(set(self.spellings) | set(extra), key=lambda p: -len(p[1]))))


def _runs(words: list[str], longest: int = 3) -> set[str]:
    """Every run of up to `longest` consecutive words, joined up."""
    return {
        "".join(words[start:start + length])
        for length in range(1, longest + 1)
        for start in range(len(words) - length + 1)
    }


@lru_cache(maxsize=4)
def seed(path: str | None = None) -> Vocabulary:
    data = json.loads(Path(path or DATA_FILE).read_text(encoding="utf-8"))
    spellings = [
        (brand["name"], flat)
        for brand in data["brands"]
        for spelling in brand["spellings"]
        if (flat := compact(spelling))
    ]
    # Longest first, so "Tomica Limited Vintage" is tried before "Tomica" and
    # "Inno Models" before a shorter spelling that is inside it.
    spellings.sort(key=lambda pair: -len(pair[1]))
    return Vocabulary(tuple(spellings))


def known(index=None) -> Vocabulary:
    """Every brand: the seed list, plus whatever the catalog holds.

    One vocabulary for printed type and for logos, which is worth saying
    because it was briefly two. A long list looked unusable: at 44 brands the
    same photographs that had produced no wrong brand at four produced ten,
    Welly and Peako and Norev among them, and the obvious conclusion was that
    a garbled logo read can only be resolved against a handful of names.

    The conclusion was wrong and the cause was short names. A five-letter
    brand was being found as a five-letter *window* inside seventy characters
    of OCR noise; once short spellings had to be spelled out as whole words
    (`WHOLE_WORD_UNDER`), the long list measured exactly the same as the
    short one — no wrong brand on any photograph, by either route. So the
    list stays long, which is the point: a Schuco box is read whether or not
    the catalog has ever heard of Schuco.
    """
    vocabulary = seed()
    if index is None:
        return vocabulary
    return vocabulary.with_names(_catalog_names(index))


def _catalog_names(index) -> list[str]:
    return [n for n in getattr(index, "brands", {}).values() if n]
