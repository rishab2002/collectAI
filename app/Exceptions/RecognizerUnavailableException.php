<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The local recognizer couldn't be reached or couldn't read a photo.
 */
class RecognizerUnavailableException extends RuntimeException {}
