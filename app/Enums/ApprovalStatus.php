<?php

namespace App\Enums;

/**
 * Lifecycle of a record created by the AI pipeline.
 *
 * The Insertion Agent writes records as Pending; the Review Agent scores them
 * and only an admin (or a high enough confidence score) promotes them to
 * Approved, which is what the public catalog reads.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
