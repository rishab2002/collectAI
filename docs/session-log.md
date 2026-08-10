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

## 2026-08-10 — A verification pass: what the file claimed vs. what the stack does

No feature was built. The session started as "read `CLAUDE.md` and see if
there's anything to add", which turned into checking every falsifiable claim in
it against the running stack, and then into two real bugs the checking exposed.

`php artisan test` green at **207 passed, 609 assertions** — the file said 202
and 580, its first wrong number.

Most of it held up exactly: twelve routes, the thresholds at 1.0 / 0.9 / 0.65,
no `env_file:` on the `app` service, Scout still absent, provenance at precisely
12,934 rows, `diablo` and `main` identical with nothing unmerged. The two-branch
trap the file leads with was not live.

**The counts drifted because the file was measuring a different thing than a
query does.** `Casting::count()` says 250; the file said 241. Both are right:
241 came from the wiki, and `CatalogSeeder`/`SubmissionSeeder` contribute the
other 9, along with 8 casting versions, 1 series, 2 releases and 3 variations.
Nothing was double-imported — the giveaway is that provenance never moved.
That's the durable form of the lesson, now in `CLAUDE.md`: **the test of what
the importer produced is a `record_sources` row, not a total.** Totals in a dev
database are the import plus whatever was seeded on top of it, and a future
session that reads 250 against a documented 241 will otherwise go looking for a
re-import that never happened.

**`decision` is the string `'pending'`, not NULL — and this one was learned the
hard way.** The first query of the review backlog used `whereNull('decision')`,
got 0, and reported the queue empty. It isn't: there are 12 pending proposals.
The failure mode is what makes it worth recording — an undecided proposal
carries the literal word, so the wrong query doesn't error, doesn't return
nonsense, and doesn't look wrong. It returns zero, which reads as "all done"
whichever direction the backlog is actually moving. The corrected count also
vindicated the file rather than contradicting it: 7 of the 12 are the importer's
held inferences, exactly as documented, and the other 5 belong to
`SubmissionSeeder`'s one submission and are fixture data, not work. Two queues,
counted apart.

**Nothing runs a queue worker, and the test suite structurally cannot notice.**
The `app` container's only process is `php artisan serve`, and
`QUEUE_CONNECTION=redis`. So capture enqueues `ReadSubmissionPhotos` and it
sits: the upload succeeds, the submission stays `queued`, `ocr_output` stays
null, and nothing logs an error anywhere. `CaptureTest` calls `Queue::fake()` on
purpose — without it the sync driver would make a live vision call during a
file-upload test — which means the one safeguard that would catch this is
deliberately disabled in the only place it could fire. Not a bug, but a fact
about running the app that the file didn't carry, and the symptom points at the
OCR agent rather than at the missing worker.

**Two symptoms, one dead link.** Reported as "all I can see is dashboard not
login, and dashboard gives a 404". Both came from
`resources/views/welcome.blade.php`, and the loop is neat enough to write down.
Logged in, `/login` bounces you off the `guest` middleware; Laravel's
`RedirectIfAuthenticated::defaultRedirectUri()` looks for a route *named*
`dashboard` or `home`, this app's is named `admin.dashboard`, so neither matches
and it falls through to `/`. `/` is the stock welcome scaffold, which swaps its
"Log in" button for a "Dashboard" one when authenticated — that is why the login
page was unreachable — and that button hardcoded `url('/dashboard')`, a route
this app has never had. Hence the 404. Fixed by pointing it at
`route('admin.dashboard')`; verified over real HTTP with a cookie jar rather
than by reading the template, because the interesting question was whether the
generated URL keeps the `:8000` port, and it does. The underlying sharp edge
stays: this app has no route named `dashboard` or `home`, so any future scaffold
leaning on that framework default will misbehave the same way. Setting an
explicit redirect target in `bootstrap/app.php` would close it, but that's a
decision about where a logged-in user should land, so it was left alone.

**The apex was documented as deliberately free, and was not free.** Asked why
nothing is connected to `collectai.club`, the honest answer needed measuring
rather than quoting. DNS is properly delegated to Cloudflare, but the apex and
`www` are *proxied* A records still pointing at Porkbun's parking service left
over from registration — `X-Service: pixie-default`, a `page_path: '/parked'`
analytics call, `X-Powered-By: PHP/8.0.25`, AWS ALB cookies. That explains an
asymmetry that would otherwise waste somebody's afternoon: Cloudflare reaches
that origin fine on port 80 and returns the parking page (`200`), but cannot
complete a TLS handshake against a certificate that doesn't cover this domain,
so HTTPS returns **525**. A 525 reads as a misconfigured origin, and the search
it invites is for a server that does not exist. There is also no deployment
configuration in the repo at all — no CI workflow, no `fly.toml`,
`render.yaml`, `Procfile` or tunnel config — so this is a deployment that never
existed rather than one that lapsed. `img.collectai.club`, by contrast, is
genuinely healthy: valid certificate, and `capture:check-storage` passes write,
read, public fetch and delete end to end. The photo half of the domain has been
connected since 2026-08-09; the app half never has.

**Why that sentence was wrong is the part worth keeping.** "The apex is
deliberately still free for it" wasn't a lie, it was an *intention* written into
a section whose whole job is to record measurements. It was accurate as a plan
and false as a fact, and it read as a fact for a day. *Current state* should say
what was observed and when — which is why the new subsection is stamped with
the date and the tools that produced it, and why deploying is now described as
**replacing** the parking records rather than creating records on an empty apex.
Getting that backwards leaves the parking page winning.

Promoted into `CLAUDE.md`: the corrected counts; the seed-vs-import warning; the
`decision` string; the missing queue worker; the `/` route-table note; and a new
*What is actually on the internet* subsection plus an ordered deploy checklist
whose steps each name the trap they avoid — Full (strict) rather than Flexible
gets its own line, because Flexible would clear the 525 while quietly sending
origin traffic unencrypted, which looks exactly like success.

## 2026-08-09 — Make/Model inference: the Metadata Agent

The 241 imported castings were all `pending` and unattributed, and each was
holding its own releases and variations back — 10,837 records in total, none of
them browsable. This is the step that clears that.

`php artisan test` green at **202 passed, 580 assertions** (154 before).

**The shape: one agent that decides, one service that writes.** `MetadataAgent`
turns casting names into attributions and touches no tables; `CastingIdentifier`
resolves those against the catalogue, attaches them and rules on them. Same
split the pipeline draws everywhere else, and it means every interesting
decision — which answers get written, which get published — is testable without
a model in the loop.

**Three verdicts, not two.** The output's `kind` is `real` / `fantasy` /
`unknown`, mapping onto the three states `castings` already has. A nullable
boolean would have conflated "no real car behind this" with "can't tell", which
is precisely the distinction `is_fantasy` was added to make. Sampling the real
names showed why the third one has to exist: `'64 Pontiac GTO` and `Bone Shaker`
are easy, but `Circle Tracker`, `Monster Vette`, `F-Racer` and `Honda Racer` are
not, and a model with only two options will resolve them by guessing. An unknown
verdict writes nothing and leaves the casting where it was.

**The score decides publication, never attachment.** This was the call that
shaped the rest. The obvious reading of "above the threshold it attaches" is to
withhold `model_id` below the bar — but a proposal for a casting with no model
is one nobody can act on: `Casting::approvalBlocker()` refuses to approve it and
`ProposalPresenter` renders it as "Not identified yet", so the review screen
would be asking for a ruling on nothing. Attaching always and gating on approval
gives the reviewer a concrete claim to say yes or no to, and it stays invisible
either way because the casting is still pending. Invisible and concrete beats
visible and vague.

**Confidence is the minimum of make and model, not the average.** Approving
publishes both, so an attribution is exactly as good as its weaker half.
Averaging would let a certain "Ford" carry a guessed nameplate over the line —
which is the failure mode, since the manufacturer is usually the easy part.
`chassis_code` is scored and attached separately: a generation nobody was sure
of shouldn't hold back a make and model that are certain, and a wrong chassis
code is a silent wrong fact on the one field that exists to disambiguate
generations.

**Batching, and the misalignment it introduces.** 241 castings one at a time is
241 round trips and the full wiki is 11,041, so the agent asks in batches of 25
behind a cached system prompt. That buys one new failure mode that nothing else
in this pipeline has: a batch is the one place a model can return a perfectly
well-formed answer *about the wrong car*. Structured outputs guarantee the shape
and therefore cannot catch it. So answers are matched back by casting id and the
schema requires the name echoed back verbatim; an id that wasn't in the batch,
or a name that doesn't match what was sent under that id, is dropped rather than
written. A dropped answer leaves a casting unidentified, which is recoverable; a
misfiled one publishes Bone Shaker as a Pontiac GTO on the GTO's score.

**Rejecting an inference is not rejecting the record.** `SubmissionProposal::
reject()` marked the proposable rejected, which is right on the capture path —
the pipeline invented the row, so "no" means it shouldn't exist. It is wrong
here: the wiki states the casting and its whole Versions table, and the only
thing inferred was the attribution. Marking the row rejected would throw away a
real, sourced casting and strand every release and variation beneath it under a
parent that can never go live. So `rejectProposed()` is now an overridable hook
on `HasApprovalStatus`, defaulting to the old behaviour, and `Casting` overrides
it: a casting with provenance has its attribution retracted and goes back to
being a real car nobody has named. `CastingIdentifier::outstanding()`
deliberately won't re-infer it — the rejected proposal is a ruling, not a gap.

**Publishing the descendants is a separate command, on purpose.** Identifying
241 castings makes castings, models and makes live and leaves 10,837 imported
records exactly where they were, because approval walks upward only and nothing
walks down. `catalog:publish-imported` is that step. It was kept out of
`approve()` because the upward-only asymmetry is a settled decision and adding a
downward cascade would change the capture path too — approving a casting would
start publishing unrelated pending variations under it.

Its gate is **"ancestors already live"**, not "no blockers". That distinction is
the whole safety property: a casting whose attribution scored 0.4 is pending and
perfectly approvable, so a blocker check would happily publish its releases and
make the reviewer's decision for them. A pending ancestor means a human is still
deciding, and the descendant waits with them. It also only touches records
carrying a `record_sources` row, so a capture's pending variation is never swept
up in a bulk publish.

**A bug worth recording, because the test passed anyway.** The publish command's
`--dry-run` first rolled back from *inside* `DB::transaction()`. Laravel's
wrapper then tries to commit, and its `if ($this->transactions == 1)` check —
which is what makes this harmless in production — is exactly true under
`RefreshDatabase`, so it committed the savepoint the test framework had opened.
The dry-run assertions still passed while every row it wrote leaked into the
next test. Fixed to the explicit `beginTransaction()`/`rollBack()` pattern the
importer already uses, with a regression test that asserts the transaction level
is where it started. The general shape: **a rehearsal that manipulates the
transaction stack has to leave it as it found it, and the assertion that it
rolled back is not the same as the assertion that it rolled back the right
thing.**

**The live run, same day.** All 241 castings through the agent in ~9 minutes of
API time across 10 batches, no failed batches: 137 identified, 82 fantasy, 22
honestly unknown. `catalog:publish-imported` then released 146 casting versions,
2,303 releases and 3,489 variations — the catalog is browsable for the first
time. Three things the run taught that no test could have:

- **The threshold was mis-set at 0.9, and the reason is structural.** In the
  first 25-casting sample every one of the 11 fantasy verdicts was correct and
  *none* scored above 0.88, while every real-car identification that cleared 0.9
  was also correct. Asserting "no real car exists behind this name" is proving a
  negative, and the model is consistently less confident about it — so a bar set
  where real-car identifications are safe leaves ~44% of the catalog needing
  hand-review of answers that were right every time. Moved to **0.85**, which in
  the sample would have added three correct fantasy calls and nothing wrong.
- **Passing the known-make list per batch works.** 39 makes across 10 batches
  with zero fragmentation — no Chevy beside Chevrolet, no VW beside Volkswagen,
  and correct historical marques (AMC, Mercury, Oldsmobile, Studebaker, Tucker,
  Willys) rather than everything collapsed onto the nearest famous name. This
  was the failure `makes.name` being hard-unique cannot catch, and it did not
  happen.
- **The chassis-code backfill was a real bug, and only a real catalog exposed
  it.** Every unit test passed because every fixture had one casting per model
  row; the wiki has seven Corvettes spanning four generations. **A rule about
  "the same nameplate across generations" cannot be tested on fixtures that
  contain one generation.** Nulling the bad code was the stopgap; splitting the
  rows was the fix — below.

### Generations became rows, same day

Auditing the chassis codes turned up 18 model rows collapsing 70+ castings:
Ford Mustang held ten spanning four generations, Camaro eight, Corvette seven.
`chassis_code` alone was never going to fix that, for two reasons:

1. **Not every generation has a code.** First-gen Mustang, second-gen Camaro and
   fifth-gen Camaro have none in common use, so keying on the code still
   collapses them. Every generation has *years*.
2. **A bare code is unreadable.** "C3" means nothing without "1968–1982" beside
   it — a catalog that can't render the distinction it just made is barely
   better than one that never made it.

So `models` gained `generation_start_year`/`generation_end_year`, the lookup key
became `(make_id, name, generation_start_year)`, and the schema went to v2 with
generation as one claim scored by one number. `generationLabel()` prints
`C3 (1968–1982)`, `1967–1969`, or `2020–present`. Note the create migration had
already named `(make_id, name, chassis_code)` as the natural key — the design
anticipated this; only the years were missing.

**The threshold lesson is the transferable one.** Generation got its own dial,
and the first two values were guesses that both failed in an instructive way. At
0.85 the re-split discarded 18 of 25 *correct* generations. At 0.75 it left
comparable castings on opposite sides of the line — `'65 Mustang` unplaced while
another Mustang of the same generation was placed — which is the tell that a
threshold is sitting on noise rather than signal. Probing the raw scores instead
of nudging the number showed why: **the distribution is bimodal.** A name with
no model year scores exactly 0.00; a real generation scores 0.66–0.92 and was
correct in all 22 sampled castings, including `'58 Impala` as a one-year body,
`'10 BMW M3` as E92 and `'69 Shelby GT500` as 1969–1970. The bar belongs in the
empty gap between the clusters. **Measure the gap; don't tune the number.**

**The queue, and the third option.** 83 proposals: 51 fantasy verdicts (every one
a genuine Hot Wheels original — Night Burner, Sky Knife, Mad Propz, RD-04) and
32 real-car attributions, all correct including the awkward ones ('57 Chevy as a
1955–57 Bel Air, '49 Drag Merc as a Mercury Eight, '70 AAR Cuda as an E-body
Barracuda). 76 approved; **7 deliberately left pending**, which is the part worth
recording. Those seven are the castings whose stored nameplate the v2 prompt now
strips a trim from, and neither button is right for them: approving publishes a
near-duplicate model row beside the generation rows, rejecting discards an
attribution that isn't wrong, only differently scoped. A review screen offers two
actions, but **not ruling is a third and sometimes correct one** — deciding
whether a trim deserves its own model row is the near-duplicate judgement
`models.name` has no unique constraint *for*.

Final: 218 live castings, 3,772 releases, 5,543 live variations — 88% of the
import — under 110 model rows, 54 of them carrying a generation.

Two things the re-split fixed on its own that had been written up as needing a
human: `Nissan Skyline GT-R (R32)` came out from under the seeder's R34 row, and
`Toyota GR Supra` moved off A80 onto its correct A90. What it deliberately did
*not* do was re-attribute the 7 castings whose make/model the v2 prompt now reads
differently (`Corvette Grand Sport` → `Corvette`, `911 GT3 RS` → `911`) — the
guard reported them and left them alone, because silently re-attributing records
an admin approved, under a command whose job is splitting generations, is a
change nobody asked for and nobody sees. That skipped list is now the
near-duplicate worklist.

The judgment calls the threshold correctly kicked to a human were the ones worth
kicking: `Back to the Future Time Machine` → DeLorean DMC-12 (right, and right
to hesitate), `'69 Shelby GT500` → Shelby rather than Ford (defensible, but it
means Shelby and Ford now coexist as makes), and `'63 Ford Mustang II Concept` →
"Mustang II", which was **wrong** — the '63 show car is not the '74–78
production Mustang II, and the bare nameplate would have collided with it. That
last one was retracted, which exercised `Casting::rejectProposed()` on a real
row: casting kept, provenance intact, attribution cleared, and the rejected
proposal correctly keeping `outstanding()` from re-proposing the same claim.

**Running the command with no key found the second bug**, which is the argument
for running things even when you know they'll fail. `identifyBatch` caught only
`MetadataFailedException`, so the SDK's own exceptions went straight past it: a
401 stack-traced out of the command, and — worse — a single transient 529 partway
through would have ended a 241-casting run and lost every batch after it. Now a
rate limit, a 5xx, a dropped connection or a refusal is recorded as a failed
batch and the run continues, while `AuthenticationException` and
`PermissionDeniedException` are re-thrown on purpose: nothing about the next
batch would differ, and swallowing them means grinding through ten batches to
produce a report that reads like the model was unsure rather than like the key
is wrong. The command catches those two, prints the partial summary — six
batches of real work is the difference between resuming and starting over — and
exits non-zero with the fix.

**The credential is deliberately not called `ANTHROPIC_API_KEY`.** That name is
first in the resolution order for Claude Code, the Anthropic SDKs and the `ant`
CLI — ahead of an OAuth subscription profile — and Anthropic's own CLI docs call
it "the #1 auth trap". This repo is edited with Claude Code and its `.env` sits
in that working directory, so the cheap guarantee is that the file never carries
the name: anything that later sources it (direnv, a `set -a; source .env` in a
launcher, a CI runner) then changes nothing about how the editor authenticates.
Laravel reads `.env` off disk itself and `AppServiceProvider` passes the key to
the client explicitly, so the value never needs to be in the process environment
at all — which is the same reason `docker-compose.yml` must not gain an
`env_file:`. Worth knowing the blank-value form too: an empty
`ANTHROPIC_API_KEY` still wins its precedence slot and authenticates with an
empty key, so setting it blank is not the same as leaving it unset.

Also folded in: the API key moved from `catalog.ocr.api_key` to
`catalog.anthropic.api_key`, since two agents now share one account and one
client; and the dashboard grew a second queue, kept apart from submissions
because these have no photos and the only thing to check them against is the
source page.

## 2026-08-09 — Two importers reconciled into one

Opened the repo to carry on with Make/Model inference and found `php artisan
test` reporting **158 failed, 29 passed**. The first assertion never ran: a
migration died on `column "toy_number" of relation "variations" already exists`.

Both `huracan` (PR #10) and `aventador` (PR #11) had built the entire Hot Wheels
Wiki importer, independently and in full, and both had been merged into `main`.

**The reason it merged cleanly is the interesting part.** The two versions
disagreed mostly on *filename*, not on content — `WikitableParser.php` against
`Wikitext/WikitextTable.php`, `WikiClient.php` against `PageSource.php` and its
two implementations, `import:hot-wheels` against `catalog:import-hot-wheels`.
Git had almost nothing to conflict on, so it took the union and reported
success. `main` ended up with two commands, two wikitext parsers, two DTO sets,
two `ImportSummary` classes and two migrations stamped `2026_08_09_100001` both
adding the same column. The lesson promoted to `CLAUDE.md`: a clean merge
reconciles text, not designs, and nothing about the absence of conflicts means
the result is coherent.

The dozen files whose paths *did* collide all resolved to huracan's version,
while the code around them came from aventador. That is how `config/catalog.php`
came to ship `import.auto_approve_threshold` at 1.0 while `CLAUDE.md` — merged
from the other side — documented it as 0.9. A silent disagreement between the
code and the file that calls itself the source of truth is exactly the failure
mode the second new rule is aimed at.

**Kept aventador.** Both were real, complete and well tested, so this was a
judgment call rather than a discovery, made on four facts:

- The dev database was already migrated *and* imported with it — 12,934
  provenance rows produced by aventador's importer, against huracan's zero.
- It carries `DumpPageSource` and the `p7zip` line in the Dockerfile. Huracan's
  `WikiClient` is API-only, and going wide over 11,041 pages through the API is
  a crawler with extra steps against someone else's wiki.
- Its three migrations are the schema decisions `CLAUDE.md` records as
  *measured* rather than argued: `country` in the variation uniqueness index (11
  collisions in 1,183 real rows without it, 5 with), `tampo` widened to `text`
  (a convention car runs to 328 characters, and tampo is in that index, so
  truncation silently changes which cars count as the same car), and
  `submission_id` nullable.
- 47 tests against 28, and six verbatim casting-page fixtures against four.

Restoring aventador meant pulling its version of the ten contested files back
out of `c8f9f48`. Huracan's `isImported()` helper on `HasApprovalStatus` went
with them; nothing outside its own deleted test used it.

**Ported `--dry-run` across**, the one thing huracan's command had that
aventador's didn't. It rehearses by writing inside a transaction and rolling
back, deliberately *not* by parsing and skipping the insert. The failure a
rehearsal exists to catch is a write that collides — and a source-key collision
is precisely what killed a real run during the original build. A dry run that
never reaches the insert would report that import clean and then die partway
through the real one.

Verified the way the schema rules in `CLAUDE.md` demand, from zero rather than
forward from an already-migrated database, which is the only way this collision
was ever going to surface: `migrate`, `rollback`, `re-migrate` and
`migrate:fresh --seed` all clean, **154 passed**, Pint clean. Then re-imported
2011 against the live wiki — 27 seconds, 241 castings, 258 casting versions,
1,856 series, 4,284 releases, 6,295 variations, 12,934 provenance rows,
identical to the pre-merge state, and a second run changed no count.

No feature work happened this session. Make/Model inference is still the next
thing, now on a base where the test suite runs.

---

## 2026-08-09 — The Hot Wheels Wiki importer landed

The implementation half of the decisions settled earlier the same day, in the
two parts they were planned in: the `record_sources` migration first, then the
importer. **2011 is imported for real** — 242 castings, 366 releases, 650
variations, 79 series, from the live wiki. Suite green at **132 tests, 492
assertions** (was 92/267).

Both gates were run for the schema work: `migrate:fresh --seed` clean,
`migrate`/`rollback`/`re-migrate` clean, full suite green.

**Two migrations, not one.** `record_sources` was expected.
`variations.toy_number` was not, and it came out of the data rather than from
design: the 2011 Nightburnerz Nissan 350Z is a single release by the natural key
(casting version + series + year) and carries **two** toy numbers, T9819 and
T9950, one per colour. So a toy number can't live on `releases` — it is
literally "what distinguishes one unit of a release from another", which is the
definition `variations` exists for. It also closed a hole nobody had noticed:
the OCR agent already extracts a toy number as its own field, and it is the most
reliable key a card carries, but there was nowhere to store one — so the
Comparison Agent could only ever have fuzzy-matched on names. It is in the
uniqueness expression index and has its own trigram index, for the same reason
`releases.collector_number` does: OCR misreads 8 for B.

**Three parsing bugs, all found by running it rather than by testing it.** Each
is now a regression test with a fixture taken verbatim from the live wiki, and
each failed *silently* — which is the part worth remembering:

- **`rowspan` is load-bearing.** The versions tables lean on it constantly: a
  release sold with four wheel types is one row carrying `rowspan="4"` on every
  shared cell, then three rows holding only what differs. Row two has three
  cells where the header has thirteen. A positional parser doesn't lose data, it
  *misfiles* it — reading a wheel type as a collector number. Carrying spans
  down is also what makes the grouping legible: a rowspan block is a release,
  each row in it a variation.
- **A `|-` before the header row.** The 350Z writes `{|` straight into `!Col #`;
  the '07 Shelby GT-500 puts a separator between them. Treating the first block
  as the header regardless parsed every page of the second kind to **zero rows**
  — and a page with no rows is indistinguishable from a casting with no releases
  that year, so it was reported as a routine skip. Cost 12 castings.
- **Pages carry more than one versions table**, and they are not ordered by
  year. The XS-IVE's 2011 release sits in its second table. Reading only the
  first dropped it, again as a "skip". Cost another 9.

Skips went 20 → 9 → **0** across those fixes. The lesson is in the failure mode
rather than the bugs: every one of them looked exactly like an ordinary absence.
A parser that reports "nothing for that year" is not obviously wrong, so the
only thing that caught them was checking that a car known to be in the 2011 list
actually landed.

**A fourth bug appeared only against live data.** Source keys were slugged,
which folded case and punctuation, so "Monster Jam: Monster Duo" and "Monster
Jam - Monster Duo" — two series the wiki lists separately — produced one key,
and the import died mid-run on the unique constraint. Keys now use the name with
only whitespace normalised. The general rule, now a comment on the method: a
source key exists to identify exactly one record, so any transform that can map
two records onto one key can only ever fail — loudly there, or silently by
overwriting. It also keeps the key in step with how a series is resolved, which
is an exact match on the name.

**The import lands almost entirely pending, and that is the point.** The wiki
states no make or model in a structured column, so the importer never sets
`model_id`; an imported casting is therefore "a real car, not identified yet",
which `Casting::approvalBlocker()` refuses to publish — and since a release
hangs off a casting version which hangs off that casting, the whole chain stays
pending with it. What goes live is the brands and series. What is left is 242
invisible castings waiting for attribution, exactly the backfill queue the
settled decision predicted, instead of 11k review items or a catalog full of
cars nobody can search by make. Worth stating plainly because it looks like a
failed import: `submission_proposals` is untouched at 5, still only the
seeder's.

**The blocker check is in the importer, not inherited.** `SubmissionProposal::
approve()` tests `approvalBlockers()`, but `promoteWithDependencies()` does not
— so a bulk writer calling it directly would have published exactly what the
rule exists to hold back. `CatalogImporter::publish()` checks before promoting.

**Provenance, and what deliberately has none.** Castings, releases and
variations key off the page; series key off the series name, because a series is
shared across castings and keying it to whichever page mentioned it first would
make the second page collide. `CastingVersion` 1 gets **no** provenance row on
purpose: a `record_sources` row asserts that a source states this record, and
the wiki states no such thing — version 1 is a requirement of our schema, and
attributing it to the wiki would be a false claim about where it came from. Its
parent casting carries the attribution.

**The API, not the dump — for now, and not in contradiction.** The settled "use
the dump, not a crawler" decision is about importing the whole wiki: 11,041
articles one request at a time, against one 16.5 MB download. A single year is a
different shape — the 2011 index names 242 distinct castings, and batched 50
titles per request that is five calls. The dump reader is what the full-wiki run
needs and is **genuinely not built**; `WikiClient` says so in its docblock so
the next session doesn't mistake its absence for the decision having been
reversed.

Re-import is idempotent, verified against the live wiki rather than only in
tests: a second full run created nothing and reported all 1,858 records
unchanged.

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
