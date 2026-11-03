"""One command for the whole training run, as the `trainer` container runs it:

    python -m train            # = export the encoder if missing, then build

Paths come from the environment (defaults suit the container):
    RECOGNIZER_MODEL_DIR   where the model goes            (/data/model)
    TRAINING_DATA          catalog:export-training-data dir (/data/training-data)
Any extra arguments are passed to train.build (e.g. --precision 0.98).
"""

import os
import sys
from pathlib import Path

from . import build, export_encoder

model_dir = Path(os.environ.get("RECOGNIZER_MODEL_DIR", "/data/model"))
data = os.environ.get("TRAINING_DATA", "/data/training-data")

if not (Path(data) / "manifest.jsonl").is_file():
    raise SystemExit(f"no {data}/manifest.jsonl — run php artisan catalog:export-training-data first (README §5.10)")

if not (model_dir / "encoder.onnx").is_file():
    export_encoder.main(["--out", str(model_dir / "encoder.onnx")])

raise SystemExit(build.main(["--data", data, "--model-dir", str(model_dir), *sys.argv[1:]]))
