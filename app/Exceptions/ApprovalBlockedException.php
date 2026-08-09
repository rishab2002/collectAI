<?php

namespace App\Exceptions;

use DomainException;

/**
 * Raised when a proposal is approved but the record — or something it would
 * promote on the way up — isn't in a publishable state.
 *
 * Thrown from the domain rather than checked only in the controller so that
 * every future approval path inherits the guard: the review screen today, and
 * the bulk auto-approve above the confidence threshold that config/catalog.php
 * is already shaped for. A screen can forget to check; a constructor can't.
 */
class ApprovalBlockedException extends DomainException
{
    /**
     * @param  list<string>  $reasons  One per record standing in the way, each
     *                                 written to be shown to an admin as-is.
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
