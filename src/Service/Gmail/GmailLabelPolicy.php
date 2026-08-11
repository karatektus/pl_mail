<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * Which of a message's labels Gmail is allowed to have an opinion about.
 *
 * The Graph side has had this since folder moves were made exclusive:
 * GraphLabelPolicy decides what a label *is* at the provider, and the syncer
 * asks it before taking a location off. Gmail had no such thing, so its label
 * application could only ever add — applyTranslatedLabels() resolved the ids
 * Gmail reported and put every one of them on. Nothing ever came off. Unfiling
 * a message in Gmail left the label on it in plMail permanently, and since
 * archiving in Gmail *is* the removal of INBOX, a message archived in the web
 * interface went on showing in plMail's inbox forever.
 *
 * The reason it could not simply be made authoritative is that a message's
 * labels are not all Gmail's to speak for. Snoozed is plMail's own bookkeeping,
 * Archive has no Gmail counterpart at all — Gmail models archiving as the
 * absence of INBOX rather than the presence of anything — and a user may keep
 * labels here that exist nowhere else. An authoritative rule that did not know
 * the difference would answer the first archive by deleting the user's local
 * filing.
 *
 * ## The discriminator, which is already in the data
 *
 * A label Gmail knows has a `gmailLabelId` on its binding, put there by
 * GmailLabelSyncer: INBOX, SENT, DRAFT, TRASH and SPAM for the system roles it
 * maps, and `Label_…` for everything the user made in Gmail. A label without
 * one is not a label Gmail has ever heard of. That is the whole test, and it
 * needs no new column — the same shape as GraphLabelPolicy keying on
 * graphFolderId.
 *
 * Note what falls out of it rather than being special-cased. Snoozed is never
 * bound because Gmail has nothing to bind it to. Archive is never bound because
 * SYSTEM_MAP has no ARCHIVE entry, Gmail having no such label. Both end up
 * internal by the ordinary rule, which is the right reason for them to be
 * internal.
 *
 * ## Why it takes an account, and which account
 *
 * gmailLabelId lives on LabelBinding, because labels became user-scoped: one
 * Label row is materialised on every account that has it, each with its own
 * remote id. So the same label can be Gmail-owned on one account and purely
 * local on another, and the question is meaningless without naming which.
 *
 * The account to ask about is always the **carrier** — the account whose API
 * produced the list of ids being applied — and never the account the message
 * was ultimately attributed to. Gmail speaks for its own mailbox. A label that
 * exists on a sibling account and not on this one is a label this feed has said
 * nothing about, and silence is not a removal.
 */
final readonly class GmailLabelPolicy
{
    /**
     * Whether Gmail is authoritative for this label on this account.
     *
     * True means a label set from Gmail that omits it is Gmail saying it is
     * gone, and it comes off. False means Gmail has never heard of it and its
     * silence means nothing.
     */
    public function isProviderOwned(Label $label, Account $account): bool
    {
        return null !== $label->bindingFor($account)?->gmailLabelId;
    }

    /**
     * plMail's own — never added or removed on Gmail's word.
     *
     * Stated positively as well as negatively because the two readings are
     * used in different places and "not provider-owned" is easy to write by
     * accident where "internal" was meant.
     */
    public function isInternal(Label $label, Account $account): bool
    {
        return false === $this->isProviderOwned($label, $account);
    }

    /**
     * The labels currently on this message that the given account's Gmail is
     * entitled to remove.
     *
     * @return list<Label>
     */
    public function providerLabels(Message $message, Account $carrier): array
    {
        $owned = [];

        foreach ($message->labels as $label) {
            if (true === $this->isProviderOwned($label, $carrier)) {
                $owned[] = $label;
            }
        }

        return $owned;
    }
}
