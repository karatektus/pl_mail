<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A user with an IMAP account whose labels are real folders, for the tests of
 * deleting one (issue #40).
 *
 * The difference from SeedsMarkerFixtures is the folder: a label here is bound
 * to a Mailbox, the way MailboxSyncer binds every folder it meets, so it is a
 * label LabelDeletion counts as being on a server.
 *
 * The consumer owns $em and $labelResolver, and runs inside a transaction it
 * rolls back.
 */
trait SeedsFolderLabels
{
    private EntityManagerInterface $em;
    private LabelResolver $labelResolver;
    private User $user;
    private Account $account;

    private function seedFolderUser(): void
    {
        $user            = new User();
        $user->email     = 'folders-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Folder';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Folder fixture';
        $account->email          = uniqid('folders-', true) . '@example.test';
        $account->username       = uniqid('folders-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->password       = 'x';
        $account->isActive       = true;
        $this->em->persist($account);

        $this->em->flush();

        $this->user    = $user;
        $this->account = $account;
    }

    /**
     * A label that is the IMAP folder at $path, bound the way a sync binds it.
     *
     * @param list<string> $segments e.g. ['Work', 'Invoices']
     */
    private function folderLabel(array $segments): Label
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = (string) end($segments);
        $mailbox->fullPath      = implode('/', $segments);
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        // Imported: a folder whose import is still running is not one that
        // can be deleted yet, and that is its own test.
        $mailbox->importFloorUid = 0;

        $this->em->persist($mailbox);
        $this->em->flush();

        $label = $this->labelResolver->customChain($segments, $this->account);

        self::assertNotNull($label);

        $this->labelResolver->bindMailbox($label, $mailbox);
        $this->em->flush();

        return $label;
    }

    /**
     * A label that is only a tag: bound to the account, with no folder.
     */
    private function tagLabel(string $name): Label
    {
        $label = $this->labelResolver->customChain([$name], $this->account);

        self::assertNotNull($label);

        $this->labelResolver->binding($label, $this->account);
        $this->em->flush();

        return $label;
    }

    /**
     * One message filed in a folder label — in its folder and wearing it.
     */
    private function messageIn(Label $label, string $subject): Message
    {
        $mailbox = $label->bindingFor($this->account)?->mailbox;
        $now     = new DateTimeImmutable();

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $now;
        $thread->category          = MessageCategory::Primary;
        $thread->messageCount      = 1;
        $thread->addLabel($label);
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->messageId      = uniqid('folders-', true) . '@example.test';
        $message->receivedAt     = $now;
        $message->sentAt         = $now;
        $message->seenAt         = $now;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->mailbox        = $mailbox;
        $message->imapUid        = random_int(1, 1_000_000);
        $message->addLabel($label);
        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();

        return $message;
    }

    private function systemLabel(LabelRole $role): Label
    {
        return $this->labelResolver->userSystemLabel($role, $this->user);
    }
}
