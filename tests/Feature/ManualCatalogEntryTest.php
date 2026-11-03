<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\Photo;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\SubmissionPhoto;
use App\Models\User;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ManualCatalogEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalog.capture.disk' => 'r2']);
        Storage::fake('r2');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Not UploadedFile::fake()->image(): that needs GD, which the app container
     * doesn't have — see CaptureTest::photo().
     */
    private function photo(string $name = 'front.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 256, 'image/jpeg');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function car(array $overrides = []): array
    {
        return [
            'casting_name' => "'93 Mazda RX-7",
            'is_fantasy' => '0',
            'make' => 'Mazda',
            'model' => 'RX-7',
            'generation_start_year' => '1992',
            'generation_end_year' => '2002',
            'chassis_code' => 'FD3S',
            'brand' => 'Hot Wheels',
            'series' => 'HW J-Imports',
            'year' => '2024',
            'collector_number' => '112',
            'color' => 'Red',
            'rim_type' => 'PR5',
            'toy_number' => 'HKH01',
            'country' => 'Malaysia',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function variation(array $overrides = []): array
    {
        return [
            'brand' => 'Hot Wheels',
            'series' => 'HW J-Imports',
            'year' => '2024',
            'color' => 'Blue',
            ...$overrides,
        ];
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.castings.create'))->assertRedirect(route('login'));
        $this->get(route('admin.catalog.index'))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_cannot_see_or_use_manual_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.castings.create'))->assertNotFound();
        $this->actingAs($user)->post(route('admin.castings.store'), $this->car())->assertNotFound();

        $this->assertDatabaseCount('castings', 0);
    }

    public function test_an_admin_adds_a_real_car_and_it_goes_live_with_its_whole_chain(): void
    {
        $response = $this->actingAs($this->admin())
            ->post(route('admin.castings.store'), $this->car());

        $variation = Variation::sole();
        $casting = Casting::sole();

        $response->assertRedirect(route('admin.castings.show', $casting).'#variation-'.$variation->id)
            ->assertSessionHas('status');

        $this->assertSame('Mazda', $casting->model->make->name);
        $this->assertSame('RX-7', $casting->model->name);
        $this->assertSame('FD3S', $casting->model->chassis_code);
        $this->assertSame(1992, $casting->model->generation_start_year);
        $this->assertFalse($casting->is_fantasy);

        // Version 1 is created explicitly — nothing does it automatically.
        $this->assertSame(1, CastingVersion::sole()->version_number);

        $release = Release::sole();
        $this->assertSame(2024, $release->year);
        $this->assertSame('112', $release->collector_number);
        $this->assertSame('HW J-Imports', $release->series->name);
        $this->assertSame('Hot Wheels', $release->series->brand->name);

        $this->assertSame('Red', $variation->color);
        $this->assertSame('HKH01', $variation->toy_number);

        // The admin is the reviewer, so every record on the chain is published.
        foreach ([$variation, $release, $release->series, $release->series->brand, CastingVersion::sole(), $casting, $casting->model, $casting->model->make] as $record) {
            $this->assertTrue($record->fresh()->isLive(), $record::class.' should be live');
        }

        // Hand-made records carry neither provenance nor a proposal.
        $this->assertDatabaseCount('record_sources', 0);
        $this->assertDatabaseCount('submission_proposals', 0);
    }

    public function test_a_fantasy_car_needs_no_make_or_model(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.castings.store'), $this->car([
                'casting_name' => 'Bone Shaker',
                'is_fantasy' => '1',
                'make' => '',
                'model' => '',
                'generation_start_year' => '',
                'generation_end_year' => '',
                'chassis_code' => '',
            ]))
            ->assertSessionHasNoErrors();

        $casting = Casting::sole();

        $this->assertTrue($casting->is_fantasy);
        $this->assertNull($casting->model_id);
        $this->assertTrue($casting->isLive());
        $this->assertDatabaseCount('makes', 0);
    }

    public function test_a_fantasy_car_ignores_a_make_typed_by_mistake(): void
    {
        // The CHECK constraint stops a fantasy casting claiming a real model, so
        // a stray make must be dropped rather than turned into a 500.
        $this->actingAs($this->admin())
            ->post(route('admin.castings.store'), $this->car(['is_fantasy' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Casting::sole()->model_id);
        $this->assertDatabaseCount('makes', 0);
    }

    public function test_a_real_car_must_name_its_make_and_model(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.castings.create'))
            ->post(route('admin.castings.store'), $this->car(['make' => '', 'model' => '']))
            ->assertRedirect(route('admin.castings.create'))
            ->assertSessionHasErrors(['make', 'model']);

        $this->assertDatabaseCount('castings', 0);
    }

    public function test_a_chassis_code_without_a_start_year_is_refused_rather_than_dropped(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.castings.store'), $this->car(['generation_start_year' => '', 'generation_end_year' => '']))
            ->assertSessionHasErrors('generation_start_year');

        $this->assertDatabaseCount('castings', 0);
    }

    public function test_existing_brands_and_makes_are_reused_case_insensitively(): void
    {
        $brand = Brand::factory()->create(['name' => 'Hot Wheels']);
        $make = Make::factory()->create(['name' => 'Mazda']);

        $this->actingAs($this->admin())
            ->post(route('admin.castings.store'), $this->car(['brand' => 'hot wheels', 'make' => 'MAZDA']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('brands', 1);
        $this->assertDatabaseCount('makes', 1);
        $this->assertSame($make->id, Casting::sole()->model->make_id);
        $this->assertSame($brand->id, Series::sole()->brand_id);
    }

    public function test_a_model_row_is_one_generation_not_the_nameplate(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.castings.store'), $this->car())->assertSessionHasNoErrors();

        // Same generation: same row.
        $this->actingAs($admin)->post(route('admin.castings.store'), $this->car([
            'casting_name' => 'Mazda RX-7 (Car Culture)',
            'chassis_code' => 'FD',
        ]))->assertSessionHasNoErrors();

        // A different generation of the same nameplate: its own row.
        $this->actingAs($admin)->post(route('admin.castings.store'), $this->car([
            'casting_name' => "'85 Mazda RX-7",
            'generation_start_year' => '1985',
            'generation_end_year' => '1992',
            'chassis_code' => 'FC3S',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, VehicleModel::count());
        $this->assertSame(3, Casting::count());
        $this->assertSame(['FC3S', 'FD3S'], VehicleModel::orderBy('chassis_code')->pluck('chassis_code')->all());
    }

    public function test_photos_uploaded_with_a_new_car_land_on_its_variation(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.castings.store'), $this->car() + [
                'card_back' => $this->photo('back.jpg'),
                'card_front' => $this->photo('front.jpg'),
                'loose' => [$this->photo('side.jpg'), $this->photo('base.jpg')],
            ])
            ->assertSessionHasNoErrors();

        $photos = Variation::sole()->photos()->orderBy('id')->get();

        $this->assertCount(4, $photos);
        $this->assertSame(['card_front', 'card_back', 'loose', 'loose'], $photos->pluck('type')->all());

        // The card front is what a collector recognises, so it is the primary.
        $this->assertSame('card_front', Photo::where('is_primary', true)->sole()->type);

        foreach ($photos as $photo) {
            $this->assertSame('r2', $photo->disk);
            $this->assertStringStartsWith('photos/', $photo->path);
            Storage::disk('r2')->assertExists($photo->path);
        }
    }

    public function test_a_second_colour_becomes_a_variation_of_the_same_release(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.castings.store'), $this->car())->assertSessionHasNoErrors();
        $casting = Casting::sole();

        $this->actingAs($admin)
            ->post(route('admin.castings.variations.store', $casting), $this->variation())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(1, Release::count());
        $this->assertSame(['Red', 'Blue'], Variation::orderBy('id')->pluck('color')->all());
        $this->assertTrue(Variation::where('color', 'Blue')->sole()->isLive());
    }

    public function test_a_new_year_or_brand_becomes_a_new_release_of_the_same_casting(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.castings.store'), $this->car())->assertSessionHasNoErrors();
        $casting = Casting::sole();

        $this->actingAs($admin)->post(route('admin.castings.variations.store', $casting), $this->variation(['year' => '2025']));
        // Shared tooling sold under a sister brand is one casting with a
        // release under each brand's series — a cross-brand release is legal.
        $this->actingAs($admin)->post(route('admin.castings.variations.store', $casting), $this->variation([
            'brand' => 'Matchbox',
            'series' => 'Matchbox Collectors',
        ]));

        $this->assertSame(1, Casting::count());
        $this->assertSame(3, Release::count());
        $this->assertEqualsCanonicalizing(['Hot Wheels', 'Matchbox'], Brand::pluck('name')->all());
    }

    public function test_a_car_already_in_the_catalog_is_refused_with_its_id(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.castings.store'), $this->car())->assertSessionHasNoErrors();
        $casting = Casting::sole();
        $existing = Variation::sole();

        $this->actingAs($admin)
            ->post(route('admin.castings.variations.store', $casting), $this->variation([
                'color' => 'Red',
                'rim_type' => 'PR5',
                'toy_number' => 'HKH01',
                'country' => 'Malaysia',
            ]))
            ->assertSessionHasErrors(['color' => "That car is already in the catalog as variation #{$existing->id} — every column you filled in matches it."]);

        $this->assertSame(1, Variation::count());
    }

    public function test_a_variation_of_an_unidentified_imported_casting_is_saved_but_held(): void
    {
        $casting = Casting::factory()->unidentified()->pending()->create(['name' => 'Circle Tracker']);
        CastingVersion::factory()->for($casting)->pending()->create(['version_number' => 1]);
        RecordSource::factory()->forSourceable($casting)->create();

        $this->actingAs($this->admin())
            ->post(route('admin.castings.variations.store', $casting), $this->variation())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'held back')
                && str_contains($message, 'Circle Tracker'));

        $variation = Variation::sole();

        // The blocker is the whole point: a real car nobody has named can't go
        // live, and neither can anything hanging off it.
        $this->assertFalse($variation->isLive());
        $this->assertFalse($casting->fresh()->isLive());
        $this->assertFalse($variation->release->isLive());
    }

    public function test_a_tooling_version_from_another_casting_is_refused(): void
    {
        $casting = Casting::factory()->create();
        CastingVersion::factory()->for($casting)->create();
        $foreign = CastingVersion::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.castings.variations.store', $casting), $this->variation([
                'casting_version_id' => $foreign->id,
            ]))
            ->assertSessionHasErrors('casting_version_id');

        $this->assertSame(0, Release::where('casting_version_id', $foreign->id)->count());
    }

    public function test_photos_can_be_added_to_an_existing_variation(): void
    {
        $variation = Variation::factory()->create();
        Photo::factory()->for($variation)->primary()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.variations.photos.store', $variation), [
                'loose' => [$this->photo('a.jpg'), $this->photo('b.jpg')],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Added 2 photos.');

        $this->assertSame(3, $variation->photos()->count());
        // A variation that already has a primary keeps it.
        $this->assertSame(1, $variation->photos()->where('is_primary', true)->count());
    }

    public function test_adding_photos_requires_at_least_one(): void
    {
        $variation = Variation::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.variations.photos.store', $variation), [])
            ->assertSessionHasErrors('card_front');
    }

    public function test_a_failed_photo_write_leaves_no_orphans_in_the_bucket(): void
    {
        $variation = Variation::factory()->create();

        Photo::creating(function (): void {
            throw new RuntimeException('database went away');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin())->post(route('admin.variations.photos.store', $variation), [
                'card_front' => $this->photo('front.jpg'),
                'loose' => [$this->photo('side.jpg')],
            ]);
            $this->fail('The photo write should have failed.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame([], Storage::disk('r2')->allFiles());
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_the_primary_photo_can_be_changed(): void
    {
        $variation = Variation::factory()->create();
        $first = Photo::factory()->for($variation)->primary()->create();
        $second = Photo::factory()->for($variation)->create();

        $this->actingAs($this->admin())
            ->post(route('admin.variations.photos.primary', [$variation, $second]))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_removing_a_photo_deletes_its_bytes_and_passes_primary_on(): void
    {
        $variation = Variation::factory()->create();
        Storage::disk('r2')->put('photos/x/primary.jpg', 'bytes');
        $primary = Photo::factory()->for($variation)->primary()->create(['disk' => 'r2', 'path' => 'photos/x/primary.jpg']);
        $other = Photo::factory()->for($variation)->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.variations.photos.destroy', [$variation, $primary]))
            ->assertRedirect();

        $this->assertModelMissing($primary);
        Storage::disk('r2')->assertMissing('photos/x/primary.jpg');
        $this->assertTrue($other->fresh()->is_primary);
    }

    public function test_removing_a_photo_keeps_bytes_a_submission_still_points_at(): void
    {
        // promoteTo() references the captured object rather than copying it, so
        // the catalog photo and the submission's record share one file.
        Storage::disk('r2')->put('submissions/abc/front.jpg', 'bytes');
        $submissionPhoto = SubmissionPhoto::factory()->create(['disk' => 'r2', 'path' => 'submissions/abc/front.jpg']);
        $photo = $submissionPhoto->promoteTo(Variation::factory()->create(), primary: true);

        $this->actingAs($this->admin())
            ->delete(route('admin.variations.photos.destroy', [$photo->variation, $photo]))
            ->assertRedirect();

        $this->assertModelMissing($photo);
        Storage::disk('r2')->assertExists('submissions/abc/front.jpg');
    }

    public function test_a_photo_cannot_be_ruled_on_through_another_variation(): void
    {
        $photo = Photo::factory()->create();
        $other = Variation::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.variations.photos.destroy', [$other, $photo]))
            ->assertNotFound();

        $this->assertModelExists($photo);
    }

    public function test_the_catalog_search_finds_castings_by_name_make_or_model_at_any_status(): void
    {
        $mazda = Make::factory()->create(['name' => 'Mazda']);
        Casting::factory()->for(VehicleModel::factory()->for($mazda, 'make')->state(['name' => 'RX-7']), 'model')
            ->create(['name' => "'93 Mazda RX-7"]);
        Casting::factory()->unidentified()->pending()->create(['name' => 'Circle Tracker']);
        Casting::factory()->fantasy()->create(['name' => 'Sharkruiser']);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.catalog.index', ['q' => 'mazda']))
            ->assertOk()
            ->assertSee("'93 Mazda RX-7")
            ->assertDontSee('Sharkruiser');

        $this->actingAs($admin)->get(route('admin.catalog.index', ['q' => 'circle']))
            ->assertOk()
            ->assertSee('Circle Tracker')
            ->assertSee('Not identified yet')
            ->assertSee('Pending');

        // A LIKE wildcard typed into the box is a literal, not a match-all.
        $this->actingAs($admin)->get(route('admin.catalog.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('Sharkruiser');
    }

    public function test_the_casting_page_shows_releases_variations_photos_and_attribution(): void
    {
        $casting = Casting::factory()->create(['name' => 'Nissan 350Z']);
        $version = CastingVersion::factory()->for($casting)->create();
        $release = Release::factory()->for($version, 'castingVersion')
            ->for(Series::factory()->state(['name' => 'Nightburnerz']))
            ->create(['year' => 2011]);
        $variation = Variation::factory()->for($release)->create(['color' => 'Midnight Blue']);
        Photo::factory()->for($variation)->primary()->create();
        RecordSource::factory()->forSourceable($casting)->create([
            'source_url' => 'https://hotwheels.fandom.com/wiki/Nissan_350Z',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.castings.show', $casting))
            ->assertOk()
            ->assertSee('2011 · Nightburnerz')
            ->assertSee('1 release,')
            ->assertSee('1 variation')
            ->assertDontSee('1 variations')
            ->assertSee('Midnight Blue')
            ->assertSee('Primary')
            ->assertSee('hotwheels.fandom.com')
            ->assertSee('Add a release or variation');
    }

    public function test_the_catalog_search_names_the_brands_that_sold_each_casting(): void
    {
        // Two tools of the same real car, one per brand, otherwise read as a
        // duplicate in the list.
        foreach (['Hot Wheels', 'Matchbox'] as $brandName) {
            $casting = Casting::factory()->unidentified()->pending()->create(['name' => 'Nissan Skyline GT-R (BNR34)']);
            Release::factory()
                ->for(CastingVersion::factory()->for($casting), 'castingVersion')
                ->for(Series::factory()->for(Brand::firstOrCreate(['name' => $brandName])))
                ->create();
        }

        $this->actingAs($this->admin())
            ->get(route('admin.catalog.index', ['q' => 'skyline']))
            ->assertOk()
            ->assertSeeInOrder(['Hot Wheels ·', 'Not identified yet', 'Matchbox ·', 'Not identified yet']);
    }

    public function test_the_entry_screens_render(): void
    {
        Brand::factory()->create(['name' => 'Mini GT']);

        $this->actingAs($this->admin())
            ->get(route('admin.castings.create'))
            ->assertOk()
            ->assertSee('Fantasy casting')
            ->assertSee('<option value="Mini GT">', false)
            // Works with no JavaScript: plain multipart form, plain file inputs.
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('capture="environment"', false);
    }
}
