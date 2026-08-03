# Universal Die-Cast Database — Project Context

Auto-loaded at the start of every Claude Code session in this repo, so the
domain model, conventions and current state only have to be explained once.
**Treat this file as the source of truth, not the conversation history** —
update it whenever a convention changes.

**Last edited:** 2026-08-03 (brand placement settled and migrated)

## Start here

A brand-agnostic diecast catalog (Hot Wheels, Matchbox, Tomica, Mini GT, …) on
Laravel + Postgres, whose catalog grows from photographs of packaging via an AI
pipeline, gated behind admin approval.

**The data layer is complete and tested. The product is not started.** Read
[Current state](#current-state) before assuming any feature exists — several
plausible-sounding ones don't, including some the commit history implies.

The five things most likely to trip you up, each expanded below:

1. **Check `database/migrations` before writing one.** Two branches once each
   built the entire schema, and both were merged. See
   [How to work in this repo](#how-to-work-in-this-repo).
2. **`migrate:fresh --seed` + `php artisan test` is the gate for schema work.**
   Plain `php artisan migrate` on an already-migrated dev DB proves nothing.
3. **Tests run on Postgres, not SQLite**, and `docker-compose.yml` must not get
   an `env_file:` on the `app` service. Both traps have cost real time.
4. **A cross-brand release is legal.** One casting is one physical tool; the
   brand that sold it is a fact about the release. See
   [Settled decisions → Schema](#schema).
5. **Nothing auto-publishes.** Catalog rows default to `pending`, `status` is
   not fillable anywhere, and approval promotes the whole ancestor chain.

## Index

- [Current state](#current-state) — what exists, what doesn't
- [What to do next](#what-to-do-next)
- [How to work in this repo](#how-to-work-in-this-repo) — conventions, traps
- [Domain model](#domain-model) — [Make/Model splitting](#makemodel-splitting-rules) · [field definitions](#field-definitions)
- [AI agent pipeline](#ai-agent-pipeline) — [example flow](#example-flow) · [acquisition priorities](#data-acquisition-priorities)
- [Settled decisions](#settled-decisions) — [schema](#schema) · [approval](#approval--submissions) · [delivery](#delivery)
- [Open questions](#open-questions)
- [Session log](docs/session-log.md) — narrative and rationale, loaded on demand

---

## Current state

*Verified 2026-08-03 against the running stack. Keep this section honest — it's
the fastest way for a new session to avoid assuming something exists.*

**Running:** `docker compose up -d` brings up `app` (:8000), `db` (Postgres 16,
:5432), `redis` (:6379), `meilisearch` (:7700). `php artisan test` → **41
passed, 81 assertions**.

**The entire HTTP surface is four routes** (`routes/web.php`):

| Route | State |
|---|---|
| `GET /` | Stock Laravel welcome page, untouched scaffold |
| `GET\|POST /login` | Real. Admin only — no registration, no password reset |
| `POST /logout` | Real |
| `GET /admin` | Read-only list of submissions awaiting review. Nothing clickable |

**Built and tested:**

- All 12 catalog tables + `submissions`, `submission_photos`,
  `submission_proposals`. Migrations verified from zero (`migrate`, `rollback`,
  `re-migrate`, `migrate:fresh --seed` all clean).
- Eloquent models for every table, factories for every model, three seeders
  (`AdminUserSeeder`, `CatalogSeeder`, `SubmissionSeeder`).
- `pg_trgm` enabled with GIN trigram indexes.
- Admin session auth + `EnsureUserIsAdmin` (alias `admin`).
- Approval mechanics: `promoteWithDependencies()`, `SubmissionProposal::approve()`
  / `reject()`, `SubmissionPhoto::promoteTo()`. Tested, but no UI calls them.

**Not built — do not assume these exist:**

- **No AI pipeline code whatsoever.** There is no `app/Services/` directory, no
  `app/AI/` directory, and no `ocr_output.json`. `SubmissionFactory::completed()`
  and `SubmissionSeeder` hand-write the shape the OCR agent is expected to emit;
  that's the starting point for the schema, not the schema.
- **No PWA.** No `public/manifest.json`, no service worker, no `apple-touch-icon`,
  no capture screen — despite commit `e2ce9d1` being titled "PWA".
- **No Meilisearch integration.** The container runs, but `laravel/scout` is not
  in `composer.json`, so nothing is indexed and cross-brand search doesn't work
  end to end. Note `pg_trgm` and Meilisearch are not substitutes: trigram
  similarity is the Comparison Agent's DB-side duplicate check, Meilisearch is
  user-facing typo-tolerant search.
- **No R2 uploads.** The disk is wired in `config/filesystems.php`
  (`league/flysystem-aws-s3-v3` installed, `throw => true` so a silent upload
  failure can't record a photo whose bytes never arrived), but the credentials
  in `.env` are empty placeholders and nothing has ever uploaded through it.
- **No public catalog browsing, collections, wishlists, price charts, or
  barcode scanning.** The tables exist; nothing reads them.
- Nothing creates a `CastingVersion` automatically alongside a `Casting`.
  `CatalogSeeder::casting()` does it explicitly. This is deliberate: a model
  `created` hook would collide with `CastingVersionFactory`, which builds a
  casting and its version separately. Whoever writes the Insertion Agent must
  create version 1 explicitly.

## What to do next

Schema, models, factories, seeders, admin auth and the approval mechanics are
all settled and tested. Per the separate-sessions convention, don't revisit the
Casting/Model split, the migrations, or the approval-status decisions — build on
them.

**The recommended next step is the capture and review screens** — the PWA the
whole submission system exists for. The backing models and the approve/reject
methods are already there and tested; this is UI plus two controllers.

- **Capture screen** (`/admin/capture`): a form posting one or more photos plus
  the optional brand/year/series hints. Use
  `<input type="file" accept="image/*" capture="environment">` — on iOS that
  opens the camera directly, and it degrades to a file picker on desktop.
  Upload to the `r2` disk, create a `Submission` with its `SubmissionPhoto`
  rows, set `state` to `queued`.
  - Uploads must be **foreground**; iOS suspends background web uploads. Show
    real progress and make a failed upload retryable rather than silent.
  - This needs real R2 credentials in `.env` first — nothing has uploaded yet.
- **Review screen** (`/admin/submissions/{submission}`): the submission's
  photos alongside its `proposals` (eager-load `proposable`), with approve and
  reject actions calling `SubmissionProposal::approve()` / `reject()`. Show
  `confidence` and flag anything `requiresManualReview()`. Approving a leaf
  already promotes its ancestors, so the UI does not need to walk the tree —
  but it should show the reviewer what else will go live as a result.
- **PWA shell**: `public/manifest.json` (`display: standalone`, icons), an
  `apple-touch-icon`, and a service worker. Keep the service worker's caching
  conservative — a stale cached review queue showing already-decided proposals
  is worse than a slow one.
- Note the layout lives at `resources/views/components/layouts/app.blade.php`
  (anonymous Blade components resolve under `views/components/`), and the base
  `TestCase` calls `withoutVite()` so view tests don't need a frontend build.

**Then the AI pipeline**, starting with the OCR output schema at
`/app/AI/schemas/ocr_output.json` — see `SubmissionFactory::completed()` for the
shape currently assumed. Vision/OCR calls belong in
`app/Services/AI/OcrAgent.php`, not in the capture controller: the controller's
job ends at storing the photo and queueing the job.

**Further out, still untouched:** Meilisearch indexing (`laravel/scout` isn't
installed), the public catalog, collections/wishlists, price charts, barcode
scanning.

## How to work in this repo

- **Keep schema/model decisions and implementation work in separate sessions.**
  Don't iterate on the Casting/Model split *and* write migrations at once —
  settle the model first.
- **Adding a new brand must require zero schema changes.** If a change to
  `brands`, `castings` or `releases` seems necessary to onboard one, that's a
  sign the schema needs a rethink, not a one-off patch.
- **Vision/OCR calls belong in a dedicated service class** (e.g.
  `app/Services/AI/OcrAgent.php`), not inline in controllers — keeps Claude API
  usage auditable and easy to swap or version.
- **Before writing a migration, look at what's already in
  `database/migrations` on your branch — not just what the DB happens to have.**
  Two branches each built the whole schema once already; both got merged, and
  `main` shipped broken without anyone noticing. A migration that only ever runs
  forward on an already-migrated dev database hides a collision that a fresh
  `migrate` would catch immediately. (Full story in the
  [session log](docs/session-log.md).)
- **`migrate:fresh --seed` and `php artisan test` are the gate for any schema
  change**, because neither passes on a database that was migrated
  incrementally. Running only `php artisan migrate` proves nothing about whether
  the schema can be built from zero.

### Environment traps — don't reintroduce these

- **`docker-compose.yml` must NOT have `env_file: .env` on the `app` service.**
  The project is bind-mounted, so Laravel reads `.env` off disk itself. Adding
  `env_file` injects every dev value into the container's process environment,
  where PHP exposes it via `$_SERVER` — and Laravel's Dotenv reads `$_SERVER`
  ahead of everything else. Consequences when it was present: `phpunit.xml` was
  silently ignored **even with `force="true"`** (PHPUnit sets `$_ENV` and
  `putenv`, never `$_SERVER`), so the suite ran `RefreshDatabase` against the
  **development** database and truncated it; and editing `.env` did nothing
  until the container was recreated. `tests/Unit/EnvironmentTest.php` now
  asserts the suite is pointed at `diecast_testing`.
- **The test suite runs on Postgres, not SQLite.** `pg_trgm`, GIN trigram
  indexes, the `COALESCE` expression index, `jsonb` and partial indexes don't
  exist in SQLite. `docker/postgres/init-test-db.sh` creates `diecast_testing`
  on first volume creation; an existing environment needs it made by hand.

Also note: `env('SOME_VAR')` returns `''`, not `null`, for a bare `VAR=` line in
`.env`. That produced an admin account whose password was the empty string and
which authenticated against it. `config/catalog.php` normalizes with `?: null`
and `AdminUserSeeder` uses `blank()`; `AdminUserSeederTest` guards it.

**Where the admin password lives:** `ADMIN_PASSWORD` in `.env`, which is
gitignored. Set it there and it survives reseeds; leave it blank and
`AdminUserSeeder` generates a fresh one per seed and prints it exactly once, so
any value written down elsewhere is stale the next time anyone runs
`migrate:fresh --seed`. Don't paste it into this file — CLAUDE.md is tracked and
pushed, and git history keeps a committed secret whether or not it's later
deleted. The seeder is idempotent and won't reset an existing admin, so changing
the password means deleting the `admin@example.test` row and re-running
`db:seed --class=AdminUserSeeder`.

### Still not built

- No capture or review **screens** yet — `/admin` is a placeholder list of
  submissions awaiting review. The PWA manifest, service worker, camera capture
  and approve/reject UI are the next milestone.
- No AI agent code at all. `/app/AI/schemas/ocr_output.json` doesn't exist yet;
  `SubmissionFactory::completed()` and `SubmissionSeeder` hand-write the shape
  the OCR agent is expected to emit, which is a starting point for that schema.
- R2 credentials in `.env` are still empty placeholders — the disk is
  configured but nothing has uploaded through it.
- Nothing creates a `CastingVersion` automatically alongside a `Casting`.
  `CatalogSeeder::casting()` does it explicitly. This is deliberate: a model
  `created` hook would collide with `CastingVersionFactory`, which builds a
  casting and its version separately. Whoever writes the Insertion Agent must
  create version 1 explicitly.

---

## Domain model

**Goal:** a brand-agnostic diecast catalog and website covering Hot Wheels,
Matchbox, Corgi, Inno64, Mini GT, Tomica, GreenLight and others, designed so new
brands can be added without schema changes. Supports catalog browsing, user
collections/wishlists, price history, and AI-assisted catalog growth from photos.

**Stack:**

- Laravel (PHP)
- PostgreSQL (over MySQL for trigram/fuzzy search via `pg_trgm`, used by the
  Comparison Agent's duplicate detection)
- Cloudflare R2 (photos — S3-compatible, so a drop-in for Laravel's S3 driver;
  chosen over AWS S3 for zero egress fees, which matters for a public,
  image-heavy catalog, and a free tier that isn't time-limited)
- Redis (queues, caching)
- Meilisearch (cross-brand search — typo-tolerant, low ops overhead; don't reach
  for Elasticsearch unless scale genuinely demands it)
- Docker (local + deploy)

**The tree:**

```
Make (real-world manufacturer, e.g. Mazda)
  └── Model (real-world vehicle, e.g. RX-7)
        └── Casting (one physical tool cut by a toy manufacturer,
                      may gain a Casting Version if retooled)
              └── Brand (Hot Wheels, Matchbox, … — a casting can be
                          reused across sister brands owned by the
                          same company)
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

> Brand sits *under* Casting: a Casting is one physical tool, and the brand that
> sold it is a fact about the Release, carried by `series.brand_id`. The schema
> matches the tree — see [Settled decisions → Schema](#schema).

### Make/Model splitting rules

- If a casting is based on a real production car, split it into real-world
  **Make** and **Model** records and link the Casting to them — even if the toy
  packaging only prints one name.
  - Example: a Hot Wheels "'93 Mazda RX-7" casting → Make: Mazda, Model:
    RX-7 (FD3S), Casting: linked to both.
- Apply this only to castings based on real vehicles. Fantasy/original castings
  (no real make/model) do NOT get Make/Model records — leave those fields null
  rather than inventing a value. In the DB this is the positive
  `castings.is_fantasy` flag, *not* a null `model_id` — null `model_id` with
  `is_fantasy = false` means "real car, not identified yet" and must block
  approval rather than go live unattributed.
- Prefer the specific chassis code (e.g. FD3S) in the Model record's metadata
  when identifiable, since the same nameplate can span multiple real-world
  generations.
- A single real Make/Model can be tooled by multiple brands (Hot Wheels,
  Matchbox and GreenLight might each cut their own RX-7) — those are separate
  Castings, while the Model is shared.

### Field definitions

For consistency across agents and manual entry.

- **Release**: defined by the *year* a casting/color combo was sold in stores
  under a given series. A casting can have many Releases across years.
- **Variation**: what distinguishes one unit of a Release from another — finish,
  color, wheel/rim type, tampo (printed graphics), interior color, window tint,
  base color, etc. Each distinguishing combination is its own Variation record,
  not a note field.

## AI agent pipeline

**None of this is written yet** — see [Current state](#current-state). The tables
it writes into and the approve/reject mechanics it feeds are built and tested.

Order matters; later agents depend on earlier output. Only the first three
should call the Claude API directly. Everything from Comparison onward should be
deterministic application code, not LLM calls — cheaper, faster and more
reliable for DB matching.

1. **Image Agent** — reads card/package photos.
2. **OCR Agent** — extracts brand, year, series, casting name, collector number.
   Output as strict JSON (define and version the schema in
   `/app/AI/schemas/ocr_output.json` — don't let it drift between prompt
   iterations).
3. **Metadata Agent** — normalizes extracted names/attributes against known
   casting/brand/series naming conventions (resolves OCR typos, standardizes
   series names).
4. **Search Agent** — finds corroborating public references for anything not
   already resolvable from the DB.
5. **Comparison Agent** — deterministic DB lookup (`pg_trgm` trigram similarity,
   Meilisearch typo-tolerant match) to check whether the release already exists.
   No LLM call here.
6. **Insertion Agent** — creates proposed records (status: pending) from
   whatever Comparison flagged as missing.
7. **Review Agent** — assigns a confidence score; anything below threshold
   requires manual admin approval before promotion from pending to live.

### Example flow

Input: Brand=Hot Wheels, Year=2011, Series="Night Burnerz", photo of a
'11 Honda Civic.

1. Identify the release from the photo + metadata.
2. Pull all known castings in the "Night Burnerz" series for that year.
3. Diff against the DB — only the missing ones proceed.
4. Search only for the missing releases' corroborating data.
5. Present proposed additions for admin approval.
6. On approval, insert as live records.

### Data acquisition priorities

1. Build an original database (don't just mirror a third-party source).
2. Import from official catalogs where available.
3. Crawl public pages/APIs only where permitted by their terms.
4. OCR/image recognition from packaging photos.
5. Always diff against the existing DB before insertion — add only genuinely
   missing records, never duplicate.

---

## Settled decisions

*Don't relitigate these — only reopen if a real requirement contradicts one.
The rationale for each is in the [session log](docs/session-log.md).*

### Schema

- **Brand lives on the Release, via the Series.** A `Casting` row is one physical
  tool and carries **no** brand — `castings.brand_id` was dropped in
  `2026_08_03_100000`. Which brand sold it is a per-release fact, reached as
  `release → series → brand`, since `series.brand_id` exists and
  `releases.series_id` is non-nullable. **A cross-brand release is legal and
  expected** — that's how shared tooling (Johnny Lightning / Auto World,
  Mini GT / Kaido House) is represented: one casting row, releases under each
  brand's series. Do not add a constraint requiring a release's series brand to
  agree with anything on the casting; `CatalogSchemaTest` guards both directions.
  There is deliberately no `Brand::castings()` relation — see the note on that
  model. Casting name is the canonical tool name; if a shared tool is marketed
  under different names per brand, that is release-level
  (`releases.card_name`, nullable, add when a real case turns up).
- **Fantasy castings**: `castings.model_id` is nullable, and `castings.is_fantasy`
  is the positive assertion. `false` + null `model_id` = "not identified yet",
  exposed as `isUnidentified()`. A CHECK stops a fantasy casting from also
  claiming a real model. There is deliberately **no** `make_id` on `castings` —
  make is reached via `model.make_id` so it can't drift out of sync.
- **Casting Version (retools)**: child table `casting_versions` (`casting_id`,
  `version_number`, `retool_year`, `notes`, unique on `casting_id + version_number`).
  `releases.casting_version_id` points at the specific tooling revision, not the
  abstract `casting_id` — a retool is physically distinct tooling with its own
  releases, while the model stays owned once on the parent `castings` row.
- **Variation uniqueness**: not a plain `UNIQUE` — in Postgres `NULL != NULL`,
  and every distinguishing attribute is optional, so NULL collisions would be
  the common case. Enforced via an expression unique index wrapping each column
  in `COALESCE(col, '')` (see `2026_08_02_100008_create_variations_table.php`).
- **Hard-unique vs. fuzzy-only**: `makes.name` and `brands.name` are small,
  admin-controlled vocabularies — hard `UNIQUE`, a duplicate there is always a
  data-entry error. `models.name`, `castings.name`, `series.name` are populated
  by the AI pipeline and deliberately have **no** hard unique constraint —
  near-duplicates are expected and should surface for Comparison/Review Agent
  handling, not be silently blocked or merged by the DB.
- **Trigram indexes**: GIN trigram on `models.name`, `castings.name`,
  `series.name`, `brands.name`, and `releases.collector_number` (OCR-extracted,
  prone to misreads).
- **`ItemCondition`** is cast on `UserCollectionItem` with a matching CHECK, so
  writes that skip Eloquent are covered too.

### Approval / submissions

- **`status` (pending/live/rejected)** on the 8 tables the Insertion Agent can
  write to: `makes`, `brands`, `models`, `castings`, `casting_versions`,
  `series`, `releases`, `variations`. Not on `price_histories`/`photos`
  (observations on an approved variation, not proposed entities) or the
  `user_*` tables. String + CHECK constraint, not a native enum.
- **The column defaults to `pending`, factories default to `live`.** The DB
  default is fail-safe, so an agent that forgets to set a status produces an
  invisible record rather than publishing unreviewed output; fixtures almost
  always want an established catalog, so tests opt into `->pending()` explicitly.
- **`status` is not fillable on any model.** Records are built from LLM-derived
  JSON, and mass-assigning a payload containing a `status` key is precisely how
  unreviewed output would publish itself. Promotion goes through `markLive()`.
- **Approval promotes the whole ancestor chain** (`promoteWithDependencies()`,
  driven by each model's `approvalDependencies()`). One photo can propose a
  make, model, casting, version, series, release and variation at once;
  approving only the leaf would leave it live under invisible parents.
  Rejection deliberately does **not** cascade — rejecting a variation says
  nothing about whether its casting was wrong.
- **Submissions are their own tables, not `photos` rows.** `photos.variation_id`
  is non-nullable, but capture happens *before* the catalog knows the car.
  `submission_photos` holds the raw capture; it graduates into `photos` on
  approval via `SubmissionPhoto::promoteTo()`, referencing the same stored
  object rather than re-uploading.
- **`submissions.state` vs catalog `status`** are different questions, named
  differently on purpose: `state` is pipeline lifecycle
  (draft/queued/processing/completed/failed), `status` is catalog visibility. A
  submission can be `completed` while all its proposals are still `pending`.
- **`submission_proposals` is polymorphic**, carrying `confidence`, `decision`,
  `decided_by`, `decided_at`. Pipeline audit metadata stays out of the domain
  tables, and "everything awaiting review" is one indexed read instead of an
  eight-way UNION. Unique on `(proposable_type, proposable_id)` — a record is
  proposed once; re-photographing a car is the Comparison Agent's problem.
- **Rejected is a terminal state, not a delete**, so a resubmitted photo can be
  recognised as something an admin already turned down.
- **`config/catalog.php`** holds the admin identity and
  `review.auto_approve_threshold`, which starts at **1.0** — nothing
  auto-approves until there's real pipeline output to justify lowering it.

### Delivery

- **A PWA served by this Laravel app**, not native. The dev machine is Windows,
  so building/signing a native iOS app would need a Mac or paid cloud CI. Plan
  is to optionally wrap it with Capacitor later, pointed at the deployed origin
  — which is why auth is **session-based** rather than tokens. A Capacitor build
  that loads from the live URL keeps cookies working; only a build that bundles
  assets under its own scheme would need tokens, and that's the point to add them.
- **Admin auth returns 404, not 403** for non-admins, so they can't discover the
  admin area exists. Login is throttled on email+IP together.

## Open questions

*None blocking.* The brand-placement disagreement that sat here was settled on
2026-08-03 — see [Settled decisions → Schema](#schema).

---

Narrative, rationale and the record of what happened when live in
**[docs/session-log.md](docs/session-log.md)**. When you finish a session, add an
entry there and promote anything that became a durable rule up into this file.
