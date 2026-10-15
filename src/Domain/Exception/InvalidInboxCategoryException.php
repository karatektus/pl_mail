<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * A name somebody typed for an inbox category cannot be used.
 *
 * Carries a translation key rather than a sentence, so the controller that
 * catches it can say why in the reader's own language without this class
 * knowing there is a translator.
 */
final class InvalidInboxCategoryException extends \DomainException
{
    public function __construct(public readonly string $translationKey)
    {
        parent::__construct($translationKey);
    }
}
