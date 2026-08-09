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

## 2026-08-09 — The Hot Wheels Wiki importer, built and run

The importer the previous session settled the shape of. Suite 92 → **153
passed, 440 assertions**. Ran for real against the live wiki: **241 castings,
4,284 releases, 6,295 variations in 28 seconds**.

Built in the order CLAUDE.md set out — `record_sources` first, then the
importer — but the middle of it turned into a second schema session, because
running the parser over real pages found three things the catalog had no room
for. Those were put to the user rather than decided unilaterally, per the
separate-sessions convention.

**Reading real pages changed the parser's design twice.** CLAUDE.md's parsing
notes described the Versions table as mapping "almost 1:1 onto releases +
variations", and the two gotchas it named (`032/244`, `[[2011 New Models]]<br>32/50`)
were both real. Two it didn't name mattered more:

- **`rowspan`.** Versions tables use it heavily — Danicar states a colour once
  and spans it down, so the row beneath carries five cells where the header
  declares thirteen. Splitting on the row separator and zipping against the
  headers puts a toy number in the colour column, silently, and for exactly the
  rows that describe the interesting variants. Cells have to be laid into a
  grid with carry-over, the way an HTML table is.
- **Column names move.** The same column is headed `Notes` on one page and
  `Notes / Variations` on the next; some pages add `Clip Color` or `Fender
  Color`. Anything indexed by position is one edit away from reading the wrong
  column, so headers are matched by name and an unrecognised one is dropped.

**Then the sixty-page sample, which was the most valuable hour.** Rather than
trust three fixtures, the parser was run over the first sixty casting articles
the 2011 index links to — 1,184 rows. It was quietly dropping 11% of them, in
four distinct ways, none visible from the fixtures:

- **Five of fifty-seven articles open their Versions table with a `|-` before
  the header row.** Treating "before the first row separator" as the header row
  read all thirteen headers as data, left the table with no column names, and
  returned every field null on every row — indistinguishable from a page with
  nothing to import. This was the big one.
- **Three of sixty index links are redirects**, which parse as a page with no
  versions and were skipped in silence.
- **`'57 Chevy` has no `==Versions==` heading at all.** It files releases under
  "Closed Hood Versions" and "Exposed Engine Versions" — the wiki describing two
  physical tools, which is a `CastingVersion` each. Reading only a section by
  that exact name dropped the whole page.
- **`[[Code 3]] Versions`** sections are now excluded. A Code 3 car is one a
  third party repainted after it left the factory; Hot Wheels never sold it, so
  it is not a release.

898 of 1,008 rows imported before, 1,183 of 1,184 after, and no article yields
nothing. One thing that *looked* like a parser gap wasn't: 57% of rows have no
collector number, but broken down by year it is 8% in 2011 rising past 60%
later — the wiki stops recording them for non-mainline releases. Real data.

**Three gaps the settled decisions didn't cover, all put to the user.**

- **Toy # and Country had nowhere to live.** Against the existing uniqueness
  index, 11 of 1,183 rows collided with a sibling in the same release —
  identical on every distinguishing column, so the second insert fails and the
  car is lost. Toy number resolves three and country another three. Both were
  added to `variations` *and* to the uniqueness index, since being told apart is
  the whole point; toy number also got a trigram index, being the number
  collectors type in from the base.
- **`submission_proposals.submission_id` was non-nullable**, but the Make/Model
  split the importer infers has no capture behind it. Made nullable, rather than
  minting a synthetic photo-less Submission per run — which would have made
  `submissions` mean two things and put rows in the capture app's list that
  nobody captured.
- **The container had no 7z**, so nothing could read the dump. `p7zip` added to
  the Dockerfile.

**`variations.tampo` became `text`.** Found by the importer failing on `'57
Chevy`: a convention car's tampo runs to 328 characters against a varchar(255).
Four rows in 1,183 overflow; every other attribute measured stays under 65.
Truncating would be worse than it looks — tampo is part of the uniqueness index,
so a cut-off description doesn't just lose text, it changes which cars count as
the same car.

**Two implementation calls worth keeping.**

*Duplicates are detected before the insert, never caught after it.* Postgres
aborts the whole transaction on a failed statement, so catching the constraint
violation and carrying on would take down the rest of the page — and a page
listing the same car twice is ordinary, not broken. The check runs through
`Variation::attributeSignature()`, which builds the same `COALESCE(col, '')`
identity the index does, so the two agree by construction.

*Keys are raw titles, not slugs.* `Str::slug()` would collapse "Muscle Mania
(2012)" and "Muscle Mania 2012" to one key, and the importer would overwrite one
series with the other.

**What the live run showed.** 117 rows (1.8%) reported as indistinguishable from
one already imported — and they are not all duplicate listings. Pass'n Gasser
accounts for nine: real sub-variants whose only distinguishing fact is in the
Notes column — *"short-run small 'Goodyear' tampo on rear wheels"* — identical in
every structured column. Notes is the editors' prose and is not ours to take, so
the catalog records the car it can describe rather than inventing a distinction
from a field it never read. The skip message says exactly that rather than
claiming the rows are the same car. **This is the licence line's real cost, and
it is worth knowing rather than discovering later.**

Also worth knowing: importing "List of 2011 Hot Wheels" imports each casting's
*whole* Versions table, 1968 onwards — which is why 241 castings produced 1,856
series. The year index is a work list of tools, not a filter on years. That is
deliberate: the casting page is the unit of truth, and importing half of one
would leave provenance claiming the page was done.

**Nothing from the import is publicly visible yet, by design.** Brand and series
publish — the wiki states them outright. Castings land unidentified, because the
wiki never says which real vehicle a casting is based on in a structured field
and `is_fantasy` is a positive assertion that there is no real car behind it.
`Casting::approvalBlocker()` then keeps them, and everything under them, pending.
Resolving that attribution is the inference the next session owes, and the
nullable `submission_id` is the plumbing it needs.

## 2026-08-09 — Catalog import decisions (no code)

A decisions-only session, per the separate-sessions convention: the importer
touches the schema, so the shape got settled before anything was written. **No
migration, no importer, no tests changed** — the deliverable is
[Settled decisions → Catalog import](../CLAUDE.md#catalog-import-hot-wheels-wiki).
Suite untouched at 92/267.

**A dump exists, so there is no crawler to write.** The open item from the
earlier research was "check `Special:Statistics` before writing an API crawler."
Checked: `wikia_xml_dumps/h/ho/hotwheels_pages_current.xml.7z`, 16.5 MB, current
revisions, `Last-Modified: 2026-03-13`. `Special:Statistics` itself 403s to a
plain fetch and has no download link in its markup, and WebFetch returns 402 —
the dump had to be found by probing Fandom's S3 path directly. So the plan is
one download plus an API delta for the ~5 months since, rather than 11,041
requests with rate-limit and politeness handling. Siteinfo re-confirmed live:
11,041 articles, 165,987 images, CC-BY-SA, MediaWiki 1.43.9.

**Facts, not prose.** CC-BY-SA has attribution and share-alike obligations, and
the honest line is between facts and expression: a year, a colour and a toy
number aren't copyrightable, article prose and the free-text `Notes` column are.
Importing only the structured wikitable columns keeps the licence question from
arising, and it's also what makes this an original database rather than a mirror
— which CLAUDE.md lists as the first acquisition priority. Together with the
existing "don't import the images" call, the legal surface is close to nil.

**The two calls that were genuinely open:**

- **The importer gets its own auto-approve threshold;
  `catalog.review.auto_approve_threshold` stays 1.0.** The wiki has no
  Make/Model split and no fantasy flag, so that inference is an LLM step, and at
  11,041 castings per-row review is not a thing a person does. Something had to
  auto-approve. Scoping it to the importer keeps the capture path's invariant
  exactly as it was — nothing a person photographed publishes itself — and the
  two paths get tuned independently, which they should, because a wikitable row
  and a phone photo of a scratched card are not the same evidence.
- **Provenance is a polymorphic `record_sources` table, not columns on eight
  tables.** Same call and the same reason as `submission_proposals`: audit
  metadata in the middle of the domain model is what that migration was written
  to avoid, and "everything from revision N" / "everything stale" wants to be one
  indexed read. `source_key` is keyed per record rather than per page —
  `page:12345` for the casting, `page:12345:row:7` for a release — because one
  article yields one casting and many releases, and idempotent re-import depends
  on `(source, source_key)` being unique.

**The nicest part is that the gate already exists.** An imported casting whose
make and model can't be inferred confidently stays `pending` rather than going
live unattributed — which is precisely what `Casting::approvalBlocker()`, added
yesterday for the review screen, already enforces. So low-confidence inferences
leave a queue of invisible rows to backfill, not 11k review items and not a
catalog full of cars nobody can search for. A guard written for one reason turned
out to be the mechanism the import needed.

Deleted the `hotwheels-fandom-import` memory afterwards: everything in it now
lives in CLAUDE.md, which a fresh session loads automatically rather than having
to recall.

## 2026-08-09 — The OCR agent, and the first real pipeline output

`php artisan test` green: **92 tests, 267 assertions**. `migrate:fresh --seed`
clean against both databases. Note it rotates the admin password whenever
`ADMIN_PASSWORD` is blank, because `AdminUserSeeder` generates and prints one —
set it in `.env` if you'd rather a reseed didn't.

The first agent exists. `app/AI/schemas/ocr_output.json` defines what a reading
is, `App\Services\AI\OcrAgent` performs the vision call, and
`App\Jobs\ReadSubmissionPhotos` runs it off the queue — the capture endpoint now
dispatches that job, which is what CLAUDE.md always said the controller's job
ended at. A capture goes queued → processing → completed with a reading the
review screen renders. Nothing proposes catalog records yet: Metadata,
Comparison and Insertion are still unwritten, so a completed submission has a
reading and no proposals. That is a legible intermediate state — you can look at
a photo and see what the model made of it before anything is built on top.

**One schema file, used twice.** It's sent to the API verbatim as the
structured-output schema *and* it's the contract for `submissions.ocr_output`.
Keeping it as one file is what stops the prompt and the stored shape drifting
apart, which is the failure CLAUDE.md called out when it asked for a versioned
schema. Two consequences follow from being the request schema: it has to stay
inside the API's supported JSON Schema subset, so the 0-to-1 bound on confidence
is prose rather than `minimum`/`maximum`; and every field is required with
`null` allowed, because an omitted key and an unreadable field are different
claims and only one of them is information.

**Per-field confidence, not one score.** A card can have a logo filling half of
it and a toy number in four-point type. One number for the whole read tells a
reviewer to check everything or nothing; six tell them which field to put their
eyes on. The review screen renders each score beside the field it scores and
flags anything under 80%.

Four things worth knowing that only showed up while building it:

- **HEIC photos are stored and cannot be read.** The capture screen accepts
  HEIC/HEIF deliberately — iOS Safari usually transcodes to JPEG but "usually
  isn't a validation strategy" — and Claude vision accepts only JPEG, PNG, WebP
  and GIF. So the agent maps each stored photo's extension to a media type and
  skips what it can't send, failing the submission with that reason if nothing
  readable is left. Converting isn't an option: the container has no GD, which
  is also why `CaptureTest` can't use `UploadedFile::fake()->image()`.
- **The photo budget goes to card photos first.** Photos are base64-encoded into
  the request body, and a capture can hold eight 12 MB images — sending all of
  them is a ~130 MB request to read text that's legible in two. Loose photos
  aren't excluded, though: a Hot Wheels base is printed with the casting name,
  year and toy number, so a loose-only capture is still readable.
- **The tests fake the PSR-18 transporter, not the SDK client.** A mocked client
  would assert that `OcrAgent` called a method with the arguments it was written
  to call — true by construction, and silent about whether `mediaType` reaches
  the wire as `media_type`. Faking the transport means the SDK really serializes
  the request, so `OcrAgentTest` asserts on the actual JSON body and the
  parameter names are proven without an API key or a network call.
- **The suite runs the `sync` queue driver.** Dispatching from the capture
  controller therefore ran the OCR agent inline inside `CaptureTest` and reached
  for the Anthropic API from a test about uploading files. `Queue::fake()` in
  that test's `setUp` is not tidiness — without it the file-upload tests make
  live API calls.

**Model choice.** `claude-opus-5` at `high` effort, both configurable.
`OCR_EFFORT` is the dial to sweep before touching the model: a misread here
becomes a catalog row an admin has to notice is wrong, and the review time that
costs dwarfs the token difference. Refusals are handled — a declined request is an
HTTP 200 with empty content, so `stop_reason` is checked before `content` is
touched — and the call opts into a server-side fallback.

**`fallbacks: "default"`, not a pinned model.** The right substitute depends on
why a request was declined, and naming one is a migration owed the day it's
deprecated. The scalar form needs the beta namespace and the
`server-side-fallback-2026-07-01` header specifically; the array form is gated
by a *different*, earlier-dated header, and crossing them is a 400 — so those
two constants must not be "tidied" into agreeing. It's switchable via
`OCR_FALLBACKS` because the parameter is Claude-API-only and eligible
substitutes are per-model, so a different `OCR_MODEL` may have none. The PHP
binding came from reading the installed SDK (`fallbacks` is
`string|array|null` on `beta->messages->create`), not from guessing at it.

Also bumped `league/commonmark` 2.8.3 → 2.9.1, clearing six DoS advisories. It
is a Laravel dependency and was already vulnerable before this session; the
constraint allowed the fix without any other change.

## 2026-08-09 — Review screen and the PWA shell

The rest of the capture/review milestone. `php artisan test` green: **75 tests,
208 assertions** (was 55/137).

`/admin/submissions/{submission}` shows a submission's photos beside its
proposals, each with its confidence, and approves or rejects through the
existing `SubmissionProposal::approve()` / `reject()`. The controller
deliberately does not walk the hierarchy itself — approving a leaf already
promotes its ancestors, and a second copy of that walk in a controller is a
second thing to keep in step with the first.

Four things came out of building it that weren't obvious beforehand:

- **Approving a leaf left its ancestors' own proposals pending.** The walk
  publishes the parents, but only the leaf's proposal row was being marked
  decided — so the seeded five-proposal submission would go live and still sit
  in the queue reporting four things to rule on, each offering Approve buttons
  for records that were already public. `approve()` now rules on the proposals
  of everything it promoted, with the same admin and timestamp, because that is
  what actually happened. Rejection still doesn't cascade.
- **`castings.is_fantasy` was documented as blocking approval, and nothing
  enforced it.** CLAUDE.md has said since 2026-08-02 that a real casting with no
  identified model "must block approval rather than go live unattributed", but
  the promotion walk skips a null `model_id` without comment — that was the
  whole point of adding the flag, and the gate was never built. The review
  screen is the first code that can actually publish anything, so the guard went
  in at the domain level (`approvalBlocker()` on the trait, overridden by
  `Casting`, raised as `ApprovalBlockedException` from `approve()`) rather than
  only in the controller. A future bulk auto-approve inherits it; a screen that
  forgets to check cannot bypass it.
- **`SubmissionPhoto::url()` never worked on the local disk.** Laravel's
  local-disk file server refuses unsigned requests for any disk not declared
  `public`, and `url()` doesn't sign — so `CAPTURE_DISK=local`, which exists
  specifically so capture can be exercised without R2, produced photos that
  403'd on every render. Not noticed before because nothing displayed a photo
  until now. Both photo models share `ResolvesStoredUrl`, which signs only for
  private local disks: R2 must keep using its plain custom-domain URL, since a
  presigned R2 link points at the S3 API endpoint, which isn't publicly
  reachable at all.
- **The layout wasn't a document.** It began at `<meta name="viewport">` with no
  doctype, no `<html>`, no `<head>` — which meant every page rendered in quirks
  mode, and `<link rel="manifest">` had nowhere to live. Tailwind's preflight was
  hiding the box-model consequences. The shell is now a real document; verified
  `document.compatMode === 'CSS1Compat'`.

The **service worker caches almost nothing, on purpose.** Only content-hashed
Vite output, the icons and one offline page. HTML is never cached: a stale
review queue listing already-decided proposals invites ruling on the same record
twice, and the second ruling fails with no explanation. Non-GET requests are
passed through untouched — a worker that retried or buffered them would produce
duplicate captures and approvals applied out of order, which is why there's a
test asserting that guard rather than trusting review to catch its removal.

Verified in a browser against the running stack: manifest and all three icons
200, service worker active at scope `/`, signed local-disk photo loads, and the
screen renders correctly at a 375px viewport.

Not done: `/` is still the stock Laravel welcome page and doesn't use the app
layout, so it carries no manifest link — installing works from `/login` or
`/admin/*`. Worth fixing whenever the public catalog replaces that page.

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
