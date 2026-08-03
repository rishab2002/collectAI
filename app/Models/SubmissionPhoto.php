<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo as captured, before anything in the catalog exists to attach it to.
 * Graduates into a Photo row once the variation it depicts is approved.
 */
#[Fillable(['submission_id', 'disk', 'path', 'type'])]
class SubmissionPhoto extends Model
{
    use HasFactory;

    public const TYPE_CARD_FRONT = 'card_front';

    public const TYPE_CARD_BACK = 'card_back';

    public const TYPE_LOOSE = 'loose';

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Copies this capture into the catalog against an approved variation.
     *
     * The stored object is referenced, not re-uploaded: the bytes in R2 are
     * already the right bytes, and duplicating them would double storage for
     * every approved submission. The SubmissionPhoto row stays as the record of
     * where the catalog photo came from.
     */
    public function promoteTo(Variation $variation, bool $primary = false): Photo
    {
        return $variation->photos()->create([
            'disk' => $this->disk,
            'path' => $this->path,
            'type' => $this->type,
            'is_primary' => $primary,
        ]);
    }
}
