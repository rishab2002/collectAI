<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\ReferenceItem;
use App\Models\ReferenceLink;
use App\Models\Release;
use App\Models\Series;
use App\Models\SubmissionProposal;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cars an admin types in by hand.
 *
 * The capture pipeline proposes and a human approves; here the human *is* the
 * reviewer, so what they enter is published on the spot — through
 * `promoteWithDependencies()`, the same walk approval uses, so nothing goes live
 * under an invisible parent. The one thing typing can't do is publish past a
 * blocker: a variation added to an imported casting nobody has identified yet
 * is saved and held, for the same reason approving it would be refused.
 *
 * Every lookup here is exact (case-insensitive), never fuzzy. A miss costs a
 * near-duplicate row an admin can see; a fuzzy hit files a car as a different
 * car. Fuzzy matching is the Comparison Agent's job.
 *
 * Records created here carry no `record_sources` row and no proposal, which is
 * exactly how the rest of the app recognises "an admin made this by hand".
 */
final class ManualCatalogEntry
{
    /**
     * A new casting, with its first release and variation.
     *
     * `$publish` false saves the car and queues it instead: the records stay
     * pending and a proposal joins the inferred-records queue, where it is
     * approved the same way anything else there is. Typing a car in is
     * normally its own review — the admin is the reviewer — but a car read
     * off a card at a glance is worth a second look before the public catalog
     * has it, and the queue is where second looks happen.
     *
     * @param  array<string, mixed>  $data  Validated by StoreCarRequest.
     */
    public function createCar(array $data, bool $publish = true): ManualEntryResult
    {
        return DB::transaction(function () use ($data, $publish): ManualEntryResult {
            $fantasy = (bool) ($data['is_fantasy'] ?? false);

            $casting = Casting::create([
                'name' => trim($data['casting_name']),
                'is_fantasy' => $fantasy,
                'model_id' => $fantasy ? null : $this->vehicleModel($data)->getKey(),
                'notes' => $this->blankToNull($data['casting_notes'] ?? null),
            ]);

            // Nothing creates version 1 automatically — see CLAUDE.md. A casting
            // without one has nowhere for a release to hang.
            $version = CastingVersion::create([
                'casting_id' => $casting->getKey(),
                'version_number' => 1,
            ]);

            $result = $this->addRelease($version, $data, $publish);
            $this->linkReference($data, $casting, $result);

            if (! $publish) {
                // One proposal, and it goes on the **variation** — the bottom
                // of the chain, not the casting at the top. Approval walks
                // upward only, which is settled, so approving the variation
                // publishes the make, model, casting, version, series and
                // release above it in one click, while approving the casting
                // would publish the casting and leave the actual car pending.
                SubmissionProposal::create([
                    'proposable_type' => $result->variation->getMorphClass(),
                    'proposable_id' => $result->variation->getKey(),
                ]);
            }

            return $result;
        });
    }

    /**
     * Another release or variation of a casting already in the catalog.
     *
     * @param  array<string, mixed>  $data  Validated by StoreVariationRequest.
     */
    public function addVariation(Casting $casting, array $data): ManualEntryResult
    {
        return DB::transaction(function () use ($casting, $data): ManualEntryResult {
            $version = isset($data['casting_version_id'])
                ? $casting->versions()->findOrFail($data['casting_version_id'])
                : $casting->versions()->orderByDesc('version_number')->first();

            // An imported casting always has one, but a casting built by a
            // factory or an older seeder might not.
            $version ??= CastingVersion::create([
                'casting_id' => $casting->getKey(),
                'version_number' => 1,
            ]);

            $result = $this->addRelease($version, $data);
            $this->linkReference($data, null, $result);

            return $result;
        });
    }

    /**
     * A car added from a reference product (Add a car → find the model) is
     * linked to it, under the key the visual model learned that product's
     * photos as — so the scanner can name it from the moment it exists.
     *
     * Only a new casting takes the product's casting key; an existing casting
     * already has its own. A key already linked to another record (the same
     * product added twice) stays with the first.
     *
     * @param  array<string, mixed>  $data
     */
    private function linkReference(array $data, ?Casting $newCasting, ManualEntryResult $result): void
    {
        $item = isset($data['reference_item_id']) ? ReferenceItem::find($data['reference_item_id']) : null;

        if ($item === null) {
            return;
        }

        $links = [[$result->variation, $item->variationKey()]];

        if ($newCasting !== null) {
            $links[] = [$newCasting, $item->castingKey()];
        }

        foreach ($links as [$record, $key]) {
            if (ReferenceLink::where('stable_key', $key)->exists()
                || ReferenceLink::where('linkable_type', $record->getMorphClass())->where('linkable_id', $record->getKey())->exists()) {
                continue;
            }

            ReferenceLink::create([
                'reference_item_id' => $item->id,
                'linkable_type' => $record->getMorphClass(),
                'linkable_id' => $record->getKey(),
                'stable_key' => $key,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addRelease(CastingVersion $version, array $data, bool $publish = true): ManualEntryResult
    {
        $series = $this->series($this->brand($data['brand']), $data['series'], $data['line'] ?? null);

        // The release's natural key is this tool, this series, this year — the
        // same unique index the importer relies on. A second colour of a car
        // already listed is a new variation of the existing release, not a
        // second release.
        $release = Release::firstOrCreate(
            [
                'casting_version_id' => $version->getKey(),
                'series_id' => $series->getKey(),
                'year' => (int) $data['year'],
            ],
            ['collector_number' => $this->blankToNull($data['collector_number'] ?? null)],
        );

        if ($release->collector_number === null && filled($data['collector_number'] ?? null)) {
            $release->update(['collector_number' => trim($data['collector_number'])]);
        }

        $attributes = ['release_id' => $release->getKey()];

        foreach (Variation::DISTINGUISHING_ATTRIBUTES as $attribute) {
            $attributes[$attribute] = $this->blankToNull($data[$attribute] ?? null);
        }

        $attributes['notes'] = $this->blankToNull($data['notes'] ?? null);

        $duplicate = $this->existingTwin($release, $attributes);

        if ($duplicate !== null) {
            // Checked here rather than left to the unique index: a failed insert
            // aborts the transaction and arrives as a 500, and the admin needs
            // to be told which car it already is.
            throw ValidationException::withMessages([
                'color' => "That car is already in the catalog as variation #{$duplicate->getKey()} — every column you filled in matches it.",
            ]);
        }

        $variation = Variation::create($attributes);

        $blockers = $variation->approvalBlockers();

        if ($publish && $blockers === []) {
            $variation->promoteWithDependencies();
        }

        return new ManualEntryResult($variation->refresh(), $blockers, queued: ! $publish);
    }

    /**
     * Compared through `attributeSignature()`, which builds the same
     * COALESCE(col, '') identity the database's unique index does.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function existingTwin(Release $release, array $attributes): ?Variation
    {
        $signature = (new Variation)->forceFill($attributes)->attributeSignature();

        return $release->variations()
            ->get()
            ->first(fn (Variation $existing): bool => $existing->attributeSignature() === $signature);
    }

    /**
     * `brands.name` is hard-unique, so this can only ever find one.
     */
    private function brand(string $name): Brand
    {
        $name = trim($name);

        return Brand::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first()
            ?? Brand::create(['name' => $name]);
    }

    /**
     * `series.name` deliberately has no unique constraint — near-duplicates are
     * expected — so an exact name can match more than one row. The live one is
     * preferred, then the oldest, so repeated entry keeps landing on the same
     * series rather than spreading across its twins.
     */
    private function series(Brand $brand, string $name, ?string $line = null): Series
    {
        $name = trim($name);
        $existing = $this->namedSeries($brand, $name);

        if ($existing !== null) {
            // Never moved: an existing series keeps the line it was placed in.
            return $existing;
        }

        $line = trim((string) $line);
        $parent = null;

        // A new series under a main line ("Car Culture" › "AutoStrasse"): the
        // line is a series with no parent, found or made, one level deep.
        if ($line !== '' && mb_strtolower($line) !== mb_strtolower($name)) {
            $parent = Series::query()->where('brand_id', $brand->getKey())->whereNull('parent_id')
                ->whereRaw('LOWER(name) = LOWER(?)', [$line])->orderBy('id')->first()
                ?? Series::create(['brand_id' => $brand->getKey(), 'name' => $line]);
        }

        $series = Series::create(['brand_id' => $brand->getKey(), 'name' => $name]);

        if ($parent !== null) {
            $series->forceFill(['parent_id' => $parent->getKey()])->save();
        }

        return $series;
    }

    private function namedSeries(Brand $brand, string $name): ?Series
    {
        return Series::query()
            ->where('brand_id', $brand->getKey())
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    /**
     * Keyed the way the Metadata Agent keys it — make, nameplate and the
     * generation's start year — so a hand-entered RX-7 lands on the same row
     * the pipeline would have chosen. A null start year is its own bucket, not a
     * wildcard: `= NULL` matches nothing in Postgres.
     *
     * @param  array<string, mixed>  $data
     */
    private function vehicleModel(array $data): VehicleModel
    {
        $makeName = trim($data['make']);
        $make = Make::query()->whereRaw('LOWER(name) = LOWER(?)', [$makeName])->first()
            ?? Make::create(['name' => $makeName]);

        $start = isset($data['generation_start_year']) ? (int) $data['generation_start_year'] : null;

        $model = VehicleModel::query()
            ->where('make_id', $make->getKey())
            ->whereRaw('LOWER(name) = LOWER(?)', [trim($data['model'])])
            ->when(
                $start === null,
                fn ($query) => $query->whereNull('generation_start_year'),
                fn ($query) => $query->where('generation_start_year', $start),
            )
            ->first();

        return $model ?? VehicleModel::create([
            'make_id' => $make->getKey(),
            'name' => trim($data['model']),
            // A generation is one claim — code plus years — and the request
            // only lets a code through alongside a start year. A code on a row
            // with no years would be stamped on every casting that later shares
            // the nameplate, which is the mislabelling the generation split
            // exists to prevent.
            'chassis_code' => $start === null ? null : $this->blankToNull($data['chassis_code'] ?? null),
            'generation_start_year' => $start,
            'generation_end_year' => $start === null || ! isset($data['generation_end_year'])
                ? null
                : (int) $data['generation_end_year'],
        ]);
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
