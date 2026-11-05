"""The packaged-cars mode: a photo of a dozen blister cards and boxes.

Synthetic cards, not real ones — real-photo accuracy is measured on real
photos and written into CLAUDE.md — but enough to pin down what each stage
does, and every one of these caught something while it was being built.
"""

import json
import unittest
from pathlib import Path

import cv2
import numpy as np

from collectai_recognizer import brands, logos, ocr, packaging
from collectai_recognizer.catalog import Matcher
from collectai_recognizer.evidence import Evidence
from collectai_recognizer.pipeline import recognise
from collectai_recognizer.segment import working_copy

from .synthetic import card, card_with_logo, layout
from .test_recognizer import index

NAMES = ["BONE SHAKER", "'93 MAZDA RX-7", "MAZDA RX-3", "FORD MUSTANG",
         "'95 MAZDA RX-7", "BONE SHAKER", "FORD MUSTANG", "MAZDA RX-3",
         "BONE SHAKER", "'93 MAZDA RX-7", "FORD MUSTANG", "MAZDA RX-3"]
ARTS = ("orange", "teal", "purple", "lime")


def a_dozen() -> np.ndarray:
    """Twelve packages in rows, two of them boxes lying on their side."""
    return layout([
        card(name, series="HW EXOTICS", collector=f"{i + 1}/250", art=ARTS[i % 4], landscape=(i % 5 == 4))
        for i, name in enumerate(NAMES)
    ])


class SegmentTest(unittest.TestCase):
    """Finding the packages. No text involved, so no Tesseract needed."""

    def test_every_package_in_a_layout_is_found_exactly_once(self):
        work, _ = working_copy(a_dozen())
        found = packaging.find_packages(work)
        self.assertEqual(12, len(found))

        # One package each: no two boxes may cover the same ground.
        for i, a in enumerate(found):
            for b in found[i + 1:]:
                ax, ay, aw, ah = a.box
                bx, by, bw, bh = b.box
                overlap = max(0, min(ax + aw, bx + bw) - max(ax, bx)) * max(0, min(ay + ah, by + bh) - max(ay, by))
                self.assertLess(overlap / (aw * ah), 0.25, "two detections of one package")

    def test_they_come_back_in_reading_order_with_a_box_on_its_side_in_its_own_row(self):
        work, _ = working_copy(a_dozen())
        boxes = [f.box for f in packaging.find_packages(work)]
        centres = [(y + h // 2, x) for x, y, _, h in boxes]
        rows = [[c for c in centres if abs(c[0] - r) < 60] for r in sorted({c[0] // 100 * 100 for c in centres})]
        self.assertEqual(sorted(centres), centres, "not top to bottom, left to right")
        self.assertTrue(all(len(r) <= 4 for r in rows))

    def test_a_whole_column_welded_into_a_strip_is_not_offered_as_a_package(self):
        # Four packages end to end are a rectangle too, and a very clean one.
        # The shape is the only thing that says it is not one package.
        work, _ = working_copy(a_dozen())
        for f in packaging.find_packages(work):
            _, (w, h), _ = cv2.minAreaRect(f.contour)
            self.assertLessEqual(max(w, h) / min(w, h), packaging.MAX_RATIO)

    def test_a_pale_package_on_a_pale_surface_is_still_found(self):
        # The edge thresholds used to come from how bright the photo was, so a
        # layout on a white sheet put the upper one at 255 and only the
        # boldest artwork was ever outlined.
        img = layout([card(n, art="lime") for n in NAMES[:4]], columns=4, surface=(246, 247, 248))
        work, _ = working_copy(img)
        self.assertEqual(4, len(packaging.find_packages(work)))


class EvidenceTest(unittest.TestCase):
    def test_a_collector_number_does_not_take_a_number_out_of_the_name(self):
        # "3/250" printed under "MAZDA RX-3". Removing the collector number a
        # word at a time removes the 3 from the name as well, and a name whose
        # number was not read is not an answer.
        ev = Evidence.from_text("MAZDA RX-3 HW EXOTICS 3/250")
        self.assertEqual("3/250", ev.collector_number)
        self.assertIn("3", ev.words.split())
        self.assertTrue(Matcher(index()).match(ev, printed=True).confident)


class MatchTest(unittest.TestCase):
    def test_a_card_year_is_judged_against_the_castings_years_not_its_tool_year(self):
        # '93 Mazda RX-7, released 2016-2024. A base carries the year the tool
        # was cut; a card carries the year it was sold in, so judging a 2019
        # card by the base rule marks the right answer down.
        ev = Evidence.from_text("'93 MAZDA RX-7 (C) 2019 MATTEL")
        self.assertEqual(2019, ev.copyright_year)

        printed = Matcher(index()).match(ev, printed=True)
        self.assertTrue(printed.confident)
        self.assertIn("within 2016–2024", " ".join(printed.best.reasons))

        on_a_base = Matcher(index()).match(ev)
        self.assertLess(on_a_base.best.score, printed.best.score)

    def test_a_card_year_outside_the_castings_years_is_not_held_against_it(self):
        # The catalog holds the years that have been imported, not the years a
        # casting sold in, so a card outside them is usually a gap rather than
        # the wrong car. What the card actually says wins: the '95 was read in
        # full, and a '93 whose shorter alias is inside that name — and which
        # the 2019 does fit — is not a rival. Marking the '95 down for its
        # years put the '93 top instead, which is how this was found.
        ev = Evidence.from_text("'95 MAZDA RX-7 (C) 2019 MATTEL")   # 11 sold 1998-2001, 10 2016-2024
        match = Matcher(index()).match(ev, printed=True)
        self.assertTrue(match.confident)
        self.assertEqual(11, match.best.casting_id)
        self.assertNotIn("outside", " ".join(match.best.reasons))

    def test_the_versions_offered_lead_with_the_year_on_the_card(self):
        # Casting 10 has 2016, 2019 and 2022 versions. Nothing in a photo of a
        # sealed card says what colour the car is, so the year is the one
        # thing that orders them.
        ev = Evidence.from_text("'93 MAZDA RX-7 (C) 2019 MATTEL")
        offered = Matcher(index()).match(ev, printed=True).variations
        self.assertEqual(2019, offered[0]["year"])
        self.assertEqual(1.0, offered[0]["year_match"])

    def test_a_loose_car_is_ordered_as_before(self):
        ev = Evidence.from_text("'93 MAZDA RX-7")
        offered = Matcher(index()).match(ev, colour="orange").variations
        self.assertEqual("orange", offered[0]["color_family"], "colour still orders a loose car")
        self.assertEqual(0.0, offered[0]["year_match"])


@unittest.skipIf(ocr.available() is None, "tesseract is not installed")
class LogoTest(unittest.TestCase):
    """A brand logo is type drawn as artwork, and reading it badly is enough:
    the vocabulary is four names, not a catalog."""

    def test_a_wordmark_on_a_field_of_brand_colour_is_read_as_its_brand(self):
        for printed, brand in (("HOT WHEELS", "Hot Wheels"), ("MATCHBOX", "Matchbox")):
            with self.subTest(brand=brand):
                found = logos.read(card_with_logo("FORD MUSTANG", brand=printed), brands.on_artwork(index()))
                self.assertIsNotNone(found, f"{printed} logo not read")
                self.assertEqual(brand, found[0])
                self.assertGreaterEqual(found[1], logos.MIN_SIMILARITY)

    def test_a_patch_of_brand_colour_with_no_wordmark_on_it_names_no_brand(self):
        # A brand is never guessed from colour: the patch has to spell
        # something out, and a car's name is not one of the four names.
        self.assertIsNone(logos.read(card_with_logo("FORD MUSTANG", brand="FORD MUSTANG"), brands.on_artwork(index())))

    def test_the_brand_the_card_spells_out_beats_the_one_its_logo_draws(self):
        # "MATCHBOX" printed as type, a Hot Wheels logo beside it: type wins,
        # because it is read properly and the logo is read badly.
        ev = Evidence.from_words([("MATCHBOX", 0.9)], logo_brand="Hot Wheels")
        self.assertEqual("Matchbox", ev.brand)
        self.assertFalse(ev.brand_from_logo)

    def test_a_brand_read_off_artwork_corroborates_but_never_counts_against(self):
        # Two castings are called Bone Shaker, 13 Hot Wheels and 14 Matchbox.
        # A logo read as Matchbox is weak evidence: it may lift the Matchbox
        # one, and it may not mark the Hot Wheels one down.
        ev = Evidence.from_words([("BONE", 0.9), ("SHAKER", 0.9)], logo_brand="Matchbox")
        self.assertTrue(ev.brand_from_logo)
        reasons = {c.casting_id: " ".join(c.reasons) for c in Matcher(index()).match(ev, printed=True).candidates}
        self.assertIn("brand Matchbox (from the logo)", reasons[14])
        self.assertNotIn("casting is", reasons[13], "a logo marked a casting down")

        # Read as type off the card, the same disagreement does count.
        typed = Evidence.from_words([("BONE", 0.9), ("SHAKER", 0.9), ("MATCHBOX", 0.9)])
        self.assertFalse(typed.brand_from_logo)
        typed_reasons = {c.casting_id: " ".join(c.reasons) for c in Matcher(index()).match(typed, printed=True).candidates}
        self.assertIn("casting is", typed_reasons[13])


@unittest.skipIf(ocr.available() is None, "tesseract is not installed")
class ReadTest(unittest.TestCase):
    def test_a_dozen_packages_are_each_named_by_what_is_printed_on_them(self):
        result = recognise(a_dozen(), index(), layout="packaged", crops=False)
        self.assertEqual(12, result["found"])
        self.assertEqual(12, len(result["detections"]))

        named = {d["n"]: (d["match"] or {}).get("casting_name") for d in result["detections"] if d["confident"]}
        # Every package but the three Bone Shakers, which two castings are
        # called — see below.
        self.assertEqual(9, len(named), named)
        self.assertEqual("'93 Mazda RX-7", named[2])
        self.assertEqual("Mazda RX-3", named[3])
        self.assertEqual("Ford Mustang", named[4])
        self.assertEqual("'95 Mazda RX-7", named[5], "a box on its side is read too")

    def test_two_castings_of_one_name_are_not_an_answer_until_the_brand_says_which(self):
        plain = recognise(layout([card("BONE SHAKER", collector="9/250")], columns=1, margin=200), index(), layout="packaged", crops=False)
        self.assertFalse(plain["detections"][0]["confident"], "a tie is not an answer")

        both = layout([card("HOT WHEELS", series="BONE SHAKER"), card("MATCHBOX", series="BONE SHAKER", art="teal")], columns=2)
        detections = recognise(both, index(), layout="packaged", crops=False)["detections"]
        self.assertEqual(["Hot Wheels", "Matchbox"], [d["read"]["brand"] for d in detections])
        self.assertTrue(all(d["confident"] for d in detections))
        # Casting 13 is the Hot Wheels Bone Shaker, 14 the Matchbox one.
        self.assertEqual([13, 14], [d["match"]["casting_id"] for d in detections])

    def test_a_package_is_never_named_by_its_colour_or_by_how_it_looks(self):
        result = recognise(a_dozen(), index(), layout="packaged", crops=False)
        self.assertTrue(all(d["colour"]["family"] is None for d in result["detections"]),
                        "the colour of a package is the colour of its artwork")
        self.assertTrue(all(d["looks"] is None for d in result["detections"]))
        self.assertIsNone(result["model"])

    def test_the_reading_is_the_ocr_agents_output_shape(self):
        detection = recognise(layout([card("FORD MUSTANG", collector="4/250", toy="DTX12")], columns=1, margin=200),
                              index(), layout="packaged", crops=False)["detections"][0]
        schema = json.loads((Path(__file__).resolve().parents[2] / "app/AI/schemas/ocr_output.json").read_text())
        self.assertEqual(sorted(schema["required"]), sorted(detection["card"]))
        self.assertEqual("Ford Mustang", detection["card"]["casting_name"])
        self.assertEqual("4/250", detection["card"]["collector_number"])

    def test_a_box_on_its_side_is_stored_the_way_up_it_reads(self):
        import base64

        sideways = layout([card("FORD MUSTANG", collector="4/250", landscape=True)], columns=1, margin=200)
        detection = recognise(sideways, index(), layout="packaged")["detections"][0]
        self.assertIn(detection["text"]["rotation"], (90, 270))
        crop = cv2.imdecode(np.frombuffer(base64.b64decode(detection["crop_jpeg"]), np.uint8), cv2.IMREAD_COLOR)
        self.assertGreater(crop.shape[0], crop.shape[1], "stored sideways, as the camera saw it")


if __name__ == "__main__":
    unittest.main()
