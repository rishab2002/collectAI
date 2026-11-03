<?php

namespace App\Services\Reference;

use App\Models\ReferenceItem;
use App\Services\AI\LocalMetadataAgent;
use App\Services\Recognition\StableKeys;
use Illuminate\Support\Facades\DB;

/**
 * Training labels for the reference photos — which car each one shows, in the
 * stable keys the visual model is trained in.
 *
 * A product is tied to a catalog casting only when that is certain:
 *  - Mini GT item numbers are the Mini GT import's own keys (exact).
 *  - A Hot Wheels product whose name, minus "Chase"/"Treasure Hunt" markers,
 *    is exactly one Hot Wheels casting's name, released within a year of the
 *    product's year.
 * Everything else is labelled as itself (`source|item:<key>`) — a class the
 * model learns, which resolves to a catalog car once one is added from it on
 * the Add a car form. A wrong tie would teach the model one car's look under
 * another's name; no tie costs nothing.
 */
final class ReferenceLabels
{
    private const MARKERS = ['super treasure hunt', 'treasure hunt', 'sth', 'th', 'chase', 'zamac', 'raw'];

    /** @var array<string, list<array{id: int, key: string, first: int, last: int}>>|null */
    private ?array $hotWheels = null;

    /** @var array<string, array{0: string, 1: ?string}>|null */
    private ?array $miniGt = null;

    /**
     * @return array{casting_key: string, variation_key: string, matched: bool}
     */
    public function for(ReferenceItem $item): array
    {
        $own = ['casting_key' => $item->castingKey(), 'variation_key' => $item->variationKey(), 'matched' => false];

        if ($item->source === 'tsm' && $item->item_code && ($hit = $this->miniGt()[strtoupper($item->item_code)] ?? null)) {
            return ['casting_key' => $hit[0], 'variation_key' => $hit[1] ?? $own['variation_key'], 'matched' => true];
        }

        if ($item->source === 'hwpriceguide' || $item->brand === 'Hot Wheels') {
            // The name as listed first; only if that finds nothing, without a
            // trailing "(Yellow)" — a bracket can be part of a real name
            // ("Skyline GT-R (R34)"), so it is never removed when it matters.
            $colour = null;
            $candidates = $this->hotWheelsCandidates($item->name, $item->year);

            if ($candidates === [] && preg_match('/^(.*\S)\s*\(([^()]+)\)\s*$/', $item->name, $m)) {
                $candidates = $this->hotWheelsCandidates($m[1], $item->year);
                $colour = trim($m[2]);
            }

            if (count($candidates) === 1) {
                return [
                    'casting_key' => $candidates[0]['key'],
                    // The version too, when the colour and year pick exactly one.
                    'variation_key' => $this->variationByColour($candidates[0]['id'], $item->year, $colour) ?? $own['variation_key'],
                    'matched' => true,
                ];
            }
        }

        return $own;
    }

    /**
     * @return list<array{id: int, key: string, first: int, last: int}>
     */
    private function hotWheelsCandidates(string $name, ?int $year): array
    {
        return array_values(array_filter(
            $this->hotWheels()[$this->clean($name)] ?? [],
            fn (array $c): bool => $year === null || ($year >= $c['first'] - 1 && $year <= $c['last'] + 1),
        ));
    }

    private function variationByColour(int $castingId, ?int $year, ?string $colour): ?string
    {
        if ($year === null || $colour === null || $colour === '') {
            return null;
        }

        $ids = DB::table('variations as v')
            ->join('releases as r', 'r.id', '=', 'v.release_id')
            ->join('casting_versions as cv', 'cv.id', '=', 'r.casting_version_id')
            ->where('cv.casting_id', $castingId)
            ->where('r.year', $year)
            ->whereRaw('lower(v.color) = ?', [mb_strtolower($colour)])
            ->pluck('v.id');

        return $ids->count() === 1 ? (StableKeys::variations([(int) $ids[0]])[(int) $ids[0]] ?? null) : null;
    }

    public function clean(string $name): string
    {
        $n = LocalMetadataAgent::normalise($name);

        do {
            $before = $n;

            foreach (self::MARKERS as $marker) {
                $n = preg_replace('/\b'.preg_quote($marker, '/').'$/', '', $n);
                $n = trim($n);
            }
        } while ($n !== $before);

        return $n;
    }

    /**
     * @return array<string, list<array{key: string, first: int, last: int}>>
     */
    private function hotWheels(): array
    {
        if ($this->hotWheels !== null) {
            return $this->hotWheels;
        }

        $rows = DB::table('castings as c')
            ->join('casting_versions as cv', 'cv.casting_id', '=', 'c.id')
            ->join('releases as r', 'r.casting_version_id', '=', 'cv.id')
            ->join('series as s', 's.id', '=', 'r.series_id')
            ->join('brands as b', 'b.id', '=', 's.brand_id')
            ->where('b.name', 'Hot Wheels')
            ->groupBy('c.id', 'c.name')
            ->get(['c.id', 'c.name', DB::raw('MIN(r.year) as first'), DB::raw('MAX(r.year) as last')]);

        $keys = StableKeys::castings($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->hotWheels = [];

        foreach ($rows as $row) {
            $this->hotWheels[$this->clean($row->name)][] = [
                'id' => (int) $row->id,
                'key' => $keys[(int) $row->id] ?? 'id:'.$row->id,
                'first' => (int) $row->first,
                'last' => (int) $row->last,
            ];
        }

        return $this->hotWheels;
    }

    /**
     * @return array<string, array{0: string, 1: ?string}> item number => [casting key, variation key]
     */
    private function miniGt(): array
    {
        if ($this->miniGt !== null) {
            return $this->miniGt;
        }

        $this->miniGt = [];

        foreach (DB::table('record_sources')->where('source', 'minigt_fandom')
            ->where(fn ($q) => $q->where('source_key', 'like', 'item:%')->whereRaw("source_key not like '%:%:%'")
                ->orWhere('source_key', 'like', 'item:%:row:1'))
            ->get(['source_key']) as $row) {
            if (preg_match('/^item:([^:]+)(:row:1)?$/', $row->source_key, $m)) {
                $code = strtoupper($m[1]);
                $this->miniGt[$code] ??= [null, null];
                $this->miniGt[$code][isset($m[2]) ? 1 : 0] = StableKeys::compose('minigt_fandom', $row->source_key);
            }
        }

        return $this->miniGt = array_filter($this->miniGt, fn ($pair) => $pair[0] !== null);
    }
}
