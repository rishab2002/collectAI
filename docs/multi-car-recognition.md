# Multi-car recognition: photo in, collection entries out

The goal: a collector photographs one car, a few, or a whole shelf, and the app
adds every car it can identify from the catalog to their collection — after
they confirm. This is the plan, in three phases, each useful on its own. Phase 1
needs no training at all.

## The rule that shapes everything

**The model proposes; the collector confirms.** Many variations differ only by
wheels, a tampo or a base colour — details a photo often can't settle. So the
scanner never adds a car silently: it shows each car it found with its best
matches, and the collector taps the right one (or "not in the list"). Wrong
entries in someone's collection are worse than a tap.

## Phase 1 — local recognizer + the catalog file (no training) — **built 2026-11-03**

Originally planned around a Claude vision call; built instead with no outside
API at the owner's request. See README §5.8–5.9 and the session log.

1. **Detect and read** — `recognizer/` (Python, OpenCV, Tesseract): separate
   cars against the photo's border colour, name each one's colour, read base or
   card text at 0° and 180°.
2. **Match deterministically** against `catalog-index.json`
   (`catalog:export-recognition-index`): toy number exact, casting name fuzzy
   with numbers exact, © year and brand as corroboration. Report a casting only
   when it scores ≥ 0.9 and leads the next by 0.08; order its versions by colour
   (body or base).
3. **Confirm screen** — `/scan`: identified cars with their versions to pick
   from; found-but-unsure cars with a search to name them; Skip.
4. **Consent** — a tick box keeps confirmed crops as training data.

What phase 1 cannot do: name a loose car seen from above by shape, colour and
wheels. That is what phases 2 and 3 are for.

## Phase 2 — Collect labelled examples, with consent

Every confirmation in Phase 1 is a labelled example: *this crop of this photo is
variation #12345*. With the collector's permission (a clear line in the terms
and a setting), keep the crop and the label. This is the training data — and it
has to come from here: the rules in CLAUDE.md (*Data sources*) apply to training
data exactly as to catalog photos, so no scraped or unlicensed images.

It also fills the catalog: a confirmed, consented photo can become the
variation's catalog photo after admin approval.

## Phase 3 — Distil into a fast, cheap recogniser

Once there are enough confirmed examples (thousands, spread across many
castings):

1. **Train an image-embedding model** (fine-tune an openly licensed image
   encoder — check the licence of the weights before choosing one) so that
   photos of the same variation land close together.
2. **Index every variation's reference embeddings** in Postgres (`pgvector`).
3. **Recognise by nearest neighbour**: crop each detected car, embed it, find
   the closest variations. Milliseconds and no per-call cost; the phase 1 text
   reader stays for bases and cards.
4. **Keep measuring**: hold back a test set of confirmed photos and track
   top-1 / top-3 accuracy per brand before switching anything over.

## What to build next

1. Put real phone photos of real bases through phase 1 and measure: how many
   bases are read, how many confident answers are right. Tune
   `RECOGNIZER_MIN_CONFIDENCE` from that, not from rendered test images.
2. Phase 2 is running as soon as collectors tick the consent box;
   `catalog:export-training-data` produces the dataset.
3. Phase 3 when the dataset has a few thousand labelled crops across many
   castings. The model must run on our own server (CPU is fine for an
   embedding model of this size), alongside the recognizer, with no outside
   API — the same constraint phase 1 was built under.
