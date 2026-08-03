<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A retool of a casting — the mould itself changed (new base, opening parts,
 * altered proportions), as opposed to a Variation, where only the finish
 * changed. Optional: most castings have none.
 */
#[Fillable([
    'casting_id',
    'name',
    'version_number',
    'introduced_year',
    'changes',
    'metadata',
])]
class CastingVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'introduced_year' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Casting, $this> */
    public function casting(): BelongsTo
    {
        return $this->belongsTo(Casting::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }
}
