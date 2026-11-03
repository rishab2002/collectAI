<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Models\VehicleModel;
use App\Services\Recognition\ColourFamilies;
use App\Services\Recognition\RecognitionIndex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The catalog file the local recognizer reads, and the colour vocabulary it
 * shares with it.
 */
class RecognitionIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/collectai-index-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function colours(): array
    {
        return [
            ['Metalflake Dark Red', 'red'],
            ['Spectraflame Purple', 'purple'],
            ['ZAMAC', 'grey'],
            ['Pearl White', 'white'],
            ['Red w/ White Stripes', 'red'],
            ['Hot Pink', 'pink'],
            ['Matte Black', 'black'],
            ['Gold Chrome', 'yellow'],
            ['Light Blue', 'blue'],
            ['Satin Mint Green', 'green'],
            ['Kawasaki', null],
            [null, null],
        ];
    }

    #[DataProvider('colours')]
    public function test_catalog_colours_map_onto_the_shared_families(?string $colour, ?string $family): void
    {
        $this->assertSame($family, app(ColourFamilies::class)->familyOf($colour));
    }

    public function test_the_shared_vocabulary_is_one_the_recognizer_can_use(): void
    {
        $data = json_decode(file_get_contents(ColourFamilies::path()), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($data['families'], 'name');

        foreach ($data['pixel_rules']['rules'] as $rule) {
            $this->assertContains($rule['family'], $names, 'Every pixel rule lands on a declared family.');
        }

        foreach ($data['neighbours'] as [$a, $b]) {
            $this->assertContains($a, $names);
            $this->assertContains($b, $names);
        }
    }

    public function test_the_index_holds_live_cars_with_what_the_recognizer_matches_on(): void
    {
        $mazda = VehicleModel::factory()->for(Make::factory()->state(['name' => 'Mazda']))->create(['name' => 'RX-7']);
        $casting = Casting::factory()->create(['name' => "'93 Mazda RX-7", 'model_id' => $mazda->id]);
        $hw = Brand::factory()->create(['name' => 'Hot Wheels']);
        $line = Series::factory()->for($hw)->create(['name' => 'Mainline']);
        $series = Series::factory()->for($hw)->create(['name' => 'HW J-Imports', 'parent_id' => $line->id]);
        $version = CastingVersion::factory()->for($casting)->create();

        $live = Variation::factory()->for(Release::factory()->for($version, 'castingVersion')->for($series)->state(['year' => 2018]))
            ->create(['color' => 'Metalflake Red', 'base_color' => 'Black Plastic', 'rim_type' => 'PR5', 'toy_number' => 'FYC14']);
        Variation::factory()->for(Release::factory()->for($version, 'castingVersion')->for($series)->state(['year' => 2021]))
            ->create(['color' => 'Blue'])->forceFill(['status' => 'pending'])->save();

        $counts = app(RecognitionIndex::class)->write($this->path);
        $index = json_decode(file_get_contents($this->path), true);

        $this->assertSame(['castings' => 1, 'variations' => 1], array_intersect_key($counts, ['castings' => 0, 'variations' => 0]));
        $this->assertSame(RecognitionIndex::FORMAT, $index['format']);
        $this->assertSame([[
            'id' => $live->id, 'casting_id' => $casting->id, 'brand_id' => $hw->id, 'year' => 2018,
            'color' => 'Metalflake Red', 'color_family' => 'red', 'base_color' => 'Black Plastic', 'base_color_family' => 'black', 'wheel_type' => 'PR5', 'toy_number' => 'FYC14',
            'series' => 'Mainline / HW J-Imports',
        ]], $index['variations']);
        $this->assertSame([[
            'id' => $casting->id, 'name' => "'93 Mazda RX-7", 'brand_ids' => [$hw->id], 'first_year' => 2018, 'last_year' => 2018,
            'make' => 'Mazda', 'model' => 'RX-7', 'fantasy' => false,
        ]], $index['castings']);
        $this->assertSame([['id' => $hw->id, 'name' => 'Hot Wheels']], $index['brands']);
        $this->assertSame([], glob($this->path.'.*.tmp'), 'Written beside and renamed, no leftovers.');

        $this->artisan('catalog:export-recognition-index', ['--path' => $this->path, '--include-pending' => true])
            ->expectsOutputToContain('2 variations')
            ->assertSuccessful();
        $this->assertSame(2021, json_decode(file_get_contents($this->path), true)['castings'][0]['last_year']);
    }

    public function test_an_empty_catalog_says_why_nothing_will_be_named(): void
    {
        $this->artisan('catalog:export-recognition-index', ['--path' => $this->path])
            ->expectsOutputToContain('Nothing is live yet')
            ->assertSuccessful();
    }

    public function test_the_index_is_refreshed_nightly_and_scans_pruned_hourly(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertSame('7 4 * * *', $events->first(fn ($e) => str_contains($e->command, 'catalog:export-recognition-index'))?->expression);
        $this->assertSame('0 * * * *', $events->first(fn ($e) => str_contains($e->command, 'catalog:prune-scans'))?->expression);
    }
}
