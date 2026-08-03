<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Series;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Series>
 */
class SeriesFactory extends Factory
{
    use HasApprovalStates;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            // Not unique()'d: the same series name legitimately recurs across
            // years and brands, and series.name has no hard unique constraint.
            'name' => fake()->randomElement([
                'Night Burnerz', 'Fast & Furious', 'Then and Now', 'Boulevard',
                'Car Culture', 'HW Exotics', 'Muscle Mania', 'Super Treasure Hunt',
                'Premium Collection', 'MBX Highway',
            ]),
            'status' => Series::STATUS_LIVE,
        ];
    }
}
