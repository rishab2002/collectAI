<?php

namespace App\Models;

use App\Models\Concerns\ResolvesStoredUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    use HasFactory, ResolvesStoredUrl;

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }
}
