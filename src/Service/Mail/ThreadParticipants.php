<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ThreadRowMessage;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;

/**
 * The "who" column of a conversation row.
 *
 * The list used to print the sender of the newest message, which meant every
 * conversation you had answered was attributed to you — the reply is the last
 * message, so a thread you were writing *in* looked like a thread you were
 * writing *to*. What a list row wants is the cast of the conversation, in the
 * order they joined it, which is also what every other mail client shows.
 */
final class ThreadParticipants
{
    /** Names shown in full before the middle is elided. */
    private const int MAX_NAMES = 3;

    /**
     * @param string $me how to label the reader's own address
     *
     * @return list<string> display names, oldest participant first
     */
    public function forThread(MessageThread $thread, string $me = 'me'): array
    {
        return $this->forMessages($thread->messages, $thread->account, $me);
    }

    /**
     * The same answer from the row fields alone — a list page never hydrates
     * the messages themselves, see App\Service\Mail\ThreadRows.
     *
     * @param iterable<Message|ThreadRowMessage> $messages oldest first
     *
     * @return list<string>
     */
    public function forMessages(iterable $messages, ?Account $account, string $me = 'me'): array
    {
        $messages = is_array($messages) ? $messages : iterator_to_array($messages, false);
        $owned    = $this->ownedAddresses($account);
        $names    = $this->senders($messages, $owned, $me);

        // A thread nobody else has written in — a sent mail, or a draft. Its
        // recipients are the interesting party; falling back to "me" alone
        // would make every row in Sent identical.
        if ([] === $names || [$me] === $names) {
            $recipients = $this->recipients($messages, $owned);

            if ([] !== $recipients) {
                return $this->elide($recipients);
            }
        }

        return $this->elide($names);
    }

    /**
     * @param array<Message|ThreadRowMessage> $messages
     * @param list<string>                    $owned
     *
     * @return list<string>
     */
    private function senders(array $messages, array $owned, string $me): array
    {
        $names = [];

        foreach ($messages as $message) {
            $address = $this->normalise($message->fromAddress);

            if ('' === $address) {
                continue;
            }

            $names[$address] ??= true === in_array($address, $owned, true)
                ? $me
                : $this->displayName($message->fromName, $address);
        }

        return $this->collapse(array_values($names));
    }

    /**
     * Everyone the newest message was addressed to, the reader excluded.
     *
     * @param array<Message|ThreadRowMessage> $messages
     * @param list<string>                    $owned
     *
     * @return list<string>
     */
    private function recipients(array $messages, array $owned): array
    {
        $newest = $this->newest($messages);

        if (null === $newest) {
            return [];
        }

        $names = [];

        foreach ($newest->toAddresses ?? [] as $entry) {
            $address = $this->normalise($entry['address'] ?? null);

            if ('' === $address || true === in_array($address, $owned, true)) {
                continue;
            }

            $names[$address] ??= $this->displayName($entry['name'] ?? null, $address);
        }

        return $this->collapse(array_values($names));
    }

    /**
     * One name per person, not one name per address.
     *
     * De-duplication happens by address first, because that is what identifies
     * a participant — but several addresses can resolve to the SAME display
     * name, and then the column prints it twice. The reported case is a thread
     * carrying two of the reader's own addresses: both map to "me", and the row
     * read "me, me". A sender writing from two of their own addresses under one
     * display name is the same fault with a different name in it.
     *
     * The first occurrence wins, so the order participants joined the
     * conversation in — which is the whole point of this column — survives.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function collapse(array $names): array
    {
        return array_values(array_unique($names));
    }

    /**
     * @param array<Message|ThreadRowMessage> $messages
     */
    private function newest(array $messages): Message|ThreadRowMessage|null
    {
        $newest = null;

        // The association is ordered by date, but a thread assembled in memory
        // during a sync is not, so the newest is picked rather than assumed.
        foreach ($messages as $message) {
            // createdAt closes the chain and is never null, so $at always has
            // a value to compare — only the first pass has nothing to compare
            // it against.
            $at = $message->receivedAt ?? $message->sentAt ?? $message->createdAt;

            if (null === $newest) {
                $newest = $message;

                continue;
            }

            $newestAt = $newest->receivedAt ?? $newest->sentAt ?? $newest->createdAt;

            if (null === $newestAt || $at >= $newestAt) {
                $newest = $message;
            }
        }

        return $newest;
    }

    /**
     * Long casts collapse to "first … last": the column is one line wide, and
     * the two ends are what identify a conversation.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function elide(array $names): array
    {
        if (count($names) <= self::MAX_NAMES) {
            return $names;
        }

        return [$names[0], '…', $names[count($names) - 1]];
    }

    private function displayName(?string $name, string $address): string
    {
        $name = null !== $name ? trim($name) : '';

        return '' !== $name ? $name : $address;
    }

    /**
     * @return list<string>
     */
    private function ownedAddresses(?Account $account): array
    {
        if (null === $account) {
            return [];
        }

        return array_values(array_filter(array_map(
            $this->normalise(...),
            $account->ownedAddresses,
        )));
    }

    private function normalise(?string $address): string
    {
        return mb_strtolower(trim((string) $address));
    }
}
