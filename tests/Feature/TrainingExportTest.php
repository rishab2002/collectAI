<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the visual model may learn from: consented scans (ScanTest), the
 * admin's own catalog photos, and wiki photos carrying a reuse licence — with
 * the licence and credit travelling alongside.
 */
class TrainingExportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/collectai-train-'.bin2hex(random_bytes(4));
    }

    private function photo(?string $licence, string $bytes): Photo
    {
        $variation = Variation::factory()->create(['color' => 'Red', 'rim_type' => 'PR5']);
        $path = 'photos/'.bin2hex(random_bytes(4)).'.webp';
        Storage::disk('local')->put($path, $bytes);

        return Photo::factory()->for($variation)->create([
            'disk' => 'local', 'path' => $path, 'license' => $licence, 'credit' => $licence ? 'Photo: Someone' : null,
        ]);
    }

    /**
     * @return list<object>
     */
    private function manifest(): array
    {
        return array_map('json_decode', file($this->dir.'/manifest.jsonl', FILE_IGNORE_NEW_LINES));
    }

    public function test_own_and_licensed_catalog_photos_are_exported_with_their_licence(): void
    {
        $own = $this->photo(null, 'OWN');
        $wiki = $this->photo('CC BY-SA 3.0', 'WIKI');

        $this->artisan('catalog:export-training-data', ['dir' => $this->dir])
            ->expectsOutputToContain('Wrote 2')
            ->assertSuccessful();

        $lines = collect($this->manifest())->keyBy('source');
        $this->assertSame($own->variation_id, $lines['own']->variation_id);
        $this->assertNull($lines['own']->licence);
        $this->assertSame('CC BY-SA 3.0', $lines['wiki_licensed']->licence);
        $this->assertSame('Photo: Someone', $lines['wiki_licensed']->credit);
        $this->assertSame($wiki->variation->release->castingVersion->casting_id, $lines['wiki_licensed']->casting_id);
        $this->assertSame('PR5', $lines['wiki_licensed']->wheel_type);
        $this->assertSame('WIKI', file_get_contents($this->dir.'/'.$lines['wiki_licensed']->image));
        $this->assertStringEndsWith('.webp', $lines['own']->image);
    }

    public function test_sources_can_be_chosen_and_unknown_ones_are_refused(): void
    {
        $this->photo(null, 'OWN');
        $this->photo('CC BY 4.0', 'WIKI');

        $this->artisan('catalog:export-training-data', ['dir' => $this->dir, '--source' => ['wiki_licensed']])->assertSuccessful();
        $this->assertSame(['wiki_licensed'], array_column($this->manifest(), 'source'));

        $this->artisan('catalog:export-training-data', ['dir' => $this->dir, '--source' => ['wiki_unlicensed']])
            ->expectsOutputToContain('Unknown source: wiki_unlicensed')
            ->assertFailed();
    }
}
