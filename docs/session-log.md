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

## 2026-11-05 — "New Models" is not a sub-series

The owner's point: on a mainline card the wave is printed up the right-hand
edge — "HW J-Imports", "Factory Fresh" — and "2009 New Models" is not that.
It records that the casting was new that year. The wiki's Series column
carries it anyway, so the import had created a wave per year, each holding
twenty-odd cars that were really mainline cars, burying the waves that exist.

Two ways to do it, and the obvious one is wrong. Deleting the rows and moving
their releases onto the line reads like what was asked, and it does not
survive a re-import: the importer keys a series by its name, and
`record_sources` allows exactly one provenance row per record, so folding
many source keys onto one series row either loses keys or breaks that
constraint. The rule that source keys are never lossily transformed exists
for this.

So it is a presentation rule instead. `config/catalog_lines.collapse` is one
regex; a series matching it is shown, grouped and browsed as the line it sits
under — which is not a new concept, because a series sharing its line's name
already behaves that way and `Series::path()` already collapses it. The rows,
the releases and the provenance all stay put. Qualified names are left alone:
"2005 First Editions: Realistix" and "2006 First Editions 5-Pack" say more
than "new this year" and remain waves.

### A Postgres detail worth keeping

The browse has to group by the same rule, so the collapse needed to exist in
SQL as well as PHP. Written the obvious way — `s.name ~* ?` with the pattern
bound — the query fails with "column s.name must appear in the GROUP BY
clause", even though the SELECT and GROUP BY expressions read identically.
They are not identical to Postgres: each `?` is its own parameter, so the two
CASE expressions differ by the parameter they reference and neither matches
the other. The pattern is now written into the SQL. It is a constant in a
config file, no more user input than the SQL around it, and its quotes are
escaped regardless.

---

## 2026-11-05 — A brand vocabulary that grows, and the short-name trap

Four hardcoded brands, in two places, was the wrong shape for a catalog that
keeps meeting new makers. The owner named Schuco, Para64, Inno64, Shadow
Model, BBR, TLV, Herpa and Majorette and said there are lots more.

`data/brands.json` is now the one vocabulary, read by the card reader and the
logo reader alike — the same arrangement `colour_families.json` has, for the
same reason. It is seeded with about forty makers and, more importantly, is
**not the limit**: every brand name in the catalog index joins it at scan
time, so a brand entered on the Add-a-car form is recognised from the next
export with nothing edited in the recognizer. The two halves cover the two
ways a new maker appears — one somebody has entered, and one that is only on
a box on the table.

### The measurement that nearly produced the wrong design

Going from 4 brands to 44 broke the logo reader badly. The same photographs
that had produced *no* wrong brand now produced **ten**: Welly, Peako, Norev,
Herpa on cards that say Hot Wheels. The obvious reading was that a garbled
logo read ("Hor wres", "JTIATLHBOX") only works by elimination against a
handful of names, and that the earlier clean result had been the short list
doing the work rather than any skill. So the first fix was two vocabularies —
the long one for printed type, a catalog-only short one for artwork.

That was treating the symptom. Looking at *which* brands were wrong gave it
away: Herpa, Norev, Welly, Peako, IXO — all five letters or fewer. A card's
crop carries seventy-odd characters of OCR noise round its printing, and in
that much garbage a five-letter name will always find a five-letter window
that matches it at 0.80. The length of the list was not the problem; short
names being matched *fuzzily* was.

With spellings under eight characters required to be spelled out as whole
words, 44 brands measure exactly as 4 did — no wrong brand on any photograph,
by either route — and scoping artwork to the catalog measured identically to
not scoping it. So the second vocabulary came out again. Keeping it would
have cost something real: a Schuco logo could never have been read, because
Schuco is not in the catalog, which is the whole case for having a seed list.

"Whole *words*", plural, matters: "Mini GT" compacts to six characters across
two tokens, so a single-token test would never find it however plainly the
box says it. The check is against every run of up to three consecutive words.

Eight is where the catalog's own short names stop needing fuzziness —
"matchbox" is exactly eight and does need it, because OCR drops a letter out
of a word it otherwise reads cleanly.

### A note on TLV

The owner listed TLV and TLV Neo as brands. They are Tomica's lines, and the
importer files their cars under the brand Tomica with the line in the series
field, so the vocabulary maps them to Tomica rather than minting brands the
schema would then have to reconcile. Same for Kaido House under Mini GT.

---

## 2026-11-05 — A 500 in the review queue, and queueing a scanned car

### The 500

The owner reported that the '92 Honda Prelude in the review queue 500s when
clicked. It rendered fine from a clean database and 500ed against the real
one, which narrows it to data: `ProposalPresenter::for()` was handed a null,
because one proposal pointed at a `VehicleModel` that no longer existed.

`submission_proposals` is polymorphic, which was a deliberate choice and a
good one — audit metadata out of the domain tables, "everything awaiting
review" as one indexed read. The price is that a polymorphic reference has no
foreign key, so **nothing in the database can keep a proposal and its record
in step**, and that price has to be paid in the application. It had not been.
One deleted model in 224 proposals was enough to make the whole submission
queue unreachable.

Paid now in two places, because they fix different things. `HasApprovalStatus`
deletes a record's proposal when the record goes, which stops new orphans.
That cannot help rows orphaned before it existed, so both review queries also
carry `->recordStillExists()`. Cheap to exclude, and far cheaper than a queue
the admin cannot get past.

### Queueing a car instead of publishing it

The add-from-scan form published immediately, which is what typing a car in
has always meant: the admin is the reviewer, so there is nobody else to wait
for. A car read off a card at a glance is a weaker claim than one typed from
the car in your hand, and the owner asked for those to be checked before the
public catalog has them. `createCar(..., publish: false)` saves the records
pending and files one proposal.

**The proposal goes on the variation, not the casting**, and the test that
found this is worth keeping. Approval walks upward only — a settled
asymmetry — so a proposal on the casting publishes the make, model and
casting and leaves the release and variation pending: the car is "approved"
and still not in the catalog. On the variation, one click carries the whole
chain up with it.

A pending variation cannot be collected, which is true everywhere in the app,
so a queued car does not go into the collection until it is approved. That is
the right order for what was asked — check it, then both things happen — and
the screen says so rather than leaving the collector wondering where the car
went.

### What was not built, and why

The same request asked for brands to be recognised from boxes and cards using
the training data, and for Google to verify new cars. Neither was built this
session; the reasoning is in the reply and the short version is:

- **Google is ruled out by rules already in this file.** Automated querying of
  Search or Images is against Google's terms; the compliant route is a paid
  API, which collides with the owner's own standing requirement that the site
  must not depend on an outside API. The permitted sources that do the same
  job are the Fandom wikis (CC-BY-SA, already imported) and `reference_items`,
  which the owner holds written permission for. That is a decision for the
  owner, not something to quietly do either way.
- **Brand from the trained model** is real work rather than a refusal: the
  gallery is labelled by casting and trained on loose castings photographed
  from above, so a card embeds nowhere near it. It needs a brand gallery built
  from packaged product shots — `reference_items` has them, with brands, under
  the permission the owner holds — which is a training job with its own
  calibration, not an afternoon's change.

---

## 2026-11-05 — Pick-or-create labels, and logos read as text

Two things the owner asked for after the first pass at labelling from a scan,
with a photograph of a mainline card as the worked example.

### Pick from what exists, or make a new one

The add-from-scan form was free text throughout, which resolves correctly —
`ManualCatalogEntry` matches an existing name exactly and case-insensitively,
or creates — but gives no way to *see* what already exists. On an ever-growing
catalog that is how you end up with "factory fresh" sitting beside "Factory
Fresh": not a bug in the resolver, a bug in the typing.

`CatalogVocabulary` fills a `<datalist>` for brand, series, sub-series, make
and model. A datalist is the right shape here precisely because it is not a
`<select>`: the input stays plain text, so the list suggests without
constraining, and it needs no JavaScript, so it does not touch the CSP rule
that forbids inline script. Two things it has to get right:

- **Scope and cap.** A full import is ~6,500 series, which as options is
  megabytes on a phone. The list is the card's brand's, ordered by how many
  releases each series has, capped at 400. A series missing from it costs
  nothing — typing it finds it.
- **Lines come from `config/catalog_lines.php` as well as the database.**
  Reading lines out of the database alone gives nothing useful on a database
  where `catalog:classify-series` has not run: every one of the dev
  database's 1,857 series has no parent, so all 1,857 look like lines. The
  config is the editorial list the classifier itself applies, so offering it
  keeps a hand-entered car in the same lines an imported one lands in.

The form now says **Series** and **Sub-series**, which is what a card says,
and `StoreScanCarRequest` maps those onto the `line`/`series` pair the service
takes. An empty sub-series hangs the release on the line itself.

### The Hot Wheels logo is text, and can be read as text

The packaged-scan work had recorded, as a measured limitation, that Tesseract
does not read the Hot Wheels flame and that recognising a logo is a job for
the visual model. The first half was right and the conclusion was too quick.

Tesseract does not find the logo when it is reading a whole card — it is a
slanted, outlined word on a field of flame, and a page-level pass walks past
it. Handed *just the logo*, at psm 8, it reads "Hor wres". That is garbage,
and garbage is enough, because the thing being identified is one of four
brand names rather than one of fifteen thousand castings. Measured:

| | Hot Wheels | best other |
|---|---|---|
| "Hor wres" (HW flame) | **0.62** | 0.27 |
| "JTIATLHBOX" (Matchbox wordmark) | 0.21 | **0.80** Matchbox |
| "MTATLHBON WWF" | 0.00 | **0.75** Matchbox |
| PORSCHE / CHALLENGER / FACTORY FRESH / GUARANTEED FOR LIFE | ≤ 0.38 | ≤ 0.36 |

Real wordmarks 0.59–0.80, everything else ≤ 0.40, so the bar goes at 0.55 in
the empty gap — the same way the generation threshold was placed, and for the
same reason: a bar inside a cluster is a bar sitting on noise.

**The band is red through yellow, and that was the whole difference.** Red
alone is the obvious choice for a Hot Wheels flame and a Matchbox box, and it
found not one logo in the owner's photographs. The Premium logo is *yellow on
black*. Red-only measured zero; red-plus-yellow with the saturation floor
dropped for dim indoor light measured five of eight packages on the
two-packs photo and 0.71 on a close-up card.

Two rules keep it honest. **Type beats artwork**: a brand spelled out in the
small print is read properly and wins, and the logo only answers when there
is no printed brand. And **a logo-read brand corroborates but never counts
against a casting** — the matcher's -0.2 for a brand that disagrees applies
only to a brand read as type. Same shape as the decision about a card's ©
year, for the same reason: weaker evidence should be allowed to help and not
to veto. It is reported at confidence 0.6 rather than 0.75.

What it does not do: read the *name* off artwork. A logo says which company
made the car, and that is all it is allowed to say.

The measured limits are worth keeping in view. It is reliable on a card that
fills the crop and patchy in a pile, where the logos are small and dim — and
it moves with the Tesseract version, branding five packages on one photo
under 5.5.4 and none under the 5.3.0 in the container. No wrong brand
appeared in anything measured, which is the property that matters.

---

## 2026-11-05 — Labelling a car the catalog hasn't got

Immediately after the packaged-scan work, the owner put the hole in it plainly:
the scanner is wrong most of the time, and when it is, the only thing the Scan
page offers is a search over **published** castings. Their example was a Hot
Wheels mainline card — "Hot Wheels · Mainline 2026 · Porsche 911 Carrera T ·
grey" — which is not in the catalog and therefore could not be named at all.

The circularity is the point. A crop becomes a training label only once it
points at a variation (`catalog:export-training-data` takes consented crops
with `decision = added` and a `variation_id`). So the cars most worth teaching
the recognizer — the ones it gets wrong, which are overwhelmingly the ones
nobody has entered — were exactly the ones that could never be labelled. The
catalog being the bottleneck was already measured in the packaged-scan work;
this is the same bottleneck seen from the other end, and it is fixable without
importing anything.

**It reuses `ManualCatalogEntry::createCar`** rather than growing a second way
to write catalog rows. That keeps one set of rules — make and model or
fantasy, exact case-insensitive lookups, published through
`promoteWithDependencies()`, a duplicate variation refused with the id it
duplicates — and it keeps the request validation identical by reusing
`StoreCarRequest` itself. Admin-only, for the reason all of manual entry is
admin-only: what is typed goes live, and the admin is the reviewer. A
collector gets a 404, not a 403, like the rest of the admin surface.

**What the prefill will and will not guess** was the only real design
question. `CardSuggestion` fills in the brand, the year, the collector and toy
numbers, and the casting name re-cased from the card's capitals into the
catalog's spelling — leaving a word alone when it contains a digit or is three
characters or fewer, which is what keeps RX-7, STI, SRT and the trailing T of
a Carrera T from becoming Rx-7, Sti, Srt and t. It offers the **make** only
when the name starts with one `makes.name` already holds, matched exactly and
longest-first, because that table is hard-unique and a fuzzy hit would file a
Ford GT under Ferrari.

It deliberately does not offer the **model**. The printed name is the casting
name, "Porsche 911 Carrera T", while `models.name` wants the nameplate alone,
"911" — and guessing that split is precisely how the catalog ended up with
`Corvette Grand Sport` and `Hummer H2 SUT` sitting beside the generation rows,
a backlog CLAUDE.md still carries. Nor does it offer a **colour**: the
packaged scan reports none on purpose, because a sealed card shows its
artwork and not its car, and inventing one on the form would undo that in the
one place it matters.

---

## 2026-11-05 — /scan for cars still in their packaging

The Scan page was built for loose cars on a table: find car-shaped blobs
against the background colour, name each one's colour, read the base. The
owner's collection is not photographed that way. It is photographed as retail
packaging — blister cards and boxes, a dozen or more at a time — where the
signal is the large printed name on the card and nothing else.

### The loose-car segmenter finds nothing, and it is worth knowing why

The first version of the packaged mode looked for rectangles: packages are
oblongs, so propose rectangles from the background-difference mask and from
Canny edges, filter by area, aspect and rectangularity, and suppress overlaps.
On synthetic layouts this worked perfectly — twelve cards laid out in rows,
twelve detections, nine named. On the owner's real photographs it found
**zero**.

Two separate reasons, both worth recognising on sight:

- **A heterogeneous border makes the background threshold enormous.**
  `background_difference` reads the surface colour from the photo's outer 3%
  and thresholds at `max(18, noise95 × 1.6)`. When that border is part carpet,
  part blanket, part the next shelf and part a corner of another package, the
  95th-percentile noise is huge and the threshold with it, so essentially
  nothing counts as foreground. The mask for a photo of five packages came back
  as two yellow logos and nothing else. The loose-car instructions ask for a
  plain contrasting sheet precisely because of this; nobody does that with a
  collection.
- **Packages overlap, and rectangles need edges.** People fan them out or pile
  them. There is no gap to separate two cards by, and no four clean edges round
  any but the topmost one.

A third thing was wrong and is a generally useful trap: the edge detector used
the common "auto Canny" rule of thresholds at 0.66× and 1.33× the image's
*median brightness*. On a bright surface that puts the upper threshold at 255,
so only the boldest artwork is outlined at all, and a whole column of pale
packages is never proposed. Thresholds now come from the 97th percentile of the
photo's own gradient magnitude, which is what the rule was reaching for.

### What a package actually is, here

The reframing that made it work: **a package is identified exactly when its
printed name is visible, so find packages by their printing.** One Tesseract
pass over the whole photo at 2400 px, group the words into lines, keep the
lines that look like print, cluster the lines that belong together, and each
cluster is a package. A package whose name is hidden under the next one cannot
be named by anything, so not finding it costs nothing — it would be a crop with
no answer in it.

Rectangles kept a job: where one is found round a block of printing it gives
straight, deskewed bounds, and a rectangle with *no* printing on it is kept as
an unnamed package — usually one lying on its side, whose name the whole-photo
pass reads sideways and so does not read. The per-package reader tries every
rotation, so what the photo missed the crop can still get.

**Telling print from carpet** took a measurement rather than a guess. A blanket
or a rug yields over a hundred "lines" from Tesseract, and the discriminator
turned out to be case: real card names are six or more characters in capitals,
Tesseract's inventions are short and lower case. Hot Wheels and Matchbox both
print car names in capitals, which is nearly the whole catalog; a mixed-case
line has to have been read at ≥0.7 instead, which is what a real Mini GT or
Tomica name reads like and what invented words never do.

### Four false positives, each a different lesson

The discipline in this pipeline is that a wrong answer is much worse than no
answer, so each of these mattered more than the matches they cost.

1. **"2023 CHEVY® TAHOE™ Z71" → the casting "'57 Chevy".** Five shared letters,
   confidently, at 0.95. It won because the right casting was excluded: the Z71
   read as 271, so the rule that every number in a name must be read exactly
   ruled it out and left the fragment unopposed. A card prints the name in
   full, so a casting that accounts for a few letters of it is a fragment of
   the name rather than the car.
2. **A card printing "FERRARI" → the casting "Ferrari FF".** This one was
   self-inflicted and is the most interesting. The crop and the whole-photo
   pass are two readings of the same print and they disagree usefully — on one
   Matchbox card the whole-photo pass read "2024 TOYOTA TACOMA" at 92% while
   the enlarged crop scored TOYOTA at 0, correct and thrown away by the
   under-0.4 rule. Using both was right. **Concatenating them was not**:
   "FERRARI" twice is "…ferrariferrari…", whose window "ferrarif" matches
   "ferrariff" at 0.94. The join invented a letter pair that was never printed.
   They are now kept apart (`Evidence.alternates`) and matched separately.
3. **"'66 BUICK RIVIERA" → the casting "1971 Buick Riviera"**, and
   **a 2018 Dodge Challenger SRT Demon → "'71 Dodge Challenger"**, both at 0.96.
   Both matched the nameplate with the year stripped off — the alias that
   exists because a worn base often omits the leading '93 of '93 Mazda RX-7.
   A card prints the year. Off a card, only the whole name now matches.
4. That rule immediately broke the correct answers, because the catalog says
   "'04 Subaru Forester STI" and the card says "2004". So, off a card only, a
   two-digit year in a name is satisfied by the four-digit year ending in it.
   With that, the 2018 Challenger matches the right casting.

A fifth, smaller one, found on the synthetic layout: a collector number like
"3/250" printed under "MAZDA RX-3" was being removed from the text a word at a
time, which took the 3 out of the name as well and failed the exact-numbers
rule. The collector number now comes out whole.

### What the card's © year means

A base carries the year the tool was cut, so the existing rule reads it against
the casting's *first* release. A card carries the year of sale, so it is read
against the whole range. More importantly it was made **corroboration only,
with no penalty for falling outside** — a casting's recorded years here are the
years that happen to have been imported, and with 212 live castings out of
15,796 the model knows about, "outside its years" is far more often a gap than
a wrong car. Leaving the penalty in was what put the '93 RX-7 above a fully
read '95, and removing it let the existing dominance rule do its job instead.

### Measured, and what it says

Two of the owner's photographs are committed at `tests/Fixtures/packaging/`,
unscaled, so the numbers can be taken again. Five overlapping premium
two-packs: 8 detections, 8 of the 10 printed names located (the two missed are
one printed upside down and one in the middle of a card), 10 seconds. A fan of
Matchbox blisters stacked so all but a few have their names hidden: 8
detections, every name a person can read in it, 8 seconds.

Against the live catalog both photos return **no answers at all**, and that is
correct — it holds 212 castings, all 2011 Hot Wheels, and none of these cars is
among them. Against a catalog built to hold them, 3 were named and **none was
named wrongly**. That second row is the one that says the matching works; the
first says the catalog is the bottleneck, not the reader.

Two limits worth carrying forward. Tesseract does not read the Hot Wheels flame
logo — it is artwork, not type — so the brand is usually blank on a Hot Wheels
card and read on a Matchbox one, which means breaking a tie between two
castings of one name still depends on the brand being printed in type;
recognising a logo is a job for the visual model, not for OCR. And a premium
two-pack yields one detection per *car*, not per package, because each car has
its own name strip — for a collection that is the more useful answer, so it was
left alone.

---

## 2026-11-05 — Three silent failures on the live deployment

The owner reported a 500 on `/scan` "regardless of the options I tick". One
log they pasted contained three separate faults, none of them the one being
chased, and each invisible in a different way.

### The 500: a Railway reference that resolved to nothing

`Unable to parse URI: http://:8080/recognize`. `RECOGNIZER_URL` was
`http://${{recognizer.RAILWAY_PRIVATE_DOMAIN}}:8080` and the reference
resolved to **empty** — scheme, port, no host.

Two theories were wrong before the right one. First that the service was named
something else: Railway's docs say renaming updates the internal DNS name, and
the owner confirmed the services were `web` and `recognizer`. Then that
references resolve at deploy time and `web` had been deployed before
`recognizer` existed — which the README's own step order causes, so it was
written up as a required redeploy. It survived the redeploy.

The fix was to stop depending on the mechanism. Private DNS names are always
`<service name>.railway.internal`, so a reference buys nothing here, and it has
one genuinely bad property: **Railway substitutes nothing for a reference it
cannot satisfy, rather than failing.** Nothing anywhere says a variable did not
resolve. A literal hostname either resolves or fails naming the host.

`RecognizerClient` also now refuses an address with no host before sending.
Its docblock already promised that every failure becomes "a message a person
can act on", and a Guzzle `MalformedUriException` escaping as a 500 broke that
promise. **The first version of that guard was wrong**: `parse_url()` returns
*false* for a URL that malformed and `blank(false)` is `false`, so it waved the
bad value straight through. The test caught it by reproducing the live 500
exactly — which is the argument for writing the test from the real failure
rather than from the fix.

### The scheduler had never run. Not once.

In the same log, from `ScheduleRunCommand`: `SQLSTATE[08006] … could not open
certificate file "/root/.postgresql/postgresql.crt": Permission denied`.

supervisord runs as root, and a program with `user = www-data` inherits root's
environment — `HOME=/root`, which `www-data` cannot traverse. libpq checks
`$HOME/.postgresql/postgresql.crt` on every connection and a permission error
reading it is fatal. So every scheduler tick since the first boot had died, and
the queue worker had the identical fault: no nightly recognition index export,
no price job, no hourly `catalog:prune-scans` — the last being a promise in the
privacy policy about deleting scan photos nobody agreed to share.

What hid it is worth remembering: **php-fpm is unaffected**, because FastCGI
passes no `HOME` and libpq then skips the lookup. So the site worked perfectly
while two of its three processes could not reach the database at all. The
compose path is unaffected too — there the entrypoint runs them as root. Only
supervisord + `www-data` breaks, which is to say only Railway.

### Watching for it

Both rules are now in CLAUDE.md's *Deployment*. The broader lesson is cheaper
to state: **three rounds went on theorising about private DNS from outside,
when the resolved value is shown next to the variable in Railway's own
Variables tab.** Same shape as the Access débâcle the day before, where the
Applications list answered in one line what three rounds of external probing
could not. When a managed platform is involved, read its own view of its own
state before reasoning about it.

### Also this session

The owner asked for the scanner to read photos of *packaged* cars — blister
cards and boxes, a dozen at a time. That is not what the pipeline does: it
segments loose cars, reads base text and matches a model trained on loose
castings, whereas a card's signal is its large printed text. It went to its own
session with a brief to reuse `/read-card` and `variations.toy_number` rather
than rebuild. That session works in this same checkout, which is why the
`RECOGNIZER_URL` fix was committed straight to `main` with three files staged
by hand: switching branches would have carried its uncommitted work along.

## 2026-11-03 (duplicate) — The Railway route built twice; what was kept

**Asked:** walk the owner through getting the site live on collectai.club
behind a password, using the Supabase and Railway projects already connected to
the repo. They said plainly they were overwhelmed and did not know how to "set
passwords in GitHub".

**What happened:** the whole route was built here — `ORIGIN_MODE=platform` with
three nginx includes, configuration from process environment, a `SITE_PASSWORD`
session gate with middleware, controller, view and ten tests, a 470-line
walkthrough, README and CLAUDE.md updates — tested green (374 passed, pint
clean) and smoke-tested end to end against a real Postgres. Then `git push` was
**rejected**, because `origin/main` already had it: `81797fb` "Host on Railway
+ Supabase, password-protected with Access" and `0a1d6fe` "Run the scanner on
Railway", pushed from another session.

**The duplicate was thrown away, not merged.** That is the whole lesson of the
two earlier collisions this log already records — two schemas, then two
importers, both merged because git had nothing to conflict on and took the
union. A clean merge reconciles text, not designs.

**And the existing one is better**, which made the decision easy:

- It closes a hole the duplicate left open. Railway's edge is shared by every
  Railway app and routes on the Host header, so `Host: collectai.club` against
  that edge reaches the container and skips Cloudflare — password included.
  `origin-mode-railway.conf` refuses anything without a secret header that a
  Cloudflare Transform Rule sets on every request for the zone. The duplicate
  allowed all peers and only suggested deleting the generated domain, which
  does not close it.
- Cloudflare Access instead of a shared secret: a list of emails, a one-time
  PIN, strangers turned away at the edge before they cost Railway time. No
  shared string to leak or rotate, and no application code at all.
- The recognizer deploys too (model baked into its image, catalog pulled over
  the private network every 15 minutes), and the worker and scheduler run
  inside `web`. The duplicate had none of that and said so as a limitation.
- Config-as-code (`railway/web.json`), the Supabase Data API turned off, a
  volume for scan crops, and `pg_dump | psql` to carry the existing catalog
  across — all things the duplicate missed.

**Why the existing warning did not prevent it.** CLAUDE.md said to run
`git branch -a` before starting a named piece of work. Nothing local would have
shown this: the work was pushed straight to `main` from another session, and
`git branch -a` without a fetch prints stale remote-tracking refs. The rule is
now `git fetch && git log --oneline HEAD..origin/main`, which is what would
have caught it in one command before any code was written.

### The two things kept, because `main` lacks them

Both are independent of the hosting design and were already written and tested:

- **`DB_SEARCH_PATH`** (`config/database.php`, default `public`, so nothing
  changes anywhere else). Laravel issues `set search_path` per connection, so
  it is also the list Postgres resolves an operator class against. Enabling
  `pg_trgm` from Supabase's Extensions page installs it into a schema called
  `extensions`, after which `CREATE EXTENSION IF NOT EXISTS` is satisfied and
  every trigram index in `2026_08_02_100000` fails with "operator class
  gin_trgm_ops does not exist" — while the extension is installed and fine.
  README §8.6 defuses this by telling the owner not to enable extensions by
  hand, which is the right first line; this is the way out once they have, and
  it is a plausible click for someone being walked through that dashboard for
  the first time. `.env.railway.example` carries it commented out, beside the
  error message that sends you looking for it.
- **The three review thresholds pinned in `phpunit.xml`.** `LocalAiTest` was
  failing on `main` before any of this work: the suite reads `.env` off disk,
  the local copy carries `IMPORT_AUTO_APPROVE_THRESHOLD=0.85` rather than the
  documented 0.9, and `'67 Camaro` scores exactly 0.85 — so it auto-approved
  where the test expects it held, with nothing in the diff to point at `.env`.
  Same class of trap as `DB_DATABASE`, and the same fix for the same reason: a
  value the suite asserts on belongs in that file, not in whatever `.env` the
  machine happens to have. **No measured threshold was changed.**

### Worth keeping from the discarded work, if it is ever wanted

Nothing was salvaged beyond the two above, and nothing should be without a
reason. For the record, the discarded branch also had: a smoke-test script that
boots the prod image with no `.env` against a throwaway Postgres and checks the
health route, the redirect chain, the headers, the asset serving and a full
login round trip; and a verification pass that corrected two claims made from
reading code rather than running it (`SESSION_SECURE_COOKIE` does not depend on
the origin mode — Laravel applies it unconditionally; and a missing origin mode
does not reliably 403, because the Cloudflare allow list already admits the
private ranges for the tunnel's cloudflared). The second of those is worth
knowing against the Railway mode too, which does not rely on the allow list at
all.

## 2026-11-04 (late night) — The scanner on Railway

- **Asked for: the scanner on Railway.** The obstacle was that compose shares
  one folder between app and recognizer (catalog index, model), and Railway
  services can't share disks.
- **Model: baked into the image** (`railway/recognizer.Dockerfile`, repo-root
  context, its own `.dockerignore` allow-list because the root one excludes
  `training/`). A two-stage build unpacks only the model group; simulated with
  a stdlib-only Python and it produced files byte-identical to the trained
  model (171 MB). Considered: fetching the model at boot (needs a host for 171
  MB and a credential) — baking keeps "commit the model, deploy picks it up".
- **Index: pulled from the app.** `GET /internal/recognition-index`, token-
  guarded, 404 otherwise, sessionless (first attempt with `withoutMiddleware`
  failed: the CSRF middleware needs a session too — so the route lives in
  `routes/internal.php`, outside the `web` group). The recognizer's
  `index_sync` polls with ETag. Measured end to end locally: real `artisan
  serve` + real recognizer — 404 without token, fetched index byte-identical,
  `/health` shows index, model (62,149 photos) and sync status.
- **Worker + scheduler inside `web`** via supervisord flags. Found while
  designing it: a separate scheduler service would have run
  `catalog:prune-scans` against its own empty disk, deleting rows but leaving
  unshared scan crops on `web`'s disk for good — breaking the privacy policy's
  24-hour promise. Flags are validated (a typo is fatal, not "off"); supervisord
  parsing checked with the real `supervisor` package, on and off.
- **IPv6**: the recognizer binds `::` dual-stack on Railway. Unmeasured here
  (the sandbox has no IPv6; that test skips) — the first Railway deploy is its
  first run.
- Privacy policy: "hosting provider" → "providers … servers and the database".
- Gate: 385 PHP tests / 1,569 assertions, 52 recognizer tests, pint, shellcheck.

---

## 2026-11-04 (night) — Every wiki photo; HWRLC declined; Railway hosting

- **Wiki collection finished and retrained.** The remaining 15,958 wiki photos
  were embedded (25 files unfetchable). Model: 62,149 photos / 15,796 classes;
  8,236 held-out versions; top-1 73.3%, top-5 85.2% (base 61.0 / 74.8);
  looks-only 95.1% precise on 3,397 answers at 25.5% coverage; familiarity
  gate 0.714. Slightly below the 27,572-photo model's 74.5 / 85.4, which is
  the expected direction: ~1,640 more cars to tell apart and a larger test.
  Coverage fell from 30.4% to 25.5% at the same precision bar — the bar held,
  the model got more careful. Packed into `training/` (233 MB); restore
  verified byte-identical.
- **HWRLC (hwrlc.com) declined as a source.** Asked to import its photos into
  the public catalog because it "has no copyright against" reuse. Checked
  first: the footer reads "Copyright © 2026 HWRLC.com", it has a DMCA
  takedown page, no licence or terms granting reuse, and robots.txt only sets
  `Crawl-delay: 3`. The photos are one collector's own work. Nothing beyond the
  homepage, robots.txt and the about/copyright/terms pages was fetched; no
  image was downloaded. Owner's contact added to `docs/source-permissions.md`.
- **Railway + Supabase + Cloudflare, behind Cloudflare Access** (README §8.6).
  The prod image couldn't have run there: it requires a mounted `.env`, and
  its Cloudflare IP allow-list would 403 every request arriving from Railway's
  proxy. Worse, Railway's edge is shared and routes on `Host`, so anything
  reachable through it skips Cloudflare — and the password. Hence
  `ORIGIN_MODE=railway`: variables as config, and a secret header set by a
  Cloudflare Transform Rule as the only way in (bar `/up`). Testing it in
  nginx 1.24 found a 64-character secret breaks the map hash at the default
  bucket size — it would have crash-looped on first deploy; fixed, and the
  image build now parses the template at the longest accepted secret.
  Measured the full catalog DB at 126 MB (Supabase Free is 500 MB). Railway's
  docs require SSL **Full**, not strict, for proxied domains. Supabase's Data
  API is turned off in the guide — left on, `public` tables (users included)
  are exposed through it. Scanner, worker and scheduler are left out for now:
  Railway services can't share the recognition folder.

---

## 2026-11-04 (later) — Privacy policy; `training/` on main; retrained on everything

- **Privacy policy** at `/privacy` (App Store needs the URL), written from the
  code. Found while writing it: deleting an account left its scan pictures on
  disk (the row cascade doesn't delete files) — fixed (`Scan::deleteWithPhotos`).
- **`training/` on main**, separate from the app: model + vectors + labels,
  text gzipped, files split < 45 MB, `PACKED.json` checksums
  (`train/package.py`); `model-publisher` copies the model into the app; deploy
  runs it; `training/train` / `./collectai train` restore → collect → build →
  save. No photos: collectors' scan crops never leave the app. 196 MB; full
  restore verified byte-identical; publish into an empty folder loads.
- **Retrained on all 46,217 photos.** Two failures on the way, both fixed and
  tested: a progress line indexed `casting_id` (reference rows only have keys);
  evaluation ranked every class for every query as label strings — 54 GB at
  5,641 × 12,741 — now integer codes, top-5 per chunk.
- **Calibration and look-alike products.** First result: no setting reached
  95% precision. Cause: the open-set held out reference products, and makers
  list one tool in several colours as separate products, so hiding one colour
  scored its sibling (the right tool) as wrong. Open-set now holds out only
  catalog castings; reference products still compete in the gallery. Result:
  74.5% / 85.4%, looks-only 95.1% precise at 30.4% coverage.

---

## 2026-11-04 — Reference sources: HWPriceGuide, TSM/Mini GT, Inno64, Para64, Horizon

**Asked:** the owner now holds written permission from HWPriceGuide,
TrueScale/Mini GT, Inno64, Para64 and Horizon Diecast to use their images and
labels for training. Use a user agent, few calls, be efficient; and offer every
brand, series and sub-series found as input when adding cars.

**Efficiency, measured:** each site read through a bulk listing —
Inno64 WooCommerce Store API 607 products / 8 requests; Para64
(paragonmodelstore.com) Shopify 224 / 2; Horizon Shopify 9,907 / 46 (accessories
and dioramas skipped); Mini GT list pages 1,250 / 153; HWPriceGuide sitemap →
171 category + 2,054 group pages: 27,027 products / 2,231 requests (item pages
never fetched). ~39,000 products from ~2,440 requests instead of ~39,000.
Mini GT gotchas: pages are 1-based (`p=0` repeats page 1), and "Full
Collection" omits Kaido House and QubeCarz, so every line list is walked.

**Labels:** tied to a catalog casting only when certain. HWPriceGuide: 16,419
of 27,027 (61%) tied to a Hot Wheels casting, 4,417 to the exact version via
"(Colour)" + year; 25 spot-checked ties all correct. Mini GT: 348 tied by item
number. The rest are their own classes (`source|item:<key>`).

**Images, efficiently:** maker sources (12,759 images) first; of HWPriceGuide
only the 5,770 images that add something now (castings with < 5 training
photos — 490 with none — and exact-version matches). The rest stay listed and
resumable for `./collectai train`.

**Add a car:** "Find the model" (reference search → pre-fill, or "already here
— add this version"); `reference_links` ties the saved records to the product
so the model knows them at once — deliberately not `record_sources`, which is
public attribution and marks imported records. Main-line field (files a *new*
series under a line); brand/line/series suggestion lists. Driven in Chromium at
phone width: search, pre-fill, no CSP violations.

---

## 2026-11-03 (final) — Wiki photos for training, `./collectai`, release guides

**Asked:** use all wiki photos for training (the owner holds Fandom's written
consent, training only, model may run on the site); save cloud disk by cycling
data through GitHub if needed; README guides for App Store uploads/updates and
website updates; one file to simplify setup.

**Built:**
- `catalog:export-wiki-photo-labels` (TrainingPhotoLabels): every Versions-row
  and infobox photo of each imported casting, labelled with casting and, from
  the row, the exact variation. Measured: Hot Wheels 35,354 labels / 2,073
  castings (96% to the variation), Matchbox 14,931 / 1,758 — 43,675 distinct
  files. 8 placeholder files ("Image Not Available.jpg" alone was 2,273 rows)
  are skipped.
- `train.collect`: API + image server, identifying UA, 4 req/s, 448 px
  thumbnails, embedded in memory and **never written to disk**; resumable, and
  a row is written only once its vector is saved (a stop between saves first
  left 301 orphaned rows — found, fixed, repaired on restart).
- `train.build`: shared float16 cache (invalidated if the encoder changes),
  de-duplication, held-out split by version, chunked evaluation.
- `./collectai`: setup / import / publish / train / test / status / deploy /
  backup / ios / ios-release / artisan, dev or prod read from `.env`,
  `--dry-run`. shellcheck clean; dry-run checked in both modes.
- iOS: real 1024 px opaque icon; `ITSAppUsesNonExemptEncryption=false`;
  `ios-release` archives with a timestamp build number and uploads via
  `xcodebuild -exportArchive` (destination upload) — not run, no Mac here.
- README: §2.0 one command, §5.10 wiki training, §8.4 App Store release and
  what needs a new build (only shell changes — the app loads the live site),
  §8.5 website updates, rollback and data refreshes.

**GitHub as overflow storage — not done, on purpose.** Not needed: vectors for
all 43,675 photos are ~35 MB, and the photos are never stored. And pushing the
photos would have put Fandom content permanently into git history, visible to
anyone with repo access — at odds with "training only" — and against GitHub's
size limits.

**Gap found:** the site has no privacy-policy page, which App Store submission
requires for apps with accounts.

**The collection, then training — and three problems found in it:**
- The background run hit the 2-hour limit at 27,717 of 43,667 files (64%) and,
  per the harness rule for a max-length task, was not restarted. Resumable.
- First training "measured" 74.3% — **void**: the 200-file trial had written
  rows (placeholders included) into the folder that run read. Clean data:
  **76.2% top-1 / 87.7% top-5** on held-out versions (base alone 62.9/76.3).
- Calibration tested only cars in the gallery, so it accepted at its grid floor
  (0.30) and named plain coloured blocks "Minecart". Added never-seen castings
  to calibration (`--open-set`). Then blocks became "'68 El Camino" (a hub).
  Measured: real photos' base-model nearest similarity ≥ 0.689 for 99%;
  blocks 0.47–0.52, grey card 0.665 → **familiarity gate** at the 2nd
  percentile (0.704). Result: blocks unanswered, bases still read; looks-only
  95.0% precise, 29.7% coverage.
- Labels were **database ids** from this machine's DB — a model trained here
  would mislabel cars on any other DB. Switched to stable provenance keys,
  resolved via the index; the 28,976 collected rows were re-keyed by exact
  match (0 unmatched), nothing re-downloaded.
- Cache keyed by encoder identity rather than file hash, so it survives a
  re-export elsewhere.

**Data on GitHub after all, in the narrow form that makes sense:** a separate
`training-data` branch (61 MB: model, vectors, keyed labels; no photos), never
merged, restorable with `./collectai model-pull` (verified byte-identical).
`RECOGNIZER_TRUST_LOOKS=never` added for running hint-only until the model has
been checked against real phone photos.

---

## 2026-11-03 (last) — Recognition phase 3: the trained visual model

**Asked:** build phase 3 with consented scans and licensed wiki photos — and
also the ~98% of wiki photos with no licence, which the owner's lawyer said was
fine for training only.

**The unlicensed wiki photos were not used, and why.** Checking before building
the downloader: Fandom's Terms of Use bar using its content "to train a machine
learning or AI system" without Fandom's express prior written consent, and bar
robots/scrapers without permission (search result quoting fandom.com/terms-of-use;
the live page and the archive both refused automated reads from here). That is
a contract term with Fandom, separate from the copyright question the lawyer
answered. Raised with the owner rather than built; if Fandom consents, the
photos are another `--data` folder.

**Built:**

- `visual.py`: DINOv2-small (Meta, Apache 2.0, revision `ed25f3a`) exported to
  ONNX (88 MB; parity with PyTorch 4e-5; 0.09 s per crop on CPU), a learned
  head, a labelled gallery, nearest-neighbour by casting.
- `train/build.py`: embed (cached by hash) → hold out → WCCN head kept only if
  it beats plain by ≥ 1 point top-1 → calibrate similarity + margin for ≥ 95%
  precision on ≥ 30 answers → `model.npz` + `manifest.json` (sources, licences,
  metrics, thresholds). `python -m train` does export + build;
  `trainer` compose service (profile `training`) runs it on the server.
- Matcher: looks corroborate (+0.03 to a matching text candidate), order
  versions (`looks_match`), and name a car alone only when `visual_only`.
- `catalog:export-training-data` now exports `scan`, `own` and `wiki_licensed`
  with licence and credit; the Scan page shows "Looks most like …" (live
  castings only) as a one-tap search hint.

**Measured:** 163 images (157 licensed wiki, 6 test scans), 158 castings — one
photo per casting, so 1 held-out image and **visual-only stays off**, as
designed. Sanity check only: altered copies (mirror, crop, light, blur) of the
157 photos found their own casting top-1 152/157, top-5 157/157 — proves the
plumbing, says nothing about phone photos. Training run: 17 s.

**Not done:** no real-photo accuracy (no real photos); Docker images not built
here (no Docker); the 6 "scan" images in the scratch DB were synthetic test
renders.

---

## 2026-11-03 (late) — Hosting on Cloudflare and DigitalOcean; two pre-deployment bugs

**Asked:** update the README with how to host on Cloudflare; then, mid-way,
host with DigitalOcean (Spaces), with the most cost-effective plans, paired with
Cloudflare if needed, DigitalOcean alone if cheaper.

**What "host on Cloudflare" can mean, measured:** Cloudflare's compute can't run
this stack. Workers/Pages don't run PHP or Postgres, and Cloudflare Containers'
disk is ephemeral — wiped when an instance sleeps (Cloudflare FAQ, checked
today), which would erase the database. So the app runs on one machine and a
**Cloudflare Tunnel** connects it: `docker-compose.tunnel.yml` adds `cloudflared`
(pinned 2026.10.0) and publishes no web ports. README §8.2.

**DigitalOcean, priced (digitalocean.com, cloudflare.com, today):** Spaces is
object storage — it cannot run the app either. Cheapest working setup: Basic
Regular Droplet 2 GB / 1 vCPU / 50 GB at **$12**, Cloudflare Free in front,
photos and backups on R2 ($0 to 10 GB, $0.015/GB after, no egress). Spaces is
$5/month minimum (250 GB + 1 TB transfer included, $0.02/GB stored after), so
R2 is cheaper at every size and is already working; a DigitalOcean-only setup
costs more ($17+) and loses the free edge. $4/$6 Droplets are too small for the
stack plus the image build (estimate, not measured on a Droplet). README §8.3.

**Bug 1 — the production allow-list would have refused every visitor.**
`cloudflare-allow.conf` used `allow <Cloudflare range>; deny all;` alongside
`set_real_ip_from` + `real_ip_header CF-Connecting-IP`. The realip module
rewrites the client address before the access phase, so `allow` compared the
*visitor's* IP against Cloudflare's ranges. Reproduced with nginx 1.24 and the
repo's own files (loopback standing in for an edge): old config 200 without the
header, **403 with it**; error log "access forbidden by rule, client:
203.0.113.9". Fixed with `geo $realip_remote_addr $from_cloudflare` and a
server-level 403. New config: 200 with the header, a non-Cloudflare peer still
403. The 2026-08-10 local verification couldn't see it — no request carried the
header — and deploy.md's "403 on every request" row blamed stale ranges.

**Bug 2 — nightly database backups into the public photo bucket.**
`pg-dump-to-r2.sh` uploaded to `R2_BUCKET/backups/diecast-<timestamp>.sql.gz`.
That bucket is world-readable through `img.collectai.club`, and the timestamp
of a 03:17 cron is guessable to the minute. Now `R2_BACKUP_BUCKET` (a private
bucket) is required and the script refuses the photo bucket; checked both
refusals by running the script. Also pinned `amazon/aws-cli:2.27.0` (was
`latest`).

**Not done:** nothing was deployed, no Droplet created, and the tunnel compose
file was validated as YAML only — Docker isn't available in the cloud session.
Spaces support for photos was not added (it would cost more; it needs a disk
with public-read uploads and a CSP entry).

---

## 2026-11-03 (night) — Recognition phase 1, and no outside AI required

**Asked:** phase 1 of multi-car recognition, with loose cars (a pile) as the main
case and base text read where visible; only highly confident results, for
confirmation; something the owner can run themselves; and the site not to rely
on Claude API calls — with the data the model needs in a file, and a model of
our own running on our servers if inference is needed.

**Built:**

- `recognizer/` — a standalone Python service (OpenCV 4.11 + Tesseract 5, numpy;
  stdlib HTTP server, no framework). Segments cars against the photo's border
  colour, names each one's colour family, reads text the right way up and at
  180° (bases are photographed every which way), and matches it against the
  catalog index file. Confident = score ≥ 0.9 **and** ≥ 0.08 ahead of the next
  *different* casting. CLI (`scan`, `card`, `serve`) and 25 unittest tests.
- `catalog:export-recognition-index` — the "hardcoded data file": every live
  casting and variation with brand, years, colour + colour family, base colour,
  wheels, toy number and series path. Nightly. 6,810 castings / 51,236
  variations is 10 MB and loads in 0.4 s.
- The **Scan** page (`/scan`, `ScanController`, `ScanProcessor`, `scans` +
  `scan_detections`). Confident cars are offered with their versions ordered by
  colour; the rest are shown as "found, not sure" with a search to name them.
  Consent box keeps the crops as training data; otherwise they're deleted when
  the scan is answered or after 24 h. `catalog:export-training-data` writes the
  consented, labelled crops for phase 2/3.
- **`CATALOG_AI_DRIVER=local` (default)**: `ReadsPackaging` → `LocalPackagingReader`
  (the recognizer's `/read-card`), `IdentifiesCastings` → `LocalMetadataAgent`
  (`resources/data/vehicles.json`). The Claude agents remain behind `claude`.

**Measured, not assumed:**

- Local make/model over all 6,810 imported castings: 2,851 make + nameplate
  (0.93, publish), 311 nameplate-only (0.85, review), 44 fantasy, 3,604
  unknown. A sample of 80 published answers had one error ("DeLorean Alpha5" as a
  DMC-12 — a model alias equal to the make's own name); fixed by dropping the
  alias. The review bucket was noisier ("Toyoace Livestock *Transporter*" as a
  VW Type 2, "Cancelled *Models*" as a Tesla Model S): fixed by `needs_make`
  (ordinary-word nameplates count only beside their make) and by allowing
  run-together matching ("RX7" for "RX-7") only when the phrase has a digit.
- Recognizer, against the full index, on a rendered pile of three bases: all
  three identified in 2.9 s, including telling the Hot Wheels Bone Shaker from
  the same-named Matchbox one by the © year. Driven in Chromium at phone width:
  7 cars found in two photos, 3 identified, confirm and name-by-hand both work,
  no CSP violations.
- Tesseract read "'93" as "'95" and the hyphen in "RX-7" at 0.35 confidence on
  the rendered font. Words under 0.4 are dropped, so the answer was "not sure",
  not the wrong RX-7. That test is kept on purpose.

**Calls made, and why:**

- **Colour never identifies a car on its own.** It orders a confident casting's
  versions. A red car is any of thousands; offering a guess on colour would
  break "only what it's sure of". Loose cars right way up are found and
  coloured, and say so.
- **Numbers in names must be read exactly.** Fuzzy similarity scores "RX-3"
  0.93 against "RX-7"; in car names the digit is usually the whole difference.
- **The © year is corroboration, both ways** (+0.03 if it fits the first
  release, −0.1 if it is after the last release or long before the first).
- **Colour is compared against base colour too.** Discovered from the
  screenshot: upside-down cars show their base, so ranking by body colour
  ranked the wrong versions first. The index carries `base_color_family`.
- **`layout` is asked, not only guessed.** On a close-up the printed words
  stand out from the metal exactly as cars stand out from a table; no
  heuristic separated them reliably, so the form asks.
- **Scan pictures are private** (`local` disk, owner-only route,
  `Cache-Control: private`), not the public R2 bucket, and only the crop of each
  car is stored, never the whole photo.
- **The local metadata agent claims no generation.** A model year in a name
  doesn't say which generation's years it falls into.

**Not done, honestly:** no real phone photo has been through the recognizer —
base-text reading accuracy on real embossed bases is unmeasured. No model has
been trained: there is no lawful training data until collectors consent. The
`recognizer` Docker image was not built here (no Docker in the cloud session);
the Python ran in a venv with the same pinned versions.

---

## 2026-11-03 (end) — Photo copying declined; iPhone shell; hosting and TestFlight guide; recognition plan

The request had three photo options, all declined:
1. take HWPriceGuide's photos;
2. AI-generate copies that "look 99% like" licensed photos from the wikis,
   HWPriceGuide and the maker sites, plus AI renders of Hot Wheels cars in
   their card packaging;
3. take the photos and AI-enhance or slightly edit them ("a dot") "allowing us
   to use them as our own".

A near-identical replica or a lightly marked copy of a copyrighted photo is
still that photo. Rendered Mattel cards presented as product photos would be
misleading and copy Mattel's packaging. Recorded under *Data sources* so it
isn't re-asked as a technical question.

Built: a Capacitor 8.5.3 iOS shell. 8.6.0 was under a week old, so the newest
release at least two weeks old was pinned. The template had no camera usage
strings, and iOS kills an app that opens the camera without them, so they
were added. `Permissions-Policy` moved from `camera=()` to `camera=(self)`:
harmless for today's `<input capture>`, but it would have silently blocked the
in-page camera the planned scanner may use. The capture flow was driven in an
emulated iPhone 15 (inputs, thumbnails, 201 upload, both photos stored).

README §8.1 says where to host: Porkbun can't run this app, and since DNS moved
to Cloudflare, records change there. §8.2 is the TestFlight walkthrough,
including Apple's guideline 4.2 on web-wrapper apps. The multi-car recognition
plan is in `docs/multi-car-recognition.md`: Claude vision plus deterministic
matching first, consented training data second, a distilled embedding model
third. The collector always confirms.

---

## 2026-11-03 (final) — Accounts and collections, monthly prices, security; photo search measured and declined

The request: photos for every model "using wikis for those brands and search
the internet for reusable photos"; a live price for each model from its last
eBay sale, refreshed monthly, falling back to hobbyDB or store MSRPs; security
measures; and accounts that save a personal collection.

### Measured first

- **Photos.** Openverse, which searches openly licensed images across Flickr,
  Wikimedia Commons and more, found **no** licensed photo for 15 of 15 sampled
  castings across all four brands. The other 5 queries timed out. Its only
  result in an earlier spot check, for "Bone Shaker", was the full-size
  real-life car: exactly the wrong-car photo an automated matcher would
  publish. Commons answered 429 from this network. Combined with the wiki
  sampling, no automated photo finder was built. The scalable route is
  collectors photographing their own cars, now that accounts exist; it is
  offered, not built.
- **Prices.** eBay's Marketplace Insights endpoint is live and wants an OAuth
  token: sold data exists through eBay's official API, on application.
  hobbyDB answered a Cloudflare "Sorry, you have been blocked" even for its
  terms page, so it is not a source. Retail MSRP pages were not scraped either.

### What was built

- **Accounts and collections.** Registration, collection, wishlist, password
  change and account deletion. Admin rights can't be obtained through
  registration; the old "no registration route" test was turned into a test
  of exactly that. The first wiring-up broke the car page: a block
  replacement matched an inner `@endforeach`. The suite caught it, as the
  ParseError surfacing as a 500.
- **Prices.** A daily budgeted run, so each car refreshes about monthly. The
  first default budget (1,000 calls) was too small for that: at ~3 calls a
  car it reaches ~10,000 cars a month against ~51,000 variations. It is now
  4,500, under eBay's 5,000 default, with the formula in config. The budget
  test also showed it can overshoot by one car's calls, and both config and
  test now say so.
- **Security.** A strict CSP with nosniff, frame-deny and HSTS. A Chromium
  pass over 12 pages found no violations except on Laravel's own error pages,
  whose inline CSS the policy blocks, so the app now ships its own. npm audit
  found 5 dev-tool advisories (2 critical), all fixed without major upgrades.
  Composer is clean.

### Not done

Password reset (needs outgoing email); community photo uploads; any real eBay
call (no keys here). Deploying still needs the owner's Cloudflare access.

---

## 2026-11-03 (latest) — Tomica, Mini GT, the Lesney years; manufacturer sites declined; deploy not possible from here

The request: "add all the minigt/true scale models … from the minigt official
website along with the associated photos … the same with Para64 … Inno64 …
Shuco, and Lesney Matchbox … then all Tomica/TomyTec/Tomica Limited Vintage/TLV
neo models with photos. Then deploy the whole site to collectai.club."

### Manufacturer websites: read the terms, then declined

The four sites' terms were read rather than assumed. Schuco's is a JavaScript
app, so it was rendered in a browser to find its legal notice:

- **tsm-models.com** (TrueScale, which makes Mini GT): "Any other use of this
  website or content or information … is strictly prohibited, unless the
  written permission of TrueScale Miniatures Ltd. first is obtained." Images
  are named explicitly.
- **paragonmodelstore.com** (para64.com redirects here): "not to reproduce,
  duplicate, copy … any portion of the Service … without express written
  permission".
- **inno-models.com**: "© INNO MODELS — All rights reserved", and no reuse terms.
- **schuco.de**: a legal notice with company details and no reuse terms, which
  means all rights reserved.

Two robots.txt files allowed crawling, and one even addressed AI agents
directly. A robots file says what may be fetched, not what may be reused, so
it changed nothing. None of the four were scraped. `docs/source-permissions.md`
gives the owner a request to send to each maker, since a written yes is what
would open these sources up.

### What could be done, was

- **Lesney Matchbox:** the dump has year indexes back to 1953, and the import
  had started at 1968. That needed only a wider range: +194 castings, with
  pre-1969 Matchbox releases going from 137 to 384.
- **Tomica, including TLV and TLV Neo:** no dump (S3 answers 403), so the API
  is used. The wiki has one article per release item, with line and year in
  its categories. TLV variants share a base code (LV-01a…f), which gives a
  real casting grouping. Result: 2,534 castings and 3,350 variations across
  five lines in 3 minutes, idempotent.
- **Mini GT:** its wiki's yearly lists stop at 2022, so 440 items. One
  casting per item, because the wiki fuses car and livery in every name and
  guessing would merge different tools.
- **Photos for these brands:** none. 150 of 150 sampled images on each of the
  Tomica, Mini GT and Inno64 wikis carry no reuse licence.

Two things worth remembering. Adding Tomica to `Wiki::fromKey()` silently made
an old test's "unknown wiki" known, and the test then made a live API call;
`catalog:import-wiki` now refuses the category-organised wikis and names the
right command. And the TLV category includes non-car items (Kaiyodo DioColle
diorama pieces), which come in as the wiki files them.

### Deploy

Not done, and not possible from this environment: it has no AWS Lightsail
account, no Cloudflare access and no credentials for either. The runbook
(`docs/deploy.md`) and the production configuration are ready. The apex still
serves the registrar's parking page with a 525.

---

## 2026-11-03 (later) — Public catalog, main lines, and what "a photo for every car" turned out to mean

The request: show every casting's year range and series / sub-series ("Silver
Series / 2026 Entertainment — Toy Story"); browse the site by brand → main
series → sub-series; and "look up and add pictures for each database entry …
scrape the internet / hot wheels wiki / other wikis … only one per model."

### Photos: measured before promising

The wiki dumps include every `File:` page, so the licence situation could be
measured offline before any code was written. Of 9,186 casting-article infobox
images (6,645 Hot Wheels, 2,541 Matchbox), most carry **no licence template at
all**, and the next largest group is `{{Self}}`. Read on both wikis, that
template says only "This file was uploaded by the photographer or author". It
is an authorship note, not a licence, and Fandom's own help says images may be
licensed differently from the CC-BY-SA text and to check each file page. What
genuinely permits reuse (GFDL, public domain, "Other free", CC, files from
Wikimedia Commons) is about 2%. The live API agreed exactly: of the 3,642
castings in the catalog, **152** have a licensed infobox image (32 Hot Wheels,
120 Matchbox). All 152 were downloaded and attached, credited, with no failures.

So the importer takes only those. The settled "no wiki images" rule from
2026-08-09 is revised rather than dropped. That rule was protecting against
copying other people's photos, and a file whose author licensed it for reuse
isn't that. Scraping images from the wider web was not done, for the same
reason HWPriceGuide wasn't. "One per model" was read as one per *casting*: the
article's own infobox image, placed on the variation whose Versions row shows
the same file. Reading those Photo-column file names meant changing a guard test
("the photo column is not read at all"). It now asserts the narrower thing that
still matters: file names stay in `VersionRow::photos` and never become a fact
about a car.

### Main lines: the wiki doesn't say, so the config does

Series articles carry categories, but they are many-to-many ("Mainline
Segment" *and* "Wal-Mart Exclusive" *and* "Glow-in-the-dark"), so a line
can't simply be read off. The taxonomy is now an ordered, editorial list per
wiki in `config/catalog_lines.php`. The first dry run put 28% of Hot Wheels
releases in Other. Looking at what was there turned up one factual signal:
mainline segments are 75–100% collector-numbered and the leftovers 0–4%. A
≥70% fallback took Other to 19%, all of it genuinely specialty lines (Connect
Cars, Speed Fleet, Holiday Hot Rods). Matchbox's compound names do most of the
work there: 93% placed.

The first real run reported "131 series are lines themselves", against 16
lines. The cause was 92 rows called "Mainline", one per year's anchor link,
each about to become its own top-level line. Now the first one is the line and
the rest hang beneath it.

### The public catalog

Brand → line → sub-series → car, live records only, so the public sees one
hand-entered Mini GT today. An admin can preview the pending backlog, and the
page says so whenever that's on. Screenshotting it against the full import
found two more problems. The CSS had been built before the views existed, so
Tailwind had never generated their classes. And the car page's breadcrumb used
the oldest release's line (a Batmobile filed under "Promotions &
Exclusives"); it now uses the line with most of the car's releases.

The year range also settled the earlier BNR34 confusion in the admin list:
"2010–2026" against "2027" makes the regular and police tools obviously
different.

---

## 2026-11-03 — Manual entry, every year of both wikis, and HWPriceGuide ruled out

The request: "a database just like HWPriceGuide and a way for me to add cars
manually as well as pictures … scrape all the database and organization for the
toys in the website … build the database and pictures … focusing first on hot
wheels, specifically everything from HWPRICEGUIDE, then hot wheels wiki and then
matchbox wiki and then shuco, minigt, true scale models, inno64, and para 64."

### HWPriceGuide: looked at properly, then declined

Measured before deciding. robots.txt disallows only `/profile-v2`; there is no
terms page (`/terms`, `/terms-of-service` and `/tos` all 404). The sitemap lists
35,801 URLs: 33,462 `/item/`, 2,054 `/group/`, 171 `/category/`. An item page is
one release: year, colour, sub-series, wheel type, first-casting flag, chase
status and Mattel product code. Next to that sit the site's own sales-derived
guide price and price history ("Collections: Upgrade to view"), a metered search
("5 searches left") and the site's own photo of the car.

Not copied, and recorded as a settled decision rather than left as a one-off
refusal. The facts on an item page are facts, but the page as a whole is a
competitor's product. Its prices are what it sells, its photos are someone's
copyright, and a bulk copy of its catalog is the opposite of
[priority 1](../CLAUDE.md#data-acquisition-priorities), an original database.
A permissive robots.txt says what a crawler may fetch, not what may be taken.
What *is* fair to learn from is the organisation: category → sub-series → item,
with chase/Treasure Hunt and first-casting called out. That belongs in the
public catalog's design when it is built. The same facts for Hot Wheels and
Matchbox come from the Fandom dumps, which are CC-BY-SA and published for
exactly this.

Pictures were the other half of the ask, and the answer is the same: no
source's images, wiki or HWPriceGuide. That is why manual entry was built
first. Photos you take are the ones the catalog can freely show.

### Manual entry

Built and driven in Chromium at 390px, not only through feature tests: login →
add a car with a deliberately missing make (error state keeps every typed value)
→ fix it and attach two photos (both rendered, served through the signed
local-disk URLs) → add a second colour from the casting page → re-add the first
colour (refused with "already in the catalog as variation #4"). Two things came
out of driving it that the tests hadn't caught. The add-variation form didn't
prefill the series, so the browser's own `required` check blocked the commonest
case (same series, new colour). And the casting header printed the chassis code
twice, because `fullName()` already includes it.

The design calls are in CLAUDE.md under *Manual entry*. The one worth arguing:
"Add a car" always creates a casting and never matches a typed name onto an
existing one. Name matching would merge, say, Hot Wheels' and Matchbox's
separate Corvette tools, which is precisely what one-casting-per-tool forbids.
New colours and years go through the casting's own page instead.

### Going wide on the wikis found more than the parser bugs it expected

The plan was to exercise `DumpPageSource` at size for the first time. A
baseline run of the *unchanged* importer over 1968–2027 got two years in before
showing the worst bug of the session. 1970's index parsed to **zero** casting
titles, and `DumpPageSource::pages([])` reads an empty list as "every page".
So "import 1970" became "import the whole wiki as 1970", visible only as an
oddly long progress bar. Fixed at both ends.

Surveying all 60 indexes with the real parser: **21 yielded nothing**. Three
index shapes from three eras of the wiki: `<gallery>` captions (early 1970s),
tables headed "Car Name" (1980s), and `{{List0000White|…}}` row templates (late
1990s). After the fix every year yields castings: 7,991 index entries, 2,287
distinct articles.

Then a survey of *every casting page* (6,649 Hot Wheels, 2,578 Matchbox), which
is what found the rest. Bold headings (`=='''USA Versions'''==`, most 1968–69
cars) and an empty `|+` caption both made real castings parse to zero rows.
Both fail exactly like a page with nothing to import, which is why they
survived two months. Widening which headings count raised a real concern:
provenance keys come from row numbers counted across sections, so a newly
recognised early section renumbers later rows. It was measured before
committing: Matchbox renumbers nothing; Hot Wheels touches 5 pages, none in
2011, the only year imported anywhere. After the full import runs that freedom
is gone, and CLAUDE.md says so.

### Matchbox, with zero schema changes

The Matchbox wiki shares the page format, so the importer became wiki-agnostic
rather than copied. The previous session's warning about two importers built
side by side applies. Three differences were each measured on both dumps
before deciding whether they were global or per-wiki:

- Wheels as images: 8,969 Matchbox cells, 1 Hot Wheels cell. Applied globally.
- Range-plus-segment series (`[[1-75]]: [[Wheeled Envy]]`): 5,932 Matchbox
  cells, but also 1,002 Hot Wheels cells with text beside the link. Applied to
  Matchbox only, because re-keying Hot Wheels series would duplicate what's
  imported.
- Header spellings (`Toy Number(s)`, `Decals`, `Labels`, `Wheel/ Type`). Global
  and harmless.

### The full run

Both wikis, every year, from the dumps, into one fresh database:
**Hot Wheels** 2,073 castings, 22,631 releases, 33,744 variations in 448 s;
**Matchbox** 1,569 castings, 12,045 releases, 13,449 variations in 190 s. Both
exit cleanly. Every record has exactly one provenance row (64,983 and 30,975),
and both brands are live under Mattel. All 3,642 castings are pending, as the
blocker intends. A second Matchbox run over the same database created nothing
and updated everything, so re-import is idempotent at full scale, not only for
2011. The full table is in CLAUDE.md under *Catalog import*.

Spot checks: Mantis (1970, read only through the gallery index) has 20 releases
and 47 variations. Python has 30 variations across its USA, Hong Kong and
2018-retool tooling. Baja Bug (the caption fix) has 76 and Lotus Turbine (the
bold heading) 17.

None of this is in the dev database on the Mac, which still holds 2011. The
commands to reproduce it are in CLAUDE.md's *What to do next*.

### The other five brands

Checked rather than guessed. Mini GT has a Fandom wiki, but small (699
articles) and in its own format. Inno64's has 24 articles. Schuco, TSM and
Para64 have none, only manufacturer sites (Inno's is a WooCommerce shop with a
sitemap; `para64.com` was unreachable through this environment's proxy). Each
is a source decision as much as a parser, so none was started. Manual entry
covers them meanwhile.

### Environment

The cloud container has PHP 8.3 and the lock needs 8.4. Adding a PHP PPA was
refused by the session's permission policy, so dependencies were resolved for
8.3 locally (Symfony 7.4) and the committed lock restored. Recorded under
*Environment traps*. Also: `pkill -f <pattern>` kills the shell running it when
the pattern appears in its own command line. That happened three times here,
so kill by PID.

---

## 2026-08-10 — Production deployment configuration, written but not yet run

The session started from a bug report: "whenever I go to collectai.club it says
SSL handshake failed, and I can't reach /admin or /login either. Everything is
set up in Cloudflare and Porkbun."

Both halves of that were true, and that is the whole diagnosis. Measured again:
`dig` returns Cloudflare proxy IPs, HTTP returns Porkbun's parking page
(`X-Service: pixie-default`, `X-Powered-By: PHP/8.0.25`, AWS ALB cookies) and
HTTPS returns **525**. Cloudflare and Porkbun genuinely are configured
correctly — which is exactly *why* the error is a 525 rather than a DNS failure.
The apex and `www` are proxied A records still pointing at the parking service,
so Cloudflare reaches an origin on port 80 and cannot complete a TLS handshake
with it on 443. `img.collectai.club` answering cleanly over HTTPS proves the
edge certificate is fine; the failing handshake is Cloudflare→origin.

**The error is the absence of a deployment, not a misconfiguration of one.**
Worth stating plainly because the 525 invites you to go looking for a broken web
server, and there is no web server.

### Host choice

AWS, at the user's request. Within AWS the fork that mattered was cost shape:
ECS Fargate + RDS + ElastiCache + ALB is the "proper" build and carries ~$16/mo
of ALB and ~$32/mo of NAT gateway *before any traffic*, against a workload with
one admin user. Lightsail with a single production compose stack was chosen
instead. The user's follow-up question — "what more would I need to pay for?" —
has a short answer: the instance, and nothing else. R2, the Anthropic key and
the domain are already paid, Cloudflare's Origin CA certificate is free, the
static IP is free while attached, and photos serve from `img.collectai.club`, so
the image-heavy half of the catalog never touches AWS bandwidth. That last point
is the R2-over-S3 decision paying out.

The tradeoff accepted knowingly: Postgres shares the box, so backups are ours.
Hence `docker/backup/pg-dump-to-r2.sh`.

### Three bugs found by writing the config, not by running it

- **`db:show` is not a database readiness probe.** It was the obvious choice for
  the entrypoint's wait loop. It connects, prints the table count — and then
  exits **1**, because its summary calls `Number::format` and `intl` isn't
  installed. The loop would have spun for two minutes on every boot and then
  declared the database unreachable while it sat there working. Caught by
  checking the exit code rather than the output, which is the only way it is
  visible. Replaced with `tinker --execute="DB::connection()->getPdo();"`;
  `laravel/tinker` is in `require`, not `require-dev`, so it ships. Adding
  `intl` was the wrong fix — nothing in the app uses `Number::`, so it would
  have been installing an extension to satisfy a probe, the same call already
  made about GD.
- **The default queue timeout would have killed the first real OCR call.**
  `ReadSubmissionPhotos` declares `$tries` and `$backoff` but no `$timeout`, so
  Laravel's 60s default applies — against an Opus 5 vision call with thinking
  enabled over up to four photos. It would be killed mid-call, retried three
  times, and marked failed, reading exactly like a broken agent on the day the
  agent first ran for real. Fixed in deployment config rather than app code:
  `--timeout=300` on the worker, with `REDIS_QUEUE_RETRY_AFTER=360` raised to
  stay above it. Those two numbers are a pair — if `retry_after` ever drops
  below the timeout, Redis re-queues a job that is still running and one capture
  becomes two billed vision calls.
- **`public/hot` would have shipped in the image.** It doesn't exist right now,
  but Vite writes it whenever `npm run dev` runs, and baked into a production
  image it makes `@vite` point every page at `localhost:5173`. CSS and JS
  silently never load and nothing logs anything. Now in `.dockerignore`.

### Calls worth the argument

- **Cloudflare's real client IP is restored in nginx, not with Laravel's
  `TrustProxies`.** `TrustProxies` at `'*'` looks like the idiomatic Laravel
  answer and is worse: it has Laravel re-read `X-Forwarded-For`, which any
  client can forge, so it moves the spoofing problem up a layer instead of
  fixing it. `set_real_ip_from` over Cloudflare's ranges with
  `real_ip_header CF-Connecting-IP` rewrites `REMOTE_ADDR` before PHP sees it,
  and CF-Connecting-IP is one value Cloudflare overwrites every request. This
  matters because `LoginController::throttleKey()` keys on email **+ IP**;
  un-restored, the IP half is constant and the throttle degrades to precisely
  the per-email form its own comment warns "would let anyone lock the admin out
  of their own catalog". It also keeps a deployment concern out of
  `bootstrap/app.php`, which is where this repo already puts that line.
- **No HTTP→HTTPS redirect at the origin.** Tempting and wrong: an origin-side
  redirect behind a proxy is exactly what turns a Cloudflare **Flexible**
  setting into an infinite loop. Flexible is itself the trap this whole session
  is about — it makes the 525 vanish instantly, which reads as success, while
  sending origin traffic in the clear. The runbook calls it out by name.
- **`.env` is mounted read-only.** The same rule as the missing `env_file:` in
  development, holding for a second reason here: nothing should rewrite its own
  configuration, and a stray `key:generate` on the box would invalidate every
  session leaving no trace of why. That is why the runbook uses
  `key:generate --show` and pastes the value in by hand.
- **PHP's upload limits sit deliberately above the application's.** A file
  rejected by PHP never reaches userland: `$_FILES` is empty and Laravel reports
  a missing field, which looks like a broken form. Letting it through means
  `StoreSubmissionRequest`'s `max:12288` rejects it and names the photo.
- **The runbook's ordering is load-bearing**, in two places. Verify against the
  raw IP with `curl --resolve` *before* touching DNS, so a failure is a private
  box rather than a public outage. And **replace** the parking A records rather
  than adding — additions round-robin with the parking service and the site
  works about half the time, which is a far worse bug than the one being fixed.

### Then it was run, and two things broke

The Docker proxy cleared later the same day, so the image was finally built and
the stack booted locally — in parallel with the user working through the runbook
on a real Lightsail box. Both hit `container collectai-web-1 is unhealthy` after
~213 seconds, for **two different reasons**, which is worth recording because
the symptom is identical and says nothing about the cause.

- **`$path_info` is not an nginx variable.** `fastcgi_split_path_info` populates
  `$fastcgi_path_info`; naming the other is a hard config error, not an empty
  value. nginx exited immediately, supervisord retried ten times and gave up —
  while php-fpm stayed up and healthy throughout, so `docker ps` showed a
  running container and compose reported only "unhealthy". The misleading part
  is that the surviving process is the one you'd naturally suspect.
- **On the box, the Cloudflare Origin CA private key would not parse**
  (`PEM_read_bio_PrivateKey() failed`). nginx validates its whole configuration
  before binding, so it reports exactly one fatal error and stops — meaning the
  box hit the certificate error *first* and would have hit the `$path_info` bug
  immediately after fixing it. Two bugs, one symptom, revealed strictly in
  sequence.

The durable fix is not either patch: it is that **the nginx configuration is now
parsed at build time**. A throwaway self-signed certificate is generated so that
`ssl.conf` (normally written by the entrypoint at boot) exists, `nginx -t` runs,
and all three files are deleted again. A typo now fails the build in
milliseconds instead of costing a deploy, a 213-second timeout and a log dig.
That is the general lesson: when a config error can only surface as
"unhealthy after four minutes", move the check to where it is cheap.

Also added to the runbook: `openssl` commands that verify both certificate files
parse *and* that the key matches the certificate, since two valid files from
different issuances fail later and far more confusingly.

### What was not verified at the time

`docker build --target prod` never ran. Docker Desktop on this Mac is configured
with an HTTP proxy at `http.docker.internal:3128` that does not respond, so the
daemon cannot pull `php:8.4-fpm-alpine`, `node:22-alpine` or `composer:2` — the
host's own network is fine, `curl` reaches the registry in 0.19s. What *was*
checked: both compose files validate, all three shell scripts pass `sh -n`, and
the dev suite is untouched at **207 passed, 609 assertions**. The first real
build will be on the Lightsail box, at step 5 of the runbook. A first-build
failure there is expected work, not a regression.

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
