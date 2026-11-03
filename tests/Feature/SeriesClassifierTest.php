<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Services\Catalog\SeriesClassifier;
use App\Services\Import\FandomWiki\Wiki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InMemoryPageSource;
use Tests\TestCase;

/**
 * Placing imported series under main lines (config/catalog_lines.php).
 */
class SeriesClassifierTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::factory()->create(['name' => 'Hot Wheels']);
    }

    /**
     * An imported series, keyed the way the importer keys it.
     */
    private function imported(string $name, ?string $page = null, int $releases = 1, int $numbered = 0): Series
    {
        $series = Series::factory()->for($this->brand)->create(['name' => $name]);

        RecordSource::factory()->forSourceable($series)->create([
            'source_key' => $page === null ? 'series:name:'.str_replace(' ', '_', $name) : 'series:'.str_replace(' ', '_', $page),
        ]);

        for ($i = 0; $i < $releases; $i++) {
            Release::factory()->for($series)->create(['collector_number' => $i < $numbered ? (string) ($i + 1) : null]);
        }

        return $series;
    }

    private function classifier(): SeriesClassifier
    {
        return new SeriesClassifier(Wiki::hotWheels());
    }

    public function test_a_name_rule_beats_a_broader_category(): void
    {
        // A Treasure Hunt is also tagged "Mainline Segment" on the wiki; the
        // specific line comes first in the config, so it wins.
        $this->assertSame('Treasure Hunts', $this->classifier()->lineFor('2009 Treasure Hunts', ['Mainline Segment']));
    }

    public function test_a_category_places_a_series_whose_name_says_nothing(): void
    {
        $this->assertSame('Mainline', $this->classifier()->lineFor('HW Art Cars', ['Hot Wheels by Series', 'Mainline Segment']));
        $this->assertSame('Car Culture', $this->classifier()->lineFor('AutoStrasse', ['Car Culture by Year']));
    }

    public function test_a_mostly_numbered_series_falls_back_to_mainline_and_the_rest_to_other(): void
    {
        $this->assertSame('Mainline', $this->classifier()->lineFor('Speed Gangs', [], numbered: 9, releases: 10));
        $this->assertSame('Other Series', $this->classifier()->lineFor('Connect Cars', [], numbered: 0, releases: 65));
        // Too few releases to read a pattern from.
        $this->assertSame('Other Series', $this->classifier()->lineFor('Tiny', [], numbered: 2, releases: 2));
    }

    public function test_series_are_placed_under_lines_read_from_their_articles(): void
    {
        $artCars = $this->imported('HW Art Cars', 'HW Art Cars');
        $treasure = $this->imported('Treasure Hunts', 'Treasure Hunts');
        $handMade = Series::factory()->for($this->brand)->create(['name' => 'My own series']);

        $report = $this->classifier()->classify(new InMemoryPageSource([
            'HW Art Cars' => "Text.\n[[Category:Hot Wheels by Series]]\n[[Category:Mainline Segment]]",
            // A redirect is followed for its categories.
            'Treasure Hunts' => '#REDIRECT [[Treasure Hunt]]',
            'Treasure Hunt' => '[[Category:Treasure Hunts]]',
        ]));

        $mainline = Series::where('name', 'Mainline')->whereNull('parent_id')->sole();
        $this->assertTrue($mainline->isLive());
        $this->assertSame($mainline->id, $artCars->fresh()->parent_id);
        $this->assertSame('Mainline / HW Art Cars', $artCars->fresh()->path());

        // Named for its line, it *is* the line.
        $this->assertNull($treasure->fresh()->parent_id);
        $this->assertSame(1, $report['already_lines']);

        // Nothing the importer didn't create is touched.
        $this->assertNull($handMade->fresh()->parent_id);
    }

    public function test_many_rows_sharing_a_line_name_collapse_under_one_line(): void
    {
        // The wiki links each year's mainline to its own anchor, so one name
        // arrives as many rows. Only one may be the line.
        $rows = collect(['List of 2001 Hot Wheels#Mainline', 'List of 2002 Hot Wheels#Mainline', 'List of 2003 Hot Wheels#Mainline'])
            ->map(fn (string $page): Series => $this->imported('Mainline', $page));

        $this->classifier()->classify(new InMemoryPageSource([]));

        $this->assertSame(1, Series::whereNull('parent_id')->where('name', 'Mainline')->count());
        $line = $rows->first()->fresh();
        $this->assertNull($line->parent_id);
        $this->assertSame([$line->id, $line->id], $rows->slice(1)->map(fn (Series $s): ?int => $s->fresh()->parent_id)->values()->all());
        // A child sharing its line's name reads as the line.
        $this->assertSame('Mainline', $rows->last()->fresh()->path());
    }

    public function test_a_placed_series_is_kept_unless_reclassifying_and_a_dry_run_changes_nothing(): void
    {
        $series = $this->imported('HW Art Cars', 'HW Art Cars');
        $elsewhere = Series::factory()->for($this->brand)->create(['name' => 'Hand-picked line']);
        $series->forceFill(['parent_id' => $elsewhere->id])->save();
        $pages = new InMemoryPageSource(['HW Art Cars' => '[[Category:Mainline Segment]]']);

        $this->classifier()->classify($pages, dryRun: true);
        $this->assertSame($elsewhere->id, $series->fresh()->parent_id);

        $report = $this->classifier()->classify($pages);
        $this->assertSame(1, $report['kept']);
        $this->assertSame($elsewhere->id, $series->fresh()->parent_id);

        $this->classifier()->classify($pages, reclassify: true);
        $this->assertSame('Mainline', $series->fresh()->parent->name);
    }

    public function test_matchbox_ranges_are_one_mainline(): void
    {
        $classifier = new SeriesClassifier(Wiki::matchbox());

        foreach (['1-75: Wheeled Envy', '1-100: Sports Cars', '1-120: MBX Metro', '1-75/100: Mountain'] as $name) {
            $this->assertSame('Mainline', $classifier->lineFor($name, []), $name);
        }

        $this->assertSame('Multi-Packs', $classifier->lineFor('5-Pack: VIP Parking', []));
    }

    public function test_the_command_reports_where_releases_land(): void
    {
        $this->imported('HW Art Cars', 'HW Art Cars', releases: 3, numbered: 3);
        $dump = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($dump, '<mediawiki><page><title>HW Art Cars</title><ns>0</ns><id>1</id><revision><id>1</id><text>[[Category:Mainline Segment]]</text></revision></page></mediawiki>');

        $this->artisan('catalog:classify-series', ['wiki' => 'hot-wheels', '--dump' => $dump])
            ->expectsOutputToContain('Placed 1 series')
            ->assertSuccessful();

        $this->artisan('catalog:classify-series', ['wiki' => 'lego'])->assertFailed();

        unlink($dump);
    }
}
