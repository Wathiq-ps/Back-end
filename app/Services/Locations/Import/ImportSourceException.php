<?php

namespace App\Services\Locations\Import;

use RuntimeException;

/**
 * A source file that can't be read as expected — missing, or a zip without
 * the entry it should contain. Expected operator error, so the commands
 * report it in one line instead of a stack trace.
 */
class ImportSourceException extends RuntimeException {}
