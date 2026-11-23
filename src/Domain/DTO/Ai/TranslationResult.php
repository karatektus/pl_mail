<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * A finished translation (#713): the call's result, its content without the
 * line that named the mail's language, and that language.
 */
final readonly class TranslationResult
{
    /**
     * @param AiChatResult $chat           the translation, tidied, or why there is none
     * @param string|null  $sourceLanguage the ISO 639-1 code the model named for the mail;
     *                                     null when it named none. See SourceLanguageLine.
     */
    public function __construct(
        public AiChatResult $chat,
        public ?string $sourceLanguage,
    ) {
    }
}
