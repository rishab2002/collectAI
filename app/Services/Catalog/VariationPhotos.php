<?php

namespace App\Services\Catalog;

use App\Models\Photo;
use App\Models\SubmissionPhoto;
use App\Models\Variation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Catalog photos an admin attaches to a variation directly.
 *
 * The capture path's photos wait on `submission_photos` until a variation
 * exists; these don't need to, because the variation is already in the catalog.
 * They go to the same disk captures use (`catalog.capture.disk`), and
 * `photos.disk` records the choice per row so a later switch of disk leaves
 * existing photos resolving.
 */
final class VariationPhotos
{
    /**
     * Stores the uploads and their rows together, or neither.
     *
     * `throw => true` on the r2 disk is what stops a row existing for bytes that
     * never arrived — and the flip side is that a failure partway through
     * strands the uploads that already succeeded. Same cleanup obligation as
     * CaptureController: delete what was stored, then re-throw.
     *
     * @param  list<array{file: UploadedFile, type: string}>  $uploads
     * @return list<Photo>
     */
    public function attach(Variation $variation, array $uploads): array
    {
        $disk = config('catalog.capture.disk');

        // A ULID prefix, as on the capture path: the bucket is world-readable,
        // so unguessable paths are what keeps it from being browsable.
        $prefix = 'photos/'.Str::ulid();

        /** @var list<array{disk: string, path: string, type: string}> $stored */
        $stored = [];

        try {
            foreach ($uploads as $upload) {
                $stored[] = [
                    'disk' => $disk,
                    'path' => $upload['file']->store($prefix, $disk),
                    'type' => $upload['type'],
                ];
            }

            return DB::transaction(function () use ($variation, $stored): array {
                // The card front is what a collector recognises, so it wins
                // primary when the variation has none yet; otherwise the first
                // photo does.
                $needsPrimary = ! $variation->photos()->where('is_primary', true)->exists();
                $primaryIndex = null;

                if ($needsPrimary && $stored !== []) {
                    $front = array_search(SubmissionPhoto::TYPE_CARD_FRONT, array_column($stored, 'type'), true);
                    $primaryIndex = $front === false ? 0 : $front;
                }

                $photos = [];

                foreach ($stored as $index => $row) {
                    $photos[] = $variation->photos()->create([
                        ...$row,
                        'is_primary' => $index === $primaryIndex,
                    ]);
                }

                return $photos;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete(array_column($stored, 'path'));

            throw $e;
        }
    }

    public function makePrimary(Photo $photo): void
    {
        DB::transaction(function () use ($photo): void {
            Photo::query()
                ->where('variation_id', $photo->variation_id)
                ->whereKeyNot($photo->getKey())
                ->update(['is_primary' => false]);

            $photo->update(['is_primary' => true]);
        });
    }

    /**
     * Removes the photo, and its bytes when nothing else points at them.
     *
     * `SubmissionPhoto::promoteTo()` references the stored object rather than
     * copying it, so a catalog photo approved from a capture shares its bytes
     * with the submission it came from. Deleting those would break the
     * submission's record of what was photographed.
     */
    public function remove(Photo $photo): void
    {
        $wasPrimary = $photo->is_primary;
        $variationId = $photo->variation_id;

        $shared = SubmissionPhoto::query()
            ->where('disk', $photo->disk)
            ->where('path', $photo->path)
            ->exists()
            || Photo::query()
                ->where('disk', $photo->disk)
                ->where('path', $photo->path)
                ->whereKeyNot($photo->getKey())
                ->exists();

        DB::transaction(function () use ($photo, $wasPrimary, $variationId): void {
            $photo->delete();

            if ($wasPrimary) {
                Photo::query()
                    ->where('variation_id', $variationId)
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_primary' => true]);
            }
        });

        // After the row is gone, so a failed delete leaves an orphaned object
        // rather than a row pointing at nothing.
        if (! $shared) {
            Storage::disk($photo->disk)->delete($photo->path);
        }
    }
}
