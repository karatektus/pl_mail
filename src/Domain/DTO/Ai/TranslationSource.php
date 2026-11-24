<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * The words of a message as the translator is given them (#713), and the two
 * ways they can be less than the whole mail. Both are said on the page, beside
 * the translation: a reader who cannot read the original cannot see for
 * themselves what was left out.
 */
final readonly class TranslationSource
{
    /**
     * @param string $text           what is sent, and what the stored row's hash is of
     * @param bool   $isPartial      the mail was longer than MessageTranslator::TEXT_BUDGET
     *                               and only its beginning is sent
     * @param bool   $leavesOutQuote the quoted history of a reply or forward was taken off;
     *                               it is earlier mail, translated where it is shown on its own
     */
    public function __construct(
        public string $text,
        public bool $isPartial,
        public bool $leavesOutQuote,
    ) {
    }

    public function hash(): string
    {
        return hash('sha256', $this->text);
    }
}
