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

];
