<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific tooling revision of a casting. Every casting has at least one
 * (version_number = 1) whether or not it was ever retooled — releases point here
 * rather than at the abstract casting, because a retool is physically distinct
 * tooling with its own production run.
 */
#[Fillable(['casting_id', 'version_number', 'retool_year', 'notes'])]
class CastingVersion extends Model
{
    use HasApprovalStatus, HasFactory;

    public function casting(): BelongsTo
    {
        return $this->belongsTo(Casting::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function approvalDependencies(): array
    {
        return [$this->casting];
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'retool_year' => 'integer',
        ];
    }
}
