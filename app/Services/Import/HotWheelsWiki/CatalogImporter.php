<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes a parsed casting article into the catalog, once.
 *
 * Three rules shape everything here.
 *
 * Idempotence is the first. Every record is resolved before it is created —
 * first through record_sources on (source, source_key), then by its own natural
 * key — so a second run over the same page updates rather than duplicates, and
 * "add only genuinely missing records" holds even when the source has been
 * reordered underneath us.
 *
 * The second is that this imports facts and nothing else. Year, series, colour,
 * wheel type and toy number are printed in the wiki's structured columns, and go
 * live through here. The real make and model behind a casting are not: the wiki
 * states them only in prose, so deriving them is an inference and belongs behind
 * submission_proposals. That is why nothing in this class ever sets model_id.
 *
 * The third follows from the second and is the one worth expecting. An imported
 * casting has no model and isn't fantasy, so Casting::approvalBlocker() refuses
 * to publish it — and since a release hangs off a casting version which hangs
 * off that casting, the whole chain below it stays pending too. A year's import
 * therefore lands as live brands and series over a backfill queue of pending
 * castings, releases and variations. That is the settled intent: invisible rows
 * awaiting attribution, rather than a catalog full of cars nobody can search by
 * make or model.
 */
class CatalogImporter
{
    public const BRAND = 'Hot Wheels';

    public function __construct(private readonly ?float $threshold = null) {}

    /**
     * Imports the releases a casting page lists for one year.
     */
    public function import(ParsedCasting $parsed, int $year, ?ImportSummary $summary = null): ImportSummary
    {
        $summary ??= new ImportSummary;

        $releases = $parsed->releasesIn($year);

        if ($releases === []) {
            $summary->skip("{$parsed->title}: nothing listed for {$year}");

            return $summary;
        }

        DB::transaction(function () use ($parsed, $releases, $summary): void {
            $brand = $this->brand($summary);
            $casting = $this->casting($parsed, $summary);
            $version = $this->version($casting, $summary);

            foreach ($releases as $parsedRelease) {
                $series = $this->series($brand, $parsed, $parsedRelease, $summary);
                $release = $this->release($parsed, $version, $series, $parsedRelease, $summary);

                foreach ($parsedRelease->variations as $position => $parsedVariation) {
                    $this->variation($parsed, $release, $parsedRelease, $parsedVariation, $position, $summary);
                }
            }
        });

        return $summary;
    }

    /**
     * The wiki is Hot Wheels only, so every series it yields belongs to this one
     * brand. Other brands need other sources — that needing no schema change is
     * the whole point of the design.
     */
    private function brand(ImportSummary $summary): Brand
    {
        $brand = Brand::firstOrNew(['name' => self::BRAND]);

        if (! $brand->exists) {
            $brand->fill(['parent_company' => 'Mattel'])->save();
            $summary->created('brand');
        } else {
            $summary->unchanged('brand');
        }

        $this->publish($brand, $summary, 'brand');

        return $brand;
    }

    private function casting(ParsedCasting $parsed, ImportSummary $summary): Casting
    {
        $key = RecordSource::pageKey($parsed->pageId);

        $casting = $this->bySourceKey(Casting::class, $key)
            ?? Casting::where('name', $parsed->name)->first();

        if ($casting === null) {
            // model_id is left null and is_fantasy false on purpose. Together
            // those mean "a real car we haven't identified yet", which is
            // exactly true of an article whose make and model are only stated in
            // prose — and it is what keeps the row out of the public catalog
            // until someone attributes it.
            $casting = Casting::create(['name' => $parsed->name, 'is_fantasy' => false]);
            $summary->created('casting');
        } else {
            $summary->unchanged('casting');
        }

        $this->recordSource($casting, $key, $parsed);
        $this->publish($casting, $summary, 'casting');

        return $casting;
    }

    /**
     * Version 1, created explicitly because nothing creates one automatically —
     * a model hook would collide with CastingVersionFactory, which builds the
     * two separately. Releases point at a version rather than a casting, so
     * without this there is nothing for a release to attach to.
     *
     * Retools are deliberately not inferred. The wiki notes them in prose, and
     * reading "retooled in 2013" out of a Description would be exactly the kind
     * of inference this importer doesn't make.
     *
     * Given no provenance on purpose: a record_sources row asserts that a source
     * states this record, and the wiki states no such thing. Version 1 is a
     * structural requirement of our own schema — releases point at a version —
     * so attributing it to the wiki would be a false claim about where it came
     * from. Its parent casting carries the attribution.
     */
    private function version(Casting $casting, ImportSummary $summary): CastingVersion
    {
        $version = $casting->versions()->firstOrNew(['version_number' => 1]);

        if (! $version->exists) {
            $version->save();
            $summary->created('casting_version');
        } else {
            $summary->unchanged('casting_version');
        }

        $this->publish($version, $summary, 'casting_version');

        return $version;
    }

    /**
     * Series are resolved by name within the brand rather than by source key.
     *
     * They are shared across castings and years — every 2011 Nightburnerz car
     * points at one Series row — so keying them to the casting page that
     * happened to mention one first would make the second page create a
     * duplicate. Their provenance is keyed by the series instead, and the URL
     * recorded is whichever page introduced it: a series goes live, so it needs
     * an attribution like anything else the public catalog shows.
     */
    private function series(Brand $brand, ParsedCasting $parsed, ParsedRelease $parsedRelease, ImportSummary $summary): Series
    {
        $key = RecordSource::seriesKey($parsedRelease->seriesName);

        $series = Series::where('brand_id', $brand->getKey())
            ->where('name', $parsedRelease->seriesName)
            ->first();

        if ($series === null) {
            $series = Series::create([
                'brand_id' => $brand->getKey(),
                'name' => $parsedRelease->seriesName,
            ]);
            $summary->created('series');
        } else {
            $summary->unchanged('series');
        }

        if ($series->source === null) {
            $this->recordSource($series, $key, $parsed);
        }

        $this->publish($series, $summary, 'series');

        return $series;
    }

    private function release(
        ParsedCasting $parsed,
        CastingVersion $version,
        Series $series,
        ParsedRelease $parsedRelease,
        ImportSummary $summary,
    ): Release {
        $key = RecordSource::releaseKey($parsed->pageId, $parsedRelease->year, $parsedRelease->seriesIdentity());

        // The natural key is the table's own unique constraint, so resolving by
        // it is what stops the second run colliding rather than duplicating.
        $release = $this->bySourceKey(Release::class, $key) ?? Release::where([
            'casting_version_id' => $version->getKey(),
            'series_id' => $series->getKey(),
            'year' => $parsedRelease->year,
        ])->first();

        if ($release === null) {
            $release = Release::create([
                'casting_version_id' => $version->getKey(),
                'series_id' => $series->getKey(),
                'year' => $parsedRelease->year,
                'collector_number' => $parsedRelease->collectorNumber,
            ]);
            $summary->created('release');
        } elseif ($release->collector_number !== $parsedRelease->collectorNumber && $parsedRelease->collectorNumber !== null) {
            $release->update(['collector_number' => $parsedRelease->collectorNumber]);
            $summary->updated('release');
        } else {
            $summary->unchanged('release');
        }

        $this->recordSource($release, $key, $parsed);
        $this->publish($release, $summary, 'release');

        return $release;
    }

    private function variation(
        ParsedCasting $parsed,
        Release $release,
        ParsedRelease $parsedRelease,
        ParsedVariation $parsedVariation,
        int $position,
        ImportSummary $summary,
    ): Variation {
        $key = RecordSource::variationKey(
            $parsed->pageId,
            $parsedRelease->year,
            $parsedRelease->seriesIdentity(),
            $position,
        );

        $attributes = $parsedVariation->attributes();

        // Matched on the exact attribute set the uniqueness index covers, so a
        // re-import can't propose a row the database is about to reject. Done
        // before the source key is trusted because a page gaining a row shifts
        // the positions below it, and the attributes are what the variation
        // actually is.
        $variation = $this->matchingVariation($release, $attributes)
            ?? $this->bySourceKey(Variation::class, $key);

        if ($variation === null) {
            $variation = Variation::create(['release_id' => $release->getKey()] + $attributes);
            $summary->created('variation');
        } else {
            $summary->unchanged('variation');
        }

        $this->recordSource($variation, $key, $parsed);
        $this->publish($variation, $summary, 'variation');

        return $variation;
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function matchingVariation(Release $release, array $attributes): ?Variation
    {
        if (! $release->exists) {
            return null;
        }

        $wanted = implode('|', array_map(strval(...), $attributes));

        foreach ($release->variations as $variation) {
            if ($variation->attributeSignature() === $wanted) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $type
     */
    private function bySourceKey(string $type, string $key): ?Model
    {
        $source = RecordSource::where('source', RecordSource::SOURCE_HOT_WHEELS_FANDOM)
            ->where('source_key', $key)
            ->where('sourceable_type', $type)
            ->first();

        return $source?->sourceable;
    }

    /**
     * Writes or refreshes the provenance row.
     *
     * updateOrCreate on the sourceable is what makes a re-run move imported_at
     * and the revision forward without a second row — and the table's unique
     * constraint on the pair is what makes that a guarantee rather than a habit.
     */
    private function recordSource(Model $record, string $key, ParsedCasting $parsed): void
    {
        RecordSource::updateOrCreate(
            [
                'sourceable_type' => $record->getMorphClass(),
                'sourceable_id' => $record->getKey(),
            ],
            [
                'source' => RecordSource::SOURCE_HOT_WHEELS_FANDOM,
                'source_key' => $key,
                'source_url' => $parsed->url,
                'source_revision_id' => $parsed->revisionId,
                'imported_at' => now(),
            ],
        );
    }

    /**
     * Publishes a record if the import is trusted enough and nothing blocks it.
     *
     * The blocker check is the important half and is not a formality: it is what
     * holds imported castings — and everything hanging off them — pending until
     * a real make and model are attached. Checked rather than caught because
     * promoteWithDependencies() doesn't test blockers itself; only the review
     * path did, and a bulk writer that skipped the check would publish exactly
     * what the rule exists to keep back.
     */
    private function publish(Model $record, ImportSummary $summary, string $type): void
    {
        if ($record->isLive() || $this->confidence() < $this->threshold()) {
            return;
        }

        if ($record->approvalBlockers() !== []) {
            return;
        }

        $record->promoteWithDependencies();
        $summary->published($type);
    }

    /**
     * A structured column in a public catalog, read verbatim. There is no model
     * in the loop to be uncertain — either the parser read the cell or it
     * didn't — so anything short of 1.0 here would be theatre. The dial that
     * matters is the threshold, which an operator can raise above 1.0 to import
     * everything as pending.
     */
    private function confidence(): float
    {
        return 1.0;
    }

    private function threshold(): float
    {
        return $this->threshold ?? (float) config('catalog.import.auto_approve_threshold', 1.0);
    }
}
