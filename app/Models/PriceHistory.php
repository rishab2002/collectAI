<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observed sale/listing price for a variation at a point in time.
 *
 * No approval status: a price is an observation attached to an already-approved
 * variation, not a catalog entity the pipeline proposes into existence.
 */
#[Fillable(['variation_id', 'price', 'currency', 'source', 'observed_at'])]
class PriceHistory extends Model
{
    use HasFactory;

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    protected function casts(): array
    {
        return [
            // Cast to string rather than float: these are currency amounts, and
            // decimal:2 keeps them out of binary floating point where 19.99 stops
            // being 19.99 as soon as anything sums a column of them.
            'price' => 'decimal:2',
            'observed_at' => 'datetime',
        ];
    }
}
