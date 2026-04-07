<?php

declare(strict_types=1);

namespace App\Http;

use Exception;

/**
 * Thrown when a controller or validator detects an invalid/unexpected request
 * (e.g. unknown query parameters, malformed input that fails early validation).
 * The front-controller catches this and renders the 400 error page.
 */
class BadRequestException extends Exception {}
