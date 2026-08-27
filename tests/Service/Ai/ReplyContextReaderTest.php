<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\ReplyContext;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use App\Service\Ai\ReplyContextReader;
use App\Service\Ai\WritingAssistant;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * How much of a conversation the composer hands over, and — when it does not
 * all fit — which end it keeps.
 *
 * THE TRUNCATION IS THE WHOLE REASON THIS FILE EXISTS
 * ───────────────────────────────────────────────────
 * WritingAssistant trims its context from the START, which is right for one
 * message and catastrophic for a transcript: assembled oldest-first and then
 * cut to length, a long thread would arrive at the model as a greeting from
 * March with the question being answered missing entirely. Nothing would error,
 * nothing would be logged, and the only symptom would be replies that answer
 * the wrong thing.
 *
 * So the assertions are about the NEWEST turn surviving, not about the text
 * being right — the text being right is obvious the first time anybody uses it,
 * and the truncation is not.
 *
 * Everything happens inside one transaction that is rolled back, so the shared
 * test database is left as it was found.
 */
final class ReplyContextReaderTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private ReplyContextReader $reader;

    /** @var list<Message> oldest first */
    private array $turns = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->reader     = new ReplyContextReader($container->get(MessageRepository::class));

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** Nothing means nothing, whatever the message says. */
    public function testTheShallowestDepthReadsNothingAtAll(): void
    {
        $this->seedThread(['first', 'second']);

        self::assertNull($this->reader->textFor(ReplyContext::None, $this->newest()));
    }

    /** The middle value is what the composer did before this setting existed. */
    public function testOneMessageIsJustThatMessagesBody(): void
    {
        $this->seedThread(['an older turn', 'the newest turn']);

        self::assertSame('the newest turn', $this->reader->textFor(ReplyContext::Message, $this->newest()));
    }

    /**
     * The generous end: every turn, oldest first, each named by its sender.
     *
     * Oldest first because that is the order a conversation happened in and the
     * order a model reads one; the sender line because "who said which half" is
     * the thing a flat concatenation loses.
     */
    public function testTheWholeThreadArrivesInConversationOrder(): void
    {
        $this->seedThread(['what time do you open', 'nine, most days', 'and on Sunday?']);

        $text = $this->reader->textFor(ReplyContext::Thread, $this->newest());

        self::assertIsString($text);

        self::assertStringContainsString('what time do you open', $text);
        self::assertStringContainsString('nine, most days', $text);
        self::assertStringContainsString('and on Sunday?', $text);

        self::assertLessThan(
            mb_strpos($text, 'and on Sunday?'),
            mb_strpos($text, 'what time do you open'),
            'the thread arrived newest-first, which is not how a conversation reads',
        );

        self::assertStringContainsString('From: ', $text);
    }

    /**
     * THE ONE THAT MATTERS. A thread too long for the budget loses its OLDEST
     * turns and keeps the message actually being replied to.
     */
    public function testAnOversizedThreadKeepsTheNewestTurnAndDropsTheOldest(): void
    {
        // Three turns that together are comfortably past the budget, so at
        // least one has to go — and it must be the first.
        $long = str_repeat('x', (int) (WritingAssistant::CONTEXT_BUDGET * 0.6));

        $this->seedThread([
            'THE-OLDEST-TURN ' . $long,
            'A-MIDDLE-TURN ' . $long,
            'THE-NEWEST-TURN ' . $long,
        ]);

        $text = $this->reader->textFor(ReplyContext::Thread, $this->newest());

        self::assertIsString($text);

        self::assertStringContainsString('THE-NEWEST-TURN', $text);
        self::assertStringNotContainsString('THE-OLDEST-TURN', $text);
        self::assertLessThanOrEqual(WritingAssistant::CONTEXT_BUDGET, mb_strlen($text));
    }

    /**
     * A single turn past the budget on its own is kept anyway.
     *
     * An empty transcript would make WritingTask::hasEnoughToWorkFrom() refuse
     * the draft outright, which is a worse answer than a long one: the far side
     * trims it to the head, and the head is where the question is.
     */
    public function testASingleOversizedTurnIsStillHandedOverRatherThanDropped(): void
    {
        $this->seedThread(['ONLY-TURN ' . str_repeat('y', WritingAssistant::CONTEXT_BUDGET * 2)]);

        $text = $this->reader->textFor(ReplyContext::Thread, $this->newest());

        self::assertIsString($text);
        self::assertStringContainsString('ONLY-TURN', $text);
    }

    /**
     * A message with no thread falls back to its own body.
     *
     * Message::$thread is nullable, and a draft that has not been threaded yet
     * is the ordinary way to meet one. The generous setting must not mean "no
     * context at all" for it.
     */
    public function testAMessageWithNoThreadFallsBackToItsOwnBody(): void
    {
        $this->seedThread(['a lone message']);

        $message         = $this->newest();
        $message->thread = null;

        self::assertSame('a lone message', $this->reader->textFor(ReplyContext::Thread, $message));
    }

    /**
     * A turn with no text part still appears, as its sender line.
     *
     * Dropping it would silently close a gap in the conversation, and a model
     * that can see somebody replied is better off than one that cannot.
     */
    public function testATurnWithNoBodyStillAppearsAsItsSenderLine(): void
    {
        $this->seedThread([null, 'the newest turn']);

        $text = $this->reader->textFor(ReplyContext::Thread, $this->newest());

        self::assertIsString($text);
        self::assertSame(2, substr_count($text, 'From: '), 'a turn with no text part vanished from the thread');
    }

    private function newest(): Message
    {
        return $this->turns[count($this->turns) - 1];
    }

    /**
     * One thread, one message per body, a minute apart so the conversation
     * order is unambiguous.
     *
     * @param list<string|null> $bodies oldest first
     */
    private function seedThread(array $bodies): void
    {
        $user = new User();
        $user->email     = 'reply-context-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Reply';
        $user->nameLast  = 'Context';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'reply-context-fixture@example.test';
        $account->username       = 'reply-context-fixture@example.test';
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

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Opening hours';
        $thread->normalizedSubject = 'opening hours';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $this->em->persist($thread);

        $at = new DateTimeImmutable('2026-03-01 09:00:00');

        foreach ($bodies as $index => $body) {
            $message = new Message();
            $message->account        = $account;
            $message->thread         = $thread;
            $message->subject        = 'Opening hours';
            $message->bodyText       = $body;
            $message->fromName       = sprintf('Sender %d', $index);
            $message->fromAddress    = sprintf('sender%d@example.test', $index);
            $message->messageId      = sprintf('reply-context-%s-%d@example.test', uniqid('', true), $index);
            $message->receivedAt     = $at->modify(sprintf('+%d minutes', $index));
            $message->sentAt         = $message->receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $this->em->persist($message);

            $this->turns[] = $message;
        }

        $this->em->flush();
    }
}
