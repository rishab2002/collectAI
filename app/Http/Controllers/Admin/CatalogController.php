<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarRequest;
use App\Http\Requests\StoreVariationRequest;
use App\Models\Brand;
use App\Models\Casting;
use App\Models\Make;
use App\Services\Catalog\ManualCatalogEntry;
use App\Services\Catalog\ManualEntryResult;
use App\Services\Catalog\VariationPhotos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The admin's own view of the catalog: find a casting, look at everything under
 * it, and add cars and photos by hand.
 *
 * Shows every status, not just live — this is where an admin goes to see what
 * the import held back as well as what it published.
 */
class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        $castings = Casting::query()
            ->with('model.make')
            ->when($query !== '', function ($builder) use ($query): void {
                // ILIKE on a substring is what the GIN trigram index on
                // castings.name serves, so this stays fast on the full import.
                $like = '%'.addcslashes($query, '%_\\').'%';

                $builder->where(fn ($where) => $where
                    ->where('name', 'ILIKE', $like)
                    ->orWhereHas('model', fn ($model) => $model
                        ->where('name', 'ILIKE', $like)
                        ->orWhereHas('make', fn ($make) => $make->where('name', 'ILIKE', $like))));
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.catalog.index', [
            'castings' => $castings,
            'brands' => $this->brandsFor($castings->pluck('id')->all()),
            'query' => $query,
            'total' => Casting::count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.catalog.create', $this->vocabularies());
    }

    public function store(StoreCarRequest $request, ManualCatalogEntry $entry, VariationPhotos $photos): RedirectResponse
    {
        $result = $entry->createCar($request->validated());

        return $this->finish($result, $request->photos(), $photos);
    }

    public function show(Casting $casting): View
    {
        $casting->load([
            'model.make',
            'source',
            'versions' => fn ($query) => $query->orderBy('version_number'),
            'versions.releases' => fn ($query) => $query->orderByDesc('year')->orderBy('id'),
            'versions.releases.series.brand',
            'versions.releases.variations' => fn ($query) => $query->orderBy('id'),
            'versions.releases.variations.photos' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
        ]);

        return view('admin.catalog.show', [
            'casting' => $casting,
            ...$this->vocabularies(),
        ]);
    }

    public function storeVariation(
        StoreVariationRequest $request,
        Casting $casting,
        ManualCatalogEntry $entry,
        VariationPhotos $photos,
    ): RedirectResponse {
        $result = $entry->addVariation($casting, $request->validated());

        return $this->finish($result, $request->photos(), $photos);
    }

    /**
     * Photos are stored after the catalog rows commit, not inside the same
     * transaction: the bytes go to a remote bucket that can't roll back, and
     * VariationPhotos cleans up its own partial uploads. A photo failure
     * therefore leaves a correctly entered car without photos — which the
     * casting page lets the admin fix — rather than losing the entry too.
     *
     * @param  list<array{file: UploadedFile, type: string}>  $uploads
     */
    private function finish(ManualEntryResult $result, array $uploads, VariationPhotos $photos): RedirectResponse
    {
        $variation = $result->variation;

        if ($uploads !== []) {
            $photos->attach($variation, $uploads);
        }

        $casting = $variation->release->castingVersion->casting;

        $message = $result->isLive()
            ? "Added variation #{$variation->getKey()} — it is live in the catalog."
            : "Saved variation #{$variation->getKey()}, but it is held back: ".implode(' ', $result->blockers);

        return redirect()
            ->to(route('admin.castings.show', $casting).'#variation-'.$variation->getKey())
            ->with($result->isLive() ? 'status' : 'warning', $message);
    }

    /**
     * Which brands sold each casting, by casting id — one query for the page
     * rather than walking versions, releases and series per row.
     *
     * @param  list<int>  $castingIds
     * @return Collection<int, list<string>>
     */
    private function brandsFor(array $castingIds): Collection
    {
        return DB::table('casting_versions')
            ->join('releases', 'releases.casting_version_id', '=', 'casting_versions.id')
            ->join('series', 'series.id', '=', 'releases.series_id')
            ->join('brands', 'brands.id', '=', 'series.brand_id')
            ->whereIn('casting_versions.casting_id', $castingIds)
            ->distinct()
            ->orderBy('brands.name')
            ->get(['casting_versions.casting_id', 'brands.name'])
            ->groupBy('casting_id')
            ->map(fn ($rows): array => $rows->pluck('name')->all());
    }

    /**
     * Suggestions for the free-text fields. Brands and makes are small,
     * hard-unique vocabularies, so offering the existing spellings is what keeps
     * "Chevy" from arriving beside "Chevrolet". Series are deliberately not
     * offered: the full import holds thousands, which is no use as a list.
     *
     * @return array<string, list<string>>
     */
    private function vocabularies(): array
    {
        return [
            'brands' => Brand::query()->orderBy('name')->pluck('name')->all(),
            'makes' => Make::query()->orderBy('name')->pluck('name')->all(),
        ];
    }
}
