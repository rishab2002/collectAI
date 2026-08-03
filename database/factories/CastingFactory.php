<?php

namespace Database\Factories;

use App\Models\Casting;
use App\Models\VehicleModel;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Casting>
 */
class CastingFactory extends Factory
{
    use HasApprovalStates;

    public function definition(): array
    {
        return [
            'model_id' => VehicleModel::factory(),
            'is_fantasy' => false,
            // Packaging names carry the model year prefix, which is part of why
            // OCR output needs normalising before it can be matched.
            'name' => "'".fake()->numberBetween(80, 99).' '.fake()->randomElement([
                'Mazda RX-7', 'Honda Civic', 'Nissan Skyline', 'Toyota Supra',
                'Ford Mustang', 'Chevy Camaro', 'Porsche 911',
            ]),
            'notes' => null,
            'status' => Casting::STATUS_LIVE,
        ];
    }

    /**
     * An original casting with no real-world basis, which per CLAUDE.md gets no
     * Make/Model records at all rather than invented ones.
     */
    public function fantasy(): static
    {
        return $this->state([
            'model_id' => null,
            'is_fantasy' => true,
            'name' => fake()->randomElement([
                'Twin Mill', 'Bone Shaker', 'Rodger Dodger', 'Deora II', 'Sharkruiser',
            ]),
        ]);
    }

    /**
     * A real car the catalog hasn't matched to a VehicleModel yet — what the
     * pipeline produces when OCR reads a casting name it can't resolve. Shares
     * the null model_id with fantasy() and is otherwise its opposite.
     */
    public function unidentified(): static
    {
        return $this->state([
            'model_id' => null,
            'is_fantasy' => false,
        ]);
    }
}
