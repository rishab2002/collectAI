<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\Series;
use App\Services\Catalog\CatalogBrowser;
use App\Services\Catalog\VariationPrices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public catalog: pick a brand, then its main line, then a sub-series, then
 * a car.
 *
 * Shows live records only. An admin can switch on a preview that includes the
 * pending backlog — the session flag below — so the browse can be judged before
 * the imported castings are identified; the page says so whenever it's on.
 */
class CatalogBrowseController extends Controller
{
    public function home(Request $request): View
    {
        return view('catalog.home', [
            'brands' => $this->browser($request)->brands(),
            'preview' => $this->previewing($request),
        ]);
    }

    public function brand(Request $request, Brand $brand): View
    {
        $lines = $this->browser($request)->lines($brand);

        abort_if($lines->isEmpty(), 404);

        return view('catalog.brand', [
            'brand' => $brand,
            'lines' => $lines,
            'preview' => $this->previewing($request),
        ]);
    }

    /**
     * A line's sub-series — or, given `?sub=`, the cars in one of them.
     *
     * One page rather than a route per sub-series because sub-series are
     * grouped by name, and names carry slashes and colons ("1-75/100",
     * "5-Pack: VIP Parking") that make poor path segments.
     */
    public function line(Request $request, Series $line): View
    {
        abort_if($line->parent_id !== null, 404);

        $browser = $this->browser($request);
        $year = $request->integer('year') ?: null;
        $sub = $request->string('sub')->trim()->value() ?: null;
        $subSeries = $browser->subSeries($line);

        abort_if($subSeries->isEmpty(), 404);

        // A line holding only its own cars has nothing to choose between.
        if ($sub === null && $subSeries->count() === 1) {
            $sub = $subSeries->first()->name;
        }

        if ($sub !== null) {
            $cars = $browser->cars($line, $sub, $year);
            $ids = collect($cars->items())->pluck('casting_id')->map(fn ($id): int => (int) $id)->all();

            abort_if($cars->total() === 0 && $year === null, 404);

            return view('catalog.cars', [
                'line' => $line->load('brand'),
                'sub' => $sub,
                'year' => $year,
                'cars' => $cars,
                'castings' => Casting::query()->with('model.make')->findMany($ids)->keyBy('id'),
                'photos' => $browser->photos($ids),
                'preview' => $browser->previewing(),
            ]);
        }

        return view('catalog.line', [
            'line' => $line->load('brand'),
            'year' => $year,
            'years' => $browser->years($line),
            'subSeries' => $year === null ? $subSeries : $browser->subSeries($line, $year),
            'preview' => $browser->previewing(),
        ]);
    }

    public function casting(Request $request, int $casting): View
    {
        $model = $this->browser($request)->casting($casting);

        abort_if($model === null, 404);

        $variationIds = $model->versions->flatMap->releases->flatMap->variations->pluck('id');
        $user = $request->user();

        return view('catalog.casting', [
            'casting' => $model,
            'preview' => $this->previewing($request),
            'prices' => app(VariationPrices::class)->latestFor($variationIds),
            // What this collector already has and wants, for the buttons.
            'owned' => $user === null ? collect() : $user->collectionItems()->whereIn('variation_id', $variationIds)->get()->keyBy('variation_id'),
            'wanted' => $user === null ? collect() : $user->wishlist()->whereIn('variations.id', $variationIds)->pluck('variations.id')->flip(),
        ]);
    }

    /**
     * Admins only: include pending records in the public pages, or stop.
     */
    public function togglePreview(Request $request): RedirectResponse
    {
        $request->session()->put('catalog_preview', ! $request->session()->get('catalog_preview', false));

        return redirect()->back(fallback: route('home'));
    }

    private function browser(Request $request): CatalogBrowser
    {
        return new CatalogBrowser(preview: $this->previewing($request));
    }

    private function previewing(Request $request): bool
    {
        return (bool) $request->user()?->is_admin && (bool) $request->session()->get('catalog_preview', false);
    }
}
