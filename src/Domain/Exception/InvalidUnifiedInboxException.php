<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * A unified inbox change refused for a reason the reader can be told: the
 * translation key says which. See UnifiedInboxes.
 */
final class InvalidUnifiedInboxException extends \DomainException
{
    public function __construct(public readonly string $translationKey)
    {
        parent::__construct($translationKey);
    }
}
