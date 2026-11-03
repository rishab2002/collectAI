<?php

namespace App\Models;

use App\Services\Recognition\StableKeys;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One product from a permitted reference source. See the
 * create_reference_items_table migration — private, never public.
 */
#[Fillable(['source', 'source_key', 'brand', 'line', 'series', 'year', 'name', 'item_code', 'scale', 'image_urls', 'page_url'])]
class ReferenceItem extends Model
{
    /** Human names of the sources, for the admin form. */
    public const SOURCES = [
        'hwpriceguide' => 'HWPriceGuide',
        'tsm' => 'TSM / Mini GT',
        'inno64' => 'Inno64',
        'para64' => 'Para64',
        'horizon' => 'Horizon Diecast',
    ];

    /**
     * The provenance key a car added from this item is linked by, and the
     * label the visual model knows its photos under (StableKeys).
     */
    public function castingKey(): string
    {
        return StableKeys::compose($this->source, 'item:'.$this->source_key);
    }

    public function variationKey(): string
    {
        return StableKeys::compose($this->source, 'item:'.$this->source_key.':v');
    }

    protected function casts(): array
    {
        return ['image_urls' => 'array', 'year' => 'integer'];
    }
}
