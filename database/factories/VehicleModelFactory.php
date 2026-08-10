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
     * Nameplate => [chassis code, first model year, last model year]. A row is
     * one *generation* of a nameplate, so the years travel with the code — and
     * a generation with no manufacturer designation still has years, which is
     * why several entries here carry a null code and a real span.
     *
     * No unique() here: models.name deliberately has no hard unique constraint,
     * because near-duplicates are the Comparison Agent's problem to surface
     * rather than the DB's to block.
     *
     * @var array<string, array{0: string|null, 1: int|null, 2: int|null}>
     */
    private const MODELS = [
        'RX-7' => ['FD3S', 1992, 2002],
        'RX-8' => ['SE3P', 2003, 2012],
        'Supra' => ['A80', 1993, 2002],
        'AE86 Corolla' => ['AE86', 1983, 1987],
        'Civic' => ['EK9', 1997, 2000],
        'NSX' => ['NA1', 1990, 2005],
        'Skyline GT-R' => ['R34', 1999, 2002],
        'Silvia' => ['S15', 1999, 2002],
        'Impreza WRX STI' => ['GC8', 1992, 2000],
        'Lancer Evolution' => ['CP9A', 1998, 2001],
        'Mustang' => [null, 1964, 1973],
        'Camaro' => [null, 1967, 1969],
        'Charger' => [null, 1968, 1970],
        '911 GT3' => ['996', 1999, 2005],
        'M3' => ['E30', 1986, 1991],
    ];

    public function definition(): array
    {
        $name = fake()->randomElement(array_keys(self::MODELS));
        [$code, $start, $end] = self::MODELS[$name];

        return [
            'make_id' => Make::factory(),
            'name' => $name,
            'chassis_code' => $code,
            'generation_start_year' => $start,
            'generation_end_year' => $end,
            'status' => VehicleModel::STATUS_LIVE,
        ];
    }

    /**
     * A nameplate the pipeline could not place in a generation — the state a
     * casting whose name carries no model year lands in.
     */
    public function withoutGeneration(): static
    {
        return $this->state([
            'chassis_code' => null,
            'generation_start_year' => null,
            'generation_end_year' => null,
        ]);
    }
}
