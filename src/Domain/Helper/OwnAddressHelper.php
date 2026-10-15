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
 *  - it is what the server says the message was delivered to (Delivered-To,
 *    X-Original-To, Envelope-To) — the best evidence there is, and when it is
 *    present nothing else at the domain is claimed;
 *  - failing that, it is a visible recipient (To, Cc). Without a delivery
 *    header there is no telling the user's address from a colleague's in the
 *    same To line, and both are claimed — the one place this still guesses;
 *  - or it is the From of a message the account itself sent, which is known
 *    by where the message is filed, not by the address.
 */
final class OwnAddressHelper
{
    private const array DELIVERY_HEADERS = ['delivered-to', 'x-original-to', 'envelope-to'];

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

        $delivered = array_values(array_unique(array_filter(self::deliveredTo($message), $catches)));

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
     * listed above.
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

        return $addresses;
    }
}
