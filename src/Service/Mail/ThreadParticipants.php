<?php

declare(strict_types=1);

namespace App\Service\Mail;

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
        $owned = $this->ownedAddresses($thread);
        $names = $this->senders($thread, $owned, $me);

        // A thread nobody else has written in — a sent mail, or a draft. Its
        // recipients are the interesting party; falling back to "me" alone
        // would make every row in Sent identical.
        if ([] === $names || [$me] === $names) {
            $recipients = $this->recipients($thread, $owned);

            if ([] !== $recipients) {
                return $this->elide($recipients);
            }
        }

        return $this->elide($names);
    }

    /**
     * @param list<string> $owned
     *
     * @return list<string>
     */
    private function senders(MessageThread $thread, array $owned, string $me): array
    {
        $names = [];

        foreach ($thread->messages as $message) {
            $address = $this->normalise($message->fromAddress);

            if ('' === $address) {
                continue;
            }

            $names[$address] ??= true === in_array($address, $owned, true)
                ? $me
                : $this->displayName($message->fromName, $address);
        }

        return array_values($names);
    }

    /**
     * Everyone the newest message was addressed to, the reader excluded.
     *
     * @param list<string> $owned
     *
     * @return list<string>
     */
    private function recipients(MessageThread $thread, array $owned): array
    {
        $newest = $this->newest($thread);

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

        return array_values($names);
    }

    private function newest(MessageThread $thread): ?Message
    {
        $newest = null;

        // The association is ordered by date, but a thread assembled in memory
        // during a sync is not, so the newest is picked rather than assumed.
        foreach ($thread->messages as $message) {
            $at = $message->receivedAt ?? $message->sentAt ?? $message->createdAt;

            if (null === $newest || null === $at) {
                $newest ??= $message;

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
    private function ownedAddresses(MessageThread $thread): array
    {
        $account = $thread->account;

        if (null === $account) {
            return [];
        }

        return array_values(array_filter(array_map(
            $this->normalise(...),
            $account->getOwnedAddresses(),
        )));
    }

    private function normalise(?string $address): string
    {
        return mb_strtolower(trim((string) $address));
    }
}
