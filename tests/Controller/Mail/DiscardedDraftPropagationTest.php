<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\User\UserRepository;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A discarded draft is discarded everywhere.
 *
 * The hole: discard() deleted the row and told nobody. A draft that had been
 * synced down from the server stayed in the provider's Drafts folder, and once
 * the row was gone nothing could ever collect it — incremental sync never
 * re-offers a UID below the high-water mark, so the message became permanently
 * invisible to plMail and permanently present in the user's real mailbox. The
 * button says discarded; it has to mean it.
 *
 * LabelChangePropagator::delete() already knew how to say this to each provider
 * in its own terms and had no caller anywhere in the app, which is why the one
 * action that removes mail from a server was reachable only in principle.
 */
final class DiscardedDraftPropagationTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The whole of it: the row goes, and the server is told to expunge the copy
     * it holds — in that order, because the propagation reads the address off
     * the row.
     */
    public function testDiscardingASyncedDraftDeletesItHereAndOnTheServer(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        [$draft, $account] = $this->syncedDraft($user);

        $draftId = (int) $draft->id;

        $this->transport()->reset();

        $client->request('POST', '/compose/discard/' . $draftId);

        self::assertResponseIsSuccessful();

        $this->em->clear();

        self::assertNull(
            $this->em->find(Message::class, $draftId),
            'the row goes, as it always did',
        );

        $sent = $this->sentOfType(ApplyImapFlagsMessage::class);

        self::assertCount(1, $sent, 'and the server is told, which it never used to be');
        self::assertSame('delete', $sent[0]->action, 'an expunge — a draft moved to Trash is a draft the user can still see');
        self::assertSame(
            77,
            $sent[0]->sourceUidFor($draftId),
            'addressed by the UID the row held when the user pressed the button',
        );
    }

    /**
     * A draft that never reached a server has no address on one, so discarding
     * it talks to nobody. This is the ordinary case — most drafts are discarded
     * before they are ever saved anywhere but here.
     */
    public function testDiscardingALocalOnlyDraftTalksToNoServer(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        [$draft] = $this->syncedDraft($user, uid: null);

        $this->transport()->reset();

        $client->request('POST', '/compose/discard/' . (int) $draft->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->sentOfType(ApplyImapFlagsMessage::class));
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function boot(object $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        $this->connection->beginTransaction();

        return $user;
    }

    /**
     * @return array{Message, Account}
     */
    private function syncedDraft(User $user, ?int $uid = 77): array
    {
        $container = static::getContainer();
        $labels    = $container->get(LabelResolver::class);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'discard-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $mailbox = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = 'Drafts';
        $mailbox->fullPath      = 'INBOX.Drafts';
        $mailbox->specialUse    = MailboxSpecialUse::DRAFTS;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);

        $this->em->flush();

        $labels->bindMailbox($labels->systemLabel(LabelRole::Drafts, $account), $mailbox);

        $thread = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Entwurf';
        $thread->normalizedSubject = 'entwurf';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $this->em->persist($thread);

        $draft = new Message();
        $draft->account        = $account;
        $draft->mailbox        = $mailbox;
        $draft->imapUid        = $uid;
        $draft->messageId      = uniqid('', true) . '@example.test';
        $draft->subject        = 'Entwurf';
        $draft->fromAddress    = $account->email;
        $draft->bodyText       = 'halb getippt';
        $draft->hasAttachments = false;
        $draft->seenAt         = new DateTimeImmutable();
        // discard() refuses anything that is not an unsent draft, and \Draft in
        // the flag list is what makes it one.
        $draft->flags          = [MessageFlag::DRAFT->value];
        $draft->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $draft->sentAt         = null;
        $draft->addLabel($labels->systemLabel(LabelRole::Drafts, $account));

        $this->em->persist($draft);
        $thread->addMessage($draft);

        $this->em->flush();

        return [$draft, $account];
    }

    /**
     * @return list<object>
     */
    private function sentOfType(string $class): array
    {
        $found = [];

        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $class) {
                $found[] = $message;
            }
        }

        return $found;
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
