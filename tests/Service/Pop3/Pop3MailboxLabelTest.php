<?php

declare(strict_types=1);

namespace App\Tests\Service\Pop3;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelResolver;
use App\Service\Pop3\Pop3AccountSyncer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A POP3 account's mailbox is bound to the Inbox label.
 *
 * The bug this exists for was found by deploying the feature, not by the suite:
 * five messages synced into a mailbox that had no label binding, so they were
 * in the database and visible nowhere. Every view in the application — the
 * Inbox list, the sidebar counts, the JMAP Mailbox object — reads the binding,
 * and MessageSyncer::buildMessageFromParsed() attaches `$mailbox->label`,
 * which was null, so the messages carried no label at all.
 *
 * IMAP never hits it: MailboxSyncer::linkLabel() binds every folder it
 * creates. This is POP3's equivalent, and it has to hold both for a mailbox
 * created fresh and for one that already existed without a binding — the
 * second is what a deployment that ran the broken code actually has.
 */
final class Pop3MailboxLabelTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->getConnection()->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'pop3-label-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $this->user->nameFirst = 'Pop3';
        $this->user->nameLast  = 'Label';
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * A mailbox this syncer creates comes out bound to the account's Inbox label.
     *
     * Reflection is used to reach the private factory rather than driving a
     * whole sync: the property under test is "the mailbox it makes is bound",
     * and a sync would need a live POP3 server to get anywhere near it. The
     * class is final and readonly, so the collaborators it needs are the real
     * ones from the container.
     */
    public function testAFreshMailboxIsBoundToTheInboxLabel(): void
    {
        $account = $this->pop3Account();
        $syncer  = static::getContainer()->get(Pop3AccountSyncer::class);

        $mailbox = $this->invokeMailboxFor($syncer, $account);

        self::assertSame(MailboxSpecialUse::INBOX, $mailbox->specialUse);
        self::assertNotNull(
            $mailbox->label,
            'a Mailbox with no label is invisible everywhere; messages filed into it get no label either',
        );
        self::assertSame(LabelRole::Inbox, $mailbox->label->role);
    }

    /**
     * A mailbox that already existed without a binding is healed.
     *
     * The deployed instance has exactly this shape: the broken code created the
     * row and stored five messages against it. Re-running only has to fix the
     * binding, not recreate anything.
     */
    public function testAnUnboundMailboxIsBoundOnTheNextRun(): void
    {
        $account = $this->pop3Account();

        $orphan = new Mailbox();
        $orphan->account       = $account;
        $orphan->name          = 'Inbox';
        $orphan->fullPath      = 'INBOX';
        $orphan->specialUse    = MailboxSpecialUse::INBOX;
        $orphan->isSelectable  = true;
        $orphan->isSyncEnabled = true;
        $this->em->persist($orphan);
        $this->em->flush();

        self::assertNull($orphan->label, 'precondition: the row starts unbound');

        $syncer  = static::getContainer()->get(Pop3AccountSyncer::class);
        $mailbox = $this->invokeMailboxFor($syncer, $account);

        self::assertSame($orphan->id, $mailbox->id, 'no second mailbox may be created');
        self::assertNotNull($mailbox->label, 'the existing row must be healed, not replaced');
        self::assertSame(LabelRole::Inbox, $mailbox->label->role);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function pop3Account(): Account
    {
        $account                  = new Account();
        $account->usr             = $this->user;
        $account->name            = 'POP3 label fixture';
        $account->email           = 'pop3-' . bin2hex(random_bytes(4)) . '@plmail.test';
        $account->username        = $account->email;
        $account->incomingProtocol = IncomingProtocol::Pop3;
        $account->imapHost        = 'pop.example.test';
        $account->imapPort        = 995;
        $account->imapEncryption  = 'ssl';
        $account->authType        = 'password';
        $account->isActive        = true;

        $this->em->persist($account);
        $this->user->addAccount($account);
        $this->em->flush();

        return $account;
    }

    private function invokeMailboxFor(Pop3AccountSyncer $syncer, Account $account): Mailbox
    {
        $method = new \ReflectionMethod($syncer, 'mailboxFor');
        $method->setAccessible(true);

        /** @var Mailbox $mailbox */
        $mailbox = $method->invoke($syncer, $account);

        $this->em->refresh($mailbox);

        return $mailbox;
    }
}
