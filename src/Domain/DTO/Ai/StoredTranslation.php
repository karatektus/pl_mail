<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Domain\Enum\Ai\TranslationLanguage;
use DateTimeImmutable;

/**
 * A translation that is already written down, and whether it still translates
 * the message as it is (#713). Two facts for StoredThreadSummary's reason: a
 * stale translation is shown marked, with the button to translate again, rather
 * than thrown away.
 */
final readonly class StoredTranslation
{
    /**
     * @param string                 $text      what the model wrote, already tidied
     * @param TranslationLanguage    $language  the language it is in
     * @param string|null            $from      the ISO 639-1 code the model named for the
     *                                          mail; null when it named none
     * @param bool                   $isFresh   whether the stored source hash still
     *                                          matches the text this message would be
     *                                          translated from right now
     * @param DateTimeImmutable|null $writtenAt null only when the stored timestamp could
     *                                          not be parsed
     */
    public function __construct(
        public string $text,
        public TranslationLanguage $language,
        public ?string $from,
        public bool $isFresh,
        public ?DateTimeImmutable $writtenAt,
    ) {
    }
}
