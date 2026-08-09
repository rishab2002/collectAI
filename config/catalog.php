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
    | OCR Agent
    |--------------------------------------------------------------------------
    |
    | The vision call that reads packaging text off a capture. The credential
    | lives here rather than in config/services.php to keep every value this app
    | is configured by in one file, the same way the admin identity above is.
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
        'api_key' => env('ANTHROPIC_API_KEY') ?: null,
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
    | Bulk import from public catalogs — currently hotwheels.fandom.com, whose
    | structured tables are where the bulk of the catalog comes from. Photograph
    | capture then adds what the wiki lacks.
    |
    | `auto_approve_threshold` is deliberately its own setting rather than a read
    | of review.auto_approve_threshold, which stays at 1.0. The two paths have
    | genuinely different risk profiles: a proposal from a photograph is one
    | model's reading of one card, while an imported row is a value a public
    | catalog states in a structured column and that a re-run will correct. What
    | must not happen is the two drifting into one dial, where lowering this to
    | get an import moving quietly starts auto-publishing captures.
    |
    | Note this threshold governs facts only. An inference layered on top of an
    | imported row — the real make and model behind a casting, whether it's
    | fantasy — is not something the wiki states, so it goes through
    | submission_proposals like any other pipeline output regardless of what this
    | is set to. In practice that means imported castings land pending: with no
    | model_id and is_fantasy false, Casting::approvalBlocker() refuses to let
    | them go live, and everything hanging off them stays pending too. That
    | backfill queue is the intended outcome, not a bug to tune away.
    |
    | `user_agent` is sent on every request. Fandom's API asks for a real contact
    | address, and an import that can't be identified is one they can only block.
    |
    */

    'import' => [
        'auto_approve_threshold' => (float) env('IMPORT_AUTO_APPROVE_THRESHOLD', 1.0),

        'hot_wheels_wiki' => [
            'api' => env('HW_WIKI_API', 'https://hotwheels.fandom.com/api.php'),
            'base_url' => env('HW_WIKI_BASE_URL', 'https://hotwheels.fandom.com'),
            'user_agent' => env('HW_WIKI_USER_AGENT', 'collectAI-importer/0.1 (https://collectai.club)'),

            // MediaWiki caps a titles query at 50 for unprivileged clients, and
            // asking for more silently drops the remainder rather than erroring.
            'batch_size' => (int) env('HW_WIKI_BATCH_SIZE', 50),

            // Courtesy pause between batches, in milliseconds.
            'delay_ms' => (int) env('HW_WIKI_DELAY_MS', 200),
        ],
    ],

];
