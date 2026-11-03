<?php

namespace App\Services\Recognition;

use App\Models\Casting;
use App\Models\Variation;
use Illuminate\Support\Facades\DB;

/**
 * Names for castings and variations that are the same in every database.
 *
 * The visual model is trained once and served anywhere, but ids are local to
 * a database: an import into a fresh database can number the same car
 * differently. So training labels and the recognition index carry the record's
 * provenance — `hotwheels_fandom|page:2602:row:7` — and the recognizer
 * translates labels to the serving database's ids through the index. Records
 * typed in by hand have no provenance; they are labelled `id:<n>`, which is
 * only meaningful in the database they came from (where such photos are
 * trained anyway).
 */
final class StableKeys
{
    public static function compose(string $source, string $sourceKey): string
    {
        return "{$source}|{$sourceKey}";
    }

    /**
     * @param  list<int>  $castingIds
     * @return array<int, string> id => key, for those that have provenance
     */
    public static function castings(array $castingIds): array
    {
        return self::lookup((new Casting)->getMorphClass(), $castingIds);
    }

    /**
     * @param  list<int>  $variationIds
     * @return array<int, string>
     */
    public static function variations(array $variationIds): array
    {
        return self::lookup((new Variation)->getMorphClass(), $variationIds);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function lookup(string $type, array $ids): array
    {
        $keys = [];

        foreach (array_chunk(array_values(array_unique($ids)), 5000) as $chunk) {
            foreach (DB::table('record_sources')->where('sourceable_type', $type)->whereIn('sourceable_id', $chunk)
                ->get(['sourceable_id', 'source', 'source_key']) as $row) {
                $keys[(int) $row->sourceable_id] = self::compose($row->source, $row->source_key);
            }
        }

        return $keys;
    }
}
