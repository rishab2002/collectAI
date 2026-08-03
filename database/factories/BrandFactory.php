<?php

namespace Database\Factories;

use App\Models\Brand;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    use HasApprovalStates;

    /**
     * Real brands mapped to their real parent companies, so fixtures exercise
     * the sister-brand relationship with data that actually has siblings in it
     * (Hot Wheels and Matchbox are both Mattel).
     *
     * Same pool-uniqueness caveat as MakeFactory — brands.name is hard-unique.
     *
     * @var array<string, string>
     */
    private const BRANDS = [
        'Hot Wheels' => 'Mattel',
        'Matchbox' => 'Mattel',
        'Tomica' => 'Takara Tomy',
        'Mini GT' => 'TSM Models',
        'Inno64' => 'Inno Models',
        'GreenLight' => 'GreenLight Collectibles',
        'Corgi' => 'Hornby',
        'Majorette' => 'Simba Dickie',
        'Auto World' => 'Round 2',
        'M2 Machines' => 'Castline',
    ];

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(array_keys(self::BRANDS));

        return [
            'name' => $name,
            'parent_company' => self::BRANDS[$name],
            'status' => Brand::STATUS_LIVE,
        ];
    }
}
