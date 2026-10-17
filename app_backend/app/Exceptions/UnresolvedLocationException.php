<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A synced record references a state, county or facility this database does not have.
 * The record is flagged for manual review instead of being moved to a guessed location.
 */
class UnresolvedLocationException extends RuntimeException {}
