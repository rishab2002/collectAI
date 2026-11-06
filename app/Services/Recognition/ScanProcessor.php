<?php

namespace App\Services\Recognition;

use App\Exceptions\RecognizerUnavailableException;
use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\ScanPhoto;
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

                // Kept so the collector can cut packages out of it by hand
                // and scan those again — the segmenter walks past a package
                // whose printing it cannot find, and this is how those get
                // in. Private, like the crops, and deleted on the same terms.
                $original = sprintf('%s/photo-%d-%s.jpg', $folder, $i + 1, Str::random(8));
                Storage::disk($disk)->put($original, (string) file_get_contents($photo->getRealPath()));
                $stored[] = $original;
                $scan->scanPhotos()->create([
                    'number' => $i + 1,
                    'disk' => $disk,
                    'path' => $original,
                    'width' => (int) ($result['width'] ?? 0),
                    'height' => (int) ($result['height'] ?? 0),
                ]);

                foreach ($result['detections'] ?? [] as $found) {
                    $this->record($scan, $found, $i + 1, (int) $found['n'], $disk, $folder, $stored);
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
     * One detection, saved — from a first scan or from a hand-cut region.
     *
     * `$region` offsets the box back into the photo's own coordinates, so
     * every detection on a scan is measured against the same picture however
     * it was found. `$stored` collects what was written, so a failure can
     * clean up after itself the way capture does.
     *
     * @param  array<string, mixed>  $found
     * @param  list<string>  $stored
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $region
     */
    private function record(Scan $scan, array $found, int $photo, int $number, string $disk,
        string $folder, array &$stored, ?array $region = null): void
    {
        $crop = null;

        if (($bytes = base64_decode((string) ($found['crop_jpeg'] ?? ''), true)) !== false && $bytes !== '') {
            $crop = sprintf('%s/%d-%d-%s.jpg', $folder, $photo, $number, Str::random(8));
            Storage::disk($disk)->put($crop, $bytes);
            $stored[] = $crop;
        }

        $match = $found['match'] ?? null;

        // What the trained model thinks it looks like — a hint for naming it
        // by hand, never an answer on its own (the recognizer only marks a
        // looks-only match confident when training measured it).
        if ($match !== null && isset($found['looks'])) {
            $match['looks'] = $found['looks'];
        }

        $confident = (bool) ($found['confident'] ?? false) && ! empty($match['casting_id']);

        $scan->detections()->create([
            'photo' => $photo,
            'number' => $number,
            'disk' => $disk,
            'crop_path' => $crop,
            'box' => $this->inWholePhoto($found['box'] ?? [], $region, $scan, $photo),
            'colour_family' => $found['colour']['family'] ?? null,
            'reading' => [
                'lines' => $found['text']['lines'] ?? [],
                // The largest line of print — on a package, the car's name,
                // whether or not the catalog has it.
                'headline' => $found['text']['headline'] ?? null,
                'brand' => $found['read']['brand'] ?? null,
                'copyright_year' => $found['read']['copyright_year'] ?? null,
                'codes' => $found['read']['codes'] ?? [],
                // Packaged cars only: what the card says, in the OCR Agent's
                // shape (app/AI/schemas/ocr_output.json), so a package that
                // matched nothing still shows the collector the brand and
                // name it was printed with.
                'card' => $found['card'] ?? null,
                // Set when the collector cut this region out themselves.
                'region' => $region,
            ],
            'match' => $match,
            'confident' => $confident,
            'casting_id' => $confident ? (int) $match['casting_id'] : null,
            'confidence' => (float) ($match['confidence'] ?? 0),
        ]);
    }

    /**
     * A box the recognizer measured against a crop, put back where it is in
     * the whole photo.
     *
     * @param  list<int>  $box
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $region
     * @return list<int>
     */
    private function inWholePhoto(array $box, ?array $region, Scan $scan, int $photo): array
    {
        if ($region === null || count($box) !== 4) {
            return $box;
        }

        $source = $scan->scanPhotos()->where('number', $photo)->first();

        if ($source === null || $source->width < 1 || $source->height < 1) {
            return $box;
        }

        return [
            (int) round($box[0] + $region[0] * $source->width),
            (int) round($box[1] + $region[1] * $source->height),
            $box[2],
            $box[3],
        ];
    }

    /**
     * Another go at one photo, over the part of it the collector cut out.
     *
     * The segmenter finds a package by the printing on it, so one whose name
     * it cannot read it walks past entirely — and no amount of confirming
     * fixes that, because there is nothing to confirm. Handing it a rectangle
     * is the way back in: the same reader, the same rules, a smaller picture.
     * The detections join the scan they came from, numbered after the ones
     * already there, so the confirm screen needs to know nothing about it.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $region  x, y, w, h as fractions
     *
     * @throws RecognizerUnavailableException
     */
    public function rescan(ScanPhoto $photo, array $region): int
    {
        $scan = $photo->scan;
        $image = (string) Storage::disk($photo->disk)->get($photo->path);
        $result = $this->recognizer->recognize($image, $scan->layout, $region);
        // The folder this scan's pictures already live in, so a re-scan does
        // not start a second one beside it.
        $folder = dirname($photo->path);

        $next = (int) $scan->detections()->where('photo', $photo->number)->max('number');
        $stored = [];
        $added = 0;

        try {
            foreach ($result['detections'] ?? [] as $found) {
                $this->record($scan, $found, $photo->number, ++$next, $photo->disk, $folder, $stored, $region);
                $added++;
            }
        } catch (Throwable $e) {
            Storage::disk($photo->disk)->delete($stored);

            throw $e;
        }

        return $added;
    }

    /**
     * Once nothing in a scan is waiting for an answer, the pictures of a
     * collector who didn't agree to share them have done their job — the
     * crops and the photos they were cut from alike.
     */
    public function tidy(Scan $scan): void
    {
        if ($scan->consent || $scan->detections()->where('decision', ScanDetection::PENDING)->exists()) {
            return;
        }

        $scan->detections()->whereNotNull('crop_path')->get()->each->deleteCrop();
        $scan->scanPhotos()->whereNotNull('path')->get()->each->deleteFile();
    }
}
