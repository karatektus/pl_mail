<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * Which labels on a message a JMAP account's server is entitled to remove.
 *
 * The rule GmailLabelPolicy states for Gmail, for the same reason: a label set
 * from the server that omits a label is the server saying it is gone — but
 * only for a label the server knows. A label bound to one of this account's
 * remote mailboxes is the server's; anything else (Snoozed, a label made in
 * plMail and never pushed, another account's folder label on a merged row) is
 * plMail's own, and the server's silence about it means nothing.
 */
final readonly class JmapLabelPolicy
{
    public function isProviderOwned(Label $label, Account $account): bool
    {
        return null !== $label->bindingFor($account)?->jmapMailboxId;
    }

    /**
     * @return list<Label>
     */
    public function providerLabels(Message $message, Account $account): array
    {
        $owned = [];

        foreach ($message->labels as $label) {
            if (true === $this->isProviderOwned($label, $account)) {
                $owned[] = $label;
            }
        }

        return $owned;
    }
}
