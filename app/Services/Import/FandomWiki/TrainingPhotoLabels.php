<?php

namespace App\Services\Import\FandomWiki;

use App\Models\Casting;
use App\Models\RecordSource;
use App\Models\Variation;
use App\Services\Recognition\StableKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which wiki photo shows which car — the labels for training the visual model
 * on the wiki's photos, licensed or not.
 *
 * **Training only, under Fandom's written consent** (owner, 2026-11-03; see
 * CLAUDE.md, Recognition). These labels never create a `photos` row: nothing
 * here is shown on the site. The recognizer's trainer (`python -m
 * train.collect`) downloads each file, turns it into a vector and discards it.
 *
 * Two kinds of label, both from the casting article:
 *  - every picture in a Versions-table row → that row's variation (the best
 *    label: colour, wheels and tampo are all known);
 *  - the infobox picture → the casting, and its variation when a row shows it.
 */
final class TrainingPhotoLabels
{
    public function __construct(private readonly Wiki $wiki) {}

    /**
     * @return iterable<array{file: string, casting_id: int, variation_id: ?int, wiki: string, api: string, page_url: string}>
     */
    public function labels(PageSource $pages): iterable
    {
        [$castings, $variations] = $this->keys();
        $parser = new CastingPageParser($this->wiki);

        foreach ($pages->pages(array_column($castings, 'title')) as $page) {
            $casting = $castings[$this->normalize($page->title)] ?? null;

            if ($casting === null) {
                continue;
            }

            $parsed = $parser->parse($page->wikitext, $page->title, $page->pageId, $page->revisionId);
            $seen = [];

            foreach ($parsed->versions as $row) {
                // The stored key, not one rebuilt from this page's id: the
                // casting was keyed when it was imported, and that is the key
                // its rows carry.
                $rowKey = RecordSource::rowKey($casting['page_key'], $row->line);
                $variationId = $variations[$rowKey] ?? null;

                foreach ($row->photos as $file) {
                    $seen[$this->fileKey($file)] = true;
                    yield $this->label($file, $casting, $variationId === null ? null : [$variationId, $rowKey], $parsed);
                }
            }

            if ($parsed->image !== null && ! isset($seen[$this->fileKey($parsed->image)])) {
                yield $this->label($parsed->image, $casting, null, $parsed);
            }
        }
    }

    /**
     * @param  array{id: int, page_key: string, title: string}  $casting
     * @param  array{0: int, 1: string}|null  $variation  id and provenance key
     * @return array<string, mixed>
     */
    private function label(string $file, array $casting, ?array $variation, CastingPage $page): array
    {
        return [
            'file' => 'File:'.$this->fileKey($file),
            'casting_id' => $casting['id'],
            'variation_id' => $variation[0] ?? null,
            // Ids are this database's; the keys are the same in every one.
            'casting_key' => StableKeys::compose($this->wiki->source, $casting['page_key']),
            'variation_key' => $variation === null ? null : StableKeys::compose($this->wiki->source, $variation[1]),
            'wiki' => $this->wiki->key,
            'api' => $this->wiki->apiEndpoint(),
            'page_url' => $page->sourceUrl(),
        ];
    }

    /**
     * This wiki's imported castings (by normalised title) and its variations'
     * provenance keys, read once.
     *
     * @return array{0: array<string, array{id: int, page_key: string, title: string}>, 1: array<string, int>}
     */
    private function keys(): array
    {
        $castings = [];

        foreach (DB::table('record_sources')->where('source', $this->wiki->source)
            ->where('sourceable_type', (new Casting)->getMorphClass())
            ->get(['source_key', 'sourceable_id', 'source_url']) as $row) {
            $title = rawurldecode(str_replace('_', ' ', (string) Str::after($row->source_url, '/wiki/')));
            $castings[$this->normalize($title)] = ['id' => (int) $row->sourceable_id, 'page_key' => $row->source_key, 'title' => $title];
        }

        $variations = DB::table('record_sources')->where('source', $this->wiki->source)
            ->where('sourceable_type', (new Variation)->getMorphClass())
            ->pluck('sourceable_id', 'source_key')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [$castings, $variations];
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $title)));
    }

    /** MediaWiki file names: underscores are spaces, first letter upper-case. */
    private function fileKey(string $file): string
    {
        $file = trim(str_replace('_', ' ', preg_replace('/^(File|Image):/i', '', $file)));

        return mb_strtoupper(mb_substr($file, 0, 1)).mb_substr($file, 1);
    }
}
