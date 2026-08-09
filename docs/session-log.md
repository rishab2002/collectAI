# Session log

Newest first. This is the narrative record: what happened in a session, and
**why** a decision went the way it did.

It lives outside `CLAUDE.md` deliberately. `CLAUDE.md` is auto-loaded into every
Claude Code session and should stay short enough to actually be read; this file
is loaded on demand, when someone needs the reasoning behind a rule rather than
the rule itself.

**When you finish a session:** add an entry here, and promote anything that
became a durable rule up into `CLAUDE.md`'s *Settled decisions* or *How to work
in this repo*. A rule belongs in `CLAUDE.md`; the argument for it belongs here.

---

## 2026-08-09 — R2 credentials live, uploads proven

`collectai-photos` bucket created, scoped API token in `.env`. Added
`php artisan capture:check-storage`, which round-trips a throwaway object
through the configured capture disk and separately checks the public URL — "the
disk is configured" and "the disk works" are different claims, and a private
bucket writes fine and then serves 401s to every photo in the catalog.

Verified by driving the real capture endpoint against the live bucket with two
genuine payloads (200,000 and 120,000 bytes), which landed under one ULID prefix
and were deleted again; the bucket is empty. Note `UploadedFile::fake()->create()`
reports a size without writing content, so the first attempt uploaded two 0-byte
objects and looked like a pass — real bytes matter for this check. The `mimetypes`
rule also sniffs content on a real upload, so the padding needs a genuine JPEG
header.

**Public reads now work too.** Nameservers moved from Porkbun to Cloudflare the
same day and `img.collectai.club` was connected to the bucket. Verified by
capturing a 150,000-byte photo through the real endpoint and fetching
`Photo::url()` back over the custom domain: identical bytes (md5 match),
`content-type: image/jpeg` preserved, Cloudflare-terminated TLS. The bucket root
returns 404, so object listing isn't exposed.

Worth recording: enabling public access makes **submission photos public too**,
not just approved catalog ones — they share a bucket because
`SubmissionPhoto::promoteTo()` references the same stored object rather than
re-uploading. Paths are unguessable (ULID prefix + random filename), but that is
obscurity, not access control. Acceptable for a catalog whose photos are all
destined to be public; not acceptable if that ever stops being true.

**Credentials were initially pasted into `.env.example`, which is tracked.**
Caught before any commit; the values moved to `.env` and the example went back
to placeholders. `R2_ENDPOINT` and `R2_URL` had also been swapped — the
`r2.cloudflarestorage.com` host is the S3 API endpoint for uploads, not the
public read URL. Rule promoted into `CLAUDE.md`.

**Delivery: the PWA decision holds, on new grounds.** Its original rationale
(Windows dev machine, no way to sign an iOS build) expired when development
moved to macOS. It survives because the requirement is now explicitly *iPhone
and Android*, and Swift doesn't cross that line — SwiftUI is Apple-only and
Swift-on-Android has no production UI story, so Swift means two clients for what
is a camera form and a review list.

## 2026-08-09 — Capture screen landed

The first half of the PWA milestone. `php artisan test` green: **55 tests, 137
assertions** (was 41/81). Scope was the capture screen only — the review screen,
the manifest and the service worker are still ahead.

`GET|POST /admin/capture` → `CaptureController` + `StoreSubmissionRequest`,
writing a `Submission` (state `queued`) and its `SubmissionPhoto` rows.

- **Named photo slots, not one `photos[]` array.** Card front / card back /
  loose, so each upload arrives already typed. `submission_photos.type` is free
  text on purpose — which angles exist is brand-dependent — so the shortlist
  lives in the UI layer, which is what that migration's comment asked for.
- **`capture="environment"` on the two card slots only.** It opens the rear
  camera directly on iOS, but it also *removes* the library option and beats
  `multiple`. Several angles of a loose car is the normal case, so that slot
  drops `capture` and keeps `multiple`; the iOS picker still offers Take Photo,
  so nothing is lost.
- **Uploads are a foreground XHR, and the form works without JS.** The plain
  multipart POST is the path the feature tests exercise and the fallback if the
  bundle never loads; `resources/js/app.js` upgrades it with thumbnails, a real
  progress bar and a retry that re-sends the files still sitting in the inputs.
  The bar deliberately switches to "Storing photos…" at 100% rather than
  claiming success — the bytes have left the phone but R2 can still fail, and
  iOS suspends a backgrounded upload either way.
- **Orphan cleanup on failure.** The `r2` disk has `throw => true`, which is
  what stops a photo row existing for bytes that never arrived; the cost is that
  a failure partway through strands earlier uploads that nothing will ever
  reference. The controller deletes what it stored before re-throwing, and a
  test forces a mid-transaction failure to prove the bucket comes back empty.
- **`config/catalog.php` gained `capture.disk`**, defaulting to `r2` but
  settable to `local` via `CAPTURE_DISK`. R2 credentials are still empty
  placeholders, and a capture screen that can't be run until they exist is a
  capture screen nobody reviews. `submission_photos.disk` records the choice per
  row, so anything captured locally keeps resolving after the switch.
- **JSON validation errors are opted into per-request, not globally.**
  `bootstrap/app.php` restricts JSON exception rendering to `api/*` on purpose,
  so the HTML forms always get redirect-with-errors. The capture upload is the
  one endpoint that genuinely posts by XHR, so `StoreSubmissionRequest`
  overrides `failedValidation()` to attach an explicit 422 response rather than
  widening the global rule and changing how login behaves.
- **Hints are trimmed to null.** An empty text input posts `''`, and `''` is a
  value the pipeline would go looking for a brand named `""` with — the same
  class of bug as the empty-string admin password. `hint_year` is also range
  checked in validation, so a typo is a form error rather than a 500 from
  `submissions_hint_year_range`.

Two environment notes that cost time and aren't visible in the code:

- **The app container has no GD.** `UploadedFile::fake()->image()` throws
  there, so `CaptureTest` fakes photos with `->create($name, $kb, 'image/jpeg')`
  instead. Nothing in this feature decodes an image, so adding a PHP extension
  purely to make a test pass would have been the wrong trade — revisit if
  server-side thumbnailing ever lands.
- **A clean checkout has no `.env`,** so the whole suite fails on
  `MissingAppKeyException` before it fails on anything real. `cp .env.example
  .env && php artisan key:generate` first.

## 2026-08-03 — `castings.brand_id` dropped

Implementation of the decision below, in its own session per the
separate-sessions convention. `2026_08_03_100000_drop_brand_id_from_castings_table`
removes the column and its index; the tree in `CLAUDE.md` and the schema now
agree for the first time.

Call sites updated: `Casting::brand()` removed and `approvalDependencies()`
narrowed to `[$this->model]`; `Brand::castings()` removed (replaced by a comment
explaining why it can't exist and what to build instead); `CastingFactory`,
`CatalogSeeder` and `SubmissionSeeder` no longer set it; `ApprovalWorkflowTest`
now asserts the brand goes live via `release → series → brand` rather than via
the casting.

Two new guards in `CatalogSchemaTest`, both aimed at the same failure mode —
someone later reading a cross-brand release as a bug and "fixing" it:
`test_one_tooling_can_be_released_under_more_than_one_brand` asserts shared
tooling stays expressible, and
`test_the_brand_that_sold_a_release_is_reached_through_its_series` asserts the
column has not come back.

The migration's `down()` restores the column **nullable**, not non-null as it
originally was, and backfills best-effort from the brand of the earliest series
each tooling was released under. That's the closest reconstruction the surviving
data supports: a casting with no releases has no recoverable brand. Verified on
the seeded catalog — the two castings that have releases recover Hot Wheels, the
other seven come back null.

Verified: `migrate`, `rollback`, `re-migrate` and `migrate:fresh --seed` all
clean; `php artisan test` green at 41 tests, 81 assertions (was 39/78).

## 2026-08-03 — Brand placement settled: it belongs on the Release

Schema decision only, per the separate-sessions convention — the migration
landed separately, in the session logged above.

**The framing in `CLAUDE.md` was wrong, and that's why it stayed open.** It posed
the question as "brand on `castings` or brand on `releases`", implying a move
with a backfill. But brand was *already* on the release path the whole time:
`series.brand_id` exists and `releases.series_id` is non-nullable, so every
release has always resolved to a brand via `release → series → brand`. PR #3's
position never required adding anything to `releases`. It required **deleting**
`castings.brand_id`.

So the schema had been carrying brand as two independent assertions —
`release → castingVersion → casting → brand_id` and `release → series → brand_id`
— with nothing reconciling them and no defined precedence. Verified live against
the dev database: a release joining the Hot Wheels `'11 Honda Civic` tooling to a
Matchbox series inserted cleanly.

**The owner's ruling: a cross-brand release is legal and should stay legal.**
That resolves it. If a Matchbox-series release of a Hot Wheels-tooled casting is
valid data, `castings.brand_id` cannot mean "the brand that sells this" — the
release path can legitimately contradict it. It isn't a constraint, it's a second
opinion, and the one the pipeline would trust is the series.

Consequences worth keeping in mind:

- The `castings.brand_id` index existed to answer "browse all Hot Wheels
  castings" cheaply. Under this ruling the question a user actually means is
  "castings Hot Wheels has *sold*", which is the release path either way — so
  the index was answering the wrong question quickly. Expect to need
  denormalisation or Meilisearch for that browse, not a column.
- Shared tooling becomes representable as one casting row with releases under
  each brand's series, instead of two unlinked rows that the Comparison Agent
  would flag as near-duplicates forever with no way to record the resolution.
- `castings.name` is the canonical tool name. Where a shared tool is marketed
  under different names per brand, that's a release-level fact — add a nullable
  `releases.card_name` when a real case turns up, not now.

Considered and rejected: keeping the column demoted to a nullable
`originating_brand_id` for tooling provenance. It reads like authoritative brand
and would get misused as one, it's frequently unknown or disputed for older
tooling, and it's additive — cheap to introduce later if provenance turns out to
matter, with nothing lost by waiting.

## 2026-08-02 — Duplicate schema reconciled

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
  attribution instead of blocking on it.
- **`ItemCondition` is now wired.** It arrived with the deleted schema and was
  never attached to anything while `user_collection_items.condition` stayed
  free text. Now cast on the model, with a matching CHECK.

The brand-on-casting vs brand-on-release disagreement was deliberately **not**
settled here, per the separate-sessions convention — folding a schema redesign
into a merge repair would have made both unreviewable. It was settled the
following day — see the 2026-08-03 entry above.

Verified: `migrate`, `rollback` and `re-migrate` all clean from scratch;
`migrate:fresh --seed` clean; `php artisan test` green at 39 tests, 78
assertions.

## 2026-08-02 — Models, admin auth, and the submission gate landed

Added the application layer the capture/review app needs. `php artisan test`
green: 35 tests, 69 assertions.

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
- **R2 disk** wired in `config/filesystems.php`.

The approval, submission and delivery decisions from this session are recorded
under *Settled decisions* in `CLAUDE.md`; the environment traps under *How to
work in this repo*.

## 2026-08-02 — Schema migrations landed

The Laravel 13 + Docker scaffold set up and running (`docker compose up -d` —
services: `app`, `db` (Postgres 16), `redis`, `meilisearch`). `.env` copied from
`.env.example`; both already match the compose service names (`DB_HOST=db`,
`REDIS_HOST=redis`, `MEILISEARCH_HOST=http://meilisearch:7700`).

The Core Data Model became concrete migrations in
`database/migrations/2026_08_02_1000*`, covering `makes`, `models`, `brands`,
`castings`, `casting_versions`, `series`, `releases`, `variations`,
`price_histories`, `photos`, `user_collection_items`, and
`user_wishlist_items`. Verified against the live Docker Postgres: migrate,
rollback, and re-migrate all run clean.

Schema decisions from this session are recorded under *Settled decisions* in
`CLAUDE.md`. Scope was schema only — no Eloquent models, no controllers, no
routes, no AI agent code.
