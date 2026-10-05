<?php

namespace App\Services;

use RuntimeException;

/**
 * A file could not be added or changed; the message is for the panel user.
 */
class PackFileException extends RuntimeException {}
