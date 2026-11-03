<?php

namespace App\Services\Import\FandomWiki;

use App\Models\Casting;
use App\Models\Photo;
use App\Models\RecordSource;
use App\Models\Variation;
use App\Services\Import\ImportSummary;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * One photo per imported casting: the article's own infobox image, and only
 * when its file page licenses it for reuse (FileLicence).
 *
 * - **One per casting.** The infobox image is the picture the article itself
 *   chose to represent the tool. It is attached to the variation whose row in
 *   the Versions table shows that same file, so the photo sits on the car it
 *   actually depicts; if no row shows it, on the casting's first variation.
 * - **Credited.** The licence, the uploader and the file page go with the
 *   photo — `photos.license`, `photos.credit` and a `record_sources` row — and
 *   the public page prints them, which is what the licences require.
 * - **Never over a photo we already have.** A casting with any photo is
 *   skipped, so one taken through capture or manual entry always wins, and a
 *   second run does nothing it did the first time.
 */
final class WikiPhotoImporter
{
    private const BATCH = 50;

    private const MAX_BYTES = 15 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly Wiki $wiki,
        private readonly string $userAgent = 'collectAI catalog importer (https://collectai.club)',
    ) {}

    public function import(PageSource $pages, ?int $limit = null, bool $dryRun = false): ImportSummary
    {
        $summary = new ImportSummary;
        $wanted = $this->castingsWithoutPhotos();
        $summary->record('castings.without_photos', count($wanted));

        // Casting article → its infobox image and the row showing it.
        $candidates = [];
        $parser = new CastingPageParser($this->wiki);

        foreach ($pages->pages(array_column($wanted, 'title')) as $page) {
            $casting = $wanted[$this->normalize($page->title)] ?? null;

            if ($casting === null) {
                continue;
            }

            $parsed = $parser->parse($page->wikitext, $page->title, $page->pageId, $page->revisionId);

            if ($parsed->image === null) {
                $summary->record('castings.no_infobox_image');

                continue;
            }

            unset($casting['title']);
            $candidates[] = [...$casting, 'page' => $parsed, 'file' => $parsed->image];
        }

        $files = $this->describe(array_values(array_unique(array_column($candidates, 'file'))));
        $attached = 0;

        foreach ($candidates as $candidate) {
            if ($limit !== null && $attached >= $limit) {
                break;
            }

            $file = $files[$this->fileKey($candidate['file'])] ?? null;

            if ($file === null) {
                $summary->record('images.missing_on_wiki');

                continue;
            }

            if ($file['licence'] === null) {
                $summary->record('images.not_licensed_for_reuse');

                continue;
            }

            $summary->record('images.licensed');

            if ($dryRun) {
                $attached++;

                continue;
            }

            try {
                $this->attach($candidate, $file);
                $summary->record('photos.created');
                $attached++;
            } catch (Throwable $e) {
                $summary->problem("{$candidate['page']->title}: {$candidate['file']} — {$e->getMessage()}");
            }
        }

        return $summary;
    }

    /**
     * Imported castings of this wiki with no photo on any variation, keyed by
     * normalised article title.
     *
     * @return array<string, array{casting_id: int, page_key: string, title: string}>
     */
    private function castingsWithoutPhotos(): array
    {
        $rows = DB::table('record_sources as rs')
            ->where('rs.source', $this->wiki->source)
            ->where('rs.sourceable_type', (new Casting)->getMorphClass())
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('photos as p')
                ->join('variations as v', 'v.id', '=', 'p.variation_id')
                ->join('releases as r', 'r.id', '=', 'v.release_id')
                ->join('casting_versions as cv', 'cv.id', '=', 'r.casting_version_id')
                ->whereColumn('cv.casting_id', 'rs.sourceable_id'))
            ->orderBy('rs.sourceable_id')
            ->get(['rs.sourceable_id', 'rs.source_key', 'rs.source_url']);

        $wanted = [];

        foreach ($rows as $row) {
            $title = rawurldecode(str_replace('_', ' ', (string) Str::after($row->source_url, '/wiki/')));
            $wanted[$this->normalize($title)] = [
                'casting_id' => (int) $row->sourceable_id,
                'page_key' => $row->source_key,
                // As written: the API matches titles case-sensitively after
                // the first letter, so the lowered key would miss.
                'title' => $title,
            ];
        }

        return $wanted;
    }

    /**
     * Licence, uploader and URL for each file, from the wiki API — the file
     * page's text for its licence templates, and imageinfo for who uploaded it
     * and where the bytes are. Fifty files a request.
     *
     * @param  list<string>  $files
     * @return array<string, array{title: string, licence: string|null, uploader: string|null, url: string|null, page_url: string}>
     */
    private function describe(array $files): array
    {
        $described = [];

        foreach (array_chunk($files, self::BATCH) as $batch) {
            $response = $this->http()->get($this->wiki->apiEndpoint(), [
                'action' => 'query',
                'titles' => implode('|', array_map(fn (string $file): string => "File:{$file}", $batch)),
                'prop' => 'revisions|imageinfo',
                'rvprop' => 'content',
                'rvslots' => 'main',
                'iiprop' => 'user|url|mime|size',
                'format' => 'json',
                'formatversion' => '2',
            ])->throw();

            foreach ($response->json('query.pages', []) as $page) {
                if ($page['missing'] ?? false) {
                    continue;
                }

                $title = (string) $page['title'];
                $info = $page['imageinfo'][0] ?? [];

                $described[$this->fileKey(Str::after($title, ':'))] = [
                    'title' => $title,
                    'licence' => FileLicence::of((string) data_get($page, 'revisions.0.slots.main.content', '')),
                    'uploader' => $info['user'] ?? null,
                    'url' => $info['url'] ?? null,
                    'page_url' => $this->wiki->articleUrl($title),
                ];
            }
        }

        return $described;
    }

    /**
     * @param  array{casting_id: int, page_key: string, page: CastingPage, file: string}  $candidate
     * @param  array{title: string, licence: string|null, uploader: string|null, url: string|null, page_url: string}  $file
     */
    private function attach(array $candidate, array $file): void
    {
        if ($file['url'] === null) {
            throw new \RuntimeException('the wiki gave no URL for the file');
        }

        $response = $this->http()->timeout(60)->get($file['url'])->throw();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (! isset(self::EXTENSIONS[$mime])) {
            throw new \RuntimeException("not an image the catalog serves ({$mime})");
        }

        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('larger than 15 MB');
        }

        $variation = $this->variationFor($candidate);
        $disk = config('catalog.capture.disk');
        // Unguessable, like every other photo path: the bucket is public.
        $path = 'photos/'.Str::ulid().'/'.Str::random(32).'.'.self::EXTENSIONS[$mime];

        Storage::disk($disk)->put($path, $body);

        try {
            DB::transaction(function () use ($variation, $disk, $path, $file, $candidate): void {
                $photo = Photo::create([
                    'variation_id' => $variation->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    'type' => 'wiki',
                    'is_primary' => ! $variation->photos()->where('is_primary', true)->exists(),
                    'license' => $file['licence'],
                    'credit' => $file['uploader'] === null
                        ? "{$this->wiki->brand} Wiki"
                        : "{$file['uploader']} on the {$this->wiki->brand} Wiki",
                ]);

                RecordSource::create([
                    'sourceable_type' => $photo->getMorphClass(),
                    'sourceable_id' => $photo->getKey(),
                    'source' => $this->wiki->source,
                    'source_key' => "photo:{$candidate['page_key']}",
                    'source_url' => $file['page_url'],
                    'source_revision_id' => null,
                    'imported_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // Same obligation as every other writer to a disk: no orphans.
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * The variation whose Versions row shows this file, else the casting's
     * first variation.
     *
     * @param  array{casting_id: int, page_key: string, page: CastingPage, file: string}  $candidate
     */
    private function variationFor(array $candidate): Variation
    {
        foreach ($candidate['page']->versions as $row) {
            foreach ($row->photos as $photo) {
                if ($this->fileKey($photo) !== $this->fileKey($candidate['file'])) {
                    continue;
                }

                $source = RecordSource::query()
                    ->where('source', $this->wiki->source)
                    ->where('source_key', RecordSource::rowKey($candidate['page_key'], $row->line))
                    ->first();

                if ($source?->sourceable instanceof Variation) {
                    return $source->sourceable;
                }
            }
        }

        return Variation::query()
            ->join('releases', 'releases.id', '=', 'variations.release_id')
            ->join('casting_versions', 'casting_versions.id', '=', 'releases.casting_version_id')
            ->where('casting_versions.casting_id', $candidate['casting_id'])
            ->orderBy('variations.id')
            ->select('variations.*')
            ->firstOrFail();
    }

    /**
     * File names compare the way MediaWiki compares them: underscores are
     * spaces, and the first letter is case-insensitive.
     */
    private function fileKey(string $file): string
    {
        $file = trim(str_replace('_', ' ', $file));

        return mb_strtoupper(mb_substr($file, 0, 1)).mb_substr($file, 1);
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $title)));
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)->retry(2, 500, throw: false)->timeout(30);
    }
}
