<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;

/**
 * Which addresses are the account's own, as far as one message is concerned.
 *
 * For an account with aliases only, that is Account::$ownedAddresses and the
 * message has nothing to add. A catch-all domain is what makes it a question
 * about the message: `anything@example.com` reaches the mailbox, so the
 * account answers to more addresses than any list can hold.
 *
 * NOT EVERY ADDRESS AT THE DOMAIN, THOUGH
 *
 * A catch-all catches what has no mailbox of its own. On a family or company
 * domain the other people have mailboxes: `anna@example.com` is at the
 * catch-all domain and is somebody else. Treating the whole domain as "me"
 * would drop Anna from a reply-all, answer her mail as though it were the
 * user's own, and accept a meeting on her behalf.
 *
 * So an address at a catch-all domain is the account's own only where this
 * message is evidence of it:
 *
 *  - it is what the server says the message was delivered to (X-Pm-Original-To,
 *    X-Original-To, Delivered-To, Envelope-To, the `for <…>` of a Received
 *    hop) — the best evidence there is, and when it is present nothing else
 *    at the domain is claimed;
 *  - failing that, it is a visible recipient (To, Cc). Without a delivery
 *    header there is no telling the user's address from a colleague's in the
 *    same To line, and both are claimed — the one place this still guesses;
 *  - or it is the From of a message the account itself sent, which is known
 *    by where the message is filed, not by the address.
 *
 * THE MAILBOX'S OWN ADDRESS IS NOT A CATCH (#631)
 *
 * Some providers deliver catch-all mail to the mailbox under its own address
 * and write THAT into Delivered-To: Purelymail delivers `test@domain` to
 * `user@domain` with `Delivered-To: user@domain`, and Soverin does the same.
 * The mailbox's address is usually at the catch-all domain itself, so it
 * passed as "the catch-all address this came in for", and every reply went
 * out from the primary address. A delivery header naming one of the
 * account's own addresses says where the mail was put, not what it was sent
 * to, so it is no evidence about the catch-all: the original recipient is
 * looked for in the other headers (Purelymail keeps it in X-Pm-Original-To,
 * Soverin in a Received hop's `for <…>`), and without one in To and Cc, as
 * for a message with no delivery header at all.
 */
final class OwnAddressHelper
{
    /**
     * Ordered by how close each is to what the sender wrote: the
     * original-recipient headers before the ones a provider rewrites to the
     * mailbox it delivered to. ReplyFromResolver answers from the first match.
     */
    private const array DELIVERY_HEADERS = ['x-pm-original-to', 'x-original-to', 'delivered-to', 'envelope-to'];

    /**
     * Every address that is the account's own in this message: its owned
     * aliases, plus the catch-all addresses the message came in for.
     *
     * @return list<string> lowercased
     */
    public static function forMessage(Message $message): array
    {
        $account = $message->account ?? null;

        if (null === $account) {
            return [];
        }

        return array_values(array_unique(array_merge(
            $account->ownedAddresses,
            self::caughtRecipients($message),
        )));
    }

    /**
     * The catch-all addresses this message was delivered to. Empty for an
     * account with no catch-all domain.
     *
     * @return list<string> lowercased
     */
    public static function caughtRecipients(Message $message): array
    {
        $account = $message->account ?? null;

        if (null === $account || [] === $account->catchAllDomains) {
            return [];
        }

        $catches = static fn (string $address): bool => true === $account->catchesAddress($address);

        // Not the account's own addresses: the mailbox a provider delivered
        // to is not the address the mail was sent to (#631, class docblock).
        $owned     = $account->ownedAddresses;
        $delivered = array_values(array_unique(array_filter(
            self::deliveredTo($message),
            static fn (string $address): bool => true === $catches($address) && false === in_array($address, $owned, true),
        )));

        if ([] !== $delivered) {
            return $delivered;
        }

        $visible = [];

        foreach ([$message->toAddresses ?? [], $message->ccAddresses ?? []] as $list) {
            foreach ($list as $entry) {
                $visible[] = AddressHelper::email(
                    true === is_array($entry) ? (string) ($entry['address'] ?? $entry['email'] ?? '') : (string) $entry,
                );
            }
        }

        return array_values(array_unique(array_filter($visible, $catches)));
    }

    /**
     * Whether the account wrote this message.
     *
     * An owned alias in From is enough. A catch-all address in From is not —
     * see the class docblock — unless the message is filed under Sent or
     * Drafts, which only the account's own mail is.
     */
    public static function sentByAccount(Message $message): bool
    {
        $account = $message->account ?? null;
        $from    = AddressHelper::email($message->fromAddress);

        if (null === $account || '' === $from) {
            return false;
        }

        if (true === in_array($from, $account->ownedAddresses, true)) {
            return true;
        }

        if (false === $account->catchesAddress($from)) {
            return false;
        }

        foreach ($message->labels as $label) {
            if (LabelRole::Sent === $label->role || LabelRole::Drafts === $label->role) {
                return true;
            }
        }

        return false;
    }

    /**
     * The addresses in the delivery headers, in the order the headers are
     * listed above, then the ones the Received hops were for.
     *
     * @return list<string> lowercased
     */
    public static function deliveredTo(Message $message): array
    {
        $headers   = $message->headers ?? [];
        $addresses = [];

        foreach (self::DELIVERY_HEADERS as $field) {
            foreach (RecipientHeaderHelper::addresses($headers, $field) as $entry) {
                $addresses[] = AddressHelper::email($entry['address']);
            }
        }

        foreach (self::receivedFor($headers) as $address) {
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The `for <address>` of each Received hop (RFC 5321 §4.4), newest first.
     *
     * Last of the evidence: a hop names the recipient it was handling then,
     * which on the provider's own hops is the original address before one of
     * them names the mailbox (Soverin, #631). Hops on the sender's side name
     * addresses that are neither the account's nor at its catch-all domain,
     * and every caller filters for those.
     *
     * @param array<string, string|array<int, string>|null> $headers
     *
     * @return list<string> lowercased
     */
    private static function receivedFor(array $headers): array
    {
        $received = RecipientHeaderHelper::canonicalise($headers)['received'] ?? '';

        if ('' === $received || 0 === preg_match_all('/\bfor\s+<?([^\s<>;]+@[^\s<>;]+?)>?\s*(?:;|\(|$)/im', $received, $matches)) {
            return [];
        }

        $addresses = [];

        foreach ($matches[1] as $candidate) {
            $address = AddressHelper::email($candidate);

            if (true === AddressHelper::isValidEmail($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
