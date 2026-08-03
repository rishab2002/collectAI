<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific year a casting version was sold in stores under a given series.
 * The natural key is (casting_version_id, series_id, year).
 */
#[Fillable(['casting_version_id', 'series_id', 'year', 'collector_number'])]
class Release extends Model
{
    use HasApprovalStatus, HasFactory;

    public function castingVersion(): BelongsTo
    {
        return $this->belongsTo(CastingVersion::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    public function approvalDependencies(): array
    {
        return [$this->castingVersion, $this->series];
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }
}
