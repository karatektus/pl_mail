<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Any non-success response from Microsoft Graph.
 */
class GraphApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
