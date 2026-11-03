<?php

namespace App\Models;

use App\Models\Concerns\ResolvesStoredUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A catalog photo of an approved variation.
 *
 * Photos taken during capture do NOT start here — they live on
 * SubmissionPhoto until the variation they depict is approved, since this
 * table's variation_id is non-nullable by design.
 */
#[Fillable(['variation_id', 'disk', 'path', 'type', 'is_primary', 'license', 'credit'])]
class Photo extends Model
{
    use HasFactory, ResolvesStoredUrl;

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    /**
     * Where an imported photo came from — the wiki file page its licence is
     * stated on. Null for photos taken through capture or manual entry.
     */
    public function source(): MorphOne
    {
        return $this->morphOne(RecordSource::class, 'sourceable');
    }

    /**
     * Whether showing this photo owes a credit line: anything imported under a
     * licence. Our own photos don't.
     */
    public function needsCredit(): bool
    {
        return $this->license !== null;
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }
}
