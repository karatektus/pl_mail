<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Helper\DomainHelper;

/**
 * A sender as a list row prints it: the display name, and — as a separate
 * field, never concatenated into it — the domain the mail really comes from.
 *
 * Why a row needs the second half at all. A display name is free text the
 * sender chose, so `"PayPal" <service@evil.example>` and the real thing look
 * identical in a list that prints names only. The domain is the one part of the
 * line the sender cannot choose, and it has to be where the eye already is,
 * not behind a click.
 *
 * Why separate fields: the sender column is narrow and truncates from the END,
 * and the domain is the end. Keeping it its own field lets the template make
 * the name give way first and the domain never.
 *
 * The REGISTRABLE domain rather than the full host, so that
 * `paypal.com.evil.example` is shown as `evil.example` and not as something
 * that begins with the brand it imitates. An internationalised domain stays in
 * its `xn--` form on purpose: that is the form in which a lookalike gives
 * itself away.
 *
 * What this does NOT do: judge. It prints a fact. The judging, where plMail
 * does any, is {@see \App\Service\Mail\SenderIdentityChecker}, which needs a
 * message's headers that a list row has never loaded.
 */
final readonly class SenderLabel
{
    public function __construct(
        /** The display name; the address itself when there is no name. */
        public string $name,
        /** Where the mail really comes from, or null when the name already is the address. */
        public ?string $domain = null,
    ) {
    }

    public static function of(?string $name, ?string $address): self
    {
        $name    = trim((string) $name);
        $address = trim((string) $address);

        // No name, or a name that only repeats the address: the address is the
        // whole label and says everything a domain beside it would.
        if ('' === $name || 0 === strcasecmp($name, $address)) {
            return new self($address);
        }

        $at = strrpos($address, '@');

        if (false === $at) {
            return new self($name);
        }

        // A host the helper cannot reduce — an IP literal, a bare label — is
        // still shown, as it is: hiding it would hide the oddest senders.
        $domain = DomainHelper::registrableOfAddress($address)
            ?? strtolower(trim(substr($address, $at + 1)));

        return new self($name, '' !== $domain ? $domain : null);
    }
}
