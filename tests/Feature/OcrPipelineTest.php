<?php

namespace Tests\Feature;

use App\Exceptions\OcrFailedException;
use App\Jobs\ReadSubmissionPhotos;
use App\Models\Submission;
use App\Models\User;
use App\Services\AI\OcrAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Capture through to a stored reading — the seam between the HTTP request and
 * the queued pipeline run, with the vision call itself stubbed out.
 * OcrAgentTest covers what the agent sends and how it reads the answer.
 */
class OcrPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_capturing_a_photo_queues_a_read(): void
    {
        Queue::fake();
        config(['catalog.capture.disk' => 'r2']);
        Storage::fake('r2');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.capture.store'), [
                'card_front' => UploadedFile::fake()->create('front.jpg', 512, 'image/jpeg'),
            ])
            ->assertRedirect();

        $submission = Submission::sole();

        // The whole point of the capture screen is that the phone hands the
        // work off and stops waiting for it.
        $this->assertSame(Submission::STATE_QUEUED, $submission->state);
        Queue::assertPushed(
            ReadSubmissionPhotos::class,
            fn (ReadSubmissionPhotos $job): bool => $job->submission->is($submission),
        );
    }

    public function test_a_successful_read_completes_the_submission(): void
    {
        $submission = Submission::factory()->queued()->create();

        $reading = [
            'schema_version' => 1,
            'brand' => 'Hot Wheels',
            'year' => 2011,
            'series' => 'Night Burnerz',
            'casting_name' => "'92 Honda Prelude",
            'collector_number' => '160/244',
            'toy_number' => 'T9765',
            'confidence' => array_fill_keys(OcrAgent::FIELDS, 0.9),
        ];

        $agent = Mockery::mock(OcrAgent::class);
        $agent->shouldReceive('read')->once()->andReturn($reading);

        (new ReadSubmissionPhotos($submission))->handle($agent);

        $submission->refresh();

        $this->assertSame(Submission::STATE_COMPLETED, $submission->state);
        // assertEquals, not assertSame: ocr_output is a jsonb column and
        // Postgres does not preserve key order, which is not information the
        // reading depends on.
        $this->assertEquals($reading, $submission->ocr_output);
        $this->assertNotNull($submission->processed_at);
        $this->assertNull($submission->error);
    }

    public function test_a_failed_read_records_a_reason_an_admin_can_act_on(): void
    {
        $submission = Submission::factory()->queued()->create();

        $reason = 'No readable photos on this submission. HEIC and HEIF are stored but cannot be read.';

        // The queue calls failed() after the last attempt; that is the only
        // place the reason gets in front of a human, because the review screen
        // renders submissions.error and nobody reads the worker log.
        (new ReadSubmissionPhotos($submission))->failed(new OcrFailedException($reason));

        $submission->refresh();

        $this->assertSame(Submission::STATE_FAILED, $submission->state);
        $this->assertSame($reason, $submission->error);
        $this->assertNotNull($submission->processed_at);
    }

    public function test_a_failed_run_still_opens_on_the_review_screen(): void
    {
        $submission = Submission::factory()->create([
            'state' => Submission::STATE_FAILED,
            'error' => 'The model declined to read these photos.',
        ]);

        // A failed pipeline run is a thing the admin has to be able to see and
        // understand, not a submission that disappears from the app.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('This pipeline run failed')
            ->assertSee('declined to read these photos');
    }
}
