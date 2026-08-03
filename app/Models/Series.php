<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand-scoped, reusable named wave ("Night Burnerz"). It carries no year of
 * its own — the same series name recurs across multiple years, and the year
 * lives on the Release.
 *
 * #[Table] is explicit because "series" is its own plural; leaving it to the
 * inflector would work today but silently depends on that staying true.
 */
#[Table('series')]
#[Fillable(['brand_id', 'name'])]
class Series extends Model
{
    use HasApprovalStatus, HasFactory;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function approvalDependencies(): array
    {
        return [$this->brand];
    }
}
