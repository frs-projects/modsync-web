<?php

namespace App\Services;

/**
 * What stops a pack's draft from being released (errors), and what players will notice
 * (warnings).
 */
final readonly class ReleaseCheck
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(public array $errors, public array $warnings) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
