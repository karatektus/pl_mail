<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * One completion, with what it cost and why it failed.
 *
 * OllamaClient used to answer `?string` and throw the timing block away with
 * the rest of the body. It answers this instead, so the one place that knows
 * the numbers can hand them to the one place that knows which feature asked.
 * AiAssistant unwraps it, so nothing outside src/Service/Ai sees the change.
 */
final readonly class AiChatResult
{
    public function __construct(
        public ?string      $content,
        public AiCallTiming $timing,
        public bool         $succeeded,
        public ?string      $errorKind = null,
    ) {
    }

    public static function ok(string $content, AiCallTiming $timing): self
    {
        return new self($content, $timing, true);
    }

    public static function failed(string $errorKind, ?AiCallTiming $timing = null): self
    {
        return new self(null, $timing ?? AiCallTiming::none(), false, $errorKind);
    }
}
