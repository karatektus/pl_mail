<?php

declare(strict_types=1);

namespace App\Domain\Exception;


/**
 * HTTP 410 from a delta query: the stored deltaLink has expired or been
 * invalidated. The delta chain cannot be resumed — the folder must be
 * re-enumerated from scratch.
 */
final class GraphResyncRequiredException extends GraphApiException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message, 410);
    }
}
