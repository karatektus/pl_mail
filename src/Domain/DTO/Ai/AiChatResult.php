<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Service\Ai\OllamaClient;

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
        /**
         * The status the host refused with, when it refused with one (#222).
         *
         * The error kind folds every refusal but a 404 into `http_status`, which
         * is right for the metrics table and too coarse for a caller deciding
         * whether to ask again: a 503 while the host restarts will pass, a 400
         * for this particular request will not.
         */
        public ?int         $httpStatus = null,
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

    /** The host answered with a status other than 200; 404 keeps its own kind. */
    public static function refused(int $status, ?AiCallTiming $timing = null): self
    {
        return new self(
            null,
            $timing ?? AiCallTiming::none(),
            false,
            404 === $status ? OllamaClient::ERROR_HTTP_404 : OllamaClient::ERROR_HTTP_STATUS,
            $status,
        );
    }
}
