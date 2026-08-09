# Universal Die-Cast Database — Project Context

Auto-loaded at the start of every Claude Code session in this repo, so the
domain model, conventions and current state only have to be explained once.
**Treat this file as the source of truth, not the conversation history** —
update it whenever a convention changes.

**Last edited:** 2026-08-09 (Hot Wheels Wiki importer built and run)

## Start here

A brand-agnostic diecast catalog (Hot Wheels, Matchbox, Tomica, Mini GT, …) on
Laravel + Postgres, whose catalog grows from photographs of packaging via an AI
pipeline, gated behind admin approval.

**The data layer is complete and tested, and the catalog now has 241 real
castings in it. The public product is not started.** Read
[Current state](#current-state) before assuming any feature exists — several
plausible-sounding ones don't, including some the commit history implies.
In particular: everything imported is `pending` and therefore invisible, on
purpose.

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
   not fillable anywhere, and approval promotes the whole ancestor chain. The
   6,295 imported variations are all pending because their castings have no
   identified model — that's the blocker doing its job, not a bug.

## Index

- [Current state](#current-state) — what exists, what doesn't
- [What to do next](#what-to-do-next)
- [How to work in this repo](#how-to-work-in-this-repo) — conventions, traps
- [Domain model](#domain-model) — [Make/Model splitting](#makemodel-splitting-rules) · [field definitions](#field-definitions)
- [AI agent pipeline](#ai-agent-pipeline) — [example flow](#example-flow) · [acquisition priorities](#data-acquisition-priorities)
- [Settled decisions](#settled-decisions) — [schema](#schema) · [approval](#approval--submissions) · [capture](#capture) · [AI pipeline](#ai-pipeline) · [catalog import](#catalog-import-hot-wheels-wiki) · [review](#review) · [delivery](#delivery)
- [Open questions](#open-questions)
- [Session log](docs/session-log.md) — narrative and rationale, loaded on demand

---

## Current state

*Verified 2026-08-09 against the running stack. Keep this section honest — it's
the fastest way for a new session to avoid assuming something exists.*

**Running:** `docker compose up -d` brings up `app` (:8000), `db` (Postgres 16,
:5432), `redis` (:6379), `meilisearch` (:7700). `php artisan test` → **153
passed, 440 assertions**.

**The catalog is populated.** `catalog:import-hot-wheels --year=2011` has been
run for real against the live wiki: 241 castings, 4,284 releases, 6,295
variations. All of it is `pending` — see
[Settled decisions → Catalog import](#catalog-import-hot-wheels-wiki) for why,
and don't read the empty public catalog as a sign the import failed.

**A clean checkout has no `.env`** (it's gitignored), so the suite fails on
`MissingAppKeyException` before it reaches anything real. `cp .env.example .env
&& php artisan key:generate` is step one.

**The entire HTTP surface is nine routes** (`routes/web.php`):

| Route | State |
|---|---|
| `GET /` | Stock Laravel welcome page, untouched scaffold |
| `GET\|POST /login` | Real. Admin only — no registration, no password reset |
| `POST /logout` | Real |
| `GET /admin` | Review queue — submissions awaiting review, each linking to its review screen |
| `GET /admin/capture` | Real. Camera capture form + optional brand/year/series hints |
| `POST /admin/capture` | Real. Uploads photos, creates a `queued` submission |
| `GET /admin/submissions/{submission}` | Real. Photos + proposals, with approve/reject |
| `POST …/{submission}/proposals/{proposal}/approve` | Real. Scoped bindings |
| `POST …/{submission}/proposals/{proposal}/reject` | Real. Scoped bindings |

**Built and tested:**

- All 12 catalog tables + `submissions`, `submission_photos`,
  `submission_proposals`, `record_sources`. Migrations verified from zero
  (`migrate`, `rollback`, `re-migrate`, `migrate:fresh --seed` all clean).
- **The Hot Wheels Wiki importer** — the whole path, end to end:
  `app/Services/Import/Wikitext/` (a rowspan-aware wikitable parser),
  `app/Services/Import/HotWheelsWiki/` (`CastingPageParser`, `YearIndexParser`,
  `CatalogImporter`, `ApiPageSource`, `DumpPageSource`) and
  `catalog:import-hot-wheels`. Parser tests run on wikitext captured verbatim
  from the wiki in `tests/Fixtures/hotwheels-wiki/`, not on wikitext written to
  suit the parser — that distinction found four separate classes of dropped row
  and is worth preserving.
- Eloquent models for every table, factories for every model, three seeders
  (`AdminUserSeeder`, `CatalogSeeder`, `SubmissionSeeder`).
- `pg_trgm` enabled with GIN trigram indexes.
- Admin session auth + `EnsureUserIsAdmin` (alias `admin`).
- Approval mechanics: `promoteWithDependencies()`, `SubmissionProposal::approve()`
  / `reject()`, `SubmissionPhoto::promoteTo()`. Tested, but no UI calls them.
- **The capture screen** (`CaptureController`, `StoreSubmissionRequest`,
  `resources/views/admin/capture.blade.php`, the enhancement layer in
  `resources/js/app.js`). Named photo slots (card front / card back / loose),
  hint fields, foreground XHR upload with progress and retry, orphan cleanup if
  storage or the DB write fails. Works as a plain form POST with JS disabled.
  `config('catalog.capture.disk')` chooses the target disk — set
  `CAPTURE_DISK=local` to use it before R2 credentials exist.
- **The review screen** (`SubmissionReviewController`,
  `resources/views/admin/submissions/show.blade.php`, `ProposalPresenter`).
  Photos beside proposals, each with its confidence, what else approving would
  publish, and approve/reject wired to the existing model methods. Plain form
  POSTs — no JS involved.
- **The OCR Agent** (`app/AI/schemas/ocr_output.json`,
  `app/Services/AI/OcrAgent.php`, `app/Jobs/ReadSubmissionPhotos.php`). Capture
  dispatches the job; it reads the packaging with a Claude vision call and
  writes the result to `submissions.ocr_output`. Tests fake the SDK's PSR-18
  transporter, so they assert the real request body without an API key.
- **The PWA shell**: `public/manifest.json`, `public/sw.js`,
  `public/offline.html`, the icon set under `public/icons/` plus
  `apple-touch-icon.png`, and registration in `resources/js/app.js`. The layout
  is now a real HTML document (it used to start at `<meta name="viewport">`, so
  every page rendered in quirks mode).

**Not built — do not assume these exist:**

- **Only the first of the seven agents exists.** The OCR Agent reads packaging
  text; Metadata, Search, Comparison, Insertion and Review do not exist, so a
  completed submission has a reading and **no proposals**. Nothing populates the
  review queue automatically yet — `SubmissionSeeder` is still what puts
  proposals in it.
- **The dump has never been read for real.** `DumpPageSource` is tested against
  an archive built by the same 7-Zip binary, but every real run so far has gone
  through `ApiPageSource`. Going wide (11,041 pages) should use `--dump=` and is
  the first thing that will exercise it at size.

- **No Meilisearch integration.** The container runs, but `laravel/scout` is not
  in `composer.json`, so nothing is indexed and cross-brand search doesn't work
  end to end. Note `pg_trgm` and Meilisearch are not substitutes: trigram
  similarity is the Comparison Agent's DB-side duplicate check, Meilisearch is
  user-facing typo-tolerant search.
- **R2 is fully working**, verified end to end on 2026-08-09 against the live
  `collectai-photos` bucket: the capture endpoint stored a 150,000-byte photo,
  and fetching `Photo::url()` over `img.collectai.club` returned identical bytes
  (md5 match) with `content-type: image/jpeg` preserved. The bucket root returns
  404, so object listing isn't exposed. `php artisan capture:check-storage`
  re-runs the whole round trip — write, read, public fetch, delete.
- **No Make/Model inference, so the imported catalog is invisible.** The
  importer creates castings *unidentified* — the wiki never states which real
  vehicle a casting is based on — and `Casting::approvalBlocker()` refuses to
  publish one of those, so every imported release and variation is pending under
  a pending casting. Resolving that is the next agent's job, and
  `submission_proposals.submission_id` was made nullable to give it somewhere to
  put its proposals. `catalog.import.auto_approve_threshold` (0.9) exists for it
  and **is not read by anything yet**.
- **No public catalog browsing, collections, wishlists, price charts, or
  barcode scanning.** The tables exist; nothing reads them.
- Nothing creates a `CastingVersion` automatically alongside a `Casting`.
  `CatalogSeeder::casting()` and `CatalogImporter::castingVersions()` both do it
  explicitly. This is deliberate: a model `created` hook would collide with
  `CastingVersionFactory`, which builds a casting and its version separately.
  Whoever writes the Insertion Agent must create version 1 explicitly.

## What to do next

Schema, models, factories, seeders, admin auth and the approval mechanics are
all settled and tested. Per the separate-sessions convention, don't revisit the
Casting/Model split, the migrations, or the approval-status decisions — build on
them.

**The capture/review milestone is complete as of 2026-08-09** — capture, review
and the PWA shell all landed. The admin loop closes: photograph a car, rule on
what the pipeline proposed, watch it go live. Nothing produces proposals
automatically yet, so `SubmissionSeeder` is still what puts anything in the
queue.

**The importer landed on 2026-08-09** and 2011 has been imported for real, so
Comparison finally has a catalog to diff against.

**The obvious next moves:**

- **Make/Model inference — the one thing blocking everything imported.** 241
  castings are sitting `pending` and unidentified, and each one blocks its own
  releases and variations from ever going live. This is the Metadata Agent's
  work: read `'72 Ford Gran Torino Sport` and produce Make: Ford, Model: Gran
  Torino Sport, with a confidence. Above `catalog.import.auto_approve_threshold`
  it attaches; below it, the casting stays pending as a backfill queue. The
  plumbing is in place — `submission_proposals.submission_id` is nullable now —
  but nothing reads that threshold yet, and there is no review screen for a
  proposal with no submission behind it.
- **Deploy to the apex.** `collectai.club` is registered and delegated to
  Cloudflare but nothing is deployed, so the capture screen has never run on a
  phone — which is the only place it's meant to run. The app is now installable
  the moment it's served over HTTPS.
- **Import more years, and go wide via the dump.** `--year=` takes any year
  1968–2027. The full run should use `--dump=` rather than the API: p7zip is in
  the image and `DumpPageSource` streams it, but the dump has only ever been
  read in tests, not against the real 16.5 MB archive.
- **Then the rest of the AI pipeline.** The OCR Agent landed on 2026-08-09, so a
  capture produces a reading. **Comparison** (deterministic `pg_trgm` lookup — no
  LLM call) and **Insertion** (create pending records + `submission_proposals`)
  turn that into proposals. Until those exist the review queue only fills from
  `SubmissionSeeder`. Whoever writes Insertion must create `CastingVersion` 1
  explicitly — nothing does it automatically, see below; `CatalogImporter` is
  the worked example.
- **Prove the OCR agent against the live API.** It has never run for real —
  there's no `ANTHROPIC_API_KEY` set, and every test to date uses a faked PSR-18
  transport. The one genuinely unverified assumption is that the API's
  structured-output subset accepts `"type": ["string", "null"]`; if it doesn't,
  that's a 400 at schema compilation and a switch to `anyOf`. Cheap to check,
  and everything downstream is built on that schema.

Note the layout lives at `resources/views/components/layouts/app.blade.php`
(anonymous Blade components resolve under `views/components/`), and the base
`TestCase` calls `withoutVite()` so view tests don't need a frontend build.

**Further out, still untouched:** Meilisearch indexing (`laravel/scout` isn't
installed), the public catalog, collections/wishlists, price charts, barcode
scanning. `/` is still the stock welcome page and is the one screen not using
the app layout, so it carries no manifest link — install works from `/login`
and `/admin/*`. Worth folding in whenever the public catalog replaces it.

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

- **`p7zip` is in the Dockerfile for the wiki dump.** `DumpPageSource` shells out
  to `7z` and streams the archive; there is no usable pure-PHP 7-Zip reader. If
  the image is ever slimmed, this is not a stray dependency.

- **The app container has no GD**, so `UploadedFile::fake()->image()` throws.
  `CaptureTest` fakes photos with `->create($name, $kb, 'image/jpeg')` instead,
  which sets the MIME type the `mimetypes` rule reads. Don't "fix" those tests
  by switching to `->image()` — that adds a PHP extension to make a test pass
  rather than to make the app work. Revisit only if server-side thumbnailing
  lands.

Also note: `env('SOME_VAR')` returns `''`, not `null`, for a bare `VAR=` line in
`.env`. That produced an admin account whose password was the empty string and
which authenticated against it. `config/catalog.php` normalizes with `?: null`
and `AdminUserSeeder` uses `blank()`; `AdminUserSeederTest` guards it.

- **`.env.example` is tracked; `.env` is not.** Real credentials go in `.env`
  only. A secret pasted into `.env.example` is one `git add` away from being
  permanent, and deleting it in a later commit doesn't remove it from history.
  Same rule as the admin password below, for the same reason.

**Where the admin password lives:** `ADMIN_PASSWORD` in `.env`, which is
gitignored. Set it there and it survives reseeds; leave it blank and
`AdminUserSeeder` generates a fresh one per seed and prints it exactly once, so
any value written down elsewhere is stale the next time anyone runs
`migrate:fresh --seed`. Don't paste it into this file — CLAUDE.md is tracked and
pushed, and git history keeps a committed secret whether or not it's later
deleted. The seeder is idempotent and won't reset an existing admin, so changing
the password means deleting the `admin@example.test` row and re-running
`db:seed --class=AdminUserSeeder`.

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

**Agent 2 of 7 is written** — see [Current state](#current-state). The tables the
rest write into, and the approve/reject mechanics they feed, are built and
tested.

Order matters; later agents depend on earlier output. Only the first three
should call the Claude API directly. Everything from Comparison onward should be
deterministic application code, not LLM calls — cheaper, faster and more
reliable for DB matching.

1. **Image Agent** — reads card/package photos.
2. **OCR Agent** — **built.** Extracts brand, year, series, casting name,
   collector number and toy number, plus a confidence score per field. The
   schema at `app/AI/schemas/ocr_output.json` is sent to the model as the
   structured-output format *and* is the contract for `submissions.ocr_output`,
   so the prompt and the stored shape cannot drift.
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
  **`toy_number` and `country` are part of that identity**, not description:
  measured against 1,183 real wiki rows, 11 collided without them and 5 with.
  Keep `Variation::DISTINGUISHING_ATTRIBUTES` and the index in step — the model's
  `attributeSignature()` is what application-side duplicate checks compare, and
  if the two drift a check passes and the insert then fails.
- **`variations.tampo` is `text`, not `varchar`.** Describing printed graphics
  is prose-shaped in a way the other attributes aren't — a convention car runs to
  328 characters. Truncating it would not just lose text: tampo is in the
  uniqueness index, so a cut-off description changes which cars count as the same
  car.
- **`submission_proposals.submission_id` is nullable.** A proposal is a record
  the pipeline produced; a capture session behind it is optional, because the
  importer's inferences have none. Don't "fix" it back — the alternative was a
  synthetic photo-less Submission per import run, which makes `submissions` mean
  two things and puts rows in the capture app's list that nobody captured.
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
  `import.auto_approve_threshold` is a separate dial at **0.9**, governing only
  the importer's inferences; the 1.0 above is not up for tuning with it.

### Capture

*The four calls the capture screen turns on. Landed 2026-08-09; the reasoning is
in the [session log](docs/session-log.md).*

- **Named photo slots, not one `photos[]` array.** Card front / card back /
  loose, so each upload arrives already typed. `submission_photos.type` is
  deliberately free text — which angles exist is brand-dependent — and that
  migration's comment says the shortlist belongs in the UI layer. This is that.
  A brand that packages differently gets new slots in the Blade file, not a
  migration.
- **`capture="environment"` on the two card slots only.** On iOS it opens the
  rear camera directly, which is what you want with the car in your other hand
  — but it also *removes* the library option and beats `multiple`. Several
  angles of a loose car is the normal case, so that slot keeps `multiple` and
  drops `capture`; the iOS picker still offers Take Photo, so nothing is lost.
  Don't "fix" the inconsistency by adding `capture` back to the loose slot.
- **Orphan cleanup is the controller's job.** `throw => true` on the r2 disk is
  what stops a photo row existing for bytes that never arrived — but it also
  means a failure partway through strands the uploads that already succeeded,
  and nothing else will ever know they're there. `CaptureController::store()`
  deletes what it stored before re-throwing, and `CaptureTest` forces a
  mid-transaction failure to assert the bucket comes back empty. Any future
  endpoint that writes to a disk before writing rows owes the same cleanup.
- **`config/catalog.php` owns `capture.disk`,** rather than the `r2` disk being
  hard-coded at the call site. `submission_photos.disk` already records the
  choice per row for exactly this reason, so `CAPTURE_DISK=local` exercises the
  whole path before Cloudflare credentials exist and photos captured that way
  keep resolving after the switch.
- **JSON responses are opted into per endpoint, never globally.**
  `bootstrap/app.php` renders exceptions as JSON only for `api/*`, so every HTML
  form keeps redirect-with-errors regardless of what Accept header a browser
  sends. The capture upload is the one genuine XHR endpoint, and an HTML
  redirect body is no use to a progress bar — so it attaches an explicit 422 in
  `StoreSubmissionRequest::failedValidation()`. Widen that global rule instead
  and login's error handling changes with it.

### AI pipeline

*Settled while building the OCR Agent on 2026-08-09; reasoning in the
[session log](docs/session-log.md).*

- **`app/AI/schemas/ocr_output.json` is one file used twice** — sent to the model
  as the structured-output schema, and the contract for what lands in
  `submissions.ocr_output`. That is the whole point: a separate "what we send"
  and "what we store" is how the prompt and the stored shape drift. Two rules
  follow. It must stay inside the API's supported JSON Schema subset (no
  `minimum`/`maximum`, no `minLength`, no recursion, `additionalProperties:
  false` on every object), which is why the 0-to-1 bound on confidence is prose
  plus a PHP check. And **every field is required with `null` allowed** — an
  omitted key and a field the model couldn't read are different claims.
- **Bump `schema_version` rather than editing v1 in place** once real output
  exists to migrate. `OcrAgent::FIELDS` and `SCHEMA_VERSION` mirror the file and
  a test asserts they agree, so a one-sided edit fails rather than silently
  dropping a field.
- **The OCR Agent transcribes; it does not normalise.** It reports what is
  printed, keeping spelling and abbreviations, because resolving "HW" to "Hot
  Wheels" is the Metadata Agent's job and it can only do that if it sees what
  the card said. Capture hints are passed as context, explicitly not as answers
  — they're typed from memory with a car in one hand.
- **Confidence is per field, not per read.** A card can carry a huge logo and a
  four-point toy number; one score tells a reviewer to check everything or
  nothing.
- **Vision accepts JPEG/PNG/WebP/GIF — not the HEIC the capture screen allows.**
  `OcrAgent` maps each stored photo's extension to a media type and skips what
  it can't send. Don't "fix" this by rejecting HEIC at capture: iOS doesn't
  reliably transcode, and a rejected photo of a car already back in its case is
  a reshoot. Converting isn't available either — the container has no GD.
- **Card photos get the photo budget first** (`catalog.ocr.max_photos`). Photos
  are base64-encoded into the request body, so eight 12 MB images is a ~130 MB
  request. Loose shots still qualify — a Hot Wheels base is printed with the
  casting name, year and toy number.
- **Check `stop_reason` before reading `content`.** A refused request is an HTTP
  200 with empty or partial content, so indexing `content[0]` first turns a
  refusal into a type error instead of a message an admin can act on.
- **`fallbacks: "default"`, never a pinned model.** The right substitute depends
  on the refusal category, and naming one is a migration owed the day it's
  deprecated. This is why the call uses `beta->messages->create` — the parameter
  only exists there. The scalar form is gated by
  `server-side-fallback-2026-07-01`; the *array* form is gated by a different,
  earlier-dated header, and pairing either header with the other form is a 400,
  so don't "fix" them into looking consistent. `OCR_FALLBACKS` turns it off:
  it's Claude-API-only and eligible substitutes are per-model.
- **Tests fake the SDK's PSR-18 transporter, never the client.** A mocked client
  only proves `OcrAgent` called the method it was written to call; faking
  transport means the SDK really serialises the request, so the tests assert the
  actual JSON body — and catch a named argument that doesn't reach the wire.
- **`Queue::fake()` is load-bearing in `CaptureTest`.** The suite runs the
  `sync` driver, so without it the capture endpoint's dispatch runs the vision
  call inline and a file-upload test makes a live API request.
- **Tune `OCR_EFFORT` before `OCR_MODEL`.** A misread becomes a catalog row an
  admin has to notice is wrong; that review time dwarfs the token difference.
  `OCR_MAX_TOKENS` covers thinking as well as the JSON — thinking is on by
  default on Opus 5, and a limit sized for the answer alone truncates mid-read.

### Catalog import (Hot Wheels Wiki)

*Settled 2026-08-09 as a decisions-only session — **no importer code or
migrations exist yet**. Verified live against the wiki the same day. Reasoning
in the [session log](docs/session-log.md).*

The catalog can't grow to useful coverage from photographs alone, so the bulk of
it comes from [hotwheels.fandom.com](https://hotwheels.fandom.com) and the
capture pipeline adds what the wiki lacks. This also gives the Comparison Agent
a populated DB to diff against — without it, duplicate detection is being built
against an empty table.

- **Use the dump, not a crawler.** `https://s3.amazonaws.com/wikia_xml_dumps/h/
  ho/hotwheels_pages_current.xml.7z` — 16.5 MB, current revisions, last modified
  2026-03-13. The API (MediaWiki 1.43.9 at `/api.php`) is only for the delta
  since the dump's `Last-Modified`, via revision timestamps. That's 1 download
  plus a small delta instead of 11,041 requests. Re-check `Last-Modified` before
  each run; if the dump has moved on, re-pull rather than widening the delta.
- **Import facts, never prose.** The wiki is CC-BY-SA. Individual facts (year,
  colour, wheel type, toy number) aren't copyrightable; article prose and the
  `Notes` column are. Import only the structured wikitable columns — **do not
  import `Notes`, article text, or the 165,987 images.** This is also what keeps
  the result an original database rather than a mirror, which is
  [priority 1](#data-acquisition-priorities). Attribution rides on
  `record_sources.source_url` and should surface wherever the public catalog
  shows an imported row.
- **Provenance is a polymorphic `record_sources` table**, not columns on eight
  catalog tables — same call, and same reason, as `submission_proposals`: audit
  metadata doesn't belong in the middle of the domain model, and "everything
  from revision N" / "everything stale" should be one indexed read. Carries
  `sourceable_type`, `sourceable_id`, `source`, `source_key`, `source_url`,
  `source_revision_id`, `imported_at`. Unique on `(source, source_key)` — that
  constraint is what makes re-import idempotent — and on
  `(sourceable_type, sourceable_id)`, one provenance row per record. `source_key`
  must be unique per *record*, not per page: a casting article yields one
  casting and many releases, so key them `page:12345` and `page:12345:row:7`.
- **The gate is provenance, not table.** Columns the wiki states go live through
  the importer. The Make/Model split and `is_fantasy` are **not** in the wiki and
  are an LLM inference, so they go through `submission_proposals` like any other
  inferred field.
- **The importer gets its own auto-approve threshold**, separate from
  `catalog.review.auto_approve_threshold`, which **stays 1.0**. Nothing a person
  photographed auto-publishes; that invariant is untouched. The two paths have
  genuinely different risk profiles and are tuned independently.
- **An unresolved casting stays `pending` — it never goes live unattributed.**
  This is the existing `Casting::approvalBlocker()` doing exactly the job it was
  added for: a real casting with a null `model_id` can't be approved, so
  low-confidence inferences leave a backfill queue of invisible rows rather than
  11k review items or a catalog full of cars nobody can search for.
- **Start with `List of 2011 Hot Wheels`**, the year in this file's worked
  example, so the parser is validated against a case the fixtures already cover
  before anything six-figure runs.
- **The importer must create `CastingVersion` 1 explicitly** — nothing does it
  automatically (see [Current state](#current-state)). It is the first bulk
  writer, so it is the first place this bites.
- **Single-brand by construction.** This wiki is Hot Wheels only, so every
  imported `series.brand_id` is Hot Wheels. Other brands need other sources —
  and needing zero schema changes to add them is the point of the design.

**Built and run on 2026-08-09.** The rules below came out of running it over
real pages; the narrative is in the [session log](docs/session-log.md).

- **A year index is a work list of castings, not a filter on years.** Importing
  `List of 2011 Hot Wheels` imports each casting's *whole* Versions table, 1968
  onwards — which is why 241 castings produced 1,856 series. The casting page is
  the unit of truth, and importing half of one would leave its provenance
  claiming the page was done.
- **Test the parser on captured wikitext, never on wikitext written to suit
  it.** `tests/Fixtures/hotwheels-wiki/` holds real pages with their source URL,
  page id, revision and licence in a header comment, prose sections stubbed out.
  Writing fixtures by hand only exercises the shapes already known about; the
  sixty-page sample that validated this found four separate classes of silently
  dropped row.
- **Duplicate variations are detected before the insert, never caught after
  it.** Postgres aborts the whole transaction on a failed statement, so catching
  the constraint violation and carrying on takes the rest of the page down with
  it. Any future bulk writer owes the same.
- **Provenance keys use raw titles, not `Str::slug()`.** Slugging collapses
  "Muscle Mania (2012)" and "Muscle Mania 2012" into one key, and the importer
  would overwrite one series with the other.
- **Imported castings are unidentified, never fantasy.** The wiki has no
  structured field for the real vehicle, and `is_fantasy` is a positive assertion
  that there is no real car. So the import publishes brand and series and nothing
  else — see [What to do next](#what-to-do-next).

**Parsing notes** (verified 2026-08-09 against real pages): casting pages carry a
`{{casting}}` infobox and a `==Versions==` wikitable with columns *Col # · Year ·
Series · Color · Tampo · Base Color/Type · Window Color · Interior Color · Wheel
Type · Toy # · Country · Notes · Photo*. Year indexes exist as
`List of YYYY Hot Wheels`, 1968–2027. `Col #` arrives as `032/244` and Series as
`[[2011 New Models]]<br>32/50` — both need splitting, and Series being a wiki-link
means series identity is resolvable rather than a bare string. What the tables
*also* do, all of it handled and all of it load-bearing:

- **`rowspan`, heavily.** A row can carry five cells where the header declares
  thirteen, inheriting the rest from above. Zipping cells against headers puts a
  toy number in the colour column for exactly the interesting variants.
- **Header names move** (`Notes` vs `Notes / Variations`, plus one-off columns
  like `Clip Color`), so columns are matched by name and unrecognised ones are
  dropped — which is also what keeps `Notes` and `Photo` out.
- **A `|-` before the header row**, on ~9% of pages. Treating "before the first
  row separator" as the header row leaves the table with no column names and
  every field null.
- **No `==Versions==` heading at all.** `'57 Chevy` uses "Closed Hood Versions"
  and "Exposed Engine Versions" — two physical tools, so a `CastingVersion` each.
  `[[Code 3]] Versions` is excluded: a third-party repaint is not a release.
- **Redirects**, ~5% of index links, which parse as a page with no versions.
- **Blank collector numbers are real data**, not a parse failure — 8% in 2011,
  past 60% in later years.

**The licence's real cost, worth knowing up front:** ~1.8% of rows are
indistinguishable from another row using only the columns we're allowed to
import. Some are genuine duplicate listings; others are real sub-variants whose
only distinguishing fact is in the `Notes` prose ("short-run small Goodyear
tampo on rear wheels"). The catalog records the car it can describe rather than
inventing a distinction from a field it never read.

### Review

*The calls the review screen turns on. Landed 2026-08-09; the reasoning is in
the [session log](docs/session-log.md).*

- **Approving rules on the ancestors' proposals too.** The walk publishes
  parents; `SubmissionProposal::approve()` also marks *their* proposal rows
  approved, with the same admin and timestamp. Otherwise a submission goes live
  and stays in the queue offering Approve buttons for records that are already
  public. Rejection still doesn't cascade — that asymmetry is deliberate.
- **An unidentified casting cannot be approved.** `approvalBlocker()` on
  `HasApprovalStatus` (null by default, overridden by `Casting`) is the
  enforcement of the long-standing `is_fantasy` rule: a real casting with a null
  `model_id` raises `ApprovalBlockedException` from `approve()`, and
  `approvalBlockers()` checks the whole chain, so a blocked *ancestor* stops a
  descendant too. The guard is in the domain, not the controller, so the
  eventual bulk auto-approve inherits it; the screen also disables the button
  and says why.
- **A blocker is not the same as an unapproved parent.** An unapproved parent is
  fixed by promoting it, which the walk does by itself. A blocker is something
  no amount of approving resolves — a human has to correct the record first.
- **Photo URLs go through `ResolvesStoredUrl`**, shared by `Photo` and
  `SubmissionPhoto`. Promotion doesn't move bytes, so the two must resolve
  identically or a photo would change address on approval. It signs **only for
  private local disks**, where Laravel's file server rejects unsigned requests —
  that's why `CAPTURE_DISK=local` rendered every photo as a 403 until now. Do
  not extend signing to R2: that bucket is world-readable behind
  `img.collectai.club`, and a presigned R2 URL points at the S3 API endpoint,
  which isn't publicly reachable at all.
- **Presenting a record to a human is `ProposalPresenter`, not a model method.**
  The eight catalog models are the pipeline's write target; how a row reads to a
  person belongs to the screen reading it, and the public catalog will want a
  different summary of the same Release.
- **The screen shows what else an approval publishes**
  (`pendingDependencies()`), because one click can make six records public.
- **The service worker never caches HTML and never touches non-GET requests.**
  Only content-hashed `/build/` output, the icons and one offline page. A stale
  review queue is worse than a slow one, and a worker that retried a POST would
  duplicate captures and apply approvals out of order. `PwaTest` guards both.
- **The layout is a full HTML document.** It used to begin at
  `<meta name="viewport">` — no doctype, so every page rendered in quirks mode —
  which also left `<link rel="manifest">` nowhere valid to sit. Don't strip it
  back to a fragment.

### Delivery

- **A PWA served by this Laravel app**, not native. The original reason (the dev
  machine was Windows, so signing an iOS build needed a Mac or paid CI) no
  longer applies — development moved to macOS in 2026-08. The decision stands on
  a different footing now: **the app has to run on both iPhone and Android**, and
  one web codebase is the only option here that does that without writing the
  client twice. Plan is to optionally wrap it with Capacitor, pointed at the
  deployed origin — which is why auth is **session-based** rather than tokens. A
  Capacitor build that loads from the live URL keeps cookies working; only a
  build that bundles assets under its own scheme would need tokens, and that's
  the point to add them.
- **Swift is not a cross-platform answer.** SwiftUI is Apple-only, and Swift on
  Android has no production UI story — choosing Swift means writing the Android
  client separately in Kotlin, i.e. two clients, two release processes, for an
  app whose entire job is a camera form and a review list. If native ever
  becomes genuinely necessary (background uploads, better camera control),
  reach for one cross-platform toolkit rather than Swift alone.
- **Admin auth returns 404, not 403** for non-admins, so they can't discover the
  admin area exists. Login is throttled on email+IP together.
- **The domain is `collectai.club`** — registered at Porkbun, DNS delegated to
  Cloudflare (2026-08-09). The app itself isn't deployed anywhere yet; the apex
  is deliberately still free for it. `img.collectai.club` is a custom domain on
  the `collectai-photos` R2 bucket, chosen over the bucket's `r2.dev` subdomain
  because `r2.dev` is rate-limited and explicitly not for production — and
  because photo URLs outlive whatever hostname first served them.
- **Photos in the bucket are world-readable**, including submission captures
  that haven't been approved. `SubmissionPhoto::promoteTo()` references the same
  stored object rather than re-uploading, so capture and catalog photos share
  one bucket by design. Paths are unguessable (a ULID prefix plus a random
  filename) and the bucket root 404s rather than listing, so this is obscurity
  rather than access control. Fine for a public catalog; revisit before storing
  anything that isn't destined to be public anyway.
- **Enhancement, not dependency.** The capture form is a working multipart POST
  on its own; `resources/js/app.js` only adds thumbnails, progress and retry.
  Feature tests exercise the no-JS path, so a broken bundle degrades instead of
  taking the capture app down — worth preserving on the review screen too.

## Open questions

*None blocking.* The brand-placement disagreement that sat here was settled on
2026-08-03 — see [Settled decisions → Schema](#schema).

---

Narrative, rationale and the record of what happened when live in
**[docs/session-log.md](docs/session-log.md)**. When you finish a session, add an
entry there and promote anything that became a durable rule up into this file.
