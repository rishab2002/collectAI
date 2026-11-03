import json
import tempfile
import unittest
from pathlib import Path

import cv2

from collectai_recognizer import ocr
from collectai_recognizer.catalog import CatalogIndex, Matcher
from collectai_recognizer.colours import classify_pixels, dominant, palette
from collectai_recognizer.evidence import Evidence
from collectai_recognizer.pipeline import read_card, recognise
from collectai_recognizer.server import IndexCache

from .synthetic import base, pile


def index(**extra) -> CatalogIndex:
    data = {
        "format": "collectai-recognition-index",
        "version": 1,
        "generated_at": "2026-11-03T00:00:00Z",
        "brands": [{"id": 1, "name": "Hot Wheels"}, {"id": 2, "name": "Matchbox"}],
        "castings": [
            {"id": 10, "name": "'93 Mazda RX-7", "brand_ids": [1], "first_year": 2016, "last_year": 2024},
            {"id": 11, "name": "'95 Mazda RX-7", "brand_ids": [1], "first_year": 1998, "last_year": 2001},
            {"id": 12, "name": "Mazda RX-3", "brand_ids": [1], "first_year": 2013, "last_year": 2023},
            {"id": 13, "name": "Bone Shaker", "brand_ids": [1], "first_year": 2006, "last_year": 2026},
            {"id": 14, "name": "Bone Shaker", "brand_ids": [2], "first_year": 2020, "last_year": 2020},
            {"id": 15, "name": "Ford Mustang", "brand_ids": [2], "first_year": 2005, "last_year": 2012},
            {"id": 16, "name": "Ford Mustang Mach 1", "brand_ids": [2], "first_year": 2021, "last_year": 2023},
        ],
        "variations": [
            {"id": 100, "casting_id": 10, "year": 2016, "color": "Red", "color_family": "red", "wheel_type": "PR5", "toy_number": "J3240"},
            {"id": 101, "casting_id": 10, "year": 2019, "color": "Blue", "color_family": "blue", "wheel_type": "10SP", "toy_number": "FYC14"},
            {"id": 102, "casting_id": 10, "year": 2022, "color": "Metalflake Orange", "color_family": "orange", "wheel_type": "MC5", "toy_number": "HCV40"},
            {"id": 110, "casting_id": 11, "year": 1998, "color": "Red", "color_family": "red", "wheel_type": "5SP", "toy_number": None},
            {"id": 130, "casting_id": 13, "year": 2006, "color": "Black", "color_family": "black", "wheel_type": "5SP", "toy_number": "DTX12"},
            {"id": 131, "casting_id": 13, "year": 2019, "color": "Red", "color_family": "red", "base_color": "Chrome", "base_color_family": "grey", "wheel_type": "5SP", "toy_number": None},
        ],
        **extra,
    }
    return CatalogIndex(data)


class ColourTest(unittest.TestCase):
    def test_every_family_in_the_rules_is_a_declared_family(self):
        p = palette()
        self.assertTrue({r["family"] for r in p.rules} <= set(p.names))
        for a, b in p.neighbours:
            self.assertIn(a, p.names)
            self.assertIn(b, p.names)

    def test_a_coloured_body_beats_its_dark_windows_and_tyres(self):
        family, _ = dominant({"black": 0.45, "red": 0.4, "grey": 0.15})
        self.assertEqual("red", family)
        family, _ = dominant({"black": 0.8, "red": 0.1})
        self.assertEqual("black", family)

    def test_pixel_rules(self):
        import numpy as np
        for bgr, expected in [((40, 30, 200), "red"), ((190, 90, 30), "blue"), ((240, 240, 240), "white"),
                              ((20, 20, 20), "black"), ((150, 150, 155), "grey"), ((40, 210, 235), "yellow")]:
            img = np.full((10, 10, 3), bgr, dtype=np.uint8)
            self.assertEqual(expected, next(iter(classify_pixels(img))), bgr)


class SegmentationTest(unittest.TestCase):
    def test_a_spread_out_pile_is_found_car_by_car_with_its_colour(self):
        img = pile([("red", (300, 250), 10), ("blue", (900, 260), -20), ("yellow", (450, 650), 75), ("black", (1100, 680), 0)])
        result = recognise(img, None, read_text=False, crops=True)
        self.assertEqual(4, result["found"])
        self.assertEqual(["red", "blue", "yellow", "black"], [d["colour"]["family"] for d in result["detections"]])
        self.assertTrue(all(d["crop_jpeg"] for d in result["detections"]))
        self.assertFalse(any(d["confident"] for d in result["detections"]), "colour alone never identifies a car")

    def test_a_close_up_is_one_object(self):
        # Said to be one: the whole frame, words and all.
        result = recognise(base(["BONE SHAKER"]), None, read_text=False, crops=False, layout="single")
        self.assertEqual(1, result["found"])
        self.assertTrue(result["detections"][0]["whole_frame"])

        # Left to guess, the printed words stand out from the metal like cars
        # from a table — which is why the layout can be stated.
        self.assertGreater(recognise(base(["BONE SHAKER"]), None, read_text=False, crops=False)["found"], 1)

    def test_one_car_filling_the_frame_is_one_object_without_being_told(self):
        img = pile([("red", (700, 450), 0)], size=(900, 1400))
        import cv2
        img = cv2.resize(img[300:600, 500:900], (1400, 900))
        result = recognise(img, None, read_text=False, crops=False)
        self.assertEqual(1, result["found"])


class EvidenceTest(unittest.TestCase):
    def test_reads_brand_copyright_year_and_codes(self):
        ev = Evidence.from_text("HOT WHEELS\n'93 MAZDA RX-7\n© 2016 MATTEL\nMADE IN MALAYSIA DTX12 N32")
        self.assertEqual("Hot Wheels", ev.brand)
        self.assertEqual(2016, ev.copyright_year)
        self.assertEqual(["DTX12"], ev.codes)  # N32 is a date code, not a toy number
        self.assertTrue(ev.text.startswith("93mazdarx7"), ev.text)
        self.assertNotIn("2016", ev.text)

    def test_a_bare_year_is_not_a_copyright_year(self):
        self.assertIsNone(Evidence.from_text("1969 CAMARO").copyright_year)
        self.assertEqual(1998, Evidence.from_text("(C) 1998 MATTEL INC").copyright_year)
        self.assertEqual(2015, Evidence.from_text("MATTEL INC. 2015").copyright_year)

    def test_low_confidence_words_are_dropped(self):
        ev = Evidence.from_words([("BONE", 0.9), ("SHAKER", 0.85), ("GTO", 0.2)])
        self.assertEqual("boneshaker", ev.text)


class MatcherTest(unittest.TestCase):
    def setUp(self):
        self.matcher = Matcher(index())

    def test_a_full_name_on_the_base_is_a_confident_match(self):
        m = self.matcher.match(Evidence.from_text("'93 MAZDA RX-7 © 2016 MATTEL"), colour="blue")
        self.assertTrue(m.confident)
        self.assertEqual(10, m.best.casting_id)
        # Colour orders the variations; it doesn't pick the casting.
        self.assertEqual(101, m.variations[0]["variation_id"])
        self.assertEqual(1.0, m.variations[0]["colour_match"])

    def test_an_upside_down_car_can_match_on_its_base_colour(self):
        m = self.matcher.match(Evidence.from_text("HOT WHEELS BONE SHAKER (C) 2005 MATTEL"), colour="grey")
        self.assertEqual(131, m.variations[0]["variation_id"])
        self.assertEqual("base", m.variations[0]["colour_matched_on"])

    def test_two_castings_that_read_the_same_are_not_an_answer(self):
        m = self.matcher.match(Evidence.from_text("MAZDA RX-7"))
        self.assertFalse(m.confident)
        self.assertEqual([], m.as_dict()["variations"])

    def test_the_copyright_year_can_settle_it(self):
        m = self.matcher.match(Evidence.from_text("MAZDA RX-7 (C) 1997 MATTEL"))
        self.assertTrue(m.confident, [(c.name, c.score) for c in m.candidates])
        self.assertEqual(11, m.best.casting_id)

    def test_the_brand_on_the_base_separates_same_named_castings(self):
        self.assertFalse(self.matcher.match(Evidence.from_text("BONE SHAKER")).confident)
        m = self.matcher.match(Evidence.from_text("MATCHBOX BONE SHAKER (C) 2019 MATTEL"))
        self.assertTrue(m.confident)
        self.assertEqual(14, m.best.casting_id)

    def test_a_toy_number_in_the_index_identifies_on_its_own(self):
        m = self.matcher.match(Evidence.from_text("DTX12"))
        self.assertTrue(m.confident)
        self.assertEqual(13, m.best.casting_id)
        self.assertTrue(m.variations[0]["code_match"])

    def test_a_longer_name_fully_read_is_not_rivalled_by_its_prefix(self):
        m = self.matcher.match(Evidence.from_text("FORD MUSTANG MACH 1"))
        self.assertTrue(m.confident)
        self.assertEqual(16, m.best.casting_id)

    def test_a_misread_letter_still_matches_but_junk_does_not(self):
        self.assertTrue(self.matcher.match(Evidence.from_text("BONE SHAKFR HOT WHEELS")).confident)
        self.assertEqual([], self.matcher.match(Evidence.from_text("XQZ ### 7")).candidates)

    def test_the_bar_is_configurable(self):
        strict = Matcher(index(), min_confidence=0.999)
        self.assertFalse(strict.match(Evidence.from_text("BONE SHAKFR HOT WHEELS")).confident)


@unittest.skipIf(ocr.available() is None, "tesseract is not installed")
class EndToEndTest(unittest.TestCase):
    def test_an_upside_down_base_is_read_and_identified(self):
        # Two castings are called Bone Shaker; the copyright year is what
        # says this is the 2006 Hot Wheels tool and not the 2020 Matchbox one.
        img = base(["BONE SHAKER", "(C) 2005 MATTEL", "MADE IN MALAYSIA"], upside_down=True)
        result = recognise(img, index(), layout="single")
        det = result["detections"][0]
        self.assertTrue(det["confident"], det["match"])
        self.assertEqual(13, det["match"]["casting_id"])
        self.assertEqual(180, det["text"]["rotation"])
        self.assertEqual(2005, det["read"]["copyright_year"])

    def test_a_word_tesseract_is_unsure_of_is_not_evidence(self):
        # Rendered in this font, the hyphen in RX-7 comes back as a dash
        # Tesseract scores ~0.35, and "'93" as "'95". Neither is trusted, so
        # the answer is "not sure" — not a guess at which RX-7.
        img = base(["'93 MAZDA RX-7", "MADE IN MALAYSIA"])
        det = recognise(img, index(), layout="single")["detections"][0]
        self.assertFalse(det["confident"])

    def test_a_card_is_read_into_the_ocr_schema(self):
        img = base(["HOT WHEELS", "BONE SHAKER  77/250", "DTX12"])
        out = read_card(img, index())
        self.assertEqual(1, out["schema_version"])
        self.assertEqual("Hot Wheels", out["brand"])
        self.assertEqual("Bone Shaker", out["casting_name"])
        self.assertEqual("77/250", out["collector_number"])
        self.assertEqual("DTX12", out["toy_number"])
        self.assertIsNone(out["year"])
        self.assertEqual(set(out) - {"schema_version", "confidence"}, set(out["confidence"]))
        schema = json.loads((Path(__file__).resolve().parents[2] / "app/AI/schemas/ocr_output.json").read_text())
        self.assertEqual(sorted(schema["required"]), sorted(out))


class ServerTest(unittest.TestCase):
    def test_the_index_is_reloaded_when_it_changes_and_a_bad_file_keeps_the_old_one(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "catalog-index.json"
            cache = IndexCache(str(path))
            self.assertIsNone(cache.get())
            self.assertIn("catalog:export-recognition-index", cache.error)

            path.write_text(json.dumps({"format": "collectai-recognition-index", "castings": [{"id": 1, "name": "Twin Mill"}], "variations": []}))
            self.assertEqual(1, cache.get().summary()["castings"])

            import os
            path.write_text("{ half written")
            os.utime(path, (1, 1))
            self.assertEqual(1, cache.get().summary()["castings"])
            self.assertIn("could not read", cache.error)


if __name__ == "__main__":
    unittest.main()
