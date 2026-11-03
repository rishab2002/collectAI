<?php

namespace App\Http\Controllers\Account;

use App\Enums\ItemCondition;
use App\Http\Controllers\Controller;
use App\Models\UserCollectionItem;
use App\Models\Variation;
use App\Services\Catalog\VariationPrices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A collector's own cars: one row per variation, with how many and in what
 * condition. Every query goes through the signed-in user's own relation, so an
 * id from someone else's collection is simply not found.
 */
class CollectionController extends Controller
{
    public function index(Request $request, VariationPrices $prices): View
    {
        $user = $request->user();

        $items = $user->collectionItems()
            ->with([
                'variation.photos' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('id'),
                'variation.release.series.brand',
                'variation.release.series.parent',
                'variation.release.castingVersion.casting',
            ])
            ->latest()
            ->get();

        $wishlist = $user->wishlist()
            ->with(['release.series.brand', 'release.series.parent', 'release.castingVersion.casting'])
            ->latest('user_wishlist_items.created_at')
            ->get();

        $current = $prices->latestFor([...$items->pluck('variation_id'), ...$wishlist->pluck('id')]);

        return view('account.collection', [
            'items' => $items,
            'wishlist' => $wishlist,
            'prices' => $current,
            'conditions' => ItemCondition::cases(),
            'totalValue' => $items->sum(fn (UserCollectionItem $item): float => (float) ($current[$item->variation_id]->price ?? 0) * $item->quantity),
            'pricedCount' => $items->filter(fn (UserCollectionItem $item): bool => isset($current[$item->variation_id]))->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);
        $variation = $this->visibleVariation($request, (int) $data['variation_id']);

        $request->user()->collectionItems()->updateOrCreate(
            ['variation_id' => $variation->getKey()],
            [
                'quantity' => $data['quantity'] ?? 1,
                'condition' => $data['condition'] ?? null,
                'acquired_at' => $data['acquired_at'] ?? null,
            ],
        );

        // Owning it answers wanting it.
        $request->user()->wishlist()->detach($variation->getKey());

        return back()->with('status', 'Saved to your collection.');
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $record = $request->user()->collectionItems()->findOrFail($item);
        $record->update($this->validated($request, creating: false));

        return back()->with('status', 'Collection updated.');
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $request->user()->collectionItems()->findOrFail($item)->delete();

        return back()->with('status', 'Removed from your collection.');
    }

    public function wish(Request $request): RedirectResponse
    {
        $variation = $this->visibleVariation($request, (int) $request->validate([
            'variation_id' => ['required', 'integer'],
        ])['variation_id']);

        $request->user()->wishlist()->syncWithoutDetaching([$variation->getKey()]);

        return back()->with('status', 'Added to your wishlist.');
    }

    public function unwish(Request $request, int $variation): RedirectResponse
    {
        $request->user()->wishlist()->detach($variation);

        return back()->with('status', 'Removed from your wishlist.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'variation_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'quantity' => ['nullable', 'integer', 'between:1,999'],
            'condition' => ['nullable', Rule::enum(ItemCondition::class)],
            'acquired_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }

    /**
     * Only a published car can be collected — a pending one is invisible to
     * everyone but admins, and saving it would leak that it exists.
     */
    private function visibleVariation(Request $request, int $id): Variation
    {
        return Variation::query()
            ->when(! $request->user()->is_admin, fn ($q) => $q->where('status', Variation::STATUS_LIVE))
            ->findOrFail($id);
    }
}
