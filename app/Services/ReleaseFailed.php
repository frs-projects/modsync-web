<?php

namespace App\Services;

use RuntimeException;

/**
 * A pack could not be released; the errors are for the panel user.
 */
class ReleaseFailed extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
