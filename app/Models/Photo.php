<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A catalog photo of an approved variation.
 *
 * Photos taken during capture do NOT start here — they live on
 * SubmissionPhoto until the variation they depict is approved, since this
 * table's variation_id is non-nullable by design.
 */
#[Fillable(['variation_id', 'disk', 'path', 'type', 'is_primary'])]
class Photo extends Model
{
    use HasFactory;

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    /**
     * Resolved through the disk recorded on the row rather than the configured
     * default, so photos stored before a disk switch still resolve afterwards.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }
}
