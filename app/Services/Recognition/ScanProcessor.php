<?php

namespace App\Services\Recognition;

use App\Exceptions\RecognizerUnavailableException;
use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Photos in, detections out: sends each photo to the local recognizer and
 * records every car it found — confident or not — with a crop of it.
 *
 * The photo itself is never stored, only the crop of each car: that is all
 * the confirm screen shows and all training needs. Crops go on a private disk
 * (catalog.recognition.disk), never the public photo bucket.
 */
final class ScanProcessor
{
    public function __construct(private readonly RecognizerClient $recognizer) {}

    /**
     * @param  list<UploadedFile>  $photos
     *
     * @throws RecognizerUnavailableException when no photo could be read at all
     */
    public function process(User $user, array $photos, string $layout, bool $consent): Scan
    {
        $scan = $user->scans()->create(['consent' => $consent, 'layout' => $layout, 'photos' => count($photos)]);
        $disk = config('catalog.recognition.disk');
        $folder = 'scans/'.Str::ulid();
        $stored = [];
        $problems = [];
        $first = null;

        try {
            foreach (array_values($photos) as $i => $photo) {
                try {
                    $result = $this->recognizer->recognize((string) file_get_contents($photo->getRealPath()), $layout);
                } catch (RecognizerUnavailableException $e) {
                    $first ??= $e;
                    $problems[] = 'Photo '.($i + 1).': '.$e->getMessage();

                    continue;
                }

                foreach ($result['detections'] ?? [] as $found) {
                    $crop = null;

                    if (($bytes = base64_decode((string) ($found['crop_jpeg'] ?? ''), true)) !== false && $bytes !== '') {
                        $crop = sprintf('%s/%d-%d-%s.jpg', $folder, $i + 1, (int) $found['n'], Str::random(8));
                        Storage::disk($disk)->put($crop, $bytes);
                        $stored[] = $crop;
                    }

                    $match = $found['match'] ?? null;

                    // What the trained model thinks it looks like — a hint for
                    // naming it by hand, never an answer on its own (the
                    // recognizer only marks a looks-only match confident when
                    // training measured it).
                    if ($match !== null && isset($found['looks'])) {
                        $match['looks'] = $found['looks'];
                    }
                    $confident = (bool) ($found['confident'] ?? false) && ! empty($match['casting_id']);

                    $scan->detections()->create([
                        'photo' => $i + 1,
                        'number' => (int) $found['n'],
                        'disk' => $disk,
                        'crop_path' => $crop,
                        'box' => $found['box'] ?? [],
                        'colour_family' => $found['colour']['family'] ?? null,
                        'reading' => [
                            'lines' => $found['text']['lines'] ?? [],
                            // The largest line of print — on a package, the
                            // car's name, whether or not the catalog has it.
                            'headline' => $found['text']['headline'] ?? null,
                            'brand' => $found['read']['brand'] ?? null,
                            'copyright_year' => $found['read']['copyright_year'] ?? null,
                            'codes' => $found['read']['codes'] ?? [],
                            // Packaged cars only: what the card says, in the
                            // OCR Agent's shape (app/AI/schemas/ocr_output.json),
                            // so a package that matched nothing still shows the
                            // collector the brand and name it was printed with.
                            'card' => $found['card'] ?? null,
                        ],
                        'match' => $match,
                        'confident' => $confident,
                        'casting_id' => $confident ? (int) $match['casting_id'] : null,
                        'confidence' => (float) ($match['confidence'] ?? 0),
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Same rule as capture: bytes written before a failure would
            // otherwise sit on the disk with nothing pointing at them.
            Storage::disk($disk)->delete($stored);
            $scan->delete();

            throw $e;
        }

        if ($problems !== [] && count($problems) === count($photos)) {
            $scan->delete();

            // Every photo failed: almost always the same reason, said once.
            throw $first;
        }

        if ($problems !== []) {
            $scan->update(['error' => implode("\n", $problems)]);
        }

        return $scan;
    }

    /**
     * Once nothing in a scan is waiting for an answer, the crops of a collector
     * who didn't agree to share them have done their job.
     */
    public function tidy(Scan $scan): void
    {
        if ($scan->consent || $scan->detections()->where('decision', ScanDetection::PENDING)->exists()) {
            return;
        }

        $scan->detections()->whereNotNull('crop_path')->get()->each->deleteCrop();
    }
}
