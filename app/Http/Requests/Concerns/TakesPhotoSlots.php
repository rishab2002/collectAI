<?php

namespace App\Http\Requests\Concerns;

use App\Models\SubmissionPhoto;
use Illuminate\Http\UploadedFile;

/**
 * The named photo slots — card front, card back, loose — shared by every form
 * that takes photos of a car.
 *
 * Named rather than one `photos[]` array so each upload arrives already typed.
 * The type is free text on both `submission_photos` and `photos`, because which
 * angles exist is brand-dependent; the shortlist lives here, in the UI layer.
 * Capture and manual entry take the same slots so a photo means the same thing
 * whichever door it came in through.
 */
trait TakesPhotoSlots
{
    /**
     * Single-file slots, mapped to the photo type they produce.
     *
     * @return array<string, string>
     */
    protected function singlePhotoSlots(): array
    {
        return [
            'card_front' => SubmissionPhoto::TYPE_CARD_FRONT,
            'card_back' => SubmissionPhoto::TYPE_CARD_BACK,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function photoSlotRules(): array
    {
        $photo = [
            'file',
            // Not the `image` rule: it leans on getimagesize(), which doesn't
            // know HEIC/HEIF in most PHP builds. iOS Safari usually transcodes
            // to JPEG on upload, but "usually" isn't a validation strategy, and
            // a rejected photo of a car already back in its case is a reshoot.
            'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
            'max:'.config('catalog.capture.max_photo_kilobytes'),
        ];

        return [
            'card_front' => ['nullable', ...$photo],
            'card_back' => ['nullable', ...$photo],
            'loose' => ['nullable', 'array', 'max:6'],
            'loose.*' => $photo,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function photoSlotAttributes(): array
    {
        return [
            'card_front' => 'card front photo',
            'card_back' => 'card back photo',
            'loose.*' => 'loose photo',
        ];
    }

    /**
     * The uploaded photos, flattened and paired with the type each slot implies.
     *
     * Defensive about what's actually an UploadedFile because after() callbacks
     * still run when an earlier rule has already failed.
     *
     * @return list<array{file: UploadedFile, type: string}>
     */
    public function photos(): array
    {
        $photos = [];

        foreach ($this->singlePhotoSlots() as $slot => $type) {
            $file = $this->file($slot);

            if ($file instanceof UploadedFile) {
                $photos[] = ['file' => $file, 'type' => $type];
            }
        }

        foreach ((array) $this->file('loose', []) as $file) {
            if ($file instanceof UploadedFile) {
                $photos[] = ['file' => $file, 'type' => SubmissionPhoto::TYPE_LOOSE];
            }
        }

        return $photos;
    }
}
