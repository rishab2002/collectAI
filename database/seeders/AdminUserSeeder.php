<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Creates the single catalog admin — the account that captures submissions
     * from the phone and rules on proposals.
     *
     * The password comes from ADMIN_PASSWORD if it's set, and is otherwise
     * generated and printed once. No hardcoded default: a seeder that ships a
     * known password is a seeder that eventually runs against something
     * reachable from the internet.
     *
     * Idempotent, so re-running the seeder doesn't reset an existing admin's
     * password or fail on the unique email.
     */
    public function run(): void
    {
        $email = config('catalog.admin.email');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists — left unchanged.");

            return;
        }

        // blank(), not === null: config normalizes an empty ADMIN_PASSWORD to
        // null, but this is the layer that must not create a password-less admin
        // even if that normalization is ever removed.
        $password = config('catalog.admin.password');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(20);
        }

        $user = User::factory()->admin()->create([
            'name' => config('catalog.admin.name'),
            'email' => $email,
            // Passed in plain: the User model's 'hashed' cast hashes it on save.
            'password' => $password,
        ]);

        $this->command?->info("Created admin {$user->email}");

        if ($generated) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
