<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Services\Import\ImportSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes a parsed casting article into the catalog.
 *
 * Every record it creates gets a `record_sources` row keyed to the thing on the
 * page it came from, which is what makes a second run over the same dump update
 * the catalog rather than duplicate it — the key is the identity, not a name
 * match. It is also the CC-BY-SA attribution the public catalog owes back to the
 * page.
 *
 * What goes live and what waits is decided by what the wiki actually states.
 * Brand and Series it states outright, so those publish. A Casting it names but
 * cannot attribute to a real make and model, and `Casting::approvalBlocker()`
 * refuses to publish one of those — so castings, and everything hanging off
 * them, land pending. Resolving that attribution is an inference, and inferences
 * go through review.
 */
final class CatalogImporter
{
    public function __construct(
        private readonly string $source = RecordSource::SOURCE_HOT_WHEELS_FANDOM,
    ) {}

    /**
     * Imports one casting article, in a transaction so a page either lands whole
     * or not at all — a half-imported casting is worse than an absent one,
     * because its provenance says it was done.
     */
    public function import(CastingPage $page): ImportSummary
    {
        $summary = new ImportSummary;

        if ($page->importableVersions() === []) {
            $summary->problem("{$page->title}: no importable version rows");

            return $summary;
        }

        DB::transaction(function () use ($page, $summary): void {
            $casting = $this->casting($page, $summary);
            $versions = $this->castingVersions($page, $casting, $summary);

            foreach ($this->groupIntoReleases($page) as $key => $rows) {
                $release = $this->release($page, $rows, $versions, $key, $summary);

                foreach ($rows as $row) {
                    $this->variation($page, $row, $release, $summary);
                }
            }
        });

        return $summary;
    }

    /**
     * The page's rows gathered into the releases they describe, keyed by release
     * identity and in page order.
     *
     * Grouped up front rather than release-by-release as rows arrive, because a
     * release takes its collector number from whichever of its rows states one —
     * the wiki leaves the cell blank on some rows of a group and fills it on
     * others, and taking the first row's would throw the number away whenever it
     * happened to be one of the blanks.
     *
     * @return array<string, list<VersionRow>>
     */
    private function groupIntoReleases(CastingPage $page): array
    {
        $groups = [];

        foreach ($page->importableVersions() as $row) {
            $groups[$this->releaseKey($page, $row)][] = $row;
        }

        return $groups;
    }

    /**
     * The tool itself.
     *
     * Created unidentified — a real car whose VehicleModel nobody has matched —
     * rather than fantasy. The wiki never states which real vehicle a casting is
     * based on in a structured field, and `is_fantasy` is a positive assertion
     * that there is no real car behind it. Guessing it here would publish
     * "Danicar is a made-up vehicle" as a fact on the strength of a missing
     * column.
     */
    private function casting(CastingPage $page, ImportSummary $summary): Casting
    {
        return $this->upsert(Casting::class, $page->sourceKey(), $page->sourceUrl(), [
            'name' => $page->name,
            'is_fantasy' => false,
        ], $page->revisionId, $summary, 'castings');
    }

    /**
     * One CastingVersion per tool the page describes, keyed by the label it
     * describes them under.
     *
     * Nothing creates version 1 automatically, so this is where it happens. Most
     * pages describe one tool and get one unlabelled version; a page filing its
     * releases under "Closed Hood Versions" and "Exposed Engine Versions" is
     * describing two physical tools and gets one each, in page order.
     *
     * @return array<string, CastingVersion>
     */
    private function castingVersions(CastingPage $page, Casting $casting, ImportSummary $summary): array
    {
        $versions = [];
        $number = 0;

        foreach ($page->toolingLabels() as $label) {
            $number++;

            // The first tool is not a retool, so it has no retool year. A later
            // one first appears in the earliest year its rows state, which is a
            // fact the page gives rather than a guess.
            $retoolYear = null;

            if ($number > 1) {
                $years = array_map(
                    static fn (VersionRow $row): int => (int) $row->year,
                    array_filter(
                        $page->importableVersions(),
                        static fn (VersionRow $row): bool => $row->toolingLabel === $label,
                    ),
                );

                $retoolYear = $years === [] ? null : min($years);
            }

            $versions[$label ?? ''] = $this->upsert(
                CastingVersion::class,
                "{$page->sourceKey()}:tooling:{$number}",
                $page->sourceUrl(),
                [
                    'casting_id' => $casting->getKey(),
                    'version_number' => $number,
                    'retool_year' => $retoolYear,
                    'notes' => $label,
                ],
                $page->revisionId,
                $summary,
                'casting_versions',
            );
        }

        return $versions;
    }

    /**
     * The series a row was sold under, shared across every casting that names it.
     *
     * Keyed by the series' own wiki article rather than by the page being
     * imported: "2011 New Models" is one series that a hundred casting pages
     * link to, and keying it per page would make a hundred of them. Where the
     * cell is a bare string with no link, the name is the best identity
     * available and is used as one.
     */
    private function series(VersionRow $row, Brand $brand, ImportSummary $summary): Series
    {
        $key = $row->seriesPage !== null
            ? 'series:'.$this->keyPart($row->seriesPage)
            : 'series:name:'.$this->keyPart((string) $row->seriesName);

        $url = $row->seriesPage !== null
            ? $this->articleUrl($row->seriesPage)
            : 'https://hotwheels.fandom.com/wiki/Special:Search?query='.rawurlencode((string) $row->seriesName);

        $series = $this->upsert(Series::class, $key, $url, [
            'brand_id' => $brand->getKey(),
            'name' => $row->seriesName,
        ], null, $summary, 'series');

        // Stated outright by the source, and its only parent is a brand that is
        // already live, so there is nothing left to review.
        if (! $series->isLive()) {
            $series->markLive();
        }

        return $series;
    }

    /**
     * A year this tooling was sold under a series. Several table rows collapse
     * into one release — four colours of the same 2011 New Models car are four
     * variations of a single release, not four releases.
     */
    /**
     * @param  list<VersionRow>  $rows
     * @param  array<string, CastingVersion>  $versions
     */
    private function release(
        CastingPage $page,
        array $rows,
        array $versions,
        string $key,
        ImportSummary $summary,
    ): Release {
        $first = $rows[0];
        $series = $this->series($first, $this->brand(), $summary);

        // Whichever row states one. The wiki fills the cell on some rows of a
        // release and leaves it blank on others, and they agree when they are
        // both filled.
        $collectorNumber = null;

        foreach ($rows as $row) {
            $collectorNumber ??= $row->collectorNumber;
        }

        return $this->upsert(Release::class, $key, $page->sourceUrl(), [
            'casting_version_id' => $versions[$first->toolingLabel ?? '']->getKey(),
            'series_id' => $series->getKey(),
            'year' => $first->year,
            'collector_number' => $collectorNumber,
        ], $page->revisionId, $summary, 'releases');
    }

    /**
     * The physical car. One per table row — this is the level the row's
     * provenance key names.
     *
     * The duplicate check is done here rather than by letting the uniqueness
     * index reject the insert. Postgres aborts the whole transaction on a failed
     * statement, so catching the constraint violation and carrying on would take
     * down the rest of the page with it — and the pages this happens on are
     * ordinary ones, five rows in a 1,183-row sample, not broken ones.
     */
    private function variation(CastingPage $page, VersionRow $row, Release $release, ImportSummary $summary): void
    {
        $attributes = [
            'release_id' => $release->getKey(),
            ...$row->variationAttributes(),
            'toy_number' => $row->toyNumber,
            'country' => $row->country,
        ];

        $key = RecordSource::rowKey($page->sourceKey(), $row->line);

        // A row this source already owns is an update, however it now reads.
        // Only a row the importer has never seen can be a duplicate of one.
        if ($this->recordFor($key) === null && $this->collidesWithSibling($release, $attributes)) {
            // Worth wording carefully, because two different things end up here.
            // Some pages genuinely list a car twice. Others describe a real
            // sub-variant — Pass'n Gasser has nine — whose only distinguishing
            // fact is written in the Notes column: "short-run small Goodyear
            // tampo on rear wheels", identical in every structured column. Notes
            // is the editors' prose and is not ours to import, so the catalog
            // records the car it can describe and does not invent a distinction
            // from a field it never read.
            $summary->problem(
                "{$page->title}: row {$row->line} is identical to a row already imported in every column this importer reads"
            );

            return;
        }

        $this->upsert(
            Variation::class,
            $key,
            $page->sourceUrl(),
            $attributes,
            $page->revisionId,
            $summary,
            'variations',
        );
    }

    /**
     * Whether a variation identical on every distinguishing column is already
     * stored against this release.
     *
     * Compared through `attributeSignature()`, which builds the same
     * COALESCE(col, '') identity the database's unique index does — so this
     * agrees with the constraint by construction rather than by both being
     * maintained to match.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function collidesWithSibling(Release $release, array $attributes): bool
    {
        $signature = (new Variation)->forceFill($attributes)->attributeSignature();

        return $release->variations()
            ->get()
            ->contains(fn (Variation $existing): bool => $existing->attributeSignature() === $signature);
    }

    /**
     * The release's identity within the page: this tool, this series, this year.
     *
     * Not the row number. Rows collapse into releases many-to-one, so keying a
     * release by the first row that produced it would move the key whenever an
     * editor reordered the table.
     */
    private function releaseKey(CastingPage $page, VersionRow $row): string
    {
        $series = $this->keyPart($row->seriesPage ?? (string) $row->seriesName);
        $tooling = $this->keyPart($row->toolingLabel ?? 'main');

        return "{$page->sourceKey()}:release:{$tooling}:{$series}:{$row->year}";
    }

    /**
     * A title made safe to put in a key without making it ambiguous.
     *
     * Deliberately not `Str::slug()`: slugging drops the punctuation that tells
     * "Muscle Mania (2012)" from "Muscle Mania 2012", and two series sharing a
     * key would have the importer overwrite one with the other.
     */
    private function keyPart(string $title): string
    {
        return str_replace(' ', '_', trim($title));
    }

    /**
     * Every series this source produces belongs to Hot Wheels — the wiki covers
     * one brand, so the brand is a property of the source rather than something
     * read off a page. It carries no provenance row for that reason: no
     * particular article states it.
     */
    private function brand(): Brand
    {
        $brand = Brand::firstOrCreate(['name' => 'Hot Wheels'], ['parent_company' => 'Mattel']);

        if (! $brand->isLive()) {
            $brand->markLive();
        }

        return $brand;
    }

    /**
     * Creates the record or updates the one this key already produced, and
     * stamps its provenance either way.
     *
     * The lookup is by source key, never by name. A casting renamed on the wiki
     * has to update the row it already owns rather than create a second one
     * beside it, and a name match would do exactly the wrong thing in both
     * directions — missing the rename, and merging two castings that happen to
     * share a name.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function upsert(
        string $model,
        string $sourceKey,
        string $sourceUrl,
        array $attributes,
        ?int $revisionId,
        ImportSummary $summary,
        string $counter,
    ): Model {
        $record = $this->recordFor($sourceKey);

        if ($record instanceof $model) {
            // `status` is not fillable on any of these models, so an update can
            // never republish a record an admin rejected.
            $record->fill($attributes)->save();
            $summary->record("{$counter}.updated");
        } else {
            $record = $model::create($attributes);
            $summary->record("{$counter}.created");
        }

        RecordSource::updateOrCreate(
            ['source' => $this->source, 'source_key' => $sourceKey],
            [
                'sourceable_type' => $record->getMorphClass(),
                'sourceable_id' => $record->getKey(),
                'source_url' => $sourceUrl,
                'source_revision_id' => $revisionId,
                'imported_at' => now(),
            ],
        );

        return $record;
    }

    /**
     * The catalog record a previous run stored under this key, if any.
     */
    private function recordFor(string $sourceKey): ?Model
    {
        return RecordSource::query()
            ->where('source', $this->source)
            ->where('source_key', $sourceKey)
            ->first()
            ?->sourceable;
    }

    private function articleUrl(string $title): string
    {
        return 'https://hotwheels.fandom.com/wiki/'.str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $title)));
    }
}
