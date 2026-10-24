<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\DomainHelper;
use App\Domain\Helper\RecipientHeaderHelper;
use App\Entity\Mail\Message;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Who a message was addressed to, for the message header.
 *
 * Two jobs. `message_addresses()` answers with the stored to/cc/bcc list and
 * falls back to the message's own header bag when the column is empty — rows
 * synced before the IMAP recipient bug was fixed have the headers but not the
 * columns, and the reader should not be told "undisclosed recipients" about a
 * message whose To: line is sitting right there in the same row. Running
 * `bin/console app:backfill recipients` moves those rows onto the columns for
 * good; until then this keeps the header honest.
 *
 * `message_recipient_summary()` compacts that into the line the header shows
 * at a glance: the first few names, a count for the rest, and — when there
 * genuinely is nobody — an emptiness the template can state out loud rather
 * than silently omit.
 *
 * `message_reply_to_elsewhere()` is the one that is about the sender rather than
 * the recipients: where an answer would go when that is not the organisation
 * the mail says it is from.
 */
final class MessageRecipientsExtension extends AbstractExtension
{
    /** Names shown before the rest become "+N". */
    private const int SUMMARY_NAMES = 3;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('message_addresses', $this->addresses(...)),
            new TwigFunction('message_recipient_summary', $this->summary(...)),
            new TwigFunction('message_reply_to_elsewhere', $this->replyToElsewhere(...)),
        ];
    }

    /**
     * The Reply-To addresses that are NOT on the From address's organisation.
     *
     * "Reply" is the one button that sends mail to an address the sender chose
     * rather than the one on the From line, and a phishing mail that cannot
     * get its answer through a forged From can still get it through here.
     *
     * Reported as a fact for the header to print, never as a warning, and that
     * is deliberate. A different Reply-To domain is how newsletters, ticket
     * systems and mailing lists are set up, so a banner here would fire on
     * ordinary mail and teach exactly the click-through it exists to stop —
     * the same reasoning SenderIdentityChecker is built around. A line that
     * says where the answer goes lets the reader judge it, and costs nothing
     * on the mail where it does not apply.
     *
     * Compared by registrable domain, so `help@support.paypal.com` answering
     * for `noreply@paypal.com` is the same organisation and silent. A host
     * that cannot be reduced (an IP literal) is compared whole. With no usable
     * From address there is nothing to compare against and nothing is said.
     *
     * @return list<array{name: string, address: string}>
     */
    public function replyToElsewhere(Message $message): array
    {
        $from = $this->organisationOf($message->fromAddress);

        if (null === $from) {
            return [];
        }

        return array_values(array_filter(
            $this->addresses($message, 'reply-to'),
            fn (array $entry): bool => $from !== $this->organisationOf($entry['address']),
        ));
    }

    /**
     * The registrable domain of an address, or its whole host when that cannot
     * be reduced; null when there is no host at all.
     */
    private function organisationOf(?string $address): ?string
    {
        $address = (string) $address;
        $at      = strrpos($address, '@');

        if (false === $at) {
            return null;
        }

        $host = trim(substr($address, $at + 1));

        if ('' === $host) {
            return null;
        }

        return DomainHelper::registrable($host) ?? $host;
    }

    /**
     * @param 'to'|'cc'|'bcc'|'reply-to' $field
     *
     * @return list<array{name: string, address: string}>
     */
    public function addresses(Message $message, string $field): array
    {
        $stored = match ($field) {
            'cc'       => $message->ccAddresses,
            'bcc'      => $message->bccAddresses,
            // No column at all: Reply-To only ever lives in the header bag.
            'reply-to' => [],
            default    => $message->toAddresses,
        } ?? [];

        if ([] !== $stored) {
            return self::clean($stored);
        }

        // Bcc is deliberately not re-derived: a Bcc: header on a received
        // message is the sender's own copy leaking through, and a row that has
        // no bcc column is the ordinary case rather than a gap to fill.
        if ('bcc' === $field) {
            return [];
        }

        return RecipientHeaderHelper::addresses($message->headers ?? [], $field);
    }

    /**
     * The at-a-glance line: "me", "Alice", "Alice, Bob" (+2 more).
     *
     * Cc counts towards the overflow but not towards the names — the question
     * the line answers is "was this addressed to me, or to a crowd", and a Cc
     * recipient billed as a headline recipient answers it wrongly. Unless
     * there is no To: at all, in which case the Cc list is the only set of
     * recipients there is, and naming nobody while counting them would render
     * as "to  +1".
     *
     * @return array{names: list<string>, extra: int, empty: bool}
     */
    public function summary(Message $message): array
    {
        $to = $this->addresses($message, 'to');
        $cc = $this->addresses($message, 'cc');

        if ([] === $to && [] === $cc) {
            return ['names' => [], 'extra' => 0, 'empty' => true];
        }

        $named = [] !== $to ? $to : $cc;

        // The reader asked for the real address rather than "me": on their own
        // received mail the recipient is always themselves, so "to me" told
        // them nothing. An owned address renders as the address itself — which
        // is the one the mail actually reached, the thing worth seeing when a
        // reader has several. Not its display name either, which hides the very
        // address they asked to see. Everyone else keeps a name where there is
        // one, since a stranger's name reads better than their address.
        $owned = array_flip($message->account->ownedAddresses);
        $names = [];

        foreach (array_slice($named, 0, self::SUMMARY_NAMES) as $entry) {
            $names[] = true === isset($owned[$entry['address']])
                ? $entry['address']
                : ('' !== $entry['name'] ? $entry['name'] : $entry['address']);
        }

        return [
            'names' => $names,
            'extra' => count($to) + count($cc) - count($names),
            'empty' => false,
        ];
    }

    /**
     * Stored lists predate the current shape in places: entries can carry a
     * null name, or an address that never parsed. Same filter the header
     * fallback applies, so both sources answer with the same thing.
     *
     * @param array<mixed> $stored
     *
     * @return list<array{name: string, address: string}>
     */
    private static function clean(array $stored): array
    {
        $result = [];

        foreach ($stored as $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            $address = AddressHelper::email(
                true === is_string($entry['address'] ?? null) ? $entry['address'] : null,
            );

            if ('' === $address) {
                continue;
            }

            $result[] = [
                'name'    => AddressHelper::name(
                    true === is_string($entry['name'] ?? null) ? $entry['name'] : null,
                ),
                'address' => $address,
            ];
        }

        return $result;
    }
}
