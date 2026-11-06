<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo a scan was made from, kept so it can be cropped by hand.
 *
 * Private, like the crops it produced: the same disk, served only to the
 * collector who took it, and deleted on the same terms. See the
 * create_scan_photos_table migration.
 */
#[Fillable(['number', 'disk', 'path', 'width', 'height'])]
class ScanPhoto extends Model
{
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function deleteFile(): void
    {
        if ($this->path !== null) {
            Storage::disk($this->disk)->delete($this->path);
            $this->forceFill(['path' => null])->save();
        }
    }

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer'];
    }
}
