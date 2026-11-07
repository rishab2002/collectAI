"""Measure whether a car can be recognised *through its packaging*.

    python -m train.packaged_check --model-dir MODEL_DIR [--photos DIR] [--labels FILE]

The question this answers, and the reason it is a tool rather than a one-off
script: the packaged scan names a car only from the printing on it, and the
obvious thing to try instead is to recognise it by how it looks. That was
tried twice and measured both times (CLAUDE.md, *Recognition → Can a car be
recognised through its packaging?*). Whoever tries a third time should be able
to re-run the measurement in one command against their new model, rather than
re-deriving what the answer was last time.

`DIR` holds the photographs and a `cars.json` saying what is in them — see
`tests/Fixtures/packaging/`, which is the only labelled packaged data there
is. Each car has two boxes: `car`, the car seen through the blister, and
`card`, the whole package cut tight to its edges. Both are queried, because
those were the two hypotheses.

**Retrieval is measured against the gallery, never through the serving
index.** The scanner may only ever *offer* a casting that is live in the
catalog in front of it, so running this through `allowed` would report what
that catalog happens to hold rather than what the model knows. The model is
bound instead to a stand-in index that knows every label in its own gallery.

A car whose casting the gallery has no photo of is an open-set query: the
right answer for it is silence, and a confident answer is counted wrong.
"""

from __future__ import annotations

import argparse
import json
import urllib.parse
from pathlib import Path

import cv2
import numpy as np

from collectai_recognizer.visual import VisualModel


class WholeGallery:
    """Stands in for a CatalogIndex: every label the gallery holds is offerable."""

    def __init__(self, model: VisualModel):
        self.casting_keys = {str(k): i for i, k in enumerate(dict.fromkeys(model.casting_labels.tolist()), start=1)}
        self.variation_keys = {str(k): i for i, k in enumerate(dict.fromkeys(model.variation_labels.tolist()), start=1)}
        self.by_id = {i: k for k, i in self.casting_keys.items()}


def read_truth(photos: Path) -> list[dict]:
    """Every labelled car in `photos/cars.json`, with its photo resolved."""
    data = json.loads((photos / "cars.json").read_text(encoding="utf-8"))
    out = []
    for name, entry in data.items():
        if name.startswith("_"):
            continue
        path = photos / name
        if not path.is_file():
            raise SystemExit(f"{path} is named in cars.json but is not there")
        for car in entry["cars"]:
            out.append({"photo": name, "path": path, **car})
    return out


def cut(bgr: np.ndarray, box: list[float]) -> np.ndarray:
    h, w = bgr.shape[:2]
    x, y, bw, bh = box
    x0, y0 = max(0, int(x * w)), max(0, int(y * h))
    x1, y1 = min(w, int((x + bw) * w)), min(h, int((y + bh) * h))
    return bgr[y0:max(y0 + 1, y1), x0:max(x0 + 1, x1)]


def readable(paths: list[str]) -> dict[str, str]:
    """casting key -> a name, from train.collect label files. Empty without them."""
    out: dict[str, str] = {}
    for p in paths:
        for line in Path(p).read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            row = json.loads(line)
            key = row.get("casting_key")
            if not key or key in out:
                continue
            if row.get("name"):
                out[key] = f"{row.get('brand') or '?'} · {row['name']}"
            elif row.get("page_url"):
                out[key] = urllib.parse.unquote(row["page_url"].rsplit("/", 1)[-1]).replace("_", " ")
    return out


def queries(cars: list[dict], kind: str) -> list[dict]:
    """One query per distinct box, with every casting that box may answer with.

    A car's `car` box is its own, so this is one query per car. Several cars
    share a `card` box — a two-pack is one card with two cars printed on it —
    and that card is answered rightly by either of them, so they become one
    query with two acceptable castings. Scoring a two-pack card against one
    named car would count the right answer wrong half the time.
    """
    out: dict[tuple, dict] = {}
    for car in cars:
        at = (car["photo"], tuple(car[kind]))
        q = out.setdefault(at, {"photo": car["photo"], "path": car["path"], "box": car[kind],
                                "names": [], "castings": []})
        q["names"].append(car["name"])
        if car["casting"]:
            q["castings"].append(car["casting"])
    for q in out.values():
        q["name"] = " + ".join(q["names"])
    return list(out.values())


def measure(model: VisualModel, cars: list[dict], kind: str) -> dict:
    """Query the gallery with each distinct box; `kind` is "car" or "card"."""
    index = WholeGallery(model)
    model.bind(index)
    asked = queries(cars, kind)
    images = []
    for q in asked:
        bgr = cv2.imread(str(q["path"]), cv2.IMREAD_COLOR)
        if bgr is None:
            raise SystemExit(f"cannot read {q['path']}")
        images.append(cut(bgr, q["box"]))
    wanted = [{index.casting_keys[c] for c in q["castings"] if c in index.casting_keys} for q in asked]

    raws, vectors = model.embed_pair(images)
    rows, answerable = [], 0
    top1 = top5 = confident = wrong = 0
    for q, want, raw, vec in zip(asked, wanted, raws, vectors):
        seen = model.match(vec, raw=raw, limit=5)
        got = [(index.by_id[c.casting_id], round(c.score, 3)) for c in seen.candidates]
        hit1 = bool(want and seen.top and seen.top.casting_id in want)
        hit5 = bool(want and any(c.casting_id in want for c in seen.candidates))
        answerable += bool(want)
        top1 += hit1
        top5 += hit5
        if seen.sure:
            confident += 1
            wrong += not hit1
        rows.append({"name": q["name"], "photo": q["photo"], "castings": q["castings"],
                     "familiarity": round(seen.familiarity, 3), "margin": round(seen.margin, 3),
                     "sure": seen.sure, "top1": hit1, "top5": hit5, "candidates": got})
    return {"crop": kind, "queries": len(asked), "answerable": answerable, "top1": top1, "top5": top5,
            "confident": confident, "wrong": wrong, "rows": rows}


def report(result: dict, names: dict[str, str]) -> None:
    def show(key: str) -> str:
        return names.get(key, key)

    print(f"\n{'=' * 92}\nthe {result['crop']} in each photo "
          f"({result['queries']} queries, {result['answerable']} with an answer in the gallery)")
    for row in result["rows"]:
        mark = "HIT1" if row["top1"] else ("hit5" if row["top5"] else ("open" if not row["castings"] else "   -"))
        print(f"{mark} {'SURE' if row['sure'] else '    '} {row['name'][:38]:40s} "
              f"fam {row['familiarity']:.3f} margin {row['margin']:.3f}  "
              + "  ".join(f"{s:.3f} {show(k)[:30]}" for k, s in row["candidates"][:2]))
    print(f"-> top1 {result['top1']}/{result['answerable']}, top5 {result['top5']}/{result['answerable']}, "
          f"confident answers {result['confident']}, of which wrong {result['wrong']}")


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--model-dir", required=True)
    p.add_argument("--photos", default="tests/Fixtures/packaging", help="a folder holding cars.json and the photos")
    p.add_argument("--labels", action="append", default=[],
                   help="a train.collect labels file, to print casting names instead of keys; repeatable")
    p.add_argument("--crop", choices=("car", "card", "both"), default="both")
    p.add_argument("--json", action="store_true", help="print the measurement as JSON instead")
    args = p.parse_args(argv)

    model = VisualModel.load(args.model_dir)
    if model is None:
        raise SystemExit(f"no visual model in {args.model_dir} — unpack one with train.package publish")
    cars = read_truth(Path(args.photos))
    kinds = ("car", "card") if args.crop == "both" else (args.crop,)
    results = [measure(model, cars, kind) for kind in kinds]

    if args.json:
        print(json.dumps({"model": model.summary(), "results": results}, indent=2, default=str))
        return 0
    names = readable(args.labels)
    print(f"model: {model.summary()}")
    for result in results:
        report(result, names)
    print("\nA confident answer for a car the gallery has no photo of is wrong by definition.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
