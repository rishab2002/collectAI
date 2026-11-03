<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One car the recognizer found in a scan photo, and the collector's answer.
 */
#[Fillable([
    'photo', 'number', 'disk', 'crop_path', 'box', 'colour_family', 'reading',
    'match', 'confident', 'casting_id', 'confidence',
])]
class ScanDetection extends Model
{
    public const PENDING = 'pending';

    public const ADDED = 'added';

    public const REJECTED = 'rejected';

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function casting(): BelongsTo
    {
        return $this->belongsTo(Casting::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    public function isPending(): bool
    {
        return $this->decision === self::PENDING;
    }

    /**
     * The variation ids the recognizer offered, best first.
     *
     * @return list<int>
     */
    public function offeredVariationIds(): array
    {
        return array_map('intval', array_column($this->match['variations'] ?? [], 'variation_id'));
    }

    public function deleteCrop(): void
    {
        if ($this->crop_path !== null) {
            Storage::disk($this->disk)->delete($this->crop_path);
            $this->forceFill(['crop_path' => null])->save();
        }
    }

    protected function casts(): array
    {
        return [
            'box' => 'array',
            'reading' => 'array',
            'match' => 'array',
            'confident' => 'boolean',
            'labelled_by_hand' => 'boolean',
            'confidence' => 'float',
            'decided_at' => 'datetime',
        ];
    }
}
