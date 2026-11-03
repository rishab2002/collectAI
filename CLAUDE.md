# Universal Die-Cast Database — Project Context

Auto-loaded at the start of every Claude Code session in this repo, so the
domain model, conventions and current state only have to be explained once.
**Treat this file as the source of truth, not the conversation history** —
update it whenever a convention changes.

**Last edited:** 2026-11-03 (manual entry of cars and photos; the importer reads
every Hot Wheels year and the Matchbox wiki; HWPriceGuide ruled out as a source.
**Still nothing deployed** — the apex is unchanged)

## Start here

A brand-agnostic diecast catalog (Hot Wheels, Matchbox, Tomica, Mini GT, …) on
Laravel + Postgres, whose catalog grows from photographs of packaging via an AI
pipeline, gated behind admin approval.

**The data layer is complete and tested. The importer covers every Hot Wheels
year (1968–2027) and the Matchbox wiki, and an admin can add cars and photos by
hand — but your dev database holds only what you have actually imported (2011,
as of the last measurement). The public product is not started.** Read
[Current state](#current-state) before assuming any feature exists — several
plausible-sounding ones don't, including some the commit history implies.
In particular: everything imported is `pending` and therefore invisible, on
purpose.

The five things most likely to trip you up, each expanded below:

1. **Check `database/migrations` before writing one, and `git branch -a` before
   building a feature.** Two branches once each built the entire schema, and
   both were merged. It then happened a second time with the whole importer.
   See [How to work in this repo](#how-to-work-in-this-repo).
2. **`migrate:fresh --seed` + `php artisan test` is the gate for schema work.**
   Plain `php artisan migrate` on an already-migrated dev DB proves nothing.
3. **Tests run on Postgres, not SQLite**, and `docker-compose.yml` must not get
   an `env_file:` on the `app` service. Both traps have cost real time.
4. **A cross-brand release is legal.** One casting is one physical tool; the
   brand that sold it is a fact about the release. See
   [Settled decisions → Schema](#schema).
5. **Nothing auto-publishes on the capture path.** Catalog rows default to
   `pending`, `status` is not fillable anywhere, and approval promotes the whole
   ancestor chain. Imported castings arrive unidentified and their releases and
   variations are held back with them — that's the blocker doing its job, not a
   bug. The importer's *own inferences* are the one exception, and they have
   their own separate threshold; see
   [Make/Model inference](#makemodel-inference).

## Index

- [Current state](#current-state) — what exists, what doesn't · [what is actually on the internet](#what-is-actually-on-the-internet)
- [What to do next](#what-to-do-next)
- [How to work in this repo](#how-to-work-in-this-repo) — conventions, traps
- [Domain model](#domain-model) — [Make/Model splitting](#makemodel-splitting-rules) · [field definitions](#field-definitions)
- [AI agent pipeline](#ai-agent-pipeline) — [example flow](#example-flow) · [acquisition priorities](#data-acquisition-priorities)
- [Settled decisions](#settled-decisions) — [schema](#schema) · [approval](#approval--submissions) · [capture](#capture) · [AI pipeline](#ai-pipeline) · [catalog import](#catalog-import-fandom-wikis) · [data sources](#data-sources) · [Make/Model inference](#makemodel-inference) · [review](#review) · [manual entry](#manual-entry) · [delivery](#delivery) · [deployment](#deployment)
- [Open questions](#open-questions)
- [Session log](docs/session-log.md) — narrative and rationale, loaded on demand

---

## Current state

*Counts re-verified 2026-08-10 against the running stack. Keep this section
honest — it's the fastest way for a new session to avoid assuming something
exists.*

**Running:** `docker compose up -d` brings up `app` (:8000), `db` (Postgres 16,
:5432), `redis` (:6379), `meilisearch` (:7700). `php artisan test` → **255
passed, 823 assertions** (re-measured 2026-11-03).

**The dev database holds the import *plus* seed data, so its counts are not the
import's counts.** `CatalogSeeder` and `SubmissionSeeder` contribute 9 castings
(`'92 Honda Prelude`, `Twin Mill`, four RX-7s, …), 8 casting versions, 1 series,
2 releases and 3 variations on top of the 241 imported castings — which is why a
`Casting::count()` says 250. The reliable test of what came from the wiki is a
`record_sources` row, not a total: provenance sits at exactly 12,934 and is
untouched by seeding. Check that before concluding the importer double-ran.

**A clean checkout has no `.env`** (it's gitignored), so the suite fails on
`MissingAppKeyException` before it reaches anything real. `cp .env.example .env
&& php artisan key:generate` is step one.

**Nothing runs a queue worker.** The `app` container's only process is `php
artisan serve`, and `QUEUE_CONNECTION=redis`, so the `ReadSubmissionPhotos` job
that capture dispatches is enqueued and then sits there: the upload succeeds,
the submission stays `queued`, and `ocr_output` stays null with no error
anywhere. Run `php artisan queue:work` in a second shell to watch the OCR agent
actually fire. Don't diagnose this as a broken agent — and note the test suite
can't catch it, because `CaptureTest` calls `Queue::fake()` on purpose (see
[AI pipeline](#ai-pipeline)).

### What is actually on the internet

*Measured 2026-08-10 with `dig`, `curl`, `openssl s_client` and
`capture:check-storage`. The app half of this domain has never been connected;
the photo half has been since 2026-08-09.*

| Host | Measured 2026-08-10 |
|---|---|
| `img.collectai.club` | **Working.** R2 custom domain, valid Cloudflare cert (`Verify return code: 0`). `capture:check-storage` passes all four steps: write, read, public fetch, delete. Root and unknown keys 404 by design — that is not breakage |
| `collectai.club` | **Porkbun parking page.** `200` over HTTP, **`525`** over HTTPS |
| `www.collectai.club` | Same parking page, same behaviour |

**The 525 is not a broken deployment — it is the absence of one.** DNS is
correctly delegated to Cloudflare (`cris`/`suzanne.ns.cloudflare.com`), and the
apex and `www` are *proxied* A records that still point at Porkbun's parking
service. The fingerprints are unambiguous: `X-Service: pixie-default`,
`gtag('config', …, {'page_path': '/parked'})`, `X-Powered-By: PHP/8.0.25`, and
AWS ALB cookies. Cloudflare reaches that origin fine over port 80 and hands back
the parking page, but cannot complete a TLS handshake with a certificate that
doesn't cover this domain — hence `525` on HTTPS and `200` on HTTP. Anyone
diagnosing the 525 as a misconfigured origin will look for a server that does
not exist.

**There is now deployment configuration, but it has never been run.** The
target is a single AWS Lightsail box: `docker-compose.prod.yml` plus the
Dockerfile's `prod` stage (nginx + php-fpm under supervisord, a separate
`queue:work` container, Postgres, Redis), with `docs/deploy.md` as the runbook.
Nothing has been provisioned, so the table above is still exactly what is on the
internet — the 525 stands until someone works through the runbook.

**The `prod` image builds and the stack boots — verified locally 2026-08-10.**
`web` reaches healthy, `/login` renders with its compiled assets (the
content-hashed CSS returns 200 with `Cache-Control: public, immutable`), and the
worker starts `queue:work`. The dev suite was 207 passed, 609
assertions at the time. What has *not* happened is a real deployment: no instance, no DNS
change, so the table above still stands.

**Read a `web` container that never goes healthy by finding the first
`[emerg]`.** nginx and php-fpm are supervised together, and php-fpm survives
whatever nginx does — so a config error leaves a *running* container that merely
fails its healthcheck, and compose reports only `dependency failed to start`
after ~213 seconds. Two separate bugs presented exactly this way on the first
run. nginx validates its whole configuration before binding, so it reports one
fatal error and stops; fixing it can reveal the next. **The nginx config is now
parsed at build time** (`nginx -t` against a throwaway certificate, all of it
deleted again), so that class of error fails the build instead.

There is still no CI: no `.github/workflows`, and a deploy is `git pull` plus
`docker compose -f docker-compose.prod.yml up -d --build` on the box.
`docker-compose.yml` remains the development box — one `php artisan serve`, no
queue worker, `APP_URL=http://localhost` — reachable only from this Mac on
`localhost:8000`.

**The entire HTTP surface is twenty-one routes** (`routes/web.php`, counted with `route:list` 2026-11-03):

| Route | State |
|---|---|
| `GET /` | Stock Laravel welcome page. Scaffold apart from one fix: its authed "Dashboard" button hardcoded `url('/dashboard')`, a route this app has never had, so every logged-in visitor was one click from a 404. Now `route('admin.dashboard')` |
| `GET\|POST /login` | Real. Admin only — no registration, no password reset |
| `POST /logout` | Real |
| `GET /admin` | Two queues: submissions awaiting review, and a link to the inferred queue below |
| `GET /admin/capture` | Real. Camera capture form + optional brand/year/series hints |
| `POST /admin/capture` | Real. Uploads photos, creates a `queued` submission |
| `GET /admin/submissions/{submission}` | Real. Photos + proposals, with approve/reject |
| `POST …/{submission}/proposals/{proposal}/approve` | Real. Scoped bindings |
| `POST …/{submission}/proposals/{proposal}/reject` | Real. Scoped bindings |
| `GET /admin/proposals` | Real. Proposals with **no** submission behind them — the importer's inferences, paginated |
| `POST /admin/proposals/{proposal}/approve` | Real. Unscoped — there is no parent to scope to |
| `POST /admin/proposals/{proposal}/reject` | Real. Retracts, rather than rejects, an imported casting |
| `GET /admin/catalog` | Real. Search castings by name, make or model, every status |
| `GET /admin/castings/create` | Real. Add a car by hand — see [Manual entry](#manual-entry) |
| `POST /admin/castings` | Real. New casting + version 1 + release + variation (+ photos); goes live |
| `GET /admin/castings/{casting}` | Real. Every release, variation and photo; add-variation form; source attribution |
| `POST /admin/castings/{casting}/variations` | Real. Another release/variation of the same tool |
| `POST /admin/variations/{variation}/photos` | Real. Named photo slots, same as capture |
| `POST\|DELETE /admin/variations/{variation}/photos/{photo}[/primary]` | Real. Scoped bindings; remove keeps bytes a submission still shares |

**Built and tested:**

- All 12 catalog tables + `submissions`, `submission_photos`,
  `submission_proposals`, `record_sources`. Migrations verified from zero
  (`migrate`, `rollback`, `re-migrate`, `migrate:fresh --seed` all clean).
- **The Fandom wiki importer — Hot Wheels and Matchbox** — the whole path, end
  to end: `app/Services/Import/Wikitext/` (a rowspan-aware wikitable parser),
  `app/Services/Import/FandomWiki/` (`Wiki`, `CastingPageParser`,
  `YearIndexParser`, `CatalogImporter`, `ApiPageSource`, `DumpPageSource`) and
  `catalog:import-wiki {hot-wheels|matchbox}` (`--year` or `--from`/`--to`,
  `--dump`, `--limit`, `--dry-run`). `catalog:import-hot-wheels` is the same
  command fixed to Hot Wheels and still works. The namespace was
  `HotWheelsWiki` until 2026-11-03.
  Parser tests run on wikitext captured verbatim from the wiki in
  `tests/Fixtures/hotwheels-wiki/`, not on wikitext written to suit the parser —
  that distinction found four separate classes of dropped row and is worth
  preserving. **2011 is imported** into the dev DB: 241 castings, 258 casting
  versions, 1,856 series, 4,284 releases, 6,295 variations, 12,934 provenance
  rows, in about 27 seconds. Re-import is idempotent — verified against the live
  wiki, not just in tests.
  - **Every year, both wikis, has been run from the dumps — in a cloud session,
    not into your dev DB.** See [Catalog import](#catalog-import-fandom-wikis)
    for the measured totals and the command to reproduce them locally.
  - **Almost all of it is `pending`, by design.** The wiki states no make or
    model in a structured column, so imported castings are unidentified and
    `Casting::approvalBlocker()` holds them — and their releases and variations
    — back. Brands and series go live. `submission_proposals` is untouched: the
    import does not create review items. Don't "fix" the pending rows by setting
    `model_id` in the importer; that's the inference the split is meant to gate.
  - **`--dry-run` writes and rolls back**, rather than parsing and skipping the
    insert. What a rehearsal is for is catching writes that *collide*, and one
    that never reaches the insert reports a clean run and then dies partway
    through the real one. It holds a single transaction open for the whole run,
    so pair it with `--limit` before rehearsing the dump.
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
- **The Metadata Agent** (`app/AI/schemas/casting_identity.json` — **v2**,
  `app/Services/AI/MetadataAgent.php` + `CastingIdentity`,
  `app/Services/Catalog/CastingIdentifier.php`, `catalog:identify-castings`).
  Turns a casting name into a Make/Model and a *generation* — or a fantasy flag,
  or an honest "unknown" — with per-field confidence, and writes the result.
  Asks in batches of 25 behind a cached system prompt, and matches answers back
  **by casting id with the name echoed for confirmation**, never by position.
  `--regenerate` re-files already-attributed castings under the right
  generation, writing only `castings.model_id` and refusing to move any casting
  whose make/model no longer agrees with the catalog. See
  [Make/Model inference](#makemodel-inference) for the rules.
- **`catalog:publish-imported`** — publishes the casting versions, releases and
  variations that were only ever waiting on a blocked casting. Separate from
  approval on purpose, and gated on *ancestors already live* rather than on "no
  blockers". Both calls are explained under
  [Make/Model inference](#makemodel-inference).
- **The inferred-proposal review screen** (`ProposalReviewController`,
  `resources/views/admin/proposals/index.blade.php`) — the queue for proposals
  with no submission behind them. Same model methods as the submission screen;
  what differs is that there are no photos, so it links the source page instead.
- **R2**, verified end to end on 2026-08-09 against the live `collectai-photos`
  bucket: the capture endpoint stored a 150,000-byte photo, and fetching
  `Photo::url()` over `img.collectai.club` returned identical bytes (md5 match)
  with `content-type: image/jpeg` preserved. The bucket root returns 404, so
  object listing isn't exposed. `php artisan capture:check-storage` re-runs the
  whole round trip — write, read, public fetch, delete.
- **The PWA shell**: `public/manifest.json`, `public/sw.js`,
  `public/offline.html`, the icon set under `public/icons/` plus
  `apple-touch-icon.png`, and registration in `resources/js/app.js`. The layout
  is now a real HTML document (it used to start at `<meta name="viewport">`, so
  every page rendered in quirks mode).
- **Manual entry** (`CatalogController`, `VariationPhotoController`,
  `app/Services/Catalog/ManualCatalogEntry.php`, `VariationPhotos.php`, the
  `StoreCarRequest` / `StoreVariationRequest` / `StoreVariationPhotosRequest`
  requests, `resources/views/admin/catalog/`, `components/catalog/`). Add a car
  by hand — make/model or fantasy, casting, release, variation, photos — or add a
  variation and photos to any casting already in the catalog; search castings at
  every status. What an admin enters goes live. See [Manual entry](#manual-entry).
  Driven end to end in Chromium at phone width, not just in feature tests.
- **The production deployment configuration** — written, not yet run. The
  Dockerfile is multi-stage (`dev` unchanged, plus `vendor`, `assets` and
  `prod`), `docker-compose.prod.yml` runs web + worker + Postgres + Redis, and
  `docker/` holds the nginx, php-fpm, supervisord, entrypoint and backup pieces.
  `docs/deploy.md` is the runbook. The image builds and the stack boots healthy
  locally; nothing is deployed. See [Deployment](#deployment) for the settled
  calls.

**Not built — do not assume these exist:**

- **Two of the seven agents exist, and they are not yet joined up.** OCR reads
  packaging text into `submissions.ocr_output`; Metadata attributes *imported
  castings* from their names. Nothing connects the two: Metadata is driven by
  `catalog:identify-castings` over the import backlog, and does not read a
  submission's OCR output. Search, Comparison, Insertion and Review do not
  exist, so a completed capture still has a reading and **no proposals**, and
  `SubmissionSeeder` is still what puts anything in the *submission* queue.
  Pointing Metadata at a capture's reading is a natural next step and is not
  built.
- **No image import from any source, and no price data.** The wiki images are
  excluded by a settled decision, and HWPriceGuide is ruled out as a source
  entirely — see [Data sources](#data-sources). Photos come from capture and
  manual entry.
- **Schuco, Mini GT, TSM, Inno64 and Para64 have no importer.** Mini GT has a
  small Fandom wiki (699 articles, its own page format); Inno64's has 24
  articles; the rest have no wiki, only manufacturer product sites. Each is a
  separate source to vet and parse. Until then, manual entry is how they get in.

- **No Meilisearch integration.** The container runs, but `laravel/scout` is not
  in `composer.json`, so nothing is indexed and cross-brand search doesn't work
  end to end. Note `pg_trgm` and Meilisearch are not substitutes: trigram
  similarity is the Comparison Agent's DB-side duplicate check, Meilisearch is
  user-facing typo-tolerant search.
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
what the pipeline proposed, watch it go live. Nothing produces proposals from a
*capture* automatically yet, so `SubmissionSeeder` is still what puts anything
in that queue.

**The importer landed on 2026-08-09** and 2011 has been imported for real, so
Comparison finally has a catalog to diff against.

**Make/Model inference landed, ran for real, and the queue was worked on
2026-08-09.** All 241 imported castings have been through the Metadata Agent and
the inferred queue is down to 7. **The catalog is browsable** — 218 live
castings, 3,772 releases, **5,543 live variations** (88% of the import), 118
model rows of which 55 carry a generation. What's left is a small, named
backfill: 23 castings the agent honestly couldn't name, 7 held proposals (see
the near-duplicate item below), and the 1,333 pending records waiting behind
them (32 castings, 32 versions, 514 releases, 755 variations).

**Two queues, and only one of them is the backlog.** `/admin/proposals` holds
the 7 inferred proposals above; the 5 others in the DB belong to
`SubmissionSeeder`'s one submission and are fixture data, not work. Count them
apart — 12 pending proposals is the expected total, not a regression.

**The obvious next moves:**

- **Resolve the 7 held proposals, which are all one question.** Each is a
  casting whose stored nameplate carries a trim the v2 prompt now strips —
  `Corvette Grand Sport` → `Corvette`, `Hummer H2 SUT` → `H2`, `Mini Cooper S
  Challenge` → `Cooper`. They were deliberately left pending rather than ruled
  on: approving publishes a near-duplicate model row beside the generation rows,
  and rejecting throws away an attribution that isn't wrong, only differently
  scoped. Deciding whether a trim is its own model row is the near-duplicate
  call `models.name` has no unique constraint *for*, and belongs with the
  Comparison Agent. `--regenerate`'s skipped list is the full worklist.
- **Trim names are leaking into `models.name`, creating near-duplicates.**
  The v1 prompt produced rows like `Corvette Grand Sport`, `Corvette Stingray
  Concept`, `911 GT3 RS` and `H2 SUT`; v2 says "the nameplate alone" and now
  reduces those to `Corvette`, `911`, `H2`. The re-split's make/model guard
  correctly refused to re-attribute them on its own — 7 castings were skipped
  and reported — so the catalog currently holds both spellings. Merging a trim
  row into its nameplate row is a near-duplicate judgement, which is the
  Comparison Agent's job; the skipped list from `--regenerate` is the worklist.
  One of them is a genuine correction worth taking: `Custom '41 Willys Coupe`
  is a Willys **Americar**, not "Coupe".
- **Deploy to the apex.** `collectai.club` is registered and delegated to
  Cloudflare but nothing is deployed, so the capture screen has never run on a
  phone — which is the only place it's meant to run. The app is now installable
  the moment it's served over HTTPS, and HTTPS already terminates at the
  Cloudflare edge, so the install prompt should work the moment an origin
  exists. What is measured to be live, and why the apex returns 525 today, is in
  [What is actually on the internet](#what-is-actually-on-the-internet).

  **The host decision is made and the configuration is written: AWS Lightsail,
  one box, `docker-compose.prod.yml`.** Chosen over ECS/RDS/ElastiCache because
  that shape's fixed costs — an ALB at ~$16/mo and, with private subnets, a NAT
  gateway at ~$32/mo — dwarf a workload with one admin user. The only new
  spend is the instance itself; R2, the Anthropic key and the domain are already
  paid, and photos serve from `img.collectai.club`, so the image-heavy half of
  the catalog never touches AWS bandwidth.

  **Follow [docs/deploy.md](docs/deploy.md) rather than improvising** — the
  ordering in it is load-bearing, particularly verifying against the raw IP with
  `curl --resolve` *before* touching DNS, and setting Full (strict) only *after*
  the origin certificate is in place. The calls behind it are in
  [Deployment](#deployment).
- **Run the full imports into the dev DB.** No new code needed:

  ```bash
  php artisan catalog:import-wiki hot-wheels --from=1968 --to=2027 --dump=/path/to/hotwheels_pages_current.xml.7z
  php artisan catalog:import-wiki matchbox --from=1968 --to=2027 --dump=/path/to/matchbox_pages_current.xml.7z
  ```

  The dump URLs are `Wiki::dumpUrl()`; S3 refuses `HEAD` with a 403 but serves
  `GET`, so don't read a failed `curl -I` as the dump having moved. Decompress
  once with `7z x` and pass the `.xml` if you will run more than once. Timings
  are under [Catalog import](#catalog-import-fandom-wikis).
- **Then budget before identifying.** Everything imported arrives unidentified,
  and `catalog:identify-castings` is a billed Claude call per 25 castings. A
  full import takes the backlog from 241 castings to 3,642 (measured) — decide on a `--limit`
  per run rather than letting it walk the whole thing.
- **Join the two agents up.** Metadata is currently driven by the import
  backlog only. A capture produces an OCR reading and still yields no proposals,
  because the link that turns a reading into catalog records doesn't exist:
  **Comparison** (deterministic `pg_trgm` lookup — no LLM call) and **Insertion**
  (create pending records + `submission_proposals`). Until those exist the
  submission queue only fills from `SubmissionSeeder`. Whoever writes Insertion
  must create `CastingVersion` 1 explicitly — nothing does it automatically, see
  below. Comparison now has a real catalog to diff against, which was the point
  of doing the import first — and `variations.toy_number` is the match key to
  reach for before fuzzy name matching, since it's what the OCR agent already
  reads off the card.
- **Prove the OCR agent against the live API.** A working key *is* now set
  (`COLLECTAI_ANTHROPIC_KEY` in `.env`) and the Metadata Agent has billed real
  calls against it, so the credential is no longer the blocker — OCR simply
  hasn't been pointed at a real photo yet, and every test to date uses a faked
  PSR-18 transport. The one genuinely unverified assumption is that the API's
  structured-output subset accepts `"type": ["string", "null"]`; if it doesn't,
  that's a 400 at schema compilation and a switch to `anyOf`. Note
  `casting_identity.json` uses the same construct and the Metadata Agent's live
  runs succeeded, which is strong evidence it's accepted — but confirm on the
  OCR schema rather than inferring it. Everything downstream is built on it.

Note the layout lives at `resources/views/components/layouts/app.blade.php`
(anonymous Blade components resolve under `views/components/`), and the base
`TestCase` calls `withoutVite()` so view tests don't need a frontend build.

**Further out, still untouched:** Meilisearch indexing (`laravel/scout` isn't
installed), the public catalog, collections/wishlists, price charts, barcode
scanning. `/` is still essentially the stock welcome page (one dead link fixed,
see the route table) and is the one screen not using the app layout, so it
carries no manifest link — install works from `/login` and `/admin/*`. Worth
folding in whenever the public catalog replaces it.

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
- **Check whether the feature you are about to build already exists on another
  branch.** This has now happened twice, and the second time was worse than the
  first: `huracan` and `aventador` each built the entire Hot Wheels importer,
  both were merged, and because the two versions mostly disagreed on *filename*
  rather than on content, git had almost nothing to conflict on and took the
  union. `main` ended up with two commands, two wikitext parsers, two DTO sets
  and two migrations adding the same column. **Nothing about a clean merge means
  the result is coherent** — `git merge` reconciles text, not designs. Before
  starting a named piece of work, `git branch -a` and skim the branches that
  aren't merged yet.
- **A merge that touches a file both sides rewrote deserves a re-read, not a
  resolution.** Every contested file in that merge silently resolved one way
  while the surrounding code came from the other, which is how `config/catalog.php`
  ended up shipping a threshold that this file documented as a different number.
  If a conflict resolution is more than a few lines, check the result still
  agrees with what CLAUDE.md claims.
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

- **Cloud sessions have PHP 8.3; the lock file needs 8.4** (Symfony 8.1). To run
  the suite there, `composer update` resolves Laravel 13 onto Symfony 7.4, then
  `git checkout composer.lock` — **never commit the 8.3 lock**. Also `cp
  .env.example .env`, point `db` at 127.0.0.1 in `/etc/hosts` (phpunit.xml forces
  `DB_HOST=db`), and create the `diecast`/`diecast_testing` databases.

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
  generations. **Only when the Model record covers exactly one generation** —
  a row that several castings share cannot carry a code, because the castings
  span the generations the code is meant to distinguish. See
  [Make/Model inference](#makemodel-inference).
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

**Agents 2 and 3 of 7 are written** — see [Current state](#current-state). They
are not yet joined to each other: OCR runs off a capture, Metadata runs off the
import backlog. The tables the rest write into, and the approve/reject mechanics
they feed, are built and tested.

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
3. **Metadata Agent** — **built.** Normalizes names against known conventions.
   Today it does the Make/Model split behind an imported casting name, driven by
   `catalog:identify-castings`; the same agent is where resolving OCR typos and
   standardizing series names belongs when a capture needs it. See
   [Make/Model inference](#makemodel-inference).
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
- **`decision` is the string `'pending'`, never NULL.** An undecided proposal
  carries the word, so `whereNull('decision')` matches zero rows and reports an
  empty queue whichever way the backlog is actually going — the one wrong answer
  that looks like success. Query `where('decision', 'pending')`.
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
  only proves an agent called the method it was written to call; faking
  transport means the SDK really serialises the request, so the tests assert the
  actual JSON body — and catch a named argument that doesn't reach the wire.
  `tests/Concerns/FakesAnthropicTransport` is the shared seam.
- **One `Anthropic\Client` for every agent, keyed from
  `catalog.anthropic.api_key`.** They are calls to the same account; what
  differs per agent (model, effort, token budget) is a request parameter, not
  client state. The key used to live at `catalog.ocr.api_key`, which stopped
  being an honest name the moment there was a second agent.
- **The env var is `COLLECTAI_ANTHROPIC_KEY`, never `ANTHROPIC_API_KEY`.**
  That name is resolved first by Claude Code, the Anthropic SDKs and the `ant`
  CLI — ahead of an OAuth subscription profile — so anything that loads this
  repo's `.env` into the environment would silently switch those tools to
  billing the API account. Laravel reads `.env` off disk itself and the client
  is passed the key explicitly, so the value never needs to be in the
  environment. Don't "fix" this back to the conventional name. Note the
  blank-value form of the same trap: an empty `ANTHROPIC_API_KEY` still wins its
  precedence slot and authenticates with an empty key.
- **`Queue::fake()` is load-bearing in `CaptureTest`.** The suite runs the
  `sync` driver, so without it the capture endpoint's dispatch runs the vision
  call inline and a file-upload test makes a live API request.
- **Tune `OCR_EFFORT` before `OCR_MODEL`.** A misread becomes a catalog row an
  admin has to notice is wrong; that review time dwarfs the token difference.
  `OCR_MAX_TOKENS` covers thinking as well as the JSON — thinking is on by
  default on Opus 5, and a limit sized for the answer alone truncates mid-read.

### Catalog import (Fandom wikis)

*Settled 2026-08-09 as a decisions-only session, then built and run the same
day. Verified live against the wiki. Widened to every year and to the Matchbox
wiki on 2026-11-03. Reasoning in the [session log](docs/session-log.md).*

The catalog can't grow to useful coverage from photographs alone, so the bulk of
it comes from [hotwheels.fandom.com](https://hotwheels.fandom.com) and
[matchbox.fandom.com](https://matchbox.fandom.com), and the capture pipeline and
manual entry add what the wikis lack. This also gives the Comparison Agent
a populated DB to diff against — without it, duplicate detection is being built
against an empty table.

- **Use the dump, not a crawler.** `Wiki::dumpUrl()` —
  `…/wikia_xml_dumps/h/ho/hotwheels_pages_current.xml.7z` (17.6 MB, 195 MB
  decompressed, last modified 2026-10-20) and `…/m/ma/matchbox_pages_current.xml.7z`
  (3.75 MB, last modified 2026-09-25). S3 answers `HEAD` with 403 and `GET`
  normally. The API (MediaWiki 1.43.9 at `/api.php`) is only for the delta
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
- **One brand per wiki, one `Wiki` per site.** Each Fandom wiki covers one
  brand, so the brand is a property of the source (`Wiki::$brand`), not read off
  a page. What differs between wikis — host, `record_sources.source`, brand,
  year-index title, series rule — lives on `App\Services\Import\FandomWiki\Wiki`
  and nowhere else; every constructor defaults to Hot Wheels. Matchbox needed
  **zero schema changes**, which is the design working.
- **Each wiki is its own provenance source** (`hotwheels_fandom`,
  `matchbox_fandom`). Page ids restart from 1 on every wiki, so `page:2602` is a
  different article on each, and `CatalogImporter` refuses a page from another
  wiki rather than overwrite a casting that shares its id.

**Rules learned building it** (2026-08-09). Each came from a bug that failed
silently; all are now regression-tested against verbatim live wikitext under
`tests/Fixtures/hotwheels-wiki/`:

- **`rowspan` is the whole ballgame, and a rowspan block is a release.** Versions
  tables write one release sold with four wheel types as a row carrying
  `rowspan="4"` on every shared cell, then three rows holding only what differs
  — three cells where the header has thirteen. A positional parser doesn't drop
  data, it *misfiles* it, reading a wheel type as a collector number. Carrying
  spans down is also what produces the grouping: block = release, row within it
  = variation.
- **Nothing about page layout is uniform.** A `|-` may or may not precede the
  header row, and a page may carry **several** versions tables, not in year
  order. Assuming otherwise cost 21 castings across two bugs, both failing the
  same way: the page parsed to zero rows, which is indistinguishable from a
  casting with no releases that year, so it was reported as an ordinary skip.
  **If a parser change makes skips go up, treat it as a bug** — check that a car
  you know is in the year index actually landed.
- **Never lossily transform a source key.** Keys were slugged, folding case and
  punctuation, so two series the wiki lists separately ("Monster Jam: Monster
  Duo" and "Monster Jam - Monster Duo") collapsed onto one and the import died
  mid-run on the unique constraint. A key identifies exactly one record, so any
  transform that can map two records onto one key can only fail — loudly, or
  silently by overwriting. Keys keep the name with whitespace normalised and
  nothing else, which also keeps them in step with how a series is resolved (an
  exact name match).
- **A bulk writer must check `approvalBlockers()` itself.**
  `promoteWithDependencies()` doesn't — only `SubmissionProposal::approve()`
  did — so publishing directly would have released exactly what the
  unidentified-casting rule exists to hold back.
- **Toy number is a variation attribute, not a release one** — the 2011
  Nightburnerz 350Z is one release carrying both T9819 and T9950. It's also the
  best match key a card has, which is why `variations.toy_number` exists and
  carries a trigram index.

**Rules learned going wide** (2026-11-03, running every year of both dumps).
Regression-tested on verbatim pages under `tests/Fixtures/hotwheels-wiki/` and
`tests/Fixtures/matchbox-wiki/`:

- **Year indexes come in three shapes.** Tables with a casting column (headed
  `Model Name`, or `Car Name` in the 1980s), `<gallery>` blocks (the early
  1970s — prefer the caption's link over `link=`, which sometimes points
  elsewhere), and rows rendered by `{{List…|toy|col|[[Model]]|…}}` templates
  (late 1990s). Reading only the first left **21 of 60 Hot Wheels years
  yielding nothing**. Survey every index before trusting a range run.
- **An empty title list must never reach a page source.** `DumpPageSource`
  treats an empty want-list as "every page" (that's what `all()` is), so an
  index that parsed to nothing silently began importing the **entire wiki** as
  that year. `pages([])` now yields nothing and the command says so.
- **Which headings count as Versions sections is now effectively frozen.** Row
  numbers are counted across the page's Versions sections in document order, and
  provenance keys are built from them — recognise a new section *before* an
  existing one and every later row renumbers, so a re-import overwrites car A
  with car B. The 2026-11-03 widening (bold headings, singular `Version`, a
  trailing parenthetical like `Versions (MB168)`) was checked against both dumps
  first: Matchbox renumbered nothing, Hot Wheels 5 pages none of which were in
  the 2011 import. Any further change needs the same measurement.
- **Matchbox writes series as range plus segment** — `[[1-75]]: [[Wheeled
  Envy]]`, `5-Pack: [[…|VIP Parking]]`, position sometimes on the same line —
  and the first link alone would file every mainline car under "1-75". So
  `Wiki::$compoundSeries` names the series by the whole line. It is **off for
  Hot Wheels and must stay off**: 1,002 Hot Wheels cells carry text beside the
  link, and re-keying them would duplicate every series already imported.
- **A wheel written as a picture is recorded by its file name** (`M002f`), the
  wiki's own wheel code — 8,969 Matchbox cells, 1 Hot Wheels cell. Dropping it
  made cars that differ only by wheels identical. The image is never fetched.
- **A caption line (`|+`) is not a data row.** Read as one, it started the body
  early and the table lost its header row (Baja Bug).
- **`N/A` in Col # means no number**, not collector number "N". Underscored index
  links (`[[Fisker_Karma_(2011)|…]]`) are normalised to spaces.
- **What still parses empty is mostly not a casting:** track sets, monster
  trucks and playsets carry `{{casting}}` but no Versions table. Matchbox's
  seven `==Version 1 (With Gun)==`-style pages are a known gap, left alone for
  the renumbering reason above.

**Measured, both wikis, every year, from the dumps** (cloud session 2026-11-03,
fresh database, both wikis in one DB):

| | Hot Wheels | Matchbox |
|---|---|---|
| Index titles → castings | 2,287 → 2,073 | 1,900 → 1,569 |
| Casting versions | 2,290 | 1,587 |
| Series | 4,245 | 2,325 |
| Releases (years) | 22,631 (1968–2027) | 12,045 (1960–2027) |
| Variations | 33,744 | 13,449 |
| Provenance rows | 64,983 | 30,975 |
| Skipped rows/pages | 539 | 209 |
| Time | 448 s | 190 s |

Index titles exceed castings because 243 / 377 of them are redirects. Skips are
overwhelmingly rows identical in every column read — the Notes-only
sub-variants, deliberately not distinguished. Every casting lands `pending`
(3,642), as designed. A second Matchbox run created nothing and updated
everything (163 s) — idempotent at full scale.

**Parsing notes** (verified 2026-08-09): casting pages carry a `{{casting}}`
infobox and a `==Versions==` wikitable with columns *Col # · Year · Series ·
Color · Tampo · Base Color/Type · Window Color · Interior Color · Wheel Type ·
Toy # · Country · Notes · Photo*, mapping almost 1:1 onto releases + variations.
Year indexes exist as `List of YYYY Hot Wheels`, 1968–2027. Two gotchas: `Col #`
arrives as `032/244`, and Series as `[[2011 New Models]]<br>32/50` — both need
splitting, and Series being a wiki-link means series identity is resolvable
rather than a bare string.

### Data sources

*Settled 2026-11-03, when the request was to copy HWPriceGuide's whole database
and photos. Measured, not assumed — details in the [session log](docs/session-log.md).*

- **HWPriceGuide (hwpriceguide.com) is not a source — not its catalog, its
  prices or its photos.** It is a commercial competitor: ~33,500 `/item/` pages,
  paid memberships, prices it derives from sales data and puts partly behind
  "Upgrade to view", a metered search ("5 searches left"), and its own car photos
  under `/dist/images/cars/`. Its robots.txt doesn't forbid `/item/` and it
  publishes no terms page, but neither of those makes copying a competitor's
  compiled product and photographs acceptable. The photos are someone's
  copyright; the prices are the product; and mirroring the catalog contradicts
  [priority 1](#data-acquisition-priorities), an original database. Its
  *organisation* — category, sub-series, chase/Treasure Hunt status, first
  casting — is an idea and fair to learn from when the public catalog is built.
- **The same facts come from sources that allow it.** Year, series, colour,
  wheels and toy number for Hot Wheels and Matchbox are in the Fandom wikis,
  CC-BY-SA, with published dumps. That is where catalog breadth comes from.
- **Photos: ours, not theirs.** No source's images are imported — not the
  wikis' (settled 2026-08-09) and not HWPriceGuide's. Catalog photos come from
  capture and manual entry, which is what makes them unencumbered.
- **Prices need a source that permits reuse**, or our own observations. None is
  chosen; `price_histories` stays empty until one is.
- **Every new source gets this check first**: terms, robots.txt, whether the
  data is facts or someone's product, and whether images are involved. A source
  for Schuco, Mini GT, TSM, Inno64 or Para64 is a decision, not just a parser.

### Make/Model inference

*The Metadata Agent and the two commands that drive it. Landed 2026-08-09;
reasoning in the [session log](docs/session-log.md).*

- **Three verdicts, not two.** `casting_identity.json`'s `kind` is
  `real` / `fantasy` / `unknown`, mapping onto the three states `castings`
  already has. **"unknown" is a first-class answer**, and the prompt says so at
  length: `'64 Pontiac GTO` and `Bone Shaker` are easy, but `Circle Tracker`,
  `Monster Vette`, `F-Racer` and `Honda Racer` are real names from the real
  import, and a model with only two options resolves them by guessing. An
  unknown writes nothing and is counted separately from failures, not filed as a
  skip where it would bury them.
- **The score decides whether an attribution is *published*, never whether it is
  *written down*.** Every usable answer attaches `model_id` (or `is_fantasy`)
  whatever it scored; only approval is gated. Withholding the attachment below
  the threshold sounds safer and isn't: `Casting::approvalBlocker()` refuses to
  approve a casting with no model, so the reviewer would be handed a proposal
  they cannot act on and `ProposalPresenter` would render it "Not identified
  yet". Nothing is exposed either way — the casting is pending until approved.
- **Proposal confidence is `min(make, model)`, not the average.** Approving
  publishes both, so an attribution is as good as its weaker half, and the
  manufacturer is usually the easy part — averaging is exactly how a guessed
  nameplate rides a certain make over the line.
- **A `models` row is one generation of one nameplate, not the nameplate.**
  The lookup key is `(make_id, name, generation_start_year)` — the start year,
  because not every generation has a manufacturer code but every generation has
  years. This is the fix for the first live run's worst bug: seven Corvette
  castings spanning C2 to C6 all resolved to a single "Chevrolet / Corvette" row
  which then took whichever chassis code arrived first and mislabelled the other
  six. A nameplate is not one vehicle. Corollary: **a null start year is its own
  bucket, not a wildcard** — `where generation_start_year = null` matches nothing
  in Postgres, so the lookup uses an explicit `whereNull` or every unplaced
  casting mints a fresh row.
- **A generation is one claim — code plus both years — recorded or dropped
  whole.** Half of it files a casting under a generation the agent never
  asserted. `VehicleModel::generationLabel()` renders it as `C3 (1968–1982)`,
  or `1967–1969` where the manufacturer publishes no code, or `2020–present`.
- **Generation has its own threshold, `import.generation_threshold` (0.65),
  and the value was measured.** Scores come back *bimodal*: a name with no model
  year in it scores exactly 0.00, and a real generation scores 0.66–0.92 and was
  correct in every one of 22 sampled castings. The bar belongs in the empty gap,
  not inside the upper cluster — at 0.85 it discarded four of ten correct
  generations, and at 0.75 it left comparable castings on both sides of the
  line, which is the tell that a threshold is sitting on noise. Re-measure the
  gap rather than nudging the number if the model or prompt changes.
- **Batched, and therefore matched by id with the name echoed back.** Batching
  is what makes 11,041 castings affordable, and it introduces the one failure
  this pipeline doesn't otherwise have: a well-formed answer about the *wrong
  car*. Structured outputs guarantee the shape and cannot catch it. An id that
  wasn't in the batch, or an echoed name that doesn't match what was sent under
  that id, is **dropped**. Do not "fix" that by trusting position or by matching
  loosely — a dropped answer leaves a casting unidentified, a misfiled one
  publishes Bone Shaker as a Pontiac GTO on the GTO's score.
- **Makes are matched case-insensitively on the exact name, never fuzzily.**
  `makes.name` is hard-unique, so "Chevy" beside "Chevrolet" is not a collision
  the DB catches — it is two rows for one company. The known-make list is sent
  with every batch for that reason. Fuzzy matching is the Comparison Agent's
  job and would merge a Ford GT into a Ford GT40.
- **Rejecting an inference is not rejecting the record.**
  `HasApprovalStatus::rejectProposed()` defaults to `markRejected()` — right for
  the capture path, where the pipeline invented the row. `Casting` overrides it:
  a casting carrying provenance has its attribution retracted and goes back to
  unidentified. Marking that row rejected would throw away a real, sourced
  casting and strand every release and variation under a parent that can never
  go live. `CastingIdentifier::outstanding()` then won't re-infer it — a
  rejected proposal is a ruling, not a gap.
- **A bulk agent run survives an API error but not a rejected credential.**
  `CastingIdentifier` records a rate limit, a 529, a dropped connection or a
  refusal as a failed batch and carries on — one bad batch shouldn't cost the
  240 castings behind it. `AuthenticationException` and `PermissionDeniedException`
  are deliberately re-thrown: nothing about the next batch would differ, and
  swallowing them means grinding through every batch and finishing with a report
  that reads like the model was unsure rather than like the key is wrong.
- **`catalog:publish-imported` is separate from approval, and gated on
  "ancestors already live" rather than "no blockers".** Approval walks upward
  only and that asymmetry is settled, so identifying castings publishes makes,
  models and castings and leaves 10,837 imported records exactly where they
  were; this is the step that releases them. The gate is the safety property: a
  casting whose attribution scored 0.4 is pending and perfectly *approvable*, so
  a blocker check would publish its releases and make the reviewer's decision
  for them. It also only touches records with a `record_sources` row, so a
  capture's pending variation is never swept up.

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

### Manual entry

*Landed 2026-11-03. The reasoning is in the [session log](docs/session-log.md).*

- **What an admin types goes live** — the admin *is* the reviewer, so there is no
  proposal and no queue. It still publishes through `promoteWithDependencies()`,
  so nothing goes live under an invisible parent, and it still checks
  `approvalBlockers()`: a variation added to an imported casting nobody has
  identified is **saved and held**, with the reason shown. Hand-made records
  carry no `record_sources` row and no proposal, which is how the rest of the
  app tells them apart.
- **A new car must say what it is** — make + model, or "fantasy". The form
  refuses a real car with no model rather than saving something that would sit
  pending looking lost. A make typed with "fantasy" ticked is dropped, not
  stored (`exclude_if`), because the CHECK forbids the combination.
- **Lookups are exact and case-insensitive, never fuzzy.** Brands and makes by
  name (hard-unique); models by `(make, name, generation start year)`, the same
  key the Metadata Agent uses, so a hand-entered RX-7 lands on the row the
  pipeline would choose; series by name within the brand, preferring a live row.
  A miss costs a near-duplicate an admin can see; a fuzzy hit files a car as a
  different car. Generation is one claim — a chassis code or end year without a
  start year is refused, not half-stored.
- **New castings and new variations are separate entry points.** "Add a car"
  always mints a casting; a new colour or year is added from the existing
  casting's page. Matching a typed casting name to an existing one would merge
  different brands' tools that share a name — exactly what one-casting-per-tool
  forbids.
- **A duplicate variation is refused with the id it duplicates**, checked
  through `attributeSignature()` so the check and the unique index agree.
- **Photos reuse the capture slots and disk** (`TakesPhotoSlots`,
  `catalog.capture.disk`), get unguessable ULID paths, and clean up after a
  failed write like `CaptureController`. Removing a photo **keeps the bytes when a
  `submission_photos` row shares them** — `promoteTo()` references rather than
  copies. Photos are stored after the catalog rows commit: a photo failure
  leaves a correctly entered car without photos, not a lost entry.
- **No JavaScript involved.** Plain multipart forms with visible file inputs, so
  manual entry keeps working if the bundle breaks.

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
  Cloudflare (2026-08-09). The app itself isn't deployed anywhere yet, and the
  apex is **not** free for it: `collectai.club` and `www` are proxied A records
  still pointing at Porkbun's parking page (`X-Service: pixie-default`), left
  over from registration. Measured 2026-08-10, that serves the parking page over
  HTTP and **error 525** over HTTPS, because Cloudflare can't complete a TLS
  handshake with a parking origin whose certificate doesn't cover this domain.
  Deploying means replacing those records, not creating them.
  `img.collectai.club` is a custom domain on
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

### Deployment

*Written 2026-08-10. The runbook is [docs/deploy.md](docs/deploy.md); this is
why it says what it says. None of it has run against real infrastructure yet.*

- **One Lightsail box running `docker-compose.prod.yml`**, not ECS/RDS/
  ElastiCache. That shape's *fixed* costs — ALB ~$16/mo, NAT gateway ~$32/mo —
  exceed the whole workload, which has one admin user. The tradeoff taken
  knowingly: Postgres shares the box, so backups are ours to arrange.
- **`prod` is the last stage in the Dockerfile, so `docker-compose.yml` must
  name `target: dev`.** A bare `docker build .` should produce the production
  image; the cost is that the development stack would otherwise silently build
  nginx + php-fpm and break the suite.
- **Cloudflare's real client IP is restored in nginx, never with Laravel's
  `TrustProxies`.** `set_real_ip_from` over Cloudflare's ranges plus
  `real_ip_header CF-Connecting-IP` rewrites `REMOTE_ADDR` before PHP sees it.
  `TrustProxies` at `'*'` would instead have Laravel re-read `X-Forwarded-For`,
  which any client can forge — reintroducing the problem one layer up. What
  depends on this: `LoginController::throttleKey()` keys on email **+ IP**, and
  behind an un-restored proxy the IP half is a constant, degrading to exactly
  the per-email throttle its own comment warns "would let anyone lock the admin
  out of their own catalog".
- **The origin only accepts Cloudflare ranges.** The Lightsail IP is not secret
  — certificate transparency logs and old DNS records leak origins routinely —
  and without the allow-list it is a second front door past the edge's TLS
  policy and rate limiting. `set_real_ip_from` and `allow` are different
  directives over the same list, so `cloudflare-ips.conf` and
  `cloudflare-allow.conf` are generated **together** by
  `docker/nginx/refresh-cloudflare-ips.sh`. Stale in only one and the failure is
  baffling: some visitors 403, others throttle against the wrong key.
- **No HTTP→HTTPS redirect at the origin.** TLS is the edge's job. An
  origin-side redirect is precisely what turns a Cloudflare **Flexible** SSL
  setting into an infinite loop, and Flexible is the tempting wrong fix for the
  525 — it clears the error while sending origin traffic in the clear.
- **`.env` is mounted read-only; configuration never arrives as process
  environment.** Same rule as `docker-compose.yml`'s missing `env_file`, and it
  still holds here even though there are no tests to corrupt: Laravel's Dotenv
  reads `$_SERVER` first, so an injected value cannot be overridden from the
  file. Read-only additionally means a stray `key:generate` on the box cannot
  invalidate every session — which is why the runbook uses `key:generate
  --show` and pastes the value in by hand.
- **The worker's `--timeout` (300s) must stay below
  `REDIS_QUEUE_RETRY_AFTER` (360s).** Redis re-queues a job after `retry_after`;
  if that fires while the job is still running, a second worker takes it and one
  capture becomes two billed vision calls. Laravel's defaults (60s / 90s) are
  both far too low for an Opus 5 vision call with thinking enabled, and
  `ReadSubmissionPhotos` declares no `$timeout` of its own — so at the default
  the first real OCR run is killed mid-call, retried three times and marked
  failed, which reads exactly like a broken agent.
- **PHP's upload limits are set deliberately *above* the application's.** When
  PHP rejects a file it does so before userland runs: `$_FILES` comes back empty
  and Laravel reports a missing field, which looks like a broken form. Letting
  the request through means `StoreSubmissionRequest`'s `max:12288` rejects it
  with a message naming the photo. `client_max_body_size`, `post_max_size` and
  `CAPTURE_MAX_PHOTO_KB` move together or not at all.
- **The readiness probe is `tinker --execute="DB::connection()->getPdo();"`, not
  `db:show`.** `db:show` is the obvious choice and a trap: it connects, prints
  the tables, then exits **1** anyway because its summary calls
  `Number::format`, which throws without the `intl` extension. The wait loop
  would never succeed and every boot would time out claiming the database was
  unreachable. Nothing in the app uses `Number::`, so adding `intl` would be
  installing an extension to satisfy a probe rather than to make the app work —
  the same call already made about GD and `UploadedFile::fake()->image()`.
- **`opcache.validate_timestamps=0`, so a deploy is a rebuild and a restart.**
  Correct for an immutable image, but it means editing a file inside a running
  container has no effect at all.
- **`public/hot` is in `.dockerignore`.** Vite writes it while `npm run dev`
  runs, and baked into an image it makes `@vite` point every page at
  `localhost:5173` — so CSS and JS silently never load, with nothing in any log
  saying why.
- **Postgres is not published to the host in production.** `docker-compose.yml`
  maps 5432 for psql and GUI clients; doing that on a public box exposes it to
  the internet.
- **Backups are `pg_dump` to the R2 bucket that already holds the photos**, and
  the script deliberately does **not** prune. Retention belongs in an R2
  lifecycle rule: server-side, no credentials on the box, and no delete loop
  running as root nightly. An instance snapshot is not a substitute — it catches
  the volume mid-write.
- **Meilisearch is not deployed.** `laravel/scout` isn't installed, so it would
  hold a few hundred MB on a small box indexing nothing.
- **The nginx config is parsed at build time**, using a throwaway self-signed
  certificate to satisfy the `ssl.conf` include the entrypoint normally writes
  at boot, then deleting all of it. Cheap because the alternative is expensive:
  a bad directive otherwise costs a full deploy, a ~213-second healthcheck
  timeout, and a log dig — and it presents as a *running* container, because
  php-fpm is unaffected by anything nginx does. When a config error can only
  surface as "unhealthy after four minutes", move the check to where it is
  cheap.

## Open questions

*None blocking.* The brand-placement disagreement that sat here was settled on
2026-08-03 — see [Settled decisions → Schema](#schema).

---

Narrative, rationale and the record of what happened when live in
**[docs/session-log.md](docs/session-log.md)**. When you finish a session, add an
entry there and promote anything that became a durable rule up into this file.
