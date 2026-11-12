<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Mail\MessageSnippet;
use App\Service\Mail\ThreadRows;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The list preview is made once and kept (Message::$preview), and each of the
 * things that make a kept line wrong makes it again: the body changing, the
 * message moving into or out of a bulk category, and the rules changing.
 *
 * The "kept" half is shown by changing the body behind the ORM's back: a list
 * that still shows the old line read the stored one and did not go back to
 * the body, which is the whole saving.
 */
final class ThreadRowsPreviewTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ThreadRows $rows;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->rows       = $container->get(ThreadRows::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'previews-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Preview';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Previews';
        $account->email          = 'previews@example.test';
        $account->username       = uniqid('previews-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFirstListMakesThePreviewAndKeepsIt(): void
    {
        $message = $this->message('<p>Hello there, the first version of this mail.</p>');

        self::assertSame('Hello there, the first version of this mail.', $this->snippetOf($message));
        self::assertSame(
            ['Hello there, the first version of this mail.', MessageSnippet::keyFor(MessageCategory::Primary)],
            $this->stored($message),
        );

        $this->bodyBehindTheOrmsBack($message, '<p>Changed without anybody knowing.</p>');

        self::assertSame('Hello there, the first version of this mail.', $this->snippetOf($message), 'the kept line, not the body');
    }

    public function testABodyChangedThroughTheEntityIsPreviewedAfresh(): void
    {
        $message = $this->message('<p>Before the decrypted copy arrived.</p>');
        $this->snippetOf($message);

        // Loaded again, as the request that changes a body would load it.
        $this->em->clear();
        $loaded               = $this->em->find(Message::class, $message->id);
        self::assertInstanceOf(Message::class, $loaded);
        $loaded->bodyHtmlSafe = '<p>After the decrypted copy arrived.</p>';
        $this->em->flush();

        self::assertSame([null, null], $this->stored($loaded));
        self::assertSame('After the decrypted copy arrived.', $this->snippetOf($loaded));
    }

    /**
     * Bulk mail's preview skips the short blocks it opens with; a person's
     * does not. Re-sorted into Promotions, the line kept for Primary is the
     * wrong one.
     */
    public function testARecategorisedMessageIsPreviewedByItsNewCategorysRule(): void
    {
        $body    = '<p>View in browser</p><p>The autumn collection is here, with forty new pieces to look at.</p>';
        $message = $this->message($body);

        self::assertStringStartsWith('View in browser', $this->snippetOf($message));

        $this->connection->executeStatement('UPDATE message SET category = ? WHERE id = ?', [MessageCategory::Promotions->value, $message->id]);

        self::assertSame('The autumn collection is here, with forty new pieces to look at.', $this->snippetOf($message));
        self::assertSame(MessageSnippet::keyFor(MessageCategory::Promotions), $this->stored($message)[1]);
    }

    public function testALineMadeUnderOtherRulesIsMadeAgain(): void
    {
        $message = $this->message('<p>The current rules make this line.</p>');

        $this->connection->executeStatement(
            'UPDATE message SET preview = ?, preview_key = ? WHERE id = ?',
            ['an old rule made this', MessageSnippet::keyFor(MessageCategory::Primary) - 2, $message->id],
        );

        self::assertSame('The current rules make this line.', $this->snippetOf($message));
    }

    private function message(string $html): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Preview';
        $thread->normalizedSubject = 'preview';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-11-01 09:00');
        $this->em->persist($thread);

        $message               = new Message();
        $message->account      = $this->account;
        $message->thread       = $thread;
        $message->subject      = 'Preview';
        $message->fromAddress  = 'sender@example.test';
        $message->receivedAt   = new DateTimeImmutable('2026-11-01 09:00');
        $message->sentAt       = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->category     = MessageCategory::Primary;
        $message->bodyHtmlSafe = $html;
        $thread->addMessage($message);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * The row's preview as a fresh list would draw it: from a thread loaded
     * anew, as a list request loads it. A thread whose messages are already in
     * memory is drawn from those entities, which never reads a stored preview
     * (ThreadRows::for()).
     */
    private function snippetOf(Message $message): string
    {
        $threadId = $message->thread?->id;
        self::assertNotNull($threadId);

        $this->em->clear();
        $thread = $this->em->find(MessageThread::class, $threadId);
        self::assertInstanceOf(MessageThread::class, $thread);

        $this->rows->reset();
        $this->rows->preload([$thread]);

        return $this->rows->for($thread)->snippet;
    }

    /** @return array{?string, ?int} */
    private function stored(Message $message): array
    {
        /** @var array{preview: ?string, preview_key: int|string|null} $row */
        $row = $this->connection->fetchAssociative('SELECT preview, preview_key FROM message WHERE id = ?', [$message->id]);

        return [$row['preview'], null === $row['preview_key'] ? null : (int) $row['preview_key']];
    }

    private function bodyBehindTheOrmsBack(Message $message, string $html): void
    {
        $this->connection->executeStatement('UPDATE message SET body_html_safe = ? WHERE id = ?', [$html, $message->id]);
    }
}
