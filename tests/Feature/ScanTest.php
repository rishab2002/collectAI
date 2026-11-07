<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\Release;
use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\Series;
use App\Models\SubmissionProposal;
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
        Http::fake(['recognizer:8080/recognize*' => Http::response($this->twoCars())]);
    }

    /**
     * Two cars found: one read off its base and offered with the red version
     * first (colour match), one loose car the recognizer would not name.
     *
     * @return array<string, mixed>
     */
    private function twoCars(): array
    {
        return [
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
        ];
    }

    /**
     * A photo of two packages: one blister card the scanner matched from the
     * printing on it, one it could not — but whose card it still read.
     */
    private function fakePackagedRecognizer(): void
    {
        $card = fn (array $over = []): array => $over + [
            'schema_version' => 1, 'brand' => 'Hot Wheels', 'year' => null, 'series' => null,
            'casting_name' => null, 'collector_number' => null, 'toy_number' => null,
            'confidence' => ['brand' => 0.9, 'year' => 0.0, 'series' => 0.0, 'casting_name' => 0.0,
                'collector_number' => 0.7, 'toy_number' => 0.95],
        ];

        Http::fake(['recognizer:8080/recognize*' => Http::response([
            'found' => 2, 'confident' => 1, 'model' => null,
            'detections' => [
                [
                    'n' => 1, 'box' => [40, 30, 420, 620],
                    // A package is never given a colour: see ScanProcessor.
                    'colour' => ['family' => null, 'share' => 0.0, 'distribution' => []],
                    'text' => ['lines' => ['BONE SHAKER', 'HW FLAMES', '77/250'], 'headline' => 'BONE SHAKER', 'rotation' => 0],
                    'read' => ['brand' => 'Hot Wheels', 'copyright_year' => 2019, 'codes' => ['DTX12']],
                    'card' => $card(['casting_name' => 'Bone Shaker', 'collector_number' => '77/250', 'toy_number' => 'DTX12']),
                    'looks' => null, 'confident' => true,
                    'match' => [
                        'confident' => true, 'casting_id' => $this->casting->id, 'casting_name' => 'Bone Shaker', 'confidence' => 0.97,
                        'reasons' => ['name read as “bone shaker” (1.00)', 'brand Hot Wheels', '© 2019 is within 2006–2019'],
                        'variations' => [
                            ['variation_id' => $this->cars['Red']->id, 'year' => 2019, 'year_match' => 1.0, 'colour_match' => 0.0, 'code_match' => false],
                            ['variation_id' => $this->cars['Black']->id, 'year' => 2006, 'year_match' => 0.0, 'colour_match' => 0.0, 'code_match' => false],
                        ],
                        'candidates' => [],
                    ],
                    'crop_jpeg' => base64_encode('CARD-1'),
                ],
                [
                    'n' => 2, 'box' => [500, 30, 420, 620],
                    'colour' => ['family' => null, 'share' => 0.0, 'distribution' => []],
                    'text' => ['lines' => ['SUBARU FORESTER STI', 'HW WAGONS'], 'headline' => 'SUBARU FORESTER STI', 'rotation' => 0],
                    'read' => ['brand' => 'Hot Wheels', 'copyright_year' => null, 'codes' => []],
                    'card' => $card(['collector_number' => '12/250']),
                    'looks' => null, 'confident' => false,
                    'match' => ['confident' => false, 'casting_id' => null, 'confidence' => 0.0, 'reasons' => [], 'variations' => [], 'candidates' => []],
                    'crop_jpeg' => base64_encode('CARD-2'),
                ],
            ],
        ])]);
    }

    public function test_a_photo_of_packages_names_each_one_from_its_card(): void
    {
        $this->fakePackagedRecognizer();

        $this->actingAs($this->user)->post(route('scan.store'), [
            'photos' => [UploadedFile::fake()->create('shelf.jpg', 400, 'image/jpeg')],
            'layout' => 'packaged',
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'layout=packaged'));

        $scan = Scan::sole();
        [$matched, $unmatched] = $scan->detections->all();
        $this->assertSame($this->casting->id, $matched->casting_id);
        $this->assertSame('Bone Shaker', $matched->reading['card']['casting_name']);
        $this->assertSame('DTX12', $matched->reading['card']['toy_number']);
        $this->assertSame('SUBARU FORESTER STI', $unmatched->reading['headline']);

        // The colour of a package is its artwork's, so none is claimed.
        $this->assertNull($matched->colour_family);

        $this->actingAs($this->user)->get(route('scan.show', $scan))
            ->assertOk()
            ->assertSeeInOrder([
                'the year printed on the card',
                'package 1',
                'The card says', 'Hot Wheels · BONE SHAKER · 77/250 · DTX12',
                'Found, but not sure what they are',
                // A package the catalog hasn't got is still shown as the car
                // it says it is, rather than as a crop with no name.
                'Hot Wheels · SUBARU FORESTER STI · 12/250',
            ], escape: false)
            ->assertDontSee('Turn these over and scan the bases');
    }

    private function scan(bool $consent = false, bool $fake = true): Scan
    {
        if ($fake) {
            $this->fakeRecognizer();
        }

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

    /**
     * The car on the card is not in the catalog — which is the usual case —
     * so the admin adds it from the scan instead of being stuck with a search
     * that can only offer what is already published.
     */
    public function test_an_admin_can_add_a_car_the_catalog_has_not_got_from_the_scan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->user = $admin;
        $scan = $this->scan(consent: true);
        $unsure = $scan->detections->last();

        $this->actingAs($admin)->post(route('scan.catalog', [$scan, $unsure]), [
            'casting_name' => 'Porsche 911 Carrera T',
            'brand' => 'Hot Wheels',
            // Read as a collector reads a card: the line, then the wave under
            // it. StoreScanCarRequest maps them onto series/line.
            'series' => 'Mainline',
            'sub_series' => 'Factory Fresh',
            'year' => 2026,
            'color' => 'Grey',
            'make' => 'Porsche',
            'model' => '911',
            'collector_number' => '15/250',
        ])->assertRedirect(route('scan.show', $scan));

        $casting = Casting::where('name', 'Porsche 911 Carrera T')->sole();
        $this->assertSame(Casting::STATUS_LIVE, $casting->status);
        $this->assertSame('Porsche', $casting->model->make->name);
        $this->assertSame('911', $casting->model->name);

        $variation = $casting->versions->first()->releases->first()->variations->first();
        $this->assertSame(2026, $variation->release->year);
        $this->assertSame('15/250', $variation->release->collector_number);
        $this->assertSame('Factory Fresh', $variation->release->series->name);
        $this->assertSame('Mainline', $variation->release->series->parent->name);

        // In the collection, and the crop is now a labelled example.
        $this->assertSame($variation->id, $admin->collectionItems()->sole()->variation_id);
        $unsure->refresh();
        $this->assertSame(ScanDetection::ADDED, $unsure->decision);
        $this->assertSame($variation->id, $unsure->variation_id);
        $this->assertTrue($unsure->labelled_by_hand);
        $this->assertNotNull($unsure->crop_path, 'a consented crop is kept — it is the training label');
    }

    /**
     * A sub-series the catalog has never heard of is created under its line,
     * and a line that already exists is reused rather than duplicated.
     */
    public function test_a_new_sub_series_is_created_under_its_line_and_an_existing_one_is_reused(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->user = $admin;
        $scan = $this->scan();
        [$first, $second] = $scan->detections->all();

        $car = fn (array $over): array => $over + [
            'brand' => 'Hot Wheels', 'series' => 'Mainline', 'year' => 2026,
            'make' => 'Porsche', 'model' => '911',
        ];

        $this->actingAs($admin)->post(route('scan.catalog', [$scan, $first]),
            $car(['casting_name' => 'Porsche 911 Carrera T', 'sub_series' => 'Factory Fresh']))->assertRedirect();
        $this->actingAs($admin)->post(route('scan.catalog', [$scan, $second]),
            $car(['casting_name' => 'Porsche 911 GT3 RS', 'sub_series' => 'Factory Fresh']))->assertRedirect();

        // One "Factory Fresh", one "Mainline" above it, both cars under them.
        $sub = Series::where('name', 'Factory Fresh')->sole();
        $this->assertSame('Mainline', $sub->parent->name);
        $this->assertSame(2, $sub->releases()->count());
        $this->assertSame(1, Series::where('name', 'Mainline')->count());

        // The make and the model are reused too, not duplicated per car.
        $this->assertSame(1, Make::where('name', 'Porsche')->count());
    }

    /**
     * A car read off a card at a glance can be held back and checked before
     * the public catalog has it, instead of going live the moment it is typed.
     */
    public function test_a_car_can_be_queued_for_review_instead_of_published(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->user = $admin;
        $scan = $this->scan();
        $detection = $scan->detections->last();

        $this->actingAs($admin)->post(route('scan.catalog', [$scan, $detection]), [
            'casting_name' => 'Porsche 911 Carrera T', 'brand' => 'Hot Wheels', 'series' => 'Mainline',
            'sub_series' => 'Factory Fresh', 'year' => 2026, 'make' => 'Porsche', 'model' => '911',
            'queue_for_review' => '1',
        ])->assertRedirect(route('scan.show', $scan));

        $casting = Casting::where('name', 'Porsche 911 Carrera T')->sole();
        $this->assertSame(Casting::STATUS_PENDING, $casting->status, 'queued, so not live');
        $variation = $casting->versions->first()->releases->first()->variations->first();
        $this->assertSame(Variation::STATUS_PENDING, $variation->status);

        // Nothing collected and nothing answered until it has been approved.
        $this->assertSame(0, $admin->collectionItems()->count());
        $this->assertTrue($detection->fresh()->isPending());

        // It is in the queue, and approving it publishes the whole chain.
        // On the variation, not the casting: approval walks upward, so this
        // is the one click that publishes the whole car.
        $proposal = SubmissionProposal::query()->where('proposable_type', $variation->getMorphClass())
            ->where('proposable_id', $variation->id)->sole();
        $this->assertNull($proposal->submission_id, 'no capture behind it, so it joins the inferred queue');
        $this->actingAs($admin)->get(route('admin.proposals.index'))->assertOk()->assertSee('Porsche 911 Carrera T');

        $this->actingAs($admin)->post(route('admin.proposals.approve', $proposal))->assertRedirect();
        $this->assertSame(Casting::STATUS_LIVE, $casting->fresh()->status);
        $this->assertSame(Variation::STATUS_LIVE, $variation->fresh()->status);
    }

    public function test_a_car_with_no_sub_series_hangs_on_the_line_itself(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->user = $admin;
        $scan = $this->scan();

        $this->actingAs($admin)->post(route('scan.catalog', [$scan, $scan->detections->first()]), [
            'casting_name' => 'Twin Mill', 'brand' => 'Hot Wheels', 'series' => 'Mainline',
            'year' => 2026, 'is_fantasy' => '1',
        ])->assertRedirect();

        $series = Casting::where('name', 'Twin Mill')->sole()
            ->versions->first()->releases->first()->series;
        $this->assertSame('Mainline', $series->name);
        $this->assertNull($series->parent_id, 'the line is the series, not a child of itself');
    }

    public function test_the_form_offers_what_the_catalog_has_without_limiting_you_to_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->user = $admin;
        $scan = $this->scan();
        $detection = $scan->detections->last();

        $this->actingAs($admin)->get(route('scan.show', ['scan' => $scan, 'label' => $detection->id]))
            ->assertOk()
            // Brands and series already in the catalog are offered …
            ->assertSee('<option value="Hot Wheels">', escape: false)
            ->assertSee('<option value="HW Flames">', escape: false)
            // … from the editorial line list, even before the classifier runs …
            ->assertSee('<option value="Car Culture">', escape: false)
            // … and every one of them is a plain text input, so a name the
            // catalog has never seen can still be typed.
            ->assertSee('name="sub_series" list=', escape: false);
    }

    public function test_the_form_is_filled_in_from_what_the_card_said_and_only_for_an_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        // The make has to be in the catalog for the form to offer it — the
        // lookup is exact, never a guess at the first word of a name.
        Make::query()->firstOrCreate(['name' => 'Porsche']);
        $this->user = $admin;
        $scan = $this->scan();
        $detection = $scan->detections->last();
        $detection->forceFill(['reading' => [
            'lines' => ['PORSCHE 911 CARRERA T'],
            'headline' => 'PORSCHE 911 CARRERA T',
            'brand' => 'Hot Wheels',
            'copyright_year' => 2026,
            'codes' => [],
            'card' => ['brand' => 'Hot Wheels', 'collector_number' => '15/250', 'toy_number' => 'HTB22'],
        ]])->save();

        $this->actingAs($admin)->get(route('scan.show', ['scan' => $scan, 'label' => $detection->id]))
            ->assertOk()
            ->assertSee('Not in the catalog? Add it')
            // Capitals on the card, the catalog's spelling in the form — and
            // the trailing T kept, not turned into "t".
            ->assertSee('value="Porsche 911 Carrera T"', escape: false)
            ->assertSee('value="Porsche"', escape: false)
            ->assertSee('value="15/250"', escape: false)
            ->assertSee('value="2026"', escape: false);

        // A collector is not offered it, and the route denies them outright.
        $collector = User::factory()->create();
        $this->actingAs($collector)->get(route('scan.show', ['scan' => $scan, 'label' => $detection->id]))->assertNotFound();
    }

    public function test_a_collector_cannot_add_to_the_catalog_from_a_scan(): void
    {
        $scan = $this->scan();
        $detection = $scan->detections->last();

        $this->actingAs($this->user)->post(route('scan.catalog', [$scan, $detection]), [
            'casting_name' => 'Porsche 911 Carrera T', 'brand' => 'Hot Wheels', 'series' => 'Mainline',
            'year' => 2026, 'make' => 'Porsche', 'model' => '911',
        ])->assertNotFound();

        $this->assertDatabaseMissing('castings', ['name' => 'Porsche 911 Carrera T']);
        $this->assertTrue($detection->fresh()->isPending());
    }

    public function test_a_new_car_added_from_a_scan_must_still_say_what_it_is(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->user = $admin;
        $scan = $this->scan();
        $detection = $scan->detections->last();

        $this->actingAs($admin)->post(route('scan.catalog', [$scan, $detection]), [
            'casting_name' => 'Porsche 911 Carrera T', 'brand' => 'Hot Wheels', 'series' => 'Mainline', 'year' => 2026,
        ])->assertSessionHasErrors(['make', 'model']);

        $this->assertDatabaseMissing('castings', ['name' => 'Porsche 911 Carrera T']);
    }

    public function test_only_live_cars_can_be_added(): void
    {
        $scan = $this->scan();
        $this->cars['Red']->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->user)->post(route('scan.add', [$scan, $scan->detections->first()]), ['variation_id' => $this->cars['Red']->id])
            ->assertNotFound();
        $this->assertSame(0, $this->user->collectionItems()->count());
    }

    /**
     * The scanner walks past a package whose printing it cannot read, so
     * there is no detection to confirm and no way to reach the car from the
     * results. Cutting it out by hand is the way back in, and it has to work
     * as many times as there are packages it missed.
     */
    public function test_a_piece_of_a_photo_can_be_scanned_again_by_hand(): void
    {
        // A sequence, not two Http::fake calls: a second fake for the same URL
        // is merged behind the first, so the re-scan would get the first
        // stub's answer and the test would quietly prove nothing.
        Http::fakeSequence('recognizer:8080/recognize*')
            ->push($this->twoCars())
            ->push([
                'found' => 1, 'confident' => 0,
                'detections' => [[
                    'n' => 1, 'box' => [10, 20, 100, 200], 'colour' => ['family' => null],
                    'text' => ['lines' => ['PAGANI ZONDA CINQUE'], 'headline' => 'PAGANI ZONDA CINQUE'],
                    'read' => ['brand' => 'Hot Wheels', 'copyright_year' => null, 'codes' => []],
                    'confident' => false,
                    'match' => ['confident' => false, 'casting_id' => null, 'confidence' => 0.0,
                        'reasons' => [], 'variations' => [], 'candidates' => []],
                    'crop_jpeg' => base64_encode('CUT-OUT'),
                ]],
            ])
            ->push(['found' => 0, 'detections' => []]);

        $scan = $this->scan(fake: false);
        $photo = $scan->scanPhotos()->sole();
        $this->assertSame(1, $photo->number);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame(2, $scan->detections()->count());

        $this->actingAs($this->user)
            ->post(route('scan.rescan', [$scan, $photo]), ['x' => 0.5, 'y' => 0.1, 'width' => 0.5, 'height' => 0.8])
            ->assertRedirect(route('scan.show', $scan));

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'region=0.5,0.1,0.5,0.8'));

        // reorder(): the relation sorts by photo then number, which would
        // otherwise win over latest() and hand back the first detection.
        $added = $scan->detections()->reorder()->latest('id')->first();
        $this->assertSame('PAGANI ZONDA CINQUE', $added->reading['headline']);
        $this->assertSame(1, $added->photo, 'it came out of photo 1');
        $this->assertSame(3, $added->number, 'numbered after the two already there');
        $this->assertSame([0.5, 0.1, 0.5, 0.8], $added->reading['region']);

        // The box is put back where it is in the whole photo, so every
        // detection on the scan is measured against the same picture.
        $this->assertSame([10 + (int) round(0.5 * $photo->width), 20 + (int) round(0.1 * $photo->height), 100, 200],
            $added->box);

        // And again, as many times as needed.
        $this->actingAs($this->user)
            ->post(route('scan.rescan', [$scan, $photo]), ['x' => 0, 'y' => 0, 'width' => 0.4, 'height' => 0.4])
            ->assertRedirect();
        $this->assertSame(3, $scan->detections()->count(), 'a piece with nothing in it adds nothing');
    }

    public function test_the_prune_takes_the_photos_too_not_just_the_crops(): void
    {
        $scan = $this->scan();
        $scan->forceFill(['created_at' => now()->subHours(48)])->save();
        $photo = $scan->scanPhotos()->sole();

        $this->artisan('catalog:prune-scans')->assertSuccessful();

        $this->assertNull($photo->fresh()->path, 'the photo outlived the crops');
        Storage::disk('local')->assertMissing($photo->path);
    }

    /**
     * Pressing on the photo has to start a selection, not pick the picture
     * up. A browser's own answer to dragging an image is to drag the image —
     * it trails a ghost of it under the cursor and the drag never reaches
     * the selection code, so the width stays at zero and the form comes back
     * with "must be between 0.02 and 1", which reads like a bad number
     * rather than a selection that never happened.
     */
    public function test_the_photo_cannot_be_dragged_away_instead_of_selected(): void
    {
        $scan = $this->scan();

        $this->actingAs($this->user)->get(route('scan.show', $scan))
            ->assertOk()
            ->assertSee('draggable="false"', escape: false)
            // The inputs refuse what the server refuses, so an empty
            // selection is caught before it is a round trip.
            ->assertSee('min="0.02"', escape: false);
    }

    public function test_a_selection_off_the_edge_is_refused(): void
    {
        $scan = $this->scan();
        $photo = $scan->scanPhotos()->sole();

        $this->actingAs($this->user)
            ->post(route('scan.rescan', [$scan, $photo]), ['x' => 0.8, 'y' => 0, 'width' => 0.5, 'height' => 0.5])
            ->assertSessionHasErrors('width');
        $this->actingAs($this->user)
            ->post(route('scan.rescan', [$scan, $photo]), ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0.5])
            ->assertSessionHasErrors(['width' => 'Draw a box on the photo first — press and drag across the package you want.']);

        $this->assertSame(2, $scan->detections()->count(), 'nothing was added');
    }

    public function test_the_photos_are_the_collectors_own_and_go_when_the_crops_do(): void
    {
        $scan = $this->scan();                       // no consent
        $photo = $scan->scanPhotos()->sole();

        $this->actingAs($this->user)->get(route('scan.photo', [$scan, $photo]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('scan.photo', [$scan, $photo]))->assertNotFound();

        // Answering everything finishes the scan, and an unconsented scan's
        // pictures go — the photo it was cut from as well as the crops.
        foreach ($scan->detections as $detection) {
            $this->actingAs($this->user)->post(route('scan.reject', [$scan, $detection]));
        }

        $this->assertNull($photo->fresh()->path);
        Storage::disk('local')->assertMissing($photo->path);
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
        // Three: the two crops and the photo they were cut from, which is
        // kept so a missed package can be cut out by hand and goes with them.
        $this->artisan('catalog:prune-scans')->expectsOutputToContain('Deleted 3')->assertSuccessful();
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
