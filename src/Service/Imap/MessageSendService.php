<?php

namespace App\Service\Imap;

use App\Domain\Enum\MessageFlag;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Entity\Message;
use App\Repository\MailboxRepository;
use Carbon\Carbon;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MessageSendService
{
    public function __construct(
        private readonly MailboxRepository      $mailboxRepository,
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorageHelper $attachmentStorage,
    )
    {
    }

    public function send(Message $message): bool
    {
        $account = $message->getMailbox()->getAccount();

        $email = $this->buildEmail($message, $account);

        $sendSuccess = $this->sendViaSmtp($email, $account);
        $this->appendToSentFolder($email, $account);

        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);
        if(false === $sendSuccess){
            return false;
        }

        if (null !== $sentMailbox) {
            $message
                ->setMailbox($sentMailbox)
                ->setSentAt(new DateTimeImmutable());

            $this->em->flush();
        }

        return true;
    }

    private function sendViaSmtp(Email $email, Account $account): bool
    {
        $enc = strtolower($account->getSmtpEncryption() ?? 'tls');
        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $enc === 'ssl' ? 'smtps' : 'smtp',
            urlencode($account->getUsername()),
            urlencode($account->getPassword()),
            $account->getSmtpHost(),
            $account->getSmtpPort() ?? 587,
        );

        try {
            new Mailer(Transport::fromDsn($dsn))->send($email);
        } catch (TransportExceptionInterface $e) {
            return false;
        }

        return true;
    }

    private function appendToSentFolder(Email $email, Account $account): void
    {
        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);
        if (null === $sentMailbox) {
            return;
        }

        $client = ImapConnectionFactory::connect($account);
        $folder = $client->getFolder($sentMailbox->getName());

        $appendResult = $folder->appendMessage(
            $email->toString(),
            [MessageFlag::SEEN->value],
        );

        $client->disconnect();
    }

    private function buildEmail(Message $message, Account $account): Email
    {
        $email = new Email()
            ->from(new Address($account->getEmail(), $account->getName() ?? ''))
            ->subject($message->getSubject() ?? '');

        foreach ($message->getToAddresses() ?? [] as $addr) {
            $email->addTo(new Address($addr['address'], $addr['name'] ?? ''));
        }
        foreach ($message->getCcAddresses() ?? [] as $addr) {
            $email->addCc(new Address($addr['address'], $addr['name'] ?? ''));
        }
        foreach ($message->getBccAddresses() ?? [] as $addr) {
            $email->addBcc(new Address($addr['address'], $addr['name'] ?? ''));
        }

        if ($message->getBodyHtml()) {
            $email->html($message->getBodyHtml());
        }
        if ($message->getBodyText()) {
            $email->text($message->getBodyText());
        }

        foreach ($message->getMessageParts() as $part) {
            if (true === $part->isInline()) {
                $email->embedFromPath(
                    $this->attachmentStorage->getAbsolutePath($part->getStoragePath()),
                    $part->getContentId() ?? $part->getFilename(),
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
}
