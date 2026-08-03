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
