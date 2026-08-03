<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo of a variation, stored on R2 via the S3 driver.
 *
 * `disk` is persisted rather than assumed so existing rows keep resolving if
 * the default disk is ever repointed. `is_ai_source` marks the packaging shots
 * the Image/OCR agents read, so a proposed record can be traced back to the
 * photo it came from during review.
 */
#[Fillable([
    'variation_id',
    'uploaded_by',
    'disk',
    'path',
    'original_filename',
    'mime_type',
    'size',
    'width',
    'height',
    'is_primary',
    'is_ai_source',
    'attribution',
])]
class Photo extends Model
{
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_primary' => 'boolean',
            'is_ai_source' => 'boolean',
        ];
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return Attribute<string|null, never> */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->path
            ? Storage::disk($this->disk ?? config('filesystems.default'))->url($this->path)
            : null);
    }

    #[Scope]
    protected function primary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }
}
