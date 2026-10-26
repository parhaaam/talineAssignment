<?php

namespace App\Exceptions;

use RuntimeException;

/** Internal signal: the unique idempotency insert lost the race; the caller re-reads the winner. */
class IdempotencyKeyTaken extends RuntimeException
{
}
