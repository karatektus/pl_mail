<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The minimum row set a console command needs to have something to act on.
 *
 * Static rather than a trait so PHPStan can see what it operates on, and so a
 * test that needs two users or two accounts does not have to fight a
 * setUp()-owned singleton to get them.
 *
 * Every identifier is uniquified. The command suite runs each test inside a
 * transaction it rolls back, but several of these commands sweep *every* row of
 * a table rather than only the ones a test seeded, so fixtures from different
 * tests have to be distinguishable in the output even while they coexist.
 */
final class MailFixtures
{
    public static function user(EntityManagerInterface $em, string $prefix = 'cmd'): User
    {
        $user = new User();
        $user->email = sprintf('%s-%s@example.test', $prefix, uniqid('', true));
        $user->nameFirst = 'Command';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public static function account(
        EntityManagerInterface $em,
        User $user,
        ?string $username = null,
        bool $isActive = true,
    ): Account {
        $account = new Account();
        $account->usr = $user;
        $account->name = 'Command Fixture';
        $account->email = 'Command Fixture';
        $account->username = $username ?? sprintf('acct-%s@example.test', uniqid('', true));
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = $isActive;

        $em->persist($account);
        $em->flush();

        return $account;
    }

    public static function mailbox(
        EntityManagerInterface $em,
        Account $account,
        string $name = 'INBOX',
        bool $idle = false,
    ): Mailbox {
        $mailbox = new Mailbox();
        $mailbox->account = $account;
        $mailbox->name = $name;
        $mailbox->fullPath = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = $idle;
        $mailbox->createdAt = new \DateTimeImmutable();
        $mailbox->updatedAt = new \DateTimeImmutable();

        $em->persist($mailbox);
        $em->flush();

        return $mailbox;
    }

    public static function message(
        EntityManagerInterface $em,
        Account $account,
        string $subject = 'Command fixture',
        ?\DateTimeImmutable $receivedAt = null,
    ): Message {
        $message = new Message();
        $message->account = $account;
        $message->subject = $subject;
        $message->fromAddress = 'sender@example.test';
        $message->fromName = 'Sender';
        $message->receivedAt = $receivedAt ?? new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('cmd-%s@example.test', uniqid('', true));

        $em->persist($message);
        $em->flush();

        return $message;
    }

    public static function thread(
        EntityManagerInterface $em,
        Account $account,
        string $subject = 'Command fixture',
    ): MessageThread {
        $thread = new MessageThread();
        $thread->account = $account;
        $thread->subject = $subject;
        $thread->normalizedSubject = mb_strtolower(trim($subject));
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->unreadCount = 0;
        $thread->messageCount = 0;

        $em->persist($thread);
        $em->flush();

        return $thread;
    }
}
