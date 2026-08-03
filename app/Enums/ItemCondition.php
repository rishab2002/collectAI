<?php

namespace App\Enums;

/**
 * Condition of a physical unit, as recorded on a collector's own entry for a
 * variation.
 *
 * Not yet on price_histories, which has no condition column — worth adding when
 * price tracking starts in earnest, since a loose car and a carded one are
 * effectively different markets and averaging them together would be
 * misleading.
 */
enum ItemCondition: string
{
    case Mint = 'mint';
    case Carded = 'carded';
    case Opened = 'opened';
    case Loose = 'loose';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Mint => 'Mint on card',
            self::Carded => 'Carded',
            self::Opened => 'Opened packaging',
            self::Loose => 'Loose',
            self::Damaged => 'Damaged',
        };
    }
}
