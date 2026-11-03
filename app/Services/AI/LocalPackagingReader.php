<?php

namespace App\Services\AI;

use App\Exceptions\OcrFailedException;
use App\Models\Submission;
use App\Models\SubmissionPhoto;
use App\Services\AI\Contracts\ReadsPackaging;
use App\Services\Recognition\RecognizerClient;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a capture's packaging with the recognizer on our own box (Tesseract),
 * into the same ocr_output.json shape the Claude OCR Agent produces — so the
 * review screen and everything after it can't tell which one ran.
 *
 * Each photo is read separately, card front first, and every field keeps the
 * value from whichever photo it was read with the most confidence: the name
 * is on the front, the toy number usually on the back.
 *
 * It reads less than the vision model and says so: year and series are left
 * null (on a card they are artwork, not text), and a casting name is given
 * only when it matched a catalog casting confidently.
 */
final class LocalPackagingReader implements ReadsPackaging
{
    private const SLOT_PRIORITY = ['card_front', 'card_back', 'loose'];

    private const READABLE = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly RecognizerClient $recognizer) {}

    public function read(Submission $submission): array
    {
        $photos = $submission->photos
            ->filter(fn (SubmissionPhoto $p): bool => in_array(mb_strtolower(pathinfo($p->path, PATHINFO_EXTENSION)), self::READABLE, true))
            ->sortBy(fn (SubmissionPhoto $p): int => ($rank = array_search($p->type, self::SLOT_PRIORITY, true)) === false ? PHP_INT_MAX : $rank)
            ->take((int) config('catalog.ocr.max_photos'))
            ->values();

        if ($photos->isEmpty()) {
            throw new OcrFailedException('No readable photos on this submission. The local reader takes JPEG, PNG and WebP; HEIC is stored but cannot be read.');
        }

        $output = [
            'schema_version' => OcrAgent::SCHEMA_VERSION,
            'confidence' => array_fill_keys(OcrAgent::FIELDS, 0.0),
        ] + array_fill_keys(OcrAgent::FIELDS, null);

        foreach ($photos as $photo) {
            // A RecognizerUnavailableException is left to propagate: the job
            // retries it, and a recognizer that is down is not the same answer
            // as a card with no text on it.
            $reading = $this->recognizer->readCard((string) Storage::disk($photo->disk)->get($photo->path));

            foreach (OcrAgent::FIELDS as $field) {
                $score = (float) ($reading['confidence'][$field] ?? 0);

                if (($reading[$field] ?? null) !== null && $score > $output['confidence'][$field]) {
                    $output[$field] = $reading[$field];
                    $output['confidence'][$field] = round(max(0.0, min(1.0, $score)), 3);
                }
            }
        }

        return $output;
    }
}
