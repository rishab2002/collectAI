<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\RecordSource;
use App\Models\ReferenceItem;
use App\Models\ReferenceLink;
use App\Models\Release;
use App\Models\Series;
use App\Models\User;
use App\Models\Variation;
use App\Services\Recognition\StableKeys;
use App\Services\Reference\ReferenceLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reference products as training labels (tied to a catalog casting only when
 * certain) and as the Add a car form's "find the model" — which pre-fills the
 * form and links what's saved, so the scanner knows it at once.
 */
class ReferenceFormTest extends TestCase
{
    use RefreshDatabase;

    private Casting $boneShaker;

    protected function setUp(): void
    {
        parent::setUp();

        $hw = Brand::factory()->create(['name' => 'Hot Wheels']);
        $this->boneShaker = $this->catalogCasting('Bone Shaker', $hw, [2006, 2010], 'hotwheels_fandom', 'page:100', 'Black');
        $this->catalogCasting('Twin Mill', $hw, [2005, 2009], 'hotwheels_fandom', 'page:200');
        $this->catalogCasting('Twin Mill', $hw, [2007, 2012], 'hotwheels_fandom', 'page:300');
    }

    private function catalogCasting(string $name, Brand $brand, array $years, string $source, string $key, ?string $colour = null): Casting
    {
        $casting = Casting::factory()->fantasy()->create(['name' => $name]);
        RecordSource::factory()->forSourceable($casting)->create(['source' => $source, 'source_key' => $key]);
        $version = CastingVersion::factory()->for($casting)->create();
        $series = Series::factory()->for($brand)->create();

        foreach ($years as $i => $year) {
            $variation = Variation::factory()->for(Release::factory()->for($version, 'castingVersion')->for($series)->state(['year' => $year]))
                ->create(['color' => $i === 1 ? $colour : 'Red']);
            RecordSource::factory()->forSourceable($variation)->create(['source' => $source, 'source_key' => "{$key}:row:".($i + 1)]);
        }

        return $casting;
    }

    private function item(array $attributes): ReferenceItem
    {
        return ReferenceItem::create([
            'source' => 'hwpriceguide', 'source_key' => fake()->unique()->slug(), 'brand' => 'Hot Wheels', 'name' => 'x',
            'image_urls' => ['https://example.test/a.jpg'], ...$attributes,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_a_product_is_tied_to_a_casting_only_when_certain(): void
    {
        $labels = app(ReferenceLabels::class);

        $match = $labels->for($this->item(['name' => 'Bone Shaker (Black)', 'year' => 2010]));
        $this->assertTrue($match['matched']);
        $this->assertSame('hotwheels_fandom|page:100', $match['casting_key']);
        $this->assertSame('hotwheels_fandom|page:100:row:2', $match['variation_key'], 'colour and year pick the version');

        $chase = $labels->for($this->item(['name' => 'Bone Shaker Chase', 'year' => 2007]));
        $this->assertSame('hotwheels_fandom|page:100', $chase['casting_key']);

        $outOfRange = $this->item(['name' => 'Bone Shaker', 'year' => 1999]);
        $this->assertSame($outOfRange->castingKey(), $labels->for($outOfRange)['casting_key'], 'a year far from any release is not a tie');

        $ambiguous = $this->item(['name' => 'Twin Mill', 'year' => 2008]);
        $this->assertFalse($labels->for($ambiguous)['matched'], 'two castings fit: no guess');
    }

    public function test_mini_gt_item_numbers_tie_to_the_mini_gt_import(): void
    {
        $mgt = $this->catalogCasting('Lamborghini Huracán', Brand::factory()->create(['name' => 'Mini GT']), [2019], 'minigt_fandom', 'item:MGT00001');
        $item = $this->item(['source' => 'tsm', 'source_key' => 'MGT00001', 'item_code' => 'MGT00001', 'brand' => 'Mini GT', 'name' => 'Lamborghini Huracán']);

        $label = app(ReferenceLabels::class)->for($item);

        $this->assertSame(['minigt_fandom|item:MGT00001', 'minigt_fandom|item:MGT00001:row:1'], [$label['casting_key'], $label['variation_key']]);
        $this->assertNotNull($mgt);
    }

    public function test_find_the_model_sends_a_known_casting_to_its_page_and_a_new_one_to_the_form(): void
    {
        $known = $this->item(['name' => 'Bone Shaker (Black)', 'year' => 2010]);
        $new = $this->item(['source' => 'inno64', 'source_key' => 'IN64-R34-BL', 'item_code' => 'IN64-R34-BL', 'brand' => 'Inno64', 'line' => 'Limited Editions', 'name' => 'Nissan Skyline GT-R (R34) Bayside Blue']);

        $this->actingAs($this->admin())->get(route('admin.castings.create', ['find' => 'Bone']))
            ->assertOk()->assertSee('Already here as Bone Shaker')
            ->assertSee(route('admin.castings.show', ['casting' => $this->boneShaker, 'ref' => $known->id]), false);

        $this->actingAs($this->admin())->get(route('admin.castings.create', ['find' => 'in64-r34-bl']))
            ->assertOk()->assertSee('Use this')->assertSee(route('admin.castings.create', ['ref' => $new->id]), false);
    }

    public function test_a_reference_product_fills_the_form_in(): void
    {
        $item = $this->item(['source' => 'inno64', 'source_key' => 'IN64-R34-BL', 'item_code' => 'IN64-R34-BL', 'brand' => 'Inno64', 'line' => 'Limited Editions', 'name' => 'Nissan Skyline GT-R (R34) Bayside Blue']);

        $this->actingAs($this->admin())->get(route('admin.castings.create', ['ref' => $item->id]))
            ->assertOk()
            ->assertSee('value="Nissan Skyline GT-R (R34) Bayside Blue"', false)
            ->assertSee('value="Nissan"', false)
            ->assertSee('value="Skyline GT-R"', false)
            ->assertSee('value="Inno64"', false)
            ->assertSee('value="Limited Editions"', false)
            ->assertSee('value="IN64-R34-BL"', false)
            ->assertSee('name="reference_item_id" value="'.$item->id.'"', false)
            ->assertSee('<option value="Inno64"></option>', false);
    }

    public function test_a_car_saved_from_a_reference_product_is_linked_and_filed_under_its_line(): void
    {
        $item = $this->item(['source' => 'inno64', 'source_key' => 'IN64-R34-BL', 'item_code' => 'IN64-R34-BL', 'brand' => 'Inno64', 'name' => 'Nissan Skyline GT-R (R34) Bayside Blue']);

        $this->actingAs($this->admin())->post(route('admin.castings.store'), [
            'casting_name' => 'Nissan Skyline GT-R (R34)', 'make' => 'Nissan', 'model' => 'Skyline GT-R',
            'brand' => 'Inno64', 'line' => 'Limited Editions', 'series' => 'Hong Kong Toy Show', 'year' => 2025,
            'color' => 'Bayside Blue', 'toy_number' => 'IN64-R34-BL', 'reference_item_id' => $item->id,
        ])->assertRedirect();

        $casting = Casting::where('name', 'Nissan Skyline GT-R (R34)')->sole();
        $variation = $casting->versions->first()->releases->first()->variations->first();

        $this->assertSame([$casting->id => 'inno64|item:IN64-R34-BL'], StableKeys::castings([$casting->id]));
        $this->assertSame([$variation->id => 'inno64|item:IN64-R34-BL:v'], StableKeys::variations([$variation->id]));
        $this->assertSame(0, RecordSource::where('sourceable_id', $casting->id)->where('sourceable_type', $casting->getMorphClass())->count(),
            'a hand-made record gets no provenance row, so no public attribution');

        $series = $variation->release->series;
        $this->assertSame('Limited Editions', $series->parent->name);
        $this->assertTrue($series->parent->isLive(), 'the line is published with the car');

        // Adding the same product again doesn't steal the link.
        $this->actingAs($this->admin())->post(route('admin.castings.store'), [
            'casting_name' => 'Another', 'is_fantasy' => 1, 'brand' => 'Inno64', 'series' => 'X', 'year' => 2025, 'reference_item_id' => $item->id,
        ]);
        $this->assertSame(2, ReferenceLink::count());
    }

    public function test_an_existing_series_keeps_its_line(): void
    {
        $this->actingAs($this->admin())->post(route('admin.castings.store'), [
            'casting_name' => 'A', 'is_fantasy' => 1, 'brand' => 'Hot Wheels', 'series' => 'Night Burnerz', 'year' => 2011,
        ]);
        $this->actingAs($this->admin())->post(route('admin.castings.store'), [
            'casting_name' => 'B', 'is_fantasy' => 1, 'brand' => 'Hot Wheels', 'line' => 'Mainline', 'series' => 'Night Burnerz', 'year' => 2011,
        ]);

        $this->assertSame(1, Series::where('name', 'Night Burnerz')->count());
        $this->assertNull(Series::where('name', 'Night Burnerz')->sole()->parent_id);
    }

    public function test_reference_labels_export(): void
    {
        $this->item(['name' => 'Bone Shaker (Black)', 'year' => 2010, 'image_urls' => ['https://a.test/1.jpg', 'https://a.test/2.jpg']]);
        $out = sys_get_temp_dir().'/ref-'.bin2hex(random_bytes(4)).'.jsonl';

        $this->artisan('catalog:export-reference-labels', ['--out' => $out, '--images' => 2])
            ->expectsOutputToContain('2 images; 1 products tied')->assertSuccessful();

        $rows = array_map(fn ($l) => json_decode($l, true), file($out, FILE_IGNORE_NEW_LINES));
        $this->assertSame(['https://a.test/1.jpg', 'https://a.test/2.jpg'], array_column($rows, 'image_url'));
        $this->assertSame('ref_hwpriceguide', $rows[0]['source']);
        $this->assertSame('hotwheels_fandom|page:100', $rows[0]['casting_key']);
    }
}
