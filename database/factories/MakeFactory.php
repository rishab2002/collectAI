<?php

namespace Database\Factories;

use App\Models\Make;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Make>
 */
class MakeFactory extends Factory
{
    use HasApprovalStates;

    /**
     * makes.name is hard-unique, so the factory draws from a fixed pool of real
     * manufacturers rather than generating names. The pool size is therefore the
     * ceiling on how many distinct makes one test can create before faker's
     * unique() gives up — thirty is well past what any test here needs, and a
     * test that wants more should be passing explicit names anyway.
     *
     * @var list<string>
     */
    private const NAMES = [
        'Mazda', 'Toyota', 'Honda', 'Nissan', 'Subaru', 'Mitsubishi',
        'Ford', 'Chevrolet', 'Dodge', 'Plymouth', 'Pontiac', 'Buick',
        'Porsche', 'BMW', 'Mercedes-Benz', 'Audi', 'Volkswagen',
        'Ferrari', 'Lamborghini', 'Maserati', 'Alfa Romeo', 'Lancia', 'Fiat',
        'McLaren', 'Aston Martin', 'Jaguar', 'Land Rover', 'Lotus', 'Mini',
        'Volvo',
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(self::NAMES),
            'status' => Make::STATUS_LIVE,
        ];
    }
}
