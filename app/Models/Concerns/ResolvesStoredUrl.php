<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Turns the (disk, path) pair recorded on a row into something an <img> can
 * actually load.
 *
 * Resolved through the disk stored on the row rather than the configured
 * default, so a photo captured before a disk switch still resolves after it.
 * Shared between SubmissionPhoto and Photo because promotion doesn't move any
 * bytes — an approved photo points at the object the capture wrote — so if the
 * two resolved differently a photo would change address on approval.
 */
trait ResolvesStoredUrl
{
    public function url(): string
    {
        $config = config("filesystems.disks.{$this->disk}", []);

        // Laravel's local-disk file server refuses any unsigned request for a
        // disk that isn't declared public, and url() never signs — so on the
        // local disk it hands back a link that always 403s. CAPTURE_DISK=local
        // exists precisely so the whole path can be exercised without R2, and
        // a photo that can't be displayed doesn't exercise much.
        //
        // Narrowed to local disks deliberately. R2 is world-readable behind its
        // custom domain, and asking it for a temporary URL would presign
        // against the S3 API endpoint — which is not publicly reachable, so the
        // signed link would be worse than the plain one.
        $isPrivateLocal = ($config['driver'] ?? null) === 'local'
            && ($config['serve'] ?? false)
            && ($config['visibility'] ?? 'private') !== 'public';

        return $isPrivateLocal
            ? Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes(30))
            : Storage::disk($this->disk)->url($this->path);
    }
}
