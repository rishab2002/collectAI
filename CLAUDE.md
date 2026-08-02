# Universal Die-Cast Database — Project Context

This file is auto-loaded by Claude Code at the start of every session in this
repo. It exists so the domain model, conventions, and pipeline design only
have to be explained once. Update it whenever a convention changes — treat
it as the source of truth, not the conversation history.

## Goal

A brand-agnostic diecast catalog and website covering Hot Wheels, Matchbox,
Corgi, Inno64, Mini GT, Tomica, GreenLight, and others, designed so new
brands can be added without schema changes. Supports catalog browsing,
user collections/wishlists, price history, and AI-assisted catalog growth
from photos.

## Stack

- Laravel (PHP)
- PostgreSQL (preferred over MySQL for trigram/fuzzy search support via
  `pg_trgm`, useful for the Comparison Agent's duplicate detection)
- AWS S3 (photos)
- Redis (queues, caching)
- Meilisearch (cross-brand search — typo-tolerant, low ops overhead;
  don't reach for Elasticsearch unless scale genuinely demands it)
- Docker (local + deploy)

## Core Data Model

```
Make (real-world manufacturer, e.g. Mazda)
  └── Model (real-world vehicle, e.g. RX-7)
        └── Casting (a toy manufacturer's specific tooling of that model,
                      may include a Casting Version if retooled)
              └── Brand (Hot Wheels, Matchbox, etc. — a casting can be
                          reused across sister brands owned by the same
                          company)
                    └── Series (e.g. "Night Burnerz", a themed release wave)
                          └── Release (a specific year the casting was sold
                                       in stores, under that series)
                                └── Variation (what makes this release unit
                                               unique: finish, color, rims,
                                               tampos, etc.)
                                      └── Price History
                                      └── Photos
                                      └── User Collection / Wishlist entries
```

### Make/Model splitting rules

- If a casting is based on a real production car, split it into real-world
  **Make** and **Model** records and link the Casting to them — even if the
  toy packaging only prints one name.
  - Example: a Hot Wheels "'93 Mazda RX-7" casting → Make: Mazda, Model:
    RX-7 (FD3S), Casting: linked to both.
- Apply this only to castings based on real vehicles. Fantasy/original
  castings (no real make/model) do NOT get Make/Model records — leave
  those fields null rather than inventing a value.
- Prefer the specific chassis code (e.g. FD3S) in the Model record's
  metadata when it's identifiable, since the same nameplate can span
  multiple real-world generations.
- A single real Make/Model can be cast by multiple brands (Hot Wheels,
  Matchbox, GreenLight might each tool their own RX-7) — Casting is
  brand-specific, Model is not.

### Field definitions (for consistency across agents and manual entry)

- **Release**: defined by the *year* a casting/color combo was sold in
  stores under a given series. A casting can have many Releases across
  years.
- **Variation**: what distinguishes one unit of a Release from another —
  finish, color, wheel/rim type, tampo (printed graphics), interior color,
  window tint, base color, etc. Each distinguishing combination is its own
  Variation record, not a note field.

## AI Agent Pipeline

Order matters — later agents depend on earlier ones' output. Only the first
three should call the Claude API directly; everything from Comparison
onward should be deterministic application code, not LLM calls (cheaper,
faster, and more reliable for DB matching).

1. **Image Agent** — reads card/package photos.
2. **OCR Agent** — extracts brand, year, series, casting name, collector
   number from the image. Output as strict JSON (define and version the
   schema in `/app/AI/schemas/ocr_output.json` — don't let this drift
   between prompt iterations).
3. **Metadata Agent** — normalizes extracted names/attributes against known
   casting/brand/series naming conventions (e.g. resolves OCR typos,
   standardizes series names).
4. **Search Agent** — finds corroborating public references for anything
   not already resolvable from the DB.
5. **Comparison Agent** — deterministic DB lookup (trigram similarity via
   `pg_trgm`, Meilisearch typo-tolerant match) to check if the release
   already exists. No LLM call here.
6. **Insertion Agent** — creates proposed records (status: pending) from
   whatever the Comparison Agent flagged as missing.
7. **Review Agent** — assigns a confidence score to proposed records;
   anything below threshold requires manual admin approval before it's
   promoted from pending to live.

### Example flow

Input: Brand=Hot Wheels, Year=2011, Series="Night Burnerz", photo of a
'11 Honda Civic.

1. Identify the release from the photo + metadata.
2. Pull all known castings in the "Night Burnerz" series for that year.
3. Diff against the DB — only the missing ones proceed.
4. Search only for the missing releases' corroborating data.
5. Present proposed additions for admin approval.
6. On approval, insert as live records.

## Data Acquisition Priorities

1. Build an original database (don't just mirror a third-party source).
2. Import from official catalogs where available.
3. Crawl public pages/APIs only where permitted by their terms.
4. OCR/image recognition from packaging photos.
5. Always diff against the existing DB before insertion — add only
   genuinely missing records, never duplicate.

## Conventions for Claude Code sessions in this repo

- Keep schema/model decisions and implementation work in separate
  sessions — don't iterate on the Casting/Model split *and* write
  migrations in the same session; settle the model first.
- When adding a new brand, it should require zero schema changes — if a
  change to `brands`, `castings`, or `releases` tables seems necessary to
  onboard a new brand, that's a sign the schema needs a rethink, not a
  one-off patch.
- Vision/OCR calls belong in a dedicated service class (e.g.
  `app/Services/AI/OcrAgent.php`), not inline in controllers — keeps the
  Claude API usage auditable and easy to swap/version.
