<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\SpamSender;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;

/**
 * Works out who a conversation is from when somebody calls it spam.
 *
 * A conversation can have several senders, and one of them is usually the
 * person looking at it. The answer is the sender of the newest message that
 * somebody else wrote: the last thing to arrive is what the button was pressed
 * about, and a filter on one's own address would file one's own Sent copies.
 *
 * Null when nobody else wrote anything — a conversation made only of the
 * user's own mail, or one whose sender has no usable address. The menu then
 * offers the move and no filter, because there is nobody to filter.
 *
 * Read on the server each time rather than sent by the browser. The filter
 * this feeds is a standing rule over future mail, and what it matches on must
 * not be whatever a request said it was.
 */
final readonly class SpamSenderResolver
{
    public function __construct(
        private MailPresetProvider $presets,
        private AccountRepository  $accounts,
    ) {
    }

    public function of(MessageThread $thread, User $user): ?SpamSender
    {
        $own    = $this->ownAddresses($user);
        $newest = null;

        foreach ($thread->messages as $message) {
            $address = $this->addressOf($message);

            if (null === $address || true === in_array($address, $own, true)) {
                continue;
            }

            if (null === $newest || $this->arrivedAt($message) > $this->arrivedAt($newest)) {
                $newest = $message;
            }
        }

        if (null === $newest) {
            return null;
        }

        $address = (string) $this->addressOf($newest);
        $domain  = substr($address, (int) strrpos($address, '@') + 1);

        return new SpamSender($address, $domain, null !== $this->presets->findByDomain($domain));
    }

    /** The From address, lower-cased, or null when it is not an address. */
    private function addressOf(Message $message): ?string
    {
        $address = mb_strtolower(trim((string) $message->fromAddress));

        if (false === filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $address;
    }

    private function arrivedAt(Message $message): int
    {
        return ($message->receivedAt ?? $message->sentAt)?->getTimestamp() ?? 0;
    }

    /**
     * Every address the user sends as: each account's own, and its aliases.
     *
     * Asked of the repository rather than read off `$user->accounts`: this is
     * the list that keeps a filter off the user's own address, and it should
     * not depend on whether that collection happens to be loaded and current.
     *
     * @return list<string>
     */
    private function ownAddresses(User $user): array
    {
        $own = [];

        foreach ($this->accounts->findBy(['usr' => $user]) as $account) {
            $own[] = mb_strtolower(trim((string) $account->email));

            foreach ($account->aliases as $alias) {
                $own[] = mb_strtolower(trim($alias->address));
            }
        }

        return $own;
    }
}
