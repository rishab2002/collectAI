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

## Phase 1 — Claude vision + the database (no training)

What already exists: the OCR Agent reads one card with a Claude vision call
(`app/Services/AI/OcrAgent.php`), the catalog has toy numbers, names, years and
colours for ~51,000 variations, and Postgres has trigram indexes for fuzzy name
matching.

1. **Detect and read.** One Claude vision call per photo, with a structured
   output listing every car it sees: a rough position ("left, second from top"),
   whether it is carded or loose, any text it can read (casting name, toy
   number, year, series), and the visible colour. Same pattern as `OcrAgent`:
   schema file, per-field confidence, `stop_reason` checked.
2. **Match deterministically** (the Comparison Agent the project already
   planned — no LLM call): toy number first (exact), then casting name by
   trigram similarity within the brand, narrowed by year and colour. Return the
   top 3 candidates per car with a score.
3. **Confirm screen.** "We found 7 cars": each with its crop or position, the
   top candidates with their catalog photos, and Add / Not this / Skip. Added
   cars go into `user_collection_items` exactly as the manual button does.
4. **Where it lives:** a collector-facing **Scan** page (camera input, like the
   admin capture form), so it works in the phone browser and in the iPhone app.

Cost: one vision call per photo. Carded cars should match well (the card prints
the name and often the toy number); loose cars will often need the collector to
choose between candidates.

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
   the closest variations. Milliseconds and no per-call cost; Claude stays as
   the fallback for low-confidence crops and for reading card text.
4. **Keep measuring**: hold back a test set of confirmed photos and track
   top-1 / top-3 accuracy per brand before switching anything over.

## What to build first

Phase 1, steps 1–4: the schema file for the multi-car reading, the matching
service with tests on real catalog rows, and the Scan page with the confirm
screen. It reuses the OCR Agent's structure and the collection code that
already exists.
