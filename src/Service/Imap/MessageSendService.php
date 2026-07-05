<?php

namespace App\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Entity\Message;
use App\Repository\MailboxRepository;
use Carbon\Carbon;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MessageSendService
{
    public function __construct(
        private readonly MailboxRepository      $mailboxRepository,
        private readonly EntityManagerInterface $em,
    )
    {
    }

    public function send(Message $message): void
    {
        $account = $message->getMailbox()->getAccount();

        $this->sendViaSmtp($message, $account);
        $this->appendToSentFolder($message, $account);

        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);

        $message
            ->setMailbox($sentMailbox)
            ->setSentAt(new DateTimeImmutable());

        $this->em->flush();
    }

    private function sendViaSmtp(Message $message, Account $account): void
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

        $mailer = new Mailer(Transport::fromDsn($dsn));

        $email = (new Email())
            ->from(new Address($account->getEmail(), $account->getName() ?? ''))
            ->subject($message->getSubject() ?? '');

        foreach ($message->getToAddresses() ?? []as $addr) {
            $email->addTo($addr['address']);
        }
        foreach ($message->getCcAddresses() ?? [] as $addr) {
            $email->addCc($addr['address']);
        }
        foreach ($message->getBccAddresses() ?? [] as $addr) {
            $email->addBcc($addr['address']);
        }

        if (null !== $message->getBodyHtml()) {
            $email->html($message->getBodyHtml());
        }
        if (null !== $message->getBodyText()) {
            $email->text($message->getBodyText());
        }

        $mailer->send($email);
    }

    private function appendToSentFolder(Message $message, Account $account): void
    {
        $client = ImapConnectionFactory::connect($account);

        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);
        $folderPath = $sentMailbox?->getName();

        dump($client->getFolders()) ;
        $folder = $client->getFolder($folderPath);
        $folder->appendMessage(
            $message->getBodyHtml() ?? $message->getBodyText() ?? '',
            ['\\Seen'],
           Carbon::now(),
        );

        $client->disconnect();
    }
}
