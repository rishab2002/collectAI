<?php

namespace Database\Factories;

use App\Models\Casting;
use App\Models\CastingVersion;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CastingVersion>
 */
class CastingVersionFactory extends Factory
{
    use HasApprovalStates;

    /**
     * Defaults to version 1 — the original tooling every casting has whether or
     * not it was ever retooled.
     *
     * Note that (casting_id, version_number) is unique, so building several
     * versions of the *same* casting needs distinct numbers:
     * CastingVersion::factory()->count(3)->for($casting)->sequence(
     *     ['version_number' => 1], ['version_number' => 2], ['version_number' => 3],
     * )
     */
    public function definition(): array
    {
        return [
            'casting_id' => Casting::factory(),
            'version_number' => 1,
            'retool_year' => null,
            'notes' => null,
            'status' => CastingVersion::STATUS_LIVE,
        ];
    }

    /**
     * A later tooling revision. Takes the version number explicitly because only
     * the caller knows what else exists for that casting.
     */
    public function retool(int $versionNumber = 2, ?int $year = null): static
    {
        return $this->state([
            'version_number' => $versionNumber,
            'retool_year' => $year ?? fake()->numberBetween(2000, 2026),
            'notes' => 'Retooled base and interior.',
        ]);
    }
}
