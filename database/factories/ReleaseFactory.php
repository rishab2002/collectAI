<?php

namespace Database\Factories;

use App\Models\CastingVersion;
use App\Models\Release;
use App\Models\Series;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    use HasApprovalStates;

    public function definition(): array
    {
        return [
            'casting_version_id' => CastingVersion::factory(),
            'series_id' => Series::factory(),
            'year' => fake()->numberBetween(1995, 2026),
            // Format varies by brand, which is why it's descriptive metadata
            // rather than part of the release's identity.
            'collector_number' => fake()->numberBetween(1, 250).'/250',
            'status' => Release::STATUS_LIVE,
        ];
    }
}
