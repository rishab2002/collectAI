"""The brand vocabulary: a seed list that the catalog grows."""

import unittest
from pathlib import Path

import cv2

from collectai_recognizer import brands, ocr
from collectai_recognizer.evidence import PRINTED_BRAND, Evidence
from collectai_recognizer.logos import MIN_SIMILARITY
from collectai_recognizer.pipeline import recognise

from .test_recognizer import index


class SeedTest(unittest.TestCase):
    def test_the_makers_a_collector_meets_are_in_it(self):
        names = brands.seed().names
        for maker in ("Hot Wheels", "Matchbox", "Tomica", "Mini GT", "Schuco", "Inno64",
                      "Para64", "Herpa", "BBR", "Shadow Model", "Majorette", "Maisto"):
            self.assertIn(maker, names)

    def test_a_box_printing_its_name_is_read_even_though_the_catalog_has_no_such_brand(self):
        # The whole point of the seed: a maker nobody has entered yet.
        vocabulary = brands.known()
        for printed, brand in [("SCHUCO 1:64", "Schuco"), ("INNO64 MODELS", "Inno64"),
                               ("made by Tarmac Works", "Tarmac Works"), ("PARA64", "Para64"),
                               ("MINI GT", "Mini GT"), ("BBR", "BBR")]:
            with self.subTest(printed=printed):
                self.assertEqual(brand, vocabulary.match(printed, PRINTED_BRAND)[0])

    def test_a_line_is_reported_as_the_brand_whose_line_it_is(self):
        # TLV is Tomica's line, and the catalog files its cars under Tomica.
        self.assertEqual("Tomica", brands.known().match("a TLV release", PRINTED_BRAND)[0])

    def test_card_text_that_is_not_a_brand_names_none(self):
        vocabulary = brands.known()
        for printed in ("PORSCHE", "TIME MACHINE", "KOENIGSEGG AGERA R", "EVOLUTION IX WAGON",
                        "2004 SUBARU FORESTER STI", "FACTORY FRESH", "GUARANTEED FOR LIFE"):
            with self.subTest(printed=printed):
                self.assertIsNone(vocabulary.match(printed, PRINTED_BRAND))

    def test_a_short_name_has_to_be_spelled_out_not_found_inside_noise(self):
        # 70 characters of OCR noise off a Hot Wheels card. A five-letter name
        # finds a five-letter window in that at 0.80, which is how Herpa and
        # Norev were reported off photographs that say neither.
        noise = "oostilsudionlanucoroevolution1xwagonhrcjonueeessssefnameermwe4aefape"
        self.assertIsNone(brands.known().match(noise, PRINTED_BRAND))
        self.assertIsNone(brands.known().match(noise, MIN_SIMILARITY))

    def test_but_a_short_name_actually_printed_is_still_read(self):
        self.assertEqual("Welly", brands.known().match("WELLY diecast", PRINTED_BRAND)[0])
        self.assertEqual("IXO", brands.known().match("IXO Models", PRINTED_BRAND)[0])


class NoFalseBrandsTest(unittest.TestCase):
    """The guard that lets the list keep growing.

    `data/card-text.txt` is text that really appears on a card, in the
    catalog, or as OCR noise read off one — 450 lines of casting names,
    series names, packaging boilerplate and garbage. None of it is a brand,
    so nothing in `brands.json` may match any of it. Add a brand and run
    this: if the new name collides with something printed on cards, it will
    say so here rather than on a photograph months later.
    """

    @staticmethod
    def corpus() -> list[str]:
        text = (Path(__file__).resolve().parent / "data" / "card-text.txt").read_text(encoding="utf-8")
        return [line for line in text.splitlines() if line.strip() and not line.startswith("#")]

    def test_no_brand_is_found_in_text_that_is_not_a_brand(self):
        """At the printed-type bar, which is the one applied to card text.

        Measured clean across all 1,355 lines with 44 brands in the list, and
        that is what says a long list is safe here. The logo bar (0.55) is a
        different matter and is **not** tested against this corpus: 466 of
        these lines clear it, every one of them a casting name the logo
        reader never sees, because it only ever reads a patch of brand colour
        at psm 8. The logo path's safety comes from what it is fed, not from
        its threshold, so it is guarded end-to-end on a photograph below
        rather than pretended about here.
        """
        vocabulary = brands.known()
        wrong = [
            (line, found) for line in self.corpus()
            if (found := vocabulary.match(line, PRINTED_BRAND))
        ]
        self.assertEqual([], wrong, f"{len(wrong)} lines of card text read as a brand")

    def test_every_spelling_is_long_enough_to_be_worth_having(self):
        for name, spelling in brands.seed().spellings:
            with self.subTest(brand=name):
                self.assertGreaterEqual(len(spelling), 3, f"{name}: {spelling!r} is too short to identify anything")

    def test_no_two_brands_share_a_spelling(self):
        seen: dict[str, str] = {}
        for name, spelling in brands.seed().spellings:
            self.assertEqual(seen.setdefault(spelling, name), name,
                             f"{spelling!r} is claimed by both {seen[spelling]} and {name}")


@unittest.skipIf(ocr.available() is None, "tesseract is not installed")
class NoWrongBrandOnARealPhotographTest(unittest.TestCase):
    """The guard the logo path actually needs.

    Five overlapping Hot Wheels premium two-packs, photographed on a carpet.
    Every brand the scan reports has to be Hot Wheels — not most of them. Add
    a brand to the list and this is what catches it reaching for a card that
    says something else; it is how Welly, Peako and Norev were caught being
    reported off this very photograph.
    """

    PHOTO = Path(__file__).resolve().parents[2] / "tests/Fixtures/packaging/premium-two-packs.jpg"

    def test_every_brand_it_finds_is_the_one_on_the_card(self):
        image = cv2.imread(str(self.PHOTO))
        self.assertIsNotNone(image, f"missing fixture {self.PHOTO}")

        detections = recognise(image, index(), layout="packaged", crops=False)["detections"]
        found = [d["read"]["brand"] for d in detections if d["read"]["brand"]]

        self.assertNotEqual([], found, "no brand read at all — the logo reader has stopped working")
        self.assertEqual({"Hot Wheels"}, set(found), "a brand that is not on these cards")


class CatalogGrowsItTest(unittest.TestCase):
    def test_a_brand_in_the_catalog_joins_the_vocabulary(self):
        class Catalog:
            brands = {1: "Hot Wheels", 2: "Matchbox", 3: "Reel Rides"}

        # Not in the seed list, and recognised anyway — which is what makes
        # this grow without the recognizer being edited.
        self.assertIsNone(brands.seed().match("REEL RIDES", PRINTED_BRAND))
        self.assertEqual("Reel Rides", brands.known(Catalog()).match("REEL RIDES", PRINTED_BRAND)[0])

    def test_a_catalog_brand_the_seed_knows_keeps_the_seed_spellings(self):
        class Catalog:
            brands = {1: "Matchbox"}

        # "Lesney" is what a 1960s Matchbox box says.
        self.assertEqual("Matchbox", brands.known(Catalog()).match("LESNEY ENGLAND", PRINTED_BRAND)[0])

    def test_a_garbled_logo_read_survives_the_long_list(self):
        # This is what made the long list look unusable: at 44 brands the
        # photographs produced ten wrong brands where four had produced none.
        # It was not the length, it was short names being found inside noise.
        # With those spelled out, a garbled wordmark still lands on its brand.
        vocabulary = brands.known(index())
        for garbled, brand in (("Hor wres", "Hot Wheels"), ("JTIATLHBOX", "Matchbox"),
                               ("MTATLHBON WWF", "Matchbox")):
            with self.subTest(garbled=garbled):
                self.assertEqual(brand, vocabulary.match(garbled, MIN_SIMILARITY)[0])

        for junk in ("Sane — Waa", "eas See", "JNATO 7 A$", "Antoatns ;", "wield", "Jy ap ase"):
            with self.subTest(junk=junk):
                self.assertIsNone(vocabulary.match(junk, MIN_SIMILARITY))


class EvidenceTest(unittest.TestCase):
    def test_the_evidence_uses_the_vocabulary_it_is_given(self):
        class Catalog:
            brands = {1: "Shadow Model"}

        self.assertEqual("Shadow Model",
                         Evidence.from_text("SHADOW MODEL 1:64", vocabulary=brands.known(Catalog())).brand)


if __name__ == "__main__":
    unittest.main()
