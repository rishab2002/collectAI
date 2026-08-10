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

    'import' => [
        'auto_approve_threshold' => (float) env('IMPORT_AUTO_APPROVE_THRESHOLD', 0.9),
    ],

];
