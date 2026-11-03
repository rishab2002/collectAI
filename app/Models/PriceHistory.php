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
#[Fillable(['variation_id', 'price', 'currency', 'kind', 'sample_size', 'source', 'url', 'observed_at'])]
class PriceHistory extends Model
{
    use HasFactory;

    public const KIND_SOLD = 'sold';

    public const KIND_LISTING = 'listing';

    public const KIND_MSRP = 'msrp';

    /**
     * How the figure reads to a person — the three kinds are never shown as
     * the same thing.
     */
    public function label(): string
    {
        return match ($this->kind) {
            self::KIND_SOLD => $this->sample_size > 1
                ? "eBay sold · median of last {$this->sample_size} sales"
                : 'eBay sold · last sale',
            self::KIND_LISTING => $this->sample_size > 1
                ? "eBay asking · median of {$this->sample_size} listings"
                : 'eBay asking price',
            default => 'MSRP (list price)',
        };
    }

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
