<?php

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the outgoing MIME once, then dispatches it to the sender that handles
 * the account (Gmail API for Google OAuth, SMTP for password accounts).
 */
class MessageSendService
{
    public function __construct(
        private readonly MailboxRepository       $mailboxRepository,
        private readonly EntityManagerInterface  $em,
        private readonly MailSenderRegistry      $senderRegistry,
        private readonly ImapConnectionFactory   $imapConnectionFactory,
        private readonly AttachmentResolver      $attachmentResolver,
        private readonly LabelResolver           $labelResolver,
        private readonly LabelRepository         $labelRepository,
        private readonly MailChangeRecorder      $changes,
        private readonly MessageThreader         $threader,
        private readonly ThreadLabelSynchronizer $threadLabels,
    ) {
    }

    public function send(Message $message): bool
    {
        $account = $message->account;

        if (null === $account) {
            return false;
        }

        $email = $this->buildEmail($message, $account);

        $this->stampMessageId($message, $email);

        $sender      = $this->senderRegistry->resolve($account);
        $sendSuccess = $sender->send($email, $account);

        if (false === $sendSuccess) {
            // Nothing to record. Senders take a Symfony\Mime\Email and hand
            // back a bool — a refused send never reaches the row, so there is
            // no error flag, retry counter or bounce state for a client to be
            // told about, and the draft it already holds is still accurate.
            // The retry lives on the messenger transport, not on the message.
            return false;
        }

        // API senders file their own Sent copy; only append manually for SMTP.
        if (false === $sender->filesSentCopy()) {
            $this->appendToSentFolder($email, $account);
        }

        $sentLabel   = $this->labelResolver->systemLabel(LabelRole::Sent, $account);
        $draftsLabel = $this->labelRepository->findOneByRoleForUser(LabelRole::Drafts, $account->usr);

        $message->addLabel($sentLabel);

        if (null !== $draftsLabel) {
            $message->removeLabel($draftsLabel);
        }

        $message->removeFlag(MessageFlag::DRAFT);
        $message->sentAt = new DateTimeImmutable();

        // Plain-IMAP: physical Sent folder; Gmail: no mailbox.
        $message->mailbox = $sentLabel->bindingFor($account)?->mailbox;

        // The conversation just moved, and until now nothing said so. Thread
        // lists all sort on lastMessageAt and only the threader ever wrote it,
        // so a thread ranked where its last *incoming* message left it: you
        // answered a mail and the conversation stayed buried under everything
        // that had arrived since. See MessageThreader::recordActivity().
        $thread = $message->thread;

        if (null !== $thread) {
            $this->threader->recordActivity($message, $thread);

            // Swapping Drafts for Sent is a message-level label mutation, and
            // this is what such a mutation is always followed by — the thread's
            // labels are the union of its messages'. Missing it, the
            // conversation kept a Drafts label with no draft in it and never
            // gained a Sent one, so an answered thread was absent from the Sent
            // list until the server copy came back and dragged the label along
            // as a side effect of being a duplicate.
            $this->threadLabels->sync($thread);
        }

        // The draft->sent transition rewrites three properties JMAP publishes:
        // keywords (the $draft keyword goes away), mailboxIds (EmailMapper
        // reads those off the labels, so swapping Drafts for Sent is a move as
        // far as a client is concerned) and sentAt. Nothing was announced here,
        // so a client saw the draft appear and never heard another word about
        // it — mail that left the building hours ago sat in its cache as an
        // unsent draft until a full resync.
        //
        // Ahead of the flush below on purpose. The message was persisted long
        // before the send was queued and so was its thread, so both ids exist,
        // and recording only persists — putting it here commits the log rows in
        // the same unit of work as the transition they describe, rather than
        // leaving a window where the mail is sent and the log does not say so.
        //
        // The Sent Mailbox itself needs nothing: systemLabel() above goes
        // through LabelResolver::binding(), which records the Mailbox create
        // when it mints a binding, and per-mailbox counts are not a change
        // this codebase logs (see EmailSetMethod, which moves messages between
        // mailboxes and records only the Email and its Thread).
        $this->changes->emailChanged(
            (int) $account->id,
            (string) $message->id,
            created: false,
            thread: $message->thread,
        );

        // Only for mail that arrived here through EmailSubmission/set, which is
        // what submissionSendAt marks. A send from the web composer has no
        // submission a client was told about — EmailSubmission/get reports it
        // as final because any sent Email is describable that way, but nothing
        // was ever "pending" for it, so announcing a change would wake clients
        // for a transition none of them was watching.
        //
        // For the ones that were: the submission this client is holding says
        // pending and a release time, and it has just become final. Without
        // this the only announcement was the Email's, and a client that follows
        // the submission — which is the object that told it "sending at 09:00"
        // — would show a schedule for mail that has already gone.
        if (null !== $message->submissionSendAt) {
            $this->changes->submissionChanged((int) $account->id, (string) $message->id);
        }

        $this->em->flush();

        return true;
    }

    /**
     * Give the outgoing mail one Message-ID and put it on the row as well.
     *
     * This is the durable identity of the message, and before this nothing had
     * one. The row was stored with message_id NULL, and the MIME was left for
     * Symfony to label: Message::getPreparedHeaders() mints an id only into the
     * clone it returns, so the transport's copy and the `toString()` used for
     * the Sent APPEND each got a *different* random id. Three stores, three
     * identities, nothing to reconcile on — so when the Sent copy came back
     * from the server the syncer had no way to recognise it as the message it
     * had already stored, and inserted it again. That is the duplicate.
     *
     * Set on the headers before either use of $email, so the recipient's copy,
     * the Sent copy and the row all name the same message — and so a reply to
     * it references an id we can actually find.
     *
     * Kept if the row already has one: a resend of the same row is the same
     * message, and re-minting would strand the copy already on the server.
     */
    private function stampMessageId(Message $message, Email $email): void
    {
        $existing = MessageIdHelper::normalise((string) $message->messageId);

        if ('' === $existing) {
            $existing = MessageIdHelper::mint(
                (string) ($email->getFrom()[0]->getAddress() ?? ''),
            );

            $message->messageId = $existing;
        }

        $headers = $email->getHeaders();

        if (true === $headers->has('Message-ID')) {
            $headers->remove('Message-ID');
        }

        // addIdHeader wants the bare id and writes the angle brackets itself,
        // which is why the column stores the canonical bracket-less form.
        $headers->addIdHeader('Message-ID', $existing);
    }

    private function appendToSentFolder(Email $email, Account $account): void
    {
        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);

        if (null === $sentMailbox) {
            return;
        }

        $client = $this->imapConnectionFactory->connect($account);
        $folder = $client->getFolder($sentMailbox->name);

        $folder->appendMessage(
            $email->toString(),
            [MessageFlag::SEEN->value],
        );

        $client->disconnect();
    }

    private function buildEmail(Message $message, Account $account): Email
    {
        $fromName = $account->name;
        if (null === $fromName) {
            $fromName = '';
        }

        $subject = $message->subject;
        if (null === $subject) {
            $subject = '';
        }

        $fromAddress = $message->fromAddress;

        if (null === $fromAddress || '' === $fromAddress) {
            $fromAddress = $account->displayAddress ?? $account->email ?? '';
        }

        $email = new Email()
            ->from(new Address($fromAddress, $fromName))
            ->subject($subject);

        $toAddresses = $message->toAddresses;
        if (null !== $toAddresses) {
            foreach ($toAddresses as $addr) {
                $email->addTo($this->toAddress($addr));
            }
        }

        $ccAddresses = $message->ccAddresses;
        if (null !== $ccAddresses) {
            foreach ($ccAddresses as $addr) {
                $email->addCc($this->toAddress($addr));
            }
        }

        $bccAddresses = $message->bccAddresses;
        if (null !== $bccAddresses) {
            foreach ($bccAddresses as $addr) {
                $email->addBcc($this->toAddress($addr));
            }
        }

        if ($message->bodyHtml) {
            $email->html($message->bodyHtml);
        }

        if ($message->bodyText) {
            $email->text($message->bodyText);
        }

        foreach ($message->messageParts as $part) {
            if (true === $part->isInline) {
                $contentId = $part->contentId;
                if (null === $contentId) {
                    $contentId = $part->filename;
                }

                $email->embedFromPath(
                    $this->attachmentResolver->absolutePathFor($part),
                    $contentId,
                    $part->contentType,
                );
            } else {
                $email->attachFromPath(
                    $this->attachmentResolver->absolutePathFor($part),
                    $part->filename,
                    $part->contentType,
                );
            }
        }

        return $email;
    }

    /**
     * @param array{name?: string|null, address: string} $addr
     */
    private function toAddress(array $addr): Address
    {
        $name = '';

        if (array_key_exists('name', $addr) && null !== $addr['name']) {
            $name = $addr['name'];
        }

        return new Address($addr['address'], $name);
    }
}
