<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Command\Backfill\GmailifyPlacementBackfillTask;
use App\Domain\Enum\Account\AuthType;
use App\Infrastructure\Messaging\Handler\SeverGmailifyLinksHandler;
use App\Infrastructure\Messaging\Message\SeverGmailifyLinksMessage;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Gmail\GmailifyLinks;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A Gmailify sibling's IMAP copy and the Gmail mailbox that carries it (#203,
 * #205): what taking the link off leaves, and what putting placement right
 * from the folder changes.
 *
 * The sibling here is a plain IMAP account with an Inbox folder bound to the
 * Inbox label, which is how MessageSyncer files what it fetches; the carrier
 * is a Gmail account whose labels carry Gmail ids.
 */
final class GmailifyLinksTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labels;
    private GmailifyLinks $links;

    private User $user;
    private Account $carrier;
    private Account $sibling;
    private Mailbox $siblingInbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->labels     = $container->get(LabelResolver::class);
        $this->links      = $container->get(GmailifyLinks::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The Gmail identity goes, and the labels only Gmail put on go with it.
     * Placement stays as it is: that is the folder's to decide, and
     * placeByFolder() is what corrects it.
     */
    public function testDetachingTakesGmailsIdentityAndLabelsOffAndLeavesTheFolder(): void
    {
        $inbox    = $this->labels->systemLabel(LabelRole::Inbox, $this->sibling);
        $projekte = $this->gmailLabel('Label_projekte', 'Projekte');
        $local    = $this->labels->customChain(['Nur hier'], $this->sibling);
        self::assertNotNull($local);

        $row = $this->imapCopy();
        $row->addLabel($projekte);
        $row->addLabel($local);
        $this->em->flush();

        $this->links->detach($row);
        $this->em->flush();

        self::assertNull($row->gmailId);
        self::assertNull($row->gmailCarrierAccount);
        self::assertNull($row->gmailLabelIds);
        self::assertFalse($row->hasLabel($projekte), 'a label Gmail put on goes with Gmail');
        self::assertTrue($row->hasLabel($local), 'a label plMail put on is not Gmail\'s to take');
        self::assertTrue($row->hasLabel($inbox), 'the folder still says Inbox');
        self::assertFalse(GmailifyLinks::isImapCopy($row));
    }

    /**
     * The row #203 is about: Gmail trashed its copy, the merge trashed the IMAP
     * copy with it, and the IMAP server still has the mail in its Inbox.
     */
    public function testAnImapCopyGmailTrashedGoesBackToItsInbox(): void
    {
        $inbox   = $this->labels->systemLabel(LabelRole::Inbox, $this->sibling);
        $trash   = $this->labels->systemLabel(LabelRole::Trash, $this->sibling);
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->sibling);

        $row    = $this->imapCopy();
        $thread = $row->thread;
        self::assertNotNull($thread);

        $row->removeLabel($inbox);
        $row->addLabel($trash);
        $row->addLabel($archive);
        $thread->removeLabel($inbox);
        $thread->addLabel($trash);
        $this->em->flush();

        self::assertTrue($this->links->placeByFolder($row));
        $this->em->flush();

        self::assertTrue($row->hasLabel($inbox));
        self::assertFalse($row->hasLabel($trash));
        self::assertFalse($row->hasLabel($archive), 'mail in its Inbox folder is not archived');
        self::assertTrue($thread->hasLabel($inbox), 'the inbox list reads the thread');
        self::assertFalse($thread->hasLabel($trash));

        self::assertFalse($this->links->placeByFolder($row), 'a second run changes nothing');
    }

    /**
     * Archive is left alone outside the Inbox: an IMAP account with no archive
     * folder may archive by label, and the folder cannot say otherwise.
     */
    public function testArchiveOutsideTheInboxIsLeftAlone(): void
    {
        $belege = $this->labels->customChain(['Belege'], $this->sibling);
        self::assertNotNull($belege);

        $folder = $this->mailbox('Belege', null);
        $this->labels->bindMailbox($belege, $folder);
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->sibling);
        $this->em->flush();

        $row          = $this->imapCopy();
        $row->mailbox = $folder;
        $row->removeLabel($this->labels->systemLabel(LabelRole::Inbox, $this->sibling));
        $row->addLabel($belege);
        $row->addLabel($archive);
        $this->em->flush();

        self::assertFalse($this->links->placeByFolder($row));
        self::assertTrue($row->hasLabel($archive));
    }

    /**
     * The switch going off (#205) detaches the IMAP copies this mailbox
     * carries. A row Gmail built for the sibling has no IMAP copy behind it and
     * stays linked: Gmail is all it has.
     */
    public function testSeveringDetachesOnlyTheImapCopies(): void
    {
        $imapCopy = $this->imapCopy();

        $gmailOnly          = $this->imapCopy();
        $gmailOnly->mailbox = null;
        $gmailOnly->imapUid = null;
        $this->em->flush();

        self::assertSame(1, $this->links->severCarriedBy((int) $this->carrier->id));

        // Read back: severing clears the entity manager after every page.
        $imapCopy  = $this->em->find(Message::class, $imapCopy->id);
        $gmailOnly = $this->em->find(Message::class, $gmailOnly->id);
        self::assertNotNull($imapCopy);
        self::assertNotNull($gmailOnly);

        self::assertNull($imapCopy->gmailCarrierAccount);
        self::assertSame($this->carrier->id, $gmailOnly->gmailCarrierAccount?->id);
        self::assertNotNull($gmailOnly->gmailId);
    }

    /**
     * The job reads the switch again: turned off and straight back on, the
     * second choice is the one that counts, and nothing is detached.
     */
    public function testTheJobDoesNothingOnceTheSwitchIsBackOn(): void
    {
        $copy = $this->imapCopy();
        $job  = self::getContainer()->get(SeverGmailifyLinksHandler::class);

        $job(new SeverGmailifyLinksMessage((int) $this->carrier->id));

        $copy = $this->em->find(Message::class, $copy->id);
        self::assertNotNull($copy?->gmailCarrierAccount, 'Gmailify is on, so the link stays');

        $carrier                  = $this->em->find(Account::class, $this->carrier->id);
        self::assertNotNull($carrier);
        $carrier->gmailifyEnabled = false;
        $this->em->flush();

        $job(new SeverGmailifyLinksMessage((int) $this->carrier->id));

        $copy = $this->em->find(Message::class, $copy->id);
        self::assertNull($copy?->gmailCarrierAccount);
    }

    /** `app:backfill gmailify-placement` runs the same repair over every carried IMAP copy. */
    public function testTheBackfillPutsATrashedCopyBack(): void
    {
        $trash = $this->labels->systemLabel(LabelRole::Trash, $this->sibling);
        $inbox = $this->labels->systemLabel(LabelRole::Inbox, $this->sibling);

        $row = $this->imapCopy();
        $row->removeLabel($inbox);
        $row->addLabel($trash);
        $this->em->flush();

        $id = $row->id;

        $task   = self::getContainer()->get(GmailifyPlacementBackfillTask::class);
        $output = new BufferedOutput();

        self::assertSame(Command::SUCCESS, $task->run(new SymfonyStyle(new ArrayInput([]), $output)));

        $row = $this->em->find(Message::class, $id);
        self::assertNotNull($row);
        self::assertSame(
            [LabelRole::Inbox],
            array_values(array_filter(array_map(static fn (Label $label): ?LabelRole => $label->role, $row->labels->toArray()))),
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A label the carrier's Gmail knows, which is what a gmailLabelId on its binding means. */
    private function gmailLabel(string $gmailLabelId, string $name): Label
    {
        $label = $this->labels->customChain([$name], $this->carrier);
        self::assertNotNull($label);

        $this->labels->binding($label, $this->carrier)->gmailLabelId = $gmailLabelId;
        $this->labels->binding($label, $this->sibling);
        $this->em->flush();

        return $label;
    }

    /** The sibling's own copy in its Inbox folder, merged with the carrier's Gmail copy. */
    private function imapCopy(): Message
    {
        $inbox = $this->labels->systemLabel(LabelRole::Inbox, $this->sibling);

        $thread = new MessageThread();
        $thread->account           = $this->sibling;
        $thread->subject           = 'Rechnung';
        $thread->normalizedSubject = 'rechnung';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $thread->addLabel($inbox);
        $this->em->persist($thread);

        $message = new Message();
        $message->account             = $this->sibling;
        $message->mailbox             = $this->siblingInbox;
        $message->imapUid             = random_int(1, 1_000_000);
        $message->gmailId             = 'gmail-' . uniqid('', true);
        $message->gmailCarrierAccount = $this->carrier;
        $message->gmailLabelIds       = ['INBOX'];
        $message->messageId           = uniqid('', true) . '@example.test';
        $message->subject             = 'Rechnung';
        $message->fromAddress         = 'kunde@example.test';
        $message->bodyText            = 'Rechnung';
        $message->hasAttachments      = false;
        $message->seenAt              = new DateTimeImmutable();
        $message->flags               = [];
        $message->receivedAt          = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt              = $message->receivedAt;
        $message->addLabel($inbox);

        $this->em->persist($message);
        $thread->addMessage($message);

        $this->em->flush();

        return $message;
    }

    private function mailbox(string $name, ?MailboxSpecialUse $specialUse): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->sibling;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);

        return $mailbox;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email     = 'gmailify-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Gmailify';
        $this->user->nameLast  = 'Links';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $this->carrier = $this->account('carrier', true);
        $this->sibling = $this->account('sibling', false);
        $this->em->flush();

        $this->user->addAccount($this->carrier);
        $this->user->addAccount($this->sibling);

        $this->siblingInbox = $this->mailbox('INBOX', null);
        $this->em->flush();

        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Inbox, $this->sibling), $this->siblingInbox);
        $this->labels->binding($this->labels->systemLabel(LabelRole::Inbox, $this->sibling), $this->carrier)->gmailLabelId = 'INBOX';
        $this->em->flush();
    }

    private function account(string $slug, bool $gmail): Account
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = $slug . '-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = true === $gmail ? 'imap.gmail.com' : 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'smtp.example.test';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = true === $gmail ? AuthType::OAuth2->value : AuthType::Password->value;
        $account->oauthProvider  = true === $gmail ? MailProvider::Google->value : null;
        $account->isActive       = true;

        $this->em->persist($account);

        return $account;
    }
}
