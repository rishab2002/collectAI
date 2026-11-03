import gzip
import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from train import package


class PackageTest(unittest.TestCase):
    def test_save_splits_and_gzips_and_restore_puts_it_back_byte_for_byte(self):
        with tempfile.TemporaryDirectory() as tmp:
            work, training, back = Path(tmp, "work"), Path(tmp, "training"), Path(tmp, "back")
            (work / "model").mkdir(parents=True)
            (work / "wiki-training").mkdir()
            big = bytes(range(256)) * 5000                       # 1.28 MB
            (work / "model" / "model.npz").write_bytes(big)
            (work / "model" / "manifest.json").write_text('{"x": 1}')
            (work / "wiki-training" / "manifest.jsonl").write_text('{"a": 1}\n' * 1000)

            with mock.patch.object(package, "PART", 500_000):
                package.save(work, training)
            index = json.loads((training / "PACKED.json").read_text())
            self.assertEqual(["model.npz.part-00", "model.npz.part-01", "model.npz.part-02"], index["model/model.npz"]["stored"])
            gz = training / "data" / "wiki-training" / "manifest.jsonl.gz"
            self.assertLess(gz.stat().st_size, 9000 // 5, "text is compressed")
            self.assertEqual('{"a": 1}\n' * 1000, gzip.open(gz, "rt").read())

            package.restore(training, back)
            self.assertEqual(big, (back / "model" / "model.npz").read_bytes())
            self.assertEqual(0, package.restore(training, back), "nothing to do the second time")

    def test_publish_copies_only_the_model(self):
        with tempfile.TemporaryDirectory() as tmp:
            work, training, app = Path(tmp, "w"), Path(tmp, "t"), Path(tmp, "app")
            (work / "model").mkdir(parents=True)
            (work / "model" / "model.npz").write_bytes(b"model")
            (work / "model" / "embeddings-cache.npz").write_bytes(b"cache")
            package.save(work, training)
            package.main(["publish", str(training), str(app)])
            self.assertEqual(b"model", (app / "model" / "model.npz").read_bytes())
            self.assertFalse((app / "model" / "embeddings-cache.npz").exists(), "training data stays out of the app")

    def test_a_damaged_copy_is_refused_and_replaces_nothing(self):
        with tempfile.TemporaryDirectory() as tmp:
            work, training, app = Path(tmp, "w"), Path(tmp, "t"), Path(tmp, "app")
            (work / "model").mkdir(parents=True)
            (work / "model" / "model.npz").write_bytes(b"good model")
            package.save(work, training)
            (app / "model").mkdir(parents=True)
            (app / "model" / "model.npz").write_bytes(b"old model")
            (training / "model" / "model.npz.part-00").write_bytes(b"truncated")
            with self.assertRaises(SystemExit):
                package.restore(training, app)
            self.assertEqual(b"old model", (app / "model" / "model.npz").read_bytes())


if __name__ == "__main__":
    unittest.main()
