<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\RecognizerUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScanCarRequest;
use App\Models\Casting;
use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\Variation;
use App\Services\Catalog\CatalogVocabulary;
use App\Services\Catalog\ManualCatalogEntry;
use App\Services\Recognition\CardSuggestion;
use App\Services\Recognition\ScanProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $scan = $request->user()->scans()
            ->with('scanPhotos', 'detections.casting', 'detections.variation.release.castingVersion.casting')
            ->findOrFail($scan);
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
            'suggestion' => $suggestion = $labelling && $request->user()->is_admin
                ? CardSuggestion::from($scan->detections->firstWhere('id', $labelling) ?? new ScanDetection)
                : null,
            // Scoped to the brand the card says, so the series offered are
            // that brand's. Nothing is limited to them: every one of these
            // fields takes a name the catalog has never seen.
            'vocabulary' => $suggestion ? CatalogVocabulary::for($suggestion['brand']) : null,
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
    public function catalog(StoreScanCarRequest $request, int $scan, int $detection, ManualCatalogEntry $entry, ScanProcessor $processor): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 404);

        $scan = $request->user()->scans()->findOrFail($scan);
        $record = $scan->detections()->findOrFail($detection);

        if (! $record->isPending()) {
            return back()->with('status', 'Already answered.');
        }

        // Queued, the car is saved pending and goes to /admin/proposals to be
        // checked before the public catalog has it. Published, it is live at
        // once, which is what typing a car in has always meant.
        $queue = $request->boolean('queue_for_review');
        $result = $entry->createCar($request->validated(), publish: ! $queue);
        $variation = $result->variation;
        $casting = $variation->release->castingVersion->casting;

        if ($result->queued) {
            // A pending car cannot be collected — only live variations can be,
            // everywhere — so the detection stays pending too, and the
            // collector adds it once it has been approved.
            return redirect()->route('scan.show', $scan)->with(
                'status',
                "Saved {$casting->name} for review. Check it in Inferred records; it goes into the catalog, and into your collection, once you approve it.",
            );
        }

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

    /**
     * One of the photos the scan was made from. The collector's own, so the
     * same private serving the crops get.
     */
    public function photo(Request $request, int $scan, int $photo): StreamedResponse
    {
        $record = $request->user()->scans()->findOrFail($scan)
            ->scanPhotos()->whereNotNull('path')->findOrFail($photo);

        return Storage::disk($record->disk)->response($record->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Scan a piece of one photo again, cut out by hand.
     *
     * The segmenter finds a package by the printing on it, so one whose name
     * it cannot read it walks past — and nothing on the confirm screen can
     * recover that, because no detection was ever made. Drawing a rectangle
     * round it is the way back in, and it can be done as many times as there
     * are packages it missed.
     */
    public function rescan(Request $request, int $scan, int $photo, ScanProcessor $processor): RedirectResponse
    {
        $scan = $request->user()->scans()->findOrFail($scan);
        $record = $scan->scanPhotos()->whereNotNull('path')->findOrFail($photo);

        $data = $request->validate([
            // Fractions of the photo, so the browser can work in whatever
            // size it displayed and never has to know the original's.
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'width' => ['required', 'numeric', 'between:0.02,1'],
            'height' => ['required', 'numeric', 'between:0.02,1'],
        ]);

        $region = [(float) $data['x'], (float) $data['y'], (float) $data['width'], (float) $data['height']];

        if ($region[0] + $region[2] > 1.0001 || $region[1] + $region[3] > 1.0001) {
            return back()->withErrors(['width' => 'That selection runs off the edge of the photo.']);
        }

        try {
            $found = $processor->rescan($record, $region);
        } catch (RecognizerUnavailableException $e) {
            return back()->withErrors(['width' => $e->getMessage()]);
        }

        return redirect()->route('scan.show', $scan)->with('status', $found === 0
            ? 'Nothing found in that piece. Try a tighter crop round one package, with its name showing.'
            : $found.' more '.Str::plural('car', $found).' found in that piece of photo '.$record->number.'.');
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
