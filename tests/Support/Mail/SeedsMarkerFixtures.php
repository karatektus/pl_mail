<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A user, an account, an Inbox label and threads of a chosen age.
 *
 * Extracted from NewMailMarkerTest once BadgeSemanticsTest needed the same
 * fixtures: the dots-agree-with-the-badges assertion is only worth anything
 * against a mailbox whose contents the test chose, and in particular against
 * one holding mail on both sides of the 24-hour window. Two copies of this
 * would be two definitions of "a new thread", which is precisely the drift
 * those tests exist to catch.
 *
 * The consumer owns $em, $user, $account and $inbox, and is expected to be
 * running inside a transaction it rolls back.
 */
trait SeedsMarkerFixtures
{
    private EntityManagerInterface $em;
    private User $user;
    private Account $account;
    private Label $inbox;

    /**
     * One conversation, as old as you say.
     *
     * $lastMessageAt is relative by default and must stay that way. Newness has
     * a 24-hour ceiling (MessageThread::NEW_WINDOW), so a fixture pinned to a
     * calendar date silently becomes aged-out mail as soon as the wall clock
     * passes it — which is how "just arrived" fixtures written as '2026-03-01'
     * would quietly stop testing the badge at all.
     */
    private function thread(
        string           $subject,
        ?MessageCategory $category = null,
        string           $lastMessageAt = 'now',
        int              $unread = 0,
        bool             $flush = true,
        ?string          $fromName = null,
    ): MessageThread {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($lastMessageAt);
        $thread->category          = $category ?? MessageCategory::Primary;
        $thread->messageCount      = 1;
        $thread->unreadCount       = $unread;
        $thread->addLabel($this->inbox);

        $this->em->persist($thread);

        $message              = new Message();
        $message->account     = $this->account;
        $message->thread      = $thread;
        $message->subject     = $subject;
        $message->fromAddress = 'sender@example.test';
        $message->fromName    = $fromName;
        $message->receivedAt  = new DateTimeImmutable($lastMessageAt);
        $message->sentAt      = $message->receivedAt;
        $message->seenAt         = $unread > 0 ? null : new DateTimeImmutable($lastMessageAt);
        $message->flags          = [];
        $message->hasAttachments = false;

        $thread->addMessage($message);
        $this->em->persist($message);

        if (true === $flush) {
            $this->em->flush();
        }

        return $thread;
    }

    private function seedLabel(string $name, ?LabelRole $role = null): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Marker fixture';
        $account->email          = uniqid('marker-', true) . '@example.test';
        $account->username       = uniqid('marker-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'marker-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Marker';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
