<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\Photo;
use App\Models\Series;
use App\Support\LineNames;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The public catalog's reads: brand → main line → sub-series → car.
 *
 * Live records only, which is the whole contract of the approval gate — unless
 * `$preview` is set, which only an admin can do, to see what the catalog will
 * look like once the pending backlog is identified and published. Every query
 * filters on the *release*: approval promotes a release's whole ancestor chain,
 * so a live release implies a live tool, series and brand above it.
 *
 * Sub-series are grouped by name rather than by row. The wiki links each
 * year's mainline to a different anchor, so one line can hold many rows with
 * one name; a collector picking "HW Art Cars" wants all of them.
 */
final class CatalogBrowser
{
    public function __construct(private readonly bool $preview = false) {}

    public function previewing(): bool
    {
        return $this->preview;
    }

    /**
     * Brands with something to show, biggest first.
     *
     * @return Collection<int, object{id: int, name: string, cars: int, first_year: int, last_year: int}>
     */
    public function brands(): Collection
    {
        return $this->releases()
            ->join('brands as b', 'b.id', '=', 's.brand_id')
            ->when(! $this->preview, fn (Builder $query) => $query->where('b.status', 'live'))
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('cars')
            ->get([
                'b.id', 'b.name',
                DB::raw('COUNT(DISTINCT cv.casting_id) as cars'),
                DB::raw('MIN(r.year) as first_year'),
                DB::raw('MAX(r.year) as last_year'),
            ]);
    }

    /**
     * A brand's main lines — its top-level series — with how many cars and
     * sub-series each holds. A top-level series nobody has classified (a
     * hand-entered "Mini GT", say) shows here too, holding its own cars.
     *
     * @return Collection<int, object{id: int, name: string, cars: int, subseries: int, first_year: int, last_year: int}>
     */
    public function lines(Brand $brand): Collection
    {
        return $this->releases()
            ->join('series as l', fn ($join) => $join->on('l.id', '=', 's.id')->orOn('l.id', '=', 's.parent_id'))
            ->whereNull('l.parent_id')
            ->where('l.brand_id', $brand->getKey())
            ->groupBy('l.id', 'l.name')
            ->orderByDesc('cars')
            ->get([
                'l.id', 'l.name',
                DB::raw('COUNT(DISTINCT cv.casting_id) as cars'),
                DB::raw('COUNT(DISTINCT CASE WHEN LOWER(s.name) <> LOWER(l.name)'
                    .' AND NOT ('.LineNames::sql('s.name').') THEN LOWER(s.name) END) as subseries'),
                DB::raw('MIN(r.year) as first_year'),
                DB::raw('MAX(r.year) as last_year'),
            ]);
    }

    /**
     * The sub-series in a line, grouped by name, newest first — optionally only
     * those sold in one year.
     *
     * @return Collection<int, object{name: string, cars: int, first_year: int, last_year: int}>
     */
    public function subSeries(Series $line, ?int $year = null): Collection
    {
        // Grouped by the name a release is shown under, so "2009 New Models"
        // joins the line's own cars instead of standing as a wave of its own.
        $name = LineNames::effectiveName('s.name');

        return $this->inLine($line)
            ->when($year !== null, fn (Builder $query) => $query->where('r.year', $year))
            ->groupByRaw($name)
            ->orderByDesc('last_year')
            ->orderByRaw($name)
            ->get([
                DB::raw("{$name} as name"),
                DB::raw('COUNT(DISTINCT cv.casting_id) as cars'),
                DB::raw('MIN(r.year) as first_year'),
                DB::raw('MAX(r.year) as last_year'),
            ])
            // The collapsed rows came back as null; they are the line's own.
            ->each(fn (object $row) => $row->name ??= $line->name);
    }

    /**
     * Every year the line sold something in, newest first — the line page's
     * year filter.
     *
     * @return list<int>
     */
    public function years(Series $line): array
    {
        return $this->inLine($line)
            ->distinct()
            ->orderByDesc('r.year')
            ->pluck('r.year')
            ->map(fn ($year): int => (int) $year)
            ->all();
    }

    /**
     * The cars in one sub-series of a line (or in the line itself, when the
     * name is the line's own), one row per casting.
     *
     * @return LengthAwarePaginator<int, object{casting_id: int, first_year: int, last_year: int, variations: int}>
     */
    public function cars(Series $line, string $subSeries, ?int $year = null, int $perPage = 48): LengthAwarePaginator
    {
        return $this->inLine($line)
            ->join('variations as v', 'v.release_id', '=', 'r.id')
            ->when(! $this->preview, fn (Builder $query) => $query->where('v.status', 'live'))
            ->whereRaw('LOWER(COALESCE('.LineNames::effectiveName('s.name').', ?)) = LOWER(?)',
                [$line->name, $subSeries])
            ->when($year !== null, fn (Builder $query) => $query->where('r.year', $year))
            ->groupBy('cv.casting_id')
            ->orderByDesc('last_year')
            ->orderBy('cv.casting_id')
            ->select([
                'cv.casting_id',
                DB::raw('MIN(r.year) as first_year'),
                DB::raw('MAX(r.year) as last_year'),
                DB::raw('COUNT(DISTINCT v.id) as variations'),
            ])
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One photo per casting, for a grid: a primary photo if the casting has
     * one, otherwise its first. Only photos of variations the reader may see.
     *
     * @param  list<int>  $castingIds
     * @return Collection<int, Photo> keyed by casting id
     */
    public function photos(array $castingIds): Collection
    {
        if ($castingIds === []) {
            return collect();
        }

        $ids = DB::table('photos as p')
            ->join('variations as v', 'v.id', '=', 'p.variation_id')
            ->join('releases as r', 'r.id', '=', 'v.release_id')
            ->join('casting_versions as cv', 'cv.id', '=', 'r.casting_version_id')
            ->whereIn('cv.casting_id', $castingIds)
            ->when(! $this->preview, fn (Builder $query) => $query->where('v.status', 'live'))
            // Postgres's DISTINCT ON: the first row per casting in this order.
            ->selectRaw('DISTINCT ON (cv.casting_id) cv.casting_id, p.id')
            ->orderBy('cv.casting_id')
            ->orderByDesc('p.is_primary')
            ->orderBy('p.id')
            ->pluck('p.id', 'cv.casting_id');

        $photos = Photo::query()->whereIn('id', $ids->values())->get()->keyBy('id');

        return $ids->map(fn (int $id): ?Photo => $photos[$id] ?? null)->filter();
    }

    /**
     * A casting as the public sees it: null when nothing about it is visible.
     */
    public function casting(int $id): ?Casting
    {
        $casting = Casting::query()
            ->with([
                'model.make',
                'source',
                'versions.releases' => fn ($query) => $this->visible($query)->orderByDesc('year')->orderBy('id'),
                'versions.releases.series.brand',
                'versions.releases.series.parent',
                'versions.releases.variations' => fn ($query) => $this->visible($query)->orderBy('id'),
                'versions.releases.variations.photos' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
                'versions.releases.variations.photos.source',
            ])
            ->find($id);

        if ($casting === null) {
            return null;
        }

        $visible = $casting->versions->flatMap->releases->isNotEmpty();

        return ($visible && ($this->preview || $casting->isLive())) ? $casting : null;
    }

    /**
     * @template TQuery
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function visible($query)
    {
        return $this->preview ? $query : $query->where('status', 'live');
    }

    /**
     * Releases joined to their series and tooling, filtered to what may be seen.
     */
    private function releases(): Builder
    {
        return DB::table('releases as r')
            ->join('series as s', 's.id', '=', 'r.series_id')
            ->join('casting_versions as cv', 'cv.id', '=', 'r.casting_version_id')
            ->when(! $this->preview, fn (Builder $query) => $query->where('r.status', 'live'));
    }

    /**
     * Releases in a line: the line's own, and its sub-series'.
     */
    private function inLine(Series $line): Builder
    {
        return $this->releases()->where(fn (Builder $query) => $query
            ->where('s.id', $line->getKey())
            ->orWhere('s.parent_id', $line->getKey()));
    }
}
