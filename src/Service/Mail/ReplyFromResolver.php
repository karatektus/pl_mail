<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\OwnAddressHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * Which of the account's addresses answers a message: the one it was sent to.
 *
 * A mailbox with three addresses is three correspondents to the people writing
 * to it. Answering mail that came in for `sales@` from `me@` tells the sender
 * an address they were never given, and on a catch-all domain it is the whole
 * reason the domain exists — the address somebody was handed is the one their
 * answer has to come from.
 *
 * Until this existed every reply opened on the account's primary address,
 * aliases included, and getting it right was a dropdown the user had to
 * remember on every mail.
 *
 * WHERE THE ADDRESS IS LOOKED FOR, IN ORDER
 *
 *  1. From, when the account itself sent the original: answering something you
 *     sent continues it as whoever you were when you sent it.
 *  2. To, then Cc — what the sender typed — for an address that is one of the
 *     account's sendable aliases.
 *  3. The catch-all addresses the message came in for: what the server says
 *     it delivered to, and only without that the To and Cc addresses at a
 *     catch-all domain. Delivery first here, the reverse of step 2, because
 *     at a catch-all domain a visible recipient may be somebody else — a
 *     colleague with a mailbox of their own — and the delivery header is the
 *     one thing that says which address was this mailbox's. See
 *     OwnAddressHelper.
 *  4. Delivered-To, X-Original-To, Envelope-To for an alias — the only place
 *     it appears when the user was in Bcc or on a list. Last, because a
 *     forwarding hop rewrites these to an address nobody wrote to.
 *
 * Whatever comes out is an address Account::maySendAs() accepts. That is the
 * rule the From selector is built from, so it is an option the selector can
 * show and the send will take. Null means the message names none of the
 * account's addresses, and the caller stays on the primary one.
 *
 * WHAT IS DELIBERATELY NOT ENOUGH
 *
 * A From at a catch-all domain. Mail from `anna@example.com` to the user is
 * not the user's own mail because the domain is a catch-all, and answering it
 * as Anna would be sending as somebody else.
 */
final readonly class ReplyFromResolver
{
    public function addressFor(Message $original, Account $account): ?string
    {
        $from = AddressHelper::email($original->fromAddress);

        if (true === OwnAddressHelper::sentByAccount($original) && true === $account->maySendAs($from)) {
            return $from;
        }

        $aliases = [];

        foreach ($account->sendableAliases as $alias) {
            $aliases[] = $alias->address;
        }

        foreach ($this->visibleRecipients($original) as $address) {
            if (true === in_array($address, $aliases, true)) {
                return $address;
            }
        }

        $caught = OwnAddressHelper::caughtRecipients($original);

        if ([] !== $caught) {
            return $caught[0];
        }

        foreach (OwnAddressHelper::deliveredTo($original) as $address) {
            if (true === $account->maySendAs($address)) {
                return $address;
            }
        }

        // An account with no alias rows yet sends as its own address, which no
        // list above contains.
        foreach ($this->visibleRecipients($original) as $address) {
            if (true === $account->maySendAs($address) && false === $account->catchesAddress($address)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @return list<string> lowercased, To before Cc
     */
    private function visibleRecipients(Message $original): array
    {
        $addresses = [];

        foreach ([$original->toAddresses ?? [], $original->ccAddresses ?? []] as $list) {
            foreach ($list as $entry) {
                $addresses[] = AddressHelper::email((string) ($entry['address'] ?? ''));
            }
        }

        return $addresses;
    }
}
