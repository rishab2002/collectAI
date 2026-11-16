# collectAI

A catalog of diecast cars — Hot Wheels, Matchbox, Tomica, Mini GT and whatever
comes next — that fills itself from public wiki dumps, reads cars off
photographs on its own hardware, and publishes nothing until a person has
approved it.

Collectors browse by brand → main line → sub-series → car, keep a collection
and a wishlist, and photograph a pile of their own cars to add them. The import
reaches 6,819 castings across four sources, carrying 38,266 releases and 51,239
variations under them.

The site runs at [collectai.club](https://collectai.club) on Railway, with
Postgres on Supabase and Cloudflare in front. It sits behind an email-gated
Cloudflare Access login while it's in development, so that link won't open
without an invitation. It runs locally in Docker in two commands.

## The parts worth a look

**An importer that treats provenance as the gate.** Hot Wheels and Matchbox
publish MediaWiki dumps. The importer parses their release tables — rowspan
blocks where one release sold with four wheel types is a single row plus three
partial ones — and keys every record it writes to the source page and row it
came from. That key is what makes a re-import update instead of duplicate.
Facts the wiki states go live. The one thing it never states, which real make
and model a toy casting represents, is inferred separately and held in a review
queue, so an unidentified car stays invisible rather than going public
unattributed.

**Recognition that doesn't call out to anyone.** A standalone Python service
(OpenCV, Tesseract, ONNX Runtime) segments cars out of a photograph, reads the
base and card text, names the colour family, and matches all of it against a
catalog index exported as a single JSON file. It never touches the database, so
it runs anywhere. A DINOv2-small encoder exported to ONNX does the visual half:
training embeds labelled photos, fits a whitening head only when that beats the
plain encoder on held-out data, and calibrates its own confidence thresholds.
There's a Claude-API driver for the text and metadata agents, but it's opt-in —
`CATALOG_AI_DRIVER=local` is the default, and the deployed site depends on no
outside model.

**Approval that can't be routed around.** Every catalog row is `pending` or
`live`; the public pages read `live` only. `status` isn't mass-assignable on any
model, the column defaults to `pending`, and approving a record promotes its
whole ancestor chain in one transaction so nothing goes live underneath an
invisible parent. A real casting with no model attached raises on approval
rather than publishing.

**Measurements instead of claims.** "Can the trained model recognise a car
through its blister packaging?" is answered no, with the numbers: padding a
card out with the carpet it's lying on costs 60 points of top-1 accuracy, while
angle, light, distance and a card half out of frame cost between nothing and
twelve. Two candidate fixes were built, measured, and thrown away. The write-up
in `CLAUDE.md` says which three things would change the answer.

## Built with

| | |
|---|---|
| Web | Laravel 13, PHP 8.3+, Blade, Tailwind 4, Vite |
| Data | PostgreSQL 16 (`pg_trgm` trigram indexes, `jsonb`, expression unique indexes), Redis |
| Photos | Cloudflare R2, served from a custom domain |
| Recognition | Python, OpenCV, Tesseract, ONNX Runtime, DINOv2-small |
| Clients | PWA, plus an iOS shell via Capacitor |
| Infra | Docker; nginx + php-fpm under supervisord in production; Railway, Supabase, Cloudflare Access |

The PHP suite runs 406 tests and 1,711 assertions against Postgres rather than
SQLite, because the schema leans on extensions SQLite doesn't have. The
recognizer carries 91 of its own.

## Measured

| | |
|---|---|
| Catalog, four sources | 6,819 castings · 38,266 releases · 51,239 variations |
| Visual model gallery | 62,149 photos · 15,796 castings |
| Top-1 / top-5 on 8,236 held-out versions | 73.3% / 85.2% |
| Visual-only answers, where thresholds allow one | 95.1% precise at 25.5% coverage |

The held-out split holds out whole versions, so a car is tested on a colour it
never saw during training. Those photos come from the wikis and from makers'
product listings; accuracy on a phone photo of a loose car is still unmeasured,
and the manual says so wherever it comes up.

## Running it

With Docker Desktop and Node 22+ installed:

```bash
./collectai setup
```

That writes `.env`, builds and starts the containers, migrates the database,
creates an admin account and builds the styles. The site is then on
`localhost:8000`. To fill it from the wiki dumps, which takes about twenty
minutes:

```bash
./collectai import
```

`./collectai help` lists the rest — publishing, training, tests, deploy — and
`--dry-run` prints what any of them would run without running it. Every step it
takes is documented longhand in
[instructions/README.md](instructions/README.md).

## Where the detail lives

- [instructions/README.md](instructions/README.md) — the full manual: setup,
  the import pipeline, the recognition and training commands, deployment,
  troubleshooting, command reference.
- [CLAUDE.md](CLAUDE.md) — the decisions and the reasons, including the traps
  that cost real time and the measurements behind each threshold.
- [docs/session-log.md](docs/session-log.md) — what happened when, and why.
- [docs/deploy.md](docs/deploy.md) — the production runbook.

Data sources are documented and licence-checked rather than scraped:
CC-BY-SA wiki facts, wiki photos only where the file itself is licensed for
reuse, and written permission on file for the reference sources used in
training. [docs/source-permissions.md](docs/source-permissions.md) is the
request that goes out to a maker; several sites were checked and declined.
