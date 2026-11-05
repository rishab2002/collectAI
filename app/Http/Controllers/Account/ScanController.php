<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\RecognizerUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarRequest;
use App\Models\Casting;
use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\Variation;
use App\Services\Catalog\ManualCatalogEntry;
use App\Services\Recognition\CardSuggestion;
use App\Services\Recognition\ScanProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Scan page: photograph your cars, confirm what was found, and the cars
 * go into your collection.
 *
 * Only what the recognizer is sure of is offered for confirmation — it names
 * the casting, and lists that casting's variations ordered by how well their
 * colour matches the photo; the collector picks the one in their hand. Cars
 * it found but couldn't name are shown separately, with a search, so the
 * collector can name them — which, with consent, is the training data for a
 * recognizer that can one day name loose cars from the top.
 *
 * Every lookup goes through the signed-in user's own scans, so another
 * collector's scan or detection is a 404.
 */
class ScanController extends Controller
{
    public function index(Request $request): View
    {
        return view('scan.index', [
            'scans' => $request->user()->scans()->withCount([
                'detections',
                'detections as confident_count' => fn ($q) => $q->where('confident', true),
                'detections as added_count' => fn ($q) => $q->where('decision', ScanDetection::ADDED),
            ])->latest()->limit(10)->get(),
            'maxPhotos' => config('catalog.recognition.max_photos'),
        ]);
    }

    public function store(Request $request, ScanProcessor $processor): RedirectResponse
    {
        $max = config('catalog.recognition.max_photos');
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', "max:{$max}"],
            'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('catalog.capture.max_photo_kilobytes')],
            'layout' => ['required', Rule::in(Scan::LAYOUTS)],
            'consent' => ['nullable', 'boolean'],
        ], [
            'photos.*.mimetypes' => 'Photos must be JPEG, PNG or WebP. (On an iPhone, Settings › Camera › Formats › Most Compatible.)',
        ]);

        try {
            $scan = $processor->process($request->user(), $data['photos'], $data['layout'], (bool) ($data['consent'] ?? false));
        } catch (RecognizerUnavailableException $e) {
            return back()->withErrors(['photos' => $e->getMessage()]);
        }

        return redirect()->route('scan.show', $scan);
    }

    public function show(Request $request, int $scan): View
    {
        $scan = $request->user()->scans()->with('detections.casting', 'detections.variation.release.castingVersion.casting')->findOrFail($scan);
        $offered = $scan->detections->flatMap->offeredVariationIds()->unique()->all();

        // The variations offered, as the catalog has them now and only if still
        // live — the index file may be a day old.
        $variations = Variation::query()
            ->whereIn('id', $offered ?: [0])
            ->where('status', Variation::STATUS_LIVE)
            ->with(['release.series.brand', 'release.series.parent', 'photos' => fn ($q) => $q->orderByDesc('is_primary')])
            ->get()
            ->keyBy('id');

        $labelling = $request->integer('label') ?: null;

        // "Looks most like…" for cars it couldn't name: live castings only, so
        // a hint can't reveal a record still in review.
        $lookalikeIds = $scan->detections->where('confident', false)
            ->map(fn (ScanDetection $d) => $d->match['looks']['candidates'][0]['casting_id'] ?? null)
            ->filter()->unique()->all();
        $lookalikes = Casting::query()->whereIn('id', $lookalikeIds ?: [0])
            ->where('status', Casting::STATUS_LIVE)->pluck('name', 'id');

        return view('scan.show', [
            'scan' => $scan,
            'confident' => $scan->detections->where('confident', true),
            'unnamed' => $scan->detections->where('confident', false),
            'variations' => $variations,
            'labelling' => $labelling,
            'lookalikes' => $lookalikes,
            'search' => $labelling ? $this->search((string) $request->query('q', '')) : collect(),
            // Only an admin may add to the catalog from here, so only an admin
            // is offered anything to add.
            'suggestion' => $labelling && $request->user()->is_admin
                ? CardSuggestion::from($scan->detections->firstWhere('id', $labelling) ?? new ScanDetection)
                : null,
            'owned' => $request->user()->collectionItems()->whereIn('variation_id', $variations->keys())->pluck('quantity', 'variation_id'),
        ]);
    }

    /**
     * Adds the chosen car to the collection — one more if it's already there,
     * since a pile can hold two of the same car.
     */
    public function add(Request $request, int $scan, int $detection, ScanProcessor $processor): RedirectResponse
    {
        $scan = $request->user()->scans()->findOrFail($scan);
        $record = $scan->detections()->findOrFail($detection);
        $variationId = (int) $request->validate(['variation_id' => ['required', 'integer']])['variation_id'];

        if (! $record->isPending()) {
            return back()->with('status', 'Already answered.');
        }

        // A live car only, as anywhere else a car is collected.
        $variation = Variation::query()->where('status', Variation::STATUS_LIVE)->findOrFail($variationId);

        DB::transaction(function () use ($request, $record, $variation): void {
            $item = $request->user()->collectionItems()->firstOrNew(['variation_id' => $variation->getKey()]);
            $item->quantity = $item->exists ? $item->quantity + 1 : 1;
            $item->save();
            $request->user()->wishlist()->detach($variation->getKey());

            $record->forceFill([
                'decision' => ScanDetection::ADDED,
                'variation_id' => $variation->getKey(),
                'labelled_by_hand' => ! in_array($variation->getKey(), $record->offeredVariationIds(), true),
                'decided_at' => now(),
            ])->save();
        });

        $processor->tidy($scan);

        return redirect()->route('scan.show', $scan)->with('status', 'Added '.$variation->release->castingVersion->casting->name.' to your collection.');
    }

    /**
     * A car the catalog hasn't got yet, added from the scan that found it.
     *
     * The label panel can otherwise only offer what is already published, so a
     * car nobody has entered cannot be named at all — and naming it is the
     * whole point: a named crop is what teaches the recognizer, and the cars
     * it gets wrong are exactly the ones missing from the catalog. This closes
     * that loop without making the admin leave the scan, retype the card into
     * Add a car and come back.
     *
     * It is the same `ManualCatalogEntry::createCar` the admin form uses, so
     * the rules are not a second set: make and model or fantasy, exact
     * case-insensitive lookups, published through `promoteWithDependencies()`.
     * Admin only, for the reason manual entry is admin only — what is typed
     * here goes live, and the admin is the reviewer.
     */
    public function catalog(StoreCarRequest $request, int $scan, int $detection, ManualCatalogEntry $entry, ScanProcessor $processor): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 404);

        $scan = $request->user()->scans()->findOrFail($scan);
        $record = $scan->detections()->findOrFail($detection);

        if (! $record->isPending()) {
            return back()->with('status', 'Already answered.');
        }

        $result = $entry->createCar($request->validated());
        $variation = $result->variation;
        $casting = $variation->release->castingVersion->casting;

        if (! $result->isLive()) {
            // Only a live car can be collected, anywhere in the app. A new
            // casting that says what it is has no blockers, so this is the
            // unexpected branch — say what happened rather than lose it.
            return redirect()->route('scan.show', $scan)->with(
                'status',
                "Added {$casting->name} to the catalog, but it is being held: ".implode(' ', $result->blockers),
            );
        }

        DB::transaction(function () use ($request, $record, $variation): void {
            $item = $request->user()->collectionItems()->firstOrNew(['variation_id' => $variation->getKey()]);
            $item->quantity = $item->exists ? $item->quantity + 1 : 1;
            $item->save();

            $record->forceFill([
                'decision' => ScanDetection::ADDED,
                'variation_id' => $variation->getKey(),
                // Named by a person, not confirmed from a suggestion: that is
                // what the training export records this crop as.
                'labelled_by_hand' => true,
                'decided_at' => now(),
            ])->save();
        });

        $processor->tidy($scan);

        return redirect()->route('scan.show', $scan)
            ->with('status', "Added {$casting->name} to the catalog and to your collection.");
    }

    public function reject(Request $request, int $scan, int $detection, ScanProcessor $processor): RedirectResponse
    {
        $scan = $request->user()->scans()->findOrFail($scan);
        $scan->detections()->findOrFail($detection)
            ->forceFill(['decision' => ScanDetection::REJECTED, 'decided_at' => now()])
            ->save();

        $processor->tidy($scan);

        return redirect()->route('scan.show', $scan)->with('status', 'Skipped.');
    }

    public function crop(Request $request, int $scan, int $detection): StreamedResponse
    {
        $record = $request->user()->scans()->findOrFail($scan)->detections()->whereNotNull('crop_path')->findOrFail($detection);

        return Storage::disk($record->disk)->response($record->crop_path, null, [
            'Content-Type' => 'image/jpeg',
            // A collector's own photo: never in a shared cache.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function destroy(Request $request, int $scan): RedirectResponse
    {
        $request->user()->scans()->findOrFail($scan)->deleteWithPhotos();

        return redirect()->route('scan.index')->with('status', 'Scan and its photos deleted.');
    }

    /**
     * Live castings by name, for naming a car the recognizer couldn't.
     */
    private function search(string $q)
    {
        $q = trim($q);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        return Casting::query()
            ->where('status', Casting::STATUS_LIVE)
            ->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%')
            ->orderByRaw('length(name)')
            ->limit(8)
            ->with(['versions.releases' => fn ($r) => $r->where('status', 'live')->orderBy('year')->with([
                'series.brand', 'series.parent',
                'variations' => fn ($v) => $v->where('status', Variation::STATUS_LIVE),
            ])])
            ->get();
    }
}
