<?php

declare(strict_types=1);

namespace App\Service\Mail;

use DateTimeImmutable;
use Throwable;

/**
 * When the user's own mail provider took delivery of a message.
 *
 * Read from the newest `Received:` header, which the receiving server writes
 * itself, with its own clock, at the top of the message.
 *
 * WHY NOT THE DATE THE MESSAGE ALREADY CARRIES. Message::$receivedAt is the
 * right date to show and to sort by, and the wrong one to measure from. On
 * IMAP it is the `Date:` header: the sender's clock, set when the mail was
 * written. On Gmail it is `internalDate`, which is documented as the moment
 * Google accepted the message and in practice follows the `Date:` header too.
 * A newsletter stamped when its batch was built, a first-time sender held back
 * by greylisting, an application answered by a queue that runs every ten
 * minutes — each was reported by Admin → Performance as plMail being that late,
 * when plMail had stored it within seconds of it existing anywhere plMail
 * could see.
 *
 * The header's last `;` separates the route from the date (RFC 5322 §3.6.7);
 * what follows may end in a comment — "(PDT)" — which is dropped.
 */
final class ProviderAcceptTime
{
    /**
     * @param array<string, string|list<string>>|null $headers as HeaderNormalizer leaves them
     */
    public function fromHeaders(?array $headers): ?DateTimeImmutable
    {
        $received = $headers['received'] ?? null;

        // Several hops is the normal case, newest first.
        if (true === is_array($received)) {
            $received = $received[0] ?? null;
        }

        if (false === is_string($received)) {
            return null;
        }

        $semicolon = strrpos($received, ';');

        if (false === $semicolon) {
            return null;
        }

        $date = trim((string) preg_replace('/\([^()]*\)/', '', substr($received, $semicolon + 1)));

        if ('' === $date) {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($date);
        } catch (Throwable) {
            return null;
        }

        // In the process's own zone, like every other date on the row: the
        // column has no zone of its own, so "05:15 -0700" stored as it stands
        // would be read back as a quarter past five here.
        return new DateTimeImmutable()->setTimestamp($parsed->getTimestamp());
    }
}
