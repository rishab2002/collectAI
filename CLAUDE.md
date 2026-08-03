## Status as of 2026-08-02 — Duplicate schema reconciled

`main` had shipped broken and nobody had noticed, because nothing rebuilds the
schema in normal use. Read this block before adding migrations.

**What happened.** PRs #2 and #3 each built the catalog schema independently,
on branches that never saw each other, and both were merged. `main` ended up
with two complete sets of create-table migrations for the same nine tables
(`2026_08_02_0000*` from #3 and `2026_08_02_1000*` from #2), so a fresh
`php artisan migrate` died on the second `makes`:

```
2026_08_02_100001_create_makes_table ... FAIL
SQLSTATE[42P07]: relation "makes" already exists
```

The dev database kept working only because it had been migrated before the
collision existed; all 13 of PR #3's migrations sat at `Pending` and had never
run anywhere. The merge also kept PR #3's model classes while keeping PR #4's
factories, seeders and tests, which target the *other* schema — so the models
and the suite that exercised them disagreed about column names.

**Resolution: the `1000*` set (PR #2) survives, the `0000*` set is deleted.**
It's the set CLAUDE.md documents as settled, the one the `200*` migrations
build on, the one the live database has, and the one the factories and tests
assert. Model classes were restored to their PR #4 versions, the duplicate
`CollectionItem`/`WishlistItem` pair dropped in favour of
`UserCollectionItem`/`UserWishlistItem`, and the `ApprovalStatus` enum dropped
— its `approved` case would have failed the `pending`/`live`/`rejected` CHECK.

**Two ideas were salvaged from the deleted set** and rebuilt as additive
migrations (`200005`, `200006`):

- **`castings.is_fantasy`.** Previously "fantasy casting" and "real car we
  haven't identified yet" were both spelled `model_id IS NULL`, and
  `Casting::isFantasy()` returned exactly that. That breaks as soon as the
  pipeline writes castings: OCR routinely yields a casting whose `VehicleModel`
  the Comparison Agent can't match, and `approvalDependencies()` skips a null
  model — so an unidentified real car would have gone live with no Make/Model
  attribution instead of blocking on it. The flag is now the positive
  assertion; `false` + null `model_id` is the "not identified yet" state,
  exposed as `isUnidentified()` and as a factory state. A CHECK stops a fantasy
  casting from also claiming a real model.
- **`ItemCondition` is now wired.** It arrived with the deleted schema and was
  never attached to anything while `user_collection_items.condition` stayed
  free text. Now cast on the model, with a matching CHECK so writes that skip
  Eloquent are covered too.

**Still open — the one real design question underneath all this.** The two
schemas disagreed about where Brand belongs, and that disagreement is still
unresolved; the surviving set won on integration, not on merit.

- **What's in the DB now (PR #2):** `castings.brand_id`, required. One casting
  row belongs to one brand, and shared tooling is modelled as separate casting
  rows per brand.
- **What PR #3 argued:** brand belongs on `releases`, so one casting row is one
  physical tool and each release records which brand sold it that year.
- **The Core Data Model below actually describes PR #3's version** — its tree
  puts Brand *under* Casting and says outright that "a casting can be reused
  across sister brands owned by the same company". That's domain-accurate;
  tooling reuse across sister brands is common (Johnny Lightning / Auto World,
  Mini GT / Kaido House). Under the current schema those become two casting
  rows with no way to record that they're one tool, which is exactly the kind
  of near-duplicate the Comparison Agent exists to catch.

This was deliberately **not** changed here, per the "keep schema decisions and
implementation work in separate sessions" convention below — folding a schema
redesign into a merge repair would have made both unreviewable. Settle it on
its own, and if brand moves to `releases`, do it as a migration with a backfill
rather than by editing `100004`.

Verified: `migrate`, `rollback` and `re-migrate` all clean from scratch;
`migrate:fresh --seed` clean; `php artisan test` green at 39 tests, 78
assertions.

---

## Status as of 2026-08-02 — Models, admin auth, and the submission gate landed

Builds on the schema session below (whose decisions all still stand). This
session added the application layer the capture/review app needs. `php artisan
test` is green: 35 tests, 69 assertions.

**Goal driving this work**: an admin-only capture app the catalog owner uses
from an iPhone — photograph a package, let the pipeline propose records,
approve or reject them. Delivery decision: **a PWA served by this Laravel app**,
not native. The dev machine is Windows, so building/signing a native iOS app
would need a Mac or paid cloud CI. Plan is to optionally wrap it with Capacitor
later, pointed at the deployed origin — which is why auth is **session-based**
rather than tokens. A Capacitor build that loads from the live URL keeps
cookies working; only a build that bundles assets under its own scheme would
need tokens, and that's the point to add them.

### What's new

- **Eloquent models** for all 12 catalog tables plus `Submission`,
  `SubmissionPhoto`, `SubmissionProposal`. `VehicleModel` maps to `models` via
  `#[Table('models')]`. Models use Laravel 13's attribute style
  (`#[Fillable]`, `#[Scope]`) to match the existing `User` model.
- **Factories for every model** plus `AdminUserSeeder`, `CatalogSeeder`,
  `SubmissionSeeder`. `CatalogSeeder` deliberately plants near-duplicates
  (`'95 Mazda RX-7` vs `95 Mazda RX7`, plus an FC3S RX-7 that must *not* be
  merged into the FD) so the Comparison Agent has realistic ambiguity to be
  developed against.
- **Admin session auth**: login only, no registration, no password reset.
  `EnsureUserIsAdmin` (alias `admin`) returns **404, not 403**, so a non-admin
  can't discover the admin area exists. Login is throttled on email+IP together.
- **R2 disk** wired in `config/filesystems.php` (`league/flysystem-aws-s3-v3`
  installed). `throw => true` unlike the other disks — a silent upload failure
  would record a photo whose bytes never arrived.

### New decisions (settled — same rules as the schema block below)

- **`status` (pending/live/rejected)** on the 8 tables the Insertion Agent can
  write to: `makes`, `brands`, `models`, `castings`, `casting_versions`,
  `series`, `releases`, `variations`. Not on `price_histories`/`photos`
  (observations on an approved variation, not proposed entities) or the
  `user_*` tables. String + CHECK constraint, not a native enum.
- **The column defaults to `pending`, factories default to `live`.** That
  asymmetry is deliberate: the DB default is fail-safe, so an agent that
  forgets to set a status produces an invisible record rather than publishing
  unreviewed output; fixtures almost always want an established catalog, so
  tests opt into `->pending()` explicitly.
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
- **`submissions.state` vs catalog `status`** are different questions and are
  named differently on purpose: `state` is pipeline lifecycle
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

### Two environment traps that cost real time — don't reintroduce them

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

- **Fantasy castings**: `castings.model_id` is nullable. There is deliberately
  no `make_id` column on `castings` — make is reached via `model.make_id` so it
  can't drift out of sync with model_id. (Superseded in part: null `model_id`
  no longer means "no real vehicle" on its own — see the `is_fantasy` flag in
  the reconciliation block at the top.)
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
- **Before writing a migration, look at what's already in
  `database/migrations` on your branch — not just what the DB happens to have.**
  Two branches each built the whole schema once already (see the top block).
  A migration that only ever runs forward on an already-migrated dev database
  hides a collision that a fresh `migrate` would catch immediately.
- **`migrate:fresh --seed` and `php artisan test` are the gate for any schema
  change**, because neither one passes on a database that was migrated
  incrementally. Running only `php artisan migrate` proves nothing about
  whether the schema can be built from zero.

## Next Steps

*Added 2026-08-02. Migrations, models, factories and seeders all landed since;
what's left of the original list is the search layer.*

- **Meilisearch indexing** — `laravel/scout` is not in `composer.json` yet,
  so nothing is indexed and cross-brand search doesn't work end to end. Note
  that `pg_trgm` and Meilisearch are not substitutes for each other: trigram
  similarity is the Comparison Agent's DB-side duplicate check, Meilisearch
  is user-facing typo-tolerant search.

## Guide for the next session

Schema, models, factories, seeders, admin auth and the approval mechanics are
all settled and tested (see the status blocks at the top). Per the "separate
sessions" convention above, don't revisit the Casting/Model split, the
migrations, or the approval-status decisions — build on them.

Two candidates, depending on appetite:

**If you want to settle schema first**: the brand-on-casting vs brand-on-release
question in the top block. It's the last unresolved disagreement between the two
merged schemas, it gets more expensive the more catalog data exists, and the
Comparison Agent's behaviour depends on the answer. Its own session, per the
convention above.

**Otherwise, the recommended next step is the capture and review screens** — the
PWA the whole submission perspective exists for. The backing models and the
approve/reject methods are already there and tested; this is UI plus two
controllers.

- **Capture screen** (`/admin/capture`): a form posting one or more photos plus
  the optional brand/year/series hints. Use
  `<input type="file" accept="image/*" capture="environment">` — on iOS that
  opens the camera directly, and it degrades to a file picker on desktop.
  Upload to the `r2` disk, create a `Submission` with its `SubmissionPhoto`
  rows, set `state` to `queued`.
  - Uploads must be **foreground**; iOS suspends background web uploads. Show
    real progress and make a failed upload retryable rather than silent.
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

After the screens, the natural order is the AI pipeline, starting with the OCR
output schema at `/app/AI/schemas/ocr_output.json` — see
`SubmissionFactory::completed()` for the shape currently assumed. Per the
convention above, vision/OCR calls belong in `app/Services/AI/OcrAgent.php`,
not in the capture controller: the controller's job ends at storing the photo
and queueing the job.
