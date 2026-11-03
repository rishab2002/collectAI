# collectAI — a diecast catalog

A brand-agnostic catalog of diecast cars — Hot Wheels, Matchbox, Mini GT and
whatever comes next — that collectors browse by **brand → main line →
sub-series → car**. It is filled three ways: bulk-imported from the Hot Wheels
and Matchbox wikis, typed in by hand, and photographed with a phone and read on
the server itself. Collectors can photograph a pile of their own cars and add
them to their collection. Nothing goes public until it has been checked, and
nothing depends on an outside AI service.

Built on Laravel 13 (PHP 8.4), PostgreSQL 16, Redis, and Cloudflare R2 for
photos. Runs in Docker.

This README is the hands-on tutorial: how to run it, fill it, make it public,
change it, and deploy it. For *why* things are the way they are, see
[CLAUDE.md](CLAUDE.md) (the project's rules and decisions) and
[docs/session-log.md](docs/session-log.md) (the history behind them).

---

## Contents

1. [The ten-minute picture](#1-the-ten-minute-picture)
2. [Run it on your machine](#2-run-it-on-your-machine)
3. [Fill the catalog from the wikis](#3-fill-the-catalog-from-the-wikis)
4. [Make it go live](#4-make-it-go-live)
5. [Day-to-day: add cars, photos and captures](#5-day-to-day-add-cars-photos-and-captures)
6. [Where to edit things, and what each edit does](#6-where-to-edit-things-and-what-each-edit-does)
7. [Keep it up to date](#7-keep-it-up-to-date)
8. [Deploy to collectai.club](#8-deploy-to-collectaiclub)
9. [Tests and checks](#9-tests-and-checks)
10. [Troubleshooting](#10-troubleshooting)
11. [Data rules you must not break](#11-data-rules-you-must-not-break)
12. [Command reference](#12-command-reference)

---

## 1. The ten-minute picture

**How the catalog is shaped.** Every car hangs off a tree:

```
Brand (Hot Wheels)
 └─ Main line (Mainline)                 ← what you pick first on the site
     └─ Sub-series (HW J-Imports)        ← then this
         └─ Release (2024, #112)         ← a year the car was sold in that series
             └─ Variation (Red, PR5 wheels, toy # HKH01)  ← one physical car
                 └─ Photos
```

A **casting** is one physical toy tool (the "Nissan Skyline GT-R (R34)" die),
and it can be sold in many releases, under many series, even under several
brands. Separately, each casting is linked to the **real car** it models
(Make: Nissan, Model: Skyline GT-R, generation BNR34) — or flagged as a
**fantasy** casting if there is no real car (Bone Shaker).

**Live vs pending — the one thing to understand.** Every record is either
`pending` (only admins can see it) or `live` (everyone can). The public site
shows **live records only**. Imported cars arrive *pending*, because the wikis
never say which real make and model a casting is — and a car nobody has
identified is not allowed to go live. [Section 4](#4-make-it-go-live) is how
they get identified and published.

**Who does what.**

| You want to… | Use |
|---|---|
| Load thousands of cars at once | The wiki importer ([§3](#3-fill-the-catalog-from-the-wikis)) |
| Add one car you have in hand, with photos | **Admin → Add a car** ([§5](#5-day-to-day-add-cars-photos-and-captures)) |
| Photograph a carded car and let the AI read it | **Admin → New capture** ([§5](#5-day-to-day-add-cars-photos-and-captures)) |
| Decide what goes public | **Admin → review queues** + `catalog:publish-imported` ([§4](#4-make-it-go-live)) |
| Browse like a collector | The public site at `/` |
| Keep your own collection and wishlist | **Join** (top right), then *Add to my collection* on any car ([§5.6](#56-collector-accounts-and-collections)) |
| Show what cars are worth | The daily price run ([§5.7](#57-prices-ebay-sold-ebay-asking-msrp)) |
| Photograph a pile of your cars and add them to your collection | **Scan** (top bar) ([§5.8](#58-scan-a-pile-of-cars--scan)) |

**No outside AI is needed.** Reading cards, scanning piles and naming the real
car behind each casting all run on your own server: the *recognizer* (a small
Python service using OpenCV and Tesseract) and a vehicle dictionary file. The
Claude API is an optional switch (`CATALOG_AI_DRIVER=claude`), never required.

---

## 2. Run it on your machine

You need **Docker Desktop** and **Node 22+** (for building the page styles).
Everything else runs inside containers.

### 2.0 The quick way: one command

Everything below is wrapped in **`./collectai`**, a script in the repo folder.
It runs exactly the commands this README documents, printing each one as it
goes, and `--dry-run` shows them without running anything:

```bash
git clone https://github.com/rishab2002/collectAI.git && cd collectAI && ./collectai setup
```

```bash
./collectai import
```

| Command | Does |
|---|---|
| `./collectai setup` | First time on your computer: `.env`, containers, database, admin account, styles |
| `./collectai import` | Downloads the wiki dumps and runs the whole pipeline of §3–4 (~20 min) |
| `./collectai publish` | After approving things in `/admin/proposals`: publishes them and refreshes the scanner |
| `./collectai train` | Trains the scanner's visual model (§5.10) |
| `./collectai test` | Every test and formatting check |
| `./collectai status` | What's running, and the recognizer's health |
| `./collectai deploy` | **On the server:** pull the latest code, rebuild, restart (§8.5) |
| `./collectai ios` / `ios-release 1.1` | Open the iPhone project / upload a new build to TestFlight (§8.4) |
| `./collectai artisan …` | Any `php artisan` command |

It reads `.env` to know where it is: `APP_ENV=production` means the server, and
it then adds the production (and, with `CLOUDFLARE_TUNNEL_TOKEN` set, the
tunnel) compose files by itself. `./collectai help` lists everything. The rest
of this README is what each command does, step by step.

### 2.1 First-time setup, step by step

```bash
git clone https://github.com/rishab2002/collectAI.git
cd collectAI
cp .env.example .env
```

Open `.env` and set at least:

```dotenv
ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=pick-a-strong-password    # survives re-seeding; leave blank to get a random one printed once
CAPTURE_DISK=local                       # photos on your disk until R2 is set up (see §6.2)
```

Then start the stack and prepare the app:

```bash
docker compose up -d --build
```

```bash
docker compose exec app php artisan key:generate
```

```bash
docker compose exec app php artisan migrate
```

```bash
docker compose exec app php artisan db:seed --class=AdminUserSeeder
```

```bash
npm install
```

```bash
npm run build
```

Open **http://localhost:8000** for the public site and
**http://localhost:8000/login** to sign in as the admin.

> **Seeding, two ways.** `db:seed --class=AdminUserSeeder` creates only your
> admin account — use this for a catalog you mean to keep.
> `migrate:fresh --seed` **wipes the database** and adds a handful of fake demo
> cars and a fake capture; it is for development and tests only.

### 2.2 What is running

`docker compose up -d` starts five containers:

| Container | Port | What it is |
|---|---|---|
| `app` | 8000 | Laravel (`php artisan serve`) |
| `db` | 5432 | PostgreSQL 16 — database `diecast`, plus `diecast_testing` for the tests |
| `redis` | 6379 | Queue and cache |
| `meilisearch` | 7700 | Not used yet (search isn't built) |
| `recognizer` | 8090 | The local car recognizer (§5.8) — Python, OpenCV, Tesseract, and the trained model if there is one (§5.10). `curl localhost:8090/health` |
| `trainer` | — | Not started by `up`. Trains the visual model on demand (§5.10) |

**Every `php artisan …` command in this README runs inside the app container**
— that's why each one starts with `docker compose exec app`.

### 2.3 Stopping and starting

```bash
docker compose down
```

```bash
docker compose up -d
```

`down` keeps your data (it lives in the `pgdata` volume). Only
`docker compose down -v` deletes it.

---

## 3. Fill the catalog from the wikis

This loads **every year of the Hot Wheels Wiki (1968–2027) and the Matchbox
Wiki (1953–2027, Lesney era included)**, then **Tomica — including Tomica
Limited Vintage and TLV Neo — and Mini GT** from their wikis: about 6,800
castings and 51,000 variations across four brands. It then groups the series
into main lines and attaches the photos that are licensed for reuse.

| Brand | Source | Castings | What's covered |
|---|---|---|---|
| Hot Wheels | Hot Wheels Wiki (dump) | 2,073 | Every year 1968–2027 |
| Matchbox | Matchbox Wiki (dump) | 1,763 | 1953–2027, Lesney years included |
| Tomica | Tomica Wiki (API) | 2,534 | Classic Tomica, Tomica Limited Vintage, TLV Neo, Tomica Premium, Tomica Limited |
| Mini GT | Mini GT Wiki (API) | 440 | 2018–2022 only — the wiki stops there |

Schuco, Para64, Inno64 and TrueScale/Mini GT's own websites are **not**
sources — see [§11](#11-data-rules-you-must-not-break) for why and what to do
about it. Each step is **safe to re-run**: it updates what it already created
and never duplicates.

Run the steps in this order.

### Step 1 — Download the wiki dumps (one minute)

The wikis publish a full copy of every page. One download replaces tens of
thousands of web requests.

```bash
mkdir -p storage/app/wiki-dumps
```

```bash
curl -fL -o storage/app/wiki-dumps/hotwheels_pages_current.xml.7z https://s3.amazonaws.com/wikia_xml_dumps/h/ho/hotwheels_pages_current.xml.7z
```

```bash
curl -fL -o storage/app/wiki-dumps/matchbox_pages_current.xml.7z https://s3.amazonaws.com/wikia_xml_dumps/m/ma/matchbox_pages_current.xml.7z
```

Unpack them once (7-Zip is already in the container):

```bash
docker compose exec app sh -c "cd storage/app/wiki-dumps && 7z x -y hotwheels_pages_current.xml.7z && 7z x -y matchbox_pages_current.xml.7z"
```

`storage/app/wiki-dumps/` is git-ignored, so the files never get committed.

> `curl -I` (a HEAD request) on those URLs answers **403** — that is S3, not a
> missing file. A normal download works.

### Step 2 — Import every year (about 10 minutes for both)

```bash
docker compose exec app php artisan catalog:import-wiki hot-wheels --from=1968 --to=2027 --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:import-wiki matchbox --from=1953 --to=2027 --dump=storage/app/wiki-dumps/matchbox_pages_current.xml
```

What it does: reads each year's "List of YYYY …" page to find the casting
articles, then reads each article's **Versions** table into castings, releases
and variations. Brands and series go live; castings, releases and variations
arrive **pending** (§4 explains why and how to publish them).

What you should see (measured on the October 2026 dumps):

| | Hot Wheels | Matchbox |
|---|---|---|
| Castings | 2,073 | 1,569 |
| Releases | 22,631 | 12,045 |
| Variations | 33,744 | 13,449 |
| Time | 7½–11 min | 3 min |

Matchbox starts at 1953 so the Lesney years are included. A few hundred rows are reported as **skipped** — almost all are rows identical
in every column the importer reads (they differ only in the wiki's *Notes*
prose, which is deliberately never imported). That is expected.

Useful options: `--year=2024` for one year; `--limit=20` to try a few cars;
`--dry-run` to see what would happen without keeping anything.

### Step 3 — Group series into main lines (under a minute)

The wikis name each car's sub-series ("HW J-Imports") but not the main line
("Mainline"). This step places every sub-series under a main line, using the
rules in `config/catalog_lines.php` (§6.1).

```bash
docker compose exec app php artisan catalog:classify-series hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:classify-series matchbox --dump=storage/app/wiki-dumps/matchbox_pages_current.xml
```

It prints each line's share of releases. Expect Hot Wheels in 16 lines with
about 19% in **Other Series**, and Matchbox in 7 lines with about 7% in Other.
The table under the totals lists what is left in Other — read it before
adding a rule.

### Step 4 — Attach photos that are licensed for reuse (about 2 minutes)

```bash
docker compose exec app php artisan catalog:import-wiki-photos hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:import-wiki-photos matchbox --dump=storage/app/wiki-dumps/matchbox_pages_current.xml
```

For each car it takes the wiki article's main image — **only if that image's
own wiki page grants a reuse licence** — downloads it to your photo storage
(`CAPTURE_DISK`), attaches it to the exact variation it shows, and records the
photographer, licence and source so the site can credit them.

**Expect about 150 photos (32 Hot Wheels, 120 Matchbox), not 3,600.** Most
wiki images have no licence, or only a "Self" tag that says who took the photo
without letting anyone reuse it. Those are left alone on purpose
([§11](#11-data-rules-you-must-not-break)). Every other car needs a photo from
you — [§5](#5-day-to-day-add-cars-photos-and-captures).

Add `--dry-run` to count licensed images without downloading anything. Cars that
already have any photo are skipped, so your own photos are never replaced.

### Step 5 — Import Tomica and Mini GT (about 3 minutes)

These two wikis publish no dump, so this reads them through the wiki API —
about 75 polite requests for Tomica, one for Mini GT. No download step needed.

```bash
docker compose exec app php artisan catalog:import-wiki-catalog tomica
```

```bash
docker compose exec app php artisan catalog:import-wiki-catalog mini-gt
```

```bash
docker compose exec app php artisan catalog:classify-series tomica
```

```bash
docker compose exec app php artisan catalog:classify-series mini-gt
```

What to expect:

- **Tomica** — one brand with five lines: Classic Tomica (the numbered No. 1–140
  range), Tomica Limited Vintage, Tomica Limited Vintage Neo, Tomica Premium and
  Tomica Limited. Tomytec makes TLV for Takara Tomy, so it sits under Tomica
  rather than as a separate brand. TLV variants that share a number (LV-01a,
  LV-01b, LV-01c…) are one casting with several variations. About 150 pages
  are skipped as "no year category" — they are year-summary pages, not cars.
  The wiki also files some non-car items under TLV (e.g. Kaiyodo DioColle
  diorama pieces); they come in as the wiki lists them.
- **Mini GT** — 440 items from the wiki's 2018–2022 lists, in two lines (Mini GT
  and Kaido House). Each item is its own casting, because the wiki doesn't say
  which items share a tool.
- **No photos** for either: none of the images on those wikis carry a reuse
  licence. `catalog:import-wiki-photos` finds nothing to take there.

To edit how these lines are named or grouped, see `config/catalog_lines.php`
(the `tomica` and `mini-gt` entries) and
`app/Services/Import/FandomWiki/TomicaArticles.php` (`LINES`).

### Step 6 — Identify the real cars, then publish (see §4)

Imported cars are still pending at this point. Continue with [§4](#4-make-it-go-live).

---

## 4. Make it go live

A car becomes public when **it and everything above it is live**. For imported
cars the only thing standing in the way is "which real car is this?". Three
steps clear it.

### 4.1 Identify the real make and model (free, runs locally)

By default this needs nothing but the app. Each casting's name is looked up in
the vehicle dictionary **`resources/data/vehicles.json`** — about 95
manufacturers, 870 nameplates and the Hot Wheels originals that aren't real
cars. No API key, no cost, same answer every time:

```bash
docker compose exec app php artisan catalog:identify-castings --dry-run
```

```bash
docker compose exec app php artisan catalog:identify-castings
```

Measured over all 6,810 imported castings (2026-11-03):

| Outcome | Castings | What happens |
|---|---|---|
| Make **and** nameplate in the name (`'93 Mazda RX-7`) | 2,851 | Scored 0.93: published |
| Nameplate only, one make sells it (`'67 Camaro`) | 311 | Scored 0.85: waits for you in §4.2 |
| A known fantasy casting (`Bone Shaker`) | 44 | Published as fantasy |
| Not in the dictionary | 3,604 | Left alone — stays pending |

A spot check of 80 of the published answers found one wrong (fixed since). The
dictionary never claims a generation, so cars are filed under the bare
nameplate. **To identify more cars, add makes, nameplates and fantasy names to
`vehicles.json`** and run the command again — it only touches cars not yet
identified. (`needs_make` lists nameplates that are ordinary words, like
"Phantom", which only count when the make is in the name too.)

**Optional: the Claude API.** It knows far more cars and can name generations,
but it is an outside service and costs money. Set `CATALOG_AI_DRIVER=claude`
and put your Anthropic API key in `.env`:

```dotenv
COLLECTAI_ANTHROPIC_KEY=sk-ant-...
```

> It is deliberately **not** called `ANTHROPIC_API_KEY` — that name is picked up
> by other Anthropic tools on your machine and would switch them to billing this
> key.

With that driver, try a handful first. `--dry-run` throws the results away
afterwards, but **the API calls are still made and billed** — 25 cars is one
call:

```bash
docker compose exec app php artisan catalog:identify-castings --limit=25 --dry-run
```

Then for real, in chunks you're comfortable paying for:

```bash
docker compose exec app php artisan catalog:identify-castings --limit=500
```

Repeat until it reports nothing left. Each Claude call identifies 25 cars (set by
`METADATA_BATCH_SIZE`). A full import of all four brands is about 6,800
castings — roughly 275 calls.

For each car the AI answers *real* (make + model + generation), *fantasy*
(no real car), or *unknown*. Then:

- **Confident answers** (score ≥ `IMPORT_AUTO_APPROVE_THRESHOLD`, default 0.9)
  are approved and published straight away.
- **Unsure answers** wait for you in **Admin → Inferred records**
  (`/admin/proposals`). Approve or reject each one there.
- **Unknown** writes nothing; the car stays pending until you fix it by hand.

### 4.2 Review what the AI wasn't sure about

Open **http://localhost:8000/admin/proposals**. Each row shows the casting, what
the AI thinks it is, its confidence, and a link to the wiki page.

- **Approve** — publishes the car and its make/model.
- **Reject** — removes the AI's guess; the imported car stays (pending,
  unidentified). It won't be re-guessed automatically.

### 4.3 Publish the releases and variations

Identifying a car publishes the *car*, not its hundreds of releases. This step
publishes every imported release and variation whose parents are now live:

```bash
docker compose exec app php artisan catalog:publish-imported --dry-run
```

```bash
docker compose exec app php artisan catalog:publish-imported
```

Run it after every identification run and after every review session. It only
ever publishes **imported** records whose parents are already live, so it can't
publish anything a person still has to decide on. On a fresh full import, before
any identification, it reports about **85,700 records waiting** — that is
normal, and the number falls as cars are identified.

### 4.4 Check what the public will see — before and after

Sign in as admin and open the site. A banner at the top lets you **Preview
pending**: the public pages then include pending records too (marked by the
yellow banner), so you can judge the browse before publishing. **Stop preview**
shows exactly what visitors see. The preview is a per-admin setting; visitors
never see pending records.

### 4.5 The shortcut for a single car

Anything you add through **Admin → Add a car** goes live immediately — you are
the reviewer. See §5.

---

## 5. Day-to-day: add cars, photos and captures

Everything below is under **http://localhost:8000/admin** after signing in.

### 5.1 Add a car you have in hand — *Add a car*

Fill in:

- **Casting** — the toy's name ("'93 Mazda RX-7", "Bone Shaker"). Tick
  **Fantasy casting** if there is no real car behind it.
- **Real car** — make and model ("Mazda", "RX-7"), and optionally the
  generation (years and chassis code, e.g. 1992–2002, FD3S). Use the nameplate
  alone: "Corvette", not "Corvette Grand Sport".
- **Release** — brand, series and year (e.g. Mini GT · Mini GT · 2024). New
  brands and series are created for you.
- **Variation** — colour, wheels, toy number, etc. Leave blank what you can't
  see.
- **Photos** — card front, card back, and up to six loose shots.

It goes **live straight away**. If the same car (every field you filled in) is
already there, you'll be told its number instead of getting a duplicate.

**A new colour or year of a car that already exists?** Don't use *Add a car*
(that would create a second casting). Find it under **Catalog**, open it, and
use **Add a release or variation** at the bottom — brand, series and year are
pre-filled from its latest release.

### 5.2 Photos on an existing car

Open the car (Admin → Catalog → search), and under any variation choose **Add
photos**. Each photo has **Make primary** (the one shown in grids) and
**Remove**. The first photo of a car becomes primary automatically, preferring
the card front.

**Where else photos can come from.** Checked on 2026-11-03: the Tomica, Mini
GT and Inno64 wikis have no reuse-licensed images (0 of 150 sampled on each),
and a search of openly licensed photos across the web (Openverse, which covers
Flickr, Wikimedia Commons and others) found **none** for 15 of 15 sampled
castings — and its one hit for "Bone Shaker" was the full-size real car, not the
toy. So photos you (and, later, your collectors) take are the way to fill the
catalog.

### 5.3 Find anything — *Catalog*

Search by casting name, make or model. Each result shows its status, brand(s),
years and series paths, e.g. "Hot Wheels · 2010–2026 · Mainline / HW J-Imports".
The search shows every status, including pending.

### 5.4 Photograph a carded car — *New capture*

On your phone: take the card front, card back and any loose angles, add the
brand/year/series if you know them, and upload. The packaging is read in the
background — by the local recognizer (Tesseract) by default, or by Claude with
`CATALOG_AI_DRIVER=claude`. The local reader fills in the brand, collector
number, toy number and (when it matches a catalog car confidently) the casting
name; it leaves year and series empty rather than guess.

This needs two things running that the others don't:

1. The recognizer (`docker compose up -d` starts it), or `COLLECTAI_ANTHROPIC_KEY` with the Claude driver.
2. **A queue worker** — the reading happens in a background job:

```bash
docker compose exec app php artisan queue:work
```

Leave that running in its own terminal. Without it the upload succeeds but the
capture just sits "queued". (In production a worker container runs
automatically.)

> Today a capture produces a *reading* of the card, but turning that reading into
> proposed catalog records isn't built yet — so captures are best for
> photographing cars you then enter with *Add a car*.

### 5.5 The review queue — *Review queue*

The admin home page lists captures awaiting review and, whenever the AI has left
something for you, a link to **Inferred records** (§4.2). Approving a proposal publishes it together with any parent
records it needs, and the screen tells you exactly what one click will publish.

---

### 5.6 Collector accounts and collections

Anyone can create an account: **Join** in the top-right corner (or `/register`).

- **Sign-up** needs a name, an email and a password of at least 12 characters
  with letters and numbers. In production, passwords that appear in known data
  breaches are refused. Each address can create 5 accounts an hour at most.
- **Add to my collection** appears under every version of every car. Collectors
  can set how many they own, the condition (mint, carded, opened, loose,
  damaged) and when they got it. **Want it** adds it to a wishlist instead.
- **My collection** (`/collection`) lists both, with each car's latest price
  and the collection's total estimated value.
- **Account** (`/account`) changes the password (signing out other devices) or
  deletes the account and its collection for good.
- Only **published** cars can be collected. Nobody can see or change another
  collector's list.
- Signing up never makes an admin — the only admin is the one
  `AdminUserSeeder` creates. Admins sign in at the same `/login` and land in
  the admin area; collectors land in their collection.

There is **no password reset yet**, because that needs outgoing email. Until
email is configured, an admin can reset a collector's password from the
command line:

```bash
docker compose exec app php artisan tinker --execute="App\Models\User::where('email','them@example.com')->first()->update(['password' => 'a-new-long-password-42'])"
```

### 5.7 Prices: eBay sold, eBay asking, MSRP

Every published car gets a price that refreshes **about once a month**. A daily
job (03:17) prices the cars whose price is oldest — never-priced first — until
that day's eBay allowance is spent, so the whole catalog cycles through every
~30 days without hammering eBay. Each car's price comes from the first of these
that has one, and **the site always says which it is**:

| Kind | Where from | Shown as |
|---|---|---|
| Sold | eBay **Marketplace Insights** API — median of the 5 most recent sales | "eBay sold · median of last 5 sales" |
| Asking | eBay **Browse** API — median of current fixed-price listings (optional) | "eBay asking · median of N listings" |
| MSRP | Your list-price table, `config/catalog_prices.php` | "MSRP (list price)" |

Lots, bundles, other currencies and listings that don't name the car are
ignored. Each price links to the matching eBay listings.

**Setting it up:**

1. Create a developer account at **developer.ebay.com**, create an application,
   and copy its **Production** *App ID (Client ID)* and *Cert ID (Client
   Secret)* into `.env`:

   ```dotenv
   EBAY_CLIENT_ID=YourApp-...
   EBAY_CLIENT_SECRET=PRD-...
   ```

2. **Sold prices need eBay's permission.** The Marketplace Insights API is a
   restricted API: request access for your application from the eBay developer
   portal. Once approved, set `EBAY_INSIGHTS=true`.
3. **Until then (or as a fallback),** set `EBAY_LISTINGS_FALLBACK=true` to use
   current asking prices, which every developer account can read. They are
   always labelled as asking prices, never as sold.
4. Fill in `config/catalog_prices.php` with the MSRPs you know (per brand and
   main line). It is empty on purpose — a guessed MSRP would be shown as fact.
5. Try it on a few cars without saving anything:

   ```bash
   docker compose exec app php artisan catalog:update-prices --limit=10 --dry-run
   ```

   Then for real (the daily job does this on its own in production):

   ```bash
   docker compose exec app php artisan catalog:update-prices --limit=200
   ```

To have the daily job run on your own machine, leave this running in a terminal:

```bash
docker compose exec app php artisan schedule:work
```

**How many calls?** `PRICE_DAILY_BUDGET` (default 4,500, under eBay's usual
5,000/day) is the number of eBay calls per day; a car takes about 3. To refresh
every published car each month you need roughly *3 × published variations ÷ 30*
calls a day — about 4,500 covers 45,000 cars. If the published catalog grows
past that, raise the budget and ask eBay for a higher limit.

**Not used, and why:** scraping eBay's pages (against eBay's User Agreement),
hobbyDB (it blocks automated access outright), and toy-store pages for MSRP
(their terms forbid scraping). See [§11](#11-data-rules-you-must-not-break).

### 5.8 Scan a pile of cars — *Scan*

Signed-in collectors get **Scan** in the top bar (`/scan`). Upload up to six
photos, say what they show, and confirm what was found:

1. **Lay the cars out** on a plain surface that contrasts with them (a white
   sheet for dark cars, a dark one for light cars), **not touching** — cars
   that touch are seen as one blob.
2. **Turn them upside down if you can.** The base carries the casting name, a
   © year and often a code, and those are what the scanner reads. Right way up,
   a car is found and its colour noted, but colour and wheels alone aren't
   enough to name a car yet.
3. Choose **Several cars, spread out** — or **One car close up** for a single
   base or a card (otherwise the printed words on a close-up are mistaken for
   separate objects).
4. On the results page:
   - **Identified — confirm each one.** Only cars the scanner is sure of appear
     here (score ≥ 0.9 *and* clearly ahead of the next possibility). It names
     the casting; you pick the version in your hand from its list, which puts
     the ones whose colour — body or base — matches the photo first. *Add to
     my collection* adds one (or one more, if you already have it).
   - **Found, but not sure what they are.** No guesses. *Name it* lets you
     search the catalog and pick the car yourself.
5. **Help teach the scanner** (the tick box) keeps the picture of each car you
   confirm or name, with what it is. That is the training data for a future
   recognizer that can name loose cars from the top (§5.9). Unticked, the
   pictures are deleted when you finish the scan, or after 24 hours.

Pictures are stored privately (`SCAN_DISK`, the `local` disk by default — not
the public photo bucket) and only the collector who took them can see them.
Nothing is sent to any outside service.

The scanner matches against **`storage/app/recognition/catalog-index.json`**,
a file the app writes nightly with every live car. Write it now with:

```bash
docker compose exec app php artisan catalog:export-recognition-index
```

The recognizer notices the new file by itself. Only **live** cars are in it
(only those can be collected); a car you publish today is scannable after the
next export.

**What it is measured to do, and what isn't measured.** On rendered test
images it finds every separate car, names its colour, reads base text the right
way up or upside down, and tells same-named castings apart by the © year
(Hot Wheels and Matchbox both make a "Bone Shaker"). Matching a photo against
all 6,810 castings and 51,236 variations takes about 3 seconds for three cars.
**It has not yet been measured on real phone photos of real bases** — embossed
base text is small and low-contrast, so expect it to read some bases and not
others. Real photos will tell; `RECOGNIZER_MIN_CONFIDENCE` is the dial if it
offers too much or too little.

### 5.9 Run the recognizer yourself

The recognizer is `recognizer/`, a self-contained Python program. You can run
it on your own computer against your own photos, with no website and no network.

**Needs:** Python 3.11+ and Tesseract (`brew install tesseract` on a Mac,
`sudo apt install tesseract-ocr` on Linux).

```bash
cd recognizer && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
```

Copy the catalog file from the site (or write one from your own database —
`--include-pending` adds cars still in review):

```bash
docker compose exec app php artisan catalog:export-recognition-index --include-pending --path=storage/app/recognition/my-index.json
```

Then scan photos:

```bash
.venv/bin/python -m collectai_recognizer --index ../storage/app/recognition/my-index.json scan ~/Pictures/pile.jpg --all --save-crops /tmp/crops
```

It prints each car it is sure of, with its reasons and the best-matching
version, and with `--all` the ones it isn't. `--json` prints everything;
`--layout single` is for close-ups; `card photo.jpg` reads a carded car;
`serve --port 8090` runs the same HTTP service the website uses. Its tests:

```bash
.venv/bin/python -m unittest discover -s tests -t .
```

**Training the model that recognises cars by their looks** is §5.10.

### 5.10 Train the visual model (phase 3)

Phase 1 reads text. Phase 3 adds a model that recognises a car **by how it
looks**: shape, colour, tampos, wheels. That's what a loose car seen right way
up needs. It runs on your own server, with no outside API.

**How it works.** A small, openly licensed image model (**DINOv2-small** by Meta,
Apache 2.0) turns each photo of a car into 384 numbers. Training learns from
labelled photos — *this photo is that casting and version* — and keeps a
reference set of them. A scanned car is then recognised by which labelled photos
it is closest to. (This is "our own model" in the sense that matters: the
recognition is learned from collectAI's photos and runs on collectAI's server.
The base image model is reused rather than trained from scratch, which would
need millions of photos.)

**The one-command way:** `./collectai train` does everything in this section,
in order.

**What it learns from** (`catalog:export-training-data`, plus the wiki photos):

| Source | What | Count today |
|---|---|---|
| `scan` | Cars collectors confirmed or named in a scan, **only with "Help teach the scanner" ticked** | grows with use |
| `own` | Your own catalog photos — *Add a car* and approved captures | grows as you add photos |
| `wiki_licensed` | Wiki photos imported because they carry a reuse licence, with their licence and credit | 157 |
| `wiki_consented` | **Every** photo in the Hot Wheels and Matchbox wikis' Versions tables and infoboxes, used under Fandom's written consent **for training only** | ~43,700 photos of ~3,800 castings |

**The wiki photos are for training only — never shown on the site.** They are
used under Fandom's written consent to you (keep that document). They are never
added to the catalog, the public pages or the photo bucket. In fact they are
never even saved: `train.collect` downloads each one, turns it into its 384
numbers and discards it. Only the numbers and the label are kept
(`storage/app/recognition/model/`, about 35 MB for all of them). That is what
the scanner uses on the site. Placeholder images ("Image Not Available") are
skipped.

The wiki photos are collected in two steps (`./collectai train` runs both).
First list every photo with its label, from the dumps:

```bash
docker compose exec app php artisan catalog:export-wiki-photo-labels hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml.7z
```

```bash
docker compose exec app php artisan catalog:export-wiki-photo-labels matchbox --dump=storage/app/wiki-dumps/matchbox_pages_current.xml.7z
```

Then fetch and embed them. This runs at a polite 4 requests a second with an
identifying user agent, so the first run takes about 4 hours. It can be stopped
and restarted at any time and carries on where it stopped:

```bash
docker compose --profile training run --rm trainer python -m train.collect --labels /data/wiki-labels-hot-wheels.jsonl --labels /data/wiki-labels-matchbox.jsonl --model-dir /data/model --out /data/wiki-training
```

**It refuses to guess until it has proven itself.** Training keeps back some
photos and tests itself on them. It picks the similarity a match must reach for
**95% of its answers to be right**, on at least 30 test answers. Until it can
show that, looks alone never name a car. They only:

- put the version that looks most alike first;
- nudge a text match (e.g. between two castings with the same name);
- show "Looks most like …" as a hint, with a one-tap search, on cars it
  couldn't name.

**The test is deliberately hard.** It holds back whole *versions* of a car: it
learns the casting from, say, the blue and black versions, and is tested on the
red one it has never seen. **Your own photos make it better where it counts.**
Wiki photos are mostly clean, side-on shots; photos from your phone teach it what
a car looks like in a pile on your table.

**Run a training** (after adding photos or confirming scans):

```bash
docker compose exec app php artisan catalog:export-training-data storage/app/recognition/training-data
```

```bash
docker compose --profile training run --rm trainer python -m train.build --data /data/training-data --data /data/wiki-training --model-dir /data/model
```

(`docker compose --profile training run --rm trainer` on its own trains on
`training-data` alone.) The first run downloads the base model (88 MB, pinned revision) and builds the
training image (CPU PyTorch, about 1 GB, used only for training). It prints how
many photos it learned from, its measured top-1 and top-5 accuracy, and whether
looks alone are trusted yet. The result goes to
`storage/app/recognition/model/`, and the recognizer switches to it by itself.
157 photos take about 20 seconds; each new photo adds about 0.1 s, and photos
already seen are cached.

In production, use the same two commands with the production files; the
trainer writes into the shared `recognition` volume:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm web php artisan catalog:export-training-data storage/app/recognition/training-data
```

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml --profile training run --rm trainer
```

**On your own computer** (Python venv from §5.9):

```bash
cd recognizer && .venv/bin/pip install --index-url https://download.pytorch.org/whl/cpu torch==2.6.0 && .venv/bin/pip install -r requirements-train.txt
```

```bash
.venv/bin/python -m train.export_encoder --out ../storage/app/recognition/model/encoder.onnx
```

```bash
.venv/bin/python -m train.build --data ../storage/app/recognition/training-data --model-dir ../storage/app/recognition/model
```

Then `scan` with `--model ../storage/app/recognition/model` uses it.
`manifest.json` in the model folder records what the model learned from
(sources and licences), its measured accuracy and its thresholds.
`--precision 0.98` asks for a stricter bar. `--exclude-source scan` leaves a
source out.

---

## 6. Where to edit things, and what each edit does

### 6.1 How series are grouped into main lines — `config/catalog_lines.php`

This file decides which main line every sub-series appears under on the site.
For each wiki there is an **ordered** list of lines; a series goes to the
**first** line it matches:

```php
['name' => 'Car Culture',                      // the line shown on the site
    'names' => ['/^car culture\b/i'],          // regexes tried on the series name
    'categories' => ['Car Culture']],          // wiki categories on the series article
```

| Edit | Effect |
|---|---|
| Add a line (a new array entry) | A new main line appears on the brand page once you re-run classification |
| Move a line higher | It wins over the lines below it for series that match both |
| Add a regex to `names` | Series whose names match move into that line |
| Add a category to `categories` | Series whose wiki article carries it move into that line |
| Change `'other'` | Renames the catch-all line ("Other Series") |
| Change `numbered_fallback` | Which line gets unmatched series whose releases mostly carry a collector number |

Then apply it to the series already placed:

```bash
docker compose exec app php artisan catalog:classify-series hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml --dry-run
```

```bash
docker compose exec app php artisan catalog:classify-series hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml --reclassify
```

Without `--reclassify`, already-placed series are left where they are — that's
what protects a placement you made by hand.

> Example: to give "Silver Series" its own line, add
> `['name' => 'Silver Series', 'names' => ['/silver series/i'], 'categories' => []]`
> above `'Mainline'`, then run with `--reclassify`.

### 6.2 Settings in `.env`

In development, an `.env` edit applies to the next request or command. In
**production** the settings are cached when the containers start, so restart
them after editing `.env` on the server:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml up -d --force-recreate
```

| Setting | What it does |
|---|---|
| `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME` | The admin account the seeder creates. Changing the password later: delete that user and re-run `db:seed --class=AdminUserSeeder` |
| `CAPTURE_DISK` | Where photos are stored: `local` (this machine) or `r2` (Cloudflare R2, needed in production). Each photo remembers its disk, so switching later doesn't break old photos |
| `CAPTURE_MAX_PHOTO_KB` | Largest photo accepted (default 12 MB) |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_URL` | The R2 bucket. `R2_URL` is the public address photos are served from (`https://img.collectai.club`). Test with `php artisan capture:check-storage` |
| `CATALOG_AI_DRIVER` | `local` (default): cards are read by the recognizer, cars named by `resources/data/vehicles.json` — nothing leaves the server. `claude`: use the Claude API instead |
| `RECOGNIZER_URL` | Where the recognizer listens (`http://recognizer:8080` in Docker) |
| `RECOGNIZER_MIN_CONFIDENCE` (0.9), `RECOGNIZER_MIN_MARGIN` (0.08) | How sure the scanner must be, and how far ahead of the next car, before it offers a car. Raise for fewer, surer answers |
| `SCAN_DISK` (`local`), `SCAN_MAX_PHOTOS` (6), `SCAN_UNCONSENTED_HOURS` (24) | Where scan pictures are kept (keep it private), photos per scan, and how long pictures of collectors who didn't tick "help teach" are kept at most |
| `COLLECTAI_ANTHROPIC_KEY` | Only with `CATALOG_AI_DRIVER=claude`: the Claude API key |
| `METADATA_MODEL`, `METADATA_EFFORT`, `METADATA_BATCH_SIZE` | Claude driver only: the identifying model, how hard it thinks, cars per call |
| `OCR_MODEL`, `OCR_EFFORT` | Claude driver only: the capture-reading model. Prefer raising `OCR_EFFORT` before changing the model |
| `OCR_MAX_PHOTOS` | Most photos of a capture that are read (either driver) |
| `IMPORT_AUTO_APPROVE_THRESHOLD` (0.9) | How sure the AI must be (0–1) for an identification to publish without you. Lower = less review work, more risk |
| `IMPORT_GENERATION_THRESHOLD` (0.65) | How sure it must be before recording a car's generation (e.g. "C3 1968–1982") |
| `REVIEW_AUTO_APPROVE_THRESHOLD` (1.0) | The same for captures. At 1.0 nothing a person photographed publishes without review — keep it there |

The full annotated list is in `config/catalog.php`.

### 6.3 What the public pages look like — `resources/views/catalog/`

| File | Page |
|---|---|
| `home.blade.php` | `/` — the brand list |
| `brand.blade.php` | A brand's main lines |
| `line.blade.php` | A line's sub-series and the year filter |
| `cars.blade.php` | The photo grid of cars in a sub-series |
| `casting.blade.php` | One car: photo, credit, every release |
| `../components/layouts/app.blade.php` | The header and frame around every page |

Admin screens are in `resources/views/admin/`, and shared pieces (form fields,
status badges, the preview banner) in `resources/views/components/catalog/`.
The queries behind the public pages are in
`app/Services/Catalog/CatalogBrowser.php`.

> **After editing any view, rebuild the styles:** `npm run build` (or keep
> `npm run dev` running while you work). The styling is Tailwind, which only
> includes the classes it found when it last built — a new class does nothing
> until you rebuild.

### 6.4 Adding another wiki or brand

- **A new brand needs no database changes.** Add cars for it through *Add a car*
  — type the new brand name and it's created.
- **Another Fandom wiki with the same page layout** as the Hot Wheels/Matchbox
  wikis: add a factory method in `app/Services/Import/FandomWiki/Wiki.php`
  (host, brand, source name), add its key to `keys()` and `fromKey()`, and add
  its lines to `config/catalog_lines.php`. Then it works with every command above.
- **A site with a different layout** (e.g. a manufacturer's own website) needs
  its own importer — and first a check that its terms allow it (§11).

### 6.5 Security settings

These are on automatically; this is what they are and where they live.

| Protection | Where | What it does |
|---|---|---|
| Content Security Policy and other headers | `app/Http/Middleware/SecurityHeaders.php` | Browsers run only this site's own scripts, so an injected script does nothing; the site can't be framed (clickjacking); photos may also load from `R2_URL`. HTTPS-only (HSTS) in production |
| Password rules | `app/Providers/AppServiceProvider.php` | 12+ characters with letters and numbers; breached passwords refused in production |
| Rate limits | same file, plus `LoginController` | 5 sign-ups per hour per address, 5 wrong passwords per email+address, 60 collection changes a minute |
| Secure cookies, no debug output | `.env.production.example` | `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `APP_ENV=production` — keep them |
| Error pages | `resources/views/errors/` | Plain pages that never show internals and work even if the database is down |
| Admin area hidden | `EnsureUserIsAdmin` | Non-admins get "not found", so the admin area can't be discovered |

**One rule when editing views:** never add inline `<script>` blocks,
`style="…"` attributes or `onclick=`-style handlers. The security policy blocks
them in the browser (the page silently breaks), and `SecurityHeadersTest` fails
if one appears. Put scripts in `resources/js/app.js` and styles in Tailwind
classes.

**Keep dependencies patched:**

```bash
docker compose exec app composer audit
```

```bash
npm audit
```

### 6.6 URLs and screens — `routes/web.php`

Every page's address is defined here, public pages first, then `/admin/…`.

---

## 7. Keep it up to date

The wikis publish a fresh dump every few weeks. To catch up:

1. Re-download and unpack the dumps (§3 step 1).
2. Re-run steps 2–4 of §3, then §4.1 and §4.3.

Everything is idempotent: existing cars are updated in place, new ones are
added, and nothing is duplicated. Cars you **rejected** stay rejected, series
you **placed by hand** stay placed, and cars with **your photos** keep them.

To catch up on just one recent year without a new dump (uses the wiki's API
instead of a file):

```bash
docker compose exec app php artisan catalog:import-wiki hot-wheels --year=2027
```

---

## 8. Deploy to collectai.club

Production is one server running `docker-compose.prod.yml` — nginx + PHP, a
queue worker, a scheduler (prices, the nightly recognition index, scan
pruning), the recognizer, PostgreSQL and Redis — behind Cloudflare. Put your
eBay keys (§5.7) in the server's `.env` too.

There are two ways to connect that server to Cloudflare. Pick one:

| | **Cloudflare Tunnel** ([§8.2](#82-host-it-with-cloudflare-cloudflare-tunnel)) — easiest | **Direct** ([docs/deploy.md](docs/deploy.md)) |
|---|---|---|
| The server can be | Any machine with Docker, **including your own Mac** | A cloud server with a public IP |
| Ports to open | None | 80 and 443 |
| HTTPS certificate on the server | Not needed | A Cloudflare Origin Certificate |
| DNS records | Created for you by Cloudflare | Two A records you edit |
| Start command | `docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d --build` | `docker compose -f docker-compose.prod.yml up -d --build` |

Either way the order matters: for the direct route, test against the server's
IP before touching DNS, and switch Cloudflare to *Full (strict)* only after the
certificate is installed.

Once the site is up, fill it the same way as §3–4, with two differences:
commands use `docker compose -f docker-compose.prod.yml run --rm web …`, and the
dumps have to be mounted into the container because production has no project
folder inside it:

```bash
mkdir -p ~/wiki-dumps
```

```bash
curl -fL -o ~/wiki-dumps/hotwheels_pages_current.xml.7z https://s3.amazonaws.com/wikia_xml_dumps/h/ho/hotwheels_pages_current.xml.7z
```

```bash
curl -fL -o ~/wiki-dumps/matchbox_pages_current.xml.7z https://s3.amazonaws.com/wikia_xml_dumps/m/ma/matchbox_pages_current.xml.7z
```

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm -v ~/wiki-dumps:/dumps:ro web php artisan catalog:import-wiki hot-wheels --from=1968 --to=2027 --dump=/dumps/hotwheels_pages_current.xml.7z
```

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm -v ~/wiki-dumps:/dumps:ro web php artisan catalog:import-wiki matchbox --from=1953 --to=2027 --dump=/dumps/matchbox_pages_current.xml.7z
```

…and the same pattern for `catalog:classify-series`, `catalog:import-wiki-photos`,
`catalog:identify-castings` and `catalog:publish-imported`. The `.7z` files can
be passed directly; they are unpacked on the fly. In production
`CAPTURE_DISK=r2`, so imported photos go straight to the R2 bucket.

**Deploying a code update** (on the server):

```bash
cd /opt/collectai && git pull && docker compose -f docker-compose.prod.yml up -d --build
```

With a tunnel, add the second file to every `docker compose` command — that
one and all the `run --rm web …` commands above:

```bash
cd /opt/collectai && git pull && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d --build
```

Migrations run automatically when the new container starts. After the first
deploy, write the recognizer's catalog file once rather than waiting for the
night (§5.8):

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm web php artisan catalog:export-recognition-index
```

### 8.1 Choosing where to host it (Porkbun, DigitalOcean, Lightsail, any server)

**Porkbun is where you bought the domain, not where the site runs.** Its
hosting plans are for static sites and WordPress; this app needs a server that
runs Docker (PHP, PostgreSQL, Redis, a background worker and the daily price
job). And because collectai.club's DNS was moved to Cloudflare, **DNS records
are changed in Cloudflare**, not in Porkbun — Porkbun's DNS page has no effect
any more.

Any of these works, because `docker-compose.prod.yml` doesn't care whose server
it runs on:

| Option | Effort | Notes |
|---|---|---|
| **DigitalOcean Droplet + Cloudflare Tunnel** (cheapest, ~$12/month) | [§8.3](#83-host-it-on-digitalocean-the-most-cost-effective-setup) | The recommended plan, with costs. DigitalOcean *Spaces* is storage only and can't run the app |
| **Cloudflare Tunnel** on any other machine | [§8.2](#82-host-it-with-cloudflare-cloudflare-tunnel) | No open ports, no certificate. Runs on a Lightsail/VPS server or on a Mac you leave on |
| **AWS Lightsail**, direct | Follow [docs/deploy.md](docs/deploy.md) exactly | Pick an instance with at least 2 GB of RAM (4 GB is comfortable with the recognizer). Can also use the tunnel instead of steps 4, 7 and 8 |
| **Any other Ubuntu server with Docker** — Hetzner, Linode, Vultr… | Same runbook | Only step 1 (creating the server and opening ports 80 and 443) differs; from step 2 on, the commands are identical |
| Cloudflare Workers, Pages or Containers alone | Not possible | See §8.2 — they have nowhere to keep the database |
| Managed Laravel platforms | Would need adapting | Not set up here — they run their own build, worker and database, so the Docker files don't apply |

The short version of going live **directly** (for the tunnel, see §8.2):

1. Create the server, open ports 80 and 443, install Docker (deploy.md §1–2).
2. Clone the repo, fill in `.env` from `.env.production.example` (deploy.md §3).
3. Create a **Cloudflare Origin Certificate** and put it in `certs/` (deploy.md §4).
4. Start it: `docker compose -f docker-compose.prod.yml up -d --build` (deploy.md §5).
5. Check it answers on the server's IP **before** touching DNS (deploy.md §6).
6. In **Cloudflare → DNS**, change the two **A records** for `collectai.club`
   and `www` from the Porkbun parking address to your server's IP, proxied
   (orange cloud) (deploy.md §7).
7. In **Cloudflare → SSL/TLS**, set the mode to **Full (strict)** (deploy.md §8).

The 525 error collectai.club shows today goes away at step 6.

### 8.2 Host it with Cloudflare (Cloudflare Tunnel)

**What "on Cloudflare" can mean here.** Cloudflare already does most of the
hosting for collectai.club: its DNS, its HTTPS certificate, caching and attack
protection at the edge, and the photo storage (R2, `img.collectai.club`). What
it cannot run is the app itself. PHP, PostgreSQL, Redis, the background worker,
the scheduler and the recognizer need one computer whose disk survives a
restart. Cloudflare Workers and Pages don't run PHP or databases, and Cloudflare
Containers wipe their disk every time an instance goes to sleep (Cloudflare's own
FAQ, checked 2026-11-03), which would erase the database.

So the app runs on a machine you choose, and a **Cloudflare Tunnel** connects it
to Cloudflare. A small program, `cloudflared`, runs next to the app and dials
*out* to Cloudflare. Visitors reach Cloudflare, and Cloudflare sends their
requests back down that connection. As a result:

- **No ports are opened** on the machine and it needs no public IP. A home Mac
  behind a router works.
- **No certificate is installed on the machine.** HTTPS is Cloudflare's.
- **Cloudflare creates the DNS records** for you.
- Nobody can reach the server except through Cloudflare. The app container
  publishes no ports in this mode.

**Which machine?** For a site people rely on, a small cloud server (Lightsail
2–4 GB, about $10–20 a month) that is always on. For trying it out, your own
Mac with Docker Desktop: free, but the site is down whenever the Mac sleeps or
is off, and every visitor uses your home upload bandwidth.

#### Step 1 — Get the code and settings onto the machine

On the machine (a cloud server: install Docker first, deploy.md §2; a Mac:
install Docker Desktop and set it to start at login):

```bash
sudo mkdir -p /opt/collectai && sudo chown $USER /opt/collectai && git clone https://github.com/rishab2002/collectAI.git /opt/collectai
```

```bash
cd /opt/collectai && cp .env.production.example .env
```

Fill in `.env` as [docs/deploy.md](docs/deploy.md) §3 describes (database
password, `APP_KEY`, R2 keys, admin email). Keep `APP_URL=https://collectai.club`.
Leave the `certs/` folder empty — a tunnel doesn't use it.

On a cloud server, its firewall needs **only port 22 (SSH)**. Close 80 and 443
if they are open.

#### Step 2 — Create the tunnel in Cloudflare

1. Cloudflare dashboard → **Networking → Tunnels → Create a tunnel**.
2. Name it `collectai`, then **Create Tunnel**.
3. On the install screen choose **Docker**. Cloudflare shows a command ending in
   `--token eyJ…`. **Don't run it** — copy only the long token after `--token`.
4. Put the token in the server's `.env`:

```dotenv
CLOUDFLARE_TUNNEL_TOKEN=eyJ...
```

Treat it like a password: anyone who has it can serve your domain.

#### Step 3 — Remove the old parking records

collectai.club still points at Porkbun's parking page. In **Cloudflare → DNS →
Records**, delete the **A** records for `collectai.club` and `www`. Cloudflare
won't create the tunnel's records over existing ones. **Leave
`img.collectai.club` alone** — that's the photo bucket.

#### Step 4 — Start everything

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d --build
```

The first build takes several minutes. Then check that the tunnel connected:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml logs cloudflared
```

Look for `Registered tunnel connection`. Back in the dashboard, the tunnel shows
as **Healthy** — select **Continue**.

#### Step 5 — Point the domain at the app

In the tunnel, open **Routes → Add route → Published application**:

| Field | Value |
|---|---|
| Subdomain | *(leave empty)* |
| Domain | `collectai.club` |
| Service URL | `http://web:80` |

Select **Add route**, then add a second route the same way with Subdomain
`www`. The address is `web`, the app's name inside Docker — **not**
`localhost`, which would mean the `cloudflared` container itself.

#### Step 6 — Always HTTPS

**Cloudflare → SSL/TLS → Edge Certificates → Always Use HTTPS: On.** The app is
told every request arrived over HTTPS, so visitors must really be on HTTPS.
(The *Full (strict)* setting in deploy.md is for the direct setup. It doesn't
apply to traffic arriving through a tunnel.)

#### Step 7 — Check it, then fill it

```bash
curl -sI https://collectai.club/up
```

`HTTP/2 200` means it's live. Create the admin account and fill the catalog as
in §3–4, adding `-f docker-compose.tunnel.yml` to each command, e.g.:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml run --rm web php artisan db:seed --class=AdminUserSeeder
```

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml run --rm web php artisan catalog:export-recognition-index
```

**Backups** work exactly as in deploy.md's *Backups* section: the database
dumps nightly to a second, **private** R2 bucket — never the public photo bucket.

**Two things not to do in tunnel mode:**

- **Don't publish ports 80/443** (for example by starting without the second
  `-f` file and then with it). In tunnel mode nginx trusts anything on the
  Docker network to say who the visitor is, which is only safe while
  `cloudflared` is the only way in.
- **Don't route the tunnel to `https://web:443`.** Use `http://web:80`; the
  tunnel already encrypts the traffic.

| If you see… | It means |
|---|---|
| Cloudflare error **1033** | `cloudflared` isn't connected: it isn't running, or the token in `.env` is wrong. Check its logs (step 4) |
| **502 Bad Gateway** | The tunnel works but can't reach the app. The Service URL must be `http://web:80`, and `web` must be healthy (`docker compose … ps`) |
| Adding the route fails because a DNS record already exists | Step 3 — delete the old A record first |
| `docker compose` complains about `!reset` | Compose is older than 2.24 — update Docker |

### 8.3 Host it on DigitalOcean (the most cost-effective setup)

**DigitalOcean Spaces can't host the site by itself.** Spaces is DigitalOcean's
file storage, the same kind of service as the R2 bucket that already holds your
photos (`img.collectai.club`). It stores and serves files. It can't run PHP,
the database, the background worker or the recognizer. On DigitalOcean those
run on a **Droplet** (a virtual server).

**Recommended plan — about $12 a month in total** (prices checked on
digitalocean.com and cloudflare.com, 2026-11-03):

| Piece | Plan | Monthly |
|---|---|---|
| Server | **DigitalOcean Droplet, Basic › Regular: 2 GB RAM, 1 vCPU, 50 GB SSD, 2 TB transfer** | **$12** |
| Domain, HTTPS, CDN, tunnel | Cloudflare **Free** plan (you already use it for DNS) | $0 |
| Photos and database backups | Cloudflare **R2** (already set up) — first 10 GB free, then $0.015 per GB, **no download charges** | $0 at today's size |
| Optional: Droplet weekly backups | 20% of the Droplet price | $2.40 |
| **Total** | | **$12** (or $14.40 with Droplet backups) |

Why these and not something else:

- **Not the $4 or $6 Droplet.** The stack (PostgreSQL, Redis, nginx + PHP, a
  queue worker, a scheduler and the OpenCV recognizer) plus building the image
  (Composer and the asset build) won't fit in 0.5–1 GB. 2 GB with 2 GB of swap
  should. This is an estimate: memory has not been measured on a Droplet yet.
  If scans feel slow (the recognizer uses one CPU per photo), move to **Basic ›
  Regular 4 GB / 2 vCPU, $24**. Resizing a Droplet keeps everything.
- **No managed database or App Platform.** Both add a monthly fee on top, and
  `docker-compose.prod.yml` already runs PostgreSQL on the Droplet, with nightly
  backups to R2.
- **R2, not Spaces, for photos.** Spaces costs **$5 a month minimum** (including
  250 GB stored and 1 TB downloaded; then $0.02 per GB stored and $0.01 per GB
  downloaded). R2 costs **$0** up to 10 GB, $0.015 per GB after that, and never
  charges for downloads. That is cheaper at every size. For comparison, 250 GB on
  R2 would be $3.60 against Spaces' $5. R2 is already working
  (`capture:check-storage` passes), and the app is built for it. Switching to
  Spaces would cost more and need code changes.
- **Cloudflare stays in front.** It costs nothing, and it supplies the HTTPS
  certificate, the tunnel, caching and attack protection. A DigitalOcean-only
  setup (DigitalOcean DNS plus Spaces, no Cloudflare) would cost more ($17+),
  not less. It would also need its own certificates, and this app's web server
  is built to sit behind Cloudflare.

The domain itself stays registered at Porkbun (renewal is billed there,
separately).

#### Step 1 — Create the Droplet

1. Sign up at digitalocean.com → **Create → Droplets**.
2. **Region:** the one nearest you and your visitors (e.g. New York or San
   Francisco).
3. **Image:** Ubuntu **24.04 (LTS) x64**.
4. **Size:** **Basic → Regular → $12/mo (2 GB / 1 CPU / 50 GB SSD)**.
5. **Authentication:** **SSH key**. On your Mac, show your public key (make one
   first with `ssh-keygen -t ed25519` if this says "No such file"):

```bash
cat ~/.ssh/id_ed25519.pub
```

   Paste it into **New SSH Key**.
6. Optional: tick **Backups** (weekly, +$2.40). The nightly database dump to R2
   (step 5) is the backup that matters; this one also covers the server itself.
7. **Hostname:** `collectai` → **Create Droplet**. Note its IP address.

#### Step 2 — Firewall: SSH only

The tunnel needs no open web ports. **Networking → Firewalls → Create
Firewall**. Name it `collectai`. Under **Inbound Rules**, keep only **SSH (TCP
22)** and delete anything else. Leave **Outbound Rules** as they are (allow
all). Under **Apply to Droplets**, choose `collectai` → **Create Firewall**.

#### Step 3 — Install Docker and swap

From your Mac (replace the IP):

```bash
ssh root@203.0.113.10
```

Then, on the Droplet:

```bash
curl -fsSL https://get.docker.com | sh
```

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab
```

The swap is what stops the first image build from running out of memory on a
2 GB Droplet.

#### Step 4 — Connect it to Cloudflare with a tunnel

Follow **§8.2 from Step 1** on the Droplet. Use the same commands; you're
already root, so `sudo` is harmless. In short: clone, fill `.env`, create the
tunnel and paste its token, delete the two parking A records, start with both
compose files, add the `collectai.club` and `www` routes to `http://web:80`,
and turn on Always Use HTTPS.

#### Step 5 — Nightly database backup

Follow the **Backups** section of [docs/deploy.md](docs/deploy.md). Create a
second, **private** R2 bucket `collectai-backups`, set
`R2_BACKUP_BUCKET=collectai-backups` in `.env`, then install the nightly run:

```bash
crontab -e
```

```
17 3 * * * cd /opt/collectai && ./docker/backup/pg-dump-to-r2.sh >> /var/log/collectai-backup.log 2>&1
```

**Never point backups at the photo bucket.** It's public, and a dump holds
every account's email and password hash. The script refuses to do it.

#### Updating the site later

```bash
ssh root@203.0.113.10
```

```bash
cd /opt/collectai && git pull && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d --build
```

**Prefer to open ports instead of a tunnel?** Use the same Droplet and follow
[docs/deploy.md](docs/deploy.md) from step 3. In the DigitalOcean firewall,
allow **HTTP (80) and HTTPS (443)** as well as SSH. Everything in that runbook
that mentions the Lightsail console applies the same way to the Droplet.

### 8.4 Test it as an iPhone app (TestFlight)

The app is the website in a native iPhone shell (Capacitor), so everything —
the catalog, accounts, the camera upload — is the same code. The shell loads
`https://collectai.club` (set in `capacitor.config.json`), so **deploy the site
first** (§8.1–8.3). The iOS project is in `ios/`, with the camera and photo-library
permission messages already set (iOS closes any app that opens the camera
without them).

**You need:** a Mac with the current Xcode, an iPhone, and your Apple Developer
Program membership.

**1. Prepare the project** (on the Mac, in the repo folder):

```bash
npm install
```

```bash
npx cap sync ios
```

```bash
npx cap open ios
```

That opens Xcode.

**2. Sign it.** In Xcode, click the blue **App** project → target **App** →
**Signing & Capabilities**: tick *Automatically manage signing* and choose your
**Team**. The bundle ID is `club.collectai.app`; if Apple says it's taken,
change it here **and** `appId` in `capacitor.config.json` to something like
`club.collectai.yourname`, then run `npx cap sync ios` again.

**3. Run it on your iPhone.** Plug the phone in (first time: unlock it and tap
*Trust*; on iOS 16+ also turn on *Settings → Privacy & Security → Developer
Mode*). Pick the phone at the top of Xcode and press **▶ Run**.

**4. Put it on TestFlight** (so it installs without a cable, and others can test):

1. In **App Store Connect → Apps → +**, create a new iOS app named collectAI
   with the same bundle ID.
2. In Xcode, choose **Any iOS Device (arm64)** as the target, then
   **Product → Archive**. When the Organizer opens, **Distribute App → App Store
   Connect → Upload**.
3. In App Store Connect → your app → **TestFlight**, wait for the build to
   finish processing, then add yourself (and up to 100 people on your team)
   under **Internal Testing**. Internal testing needs no Apple review.
4. Install the **TestFlight** app on the iPhone and accept the invite.

For every new upload, raise the **Build** number (target **App → General →
Identity**) — or use `./collectai ios-release` below, which does it for you.
The app icon is the collectAI car (1024×1024, made from `public/icons/`).

**5. Test the camera.** In the app: sign in as the admin → **Admin → New
capture** → tap **Card front**. iOS asks for camera permission once, showing
the message from `Info.plist`; allow it, take the photo, add a loose shot, and
tap **Upload & queue**. You should see "Submission #N queued — photos
uploaded", and the photos appear in the review queue. (Verified 2026-11-03 in
an emulated iPhone 15: both camera inputs, thumbnails, the upload and storage
all work. The real-device check is yours.)

**Testing against your Mac instead of the live site** (before deploying):
temporarily set `"url": "http://<your-Mac's-IP>:8000"` and `"cleartext": true`
in `capacitor.config.json`, run `npx cap sync ios`, and start the app with
`php artisan serve --host=0.0.0.0` on the Mac. Don't commit that change.

#### The quick way to upload a build (one command, on the Mac)

Once, in Xcode: **Xcode → Settings → Accounts → +** and sign in with your Apple
developer Apple ID. Then put your Team ID in `.env` (developer.apple.com →
**Account → Membership details → Team ID**):

```dotenv
APPLE_TEAM_ID=ABCDE12345
```

From then on, every upload is:

```bash
./collectai ios-release 1.0
```

It syncs the project, archives a Release build stamped with a new build number
(the date and time, so it's always higher than the last), signs it with your
team and uploads it to App Store Connect. The `1.0` is the version people see.
Leave it off to keep the current one, and raise it (`1.1`, `2.0`) when you
release something new to the public. 10–30 minutes later the build appears under
**App Store Connect → your app → TestFlight**. The app declares that it uses
only standard encryption (`ITSAppUsesNonExemptEncryption`), so there's no
export question to answer per build. (This script has not been run on a real
Mac from here, because there isn't one. If it stops at signing, use Xcode's
**Product → Archive** route above once, then try again.)

#### Release it on the public App Store

1. **App Store Connect → your app → App Store** tab, under the version:
   - **Screenshots** from an iPhone 6.9" (e.g. iPhone 16 Pro Max): the catalog,
     a car page, Scan results, My collection.
   - **Description** and **keywords**.
   - **Support URL** (`https://collectai.club`).
   - **Privacy Policy URL**, which is required because people create accounts.
     *The site has no privacy page yet; it needs one before submission.*
2. **App Privacy:** declare email address and user content (photos and the
   collection), used for app functionality and not for tracking. Accounts can
   be deleted in the app (**Account → Delete account**), which Apple requires.
3. **Age rating** questionnaire, and **Pricing and Availability**.
4. **Build:** pick the TestFlight build you tested.
5. **App Review Information:** give a demo collector account (email and
   password) and a note such as: "Sign in, tap Scan, photograph toy cars laid
   out on a table; tap Add to my collection."
6. **Add for Review → Submit.** Review usually takes one to two days.

**Expect questions under guideline 4.2** ("minimum functionality"). Apple
rejects apps that are just a website in a frame. collectAI's case is that the
app is built around the camera: Scan and capture. Lead with that in the
screenshots and the review note. If it's rejected, the usual fixes are native
touches, such as a native camera button or offline access to your collection.

#### How updates to the app work

The iPhone app is a native shell that **loads the live website**
(`server.url` in `capacitor.config.json`). So almost every change reaches the
app without Apple:

| You changed… | To get it into the app | Apple review? |
|---|---|---|
| Pages, features, styles, the catalog, the scanner, the model | Deploy the website (§8.5). The app shows it next time it's opened | **No** |
| The app's name, icon, permissions text (`ios/App/App/Info.plist`), `capacitor.config.json`, a Capacitor plugin, the Capacitor version | `./collectai ios-release 1.1` → TestFlight → submit | Yes |

So the loop for a new feature is: build and test it locally (`./collectai
setup`, `./collectai test`), push it, run `./collectai deploy` on the server,
and it's live on the site and in the app at once. A new App Store build is only
for changes to the shell itself. Keep the website working for the app version
people already have installed. They'll keep using the older shell for a while,
so don't remove a page the shell relies on (for example `/login`).

**Android** isn't set up yet; it's the same pattern
(`npm install --save-exact @capacitor/android@8.5.3`, then `npx cap add android`).

### 8.5 Updating the website: new features and new data

**New features (code).** Work and test on your computer, then publish:

1. Start the local stack (`./collectai setup` the first time,
   `docker compose up -d` after) and make the change.
2. `./collectai test`. Everything must pass.
3. Commit and push to GitHub. Finished work goes to `main` (CLAUDE.md, *How to
   work in this repo*).
4. On the server:

```bash
ssh root@your-server-ip
```

```bash
cd /opt/collectai && ./collectai deploy
```

`deploy` pulls `main`, rebuilds the images, restarts the containers (database
changes are applied automatically as the new version starts) and refreshes the
scanner's catalog. The site is unavailable for under a minute while it
restarts. The iPhone app picks the change up by itself (§8.4).

**If an update goes wrong**, go back to the version before it:

```bash
cd /opt/collectai && git log --oneline -5
```

```bash
cd /opt/collectai && git checkout <previous-commit> && docker compose -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d --build
```

Then `git checkout main` again once the fix is pushed. (Drop the tunnel file
from that command if you host without a tunnel.) A database change can't be
undone this way. Restore the previous night's backup if one went wrong.

**New data** — on the server:

| You want… | Run |
|---|---|
| The latest wiki changes (new cars, new versions) | `./collectai import` — safe to re-run; it updates what exists and adds what's new |
| What you approved in `/admin/proposals` to go live | `./collectai publish` |
| The scanner to learn from new photos and scans | `./collectai train` |
| Prices refreshed now rather than overnight | `./collectai artisan catalog:update-prices` |
| A car typed in by hand | **Admin → Add a car** — live at once, no command |

Everything that should happen regularly already does, by itself, in the
`scheduler` container: prices nightly, the scanner's catalog nightly, and
cleaning up unshared scan photos hourly.

---

## 9. Tests and checks

The tests run against a real PostgreSQL database (`diecast_testing`), never
your development data.

```bash
docker compose exec app php artisan test
```

```bash
docker compose exec app vendor/bin/pint --test
```

The first runs the 364 tests; the second checks code formatting (drop `--test`
to fix it). The recognizer has its own 35 tests, run from `recognizer/` (see
§5.9 for the one-time setup):

```bash
cd recognizer && .venv/bin/python -m unittest discover -s tests -t .
```

Both suites must pass before merging. After any database change, also
prove the schema builds from nothing — **this wipes the development
database**:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

---

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `MissingAppKeyException` | No app key: `docker compose exec app php artisan key:generate` |
| The public site says "Nothing is published yet" | Everything imported is still pending. Do §4, or turn on **Preview pending** as admin to look anyway |
| A brand or line page is a 404 for visitors | Same — nothing under it is live yet |
| Pages look unstyled, or a new style does nothing | Rebuild the styles: `npm run build` |
| A capture stays "queued" forever | No queue worker. Run `docker compose exec app php artisan queue:work` |
| Scan says "The recognizer isn't running" | Start it: `docker compose up -d recognizer`. Check it with `curl localhost:8090/health` |
| Scan finds cars but identifies none | Is the catalog file there and are cars live? `curl localhost:8090/health` shows its counts; write it with `catalog:export-recognition-index`. Then: turn the cars over so the base text shows, and use good light |
| Scan sees two touching cars as one, or a base's words as several cars | Spread the cars apart; choose *One car close up* for a single base or card |
| `identify-castings` stops with "The Claude API rejected the credential" | `COLLECTAI_ANTHROPIC_KEY` is missing or wrong in `.env`. It stops at the first batch rather than failing every one |
| Photos don't show in development | Use `CAPTURE_DISK=local` until R2 is configured; check R2 with `php artisan capture:check-storage` |
| Only ~150 cars have photos after step 4 | Expected — only those images are licensed for reuse (§3 step 4) |
| A series is in the wrong main line | Add a rule to `config/catalog_lines.php` and re-run classification with `--reclassify` (§6.1) |
| Lots of "skipped … identical row" lines after an import | Expected: those rows differ only in the wiki's Notes prose, which isn't imported |
| No prices on any car | eBay isn't set up — see §5.7. `catalog:update-prices --dry-run` says exactly what's missing |
| Prices say "asking", not "sold" | Marketplace Insights isn't approved for your eBay app yet (`EBAY_INSIGHTS=false`) — §5.7 step 2 |
| "Too many requests" when signing up | The sign-up rate limit (5 per hour per address). Wait an hour |
| A page looks broken after you edited a view | An inline script or style was blocked by the security policy — §6.5 |
| `collectai.club` shows a 525 error | Nothing is deployed yet; the domain still points at the registrar's parking page. See [docs/deploy.md](docs/deploy.md) |
| Tests wipe or touch your development data | Never expected — don't add `env_file:` to the `app` service in `docker-compose.yml` (see CLAUDE.md) |

---

## 11. Data rules you must not break

### Why Schuco, Para64, Inno64 and the Mini GT website aren't imported

Their terms were read on 2026-11-03:

| Site | What its terms say |
|---|---|
| tsm-models.com (TrueScale / Mini GT) | Any use of the site's content or information other than shopping is "strictly prohibited" without written permission; images named explicitly |
| paragonmodelstore.com (Para64 — para64.com redirects here) | "You agree not to reproduce, duplicate, copy … any portion of the Service … without express written permission" |
| inno-models.com (Inno64) | "© INNO MODELS — All rights reserved"; no reuse terms |
| schuco.de | No reuse terms at all, which by default means all rights reserved |

So their catalogs and photos can't be copied **unless they give permission** —
and manufacturers often will for a collector catalog that credits and links
them. [docs/source-permissions.md](docs/source-permissions.md) has a short
request you can send. If one says yes, keep the written permission and an
importer can be built for that source.

Until then, add their cars with **Add a car** (§5.1) and your own photos.

### The rules

These keep the catalog legal and original. The full reasoning is in
[CLAUDE.md](CLAUDE.md) under *Data sources*.

- **Facts yes, prose and other people's photos no.** Years, colours, wheels and
  toy numbers are facts and are imported. The wikis' article text and *Notes*
  are the editors' writing and are never imported.
- **Wiki photos only with a reuse licence**, always credited (photographer,
  licence, link) — the site prints the credit under the photo. A "Self" tag is
  not a licence.
- **Imported data is credited to the wiki** at the bottom of every car page
  (the wikis are CC BY-SA). Don't remove that line.
- **HWPriceGuide is not a source** — not its catalog, prices or photos. It's a
  commercial competitor's product.
- **Don't scrape images from the wider web.** Photos you take are the ones the
  catalog can freely show.
- **Check a new source's terms before writing an importer for it.**
- **Prices only through eBay's official APIs** — never by scraping eBay,
  hobbyDB or store pages.

---

## 12. Command reference

All commands are run as `docker compose exec app php artisan <command>` (local)
or `docker compose -f docker-compose.prod.yml run --rm web php artisan <command>`
(production). Every one accepts `--help`.

| Command | What it does | Useful options |
|---|---|---|
| `catalog:import-wiki {hot-wheels\|matchbox}` | Import castings, releases and variations from a wiki | `--from=1968 --to=2027`, `--year=`, `--dump=`, `--limit=`, `--dry-run` |
| `catalog:import-hot-wheels` | Same as `catalog:import-wiki hot-wheels` (older name) | same |
| `catalog:import-wiki-catalog {tomica\|mini-gt}` | Import Tomica (all five lines) or Mini GT from their wikis' API | `--limit=`, `--dry-run` |
| `catalog:classify-series {wiki}` | Place sub-series under main lines | `--dump=`, `--dry-run`, `--reclassify` |
| `catalog:import-wiki-photos {wiki}` | One licensed, credited photo per imported car | `--dump=`, `--limit=`, `--dry-run` |
| `catalog:identify-castings` | Work out each car's real make/model — locally from `vehicles.json`, or with Claude if `CATALOG_AI_DRIVER=claude` | `--limit=`, `--dry-run`, `--threshold=`, `--regenerate` (Claude only) |
| `catalog:export-recognition-index` | Write the catalog file the recognizer matches against (nightly on its own) | `--path=`, `--include-pending` |
| `catalog:export-training-data {dir}` | Export labelled photos for training: consented scans, your own and licensed catalog photos (§5.10) | `--source=scan --source=own --source=wiki_licensed` |
| `catalog:export-wiki-photo-labels {wiki}` | List every wiki photo of an imported car with its label, for training only (§5.10) | `--dump=`, `--out=` |
| `catalog:prune-scans` | Delete pictures of unconsented scans past their window (hourly on its own) | — |
| `catalog:publish-imported` | Publish imported releases/variations whose parents are live | `--dry-run` |
| `catalog:update-prices` | Refresh prices (runs daily on its own via the scheduler) | `--limit=`, `--msrp-only`, `--dry-run` |
| `schedule:work` | Run the scheduled jobs (prices, recognition index, scan pruning) — automatic in production | — |
| `capture:check-storage` | Test photo storage: write, read, public fetch, delete | — |
| `queue:work` | Run background jobs (reading captures) | — |
| `db:seed --class=AdminUserSeeder` | Create the admin account from `.env` | — |
| `migrate` | Apply database changes | — |
| `test` | Run the test suite | `--filter=` |

**The whole pipeline, in order** (local; after §2):

```bash
docker compose exec app php artisan catalog:import-wiki hot-wheels --from=1968 --to=2027 --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:import-wiki matchbox --from=1953 --to=2027 --dump=storage/app/wiki-dumps/matchbox_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:classify-series hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:classify-series matchbox --dump=storage/app/wiki-dumps/matchbox_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:import-wiki-photos hot-wheels --dump=storage/app/wiki-dumps/hotwheels_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:import-wiki-photos matchbox --dump=storage/app/wiki-dumps/matchbox_pages_current.xml
```

```bash
docker compose exec app php artisan catalog:import-wiki-catalog tomica
```

```bash
docker compose exec app php artisan catalog:import-wiki-catalog mini-gt
```

```bash
docker compose exec app php artisan catalog:classify-series tomica
```

```bash
docker compose exec app php artisan catalog:classify-series mini-gt
```

```bash
docker compose exec app php artisan catalog:identify-castings --limit=500
```

```bash
docker compose exec app php artisan catalog:publish-imported
```

```bash
docker compose exec app php artisan catalog:export-recognition-index
```

Review `/admin/proposals` (§4.2), then run `catalog:publish-imported` and the
export once more for whatever you approved.
