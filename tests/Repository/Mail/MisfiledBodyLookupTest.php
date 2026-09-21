<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which stored messages the misfiled-body repair will touch.
 *
 * Against the real database on purpose. The predicate is the whole safety of
 * that repair — it decides which rows get a part deleted and a body
 * overwritten — and it leans on LENGTH() and a bracketed OR that DQL is
 * perfectly capable of compiling into something other than what was meant.
 * Asserting it against Postgres is the only way to know it selects what it
 * reads as selecting.
 */
final class MisfiledBodyLookupTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $repository;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageRepository::class);

        $this->connection->beginTransaction();

        $this->account = $this->seedAccount($this->seedUser());
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The reported message: no body, one part named after its own hash. */
    public function testItFindsAMessageWhoseBodyIsStoredAsANamelessPart(): void
    {
        $message = $this->seedMessage(bodyHtml: '');
        $this->seedPart($message, 'text/html', '09704cea');

        self::assertSame([$message->id], $this->foundIds());
    }

    /**
     * THE GUARD THAT MATTERS MOST. A part with a name of its own is a file
     * somebody attached, and this repair deletes the parts it selects.
     */
    public function testItLeavesAnAttachmentWithARealFilenameAlone(): void
    {
        $message = $this->seedMessage(bodyHtml: '');
        $this->seedPart($message, 'text/html', 'Rechnung.html');

        self::assertSame([], $this->foundIds());
    }

    /**
     * The second guard: webklex read the tree correctly and there IS a body,
     * so the nameless part beside it is something else. Overwriting a good
     * body would be the one way this repair could destroy mail.
     */
    public function testItLeavesAMessageThatAlreadyHasABodyAlone(): void
    {
        $message = $this->seedMessage(bodyHtml: '<p>Ich bin da</p>');
        $this->seedPart($message, 'text/html', '09704cea');

        self::assertSame([], $this->foundIds());
    }

    /** The count and the walk have to agree, since one drives the other's progress bar. */
    public function testTheCountAgreesWithTheWalk(): void
    {
        $message = $this->seedMessage(bodyHtml: '');
        $this->seedPart($message, 'text/html', '09704cea');
        // Both halves of a multipart/alternative misfiled: still one message.
        $this->seedPart($message, 'text/plain', 'a1b2c3d4');

        self::assertSame(1, $this->repository->countWithMisfiledBodyPart());
        self::assertCount(1, $this->repository->findWithMisfiledBodyPart(0, 50));
    }

    /**
     * @return list<int|null>
     */
    private function foundIds(): array
    {
        $found = $this->repository->findWithMisfiledBodyPart(0, 50);

        return array_map(static fn(Message $m): ?int => $m->id, $found);
    }

    private function seedMessage(string $bodyHtml): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Fixture';
        $thread->normalizedSubject = 'fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Fixture';
        $message->messageId      = uniqid('misfiled-', true) . '@example.test';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = true;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();
        $message->bodyHtml       = $bodyHtml;
        $message->bodyText       = '';

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedPart(Message $message, string $contentType, string $filename): MessagePart
    {
        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = $contentType;
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->isInline    = false;
        $part->size        = 42;
        $part->storagePath = 'fixtures/' . $filename;

        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Misfiled fixture';
        $account->email          = 'misfiled@example.test';
        $account->username       = uniqid('misfiled-', true);
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
        $user->email     = 'misfiled-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Misfiled';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
