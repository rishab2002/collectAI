<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Photo;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The public catalog: brand → main line → sub-series → car, live records only.
 */
class CatalogBrowseTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Series $mainline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::factory()->create(['name' => 'Hot Wheels']);
        $this->mainline = Series::factory()->for($this->brand)->create(['name' => 'Mainline']);
    }

    /**
     * The page's visible text, whitespace collapsed, contains these in order —
     * what a reader sees, rather than how the template happens to break lines.
     */
    private function assertReads(TestResponse $response, string ...$phrases): TestResponse
    {
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $response->getContent()), ENT_QUOTES | ENT_HTML5));
        $offset = 0;

        foreach ($phrases as $phrase) {
            $found = mb_strpos($text, $phrase, $offset);
            $this->assertNotFalse($found, "Expected the page to read “{$phrase}” (in order). It reads: ".mb_substr($text, 0, 600));
            $offset = $found + mb_strlen($phrase);
        }

        return $response;
    }

    private function sub(string $name, ?Series $line = null): Series
    {
        return Series::factory()->for($this->brand)->create(['name' => $name, 'parent_id' => ($line ?? $this->mainline)->id]);
    }

    /**
     * A casting with one release per year in the series, one variation each.
     *
     * @param  list<int>  $years
     */
    private function car(string $name, Series $series, array $years, bool $live = true): Casting
    {
        $casting = Casting::factory()->state($live ? [] : ['status' => 'pending'])->create(['name' => $name]);
        $version = CastingVersion::factory()->for($casting)->state($live ? [] : ['status' => 'pending'])->create();

        foreach ($years as $year) {
            $release = Release::factory()->for($version, 'castingVersion')->for($series)
                ->state($live ? [] : ['status' => 'pending'])->create(['year' => $year]);
            Variation::factory()->for($release)->state($live ? [] : ['status' => 'pending'])->create(['color' => "Colour {$year}"]);
        }

        return $casting;
    }

    public function test_the_home_page_lists_brands_with_something_published(): void
    {
        $this->car('Bone Shaker', $this->sub('HW Art Cars'), [2015, 2016]);
        Brand::factory()->create(['name' => 'Empty Brand']);

        $this->assertReads($this->get(route('home'))->assertOk(), 'Hot Wheels', '1 casting · 2015–2016')
            ->assertDontSee('Empty Brand');
    }

    public function test_a_brand_page_lists_its_lines_with_counts(): void
    {
        $this->car('Bone Shaker', $this->sub('HW Art Cars'), [2015]);
        $this->car('Twin Mill', $this->sub('HW Exotics'), [2016]);
        $culture = Series::factory()->for($this->brand)->create(['name' => 'Car Culture']);
        $this->car('Porsche 911', $this->sub('AutoStrasse', $culture), [2020]);

        $this->assertReads(
            $this->get(route('catalog.brand', $this->brand))->assertOk(),
            'Mainline', '2015–2016 · 2 castings · 2 series', 'Car Culture', '2020 · 1 casting · 1 series',
        );
    }

    public function test_a_line_lists_sub_series_grouped_by_name_and_filters_by_year(): void
    {
        // Two rows with one name — the wiki gives each year its own anchor.
        $this->car('Bone Shaker', $this->sub('HW Art Cars'), [2015]);
        $this->car('Twin Mill', $this->sub('HW Art Cars'), [2016]);
        $this->car('Deora', $this->sub('HW Exotics'), [2016]);
        // Cars filed under the line itself.
        $this->car('Old Car', $this->mainline, [1999]);

        $this->assertReads(
            $this->get(route('catalog.line', $this->mainline))->assertOk(),
            'All years', 'HW Art Cars', '2015–2016 · 2 castings', 'HW Exotics', 'Mainline (no sub-series)',
        );

        $this->get(route('catalog.line', [$this->mainline, 'year' => 2015]))
            ->assertOk()
            ->assertSee('HW Art Cars')
            ->assertDontSee('HW Exotics');
    }

    public function test_a_sub_series_lists_its_cars_with_their_photo(): void
    {
        $artCars = $this->sub('HW Art Cars');
        $casting = $this->car('Bone Shaker', $artCars, [2015, 2016]);
        Photo::factory()->primary()->for($casting->versions->first()->releases->first()->variations->first())->create();

        $this->assertReads(
            $this->get(route('catalog.line', [$this->mainline, 'sub' => 'HW Art Cars']))->assertOk(),
            'Bone Shaker', '2015–2016 · 2 versions',
        )->assertSee('<img', false);
    }

    public function test_a_car_page_shows_its_releases_by_series_path_and_credits_the_wiki(): void
    {
        $casting = $this->car('Bone Shaker', $this->sub('HW Art Cars'), [2015]);
        RecordSource::factory()->forSourceable($casting)->create(['source_url' => 'https://hotwheels.fandom.com/wiki/Bone_Shaker']);
        $photo = Photo::factory()->primary()->for(Variation::sole())->create(['license' => 'GFDL', 'credit' => 'HaarFager on the Hot Wheels Wiki']);
        RecordSource::factory()->forSourceable($photo)->create(['source_key' => 'photo:page:1', 'source_url' => 'https://hotwheels.fandom.com/wiki/File:Bone.jpg']);

        $this->assertReads(
            $this->get(route('catalog.casting', $casting->id))->assertOk(),
            'Photo: HaarFager on the Hot Wheels Wiki · GFDL', '2015 · Mainline / HW Art Cars', 'Colour 2015', 'CC BY-SA',
        )->assertSee('https://hotwheels.fandom.com/wiki/File:Bone.jpg', false);
    }

    public function test_the_public_never_sees_pending_records(): void
    {
        $pending = $this->car('Circle Tracker', $this->sub('HW Art Cars'), [2015], live: false);

        $this->get(route('home'))->assertOk()->assertSee('Nothing is published yet');
        $this->get(route('catalog.brand', $this->brand))->assertNotFound();
        $this->get(route('catalog.line', $this->mainline))->assertNotFound();
        $this->get(route('catalog.casting', $pending->id))->assertNotFound();
    }

    public function test_an_admin_can_preview_the_pending_backlog_and_nobody_else_can(): void
    {
        $pending = $this->car('Circle Tracker', $this->sub('HW Art Cars'), [2015], live: false);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('catalog.casting', $pending->id))->assertNotFound();

        $this->actingAs($admin)->post(route('catalog.preview'))->assertRedirect();

        $this->actingAs($admin)->get(route('catalog.casting', $pending->id))
            ->assertOk()
            ->assertSee('Admin preview');

        $this->actingAs($admin)->get(route('catalog.brand', $this->brand))->assertOk()->assertSee('Mainline');

        // The toggle is admin-only, and the flag means nothing to anyone else.
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('catalog.preview'))->assertNotFound();
        $this->actingAs($user)->withSession(['catalog_preview' => true])
            ->get(route('catalog.casting', $pending->id))
            ->assertNotFound();
    }

    public function test_a_sub_series_is_not_a_line(): void
    {
        $artCars = $this->sub('HW Art Cars');
        $this->car('Bone Shaker', $artCars, [2015]);

        $this->get(route('catalog.line', $artCars))->assertNotFound();
    }
}
