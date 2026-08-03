<?php

namespace Database\Factories;

use App\Models\Make;
use App\Models\VehicleModel;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    use HasApprovalStates;

    /**
     * Nameplate => chassis code. No unique() here: models.name deliberately has
     * no hard unique constraint, because near-duplicates are the Comparison
     * Agent's problem to surface rather than the DB's to block.
     *
     * @var array<string, string|null>
     */
    private const MODELS = [
        'RX-7' => 'FD3S',
        'RX-8' => 'SE3P',
        'Supra' => 'A80',
        'AE86 Corolla' => 'AE86',
        'Civic' => 'EK9',
        'NSX' => 'NA1',
        'Skyline GT-R' => 'R34',
        'Silvia' => 'S15',
        'Impreza WRX STI' => 'GC8',
        'Lancer Evolution' => 'CP9A',
        'Mustang' => null,
        'Camaro' => null,
        'Charger' => null,
        '911 GT3' => '996',
        'M3' => 'E30',
    ];

    public function definition(): array
    {
        $name = fake()->randomElement(array_keys(self::MODELS));

        return [
            'make_id' => Make::factory(),
            'name' => $name,
            'chassis_code' => self::MODELS[$name],
            'status' => VehicleModel::STATUS_LIVE,
        ];
    }

    /**
     * A nameplate with no identifiable generation — the pipeline can't always
     * pin a chassis code from a package photo.
     */
    public function withoutChassisCode(): static
    {
        return $this->state(['chassis_code' => null]);
    }
}
