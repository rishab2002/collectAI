<?php

namespace Tests\Feature;

use App\Models\Casting;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The command that walks a year index and imports what it names.
 *
 * Driven through a faked API rather than the real one, so the run is
 * deterministic — but the wikitext it is fed is the captured fixtures, so what
 * the command hands the parser is what the wiki really serves.
 */
class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/hotwheels-wiki/{$name}.wikitext"));
    }

    /**
     * @param  array<string, array{0: string, 1: int, 2: int}>  $pages
     */
    private function fakeWiki(array $pages): void
    {
        Http::fake(function ($request) use ($pages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $wanted = explode('|', $query['titles'] ?? '');

            $found = [];

            foreach ($wanted as $title) {
                if (! isset($pages[$title])) {
                    $found[] = ['title' => $title, 'missing' => true];

                    continue;
                }

                [$wikitext, $pageId, $revisionId] = $pages[$title];

                $found[] = [
                    'title' => $title,
                    'pageid' => $pageId,
                    'revisions' => [['revid' => $revisionId, 'slots' => ['main' => ['content' => $wikitext]]]],
                ];
            }

            return Http::response(['query' => ['pages' => $found]]);
        });
    }

    public function test_it_imports_the_castings_a_year_index_names(): void
    {
        $this->fakeWiki([
            'List of 2011 Hot Wheels' => [$this->fixture('list-of-2011-hot-wheels'), 21769, 790637],
            'Danicar' => [$this->fixture('danicar'), 21620, 762778],
            "'72 Ford Gran Torino Sport" => [$this->fixture('72-ford-gran-torino-sport'), 21739, 790239],
        ]);

        $this->artisan('catalog:import-hot-wheels', ['--year' => 2011, '--limit' => 2])
            ->assertSuccessful();

        $this->assertSame(2, Casting::count());
        $this->assertGreaterThan(0, Variation::count());
        $this->assertSame(790239, Casting::query()->where('name', "'72 Ford Gran Torino Sport")->sole()->source->source_revision_id);
    }

    public function test_it_follows_a_redirect_rather_than_dropping_the_casting(): void
    {
        $this->fakeWiki([
            'List of 2011 Hot Wheels' => ["{|\n! Model Name\n|-\n|[['70 Camaro Road Race]]\n|}", 1, 1],
            "'70 Camaro Road Race" => ["#REDIRECT [['70 Camaro]]", 2, 2],
            "'70 Camaro" => [$this->fixture('danicar'), 21620, 762778],
        ]);

        $this->artisan('catalog:import-hot-wheels', ['--year' => 2011])->assertSuccessful();

        // Twelve of 2011's 242 index links are redirects. Left unfollowed, those
        // castings are skipped in silence.
        $this->assertSame(1, Casting::count());
        $this->assertSame('Danicar', Casting::sole()->name);
    }

    public function test_a_title_the_wiki_lacks_is_reported_rather_than_failing_the_run(): void
    {
        $this->fakeWiki([
            'List of 2011 Hot Wheels' => ["{|\n! Model Name\n|-\n|[[Danicar]]\n|-\n|[[Never Written]]\n|}", 1, 1],
            'Danicar' => [$this->fixture('danicar'), 21620, 762778],
        ]);

        // A year index can link to an article nobody has written. That is a gap
        // in the source, and one missing casting must not cost the other 241.
        $this->artisan('catalog:import-hot-wheels', ['--year' => 2011])
            ->expectsOutputToContain('Never Written')
            ->assertSuccessful();

        $this->assertSame(1, Casting::count());
    }

    public function test_a_dry_run_reports_what_it_would_import_and_keeps_none_of_it(): void
    {
        $this->fakeWiki([
            'List of 2011 Hot Wheels' => [$this->fixture('list-of-2011-hot-wheels'), 21769, 790637],
            'Danicar' => [$this->fixture('danicar'), 21620, 762778],
            "'72 Ford Gran Torino Sport" => [$this->fixture('72-ford-gran-torino-sport'), 21739, 790239],
        ]);

        $this->artisan('catalog:import-hot-wheels', ['--year' => 2011, '--limit' => 2, '--dry-run' => true])
            ->expectsOutputToContain('rolled back')
            ->assertSuccessful();

        // The point of rehearsing is that the writes really happen and are then
        // discarded, so a collision surfaces here rather than partway through
        // the real run.
        $this->assertSame(0, Casting::count());
        $this->assertSame(0, Variation::count());
    }

    public function test_a_year_with_no_index_page_fails_rather_than_reporting_success(): void
    {
        $this->fakeWiki([]);

        $this->artisan('catalog:import-hot-wheels', ['--year' => 1066])
            ->assertFailed();
    }
}
