<?php

namespace App\Enums;

/**
 * Condition of a physical unit. Shared by price history (what condition the
 * sale was in) and user collection entries (what condition the owner has).
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
