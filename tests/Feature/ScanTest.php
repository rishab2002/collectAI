<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Release;
use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\Series;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Scan page: photos go to the local recognizer, confident cars are offered
 * for confirmation, and confirmed cars land in the collection. The recognizer
 * is faked at the HTTP layer; its own behaviour is tested in recognizer/tests.
 */
class ScanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Casting $casting;

    /** @var array<string, Variation> */
    private array $cars = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['catalog.recognition.url' => 'http://recognizer:8080', 'catalog.recognition.disk' => 'local']);
        $this->user = User::factory()->create();

        $this->casting = Casting::factory()->create(['name' => 'Bone Shaker']);
        $version = CastingVersion::factory()->for($this->casting)->create();
        $series = Series::factory()->for(Brand::factory()->state(['name' => 'Hot Wheels']))->create(['name' => 'HW Flames']);

        foreach (['Black' => 2006, 'Red' => 2019] as $colour => $year) {
            $this->cars[$colour] = Variation::factory()
                ->for(Release::factory()->for($version, 'castingVersion')->for($series)->state(['year' => $year]))
                ->create(['color' => $colour, 'rim_type' => '5SP']);
        }
    }

    /**
     * Two cars found: one read off its base and offered with the red version
     * first (colour match), one loose car the recognizer would not name.
     */
    private function fakeRecognizer(): void
    {
        Http::fake(['recognizer:8080/recognize*' => Http::response([
            'found' => 2, 'confident' => 1,
            'detections' => [
                [
                    'n' => 1, 'box' => [10, 10, 300, 120], 'colour' => ['family' => 'red'],
                    'text' => ['lines' => ['BONE SHAKER', '(C) 2005 MATTEL']], 'read' => ['brand' => null, 'copyright_year' => 2005, 'codes' => []],
                    'confident' => true,
                    'match' => [
                        'confident' => true, 'casting_id' => $this->casting->id, 'casting_name' => 'Bone Shaker', 'confidence' => 0.98,
                        'reasons' => ['name read as “bone shaker” (1.00)'],
                        'variations' => [
                            ['variation_id' => $this->cars['Red']->id, 'colour_match' => 1.0, 'code_match' => false],
                            ['variation_id' => $this->cars['Black']->id, 'colour_match' => 0.0, 'code_match' => false],
                        ],
                        'candidates' => [],
                    ],
                    'crop_jpeg' => base64_encode('CROP-1'),
                ],
                [
                    'n' => 2, 'box' => [400, 10, 300, 120], 'colour' => ['family' => 'blue'],
                    'text' => ['lines' => []], 'read' => ['brand' => null, 'copyright_year' => null, 'codes' => []],
                    'confident' => false,
                    'match' => ['confident' => false, 'casting_id' => null, 'confidence' => 0.0, 'reasons' => [], 'variations' => [], 'candidates' => []],
                    'looks' => ['sure' => false, 'candidates' => [['casting_id' => $this->casting->id, 'score' => 0.71]]],
                    'crop_jpeg' => base64_encode('CROP-2'),
                ],
            ],
        ])]);
    }

    private function scan(bool $consent = false): Scan
    {
        $this->fakeRecognizer();

        $this->actingAs($this->user)->post(route('scan.store'), [
            'photos' => [UploadedFile::fake()->create('pile.jpg', 200, 'image/jpeg')],
            'layout' => 'pile',
            'consent' => $consent ? '1' : null,
        ])->assertRedirect();

        return Scan::sole();
    }

    public function test_the_scan_page_needs_an_account(): void
    {
        $this->get(route('scan.index'))->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('scan.index'))->assertOk()->assertSee('Help teach the scanner');
    }

    public function test_a_scan_offers_only_what_the_recognizer_is_sure_of(): void
    {
        $scan = $this->scan();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/recognize?') && str_contains($request->url(), 'layout=pile')
            && str_contains($request->url(), 'min_confidence=0.9'));
        $this->assertSame(2, $scan->detections()->count());
        [$sure, $unsure] = $scan->detections->all();
        $this->assertTrue($sure->confident);
        $this->assertSame($this->casting->id, $sure->casting_id);
        $this->assertFalse($unsure->confident);
        $this->assertNull($unsure->casting_id);
        Storage::disk('local')->assertExists($sure->crop_path);
        $this->assertStringStartsWith('scans/', $sure->crop_path);

        $this->actingAs($this->user)->get(route('scan.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Identified — confirm each one', 'Bone Shaker', '98% sure', 'Red', 'colour matches', 'Black',
                'Found, but not sure what they are', 'Looks most like', 'Name it'])
            ->assertDontSee('Maybe');
    }

    public function test_a_lookalike_hint_never_names_a_car_still_in_review(): void
    {
        $scan = $this->scan();
        $this->casting->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->user)->get(route('scan.show', $scan))
            ->assertOk()
            ->assertDontSee('Looks most like');
    }

    public function test_confirming_adds_the_chosen_version_and_counts_duplicates(): void
    {
        $scan = $this->scan();
        $sure = $scan->detections->first();

        $this->actingAs($this->user)->post(route('scan.add', [$scan, $sure]), ['variation_id' => $this->cars['Red']->id])
            ->assertRedirect(route('scan.show', $scan));

        $item = $this->user->collectionItems()->sole();
        $this->assertSame($this->cars['Red']->id, $item->variation_id);
        $this->assertSame(1, $item->quantity);
        $this->assertSame(ScanDetection::ADDED, $sure->fresh()->decision);
        $this->assertFalse($sure->fresh()->labelled_by_hand);

        // A second scan of another red Bone Shaker from the same pile: two now.
        Scan::query()->delete();
        $again = $this->scan()->detections->first();
        $this->actingAs($this->user)->post(route('scan.add', [$again->scan_id, $again]), ['variation_id' => $this->cars['Red']->id]);
        $this->assertSame(2, $item->fresh()->quantity);

        // Answering twice changes nothing.
        $this->actingAs($this->user)->post(route('scan.add', [$again->scan_id, $again]), ['variation_id' => $this->cars['Red']->id]);
        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_naming_an_unidentified_car_by_hand_is_recorded_as_such(): void
    {
        $scan = $this->scan(consent: true);
        $unsure = $scan->detections->last();

        $this->actingAs($this->user)->get(route('scan.show', ['scan' => $scan, 'label' => $unsure->id, 'q' => 'bone']))
            ->assertOk()
            ->assertSee('This one');

        $this->actingAs($this->user)->post(route('scan.add', [$scan, $unsure]), ['variation_id' => $this->cars['Black']->id]);

        $this->assertTrue($unsure->fresh()->labelled_by_hand);
        $this->assertSame($this->cars['Black']->id, $unsure->fresh()->variation_id);
    }

    public function test_only_live_cars_can_be_added(): void
    {
        $scan = $this->scan();
        $this->cars['Red']->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->user)->post(route('scan.add', [$scan, $scan->detections->first()]), ['variation_id' => $this->cars['Red']->id])
            ->assertNotFound();
        $this->assertSame(0, $this->user->collectionItems()->count());
    }

    public function test_another_collectors_scan_is_not_found(): void
    {
        $scan = $this->scan();
        $detection = $scan->detections->first();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('scan.show', $scan))->assertNotFound();
        $this->actingAs($stranger)->get(route('scan.crop', [$scan, $detection]))->assertNotFound();
        $this->actingAs($stranger)->post(route('scan.add', [$scan, $detection]), ['variation_id' => $this->cars['Red']->id])->assertNotFound();
        $this->actingAs($stranger)->delete(route('scan.destroy', $scan))->assertNotFound();

        $this->actingAs($this->user)->get(route('scan.crop', [$scan, $detection]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private');
    }

    public function test_without_consent_the_pictures_go_once_the_scan_is_answered(): void
    {
        $scan = $this->scan(consent: false);
        [$sure, $unsure] = $scan->detections->all();

        $this->actingAs($this->user)->post(route('scan.add', [$scan, $sure]), ['variation_id' => $this->cars['Red']->id]);
        Storage::disk('local')->assertExists($sure->crop_path); // one still waiting

        $this->actingAs($this->user)->post(route('scan.reject', [$scan, $unsure]));

        Storage::disk('local')->assertMissing($sure->crop_path);
        Storage::disk('local')->assertMissing($unsure->crop_path);
        $this->assertNull($sure->fresh()->crop_path);
        $this->assertSame(ScanDetection::ADDED, $sure->fresh()->decision, 'The answer is kept; only the picture goes.');
    }

    public function test_with_consent_they_stay_and_become_training_data(): void
    {
        $scan = $this->scan(consent: true);
        [$sure, $unsure] = $scan->detections->all();
        $this->actingAs($this->user)->post(route('scan.add', [$scan, $sure]), ['variation_id' => $this->cars['Red']->id]);
        $this->actingAs($this->user)->post(route('scan.add', [$scan, $unsure]), ['variation_id' => $this->cars['Black']->id]);
        Storage::disk('local')->assertExists($sure->crop_path);

        $dir = sys_get_temp_dir().'/collectai-train-'.bin2hex(random_bytes(4));
        $this->artisan('catalog:export-training-data', ['dir' => $dir])->expectsOutputToContain('Wrote 2')->assertSuccessful();

        $lines = array_map('json_decode', file($dir.'/manifest.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame(['scan', 'scan'], array_column($lines, 'source'));
        $this->assertSame(['confirmed_suggestion', 'named_by_collector'], array_column($lines, 'labelled'));
        $this->assertSame($this->cars['Red']->id, $lines[0]->variation_id);
        $this->assertSame('CROP-1', file_get_contents($dir.'/'.$lines[0]->image));
        $this->assertSame('5SP', $lines[0]->wheel_type);
    }

    public function test_unconsented_pictures_are_pruned_after_the_window_even_if_unfinished(): void
    {
        $scan = $this->scan(consent: false);
        $path = $scan->detections->first()->crop_path;

        $this->artisan('catalog:prune-scans')->expectsOutputToContain('Deleted 0')->assertSuccessful();
        Storage::disk('local')->assertExists($path);

        $this->travel(25)->hours();
        $this->artisan('catalog:prune-scans')->expectsOutputToContain('Deleted 2')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($this->user)->get(route('scan.show', $scan))->assertOk()->assertSee('Picture deleted');
    }

    public function test_deleting_a_scan_deletes_its_pictures(): void
    {
        $scan = $this->scan(consent: true);
        $path = $scan->detections->first()->crop_path;

        $this->actingAs($this->user)->delete(route('scan.destroy', $scan))->assertRedirect(route('scan.index'));

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, ScanDetection::count());
    }

    public function test_a_recognizer_that_is_not_running_is_explained_and_leaves_nothing_behind(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->actingAs($this->user)->from(route('scan.index'))->post(route('scan.store'), [
            'photos' => [UploadedFile::fake()->create('pile.jpg', 200, 'image/jpeg')],
            'layout' => 'auto',
        ])->assertRedirect(route('scan.index'))->assertSessionHasErrors(['photos' => 'The recognizer isn’t running. Start it with `docker compose up -d recognizer` (see README, “Scan”).']);

        $this->assertSame(0, Scan::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_recognizer_address_with_no_host_is_explained_rather_than_a_500(): void
    {
        // What an unresolved Railway variable reference leaves behind:
        // RECOGNIZER_URL=http://${{recognizer.RAILWAY_PRIVATE_DOMAIN}}:8080
        // with no service of that name becomes `http://:8080`. Guzzle throws
        // MalformedUriException from inside the client, which is not one of
        // the failures this class converts, so it reached the browser as a
        // 500 on the live site until this guard existed.
        config(['catalog.recognition.url' => 'http://:8080']);
        Http::fake();

        $this->actingAs($this->user)->from(route('scan.index'))->post(route('scan.store'), [
            'photos' => [UploadedFile::fake()->create('pile.jpg', 200, 'image/jpeg')],
            'layout' => 'auto',
        ])->assertRedirect(route('scan.index'))->assertSessionHasErrorsIn('default', ['photos']);

        $this->assertStringContainsString(
            'RECOGNIZER_URL',
            (string) session('errors')->first('photos'),
        );

        // Nothing was attempted, and nothing was left behind.
        Http::assertNothingSent();
        $this->assertSame(0, Scan::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photos_must_be_images_the_recognizer_can_read(): void
    {
        Http::fake();

        $this->actingAs($this->user)->post(route('scan.store'), [
            'photos' => [UploadedFile::fake()->create('car.heic', 200, 'image/heic')],
            'layout' => 'pile',
        ])->assertSessionHasErrors('photos.0');

        Http::assertNothingSent();
    }
}
