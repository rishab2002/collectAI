<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Exceptions\RecognizerUnavailableException;
use App\Jobs\ReadSubmissionPhotos;
use App\Models\Casting;
use App\Models\RecordSource;
use App\Models\Submission;
use App\Models\SubmissionPhoto;
use App\Models\SubmissionProposal;
use App\Services\AI\Contracts\IdentifiesCastings;
use App\Services\AI\Contracts\ReadsPackaging;
use App\Services\AI\LocalMetadataAgent;
use App\Services\AI\LocalPackagingReader;
use App\Services\AI\MetadataAgent;
use App\Services\AI\OcrAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The site no longer needs the Claude API: both jobs it did — reading a card
 * and naming the real car behind a casting — have local implementations, and
 * those are the default.
 */
class LocalAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalog.recognition.url' => 'http://recognizer:8080']);
    }

    public function test_local_is_the_default_and_claude_is_opt_in(): void
    {
        $this->assertSame('local', config('catalog.ai.driver'));
        $this->assertInstanceOf(LocalPackagingReader::class, app(ReadsPackaging::class));
        $this->assertInstanceOf(LocalMetadataAgent::class, app(IdentifiesCastings::class));
        $this->assertFalse(app()->resolved(Client::class), 'Nothing built a Claude client.');

        config(['catalog.ai.driver' => 'claude']);
        $this->assertInstanceOf(OcrAgent::class, app(ReadsPackaging::class));
        $this->assertInstanceOf(MetadataAgent::class, app(IdentifiesCastings::class));
    }

    /**
     * @return array<string, array{string, string, ?string, ?string, float}>
     */
    public static function names(): array
    {
        return [
            'make and model' => ["'93 Mazda RX-7", 'real', 'Mazda', 'RX-7', 0.93],
            'run together' => ['Ford GT-40', 'real', 'Ford', 'GT40', 0.93],
            'longest nameplate wins' => ['Nissan Skyline GT-R (R34)', 'real', 'Nissan', 'Skyline GT-R', 0.93],
            'chassis code is the 911' => ['Porsche 993 GT2', 'real', 'Porsche', '911', 0.93],
            'alias of the make' => ['VW Drag Bus', 'real', 'Volkswagen', 'Type 2', 0.93],
            'first make named wins' => ['Dodge Ram 1500', 'real', 'Dodge', 'Ram', 0.93],
            'trim is not the nameplate' => ['Hummer H2 SUT', 'real', 'Hummer', 'H2', 0.93],
            'nameplate only, held for review' => ["'67 Camaro", 'real', 'Chevrolet', 'Camaro', 0.85],
            'fantasy' => ["Splittin' Image II", 'fantasy', null, null, 0.95],
            'honest unknown' => ['Circle Tracker', 'unknown', null, null, 0.0],
            'common word needs its make' => ['Phantom Racer', 'unknown', null, null, 0.0],
            'make without a known model' => ["Custom '41 Willys Coupe", 'unknown', null, null, 0.0],
        ];
    }

    #[DataProvider('names')]
    public function test_the_dictionary_names_cars(string $name, string $kind, ?string $make, ?string $model, float $score): void
    {
        $identity = (new LocalMetadataAgent)->identifyName(1, $name);

        $this->assertSame($kind, $identity->kind);
        $this->assertSame($make, $identity->make);
        $this->assertSame($model, $identity->model);
        $this->assertEqualsWithDelta($score, $identity->score(), 0.001);
        $this->assertFalse($identity->hasGeneration(), 'The dictionary never claims a generation.');
    }

    public function test_the_vehicle_file_is_well_formed(): void
    {
        $data = json_decode(file_get_contents(config('catalog.ai.vehicles')), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($data['makes'], 'name');

        $this->assertSame(count($names), count(array_unique($names)), 'Each make once.');
        $this->assertGreaterThan(80, count($names));

        foreach ($data['makes'] as $make) {
            $this->assertNotEmpty($make['models'], $make['name']);
        }
    }

    public function test_identify_castings_runs_locally_and_gates_on_the_score(): void
    {
        Http::preventStrayRequests();
        $sure = $this->importedCasting("'93 Mazda RX-7");
        $likely = $this->importedCasting("'67 Camaro");
        $unknown = $this->importedCasting('Circle Tracker');

        $this->artisan('catalog:identify-castings')
            ->expectsOutputToContain('no API calls')
            ->assertSuccessful();

        $this->assertSame('RX-7', $sure->fresh()->model->name);
        $this->assertSame('Mazda', $sure->fresh()->model->make->name);
        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $sure->fresh()->proposal->decision);

        $this->assertSame('Camaro', $likely->fresh()->model->name);
        $this->assertSame(SubmissionProposal::DECISION_PENDING, $likely->fresh()->proposal->decision);

        $this->assertNull($unknown->fresh()->model_id);
        $this->assertNull($unknown->fresh()->proposal);
        $this->assertFalse(app()->resolved(Client::class));
    }

    public function test_a_capture_is_read_by_the_recognizer_photo_by_photo(): void
    {
        Storage::fake('local');
        $submission = Submission::factory()->queued()->create();
        $front = SubmissionPhoto::factory()->for($submission)->create(['path' => 'submissions/front.jpg']);
        $back = SubmissionPhoto::factory()->for($submission)->cardBack()->create(['path' => 'submissions/back.jpg']);
        SubmissionPhoto::factory()->for($submission)->loose()->create(['path' => 'submissions/base.heic']);
        Storage::disk('local')->put($front->path, 'FRONT');
        Storage::disk('local')->put($back->path, 'BACK');

        Http::fake(['recognizer:8080/read-card*' => function ($request) {
            $front = $request->body() === 'FRONT';

            return Http::response([
                'schema_version' => 1,
                'brand' => 'Hot Wheels', 'year' => null, 'series' => null,
                'casting_name' => $front ? 'Bone Shaker' : null,
                'collector_number' => $front ? '77/250' : null,
                'toy_number' => $front ? null : 'DTX12',
                'confidence' => [
                    'brand' => $front ? 0.9 : 0.75, 'year' => 0, 'series' => 0,
                    'casting_name' => $front ? 0.97 : 0, 'collector_number' => $front ? 0.7 : 0, 'toy_number' => $front ? 0 : 0.95,
                ],
            ]);
        }]);

        ReadSubmissionPhotos::dispatchSync($submission);

        $submission->refresh();
        $this->assertSame(Submission::STATE_COMPLETED, $submission->state);
        // assertEquals: jsonb keeps neither key order nor 0.0 vs 0.
        $this->assertEquals([
            'schema_version' => 1,
            'confidence' => ['brand' => 0.9, 'year' => 0.0, 'series' => 0.0, 'casting_name' => 0.97, 'collector_number' => 0.7, 'toy_number' => 0.95],
            'brand' => 'Hot Wheels', 'year' => null, 'series' => null,
            'casting_name' => 'Bone Shaker', 'collector_number' => '77/250', 'toy_number' => 'DTX12',
        ], $submission->ocr_output);
        // The HEIC photo is skipped, not sent.
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    public function test_a_recognizer_that_is_down_is_an_error_worth_retrying(): void
    {
        Storage::fake('local');
        $submission = Submission::factory()->queued()->create();
        $photo = SubmissionPhoto::factory()->for($submission)->create();
        Storage::disk('local')->put($photo->path, 'x');
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->expectException(RecognizerUnavailableException::class);
        $this->expectExceptionMessage('docker compose up -d recognizer');

        app(LocalPackagingReader::class)->read($submission);
    }

    private function importedCasting(string $name): Casting
    {
        $casting = Casting::factory()->unidentified()->pending()->create(['name' => $name]);
        RecordSource::factory()->forSourceable($casting)->create();

        return $casting;
    }
}
