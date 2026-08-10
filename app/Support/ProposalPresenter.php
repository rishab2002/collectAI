<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Renders a proposed catalog record as the three things a reviewer needs: what
 * kind of thing it is, what it's called, and the fields that would go live.
 *
 * Deliberately not methods on the models. These eight classes are the pipeline's
 * write target, and how a row reads to a human is a property of the screen
 * reading it, not of the row — the public catalog and the search index will each
 * want a different summary of the same Release. Putting the first one on the
 * model means the second either reuses a label built for another audience or
 * adds a competing one beside it.
 */
final class ProposalPresenter
{
    /**
     * @param  array<string, string>  $details  Label => value, already stripped
     *                                          of anything the record left null.
     */
    private function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly array $details,
    ) {}

    public static function for(Model $record): self
    {
        return match (true) {
            $record instanceof Make => new self('Make', $record->name, []),

            $record instanceof Brand => new self('Brand', $record->name, self::compact([
                'Parent company' => $record->parent_company,
            ])),

            $record instanceof VehicleModel => new self('Model', $record->fullName(), self::compact([
                'Make' => $record->make?->name,
                // One row, one generation — so the generation belongs in the
                // title, and this line spells out what the label means for a
                // reviewer who doesn't read chassis codes fluently.
                'Generation' => $record->generationLabel() ?? 'Not established',
            ])),

            $record instanceof Casting => new self('Casting', $record->name, self::compact([
                // The three states of castings.model_id read very differently to
                // a reviewer, and only one of them is a problem — so say which.
                'Real car' => match (true) {
                    $record->isFantasy() => 'Fantasy casting — no real vehicle',
                    $record->isUnidentified() => 'Not identified yet',
                    default => trim(($record->make?->name ?? '').' '.($record->model?->fullName() ?? '')),
                },
                'Notes' => $record->notes,
            ])),

            $record instanceof CastingVersion => new self(
                'Casting version',
                trim(($record->casting?->name ?? 'Casting')." v{$record->version_number}"),
                self::compact([
                    'Casting' => $record->casting?->name,
                    'Retooled' => $record->retool_year,
                    'Notes' => $record->notes,
                ]),
            ),

            $record instanceof Series => new self('Series', $record->name, self::compact([
                'Brand' => $record->brand?->name,
            ])),

            $record instanceof Release => new self(
                'Release',
                trim($record->year.' '.($record->castingVersion?->casting?->name ?? '')),
                self::compact([
                    'Series' => $record->series?->name,
                    // Shown explicitly because a release's brand is reached
                    // through its series, and a cross-brand release is legal —
                    // so it is exactly the field worth double-checking.
                    'Brand' => $record->series?->brand?->name,
                    'Year' => $record->year,
                    'Collector number' => $record->collector_number,
                ]),
            ),

            $record instanceof Variation => new self(
                'Variation',
                self::variationTitle($record),
                self::compact([
                    ...collect(Variation::DISTINGUISHING_ATTRIBUTES)
                        ->mapWithKeys(fn (string $attribute): array => [
                            Str::headline($attribute) => $record->{$attribute},
                        ])
                        ->all(),
                    'Notes' => $record->notes,
                ]),
            ),

            // Not a match arm's default for a closed set of eight: a ninth
            // catalog table would silently render as a bare class name here
            // rather than being noticed.
            default => new self(
                Str::headline(class_basename($record)),
                (string) ($record->name ?? "#{$record->getKey()}"),
                [],
            ),
        };
    }

    /**
     * The attributes that actually distinguish this variation, in the order the
     * DB's uniqueness index lists them. A variation with none set is the plain
     * one — legitimate, and worth naming rather than rendering as blank.
     */
    private static function variationTitle(Variation $variation): string
    {
        $set = collect(Variation::DISTINGUISHING_ATTRIBUTES)
            ->map(fn (string $attribute) => $variation->{$attribute})
            ->filter()
            ->implode(' · ');

        return $set !== '' ? $set : 'Base variation';
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    private static function compact(array $details): array
    {
        return collect($details)
            ->map(fn ($value): string => (string) $value)
            ->reject(fn (string $value): bool => trim($value) === '')
            ->all();
    }
}
