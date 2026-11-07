import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

import cv2
import numpy as np

from collectai_recognizer.catalog import Matcher
from collectai_recognizer.evidence import Evidence
from collectai_recognizer.pipeline import recognise
from collectai_recognizer.visual import Head, VisualModel, normalise, preprocess
from train import build, packaged_check

from .synthetic import pile
from .test_recognizer import index


class ColourEncoder:
    """Stand-in for the ONNX model: a crop's mean colour, so red looks like red."""

    def embed(self, images):
        out = []
        for im in images:
            b, g, r = im.reshape(-1, 3).mean(axis=0) / 255.0
            out.append([r, g, b, 0.05])
        return np.array(out, dtype=np.float32)


def model(visual_only: bool, min_similarity=0.9, min_margin=0.02) -> VisualModel:
    # Gallery: casting 13 (Bone Shaker) is red, variation 131; casting 10 is blue.
    vectors = normalise(np.array([[0.78, 0.12, 0.16, 0.05], [0.12, 0.35, 0.75, 0.05]], dtype=np.float32))
    manifest = {"thresholds": {"visual_only": visual_only, "min_similarity": min_similarity, "min_margin": min_margin}}
    return VisualModel(ColourEncoder(), Head(), vectors, np.array([13, 10]), np.array([131, 0]), manifest)


class VisualMatchTest(unittest.TestCase):
    def test_preprocess_letterboxes_without_cropping(self):
        x = preprocess(np.zeros((100, 300, 3), np.uint8))
        self.assertEqual((3, 224, 224), x.shape)

    def test_nearest_casting_and_version(self):
        m = model(visual_only=True)
        red = np.full((50, 120, 3), (40, 30, 200), np.uint8)
        result = m.match(m.embed([red])[0])
        self.assertEqual(13, result.top.casting_id)
        self.assertIn(131, result.top.variations)
        self.assertTrue(result.sure)

    def test_looks_alone_never_name_a_car_unless_training_measured_it(self):
        red = np.full((50, 120, 3), (40, 30, 200), np.uint8)
        for visual_only, expected in ((False, False), (True, True)):
            m = model(visual_only=visual_only)
            seen = m.match(m.embed([red])[0])
            match = Matcher(index()).match(Evidence.from_text(""), colour="red", visual=seen)
            self.assertEqual(expected, match.confident)
            if expected:
                self.assertEqual(13, match.best.casting_id)
                self.assertEqual(131, match.variations[0]["variation_id"], "the version it looks like comes first")

    def test_looks_break_a_tie_between_same_named_castings(self):
        m = model(visual_only=False)
        seen = m.match(m.embed([np.full((50, 120, 3), (40, 30, 200), np.uint8)])[0])
        without = Matcher(index()).match(Evidence.from_text("BONE SHAKER"))
        with_looks = Matcher(index()).match(Evidence.from_text("BONE SHAKER"), visual=seen)
        self.assertFalse(without.confident)
        self.assertEqual(13, with_looks.best.casting_id)
        self.assertTrue(any("looks like it" in r for r in with_looks.best.reasons))
        # +0.03 is a nudge, not a verdict: still short of the margin.
        self.assertFalse(with_looks.confident)

    def test_the_pipeline_uses_the_model_on_every_crop(self):
        img = pile([("red", (300, 250), 10), ("blue", (900, 260), -20)])
        result = recognise(img, index(), read_text=False, crops=False, visual=model(visual_only=True, min_similarity=0.5))
        self.assertEqual([13, 10], [d["looks"]["candidates"][0]["casting_id"] for d in result["detections"]])
        self.assertTrue(result["detections"][0]["confident"])

    def test_unfamiliar_photos_never_get_a_looks_only_answer(self):
        vectors = normalise(np.array([[0.78, 0.12, 0.16, 0.05], [0.12, 0.35, 0.75, 0.05]], dtype=np.float32))
        manifest = {"thresholds": {"visual_only": True, "min_similarity": 0.5, "min_margin": 0.02, "min_familiarity": 0.95}}
        m = VisualModel(ColourEncoder(), Head(), vectors, np.array([13, 10]), np.array([131, 0]), manifest, raw_vectors=vectors)
        red, grey = np.full((50, 120, 3), (40, 30, 200), np.uint8), np.full((50, 120, 3), (128, 128, 128), np.uint8)
        (raw_red, raw_grey), (v_red, v_grey) = m.embed_pair([red, grey])
        self.assertTrue(m.match(v_red, raw=raw_red).sure)
        unfamiliar = m.match(v_grey, raw=raw_grey)
        self.assertLess(unfamiliar.familiarity, 0.95)
        self.assertFalse(unfamiliar.sure, "nothing like the training photos: hint at most")
        self.assertFalse(m.match(v_red).sure, "a gated model without the base vector never trusts looks")

    def test_familiarity_gate_is_what_real_photos_reach(self):
        self.assertAlmostEqual(0.7, build.familiarity_gate(np.linspace(0.7, 0.95, 1000), 0.0), places=3)

    def test_looks_only_answers_can_be_switched_off(self):
        with mock.patch.dict("os.environ", {"RECOGNIZER_TRUST_LOOKS": "never"}):
            self.assertFalse(model(visual_only=True).visual_only)
        self.assertTrue(model(visual_only=True).visual_only)

    def test_stable_labels_are_resolved_through_the_serving_index(self):
        vectors = normalise(np.array([[0.78, 0.12, 0.16, 0.05], [0.12, 0.35, 0.75, 0.05]], dtype=np.float32))
        m = VisualModel(ColourEncoder(), Head(), vectors,
                        np.array(["hotwheels_fandom|page:500", "hotwheels_fandom|page:999"]),
                        np.array(["hotwheels_fandom|page:500:row:3", ""]),
                        {"thresholds": {"visual_only": True, "min_similarity": 0.5, "min_margin": 0.0}})
        ix = index()
        ix.casting_keys = {"hotwheels_fandom|page:500": 13}           # page:999 isn't in this database
        ix.variation_keys = {"hotwheels_fandom|page:500:row:3": 131}
        m.bind(ix)
        blue = m.match(m.embed([np.full((50, 120, 3), (190, 90, 30), np.uint8)])[0])
        self.assertEqual([13], [c.casting_id for c in blue.candidates], "an unknown key is never offered")
        self.assertEqual({131: blue.top.variations[131]}, blue.top.variations)

    def test_load_returns_none_without_a_model(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertIsNone(VisualModel.load(tmp))


class TrainingTest(unittest.TestCase):
    def clusters(self, classes=40, per=5, noise=0.15, seed=0):
        rng = np.random.default_rng(seed)
        centres = rng.normal(size=(classes, 32))
        x = np.concatenate([c + noise * rng.normal(size=(per, 32)) for c in centres]).astype(np.float32)
        return x, np.repeat(np.arange(classes), per)

    def test_split_holds_out_whole_versions(self):
        labels = np.array([1, 1, 1, 1, 2, 2])
        groups = np.array(["a", "a", "b", "b", "c", "c"])
        train, test = build.split(labels, 0.5, groups=groups)
        self.assertEqual(2, len(test))
        self.assertEqual(1, len(set(groups[test])), "a held-out version is held out entirely")
        self.assertNotIn(2, labels[test], "a casting with one version is never held out")

    def test_dedupe_keeps_one_row_per_photo_and_casting(self):
        rows = [{"casting_id": 1}, {"casting_id": 1}, {"casting_id": 2}]
        kept, keys = build.dedupe(rows, ["x", "x", "x"])
        self.assertEqual([1, 2], [r["casting_id"] for r in kept])

    def test_rows_labelled_only_by_key_are_kept(self):
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, "manifest.jsonl").write_text(
                json.dumps({"key": "url:abc", "source": "ref_inno64", "casting_key": "inno64|item:IN64-X"}) + "\n"
                + json.dumps({"key": "url:def", "source": "x"}) + "\n")
            rows = build.read_manifests([tmp])
        self.assertEqual(["inno64|item:IN64-X"], [r["casting_key"] for r in rows])
        self.assertIsNone(rows[0]["path"])
        kept, _ = build.dedupe(rows + rows, ["url:abc", "url:abc"])
        self.assertEqual(1, len(kept))

    def test_split_keeps_every_class_in_training_and_singletons_out_of_test(self):
        labels = np.array([1, 1, 1, 1, 1, 2, 3, 3])
        train, test = build.split(labels, 0.2)
        self.assertEqual(set(labels), set(labels[train]))
        self.assertNotIn(2, labels[test])
        self.assertEqual(set(), set(train) & set(test))

    def test_evaluate_and_calibrate_on_separable_data(self):
        x, y = self.clusters()
        x = normalise(x)
        train, test = build.split(y, 0.2)
        r = build.evaluate(x[train], y[train], x[test], y[test])
        self.assertGreater(r["top1"], 0.95)
        t = build.calibrate(r["_best"], r["_margin"], r["_correct"], precision=0.95, min_support=20)
        self.assertTrue(t["visual_only"])
        self.assertGreaterEqual(t["precision"], 0.95)

    def test_unknown_cars_raise_the_bar(self):
        # Known cars: right, with margins spread 0.05-0.3. Unknown cars: always
        # wrong, margins up to 0.1. Calibrating with them must demand a margin
        # above where the unknowns sit.
        rng = np.random.default_rng(2)
        best = np.concatenate([rng.uniform(0.5, 0.9, 300), rng.uniform(0.5, 0.9, 300)])
        margin = np.concatenate([rng.uniform(0.05, 0.3, 300), rng.uniform(0.0, 0.1, 300)])
        correct = np.concatenate([np.ones(300, bool), np.zeros(300, bool)])
        t = build.calibrate(best, margin, correct, 0.95, 30)
        self.assertTrue(t["visual_only"])
        self.assertGreaterEqual(t["min_margin"], 0.08)

    def test_no_trust_without_enough_held_out_answers_or_precision(self):
        t = build.calibrate(np.array([0.9] * 5), np.array([0.2] * 5), np.array([True] * 5), 0.95, 30)
        self.assertFalse(t["visual_only"])
        self.assertIn("only 5", t["reason"])
        rng = np.random.default_rng(1)
        coin = rng.random(200) > 0.5
        t = build.calibrate(rng.random(200), rng.random(200) * 0.1, coin, 0.95, 30)
        self.assertFalse(t["visual_only"])

    def test_wccn_needs_enough_repeated_classes(self):
        x, y = self.clusters(classes=10)
        self.assertIsNone(build.fit_wccn(x, y))
        x, y = self.clusters(classes=40)
        head = build.fit_wccn(x, y)
        self.assertEqual((32, 32), head.matrix.shape)

    def test_build_end_to_end_writes_a_model_the_recognizer_loads(self):
        with tempfile.TemporaryDirectory() as tmp:
            data, out = Path(tmp) / "data", Path(tmp) / "model"
            (data / "images").mkdir(parents=True)
            out.mkdir()
            rows = []
            for i, (cid, bgr) in enumerate([(13, (40, 30, 200))] * 3 + [(10, (190, 90, 30))] * 3):
                f = f"images/{i}.png"
                img = np.full((40, 90, 3), bgr, np.uint8)
                img[0, i] = 255          # distinct files: identical ones are de-duplicated
                cv2.imwrite(str(data / f), img)
                # Half the rows carry only a stable key, as reference photos do.
                rows.append({"image": f, "casting_key": f"ref|item:{cid}", "source": "own"} if i % 2
                            else {"image": f, "casting_id": cid, "variation_id": 100 + i, "source": "own"})
            (data / "manifest.jsonl").write_text("\n".join(json.dumps(r) for r in rows))

            with mock.patch.object(build, "Encoder", lambda path: ColourEncoder()):
                self.assertEqual(0, build.main(["--data", str(data), "--model-dir", str(out), "--min-support", "50"]))

            manifest = json.loads((out / "manifest.json").read_text())
            self.assertEqual({"own": 6}, manifest["sources"])
            self.assertFalse(manifest["thresholds"]["visual_only"], "2 held-out photos can't establish precision")
            loaded = VisualModel.load(out, encoder=ColourEncoder())
            self.assertEqual(6, loaded.summary()["images"])
            self.assertTrue((out / "embeddings-cache.npz").is_file())


if __name__ == "__main__":
    unittest.main()


class PackagedCheckTest(unittest.TestCase):
    """The measurement tool behind CLAUDE.md's packaging numbers."""

    def photos(self, tmp: str) -> Path:
        photos = Path(tmp)
        img = np.zeros((100, 200, 3), np.uint8)
        img[:, :100] = (40, 30, 200)                     # left half red
        img[:, 100:] = (190, 90, 30)                     # right half blue
        cv2.imwrite(str(photos / "layout.jpg"), img)
        (photos / "cars.json").write_text(json.dumps({
            "_": ["a note, skipped"],
            "layout.jpg": {"cars": [
                {"name": "red one", "car": [0.0, 0.0, 0.5, 1.0], "card": [0.0, 0.0, 1.0, 1.0],
                 "casting": "key|red"},
                {"name": "one nobody has photographed", "car": [0.5, 0.0, 0.5, 1.0],
                 "card": [0.0, 0.0, 1.0, 1.0], "casting": None},
            ]},
        }))
        return photos

    def model(self) -> VisualModel:
        vectors = normalise(np.array([[0.78, 0.12, 0.16, 0.05], [0.12, 0.35, 0.75, 0.05]], dtype=np.float32))
        manifest = {"thresholds": {"visual_only": True, "min_similarity": 0.9, "min_margin": 0.02}}
        return VisualModel(ColourEncoder(), Head(), vectors,
                           np.array(["key|red", "key|blue"]), np.array(["key|red:v", "key|blue:v"]), manifest)

    def test_it_measures_against_the_gallery_not_through_a_serving_index(self):
        with tempfile.TemporaryDirectory() as tmp:
            cars = packaged_check.read_truth(self.photos(tmp))
            self.assertEqual(2, len(cars))
            result = packaged_check.measure(self.model(), cars, "car")

        self.assertEqual(1, result["answerable"], "the second car is not in the gallery")
        self.assertEqual(1, result["top1"], "stable keys resolve without a catalog index")
        self.assertEqual("key|red", result["rows"][0]["candidates"][0][0])

    def test_a_confident_answer_for_a_car_the_gallery_lacks_counts_as_wrong(self):
        with tempfile.TemporaryDirectory() as tmp:
            cars = packaged_check.read_truth(self.photos(tmp))
            result = packaged_check.measure(self.model(), cars, "car")

        self.assertTrue(result["rows"][1]["sure"], "blue does look like the blue one in the gallery")
        self.assertEqual(2, result["confident"])
        self.assertEqual(1, result["wrong"], "an open-set car answered confidently is a wrong answer")
