<?php

namespace App\Service\Imap;

use App\Domain\Enum\MessageFlag;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Entity\Message;
use App\Repository\MailboxRepository;
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
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly MailSenderRegistry      $senderRegistry,
        private readonly ImapConnectionFactory   $imapConnectionFactory,
    ) {
    }

    public function send(Message $message): bool
    {
        $account = $message->getMailbox()->getAccount();
        $email   = $this->buildEmail($message, $account);

        $sender      = $this->senderRegistry->resolve($account);
        $sendSuccess = $sender->send($email, $account);

        if (false === $sendSuccess) {
            return false;
        }

        // API senders file their own Sent copy; only append manually for SMTP.
        if (false === $sender->filesSentCopy()) {
            $this->appendToSentFolder($email, $account);
        }

        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);

        if (null !== $sentMailbox) {
            $message
                ->setMailbox($sentMailbox)
                ->setSentAt(new DateTimeImmutable());

            $this->em->flush();
        }

        return true;
    }

    private function appendToSentFolder(Email $email, Account $account): void
    {
        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);

        if (null === $sentMailbox) {
            return;
        }

        $client = $this->imapConnectionFactory->connect($account);
        $folder = $client->getFolder($sentMailbox->getName());

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

        $subject = $message->getSubject();
        if (null === $subject) {
            $subject = '';
        }

        $email = (new Email())
            ->from(new Address($account->getEmail(), $fromName))
            ->subject($subject);

        $toAddresses = $message->getToAddresses();
        if (null !== $toAddresses) {
            foreach ($toAddresses as $addr) {
                $email->addTo($this->toAddress($addr));
            }
        }

        $ccAddresses = $message->getCcAddresses();
        if (null !== $ccAddresses) {
            foreach ($ccAddresses as $addr) {
                $email->addCc($this->toAddress($addr));
            }
        }

        $bccAddresses = $message->getBccAddresses();
        if (null !== $bccAddresses) {
            foreach ($bccAddresses as $addr) {
                $email->addBcc($this->toAddress($addr));
            }
        }

        if ($message->getBodyHtml()) {
            $email->html($message->getBodyHtml());
        }

        if ($message->getBodyText()) {
            $email->text($message->getBodyText());
        }

        foreach ($message->getMessageParts() as $part) {
            if (true === $part->isInline()) {
                $contentId = $part->getContentId();
                if (null === $contentId) {
                    $contentId = $part->getFilename();
                }

                $email->embedFromPath(
                    $this->attachmentStorage->getAbsolutePath($part->getStoragePath()),
                    $contentId,
                    $part->getContentType(),
                );
            } else {
                $email->attachFromPath(
                    $this->attachmentStorage->getAbsolutePath($part->getStoragePath()),
                    $part->getFilename(),
                    $part->getContentType(),
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
