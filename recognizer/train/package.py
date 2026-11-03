"""Move the model and training data between the app's working area and the
repo's `training/` folder.

    python -m train.package save     WORK TRAINING   # work area -> training/ (to commit)
    python -m train.package restore  TRAINING WORK   # training/ -> work area (to keep training)
    python -m train.package publish  TRAINING WORK   # only the model -> the app (what deploy runs)

WORK is the recognizer's data folder (storage/app/recognition, or the
`recognition` volume in production: the recognizer reads WORK/model and reloads
it by itself). TRAINING is the repo's training/ folder, which the app never
reads.

In training/, text is gzipped and anything over 45 MB is split into parts —
GitHub rejects files over 100 MB and warns above 50 MB — and PACKED.json records
every file's SHA-256, so a restore is checked byte for byte. No photo is ever
saved here: collectors' scan pictures stay in the app (the privacy policy says
they are never shared outside collectAI); only vectors, labels and the model.

Standard library only, so it runs in any Python 3 — no PyTorch needed.
"""

from __future__ import annotations

import gzip
import hashlib
import json
import shutil
import sys
from pathlib import Path

PART = 45 * 1024 * 1024

GROUPS = {
    # What the app runs: copied into WORK/model by `publish`.
    "model": ["model/manifest.json", "model/model.npz", "model/encoder.onnx",
              "model/encoder-id.txt", "model/NOTICE-encoder.txt"],
    # What training needs to carry on without downloading anything again.
    "data": ["model/embeddings-cache.npz",
             "wiki-training/manifest.jsonl", "wiki-training/missing-wiki-files.txt",
             "reference-training/manifest.jsonl", "reference-training/missing-wiki-files.txt",
             "wiki-labels-hot-wheels.jsonl", "wiki-labels-matchbox.jsonl", "reference-labels.jsonl"],
}
TEXT = (".jsonl", ".txt", ".json")


def location(training: Path, group: str, rel: str) -> Path:
    """Where a work-area file lives in training/: model files in training/model/,
    the rest under training/data/ with their own sub-folders."""
    inner = rel.removeprefix("model/") if group == "model" else rel
    return training / group / inner


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def save(work: Path, training: Path) -> dict:
    index_path = training / "PACKED.json"
    index = json.loads(index_path.read_text()) if index_path.is_file() else {}
    for group, files in GROUPS.items():
        for rel in files:
            src = work / rel
            if not src.is_file():
                continue
            digest = sha256(src)
            if index.get(rel, {}).get("sha256") == digest:
                continue                          # unchanged: no new copy in git
            base = location(training, group, rel)
            base.parent.mkdir(parents=True, exist_ok=True)
            for old in base.parent.glob(base.name + "*"):
                old.unlink()                      # stale parts of an older version
            if rel.endswith(TEXT):
                with open(src, "rb") as fin, gzip.open(str(base) + ".gz", "wb", compresslevel=9) as fout:
                    shutil.copyfileobj(fin, fout)
                stored = [base.name + ".gz"]
            else:
                stored = []
                with open(src, "rb") as fin:
                    n = 0
                    while chunk := fin.read(PART):
                        name = f"{base.name}.part-{n:02d}"
                        (base.parent / name).write_bytes(chunk)
                        stored.append(name)
                        n += 1
            index[rel] = {"group": group, "sha256": digest, "bytes": src.stat().st_size, "stored": stored}
            print(f"  saved {rel} ({src.stat().st_size // 1024} KB) as {len(stored)} file(s)")
    index_path.write_text(json.dumps(index, indent=2, sort_keys=True) + "\n")
    return index


def restore(training: Path, work: Path, groups: tuple[str, ...] = ("model", "data")) -> int:
    index_path = training / "PACKED.json"
    if not index_path.is_file():
        print(f"nothing saved in {training} yet")
        return 0
    changed = 0
    for rel, entry in json.loads(index_path.read_text()).items():
        if entry["group"] not in groups:
            continue
        dest = work / rel
        if dest.is_file() and sha256(dest) == entry["sha256"]:
            continue
        dest.parent.mkdir(parents=True, exist_ok=True)
        tmp = dest.with_name(dest.name + ".restoring")
        folder = location(training, entry["group"], rel).parent
        with open(tmp, "wb") as out:
            for name in entry["stored"]:
                if name.endswith(".gz"):
                    with gzip.open(folder / name, "rb") as f:
                        shutil.copyfileobj(f, out)
                else:
                    with open(folder / name, "rb") as f:
                        shutil.copyfileobj(f, out)
        if sha256(tmp) != entry["sha256"]:
            tmp.unlink()
            raise SystemExit(f"{rel}: checksum mismatch — the saved copy is incomplete; nothing was replaced")
        # manifest.json last-ish matters: the recognizer reloads when it changes,
        # and os.replace makes each file appear whole.
        tmp.replace(dest)
        changed += 1
        print(f"  restored {rel}")
    return changed


def main(argv: list[str] | None = None) -> int:
    args = argv if argv is not None else sys.argv[1:]
    if len(args) != 3 or args[0] not in ("save", "restore", "publish"):
        print(__doc__)
        return 2
    command, a, b = args[0], Path(args[1]), Path(args[2])
    if command == "save":
        save(a, b)
    elif command == "restore":
        restore(a, b)
    else:
        n = restore(a, b, groups=("model",))
        # The manifest goes last so the recognizer reloads onto a complete model.
        if n:
            (b / "model" / "manifest.json").touch()
        print(f"published: {n} model file(s) updated in {b / 'model'}" if n else "the app already has this model")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
