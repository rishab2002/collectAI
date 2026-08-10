<?php

namespace App\Services\AI;

use Anthropic\Client;
use App\Exceptions\MetadataFailedException;
use App\Models\Casting;
use Illuminate\Support\Collection;

/**
 * Works out which real-world vehicle a casting is a model of.
 *
 * Step 3 of the pipeline in CLAUDE.md, and the one thing standing between the
 * imported catalogue and a browsable one: the wiki names a tool but never states
 * the Make/Model split in a structured column, so every imported casting arrives
 * unidentified and Casting::approvalBlocker() holds it — and its releases and
 * variations — back.
 *
 * Its only job is the attribution. It does not write anything, decide what is
 * published, or resolve names against rows in the catalogue; CastingIdentifier
 * does all three. Keeping the call separate from the write is what lets the
 * whole inference be tested against a fake transporter with no database
 * decisions tangled into the assertions.
 *
 * Two things about the request are load-bearing:
 *
 * - **It asks about castings in batches.** 241 castings is 241 round trips one
 *   at a time, and the full wiki is 11,041 — while a batch shares the cached
 *   system prompt and answers in one pass.
 * - **Answers are matched back by id, never by position.** A batch is the one
 *   place a language model can quietly misalign an answer, and a misaligned
 *   answer here is not a missing attribution but a wrong one: Bone Shaker filed
 *   as a Pontiac GTO, published on the GTO's confidence score. Both the id and
 *   the echoed name are checked.
 */
class MetadataAgent
{
    /**
     * The scored fields, in the order the schema lists them. Kept next to the
     * code that reads the response so a schema edit that isn't mirrored here
     * fails a test rather than silently dropping a score.
     *
     * @var list<string>
     */
    public const FIELDS = ['kind', 'make', 'model', 'generation'];

    public const SCHEMA_VERSION = 2;

    /** @see OcrAgent::FALLBACK_BETA — the scalar form's header, not the array form's. */
    public const FALLBACK_BETA = OcrAgent::FALLBACK_BETA;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You identify which real-world vehicle a die-cast model is a model of.

        You are given the names of castings — the tools a toy manufacturer cuts — as a catalogue records them. For each one, say whether it depicts a real vehicle, and if so which.

        Answer with one of three kinds:
        - "real" — a model of a real production or racing vehicle. A customised, chopped, race-bodied or otherwise modified version of a real car is still that car: a Mustang funny car is a Ford Mustang.
        - "fantasy" — an original design by the toy manufacturer with no real-world counterpart. Bone Shaker, Twin Mill and Rodger Dodger are fantasy castings; so is most of what sounds like a name rather than a vehicle.
        - "unknown" — the name does not identify a specific real vehicle and you cannot tell which of the other two it is.

        "unknown" is a real answer, and the one to reach for whenever you are reasoning towards a car rather than recognising one. What you report is published as fact about a physical object a collector owns, and nothing looks at it again afterwards; a casting left unknown stays in a queue for a human, which costs nothing but time. Do not resolve a vague name by picking the most likely vehicle.

        How these names are written, and what it means for you:
        - The manufacturer is often left out, because the nameplate alone is famous enough. "'70 Monte Carlo" is a Chevrolet Monte Carlo. Supply the manufacturer the name assumes.
        - Abbreviations and nicknames are common. "'63 T-Bird" is a Ford Thunderbird. Expand them — this step exists to normalise names, and a later step matches on what you return.
        - A leading apostrophe-year is the vehicle's model year, not part of the nameplate. "'64 Pontiac GTO" has make Pontiac and model GTO.
        - Words describing the toy rather than the car — Custom, Tooned, Super, and the like — are not part of the nameplate.

        Field notes:
        - make is the real-world vehicle manufacturer, never the toy brand. Write it as the manufacturer normally writes it: Chevrolet, not Chevy; Volkswagen, not VW; Mercedes-Benz, not Mercedes. Where the manufacturer named in the catalogue list you are given is the same one, reuse that spelling exactly, so the catalogue does not end up with two rows for one company.
        - model is the nameplate on its own — "Corvette", never "Corvette C3". The generation goes in the three fields below.

        Then say which generation of that nameplate the casting depicts. A nameplate is not one vehicle: a Corvette is eight quite different cars, and filing them together makes the catalogue unable to tell a 1963 split-window from a 2011 Grand Sport. This is the whole reason the field exists.

        The model year in the casting name is what pins it down, and most names carry one. "'69 Corvette" is a 1969 car, and the generation containing 1969 is the C3 — so chassis_code "C3", generation_start_year 1968, generation_end_year 1982. Report the generation's own span, not the year in the name: the start year for that casting is 1968, not 1969.

        - chassis_code is the manufacturer's designation for the generation where one is in common use — C3, FD3S, R32, S197, E30. Many nameplates have none; that is normal, not a failure, and the year range still identifies the generation. Never invent a code.
        - generation_start_year and generation_end_year are the generation's first and last model years. Leave the end year null if the generation is still in production. If the name carries no year and nothing else in it identifies a generation, leave all three null — that is the honest answer, and the catalogue records the nameplate without a generation rather than guessing one.

        Score each field between 0 and 1: how sure you are of that specific value. The generation score covers the code and the years together, since they are one claim. Score any field you reported as null at 0. Spread the scores — a name you recognise outright and a name you reasoned your way to should not both come back at 0.9, because a reviewer uses these to decide what to check by hand.
        PROMPT;

    public function __construct(private readonly Client $client) {}

    /**
     * @param  Collection<int, Casting>  $castings  One batch's worth.
     * @param  list<string>  $knownMakes  Manufacturers already in the catalogue,
     *                                    so the model reuses a spelling rather
     *                                    than adding "Chevy" beside "Chevrolet".
     * @return array<int, CastingIdentity> Keyed by casting id.
     *
     * @throws MetadataFailedException
     */
    public function identify(Collection $castings, array $knownMakes = []): array
    {
        if ($castings->isEmpty()) {
            return [];
        }

        $fallbacks = config('catalog.metadata.fallbacks') ?: null;

        $message = $this->client->beta->messages->create(
            model: config('catalog.metadata.model'),
            maxTokens: config('catalog.metadata.max_tokens'),
            system: [[
                'type' => 'text',
                'text' => self::SYSTEM_PROMPT,
                // The one part of the request that is identical on every batch,
                // so the only part worth caching. The casting list and the known
                // makes change every call and sit after this block.
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [['role' => 'user', 'content' => $this->prompt($castings, $knownMakes)]],
            outputConfig: [
                'effort' => config('catalog.metadata.effort'),
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
            fallbacks: $fallbacks,
            betas: $fallbacks === null ? null : [self::FALLBACK_BETA],
        );

        // Checked before touching content, for the same reason as in OcrAgent: a
        // declined request is an HTTP 200 with empty or partial content, so
        // reading content[0] first turns a refusal into a type error.
        if ($message->stopReason === 'refusal') {
            $category = isset($message->stopDetails) ? $message->stopDetails->category : null;

            throw new MetadataFailedException(
                'The model declined to identify this batch'.($category ? " ({$category})" : '').'.'
            );
        }

        if ($message->stopReason === 'max_tokens') {
            throw new MetadataFailedException(
                'The model ran out of output tokens before finishing the batch. '
                .'Lower METADATA_BATCH_SIZE, raise METADATA_MAX_TOKENS, or lower METADATA_EFFORT.'
            );
        }

        return $this->decode($message->content, $castings);
    }

    /**
     * The schema, read from the file the model is also given, so there is
     * exactly one definition of this shape in the project.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return json_decode(file_get_contents(self::schemaPath()), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function schemaPath(): string
    {
        return app_path('AI/schemas/casting_identity.json');
    }

    /**
     * @param  Collection<int, Casting>  $castings
     * @param  list<string>  $knownMakes
     */
    private function prompt(Collection $castings, array $knownMakes): string
    {
        $list = $castings
            ->map(fn (Casting $casting): string => "{$casting->getKey()}\t{$casting->name}")
            ->implode("\n");

        // Only the name is given. It is the whole of what the source states about
        // which car this is — the release years and series a casting was sold
        // under describe the toy's marketing, not the vehicle, and offering them
        // as evidence invites reasoning from the wave a car shipped in to what
        // car it is.
        $prompt = "Identify each of these castings. One line per casting, as id then a tab then the name:\n\n{$list}\n\n"
            .'Return exactly one entry per line above, copying casting_id and casting_name back unchanged.';

        if ($knownMakes !== []) {
            $prompt .= "\n\nManufacturers already in the catalogue, for spelling — reuse one of these where you mean the same company, and write any other manufacturer the way it normally writes itself:\n"
                .implode(', ', $knownMakes);
        }

        return $prompt;
    }

    /**
     * @param  array<int, object>  $content
     * @param  Collection<int, Casting>  $castings
     * @return array<int, CastingIdentity>
     *
     * @throws MetadataFailedException
     */
    private function decode(array $content, Collection $castings): array
    {
        foreach ($content as $block) {
            if ($block->type !== 'text') {
                continue;
            }

            $decoded = json_decode($block->text, true);

            if (! is_array($decoded)) {
                throw new MetadataFailedException('The model returned text that is not JSON.');
            }

            return $this->validated($decoded, $castings);
        }

        throw new MetadataFailedException('The model returned no text block to read.');
    }

    /**
     * Turns the response into identifications keyed by casting, dropping any
     * answer that cannot be tied to a casting that was asked about.
     *
     * Structured outputs guarantee the shape, so nothing here re-checks that.
     * What they cannot guarantee is that the answers are about the right cars,
     * and that is the whole risk of batching — so an id that wasn't in the batch,
     * or a name that doesn't match the one sent under that id, is dropped rather
     * than written. A dropped answer leaves its casting unidentified, which is
     * the safe direction; a misfiled one publishes the wrong car.
     *
     * @param  array<string, mixed>  $output
     * @param  Collection<int, Casting>  $castings
     * @return array<int, CastingIdentity>
     *
     * @throws MetadataFailedException
     */
    private function validated(array $output, Collection $castings): array
    {
        if (($output['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new MetadataFailedException('The model returned an unexpected schema version.');
        }

        if (! is_array($output['identifications'] ?? null)) {
            throw new MetadataFailedException('The model returned no identifications array.');
        }

        $asked = $castings->keyBy(fn (Casting $casting): int => $casting->getKey());
        $identities = [];

        foreach ($output['identifications'] as $row) {
            if (! is_array($row) || ! isset($row['casting_id'])) {
                continue;
            }

            $casting = $asked->get((int) $row['casting_id']);

            if ($casting === null || trim((string) ($row['casting_name'] ?? '')) !== trim($casting->name)) {
                continue;
            }

            $identity = CastingIdentity::fromArray($row);

            foreach ($identity->confidence as $field => $score) {
                // The API's schema subset can't express a numeric bound, so this
                // is the one part of the shape structured outputs don't cover.
                if ($score < 0 || $score > 1) {
                    throw new MetadataFailedException(
                        "The model scored '{$field}' outside 0 to 1 for casting {$identity->castingId}."
                    );
                }
            }

            $identities[$identity->castingId] = $identity;
        }

        return $identities;
    }
}
