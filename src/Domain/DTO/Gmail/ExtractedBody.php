<?php

declare(strict_types=1);

namespace App\Domain\DTO\Gmail;

/**
 * What one walk of a Gmail MIME tree yields.
 *
 * Was an `array{string, string, list}` destructured at the call site, which was
 * already at the limit of what a shape should carry before this added a fourth
 * thing to it — and a fourth positional element is the point where the reader
 * has to go and count the returns.
 *
 * The two part lists are separate because they are persisted differently.
 * `lazyParts` have an attachmentId and are stored as a `gmail://` stub for
 * AttachmentResolver to materialise on first access. `inlineParts` arrived
 * with their bytes already in the payload — Gmail inlines small parts as
 * base64 in `body.data` and gives them no attachmentId at all — so there is
 * nothing to fetch later and the bytes go straight to disk.
 */
final readonly class ExtractedBody
{
    /**
     * @param list<array<string,mixed>>                    $lazyParts   parts with an attachmentId
     * @param list<array{part: array<string,mixed>, bytes: string}> $inlineParts parts whose bytes came inline
     */
    public function __construct(
        public string $bodyText = '',
        public string $bodyHtml = '',
        public array  $lazyParts = [],
        public array  $inlineParts = [],
    ) {
    }
}
