<?php

namespace App\Services\AI\Contracts;

use App\Exceptions\OcrFailedException;
use App\Models\Submission;

/**
 * Reads what is printed on a captured car's packaging, into the shape of
 * app/AI/schemas/ocr_output.json.
 *
 * Two implementations, chosen by catalog.ai.driver: LocalPackagingReader (the
 * recognizer on our own box — the default) and OcrAgent (the Claude API).
 */
interface ReadsPackaging
{
    /**
     * @return array<string, mixed>
     *
     * @throws OcrFailedException
     */
    public function read(Submission $submission): array;
}
