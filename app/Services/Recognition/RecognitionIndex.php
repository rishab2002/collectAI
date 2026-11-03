<?php

namespace App\Services\Recognition;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The catalog, as the one file the recognizer needs.
 *
 * Every casting and variation the recognizer may answer with — names, brands,
 * years, colours (with their family), wheels, toy numbers and series — in a
 * plain JSON file. The recognizer never touches the database: copy this file
 * to any computer and it runs there with no network at all.
 *
 * Live records only by default, because the Scan page can only add live cars
 * to a collection. `includePending` is for running the recognizer yourself
 * over a catalog that is still mostly in review.
 */
final class RecognitionIndex
{
    public const FORMAT = 'collectai-recognition-index';

    public const VERSION = 1;

    public function __construct(private readonly ColourFamilies $colours) {}

    /**
     * @return array{castings: int, variations: int, bytes: int}
     */
    public function write(string $path, bool $includePending = false): array
    {
        $index = $this->build($includePending);
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException("Cannot create the directory for {$path}.");
        }

        // Written beside and renamed over: the recognizer reloads the file when
        // it changes, and must never read half of one.
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, $json);
        rename($temporary, $path);

        return ['castings' => count($index['castings']), 'variations' => count($index['variations']), 'bytes' => strlen($json)];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(bool $includePending = false): array
    {
        $variations = [];
        $castings = [];

        $rows = DB::table('variations as v')
            ->join('releases as r', 'r.id', '=', 'v.release_id')
            ->join('casting_versions as cv', 'cv.id', '=', 'r.casting_version_id')
            ->join('series as s', 's.id', '=', 'r.series_id')
            ->leftJoin('series as p', 'p.id', '=', 's.parent_id')
            ->when(! $includePending, fn ($q) => $q->where('v.status', 'live'))
            ->orderBy('v.id')
            ->select([
                'v.id', 'cv.casting_id', 'r.year', 'v.color', 'v.base_color', 'v.rim_type', 'v.toy_number',
                's.brand_id', 's.name as series', 'p.name as line',
            ])
            ->lazy(5000);

        foreach ($rows as $row) {
            $castingId = (int) $row->casting_id;
            $year = $row->year === null ? null : (int) $row->year;

            $variations[] = [
                'id' => (int) $row->id,
                'casting_id' => $castingId,
                'brand_id' => (int) $row->brand_id,
                'year' => $year,
                'color' => $row->color,
                'color_family' => $this->colours->familyOf($row->color),
                // An upside-down car shows its base, not its paint.
                'base_color' => $row->base_color,
                'base_color_family' => $this->colours->familyOf($row->base_color),
                'wheel_type' => $row->rim_type,
                'toy_number' => $row->toy_number,
                'series' => $row->line === null || mb_strtolower($row->line) === mb_strtolower($row->series)
                    ? ($row->line ?? $row->series)
                    : "{$row->line} / {$row->series}",
            ];

            $c = $castings[$castingId] ?? ['brand_ids' => [], 'first_year' => null, 'last_year' => null];
            $c['brand_ids'][(int) $row->brand_id] = (int) $row->brand_id;

            if ($year !== null) {
                $c['first_year'] = $c['first_year'] === null ? $year : min($c['first_year'], $year);
                $c['last_year'] = $c['last_year'] === null ? $year : max($c['last_year'], $year);
            }

            $castings[$castingId] = $c;
        }

        $details = DB::table('castings as c')
            ->leftJoin('models as m', 'm.id', '=', 'c.model_id')
            ->leftJoin('makes as mk', 'mk.id', '=', 'm.make_id')
            ->whereIn('c.id', array_keys($castings) ?: [0])
            ->get(['c.id', 'c.name', 'c.is_fantasy', 'm.name as model', 'mk.name as make'])
            ->keyBy('id');

        $castingRows = [];

        foreach ($castings as $id => $c) {
            $detail = $details[$id] ?? null;

            if ($detail === null) {
                continue;
            }

            $castingRows[] = [
                'id' => $id,
                'name' => $detail->name,
                'brand_ids' => array_values($c['brand_ids']),
                'first_year' => $c['first_year'],
                'last_year' => $c['last_year'],
                'make' => $detail->make,
                'model' => $detail->model,
                'fantasy' => (bool) $detail->is_fantasy,
            ];
        }

        $brandIds = array_unique(array_merge(...array_column($castingRows, 'brand_ids') ?: [[]]));

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'includes_pending' => $includePending,
            'colour_families' => $this->colours->names(),
            'brands' => DB::table('brands')->whereIn('id', $brandIds ?: [0])->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b): array => ['id' => (int) $b->id, 'name' => $b->name])->all(),
            'castings' => $castingRows,
            'variations' => $variations,
        ];
    }
}
