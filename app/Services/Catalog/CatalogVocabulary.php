<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Make;
use App\Models\Series;
use App\Models\VehicleModel;
use Illuminate\Support\Str;

/**
 * The names already in the catalog, offered as choices without becoming the
 * only choices.
 *
 * Every field these fill is free text that `ManualCatalogEntry` resolves by an
 * exact, case-insensitive name: a hit reuses the row, a miss makes a new one.
 * So the lists here are purely a way of landing on the existing spelling —
 * typing "factory fresh" when the catalog says "Factory Fresh" is the same
 * series either way, and typing something genuinely new is how the catalog
 * grows. They are rendered as `<datalist>`, which is a suggestion list on a
 * plain text input and needs no JavaScript, so nothing breaks the CSP and
 * nothing stops a new name being typed.
 *
 * Scoped to one brand where it can be, and capped, because the full import is
 * ~6,500 series and a page carrying all of them would be megabytes on a phone.
 * A series missing from the list costs nothing: type it and it is found.
 */
final class CatalogVocabulary
{
    /** Enough to cover a brand's real vocabulary without weighing the page down. */
    public const LIMIT = 400;

    /**
     * @return array{brands: list<string>, lines: list<string>, series: array<string, string>, makes: list<string>, models: array<string, string>}
     */
    public static function for(?string $brand = null): array
    {
        $brandId = $brand === null
            ? null
            : Brand::query()->whereRaw('LOWER(name) = LOWER(?)', [trim($brand)])->value('id');

        return [
            'brands' => Brand::query()->orderBy('name')->pluck('name')->all(),
            'lines' => self::lines($brand, $brandId),
            'series' => self::series($brandId),
            'makes' => Make::query()->orderBy('name')->pluck('name')->all(),
            'models' => self::models(),
        ];
    }

    /**
     * The editorial line list for the brand, plus any line already in the
     * database. `config/catalog_lines.php` is the authority — it is what
     * `catalog:classify-series` files imported series under — so offering it
     * keeps a hand-entered car in the same lines as an imported one, even on a
     * database where the classifier has not been run yet.
     *
     * @return list<string>
     */
    private static function lines(?string $brand, ?int $brandId): array
    {
        // One brand's lines when the card said which brand; every brand's when
        // it did not, because a list of nothing is worse than a longer list.
        $wikis = $brand === null
            ? array_filter((array) config('catalog_lines'), 'is_array')
            : [(array) config('catalog_lines.'.Str::slug($brand), [])];

        $configured = [];

        foreach ($wikis as $lines) {
            foreach ($lines as $line) {
                if (is_array($line) && isset($line['name'])) {
                    $configured[] = $line;
                }
            }
        }

        $existing = Series::query()
            ->whereNull('parent_id')
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            // A line is a series other series sit under. Without this, a
            // database where the classifier has not run offers all 1,857
            // series as lines, which is no help at all.
            ->whereIn('id', Series::query()->whereNotNull('parent_id')->select('parent_id'))
            ->orderBy('name')
            ->pluck('name');

        return collect(array_column($configured, 'name'))
            ->merge([config('catalog_lines.other')])
            ->merge($existing)
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->values()
            ->all();
    }

    /**
     * Sub-series names, each with the line it already sits under, commonest
     * first so the cap keeps the ones most likely to be wanted.
     *
     * @return array<string, string>
     */
    private static function series(?int $brandId): array
    {
        return Series::query()
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->withCount('releases')
            ->with('parent:id,name')
            ->orderByDesc('releases_count')
            ->orderBy('name')
            ->limit(self::LIMIT * 2)
            ->get(['id', 'name', 'parent_id'])
            ->reduce(function (array $carry, Series $series): array {
                // Several rows share a name — the wiki links each year's
                // mainline to its own anchor — and they are one choice here.
                $carry[$series->name] ??= $series->parent?->name ?? '';

                return $carry;
            }, []);
    }

    /**
     * @return array<string, string>
     */
    private static function models(): array
    {
        return VehicleModel::query()
            ->with('make:id,name')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'make_id'])
            ->reduce(function (array $carry, VehicleModel $model): array {
                $carry[$model->name] ??= $model->make?->name ?? '';

                return $carry;
            }, []);
    }
}
