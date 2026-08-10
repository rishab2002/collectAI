<?php

namespace Tests\Feature;

use App\Exceptions\MetadataFailedException;
use App\Models\Casting;
use App\Models\Make;
use App\Services\AI\CastingIdentity;
use App\Services\AI\MetadataAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAnthropicTransport;
use Tests\TestCase;

/**
 * The Metadata Agent's call and the reading of its answer.
 *
 * The assertions worth the most here are the misalignment ones. Asking about
 * castings in batches is what makes an 11,041-page import affordable, and it is
 * also the only place in this pipeline where a model can return a well-formed
 * answer about the wrong car — so the tests that matter are the ones proving a
 * drifted batch is dropped rather than written.
 */
class MetadataAgentTest extends TestCase
{
    use FakesAnthropicTransport, RefreshDatabase;

    public function test_it_identifies_a_real_car_from_a_casting_name(): void
    {
        $casting = Casting::factory()->unidentified()->create(['name' => "'63 T-Bird"]);

        $identities = $this->agentReturning([
            $this->identification($casting, make: 'Ford', model: 'Thunderbird'),
        ])->identify(collect([$casting]));

        $identity = $identities[$casting->id];

        $this->assertSame('Ford', $identity->make);
        $this->assertSame('Thunderbird', $identity->model);
        $this->assertTrue($identity->isUsable());
    }

    public function test_the_request_carries_the_casting_list_the_schema_and_the_known_makes(): void
    {
        $casting = Casting::factory()->unidentified()->create(['name' => "'70 Monte Carlo"]);
        Make::factory()->create(['name' => 'Chevrolet']);

        $this->agentReturning([$this->identification($casting, make: 'Chevrolet', model: 'Monte Carlo')])
            ->identify(collect([$casting]), ['Chevrolet']);

        $body = $this->sentBody();

        $this->assertSame(config('catalog.metadata.model'), $body['model']);

        $prompt = $body['messages'][0]['content'];
        $this->assertStringContainsString((string) $casting->id, $prompt);
        $this->assertStringContainsString("'70 Monte Carlo", $prompt);

        // The known-make list is what keeps "Chevy" from landing beside
        // "Chevrolet" in a hard-unique column the database can't tell apart.
        $this->assertStringContainsString('Chevrolet', $prompt);

        // The schema is sent as the output format, so the shape the model is
        // held to is the file in the repo rather than a copy in the prompt.
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(
            MetadataAgent::schema()['properties']['identifications']['items']['required'],
            $body['output_config']['format']['schema']['properties']['identifications']['items']['required'],
        );
        $this->assertSame(config('catalog.metadata.effort'), $body['output_config']['effort']);
    }

    public function test_release_years_and_series_are_not_offered_as_evidence(): void
    {
        $casting = Casting::factory()->unidentified()->create(['name' => 'Circle Tracker']);

        $this->agentReturning([$this->identification($casting, kind: CastingIdentity::KIND_UNKNOWN)])
            ->identify(collect([$casting]));

        // Only the name goes in. What wave a toy shipped in describes the
        // marketing, not the vehicle, and offering it invites the model to
        // reason from the series to the car.
        $prompt = $this->sentBody()['messages'][0]['content'];
        $this->assertStringNotContainsString('series', strtolower($prompt));
        $this->assertStringNotContainsString('year', strtolower($prompt));
    }

    public function test_an_answer_for_a_casting_that_was_not_asked_about_is_dropped(): void
    {
        $asked = Casting::factory()->unidentified()->create(['name' => "'64 Pontiac GTO"]);
        $other = Casting::factory()->unidentified()->create(['name' => 'Bone Shaker']);

        $identities = $this->agentReturning([
            $this->response([
                $this->row($asked->id, $asked->name, make: 'Pontiac', model: 'GTO'),
                $this->row($other->id, $other->name, kind: CastingIdentity::KIND_FANTASY),
            ]),
        ])->identify(collect([$asked]));

        // The second answer is about a real casting — it just wasn't in this
        // batch. Writing it would apply a verdict produced with different
        // context from the one the caller is about to act on.
        $this->assertArrayHasKey($asked->id, $identities);
        $this->assertArrayNotHasKey($other->id, $identities);
    }

    public function test_an_answer_whose_echoed_name_does_not_match_is_dropped(): void
    {
        $gto = Casting::factory()->unidentified()->create(['name' => "'64 Pontiac GTO"]);
        $boneShaker = Casting::factory()->unidentified()->create(['name' => 'Bone Shaker']);

        // The batch came back shifted by one: the GTO's id carrying the Bone
        // Shaker's answer. Structured outputs guarantee this is well-formed,
        // which is exactly why the shape alone can't catch it.
        $identities = $this->agentReturning([
            $this->response([
                $this->row($gto->id, 'Bone Shaker', kind: CastingIdentity::KIND_FANTASY),
                $this->row($boneShaker->id, "'64 Pontiac GTO", make: 'Pontiac', model: 'GTO'),
            ]),
        ])->identify(collect([$gto, $boneShaker]));

        $this->assertSame([], $identities);
    }

    public function test_a_refusal_is_not_read_as_an_identification(): void
    {
        $casting = Casting::factory()->unidentified()->create();

        $agent = $this->agentReturning([[
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-opus-5', 'content' => [],
            'stop_reason' => 'refusal', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]]);

        $this->expectException(MetadataFailedException::class);
        $this->expectExceptionMessage('declined');

        $agent->identify(collect([$casting]));
    }

    public function test_a_truncated_batch_fails_and_says_which_dial_to_turn(): void
    {
        $casting = Casting::factory()->unidentified()->create();

        $agent = $this->agentReturning([
            $this->messageWrapping(['schema_version' => 1, 'identifications' => []], stopReason: 'max_tokens'),
        ]);

        // The batch size and the token budget are the same constraint from two
        // directions, so the message names both rather than only the one this
        // run happened to hit.
        $this->expectException(MetadataFailedException::class);
        $this->expectExceptionMessage('METADATA_BATCH_SIZE');

        $agent->identify(collect([$casting]));
    }

    public function test_a_confidence_score_outside_zero_to_one_is_rejected(): void
    {
        $casting = Casting::factory()->unidentified()->create();

        $row = $this->row($casting->id, $casting->name, make: 'Ford', model: 'Mustang');
        $row['confidence']['make'] = 1.4;

        $agent = $this->agentReturning([$this->response([$row])]);

        // The API's schema subset can't express a numeric bound, so structured
        // outputs don't guarantee this one.
        $this->expectException(MetadataFailedException::class);
        $this->expectExceptionMessage("scored 'make' outside 0 to 1");

        $agent->identify(collect([$casting]));
    }

    public function test_an_empty_batch_makes_no_request(): void
    {
        $this->agentReturning([])->identify(collect());

        $this->assertSame([], $this->sent);
    }

    public function test_the_schema_file_and_the_agents_field_list_agree(): void
    {
        $schema = MetadataAgent::schema();
        $item = $schema['properties']['identifications']['items'];

        // Two definitions of the same field set in different languages, which is
        // what stops a schema edit silently dropping a score.
        $this->assertSame(MetadataAgent::FIELDS, $item['properties']['confidence']['required']);
        $this->assertSame(MetadataAgent::SCHEMA_VERSION, $schema['properties']['schema_version']['const']);

        $this->assertSame(
            [CastingIdentity::KIND_REAL, CastingIdentity::KIND_FANTASY, CastingIdentity::KIND_UNKNOWN],
            $item['properties']['kind']['enum'],
        );

        // additionalProperties: false on every object is required by the API's
        // structured-output subset, not a stylistic choice.
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertFalse($item['properties']['confidence']['additionalProperties']);
    }

    /**
     * @param  list<array<string, mixed>>  $responses
     */
    private function agentReturning(array $responses): MetadataAgent
    {
        return new MetadataAgent($this->clientReturning($responses));
    }

    /**
     * @return array<string, mixed>
     */
    private function identification(
        Casting $casting,
        string $kind = CastingIdentity::KIND_REAL,
        ?string $make = null,
        ?string $model = null,
    ): array {
        return $this->response([$this->row($casting->id, $casting->name, $kind, $make, $model)]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function response(array $rows): array
    {
        return $this->messageWrapping(['schema_version' => 2, 'identifications' => $rows]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $id,
        string $name,
        string $kind = CastingIdentity::KIND_REAL,
        ?string $make = null,
        ?string $model = null,
        ?string $chassisCode = null,
        ?int $startYear = null,
        ?int $endYear = null,
        float $score = 0.95,
        float $generationScore = 0.0,
    ): array {
        return [
            'casting_id' => $id,
            'casting_name' => $name,
            'kind' => $kind,
            'make' => $make,
            'model' => $model,
            'chassis_code' => $chassisCode,
            'generation_start_year' => $startYear,
            'generation_end_year' => $endYear,
            'confidence' => [
                'kind' => $score,
                'make' => $make === null ? 0.0 : $score,
                'model' => $model === null ? 0.0 : $score,
                'generation' => $generationScore,
            ],
        ];
    }
}
