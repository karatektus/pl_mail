<?php

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\AttachmentResolver;
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
        private readonly StateManager            $stateManager,
    ) {
    }

    public function send(Message $message): bool
    {
        $account = $message->account;

        if (null === $account) {
            return false;
        }

        $email = $this->buildEmail($message, $account);

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
        $draftsLabel = $this->labelRepository->findOneByRoleForUser(LabelRole::Drafts, $account->getUsr());

        $message->addLabel($sentLabel);

        if (null !== $draftsLabel) {
            $message->removeLabel($draftsLabel);
        }

        $message->removeFlag(MessageFlag::DRAFT);
        $message->sentAt = new DateTimeImmutable();

        // Plain-IMAP: physical Sent folder; Gmail: no mailbox.
        $message->mailbox = $sentLabel->bindingFor($account)?->mailbox;

        // The draft->sent transition rewrites three properties JMAP publishes:
        // keywords (the $draft keyword goes away), mailboxIds (EmailMapper
        // reads those off the labels, so swapping Drafts for Sent is a move as
        // far as a client is concerned) and sentAt. Nothing was recorded here,
        // so a client saw the draft appear and never heard another word about
        // it — mail that left the building hours ago sat in its cache as an
        // unsent draft until a full resync.
        //
        // Ahead of the flush below on purpose. The message was persisted long
        // before the send was queued and so was its thread, so both ids exist,
        // and record() only persists — putting it here commits the log rows in
        // the same unit of work as the transition they describe, rather than
        // leaving a window where the mail is sent and the log does not say so.
        //
        // The Sent Mailbox itself needs nothing: systemLabel() above goes
        // through LabelResolver::binding(), which records the Mailbox create
        // when it mints a binding, and per-mailbox counts are not a change
        // this codebase logs (see EmailSetMethod, which moves messages between
        // mailboxes and records only the Email and its Thread).
        $accountId = (int) $account->getId();

        $this->stateManager->recordUpdated(
            $accountId,
            JmapObjectType::Email,
            (string) $message->id,
        );

        $thread = $message->thread;

        if (null !== $thread) {
            $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
        }

        $this->em->flush();

        return true;
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
        $fromName = $account->getName();
        if (null === $fromName) {
            $fromName = '';
        }

        $subject = $message->subject;
        if (null === $subject) {
            $subject = '';
        }

        $fromAddress = $message->fromAddress;

        if (null === $fromAddress || '' === $fromAddress) {
            $fromAddress = $account->getDisplayAddress() ?? $account->getEmail() ?? '';
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
