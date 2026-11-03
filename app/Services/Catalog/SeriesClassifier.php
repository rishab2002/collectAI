<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\RecordSource;
use App\Models\Series;
use App\Services\Import\FandomWiki\CastingPageParser;
use App\Services\Import\FandomWiki\PageSource;
use App\Services\Import\FandomWiki\Wiki;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Places each imported series under a main line, so the catalog can be browsed
 * brand → line → sub-series → car.
 *
 * The rules are editorial and live in config/catalog_lines.php; this applies
 * them. It reads each series' wiki article only for its categories — the same
 * facts-not-prose line the importer holds — and never touches a series an admin
 * already placed, unless asked to reclassify.
 */
final class SeriesClassifier
{
    /**
     * @param  array<int, array{name: string, names: list<string>, categories: list<string>}>|null  $lines
     */
    public function __construct(
        private readonly Wiki $wiki,
        private ?array $lines = null,
        private ?string $other = null,
    ) {
        $this->lines ??= config("catalog_lines.{$wiki->key}", []);
        $this->other ??= config('catalog_lines.other', 'Other');
    }

    /**
     * @return array{lines: array<string, int>, other_categories: array<string, int>, other_names: array<string, int>, placed: int, kept: int, already_lines: int}
     */
    public function classify(PageSource $source, bool $reclassify = false, bool $dryRun = false): array
    {
        $series = $this->importedSeries();
        $categories = $this->categories($source, $series->pluck('page')->filter()->unique()->values()->all());

        $report = ['lines' => [], 'other_categories' => [], 'other_names' => [], 'placed' => 0, 'kept' => 0, 'already_lines' => 0];

        DB::transaction(function () use ($series, $categories, $reclassify, $dryRun, &$report): void {
            $lineRows = [];

            foreach ($series as $row) {
                /** @var Series $model */
                $model = $row['series'];
                $cats = $row['page'] !== null ? ($categories[$this->normalize($row['page'])] ?? []) : [];
                $line = $this->lineFor($model->name, $cats, $row['numbered'], $row['releases']);

                // Weighted by releases: what matters is how much of the
                // catalog lands in a line, not how many series names do.
                $weight = max(1, (int) $row['releases']);
                $report['lines'][$line] = ($report['lines'][$line] ?? 0) + $weight;

                if ($line === $this->other) {
                    foreach ($cats as $category) {
                        $report['other_categories'][$category] = ($report['other_categories'][$category] ?? 0) + $weight;
                    }

                    $report['other_names'][$model->name] = $weight;
                }

                if ($dryRun) {
                    continue;
                }

                if ($model->parent_id !== null && ! $reclassify) {
                    $report['kept']++;

                    continue;
                }

                $lineRows[$line] ??= $this->lineRow($model->brand_id, $line);

                // A series named for its line *is* the line: "Mainline" is
                // the top of the tree, not a child called Mainline. But the wiki
                // links mainline cars to a different anchor each year, which made
                // 92 rows called "Mainline" — only the first is the line; the rest
                // hang under it, and the browse pages read a child that shares its
                // line's name as the line's own cars.
                if ($model->is($lineRows[$line])) {
                    if ($model->parent_id !== null) {
                        $model->forceFill(['parent_id' => null])->save();
                    }

                    $report['already_lines']++;

                    continue;
                }

                $model->forceFill(['parent_id' => $lineRows[$line]->getKey()])->save();
                $report['placed']++;
            }
        });

        arsort($report['lines']);
        arsort($report['other_categories']);
        arsort($report['other_names']);

        return $report;
    }

    /**
     * The first line whose name patterns or categories match, in config order;
     * then the numbering fallback; then Other.
     *
     * The fallback is a fact, not a guess: a mainline car carries its number in
     * the year's run ("032/250") and packs, sets and specials almost never do.
     * Measured on the 2026-10 dump, mainline segments are 75–100% numbered and
     * the series left over were 0–4%, so the bar sits in that gap.
     *
     * @param  list<string>  $categories
     */
    public function lineFor(string $seriesName, array $categories, int $numbered = 0, int $releases = 0): string
    {
        $categories = array_map('mb_strtolower', $categories);

        foreach ($this->lines as $line) {
            foreach ($line['names'] ?? [] as $pattern) {
                if (preg_match($pattern, $seriesName) === 1) {
                    return $line['name'];
                }
            }

            foreach ($line['categories'] ?? [] as $category) {
                if (in_array(mb_strtolower($category), $categories, true)) {
                    return $line['name'];
                }
            }
        }

        $fallback = config("catalog_lines.numbered_fallback.{$this->wiki->key}");

        if ($fallback !== null && $releases >= 3 && $numbered / $releases >= 0.7) {
            return $fallback;
        }

        return $this->other;
    }

    /**
     * Every series this wiki's import created, with the article it was keyed by
     * (null when the cell had no link) and how many releases it carries.
     *
     * @return Collection<int, array{series: Series, page: string|null, releases: int, numbered: int}>
     */
    private function importedSeries(): Collection
    {
        $sources = RecordSource::query()
            ->where('source', $this->wiki->source)
            ->where('sourceable_type', (new Series)->getMorphClass())
            ->get(['sourceable_id', 'source_key'])
            ->keyBy('sourceable_id');

        return Series::query()
            ->whereIn('id', $sources->keys())
            ->withCount(['releases', 'releases as numbered_count' => fn ($query) => $query->whereNotNull('collector_number')])
            ->get()
            ->map(function (Series $series) use ($sources): array {
                $key = $sources[$series->getKey()]->source_key;
                $page = str_starts_with($key, 'series:name:') ? null : substr($key, strlen('series:'));

                return [
                    'series' => $series,
                    // Keys keep the title as written, underscores for spaces;
                    // a section link still names the article that holds it.
                    'page' => $page === null ? null : trim(str_replace('_', ' ', explode('#', $page, 2)[0])),
                    'releases' => $series->releases_count,
                    'numbered' => $series->numbered_count,
                ];
            });
    }

    /**
     * Categories of each article, following one redirect — "Treasure Hunts"
     * redirects to "Treasure Hunt", and the categories are on the target.
     *
     * @param  list<string>  $titles
     * @return array<string, list<string>> keyed by normalised title
     */
    private function categories(PageSource $source, array $titles): array
    {
        $found = [];
        $redirects = [];

        foreach ($source->pages($titles) as $page) {
            if (($target = CastingPageParser::redirectTarget($page->wikitext)) !== null) {
                $redirects[$this->normalize($page->title)] = $target;

                continue;
            }

            $found[$this->normalize($page->title)] = $this->categoriesIn($page->wikitext);
        }

        $targets = array_values(array_unique(array_filter(
            $redirects,
            fn (string $target): bool => ! isset($found[$this->normalize($target)]),
        )));

        foreach ($targets === [] ? [] : $source->pages($targets) as $page) {
            $found[$this->normalize($page->title)] = $this->categoriesIn($page->wikitext);
        }

        foreach ($redirects as $from => $to) {
            $found[$from] = $found[$this->normalize($to)] ?? [];
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function categoriesIn(string $wikitext): array
    {
        preg_match_all('/\[\[\s*Category\s*:\s*([^\]|]+)/i', $wikitext, $matches);

        return array_values(array_unique(array_map('trim', $matches[1])));
    }

    /**
     * The top-level series row for a line — the brand's existing series of that
     * name if there is one (an imported "Mainline" or "Car Culture" is the line
     * itself), otherwise a new one. Lines are editorial groupings an admin
     * defined in config, so they are live from the start, like a brand.
     */
    private function lineRow(int $brandId, string $line): Series
    {
        $row = Series::query()
            ->where('brand_id', $brandId)
            ->whereNull('parent_id')
            ->whereRaw('LOWER(name) = LOWER(?)', [$line])
            ->orderBy('id')
            ->first()
            ?? Series::create(['brand_id' => $brandId, 'name' => $line]);

        if (! $row->isLive()) {
            $row->markLive();
        }

        return $row;
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $title)));
    }

    public function brand(): ?Brand
    {
        return Brand::query()->where('name', $this->wiki->brand)->first();
    }
}
