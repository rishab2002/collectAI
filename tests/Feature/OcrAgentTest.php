<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Exceptions\OcrFailedException;
use App\Models\Submission;
use App\Models\SubmissionPhoto;
use App\Services\AI\OcrAgent;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Drives the real SDK against a fake PSR-18 transporter.
 *
 * Faking at the transport layer rather than mocking the client is deliberate:
 * the request body is built by the SDK from named arguments, so the parameter
 * names are only proven correct if something serializes them. A mocked client
 * would assert that this class called a method with the arguments it was
 * written to call — which is true by construction and says nothing about
 * whether `mediaType` reaches the wire as `media_type`.
 */
class OcrAgentTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{request: Request, response: mixed}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        config(['catalog.capture.disk' => 'r2']);
    }

    public function test_it_reads_the_packaging_and_returns_the_reading(): void
    {
        $submission = $this->submissionWithPhotos(['card_front.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);

        $agent = $this->agentReturning($this->reading());

        $output = $agent->read($submission);

        $this->assertSame(1, $output['schema_version']);
        $this->assertSame('Hot Wheels', $output['brand']);
        $this->assertSame(2011, $output['year']);
        $this->assertSame("'92 Honda Prelude", $output['casting_name']);
        $this->assertSame(0.62, $output['confidence']['toy_number']);
    }

    public function test_the_request_carries_the_photo_the_schema_and_the_model(): void
    {
        $submission = $this->submissionWithPhotos(['card_front.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);
        $submission->update(['hint_brand' => 'Hot Wheels', 'hint_year' => 2011]);

        $this->agentReturning($this->reading())->read($submission->fresh());

        $body = $this->sentBody();

        $this->assertSame(config('catalog.ocr.model'), $body['model']);

        // Wire names, not the SDK's camelCase arguments — this is the assertion
        // that would catch `mediaType` being passed somewhere it isn't mapped.
        $image = collect($body['messages'][0]['content'])->firstWhere('type', 'image');
        $this->assertSame('base64', $image['source']['type']);
        $this->assertSame('image/jpeg', $image['source']['media_type']);
        $this->assertNotEmpty($image['source']['data']);

        // The schema is sent as the output format, so the shape the model is
        // held to is the file in the repo rather than a copy in the prompt.
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(
            OcrAgent::schema()['properties']['confidence']['required'],
            $body['output_config']['format']['schema']['properties']['confidence']['required'],
        );
        $this->assertSame(config('catalog.ocr.effort'), $body['output_config']['effort']);

        $text = collect($body['messages'][0]['content'])->where('type', 'text')->pluck('text')->implode("\n");
        $this->assertStringContainsString('card front', $text);
        $this->assertStringContainsString('Hot Wheels', $text);
        $this->assertStringContainsString('report the packaging', $text);
    }

    public function test_it_opts_into_a_server_side_fallback_when_the_model_declines(): void
    {
        $submission = $this->submissionWithPhotos(['card.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);

        $this->agentReturning($this->reading())->read($submission);

        // "default" rather than a pinned substitute — Anthropic routes by
        // refusal category, and a named model is a migration owed the day it's
        // deprecated.
        $this->assertSame('default', $this->sentBody()['fallbacks']);

        // The scalar form is gated by this specific header. The array form uses
        // an earlier-dated one, and crossing them is a 400.
        $this->assertStringContainsString(
            OcrAgent::FALLBACK_BETA,
            $this->sent[0]['request']->getHeaderLine('anthropic-beta'),
        );
    }

    public function test_the_fallback_can_be_turned_off_for_a_model_that_has_none(): void
    {
        config(['catalog.ocr.fallbacks' => null]);

        $submission = $this->submissionWithPhotos(['card.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);

        $this->agentReturning($this->reading())->read($submission);

        // Sending fallbacks against a model with no eligible substitute is a
        // 400 on every read, so this has to be switchable rather than implied.
        $this->assertArrayNotHasKey('fallbacks', $this->sentBody());
        $this->assertStringNotContainsString(
            OcrAgent::FALLBACK_BETA,
            $this->sent[0]['request']->getHeaderLine('anthropic-beta'),
        );
    }

    public function test_card_photos_are_sent_before_loose_ones(): void
    {
        $submission = $this->submissionWithPhotos([
            'loose-a.jpg' => SubmissionPhoto::TYPE_LOOSE,
            'card-back.jpg' => SubmissionPhoto::TYPE_CARD_BACK,
            'card-front.jpg' => SubmissionPhoto::TYPE_CARD_FRONT,
        ]);

        config(['catalog.ocr.max_photos' => 2]);

        $this->agentReturning($this->reading())->read($submission);

        $body = $this->sentBody();
        $labels = collect($body['messages'][0]['content'])
            ->where('type', 'text')
            ->pluck('text')
            ->filter(fn (string $t): bool => str_starts_with($t, 'Photograph'))
            ->values();

        // The budget goes to the two photos with printed text on them.
        $this->assertCount(2, $labels);
        $this->assertStringContainsString('card front', $labels[0]);
        $this->assertStringContainsString('card back', $labels[1]);
    }

    public function test_heic_photos_are_skipped_because_vision_cannot_read_them(): void
    {
        $submission = $this->submissionWithPhotos([
            'shot.heic' => SubmissionPhoto::TYPE_CARD_FRONT,
            'shot.jpg' => SubmissionPhoto::TYPE_CARD_BACK,
        ]);

        $this->agentReturning($this->reading())->read($submission);

        $body = $this->sentBody();
        $images = collect($body['messages'][0]['content'])->where('type', 'image');

        // The capture screen accepts HEIC on purpose (iOS doesn't always
        // transcode), so it reaches the bucket and has to be dropped here
        // rather than sent as bytes the API rejects.
        $this->assertCount(1, $images);
    }

    public function test_a_submission_with_nothing_readable_fails_with_a_usable_reason(): void
    {
        $submission = $this->submissionWithPhotos(['shot.heic' => SubmissionPhoto::TYPE_CARD_FRONT]);

        $agent = $this->agentReturning($this->reading());

        $this->expectException(OcrFailedException::class);
        $this->expectExceptionMessage('HEIC and HEIF are stored but cannot be read');

        $agent->read($submission);
    }

    public function test_a_refusal_is_not_read_as_a_reading(): void
    {
        $submission = $this->submissionWithPhotos(['card.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);

        // A declined request is a 200 with empty content — reading content[0]
        // before checking stop_reason is how that becomes a type error instead
        // of a message an admin can act on.
        $agent = $this->agentReturning([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-opus-5', 'content' => [],
            'stop_reason' => 'refusal', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]);

        $this->expectException(OcrFailedException::class);
        $this->expectExceptionMessage('declined');

        $agent->read($submission);
    }

    public function test_a_truncated_response_fails_rather_than_storing_half_a_reading(): void
    {
        $submission = $this->submissionWithPhotos(['card.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);

        $agent = $this->agentReturning([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => '{"schema_version":1,"brand":"Hot Whe']],
            'stop_reason' => 'max_tokens', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 8000],
        ]);

        $this->expectException(OcrFailedException::class);
        $this->expectExceptionMessage('ran out of output tokens');

        $agent->read($submission);
    }

    public function test_a_confidence_score_outside_zero_to_one_is_rejected(): void
    {
        $submission = $this->submissionWithPhotos(['card.jpg' => SubmissionPhoto::TYPE_CARD_FRONT]);

        $reading = $this->readingPayload();
        $reading['confidence']['brand'] = 4.2;

        $agent = $this->agentReturning($this->messageWrapping($reading));

        // The API's schema subset can't express a numeric bound, so structured
        // outputs don't guarantee this one — it's the reason the tripwire in
        // OcrAgent::validated() checks confidence and not much else.
        $this->expectException(OcrFailedException::class);
        $this->expectExceptionMessage("scored 'brand' outside 0 to 1");

        $agent->read($submission);
    }

    public function test_the_schema_file_and_the_agents_field_list_agree(): void
    {
        $schema = OcrAgent::schema();

        // Two definitions of the same field set, in different languages. This
        // is what stops them drifting.
        $this->assertSame(
            OcrAgent::FIELDS,
            array_values(array_diff($schema['required'], ['schema_version', 'confidence'])),
        );
        $this->assertSame(OcrAgent::FIELDS, $schema['properties']['confidence']['required']);
        $this->assertSame(OcrAgent::SCHEMA_VERSION, $schema['properties']['schema_version']['const']);

        // additionalProperties: false on every object is required by the API's
        // structured-output subset, not a stylistic choice.
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['confidence']['additionalProperties']);
    }

    /**
     * The decoded JSON body of the request the SDK actually sent.
     *
     * @return array<string, mixed>
     */
    private function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true);
    }

    /**
     * @param  array<string, string>  $photos  filename => slot type
     */
    private function submissionWithPhotos(array $photos): Submission
    {
        $submission = Submission::factory()->queued()->create();

        foreach ($photos as $name => $type) {
            $path = "submissions/{$name}";
            Storage::disk('r2')->put($path, 'pretend-image-bytes');

            SubmissionPhoto::factory()->create([
                'submission_id' => $submission->id,
                'disk' => 'r2',
                'path' => $path,
                'type' => $type,
            ]);
        }

        return $submission->fresh();
    }

    /**
     * An OcrAgent whose SDK client talks to a canned response instead of the
     * network, recording each outgoing request for assertions.
     *
     * @param  array<string, mixed>  $response
     */
    private function agentReturning(array $response): OcrAgent
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['content-type' => 'application/json'], json_encode($response)),
        ]));

        $stack->push(Middleware::history($this->sent));

        return new OcrAgent(new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(
                transporter: new \GuzzleHttp\Client(['handler' => $stack]),
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function readingPayload(): array
    {
        return [
            'schema_version' => 1,
            'brand' => 'Hot Wheels',
            'year' => 2011,
            'series' => 'Night Burnerz',
            'casting_name' => "'92 Honda Prelude",
            'collector_number' => '160/244',
            'toy_number' => null,
            'confidence' => [
                'brand' => 0.99,
                'year' => 0.95,
                'series' => 0.93,
                'casting_name' => 0.91,
                'collector_number' => 0.74,
                'toy_number' => 0.62,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reading(): array
    {
        return $this->messageWrapping($this->readingPayload());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function messageWrapping(array $payload): array
    {
        return [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 180],
        ];
    }
}
