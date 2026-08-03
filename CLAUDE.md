## Status as of 2026-08-02 — Schema migrations landed

The Laravel 13 + Docker scaffold is set up and running
(`docker compose up -d` — services: `app`, `db` (Postgres 16), `redis`,
`meilisearch`). `.env` is copied from `.env.example`; both already match the
compose service names (`DB_HOST=db`, `REDIS_HOST=redis`,
`MEILISEARCH_HOST=http://meilisearch:7700`).

The Core Data Model above is now concrete migrations in
`database/migrations/2026_08_02_1000*`, covering `makes`, `models`, `brands`,
`castings`, `casting_versions`, `series`, `releases`, `variations`,
`price_histories`, `photos`, `user_collection_items`, and
`user_wishlist_items`. Verified against the live Docker Postgres: migrate,
rollback, and re-migrate all run clean.

Key decisions made while writing them (don't relitigate these — they're
settled, only reopen if a real requirement contradicts one):

- **Fantasy castings**: `castings.model_id` is nullable (null = no real
  vehicle). There is deliberately no `make_id` column on `castings` — make is
  reached via `model.make_id` so it can't drift out of sync with model_id.
- **Casting Version (retools)**: a child table, `casting_versions`
  (`casting_id`, `version_number`, `retool_year`, `notes`, unique on
  `casting_id + version_number`). `releases.casting_version_id` points at the
  specific tooling revision, not the abstract `casting_id` — a retool is
  physically distinct tooling with its own releases, while brand/model stay
  owned once on the parent `castings` row.
- **Variation uniqueness**: not a plain `UNIQUE` constraint — in Postgres
  `NULL != NULL`, and every distinguishing attribute (finish, color, rim
  type, tampo, interior color, window tint, base color) is optional, so NULL
  collisions would be the common case. Enforced instead via a Postgres
  expression unique index that wraps each column in `COALESCE(col, '')`
  (see `2026_08_02_100008_create_variations_table.php`). Confirmed live: an
  exact duplicate on the same release is rejected; a genuinely different
  variation on the same release is accepted.
- **Hard-unique vs. fuzzy-only**: `makes.name` and `brands.name` are
  small, admin-controlled vocabularies — hard `UNIQUE`, since a duplicate
  there is always a data-entry error. `models.name`, `castings.name`, and
  `series.name` are populated by the AI pipeline and deliberately have **no**
  hard unique constraint — near-duplicates are expected and should surface
  for Comparison/Review Agent handling, not be silently blocked or merged by
  the DB.
- **Trigram (`pg_trgm`) indexes**: GIN trigram indexes on `models.name`,
  `castings.name`, `series.name`, `brands.name`, and
  `releases.collector_number` (OCR-extracted, prone to misreads). Not on
  `makes.name`/`brands.name`'s uniqueness path — those rely on exact hard
  uniqueness instead, not fuzzy search.

Scope of that session was schema only — no Eloquent models, no controllers,
no routes, no AI agent code yet.

---

# Universal Die-Cast Database — Project Context

This file is auto-loaded by Claude Code at the start of every session in this
repo. It exists so the domain model, conventions, and pipeline design only
have to be explained once. Update it whenever a convention changes — treat
it as the source of truth, not the conversation history.

**Last edited:** 2026-08-02

## Index

- [Goal](#goal)
- [Stack](#stack)
- [Core Data Model](#core-data-model)
  - [Make/Model splitting rules](#makemodel-splitting-rules)
  - [Field definitions](#field-definitions-for-consistency-across-agents-and-manual-entry)
- [AI Agent Pipeline](#ai-agent-pipeline)
  - [Example flow](#example-flow)
- [Data Acquisition Priorities](#data-acquisition-priorities)
- [Conventions for Claude Code sessions in this repo](#conventions-for-claude-code-sessions-in-this-repo)
- [Next Steps](#next-steps)

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
- Cloudflare R2 (photos — S3-compatible API, so it's a drop-in with
  Laravel's built-in S3 filesystem driver; chosen over AWS S3 for zero
  egress fees, which matters for a public, image-heavy catalog, and a
  free tier that isn't time-limited like AWS's 12-month trial)
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

## Next Steps

*Added 2026-08-02.*

Eloquent models (`app/Models`) and migrations (`database/migrations`) for the
full hierarchy above are in place. Outstanding:

1. **Model factories and seeders** — none exist yet, so there is no way to
   populate a local catalog or to write tests that need catalog data. The
   models were written without `HasFactory` rather than pointing the trait at
   factory classes that don't exist; add it to each model as its factory
   lands. Seed at least one complete vertical slice (Make → Model → Casting →
   Brand → Series → Release → Variation → Price History) so the AI pipeline
   has something real to diff against.
2. **Meilisearch indexing** — `laravel/scout` is not in `composer.json` yet,
   so nothing is indexed and cross-brand search doesn't work end to end. Note
   that `pg_trgm` and Meilisearch are not substitutes for each other: trigram
   similarity is the Comparison Agent's DB-side duplicate check, Meilisearch
   is user-facing typo-tolerant search.
## Guide for the next session

Schema is settled and migrated (see the status block at the top of this
file). Per the "keep schema and implementation in separate sessions"
convention above, the next session should **not** revisit the Casting/Model
split or the migrations — build on them.

Recommended next step: **Eloquent models + relationships**, since everything
downstream (agents, controllers, admin approval UI) depends on them.

- One model per table in `app/Models/`, with the relationships already
  implied by the FKs: `Make hasMany Model`, `Model hasMany Casting`,
  `Casting belongsTo Brand`, `Casting hasMany CastingVersion`,
  `CastingVersion hasMany Release`, `Release belongsTo Series`,
  `Release hasMany Variation`, `Variation hasMany PriceHistory|Photo`, and
  the `User` belongsToMany `Variation` pairs for collection/wishlist.
- Note the `Model` name collision: the `models` table maps to a vehicle
  model, which clashes with Eloquent's own `Model` base class. Name the
  class something like `VehicleModel` with an explicit
  `protected $table = 'models'` rather than renaming the table.
- Add factories/seeders alongside them — the AI pipeline work after this
  will need realistic fixture data to test the Comparison Agent's duplicate
  detection against.

After models, the natural order is: admin approval workflow (the `pending` →
live promotion the Review Agent depends on), then the AI agent pipeline
starting with the OCR output schema at `/app/AI/schemas/ocr_output.json`.

Two things not yet built that the schema assumes:

- A `status` (pending/live) column does **not** exist yet on any table. The
  Insertion/Review Agents need it, so whoever builds the approval workflow
  should add it as a new migration — decide then whether it belongs on every
  catalog table or just the ones agents write to.
- The photos table defaults `disk` to `'r2'`, but no R2 disk is configured in
  `config/filesystems.php` and the R2 credentials in `.env` are still the
  empty AWS placeholders. Wire that up before anything actually uploads.
