<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Services\Import\FandomWiki\CastingPageParser;
use App\Services\Import\FandomWiki\CatalogImporter;
use App\Services\Import\FandomWiki\TrainingPhotoLabels;
use App\Services\Import\FandomWiki\Wiki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InMemoryPageSource;
use Tests\TestCase;

/**
 * Labels for training on wiki photos: every photo of an imported car, with the
 * exact version where its Versions row says so. Training only — never a
 * `photos` row, so never on the site.
 */
class TrainingPhotoLabelsTest extends TestCase
{
    use RefreshDatabase;

    private string $evora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evora = file_get_contents(base_path('tests/Fixtures/matchbox-wiki/lotus-evora-2008.wikitext'));
        (new CatalogImporter(Wiki::matchbox()))->import(
            (new CastingPageParser(Wiki::matchbox()))->parse($this->evora, 'Lotus Evora (2008)', 2602, 209346),
        );
    }

    public function test_every_versions_photo_is_labelled_with_its_variation(): void
    {
        $page = (new CastingPageParser(Wiki::matchbox()))->parse($this->evora, 'Lotus Evora (2008)', 2602, 209346);
        $photos = collect($page->versions)->flatMap(fn ($row) => $row->photos)->unique()->count();

        $labels = iterator_to_array((new TrainingPhotoLabels(Wiki::matchbox()))
            ->labels(new InMemoryPageSource(['Lotus Evora (2008)' => $this->evora])), false);

        $this->assertGreaterThan(0, $photos);
        $this->assertGreaterThanOrEqual($photos, count($labels));
        $this->assertSame(1, collect($labels)->pluck('casting_id')->unique()->count());
        $this->assertTrue(collect($labels)->every(fn ($l) => str_starts_with($l['file'], 'File:') && ! str_contains($l['file'], '_')));
        $this->assertGreaterThan(0, collect($labels)->whereNotNull('variation_id')->count());
        $this->assertSame('https://matchbox.fandom.com/api.php', $labels[0]['api']);
        $withVariation = collect($labels)->firstWhere('variation_id', '!=', null);
        $this->assertStringStartsWith('matchbox_fandom|page:2602:row:', $withVariation['variation_key']);
        $this->assertSame(0, Photo::count(), 'nothing is added to the catalog');
    }

    public function test_pages_of_cars_that_were_never_imported_are_ignored(): void
    {
        $labels = iterator_to_array((new TrainingPhotoLabels(Wiki::matchbox()))
            ->labels(new InMemoryPageSource(['Some Other Car' => $this->evora])), false);

        $this->assertSame([], $labels);
    }

    public function test_the_command_writes_jsonl(): void
    {
        $out = sys_get_temp_dir().'/labels-'.bin2hex(random_bytes(4)).'.jsonl';
        $dump = sys_get_temp_dir().'/dump-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($dump, '<mediawiki><page><title>Lotus Evora (2008)</title><ns>0</ns><id>2602</id><revision><id>209346</id><text>'
            .htmlspecialchars($this->evora, ENT_XML1).'</text></revision></page></mediawiki>');

        $this->artisan('catalog:export-wiki-photo-labels', ['wiki' => 'matchbox', '--dump' => $dump, '--out' => $out])
            ->expectsOutputToContain('Training only')
            ->assertSuccessful();

        $first = json_decode(strtok(file_get_contents($out), "\n"), true);
        $this->assertSame(['file', 'casting_id', 'variation_id', 'casting_key', 'variation_key', 'wiki', 'api', 'page_url'], array_keys($first));
        $this->assertSame('matchbox_fandom|page:2602', $first['casting_key']);
    }
}
