<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pre-flight for the capture screen's storage.
 *
 * Exists because "the disk is configured" and "the disk works" are different
 * claims, and the gap between them is invisible until an admin has already
 * photographed a car. Round-trips a throwaway object through the configured
 * capture disk and, if a public URL is configured, checks the object is
 * actually readable over it — a private bucket writes fine and then serves 401s
 * to every photo in the catalog.
 */
class CheckCaptureStorage extends Command
{
    protected $signature = 'capture:check-storage';

    protected $description = 'Round-trip a test object through the configured capture disk';

    public function handle(): int
    {
        $diskName = config('catalog.capture.disk');
        $disk = Storage::disk($diskName);

        $this->line("Capture disk: <options=bold>{$diskName}</>");

        // Under submissions/ rather than the bucket root so it lands where real
        // captures land — a bucket policy that only permits that prefix should
        // fail this check too, not pass it and break in production.
        $path = 'submissions/_healthcheck/'.Str::ulid().'.txt';
        $payload = 'collectai capture storage check '.now()->toIso8601String();

        try {
            $disk->put($path, $payload);
            $this->info("  write   ok  ({$path})");
        } catch (Throwable $e) {
            $this->error('  write   FAILED  '.$e->getMessage());
            $this->newLine();
            $this->warn('Check R2_ACCESS_KEY_ID / R2_SECRET_ACCESS_KEY / R2_BUCKET / R2_ENDPOINT in .env.');

            return self::FAILURE;
        }

        try {
            $readBack = $disk->get($path);

            if ($readBack !== $payload) {
                $this->error('  read    FAILED  object came back with different contents');

                return self::FAILURE;
            }

            $this->info('  read    ok');
        } catch (Throwable $e) {
            $this->error('  read    FAILED  '.$e->getMessage());

            return self::FAILURE;
        }

        $status = $this->checkPublicUrl($disk->url($path));

        try {
            $disk->delete($path);
            $this->info('  delete  ok');
        } catch (Throwable $e) {
            // Not fatal — the bytes are in the bucket and the capture path works.
            // Worth saying out loud so the stray object gets cleaned up by hand.
            $this->warn('  delete  FAILED  '.$e->getMessage()." — remove {$path} manually");
        }

        return $status;
    }

    private function checkPublicUrl(string $url): int
    {
        if (! str_starts_with($url, 'http')) {
            $this->warn('  public  skipped — no public URL configured for this disk');

            return self::SUCCESS;
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable $e) {
            $this->error('  public  FAILED  '.$e->getMessage());
            $this->newLine();
            $this->warn("Set R2_URL to the bucket's public hostname and make sure it resolves.");

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->info("  public  ok  ({$url})");

            return self::SUCCESS;
        }

        $this->error("  public  FAILED  HTTP {$response->status()} from {$url}");
        $this->newLine();
        $this->warn('The object stored but is not publicly readable. Enable public access on the');
        $this->warn('bucket (custom domain or r2.dev) and point R2_URL at it — otherwise every');
        $this->warn('catalog photo URL will 401.');

        return self::FAILURE;
    }
}
