<?php

namespace App\Services\AI;

/**
 * One casting, and what the Metadata Agent made of its name.
 *
 * A value object rather than the raw array so the two rules that turn a set of
 * per-field scores into a decision — which fields have to be present for an
 * answer to be usable, and what the proposal's single score is — live in one
 * place instead of being re-derived by the writer, the command and the tests.
 */
final readonly class CastingIdentity
{
    public const KIND_REAL = 'real';

    public const KIND_FANTASY = 'fantasy';

    public const KIND_UNKNOWN = 'unknown';

    /**
     * @param  array<string, float>  $confidence  Keyed by MetadataAgent::FIELDS.
     */
    public function __construct(
        public int $castingId,
        public string $castingName,
        public string $kind,
        public ?string $make,
        public ?string $model,
        public ?string $chassisCode,
        public ?int $generationStartYear,
        public ?int $generationEndYear,
        public array $confidence,
    ) {}

    /**
     * @param  array<string, mixed>  $row  One entry of the schema's `identifications`.
     */
    public static function fromArray(array $row): self
    {
        return new self(
            castingId: (int) $row['casting_id'],
            castingName: (string) $row['casting_name'],
            kind: (string) $row['kind'],
            make: self::trimmed($row['make']),
            model: self::trimmed($row['model']),
            chassisCode: self::trimmed($row['chassis_code']),
            generationStartYear: self::year($row['generation_start_year']),
            generationEndYear: self::year($row['generation_end_year']),
            confidence: array_map(
                static fn ($score): float => (float) $score,
                array_intersect_key($row['confidence'], array_flip(MetadataAgent::FIELDS)),
            ),
        );
    }

    /**
     * Whether this answer pins down a generation well enough to file the casting
     * under one.
     *
     * The start year is what makes it usable, not the chassis code: plenty of
     * nameplates have no manufacturer designation, and the year range identifies
     * the generation on its own. A code with no years is the reverse — a label
     * for a generation the catalogue can't place — so it isn't enough either.
     */
    public function hasGeneration(): bool
    {
        return $this->generationStartYear !== null;
    }

    /**
     * The generation as the catalogue stores it: code, first year, last year.
     *
     * Returned as one unit because it is one claim scored by one number. Taking
     * the code without the years — or vice versa — would file a casting under a
     * generation the agent never actually asserted.
     *
     * @return array{chassis_code: ?string, generation_start_year: ?int, generation_end_year: ?int}
     */
    public function generation(float $threshold): array
    {
        $keep = $this->hasGeneration() && $this->generationScore() >= $threshold;

        return [
            'chassis_code' => $keep ? $this->chassisCode : null,
            'generation_start_year' => $keep ? $this->generationStartYear : null,
            'generation_end_year' => $keep ? $this->generationEndYear : null,
        ];
    }

    public function isFantasy(): bool
    {
        return $this->kind === self::KIND_FANTASY;
    }

    /**
     * Whether this answer says enough to write down.
     *
     * A "real" verdict with no make or no model is not a partial answer, it is
     * the unknown verdict wearing the wrong label: castings.model_id cannot hold
     * half an attribution, and Casting::approvalBlocker() would refuse to
     * publish the result anyway. Treating it as unusable here is what keeps that
     * from becoming a row an admin is asked to rule on with nothing to rule on.
     */
    public function isUsable(): bool
    {
        return match ($this->kind) {
            self::KIND_FANTASY => true,
            self::KIND_REAL => $this->make !== null && $this->model !== null,
            default => false,
        };
    }

    /**
     * The single score the proposal carries, and the one the auto-approve
     * threshold is applied to.
     *
     * The minimum rather than an average, because approving publishes the make
     * and the model together: an attribution is exactly as trustworthy as its
     * weakest half, and averaging would let a certain "Ford" carry a guessed
     * nameplate over the line. A fantasy verdict has no make or model to score,
     * so the classification is the whole claim.
     */
    public function score(): float
    {
        return match (true) {
            $this->isFantasy() => $this->scoreFor('kind'),
            $this->isUsable() => min(
                $this->scoreFor('kind'),
                $this->scoreFor('make'),
                $this->scoreFor('model'),
            ),
            default => 0.0,
        };
    }

    /**
     * Scored separately from the identity because it is applied separately: a
     * generation below the bar is dropped while the make and model it belongs to
     * still go through, filing the casting against the bare nameplate. Folding
     * it into score() would hold back correct attributions over a generation
     * nobody could pin down.
     */
    public function generationScore(): float
    {
        return $this->hasGeneration() ? $this->scoreFor('generation') : 0.0;
    }

    private function scoreFor(string $field): float
    {
        return $this->confidence[$field] ?? 0.0;
    }

    /**
     * Four-digit model years only. The schema types these as integers, so this
     * guards the range rather than the type — a 69 where 1969 was meant would
     * otherwise become a generation nothing matches and a legend reading
     * "(69–82)".
     */
    private static function year(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1800 && $year <= 2100 ? $year : null;
    }

    private static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // An empty string is the model saying "nothing here" in the one way the
        // schema still permits once null is allowed, and a Make named '' would
        // take the hard-unique slot for every future blank.
        return trim($value) === '' ? null : trim($value);
    }
}
