"""Command line: run the recognizer on your own photos, or serve it.

    python -m collectai_recognizer --index catalog-index.json scan photo.jpg [more.jpg] [--layout single]
    python -m collectai_recognizer --index catalog-index.json card card.jpg
    python -m collectai_recognizer --index catalog-index.json serve --port 8090
"""

from __future__ import annotations

import argparse
import base64
import json
import sys
from pathlib import Path

from . import __version__, ocr
from .catalog import CatalogIndex
from .pipeline import decode, read_card, recognise
from .segment import LAYOUTS
from .server import env_defaults, serve


def main(argv: list[str] | None = None) -> int:
    d = env_defaults()
    p = argparse.ArgumentParser(prog="collectai_recognizer", description=f"collectAI local car recognizer {__version__}")
    p.add_argument("--index", default=d["index"], help="catalog index JSON (php artisan catalog:export-recognition-index)")
    p.add_argument("--min-confidence", type=float, default=d["min_confidence"])
    p.add_argument("--min-margin", type=float, default=d["min_margin"])
    sub = p.add_subparsers(dest="command", required=True)

    scan = sub.add_parser("scan", help="find and identify every car in one or more photos")
    scan.add_argument("photos", nargs="+")
    scan.add_argument("--json", action="store_true", help="print the full result as JSON")
    scan.add_argument("--save-crops", metavar="DIR", help="write each detected car as a JPEG here")
    scan.add_argument("--all", action="store_true", help="also list cars that were found but not identified")
    scan.add_argument("--layout", choices=LAYOUTS, default="auto",
                      help="single = one car or base close up; pile = several cars spread out; auto = guess")

    card = sub.add_parser("card", help="read a carded car into the OCR schema")
    card.add_argument("photo")

    srv = sub.add_parser("serve", help="run the HTTP service the website calls")
    srv.add_argument("--host", default="0.0.0.0")
    srv.add_argument("--port", type=int, default=8080)

    args = p.parse_args(argv)

    if args.command == "serve":
        serve(args.host, args.port, args.index, args.min_confidence, args.min_margin)
        return 0

    if ocr.available() is None:
        print("warning: tesseract is not installed, so no text will be read (apt install tesseract-ocr / brew install tesseract)", file=sys.stderr)

    index = None
    if Path(args.index).is_file():
        index = CatalogIndex.load(args.index)
    else:
        print(f"warning: no index at {args.index}; cars will be found but not identified", file=sys.stderr)

    if args.command == "card":
        print(json.dumps(read_card(decode(Path(args.photo).read_bytes()), index,
                                   min_confidence=args.min_confidence, min_margin=args.min_margin), indent=2))
        return 0

    for photo in args.photos:
        result = recognise(decode(Path(photo).read_bytes()), index, crops=bool(args.save_crops), layout=args.layout,
                           min_confidence=args.min_confidence, min_margin=args.min_margin)
        if args.save_crops:
            out = Path(args.save_crops)
            out.mkdir(parents=True, exist_ok=True)
            for det in result["detections"]:
                (out / f"{Path(photo).stem}-{det['n']:02d}.jpg").write_bytes(base64.b64decode(det["crop_jpeg"]))
                det["crop_jpeg"] = f"{Path(photo).stem}-{det['n']:02d}.jpg"
        if args.json:
            print(json.dumps({"photo": photo, **result}, indent=2))
            continue
        print(f"{photo}: {result['found']} object(s), {result['confident']} identified with confidence")
        for det in result["detections"]:
            m = det["match"] or {}
            colour = det["colour"]["family"] or "?"
            if det["confident"]:
                top = m["variations"][0] if m["variations"] else {}
                print(f"  #{det['n']:<2} {m['casting_name']}  ({m['confidence']:.2f}; {', '.join(m['reasons'])})")
                print(f"       colour {colour}; best variation: {top.get('year')} {top.get('color')} · {top.get('wheel_type') or '—'} · {top.get('series') or ''}")
            elif args.all:
                guess = (m.get("candidates") or [{}])[0]
                hint = f"best guess {guess['name']} {guess['score']:.2f}, not confident" if guess else "no readable text"
                print(f"  #{det['n']:<2} not identified — colour {colour}; {hint}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
