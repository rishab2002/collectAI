<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\RecordSource;
use App\Models\Variation;
use App\Services\Import\FandomWiki\CastingPageParser;
use App\Services\Import\FandomWiki\CatalogImporter;
use App\Services\Import\FandomWiki\FileLicence;
use App\Services\Import\FandomWiki\Wiki;
use App\Services\Import\FandomWiki\WikiPhotoImporter;
use App\Services\Import\ImportSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InMemoryPageSource;
use Tests\TestCase;

/**
 * One credited photo per casting, and only where the file is licensed for reuse.
 */
class WikiPhotoImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $evora;

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalog.capture.disk' => 'r2']);
        Storage::fake('r2');

        // The real article, imported the real way.
        $this->evora = file_get_contents(base_path('tests/Fixtures/matchbox-wiki/lotus-evora-2008.wikitext'));
        (new CatalogImporter(Wiki::matchbox()))->import(
            (new CastingPageParser(Wiki::matchbox()))->parse($this->evora, 'Lotus Evora (2008)', 2602, 209346),
        );
    }

    /**
     * The wiki API and its image server. `$licence` is the file page's text.
     */
    private function fakeWiki(string $licence, string $contentType = 'image/jpeg'): void
    {
        Http::fake([
            'matchbox.fandom.com/api.php*' => Http::response(['query' => ['pages' => [[
                'title' => 'File:0916-LotusEvora.jpg',
                'revisions' => [['slots' => ['main' => ['content' => $licence]]]],
                'imageinfo' => [['user' => 'Uploader1', 'url' => 'https://static.wikia.nocookie.net/matchbox/images/d/df/0916-LotusEvora.jpg', 'mime' => 'image/jpeg', 'size' => 10]],
            ]]]]),
            'static.wikia.nocookie.net/*' => Http::response('jpeg bytes', 200, ['Content-Type' => $contentType]),
        ]);
    }

    private function importPhotos(): ImportSummary
    {
        return (new WikiPhotoImporter(Wiki::matchbox()))->import(new InMemoryPageSource(['Lotus Evora (2008)' => $this->evora]));
    }

    public function test_only_licences_that_permit_reuse_are_accepted(): void
    {
        $this->assertSame('CC BY-SA 3.0', FileLicence::of("== Licensing ==\n{{cc-by-sa-3.0}}"));
        $this->assertSame('GFDL', FileLicence::of('{{GFDL}}'));
        $this->assertSame('Public domain', FileLicence::of('{{PD}}'));
        $this->assertSame('Free licence (Wikimedia Commons)', FileLicence::of('{{From Wikimedia}}'));
        $this->assertSame('Free licence (as stated on the file page)', FileLicence::of("== Licensing ==\n{{Other free}}"));
        // A free licence beside an authorship note still counts.
        $this->assertSame('CC BY-SA', FileLicence::of('{{Self}} {{cc-by-sa}}'));

        // Authorship, the wiki's own fair-use claim, permission given to the
        // wiki, and silence: none of these is a licence to us.
        $this->assertNull(FileLicence::of('{{Self}}'));
        $this->assertNull(FileLicence::of('{{Fairuse}}'));
        $this->assertNull(FileLicence::of('{{Permission}}'));
        $this->assertNull(FileLicence::of("== Licensing ==\n{{None selected}}"));
        $this->assertNull(FileLicence::of('Photo by Jim. Used with permission.'));
        $this->assertNull(FileLicence::of(''));
    }

    public function test_a_licensed_infobox_image_lands_on_the_car_it_shows_with_its_credit(): void
    {
        $this->fakeWiki("== Licensing ==\n{{GFDL}}");

        $summary = $this->importPhotos();

        $this->assertSame(1, $summary->count('photos.created'));
        $photo = Photo::sole();

        // Row 2 of the Versions table is the one whose Photo cell shows the
        // infobox image; the photo belongs to that car, not just the casting.
        $row2 = RecordSource::where('source_key', RecordSource::rowKey('page:2602', 2))->sole()->sourceable;
        $this->assertSame($row2->id, $photo->variation_id);
        $this->assertSame('Bright Blue', $row2->color);

        $this->assertTrue($photo->is_primary);
        $this->assertSame('wiki', $photo->type);
        $this->assertSame('GFDL', $photo->license);
        $this->assertSame('Uploader1 on the Matchbox Wiki', $photo->credit);
        $this->assertSame('photo:page:2602', $photo->source->source_key);
        $this->assertSame('https://matchbox.fandom.com/wiki/File%3A0916-LotusEvora.jpg', $photo->source->source_url);
        Storage::disk('r2')->assertExists($photo->path);
        $this->assertStringEndsWith('.jpg', $photo->path);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $this->fakeWiki('{{GFDL}}');
        $this->importPhotos();

        $summary = $this->importPhotos();

        $this->assertSame(0, $summary->count('photos.created'));
        $this->assertSame(0, $summary->count('castings.without_photos'));
        $this->assertSame(1, Photo::count());
    }

    public function test_an_unlicensed_image_is_left_alone(): void
    {
        $this->fakeWiki("'''''This file was uploaded by the photographer or author.''''' {{Self}}");

        $summary = $this->importPhotos();

        $this->assertSame(1, $summary->count('images.not_licensed_for_reuse'));
        $this->assertSame(0, Photo::count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'static.wikia.nocookie.net'));
    }

    public function test_a_casting_that_already_has_a_photo_keeps_it(): void
    {
        $this->fakeWiki('{{GFDL}}');
        Photo::factory()->primary()->for(Variation::first())->create();

        $summary = $this->importPhotos();

        $this->assertSame(0, $summary->count('castings.without_photos'));
        $this->assertSame(1, Photo::count());
        // The existing photo is ours, untouched — not swapped for the wiki's.
        $this->assertNull(Photo::sole()->license);
    }

    public function test_something_that_is_not_an_image_is_refused_without_leaving_files(): void
    {
        $this->fakeWiki('{{GFDL}}', contentType: 'text/html');

        $summary = $this->importPhotos();

        $this->assertSame(0, Photo::count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
        $this->assertStringContainsString('not an image', $summary->problems()[0]);
    }

    public function test_the_command_reports_the_licensed_share(): void
    {
        $this->fakeWiki('{{Self}}');
        $dump = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($dump, '<mediawiki><page><title>Lotus Evora (2008)</title><ns>0</ns><id>2602</id><revision><id>1</id><text>'.htmlspecialchars($this->evora, ENT_XML1).'</text></revision></page></mediawiki>');

        $this->artisan('catalog:import-wiki-photos', ['wiki' => 'matchbox', '--dump' => $dump, '--dry-run' => true])
            ->expectsOutputToContain('0 of the images are licensed for reuse; 1 are not')
            ->assertSuccessful();

        unlink($dump);
    }
}
