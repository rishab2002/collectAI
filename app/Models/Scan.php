<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One go at the Scan page: some photos, the cars found in them.
 * See the create_scans_tables migration.
 */
#[Fillable(['consent', 'layout', 'photos', 'error'])]
class Scan extends Model
{
    public const LAYOUTS = ['auto', 'pile', 'single'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detections(): HasMany
    {
        return $this->hasMany(ScanDetection::class)->orderBy('photo')->orderBy('number');
    }

    protected function casts(): array
    {
        return ['consent' => 'boolean'];
    }
}
