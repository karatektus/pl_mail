<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ReadReceiptDecision;
use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Whether a read receipt gets sent for a message, and to whom.
 *
 * One place, because there are three callers who must all reach the same
 * answer — the read transition in ThreadStatusUpdater, the explicit-confirm
 * controller action, and the message view deciding whether to draw a prompt —
 * and a policy re-derived per caller is a policy that eventually differs per
 * caller. The one that matters is the read transition: it fires without a
 * human in the loop, so it is the one that must not be able to be more
 * generous than the setting says.
 *
 * Every path out of decide() that is not a positive answer returns
 * ReadReceiptDecision::silent(). That is deliberate: absent setting, absent
 * alias, unparseable address, message from our own mailbox, no request at all
 * — all of them mean "send nothing", and making them share one return value
 * means a new early exit cannot accidentally fall through into a send.
 *
 * THE MISMATCH DOWNGRADE
 * ──────────────────────
 * A message may name any address in Disposition-Notification-To, and nothing
 * requires it to be the one in From. That is the trick: mail is sent claiming
 * to be from someone the recipient corresponds with, and the receipt is
 * directed somewhere else entirely — so the read confirmation, with its
 * timestamp, goes to a third party the reader never heard of. It also works as
 * plain confirmation-of-life against an address list, since the reply goes
 * wherever the sender chose regardless of whether the From address bounces.
 *
 * So the pair has to agree. When it does not, the decision is pulled back to
 * Ask no matter what the user chose — never to Never, because the request may
 * be perfectly legitimate (a mailing system answering to its bounce address is
 * the common case) and silently dropping it would be answering a security
 * question on the user's behalf. Ask puts a human in front of it, which is the
 * only thing that can actually tell the two apart.
 */
final readonly class ReadReceiptPolicy
{
    public function __construct(
        private HeaderNormalizer $headers,
    ) {
    }

    /**
     * What to do about the receipt this message asks for.
     *
     * Reads the flag rather than the header bag: ReadReceiptStep already
     * decided at ingest whether a request is present, and re-parsing headers
     * on every mailbox open is what the flag exists to avoid.
     */
    public function decide(Message $message): ReadReceiptDecision
    {
        if (false === $message->wantsReadReceipt()) {
            return ReadReceiptDecision::silent();
        }

        // Our own outgoing mail carries the flag too — it is one column for
        // both directions — and a sent message is not something to answer.
        if (null !== $message->sentAt) {
            return ReadReceiptDecision::silent();
        }

        $notifyTo = $this->notifyAddress($message);

        if (null === $notifyTo) {
            return ReadReceiptDecision::silent();
        }

        $account   = $message->account;
        $recipient = $this->receivingAddress($message, $account);

        if (null === $recipient) {
            // Nothing to put in Final-Recipient. An MDN without one is not a
            // valid MDN, and guessing the account's primary address would
            // report a mailbox the sender may never have written to.
            return ReadReceiptDecision::silent();
        }

        $mode = $this->configuredMode($account, $message);

        if (ReadReceiptMode::Never === $mode) {
            return ReadReceiptDecision::silent();
        }

        if (false === $this->notifyMatchesSender($message, $notifyTo)) {
            return new ReadReceiptDecision(
                mode: ReadReceiptMode::Ask,
                notifyTo: $notifyTo,
                finalRecipient: $recipient,
                downgraded: ReadReceiptMode::Always === $mode,
            );
        }

        return new ReadReceiptDecision(
            mode: $mode,
            notifyTo: $notifyTo,
            finalRecipient: $recipient,
        );
    }

    /**
     * The mode in force for the address this message was delivered to.
     *
     * Per-alias first, account default second, Never last. The alias lookup
     * matches the message's recipients against the account's aliases, so a
     * mailbox holding a work address and a personal one answers according to
     * which of them was written to — which is the entire reason the setting is
     * per alias.
     */
    public function configuredMode(Account $account, Message $message): ReadReceiptMode
    {
        $alias = $this->matchAlias($account, $message);

        if (null !== $alias && null !== $alias->id) {
            $stored = $account->getSetting(Account::readReceiptAliasSetting((int) $alias->id));

            // An alias with no stored answer falls through to the account
            // default rather than to Never, so setting the default once covers
            // every address that has not been given its own.
            if (null !== $stored) {
                return ReadReceiptMode::fromSetting($stored);
            }
        }

        return ReadReceiptMode::fromSetting(
            $account->getSetting(Account::SETTING_READ_RECEIPT_DEFAULT),
        );
    }

    /**
     * Which of this account's aliases the message was addressed to.
     *
     * Delivered-To and X-Original-To lead, because they are what the receiving
     * MTA actually wrote and they survive Bcc — a message Bcc'd to an alias
     * names it in neither To nor Cc, and matching only on those would resolve
     * such a message to no alias at all. To and Cc are the fallback for the
     * providers that do not carry a delivery header.
     */
    public function matchAlias(Account $account, Message $message): ?EmailAlias
    {
        $candidates = [];

        foreach (['delivered-to', 'x-original-to', 'envelope-to'] as $header) {
            $value = $this->headers->first($message->headers, $header);

            if (null !== $value) {
                $candidates[] = $this->bareAddress($value);
            }
        }

        foreach ([$message->toAddresses, $message->ccAddresses] as $list) {
            if (null === $list) {
                continue;
            }

            foreach ($list as $entry) {
                if (true === is_array($entry) && true === array_key_exists('address', $entry)) {
                    $candidates[] = EmailAlias::normalize((string) $entry['address']);
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ('' === $candidate) {
                continue;
            }

            foreach ($account->aliases as $alias) {
                if ($alias->address === $candidate) {
                    return $alias;
                }
            }
        }

        return null;
    }

    /**
     * The address the receipt will claim was the one that read the mail.
     *
     * The matched alias when there is one, the account's own address
     * otherwise. Reporting the alias matters: an MDN naming the account's
     * primary address for mail delivered to an alias tells the sender an
     * address they never wrote to, which is itself a small disclosure.
     */
    private function receivingAddress(Message $message, Account $account): ?string
    {
        $alias = $this->matchAlias($account, $message);

        if (null !== $alias) {
            return $alias->address;
        }

        $own = $account->displayAddress ?? $account->email;

        if (null === $own || '' === trim($own)) {
            return null;
        }

        return EmailAlias::normalize($own);
    }

    /**
     * Where the sender asked to be told, or null if they did not ask in a form
     * we can answer.
     */
    public function notifyAddress(Message $message): ?string
    {
        foreach (['disposition-notification-to', 'return-receipt-to'] as $header) {
            $raw = $this->headers->first($message->headers, $header);

            if (null === $raw) {
                continue;
            }

            $address = $this->bareAddress($raw);

            if ('' === $address) {
                continue;
            }

            try {
                // Rejects the malformed and the actively hostile alike — a
                // header carrying a newline would otherwise become extra
                // headers on the MDN we build from it.
                new Address($address);
            } catch (RfcComplianceException) {
                continue;
            }

            return $address;
        }

        return null;
    }

    /**
     * Whether the address asking for the receipt is the one that sent the mail.
     *
     * Return-Path counts as agreement as well as From. A mailing system that
     * sends as `news@example.com` and collects receipts at its bounce address
     * is not the attack this guards against, and treating it as one would put
     * a prompt in front of every newsletter that asks.
     */
    private function notifyMatchesSender(Message $message, string $notifyTo): bool
    {
        $from = null !== $message->fromAddress
            ? EmailAlias::normalize($message->fromAddress)
            : '';

        if ('' !== $from && $from === $notifyTo) {
            return true;
        }

        foreach (['return-path', 'sender', 'reply-to'] as $header) {
            $value = $this->headers->first($message->headers, $header);

            if (null === $value) {
                continue;
            }

            if ($this->bareAddress($value) === $notifyTo) {
                return true;
            }
        }

        return false;
    }

    /**
     * The addr-spec out of a header value, lowercased.
     *
     * Handles `Name <a@b>`, a bare `a@b`, and the `<a@b>` form Return-Path
     * always uses. Only the first address is taken: a
     * Disposition-Notification-To listing several is legal and means "tell all
     * of them", which is a fan-out this deliberately does not perform.
     */
    private function bareAddress(string $value): string
    {
        $value = trim($value);

        if (1 === preg_match('/<([^>]+)>/', $value, $matches)) {
            $value = $matches[1];
        }

        $value = explode(',', $value)[0];

        return EmailAlias::normalize($value);
    }
}
