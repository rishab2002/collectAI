<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\AuthenticationException;
use App\Models\Casting;
use App\Models\Make;
use App\Models\RecordSource;
use App\Models\SubmissionProposal;
use App\Models\VehicleModel;
use App\Services\AI\CastingIdentity;
use App\Services\AI\MetadataAgent;
use App\Services\Catalog\CastingIdentifier;
use App\Services\Import\ImportSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAnthropicTransport;
use Tests\TestCase;

/**
 * What the catalogue does with an attribution once the agent has made one.
 *
 * The rule these all circle is the one the whole design turns on: the score
 * decides whether an attribution is *published*, never whether it is *written
 * down*. A low-confidence answer still lands on the casting, because a proposal
 * with no model attached is one an admin cannot act on — Casting's approval
 * blocker refuses it and the review screen has nothing to show. Invisible and
 * concrete beats visible and vague.
 */
class CastingIdentificationTest extends TestCase
{
    use FakesAnthropicTransport, RefreshDatabase;

    public function test_a_confident_attribution_is_written_and_published(): void
    {
        $casting = $this->importedCasting("'63 T-Bird");

        $this->identify($casting, [$this->row($casting, make: 'Ford', model: 'Thunderbird', score: 0.97)]);

        $casting->refresh();

        $this->assertSame('Thunderbird', $casting->model->name);
        $this->assertSame('Ford', $casting->model->make->name);

        // The whole chain, because approval walks upward: a live casting under
        // an invisible make would be unreachable in the catalogue.
        $this->assertTrue($casting->isLive());
        $this->assertTrue($casting->model->isLive());
        $this->assertTrue($casting->model->make->isLive());

        $this->assertSame(
            SubmissionProposal::DECISION_APPROVED,
            $casting->proposal->decision,
        );

        // No admin ruled on it — the threshold did. The table's decision
        // metadata CHECK allows exactly that shape.
        $this->assertNull($casting->proposal->decided_by);
        $this->assertNotNull($casting->proposal->decided_at);
        $this->assertNull($casting->proposal->submission_id);
    }

    public function test_a_low_confidence_attribution_is_written_but_left_pending(): void
    {
        $casting = $this->importedCasting('Circle Tracker');

        $this->identify($casting, [$this->row($casting, make: 'Ford', model: 'Fairlane', score: 0.55)]);

        $casting->refresh();

        // Attached, so the reviewer has a concrete claim to rule on rather than
        // a proposal that says "not identified yet".
        $this->assertSame('Fairlane', $casting->model->name);

        $this->assertFalse($casting->isLive());
        $this->assertFalse($casting->model->isLive());
        $this->assertSame(SubmissionProposal::DECISION_PENDING, $casting->proposal->decision);
        $this->assertSame('0.550', $casting->proposal->confidence);
    }

    public function test_an_attribution_is_only_as_strong_as_its_weaker_half(): void
    {
        $casting = $this->importedCasting("'71 Mustang Funny Car");

        // Certain of the manufacturer, guessing at the nameplate. Approving
        // publishes both, so averaging these would carry the guess over the bar
        // on the strength of the certainty.
        $row = $this->row($casting, make: 'Ford', model: 'Mustang', score: 0.99);
        $row['confidence']['model'] = 0.6;

        $this->identify($casting, [$row]);

        $casting->refresh();

        $this->assertFalse($casting->isLive());
        $this->assertSame('0.600', $casting->proposal->confidence);
    }

    public function test_a_fantasy_casting_gets_the_flag_and_no_invented_make(): void
    {
        $casting = $this->importedCasting('Rennen Rig');

        $this->identify($casting, [$this->row($casting, kind: CastingIdentity::KIND_FANTASY, score: 0.96)]);

        $casting->refresh();

        $this->assertTrue($casting->isFantasy());
        $this->assertNull($casting->model_id);

        // CLAUDE.md's rule: a fantasy casting gets no Make/Model records at all
        // rather than invented ones.
        $this->assertSame(0, Make::count());
        $this->assertSame(0, VehicleModel::count());

        // And it publishes, because is_fantasy is the positive assertion that
        // clears Casting::approvalBlocker().
        $this->assertTrue($casting->isLive());
    }

    public function test_an_unknown_verdict_writes_nothing_at_all(): void
    {
        $casting = $this->importedCasting('Jaded');

        $summary = $this->identify($casting, [$this->row($casting, kind: CastingIdentity::KIND_UNKNOWN)]);

        $casting->refresh();

        $this->assertTrue($casting->isUnidentified());
        $this->assertNull($casting->proposal);
        $this->assertSame(1, $summary->count('castings.unknown'));

        // An honest "I can't tell" is the answer this was designed to accept, so
        // it isn't filed alongside genuine failures where it would bury them.
        $this->assertSame([], $summary->problems());
    }

    public function test_a_real_verdict_with_no_make_is_treated_as_unknown(): void
    {
        $casting = $this->importedCasting('F-Racer');

        $summary = $this->identify($casting, [
            $this->row($casting, kind: CastingIdentity::KIND_REAL, make: null, model: null, score: 0.8),
        ]);

        // castings.model_id cannot hold half an attribution, and the approval
        // blocker would refuse the result anyway — so this is the unknown
        // verdict wearing the wrong label, not a partial answer.
        $this->assertTrue($casting->refresh()->isUnidentified());
        $this->assertSame(1, $summary->count('castings.unknown'));
    }

    public function test_an_existing_make_is_reused_rather_than_spelled_again(): void
    {
        Make::factory()->create(['name' => 'Chevrolet']);
        $casting = $this->importedCasting("'70 Monte Carlo");

        $this->identify($casting, [$this->row($casting, make: 'chevrolet', model: 'Monte Carlo', score: 0.95)]);

        // makes.name is hard-unique, so a second spelling isn't a collision the
        // database catches — it's two rows for one company, matched
        // case-insensitively to avoid exactly that.
        $this->assertSame(1, Make::count());
        $this->assertSame('Chevrolet', $casting->refresh()->model->make->name);
    }

    public function test_an_existing_model_under_the_same_make_is_reused(): void
    {
        $ford = Make::factory()->create(['name' => 'Ford']);
        $foxBody = VehicleModel::factory()->create([
            'make_id' => $ford->id,
            'name' => 'Mustang',
            'chassis_code' => 'Fox',
            'generation_start_year' => 1979,
            'generation_end_year' => 1993,
        ]);

        $casting = $this->importedCasting("'92 Ford Mustang");

        $this->identify($casting, [$this->row(
            $casting, make: 'Ford', model: 'Mustang', score: 0.95,
            chassisCode: 'Fox', startYear: 1979, endYear: 1993,
        )]);

        // Same make, same nameplate, same generation — one row.
        $this->assertSame(1, VehicleModel::count());
        $this->assertSame($foxBody->id, $casting->refresh()->model_id);
    }

    public function test_a_resplit_moves_a_casting_to_its_generation_without_reviewing_it_again(): void
    {
        // The state the live catalogue was in: an approved casting filed under a
        // nameplate row that collapsed every generation of it.
        $chevrolet = Make::factory()->create(['name' => 'Chevrolet']);
        $collapsed = VehicleModel::factory()->withoutGeneration()->create([
            'make_id' => $chevrolet->id, 'name' => 'Corvette',
        ]);
        $casting = $this->importedCasting("'97 Corvette");
        $casting->forceFill(['model_id' => $collapsed->id, 'status' => Casting::STATUS_LIVE])->save();
        $proposal = SubmissionProposal::factory()->forProposable($casting)->approved()
            ->create(['submission_id' => null, 'confidence' => 0.95]);

        $summary = $this->resplit($casting, [$this->row(
            $casting, make: 'Chevrolet', model: 'Corvette', score: 0.95,
            chassisCode: 'C5', startYear: 1997, endYear: 2004,
        )]);

        $casting->refresh();

        $this->assertSame('C5', $casting->model->chassis_code);
        $this->assertSame(1, $summary->count('castings.resplit'));

        // A re-split is a data migration, not a re-review: the ruling stands and
        // no second proposal appears.
        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $proposal->refresh()->decision);
        $this->assertSame(1, SubmissionProposal::count());

        // A live casting must not end up under a pending model — that is the
        // "live record with an invisible parent" the approval walk exists to
        // prevent, reached sideways.
        $this->assertTrue($casting->isLive());
        $this->assertTrue($casting->model->isLive());
    }

    public function test_a_resplit_leaves_a_casting_alone_when_the_attribution_itself_changed(): void
    {
        $ford = Make::factory()->create(['name' => 'Ford']);
        $mustang = VehicleModel::factory()->withoutGeneration()->create([
            'make_id' => $ford->id, 'name' => 'Mustang',
        ]);
        $casting = $this->importedCasting("'71 Mustang Funny Car");
        $casting->forceFill(['model_id' => $mustang->id])->save();

        // The agent now says something else entirely.
        $summary = $this->resplit($casting, [$this->row(
            $casting, make: 'Mercury', model: 'Cougar', score: 0.95,
            chassisCode: null, startYear: 1967, endYear: 1970,
        )]);

        // Re-attributing a record under a command whose job is splitting
        // generations would be a change nobody asked for and nobody sees.
        $this->assertSame($mustang->id, $casting->refresh()->model_id);
        $this->assertCount(1, $summary->problems());
        $this->assertStringContainsString('left alone', $summary->problems()[0]);
    }

    public function test_one_nameplate_across_generations_becomes_one_row_per_generation(): void
    {
        // The exact shape that broke on the real 2011 import: four Corvette
        // castings, four generations, one nameplate.
        $castings = [
            ["Split Window '63", 'C2', 1963, 1967],
            ["'69 COPO Corvette", 'C3', 1968, 1982],
            ["'97 Corvette", 'C5', 1997, 2004],
            ["'11 Corvette Grand Sport", 'C6', 2005, 2013],
        ];

        foreach ($castings as [$name, $code, $start, $end]) {
            $casting = $this->importedCasting($name);
            $this->identify($casting, [$this->row(
                $casting, make: 'Chevrolet', model: 'Corvette', score: 0.95,
                chassisCode: $code, startYear: $start, endYear: $end,
            )]);
        }

        // One make, four generation rows — not one "Corvette" wearing whichever
        // code happened to arrive first.
        $this->assertSame(1, Make::count());
        $this->assertSame(4, VehicleModel::count());

        $this->assertSame(
            ['C2', 'C3', 'C5', 'C6'],
            VehicleModel::orderBy('generation_start_year')->pluck('chassis_code')->all(),
        );

        // Every casting sits under its own generation.
        foreach ($castings as [$name, $code]) {
            $this->assertSame($code, Casting::where('name', $name)->first()->model->chassis_code, $name);
        }
    }

    public function test_two_castings_of_the_same_generation_share_one_row(): void
    {
        foreach (["'69 COPO Corvette", "'69 Corvette"] as $name) {
            $casting = $this->importedCasting($name);
            $this->identify($casting, [$this->row(
                $casting, make: 'Chevrolet', model: 'Corvette', score: 0.95,
                chassisCode: 'C3', startYear: 1968, endYear: 1982,
            )]);
        }

        // Splitting by generation must not become splitting per casting — these
        // are two models of the same car.
        $this->assertSame(1, VehicleModel::count());
    }

    public function test_a_generation_below_the_threshold_is_dropped_but_the_car_still_lands(): void
    {
        $casting = $this->importedCasting('Chevy Camaro Concept');

        $this->identify($casting, [$this->row(
            $casting, make: 'Chevrolet', model: 'Camaro', score: 0.95,
            chassisCode: 'F-body', startYear: 1967, endYear: 1969, generationScore: 0.4,
        )]);

        $model = $casting->refresh()->model;

        // The generation is dropped whole — code and years together, since they
        // are one claim scored by one number. Half of it would file the casting
        // under a generation the agent never actually asserted.
        $this->assertSame('Camaro', $model->name);
        $this->assertNull($model->chassis_code);
        $this->assertNull($model->generation_start_year);
        $this->assertNull($model->generation_end_year);

        // But the make and model still went through and still published.
        $this->assertTrue($model->isLive());
    }

    public function test_castings_with_no_generation_share_the_unplaced_row(): void
    {
        foreach (['Blown Camaro', 'Camaro Convertible Concept'] as $name) {
            $casting = $this->importedCasting($name);
            $this->identify($casting, [$this->row($casting, make: 'Chevrolet', model: 'Camaro', score: 0.95)]);
        }

        // Null start year is its own bucket rather than a wildcard. In Postgres
        // `where generation_start_year = null` matches nothing, so without an
        // explicit whereNull these would each create a fresh row.
        $this->assertSame(1, VehicleModel::count());
        $this->assertNull(VehicleModel::first()->generation_start_year);
    }

    public function test_an_existing_generation_row_is_reused_not_rewritten(): void
    {
        $nissan = Make::factory()->create(['name' => 'Nissan']);
        $r34 = VehicleModel::factory()->create([
            'make_id' => $nissan->id,
            'name' => 'Skyline GT-R',
            'chassis_code' => 'R34',
            'generation_start_year' => 1999,
            'generation_end_year' => 2002,
        ]);

        $casting = $this->importedCasting('Nissan Skyline GT-R (R32)');

        $this->identify($casting, [$this->row(
            $casting, make: 'Nissan', model: 'Skyline GT-R', score: 0.95,
            chassisCode: 'R32', startYear: 1989, endYear: 1994,
        )]);

        // The R32 casting gets its own row rather than being filed under — or
        // rewriting — the R34 that already existed. This is the failure the
        // seeded catalogue actually had.
        $this->assertSame(2, VehicleModel::count());
        $this->assertSame('R34', $r34->refresh()->chassis_code);
        $this->assertSame('R32', $casting->refresh()->model->chassis_code);
    }

    public function test_the_generation_reads_as_a_legend(): void
    {
        $model = VehicleModel::factory()->create([
            'name' => 'Corvette', 'chassis_code' => 'C3',
            'generation_start_year' => 1968, 'generation_end_year' => 1982,
        ]);
        $this->assertSame('C3 (1968–1982)', $model->generationLabel());
        $this->assertSame('Corvette · C3 (1968–1982)', $model->fullName());

        // A generation with no manufacturer designation still reads.
        $this->assertSame('1967–1969', VehicleModel::factory()->create([
            'name' => 'Camaro', 'chassis_code' => null,
            'generation_start_year' => 1967, 'generation_end_year' => 1969,
        ])->generationLabel());

        // Still in production — "1997–" would read as unfinished.
        $this->assertSame('2020–present', VehicleModel::factory()->create([
            'chassis_code' => null, 'generation_start_year' => 2020, 'generation_end_year' => null,
        ])->generationLabel());

        // Never established is a real state, not an empty pair of brackets.
        $unplaced = VehicleModel::factory()->withoutGeneration()->create(['name' => 'Camaro']);
        $this->assertNull($unplaced->generationLabel());
        $this->assertSame('Camaro', $unplaced->fullName());
    }

    public function test_a_casting_with_no_answer_is_reported_rather_than_skipped_silently(): void
    {
        $casting = $this->importedCasting('Scorpedo');

        $summary = $this->identify($casting, []);

        $this->assertTrue($casting->refresh()->isUnidentified());
        $this->assertCount(1, $summary->problems());
        $this->assertStringContainsString('Scorpedo', $summary->problems()[0]);
    }

    public function test_castings_are_asked_about_in_batches(): void
    {
        $castings = collect(['Bone Shaker', 'Twin Mill', 'Deora II'])
            ->map(fn (string $name): Casting => $this->importedCasting($name));

        $agent = new MetadataAgent($this->clientReturning([
            $this->response($castings->take(2)->map(
                fn (Casting $c) => $this->row($c, kind: CastingIdentity::KIND_FANTASY, score: 0.95)
            )->all()),
            $this->response([$this->row($castings[2], kind: CastingIdentity::KIND_FANTASY, score: 0.95)]),
        ]));

        $summary = new ImportSummary;
        $identifier = new CastingIdentifier($agent, 0.9);

        foreach ($castings->chunk(2) as $batch) {
            $identifier->identifyBatch($batch, $summary);
        }

        $this->assertCount(2, $this->sent);
        $this->assertSame(3, $summary->count('castings.fantasy'));
    }

    public function test_a_failed_batch_does_not_end_the_run(): void
    {
        $casting = $this->importedCasting('Bone Shaker');

        $summary = new ImportSummary;

        // A refusal: HTTP 200 with empty content.
        $agent = new MetadataAgent($this->clientReturning([[
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-opus-5', 'content' => [],
            'stop_reason' => 'refusal', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]]));

        (new CastingIdentifier($agent, 0.9))->identifyBatch(collect([$casting]), $summary);

        // The castings stay exactly where they were, which is where a 241-run
        // can pick them up next time.
        $this->assertTrue($casting->refresh()->isUnidentified());
        $this->assertCount(1, $summary->problems());
    }

    public function test_an_api_error_is_recorded_and_the_run_carries_on(): void
    {
        $casting = $this->importedCasting('Bone Shaker');
        $summary = new ImportSummary;

        $agent = new MetadataAgent($this->clientFailingWith(400));

        (new CastingIdentifier($agent, 0.9))->identifyBatch(collect([$casting]), $summary);

        // A rate limit, a 529 or a dropped connection is worth surviving: one
        // bad batch shouldn't cost the 240 castings behind it.
        $this->assertTrue($casting->refresh()->isUnidentified());
        $this->assertCount(1, $summary->problems());
    }

    public function test_a_rejected_credential_stops_the_run_instead_of_repeating_it(): void
    {
        $casting = $this->importedCasting('Bone Shaker');

        $agent = new MetadataAgent($this->clientFailingWith(401, 'authentication_error'));

        // Nothing about the next batch would be different. Swallowing this would
        // grind through all ten batches and finish reporting a run that
        // identified nothing — which reads like the model was unsure rather than
        // like the key is wrong.
        $this->expectException(AuthenticationException::class);

        (new CastingIdentifier($agent, 0.9))->identifyBatch(collect([$casting]), new ImportSummary);
    }

    public function test_the_command_reports_a_rejected_credential_rather_than_stack_tracing(): void
    {
        $this->importedCasting('Bone Shaker');

        // The Claude driver: the local one has no credential to reject.
        config(['catalog.ai.driver' => 'claude']);

        $this->app->bind(
            MetadataAgent::class,
            fn (): MetadataAgent => new MetadataAgent($this->clientFailingWith(401, 'authentication_error')),
        );

        $this->artisan('catalog:identify-castings', ['--limit' => 1])
            // The variable this app reads — not ANTHROPIC_API_KEY, which would
            // point the reader at the credential Claude Code resolves first.
            ->expectsOutputToContain('COLLECTAI_ANTHROPIC_KEY')
            ->assertFailed();
    }

    public function test_the_outstanding_queue_leaves_out_castings_already_ruled_on(): void
    {
        $waiting = $this->importedCasting('Circle Tracker');

        $retracted = $this->importedCasting('Monster Vette');
        SubmissionProposal::factory()
            ->forProposable($retracted)
            ->rejected()
            ->create(['submission_id' => null]);

        $identified = Casting::factory()->pending()->create();

        $outstanding = CastingIdentifier::outstanding()->pluck('id');

        $this->assertTrue($outstanding->contains($waiting->id));

        // A rejected proposal is a ruling, not a gap. Re-inferring would hand
        // the admin back the claim they just refused.
        $this->assertFalse($outstanding->contains($retracted->id));
        $this->assertFalse($outstanding->contains($identified->id));
    }

    /**
     * A casting as the importer leaves one: real, unattributed, pending, and
     * carrying the provenance of the page it was read from.
     */
    private function importedCasting(string $name): Casting
    {
        $casting = Casting::factory()->unidentified()->pending()->create(['name' => $name]);

        RecordSource::factory()->forSourceable($casting)->create();

        return $casting;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function identify(Casting $casting, array $rows, float $threshold = 0.9): ImportSummary
    {
        $summary = new ImportSummary;

        $identifier = new CastingIdentifier(
            new MetadataAgent($this->clientReturning([$this->response($rows)])),
            $threshold,
            // Both bars explicit, so a test asserts its own arrangement rather
            // than whatever config/catalog.php currently says.
            $threshold,
        );

        $identifier->identifyBatch(collect([$casting]), $summary);

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function resplit(Casting $casting, array $rows, float $threshold = 0.9): ImportSummary
    {
        $summary = new ImportSummary;

        (new CastingIdentifier(
            new MetadataAgent($this->clientReturning([$this->response($rows)])),
            $threshold,
        ))->resplitBatch(collect([$casting]), $summary);

        return $summary;
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
        Casting $casting,
        string $kind = CastingIdentity::KIND_REAL,
        ?string $make = null,
        ?string $model = null,
        float $score = 0.95,
        ?string $chassisCode = null,
        ?int $startYear = null,
        ?int $endYear = null,
        ?float $generationScore = null,
    ): array {
        return [
            'casting_id' => $casting->id,
            'casting_name' => $casting->name,
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
                'generation' => $generationScore ?? ($startYear === null ? 0.0 : $score),
            ],
        ];
    }
}
