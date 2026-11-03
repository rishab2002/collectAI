"""Download the base image model once and save it as ONNX for the recognizer.

    python -m train.export_encoder --out ../storage/app/recognition/model/encoder.onnx

DINOv2-small (facebook/dinov2-small, Meta AI), licensed Apache 2.0 — free to
use commercially, with the licence and notice kept. The revision is pinned so a
re-export gives the same model. The output is the image's [CLS] embedding after
the final layer norm (384 numbers).
"""

from __future__ import annotations

import argparse
from pathlib import Path

MODEL = "facebook/dinov2-small"
REVISION = "ed25f3a31f01632728cabb09d1542f84ab7b0056"
# What the vectors depend on. Two exports of the same pinned weights give the
# same numbers (to ~1e-5) even if the .onnx bytes differ, so the embedding
# cache is keyed by this, not by the file's hash — a cache built on one
# machine stays valid on another.
IDENTITY = f"{MODEL}@{REVISION}/pooler/opset17/letterbox224-imagenet"

NOTICE = (
    "encoder.onnx is DINOv2-small by Meta AI (https://huggingface.co/facebook/dinov2-small, "
    f"revision {REVISION}), licensed under the Apache License 2.0 "
    "(https://www.apache.org/licenses/LICENSE-2.0). Converted to ONNX by collectAI; weights unchanged."
)


def main(argv: list[str] | None = None) -> int:
    import torch
    from transformers import Dinov2Model

    p = argparse.ArgumentParser()
    p.add_argument("--out", required=True)
    args = p.parse_args(argv)

    model = Dinov2Model.from_pretrained(MODEL, revision=REVISION).eval()

    class Wrapper(torch.nn.Module):
        def __init__(self, inner):
            super().__init__()
            self.inner = inner

        def forward(self, pixel_values):
            return self.inner(pixel_values=pixel_values).pooler_output

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    torch.onnx.export(
        Wrapper(model), torch.zeros(1, 3, 224, 224), str(out),
        input_names=["pixel_values"], output_names=["embedding"],
        dynamic_axes={"pixel_values": {0: "batch"}, "embedding": {0: "batch"}},
        opset_version=17,
    )
    (out.parent / "NOTICE-encoder.txt").write_text(NOTICE + "\n", encoding="utf-8")
    (out.parent / "encoder-id.txt").write_text(IDENTITY + "\n", encoding="utf-8")
    print(f"wrote {out} ({out.stat().st_size // 1_000_000} MB)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
