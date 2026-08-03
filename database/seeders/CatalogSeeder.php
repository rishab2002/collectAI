<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\Make;
use App\Models\Release;
use App\Models\Series;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * A small but real slice of the catalog: correct makes, models, brands and
 * castings, so downstream work has something recognisable to develop against
 * rather than faker noise.
 *
 * It also plants deliberate near-duplicates (see nearDuplicates()) — the
 * Comparison Agent's whole job is telling those apart from genuine new records,
 * and it can't be developed against a database where every name is distinct.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [];

        foreach ([
            'Hot Wheels' => 'Mattel',
            'Matchbox' => 'Mattel',
            'Mini GT' => 'TSM Models',
        ] as $name => $parentCompany) {
            $brand = Brand::create(['name' => $name, 'parent_company' => $parentCompany]);
            $brand->markLive();

            $brands[$name] = $brand;
        }

        $vehicles = [
            'Mazda' => ['RX-7' => 'FD3S'],
            'Honda' => ['Civic' => 'EK9'],
            'Nissan' => ['Skyline GT-R' => 'R34'],
            'Toyota' => ['Supra' => 'A80'],
        ];

        $models = [];

        foreach ($vehicles as $makeName => $nameplates) {
            $make = Make::create(['name' => $makeName]);
            $make->markLive();

            foreach ($nameplates as $nameplate => $chassisCode) {
                $model = $make->models()->create([
                    'name' => $nameplate,
                    'chassis_code' => $chassisCode,
                ]);
                $model->markLive();

                $models["{$makeName} {$nameplate}"] = $model;
            }
        }

        // A real Make/Model cast by two different brands — the case that proves
        // Casting is brand-specific while Model is shared.
        $this->casting($brands['Hot Wheels'], $models['Mazda RX-7'], "'95 Mazda RX-7");
        $this->casting($brands['Matchbox'], $models['Mazda RX-7'], 'Mazda RX-7 FD');

        $civic = $this->casting($brands['Hot Wheels'], $models['Honda Civic'], "'11 Honda Civic");
        $this->casting($brands['Hot Wheels'], $models['Nissan Skyline GT-R'], 'Nissan Skyline GT-R (R34)');
        $this->casting($brands['Mini GT'], $models['Toyota Supra'], 'Toyota GR Supra');

        // A fantasy casting: no real-world basis, so no Make/Model at all rather
        // than invented ones.
        $this->casting($brands['Hot Wheels'], null, 'Twin Mill');

        $nightBurnerz = Series::create([
            'brand_id' => $brands['Hot Wheels']->id,
            'name' => 'Night Burnerz',
        ]);
        $nightBurnerz->markLive();

        $release = Release::create([
            'casting_version_id' => $civic->versions()->first()->id,
            'series_id' => $nightBurnerz->id,
            'year' => 2011,
            'collector_number' => '112/244',
        ]);
        $release->markLive();

        // Two genuinely different variations of the same release — distinct rows,
        // not a notes field, and both accepted by the uniqueness index.
        foreach ([
            ['color' => 'Blue', 'rim_type' => '5SP', 'finish' => 'Enamel'],
            ['color' => 'Black', 'rim_type' => 'MC5', 'finish' => 'Spectraflame'],
        ] as $attributes) {
            $variation = $release->variations()->create($attributes);
            $variation->markLive();
        }

        $this->nearDuplicates($brands['Hot Wheels'], $models['Mazda RX-7']);
    }

    /**
     * Creates a casting together with its version 1.
     *
     * Every casting needs an original tooling row for releases to point at, and
     * nothing in the schema creates it automatically — it's left to the
     * application deliberately, since a DB-level hook would collide with
     * factories that build a casting and its version separately.
     */
    private function casting(Brand $brand, ?VehicleModel $model, string $name): Casting
    {
        $casting = Casting::create([
            'brand_id' => $brand->id,
            'model_id' => $model?->id,
            'name' => $name,
        ]);
        $casting->markLive();

        $version = $casting->versions()->create(['version_number' => 1]);
        $version->markLive();

        return $casting;
    }

    /**
     * Names that are close enough to trip naive matching but are not the same
     * record, plus one that genuinely is a duplicate spelled differently.
     *
     * These exist so trigram similarity and the Comparison Agent can be tuned
     * against realistic ambiguity: "'95 Mazda RX-7" vs "95 Mazda RX7" is the
     * same casting misread by OCR, while the FC is a different generation
     * entirely and must not be collapsed into the FD.
     */
    private function nearDuplicates(Brand $brand, VehicleModel $rx7): void
    {
        // Same casting, OCR-mangled name — left pending, which is where a
        // proposal the agent wasn't sure about should sit.
        Casting::create([
            'brand_id' => $brand->id,
            'model_id' => $rx7->id,
            'name' => '95 Mazda RX7',
            'notes' => 'Seeded near-duplicate: OCR variant of the FD casting.',
        ]);

        // Different real-world generation with a similar name — a legitimately
        // separate model and casting that fuzzy matching must not merge.
        $fc = $rx7->make->models()->create([
            'name' => 'RX-7',
            'chassis_code' => 'FC3S',
        ]);
        $fc->markLive();

        $fcCasting = Casting::create([
            'brand_id' => $brand->id,
            'model_id' => $fc->id,
            'name' => "'89 Mazda Savanna RX-7 FC3S",
        ]);
        $fcCasting->markLive();
        $fcCasting->versions()->create(['version_number' => 1])->markLive();
    }
}
