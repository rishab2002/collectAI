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
    public const LAYOUTS = ['auto', 'pile', 'single', 'packaged'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The scan, its detections and every picture of it on disk. The rows
     * would cascade on their own; the files would not.
     */
    public function deleteWithPhotos(): void
    {
        $this->detections()->get()->each->deleteCrop();
        $this->scanPhotos()->get()->each->deleteFile();
        $this->delete();
    }

    /**
     * The photos it was made from, kept so they can be cropped by hand.
     */
    public function scanPhotos(): HasMany
    {
        return $this->hasMany(ScanPhoto::class)->orderBy('number');
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
