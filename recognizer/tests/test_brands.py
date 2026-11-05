"""The brand vocabulary: a seed list that the catalog grows."""

import unittest

from collectai_recognizer import brands
from collectai_recognizer.evidence import PRINTED_BRAND, Evidence
from collectai_recognizer.logos import MIN_SIMILARITY

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
