<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * The one-line description of a casting a list shows: which brands sold it,
 * over which years, and in which series — "Hot Wheels · 2010–2026 · Mainline /
 * HW J-Imports, Car Culture / Deluxe +12 more".
 *
 * One query for a whole page of castings, not a walk per row: a list of thirty
 * castings would otherwise be thirty trips through versions, releases and
 * series. Public lists pass `$liveOnly` so a pending release never leaks into
 * a summary of a live casting.
 */
final class CastingSummaries
{
    /**
     * @param  list<int>  $castingIds
     * @return array<int, array{brands: list<string>, years: string|null, paths: list<string>, more: int}>
     */
    public function for(array $castingIds, bool $liveOnly = false, int $paths = 2): array
    {
        if ($castingIds === []) {
            return [];
        }

        $rows = DB::table('casting_versions as cv')
            ->join('releases as r', 'r.casting_version_id', '=', 'cv.id')
            ->join('series as s', 's.id', '=', 'r.series_id')
            ->join('brands as b', 'b.id', '=', 's.brand_id')
            ->leftJoin('series as p', 'p.id', '=', 's.parent_id')
            ->whereIn('cv.casting_id', $castingIds)
            ->when($liveOnly, fn ($query) => $query->where('r.status', 'live'))
            ->groupBy('cv.casting_id', 'b.name', 's.name', 'p.name')
            ->get([
                'cv.casting_id',
                'b.name as brand',
                's.name as series',
                'p.name as line',
                DB::raw('MIN(r.year) as first_year'),
                DB::raw('MAX(r.year) as last_year'),
                DB::raw('COUNT(*) as releases'),
            ]);

        $summaries = [];

        foreach ($rows->groupBy('casting_id') as $castingId => $group) {
            $first = $group->min('first_year');
            $last = $group->max('last_year');

            // Paths ranked by how many releases the casting has in them, so a
            // car's main home comes first; a child sharing its line's name is
            // the line's own cars, and reads as the line alone.
            $byPath = [];

            foreach ($group as $row) {
                $path = $row->line === null || mb_strtolower($row->line) === mb_strtolower($row->series)
                    ? ($row->line ?? $row->series)
                    : "{$row->line} / {$row->series}";

                $byPath[$path] = ($byPath[$path] ?? 0) + (int) $row->releases;
            }

            arsort($byPath);

            $summaries[(int) $castingId] = [
                'brands' => $group->pluck('brand')->unique()->sort()->values()->all(),
                'years' => $first === $last ? (string) $first : "{$first}–{$last}",
                'paths' => array_slice(array_keys($byPath), 0, $paths),
                'more' => max(0, count($byPath) - $paths),
            ];
        }

        return $summaries;
    }
}
