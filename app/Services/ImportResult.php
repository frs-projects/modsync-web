<?php

namespace App\Services;

/**
 * What an import did. Skipped entries name their path and why.
 */
final class ImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $removed = 0;

    /** @var list<string> */
    public array $skipped = [];

    /** @var list<string> */
    public array $warnings = [];
}
