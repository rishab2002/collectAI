<?php

namespace Tests\Feature;

use App\Models\Submission;
use App\Models\SubmissionPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CaptureTest extends TestCase
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
     * A stand-in for a photo off the phone.
     *
     * Deliberately not UploadedFile::fake()->image(), which needs the GD
     * extension — the app container doesn't have it, and nothing in this feature
     * decodes an image, so requiring it would mean adding a PHP extension to
     * make a test pass rather than to make the app work. create() sets the MIME
     * type directly, which is what the mimetypes rule reads.
     */
    private function photo(string $name = 'front.jpg', int $kilobytes = 512): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.capture'))->assertRedirect(route('login'));
    }

    public function test_a_signed_in_non_admin_cannot_see_that_the_capture_screen_exists(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.capture'))
            ->assertNotFound();
    }

    public function test_a_non_admin_cannot_post_a_capture(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_an_admin_reaches_the_capture_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.capture'))
            ->assertOk()
            ->assertSee('Card front')
            // The attribute that makes the phone open its camera rather than the
            // photo picker — the whole reason this is a PWA and not a desktop form.
            ->assertSee('capture="environment"', escape: false);
    }

    public function test_a_capture_uploads_its_photos_and_queues_the_submission(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
                'card_back' => $this->photo('back.jpg'),
                'loose' => [
                    $this->photo('loose-1.jpg'),
                    $this->photo('loose-2.jpg'),
                ],
            ])
            ->assertRedirect(route('admin.capture'))
            ->assertSessionHas('status');

        $submission = Submission::sole();

        $this->assertSame($admin->id, $submission->user_id);
        // Queued, not draft: the bytes are already stored, so there is nothing
        // left for the admin to do to this submission.
        $this->assertSame(Submission::STATE_QUEUED, $submission->state);
        $this->assertNull($submission->ocr_output);
        $this->assertCount(4, $submission->photos);

        $this->assertSame(
            [
                SubmissionPhoto::TYPE_CARD_FRONT,
                SubmissionPhoto::TYPE_CARD_BACK,
                SubmissionPhoto::TYPE_LOOSE,
                SubmissionPhoto::TYPE_LOOSE,
            ],
            $submission->photos->pluck('type')->all(),
        );

        foreach ($submission->photos as $photo) {
            // The disk is recorded per row, not assumed, so these keep resolving
            // if the configured capture disk changes later.
            $this->assertSame('r2', $photo->disk);
            Storage::disk('r2')->assertExists($photo->path);
        }

        // One prefix per capture, so a capture's photos stay together in the bucket.
        $this->assertCount(1, $submission->photos->map(
            fn (SubmissionPhoto $photo) => dirname($photo->path)
        )->unique());
    }

    public function test_capture_hints_are_recorded(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
                'hint_brand' => 'Hot Wheels',
                'hint_year' => 2011,
                'hint_series' => 'Night Burnerz',
            ])
            ->assertRedirect(route('admin.capture'));

        $this->assertDatabaseHas('submissions', [
            'hint_brand' => 'Hot Wheels',
            'hint_year' => 2011,
            'hint_series' => 'Night Burnerz',
        ]);
    }

    public function test_blank_hints_are_stored_as_null_rather_than_empty_strings(): void
    {
        // An empty text input posts '', and '' is a value the pipeline would go
        // looking for a brand named "" with. Same class of bug as the empty-string
        // admin password.
        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
                'hint_brand' => '  ',
                'hint_year' => '',
                'hint_series' => '',
            ])
            ->assertRedirect(route('admin.capture'));

        $submission = Submission::sole();

        $this->assertNull($submission->hint_brand);
        $this->assertNull($submission->hint_year);
        $this->assertNull($submission->hint_series);
    }

    public function test_a_submission_with_no_photos_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), ['hint_brand' => 'Hot Wheels'])
            ->assertSessionHasErrors('card_front');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_a_file_that_is_not_a_photo_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), [
                'card_front' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf'),
            ])
            ->assertSessionHasErrors('card_front');

        $this->assertDatabaseCount('submissions', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_an_oversized_photo_is_rejected(): void
    {
        config(['catalog.capture.max_photo_kilobytes' => 1024]);

        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('huge.jpg', 2048),
            ])
            ->assertSessionHasErrors('card_front');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_a_hint_year_outside_the_catalog_range_is_rejected(): void
    {
        // Caught in validation rather than by submissions_hint_year_range, so a
        // typo is a form error instead of a 500.
        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
                'hint_year' => 1899,
            ])
            ->assertSessionHasErrors('hint_year');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_an_xhr_capture_gets_json_back_so_the_progress_bar_can_redirect(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
            ])
            ->assertCreated()
            ->assertJson(['redirect' => route('admin.capture')]);

        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_an_xhr_validation_failure_comes_back_as_json_errors(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.capture.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_front');
    }

    public function test_a_failure_after_upload_leaves_no_orphaned_objects_in_the_bucket(): void
    {
        // The r2 disk throws on a failed PUT by design, which is what stops a photo
        // row existing for bytes that never arrived. The cost is that a failure
        // partway through would strand earlier uploads that nothing will ever
        // reference — so the controller cleans up after itself.
        Submission::creating(function (): void {
            throw new RuntimeException('database went away');
        });

        $this->actingAs($this->admin())
            ->post(route('admin.capture.store'), [
                'card_front' => $this->photo('front.jpg'),
                'card_back' => $this->photo('back.jpg'),
            ])
            ->assertStatus(500);

        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('submission_photos', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }
}
