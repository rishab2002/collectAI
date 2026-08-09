<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A pipeline run that could not produce a reading.
 *
 * Its message is written to be shown to an admin on the review screen — it
 * lands in submissions.error, which is what that screen renders when a run
 * fails. So it says what stopped and what to do, not which class threw.
 */
class OcrFailedException extends RuntimeException {}
