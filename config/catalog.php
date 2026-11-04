<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog Admin
    |--------------------------------------------------------------------------
    |
    | The single privileged account that captures submissions from the phone and
    | rules on the pipeline's proposals. Read through config rather than env()
    | directly so it still resolves once config is cached.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Catalog Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.test'),
        // Deliberately has no default. AdminUserSeeder generates and prints a
        // password when this is unset, rather than shipping a known one.
        //
        // The ?: null matters: a bare `ADMIN_PASSWORD=` line in .env resolves to
        // an empty string, not null, and an empty string is a value the seeder
        // would happily hash and accept as the admin's password.
        'password' => env('ADMIN_PASSWORD') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    |
    | Where photos taken on the phone are written. Configurable rather than
    | hard-coded to 'r2' for the same reason submission_photos.disk is stored
    | per row: a capture made while the app was pointed at the local disk has to
    | keep resolving after R2 is switched on. Set CAPTURE_DISK=local to use the
    | screen before real R2 credentials exist.
    |
    | The size cap is per photo and in kilobytes, to match Laravel's `max` rule.
    | 12 MB clears a modern phone camera with room to spare; PHP's own
    | upload_max_filesize and post_max_size still have to be at least as
    | generous or the request never reaches validation.
    |
    */

    'capture' => [
        'disk' => env('CAPTURE_DISK', 'r2'),
        'max_photo_kilobytes' => (int) env('CAPTURE_MAX_PHOTO_KB', 12288),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI driver
    |--------------------------------------------------------------------------
    |
    | Who does the two jobs that used to be Claude API calls:
    |
    |   reading a captured card (ReadsPackaging)
    |   naming the real car behind a casting (IdentifiesCastings)
    |
    | "local" (the default) keeps both inside this site: the card is read by
    | the recognizer service (Tesseract, on our own box), and the make/model
    | comes from the vehicle dictionary in resources/data/vehicles.json. No
    | request leaves the server and nothing is billed.
    |
    | "claude" switches back to the OcrAgent and MetadataAgent, which call the
    | Claude API with COLLECTAI_ANTHROPIC_KEY. Better at hard cases, but an
    | outside dependency — optional, never required.
    |
    */

    'ai' => [
        'driver' => env('CATALOG_AI_DRIVER', 'local'),
        'vehicles' => resource_path('data/vehicles.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recognition (the Scan page and the local card reader)
    |--------------------------------------------------------------------------
    |
    | The recognizer is the Python service in recognizer/, running next to the
    | app (docker compose service `recognizer`). It finds the cars in a photo,
    | reads base and card text with Tesseract and matches it against the index
    | file below. No outside API.
    |
    | `index_path` is written by catalog:export-recognition-index (scheduled
    | nightly) and read by the recognizer from the same volume.
    |
    | `min_confidence` / `min_margin`: a car is offered for confirmation only
    | when its best casting scores at least this and beats the next different
    | casting by the margin. Raise them to see fewer, surer answers.
    |
    | `disk` holds the crops of each found car. Private (the `local` disk),
    | not the public photo bucket: a collector's photos are theirs. Crops of
    | collectors who didn't agree to share them for training are deleted once
    | the scan is finished, or after `unconsented_hours` at the latest.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Privacy policy (/privacy)
    |--------------------------------------------------------------------------
    |
    | The address people write to about their data, shown on the policy, and
    | the date the policy last changed — bump it whenever the text changes.
    | App Store Connect asks for the policy's URL: https://collectai.club/privacy
    |
    */

    'privacy' => [
        'contact' => env('PRIVACY_CONTACT_EMAIL', 'privacy@collectai.club'),
        'updated' => '2026-11-04',
    ],

    'recognition' => [
        'url' => rtrim((string) env('RECOGNIZER_URL', 'http://recognizer:8080'), '/'),
        'timeout' => (int) env('RECOGNIZER_TIMEOUT', 120),
        'index_path' => env('RECOGNIZER_INDEX_PATH', storage_path('app/recognition/catalog-index.json')),
        // When the recognizer runs where it can't share the app's folder
        // (Railway: services have separate disks), it fetches the index from
        // GET /internal/recognition-index with this token. Unset — the default,
        // and right for docker compose, where the index is a shared volume —
        // and that route answers 404 to everyone.
        'index_token' => env('RECOGNIZER_INDEX_TOKEN') ?: null,
        'min_confidence' => (float) env('RECOGNIZER_MIN_CONFIDENCE', 0.9),
        'min_margin' => (float) env('RECOGNIZER_MIN_MARGIN', 0.08),
        'disk' => env('SCAN_DISK', 'local'),
        'max_photos' => (int) env('SCAN_MAX_PHOTOS', 6),
        'unconsented_hours' => (int) env('SCAN_UNCONSENTED_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic
    |--------------------------------------------------------------------------
    |
    | The credential every agent's Claude API call goes out under. It lives here
    | rather than in config/services.php to keep every value this app is
    | configured by in one file, the same way the admin identity above is, and it
    | sits above the per-agent blocks rather than inside one of them because
    | there is now more than one agent and they share an account.
    |
    | **Deliberately not named ANTHROPIC_API_KEY.** That is the variable Claude
    | Code, the Anthropic SDKs and the `ant` CLI all resolve first — ahead of an
    | OAuth subscription profile — so a process that inherits it bills to the API
    | account instead of the subscription, silently. This repo is edited with
    | Claude Code and its .env sits in that working directory, so the one thing
    | worth guaranteeing is that the file never carries that name. Anything that
    | sources .env then changes nothing about how the editor authenticates.
    |
    | The same trap has a blank-value form: an empty ANTHROPIC_API_KEY still wins
    | its precedence slot and authenticates with an empty key, so leaving it set
    | but blank is not the same as leaving it unset.
    |
    | Read through config, never env() directly, so it still resolves once config
    | is cached. Unset is not an error here — it surfaces as a 401 on the first
    | real call, which `catalog:identify-castings` reports with the fix.
    |
    */

    'anthropic' => [
        'api_key' => env('COLLECTAI_ANTHROPIC_KEY') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR Agent
    |--------------------------------------------------------------------------
    |
    | The vision call that reads packaging text off a capture.
    |
    | `model` defaults to Claude Opus 5. Don't lower it to save money without
    | measuring first: a misread here becomes a catalog row an admin has to
    | notice is wrong, and the review time that costs dwarfs the token
    | difference. `effort` is the cheaper dial and the one to sweep — it starts
    | at Anthropic's recommended `high` for intelligence-sensitive work, and
    | `medium` and `low` are worth testing against real cards before assuming
    | they're worse.
    |
    | `max_tokens` covers thinking *and* the JSON, which is why it is far larger
    | than the output: thinking is on by default on Opus 5, and a limit sized
    | for the answer alone truncates mid-read.
    |
    | `max_photos` bounds the request. Photos are base64-encoded into the body,
    | and a capture may hold eight 12 MB images — sending all of them would be a
    | ~130 MB request to read text that is legible in two. OcrAgent spends the
    | budget on card photos first.
    |
    */

    'ocr' => [
        'model' => env('OCR_MODEL', 'claude-opus-5'),
        'effort' => env('OCR_EFFORT', 'high'),
        'max_tokens' => (int) env('OCR_MAX_TOKENS', 8000),
        'max_photos' => (int) env('OCR_MAX_PHOTOS', 4),

        // Server-side retry on a substitute model when the requested one
        // declines for policy reasons. "default" lets Anthropic route by
        // refusal category rather than pinning a model that will one day be
        // deprecated. Blank disables it — the parameter is Claude-API-only and
        // the eligible substitutes are per-model, so a different OCR_MODEL may
        // have none.
        'fallbacks' => env('OCR_FALLBACKS', 'default') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Agent
    |--------------------------------------------------------------------------
    |
    | The call that works out which real-world vehicle a casting is a model of —
    | the Make/Model split the wiki never states, and the one thing standing
    | between the imported catalogue and a browsable one.
    |
    | `batch_size` is the dial with real consequences, and it trades two failure
    | modes against each other. Too small and a full 11,041-casting import is
    | that many round trips. Too large and two things go wrong at once: the
    | answer may not fit in max_tokens, which fails the whole batch rather than
    | one casting, and a long list is where a model starts answering from the
    | shape of the list instead of from each name. 25 is a starting point, not a
    | measured value.
    |
    | `max_tokens` covers thinking as well as the JSON, and the JSON here grows
    | with the batch: roughly 80 tokens per casting answered, plus whatever
    | thinking the harder names take. Raise it with batch_size, not instead of it.
    |
    | Same reasoning as the OCR agent on `model` and `effort`: a wrong
    | attribution becomes a live catalogue row that nothing flags again, and the
    | review time that costs dwarfs the token difference. `effort` is the cheaper
    | dial to sweep.
    |
    */

    'metadata' => [
        'model' => env('METADATA_MODEL', 'claude-opus-5'),
        'effort' => env('METADATA_EFFORT', 'high'),
        'max_tokens' => (int) env('METADATA_MAX_TOKENS', 16000),
        'batch_size' => (int) env('METADATA_BATCH_SIZE', 25),

        // As on the OCR agent: "default" lets Anthropic route by refusal
        // category rather than pinning a model that will one day be deprecated.
        // Blank disables it for a model with no eligible substitute.
        'fallbacks' => env('METADATA_FALLBACKS', 'default') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Review Thresholds
    |--------------------------------------------------------------------------
    |
    | Proposals the Review Agent scores at or above this go through without a
    | human; anything below waits for manual approval. Starts at 1.0 — nothing
    | auto-approves until there's enough real pipeline output to justify
    | lowering it, which is the safe direction to be wrong in.
    |
    */

    'review' => [
        'auto_approve_threshold' => (float) env('REVIEW_AUTO_APPROVE_THRESHOLD', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog Import
    |--------------------------------------------------------------------------
    |
    | Bulk import from a public catalog source. A deliberately separate dial from
    | review.auto_approve_threshold above, which stays 1.0: nothing a person
    | photographed auto-publishes, and that invariant is not up for tuning here.
    |
    | The two paths carry different risk. A photographed car is one record an
    | admin is already looking at, so waiting for them costs nothing. An import
    | is tens of thousands of rows, and holding all of them for review would mean
    | the catalog never becomes browsable — which also leaves the Comparison
    | Agent with nothing to diff against, the reason the importer comes first.
    |
    | What this threshold governs is only the *inferred* part. Facts the wiki
    | states — year, colour, wheel type, toy number — go live through the
    | importer on the strength of their provenance. The Make/Model split and
    | is_fantasy are not in the wiki and are an LLM inference, so they are scored
    | and compared against this.
    |
    | 0.9 is a starting point, not a measured value; sweep it against the first
    | `List of 2011 Hot Wheels` run. Err low rather than high: an inference below
    | the bar leaves a casting pending with no model, which Casting's approval
    | blocker already refuses to publish, so the cost is an invisible row in a
    | backfill queue. Above the bar the cost is a wrong make and model on a live
    | car that nothing will flag again.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Prices
    |--------------------------------------------------------------------------
    |
    | catalog:update-prices runs daily (routes/console.php) and prices up to
    | `daily_budget` published variations whose last check is older than
    | `refresh_days` — so every car refreshes about once a month without a
    | burst of calls. Sources, first answer wins:
    |
    |  1. eBay SOLD prices — Marketplace Insights API. eBay grants this API on
    |     application; set EBAY_INSIGHTS=true once yours is approved.
    |  2. eBay ASKING prices — Browse API, available to every developer
    |     account. Off unless EBAY_LISTINGS_FALLBACK=true, and always labelled
    |     as asking, never as sold.
    |  3. MSRP — the list-price table in config/catalog_prices.php.
    |
    | Scraping eBay's pages is against its User Agreement, and hobbyDB blocks
    | automated access, so neither is a source. See CLAUDE.md, Data sources.
    |
    */

    'prices' => [
        'refresh_days' => (int) env('PRICE_REFRESH_DAYS', 30),
        // eBay API calls per daily run. Checked before each car, so a run may
        // finish the car it started — at most 6 calls over. eBay's default
        // allowance is 5,000 calls a day; a car takes about 3, so 4,500 prices
        // ~1,500 cars a day, ~45,000 per 30 days. To refresh every published
        // variation monthly, budget ≈ 3 × variations ÷ refresh_days — raise it
        // (and ask eBay for a higher limit) as the published catalog grows.
        'daily_budget' => (int) env('PRICE_DAILY_BUDGET', 4500),
        // How many recent sales (or listings) the median is taken over.
        'sample' => (int) env('PRICE_SAMPLE', 5),

        'ebay' => [
            'client_id' => env('EBAY_CLIENT_ID') ?: null,
            'client_secret' => env('EBAY_CLIENT_SECRET') ?: null,
            'marketplace' => env('EBAY_MARKETPLACE', 'EBAY_US'),
            'currency' => env('EBAY_CURRENCY', 'USD'),
            // Toys & Hobbies › Diecast & Toy Vehicles.
            'category_id' => env('EBAY_CATEGORY_ID', '222'),
            'insights' => (bool) env('EBAY_INSIGHTS', false),
            'listings_fallback' => (bool) env('EBAY_LISTINGS_FALLBACK', false),
            'api' => env('EBAY_API_BASE', 'https://api.ebay.com'),
        ],
    ],

    'import' => [
        'auto_approve_threshold' => (float) env('IMPORT_AUTO_APPROVE_THRESHOLD', 0.9),

        /*
        | The bar a *generation* claim has to clear to be recorded. A third
        | dial, and a necessary one, because it governs a different question
        | from the one above.
        |
        | `auto_approve_threshold` decides whether an attribution is published:
        | get it wrong and a car goes live as a different car. This decides
        | which model row a casting is filed under, within an attribution that
        | already passed that bar — get it wrong and a 1963 Corvette is filed
        | with the 1968s, which is bad but bounded, visible, and fixable by
        | moving one row.
        |
        | They also score differently, and the difference was measured rather
        | than guessed. Over 22 castings sampled from the 2011 import the scores
        | come back **bimodal**, not spread:
        |
        |   0.00           a name with no model year and no generation in it —
        |                  "Mustang Funny Car", "Blown Camaro", "Toyota RSC".
        |                  Correct refusals, every one.
        |   0.66 - 0.92    a real generation, and in the sample *correct* every
        |                  time, including the awkward ones ('58 Impala as a
        |                  one-year body, '10 BMW M3 as E92, '69 Shelby GT500 as
        |                  1969-1970).
        |
        | So the bar belongs in the empty gap between them, not inside the upper
        | cluster. At 0.85 four of ten correct generations were discarded; at
        | 0.75 the cut still ran through the middle of the cluster and left
        | comparable castings on both sides ('65 Mustang unplaced while another
        | Mustang of the same generation was placed) — which is the tell that a
        | threshold is sitting on noise rather than on signal.
        |
        | 0.65 sits in the gap. Being too strict costs a collapsed row and a
        | visible backfill item; being too loose costs a car filed one
        | generation off. Re-measure before moving it — the gap is the thing to
        | aim at, and it will move with the model and the prompt.
        */
        'generation_threshold' => (float) env('IMPORT_GENERATION_THRESHOLD', 0.65),
    ],

];
