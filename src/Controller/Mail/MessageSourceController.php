<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Helper\CharsetHelper;
use App\Entity\Mail\Message;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\MessageSourceBuilder;
use App\Service\Mail\RawMessageResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Standalone "show original" and "print" views for a single message, opened
 * in a new tab from the per-message menu in the thread view.
 *
 * The source view shows the original RFC822 bytes where they are on disk or
 * the provider can still supply them, and a reconstruction only where they
 * are not — the same order BlobResolver::message() uses, so a blob download
 * and a "show original" of the same message can never disagree.
 *
 * The docblock here used to say no raw blob was stored. That stopped being
 * true when Message::$rawPath arrived, but this controller was never told:
 * it asked MessageSourceBuilder unconditionally and served a flattening of
 * the parsed header map instead of the message. What that looks like is not
 * subtle once you know — php-imap promotes structured-header parameters to
 * top-level attributes, so a DKIM-Signature comes back out as a column of
 * `v:`, `a:`, `d:`, `s:`, `bh:`, `b:` lines, `Content-Type` sheds its
 * boundary onto a line of its own, and php-imap's synthetic `priority` and
 * `spoofed` appear as though they had been headers all along. Underneath it,
 * only the decoded text body: no MIME structure, and no HTML part at all.
 */
#[Route('/mail/message/{id}', name: 'app_mail_message_', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class MessageSourceController extends AbstractController
{
    public function __construct(
        private readonly MessageSourceBuilder $sourceBuilder,
        private readonly RawMessageResolver $rawResolver,
    ) {}

    #[Route('/original', name: 'original', methods: ['GET'])]
    public function original(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        $raw = $this->rawSource($message);

        return $this->render('mail/original.html.twig', [
            'message'       => $message,
            'source'        => $raw ?? $this->sourceBuilder->build($message),
            'reconstructed' => null === $raw,
            'auth'          => $this->authenticationResults($message),
        ]);
    }

    #[Route('/print', name: 'print', methods: ['GET'])]
    public function print(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('mail/print.html.twig', [
            'message' => $message,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The original bytes, or null when this message has none to give.
     *
     * Null is ordinary rather than an error. RawMessageResolver deliberately
     * never re-opens an IMAP connection, so a plain-IMAP message synced before
     * raw storage existed resolves to null for good — the caller falls back to
     * a reconstruction and the page says as much. Showing a flattened header
     * map while silently calling it the original is the worse of the two
     * failures: it is the one that gets pasted into a bug report.
     *
     * Forced to UTF-8 because a raw message is bytes and not text. A single
     * unencoded latin-1 Subject or an 8-bit body is enough to make Twig's
     * escaper throw "not a valid UTF-8 string", which would turn a cosmetic
     * bug into a 500 on exactly the mail somebody opened this page to inspect.
     * Same guard, for the same reason, as HeaderNormalizer.
     */
    private function rawSource(Message $message): ?string
    {
        $path = $this->rawResolver->absolutePathFor($message);

        if (null === $path || false === is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);

        return false === $bytes ? null : CharsetHelper::ensureUtf8($bytes);
    }

    /**
     * SPF / DKIM / DMARC verdicts, parsed out of Authentication-Results when
     * the provider recorded it.
     *
     * @return array<string, string>
     */
    private function authenticationResults(Message $message): array
    {
        $headers = $message->headers ?? [];
        $raw     = null;

        foreach ($headers as $key => $value) {
            if ('authentication-results' === strtolower((string) $key)) {
                $raw = true === is_array($value) ? implode(' ', $value) : (string) $value;

                break;
            }
        }

        if (null === $raw) {
            return [];
        }

        $results = [];

        foreach (['spf', 'dkim', 'dmarc'] as $mechanism) {
            if (1 === preg_match('/\b'.$mechanism.'=([a-z]+)/i', $raw, $matches)) {
                $results[strtoupper($mechanism)] = strtolower($matches[1]);
            }
        }

        return $results;
    }

}
