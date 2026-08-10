<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a Metadata Agent call cannot be turned into identifications.
 *
 * Deliberately not raised for a casting the model declined to identify — that
 * is an "unknown" verdict, which is an answer and is recorded as one. This is
 * for the call itself failing: a refusal, a truncated response, a body that
 * isn't the schema, or answers that don't line up with what was asked.
 */
class MetadataFailedException extends RuntimeException {}
