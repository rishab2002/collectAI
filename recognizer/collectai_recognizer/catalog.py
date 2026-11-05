"""The catalog index file, and matching a reading against it.

The index is exported by the Laravel app (`php artisan
catalog:export-recognition-index`) — every casting and variation the
recognizer may answer with. It is a plain JSON file, so it can be copied to
any computer and the recognizer run there with no database and no network.

**Only confident answers come back.** A casting is reported only when its
score clears `min_confidence` *and* beats the next different casting by
`min_margin`. Two castings that read the same — '93 and '95 Mazda RX-7 with
the year worn off — are a tie, and a tie is not an answer.
"""

from __future__ import annotations

import json
import re
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path

from .colours import palette
from .evidence import Evidence
from .textmatch import aliases, best_window, trigrams

FORMAT = "collectai-recognition-index"


class IndexError_(ValueError):
    pass


@dataclass
class Candidate:
    casting_id: int
    name: str
    score: float
    alias: str
    reasons: list[str] = field(default_factory=list)


class CatalogIndex:
    def __init__(self, data: dict):
        if data.get("format") != FORMAT:
            raise IndexError_(f"not a {FORMAT} file")
        self.generated_at = data.get("generated_at")
        self.brands = {int(b["id"]): b["name"] for b in data.get("brands", [])}
        self.castings = {int(c["id"]): c for c in data.get("castings", [])}
        # Stable keys (provenance, same in every database) -> this database's ids.
        self.casting_keys = {c["key"]: int(c["id"]) for c in data.get("castings", []) if c.get("key")}
        self.variation_keys = {v["key"]: int(v["id"]) for v in data.get("variations", []) if v.get("key")}
        self.variations: dict[int, list[dict]] = defaultdict(list)
        self.toy_numbers: dict[str, set[int]] = defaultdict(set)
        for v in data.get("variations", []):
            self.variations[int(v["casting_id"])].append(v)
            if v.get("toy_number"):
                self.toy_numbers[v["toy_number"].upper().replace(" ", "").replace("-", "")].add(int(v["casting_id"]))
        for c in self.castings.values():
            for code in c.get("codes", []) or []:
                self.toy_numbers[code.upper().replace(" ", "").replace("-", "")].add(int(c["id"]))

        # (casting id, alias with spaces, alias compacted, is the full name)
        self.entries: list[tuple[int, str, str, bool]] = []
        self.grams: dict[str, list[int]] = defaultdict(list)
        for cid, c in self.castings.items():
            for alias, full in aliases(c["name"]):
                flat = alias.replace(" ", "")
                if len(flat) < 3:
                    continue
                idx = len(self.entries)
                self.entries.append((cid, alias, flat, full))
                for g in trigrams(flat):
                    self.grams[g].append(idx)

    @classmethod
    def load(cls, path: str | Path) -> "CatalogIndex":
        return cls(json.loads(Path(path).read_text(encoding="utf-8")))

    def summary(self) -> dict:
        return {
            "generated_at": self.generated_at,
            "castings": len(self.castings),
            "variations": sum(len(v) for v in self.variations.values()),
        }

    def shortlist(self, text: str, limit: int = 80) -> list[int]:
        """Entries sharing the most trigrams with the text — the cheap pre-filter."""
        counts: dict[int, int] = defaultdict(int)
        for g in trigrams(text):
            for idx in self.grams.get(g, ()):
                counts[idx] += 1
        ranked = sorted(counts.items(), key=lambda kv: -kv[1] / max(1, len(self.entries[kv[0]][2])))
        return [idx for idx, _ in ranked[:limit]]


@dataclass
class Match:
    confident: bool
    candidates: list[Candidate]
    variations: list[dict] = field(default_factory=list)

    @property
    def best(self) -> Candidate | None:
        return self.candidates[0] if self.candidates else None

    def as_dict(self) -> dict:
        best = self.best
        return {
            "confident": self.confident,
            "casting_id": best.casting_id if best and self.confident else None,
            "casting_name": best.name if best and self.confident else None,
            "confidence": round(best.score, 3) if best else 0.0,
            "reasons": best.reasons if best else [],
            "variations": self.variations if self.confident else [],
            "candidates": [
                {"casting_id": c.casting_id, "name": c.name, "score": round(c.score, 3), "reasons": c.reasons}
                for c in self.candidates[:3]
            ],
        }


class Matcher:
    def __init__(self, index: CatalogIndex, min_confidence: float = 0.9, min_margin: float = 0.08):
        self.index = index
        self.min_confidence = min_confidence
        self.min_margin = min_margin

    def match(self, ev: Evidence, colour: str | None = None, visual=None, printed: bool = False) -> Match:
        """`visual` is a visual.VisualResult for the same crop, or None.

        `printed` says the text came off packaging rather than off a car's
        base, which changes what the copyright year means — see below — and
        lets the versions offered be ordered by the year on the card.
        """
        scores: dict[int, Candidate] = {}

        readings = [t for t in ev.readings if t]
        if readings and max(len(t) for t in readings) >= 3:
            tokens = set(ev.words.split()) | ev.tokens
            for idx in self.index.shortlist(" ".join(readings)):
                cid, alias, flat, full = self.index.entries[idx]
                if len(flat) < 5:
                    # Short names ("GTO", "Mini") match by accident inside
                    # longer words; only an exact standalone word counts.
                    r = 1.0 if alias in tokens or alias in ev.words.split(" ") else 0.0
                else:
                    r = max(best_window(flat, t) for t in readings)
                if printed and not full:
                    # Only whole names off a card. The shortened alias exists
                    # because a base often omits the leading '93 of '93 Mazda
                    # RX-7; a card prints the year, and dropping it throws
                    # away the one thing that separates a nameplate's
                    # generations. Measured on the two photographs in
                    # tests/Fixtures/packaging, both of this rule's absences
                    # were wrong answers given confidently: "'66 BUICK
                    # RIVIERA" came back as the casting "1971 Buick Riviera",
                    # and a 2018 Dodge Challenger SRT Demon as "'71 Dodge
                    # Challenger". Both matched on the nameplate alone, at
                    # 0.96, with the year sitting in the read text saying
                    # otherwise. The cost is a name strip that was cut off
                    # before its year now gets no answer instead of a lucky
                    # one, which is the way round this pipeline errs.
                    continue
                if r < 0.72 or not self._numbers_agree(alias, tokens, printed):
                    continue
                score = r - (0.0 if full else 0.04) - (0.04 if len(flat) < 8 else 0.0)
                current = scores.get(cid)
                if current is None or score > current.score:
                    scores[cid] = Candidate(cid, self.index.castings[cid]["name"], score, flat,
                                            [f"name read as “{alias}” ({r:.2f})"])

        for code in ev.codes:
            owners = self.index.toy_numbers.get(code, set())
            if len(owners) == 1:
                cid = next(iter(owners))
                c = scores.get(cid)
                if c is None:
                    scores[cid] = Candidate(cid, self.index.castings[cid]["name"], 0.95, code, [f"code {code}"])
                else:
                    c.score = max(c.score, 0.97) + 0.02
                    c.reasons.append(f"code {code}")
            for cid in set(scores) - owners if owners else ():
                scores[cid].score -= 0.1
                scores[cid].reasons.append(f"code {code} belongs to another casting")

        for c in scores.values():
            casting = self.index.castings[c.casting_id]
            brands = {self.index.brands.get(int(b)) for b in casting.get("brand_ids", [])}
            if ev.brand and brands:
                if ev.brand in brands:
                    c.score += 0.03
                    c.reasons.append(f"brand {ev.brand}" + (" (from the logo)" if ev.brand_from_logo else ""))
                elif not ev.brand_from_logo:
                    c.score -= 0.2
                    c.reasons.append(f"base says {ev.brand}, casting is {', '.join(sorted(b for b in brands if b))}")
            first, last = casting.get("first_year"), casting.get("last_year")
            if ev.copyright_year and first:
                if printed:
                    # A card is printed for the year it is sold in, so its
                    # copyright year falls inside the casting's own years, and
                    # the base rule below would mark down exactly the right
                    # answer: a 2024 card for a tool cut in 2010 is neither
                    # near the first release nor after the last.
                    #
                    # It only ever corroborates — there is no penalty for a
                    # year outside the range, where the base rule has one.
                    # A casting's first and last year here are the years that
                    # happen to have been imported, not the years it sold in,
                    # so with a catalog this far from complete "outside its
                    # years" is far more often a gap than the wrong car. It
                    # still does the job the year is there for, which is
                    # telling two castings of one name apart.
                    if first - 1 <= ev.copyright_year <= (last or first) + 1:
                        c.score += 0.03
                        c.reasons.append(f"© {ev.copyright_year} is within {first}–{last or first}")
                # A base carries the year the tool was cut, a year or so before
                # the first release.
                elif first - 8 <= ev.copyright_year <= first + 1:
                    c.score += 0.03
                    c.reasons.append(f"© {ev.copyright_year} fits first release {first}")
                elif ev.copyright_year > (last or first) + 1:
                    c.score -= 0.1
                    c.reasons.append(f"© {ev.copyright_year} is after its last release")
                else:
                    c.score -= 0.1
                    c.reasons.append(f"© {ev.copyright_year} is long before its first release {first}")
            c.score = max(0.0, min(1.0, c.score))

        self._apply_visual(scores, visual)

        ranked = sorted(scores.values(), key=lambda c: (-c.score, -len(c.alias)))
        ranked = self._without_dominated(ranked)
        if not ranked:
            return Match(False, [])

        best = ranked[0]
        runner = ranked[1].score if len(ranked) > 1 else 0.0
        confident = best.score >= self.min_confidence and best.score - runner >= self.min_margin
        looks = {}
        if visual is not None:
            for m in visual.candidates:
                if m.casting_id == best.casting_id:
                    looks = m.variations
        variations = self.variations_for(best.casting_id, colour, ev.codes, looks,
                                         year=ev.copyright_year if printed else None) if confident else []
        return Match(confident, ranked, variations)

    def _apply_visual(self, scores: dict[int, Candidate], visual) -> None:
        """Looks corroborate; they name a car alone only at the measured bar.

        - A text candidate that is also the closest-looking casting gets +0.03 —
          enough to break a tie between same-named castings, never enough to
          lift a weak reading over the confidence bar by itself.
        - When training measured that looks alone reach the target precision
          (`visual.sure`), the top casting is offered at the calibrated score,
          and it must still beat any text rival by the usual margin.
        """
        if visual is None or visual.top is None:
            return
        top = visual.top
        if top.casting_id not in self.index.castings:
            return
        reason = f"looks like it ({top.score:.2f})"
        if top.casting_id in scores:
            scores[top.casting_id].score = min(1.0, scores[top.casting_id].score + 0.03)
            scores[top.casting_id].reasons.append(reason)
        if visual.sure:
            calibrated = max(self.min_confidence, 0.9)
            c = scores.get(top.casting_id)
            if c is None:
                scores[top.casting_id] = Candidate(top.casting_id, self.index.castings[top.casting_id]["name"], calibrated,
                                                   "", [reason + ", above the measured bar"])
            elif c.score < calibrated:
                c.score = calibrated
                c.reasons.append("above the measured bar")

    @staticmethod
    def _year_match(printed: int | None, year: int | None) -> float:
        """1 for the same year, 0.5 for either side of it — a card is printed
        a little before the year it is sold in."""
        if not printed or not year:
            return 0.0
        return 1.0 if printed == year else (0.5 if abs(printed - year) == 1 else 0.0)

    @staticmethod
    def _numbers_agree(alias: str, tokens: set[str], printed: bool = False) -> bool:
        """RX-3 is not RX-7. Every number in a name must be read, exactly.

        Fuzzy similarity treats one wrong digit as a near-perfect match, and in
        a car name the digit is usually the whole difference.

        The one thing that is the same number written two ways is the model
        year: the catalog calls a casting "'04 Subaru Forester STI" and the
        card it is sold on prints "2004". Off a card, and only off a card,
        a two-digit number in the name is therefore satisfied by the four-digit
        year ending in it — without which, now that a card has to match the
        whole name, no Hot Wheels casting named with a year could ever match
        the card it is printed on.
        """
        wanted = re.findall(r"\d+", alias)
        if not wanted:
            return True
        read = {d for t in tokens for d in re.findall(r"\d+", t)}
        if printed:
            read |= {y[2:] for y in read if re.fullmatch(r"(19|20)\d{2}", y)}
        return all(d in read for d in wanted)

    @staticmethod
    def _without_dominated(ranked: list[Candidate]) -> list[Candidate]:
        """"Mustang" inside a fully matched "Mustang Mach 1" is not a rival."""
        kept: list[Candidate] = []
        for c in ranked:
            if any(c.alias != k.alias and c.alias in k.alias and k.score >= c.score - 0.02 for k in kept):
                continue
            kept.append(c)
        return kept

    def variations_for(self, casting_id: int, colour: str | None, codes: list[str],
                       looks: dict[int, float] | None = None, limit: int = 12,
                       year: int | None = None) -> list[dict]:
        """`year` is the year printed on a package, which orders the versions
        ahead of anything a photograph of a sealed card can say about colour."""
        pal = palette()
        out = []
        for v in self.index.variations.get(casting_id, []):
            code = (v.get("toy_number") or "").upper()
            # An upside-down car shows its base, not its paint: the colour in
            # the photo may be either, so it counts against whichever agrees.
            body = pal.similarity(colour, v.get("color_family"))
            base = pal.similarity(colour, v.get("base_color_family"))
            out.append({
                "variation_id": int(v["id"]),
                "year": v.get("year"),
                "color": v.get("color"),
                "color_family": v.get("color_family"),
                "wheel_type": v.get("wheel_type"),
                "toy_number": v.get("toy_number"),
                "series": v.get("series"),
                "base_color": v.get("base_color"),
                "colour_match": max(body, base),
                "colour_matched_on": None if max(body, base) == 0 else ("body" if body >= base else "base"),
                "code_match": bool(code) and code in codes,
                # Similarity to a labelled photo of this exact version, if the
                # trained model has one.
                "looks_match": round((looks or {}).get(int(v["id"]), 0.0), 3),
                "year_match": self._year_match(year, v.get("year")),
            })
        out.sort(key=lambda v: (-int(v["code_match"]), -v["year_match"], -v["looks_match"],
                                -v["colour_match"], -(v["year"] or 0)))
        return out[:limit]
